<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BenchmarkService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\HeatPumpService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\StromSaldoService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (F1032, GitHub #40) — Die Kaskade genau wie im Issue:
 *
 *   Z1  Zweirichtungszähler am Netzanschluss: Bezug HT/NT, Einspeisung
 *   Z2  dahinter, misst den Haushalt (HT/NT)
 *   Wärmepumpe = Z1 − Z2 je HT und NT (der Rest)
 *   Z3  PV-Erzeugung; Eigenverbrauch = Z3 − Einspeisung
 *   Verträge: Wärmepumpe HT/NT (an Z1), Haushalt HT/NT (an Z2), Einspeisevergütung (Z1)
 *
 * So bildet die App es ab: je Zählwerk ein Zähler (Z1 HT, Z1 NT; Z2 HT, Z2 NT),
 * je Zähler eine Gruppe mit Gruppenvertrag und Preis je Zählwerk, Z2 HT hinter
 * Z1 HT und Z2 NT hinter Z1 NT, Einspeisung und Erzeugung als eigene Arten.
 * In der Kaskade misst der Elternzähler alles, der Rest ist die Wärmepumpe.
 *
 * Erfundene Werte je Monat: Z1 HT 400, Z1 NT 300, Z2 HT 150, Z2 NT 100 kWh,
 * Einspeisung 250, Erzeugung 600 kWh. Von Hand:
 *   Wärmepumpe (400 − 150) × 30 ct + (300 − 100) × 20 ct + 10 € = 125,00 €
 *   Haushalt    150 × 35 ct + 100 × 25 ct + 12 €              =  89,50 €
 *   Einspeisung 250 × 8 ct                                    =  20,00 € Erlös
 *   Strom-Summe 700 kWh, 214,50 €; Saldo 214,50 − 20,00       = 194,50 €
 *   Eigenverbrauch 600 − 250 = 350 kWh; Autarkie 350 / (350 + 700) = 33,3 %
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(PvSummaryService::class)]
#[CoversClass(StromSaldoService::class)]
#[CoversClass(HeatPumpService::class)]
#[CoversClass(BenchmarkService::class)]
final class CascadeMeteringTest extends ServiceTestCase
{
    use ReportFixture;

    /** @var array<string,string> */
    private array $id = [];
    private string $g1 = '';
    private string $g2 = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->store->write('settings.json', ['active_utilities' => ['strom', 'pv_einspeisung', 'pv_erzeugung', 'waerme'],
            'wohnflaeche_m2' => 140] + (array)$this->store->read('settings.json', []));
        foreach (['strom', 'pv_einspeisung', 'pv_erzeugung', 'waerme'] as $u) {
            $this->store->write("$u/meters.json", []);
            $this->store->write("$u/contracts.json", []);
            $this->store->write("$u/readings.json", []);
        }
        $mk = fn(string $u, string $name, array $extra = []) => (string)$this->meters->create($u, $extra + ['name' => $name, 'installed_on' => '2024-01-01'])['id'];
        $this->id['z1ht'] = $mk('strom', 'Z1 Bezug HT', ['role' => 'heat_pump']);
        $this->id['z1nt'] = $mk('strom', 'Z1 Bezug NT', ['role' => 'heat_pump']);
        $this->id['z2ht'] = $mk('strom', 'Z2 Haushalt HT', ['role' => 'household', 'parent_meter_id' => $this->id['z1ht']]);
        $this->id['z2nt'] = $mk('strom', 'Z2 Haushalt NT', ['role' => 'household', 'parent_meter_id' => $this->id['z1nt']]);
        $this->g1 = (string)$this->meters->createGroup('strom', ['name' => 'Z1 Wärmepumpe'])['id'];
        $this->g2 = (string)$this->meters->createGroup('strom', ['name' => 'Z2 Haushalt'])['id'];
        foreach (['z1ht', 'z1nt'] as $k) $this->meters->update('strom', $this->id[$k], ['meter_group_id' => $this->g1]);
        foreach (['z2ht', 'z2nt'] as $k) $this->meters->update('strom', $this->id[$k], ['meter_group_id' => $this->g2]);
        $this->id['feed'] = $mk('pv_einspeisung', 'Z1 Einspeisung');
        $this->id['pv'] = $mk('pv_erzeugung', 'Z3 PV-Erzeugung');
        $this->id['heat'] = $mk('waerme', 'Wärmemenge Wärmepumpe', ['role' => 'heat_pump_output', 'heat_pump_meter_ids' => [$this->id['z1ht'], $this->id['z1nt']]]);

        $per = ['strom' => ['z1ht' => 400.0, 'z1nt' => 300.0, 'z2ht' => 150.0, 'z2nt' => 100.0],
                'pv_einspeisung' => ['feed' => 250.0], 'pv_erzeugung' => ['pv' => 600.0], 'waerme' => ['heat' => 1575.0]];
        foreach ($per as $u => $list) {
            $all = [];
            foreach ($list as $k => $perMonth) {
                $dev = $this->meters->get($u, $this->id[$k])['devices'][0]['id'];
                for ($i = 0; $i <= 24; $i++) {
                    $all[] = ['id' => "r_{$k}_$i", 'meter_id' => $this->id[$k], 'device_id' => $dev, 'date' => date('Y-m-d', (int)strtotime("2024-01-01 +$i months")),
                              'counter' => 1000.0 + $perMonth * $i, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
                }
            }
            $this->store->write("$u/readings.json", $all);
        }
        $this->contracts->create('strom', ['meter_group_id' => $this->g1, 'provider' => 'Versorger A', 'tariff_name' => 'Wärmepumpe HT/NT', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
            'working_prices_by_meter' => [$this->id['z1nt'] => [['from' => '2024-01-01', 'ct_per_kwh' => 20.0]]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 10.0]]]);
        $this->contracts->create('strom', ['meter_group_id' => $this->g2, 'provider' => 'Versorger B', 'tariff_name' => 'Haushalt HT/NT', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 35.0]],
            'working_prices_by_meter' => [$this->id['z2nt'] => [['from' => '2024-01-01', 'ct_per_kwh' => 25.0]]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]]]);
        $this->contracts->create('pv_einspeisung', ['meter_id' => $this->id['feed'], 'provider' => 'Netzbetreiber', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 8.0]]]);
    }

    private static function row(array $rows, string $ym): array
    {
        foreach ($rows as $r) if (($r['ym'] ?? '') === $ym) return $r;
        self::fail("Monat $ym fehlt");
    }

    public function testThreeContractsAsInTheIssue(): void
    {
        $wp = self::row($this->consumption->forMeter('strom', $this->meters->groupTarget('strom', $this->g1)), '2025-06');
        self::assertEqualsWithDelta(700.0, $wp['kwh'], 0.05, 'Z1 misst alles');
        self::assertEqualsWithDelta(450.0, $wp['billed_kwh'], 0.05, 'Wärmepumpe = Z1 − Z2 je HT und NT');
        self::assertEqualsWithDelta(125.0, $wp['cost'], 0.01);
        $hh = self::row($this->consumption->forMeter('strom', $this->meters->groupTarget('strom', $this->g2)), '2025-06');
        self::assertEqualsWithDelta(89.5, $hh['cost'], 0.01);
        $feed = self::row($this->consumption->forMeter('pv_einspeisung', $this->meters->get('pv_einspeisung', $this->id['feed'])), '2025-06');
        self::assertEqualsWithDelta(20.0, $feed['cost'], 0.01);
        $total = self::row($this->consumption->forUtility('strom')['monthly_total'], '2025-06');
        self::assertEqualsWithDelta(700.0, $total['kwh'], 0.05);
        self::assertEqualsWithDelta(214.5, $total['cost'], 0.01);
        // Rechnung prüfen für den Wärmepumpenvertrag (die Gruppe Z1): Menge 450 kWh, 125 €
        $bd = $this->consumption->billBreakdown('strom', $this->meters->groupTarget('strom', $this->g1), '2025-06-01', '2025-07-01');
        self::assertEqualsWithDelta(450.0, $bd['totals']['kwh'], 0.1);
        self::assertEqualsWithDelta(125.0, $bd['totals']['total'], 0.05);
    }

    public function testPvSelfConsumptionAutarkyAndBalanceWithZ1AsGridMeter(): void
    {
        $pv = (new PvSummaryService($this->consumption, $this->settings))->compute();
        $m = self::row($pv['monthly'], '2025-06');
        self::assertEqualsWithDelta(700.0, $m['bezug_kwh'], 0.05, 'Netzbezug = Z1 (HT + NT), Z2 nicht zusätzlich');
        self::assertEqualsWithDelta(350.0, $m['eigenverbrauch_kwh'], 0.05, 'Eigenverbrauch = Z3 − Einspeisung');
        self::assertEqualsWithDelta(350.0 / 1050.0, $m['autarkiequote'], 0.0005);
        $saldo = (new StromSaldoService($this->consumption))->compute();
        $s = self::row($saldo['monthly'], '2025-06');
        self::assertEqualsWithDelta(214.5, $s['bezug_cost'], 0.01, 'Bezug: beide Strom-Verträge');
        self::assertEqualsWithDelta(194.5, $s['saldo_netto'], 0.01);
    }

    public function testRolesFollowTheCascade(): void
    {
        // Jahresarbeitszahl: Strom der Wärmepumpe ist der Rest (450 kWh), nicht Z1 (700 kWh)
        $hp = (new HeatPumpService($this->meters, $this->consumption))->forYear(2025);
        self::assertEqualsWithDelta(450.0 * 12, $hp['pumps'][0]['elec_kwh'], 1.0);
        self::assertEqualsWithDelta(3.5, $hp['pumps'][0]['jaz'], 0.001);
        // Strom nach Rollen im Jahresbericht: Wärmepumpe 5.400, Haushalt 3.000 + 4.200 selbst genutzt
        $model = $this->reportServices()['model']->build(2025);
        $roles = null;
        foreach ($model['pages'] as $p) if (isset($p['roles']['rows'])) $roles = $p['roles']['rows'];
        self::assertNotNull($roles, 'Rollen im Bericht');
        $by = array_column($roles, 'kwh', 'role');
        self::assertEqualsWithDelta(450.0 * 12, $by['heat_pump'], 1.0);
        self::assertEqualsWithDelta(250.0 * 12 + 350.0 * 12, $by['household'], 1.0);
        // Effizienz: Heizstrom ist der Rest am Wärmepumpenzähler (5.400 kWh), nicht Z1 (8.400 kWh)
        $bench = new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
        $year = (new \ReflectionMethod($bench, 'yearData'))->invoke($bench, 'strom', 2025);
        self::assertEqualsWithDelta(450.0 * 12, $year['kwh'], 1.0);
    }

    /**
     * Das Beispiel im Anwendungsfall H (DE und EN, nach `<!-- doc-claim: cascade-example`) nennt
     * Mengen und Beträge; jede Zahl mit kWh, € oder % muss die gerechnete sein (Doku-Behauptung am Test).
     */
    public function testTheDocumentedExampleIsWhatTheAppCalculates(): void
    {
        $wp = self::row($this->consumption->forMeter('strom', $this->meters->groupTarget('strom', $this->g1)), '2025-06');
        $hh = self::row($this->consumption->forMeter('strom', $this->meters->groupTarget('strom', $this->g2)), '2025-06');
        $total = self::row($this->consumption->forUtility('strom')['monthly_total'], '2025-06');
        $feed = self::row($this->consumption->forMeter('pv_einspeisung', $this->meters->get('pv_einspeisung', $this->id['feed'])), '2025-06');
        $pv = self::row((new PvSummaryService($this->consumption, $this->settings))->compute()['monthly'], '2025-06');
        $expected = [400.0, 300.0, 150.0, 100.0, 250.0, 600.0,
            10.0, $wp['cost'], 12.0, $hh['cost'], $total['kwh'], $total['cost'], $feed['cost'],
            $pv['eigenverbrauch_kwh'], round($pv['autarkiequote'] * 100)];
        foreach (['docs/anleitungen/anwendungsfaelle.md', 'docs/en/anleitungen/anwendungsfaelle.md'] as $rel) {
            $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            $pos = strpos($text, '<!-- doc-claim: cascade-example');
            self::assertNotFalse($pos, "$rel: Beispiel fehlt");
            preg_match('/```text\n(.*?)```/s', substr($text, (int)$pos), $m);
            preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:kWh|€|%)(?!\w)/u', $m[1] ?? '', $all);
            $found = array_map(fn($v) => (float)str_replace(',', '.', $v), $all[1]);
            self::assertEqualsWithDelta($expected, $found, 0.005, "$rel: Zahlen im Beispiel");
        }
    }

    /** Modul 2 hinter dem Haushaltszähler: Der Wärmepumpen-Subzähler zählt in der Effizienz (Anwendungsfall C). */
    public function testHeatPumpSubmeterBehindHouseholdCountsInEfficiency(): void
    {
        // aus der Kaskade wird „Haushalt vorn, Wärmepumpe dahinter“: Z1 Haushalt, Z2 Wärmepumpe
        foreach (['z1ht', 'z1nt'] as $k) $this->meters->update('strom', $this->id[$k], ['role' => 'household']);
        foreach (['z2ht', 'z2nt'] as $k) $this->meters->update('strom', $this->id[$k], ['role' => 'heat_pump']);
        $bench = new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
        $year = (new \ReflectionMethod($bench, 'yearData'))->invoke($bench, 'strom', 2025);
        self::assertEqualsWithDelta(250.0 * 12, $year['kwh'], 1.0, 'die Subzähler der Wärmepumpe (HT 150 + NT 100)');
    }
}
