<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ClimateNormalService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherService;
use Energietracker\Services\WeatherSource;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.8.0 — Temperatur-Sync und Klimanormal (Review CALC-08, CALC-30, API-24).
 *
 * Bis v2.7 begann der Sync am letzten gespeicherten Tag (ohne Bestand bei
 * heute − 30) und speicherte Vorhersagen als Messwerte; danach wurde das
 * Archiv nie mehr abgerufen. Die Tests laufen gegen eine Wetterquelle ohne
 * Netz, die jede Anfrage protokolliert.
 */
#[CoversClass(TemperatureService::class)]
#[CoversClass(ClimateNormalService::class)]
final class TemperatureSyncTest extends ServiceTestCase
{
    private FakeWeather $weather;
    private ClimateNormalService $climate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weather = new FakeWeather();
        $this->climate = new ClimateNormalService($this->store, $this->settings);
    }

    private function service(): TemperatureService
    {
        return new TemperatureService($this->store, $this->settings, $this->weather, $this->climate);
    }

    private function seedReadingFrom(string $date): void
    {
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => $date,
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('gas', $meterId, [
            ['date' => $date, 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => date('Y-m-d', strtotime('-10 days')), 'counter' => 500.0, 'device_id' => 'd1'],
        ]);
    }

    public function testFirstSyncStartsAtTheFirstReading(): void
    {
        $this->seedReadingFrom('2024-03-01');
        $this->service()->syncOpenMeteo();
        self::assertSame('2024-03-01', $this->weather->archiveCalls[0][0], 'Archiv ab der ersten Ablesung, nicht ab heute − 30');
        self::assertSame(date('Y-m-d', strtotime('-6 days')), $this->weather->archiveCalls[0][1]);
    }

    public function testForecastIsMarkedAndLaterReplacedByTheArchive(): void
    {
        $this->seedReadingFrom(date('Y-m-d', strtotime('-40 days')));
        $svc = $this->service();
        $svc->syncOpenMeteo();
        $all = $svc->all();
        $future = date('Y-m-d', strtotime('+3 days'));
        self::assertSame('forecast', $all[$future]['source'] ?? null, 'Vorhersage trägt ihre Quelle');

        // Zweiter Sync: Der erste Archivabruf beginnt an der ersten Lücke im
        // Messbestand — nicht am letzten (künftigen) Tag wie bis v2.7.
        $this->weather->archiveCalls = [];
        $recent = date('Y-m-d', strtotime('-7 days'));
        $all[$recent] = ['avg' => 1.0, 'min' => 0.0, 'max' => 2.0, 'source' => 'forecast'];
        $this->store->write('temperatures.json', $all);
        $svc->syncOpenMeteo();
        self::assertSame($recent, $this->weather->archiveCalls[0][0] ?? null, 'Archiv ab dem ersten nicht gemessenen Tag');
        self::assertSame('archive', $svc->all()[$recent]['source'], 'Vorhersagetag durch Archivwert ersetzt');
    }

    public function testCsvAndManualValuesAreNeverOverwritten(): void
    {
        $this->seedReadingFrom('2025-01-01');
        $svc = $this->service();
        $svc->upsert('2025-01-10', 3.3, 1.0, 5.0);   // manuell
        $svc->importCsv("11.01.2025\"4.4\"2.0\"6.0\n");
        $svc->syncOpenMeteo(null, null, true);        // selbst mit reload
        $all = $svc->all();
        self::assertSame(3.3, $all['2025-01-10']['avg']);
        self::assertSame('manual', $all['2025-01-10']['source']);
        self::assertSame(4.4, $all['2025-01-11']['avg']);
        self::assertSame('csv', $all['2025-01-11']['source']);
    }

    public function testLegacyEntriesStayUnlessReloadIsAsked(): void
    {
        $this->seedReadingFrom('2025-01-01');
        // Eintrag von vor v2.8.0: ohne Quelle, weit vor dem Archivhorizont
        $this->store->write('temperatures.json', ['2025-01-05' => ['avg' => 9.9, 'min' => 9.0, 'max' => 10.0]]);
        $svc = $this->service();
        $svc->syncOpenMeteo();
        self::assertSame(9.9, $svc->all()['2025-01-05']['avg'], 'ohne reload bleibt der Altwert (Lektion 36)');
        $svc->syncOpenMeteo(null, null, true);
        self::assertSame(FakeWeather::AVG, $svc->all()['2025-01-05']['avg'], 'mit reload kommt der Archivwert');
        self::assertSame('archive', $svc->all()['2025-01-05']['source']);
    }

    public function testAutoSyncRunsAtMostOncePerDay(): void
    {
        $this->seedReadingFrom(date('Y-m-d', strtotime('-20 days')));
        $svc = $this->service();
        self::assertArrayNotHasKey('skipped', $svc->syncOpenMeteo(null, null, false, true));
        $calls = count($this->weather->forecastCalls);
        $second = $svc->syncOpenMeteo(null, null, false, true);
        self::assertTrue($second['skipped'] ?? false);
        self::assertCount($calls, $this->weather->forecastCalls, 'kein zweiter Abruf am selben Tag');
    }

    public function testClimateNormalIsFetchedOnceAndRefetchedAfterAMove(): void
    {
        $this->seedReadingFrom(date('Y-m-d', strtotime('-20 days')));
        $svc = $this->service();
        $r1 = $svc->syncOpenMeteo();
        self::assertSame('fetched', $r1['climate_normal']['status']);
        self::assertCount(1, $this->weather->meanCalls);
        $period = ClimateNormalService::period();
        self::assertSame([$period['from'], $period['to']], $this->weather->meanCalls[0]);

        $r2 = $svc->syncOpenMeteo();
        self::assertSame('present', $r2['climate_normal']['status']);
        self::assertCount(1, $this->weather->meanCalls, 'kein zweiter 30-Jahre-Abruf');

        $this->settings->set(['latitude' => 48.21, 'longitude' => 16.37]);
        $svc->syncOpenMeteo();
        self::assertCount(2, $this->weather->meanCalls, 'nach einem Umzug neu');
    }

    public function testEarliestDataDateIncludesDeliveries(): void
    {
        $this->seedReadingFrom('2024-06-01');
        $this->store->write('heizoel/deliveries.json', [['id' => 'x', 'meter_id' => 'm', 'date' => '2023-11-15', 'quantity' => 1000]]);
        self::assertSame('2023-11-15', $this->service()->earliestDataDate());
    }

    /** API-24: Der Standort verlässt den Server auf rund 1 km gerundet. */
    public function testLocationIsRoundedInRequests(): void
    {
        $w = new WeatherService();
        self::assertStringContainsString('latitude=51.34&longitude=12.37', $w->archiveUrl(51.3397, 12.3731, '2024-01-01', '2024-01-31'));
        self::assertStringContainsString('latitude=51.34&longitude=12.37', $w->forecastUrl(51.3397, 12.3731));
    }

    // ── Klimanormal ─────────────────────────────────────────────────────

    /** Konstante Tagesmittel je Monat über drei Jahre, das zweite Jahr 2 °C kälter. */
    private function syntheticMeans(): array
    {
        $temps = [1 => 0.0, 2 => 1.0, 3 => 5.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
                  7 => 19.0, 8 => 18.0, 9 => 14.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];
        $out = [];
        for ($y = 2021; $y <= 2023; $y++) {
            for ($t = strtotime("$y-01-01"); $t <= strtotime("$y-12-31"); $t += 86400) {
                $m = (int)date('n', $t);
                $out[date('Y-m-d', $t)] = $temps[$m] - ($y === 2022 ? 2.0 : 0.0);
            }
        }
        return $out;
    }

    public function testNormalHasMeanAndSpreadPerMonth(): void
    {
        $normal = ClimateNormalService::compute($this->syntheticMeans(), 51.34, 12.37, ['from' => '2021-01-01', 'to' => '2023-12-31']);
        $this->climate->save($normal);
        $jan = $this->climate->hddForMonth(1, 15.0);
        // Januar: 31 × (15 − 0) = 465, im kalten Jahr 31 × 17 = 527 → Mittel 485,67
        self::assertEqualsWithDelta((465 + 527 + 465) / 3, $jan['mean'], 0.01);
        self::assertGreaterThan(0.0, (float)$jan['sd'], 'Streuung über die Jahre');
        self::assertEqualsWithDelta(0.0, $this->climate->hddForMonth(7, 15.0)['mean'], 0.01, 'Juli über der Heizgrenze');
        self::assertNotNull($this->climate->yearSd(15.0));
    }

    public function testNormalInterpolatesBetweenGridPoints(): void
    {
        $this->climate->save(ClimateNormalService::compute($this->syntheticMeans(), 51.34, 12.37, ['from' => '2021-01-01', 'to' => '2023-12-31']));
        $a = $this->climate->hddForMonth(1, 15.0)['mean'];
        $b = $this->climate->hddForMonth(1, 15.5)['mean'];
        self::assertEqualsWithDelta(($a + $b) / 2, $this->climate->hddForMonth(1, 15.25)['mean'], 0.01);
    }

    public function testNormalIsStaleAfterAMoveOrAYear(): void
    {
        $this->climate->save(ClimateNormalService::compute($this->syntheticMeans(), 51.34, 12.37, ClimateNormalService::period(2026)));
        self::assertFalse($this->climate->isStale(51.34, 12.37, 2026));
        self::assertTrue($this->climate->isStale(48.21, 16.37, 2026), 'anderer Ort');
        self::assertTrue($this->climate->isStale(51.34, 12.37, 2028), 'Referenzperiode veraltet');
    }
}

/** Wetterquelle ohne Netz: liefert für jeden Tag dieselben Werte und protokolliert. */
final class FakeWeather implements WeatherSource
{
    public const AVG = 7.5;
    /** @var list<array{0:string,1:string}> */
    public array $archiveCalls = [];
    /** @var list<array{0:int,1:int}> */
    public array $forecastCalls = [];
    /** @var list<array{0:string,1:string}> */
    public array $meanCalls = [];

    public function fetchArchive(float $lat, float $lon, string $start, string $end): array
    {
        $this->archiveCalls[] = [$start, $end];
        $out = [];
        for ($t = strtotime($start . ' 12:00'); date('Y-m-d', $t) <= $end; $t += 86400) {
            $out[date('Y-m-d', $t)] = ['avg' => self::AVG, 'min' => 2.0, 'max' => 12.0];
        }
        return ['data' => $out, 'error' => null];
    }

    public function fetchForecast(float $lat, float $lon, int $forecastDays = 14, int $pastDays = 0): array
    {
        $this->forecastCalls[] = [$forecastDays, $pastDays];
        $out = [];
        for ($i = -$pastDays; $i < $forecastDays; $i++) {
            $out[date('Y-m-d', strtotime("$i days"))] = ['avg' => 3.0, 'min' => 0.0, 'max' => 6.0];
        }
        return ['data' => $out, 'error' => null];
    }

    public function fetchArchiveMeans(float $lat, float $lon, string $start, string $end): array
    {
        $this->meanCalls[] = [$start, $end];
        $out = [];
        for ($t = strtotime($start . ' 12:00'); date('Y-m-d', $t) <= $end; $t += 86400) {
            $out[date('Y-m-d', $t)] = 10.0 - 8.0 * cos(2 * M_PI * ((int)date('z', $t) - 15) / 365);
        }
        return ['data' => $out, 'error' => null];
    }
}
