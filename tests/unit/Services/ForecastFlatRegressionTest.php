<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ClimateNormalService;
use Energietracker\Services\ForecastService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (#38, Rückblick) — Mischmonate dort, wo Knick oder Sigmoide flach sind.
 *
 * Das Knickmodell rechnet y = b + a × max(0, HGT − Knick), die Sigmoide unter θ₀ die Grundlast D.
 * Ein Monat mit gemessenem Saisonwert und Gradtagen zwischen dem kleinsten Punkt der Kurve und
 * dem Knick bekam bis v3.8 0,8 × b + 0,2 × Saisonwert: Juni, August und September dasselbe
 * Niveau b, das aller Monate unter dem Knick. Jetzt gilt dort wie unter den Punkten der Kurve der
 * gemessene Saisonwert (`seasonal_only`). In den Rückblicken (Form 15 °C, halb beheizt) lagen
 * Juni, August und September so im Mittel 40, 32 und 103 % zu hoch.
 *
 * Hier ein erfundener Gas-Haushalt, an dem das Heizmodell die Gütesperre besteht (sonst gilt
 * der Saisonwert ohnehin): Heizgrenze 15 °C (Einstellung), jeder Kalendermonat mit fester
 * Tagestemperatur T, Verbrauch je Tag genau 6 + 4,5 × max(0, 15 − T), abgelesen an jedem
 * Monatsersten vom 01.10.2023 bis 01.05.2026. Selbst bei dieser Geraden setzt das Knickmodell
 * den Knick auf 124 HGT (das erste Quartil) und alles darunter auf ein Niveau.
 *
 * Handrechnung (Saisonwert = Tagesrate × Tage):
 *   Juni      13 °C: 6 + 4,5 × 2 = 15,0 kWh/Tag × 30 = 450,0
 *   August    14 °C: 6 + 4,5 × 1 = 10,5 kWh/Tag × 31 = 325,5
 *   September 12 °C: 6 + 4,5 × 3 = 19,5 kWh/Tag × 30 = 585,0
 *   Mai       11 °C: 6 + 4,5 × 4 = 24,0 kWh/Tag × 31 = 744,0
 * Gradtage zu 15 °C (Normal, Jahre ± 1,5 °C): Juni 60, August 36,2, September 90, Mai 124; der
 * kleinste Punkt der Kurve ist der August mit 31 — alle liegen über ihm, die alte Regel
 * („unter den Punkten“) griff nicht.
 */
#[CoversClass(ForecastService::class)]
final class ForecastFlatRegressionTest extends ServiceTestCase
{
    private const TEMP = [1 => 0.0, 2 => 1.0, 3 => 4.0, 4 => 8.0, 5 => 11.0, 6 => 13.0,
                          7 => 16.0, 8 => 14.0, 9 => 12.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];

    private function forecast(string $model, array $extraSettings = []): array
    {
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]],
            'hdd_base_temp' => 15.0, 'forecast_model' => $model] + $extraSettings);
        $temps = [];
        for ($t = strtotime('2023-10-01 12:00'); date('Y-m-d', $t) <= '2026-05-01'; $t += 86400) {
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
        $c = 0.0; $rows = [];
        foreach ($temps as $d => $tt) {
            if (substr($d, 8, 2) === '01') $rows[] = ['date' => $d, 'counter' => $c, 'device_id' => 'd1'];
            $c += 6.0 + 4.5 * max(0.0, 15.0 - $tt['avg']);
        }
        $this->setReadings('gas', $id, $rows);
        $this->store->write('gas/contracts.json', []);
        return (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))
            ->forMeter('gas', $this->meters->get('gas', $id));
    }

    private static function month(array $fc, int $month): array
    {
        return array_values(array_filter($fc['forecast'], fn($r) => $r['month'] === $month))[0];
    }

    public function testBelowTheKneeAMixedMonthIsItsMeasuredSeasonalValue(): void
    {
        $fc = $this->forecast('segmented');
        self::assertSame('segmented', $fc['regression']['model']);
        self::assertTrue($fc['regression']['valid']);
        self::assertSame('good', $fc['fit_quality']['model_quality'], 'sonst griffe schon die Gütesperre');
        self::assertSame(124.0, (float)$fc['regression']['split']);
        foreach ([6 => 450.0, 8 => 325.5, 9 => 585.0] as $mo => $kwh) {
            $r = self::month($fc, $mo);
            self::assertGreaterThanOrEqual(31.0, $r['hdd_estimated'], "Monat $mo über dem kleinsten Punkt der Kurve");
            self::assertLessThan(124.0, $r['hdd_estimated'], "Monat $mo unter dem Knick");
            self::assertSame('seasonal_only', $r['method'], "Monat $mo");
            self::assertEqualsWithDelta($kwh, $r['kwh'], 0.5, "Monat $mo: gemessene Tagesrate × Tage");
        }
        // über dem Knick mischt die Kurve weiter (Mai liegt mit 124 HGT genau auf dem Knick)
        self::assertSame('blend(reg=0.80, seasonal=0.20)', self::month($fc, 5)['method']);
        self::assertSame('blend(reg=0.80, seasonal=0.20)', self::month($fc, 1)['method']);
    }

    public function testTheStraightLineStillBlendsTheseMonths(): void
    {
        // dieselben Monate mit der Geraden: keine flache Stelle, die Mischung bleibt und trifft:
        // 0,8 × (4,5 × HGT des Normals + 6 × Tage) + 0,2 × Saisonwert, im August mit 36,2 statt
        // der 31 HGT der Vorgeschichte 0,8 × 348,75 + 65,1 = 344,1 (die Regression der
        // Monatssummen hat einen Achsenabschnitt je Monat, nicht je Tag: bis 1 % daneben)
        $fc = $this->forecast('linear');
        foreach ([6 => [60.0, 30, 450.0], 8 => [36.1667, 31, 325.5], 9 => [90.0, 30, 585.0]] as $mo => [$hdd, $dim, $seasonal]) {
            $r = self::month($fc, $mo);
            self::assertSame('blend(reg=0.80, seasonal=0.20)', $r['method'], "Monat $mo");
            $want = 0.8 * (4.5 * $hdd + 6 * $dim) + 0.2 * $seasonal;
            self::assertEqualsWithDelta($want, $r['kwh'], 0.01 * $want, "Monat $mo");
        }
    }

    public function testAFixedKneeAlsoCoversMay(): void
    {
        $fc = $this->forecast('segmented', ['segmented_split_mode' => 'fixed', 'segmented_fixed_split' => 150.0]);
        self::assertSame(150.0, (float)$fc['regression']['split']);
        foreach ([5 => 744.0, 6 => 450.0, 8 => 325.5, 9 => 585.0] as $mo => $kwh) {
            self::assertSame('seasonal_only', self::month($fc, $mo)['method'], "Monat $mo");
            self::assertEqualsWithDelta($kwh, self::month($fc, $mo)['kwh'], 0.5, "Monat $mo");
        }
    }

    public function testTheSigmoidIsFlatUpToTheta0(): void
    {
        // Die Sigmoide landet an diesen Haushalten mit θ₀ unter null; die Regel selbst:
        // bis θ₀ einschließlich die Grundlast D (RegressionService::predict), darüber nicht
        $flat = new \ReflectionMethod(ForecastService::class, 'regressionFlat');
        $sig = ['model' => 'sigmoid', 'theta0' => 80.0, 'A' => 2000.0, 'B' => 200.0, 'C' => 3.0, 'D' => 180.0, 'valid' => true];
        self::assertSame([true, true, false], [$flat->invoke(null, $sig, 36.2), $flat->invoke(null, $sig, 80.0), $flat->invoke(null, $sig, 80.5)]);
        self::assertSame(180.0, $this->regression->predict($sig, 80.0), 'dort rechnet sie wirklich flach');
        self::assertGreaterThan(180.0, $this->regression->predict($sig, 80.5));
        $seg = ['model' => 'segmented', 'split' => 124.0];
        self::assertSame([true, false], [$flat->invoke(null, $seg, 123.9), $flat->invoke(null, $seg, 124.0)]);
        self::assertFalse($flat->invoke(null, ['model' => 'linear', 'a' => 4.5, 'b' => 180.0], 0.0));
    }
}
