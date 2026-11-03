<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BenchmarkService;
use Energietracker\Services\CascadeFeed;
use Energietracker\Services\HeatPumpService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.10.0 (Skeptiker M5, N10) — Kaskade C3 mit unordentlichen Ablesungen: Eine Lücke oder eine nur
 * vierteljährlich abgelesene Erzeugung ist eine Schätzung, kein „Widerspruch“, und ergibt keine
 * Arbeitszahl 12,8. Erfundener Haushalt (Werte je Monat, Sommer 2025 mit eigenem Verlauf):
 *
 *   Z1 Bezug HT 400, NT 300 · Z2 Bezug HT 150, NT 100 (Wärmepumpe aus dem Netz 450) · Wärme 1.500
 *   Erzeugung Z3 600, im Juni 1.000, Juli 900, August 600, September 300
 *   Haushalt nutzt je Monat 200 kWh Sonne: Z2 = Erzeugung − 200; Wärmepumpe 50: Z1 = Z2 − 50
 *
 * Von Hand (Tagesverteilung zwischen zwei Ablesungen):
 *   A) Erzeugung nur am 1.1., 1.4., 1.7., 1.10. abgelesen
 *      April–Juni 2.200 kWh auf 91 Tage: Juni 2.200 × 30/91 = 725,27; Z2 Juni 800 → dahinter −74,7 → 0
 *      Juli–September 1.800 auf 92 Tage: Juli 606,52; Z2 Juli 700 → dahinter −93,5 → 0; August 206,5
 *      vorn in jedem Monat gemessen 50 kWh → Arbeitszahl Juli 1.500 / (450 + 50) = 3,0
 *   B) An Z2 fehlen die Ablesungen 1.7. und 1.8.2025: Juni–August 1.900 kWh auf 92 Tage
 *      Juni 619,57, Juli 640,22, August 640,22; vorn Juni 619,57 − 750 → 0, Juli 640,22 − 650 → 0,
 *      August 290,2; die Arbeitszahl dieser drei Monate ist geschätzt und steht nicht da
 */
#[CoversClass(CascadeFeed::class)]
#[CoversClass(PvSummaryService::class)]
#[CoversClass(HeatPumpService::class)]
#[CoversClass(BenchmarkService::class)]
final class CascadeFeedEstimatedTest extends ServiceTestCase
{
    use ReportFixture;

    /** @var array<string,string> */
    private array $id = [];

    private const SUMMER_GEN = ['2025-06' => 1000.0, '2025-07' => 900.0, '2025-08' => 600.0, '2025-09' => 300.0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store->write('settings.json', ['active_utilities' => ['strom', 'pv_einspeisung', 'pv_erzeugung', 'waerme'],
            'wohnflaeche_m2' => 140, 'country' => 'DE'] + (array)$this->store->read('settings.json', []));
        foreach (['strom', 'pv_einspeisung', 'pv_erzeugung', 'waerme'] as $u) {
            foreach (['meters', 'contracts', 'readings'] as $pot) $this->store->write("$u/$pot.json", []);
        }
        $mk = fn(string $u, string $name, array $extra = []) => (string)$this->meters->create($u, $extra + ['name' => $name, 'installed_on' => '2024-01-01'])['id'];
        $this->id['z1ht'] = $mk('strom', 'Z1 HT', ['role' => 'heat_pump']);
        $this->id['z1nt'] = $mk('strom', 'Z1 NT', ['role' => 'heat_pump']);
        $this->id['z2ht'] = $mk('strom', 'Z2 HT', ['parent_meter_id' => $this->id['z1ht']]);
        $this->id['z2nt'] = $mk('strom', 'Z2 NT', ['parent_meter_id' => $this->id['z1nt']]);
        $g1 = (string)$this->meters->createGroup('strom', ['name' => 'Z1 Wärmepumpe'])['id'];
        $g2 = (string)$this->meters->createGroup('strom', ['name' => 'Z2 Haushalt'])['id'];
        foreach (['z1ht', 'z1nt'] as $k) $this->meters->update('strom', $this->id[$k], ['meter_group_id' => $g1]);
        foreach (['z2ht', 'z2nt'] as $k) $this->meters->update('strom', $this->id[$k], ['meter_group_id' => $g2]);
        $this->id['feed1'] = $mk('pv_einspeisung', 'Z1 Einspeisung');
        $this->id['feed2'] = $mk('pv_einspeisung', 'Z2 Einspeisung', ['cascade_meter_id' => $this->id['z2ht']]);
        $this->id['gen'] = $mk('pv_erzeugung', 'Z3 PV');
        $this->id['heat'] = $mk('waerme', 'Wärme WP', ['role' => 'heat_pump_output', 'heat_pump_meter_ids' => [$this->id['z1ht'], $this->id['z1nt']]]);
        foreach (['z1ht' => 400.0, 'z1nt' => 300.0, 'z2ht' => 150.0, 'z2nt' => 100.0] as $k => $v) $this->series('strom', $k, fn() => $v);
        $this->series('waerme', 'heat', fn() => 1500.0);
    }

    private static function gen(string $ym): float { return self::SUMMER_GEN[$ym] ?? 600.0; }

    /**
     * Stände ab 01.01.2024 aus Monatsmengen; `$skip` lässt Ablesungen weg (Datum → true),
     * `$only` nimmt nur diese Ablesetage.
     */
    private function series(string $u, string $k, callable $perMonth, array $skip = [], ?array $only = null): void
    {
        $all = array_values(array_filter((array)$this->store->read("$u/readings.json", []), fn($r) => $r['meter_id'] !== $this->id[$k]));
        $dev = $this->meters->get($u, $this->id[$k])['devices'][0]['id'];
        $counter = 1000.0;
        for ($i = 0; $i <= 24; $i++) {
            $date = date('Y-m-d', (int)strtotime("2024-01-01 +$i months"));
            if ($i > 0) $counter += $perMonth(date('Y-m', (int)strtotime("$date -1 month")));
            if (isset($skip[$date]) || ($only !== null && !in_array($date, $only, true))) continue;
            $all[] = ['id' => "r_{$k}_$i", 'meter_id' => $this->id[$k], 'device_id' => $dev, 'date' => $date, 'counter' => $counter,
                      'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write("$u/readings.json", $all);
    }

    /** Erzeugung, Z2 und Z1 nach dem Haushalt oben; `$genDates`/`$z2Skip` machen ihn unordentlich. */
    private function solar(?array $genDates = null, array $z2Skip = []): void
    {
        $this->series('pv_erzeugung', 'gen', fn($ym) => self::gen($ym), [], $genDates);
        $this->series('pv_einspeisung', 'feed2', fn($ym) => self::gen($ym) - 200.0, $z2Skip);
        $this->series('pv_einspeisung', 'feed1', fn($ym) => self::gen($ym) - 250.0);
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

    public function testQuarterlyGenerationIsAnEstimateNotAContradiction(): void
    {
        $quarters = ['2024-01-01', '2024-04-01', '2024-07-01', '2024-10-01', '2025-01-01', '2025-04-01', '2025-07-01', '2025-10-01', '2026-01-01'];
        $this->solar($quarters);
        $pv = $this->pv();
        foreach (['2025-06' => 0.0, '2025-07' => 0.0, '2025-08' => 1800.0 * 31 / 92 - 400.0] as $ym => $behind) {
            $c = self::row($pv['monthly'], $ym)['cascade'];
            $by = array_column($c['strands'], null, 'position');
            self::assertFalse($c['inconsistent'], "$ym: verteilt, kein Widerspruch");
            self::assertTrue($c['interpolated'], "$ym: verteilt");
            self::assertTrue($by['behind']['estimated'], "$ym: dahinter geschätzt");
            self::assertFalse($by['front']['estimated'], "$ym: vorn gemessen (Z2 und Z1 monatlich)");
            self::assertEqualsWithDelta($behind, $by['behind']['kwh'], 0.1, "$ym dahinter");
            self::assertEqualsWithDelta(50.0, $by['front']['kwh'], 0.05, "$ym vorn");
        }
        self::assertTrue(self::row($pv['monthly'], '2025-07')['cascade']['clamped'], 'unter null begrenzt');
        $y = array_column($pv['yearly'], null, 'year')[2025]['cascade'];
        self::assertSame([], $y['inconsistent_months'], 'keine Warnung „bitte die Ablesungen prüfen“');
        // v3.10.0 — über die verteilten Quartale genau: dahinter 12 × 200 = 2.400 (Juni und Juli unbegrenzt
        // −74,73 und −93,48, ausgeglichen in den anderen Monaten ihres Quartals)
        self::assertEqualsWithDelta(200.0 * 12, array_column($y['strands'], 'kwh', 'position')['behind'], 0.5);
        $ys = array_column($y['strands'], null, 'position');
        self::assertGreaterThan(0, $ys['behind']['estimated_months']);
        self::assertSame(0, $ys['front']['estimated_months']);
        // die Wärmepumpe hängt am vorderen Strang: gemessen, Arbeitszahl je Monat 1.500 / 500 = 3,0
        $hp = (new HeatPumpService($this->meters, $this->consumption))->forYear(2025)['pumps'][0];
        $jul = self::row($hp['months'], '2025-07');
        self::assertArrayNotHasKey('estimated', $jul);
        self::assertEqualsWithDelta(3.0, $jul['cop'], 0.001);
        self::assertEqualsWithDelta(3.0, $hp['jaz'], 0.001);
    }

    public function testAGapAtZ2GivesNoMonthlyPerformanceFactor(): void
    {
        $this->solar(null, ['2025-07-01' => true, '2025-08-01' => true]);
        $pv = $this->pv();
        $z2 = fn(int $days) => 1900.0 * $days / 92;
        foreach (['2025-06' => [0.0, 1000.0 - $z2(30)], '2025-07' => [0.0, 900.0 - $z2(31)], '2025-08' => [$z2(31) - 350.0, 0.0]] as $ym => [$front, $behind]) {
            $c = self::row($pv['monthly'], $ym)['cascade'];
            $by = array_column($c['strands'], null, 'position');
            self::assertFalse($c['inconsistent'], "$ym: Lücke an Z2, kein Widerspruch");
            self::assertTrue($by['front']['estimated'] && $by['behind']['estimated'], "$ym: beide Stränge geschätzt");
            self::assertEqualsWithDelta($front, $by['front']['kwh'], 0.1, "$ym vorn");
            self::assertEqualsWithDelta($behind, $by['behind']['kwh'], 0.1, "$ym dahinter");
        }
        self::assertSame([], array_column($pv['yearly'], null, 'year')[2025]['cascade']['inconsistent_months']);

        $hp = (new HeatPumpService($this->meters, $this->consumption))->forYear(2025)['pumps'][0];
        foreach (['2025-06' => 450.0, '2025-07' => 450.0, '2025-08' => 450.0 + $z2(31) - 350.0] as $ym => $elec) {
            $m = self::row($hp['months'], $ym);
            self::assertTrue($m['estimated'] ?? false, "$ym geschätzt");
            self::assertNull($m['cop'], "$ym: keine Arbeitszahl je Monat (bisher 3,3 bzw. 2,0 statt 3,0)");
            self::assertEqualsWithDelta($elec, $m['elec_kwh'], 0.1);
        }
        self::assertEqualsWithDelta(3.0, self::row($hp['months'], '2025-05')['cop'], 0.001, 'gemessener Monat');
        self::assertNotContains('2025-07', array_column($hp['by_hdd'], 'ym'));
        // v3.10.0 (Entscheidung des Maintainers) — Jahressumme über die Lücke genau: In den verteilten
        // Monaten zählt der unbegrenzte Sonnenstrom, vorn −130,43 − 9,78 + 290,22 = 150,0 = 3 × 50.
        // Strom im Jahr 12 × 450 + 12 × 50 = 6.000, Wärme 18.000 → JAZ 3,0 (bisher 18.000 / 6.140,2 = 2,93).
        // Die Monatszeilen bleiben begrenzt (Juni und Juli 450): Ihre Summe ist nicht die Jahressumme.
        self::assertEqualsWithDelta(6000.0, $hp['elec_kwh'], 0.5);
        self::assertEqualsWithDelta(3.0, $hp['jaz'], 0.001);
        self::assertEqualsWithDelta(18000.0 / 6140.2, array_sum(array_column($hp['months'], 'heat_kwh')) / array_sum(array_column($hp['months'], 'elec_kwh')), 0.001,
            'die Monatszeilen zeigen weiter den begrenzten Wert');
        // dieselbe Jahressumme an der Karte, im Energiefluss und bei der Wärmepumpe im Fluss
        $y = array_column($pv['yearly'], null, 'year')[2025];
        $ys = array_column($y['cascade']['strands'], 'kwh', 'position');
        self::assertEqualsWithDelta(50.0 * 12, $ys['front'], 0.5, 'vorn über die Lücke genau');
        self::assertEqualsWithDelta(200.0 * 12, $ys['behind'], 0.5, 'dahinter: 1.800 + 380,43 + 259,78 − 40,22 = 2.400');
        self::assertEqualsWithDelta(6000.0, $y['heat_pump_kwh'], 0.5);
        self::assertEqualsWithDelta(6000.0, $y['flow']['heat_pump'], 0.5);
        // Jahresbericht: der geschätzte Monat ohne Wert (kein Balken 0 oder 12,8)
        $months = null;
        $walk = function ($node) use (&$walk, &$months): void {
            if (!is_array($node)) return;
            if (isset($node['pumps'][0]['months']) && isset($node['pumps'][0]['jaz'])) { $months = $node['pumps'][0]['months']; return; }
            foreach ($node as $v) $walk($v);
        };
        $walk($this->reportServices()['model']->build(2025));
        self::assertNotNull($months, 'Wärmepumpe im Jahresbericht');
        $est = array_values(array_filter($months, fn($m) => $m['estimated']));
        self::assertCount(3, $est);
        self::assertSame([null, null, null], array_column($est, 'value'));
    }

    /** Alle drei gemessen und trotzdem unter null: der Widerspruch bleibt gemeldet. */
    public function testAMeasuredContradictionIsStillReported(): void
    {
        $this->solar();
        $this->series('pv_einspeisung', 'feed2', fn($ym) => $ym === '2025-05' ? 700.0 : self::gen($ym) - 200.0);
        $pv = $this->pv();
        $c = self::row($pv['monthly'], '2025-05')['cascade'];
        self::assertTrue($c['inconsistent']);
        self::assertFalse($c['interpolated']);
        self::assertSame(['2025-05'], array_column($pv['yearly'], null, 'year')[2025]['cascade']['inconsistent_months']);
        // gemessen wird der Widerspruch nicht mit anderen Monaten verrechnet: dahinter Mai 0 (statt −100),
        // im Jahr 11 × 200 = 2.200 (die Meldung nennt den Monat)
        $ys = array_column(array_column($pv['yearly'], null, 'year')[2025]['cascade']['strands'], 'kwh', 'position');
        self::assertEqualsWithDelta(2200.0, $ys['behind'], 0.5);
    }

    /** N10: Die Effizienz zählt die Wärmepumpe vorn in der Kaskade nur mit dem Strom aus dem Netz und sagt es. */
    public function testEfficiencySaysGridOnly(): void
    {
        $this->solar();
        $bench = fn() => new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
        $strom = array_column($bench()->efficiency(2025)['per_source'], null, 'utility')['strom'] ?? null;
        self::assertNotNull($strom);
        self::assertTrue($strom['grid_only'] ?? false);
        self::assertEqualsWithDelta(450.0 * 12, $strom['kwh'], 1.0, 'der Rest aus dem Netz');
        // ohne Zählwerk an Z2 kein Satz (dann rechnen alle Ansichten mit dem Netzstrom)
        $this->meters->update('pv_einspeisung', $this->id['feed2'], ['cascade_meter_id' => '']);
        $strom2 = array_column($bench()->efficiency(2025)['per_source'], null, 'utility')['strom'] ?? null;
        self::assertArrayNotHasKey('grid_only', $strom2);
    }
}
