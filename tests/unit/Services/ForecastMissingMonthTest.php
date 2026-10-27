<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ClimateNormalService;
use Energietracker\Services\ForecastService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (#38, Rückblick) — ein Kalendermonat ohne eigene Historie in der Prognose.
 *
 * Erfundener Gas-Haushalt, eigene Heizgrenze 10 °C (Einstellung 15 °C), jeder Kalendermonat mit
 * fester Tagestemperatur (TEMP), Verbrauch je Tag genau a × max(0, 10 − T) + c mit a = 26 kWh je
 * Gradtag und c = 6 kWh. Abgelesen am Monatsersten, nur im Sommer in langen Abständen:
 *
 *   2024: 01.04. → 01.06. (April und Mai verteilt), 01.06. → 01.07. (Juni gemessen),
 *         01.07. → 01.09. (Juli und August verteilt)
 *   2025: 01.04. → 01.05. (April gemessen), 01.05. → 25.06. (Mai, Juni verteilt),
 *         25.06. → 12.08. (Juli verteilt), 12.08. → 01.09. (August zu 20 von 31 Tagen gemessen)
 *
 * Mai und Juli wurden nie gemessen (`history_short`, missing [5, 7]); letzte Ablesung 30.04.2026.
 * Klimanormal aus dreißig Jahren: je ein Drittel der Jahre 1,5 °C kälter, gleich, 1,5 °C wärmer.
 *
 * Handrechnung zur Heizgrenze 10 °C:
 *   HGT Mai (Normal)  = 31 × (2,5 + 1,0 + 0) / 3 = 36,1667   (Mai 9 °C: 7,5 / 9 / 10,5 °C)
 *   HGT Juli (Normal) = 0                                    (Juli 16 °C ± 1,5 °C)
 *   Mai  = 26 × 36,1667 + 6 × 31 = 940,33 + 186 = 1126,3 kWh
 *   Juli = 26 × 0       + 6 × 31 =               186,0 kWh
 *
 * Bis v3.8 kamen beide aus der Regression der Monatssummen (`regression_only`); mit dem
 * Knickmodell bekamen alle Monate unter dem Knick dasselbe Niveau (Mai = Juli).
 */
#[CoversClass(ForecastService::class)]
final class ForecastMissingMonthTest extends ServiceTestCase
{
    private const A = 26.0;
    private const C = 6.0;
    private const TEMP = [1 => 0.0, 2 => 1.0, 3 => 4.0, 4 => 8.0, 5 => 9.0, 6 => 13.0,
                          7 => 16.0, 8 => 15.0, 9 => 12.0, 10 => 8.0, 11 => 4.0, 12 => 1.0];

    /**
     * @param list<string> $dates     Ablesetage
     * @param float        $winterAdd zusätzlicher Verbrauch je Tag unter 12 °C (Heizung, die die
     *                                Gradtage zu 10 °C nicht erfassen)
     * @param (callable(float):float)|null $perDay Verbrauch je Tag aus der Tagestemperatur (sonst A, C, $winterAdd)
     * @param array<int,float>|null        $temp   Temperatur je Kalendermonat (sonst TEMP)
     */
    private function household(array $dates, string $to, string $model = 'linear', float $winterAdd = 0.0,
        ?callable $perDay = null, float $limit = 10.0, ?array $temp = null): array
    {
        $temp ??= self::TEMP;
        $perDay ??= fn(float $t) => self::A * max(0.0, 10.0 - $t) + self::C + ($t < 12.0 ? $winterAdd : 0.0);
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]],
            'hdd_base_temp' => 15.0, 'forecast_model' => $model]);
        $temps = [];
        for ($t = strtotime('2023-10-01 12:00'); date('Y-m-d', $t) <= $to; $t += 86400) {
            $a = $temp[(int)date('n', $t)];
            $temps[date('Y-m-d', $t)] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $means = [];
        for ($y = 1996; $y <= 2025; $y++) {
            for ($t = strtotime("$y-01-01 12:00"); date('Y', $t) === (string)$y; $t += 86400) {
                $means[date('Y-m-d', $t)] = $temp[(int)date('n', $t)] + (($y % 3) - 1) * 1.5;
            }
        }
        (new ClimateNormalService($this->store, $this->settings))
            ->save(ClimateNormalService::compute($means, 50.0, 8.0, ['from' => '1996-01-01', 'to' => '2025-12-31']));
        $id = $this->setMeterDevices('gas', [['id' => 'd1', 'serial' => null, 'installed_on' => '2023-10-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $meters = $this->store->read('gas/meters.json');
        $meters[0]['hdd_base_temp'] = $limit;
        $this->store->write('gas/meters.json', $meters);
        $c = 0.0; $counter = [];
        foreach ($temps as $d => $tt) {
            $counter[$d] = $c;
            $c += $perDay((float)$tt['avg']);
        }
        $this->setReadings('gas', $id, array_map(fn($d) => ['date' => $d, 'counter' => $counter[$d], 'device_id' => 'd1'], $dates));
        $this->store->write('gas/contracts.json', []);
        return $this->meters->get('gas', $id);
    }

    /** Ablesung am Monatsersten von `$from` bis `$to`, ohne `$skip`, dazu `$add`. */
    private static function dates(string $from, string $to, array $skip, array $add): array
    {
        $out = [];
        for ($t = strtotime($from); date('Y-m-d', $t) <= $to; $t = strtotime('+1 month', $t)) $out[] = date('Y-m-d', $t);
        $out = array_merge(array_values(array_diff($out, $skip)), $add);
        sort($out);
        return $out;
    }

    /** Der Haushalt aus dem Klassenkommentar: Mai und Juli nie gemessen. */
    private function withoutMayAndJuly(string $model = 'linear', ?callable $perDay = null, float $limit = 10.0, ?array $temp = null): array
    {
        $dates = self::dates('2023-10-01', '2026-04-01', ['2024-05-01', '2024-08-01', '2025-06-01', '2025-07-01', '2025-08-01'],
            ['2025-06-25', '2025-08-12', '2026-04-30']);
        return $this->household($dates, '2026-04-30', $model, 0.0, $perDay, $limit, $temp);
    }

    private function forecast(array $meter): array
    {
        return (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))
            ->forMeter('gas', $meter);
    }

    private static function month(array $fc, string $ym): array
    {
        return array_values(array_filter($fc['forecast'], fn($r) => $r['ym'] === $ym))[0];
    }

    // ── Monat ohne eigene Historie ──────────────────────────────────────

    public function testAMonthWithoutHistoryComesFromTheHeatModelAtTheMetersLimit(): void
    {
        $fc = $this->forecast($this->withoutMayAndJuly());
        $short = array_values(array_filter($fc['warnings'], fn($w) => $w['code'] === 'history_short'))[0];
        self::assertSame([5, 7], $short['missing']);
        self::assertSame(10.0, $fc['hdd_base']);
        self::assertSame('climate_normal', $fc['hdd_source']);
        self::assertEqualsWithDelta(self::A, $fc['heat_model']['a'], 0.01);
        self::assertEqualsWithDelta(self::C, $fc['heat_model']['c'], 0.01);

        $may = self::month($fc, '2026-05');
        $jul = self::month($fc, '2026-07');
        self::assertSame(36.2, $may['hdd_estimated'], 'Normal zur Heizgrenze des Zählers (10 °C), nicht zur Einstellung (15 °C)');
        self::assertSame(0.0, $jul['hdd_estimated']);
        self::assertSame(['heat_model', 'heat_model'], [$may['method'], $jul['method']]);
        self::assertEqualsWithDelta(26 * 31 * 3.5 / 3 + 6 * 31, $may['kwh'], 0.5);   // 1126,3
        self::assertEqualsWithDelta(186.0, $jul['kwh'], 0.5);
        // der gemessene Juni bleibt der gemessene Juni
        self::assertSame('seasonal_only', self::month($fc, '2026-06')['method']);
        self::assertEqualsWithDelta(180.0, self::month($fc, '2026-06')['kwh'], 0.5);
    }

    public function testTheChangePointModelNoLongerGivesMayTheValueOfJuly(): void
    {
        $fc = $this->forecast($this->withoutMayAndJuly('segmented'));
        self::assertSame('segmented', $fc['regression']['model']);
        self::assertTrue($fc['regression']['valid']);
        $may = self::month($fc, '2026-05');
        $jul = self::month($fc, '2026-07');
        self::assertNotEquals($may['kwh'], $jul['kwh'], 'zwei Monate mit verschiedenem Klima');
        self::assertEqualsWithDelta(1126.3, $may['kwh'], 0.5);
        self::assertEqualsWithDelta(186.0, $jul['kwh'], 0.5);
    }

    public function testAMonthFromTheHeatModelCountsAsHeatingCurve(): void
    {
        $fc = $this->forecast($this->withoutMayAndJuly());
        // L4: der Anteil der Jahresmenge aus der Heizkurve. Ein Mischmonat trägt seinen
        // Regressionsteil (Wert − Saisonanteil), ein Monat aus dem Heizmodell ganz
        $fromCurve = 0.0; $annual = 0.0; $heatMonths = 0;
        foreach (array_slice($fc['forecast'], 0, 12) as $r) {
            $annual += $r['kwh'];
            if ($r['method'] === 'heat_model') { $fromCurve += $r['kwh']; $heatMonths++; }
            if (str_starts_with($r['method'], 'blend(')) {
                self::assertSame('blend(reg=0.80, seasonal=0.20)', $r['method']);
                $dim = (int)date('t', strtotime($r['ym'] . '-01'));
                $seasonal = (self::A * max(0.0, 10.0 - self::TEMP[$r['month']]) + self::C) * $dim;   // gemessene Tagesrate × Tage
                $fromCurve += $r['kwh'] - 0.2 * $seasonal;
            }
        }
        self::assertSame(2, $heatMonths);
        self::assertEqualsWithDelta($fromCurve / $annual, $fc['blend_effective'], 0.0005);
    }

    // ── ohne so warme Monate im Heizmodell ──────────────────────────────

    public function testWithoutWarmMonthsInTheModelTheInterpolatedMonthStands(): void
    {
        // Gemessen nur Oktober bis Mai, der Sommer in zwei Abständen 01.06.–01.08. und 01.08.–01.10.
        // (je 61 Tage, verteilt, 6 kWh am Tag). Unter 12 °C 4 kWh am Tag mehr, als die Gradtage zu
        // 10 °C erfassen: Das Heizmodell kennt nur diese Monate und setzt die Grundlast auf 10 kWh.
        // (Seit Nachmessung 2 zählen verteilte Werte nur aus Abständen bis 62 Tagen.)
        $dates = self::dates('2023-10-01', '2026-06-01', ['2024-07-01', '2024-09-01', '2025-07-01', '2025-09-01'], []);
        $fc = $this->forecast($this->household($dates, '2026-06-01', 'linear', 4.0));
        $short = array_values(array_filter($fc['warnings'], fn($w) => $w['code'] === 'history_short'))[0];
        self::assertSame([6, 7, 8, 9], $short['missing']);
        self::assertEqualsWithDelta(10.0, $fc['heat_model']['c'], 0.01, 'die Grundlast des Modells: geraten');
        $jul = self::month($fc, '2026-07');
        // Heizmodell wäre 26 × 0 + 10 × 31 = 310; verteilt: 6 × 31 = 186
        self::assertSame('seasonal_interpolated', $jul['method']);
        self::assertEqualsWithDelta(186.0, $jul['kwh'], 0.5);
        self::assertEqualsWithDelta(180.0, self::month($fc, '2026-09')['kwh'], 0.5);
    }

    // ── Heizmodell nicht bestanden, Grundlast zu klein (Nachmessung) ────

    /**
     * Konvexe Heizkurve zur Grenze 15 °C: je Tag 6 + 0,4 × h² mit h = max(0, 15 − T), September
     * 15,5 °C (wie Juli und August ohne Gradtage, das Modell kennt also so warme Monate). Die Gerade
     * a × HGT + c × Tage legt c weit unter die 6 kWh des Sommers, die Gütesperre schlägt an.
     * Ablesungen wie oben, Mai und Juli also nur verteilt:
     *
     *   Mai 2024  aus 01.04.–01.06.: (30 × 25,6 + 31 × 20,4) / 61 = 22,9574 kWh/Tag   (April h = 7)
     *   Mai 2025  aus 01.05.–25.06.: (31 × 20,4 + 24 × 7,6)  / 55 = 14,8145 kWh/Tag   (Juni h = 2)
     *   Mai 2026  = (22,9574 + 14,8145) / 2 × 31 = 585,5 kWh
     *   Juli 2024 aus 01.07.–01.09.: 6,0 kWh/Tag
     *   Juli 2025 aus 25.06.–12.08.: (6 × 7,6 + 42 × 6) / 48 = 6,2 kWh/Tag
     *   Juli 2026 = (6,0 + 6,2) / 2 × 31 = 189,1 kWh
     */
    public function testAPoorModelWithTooSmallABaseLoadLeavesTheMonthToTheInterpolatedValues(): void
    {
        $temp = self::TEMP; $temp[9] = 15.5;
        $fc = $this->forecast($this->withoutMayAndJuly('linear', fn(float $t) => 6.0 + 0.4 * max(0.0, 15.0 - $t) ** 2, 15.0, $temp));
        $short = array_values(array_filter($fc['warnings'], fn($w) => $w['code'] === 'history_short'))[0];
        self::assertSame([5, 7], $short['missing']);
        self::assertSame('poor', $fc['fit_quality']['model_quality']);
        self::assertContains('base_load', $fc['fit_quality']['reasons']);
        // gemessene Sommermonate: Juni 2024 7,6, August 2025 (11 × 6,2 + 20 × 6) / 31 = 6,0645 → Mittel 6,83
        self::assertLessThan(0.5 * (7.6 + (11 * 6.2 + 20 * 6) / 31) / 2, $fc['heat_model']['c']);
        $may = self::month($fc, '2026-05');
        $jul = self::month($fc, '2026-07');
        self::assertSame(['seasonal_interpolated', 'seasonal_interpolated'], [$may['method'], $jul['method']]);
        self::assertEqualsWithDelta((1400.4 / 61 + 814.8 / 55) / 2 * 31, $may['kwh'], 0.5);   // 585,5
        self::assertEqualsWithDelta((6.0 + 6.2) / 2 * 31, $jul['kwh'], 0.5);                 // 189,1
    }

    public function testWithOneSummerMonthTheBaseLoadIsCheckedBeyondTheQualityGate(): void
    {
        // Milder konvex, je Tag 6 + 2 × h + 0,1 × h² (Juni h = 2: 10,4 kWh/Tag), und ohne die Ablesung
        // vom 12.08.2025: gemessen ist im Sommer nur der Juni 2024. Die Gütesperre besteht, denn sie
        // prüft die Grundlast erst ab zwei Sommermonaten; diese Prüfung schon mit einem
        // (c unter 0,5 × 10,4 = 5,2). Verteilt zählt nur der Juli 2024 aus 01.07.–01.09. (62 Tage,
        // 6,0 kWh/Tag); der Juli 2025 stammt aus 25.06.–01.09. (68 Tage, INTERP_GAP_MAX_DAYS)
        // → Juli 2026 = 6,0 × 31 = 186,0
        $temp = self::TEMP; $temp[9] = 15.5;
        $dates = self::dates('2023-10-01', '2026-04-01', ['2024-05-01', '2024-08-01', '2025-06-01', '2025-07-01', '2025-08-01'],
            ['2025-06-25', '2026-04-30']);
        $fc = $this->forecast($this->household($dates, '2026-04-30', 'linear', 0.0,
            fn(float $t) => 6.0 + 2.0 * max(0.0, 15.0 - $t) + 0.1 * max(0.0, 15.0 - $t) ** 2, 15.0, $temp));
        self::assertSame('good', $fc['fit_quality']['model_quality'], 'die Gütesperre kann die Grundlast hier nicht prüfen');
        self::assertLessThan(0.5 * 10.4, $fc['heat_model']['c']);
        $jul = self::month($fc, '2026-07');
        self::assertSame('seasonal_interpolated', $jul['method']);
        self::assertEqualsWithDelta(6.0 * 31, $jul['kwh'], 0.5);
    }

    // ── verteilte Werte nur aus kurzen Abständen, sonst Sommerrate als Grundlast (Nachmessung 2) ──

    /**
     * Konvex wie oben (6 + 0,4 × h² zur Grenze 15 °C), Juli 17 °C und September 15,5 °C ohne
     * Gradtage. Mai und Juli liegen nur in langen Abständen über Winter und Frühjahr:
     *   2024: 01.02. → 01.08. (182 Tage: Februar bis Juli verteilt), danach monatlich
     *   2025: 01.03. → 01.06. (92 Tage: März bis Mai), 01.06. → 01.07. (Juni gemessen),
     *         01.07. → 01.10. (92 Tage: Juli bis September)
     * Gemessene Sommermonate: August 2024 (6,0 kWh/Tag) und Juni 2025 (Juni h = 2: 7,6 kWh/Tag),
     * Mittel 6,8. Das Heizmodell besteht die Gütesperre nicht (Grundlast), c liegt weit darunter.
     *   Juli 2026 = a × 0 + 6,8 × 31 = 210,8 kWh   (Juli-Normal 17 ± 1,5 °C: keine Gradtage)
     *   Mai 2026  = a × 186 + 6,8 × 31              (Mai-Normal 9 ± 1,5 °C: 31 × 6 = 186 HGT)
     * Bis Nachmessung 2 kamen beide aus den verteilten Werten, der Juli mit dem Winter darin.
     */
    public function testLongWinterGapsGiveTheHeatModelWithTheMeasuredSummerAsBaseLoad(): void
    {
        $temp = self::TEMP; $temp[7] = 17.0; $temp[9] = 15.5;
        $dates = self::dates('2023-10-01', '2026-04-01',
            ['2024-03-01', '2024-04-01', '2024-05-01', '2024-06-01', '2024-07-01', '2025-04-01', '2025-05-01', '2025-08-01', '2025-09-01'],
            ['2026-04-30']);
        $fc = $this->forecast($this->household($dates, '2026-04-30', 'linear', 0.0, fn(float $t) => 6.0 + 0.4 * max(0.0, 15.0 - $t) ** 2, 15.0, $temp));
        $short = array_values(array_filter($fc['warnings'], fn($w) => $w['code'] === 'history_short'))[0];
        self::assertSame([5, 7], $short['missing']);
        self::assertSame('poor', $fc['fit_quality']['model_quality']);
        self::assertLessThan(0.5 * 6.8, $fc['heat_model']['c']);
        $hist = array_column($fc['historical'], null, 'ym');
        self::assertSame([182, 92, 182, 92], [$hist['2024-05']['reading_gap_days'], $hist['2025-05']['reading_gap_days'],
            $hist['2024-07']['reading_gap_days'], $hist['2025-07']['reading_gap_days']], 'nur lange Abstände');
        $jul = self::month($fc, '2026-07');
        $may = self::month($fc, '2026-05');
        self::assertSame(['heat_model_summer', 'heat_model_summer'], [$may['method'], $jul['method']]);
        self::assertSame(0.0, $jul['hdd_estimated']);
        self::assertEqualsWithDelta(6.8 * 31, $jul['kwh'], 0.5);   // 210,8
        self::assertEqualsWithDelta(186.0, $may['hdd_estimated'], 0.05);
        self::assertEqualsWithDelta($fc['heat_model']['a'] * 186.0 + 6.8 * 31, $may['kwh'], 0.5);
        // L4: Gütesperre nicht bestanden, alle anderen Monate rechnen mit dem Saisonwert; aus der
        // Heizkurve kommen genau Mai und Juli
        $annual = array_sum(array_column(array_slice($fc['forecast'], 0, 12), 'kwh'));
        self::assertEqualsWithDelta(($may['kwh'] + $jul['kwh']) / $annual, $fc['blend_effective'], 0.0002);
    }

    public function testAReliableModelKeepsItsOwnBaseLoadEvenWithLongGaps(): void
    {
        // dieselben langen Abstände, aber die Gerade 26 × HGT + 6 zur Grenze 10 °C, im Sommer (ab 13 °C)
        // 1 kWh am Tag mehr: gemessener Sommer 7 kWh/Tag, das Modell besteht die Gütesperre und c liegt
        // knapp darunter. Dann gilt das Heizmodell mit seinem eigenen c, nicht die Sommerrate
        $dates = self::dates('2023-10-01', '2026-04-01',
            ['2024-03-01', '2024-04-01', '2024-05-01', '2024-06-01', '2024-07-01', '2025-04-01', '2025-05-01', '2025-08-01', '2025-09-01'],
            ['2026-04-30']);
        $fc = $this->forecast($this->household($dates, '2026-04-30', 'linear', 0.0,
            fn(float $t) => self::A * max(0.0, 10.0 - $t) + self::C + ($t >= 13.0 ? 1.0 : 0.0)));
        self::assertSame('good', $fc['fit_quality']['model_quality']);
        $c = $fc['heat_model']['c'];
        self::assertGreaterThan(0.5 * 7.0, $c);
        self::assertLessThan(7.0, $c);
        $jul = self::month($fc, '2026-07');
        self::assertSame('heat_model', $jul['method']);
        self::assertEqualsWithDelta($c * 31, $jul['kwh'], 0.5);
    }

    public function testShortGapsKeepTheInterpolatedValuesAndOneShortYearIsEnough(): void
    {
        // der Haushalt mit poor-Fit oben (Mai und Juli aus Abständen von 48 bis 62 Tagen): bleibt verteilt
        $temp = self::TEMP; $temp[9] = 15.5;
        $fc = $this->forecast($this->withoutMayAndJuly('linear', fn(float $t) => 6.0 + 0.4 * max(0.0, 15.0 - $t) ** 2, 15.0, $temp));
        $hist = array_column($fc['historical'], null, 'ym');
        self::assertSame([61, 55, 62, 48], [$hist['2024-05']['reading_gap_days'], $hist['2025-05']['reading_gap_days'],
            $hist['2024-07']['reading_gap_days'], $hist['2025-07']['reading_gap_days']]);
        self::assertSame('seasonal_interpolated', self::month($fc, '2026-07')['method']);
        self::assertEqualsWithDelta((6.0 + 6.2) / 2 * 31, self::month($fc, '2026-07')['kwh'], 0.5);
        // ein langer Abstand im zweiten Jahr: Der Juli kommt aus dem kurzen allein
        $dates = self::dates('2023-10-01', '2026-04-01', ['2024-05-01', '2024-08-01', '2025-06-01', '2025-07-01', '2025-08-01', '2025-09-01'],
            ['2025-06-25', '2026-04-30']);
        $fc = $this->forecast($this->household($dates, '2026-04-30', 'linear', 0.0, fn(float $t) => 6.0 + 0.4 * max(0.0, 15.0 - $t) ** 2, 15.0, $temp));
        self::assertSame(98, array_column($fc['historical'], null, 'ym')['2025-07']['reading_gap_days']);
        self::assertSame('seasonal_interpolated', self::month($fc, '2026-07')['method']);
        self::assertEqualsWithDelta(6.0 * 31, self::month($fc, '2026-07')['kwh'], 0.5);
    }

    public function testTheHeatModelCarriesAMissingMonthOnlyIfItPassesAndItsBaseLoadFitsTheSummer(): void
    {
        $trusted = new \ReflectionMethod(ForecastService::class, 'heatTrusted');
        // gemessene Sommermonate 24 kWh am Tag: ab der Hälfte (12) trägt c, darunter nicht
        self::assertTrue($trusted->invoke(null, false, 12.0, 24.0));
        self::assertTrue($trusted->invoke(null, false, 18.0, 24.0));
        self::assertFalse($trusted->invoke(null, false, 11.9, 24.0));
        self::assertFalse($trusted->invoke(null, false, 6.0, 24.0), 'wie beim Melder: c bei einem Viertel des Sommers');
        // Gütesperre nicht bestanden: auch mit passender Grundlast nicht
        self::assertFalse($trusted->invoke(null, true, 18.0, 24.0));
        // kein gemessener Sommermonat: nur die Gütesperre
        self::assertTrue($trusted->invoke(null, false, 6.0, null));
        self::assertFalse($trusted->invoke(null, true, 6.0, null));
        self::assertSame(0.5, ForecastService::HEAT_BASE_MIN_SHARE);
        // der Sommer: Juni bis August; Mai und September mit Heiztagen zählen nicht
        $summer = new \ReflectionMethod(ForecastService::class, 'summerRate');
        $rates = array_fill(1, 12, []);
        $rates[5] = [30.0]; $rates[6] = [20.0, 28.0]; $rates[8] = [24.0]; $rates[9] = [40.0];
        self::assertSame(24.0, $summer->invoke(null, $rates));   // (20 + 28 + 24) / 3
        $rates[6] = $rates[8] = [];
        self::assertNull($summer->invoke(null, $rates));
    }
}
