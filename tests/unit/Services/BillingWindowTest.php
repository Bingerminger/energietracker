<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DeliveryConsumptionService;
use Energietracker\Services\I18nService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\RegressionService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\SettingsService;
use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.6.0 (Paket abrechnung) — Zahlen stimmig an den Stellen, an denen die
 * Konsistenzprüfung über die Sichten (ViewSumsConsistencyTest) Abweichungen
 * fand:
 *
 *   1. Rechnungsprüfung Wasser: Schmutzwasser mit eigenem Zähler bzw. abzüglich
 *      Gartenzähler — bis v3.5 fehlte es (und damit in den Abrechnungsjahren).
 *   2. Saldo eines laufenden Vertrags: ab dem Tag nach der letzten gebuchten
 *      Rechnung, nicht ab Vertragsbeginn.
 *   3. Zählergruppen: ein Subzähler, dessen Elternzähler Mitglied ist, zählt
 *      nicht doppelt (Verbrauch, Rechnungsprüfung, Abrechnungsjahr der Gruppe).
 *   4. Prognose-Werkstatt: der Rest des angebrochenen Monats im Endsaldo.
 */
final class BillingWindowTest extends ServiceTestCase
{
    private const DEVICE = ['serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        'removed_on' => null, 'final_counter' => null, 'reason' => null];

    private static function reading(string $id, string $meter, string $dev, string $date, float $c): array
    {
        return ['id' => $id, 'meter_id' => $meter, 'device_id' => $dev, 'date' => $date, 'counter' => $c,
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
    }

    private static function meter(string $id, ?string $parent = null, ?string $group = null): array
    {
        return ['id' => $id, 'name' => $id, 'icon' => '', 'created_at' => '2024-01-01', 'active' => true, 'notes' => '',
                'devices' => [['id' => "d_$id"] + self::DEVICE], 'parent_meter_id' => $parent, 'meter_group_id' => $group];
    }

    // ── 1. Wasser: Schmutzwasser nach eigenem Zähler bzw. abzüglich Gartenzähler ──

    private function water(array $schmutzwasser, string $second, ?string $parent): array
    {
        $this->store->write('wasser/meters.json', [self::meter('m_main'), self::meter($second, $parent)]);
        $this->store->write('wasser/readings.json', [
            self::reading('r1', 'm_main', 'd_m_main', '2024-06-01', 0.0), self::reading('r2', 'm_main', 'd_m_main', '2024-07-01', 20.0),
            self::reading('r3', $second, "d_$second", '2024-06-01', 0.0), self::reading('r4', $second, "d_$second", '2024-07-01', 6.0),
        ]);
        $this->store->write('wasser/contracts.json', [[
            'id' => 'c_w', 'meter_id' => 'm_main', 'provider' => 'Wasserwerk', 'tariff_name' => 'Wasser', 'start' => '2024-01-01', 'end' => null,
            'trinkwasser' => ['working_prices' => [['from' => '2024-01-01', 'ct_per_m3' => 200.0]], 'base_prices' => []],
            'schmutzwasser' => $schmutzwasser + ['working_prices' => [['from' => '2024-01-01', 'ct_per_m3' => 300.0]]],
            'niederschlagswasser' => ['rates' => []], 'advance_payments' => [], 'bonuses' => [], 'special_payments' => [],
        ]]);
        $main = $this->meters->get('wasser', 'm_main');
        $june = array_column($this->consumption->forMeter('wasser', $main), null, 'ym')['2024-06'];
        return [$this->consumption->billBreakdown('wasser', $main, '2024-06-01', '2024-07-01'), $june];
    }

    public function testWaterBillCheckCountsTheSewageOfASeparateMeter(): void
    {
        [$bd, $june] = $this->water(['basis' => 'separater_zaehler', 'separater_zaehler_meter_id' => 'm_sewage'], 'm_sewage', null);
        self::assertEqualsWithDelta(6.0, array_sum(array_column($bd['rows'], 'sw_m3')), 0.01, 'Schmutzwasser = eigener Zähler');
        self::assertEqualsWithDelta(20 * 2.0 + 6 * 3.0, $bd['totals']['energy_cost'], 0.01);
        self::assertEqualsWithDelta((float)$june['cost'], $bd['totals']['total'], 0.01, 'wie die Monatszeile');
    }

    public function testWaterBillCheckDeductsTheGardenMeter(): void
    {
        [$bd, $june] = $this->water(['basis' => 'trinkwasser_minus_abzug', 'abzug_meter_ids' => ['m_garden']], 'm_garden', 'm_main');
        self::assertEqualsWithDelta(14.0, array_sum(array_column($bd['rows'], 'sw_m3')), 0.01, 'Schmutzwasser = Haupt − Garten');
        self::assertEqualsWithDelta(20 * 2.0 + 14 * 3.0, $bd['totals']['energy_cost'], 0.01);
        self::assertEqualsWithDelta((float)$june['cost'], $bd['totals']['total'], 0.01, 'wie die Monatszeile');
    }

    // ── 2. Saldo nach der letzten gebuchten Rechnung ──

    /** Ablesung je Monatserstem 2025-01 bis 2026-10, 250 kWh im Monat. */
    private static function stromReadings(string $meter = 'm_strom_default'): array
    {
        $out = [];
        for ($i = 0; $i <= 21; $i++) {
            $out[] = self::reading("r_$i", $meter, 'd_m_strom_default', date('Y-m-d', (int)strtotime("2025-01-01 +$i months")), 250.0 * $i);
        }
        return $out;
    }

    private static function stromContract(string $start, array $payments): array
    {
        return ['id' => 'c_s', 'meter_id' => 'm_strom_default', 'provider' => 'Werk', 'tariff_name' => 'Strom', 'start' => $start, 'end' => null,
                'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 10.0]],
                'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 90.0]], 'bonuses' => [], 'special_payments' => $payments,
                'is_shadow' => false, 'shadow_label' => null];
    }

    /** Ein zweites Datenverzeichnis mit eigenen Diensten — für den Vergleich „als hätte der Vertrag erst dann begonnen“. */
    private function twin(): array
    {
        $dir = sys_get_temp_dir() . '/et-twin-' . bin2hex(random_bytes(5));
        mkdir($dir, 0755, true);
        $store = new JsonStore((string)realpath($dir));
        (new Migrator($store))->initFresh();
        $settings = new SettingsService($store);
        $i18n = new I18nService(dirname(__DIR__, 3) . '/public/locales', $settings);
        $meters = new MeterService($store, $i18n);
        $readings = new ReadingService($store, $meters, $i18n);
        $contracts = new ContractService($store, $meters, $i18n);
        $cons = new ConsumptionService($store, $meters, $readings, $contracts, $settings, $i18n, new RegressionService(), new DeliveryConsumptionService($store, $settings));
        return [$store, $meters, $cons, $dir];
    }

    private function seedStrom(\Energietracker\Storage\JsonStore $store, string $start, array $payments): void
    {
        $store->write('strom/meters.json', [self::meter('m_strom_default')]);
        $store->write('strom/readings.json', self::stromReadings());
        $store->write('strom/contracts.json', [self::stromContract($start, $payments)]);
    }

    private function contractRow(ConsumptionService $c, MeterService $m): array
    {
        return $c->contractStatus('strom', $m->get('strom', 'm_strom_default'))['contracts'][0];
    }

    public function testRunningContractBalanceStartsTheDayAfterTheLastBookedBill(): void
    {
        $extra = ['id' => 'sp_extra', 'date' => '2026-02-01', 'kind' => 'abschlagszahlung', 'amount_eur' => 50.0, 'note' => ''];
        $before = ['id' => 'sp_old', 'date' => '2025-03-01', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 40.0, 'note' => ''];
        $this->seedStrom($this->store, '2025-01-01', [$before, $extra]);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        // Rechnung bis Mitte August, Ergebnis gebucht (Rückzahlung, am Rechnungsdatum)
        $b = $bills->create('strom', ['meter_id' => 'm_strom_default', 'period_from' => '2025-01-01', 'period_to' => '2025-08-14', 'issued_on' => '2025-09-10',
            'invoice' => ['energy_kwh' => 1866.9, 'amount_eur' => 640.0, 'advances_paid_eur' => 680.0]]);
        $bills->book('strom', $b['id']);
        $s = $this->contractRow($this->consumption, $this->meters);
        self::assertSame('2025-08-15', $s['balance_from'], 'Saldo ab dem Tag nach der gebuchten Rechnung');
        self::assertSame(['2026-02-01'], array_column($s['special_payments'], 'date'), 'zählt nur die Abschlagszahlung danach');

        // dieselben Daten, als hätte der Vertrag am 15.08. begonnen (ohne Rechnung, nur die Zahlung danach)
        [$store, $meters, $cons, $dir] = $this->twin();
        try {
            $this->seedStrom($store, '2025-08-15', [$extra]);
            $t = $this->contractRow($cons, $meters);
            self::assertSame('2025-08-15', $t['balance_from']);
            foreach (['current_balance', 'projected_end_balance', 'cost_to_date', 'advance_paid', 'energy_cost_to_date', 'base_to_date'] as $k) {
                self::assertEqualsWithDelta((float)$t[$k], (float)$s[$k], 0.02, $k);
            }
            self::assertEquals(array_column($t['balance_path'], 'balance'), array_column($s['balance_path'], 'balance'), 'Saldo-Verlauf ab dem Saldobeginn');
            self::assertEqualsWithDelta((float)$s['projected_end_balance'], (float)end($s['balance_path'])['balance'], 0.01, 'endet beim erwarteten Saldo');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testARecordedButUnbookedBillLeavesTheBalanceAsBefore(): void
    {
        $this->seedStrom($this->store, '2025-01-01', []);
        $before = $this->contractRow($this->consumption, $this->meters);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bills->create('strom', ['meter_id' => 'm_strom_default', 'period_from' => '2025-01-01', 'period_to' => '2025-08-14', 'issued_on' => '2025-09-10',
            'invoice' => ['amount_eur' => 640.0, 'advances_paid_eur' => 680.0]]);
        $after = $this->contractRow($this->consumption, $this->meters);
        self::assertSame('2025-01-01', $after['balance_from'], 'ohne Buchung bleibt das Ergebnis im Saldo');
        self::assertSame($before['current_balance'], $after['current_balance']);
    }

    // ── 3. Zählergruppen ohne doppelten Subzähler ──

    public function testGroupSumsSkipASubMeterWhoseParentIsAMember(): void
    {
        $this->store->write('strom/meter_groups.json', [['id' => 'g_haus', 'name' => 'Haus', 'created_at' => '2025-01-01']]);
        $this->store->write('strom/meters.json', [self::meter('m_p', null, 'g_haus'), self::meter('m_c', 'm_p', 'g_haus')]);
        $rows = [];
        foreach ([['m_p', 1000.0], ['m_c', 300.0]] as [$id, $year]) {
            for ($i = 0; $i <= 12; $i++) $rows[] = self::reading("r_{$id}_$i", $id, "d_$id", date('Y-m-d', (int)strtotime("2025-01-01 +$i months")), $year / 12 * $i);
        }
        $this->store->write('strom/readings.json', $rows);
        $group = $this->meters->groupTarget('strom', 'g_haus');
        $kwh = array_sum(array_map(fn($r) => (float)$r['kwh'], array_filter($this->consumption->forMeter('strom', $group), fn($r) => str_starts_with($r['ym'], '2025-'))));
        self::assertEqualsWithDelta(1000.0, $kwh, 0.5, 'Verbrauch der Gruppe = Elternzähler');
        self::assertEqualsWithDelta(1000.0, $this->consumption->billBreakdown('strom', $group, '2025-01-01', '2026-01-01')['totals']['kwh'], 0.5, 'Rechnungsprüfung der Gruppe');
        $bp = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption,
            new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n));
        $p = array_values(array_filter($bp->forTarget('strom', $group, false, '2026-03-01')['periods'], fn($x) => $x['from'] === '2025-01-01'))[0] ?? self::fail('Periode 2025 fehlt');
        self::assertEqualsWithDelta(1000.0, (float)$p['kwh'], 0.5, 'Abrechnungsjahr der Gruppe');
        self::assertSame(['m_p'], array_column($this->meters->groupSumMembers('strom', 'g_haus'), 'id'));
    }

    // ── 4. Werkstatt: Rest des angebrochenen Monats ──

    public function testWorkshopEndBalanceCountsTheRestOfTheMonth(): void
    {
        $running = ['from' => '2026-01-01', 'until' => '2026-12-31', 'as_of' => '2026-10-18', 'balance' => -20.0];
        $history = [['ym' => '2026-10', 'days' => 18, 'kwh_cost' => 36.0, 'base_price_eur' => 31.0, 'advance_eur' => 62.0]];
        $months = [['ym' => '2026-11', 'days' => 30, 'advance' => 60.0], ['ym' => '2026-12', 'days' => 31, 'advance' => 60.0]];
        $rest = ScenarioService::restOfMonth($running, $months, $history);
        self::assertSame(['days' => 13, 'cost' => 39.0, 'advance' => 26.0], $rest, '13 Tage: 2 € Arbeitspreis je Tag, Grundpreis und Abschlag anteilig');
        $b = ScenarioService::billingBalance($running, $months, [70.0, 80.0], [70.0, 80.0], $rest);
        self::assertEqualsWithDelta(-20.0 + (39.0 - 26.0) + (70 - 60) + (80 - 60), $b['base_end_balance'], 0.001);
        self::assertSame(13, $b['rest_days']);
        // deckt die Prognose den Monat (oder ist er zu Ende), fehlt nichts
        self::assertNull(ScenarioService::restOfMonth($running, [['ym' => '2026-10', 'days' => 31, 'advance' => 60.0]], $history));
        self::assertNull(ScenarioService::restOfMonth(['as_of' => '2026-10-31'] + $running, $months, $history));
    }
}
