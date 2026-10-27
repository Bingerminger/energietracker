<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Skeptiker-Lauf 3.9.0, M5) — Saldo-Karte und Prognose mit derselben Vertrauensregel
 * (ForecastService::heatTrusted). Im unordentlichen Testhaushalt besteht das Heizmodell die
 * Gütesperre nicht. Die Prognose rechnet deshalb mit dem Saisonprofil, die Karte schätzte bis
 * dahin mit dem Heizmodell: Juni bis August fast 0 kWh (nur Grundpreis), gemessen waren
 * 157 bis 187 kWh im Monat.
 */
#[CoversClass(ConsumptionService::class)]
final class UntrustedHeatModelTest extends ServiceTestCase
{
    private function messy(): array
    {
        $h = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/messy-household/household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings']);
        $this->store->write('temperatures.json', $h['temperatures']);
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        foreach ($h['utilities'] as $u => $pots) foreach ($pots as $pot => $rows) $this->store->write("$u/$pot.json", $rows);
        return $this->meters->list('gas')[0];
    }

    /** Summe der Schätzung je Monat. */
    private static function monthSum(callable $est, string $ym): float
    {
        $from = $ym . '-01';
        return array_sum($est($from, date('Y-m-d', (int)strtotime("$from +1 month"))));
    }

    public function testThePoorHeatModelNoLongerEstimatesTheSummer(): void
    {
        $meter = $this->messy();
        $rows = $this->consumption->forMeter('gas', $meter);
        self::assertSame('poor', $this->consumption->anomalyQuality('gas', $rows)['model_quality']);
        $est = (new \ReflectionMethod($this->consumption, 'volumeEstimator'))->invoke($this->consumption, 'gas', $rows, 'kwh', $meter);
        $p = (new \ReflectionProperty($this->consumption, 'lastProjection'))->getValue($this->consumption);
        self::assertFalse($p['trusted']);
        $f = (float)$p['base'];

        // Handrechnung Juli: Mittel der gemessenen Julis (Tagesrate) × 31 Tage × Faktor
        $julys = array_values(array_filter($rows, fn($m) => (int)$m['month'] === 7 && empty($m['interpolated']) && (int)$m['days'] >= 20));
        self::assertNotEmpty($julys);
        $rate = array_sum(array_map(fn($m) => (float)$m['kwh'] / (int)$m['days'], $julys)) / count($julys);
        self::assertEqualsWithDelta($rate * 31 * $f, self::monthSum($est, '2026-07'), 0.01);
        self::assertGreaterThan(100.0, self::monthSum($est, '2026-07'), 'nicht mehr fast null');

        // dieselben Monatsmengen wie die Prognose, bis auf den einen Faktor des jüngsten Niveaus
        $fc = (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))->forMeter('gas', $meter);
        foreach (array_slice($fc['forecast'], 0, 12) as $m) {
            self::assertSame('seasonal_only', $m['method'], $m['ym']);
            self::assertEqualsWithDelta((float)$m['kwh'] * $f, self::monthSum($est, $m['ym']), 0.06 * $f + 0.01, $m['ym']);
        }
    }

    /**
     * Ohne verlässliches Modell zählt ein verteilter Kalendermonat nur aus kurzen Abständen
     * (ForecastService::INTERP_GAP_MAX_DAYS), wie in der Prognose. Im Testhaushalt fehlen dafür die
     * Ablesungen zwischen 01.08. und 15.10. jedes Jahres: Der September ist nur noch aus Abständen
     * über 62 Tage verteilt. Dann schätzen Prognose und Karte ihn mit dem Heizmodell und der
     * gemessenen Sommerrate als Grundlast, nicht mit der Tagesrate des langen Abstands.
     */
    public function testWithoutATrustedModelOnlyShortGapsReplaceAMonth(): void
    {
        $meter = $this->messy();
        $rows = array_values(array_filter($this->store->read('gas/readings.json'), fn($r) => !(substr((string)$r['date'], 5) >= '08-01' && substr((string)$r['date'], 5) <= '10-15')));
        $this->store->write('gas/readings.json', $rows);
        $monthly = $this->consumption->forMeter('gas', $meter);
        $septs = array_values(array_filter($monthly, fn($m) => (int)$m['month'] === 9 && (int)$m['days'] >= 20));
        self::assertNotEmpty($septs);
        foreach ($septs as $m) self::assertTrue(!empty($m['interpolated']) && (int)$m['reading_gap_days'] > ForecastService::INTERP_GAP_MAX_DAYS, $m['ym']);
        $est = (new \ReflectionMethod($this->consumption, 'volumeEstimator'))->invoke($this->consumption, 'gas', $monthly, 'kwh', $meter);
        $p = (new \ReflectionProperty($this->consumption, 'lastProjection'))->getValue($this->consumption);
        self::assertFalse($p['trusted']);
        $fc = array_column((new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))->forMeter('gas', $meter)['forecast'], null, 'ym');
        self::assertStringStartsWith('heat_model', $fc['2026-09']['method']);
        // dieselbe Monatsmenge wie die Prognose (bis auf den Faktor), nicht die des langen Abstands
        self::assertEqualsWithDelta((float)$fc['2026-09']['kwh'] * (float)$p['base'], self::monthSum($est, '2026-09'), 0.02 * (float)$fc['2026-09']['kwh']);
        $longRate = array_sum(array_map(fn($m) => (float)$m['kwh'] / (int)$m['days'], $septs)) / count($septs);
        self::assertGreaterThan(0.05 * $longRate * 30, abs(self::monthSum($est, '2026-09') - $longRate * 30 * (float)$p['base']), 'nicht die Tagesrate des langen Abstands');
    }

    public function testATrustedHeatModelStillEstimatesWithTheWeather(): void
    {
        // gleichmäßig 5 kWh Grundlast + 0,3 kWh je Gradtag, monatlich abgelesen: verlässliches Modell
        $first = date('Y-m-01', strtotime('-30 months'));
        $meter = $this->setMeterDevices('gas', [['id' => 'd_t', 'serial' => null, 'installed_on' => $first, 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $temps = []; $rows = []; $c = 0.0;
        for ($t = strtotime($first); $t <= strtotime(date('Y-m-01')); $t += 86400) {
            $d = date('Y-m-d', $t);
            $avg = 10.0 - 9.0 * cos(2 * M_PI * ((int)date('z', $t) - 15) / 365.25);
            $temps[$d] = ['avg' => round($avg, 1), 'min' => round($avg - 3, 1), 'max' => round($avg + 3, 1)];
            if (substr($d, 8) === '01') $rows[] = ['date' => $d, 'counter' => round($c, 1), 'device_id' => 'd_t'];
            $c += 5.0 + 0.3 * max(0.0, 15.0 - $avg);
        }
        $this->store->write('temperatures.json', $temps);
        $this->setReadings('gas', $meter, $rows);
        $m = $this->meters->get('gas', $meter);
        $monthly = $this->consumption->forMeter('gas', $m);
        $this->consumption->contractStatus('gas', $m);
        $est = (new \ReflectionMethod($this->consumption, 'volumeEstimator'))->invoke($this->consumption, 'gas', $monthly, 'kwh', $m);
        $p = (new \ReflectionProperty($this->consumption, 'lastProjection'))->getValue($this->consumption);
        self::assertTrue($p['trusted'], 'Gütesperre bestanden, Grundlast wie der Sommer');
        // ein Tag mit 5 °C: 5 + 0,3 × 10 = 8 kWh (Faktoren rund 1)
        $model = $this->consumption->heatModel('gas', $monthly, $m);
        $temps2 = $this->store->read('temperatures.json', []);
        $day = array_key_first(array_filter($temps2, fn($x) => abs($x['avg'] - 5.0) < 0.05));
        self::assertNotNull($day);
        $hdd = max(0.0, $this->consumption->hddBaseFor($m) - (float)$temps2[$day]['avg']);
        self::assertEqualsWithDelta($model['a'] * $hdd * $p['heat'] + $model['c'] * $p['base'], $est($day, date('Y-m-d', strtotime("$day +1 day")))[$day], 1e-6);
    }
}
