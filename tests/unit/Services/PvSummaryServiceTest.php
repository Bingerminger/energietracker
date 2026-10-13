<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\PvSummaryService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * F1005 — Eigenverbrauch + Autarkiequote.
 *
 *   eigenverbrauch_kwh   = pv_erzeugung − pv_einspeisung
 *   eigenverbrauchsquote = eigenverbrauch_kwh / pv_erzeugung_kwh
 *   autarkiequote        = eigenverbrauch_kwh / (eigenverbrauch_kwh + bezug_kwh) ohne Speicher;
 *                          seit v3.4.0 (F1026) allgemein 1 − Netzstrom im Haus / Hausverbrauch
 *                          aus dem Energiefluss (PvSummaryService::flows())
 */
#[CoversClass(PvSummaryService::class)]
final class PvSummaryServiceTest extends ServiceTestCase
{
    public function testWithoutGenerationMeterFlagIsFalseAndQuotasAreNull(): void
    {
        // Nur pv_einspeisung anlegen, kein pv_erzeugung.
        $pv = $this->meters->create('pv_einspeisung', [
            'name' => 'Einspeisezähler',
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        $this->setReadings('pv_einspeisung', $pv['id'], [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => $pv['devices'][0]['id']],
            ['date' => '2024-03-01', 'counter' => 200.0, 'device_id' => $pv['devices'][0]['id']],
        ]);

        $out = (new PvSummaryService($this->consumption))->compute();
        self::assertFalse($out['has_generation_meter']);

        $byYm = array_column($out['monthly'], null, 'ym');
        // Quote NICHT berechenbar (kein Erzeugungszähler).
        self::assertNull($byYm['2024-02']['eigenverbrauchsquote']);
    }

    public function testEigenverbrauchAndAutarkieQuoteAreComputed(): void
    {
        // Erzeugung 600 kWh, Einspeisung 200 kWh → Eigenverbrauch 400 kWh.
        $erz = $this->meters->create('pv_erzeugung', [
            'name' => 'WR', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        $eins = $this->meters->create('pv_einspeisung', [
            'name' => 'Einsp', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        // Strom-Bezug 100 kWh → Autarkie = 400 / (400+100) = 0.80.
        $stromId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $this->setReadings('pv_erzeugung', $erz['id'], [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => $erz['devices'][0]['id']],
            ['date' => '2024-03-01', 'counter' => 600.0, 'device_id' => $erz['devices'][0]['id']],
        ]);
        $this->setReadings('pv_einspeisung', $eins['id'], [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => $eins['devices'][0]['id']],
            ['date' => '2024-03-01', 'counter' => 200.0, 'device_id' => $eins['devices'][0]['id']],
        ]);
        $this->setReadings('strom', $stromId, [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => 'd_strom_1'],
            ['date' => '2024-03-01', 'counter' => 100.0, 'device_id' => 'd_strom_1'],
        ]);

        $out = (new PvSummaryService($this->consumption))->compute();
        self::assertTrue($out['has_generation_meter']);

        $byYm = array_column($out['monthly'], null, 'ym');
        $row  = $byYm['2024-02'];

        self::assertEqualsWithDelta(400.0, (float)$row['eigenverbrauch_kwh'], 0.5);
        // Eigenverbrauchsquote = 400/600 ≈ 0.667
        self::assertEqualsWithDelta(0.6667, (float)$row['eigenverbrauchsquote'], 0.001);
        // Autarkiequote = 400/(400+100) = 0.8
        self::assertEqualsWithDelta(0.8, (float)$row['autarkiequote'], 0.001);
    }

    public function testEigenverbrauchClampedToZeroIfEinspeisungExceedsErzeugung(): void
    {
        // Datenfehler-Szenario: gemeldete Einspeisung > Erzeugung.
        // Eigenverbrauch darf NIE negativ werden.
        $erz = $this->meters->create('pv_erzeugung', [
            'name' => 'WR', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        $eins = $this->meters->create('pv_einspeisung', [
            'name' => 'Einsp', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        $this->setReadings('pv_erzeugung', $erz['id'], [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => $erz['devices'][0]['id']],
            ['date' => '2024-03-01', 'counter' => 100.0, 'device_id' => $erz['devices'][0]['id']],
        ]);
        $this->setReadings('pv_einspeisung', $eins['id'], [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => $eins['devices'][0]['id']],
            ['date' => '2024-03-01', 'counter' => 150.0, 'device_id' => $eins['devices'][0]['id']],
        ]);

        $out  = (new PvSummaryService($this->consumption))->compute();
        $row  = array_column($out['monthly'], null, 'ym')['2024-02'];
        self::assertSame(0.0, (float)$row['eigenverbrauch_kwh'],
            'Eigenverbrauch muss bei inkonsistenten Daten auf 0 geklemmt werden');
    }

    // ── v3.4.0 (F1026) — Energiefluss: eine Rechnung für Kennzahl und Bild ──

    /** Monatsstände ab $from für $months Monate, je Monat $perMonth kWh. */
    private function monthlyReadings(string $u, string $meterId, string $from, int $months, float $perMonth): void
    {
        $dev = $this->meters->get($u, $meterId)['devices'][0]['id'];
        $all = array_values(array_filter((array)$this->store->read("$u/readings.json", []), fn($r) => ($r['meter_id'] ?? null) !== $meterId));
        for ($i = 0; $i <= $months; $i++) {
            $all[] = ['id' => "r_{$meterId}_$i", 'meter_id' => $meterId, 'device_id' => $dev, 'date' => date('Y-m-d', (int)strtotime("$from +$i months")),
                      'counter' => $i * $perMonth, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write("$u/readings.json", $all);
    }

    /**
     * Haushalt 2025: Erzeugung 300, Einspeisung 100, Speicher lädt 60 und gibt
     * 50 ab, Haushaltsstrom 400 + Wärmepumpe 150 (eigener Zähler), Wallbox 80
     * (Subzähler des Haushalts) — je Monat.
     */
    private function household(string $genFrom = '2025-01-01', int $genMonths = 12): void
    {
        $gen = $this->meters->create('pv_erzeugung', ['name' => 'WR', 'installed_on' => '2024-01-01']);
        $chg = $this->meters->create('pv_erzeugung', ['name' => 'Laden', 'installed_on' => '2024-01-01', 'role' => 'battery_charge']);
        $dis = $this->meters->create('pv_erzeugung', ['name' => 'Entladen', 'installed_on' => '2024-01-01', 'role' => 'battery_discharge']);
        $eins = (string)$this->meters->defaultId('pv_einspeisung');
        $strom = (string)$this->meters->defaultId('strom');
        $wp = $this->meters->create('strom', ['name' => 'WP', 'installed_on' => '2024-01-01', 'role' => 'heat_pump']);
        $ev = $this->meters->create('strom', ['name' => 'Wallbox', 'installed_on' => '2024-01-01', 'role' => 'ev_charger', 'parent_meter_id' => $strom]);
        $this->monthlyReadings('pv_erzeugung', $gen['id'], $genFrom, $genMonths, 300);
        $this->monthlyReadings('pv_erzeugung', $chg['id'], '2025-01-01', 12, 60);
        $this->monthlyReadings('pv_erzeugung', $dis['id'], '2025-01-01', 12, 50);
        $this->monthlyReadings('pv_einspeisung', $eins, '2025-01-01', 12, 100);
        $this->monthlyReadings('strom', $strom, '2025-01-01', 12, 400);
        $this->monthlyReadings('strom', $wp['id'], '2025-01-01', 12, 150);
        $this->monthlyReadings('strom', $ev['id'], '2025-01-01', 12, 80);
    }

    /** Dieselbe Datei prüft tests/explainer.test.mjs gegen flowsFromTotals() im Bild. */
    public function testFlowFormulaMatchesTheSharedFixture(): void
    {
        $fixture = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/tests/fixtures/energy-flows.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(8, count($fixture['cases']));
        foreach ($fixture['cases'] as $c) {
            $f = PvSummaryService::flows((float)$c['in']['gen'], (float)$c['in']['feed'], (float)$c['in']['grid'], (float)$c['in']['charged'], (float)$c['in']['discharged']);
            foreach ($c['out'] as $k => $v) {
                if ($v === null) { self::assertNull($f[$k], "{$c['name']}: $k"); continue; }
                self::assertEqualsWithDelta((float)$v, (float)$f[$k], $k === 'autarky' ? 1e-4 : 0.05, "{$c['name']}: $k");
            }
        }
    }

    public function testMonthlyRowsCarryBatteryDevicesAndABalancedFlow(): void
    {
        $this->household();
        $out = (new PvSummaryService($this->consumption))->compute();
        $row = array_column($out['monthly'], null, 'ym')['2025-03'];

        self::assertSame(['charged_kwh' => 60.0, 'discharged_kwh' => 50.0], $row['battery']);
        self::assertEqualsWithDelta(150.0, $row['heat_pump_kwh'], 0.05);
        self::assertEqualsWithDelta(80.0, $row['ev_charger_kwh'], 0.05, 'Subzähler zählt als Verbraucher, nicht doppelt im Bezug');
        self::assertEqualsWithDelta(550.0, $row['bezug_kwh'], 0.05);

        $f = $row['flow'];
        self::assertEqualsWithDelta(300.0, $f['pv_house'] + $f['pv_battery'] + $f['pv_grid'], 0.05, 'Sonne raus = Erzeugung');
        self::assertEqualsWithDelta($f['pv_house'] + $f['battery_house'] + $f['grid_house'], $f['house'], 0.05, 'Haus = Zuflüsse');
        self::assertEqualsWithDelta(740.0, $f['house'], 0.05);
        self::assertEqualsWithDelta(60.0, $f['pv_battery'], 0.05);
        self::assertSame(0.0, $f['grid_battery']);
        self::assertEqualsWithDelta(150.0, $f['heat_pump'], 0.05);
        self::assertEqualsWithDelta(80.0, $f['ev_charger'], 0.05);
        self::assertEqualsWithDelta(1 - 550 / 740, $row['autarkiequote'], 1e-4);
        self::assertSame($f['autarky'], $row['autarkiequote'], 'Kennzahl und Bild aus derselben Rechnung');
    }

    /**
     * Korrektur v3.4.0: Speicherverluste zählten bis v3.3 als selbst genutzt
     * (Eigenverbrauch / (Eigenverbrauch + Bezug) = 200 / 750). Sie erreichen
     * das Haus nie: 1 − 550 / 740.
     */
    public function testAutarkyNoLongerCountsBatteryLossesAsSelfConsumption(): void
    {
        $this->household();
        $y = array_column((new PvSummaryService($this->consumption))->compute()['yearly'], null, 'year')[2025];
        self::assertSame(12, $y['months_covered']);
        self::assertEqualsWithDelta(1 - 6600 / 8880, $y['autarkiequote'], 1e-4);
        self::assertGreaterThan(0.005, abs(2400 / 9000 - $y['autarkiequote']), 'die alte Formel ergäbe 26,7 %');
        self::assertEqualsWithDelta(2400.0, $y['eigenverbrauch_kwh'], 0.5, 'Eigenverbrauch bleibt Erzeugung − Einspeisung');
        self::assertSame($y['flow']['autarky'], $y['autarkiequote']);
        self::assertEqualsWithDelta(1800.0, $y['heat_pump_kwh'], 0.5);
        self::assertEqualsWithDelta(960.0, $y['ev_charger_kwh'], 0.5);
    }

    /** Server und Bild rechnen über dieselben Monate: nur die abgedeckten. */
    public function testIncompleteYearFlowCoversTheSameMonthsAsTheQuotas(): void
    {
        $this->household('2025-07-01', 6);   // Erzeugungszähler erst ab Juli
        $y = array_column((new PvSummaryService($this->consumption))->compute()['yearly'], null, 'year')[2025];
        self::assertSame(6, $y['months_covered']);
        $f = $y['flow'];
        self::assertSame(6, $f['months']);
        self::assertEqualsWithDelta(6 * 300.0, $f['pv_house'] + $f['pv_battery'] + $f['pv_grid'], 0.5, 'nur Juli–Dezember');
        self::assertEqualsWithDelta(6 * 550.0, $f['grid_house'] + $f['grid_battery'], 0.5, 'Bezug derselben Monate, nicht des ganzen Jahres');
        self::assertEqualsWithDelta(6 * 150.0, $f['heat_pump'], 0.5);
        self::assertEqualsWithDelta(12 * 550.0, $y['bezug_kwh'], 0.5, 'die Jahressumme bleibt über alle Monate');
        self::assertEqualsWithDelta($f['autarky'], $y['autarkiequote'], 1e-9);
        self::assertNull(array_column((new PvSummaryService($this->consumption))->compute()['monthly'], null, 'ym')['2025-03']['flow'], 'nicht abgedeckt: kein Fluss');
    }

    /** Netzladung: Strom, der aus dem Netz in den Speicher ging, bleibt Netzstrom. */
    public function testGridChargingCountsAsGridPower(): void
    {
        $f = PvSummaryService::flows(180, 10, 900, 260, 240);
        self::assertEqualsWithDelta(90.0, $f['grid_battery'], 0.05);
        self::assertEqualsWithDelta(900.0, $f['grid_in_house'], 0.05);
        self::assertEqualsWithDelta(1 - 900 / 1050, $f['autarky'], 1e-4, 'Home-Assistant-Formel ergäbe 1 − 810/1050');
    }

    public function testWithoutBatteryOrDeviceMetersTheFieldsAreNull(): void
    {
        $erz = $this->meters->create('pv_erzeugung', ['name' => 'WR', 'installed_on' => '2024-01-01']);
        $this->monthlyReadings('pv_erzeugung', $erz['id'], '2025-01-01', 2, 300);
        $this->monthlyReadings('pv_einspeisung', (string)$this->meters->defaultId('pv_einspeisung'), '2025-01-01', 2, 100);
        $this->monthlyReadings('strom', (string)$this->meters->defaultId('strom'), '2025-01-01', 2, 400);
        $out = (new PvSummaryService($this->consumption))->compute();
        $row = array_column($out['monthly'], null, 'ym')['2025-01'];
        self::assertNull($row['battery']);
        self::assertNull($row['heat_pump_kwh']);
        self::assertNull($row['ev_charger_kwh']);
        self::assertNull($row['flow']['heat_pump']);
        self::assertEqualsWithDelta(200 / 600, $row['autarkiequote'], 1e-4, 'ohne Speicher wie bisher');
        $y = array_column($out['yearly'], null, 'year')[2025];
        self::assertNull($y['heat_pump_kwh']);
        self::assertNull($y['flow']['heat_pump']);
    }
}
