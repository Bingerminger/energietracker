<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffComparisonService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (F1032, GitHub #40) — Die Rest-Abrechnung in jedem Verbraucher
 * (Lektion „Subzähler-Ausschluss in jeden Aggregator“): Prognose, Werkstatt,
 * Rechnung prüfen, Abrechnungsjahr, Tarifvergleich (Rückblick und Wechsel),
 * Jahresbericht („Wohin geht mein Geld?“), PDF, Saldo-Schätzung.
 *
 * Haushalt: Elternzähler 500 kWh, Subzähler 200 kWh im Monat (2024–2025),
 * Elternvertrag 20 ct und 50 € Abschlag, Subzählervertrag 40 ct und 80 €
 * Abschlag, beide ohne Grundpreis und offen. Von Hand: Rest 300 kWh × 20 ct =
 * 60 € im Monat, Subzähler 80 €, Summe 140 € bei 500 kWh.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ForecastService::class)]
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(TariffComparisonService::class)]
#[CoversClass(TariffSwitchService::class)]
#[CoversClass(ScenarioService::class)]
final class SubmeterRestConsumersTest extends ServiceTestCase
{
    use ReportFixture;

    private string $parent;
    private string $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store->write('strom/meters.json', []);
        $this->store->write('strom/contracts.json', []);
        $this->store->write('settings.json', ['active_utilities' => ['strom']] + (array)$this->store->read('settings.json', []));
        $this->parent = (string)$this->meters->create('strom', ['name' => 'Hausanschluss', 'installed_on' => '2024-01-01'])['id'];
        $this->sub = (string)$this->meters->create('strom', ['name' => 'Wärmepumpe', 'installed_on' => '2024-01-01',
            'parent_meter_id' => $this->parent])['id'];
        $all = [];
        foreach ([$this->parent => 500.0, $this->sub => 200.0] as $id => $per) {
            $dev = $this->meters->get('strom', $id)['devices'][0]['id'];
            for ($i = 0; $i <= 24; $i++) {
                $all[] = ['id' => "r_{$id}_$i", 'meter_id' => $id, 'device_id' => $dev, 'date' => date('Y-m-d', (int)strtotime("2024-01-01 +$i months")),
                          'counter' => 1000.0 + $per * $i, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
        }
        $this->store->write('strom/readings.json', $all);
        foreach ([[$this->parent, 20.0, 50.0], [$this->sub, 40.0, 80.0]] as [$m, $ct, $adv]) {
            $this->contracts->create('strom', ['meter_id' => $m, 'provider' => 'Testversorger', 'start' => '2024-01-01',
                'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => $ct]],
                'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => $adv]]]);
        }
    }

    private function parentMeter(): array
    {
        return $this->meters->get('strom', $this->parent);
    }

    private function forecasts(): ForecastService
    {
        return new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
    }

    private function periods(): BillingPeriodService
    {
        return new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption,
            new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n));
    }

    private static function period(array $bp, string $from): array
    {
        foreach ($bp['periods'] as $p) if ($p['from'] === $from) return $p;
        self::fail("Periode ab $from fehlt");
    }

    public function testForecastBillsOnlyTheRest(): void
    {
        $fc = $this->forecasts()->forMeter('strom', $this->parentMeter());
        self::assertTrue($fc['valid']);
        foreach (array_slice($fc['forecast'], 0, 12) as $f) {
            // je Kalendermonat das Mittel der Tagesraten 2024/2025 × Tage (Februar: 29 und 28 Tage)
            $dim = (int)date('t', (int)strtotime($f['ym'] . '-01'));
            $rate = fn(float $perMonth) => ($perMonth / (int)date('t', (int)strtotime('2024-' . substr($f['ym'], 5) . '-01'))
                + $perMonth / (int)date('t', (int)strtotime('2025-' . substr($f['ym'], 5) . '-01'))) / 2 * $dim;
            self::assertEqualsWithDelta($rate(500.0), $f['kwh'], 0.15, 'die Prognose der Messung bleibt');
            self::assertEqualsWithDelta($rate(200.0), $f['deducted_kwh'], 0.15);
            self::assertEqualsWithDelta($rate(300.0), $f['billed_kwh'], 0.15);
            self::assertEqualsWithDelta($rate(300.0) * 0.20, $f['cost_estimated'], 0.05, $f['ym']);
        }
    }

    public function testBillCheckAndBillingYearUseTheRest(): void
    {
        $bd = $this->consumption->billBreakdown('strom', $this->parentMeter(), '2025-01-01', '2026-01-01');
        self::assertEqualsWithDelta(3600.0, $bd['totals']['kwh'], 0.5, 'Menge des Vertrags');
        self::assertEqualsWithDelta(6000.0, $bd['totals']['measured_kwh'], 0.5);
        self::assertEqualsWithDelta(720.0, $bd['totals']['total'], 0.1);
        self::assertEqualsWithDelta(2400.0, $bd['totals']['rest_of']['submeters'][0]['kwh'], 0.5);

        $p = self::period($this->periods()->forTarget('strom', $this->parentMeter(), false, '2026-10-26'), '2025-01-01');
        self::assertEqualsWithDelta(3600.0, $p['kwh'], 0.5);
        self::assertEqualsWithDelta(720.0, $p['cost'], 0.1);
        self::assertEqualsWithDelta(600.0, $p['advances'], 0.5);

        // die Summe der Art: Menge gemessen, Geld beider Verträge
        $u = self::period($this->periods()->forUtility('strom', false, '2026-10-26'), '2025-01-01');
        self::assertEqualsWithDelta(6000.0, $u['kwh'], 0.5);
        self::assertEqualsWithDelta(1680.0, $u['cost'], 0.1);
        self::assertEqualsWithDelta(1560.0, $u['advances'], 0.5);
    }

    public function testTariffComparisonAndSwitchCompareTheRest(): void
    {
        $cmp = (new TariffComparisonService($this->consumption, $this->contracts, $this->meters, $this->i18n))->compare('strom', $this->parent, 2025);
        $real = array_values(array_filter($cmp['rows'], fn($r) => empty($r['is_shadow'])))[0];
        self::assertEqualsWithDelta(3600.0, $real['consumption'], 0.5);
        self::assertEqualsWithDelta(720.0, $real['total_eur'], 0.1);
        self::assertEqualsWithDelta(20.0, $real['unit_cost_ct'], 0.01, 'nicht 12 ct (Rest-Kosten durch die ganze Messung)');

        $sw = (new TariffSwitchService($this->forecasts(), $this->contracts, $this->meters, $this->i18n))->analyze('strom', $this->parent);
        // 12 × 300 kWh; der Februar aus 29 und 28 Tagen gemittelt (294,8 kWh)
        self::assertEqualsWithDelta(3594.8, $sw['expected_consumption'], 0.5, 'erwarteter Jahresverbrauch des Elternvertrags = Rest');
    }

    public function testScenarioWorkshopCostsTheRest(): void
    {
        $svc = new ScenarioService($this->forecasts(), $this->consumption, $this->contracts, $this->meters, $this->settings,
            new TariffSwitchService($this->forecasts(), $this->contracts, $this->meters, $this->i18n), $this->periods());
        $r = $svc->compute(['utility' => 'strom', 'meter_id' => $this->parent]);
        self::assertTrue($r['valid']);
        $fc = array_slice($this->forecasts()->forMeter('strom', $this->parentMeter())['forecast'], 0, 12);
        self::assertEqualsWithDelta(array_sum(array_column($fc, 'kwh')), $r['totals']['base']['qty'], 0.5, 'Menge: die Messung');
        self::assertEqualsWithDelta(array_sum(array_column($fc, 'billed_kwh')) * 0.20, $r['totals']['base']['cost'], 0.1, 'Kosten: nur der Rest');
        self::assertEqualsWithDelta(720.0, $r['totals']['base']['cost'], 3.0);
        // ein Szenario mit 10 % weniger am Elternzähler spart 10 % der Messung zum Elternpreis
        $s = $svc->compute(['utility' => 'strom', 'meter_id' => $this->parent, 'saving_pct' => 10]);
        self::assertEqualsWithDelta(array_sum(array_column($fc, 'kwh')) * 0.10 * 0.20, $r['totals']['base']['cost'] - $s['totals']['scenario']['cost'], 0.2);
    }

    public function testYearReportAndPdfCountBothContracts(): void
    {
        $svc = $this->reportServices();
        $model = $svc['model']->build(2025);
        $strom = array_values(array_filter($model['money']['by_utility'], fn($b) => $b['utility'] === 'strom'))[0];
        self::assertEqualsWithDelta(1680.0, $strom['cost'], 0.1, '„Wohin geht mein Geld?“: 12 × 140 €');
        $pdf = $svc['pdf']->yearlyData(2025);
        $u = array_values(array_filter($pdf['utilities'], fn($x) => $x['utility'] === 'strom'))[0];
        self::assertEqualsWithDelta(6000.0, $u['consumption'], 0.5);
        self::assertEqualsWithDelta(1680.0, $u['cost'], 0.1);
    }

    /**
     * Skeptiker-Lauf v3.9.0 (M8): Der Jahresbericht sagt beim Subzähler mit eigenem Vertrag nicht
     * mehr „die Kosten laufen über dessen Vertrag“, sondern dass der Elternvertrag nur den Rest rechnet.
     */
    public function testReportSaysTheSubmeterHasItsOwnContract(): void
    {
        $text = function (): string {
            $model = $this->reportServices()['model']->build(2025);
            $page = array_values(array_filter($model['pages'], fn($p) => ($p['utility'] ?? null) === 'strom'))[0];
            return (string)($page['sub_meters'][0]['text'] ?? '');
        };
        $own = $text();
        self::assertStringContainsString('Wärmepumpe hat einen eigenen Vertrag', $own);
        self::assertStringContainsString('„Hausanschluss“ rechnet nur den Rest', $own);
        self::assertStringNotContainsString('laufen über dessen Vertrag', $own);
        // ohne eigenen Vertrag bleibt der Satz von bisher
        foreach ($this->contracts->list('strom', $this->sub) as $c) $this->contracts->delete('strom', (string)$c['id']);
        self::assertStringContainsString('die Kosten laufen über dessen Vertrag', $text());
    }

    /** PV-Ersparnis: Bezugspreis je Monat gewichtet mit der Menge jedes Vertrags (300 × 20 + 200 × 40) / 500 = 28 ct. */
    public function testPvSavingsPriceWeighsEveryContract(): void
    {
        $pv = new \Energietracker\Services\PvSummaryService($this->consumption, $this->settings);
        $m = new \ReflectionMethod($pv, 'bezugPrices');
        $prices = $m->invoke($pv, $this->consumption->forUtility('strom'));
        self::assertEqualsWithDelta(28.0, $prices['2025-06'], 0.001);
    }

    /** Saldo-Schätzung nach der letzten Ablesung: geschätzt wird der Rest (300 kWh je Monat × 20 ct). */
    public function testBalanceProjectionEstimatesTheRest(): void
    {
        $row = $this->consumption->contractStatus('strom', $this->parentMeter())['contracts'][0];
        self::assertSame('forecast', $row['projection_method']);
        $today = date('Y-m-d');
        $expected = 0.0;
        // Tagesrate je Kalendermonat: Mittel aus 2024 und 2025 (Februar 29 bzw. 28 Tage), Rest 300 kWh × 20 ct
        $perDay = fn(int $mo) => (60.0 / (int)date('t', mktime(0, 0, 0, $mo, 1, 2024)) + 60.0 / (int)date('t', mktime(0, 0, 0, $mo, 1, 2025))) / 2;
        for ($t = strtotime('2026-01-01 12:00'); date('Y-m-d', $t) < $today; $t += 86400) $expected += $perDay((int)date('n', $t));
        self::assertEqualsWithDelta($expected, $row['estimated_cost_to_date'], 0.5);
        // seit dem Paket auswertung über die laufende Abrechnungsperiode 2026: gemessen ist dort nichts (Stände bis 01.01.2026)
        self::assertSame('2026-01-01', $row['balance_from']);
        self::assertEqualsWithDelta($expected, $row['energy_cost_to_date'], 0.6);
    }
}
