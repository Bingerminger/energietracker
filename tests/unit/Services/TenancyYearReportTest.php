<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ReportModelService;
use Energietracker\Services\TenancyBudgetService;
use Energietracker\Services\TenancyService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 (Paket K2a) — Jahresbericht eines abgeschlossenen Jahres beim Mietverhältnis: Der Bericht rechnet am Tag, an dem
 * er erstellt wird (`today`), das Budget des Jahres über dessen Monate. Bis dahin war das Jahresende zugleich die Grenze der
 * Daten: Die Ablesung, die den Dezember abschließt, liegt am 01.01. des Folgejahres (eine Ablesung am Tag D zählt bis
 * D − 1), also galt der Dezember nie als gemessen und der Bericht nahm den Dezember des Vorjahres. Dazu zählt ein Monat,
 * den die Ablesungen nur zum Teil decken (Ablesung am 31.12. ohne spätere, Mietbeginn oder Mietende im Monat), mit der
 * Tagesrate seines gemessenen Teils statt mit dem Vorjahresmonat.
 *
 * Erfunden, von Hand (Tagesraten, die in jedem Monat auf 0,1 m³ aufgehen; das Budget rechnet mit den Monatszeilen):
 * Kaltwasser 0,07 m³ je Tag 2024, 0,1 m³ ab 2025; Warmwasser 0,1 bzw. 0,2 m³; Wärme 20 bzw. 25 kWh je Tag; Preise ab 2024:
 * Kaltwasser 4,20 €/m³, Warmwasser 9,80 €/m³, Wärme 0,11 €/kWh; Stichtag 01.01.
 *   2025 ganz  Kaltwasser 365 × 0,1 = 36,5 m³ × 4,20 = 153,30 €; Warmwasser 73,0 m³ × 9,80 = 715,40 € → Wasser 868,70 €
 *              Wärme 365 × 25 = 9.125 kWh × 0,11 = 1.003,75 €
 *   bis v3.12  Dezember aus 2024: Kaltwasser 2,2 statt 3,1 m³, Warmwasser 3,1 statt 6,2 m³ → Wasser 834,54 €
 */
#[CoversClass(ReportModelService::class)]
#[CoversClass(TenancyBudgetService::class)]
final class TenancyYearReportTest extends ServiceTestCase
{
    use ReportFixture;

    /** Stand an einem Tag bei konstanter Tagesrate je Jahr (ab 01.01.2024); ab 01.07.2025 mal `$summer`. */
    private static function counter(string $d, float $r2024, float $r2025, float $summer = 1.0): float
    {
        $days = fn(string $a, string $b) => (int)(new \DateTimeImmutable($a))->diff(new \DateTimeImmutable($b))->days;
        $n = $days('2024-01-01', $d);
        if ($n <= 366) return round($r2024 * $n, 4);
        $before = min($n - 366, $days('2025-01-01', '2025-07-01'));
        return round($r2024 * 366 + $r2025 * $before + $r2025 * $summer * max(0, $n - 366 - $before), 4);
    }

    /** @param list<string> $dates */
    private function household(array $dates, string $start = '2024-01-01', ?string $end = null, float $summer = 1.0): array
    {
        $this->settings->set(['active_utilities' => ['strom', 'wasser', 'waerme']]);
        $dev = fn(string $id) => [['id' => $id, 'serial' => null, 'installed_on' => '2023-12-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $meter = fn(string $id) => ['id' => $id, 'name' => $id, 'icon' => '', 'created_at' => '2023-12-01', 'active' => true, 'notes' => '', 'devices' => $dev("d_$id")];
        $this->store->write('wasser/meters.json', [$meter('m_kalt'), $meter('m_warm')]);
        $this->store->write('waerme/meters.json', [$meter('m_heiz')]);
        $w = []; $h = [];
        foreach ($dates as $i => $d) {
            $base = ['price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false, 'date' => $d];
            $w[] = $base + ['id' => "k$i", 'meter_id' => 'm_kalt', 'device_id' => 'd_m_kalt', 'counter' => self::counter($d, 0.07, 0.1, $summer)];
            $w[] = $base + ['id' => "w$i", 'meter_id' => 'm_warm', 'device_id' => 'd_m_warm', 'counter' => self::counter($d, 0.1, 0.2, $summer)];
            $h[] = $base + ['id' => "h$i", 'meter_id' => 'm_heiz', 'device_id' => 'd_m_heiz', 'counter' => self::counter($d, 20.0, 25.0)];
        }
        $this->store->write('wasser/readings.json', $w);
        $this->store->write('waerme/readings.json', $h);
        return (new TenancyService($this->store, $this->meters, $this->i18n))->create(['start' => $start, 'end' => $end, 'billing_anchor' => '01-01',
            'prepayments' => [['from' => '2024-01-01', 'heating_eur_month' => 90, 'operating_eur_month' => 40]],
            'prices' => [['from' => '2024-01-01', 'cold_water_eur_per_m3' => 4.20, 'warm_water_eur_per_m3' => 9.80, 'heat_eur_per_kwh' => 0.11]],
            'meter_ids' => ['cold_water' => ['m_kalt'], 'warm_water' => ['m_warm'], 'heat' => ['m_heiz']]]);
    }

    /** @return array{0:?float,1:?float} Kosten der Seiten Wasser und Wärme im Jahresbericht 2025 */
    private function report(string $today): array
    {
        $r = $this->reportServices()['model']->build(2025, ['today' => $today]);
        $cost = [];
        foreach ($r['pages'] as $p) if (in_array($p['utility'] ?? '', ['wasser', 'waerme'], true)) $cost[$p['utility']] = $p['cost'];
        self::assertSame(['tenancy', 'tenancy'], [$cost['wasser']['basis'], $cost['waerme']['basis']]);
        return [$cost['wasser']['value'], $cost['waerme']['value']];
    }

    /** @return list<string> */
    private static function monthly(string $from, string $to): array
    {
        $out = [];
        for ($d = new \DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('first day of next month')) $out[] = $d->format('Y-m-d');
        return $out;
    }

    /**
     * Stände rund um den Jahreswechsel, Tag des Berichts, erwartete Kosten Wasser und Wärme. Mit der Ablesung am 31.12. und
     * keiner späteren ist der 31.12. nicht gemessen (die Ablesung zählt bis zum Vortag): 30 Tage gemessen, ein Tag aus dem
     * Dezember davor (Kaltwasser 0,07, Warmwasser 0,1 m³, Wärme 20 kWh): Wasser 868,70 − 0,03 × 4,20 − 0,1 × 9,80 = 867,59 €
     * (Monatszeilen auf 0,1 m³: 867,60 €), Wärme 1.003,75 − 5 × 0,11 = 1.003,20 €.
     *
     * @return array<string,array{0:list<string>,1:string,2:float,3:float}>
     */
    public static function yearEnds(): array
    {
        return [
            'Ablesung am 01.01.'                => [['2026-01-01'], '2026-02-10', 868.70, 1003.75],
            'Ablesung am 31.12. und 03.01.'     => [['2025-12-31', '2026-01-03'], '2026-02-10', 868.70, 1003.75],
            'Ablesung am 03.01.'                => [['2026-01-03'], '2026-02-10', 868.70, 1003.75],
            'Ablesung am 31.12., keine spätere' => [['2025-12-31'], '2026-01-02', 867.60, 1003.20],
        ];
    }

    /** @param list<string> $tail */
    #[DataProvider('yearEnds')]
    public function testTheClosedYearHasAMeasuredDecember(array $tail, string $today, float $wantWater, float $wantHeat): void
    {
        $this->household([...self::monthly('2024-01-01', '2025-12-01'), ...$tail]);
        [$water, $heat] = $this->report($today);
        self::assertSame($wantWater, $water, 'Wasser 2025 aus den eigenen Ständen (bis v3.12 834,54 €)');
        self::assertSame($wantHeat, $heat, 'Wärme 2025');
    }

    /** Das Budget des Jahres am Tag des Berichts: Der Dezember ist gemessen und stammt aus 2025, nicht aus 2024. */
    public function testTheBudgetOfTheYearIsMeasuredWithTheDataOfTheReportDay(): void
    {
        $t = $this->household([...self::monthly('2024-01-01', '2025-12-01'), '2026-01-01']);
        $b = (new TenancyBudgetService(new TenancyService($this->store, $this->meters, $this->i18n), $this->meters, $this->consumption, $this->i18n))
            ->budget((string)$t['id'], '2025-12-31', '2026-02-10');
        $dec = $b['months'][11];
        self::assertSame(['2025-12', true, 3.1, 6.2], [$dec['ym'], $dec['measured'], $dec['cold_water_m3'], $dec['warm_water_m3']]);
        self::assertSame(0, $b['months_estimated']);
    }

    /**
     * Mietbeginn 15.03.2025, Mietende 20.10.2025, Einzugs- und Auszugsablesung (15.03., 21.10.); Wasser ab 01.07. doppelt:
     * 15.03. bis 30.06. 108 Tage, 01.07. bis 20.10. 112 Tage.
     * Kaltwasser 108 × 0,1 + 112 × 0,2 = 33,2 m³ × 4,20 = 139,44 €; Warmwasser 108 × 0,2 + 112 × 0,4 = 66,4 m³ × 9,80 =
     * 650,72 € → 790,16 €; Wärme 220 × 25 × 0,11 = 605,00 €. Bis v3.12 nahmen März und Oktober das Tagesmittel des Jahres.
     */
    public function testMoveInAndMoveOutWithinTheYear(): void
    {
        $this->household(['2025-03-15', ...self::monthly('2025-04-01', '2025-10-01'), '2025-10-21'], '2025-03-15', '2025-10-20', 2.0);
        [$water, $heat] = $this->report('2026-02-10');
        self::assertSame(790.16, $water);
        self::assertSame(605.0, $heat);
    }
}
