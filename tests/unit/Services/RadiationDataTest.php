<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Services\ClimateNormalService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherService;
use Energietracker\Services\WeatherSource;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket sonne) — Einstrahlung von Open-Meteo: im selben Abruf wie die Temperatur geholt, als `rad`
 * (kWh/m² am Tag) gespeichert, im Klimanormal als `rad_month` (mittlere Monatssumme), in der Sicherung dabei.
 * Ohne Netz: Die Wetterquelle ist ein Testdouble, die Antworten von Open-Meteo stehen als JSON im Test.
 */
#[CoversClass(WeatherService::class)]
#[CoversClass(ClimateNormalService::class)]
#[CoversClass(TemperatureService::class)]
#[CoversClass(BackupService::class)]
final class RadiationDataTest extends ServiceTestCase
{
    private RadFakeWeather $weather;
    private ClimateNormalService $climate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weather = new RadFakeWeather();
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

    // ── Abruf ───────────────────────────────────────────────────────────

    public function testEveryRequestAsksForTheRadiation(): void
    {
        $w = new WeatherService();
        foreach ([$w->archiveUrl(51.3, 12.4, '2025-01-01', '2025-01-31'), $w->forecastUrl(51.3, 12.4, 14, 7),
                  $w->meansUrl(51.3, 12.4, '1996-01-01', '2025-12-31')] as $url) {
            self::assertStringContainsString('shortwave_radiation_sum', $url);
        }
        self::assertStringContainsString('daily=temperature_2m_mean,temperature_2m_min,temperature_2m_max,shortwave_radiation_sum&', $w->archiveUrl(51.3, 12.4, '2025-01-01', '2025-01-31'));
    }

    public function testMegajoulesBecomeKilowattHoursPerSquareMetre(): void
    {
        // Open-Meteo liefert MJ/m² am Tag; 3,6 MJ = 1 kWh
        $days = WeatherService::parseDays(['daily' => [
            'time' => ['2025-06-01', '2025-06-02', '2025-06-03', '2025-06-04'],
            'temperature_2m_mean' => [18.0, 17.0, 16.0, null],
            'temperature_2m_min' => [12.0, 11.0, 10.0, 9.0],
            'temperature_2m_max' => [24.0, 23.0, 22.0, 21.0],
            'shortwave_radiation_sum' => [25.2, null, -1.0, 20.0],
        ]]);
        self::assertSame(['avg' => 18.0, 'min' => 12.0, 'max' => 24.0, 'rad' => 7.0], $days['2025-06-01']);
        self::assertArrayNotHasKey('rad', $days['2025-06-02'], 'ohne Einstrahlung bleibt der Tag gültig');
        self::assertArrayNotHasKey('rad', $days['2025-06-03'], 'negativ ist kein Wert');
        self::assertArrayNotHasKey('2025-06-04', $days, 'ohne Mittel wie bisher kein Tag');
        self::assertNull(WeatherService::parseDays(['hourly' => []]));
        self::assertSame(1.0, WeatherService::radiation(3.6));
        self::assertSame(0.333, WeatherService::radiation(1.2));

        $means = WeatherService::parseMeans(['daily' => [
            'time' => ['1996-01-01', '1996-01-02'], 'temperature_2m_mean' => [1.0, 2.0], 'shortwave_radiation_sum' => [3.6, null],
        ]]);
        self::assertSame(['1996-01-01' => 1.0, '1996-01-02' => 2.0], $means['data']);
        self::assertSame(['1996-01-01' => 1.0], $means['rad']);
    }

    // ── Klimanormal ─────────────────────────────────────────────────────

    /** Zwei Jahre mit fester Einstrahlung je Monat: Januar 1,0 bzw. 1,2 kWh/m² am Tag, Juni 5,0, sonst 2,0. */
    private static function twoYearsOfRadiation(): array
    {
        $out = [];
        foreach ([2021, 2022] as $y) {
            for ($t = strtotime("$y-01-01 12:00"); date('Y', $t) === (string)$y; $t += 86400) {
                $m = (int)date('n', $t);
                $out[date('Y-m-d', $t)] = $m === 1 ? ($y === 2021 ? 1.0 : 1.2) : ($m === 6 ? 5.0 : 2.0);
            }
        }
        return $out;
    }

    public function testTheNormalHoldsTheMeanMonthlySumAndItsSpread(): void
    {
        $s = ClimateNormalService::radiationStats(self::twoYearsOfRadiation());
        // Januar: 31 × 1,0 = 31,0 und 31 × 1,2 = 37,2 → Mittel 34,1, Streuung |37,2 − 31,0| / √2 = 4,38
        self::assertSame(34.1, $s['rad_month'][0]);
        self::assertSame(4.38, $s['rad_sd'][0]);
        self::assertSame(150.0, $s['rad_month'][5], 'Juni 30 × 5,0');
        self::assertSame(56.0, $s['rad_month'][1], 'Februar 28 × 2,0 (kein Schaltjahr)');
        self::assertSame(0.0, $s['rad_sd'][5]);
        self::assertSame(2, $s['rad_years']);
        // Jahressummen 2021: 31 + 150 + 2 × (365 − 31 − 30) = 789; 2022: 795,2 → Streuung 6,2 / √2
        self::assertSame(4.38, $s['rad_year_sd']);
    }

    public function testAMonthWithOneMissingDayIsScaledAndOneWithTwoIsLeftOut(): void
    {
        $rad = [];
        for ($d = 1; $d <= 31; $d++) $rad[sprintf('2021-03-%02d', $d)] = 3.0;
        unset($rad['2021-03-15']);                                         // 30 von 31 Tagen: × 31/30
        for ($d = 1; $d <= 30; $d++) $rad[sprintf('2021-04-%02d', $d)] = 4.0;
        unset($rad['2021-04-10'], $rad['2021-04-11']);                     // 28 von 30: zählt nicht
        $s = ClimateNormalService::radiationStats($rad);
        self::assertSame(93.0, $s['rad_month'][2]);
        self::assertNull($s['rad_month'][3]);
        self::assertNull($s['rad_sd'][2], 'ein Jahr: keine Streuung');
        self::assertSame(0, $s['rad_years']);
    }

    public function testComputeAlwaysCarriesTheMarkerAndANormalWithoutItIsRefetchedOnce(): void
    {
        $means = [];
        foreach (array_keys(self::twoYearsOfRadiation()) as $d) $means[$d] = 8.0;
        $without = ClimateNormalService::compute($means, 51.34, 12.37, ClimateNormalService::period(2026));
        self::assertArrayHasKey('rad_month', $without);
        self::assertNull($without['rad_month'], 'ohne Einstrahlung: Merker null, kein neuer Abruf');
        $this->climate->save($without);
        self::assertFalse($this->climate->isStale(51.34, 12.37, 2026));
        self::assertFalse($this->climate->hasRadiation());
        self::assertNull($this->climate->radForMonth(1));

        $old = $without;
        unset($old['rad_month'], $old['rad_sd'], $old['rad_year_sd'], $old['rad_years'],
            $old['rad_month_trend'], $old['rad_trend_factor'], $old['rad_trend_method']);   // gespeichert vor v3.13.0
        $this->climate->save($old);
        self::assertTrue($this->climate->isStale(51.34, 12.37, 2026), 'einmal ergänzen');
        self::assertTrue($this->climate->lacksOnlyRadiation(51.34, 12.37, 2026));
        self::assertFalse($this->climate->lacksOnlyRadiation(48.21, 16.37, 2026), 'nach einem Umzug ganz neu');
        self::assertFalse($this->climate->lacksOnlyRadiation(51.34, 12.37, 2028), 'Referenzperiode veraltet: ganz neu');

        $merged = ClimateNormalService::withRadiation($old, self::twoYearsOfRadiation());
        self::assertSame($old['hdd'], $merged['hdd'], 'Heizgradtage unverändert');
        self::assertSame($old['doy_avg'], $merged['doy_avg']);
        $this->climate->save($merged);
        self::assertTrue($this->climate->hasRadiation());
        self::assertSame(['mean' => 34.1, 'sd' => 4.38, 'trend' => false], $this->climate->radForMonth(1), 'zwei Jahre: kein Trend');
        self::assertEqualsWithDelta(56.0 / 28.25, $this->climate->radPerDay(2), 1e-9, 'Februar über die Schaltjahre gemittelt');
        self::assertEqualsWithDelta(150.0 / 30, $this->climate->radPerDay(6), 1e-9);
        self::assertSame(4.38, $this->climate->radYearSd());
    }

    // ── C2: Trend der Einstrahlung ──────────────────────────────────────

    public function testTheTrendIsTheLinearFitOfTheAnnualSumsCarriedToTheNextYear(): void
    {
        // 2001 bis 2010 je 1 kWh/m² mehr: 100 … 109, Mittel 104,5 im Jahr 2005,5; 2011: 104,5 + 1 × 5,5 = 110
        $years = array_combine(range(2001, 2010), range(100.0, 109.0));
        self::assertEqualsWithDelta(110 / 104.5, ClimateNormalService::radTrendFactor($years), 1e-12);
        self::assertNull(ClimateNormalService::radTrendFactor(array_slice($years, 0, 9, true)), 'unter zehn Jahren kein Trend');
        $steep = array_combine(range(2001, 2010), array_map(fn($i) => 100.0 + 10 * $i, range(0, 9)));
        self::assertSame(1.1, ClimateNormalService::radTrendFactor($steep), 'höchstens + 10 %');
        self::assertEqualsWithDelta(1.0, ClimateNormalService::radTrendFactor(array_combine(range(2001, 2010), array_fill(0, 10, 100.0))), 1e-12);
    }

    public function testTheNormalCarriesTheTrendAndTheForecastReadsIt(): void
    {
        // zwölf Jahre, jedes Jahr jeder Tag 1 % heller als im Vorjahr
        $rad = [];
        for ($y = 2010; $y <= 2021; $y++) {
            for ($t = strtotime("$y-01-01 12:00"); date('Y', $t) === (string)$y; $t += 86400) $rad[date('Y-m-d', $t)] = 2.0 * (1 + 0.01 * ($y - 2010));
        }
        $s = ClimateNormalService::radiationStats($rad);
        $sums = [];
        for ($y = 2010; $y <= 2021; $y++) $sums[$y] = 2.0 * (1 + 0.01 * ($y - 2010)) * (365 + (int)($y % 4 === 0));
        $f = ClimateNormalService::radTrendFactor($sums);
        self::assertEqualsWithDelta($f, $s['rad_trend_factor'], 1e-4);
        self::assertSame('linear_annual', $s['rad_trend_method']);
        self::assertEqualsWithDelta(round($s['rad_month'][6] * $s['rad_trend_factor'], 2), $s['rad_month_trend'][6], 0.011);
        self::assertGreaterThan($s['rad_month'][6], $s['rad_month_trend'][6], 'heller als das flache Mittel');
        $n = ClimateNormalService::compute(array_map(fn() => 8.0, $rad), 51.34, 12.37, ClimateNormalService::period(2026), $rad);
        $this->climate->save($n);
        self::assertTrue($this->climate->hasRadiationTrend());
        self::assertSame($s['rad_month_trend'][6], $this->climate->radForMonth(7)['mean'], 'erwartet = Trend');
        self::assertTrue($this->climate->radForMonth(7)['trend']);
        // ein Normal mit `rad_month`, aber ohne Trend (Prüfhaushalt, Entwicklungsstand): flach, und einmal ergänzen
        unset($n['rad_month_trend']);
        $this->climate->save($n);
        self::assertSame($s['rad_month'][6], $this->climate->radForMonth(7)['mean']);
        self::assertFalse($this->climate->hasRadiationTrend());
        self::assertTrue($this->climate->lacksOnlyRadiation(51.34, 12.37, 2026));
    }

    public function testTheNormalReadsMonthsAsAListOrAsKeysOneToTwelve(): void
    {
        $n = ClimateNormalService::compute(['2021-01-01' => 1.0], 51.34, 12.37, ClimateNormalService::period(2026));
        $n['rad_month'] = array_combine(range(1, 12), [20, 35, 70, 110, 140, 150, 150, 125, 85, 50, 22, 15]);
        $this->climate->save($n);
        self::assertSame(20.0, $this->climate->radForMonth(1)['mean']);
        self::assertSame(15.0, $this->climate->radForMonth(12)['mean']);
        self::assertNull($this->climate->radForMonth(12)['sd']);
    }

    // ── Abgleich ────────────────────────────────────────────────────────

    public function testTheSyncStoresTheRadiationWithArchiveAndForecast(): void
    {
        $this->seedReadingFrom(date('Y-m-d', strtotime('-30 days')));
        $svc = $this->service();
        $svc->syncOpenMeteo();
        $all = $svc->all();
        $past = date('Y-m-d', strtotime('-20 days'));
        $future = date('Y-m-d', strtotime('+3 days'));
        self::assertSame(RadFakeWeather::RAD, $all[$past]['rad'], 'Archiv');
        self::assertSame('archive', $all[$past]['source']);
        self::assertSame(RadFakeWeather::RAD_FORECAST, $all[$future]['rad'], 'Vorhersage');
        self::assertSame('forecast', $all[$future]['source']);
        self::assertSame(RadFakeWeather::AVG, $all[$past]['avg']);
    }

    public function testOldDaysGetTheirRadiationOnceAndKeepTheirTemperatures(): void
    {
        $first = date('Y-m-d', strtotime('-60 days'));
        $this->seedReadingFrom($first);
        // Bestand von vor v3.13.0: Archiv, CSV und Hand, ohne Einstrahlung
        $old = [];
        for ($t = strtotime($first . ' 12:00'); $t <= strtotime('-7 days'); $t += 86400) {
            $old[date('Y-m-d', $t)] = ['avg' => 4.25, 'min' => 1.0, 'max' => 8.0, 'source' => 'archive'];
        }
        $csvDay = date('Y-m-d', strtotime('-50 days'));
        $old[$csvDay]['source'] = 'csv';
        $this->store->write('temperatures.json', $old);

        $svc = $this->service();
        $r = $svc->syncOpenMeteo();
        self::assertSame($first, $this->weather->archiveCalls[0][0], 'ab dem ersten Tag ohne Einstrahlung');
        self::assertGreaterThan(40, $r['radiation_rows']);
        $all = $svc->all();
        self::assertSame(4.25, $all[$first]['avg'], 'Temperatur bleibt (Lektion 36)');
        self::assertSame('archive', $all[$first]['source']);
        self::assertSame(RadFakeWeather::RAD, $all[$first]['rad']);
        self::assertSame('csv', $all[$csvDay]['source']);
        self::assertSame(RadFakeWeather::RAD, $all[$csvDay]['rad'], 'auch ein CSV-Tag bekommt die Einstrahlung');
        self::assertSame(['from' => $first, 'to' => date('Y-m-d', strtotime('-6 days'))], $svc->meta()['radiation_checked']);

        // Der nächste Abgleich holt die alten Jahre nicht wieder
        $this->store->write(TemperatureService::META_FILE, ['last_sync_date' => '2000-01-01'] + $svc->meta());
        $this->weather->archiveCalls = [];
        $svc->syncOpenMeteo();
        self::assertSame([], array_filter($this->weather->archiveCalls, fn($c) => $c[0] <= $first), 'kein zweiter Abruf ab dem ersten Tag');
    }

    public function testASourceWithoutRadiationIsNotAskedForTheSameYearsAgain(): void
    {
        $this->weather->withRad = false;
        $first = date('Y-m-d', strtotime('-40 days'));
        $this->seedReadingFrom($first);
        $svc = $this->service();
        $svc->syncOpenMeteo();
        self::assertSame($first, $this->weather->archiveCalls[0][0]);
        self::assertSame(['from' => $first, 'to' => date('Y-m-d', strtotime('-6 days'))], $svc->meta()['radiation_checked']);
        $this->weather->archiveCalls = [];
        $svc->syncOpenMeteo();
        foreach ($this->weather->archiveCalls as $c) self::assertGreaterThan(date('Y-m-d', strtotime('-7 days')), $c[0], 'nur noch neue Tage');
    }

    public function testHandAndCsvValuesKeepTheMeasuredRadiationButNotAForecastOne(): void
    {
        $this->store->write('temperatures.json', [
            '2025-06-01' => ['avg' => 18.0, 'min' => 12.0, 'max' => 24.0, 'rad' => 6.5, 'source' => 'archive'],
            '2025-06-02' => ['avg' => 18.0, 'min' => 12.0, 'max' => 24.0, 'rad' => 6.1, 'source' => 'archive'],
            '2025-06-03' => ['avg' => 18.0, 'min' => 12.0, 'max' => 24.0, 'rad' => 5.0, 'source' => 'forecast'],
        ]);
        $svc = $this->service();
        $svc->upsert('2025-06-01', 17.5, 11.0, 23.0);
        $svc->importCsv("02.06.2025;16,0;10,0;22,0\n03.06.2025;15,0;9,0;21,0\n");
        $all = $svc->all();
        // JSON schreibt 11.0 als 11 (Lektion 90): verglichen wird der Inhalt
        self::assertEquals(['avg' => 17.5, 'min' => 11.0, 'max' => 23.0, 'source' => 'manual', 'rad' => 6.5], $all['2025-06-01']);
        self::assertSame(6.1, $all['2025-06-02']['rad']);
        self::assertEquals(16.0, $all['2025-06-02']['avg']);
        self::assertArrayNotHasKey('rad', $all['2025-06-03'], 'die Einstrahlung einer Vorhersage gilt nicht für einen gemessenen Tag');
    }

    public function testAnOldNormalGetsOnlyTheRadiationAndKeepsItsHeatingDegreeDays(): void
    {
        $this->seedReadingFrom(date('Y-m-d', strtotime('-20 days')));
        $period = ClimateNormalService::period();
        $means = [];
        for ($t = strtotime($period['to'] . ' 12:00 -2 years'); date('Y-m-d', $t) <= $period['to']; $t += 86400) $means[date('Y-m-d', $t)] = 9.0;
        $old = ClimateNormalService::compute($means, 51.34, 12.37, $period);
        unset($old['rad_month'], $old['rad_sd'], $old['rad_year_sd'], $old['rad_years'],
            $old['rad_month_trend'], $old['rad_trend_factor'], $old['rad_trend_method']);
        $this->climate->save($old);

        $r = $this->service()->syncOpenMeteo();
        self::assertSame('fetched', $r['climate_normal']['status']);
        self::assertTrue($r['climate_normal']['radiation_only']);
        self::assertSame([[$period['from'], $period['to']]], $this->weather->meanCalls, 'derselbe Zeitraum wie das Normal');
        $n = $this->climate->get();
        self::assertSame(json_encode($old['hdd']), json_encode($n['hdd']), 'Heizgradtage byte-gleich');
        self::assertSame($old['fetched_at'], $n['fetched_at']);
        self::assertTrue($this->climate->hasRadiation());

        $r2 = $this->service()->syncOpenMeteo();
        self::assertSame('present', $r2['climate_normal']['status']);
        self::assertCount(1, $this->weather->meanCalls, 'einmal, nicht bei jedem Abgleich');
    }

    public function testAFreshNormalComesWithRadiation(): void
    {
        $this->seedReadingFrom(date('Y-m-d', strtotime('-20 days')));
        $this->service()->syncOpenMeteo();
        self::assertTrue($this->climate->hasRadiation());
        // jeden Tag 2,0 kWh/m²: Januar 62, Februar 56 bzw. 58 (Schaltjahre im Mittel)
        self::assertSame(62.0, $this->climate->radForMonth(1)['mean']);
        self::assertGreaterThan(56.0, $this->climate->radForMonth(2)['mean']);
        self::assertLessThan(58.0, $this->climate->radForMonth(2)['mean']);
    }

    // ── Sicherung ───────────────────────────────────────────────────────

    public function testTheRadiationTravelsThroughTheBackup(): void
    {
        $backup = new BackupService($this->store, $this->i18n);
        $temps = [
            '2025-06-01' => ['avg' => 18.0, 'min' => 12.0, 'max' => 24.0, 'rad' => 6.512, 'source' => 'archive'],
            '2025-06-02' => ['avg' => 17.0, 'min' => 11.0, 'max' => 23.0, 'source' => 'csv'],
        ];
        $this->store->write('temperatures.json', $temps);
        $dump = $backup->export(false);
        $this->store->write('temperatures.json', []);
        $backup->import($dump);
        self::assertSame(json_encode($temps), json_encode($this->store->read('temperatures.json', [])));

        // eine Sicherung von vor v3.13.0 (ohne `rad`) spielt sich wie bisher ein
        $dump['temperatures'] = ['2024-01-01' => ['avg' => 1.0, 'min' => -2.0, 'max' => 3.0]];
        self::assertSame([], $backup->import($dump, true)['problems']);
    }

    public function testAnImpossibleRadiationIsAProblem(): void
    {
        $backup = new BackupService($this->store, $this->i18n);
        foreach ([-0.5, 99.0, 'viel', true] as $bad) {
            $dump = $backup->export(false);
            $dump['temperatures'] = ['2025-06-01' => ['avg' => 18.0, 'min' => 12.0, 'max' => 24.0, 'rad' => $bad]];
            try {
                $backup->import($dump);
                self::fail('eingespielt trotz rad = ' . json_encode($bad));
            } catch (BackupInvalidException $e) {
                self::assertContains('value:2025-06-01.rad', array_column($e->problems, 'problem'));
            }
        }
    }
}

/** Wetterquelle ohne Netz mit Einstrahlung (abschaltbar); protokolliert jede Anfrage. */
final class RadFakeWeather implements WeatherSource
{
    public const AVG = 7.5;
    public const RAD = 3.25;
    public const RAD_FORECAST = 1.5;
    public bool $withRad = true;
    /** @var list<array{0:string,1:string}> */
    public array $archiveCalls = [];
    /** @var list<array{0:string,1:string}> */
    public array $meanCalls = [];

    public function fetchArchive(float $lat, float $lon, string $start, string $end): array
    {
        $this->archiveCalls[] = [$start, $end];
        $out = [];
        for ($t = strtotime($start . ' 12:00'); date('Y-m-d', $t) <= $end; $t += 86400) {
            $out[date('Y-m-d', $t)] = ['avg' => self::AVG, 'min' => 2.0, 'max' => 12.0] + ($this->withRad ? ['rad' => self::RAD] : []);
        }
        return ['data' => $out, 'error' => null];
    }

    public function fetchForecast(float $lat, float $lon, int $forecastDays = 14, int $pastDays = 0): array
    {
        $out = [];
        for ($i = -$pastDays; $i < $forecastDays; $i++) {
            $out[date('Y-m-d', strtotime("$i days"))] = ['avg' => 3.0, 'min' => 0.0, 'max' => 6.0] + ($this->withRad ? ['rad' => self::RAD_FORECAST] : []);
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
        $out = []; $rad = [];
        for ($t = strtotime($start . ' 12:00'); date('Y-m-d', $t) <= $end; $t += 86400) {
            $out[date('Y-m-d', $t)] = 10.0 - 8.0 * cos(2 * M_PI * ((int)date('z', $t) - 15) / 365);
            if ($this->withRad) $rad[date('Y-m-d', $t)] = 2.0;
        }
        return ['data' => $out, 'rad' => $rad, 'error' => null];
    }
}
