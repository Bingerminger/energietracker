<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\HeatPumpService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H7) — Jahresarbeitszahl der Wärmepumpe (MKT-18), PV mit
 * Speicher, Balkonkraftwerk, Amortisation und Vermeidungsfaktor (CALC-29),
 * Gutschriften des Direktvermarkters und Vorher/Nachher ohne Gradtage (MKT-16).
 */
#[CoversClass(HeatPumpService::class)]
#[CoversClass(PvSummaryService::class)]
#[CoversClass(ConsumptionService::class)]
final class HeatPumpAndPvTest extends ServiceTestCase
{
    /** Monatsstände ab $from, je Monat $perMonth (Liste je Monat erlaubt). */
    private function monthly(string $u, string $meterId, string $from, int $months, float|array $perMonth): void
    {
        $dev = $this->meters->get($u, $meterId)['devices'][0]['id'];
        $all = array_values(array_filter((array)$this->store->read("$u/readings.json", []), fn($r) => ($r['meter_id'] ?? null) !== $meterId));
        $c = 0.0;
        for ($i = 0; $i <= $months; $i++) {
            $all[] = ['id' => "r_{$meterId}_$i", 'meter_id' => $meterId, 'device_id' => $dev, 'date' => date('Y-m-d', (int)strtotime("$from +$i months")),
                      'counter' => $c, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            $c += is_array($perMonth) ? (float)($perMonth[$i] ?? 0) : $perMonth;
        }
        $this->store->write("$u/readings.json", $all);
    }

    /** MKT-18: 9.000 kWh Wärme / 2.500 kWh Strom = JAZ 3,6. */
    public function testAnnualPerformanceFactor(): void
    {
        $wp = (string)$this->meters->create('strom', ['name' => 'WP', 'installed_on' => '2024-01-01', 'role' => 'heat_pump'])['id'];
        $hm = (string)$this->meters->create('waerme', ['name' => 'WMZ WP', 'installed_on' => '2024-01-01', 'role' => 'heat_pump_output',
            'heat_pump_meter_ids' => [$wp]])['id'];
        $this->monthly('strom', $wp, '2025-01-01', 12, 2500 / 12);
        $this->monthly('waerme', $hm, '2025-01-01', 12, 9000 / 12);
        $r = (new HeatPumpService($this->meters, $this->consumption))->forYear(2025);
        self::assertCount(1, $r['pumps']);
        $p = $r['pumps'][0];
        self::assertSame(3.6, $p['jaz']);
        self::assertSame(12, $p['months_covered']);
        self::assertSame(3.6, $p['jaz_heating_season']);
        // die Wärmeabgabe der WP zählt nicht in die Heizwärme der Art
        $jan = array_column($this->consumption->forUtility('waerme')['monthly_total'], null, 'ym')['2025-01'] ?? ['kwh' => 0];
        self::assertSame(0.0, (float)$jan['kwh']);
        // Verknüpfung nur mit einem Stromzähler der Rolle Wärmepumpe
        try {
            $this->meters->update('waerme', $hm, ['heat_pump_meter_ids' => [(string)$this->meters->defaultId('strom')]]);
            self::fail('Haushaltszähler als WP-Strom angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.meter.heatPumpLinkInvalid', $e->key);
        }
    }

    public function testMonthsWithoutElectricityAreLeftOut(): void
    {
        $wp = (string)$this->meters->create('strom', ['name' => 'WP', 'installed_on' => '2024-01-01', 'role' => 'heat_pump'])['id'];
        $hm = (string)$this->meters->create('waerme', ['name' => 'WMZ', 'installed_on' => '2024-01-01', 'role' => 'heat_pump_output',
            'heat_pump_meter_ids' => [$wp]])['id'];
        $this->monthly('waerme', $hm, '2025-01-01', 12, 750);
        $this->monthly('strom', $wp, '2025-07-01', 6, 100);   // Strom erst ab Juli
        $p = (new HeatPumpService($this->meters, $this->consumption))->forYear(2025)['pumps'][0];
        self::assertSame(6, $p['months_covered']);
        self::assertSame(7.5, $p['jaz']);
    }

    /** CALC-29: Speicherverluste, Erzeugung ohne Ladestrom, Amortisation 800 € bei 160 €/a = 5 Jahre. */
    public function testBatteryAndPayback(): void
    {
        $gen = $this->meters->create('pv_erzeugung', ['name' => 'WR', 'installed_on' => '2024-01-01',
            'investment_eur' => '800', 'commissioned_on' => '2025-03-10']);
        $chg = $this->meters->create('pv_erzeugung', ['name' => 'Laden', 'installed_on' => '2024-01-01', 'role' => 'battery_charge', 'battery_capacity_kwh' => 5]);
        $dis = $this->meters->create('pv_erzeugung', ['name' => 'Entladen', 'installed_on' => '2024-01-01', 'role' => 'battery_discharge']);
        self::assertSame([800.0, '2025-03-10'], [$gen['investment_eur'], $gen['commissioned_on']]);
        $this->monthly('pv_erzeugung', $gen['id'], '2025-01-01', 12, 100);
        $this->monthly('pv_erzeugung', $chg['id'], '2025-01-01', 12, 50);
        $this->monthly('pv_erzeugung', $dis['id'], '2025-01-01', 12, 45);
        $out = (new PvSummaryService($this->consumption, $this->settings))->compute();
        $y = array_column($out['yearly'], null, 'year')[2025];
        self::assertEqualsWithDelta(1200, $y['erzeugung_kwh'], 0.5, 'nur die Rolle generation');
        self::assertEqualsWithDelta(600, $y['battery']['charged_kwh'], 0.5);
        self::assertEqualsWithDelta(60, $y['battery']['losses_kwh'], 0.5);
        self::assertEqualsWithDelta(90.0, $y['battery']['efficiency_pct'], 0.1);
        self::assertEqualsWithDelta(120.0, $y['battery']['full_cycles'], 0.1);
        self::assertSame(['negative_prices'], $out['hints'], 'Inbetriebnahme nach dem 25.02.2025');

        // Nutzen 160 €/a ohne Bezugspreis: über die Vergütung (Einspeisung 160 kWh × 100 ct)
        $eins = (string)$this->meters->defaultId('pv_einspeisung');
        $this->monthly('pv_einspeisung', $eins, '2025-01-01', 12, 160 / 12);
        $this->contracts->create('pv_einspeisung', ['meter_id' => $eins, 'provider' => 'Netz', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 100.0]]]);
        $pb = (new PvSummaryService($this->consumption, $this->settings))->compute()['payback'];
        self::assertSame(800.0, $pb['investment_eur']);
        self::assertEqualsWithDelta(5.0, $pb['years_to_break_even'], 0.05);
        self::assertTrue($pb['break_even_projected']);
    }

    public function testPlugInWithAssumedSelfConsumptionAndAvoidedFactor(): void
    {
        $this->store->write('pv_einspeisung/meters.json', []);
        $gen = $this->meters->create('pv_erzeugung', ['name' => 'Balkon', 'installed_on' => '2024-01-01', 'plug_in' => true]);
        $this->monthly('pv_erzeugung', $gen['id'], '2025-01-01', 3, 50);
        $this->monthly('strom', (string)$this->meters->defaultId('strom'), '2025-01-01', 3, 200);
        $before = (new PvSummaryService($this->consumption, $this->settings))->compute();
        self::assertNull(array_column($before['monthly'], null, 'ym')['2025-01']['eigenverbrauch_kwh'], 'ohne Annahme keine Quote');
        $this->settings->set(['pv_assumed_self_consumption_pct' => 80]);
        $row = array_column((new PvSummaryService($this->consumption, $this->settings))->compute()['monthly'], null, 'ym')['2025-01'];
        self::assertEqualsWithDelta(40.0, $row['eigenverbrauch_kwh'], 0.01);
        self::assertTrue($row['self_consumption_assumed']);
        self::assertEqualsWithDelta(40 / 240, $row['autarkiequote'], 0.001);

        // Vermeidungsfaktor: leer = Strommix wie bisher, gesetzt = eigener Wert
        $mix = array_column($this->consumption->forMeter('pv_erzeugung', $this->meters->get('pv_erzeugung', $gen['id'])), null, 'ym')['2025-01']['co2_kg'];
        self::assertEqualsWithDelta(50 * $this->settings->co2Factor('co2_strom', 2025) / 1000, $mix, 0.1);
        $this->settings->set(['co2_pv_avoided' => 690]);
        $own = array_column($this->consumption->forMeter('pv_erzeugung', $this->meters->get('pv_erzeugung', $gen['id'])), null, 'ym')['2025-01']['co2_kg'];
        self::assertEqualsWithDelta(34.5, $own, 0.05);
    }

    /** MKT-16: eine Gutschrift 06/2026 über 23,40 € steht genau so im Monat; ohne Gutschrift unverändert. */
    public function testRevenueStatementReplacesTheCalculation(): void
    {
        $eins = (string)$this->meters->defaultId('pv_einspeisung');
        $this->monthly('pv_einspeisung', $eins, '2026-05-01', 3, 300);
        $c = $this->contracts->create('pv_einspeisung', ['meter_id' => $eins, 'provider' => 'DV', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 8.0]]]);
        $rows = fn() => array_column($this->consumption->forMeter('pv_einspeisung', $this->meters->get('pv_einspeisung', $eins)), null, 'ym');
        self::assertEqualsWithDelta(24.0, $rows()['2026-06']['cost'], 0.01);
        $this->contracts->update('pv_einspeisung', $c['id'], ['revenue_statements' => [['from' => '2026-06-01', 'to' => '2026-06-30', 'amount_eur' => '23,40']]]);
        $r = $rows();
        self::assertEqualsWithDelta(23.40, $r['2026-06']['cost'], 0.001);
        self::assertSame('statement', $r['2026-06']['revenue_source']);
        self::assertEqualsWithDelta(24.0, $r['2026-05']['cost'], 0.01, 'andere Monate unverändert');
        self::assertArrayNotHasKey('revenue_source', $r['2026-05']);
    }

    /** MKT-16: Strom −20 % nach der Zäsur → −20 %; zu wenige gemeinsame Monate → null. */
    public function testSeasonalMeanBaseline(): void
    {
        $id = (string)$this->meters->defaultId('strom');
        $per = []; for ($i = 0; $i < 24; $i++) $per[] = ($i < 12 ? 300 : 240) * (1 + 0.2 * cos($i * M_PI / 6));
        $this->monthly('strom', $id, '2024-01-01', 24, $per);
        $this->meters->update('strom', $id, ['baseline_events' => [['date' => '2025-01-01', 'label' => 'Balkonkraftwerk']]]);
        $m = $this->meters->get('strom', $id);
        $c = $this->consumption->baselineComparison('strom', $m, $this->consumption->forMeter('strom', $m));
        self::assertSame('seasonal_mean', $c['method']);
        self::assertEqualsWithDelta(-20.0, $c['delta_pct'], 0.5);
        self::assertTrue($c['significant']);
        self::assertSame(12, $c['months_compared']);

        $this->monthly('strom', $id, '2024-11-01', 4, 200);
        $m = $this->meters->get('strom', $id);
        self::assertNull($this->consumption->baselineComparison('strom', $m, $this->consumption->forMeter('strom', $m)));
    }
}
