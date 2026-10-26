<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AnomalyService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\PeriodService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ScenarioService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (GitHub #37, #39, Gütesperre aus #38) — gemessen oder verteilt.
 *
 * Liegen zwei Ablesungen weit auseinander, verteilt die Rechnung den
 * Verbrauch gleichmäßig auf die Tage. Jede Monatszeile sagt jetzt, ob ihr Wert
 * gemessen oder verteilt ist (Regel: Länge des Ablesungsabstands, nicht „eine
 * Ablesung im Monat“), und was aus Monaten lernt — Abweichungstabelle,
 * Empfehlungen, Heizmodell, Saisonprofil — lässt verteilte aus. Die
 * Monatswerte selbst, Kosten, Abschläge und Saldo bleiben, wie sie waren: Die
 * Fixtures unter tests/fixtures/interpolation/ sind mit dem Stand vor v3.7.0
 * aufgenommen.
 *
 * Synthetisches Klima mit bekannter Wahrheit wie WeatherModelTest: Verbrauch
 * je Tag = a × HGT + c (a = 0,3, c = 5), Umrechnungsfaktor 1. Alle Werte erfunden.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(AnomalyService::class)]
#[CoversClass(ForecastService::class)]
#[CoversClass(PvSummaryService::class)]
final class InterpolationTest extends ServiceTestCase
{
    use \Energietracker\Tests\Support\ReportFixture;

    private const A = 0.3;
    private const C = 5.0;
    private const TEMP = [1 => 0.0, 2 => 1.0, 3 => 5.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
                          7 => 19.0, 8 => 18.0, 9 => 14.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];
    private const FIXTURES = __DIR__ . '/../../fixtures/interpolation/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]]]);
    }

    // ── Hilfen ──────────────────────────────────────────────────────────

    private function seedTemps(string $from, string $to): void
    {
        $temps = $this->store->read('temperatures.json', []);
        for ($t = strtotime($from . ' 12:00'); date('Y-m-d', $t) <= $to; $t += 86400) {
            $avg = self::TEMP[(int)date('n', $t)];
            $temps[date('Y-m-d', $t)] = ['avg' => $avg, 'min' => $avg - 3, 'max' => $avg + 3];
        }
        $this->store->write('temperatures.json', $temps);
    }

    private static function hddPerDay(string $date): float
    {
        return max(0.0, 15.0 - self::TEMP[(int)substr($date, 5, 2)]);
    }

    /** Verbrauch eines Tages nach dem Modell a × HGT + c, dazu ein Aufschlag je Tag eines Monats. */
    private function dayUse(string $date, array $extraPerDay = [], ?callable $model = null): float
    {
        $hdd = self::hddPerDay($date);
        $base = $model ? $model($hdd, $date) : self::A * $hdd + self::C;
        return $base + ($extraPerDay[substr($date, 0, 7)] ?? 0.0);
    }

    /** Stand am Morgen von $date, wenn am Morgen von $from 0 stand. */
    private function counterOn(string $from, string $date, array $extraPerDay = [], ?callable $model = null): float
    {
        $c = 0.0;
        for ($t = strtotime($from . ' 12:00'); date('Y-m-d', $t) < $date; $t += 86400) $c += $this->dayUse(date('Y-m-d', $t), $extraPerDay, $model);
        return $c;
    }

    /**
     * Stände an den angegebenen Tagen aus dem Tagesmodell. $dates: Datum oder
     * [Datum, Felder] (etwa ['is_estimated' => true]).
     */
    private function seedReadings(string $utility, array $dates, array $extraPerDay = [], ?callable $model = null): string
    {
        $first = is_array($dates[0]) ? $dates[0][0] : $dates[0];
        $meterId = $this->setMeterDevices($utility, [[
            'id' => 'd1', 'serial' => null, 'installed_on' => $first,
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [];
        foreach ($dates as $d) {
            [$date, $extra] = is_array($d) ? [$d[0], $d[1]] : [$d, []];
            $rows[] = array_merge(['date' => $date, 'counter' => round($this->counterOn($first, $date, $extraPerDay, $model), 4), 'device_id' => 'd1'], $extra);
        }
        $this->setReadings($utility, $meterId, $rows);
        return $meterId;
    }

    private function meter(string $utility): array
    {
        return $this->meters->list($utility)[0];
    }

    private function monthly(string $utility): array
    {
        return $this->consumption->forMeter($utility, $this->meter($utility));
    }

    /** @return array<string,array<string,mixed>> ym → Zeile */
    private function byYm(array $monthly): array
    {
        return array_column($monthly, null, 'ym');
    }

    /** Monatserste von $fromYm bis $toYm (einschließlich). */
    private static function firsts(string $fromYm, string $toYm): array
    {
        $out = [];
        for ($t = strtotime($fromYm . '-01'); date('Y-m', $t) <= $toYm; $t = strtotime('+1 month', $t)) $out[] = date('Y-m-d', $t);
        return $out;
    }

    private static function interpolatedYms(array $monthly): array
    {
        return array_values(array_map(fn($m) => $m['ym'], array_filter($monthly, fn($m) => !empty($m['interpolated']))));
    }

    private function forecast(string $utility = 'gas'): array
    {
        $f = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        return $f->forMeter($utility, $this->meter($utility));
    }

    /** Empfehlungen an einem festen Stichtag (sie kommen nur aus den letzten 24 Monaten). */
    private function recommendations(string $today = '2026-01-31'): array
    {
        $recos = new RecommendationService(
            $this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n
        );
        return $recos->atDate($today)->all();
    }

    /** #37-Nachtrag — Empfehlungen aus Monaten (R1, R3, R4) nur aus den letzten 24 Monaten; die Tabelle der Analyse zeigt weiter alles. */
    public function testRecommendationsComeOnlyFromTheLastTwoYears(): void
    {
        $this->seedTemps('2023-01-01', '2026-01-31');
        $this->seedReadings('gas', self::firsts('2023-01', '2026-01'), ['2023-03' => 4.0]);
        $this->seedReadings('strom', self::firsts('2023-01', '2026-01'), ['2023-06' => 6.0], fn() => 8.0);
        $ymOf = fn(array $recs, string $rule) => array_values(array_map(fn($r) => $r['evidence']['ym'] ?? null,
            array_filter($recs, fn($r) => str_starts_with((string)$r['id'], $rule))));
        self::assertContains('2023-03', array_column($this->anomalies->detect('gas', $this->monthly('gas')), 'ym'), 'die Analyse kennt den Monat');
        $then = $this->recommendations('2024-06-30');
        self::assertContains('2023-03', $ymOf($then, 'r1'), 'Kontrolle: damals empfohlen');
        self::assertContains('2023-06', $ymOf($then, 'r4'));
        // R3: Juli gegen Januar — die Grundlast C allein ist mehr als 30 % des Januars
        self::assertContains('2023-07', $ymOf($then, 'r3'));
        $now = $this->recommendations('2026-01-31');
        self::assertNotContains('2023-03', $ymOf($now, 'r1'), 'heute zu alt');
        self::assertNotContains('2023-06', $ymOf($now, 'r4'));
        self::assertSame(['2024-07', '2025-07'], $ymOf($now, 'r3'));
        self::assertTrue(RecommendationService::isRecent('2024-01', '2026-01-31') && !RecommendationService::isRecent('2023-12', '2026-01-31'));
    }

    // ── Kennung je Monat: die Länge des Ablesungsabstands ───────────────

    public function testReadingsAtMonthEndMeasureEveryMonth(): void
    {
        $this->seedTemps('2024-01-01', '2025-01-31');
        $dates = [];
        for ($m = 1; $m <= 12; $m++) $dates[] = date('Y-m-t', strtotime(sprintf('2024-%02d-01', $m)));
        $this->seedReadings('gas', $dates);
        $monthly = $this->monthly('gas');
        self::assertSame([], self::interpolatedYms($monthly));
        foreach ($monthly as $m) {
            self::assertSame(1.0, (float)$m['measured_share'], $m['ym']);
            self::assertLessThanOrEqual(31, $m['reading_gap_days'], $m['ym']);
        }
    }

    public function testReadingsOnTheFirstMeasureEveryMonth(): void
    {
        $this->seedTemps('2024-01-01', '2025-01-31');
        $this->seedReadings('gas', self::firsts('2024-01', '2025-01'));
        $monthly = $this->monthly('gas');
        self::assertCount(12, $monthly);
        self::assertSame([], self::interpolatedYms($monthly));
    }

    public function testIrregularReadingsAroundTheMonthChangeMeasureEveryMonth(): void
    {
        $this->seedTemps('2024-01-01', '2024-12-31');
        $this->seedReadings('gas', ['2024-01-03', '2024-01-29', '2024-03-04', '2024-03-28', '2024-05-02', '2024-05-30', '2024-07-03', '2024-08-05']);
        self::assertSame([], self::interpolatedYms($this->monthly('gas')), 'Abstände von 26 bis 35 Tagen messen jeden Monat');
    }

    /** Die Regel aus der Zusage: am 31. und am Ersten des übernächsten Monats — der Monat dazwischen ist gemessen. */
    public function testThe31stAndTheFirstOfTheMonthAfterNextMeasureTheMonthBetween(): void
    {
        $this->seedTemps('2025-01-01', '2025-06-30');
        $this->seedReadings('gas', ['2025-01-31', '2025-03-01', '2025-06-01']);
        $by = $this->byYm($this->monthly('gas'));
        self::assertFalse($by['2025-02']['interpolated'], 'keine Ablesung im Februar, trotzdem gemessen');
        self::assertSame(29, $by['2025-02']['reading_gap_days']);
        foreach (['2025-03', '2025-04', '2025-05'] as $ym) {
            self::assertTrue($by[$ym]['interpolated'], "$ym aus drei Monaten verteilt");
            self::assertSame(0.0, (float)$by[$ym]['measured_share']);
            self::assertSame(92, $by[$ym]['reading_gap_days']);
        }
    }

    public function testAThreeMonthGapIsInterpolatedAndOnlyThat(): void
    {
        $this->seedTemps('2024-01-01', '2025-03-31');
        $this->seedReadings('gas', array_merge(self::firsts('2024-01', '2024-10'), self::firsts('2025-01', '2025-03')));
        self::assertSame(['2024-10', '2024-11', '2024-12'], self::interpolatedYms($this->monthly('gas')));
    }

    public function testTheLimitIsTheConstant(): void
    {
        // 45 Tage messen noch, 46 nicht (Tage ab dem 1. Januar)
        $this->seedTemps('2025-01-01', '2025-12-31');
        $this->seedReadings('gas', ['2025-01-01', '2025-02-15', '2025-04-02']);
        $by = $this->byYm($this->monthly('gas'));
        self::assertSame(ConsumptionService::MEASURED_GAP_MAX_DAYS, $by['2025-01']['reading_gap_days']);
        self::assertFalse($by['2025-01']['interpolated']);
        self::assertSame(46, $by['2025-03']['reading_gap_days']);
        self::assertTrue($by['2025-03']['interpolated']);
    }

    /** Eine geschätzte Ablesung misst nichts: Der Abstand reicht von der echten davor bis zur echten danach. */
    public function testAnEstimatedReadingDoesNotMeasure(): void
    {
        $this->seedTemps('2024-01-01', '2024-06-30');
        $this->seedReadings('gas', ['2024-01-01', ['2024-02-01', ['is_estimated' => true]], '2024-03-01', '2024-04-01', '2024-05-01']);
        $by = $this->byYm($this->monthly('gas'));
        self::assertTrue($by['2024-01']['interpolated'], 'Januar: Abstand 1.1.–1.3. = 60 Tage');
        self::assertTrue($by['2024-02']['interpolated']);
        self::assertSame(60, $by['2024-02']['reading_gap_days']);
        self::assertFalse($by['2024-03']['interpolated']);
    }

    /** Ein Monat halb aus einem langen, halb aus einem kurzen Abstand: der Anteil am Verbrauch entscheidet. */
    public function testTheShareOfTheMonthDecides(): void
    {
        $this->seedTemps('2024-01-01', '2024-12-31');
        // 1.6. → 11.9. (102 Tage), 11.9. → 1.10.: zehn Tage September verteilt, zwanzig gemessen
        $this->seedReadings('gas', ['2024-06-01', '2024-09-11', '2024-10-01']);
        $sep = $this->byYm($this->monthly('gas'))['2024-09'];
        self::assertFalse($sep['interpolated']);
        self::assertEqualsWithDelta(20 / 30, $sep['measured_share'], 0.03);
        // … und andersherum: zwanzig verteilt, zehn gemessen
        $this->seedReadings('gas', ['2024-06-01', '2024-09-21', '2024-10-01']);
        $sep = $this->byYm($this->monthly('gas'))['2024-09'];
        self::assertTrue($sep['interpolated']);
        self::assertEqualsWithDelta(10 / 30, $sep['measured_share'], 0.03);
    }

    /** Zählertausch bleibt eine eigene Kennung: Der Monat des Tauschs ist gemessen und `device_swap`. */
    public function testDeviceSwapKeepsItsOwnMarker(): void
    {
        $this->seedTemps('2024-01-01', '2024-12-31');
        $final = round($this->counterOn('2024-01-01', '2024-06-15'), 4);
        $meterId = $this->setMeterDevices('gas', [
            ['id' => 'd1', 'serial' => 'A', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0, 'removed_on' => '2024-06-15', 'final_counter' => $final, 'reason' => 'Tausch'],
            ['id' => 'd2', 'serial' => 'B', 'installed_on' => '2024-06-15', 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null],
        ]);
        $rows = [];
        foreach (self::firsts('2024-01', '2024-06') as $d) $rows[] = ['date' => $d, 'counter' => round($this->counterOn('2024-01-01', $d), 4), 'device_id' => 'd1'];
        foreach (self::firsts('2024-07', '2024-09') as $d) $rows[] = ['date' => $d, 'counter' => round($this->counterOn('2024-06-15', $d), 4), 'device_id' => 'd2'];
        $this->setReadings('gas', $meterId, $rows);
        $jun = $this->byYm($this->monthly('gas'))['2024-06'];
        self::assertTrue($jun['device_swap']);
        self::assertFalse($jun['interpolated']);
        self::assertEqualsWithDelta(30 * (self::A * 0.0 + self::C), $jun['kwh'], 0.5, 'Juni überbrückt, wie bisher');
    }

    /** Zeiträume (Verbrauchsinfo, Rechnung): Ein Jahreszeitraum ist verteilt, ein Monatszeitraum gemessen, ein geschätzter nicht. */
    public function testPeriodsAreTheirOwnReadingGap(): void
    {
        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $m = (string)$this->meters->create('waerme', ['name' => 'Wärmemengenzähler', 'capture' => 'period', 'installed_on' => '2024-01-01'])['id'];
        $periods->create('waerme', ['meter_id' => $m, 'from' => '2024-01-01', 'to' => '2024-12-31', 'value' => 9000]);
        $periods->create('waerme', ['meter_id' => $m, 'month' => '2025-01', 'value' => 900]);
        $periods->create('waerme', ['meter_id' => $m, 'month' => '2025-02', 'value' => 800, 'is_estimated' => true]);
        $by = $this->byYm($this->consumption->forMeter('waerme', $this->meters->get('waerme', $m)));
        self::assertTrue($by['2024-06']['interpolated']);
        self::assertSame(366, $by['2024-06']['reading_gap_days']);
        self::assertFalse($by['2025-01']['interpolated']);
        self::assertTrue($by['2025-02']['interpolated'], 'geschätzter Zeitraum misst nichts');
    }

    /** Heizöl und Pellets: Monatswerte nach Gradtagen modelliert — die Kennung gilt nicht (null), wie bisher keine Statistik. */
    public function testDeliveryMonthsCarryNoMarker(): void
    {
        $this->seedTemps('2024-01-01', date('Y-m-d'));
        $tank = $this->meters->create('heizoel', ['name' => 'Tank', 'capacity' => 3000, 'initial_stock' => 1500, 'installed_on' => '2024-01-01']);
        (new DeliveryService($this->store, $this->meters, $this->i18n))->create('heizoel', [
            'meter_id' => $tank['id'], 'date' => '2024-09-01', 'quantity' => 1000.0, 'unit_price_cents' => 100.0, 'fill_to_full' => true,
        ]);
        $monthly = $this->consumption->forMeter('heizoel', $this->meters->get('heizoel', $tank['id']));
        self::assertNotEmpty($monthly);
        foreach ($monthly as $m) {
            self::assertNull($m['interpolated'], $m['ym']);
            self::assertNull($m['measured_share']);
        }
        self::assertNull($this->consumption->interpolationInfo('heizoel', $this->meters->get('heizoel', $tank['id']), $monthly));
    }

    /** Summe mehrerer Zähler: gewichtet nach Verbrauch — ein dünner kleiner Zähler macht die Summe nicht „verteilt“. */
    public function testUtilityTotalsWeighTheMarkerByConsumption(): void
    {
        $this->seedTemps('2024-01-01', '2024-12-31');
        $dev = fn(string $id) => [['id' => $id, 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $this->store->write('strom/meters.json', [
            ['id' => 'm_big', 'name' => 'Haus', 'active' => true, 'devices' => $dev('d_big')],
            ['id' => 'm_small', 'name' => 'Garage', 'active' => true, 'devices' => $dev('d_small')],
        ]);
        $rows = [];
        foreach (self::firsts('2024-01', '2024-07') as $i => $d) $rows[] = ['id' => "r_b$i", 'meter_id' => 'm_big', 'device_id' => 'd_big', 'date' => $d, 'counter' => $i * 300.0];
        $rows[] = ['id' => 'r_s0', 'meter_id' => 'm_small', 'device_id' => 'd_small', 'date' => '2024-01-01', 'counter' => 0.0];
        $rows[] = ['id' => 'r_s1', 'meter_id' => 'm_small', 'device_id' => 'd_small', 'date' => '2024-07-01', 'counter' => 182.0];
        $this->store->write('strom/readings.json', $rows);
        $u = $this->consumption->forUtility('strom');
        $small = array_values(array_filter($u['meters'], fn($e) => $e['meter']['id'] === 'm_small'))[0]['monthly'];
        self::assertCount(6, self::interpolatedYms($small), 'der kleine Zähler: alle Monate verteilt');
        foreach ($u['monthly_total'] as $t) {
            self::assertFalse($t['interpolated'], $t['ym']);
            self::assertGreaterThan(0.9, $t['measured_share']);
        }
    }

    // ── Was verteilte Monate auslassen ───────────────────────────────────

    /**
     * Monatlich abgelesen, nur Oktober bis Dezember 2024 nicht: Ein echter
     * Mehrverbrauch im Oktober verteilt sich gleichmäßig auf drei Monate. Bis
     * v3.6 meldete die Tabelle alle drei als „über der Erwartung“, die
     * Empfehlung r1 ebenso — keiner davon ist gemessen. Ein gemessener
     * Ausreißer wird weiter gefunden.
     */
    private function seedGapWithExcess(): void
    {
        $this->seedTemps('2023-01-01', '2026-01-31');
        $this->seedReadings('gas', array_merge(self::firsts('2023-01', '2024-10'), self::firsts('2025-01', '2026-01')),
            ['2024-10' => 6.0, '2025-11' => 3.0]);
    }

    public function testInterpolatedMonthsLeaveTheAnomalyTable(): void
    {
        $this->seedGapWithExcess();
        $monthly = $this->monthly('gas');
        self::assertSame(['2024-10', '2024-11', '2024-12'], self::interpolatedYms($monthly));
        $found = array_column($this->anomalies->detect('gas', $monthly), 'ym');
        self::assertSame([], array_values(array_intersect($found, self::interpolatedYms($monthly))), 'kein verteilter Monat in der Tabelle');
        self::assertContains('2025-11', $found, 'der gemessene Ausreißer bleibt');
    }

    public function testRecommendationR1SkipsInterpolatedMonths(): void
    {
        $this->seedGapWithExcess();
        $r1 = array_values(array_filter($this->recommendations(), fn($r) => str_starts_with((string)$r['id'], 'r1')));
        $yms = array_map(fn($r) => $r['evidence']['ym'] ?? null, $r1);
        self::assertSame([], array_values(array_intersect($yms, ['2024-10', '2024-11', '2024-12'])));
        self::assertContains('2025-11', $yms);
    }

    /** Zwei Ablesungen ein Jahr auseinander: Juli = Januar je Tag. Bis v3.6 hieß das „Sommer-Heizverbrauch unplausibel“ (r3). */
    public function testRecommendationR3SkipsInterpolatedSummerAndWinter(): void
    {
        $this->seedTemps('2024-01-01', '2025-01-31');
        $this->seedReadings('gas', ['2024-01-01', '2025-01-01']);
        self::assertCount(12, self::interpolatedYms($this->monthly('gas')));
        self::assertSame([], array_values(array_filter($this->recommendations(), fn($r) => str_starts_with((string)$r['id'], 'r3'))));
    }

    public function testInterpolatedMonthsStayOutOfTheHeatModel(): void
    {
        $this->seedTemps('2023-01-01', '2026-01-31');
        // Winter 2024/25 nur am 1.11. und 1.3. abgelesen — gleichmäßig verteilt läge er nicht auf der Heizkurve
        $this->seedReadings('gas', array_merge(self::firsts('2023-01', '2024-11'), self::firsts('2025-03', '2026-01')));
        $monthly = $this->monthly('gas');
        self::assertSame(['2024-11', '2024-12', '2025-01', '2025-02'], self::interpolatedYms($monthly));
        $model = $this->consumption->heatModel('gas', $monthly);
        self::assertSame('months', $model['basis']);
        self::assertEqualsWithDelta(self::A, $model['a'], 1e-6, 'nur gemessene Monate: die Wahrheit');
        self::assertEqualsWithDelta(self::C, $model['c'], 1e-6);
        $by = $this->byYm($monthly);
        foreach (['2024-11', '2024-12', '2025-01', '2025-02'] as $ym) self::assertFalse($by[$ym]['regression_point'], $ym);
        self::assertSame(count(array_filter($monthly, fn($m) => $m['regression_point'])),
            $this->consumption->regressionPoints($monthly, 'gas')['n'], 'Markierung und Auswahl sind dieselbe');
        // die Erwartung gilt auch für den verteilten Monat (sie hängt am Wetter, nicht am Wert)
        self::assertEqualsWithDelta(self::A * 31 * 15 + self::C * 31, $by['2025-01']['expected_heat'], 0.1);
    }

    // ── Zu wenig gemessene Monate: über die Ablesungsabstände ────────────

    public function testFewMeasuredMonthsFallBackToTheReadingIntervals(): void
    {
        $this->seedTemps('2023-01-01', '2026-01-31');
        $dates = [];
        for ($t = strtotime('2023-01-01'); date('Y-m-d', $t) <= '2026-01-01'; $t = strtotime('+3 months', $t)) $dates[] = date('Y-m-d', $t);
        $this->seedReadings('gas', $dates);   // vierteljährlich: 13 Ablesungen, 12 Abstände
        $monthly = $this->monthly('gas');
        self::assertCount(36, self::interpolatedYms($monthly));
        self::assertNull($this->consumption->heatModel('gas', $monthly), 'ohne Zähler kein Rückfall — und kein stiller über verteilte Monate');
        $model = $this->consumption->heatModel('gas', $monthly, $this->meter('gas'));
        self::assertSame('intervals', $model['basis']);
        self::assertSame(12, $model['n']);
        self::assertEqualsWithDelta(self::A, $model['a'], 1e-6, 'das Modell ist eine Summe über Tage: ein Vierteljahr ist ein gültiger Punkt');
        self::assertEqualsWithDelta(self::C, $model['c'], 1e-6);
        // die Zeilen tragen die Erwartung aus diesem Modell, die Bereinigung ebenso
        $jan = $this->byYm($monthly)['2024-01'];
        self::assertEqualsWithDelta(self::A * 31 * 15 + self::C * 31, $jan['expected_heat'], 0.1);
        self::assertNotNull($jan['heat_adjusted']);
        // … und die Prognose rechnet mit ihm statt mit dem flachen Saisonprofil verteilter Monate
        $fc = $this->forecast();
        self::assertTrue($fc['valid']);
        self::assertSame('intervals', $fc['heat_model']['basis']);
        $janFc = array_values(array_filter($fc['forecast'], fn($r) => $r['month'] === 1))[0];
        self::assertSame('heat_model', $janFc['method']);
        self::assertEqualsWithDelta(self::A * 31 * 15 + self::C * 31, $janFc['kwh'], 1.0);
        $w = array_values(array_filter($fc['warnings'], fn($x) => $x['code'] === 'interpolated'))[0];
        self::assertSame(36, $w['months']);
        self::assertSame(13, $w['readings']);
        self::assertSame('intervals', $w['heat_basis']);
        $info = $this->consumption->interpolationInfo('gas', $this->meter('gas'), $monthly);
        self::assertSame(['readings' => 13, 'interpolated_months' => 36, 'heat_basis' => 'intervals'],
            ['readings' => $info['readings'], 'interpolated_months' => $info['interpolated_months'], 'heat_basis' => $info['heat_basis']]);
    }

    /**
     * Ein Abstand zählt nach seiner Länge (Gewicht 30,4 / Tage): Die Streuung
     * eines Vierteljahrs wächst mit seinen Tagen. `resid_sd` gilt damit je
     * mittleren Monat — wie beim Fit über Monate, den die Prognose als Band nutzt.
     */
    public function testIntervalPointsAreWeightedByLength(): void
    {
        $this->seedTemps('2023-01-01', '2026-01-31');
        $dates = []; $extra = [];
        for ($k = 0, $t = strtotime('2023-01-01'); date('Y-m-d', $t) <= '2026-01-01'; $k++, $t = strtotime('+3 months', $t)) {
            $dates[] = date('Y-m-d', $t);
            for ($j = 0; $j < 3; $j++) $extra[date('Y-m', strtotime("+$j months", $t))] = $k % 2 ? 1.0 : -1.0;   // je Tag ±1 kWh, vierteljährlich wechselnd
        }
        $this->seedReadings('gas', $dates, $extra);
        $model = $this->consumption->heatModel('gas', $this->monthly('gas'), $this->meter('gas'));
        self::assertSame('intervals', $model['basis']);
        // ±1 kWh je Tag: ein Monat streut um ~30 kWh, ein Vierteljahr um ~91 — gewichtet ~√(91 × 30,4) ≈ 53
        self::assertGreaterThan(35.0, $model['resid_sd']);
        self::assertLessThan(75.0, $model['resid_sd'], 'je Monat, nicht je Vierteljahr');
    }

    /** Rückfall über die Abstände: Abstände vor der Zäsur beschreiben ein anderes Gebäude und gehen nicht ein. */
    public function testIntervalPointsStartAfterTheBaseline(): void
    {
        $this->seedTemps('2023-01-01', '2026-01-31');
        $before = fn(float $hdd, string $d) => $d < '2024-07-01' ? 1.5 * (self::A * $hdd + self::C) : self::A * $hdd + self::C;
        $dates = [];
        for ($t = strtotime('2023-01-01'); date('Y-m-d', $t) <= '2026-01-01'; $t = strtotime('+2 months', $t)) $dates[] = date('Y-m-d', $t);
        $meterId = $this->seedReadings('gas', $dates, [], $before);   // alle zwei Monate: jeder Monat verteilt
        $this->meters->update('gas', $meterId, ['baseline_events' => [['date' => '2024-07-01', 'label' => 'Dämmung']]]);
        $meter = $this->meters->get('gas', $meterId);
        $model = $this->consumption->heatModel('gas', $this->consumption->forMeter('gas', $meter), $meter);
        self::assertSame('intervals', $model['basis']);
        self::assertSame(9, $model['n'], 'neun Abstände nach der Zäsur');
        self::assertEqualsWithDelta(self::A, $model['a'], 1e-6, 'das Gebäude danach, nicht der Mischwert');
        self::assertEqualsWithDelta(self::C, $model['c'], 1e-6);
    }

    /** Ein Monat ohne Verbrauch mitten in einer langen Lücke: Der Anteil der Tage entscheidet — verteilt. */
    public function testAMonthWithoutConsumptionCountsByDays(): void
    {
        $this->seedReadings('strom', ['2024-01-01', '2024-02-01', '2024-05-01', '2024-06-01'], [], fn(float $h, string $d) => in_array(substr($d, 0, 7), ['2024-02', '2024-03', '2024-04'], true) ? 0.0 : 5.0);
        $by = $this->byYm($this->monthly('strom'));
        self::assertSame(0.0, (float)$by['2024-03']['kwh']);
        self::assertTrue($by['2024-03']['interpolated']);
        self::assertFalse($by['2024-01']['interpolated']);
    }

    /** Zählergruppe: dieselbe Gewichtung nach Verbrauch wie die Summe der Art. */
    public function testAGroupWeighsItsMembersMarkers(): void
    {
        $dev = fn(string $id) => [['id' => $id, 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $this->store->write('strom/meter_groups.json', [['id' => 'g_test', 'name' => 'HT/NT', 'created_at' => '2024-01-01']]);
        $this->store->write('strom/meters.json', [
            ['id' => 'm_ht', 'name' => 'HT', 'active' => true, 'meter_group_id' => 'g_test', 'devices' => $dev('d_ht')],
            ['id' => 'm_nt', 'name' => 'NT', 'active' => true, 'meter_group_id' => 'g_test', 'devices' => $dev('d_nt')],
        ]);
        $rows = [];
        foreach (self::firsts('2024-01', '2024-07') as $i => $d) $rows[] = ['id' => "r_h$i", 'meter_id' => 'm_ht', 'device_id' => 'd_ht', 'date' => $d, 'counter' => $i * 30.0];
        $rows[] = ['id' => 'r_n0', 'meter_id' => 'm_nt', 'device_id' => 'd_nt', 'date' => '2024-01-01', 'counter' => 0.0];
        $rows[] = ['id' => 'r_n1', 'meter_id' => 'm_nt', 'device_id' => 'd_nt', 'date' => '2024-07-01', 'counter' => 1800.0];
        $this->store->write('strom/readings.json', $rows);
        $group = $this->meters->target('strom', 'g_test');
        foreach ($this->consumption->forMeter('strom', $group) as $m) {
            self::assertTrue($m['interpolated'], $m['ym'] . ': NT trägt neun Zehntel des Verbrauchs und ist verteilt');
            self::assertLessThan(0.2, $m['measured_share']);
        }
    }

    /** Saldo-Schätzung bis heute: dasselbe Heizmodell wie Analyse und Prognose, also mit Rückfall über die Ablesungsabstände. */
    public function testTheBalanceEstimateUsesTheSameHeatModel(): void
    {
        $this->seedTemps('2023-01-01', '2026-01-31');
        $dates = [];
        for ($t = strtotime('2023-01-01'); date('Y-m-d', $t) <= '2026-01-01'; $t = strtotime('+3 months', $t)) $dates[] = date('Y-m-d', $t);
        $this->seedReadings('gas', $dates);
        $est = new \ReflectionMethod($this->consumption, 'volumeEstimator');
        $fn = $est->invoke($this->consumption, 'gas', $this->monthly('gas'), 'kwh', $this->meter('gas'));
        $jan = $fn('2025-01-15', '2025-01-16')['2025-01-15'];
        self::assertEqualsWithDelta(self::A * 15 + self::C, $jan, 0.05, 'ein Januartag nach dem Modell — nicht der Schnitt eines verteilten Vierteljahrs');
    }

    /**
     * #37-Nachtrag — die Saldo-Schätzung folgt dem jüngsten Niveau nur über
     * gemessene Monate. Hier die letzten sieben Monate ohne Ablesung, darin
     * viel Mehrverbrauch: verteilt ergäbe das Faktor 1,5 auf jede Schätzung.
     */
    public function testTheBalanceCalibrationUsesOnlyMeasuredMonths(): void
    {
        $first = date('Y-m-01');
        $from = date('Y-m-01', strtotime("$first -36 months"));
        $this->seedTemps($from, date('Y-m-d'));
        $dates = [];
        for ($t = strtotime($from); $t <= strtotime("$first -8 months"); $t = strtotime('+1 month', $t)) $dates[] = date('Y-m-d', $t);
        $dates[] = date('Y-m-d', strtotime("$first -1 month"));
        $extra = [];
        for ($k = 2; $k <= 8; $k++) $extra[date('Y-m', strtotime("$first -$k months"))] = 8.0;
        $this->seedReadings('gas', $dates, $extra);
        $est = new \ReflectionMethod($this->consumption, 'volumeEstimator');
        $est->invoke($this->consumption, 'gas', $this->monthly('gas'), 'kwh', $this->meter('gas'));
        // v3.7.0 (Schritt 3) — zwei Faktoren: Grundlast und Heizanteil
        $p = (new \ReflectionProperty($this->consumption, 'lastProjection'))->getValue($this->consumption);
        self::assertEqualsWithDelta(1.0, $p['base'], 0.02, 'gemessene Monate des letzten Jahres liegen auf dem Modell (Grundlast)');
        self::assertEqualsWithDelta(1.0, $p['heat'], 0.02, 'gemessene Monate des letzten Jahres liegen auf dem Modell (Heizanteil)');
    }

    /** #37-Nachtrag — Tagesrate je Kalendermonat ohne Heizmodell: aus gemessenen Monaten, verteilte nur, wo der Monat nie gemessen wurde. */
    public function testTheBalanceRatesUseMeasuredMonthsFirst(): void
    {
        $rate = fn(float $h, string $d) => (int)substr($d, 5, 2) === 1 ? 12.0 : 6.0;
        // Januar 2024 gemessen; Januar 2025 nur aus Dezember–März verteilt
        $this->seedReadings('strom', array_merge(self::firsts('2023-12', '2024-12'), ['2025-03-01', '2025-04-01']), [], $rate);
        $est = new \ReflectionMethod($this->consumption, 'volumeEstimator');
        $fn = $est->invoke($this->consumption, 'strom', $this->monthly('strom'), 'kwh', $this->meter('strom'));
        self::assertEqualsWithDelta(12.0, $fn('2026-01-10', '2026-01-11')['2026-01-10'], 0.01, 'Januar wie gemessen, nicht der Schnitt mit dem verteilten');
    }

    /** Zwei Ablesungen: kein Modell möglich — die Prognose verschwindet trotzdem nicht und sagt, warum sie grob ist. */
    public function testTooFewReadingsForAnyModelStillGiveAForecast(): void
    {
        $this->seedTemps('2024-01-01', '2025-01-31');
        $this->seedReadings('gas', ['2024-01-01', '2025-01-01']);
        $monthly = $this->monthly('gas');
        self::assertNull($this->consumption->heatModel('gas', $monthly, $this->meter('gas')));
        $fc = $this->forecast();
        self::assertTrue($fc['valid']);
        self::assertCount(12, $fc['forecast']);
        self::assertNull($fc['heat_model']);
        // gleichmäßig verteilt hat jeder Monat dieselbe Tagesrate — mehr weiß die Prognose nicht
        $rate = $this->counterOn('2024-01-01', '2025-01-01') / 366;
        $jan = array_values(array_filter($fc['forecast'], fn($r) => $r['month'] === 1))[0];
        self::assertSame('seasonal_interpolated', $jan['method']);
        self::assertEqualsWithDelta($rate * 31, $jan['kwh'], 0.5);
        $w = array_values(array_filter($fc['warnings'], fn($x) => $x['code'] === 'interpolated'))[0];
        self::assertSame(['months' => 12, 'readings' => 2, 'heat_basis' => null], ['months' => $w['months'], 'readings' => $w['readings'], 'heat_basis' => $w['heat_basis']]);
        $short = array_values(array_filter($fc['warnings'], fn($x) => $x['code'] === 'history_short'));
        self::assertSame(0, $short[0]['months'] ?? null, 'kein gemessener Kalendermonat — die kurze Historie wird gemeldet');
        self::assertCount(12, $short[0]['missing']);
    }

    /**
     * Gartenzähler, im Winter nicht abgelesen: Für Monate ohne Messung bleibt
     * die Verteilung die beste Auskunft — nicht das Mittel des ganzen Jahres.
     */
    public function testWinterWithoutReadingsKeepsTheInterpolatedRateInTheForecast(): void
    {
        $use = fn(float $hdd, string $d) => in_array((int)substr($d, 5, 2), [5, 6, 7, 8, 9], true) ? 0.4 : 0.02;
        $dates = [];
        foreach ([2023, 2024, 2025] as $y) {
            foreach (['04-01', '05-01', '06-01', '07-01', '08-01', '09-01', '10-01'] as $md) $dates[] = "$y-$md";
        }
        $this->seedReadings('wasser', $dates, [], $use);
        $fc = $this->forecast('wasser');
        $byM = array_column($fc['forecast'], null, 'month');
        self::assertSame('seasonal_interpolated', $byM[1]['method']);
        self::assertLessThan(0.1 * $byM[7]['m3'], $byM[1]['m3'], 'Januar bleibt klein');
        self::assertSame('seasonal_only', $byM[7]['method']);
    }

    // ── Gütesperre (#38) ─────────────────────────────────────────────────

    public function testWellFittingModelIsGoodAndNamesItsFloor(): void
    {
        $this->seedTemps('2023-01-01', '2026-01-31');
        $this->seedReadings('gas', self::firsts('2023-01', '2026-01'));
        $q = $this->anomalies->quality('gas', $this->monthly('gas'));
        self::assertSame('good', $q['model_quality']);
        self::assertSame([], $q['reasons']);
        self::assertLessThan(AnomalyService::POOR_FIT_RATIO, $q['ratio']);
        self::assertEqualsWithDelta(1.0, $q['base_load']['ratio'], 0.05, 'Grundlast des Modells = gemessener Sommer');
        self::assertEqualsWithDelta(self::C, $q['base_load']['measured_per_day'], 0.1);
        // glatte Daten: die Rauschuntergrenze (10 % eines typischen Monats) bestimmt die Grenze
        self::assertEqualsWithDelta(2.0 * 0.10 * $q['typical'], $q['detect_floor'], 0.2);
        self::assertSame('kWh', $q['unit']);
    }

    /**
     * Verbrauch, den das lineare Modell nicht trifft (Monat für Monat ±35 %):
     * Die Tabelle bliebe leer — die Gütesperre sagt, dass sie blind ist und ab
     * welcher Abweichung sie überhaupt etwas sähe.
     */
    public function testPoorlyFittingModelIsReportedInsteadOfAnEmptyTable(): void
    {
        $this->seedTemps('2023-01-01', '2026-01-31');
        $swing = [0.65, 1.35, 1.0];
        $model = fn(float $hdd, string $d) => (self::A * $hdd + self::C) * $swing[((int)substr($d, 0, 4) * 12 + (int)substr($d, 5, 2)) % 3];
        $this->seedReadings('gas', self::firsts('2023-01', '2026-01'), [], $model);
        $monthly = $this->monthly('gas');
        self::assertSame([], $this->anomalies->detect('gas', $monthly), 'die Tabelle ist leer …');
        $q = $this->anomalies->quality('gas', $monthly);
        self::assertSame('poor', $q['model_quality'], '… aber nicht, weil alles in Ordnung ist');
        self::assertGreaterThan(AnomalyService::POOR_FIT_RATIO, $q['ratio']);
        self::assertEqualsWithDelta(2.0 * $q['resid_scale'], $q['detect_floor'], 0.11);
        self::assertGreaterThan(0.5 * $q['typical'], $q['detect_floor'], 'erst ab mehr als einem halben typischen Monat');
    }

    public function testQualityOnlyForHeatModels(): void
    {
        $this->seedReadings('strom', self::firsts('2023-01', '2026-01'), [], fn() => 8.0);
        self::assertNull($this->anomalies->quality('strom', $this->monthly('strom')));
    }

    // ── PV (#39) ─────────────────────────────────────────────────────────

    private function seedPv(array $genDates): void
    {
        $mk = function (string $u, array $dates, callable $perDay) {
            $meterId = $this->setMeterDevices($u, [[
                'id' => 'd_' . $u, 'serial' => null, 'installed_on' => $dates[0],
                'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
            ]], 'm_' . $u . '_t');
            $rows = []; $c = 0.0; $prev = $dates[0];
            foreach ($dates as $date) {
                for ($t = strtotime($prev . ' 12:00'); date('Y-m-d', $t) < $date; $t += 86400) $c += $perDay(date('Y-m-d', $t));
                $prev = $date;
                $rows[] = ['date' => $date, 'counter' => round($c, 3), 'device_id' => 'd_' . $u];
            }
            $this->setReadings($u, $meterId, $rows);
        };
        $gen = fn(string $d) => [1 => 0.6, 2 => 1.1, 3 => 2.0, 4 => 3.0, 5 => 3.8, 6 => 4.1, 7 => 4.2, 8 => 3.6, 9 => 2.6, 10 => 1.6, 11 => 0.8, 12 => 0.5][(int)substr($d, 5, 2)];
        $dates = self::firsts('2024-03', '2025-10');
        $mk('strom', $dates, fn(string $d) => 7.5);
        $mk('pv_einspeisung', $dates, fn(string $d) => 0.4 * $gen($d));
        $mk('pv_erzeugung', $genDates, $gen);
    }

    /** Ein Balkonkraftwerk mit zwei Ablesungen: alle Monate gleich je Tag. Die Übersicht sagt es und kennzeichnet die Monate. */
    public function testPvWithTwoReadingsSaysItsMonthsAreInterpolated(): void
    {
        $this->seedPv(['2024-03-01', '2025-10-01']);
        $pv = (new PvSummaryService($this->consumption, $this->settings))->compute();
        self::assertContains('interpolated', $pv['hints']);
        self::assertSame(['utility' => 'pv_erzeugung', 'readings' => 2, 'from' => '2024-03', 'to' => '2025-09', 'months' => 19, 'interpolated_months' => 19],
            $pv['interpolation']);
        foreach ($pv['monthly'] as $m) {
            self::assertTrue($m['interpolated'], $m['ym']);
            self::assertSame(['erzeugung_kwh'], $m['interpolated_series']);
        }
        self::assertSame([10, 9], array_column($pv['yearly'], 'months_interpolated'));
        // die Werte selbst bleiben, wie sie bis v3.6 waren — nur gekennzeichnet
        $want = json_decode((string)file_get_contents(self::FIXTURES . 'pv-v3.6.json'), true);
        $years = array_pop($want);
        self::assertEquals($want, array_map(fn($m) => [$m['ym'], $m['erzeugung_kwh'], $m['einspeisung_kwh'], $m['bezug_kwh'], $m['eigenverbrauch_kwh'], $m['autarkiequote'], $m['covered']], $pv['monthly']));
        self::assertEquals($years, array_map(fn($y) => [$y['year'], $y['erzeugung_kwh'], $y['eigenverbrauch_kwh'], $y['autarkiequote']], $pv['yearly']));
    }

    public function testMeasuredPvHasNoHint(): void
    {
        $this->seedPv(self::firsts('2024-03', '2025-10'));
        $pv = (new PvSummaryService($this->consumption, $this->settings))->compute();
        self::assertNotContains('interpolated', $pv['hints']);
        self::assertNull($pv['interpolation']);
        self::assertSame([], array_values(array_filter($pv['monthly'], fn($m) => $m['interpolated'])));
    }

    // ── Was sich nicht ändert (aufgenommen mit dem Stand vor v3.7.0) ─────

    /**
     * Monatliche Ablesungen, eine Lücke Juni bis Ende September 2024, eine
     * geschätzte Ablesung Ende Oktober, dann erst wieder Ende Januar; zwei
     * Verträge, die vor der letzten Ablesung enden (keine Schätzung bis heute).
     */
    private function seedFixedHousehold(): void
    {
        $this->seedTemps('2023-01-01', '2025-06-30');
        $dates = array_merge(
            self::firsts('2023-01', '2024-06'),
            ['2024-09-30', ['2024-10-31', ['is_estimated' => true]], '2025-01-31'],
            self::firsts('2025-03', '2025-06'),
        );
        $meterId = $this->seedReadings('gas', $dates, ['2024-07' => 2.0, '2024-12' => 4.0]);
        $this->store->write('gas/contracts.json', [
            [
                'id' => 'c1', 'meter_id' => $meterId, 'provider' => 'Anbieter A', 'tariff_name' => 'Tarif A',
                'start' => '2023-01-01', 'end' => '2024-12-31', 'auto_renews' => false,
                'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 10.0], ['from' => '2024-04-01', 'ct_per_kwh' => 12.5]],
                'base_prices' => [['from' => '2023-01-01', 'eur_per_month' => 12.0]],
                'advance_payments' => [['from' => '2023-01-01', 'amount_eur' => 90.0]],
                'bonuses' => [], 'special_payments' => [],
            ],
            [
                'id' => 'c2', 'meter_id' => $meterId, 'provider' => 'Anbieter B', 'tariff_name' => 'Tarif B',
                'start' => '2025-01-01', 'end' => '2025-05-31', 'auto_renews' => false,
                'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 11.0]],
                'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 10.0]],
                'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 80.0]],
                'bonuses' => [], 'special_payments' => [],
            ],
        ]);
    }

    public function testMonthlyValuesCostsAdvancesAndBalanceStayAsTheyWere(): void
    {
        $this->seedFixedHousehold();
        $want = json_decode((string)file_get_contents(self::FIXTURES . 'household-v3.6.json'), true);
        $meter = $this->meter('gas');
        $monthly = $this->consumption->forMeter('gas', $meter);
        self::assertNotSame([], self::interpolatedYms($monthly), 'der Haushalt hat verteilte Monate');
        $fields = ['kwh', 'm3', 'days', 'cost', 'kwh_cost', 'base_price_eur', 'advance_eur', 'monthly_balance', 'cumulative_balance', 'contract_id', 'co2_kg'];
        $got = [];
        foreach ($monthly as $m) $got[$m['ym']] = array_intersect_key($m, array_flip($fields));
        self::assertEquals($want['monthly'], $got);
        $gotC = [];
        foreach ($this->consumption->contractStatus('gas', $meter)['contracts'] as $c) {
            $gotC[$c['contract_id']] = array_intersect_key($c, array_flip(['actual_kwh', 'actual_cost', 'advance_paid',
                'current_balance', 'projected_end_balance', 'cost_to_date', 'estimated_cost_to_date', 'verdict', 'months_actual']));
        }
        self::assertEquals($want['contracts'], $gotC);
        self::assertEquals($want['totals'], array_map(fn($m) => [$m['ym'], $m['kwh'], $m['cost']], $this->consumption->forUtility('gas')['monthly_total']));
    }

    /** Ohne verteilte Monate rechnet alles wie bis v3.6: Heizmodell, Erwartung, Bereinigung, Abweichungen, Prognose. */
    public function testAMeterWithoutInterpolatedMonthsComputesExactlyAsBefore(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        $noise = [];
        foreach (self::firsts('2023-01', '2025-12') as $i => $d) $noise[substr($d, 0, 7)] = (($i * 7) % 5 - 2) * 0.4;
        $noise['2024-12'] = 3.0;
        $this->seedReadings('gas', self::firsts('2023-01', '2026-01'), $noise);
        $monthly = $this->monthly('gas');
        self::assertSame([], self::interpolatedYms($monthly));
        $want = json_decode((string)file_get_contents(self::FIXTURES . 'measured-v3.6.json'), true);
        $heat = $this->consumption->heatModel('gas', $monthly, $this->meter('gas'));
        self::assertSame('months', $heat['basis']);
        unset($heat['basis']);
        // v3.9.0 (#38) — additiv: die verworfenen Punkte des Robustheitsschritts; die Rechnung selbst bleibt bit-gleich
        self::assertIsArray($heat['dropped']);
        unset($heat['dropped']);
        self::assertSame($want['heat'], $heat, 'bit-gleich');
        self::assertEquals($want['expected'], array_map(fn($m) => [$m['ym'], $m['expected_heat'], $m['heat_adjusted'], $m['regression_point']], $monthly));
        // v3.9.0 (#38) — additiv: Muster wiederkehrender Monate (hier keins)
        $found = $this->anomalies->detect('gas', $monthly);
        self::assertSame([], array_filter($found, fn($a) => $a['pattern']), 'kein Monat jedes Jahr gemeldet');
        self::assertEquals($want['anomalies'], array_map(fn($a) => array_diff_key($a, ['pattern' => 1, 'pattern_years' => 1]), $found));
        $fc = $this->forecast();
        // wirklichkeit-2 (Punkt 8) — einzige gewollte Änderung: Sommermonate unter den
        // Punkten der Heizkurve (Juni bis August, HGT 0) nur noch aus dem gemessenen Saisonwert
        $summer = ['2026-06', '2026-07', '2026-08'];
        $now = array_map(fn($r) => [$r['ym'], $r['kwh'], $r['band_low'], $r['band_high'], $r['method']], $fc['forecast']);
        self::assertEquals(array_values(array_filter($want['forecast'], fn($r) => !in_array($r[0], $summer, true))),
            array_values(array_filter($now, fn($r) => !in_array($r[0], $summer, true))), 'alle anderen Monate bit-gleich');
        self::assertSame(['seasonal_only'], array_values(array_unique(array_column(array_filter($now, fn($r) => in_array($r[0], $summer, true)), 4))));
        $byYm = array_column($fc['historical'], null, 'ym');
        foreach ($summer as $ym) {
            $mo = substr($ym, 5);
            $same = array_values(array_filter($byYm, fn($m) => substr((string)$m['ym'], 5) === $mo));
            $rate = array_sum(array_map(fn($m) => $m['kwh'] / $m['days'], $same)) / count($same);
            self::assertEqualsWithDelta($rate * (int)date('t', strtotime("$ym-01")), $now[array_search($ym, array_column($now, 0), true)][1], 0.1, "$ym: der gemessene Saisonwert");
        }
        self::assertEqualsWithDelta($want['annual']['value'], $fc['annual']['value'], 0.02 * $want['annual']['value'], 'das Jahr kaum anders');
        self::assertNotContains('interpolated', array_column($fc['warnings'], 'code'));
    }

    /** Jahresbericht: Die Erwartung zu einer r4-Meldung (derselbe Monat anderer Jahre) lässt verteilte Monate aus — wie die Erkennung. */
    public function testReportTypicalMonthSkipsInterpolatedMonths(): void
    {
        // Strom: 2023 und 2025 monatlich, 2024 nur 1.1. und 1.5. (Januar bis April verteilt)
        $rate = fn(float $hdd, string $d) => (int)substr($d, 5, 2) === 3 ? (str_starts_with($d, '2025') ? 9.0 : 6.0) : 8.0;
        $this->seedReadings('strom', array_merge(self::firsts('2023-01', '2024-01'), self::firsts('2024-05', '2026-01')), [], $rate);
        $model = $this->reportServices()['model'];
        $typical = new \ReflectionMethod($model, 'typicalMonth');
        $mar2024 = $this->byYm($this->monthly('strom'))['2024-03'];
        self::assertTrue($mar2024['interpolated']);
        // März 2023 gemessen (6 × 31), März 2024 verteilt — die Erwartung für März 2025 ist allein 2023
        self::assertEqualsWithDelta(186.0, $typical->invoke($model, 'strom', $this->meter('strom'), '2025-03'), 0.1);
    }

    /**
     * #37-Nachtrag, wirklichkeit-2 (Punkt 9) — Jahresbericht: „Gemessen −X %“
     * (Wettervergleich der Seite, Heizungs-Erfolg, Kosten gesunken) nur, wenn die
     * Jahressummen beider Jahre gemessen sind: ohne verteilte Monate, oder mit
     * verteilten Monaten, wenn beide Jahresgrenzen in kurzen Abständen liegen. Sonst
     * sagt eine Notiz, warum der Vergleich fehlt.
     */
    public function testTheReportComparesYearsOnlyOnMeasuredMonths(): void
    {
        $this->seedTemps('2023-01-01', '2026-01-31');
        // 2025 heizt der Haushalt ein Fünftel sparsamer
        $model = fn(float $hdd, string $d) => (str_starts_with($d, '2025') ? 0.8 : 1.0) * self::A * $hdd + self::C;
        $priced = false;
        $report = function (array $dates) use ($model, &$priced): array {
            $id = $this->seedReadings('gas', $dates, [], $model);
            // ein Preis für „Kosten gesunken“ (derselbe Zähler bei beiden Durchläufen)
            if (!$priced) {
                $this->contracts->create('gas', ['meter_id' => $id, 'provider' => 'Testgas', 'start' => '2023-01-01', 'end' => '2027-12-31',
                    'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => '2023-01-01', 'amount_eur' => 40]]]);
                $priced = true;
            }
            $m = $this->reportServices()['model']->build(2025, ['today' => '2026-01-31']);
            $page = array_values(array_filter($m['pages'], fn($p) => $p['utility'] === 'gas'))[0];
            return [$page, array_column($m['successes'], 'rule')];
        };
        [$page, $rules] = $report(self::firsts('2023-01', '2026-01'));
        self::assertNotNull($page['weather'], 'beide Jahre gemessen: Kälter oder mehr verbraucht?');
        self::assertContains('heatingFall', $rules);
        self::assertContains('costDown', $rules, 'Kosten gesunken, gemessen');
        self::assertSame([], array_filter($page['notes'], fn($n) => str_contains($n, 'Kein Vergleich')));

        // Februar bis Mai 2025 ohne Ablesung: vier Monate verteilt — die Jahresgrenzen
        // liegen auf Ablesungen, die Jahressummen bleiben gemessen
        [$page, $rules] = $report(array_values(array_diff(self::firsts('2023-01', '2026-01'), ['2025-03-01', '2025-04-01', '2025-05-01'])));
        self::assertNotNull($page['weather'], 'Jahressummen exakt: der Vergleich bleibt');
        self::assertContains('heatingFall', $rules);
        self::assertContains('costDown', $rules);
        self::assertContains('4 Monatswerte der beiden Jahre sind verteilt; die Jahressummen sind trotzdem gemessen, weil an den Jahreswechseln abgelesen wurde.', $page['notes']);

        // Über den Jahreswechsel 2024/25 nicht abgelesen (1.11. → 1.3., 120 Tage): Die
        // Jahressumme hängt an der Verteilung, der Vergleich entfällt
        [$page, $rules] = $report(array_values(array_diff(self::firsts('2023-01', '2026-01'), ['2024-12-01', '2025-01-01', '2025-02-01'])));
        self::assertNull($page['weather']);
        self::assertNotContains('heatingFall', $rules);
        self::assertNotContains('costDown', $rules);
        self::assertContains('Kein Vergleich mit 2024: 4 Monatswerte der beiden Jahre sind verteilt, nicht gemessen.', $page['notes']);
    }

    /** wirklichkeit-2 (Punkt 9) — Jahresgrenze gemessen: auf einer Ablesung, im kurzen Abstand, nicht im langen; geschätzte zählen nicht. */
    public function testAYearBoundaryIsMeasuredOnAReadingOrInAShortInterval(): void
    {
        $this->seedReadings('gas', ['2024-11-20', '2025-01-01', '2025-04-01', [ '2025-05-10', ['is_estimated' => true]], '2025-06-01', '2025-12-20', '2026-01-15']);
        $m = $this->meter('gas');
        $on = fn(string $d) => $this->consumption->boundaryMeasured('gas', $m, $d);
        self::assertTrue($on('2025-01-01'), 'genau auf einer Ablesung');
        self::assertTrue($on('2026-01-01'), '20.12. → 15.1., 26 Tage');
        self::assertFalse($on('2025-03-01'), '1.1. → 1.4., 90 Tage');
        self::assertFalse($on('2025-05-15'), 'die geschätzte Ablesung am 10.5. misst nicht: 1.4. → 1.6., 61 Tage');
        self::assertFalse($on('2024-01-01'), 'vor der ersten Ablesung');
    }

    /** Jahresbericht: „Kosten gesunken (gemessen)“ entfällt, wenn verteilte Monate im Vergleich stecken. */
    public function testTheReportCostSuccessNeedsMeasuredMonths(): void
    {
        $advice = new \Energietracker\Services\ReportAdviceService($this->i18n);
        $cost = new \ReflectionMethod($advice, 'costSuccess');
        $t = ['cost_now' => 900.0, 'cost_prev' => 1000.0, 'interpolated' => false];
        self::assertSame('costDown', $cost->invoke($advice, $t, 2025)['rule'] ?? null);
        self::assertNull($cost->invoke($advice, ['interpolated' => true] + $t, 2025));
    }

    /** Doku-Behauptungen brauchen Tests: Grenze, Anteil und Gütesperre in Grundlagen und API (DE/EN) sind die Konstanten. */
    public function testDocsNameTheLimitsThatApply(): void
    {
        $root = dirname(__DIR__, 3) . '/docs/';
        $days = ConsumptionService::MEASURED_GAP_MAX_DAYS;
        $share = ConsumptionService::MEASURED_SHARE_MIN;
        $poor = (int)round(AnomalyService::POOR_FIT_RATIO * 100);
        $recent = RecommendationService::RECENT_MONTHS;
        $docs = ['verstehen/00-overview.md' => ["Ablesungsabstand ≤ $days Tage", ',', 'außerhalb', "der letzten $recent Monate"],
                 'en/verstehen/00-overview.md' => ["reading interval ≤ $days days", '.', 'outside', "of the last $recent months"]];
        foreach ($docs as $file => [$limit, $dec, $outside, $recentText]) {
            $text = (string)file_get_contents($root . $file);
            $num = fn(float $v) => str_replace('.', $dec, (string)$v);
            self::assertStringContainsString($limit, $text, "$file: Grenze");
            self::assertStringContainsString('measured_share < ' . $num($share), $text, "$file: Anteil");
            self::assertMatchesRegularExpression('/(wenn Verhältnis|if ratio) > ' . $poor . ' %/u', $text, "$file: Gütesperre");
            self::assertStringContainsString("$outside " . $num(AnomalyService::BASE_LOAD_MIN) . ' … ' . $num(AnomalyService::BASE_LOAD_MAX), $text, "$file: Grundlast-Band");
            self::assertStringContainsString($recentText, $text, "$file: Empfehlungen nur aus den letzten Monaten");
        }
        foreach (['referenz/api.md', 'en/referenz/api.md', 'verstehen/12-pv.md', 'en/verstehen/12-pv.md'] as $file) {
            self::assertMatchesRegularExpression("/\\b$days (Tage|days)|\"max_gap_days\": $days/u", (string)file_get_contents($root . $file), $file);
        }
    }

    // ── Prognose-Werkstatt „wie letztes Jahr“ ────────────────────────────

    /** Die Werkstatt sagt, wenn Monatswerte verteilt sind (wie die Prognose). */
    public function testTheWorkshopSaysWhenMonthsAreInterpolated(): void
    {
        $this->seedTemps('2023-01-01', '2026-01-31');
        $this->seedReadings('gas', array_merge(self::firsts('2023-01', '2024-10'), self::firsts('2025-01', '2026-01')));
        $forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $svc = new ScenarioService($forecast, $this->consumption, $this->contracts, $this->meters, $this->settings,
            new \Energietracker\Services\TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n));
        $r = $svc->compute(['utility' => 'gas', 'meter_id' => $this->meter('gas')['id']], '2026-01-31');
        self::assertTrue($r['valid']);
        $w = array_values(array_filter($r['warnings'], fn($x) => $x['code'] === 'interpolated'));
        self::assertSame(3, $w[0]['months'] ?? null);
    }

    public function testScenarioLastYearFillsInterpolatedMonthsFromTheForecast(): void
    {
        $months = [];
        foreach (['2026-01', '2026-02', '2026-03'] as $ym) $months[] = ['ym' => $ym, 'days' => (int)date('t', strtotime("$ym-01")), 'qty' => 100.0];
        $history = [
            ['ym' => '2025-01', 'days' => 31, 'kwh' => 310.0],
            ['ym' => '2025-02', 'days' => 28, 'kwh' => 140.0, 'interpolated' => true],
            ['ym' => '2025-03', 'days' => 31, 'kwh' => 155.0],
        ];
        $q = ScenarioService::modelQuantities($months, $history, 'kwh', 20);
        self::assertSame([310.0, 100.0, 155.0], $q['last_year'], 'verteilter Februar aus der Prognose');
        self::assertSame(1, $q['filled']['last_year']);
    }
}
