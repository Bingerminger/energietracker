<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ClimateNormalService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherSource;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket waermepumpe, E2) — Trend der Temperatur im Klimanormal, von Hand gerechnet: Jahresmittel linear über
 * die vollständigen Jahre, fortgeschrieben auf das Jahr nach dem letzten, minus das Mittel. Die Heizgradtage des Normals
 * bleiben unverändert (Gas bewegt sich nicht), ein Normal ohne den Merker wird einmal ergänzt.
 */
#[CoversClass(ClimateNormalService::class)]
#[CoversClass(TemperatureService::class)]
final class TemperatureTrendTest extends ServiceTestCase
{
    /** Tagesmittel: je Jahr konstant 10 °C + Steigung × (Jahr − 1995) (ohne Jahresgang, das ändert am Mittel nichts). */
    private static function days(int $from, int $to, float $slope, array $skip = []): array
    {
        $out = [];
        for ($t = strtotime("$from-01-01 12:00"); (int)date('Y', $t) <= $to; $t += 86400) {
            $d = date('Y-m-d', $t);
            if (in_array($d, $skip, true)) continue;
            $out[$d] = 10.0 + $slope * ((int)date('Y', $t) - 1995);
        }
        return $out;
    }

    public function testALinearWarmingIsProjectedToTheNextYear(): void
    {
        // 1995 bis 2024, 0,05 K je Jahr: Mittel bei 2009,5; Folgejahr 2025 liegt 15,5 Jahre darüber → 0,775 K
        $r = ClimateNormalService::temperatureTrend(self::days(1995, 2024, 0.05));
        self::assertSame(30, $r['temp_trend_years']);
        self::assertEqualsWithDelta(0.775, $r['temp_trend'], 1e-3);
        self::assertSame('linear_annual', $r['temp_trend_method']);
        // Steigung und Mitte für den Versatz eines bestimmten Jahrs (tempTrend($jahr))
        self::assertEqualsWithDelta(0.05, $r['temp_trend_slope'], 1e-6);
        self::assertSame(2009.5, $r['temp_trend_center']);
        // ohne Trend null Kelvin
        self::assertEqualsWithDelta(0.0, ClimateNormalService::temperatureTrend(self::days(1995, 2024, 0.0))['temp_trend'], 1e-9);
    }

    public function testTheTrendIsLimitedAndNeedsEnoughCompleteYears(): void
    {
        self::assertSame(ClimateNormalService::TEMP_TREND_MAX, ClimateNormalService::temperatureTrend(self::days(1995, 2024, 0.5))['temp_trend']);
        self::assertSame(-ClimateNormalService::TEMP_TREND_MAX, ClimateNormalService::temperatureTrend(self::days(1995, 2024, -0.5))['temp_trend']);
        $few = ClimateNormalService::temperatureTrend(self::days(2015, 2023, 0.05));
        self::assertSame(9, $few['temp_trend_years']);
        self::assertNull($few['temp_trend']);
        // ein Jahr mit zwei fehlenden Tagen im März zählt nicht mit
        $r = ClimateNormalService::temperatureTrend(self::days(1995, 2024, 0.05, ['2000-03-10', '2000-03-11']));
        self::assertSame(29, $r['temp_trend_years']);
    }

    public function testComputeCarriesTheTrendAndTheRadiationOnlyRefreshKeepsTheDegreeDays(): void
    {
        $days = self::days(1995, 2024, 0.05);
        $n = ClimateNormalService::compute($days, 51.0, 10.0, ['from' => '1995-01-01', 'to' => '2024-12-31']);
        self::assertEqualsWithDelta(0.775, $n['temp_trend'], 1e-3);
        self::assertArrayHasKey(ClimateNormalService::TEMP_MARKER, $n);
        // ein Normal von vor E2 (ohne Merker) wird nur ergänzt: die Gradtage bleiben, wie sie waren
        $old = $n;
        unset($old['temp_trend'], $old['temp_trend_years'], $old['temp_trend_method']);
        $new = ClimateNormalService::withRadiation($old, [], self::days(1995, 2024, 0.02));
        self::assertSame($old['hdd'], $new['hdd']);
        self::assertEqualsWithDelta(0.31, $new['temp_trend'], 1e-3, '0,02 K × 15,5 Jahre');
        // ohne Tagesmittel (wie bisher) kein Trend
        self::assertArrayNotHasKey('temp_trend', ClimateNormalService::withRadiation($old, []));
        // fehlte nur der Trend und bringt der Abruf keine Einstrahlung, bleibt die vorhandene
        $old['rad_month'] = [20.0, 35.0, 70.0, 110.0, 140.0, 150.0, 150.0, 130.0, 90.0, 55.0, 25.0, 15.0];
        self::assertSame($old['rad_month'], ClimateNormalService::withRadiation($old, [], self::days(1995, 2024, 0.02))['rad_month']);
    }

    /**
     * Der Wetterabgleich ergänzt einem Normal ohne Merker einmal den Trend aus denselben dreißig Jahren; die
     * Heizgradtage bleiben byte-gleich, der zweite Abgleich fragt nicht noch einmal.
     */
    public function testTheSyncAddsTheTrendOnceAndKeepsTheHeatingDegreeDays(): void
    {
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => date('Y-m-d', strtotime('-20 days')),
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('gas', $meterId, [
            ['date' => date('Y-m-d', strtotime('-20 days')), 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => date('Y-m-d', strtotime('-10 days')), 'counter' => 500.0, 'device_id' => 'd1'],
        ]);
        $period = ClimateNormalService::period();
        $weather = new TrendFakeWeather();
        $climate = new ClimateNormalService($this->store, $this->settings);
        $old = ClimateNormalService::compute($weather->means($period['from'], $period['to']), 51.34, 12.37, $period);
        unset($old['temp_trend'], $old['temp_trend_years'], $old['temp_trend_method']);
        $old['hdd']['15.0']['mean'][0] = 123.4;   // erkennbar: bleibt, wie es war
        $climate->save($old);
        self::assertTrue($climate->lacksOnlyRadiation(51.34, 12.37));

        $svc = new TemperatureService($this->store, $this->settings, $weather, $climate);
        $r = $svc->syncOpenMeteo();
        self::assertSame('fetched', $r['climate_normal']['status']);
        self::assertTrue($r['climate_normal']['radiation_only']);
        self::assertSame([[$period['from'], $period['to']]], $weather->meanCalls);
        $n = $climate->get();
        self::assertSame(json_encode($old['hdd']), json_encode($n['hdd']), 'Heizgradtage byte-gleich');
        // 0,04 K je Jahr über dreißig Jahre: das Folgejahr liegt 15,5 Jahre über der Mitte
        self::assertEqualsWithDelta(0.62, $climate->tempTrend(), 0.01);
        // für ein Jahr in der Periode: Abstand zur Mitte × Steigung (das erste Jahr liegt 14,5 Jahre darunter)
        self::assertEqualsWithDelta(-0.58, $climate->tempTrend((int)substr($period['from'], 0, 4)), 0.01);

        $svc->syncOpenMeteo();
        self::assertCount(1, $weather->meanCalls, 'einmal, nicht bei jedem Abgleich');
    }
}

/** Wetterquelle ohne Netz: dreißig Jahre mit 0,04 K Erwärmung je Jahr und Jahresgang; protokolliert die Normal-Abrufe. */
final class TrendFakeWeather implements WeatherSource
{
    /** @var list<array{0:string,1:string}> */
    public array $meanCalls = [];

    /** @return array<string,float> */
    public function means(string $start, string $end): array
    {
        $out = [];
        $y0 = (int)substr($start, 0, 4);
        for ($t = strtotime($start . ' 12:00'); date('Y-m-d', $t) <= $end; $t += 86400) {
            $out[date('Y-m-d', $t)] = 10.0 - 8.0 * cos(2 * M_PI * ((int)date('z', $t) - 15) / 365) + 0.04 * ((int)date('Y', $t) - $y0);
        }
        return $out;
    }

    public function fetchArchive(float $lat, float $lon, string $start, string $end): array
    {
        $out = [];
        for ($t = strtotime($start . ' 12:00'); date('Y-m-d', $t) <= $end; $t += 86400) {
            $out[date('Y-m-d', $t)] = ['avg' => 7.5, 'min' => 2.0, 'max' => 12.0, 'rad' => 3.0];
        }
        return ['data' => $out, 'error' => null];
    }

    public function fetchForecast(float $lat, float $lon, int $forecastDays = 14, int $pastDays = 0): array
    {
        $out = [];
        for ($i = -$pastDays; $i < $forecastDays; $i++) {
            $out[date('Y-m-d', strtotime("$i days"))] = ['avg' => 3.0, 'min' => 0.0, 'max' => 6.0, 'rad' => 1.5];
        }
        return ['data' => $out, 'error' => null];
    }

    public function geocode(string $query, string $language = 'de', int $count = 5): array
    {
        return ['data' => [], 'error' => null];
    }

    public function fetchArchiveMeans(float $lat, float $lon, string $start, string $end): array
    {
        $this->meanCalls[] = [$start, $end];
        $rad = [];
        foreach (array_keys($this->means($start, $end)) as $d) $rad[$d] = 2.0;
        return ['data' => $this->means($start, $end), 'rad' => $rad, 'error' => null];
    }
}
