<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ForecastService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (auswertung, Zusatzbefund) — die Stromprognose bei überwiegend verteilten Monaten.
 *
 * Bei echten Strom-Daten (fast alle Monate verteilt, kaum gemessene) lag die Prognose im
 * Rückblick durchweg zu tief. Nachgestellt am unordentlichen Testhaushalt, dessen Strombezug
 * einmal im Jahr abgelesen wird (05.01.2023, 08.01.2024, 10.01.2025, 12.01.2026):
 *
 *   - Vier Sommer-Ablesungen 2025 (gleiche Jahresmenge) senkten die Prognose von 2459,8 auf
 *     2318,3 kWh: gemessene Sommermonate neben verteilten Monaten im Mittel aller Jahre.
 *   - Ein Jahr mit 25 % mehr Verbrauch (2464,7 × 1,25 = 3080,875 kWh vom 10.01.2025 bis
 *     12.01.2026) kam als 2659,1 kWh an (−13 %): das Mittel dreier Jahre.
 *
 * Seit v3.9.0 folgt die Jahresmenge ohne zwölf gemessene Kalendermonate dem Verbrauch der
 * letzten 365 Tage (ForecastService::lastYearLevel); das Profil verteilt sie nur.
 *
 * Handrechnung (Monatswerte des Dienstes: Januar 2026 hat 11 Tage bis zur Ablesung, der
 * Januar 2025 9 Tage zur Rate 2024 und 22 zur Rate 2025):
 *   r2024 = (26845,5 − 24355,3) / 368 = 6,766848 kWh/Tag
 *   365 Tage = Januar 2026 (11) + Februar bis Dezember 2025 (334) + 20 von 31 Tagen Januar 2025
 */
#[CoversClass(ForecastService::class)]
final class ForecastLevelTest extends ServiceTestCase
{
    private const SUMMER = [['2025-05-02', 27696.7], ['2025-06-02', 27857.9], ['2025-07-02', 28004.9], ['2025-08-04', 28163.3], ['2025-09-02', 28317.0]];

    /** Der unordentliche Haushalt; $extra zusätzliche Stromablesungen, $lastCounter ersetzt den Stand vom 12.01.2026. */
    private function loadMessy(array $extra = [], ?float $lastCounter = null): void
    {
        $h = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/messy-household/household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings']);
        $this->store->write('temperatures.json', $h['temperatures']);
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        $rows = $h['utilities']['strom']['readings'];
        if ($lastCounter !== null) $rows[count($rows) - 1]['counter'] = $lastCounter;
        foreach ($extra as $i => [$date, $counter]) {
            $rows[] = ['id' => "r_strom_extra_$i", 'meter_id' => 'm_strom_messy', 'device_id' => 'd_strom', 'date' => $date, 'counter' => $counter,
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
        $h['utilities']['strom']['readings'] = $rows;
        foreach ($h['utilities'] as $u => $pots) foreach ($pots as $pot => $r) $this->store->write("$u/$pot.json", $r);
    }

    private function forecast(): array
    {
        return (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))
            ->forMeter('strom', $this->meters->get('strom', 'm_strom_messy'));
    }

    /** Summe der ersten zwölf Prognosemonate (je Monat auf 0,1 gerundet: höchstens 0,6 daneben). */
    private static function total(array $fc): float
    {
        return array_sum(array_map(fn($r) => (float)$r['kwh'], array_slice($fc['forecast'], 0, 12)));
    }

    private static function level(array $fc): ?array
    {
        return array_values(array_filter($fc['warnings'], fn($w) => $w['code'] === 'level_last_year'))[0] ?? null;
    }

    public function testAnnualReadingsGiveTheConsumptionOfTheLastYear(): void
    {
        $this->loadMessy();
        $fc = $this->forecast();
        // r2025 = 2464,7 / 367 = 6,715804; Januar 2025 = 9 × 6,766848 + 22 × 6,715804 = 208,649
        // 345 × 6,715804 + 20/31 × 208,649 = 2316,952 + 134,612 = 2451,564 (bis 3.8: 2459,8)
        self::assertEqualsWithDelta(2451.6, self::total($fc), 0.6);
        $level = self::level($fc);
        self::assertNotNull($level, 'der Hinweis sagt, woher die Jahresmenge kommt');
        self::assertEqualsWithDelta(2451.6, $level['value'], 0.6);   // Monatswerte sind auf 0,1 gerundet
        self::assertSame(['2025-01', '2026-01'], [$level['from'], $level['to']]);
    }

    public function testExtraSummerReadingsNoLongerLowerTheAnnualAmount(): void
    {
        $this->loadMessy();
        $before = self::total($this->forecast());
        $this->loadMessy(self::SUMMER);
        $fc = $this->forecast();
        // 10.01. bis 02.05.2025: 851,2 kWh in 112 Tagen = 7,6/Tag; Februar 2025 bis Januar 2026
        // = 29310,2 − (26845,5 + 22 × 7,6) = 2297,5; Januar 2025 = 9 × 6,766848 + 22 × 7,6 = 228,102
        // 2297,5 + 20/31 × 228,102 = 2444,662 (bis 3.8: 2318,3, gut 5 % weniger als ohne die Ablesungen)
        self::assertEqualsWithDelta(2444.7, self::total($fc), 0.6);
        self::assertLessThan(0.005 * $before, abs(self::total($fc) - $before), 'mehr Ablesungen ändern die Jahresmenge kaum');
        // das gemessene Profil bleibt: der Juni unter dem Februar
        $by = array_column($fc['forecast'], null, 'ym');
        self::assertSame('seasonal_only', $by['2026-06']['method']);
        self::assertLessThan($by['2026-02']['kwh'] * 0.8, $by['2026-06']['kwh']);
    }

    public function testARisingLevelIsFollowedNotAveragedAway(): void
    {
        $this->loadMessy([], 26845.5 + 2464.7 * 1.25);
        $fc = $this->forecast();
        // r2025 = 3080,875 / 367 = 8,394755; Januar 2025 = 9 × 6,766848 + 22 × 8,394755 = 245,586
        // 345 × 8,394755 + 20/31 × 245,586 = 2896,190 + 158,443 = 3054,633 (bis 3.8: 2659,1)
        self::assertEqualsWithDelta(3054.6, self::total($fc), 0.6);
        self::assertEqualsWithDelta(3054.6, self::level($fc)['value'], 0.6);
    }

    public function testTwelveMeasuredCalendarMonthsKeepTheirOwnLevel(): void
    {
        // monatliche Ablesungen zur Rate 2025: jeder Kalendermonat gemessen, kein Flickwerk
        $rate = 2464.7 / 367;
        $extra = [];
        for ($m = 2; $m <= 13; $m++) {
            $date = $m <= 12 ? sprintf('2025-%02d-01', $m) : '2026-01-01';
            $days = (int)round((strtotime($date) - strtotime('2025-01-10')) / 86400);
            $extra[] = [$date, round(26845.5 + $days * $rate, 1)];
        }
        $this->loadMessy($extra);
        $fc = $this->forecast();
        self::assertNull(self::level($fc));
        self::assertSame(['seasonal_only'], array_values(array_unique(array_column(array_slice($fc['forecast'], 0, 12), 'method'))));
    }

    public function testHeatingKeepsItsModel(): void
    {
        $this->loadMessy();
        $fc = (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))
            ->forMeter('gas', $this->meters->list('gas')[0]);
        self::assertNull(self::level($fc), 'Heizarten rechnen mit Wetter und Heizmodell, nicht mit dem Vorjahr');
    }
}
