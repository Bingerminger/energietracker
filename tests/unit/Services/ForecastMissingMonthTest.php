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
     */
    private function household(array $dates, string $to, string $model = 'linear', float $winterAdd = 0.0): array
    {
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]],
            'hdd_base_temp' => 15.0, 'forecast_model' => $model]);
        $temps = [];
        for ($t = strtotime('2023-10-01 12:00'); date('Y-m-d', $t) <= $to; $t += 86400) {
            $a = self::TEMP[(int)date('n', $t)];
            $temps[date('Y-m-d', $t)] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $means = [];
        for ($y = 1996; $y <= 2025; $y++) {
            for ($t = strtotime("$y-01-01 12:00"); date('Y', $t) === (string)$y; $t += 86400) {
                $means[date('Y-m-d', $t)] = self::TEMP[(int)date('n', $t)] + (($y % 3) - 1) * 1.5;
            }
        }
        (new ClimateNormalService($this->store, $this->settings))
            ->save(ClimateNormalService::compute($means, 50.0, 8.0, ['from' => '1996-01-01', 'to' => '2025-12-31']));
        $id = $this->setMeterDevices('gas', [['id' => 'd1', 'serial' => null, 'installed_on' => '2023-10-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $meters = $this->store->read('gas/meters.json');
        $meters[0]['hdd_base_temp'] = 10.0;
        $this->store->write('gas/meters.json', $meters);
        $c = 0.0; $counter = [];
        foreach ($temps as $d => $tt) {
            $counter[$d] = $c;
            $c += self::A * max(0.0, 10.0 - $tt['avg']) + self::C + ($tt['avg'] < 12.0 ? $winterAdd : 0.0);
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
    private function withoutMayAndJuly(string $model = 'linear'): array
    {
        $dates = self::dates('2023-10-01', '2026-04-01', ['2024-05-01', '2024-08-01', '2025-06-01', '2025-07-01', '2025-08-01'],
            ['2025-06-25', '2025-08-12', '2026-04-30']);
        return $this->household($dates, '2026-04-30', $model);
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
        // Gemessen nur Oktober bis Mai, der Sommer in einem Abstand vom 01.06. bis 01.10. (verteilt,
        // 6 kWh am Tag). Unter 12 °C 4 kWh am Tag mehr, als die Gradtage zu 10 °C erfassen: Das
        // Heizmodell kennt nur diese Monate und setzt die Grundlast auf 6 + 4 = 10 kWh am Tag
        $dates = self::dates('2023-10-01', '2026-06-01', ['2024-07-01', '2024-08-01', '2024-09-01', '2025-07-01', '2025-08-01', '2025-09-01'], []);
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
}
