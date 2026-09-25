<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\ClimateNormalService;
use Energietracker\Services\DeliveryConsumptionService;
use Energietracker\Services\DeliveryService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.10.0 — Tankbuch (Review CALC-04, CALC-25).
 *
 * Bis v2.9 rechneten Heizöl und Pellets zweimal: Die Kosten verteilten
 * Anfangsbestand plus alle Lieferungen bis heute (Endbestand 0), die
 * Bestandskurve nutzte eine kalibrierte Rate. Eine Lieferung von heute
 * erhöhte damit den Verbrauch aller Vorjahre, der Anfangsbestand war
 * kostenlos, und ohne Temperaturen wurde der Juli wie der Januar gerechnet.
 *
 * Die Testdaten sind so gebaut, dass der alte Weg sichtbar falsch liegt:
 * ein Klima mit Winter und Sommer, Stützstellen an bekannten Tagen, Preise,
 * die sich zwischen Anfangsbestand und Lieferung unterscheiden.
 */
#[CoversClass(DeliveryConsumptionService::class)]
#[CoversClass(DeliveryService::class)]
final class TankModelTest extends ServiceTestCase
{
    private DeliveryService $deliveries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
    }

    /** Tag relativ zu heute. */
    private static function day(int $offset): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
    }

    /** Synthetisches Klima: −1 °C im Januar, 19 °C im Juli. */
    private static function temp(string $date): float
    {
        $doy = (int)date('z', (int)strtotime($date));
        return round(9.0 - 10.0 * cos(2 * M_PI * ($doy - 15) / 365.25), 2);
    }

    /** Temperaturen für [from, heute]; $skip(Datum) = true lässt einen Tag aus. */
    private function writeTemperatures(string $from, ?callable $skip = null): void
    {
        $out = [];
        for ($d = $from; $d <= date('Y-m-d'); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if ($skip !== null && $skip($d)) continue;
            $t = self::temp($d);
            $out[$d] = ['avg' => $t, 'min' => $t - 3, 'max' => $t + 3, 'source' => 'csv'];
        }
        $this->store->write('temperatures.json', $out);
    }

    private function tank(string $start, float $initial, float $capacity = 3000.0, array $extra = []): array
    {
        return $this->meters->create('heizoel', array_merge([
            'name' => 'Tank', 'capacity' => $capacity, 'initial_stock' => $initial, 'installed_on' => $start,
        ], $extra));
    }

    private function deliver(array $tank, string $date, float $qty, array $extra = []): array
    {
        return $this->deliveries->create('heizoel', array_merge([
            'meter_id' => $tank['id'], 'date' => $date, 'quantity' => $qty, 'unit_price_cents' => 100.0,
        ], $extra));
    }

    private function model(array $tank): array
    {
        $meter = $this->meters->get('heizoel', $tank['id']);
        return (new DeliveryConsumptionService($this->store, $this->settings))->tankModel('heizoel', $meter);
    }

    /** Σ Verbrauch (Liter) über [from, to) */
    private static function drawBetween(array $model, string $from, string $to): float
    {
        $sum = 0.0;
        foreach ($model['days'] as $d => $row) {
            if ($d >= $from && $d < $to) $sum += $row['draw'];
        }
        return $sum;
    }

    // ─────────────────────────────────────────────────────────────────

    public function testADeliveryTodayDoesNotChangeThePast(): void
    {
        $start = self::day(-730);
        $this->writeTemperatures($start);
        $tank = $this->tank($start, 2500.0);
        // „bis voll" zweimal: Alles bis zur zweiten Füllung liegt zwischen Stützstellen
        $this->deliver($tank, self::day(-370), 2200.0, ['fill_to_full' => true]);
        $this->deliver($tank, self::day(-40), 1800.0, ['fill_to_full' => true]);
        $closedBefore = substr(self::day(-40), 0, 7);   // Monate davor sind vollständig bekannt

        $past = fn() => array_sum(array_map(
            fn($m) => $m['ym'] < $closedBefore ? (float)$m['kwh'] : 0.0,
            $this->consumption->forMeter('heizoel', $this->meters->get('heizoel', $tank['id']))
        ));
        $before = $past();
        self::assertGreaterThan(0.0, $before);

        $this->deliver($tank, self::day(0), 1000.0);
        self::assertEqualsWithDelta($before, $past(), 0.05,
            'Eine Lieferung von heute darf die Vorjahre nicht verändern (bis v2.9: +20 %)');
    }

    public function testConsumptionIsKnownBetweenTwoStockLevels(): void
    {
        $start = self::day(-200);
        $this->writeTemperatures($start);
        $tank = $this->tank($start, 2000.0);
        $this->meters->update('heizoel', $tank['id'], [
            'tank_levels' => [['date' => self::day(-100), 'level' => 1400, 'note' => 'Peilstab']],
        ]);

        $m = $this->model($tank);
        self::assertEqualsWithDelta(600.0, self::drawBetween($m, $start, self::day(-100)), 0.01,
            'Zwischen Anfangsbestand und Peilstand ist der Verbrauch bekannt: 2000 − 1400');
        self::assertSame('anchors', $m['calibration']['source']);
        self::assertSame(self::day(-100), $m['estimated_from']);
        self::assertFalse($m['days'][self::day(-101)]['estimated']);
        self::assertTrue($m['days'][self::day(-100)]['estimated']);
        // Die Kurve trifft den Peilstand: Ende des Vortags = Stand
        self::assertEqualsWithDelta(1400.0, $m['days'][self::day(-101)]['stock'], 0.05);
    }

    public function testStockCurveAndConsumptionAreOneCalculation(): void
    {
        $start = self::day(-400);
        $this->writeTemperatures($start);
        $tank = $this->tank($start, 1800.0);
        $this->deliver($tank, self::day(-250), 1500.0);
        $this->deliver($tank, self::day(-60), 1400.0);
        $meter = $this->meters->get('heizoel', $tank['id']);

        $hist  = $this->deliveries->stockHistory('heizoel', $tank['id'], $this->consumption);
        $kwh   = $this->consumption->dailyDeliveryConsumption('heizoel', $meter);
        $hu    = (float)$this->settings->get('heizoel_kwh_per_l', 10.0);
        foreach ($hist['days'] as $row) {
            self::assertEqualsWithDelta($row['consumption'] * $hu, $kwh[$row['date']], 0.01,
                'Bestandskurve und Verbrauch müssen dieselbe Reihe sein (bis v2.9 zwei Modelle)');
        }
        // Bilanz: Anfang + Lieferungen − Verbrauch = Endbestand (solange nicht leer)
        $sumDraw = array_sum(array_column($hist['days'], 'consumption'));
        $end = end($hist['days'])['stock'];
        self::assertGreaterThan(0.0, $end, 'Vorbedingung: Tank nicht rechnerisch leer');
        self::assertEqualsWithDelta(1800.0 + 1500.0 + 1400.0 - $sumDraw, $end, 0.5);
    }

    public function testInitialStockIsPricedAndTheTankMixesPrices(): void
    {
        $start = self::day(-100);
        $this->writeTemperatures($start);
        $tank = $this->tank($start, 1000.0, 3000.0, ['initial_stock_price_ct' => 80]);
        $this->meters->update('heizoel', $tank['id'], [
            'tank_levels' => [['date' => self::day(-51), 'level' => 600]],
        ]);
        $this->deliver($tank, self::day(-50), 1000.0, ['unit_price_cents' => null, 'total_eur' => 1200.0]);

        $m = $this->model($tank);
        self::assertEqualsWithDelta(80.0, $m['days'][$start]['price_ct'], 1e-6,
            'Der Anfangsbestand kostet seinen Preis (bis v2.9: 0 €)');
        self::assertGreaterThan(0.0, $m['days'][$start]['cost_eur']);

        $before = $m['days'][self::day(-51)]['stock'];   // Ende des Vortags
        $expected = ($before * 80.0 + 1000.0 * 120.0) / ($before + 1000.0);
        self::assertEqualsWithDelta($expected, $m['days'][self::day(-50)]['price_ct'], 1e-3,
            'Die Lieferung mischt sich zu ihrem Preis unter den Bestand');
        self::assertLessThan(120.0, $m['days'][self::day(-50)]['price_ct'],
            'Nicht der Preis der letzten Lieferung (bis v2.9)');
    }

    public function testWithoutPriceTheFirstDeliveryPricesTheInitialStock(): void
    {
        $start = self::day(-100);
        $this->writeTemperatures($start);
        $tank = $this->tank($start, 1000.0);
        $this->deliver($tank, self::day(-30), 800.0, ['unit_price_cents' => 110.0]);

        $m = $this->model($tank);
        self::assertEqualsWithDelta(110.0, $m['days'][$start]['price_ct'], 1e-6);
    }

    public function testASummerIntervalIsMostlyBaseLoad(): void
    {
        $y = (int)date('Y') - 1;
        $start = $y . '-05-01';
        $this->writeTemperatures($start);
        $tank = $this->tank($start, 2000.0);
        $this->meters->update('heizoel', $tank['id'], [
            'tank_levels' => [['date' => $y . '-09-01', 'level' => 1750]],
        ]);

        $m = $this->model($tank);
        $days = array_filter($m['days'], fn($d) => $d >= $start && $d < $y . '-09-01', ARRAY_FILTER_USE_KEY);
        $avg = array_sum(array_column($days, 'draw')) / count($days);
        self::assertGreaterThan(0.3 * $avg, min(array_column($days, 'draw')),
            'Warme Tage tragen die Grundlast — nicht 85 % des Sommers auf die wenigen kühlen Tage');
    }

    public function testWithoutDeliveryOrReadingThereIsNoConsumptionToInvent(): void
    {
        $start = self::day(-60);
        $this->writeTemperatures($start);
        $tank = $this->tank($start, 2000.0);

        $m = $this->model($tank);
        self::assertSame('none', $m['calibration']['source']);
        self::assertContains('no_calibration', array_column($m['warnings'], 'code'));
        self::assertEqualsWithDelta(0.0, self::drawBetween($m, $start, self::day(1)), 1e-9,
            'Bis v2.9 galt der ganze Anfangsbestand als bis heute verbraucht');
    }

    public function testOneDeliveryMeansTheInitialStockWasUsedUpToIt(): void
    {
        $start = self::day(-120);
        $this->writeTemperatures($start);
        $tank = $this->tank($start, 400.0);
        $this->deliver($tank, self::day(-30), 2000.0);

        $m = $this->model($tank);
        self::assertSame('first_delivery', $m['calibration']['source']);
        self::assertEqualsWithDelta(400.0, self::drawBetween($m, $start, self::day(-30)), 0.01);
        self::assertGreaterThan(1000.0, end($m['days'])['stock'],
            'Die frische Lieferung ist nicht verbraucht (bis v2.9: Endbestand 0)');
    }

    public function testWithoutClimateNormalTheShapeStillSpansAWholeYear(): void
    {
        // Vier Monate Temperaturen, kein Klimanormal: Das Fenster allein
        // hätte kaum Gradtage, die Grundlast-Form ginge gegen 0 und eine im
        // Sommer kalibrierte Rate würde im Herbst zu Hunderten Litern am Tag.
        $start = self::day(-120);
        $this->writeTemperatures($start);
        $tank = $this->tank($start, 400.0);
        $this->deliver($tank, self::day(-30), 2000.0);

        $cal = $this->model($tank)['calibration'];
        self::assertGreaterThan(0.5, $cal['base_per_day'] / $cal['per_hdd'],
            'Grundlast je Tag in Gradtag-Einheiten (ρ) aus einem ganzen Jahr, nicht aus dem Fenster');
    }

    public function testAnImpossibleReadingIsReportedInsteadOfBooked(): void
    {
        $start = self::day(-80);
        $this->writeTemperatures($start);
        $tank = $this->tank($start, 1000.0);
        $this->meters->update('heizoel', $tank['id'], [
            'tank_levels' => [['date' => self::day(-40), 'level' => 1500]],
        ]);

        $m = $this->model($tank);
        $w = array_values(array_filter($m['warnings'], fn($w) => $w['code'] === 'inconsistent_level'));
        self::assertCount(1, $w);
        self::assertSame(self::day(-40), $w[0]['to']);
        self::assertEqualsWithDelta(500.0, $w[0]['excess'], 0.01);
        self::assertEqualsWithDelta(0.0, self::drawBetween($m, $start, self::day(-40)), 1e-9);
    }

    public function testMissingTemperaturesComeFromTheClimateNormal(): void
    {
        $start = self::day(-365);
        // Klimanormal aus zwei synthetischen Jahren
        $daily = [];
        for ($d = '2020-01-01'; $d <= '2021-12-31'; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $daily[$d] = self::temp($d);
        }
        (new ClimateNormalService($this->store, $this->settings))->save(
            ClimateNormalService::compute($daily, 51.3, 12.4, ['from' => '2020-01-01', 'to' => '2021-12-31'])
        );
        // Nur jeder dritte Tag hat eine Messung — zu wenig für die alte Rechnung
        $this->writeTemperatures($start, fn($d) => ((int)date('z', strtotime($d))) % 3 !== 0);
        $tank = $this->tank($start, 2500.0);
        $this->meters->update('heizoel', $tank['id'], [
            'tank_levels' => [['date' => self::day(-5), 'level' => 400]],
        ]);

        $m = $this->model($tank);
        self::assertGreaterThan(200, $m['hdd_filled_days']);
        self::assertFalse($m['calibration']['flat'], 'Bis v2.9: unter 50 % Temperaturen alles flach');
        $jan = []; $jul = [];
        foreach ($m['days'] as $d => $row) {
            if (substr($d, 5, 2) === '01') $jan[] = $row['draw'];
            if (substr($d, 5, 2) === '07') $jul[] = $row['draw'];
        }
        self::assertGreaterThan(3 * (array_sum($jul) / max(1, count($jul))), array_sum($jan) / max(1, count($jan)),
            'Ein Januartag verbraucht deutlich mehr als ein Julitag');
    }

    public function testMonthlyRowsNameEstimatedDaysAndTheEffectivePrice(): void
    {
        $start = self::day(-150);
        $this->writeTemperatures($start);
        $tank = $this->tank($start, 2000.0, 3000.0, ['initial_stock_price_ct' => 95]);
        $this->meters->update('heizoel', $tank['id'], [
            'tank_levels' => [['date' => self::day(-40), 'level' => 1200]],
        ]);

        $rows = $this->consumption->forMeter('heizoel', $this->meters->get('heizoel', $tank['id']));
        $last = end($rows);
        self::assertGreaterThan(0, $last['estimated_days']);
        self::assertSame(0, $rows[0]['estimated_days']);
        self::assertEqualsWithDelta(9.5, $rows[0]['working_price_ct'], 0.05, '95 ct/L bei 10 kWh/L');

        $hist = $this->deliveries->stockHistory('heizoel', $tank['id'], $this->consumption);
        self::assertSame(['start', 'level'], array_column($hist['anchors'], 'kind'));
        self::assertSame(self::day(-40), $hist['estimated_from']);
        self::assertSame('anchors', $hist['calibration']);
    }

    public function testTankReadingsAreValidatedStrictly(): void
    {
        $tank = $this->tank(self::day(-30), 1000.0, 3000.0);
        $cases = [
            'Zukunft'            => [['date' => self::day(3), 'level' => 100]],
            'über der Kapazität' => [['date' => self::day(-3), 'level' => 3200]],
            'negativ'            => [['date' => self::day(-3), 'level' => -1]],
            'doppelt'            => [['date' => self::day(-3), 'level' => 10], ['date' => self::day(-3), 'level' => 20]],
            'kein Datum'         => [['date' => '2026-02-30', 'level' => 10]],
        ];
        foreach ($cases as $name => $levels) {
            try {
                $this->meters->update('heizoel', $tank['id'], ['tank_levels' => $levels]);
                self::fail("Peilstand „{$name}“ wurde angenommen");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        // Dezimalkomma wird angenommen (wie in den Formularen), sortiert wird nach Datum
        $ok = $this->meters->update('heizoel', $tank['id'], [
            'tank_levels' => [['date' => self::day(-3), 'level' => '1234,5'], ['date' => self::day(-10), 'level' => '900,5']],
        ]);
        self::assertSame(self::day(-10), $ok['tank_levels'][0]['date'], 'nach Datum sortiert');
        self::assertEqualsWithDelta(900.5, $ok['tank_levels'][0]['level'], 1e-9);
        self::assertEqualsWithDelta(1234.5, $ok['tank_levels'][1]['level'], 1e-9);
    }

    public function testFillToFullCannotExceedTheTank(): void
    {
        $tank = $this->tank(self::day(-30), 1000.0, 3000.0);
        $this->expectException(\InvalidArgumentException::class);
        $this->deliver($tank, self::day(-3), 3500.0, ['fill_to_full' => true]);
    }

    public function testFillToFullFalseAsTextIsFalse(): void
    {
        $tank = $this->tank(self::day(-30), 1000.0, 3000.0);
        $d = $this->deliver($tank, self::day(-3), 500.0, ['fill_to_full' => 'false']);
        self::assertFalse($d['fill_to_full']);
    }

    public function testTankBookSurvivesBackupAndRestore(): void
    {
        $tank = $this->tank(self::day(-60), 1000.0, 3000.0, ['initial_stock_price_ct' => 88.5]);
        $this->meters->update('heizoel', $tank['id'], [
            'tank_levels' => [['date' => self::day(-10), 'level' => 700, 'note' => 'Peilstab']],
        ]);
        $this->deliver($tank, self::day(-20), 1500.0, ['fill_to_full' => true]);

        $backups = new BackupService($this->store, $this->i18n);
        $dump = $backups->export();
        $this->store->write('heizoel/meters.json', []);
        $this->store->write('heizoel/deliveries.json', []);
        $backups->import($dump);

        $m = $this->meters->get('heizoel', $tank['id']);
        self::assertEquals(88.5, $m['initial_stock_price_ct']);
        self::assertSame('Peilstab', $m['tank_levels'][0]['note']);
        $d = $this->deliveries->list('heizoel', $tank['id']);
        self::assertTrue($d[0]['fill_to_full']);
    }
}
