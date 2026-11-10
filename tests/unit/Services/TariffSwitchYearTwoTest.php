<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ForecastService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Wechselentscheidung: „ab 2. Jahr“ sind die Monate 13 bis 24 nach dem Wechsel, mit den Preisen, die dann
 * gelten, ohne einmaligen Bonus. Bis v3.11 war Jahr 2 dasselbe Fenster wie Jahr 1 ohne Bonus; ein Angebot mit einer
 * Preisstaffel (billig im ersten Jahr, teuer ab dem zweiten) stand vorn, obwohl die Doku zusagt, dass ein
 * Lockangebot die Rangfolge nicht gewinnt.
 *
 * Erfundener Haushalt: Strom nach Saisonprofil (Summe 6.000 kWh), laufender Vertrag 30 ct + 12 €/Monat bis
 * 31.12.2026, ein Monat Frist → Wechsel 01.01.2027, Jahr 1 = 2027, Jahr 2 = 2028. Von Hand mit v = erwartete Menge:
 *   Staffel: 20 ct ab 2026, 34 ct ab 01.01.2028, 10 €/Monat
 *     Jahr 1 = v × 0,20 + 120 €, Jahr 2 = v × 0,34 + 120 €
 *   Fest: 26 ct + 15 €/Monat → Jahr 1 = Jahr 2 = v × 0,26 + 180 €
 *   Bestand: Jahr 2 = v × 0,30 + 144 €
 *   Rangfolge nach Jahr 2 (v ≈ 6.000): Fest ≈ 1.740 €, Staffel ≈ 2.160 € (bis v3.11: Staffel ≈ 1.320 € vorn)
 *   Break-even Staffel gegen Bestand über Jahr 2: 0,34 x + 120 = 0,30 x + 144 → x = 24 / 0,04 = 600 kWh
 */
#[CoversClass(TariffSwitchService::class)]
final class TariffSwitchYearTwoTest extends ServiceTestCase
{
    private const PROFILE = [
        1 => 800, 2 => 720, 3 => 600, 4 => 450, 5 => 330, 6 => 250,
        7 => 220, 8 => 220, 9 => 300, 10 => 450, 11 => 620, 12 => 760,
    ];

    private TariffSwitchService $switch;
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $this->switch = new TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_s', 'serial' => null, 'installed_on' => '2023-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = []; $counter = 1000.0;
        $cur = new \DateTimeImmutable('2024-01-01');
        $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => $counter, 'device_id' => 'd_s'];
        while ($cur < new \DateTimeImmutable('2026-07-01')) {
            $cur = $cur->modify('+1 month');
            $counter += self::PROFILE[(int)$cur->format('n')];
            $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => $counter, 'device_id' => 'd_s'];
        }
        $this->setReadings('strom', $this->meterId, $rows);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke', 'tariff_name' => 'Basis',
            'start' => '2026-01-01', 'end' => '2026-12-31', 'notice_period_months' => 1,
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]]]);
    }

    private function offer(string $label, array $wp, float $bp, array $extra = []): void
    {
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Anbieter', 'tariff_name' => $label,
            'start' => '2026-01-01', 'is_shadow' => true, 'shadow_label' => $label,
            'working_prices' => $wp, 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => $bp]]] + $extra);
    }

    /** @return array<string,mixed> */
    private function analyze(): array
    {
        $this->offer('Staffel', [['from' => '2026-01-01', 'ct_per_kwh' => 20.0], ['from' => '2028-01-01', 'ct_per_kwh' => 34.0]], 10.0);
        $this->offer('Fest', [['from' => '2026-01-01', 'ct_per_kwh' => 26.0]], 15.0);
        $r = $this->switch->analyze('strom', $this->meterId, ['today' => '2026-08-03']);
        self::assertSame('2027-01-01', $r['switch_date']);
        return $r;
    }

    /** @return array<string,mixed> */
    private static function cand(array $r, string $label): array
    {
        return array_values(array_filter($r['candidates'], fn($c) => $c['label'] === $label))[0];
    }

    public function testYearTwoUsesThePricesOfMonthsThirteenToTwentyFour(): void
    {
        $r = $this->analyze();
        $v = $r['expected_consumption'];
        $staffel = self::cand($r, 'Staffel');
        self::assertEqualsWithDelta($v * 0.20 + 120.0, $staffel['year1_eur'], 0.05, 'Jahr 1: 2027 zu 20 ct');
        self::assertEqualsWithDelta($v * 0.34 + 120.0, $staffel['year2_eur'], 0.05, 'Jahr 2: 2028 zu 34 ct');
        self::assertEqualsWithDelta(34.0, $staffel['monthly_year2'][0]['working_price_ct'], 0.001);
        self::assertSame('2028-01', $staffel['monthly_year2'][0]['ym']);
        self::assertSame('2028-12', $staffel['monthly_year2'][11]['ym']);
    }

    public function testATeaserStepDoesNotWinTheRanking(): void
    {
        $r = $this->analyze();
        $labels = array_column($r['candidates'], 'label');
        self::assertTrue($r['candidates'][0]['is_reference']);
        self::assertSame(['Fest', 'Staffel'], array_slice($labels, 1), 'nach Jahr 2: Fest ≈ 1.740 € vor Staffel ≈ 2.160 €');
        $v = $r['expected_consumption'];
        self::assertEqualsWithDelta(($v * 0.34 + 120.0) - ($v * 0.30 + 144.0), self::cand($r, 'Staffel')['vs_reference_year2_eur'], 0.05);
    }

    public function testBreakEvenAndSensitivityDescribeYearTwo(): void
    {
        $r = $this->analyze();
        $v = $r['expected_consumption'];
        $staffel = self::cand($r, 'Staffel');
        self::assertEqualsWithDelta(600.0, $staffel['break_even_consumption'], 5.0, 'von Hand: 24 / 0,04');
        self::assertEqualsWithDelta($v * 0.9 * 0.34 + 120.0, $staffel['sensitivity']['low'], 0.05);
        self::assertEqualsWithDelta($v * 1.1 * 0.34 + 120.0, $staffel['sensitivity']['high'], 0.05);
    }

    /**
     * Doku-Beispiel (Ansichten → Wechselentscheidung): bei 6.000 kWh „1. Jahr“ 1.320 €, „ab 2. Jahr“ 2.160 €. Aus den
     * Monatszeilen der App auf 6.000 kWh umgerechnet (Grundpreis bleibt, Arbeitspreis × Menge).
     */
    public function testTheDocumentedExampleHolds(): void
    {
        $staffel = self::cand($this->analyze(), 'Staffel');
        $at = function (array $months, float $kwh): float {
            $fixed = 0.0; $var = 0.0; $q = 0.0;
            foreach ($months as $m) {
                $fixed += $m['cost'] - $m['consumption'] * $m['working_price_ct'] / 100;
                $var += $m['consumption'] * $m['working_price_ct'] / 100;
                $q += $m['consumption'];
            }
            return round($fixed + $var / $q * $kwh);
        };
        self::assertSame(1320.0, $at($staffel['monthly'], 6000.0), 'von Hand: 6.000 × 0,20 + 120');
        self::assertSame(2160.0, $at($staffel['monthly_year2'], 6000.0), 'von Hand: 6.000 × 0,34 + 120');
        $root = dirname(__DIR__, 3);
        foreach (['docs/referenz/ansichten.md' => ['1.320 €', '2.160 €'], 'docs/en/referenz/ansichten.md' => ['€1,320', '€2,160']] as $file => $want) {
            $text = (string)file_get_contents("$root/$file");
            $pos = strpos($text, '<!-- doc-claim: tariff-switch-year-two');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            $block = substr($text, $pos, 700);
            foreach ($want as $v) self::assertStringContainsString($v, $block, "$file: $v");
        }
    }

    /**
     * Der Bestand rechnet ebenso: Ein eingetragener Preis ab 01.01.2028 (36 ct) gilt in Jahr 2.
     * Von Hand: Jahr 1 = v × 0,30 + 144 €, Jahr 2 = v × 0,36 + 144 €.
     */
    public function testTheReferenceAlsoUsesItsLaterPrices(): void
    {
        $c = $this->contracts->list('strom', $this->meterId)[0];
        $this->contracts->update('strom', (string)$c['id'], ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0], ['from' => '2028-01-01', 'ct_per_kwh' => 36.0]]] + $c);
        $r = $this->analyze();
        $v = $r['expected_consumption'];
        $ref = $r['candidates'][0];
        self::assertTrue($ref['is_reference']);
        self::assertEqualsWithDelta($v * 0.30 + 144.0, $ref['year1_eur'], 0.05);
        self::assertEqualsWithDelta($v * 0.36 + 144.0, $ref['year2_eur'], 0.05);
    }

    public function testABonusStaysInYearOneOnly(): void
    {
        $this->offer('Bonus', [['from' => '2026-01-01', 'ct_per_kwh' => 26.0]], 15.0, ['signup_bonus_eur' => 100.0]);
        $r = $this->analyze();
        $v = $r['expected_consumption'];
        $b = self::cand($r, 'Bonus');
        self::assertEqualsWithDelta($v * 0.26 + 180.0 - 100.0, $b['year1_eur'], 0.05);
        self::assertEqualsWithDelta($v * 0.26 + 180.0, $b['year2_eur'], 0.05);
    }
}
