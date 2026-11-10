<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\CascadeFeed;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\HeatPumpService;
use Energietracker\Services\MeterService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.10.0 (GitHub #40) — Kaskade je Zählwerk und Einspeisung in der Kaskade.
 *
 *   Netz ─ Z1 ─┬─ Wärmepumpe (der Rest von Z1)
 *              └─ Z2 ─┬─ Haushalt mit Wallbox
 *                     └─ Z3 ─ PV
 *
 * Jeder der drei Zähler hat ein Einspeise-Zählwerk 2.8.0: Z3 zählt den ganzen
 * Sonnenstrom (Erzeugung), Z2 was der Haushalt davon übrig lässt, Z1 was nach der
 * Wärmepumpe ins Netz geht. Erfundene Werte je Monat:
 *
 *   Z1 Bezug HT 400, NT 300 · Z2 Bezug HT 150, NT 100 kWh
 *   Z1 2.8.0 250 · Z2 2.8.0 400 · Z3 2.8.0 600 kWh · Wärme der Wärmepumpe 1.575 kWh
 *
 * Von Hand (unabhängig von der App):
 *   Sonnenstrom selbst genutzt  Haushalt 600 − 400 = 200, Wärmepumpe 400 − 250 = 150, zusammen 350
 *   Wärmepumpe gesamt           (400 − 150) + (300 − 100) = 450 aus dem Netz + 150 Sonne = 600
 *   Haushalt gesamt             150 + 100 = 250 aus dem Netz + 200 Sonne = 450
 *   Jahresarbeitszahl           1.575 / 600 = 2,625 (ohne das Zählwerk an Z2: 1.575 / 450 = 3,5)
 *   Einspeisung (Vergütung)     nur Z1: 250 kWh × 8 ct = 20 €
 */
#[CoversClass(CascadeFeed::class)]
#[CoversClass(PvSummaryService::class)]
#[CoversClass(HeatPumpService::class)]
#[CoversClass(MeterService::class)]
#[CoversClass(ConsumptionService::class)]
final class CascadeFeedTest extends ServiceTestCase
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
            foreach (['meters', 'contracts', 'readings'] as $pot) $this->store->write("$u/$pot.json", []);
        }
        $mk = fn(string $u, string $name, array $extra = []) => (string)$this->meters->create($u, $extra + ['name' => $name, 'installed_on' => '2024-01-01'])['id'];
        $this->id['z1ht'] = $mk('strom', 'Z1 Bezug HT', ['role' => 'heat_pump', 'obis' => '1.8.1']);
        $this->id['z1nt'] = $mk('strom', 'Z1 Bezug NT', ['role' => 'heat_pump', 'obis' => '1.8.2']);
        $this->id['z2ht'] = $mk('strom', 'Z2 Haushalt HT', ['parent_meter_id' => $this->id['z1ht'], 'obis' => '1.8.1']);
        $this->id['z2nt'] = $mk('strom', 'Z2 Haushalt NT', ['parent_meter_id' => $this->id['z1nt'], 'obis' => '1.8.2']);
        $this->g1 = (string)$this->meters->createGroup('strom', ['name' => 'Z1 Wärmepumpe'])['id'];
        $this->g2 = (string)$this->meters->createGroup('strom', ['name' => 'Z2 Haushalt'])['id'];
        foreach (['z1ht', 'z1nt'] as $k) $this->meters->update('strom', $this->id[$k], ['meter_group_id' => $this->g1]);
        foreach (['z2ht', 'z2nt'] as $k) $this->meters->update('strom', $this->id[$k], ['meter_group_id' => $this->g2]);
        $this->id['feed1'] = $mk('pv_einspeisung', 'Z1 Einspeisung', ['obis' => '2.8.0']);
        $this->id['feed2'] = $mk('pv_einspeisung', 'Z2 Einspeisung', ['obis' => '2.8.0', 'cascade_meter_id' => $this->id['z2ht']]);
        $this->id['gen'] = $mk('pv_erzeugung', 'Z3 PV', ['obis' => '2.8.0']);
        $this->id['heat'] = $mk('waerme', 'Wärmemenge Wärmepumpe', ['role' => 'heat_pump_output', 'heat_pump_meter_ids' => [$this->id['z1ht'], $this->id['z1nt']]]);
        $this->readings([
            'strom' => ['z1ht' => 400.0, 'z1nt' => 300.0, 'z2ht' => 150.0, 'z2nt' => 100.0],
            'pv_einspeisung' => ['feed1' => 250.0, 'feed2' => 400.0], 'pv_erzeugung' => ['gen' => 600.0], 'waerme' => ['heat' => 1575.0],
        ]);
        $this->contracts->create('strom', ['meter_group_id' => $this->g1, 'provider' => 'Versorger A', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
            'working_prices_by_meter' => [$this->id['z1nt'] => [['from' => '2024-01-01', 'ct_per_kwh' => 20.0]]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 10.0]]]);
        $this->contracts->create('strom', ['meter_group_id' => $this->g2, 'provider' => 'Versorger B', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 35.0]],
            'working_prices_by_meter' => [$this->id['z2nt'] => [['from' => '2024-01-01', 'ct_per_kwh' => 25.0]]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]]]);
        $this->contracts->create('pv_einspeisung', ['meter_id' => $this->id['feed1'], 'provider' => 'Netzbetreiber', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 8.0]]]);
    }

    /**
     * Monatliche Stände ab 01.01.2024 (25 Ablesungen, 24 Monate). `$override` setzt die Menge
     * einzelner Monate (Schlüssel → ym → kWh), `$skip` lässt Ablesungen weg (Schlüssel → Datumsliste).
     *
     * @param array<string,array<string,float>> $per
     */
    private function readings(array $per, array $override = [], array $skip = []): void
    {
        foreach ($per as $u => $list) {
            $all = array_values(array_filter((array)$this->store->read("$u/readings.json", []), fn($r) => !isset($list[array_search($r['meter_id'], $this->id, true)])));
            foreach ($list as $k => $perMonth) {
                $dev = $this->meters->get($u, $this->id[$k])['devices'][0]['id'];
                $counter = 1000.0;
                for ($i = 0; $i <= 24; $i++) {
                    $date = date('Y-m-d', (int)strtotime("2024-01-01 +$i months"));
                    if ($i > 0) $counter += $override[$k][date('Y-m', (int)strtotime("$date -1 month"))] ?? $perMonth;
                    if (in_array($date, $skip[$k] ?? [], true)) continue;
                    $all[] = ['id' => "r_{$k}_$i", 'meter_id' => $this->id[$k], 'device_id' => $dev, 'date' => $date, 'counter' => $counter,
                              'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
                }
            }
            $this->store->write("$u/readings.json", $all);
        }
    }

    private static function row(array $rows, string $ym): array
    {
        foreach ($rows as $r) if (($r['ym'] ?? '') === $ym) return $r;
        self::fail("Monat $ym fehlt");
    }

    private function pv(): array
    {
        return (new PvSummaryService($this->consumption, $this->settings))->compute();
    }

    public function testFeedRegistersBehindTheGridConnectionAreNotFeedIn(): void
    {
        self::assertFalse(MeterService::countsInTotals($this->meters->get('pv_einspeisung', $this->id['feed2'])));
        self::assertTrue(MeterService::countsInTotals($this->meters->get('pv_einspeisung', $this->id['feed1'])));
        $total = self::row($this->consumption->forUtility('pv_einspeisung')['monthly_total'], '2025-06');
        self::assertEqualsWithDelta(250.0, $total['kwh'], 0.05, 'ins Netz nur Z1, Z2 nicht zusätzlich');
        self::assertEqualsWithDelta(20.0, $total['cost'], 0.01, 'Vergütung nur für Z1');
        $m = self::row($this->pv()['monthly'], '2025-06');
        self::assertEqualsWithDelta(250.0, $m['einspeisung_kwh'], 0.05);
        self::assertEqualsWithDelta(350.0, $m['eigenverbrauch_kwh'], 0.05, 'zusammen wie bisher: Erzeugung − Einspeisung am Netz');
    }

    public function testSelfConsumptionPerStrandFromTheDifferences(): void
    {
        $pv = $this->pv();
        $c = self::row($pv['monthly'], '2025-06')['cascade'];
        self::assertEqualsWithDelta(400.0, $c['feed_kwh'], 0.05);
        $by = array_column($c['strands'], null, 'position');
        self::assertSame('household', $by['behind']['role']);
        self::assertSame('Z2 Haushalt', $by['behind']['name']);
        self::assertEqualsWithDelta(200.0, $by['behind']['kwh'], 0.05, 'Haushalt: Z3 − Z2 = 600 − 400');
        self::assertSame('heat_pump', $by['front']['role']);
        self::assertSame('Z1 Wärmepumpe', $by['front']['name']);
        self::assertEqualsWithDelta(150.0, $by['front']['kwh'], 0.05, 'Wärmepumpe: Z2 − Z1 = 400 − 250');
        self::assertFalse($c['inconsistent']);
        $y = array_column($pv['yearly'], null, 'year')[2025]['cascade'];
        $yb = array_column($y['strands'], null, 'position');
        self::assertSame(12, $y['months']);
        self::assertEqualsWithDelta(200.0 * 12, $yb['behind']['kwh'], 0.5);
        self::assertEqualsWithDelta(150.0 * 12, $yb['front']['kwh'], 0.5);
        self::assertEqualsWithDelta(400.0 * 12, $y['feed_kwh'], 0.5);
        // ohne das Zählwerk an Z2: kein Feld, Eigenverbrauch wie bisher
        $this->meters->update('pv_einspeisung', $this->id['feed2'], ['cascade_meter_id' => '']);
        $this->store->write('pv_einspeisung/readings.json', array_values(array_filter($this->store->read('pv_einspeisung/readings.json', []),
            fn($r) => $r['meter_id'] !== $this->id['feed2'])));
        $this->meters->delete('pv_einspeisung', $this->id['feed2']);
        self::assertNull(self::row($this->pv()['monthly'], '2025-06')['cascade']);
    }

    public function testTheHeatPumpGetsItsShareOfTheSun(): void
    {
        // Jahresarbeitszahl: Strom der Wärmepumpe = Rest aus dem Netz + Sonnenstrom ihres Strangs
        $hp = (new HeatPumpService($this->meters, $this->consumption))->forYear(2025);
        self::assertEqualsWithDelta(600.0 * 12, $hp['pumps'][0]['elec_kwh'], 1.0);
        self::assertEqualsWithDelta(2.63, $hp['pumps'][0]['jaz'], 0.001, '2,625 auf zwei Stellen');
        // Energiefluss: die Wärmepumpe mit 600 kWh (bis v3.9 die ganze Messung von Z1: 700)
        $m = self::row($this->pv()['monthly'], '2025-06');
        self::assertEqualsWithDelta(600.0, $m['heat_pump_kwh'], 0.05);
        self::assertEqualsWithDelta(600.0, $m['flow']['heat_pump'], 0.05);
        self::assertEqualsWithDelta(1050.0, $m['flow']['house'], 0.05, 'Haus = 700 Netz + 350 Sonne');
        // Strom nach Rollen im Jahresbericht: Wärmepumpe 600 × 12, Haushalt 450 × 12
        $roles = null;
        foreach ($this->reportServices()['model']->build(2025)['pages'] as $p) if (isset($p['roles']['rows'])) $roles = $p['roles']['rows'];
        self::assertNotNull($roles);
        $by = array_column($roles, 'kwh', 'role');
        self::assertEqualsWithDelta(600.0 * 12, $by['heat_pump'], 1.0);
        self::assertEqualsWithDelta(450.0 * 12, $by['household'], 1.0);
    }

    /**
     * Rest je Zählwerk: Im März 2024 misst Z1 HT nur 100 kWh (Ablesefehler), Z2 HT 150 kWh.
     * Je Zählwerk wie beim Versorger: HT 100 − 150 = −50 → 0 kWh (ausgewiesen), NT 300 − 100 = 200 kWh.
     * Wärmepumpe 0 × 30 ct + 200 × 20 ct + 10 € = 50,00 €. In Summe wären es (400 − 250) = 150 kWh gewesen.
     */
    public function testTheRestIsBilledPerRegisterNotInSum(): void
    {
        $this->readings(['strom' => ['z1ht' => 400.0, 'z1nt' => 300.0, 'z2ht' => 150.0, 'z2nt' => 100.0]], ['z1ht' => ['2024-03' => 100.0]]);
        $r = self::row($this->consumption->forMeter('strom', $this->meters->groupTarget('strom', $this->g1)), '2024-03');
        self::assertEqualsWithDelta(400.0, $r['kwh'], 0.05, 'gemessen 100 + 300');
        self::assertEqualsWithDelta(200.0, $r['billed_kwh'], 0.05, 'HT − HT unter null zählt nicht gegen NT − NT');
        self::assertEqualsWithDelta(50.0, $r['cost'], 0.01);
        self::assertEqualsWithDelta(50.0, $r['rest_of']['short_kwh'], 0.05);
        $regs = array_column($r['rest_of']['registers'], null, 'meter_id');
        self::assertCount(2, $regs);
        self::assertEqualsWithDelta(100.0, $regs[$this->id['z1ht']]['measured'], 0.05);
        self::assertEqualsWithDelta(150.0, $regs[$this->id['z1ht']]['deducted'], 0.05);
        self::assertEqualsWithDelta(-50.0, $regs[$this->id['z1ht']]['rest'], 0.05);
        self::assertEqualsWithDelta(50.0, $regs[$this->id['z1ht']]['short_kwh'], 0.05);
        self::assertSame('1.8.1', $regs[$this->id['z1ht']]['obis']);
        self::assertEqualsWithDelta(200.0, $regs[$this->id['z1nt']]['rest'], 0.05);
        self::assertArrayNotHasKey('short_kwh', $regs[$this->id['z1nt']]);
        // Rechnung prüfen für die Gruppe im März: dieselben Zeilen je Zählwerk, abgerechnet 200 kWh
        $bc = $this->consumption->billBreakdown('strom', $this->meters->groupTarget('strom', $this->g1), '2024-03-01', '2024-04-01');
        $br = array_column($bc['totals']['rest_of']['registers'] ?? [], null, 'meter_id');
        self::assertEqualsWithDelta(-50.0, $br[$this->id['z1ht']]['rest'] ?? null, 0.05);
        self::assertEqualsWithDelta(200.0, $br[$this->id['z1nt']]['rest'] ?? null, 0.05);
        self::assertEqualsWithDelta(200.0, $bc['totals']['kwh'], 0.1);
        // ein normaler Monat: je Zählwerk 250 und 200
        $n = self::row($this->consumption->forMeter('strom', $this->meters->groupTarget('strom', $this->g1)), '2024-06');
        self::assertSame([250.0, 200.0], array_map(fn($x) => (float)$x['rest'], $n['rest_of']['registers']));
        // Vertragskarte ab Beginn der Periode 2024 (der Hinweis zählt bis zur letzten Ablesung):
        // je Zählwerk zusammengezählt, der März bei HT unter null ausgewiesen.
        // v3.12.0 — am Stichtag 31.12.2024 ist die letzte Ablesung die vom 01.12.2024: elf Monate,
        // HT 10 × 250 − 50 (März), abgezogen 11 × 150, NT 11 × 200. Bis v3.11 zählten die
        // Ablesungen bis 01.01.2026 mit (24 Monate), die es an diesem Tag noch nicht gab.
        $card = $this->consumption->contractStatus('strom', $this->meters->groupTarget('strom', $this->g1), '2024-12-31')['contracts'][0];
        $cr = array_column($card['rest_of']['registers'], null, 'meter_id');
        self::assertEqualsWithDelta(250.0 * 10 - 50.0, $cr[$this->id['z1ht']]['rest'], 0.5);
        self::assertEqualsWithDelta(150.0 * 11, $cr[$this->id['z1ht']]['deducted'], 0.5);
        self::assertEqualsWithDelta(50.0, $cr[$this->id['z1ht']]['short_kwh'], 0.5);
        self::assertEqualsWithDelta(200.0 * 11, $cr[$this->id['z1nt']]['rest'], 0.5);
    }

    /** Zeilen je Zählwerk zweier Hinweise (etwa zweier Zeiträume) werden je Zähler zusammengezählt. */
    public function testRegisterLinesAddUpPerMeter(): void
    {
        $m = ['id' => 'z1ht', 'name' => 'Z1 HT', 'obis' => '1-0:1.8.1*255'];
        $a = \Energietracker\Services\SubmeterRest::asRegister(['measured' => 100.0, 'submeters' => [['meter_id' => 's', 'name' => 'S', 'kwh' => 150.0]], 'rest' => -50.0, 'short_kwh' => 50.0], $m);
        $b = \Energietracker\Services\SubmeterRest::asRegister(['measured' => 400.0, 'submeters' => [['meter_id' => 's', 'name' => 'S', 'kwh' => 150.0]], 'rest' => 250.0], $m);
        $sum = \Energietracker\Services\SubmeterRest::mergeRestOf($a, $b);
        self::assertCount(1, $sum['registers']);
        self::assertSame('1.8.1', $sum['registers'][0]['obis']);
        self::assertEqualsWithDelta(500.0, $sum['registers'][0]['measured'], 0.05);
        self::assertEqualsWithDelta(300.0, $sum['registers'][0]['deducted'], 0.05);
        self::assertEqualsWithDelta(200.0, $sum['registers'][0]['rest'], 0.05);
        self::assertEqualsWithDelta(50.0, $sum['registers'][0]['short_kwh'], 0.05);
    }

    /**
     * Mischpreis der Gruppe (Prognose, Saldo-Schätzung, Wechsel): gewichtet mit der Menge, die der
     * Vertrag je Zählwerk rechnet (Rest), nicht mit der Messung. Letzte zwölf Monate je Zählwerk:
     * HT 250, NT 200 → (250 × 30 + 200 × 20) / 450 = 25,5556 ct (mit der Messung 400/300: 25,7143 ct).
     */
    public function testTheBlendedPriceIsWeightedWithTheRestPerRegister(): void
    {
        $grp = $this->meters->groupTarget('strom', $this->g1);
        $c = $this->contracts->list('strom', $this->g1)[0];
        $view = $this->consumption->contractView('strom', $grp, $c);
        self::assertEqualsWithDelta(11500.0 / 450.0, $view['working_prices'][0]['ct_per_kwh'], 0.0001);
        // der Haushalt (ohne Subzähler) bleibt bei der Messung: (150 × 35 + 100 × 25) / 250 = 31 ct
        $hh = $this->consumption->contractView('strom', $this->meters->groupTarget('strom', $this->g2), $this->contracts->list('strom', $this->g2)[0]);
        self::assertEqualsWithDelta(31.0, $hh['working_prices'][0]['ct_per_kwh'], 0.0001);
    }

    /**
     * Unordentlich: An Z2 fehlen vier Ablesungen (01.04. bis 01.07.2025), am 16.10.2025 wird das
     * Gerät von Z2 getauscht (alter Endstand, neuer Zähler beginnt bei 5). Erwartung von Hand:
     * Über die Lücke stimmt die Summe (5 Monate: Haushalt 5 × 600 − 2.000 = 1.000, Wärmepumpe
     * 2.000 − 5 × 250 = 750), die Monate darin sind verteilt; nach dem Tausch wieder 200 und 150.
     */
    public function testMessyCascadeWithGapAndDeviceSwap(): void
    {
        // dazu: Z2 erst ab Juli 2024 abgelesen (davor gibt es keinen Strang, nicht „Haushalt = ganze Erzeugung“)
        $skip = ['feed2' => ['2024-01-01', '2024-02-01', '2024-03-01', '2024-04-01', '2024-05-01', '2024-06-01',
                             '2025-04-01', '2025-05-01', '2025-06-01', '2025-07-01']];
        $this->readings(['pv_einspeisung' => ['feed1' => 250.0, 'feed2' => 400.0]], [], $skip);
        // Tausch am 16.10.2025: alter Endstand 1.000 + 21 × 400 + 400 × 15/31, neuer Anfang 5
        $final = 1000.0 + 21 * 400.0 + 400.0 * 15 / 31;
        $this->meters->replaceDevice('pv_einspeisung', $this->id['feed2'], ['date' => '2025-10-16', 'old_final_counter' => $final, 'new_initial_counter' => 5.0]);
        $m = $this->meters->get('pv_einspeisung', $this->id['feed2']);
        $new = $m['devices'][1]['id'];
        $rs = array_values(array_filter($this->store->read('pv_einspeisung/readings.json', []), fn($r) => $r['meter_id'] !== $this->id['feed2'] || $r['date'] < '2025-11-01'));
        foreach (['2025-11-01' => 16, '2025-12-01' => 47, '2026-01-01' => 78] as $d => $daysSince) {
            // 16 Tage im Oktober, dann je Monat 400
            $counter = 5.0 + 400.0 * 16 / 31 + 400.0 * (int)round(($daysSince - 16) / 31);
            $rs[] = ['id' => 'r_new_' . $d, 'meter_id' => $this->id['feed2'], 'device_id' => $new, 'date' => $d, 'counter' => $counter,
                     'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write('pv_einspeisung/readings.json', $rs);

        $pv = $this->pv();
        self::assertNull(self::row($pv['monthly'], '2024-03')['cascade'], 'vor der ersten Ablesung an Z2 kein Strang');
        self::assertNotNull(self::row($pv['monthly'], '2024-08')['cascade']);
        self::assertEqualsWithDelta(350.0, self::row($pv['monthly'], '2024-03')['eigenverbrauch_kwh'], 0.05, 'zusammen weiter wie bisher');
        // v3.10.0 (Skeptiker M5) — Wärmepumpe: vor der ersten Ablesung an Z2 ist ihr Sonnenstrom unbekannt,
        // der Monat ohne Arbeitszahl; ab August 2024 gemessen: 1.575 / (450 + 150) = 2,63
        $hp24 = array_column((new HeatPumpService($this->meters, $this->consumption))->forYear(2024)['pumps'][0]['months'], null, 'ym');
        self::assertTrue($hp24['2024-03']['estimated'] ?? false);
        self::assertNull($hp24['2024-03']['cop']);
        self::assertEqualsWithDelta(2.63, $hp24['2024-08']['cop'], 0.001);
        $sum = ['behind' => 0.0, 'front' => 0.0];
        foreach (['2025-03', '2025-04', '2025-05', '2025-06', '2025-07'] as $ym) {
            $c = self::row($pv['monthly'], $ym)['cascade'];
            foreach ($c['strands'] as $s) $sum[$s['position']] += (float)$s['kwh'];
        }
        self::assertEqualsWithDelta(1000.0, $sum['behind'], 0.5, 'Haushalt über die Lücke');
        self::assertEqualsWithDelta(750.0, $sum['front'], 0.5, 'Wärmepumpe über die Lücke');
        self::assertTrue(self::row($pv['monthly'], '2025-05')['cascade']['interpolated'], 'verteilt, nicht gemessen');
        foreach (['2025-10', '2025-11', '2025-12'] as $ym) {
            $by = array_column(self::row($pv['monthly'], $ym)['cascade']['strands'], 'kwh', 'position');
            self::assertEqualsWithDelta(200.0, $by['behind'], 0.5, "$ym nach dem Tausch");
            self::assertEqualsWithDelta(150.0, $by['front'], 0.5, "$ym nach dem Tausch");
        }
        // die Einspeisung am Netz bleibt Z1 allein, auch mit Lücke und Tausch an Z2
        self::assertEqualsWithDelta(250.0, self::row($this->consumption->forUtility('pv_einspeisung')['monthly_total'], '2025-10')['kwh'], 0.05);
    }

    /** Mehr an Z2 eingespeist als erzeugt (Ablesefehler): nicht negativ, gekennzeichnet. */
    public function testAStrandBelowZeroIsFlaggedNotNegative(): void
    {
        $this->readings(['pv_einspeisung' => ['feed1' => 250.0, 'feed2' => 400.0]], ['feed2' => ['2025-06' => 650.0]]);
        $c = self::row($this->pv()['monthly'], '2025-06')['cascade'];
        $by = array_column($c['strands'], 'kwh', 'position');
        self::assertTrue($c['inconsistent']);
        self::assertSame(0.0, (float)$by['behind'], 'Haushalt 600 − 650 nicht negativ');
        self::assertEqualsWithDelta(400.0, $by['front'], 0.05, '650 − 250');
    }

    public function testTheLinkIsValidated(): void
    {
        foreach ([$this->id['z1ht'] => 'vorn (kein Zähler davor)', 'm_strom_unknown' => 'unbekannt'] as $link => $why) {
            try {
                $this->meters->create('pv_einspeisung', ['name' => 'X', 'cascade_meter_id' => $link]);
                self::fail("angenommen: $why");
            } catch (LocalizedException $e) {
                self::assertSame('errors.meter.cascadeLinkInvalid', $e->key, $why);
            }
        }
        // leer entfernt die Verbindung; die Einspeisung zählt dann wieder in Summen
        $m = $this->meters->update('pv_einspeisung', $this->id['feed2'], ['cascade_meter_id' => null]);
        self::assertArrayNotHasKey('cascade_meter_id', $m);
        self::assertTrue(MeterService::countsInTotals($m));
        // an anderen Arten bleibt das Feld weg
        $s = $this->meters->update('strom', $this->id['z1ht'], ['cascade_meter_id' => $this->id['z2ht']]);
        self::assertArrayNotHasKey('cascade_meter_id', $s);
    }

    public function testBackupKeepsTheLink(): void
    {
        $backup = new BackupService($this->store, $this->i18n);
        $data = $backup->export();
        $this->store->write('pv_einspeisung/meters.json', []);
        $backup->import($data);
        self::assertSame($this->id['z2ht'], $this->meters->get('pv_einspeisung', $this->id['feed2'])['cascade_meter_id'] ?? null);
    }

    /**
     * Das Beispiel im Anwendungsfall H (DE und EN, nach `<!-- doc-claim: cascade-feed-example`) nennt
     * Mengen; jede Zahl mit kWh muss die gerechnete sein (Doku-Behauptung am Test).
     */
    public function testTheDocumentedExampleIsWhatTheAppCalculates(): void
    {
        $m = self::row($this->pv()['monthly'], '2025-06');
        $by = array_column($m['cascade']['strands'], 'kwh', 'position');
        $expected = [600.0, 400.0, 250.0, 600.0, 400.0, $by['behind'], 400.0, 250.0, $by['front'], $m['eigenverbrauch_kwh'],
            450.0, $by['front'], $m['heat_pump_kwh'], 250.0, $by['behind'], 450.0];
        foreach (['docs/anleitungen/anwendungsfaelle.md', 'docs/en/anleitungen/anwendungsfaelle.md'] as $rel) {
            $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            $pos = strpos($text, '<!-- doc-claim: cascade-feed-example');
            self::assertNotFalse($pos, "$rel: Beispiel fehlt");
            preg_match('/```text\n(.*?)```/s', substr($text, (int)$pos), $mm);
            preg_match_all('/(\d+(?:[.,]\d+)?)\s*kWh(?!\w)/u', $mm[1] ?? '', $all);
            $found = array_map(fn($v) => (float)str_replace(',', '.', $v), $all[1]);
            self::assertEqualsWithDelta($expected, $found, 0.05, "$rel: Zahlen im Beispiel");
        }
    }
}
