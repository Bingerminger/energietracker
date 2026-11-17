<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ClimateNormalService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\PvWeatherModel;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket sonne) — PV-Erzeugung und Einspeisung nach dem Wetter, an von Hand gerechneten Kleinfällen.
 *
 * Erfundener Standort: Einstrahlung je Tag fest je Kalendermonat (RAD), das Klimanormal 10 % trüber als die
 * gemessenen Jahre (NORMAL_SHARE). Die Anlage erzeugt je Tag k(Jahreszeit) × Einstrahlung (K, ein Süddach: im
 * Winter mehr je kWh/m² als im Sommer). Erwartet wird k × Monatssumme des Normals; der alte Weg (Saisonprofil)
 * wiederholt die sonnigen Jahre und liegt um 1 ÷ 0,9 zu hoch. Alle Daten fest, kein „heute“.
 */
#[CoversClass(PvWeatherModel::class)]
#[CoversClass(ForecastService::class)]
final class PvWeatherForecastTest extends ServiceTestCase
{
    /** kWh/m² am Tag je Kalendermonat in den gemessenen Jahren. */
    private const RAD = [1 => 0.8, 2 => 1.5, 3 => 2.8, 4 => 4.3, 5 => 5.2, 6 => 5.6, 7 => 5.4, 8 => 4.6, 9 => 3.2, 10 => 1.9, 11 => 1.0, 12 => 0.6];
    private const NORMAL_SHARE = 0.9;
    /** k je Jahreszeit (PvWeatherModel::GROUPS): Nov–Jan, Feb/Okt, Mär/Sep, Apr/Aug, Mai–Jul. */
    private const K = [0 => 7.0, 1 => 6.0, 2 => 5.5, 3 => 5.0, 4 => 4.6];

    protected function setUp(): void
    {
        parent::setUp();
        // Erzeugung und Einspeisung sind am Prüfhaushalt noch nicht freigegeben (PvWeatherModel::GEN_ENABLED,
        // FEED_ENABLED); die Rechnung selbst prüfen diese Tests eingeschaltet, den Auslieferungszustand
        // testShippedStateKeepsTheSeasonalProfileWithAHint
        $this->consumption->setPvWeather(true, true);
    }

    private function forecast(): ForecastService
    {
        return new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
    }

    /** Erwartung je Monat mit eigener Messung: Mischung aus k × Normal und dem gemessenen Monat (PV_BLEND). */
    private static function blended(int $m, int $days): float
    {
        $k = self::K[PvWeatherModel::GROUPS[$m]];
        return PvWeatherModel::PV_BLEND * $k * self::normalSum($m) + (1 - PvWeatherModel::PV_BLEND) * $k * self::RAD[$m] * $days;
    }

    /** Monatssumme des Normals (kWh/m²): 90 % der gemessenen Tage, Februar mit 28,25 Tagen. */
    private static function normalSum(int $m): float
    {
        return self::RAD[$m] * ($m === 2 ? 28.25 : (int)date('t', mktime(0, 0, 0, $m, 1, 2001))) * self::NORMAL_SHARE;
    }

    /** Wetter 2023–2025 mit Einstrahlung, dazu ein Klimanormal mit `rad_month` (90 %). */
    private function weather(bool $withRad = true, bool $normalWithRad = true): void
    {
        $temps = [];
        for ($t = strtotime('2023-01-01 12:00'); $t <= strtotime('2025-12-31 12:00'); $t += 86400) {
            $m = (int)date('n', $t);
            $temps[date('Y-m-d', $t)] = ['avg' => 10.0, 'min' => 5.0, 'max' => 15.0, 'source' => 'archive'] + ($withRad ? ['rad' => self::RAD[$m]] : []);
        }
        $this->store->write('temperatures.json', $temps);
        $means = [];
        foreach (array_keys($temps) as $d) $means[$d] = 10.0;
        $n = ClimateNormalService::compute($means, 51.34, 12.37, ['from' => '1996-01-01', 'to' => '2025-12-31']);
        if ($normalWithRad) {
            $n['rad_month'] = array_map(fn($m) => self::normalSum($m), range(1, 12));
            $n['rad_sd'] = array_map(fn($m) => round(0.08 * self::normalSum($m), 2), range(1, 12));
            $n['rad_year_sd'] = 60.0;
        }
        $this->store->write(ClimateNormalService::FILE, $n);
        $this->settings->set(['active_utilities' => ['strom', 'pv_erzeugung', 'pv_einspeisung']]);
    }

    /**
     * Ein kumulativer Zähler, abgelesen an den angegebenen Tagen; je Tag $perDay($monat) kWh.
     *
     * @param list<string> $dates
     */
    private function meter(string $utility, string $id, array $dates, callable $perDay, array $extra = []): array
    {
        $dev = [['id' => 'd1', 'serial' => null, 'installed_on' => $dates[0], 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $m = ['id' => $id, 'name' => 'Test', 'icon' => '', 'created_at' => '2023-01-01', 'active' => true, 'notes' => '', 'devices' => $dev] + $extra;
        $this->store->write("$utility/meters.json", [$m]);
        $rows = []; $c = 0.0; $prev = null;
        foreach ($dates as $i => $d) {
            if ($prev !== null) for ($t = strtotime($prev . ' 12:00'); date('Y-m-d', $t) < $d; $t += 86400) $c += $perDay((int)date('n', $t));
            $rows[] = ['id' => sprintf('r_%03d', $i), 'meter_id' => $id, 'date' => $d, 'counter' => round($c, 3), 'device_id' => 'd1',
                       'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            $prev = $d;
        }
        $this->store->write("$utility/readings.json", $rows);
        return $m;
    }

    /** @return list<string> Monatserste von $from bis $to */
    private static function firsts(string $from, string $to): array
    {
        $out = [];
        for ($t = strtotime($from); date('Y-m-d', $t) <= $to; $t = strtotime('+1 month', $t)) $out[] = date('Y-m-d', $t);
        return $out;
    }

    // ── Reine Bausteine ─────────────────────────────────────────────────

    public function testOneKForAllSeasonsIsRecoveredExactly(): void
    {
        // drei Abstände: Januar (31 × 0,8 = 24,8 kWh/m²), Juni (30 × 5,6 = 168), September (30 × 3,2 = 96), k = 5
        $temps = [];
        foreach (['2024-01' => 0.8, '2024-06' => 5.6, '2024-09' => 3.2] as $ym => $r) {
            for ($d = 1; $d <= (int)date('t', strtotime("$ym-01")); $d++) $temps[sprintf('%s-%02d', $ym, $d)] = ['rad' => $r];
        }
        $spans = [['2024-01-01', '2024-02-01', 124.0], ['2024-06-01', '2024-07-01', 840.0], ['2024-09-01', '2024-10-01', 480.0]];
        $pts = PvWeatherModel::points($spans, $temps, fn(int $m): ?float => null);
        self::assertCount(3, $pts);
        self::assertEqualsWithDelta(24.8, $pts[0]['h'][0], 1e-9, 'Januar in der Jahreszeit November bis Januar');
        self::assertEqualsWithDelta(168.0, $pts[1]['h'][4], 1e-9);
        $m = PvWeatherModel::fit($pts);
        self::assertEqualsWithDelta(5.0, $m['k_all'], 1e-9);
        foreach ($m['k'] as $k) self::assertEqualsWithDelta(5.0, $k, 1e-9, 'ohne eigene Daten das gemeinsame k');
        self::assertSame([0, 2, 4], $m['groups'], 'Jahreszeiten mit eigenen Daten');
        self::assertEqualsWithDelta(5.0 * 150.0, PvWeatherModel::predict($m, 7, 150.0, 31.0), 1e-6);
    }

    public function testASpanWithTooFewRadiationDaysOrBeforeTheBaselineIsLeftOut(): void
    {
        $temps = [];
        for ($d = 1; $d <= 31; $d++) $temps[sprintf('2024-01-%02d', $d)] = ['rad' => 1.0];
        for ($d = 1; $d <= 26; $d++) $temps[sprintf('2024-03-%02d', $d)] = ['rad' => 3.0];   // 26 von 31 Tagen < 90 %
        for ($d = 1; $d <= 29; $d++) $temps[sprintf('2024-05-%02d', $d)] = ['rad' => 5.0];   // 29 von 31: Rest aus dem Normal
        $spans = [['2024-01-01', '2024-02-01', 100.0], ['2024-03-01', '2024-04-01', 300.0], ['2024-05-01', '2024-06-01', 800.0]];
        $pts = PvWeatherModel::points($spans, $temps, fn(int $m): ?float => $m === 5 ? 4.0 : null);
        self::assertCount(2, $pts);
        self::assertEqualsWithDelta(29 * 5.0 + 2 * 4.0, $pts[1]['h'][4], 1e-9, 'fehlende Tage aus dem Normal');
        self::assertCount(1, PvWeatherModel::points($spans, $temps, fn(int $m): ?float => 4.0, ['2024-01' => true]), 'vor der Zäsur');
        self::assertCount(0, PvWeatherModel::points($spans, $temps, fn(int $m): ?float => 4.0, [], '2024-01-31'), 'nach dem Stichtag');
    }

    public function testDailyStandsAreJoinedToWeeks(): void
    {
        $temps = []; $spans = [];
        for ($d = 1; $d <= 14; $d++) {
            $day = sprintf('2024-06-%02d', $d);
            $temps[$day] = ['rad' => 5.0];
            $spans[] = [$day, sprintf('2024-06-%02d', $d + 1), 25.0];
        }
        $pts = PvWeatherModel::points($spans, $temps, fn(int $m): ?float => null);
        self::assertCount(2, $pts, 'zweimal sieben Tage');
        self::assertSame(7.0, $pts[0]['d']);
        self::assertEqualsWithDelta(175.0, $pts[0]['y'], 1e-9);
        self::assertEqualsWithDelta(365.25 / 12 / 7, $pts[0]['w'], 1e-9);
    }

    public function testFeedInWithOwnUseFindsKAndTheDailyOwnUse(): void
    {
        // Einspeisung = 4 × H − 3 kWh am Tag, Monatsabstände eines Jahres
        $temps = []; $spans = [];
        foreach (range(1, 12) as $m) {
            $dim = (int)date('t', mktime(0, 0, 0, $m, 1, 2024));
            for ($d = 1; $d <= $dim; $d++) $temps[sprintf('2024-%02d-%02d', $m, $d)] = ['rad' => self::RAD[$m]];
            $spans[] = [sprintf('2024-%02d-01', $m), date('Y-m-d', mktime(0, 0, 0, $m + 1, 1, 2024)), 4.0 * self::RAD[$m] * $dim - 3.0 * $dim];
        }
        $m = PvWeatherModel::fit(PvWeatherModel::points($spans, $temps, fn(int $mo): ?float => null), true);
        self::assertEqualsWithDelta(3.0, $m['e'], 1e-6);
        foreach ($m['k'] as $k) self::assertEqualsWithDelta(4.0, $k, 1e-6);
        self::assertSame(0.0, PvWeatherModel::predict($m, 12, 18.6, 31.0), 'im Dezember nie unter null: 4 × 18,6 − 3 × 31 < 0');
    }

    /** Doku (PV → Prognose nach dem Wetter): sagt, was ausgeliefert ist (Schalter), nicht, was gebaut ist. */
    public function testTheDocumentationStatesWhatIsSwitchedOn(): void
    {
        $root = dirname(__DIR__, 3);
        $want = [
            'docs/verstehen/12-pv.md'    => [PvWeatherModel::GEN_ENABLED ? 'Erzeugung folgt der Sonne' : 'Die Prognose von Erzeugung und Einspeisung folgt weiter dem Saisonprofil',
                                             PvWeatherModel::FEED_SALDO_ENABLED ? 'rechnet nach der Einstrahlung' : 'wie bisher'],
            'docs/en/verstehen/12-pv.md' => [PvWeatherModel::GEN_ENABLED ? 'Generation follows the sun' : 'The forecast of generation and feed-in still follows the seasonal profile',
                                             PvWeatherModel::FEED_SALDO_ENABLED ? 'is calculated by the solar radiation' : 'as before'],
        ];
        foreach ($want as $file => $phrases) {
            $text = (string)file_get_contents("$root/$file");
            $pos = strpos($text, '<!-- doc-claim: pv-weather -->');
            self::assertNotFalse($pos, "$file: Abschnitt fehlt");
            $part = (string)preg_replace('/\s+/u', ' ', substr($text, $pos, 1800));
            foreach ($phrases as $p) self::assertStringContainsString($p, $part, "$file: $p");
        }
    }

    // ── Prognose ────────────────────────────────────────────────────────

    public function testTheForecastIsKTimesTheNormalNotTheSunnyYearsAgain(): void
    {
        $this->weather();
        $meter = $this->meter('pv_erzeugung', 'm_pv', self::firsts('2023-01-01', '2025-01-01'),
            fn(int $m) => self::K[PvWeatherModel::GROUPS[$m]] * self::RAD[$m]);
        $fc = $this->forecast()->forMeter('pv_erzeugung', $meter, ['today' => '2025-01-15']);
        self::assertTrue($fc['valid']);
        $sum = 0.0; $expected = 0.0; $normalOnly = 0.0;
        foreach ($fc['forecast'] as $row) {
            // jeder Kalendermonat ist gemessen: zur Hälfte k × Normal, zur Hälfte der gemessene Monat
            $want = self::blended($row['month'], (int)date('t', strtotime($row['ym'] . '-01')));
            self::assertEqualsWithDelta($want, $row['kwh'], 0.01 * $want, "Monat {$row['ym']}: Mischung aus k × Normal und Messung");
            self::assertSame('pv_weather_blend', $row['method']);
            $sum += $row['kwh']; $expected += $want;
            $normalOnly += self::K[PvWeatherModel::GROUPS[$row['month']]] * self::normalSum($row['month']);
        }
        self::assertEqualsWithDelta($expected, $sum, 0.005 * $expected, 'Jahr auf ein halbes Prozent');
        self::assertSame([0, 1, 2, 3, 4], $fc['pv_model']['groups_measured']);
        self::assertSame(24, $fc['pv_model']['n']);
        self::assertSame(PvWeatherModel::PV_BLEND, $fc['pv_model']['blend']);
        self::assertNotContains('pv_no_weather', array_column($fc['warnings'], 'code'));

        // der alte Weg (Saisonprofil) wiederholt die gemessenen Jahre: 1 ÷ 0,9 über dem Modell, über der Mischung um
        // 1 ÷ (0,5 × 0,9 + 0,5)
        $old = $this->forecast()->forMeter('pv_erzeugung', $meter, ['today' => '2025-01-15', 'pv_weather' => false]);
        $oldSum = array_sum(array_column($old['forecast'], 'kwh'));
        self::assertEqualsWithDelta($normalOnly / self::NORMAL_SHARE, $oldSum, 0.005 * $oldSum);
        self::assertArrayNotHasKey('pv_model', $old);
        self::assertSame('seasonal_only', $old['forecast'][0]['method']);
    }

    public function testWithATrendInTheNormalTheForecastTakesItAndDoesNotBlend(): void
    {
        $this->weather();
        // Trend im Normal: jede Monatssumme 5 % über dem flachen Mittel
        $n = $this->store->read(ClimateNormalService::FILE, []);
        $n['rad_month_trend'] = array_map(fn($v) => round($v * 1.05, 2), $n['rad_month']);
        $n['rad_trend_factor'] = 1.05;
        $this->store->write(ClimateNormalService::FILE, $n);
        $meter = $this->meter('pv_erzeugung', 'm_pv', self::firsts('2023-01-01', '2025-01-01'),
            fn(int $m) => self::K[PvWeatherModel::GROUPS[$m]] * self::RAD[$m]);
        $fc = $this->forecast()->forMeter('pv_erzeugung', $meter, ['today' => '2025-01-15']);
        foreach ($fc['forecast'] as $row) {
            $want = self::K[PvWeatherModel::GROUPS[$row['month']]] * round(self::normalSum($row['month']) * 1.05, 2);
            self::assertEqualsWithDelta($want, $row['kwh'], 0.01 * $want, "{$row['ym']}: k × Normal mit Trend, ohne Mischung");
            self::assertSame('pv_weather', $row['method']);
        }
        self::assertSame(1.0, $fc['pv_model']['blend']);
        self::assertSame('linear_annual', $fc['pv_model']['normal_method']);
        // die Werkstatt rechnet dann ganz aufs Normal (mit Trend): Juni 2024 hatte je Tag RAD[6], das Normal 0,945 × davon
        self::assertEqualsWithDelta(round(self::normalSum(6) * 1.05, 2) / (self::RAD[6] * 30), $this->consumption->pvNormalFactors(['2024-06'])['2024-06'], 0.002);
        // die Einspeisung mischt nie
        self::assertSame(1.0, PvWeatherModel::blendWeight('pv_einspeisung', false));
        self::assertSame(PvWeatherModel::PV_BLEND, PvWeatherModel::blendWeight('pv_erzeugung', false));
    }

    public function testShippedStateKeepsTheSeasonalProfileWithAHint(): void
    {
        $this->consumption->setPvWeather(null, null);   // wie ausgeliefert
        $this->weather();
        $dates = self::firsts('2023-01-01', '2025-01-01');
        $gen = $this->meter('pv_erzeugung', 'm_gen', $dates, fn(int $m) => 5.0 * self::RAD[$m]);
        $feed = $this->meter('pv_einspeisung', 'm_feed', $dates, fn(int $m) => 3.0 * self::RAD[$m]);
        foreach (['pv_erzeugung' => $gen, 'pv_einspeisung' => $feed] as $u => $m) {
            $fc = $this->forecast()->forMeter($u, $m, ['today' => '2025-01-15']);
            $w = array_column($fc['warnings'], 'reason', 'code');
            self::assertSame(PvWeatherModel::enabled($u) ? null : 'not_validated', $w['pv_no_weather'] ?? null, $u);
            if (!PvWeatherModel::enabled($u)) {
                self::assertSame(['reason' => 'not_validated'], $fc['pv_model']);
                self::assertNotContains('pv_weather', array_column($fc['forecast'], 'method'));
                self::assertNotContains('pv_weather_blend', array_column($fc['forecast'], 'method'));
            }
        }
        // die Werkstatt rechnet das Grundjahr nur mit freigegebener Erzeugung auf normale Sonne
        self::assertSame(PvWeatherModel::GEN_ENABLED, $this->consumption->pvNormalFactors(['2024-06']) !== null);
    }

    public function testTwoReadingsAYearApartGiveOneKForTheWholeYear(): void
    {
        $this->weather();
        $meter = $this->meter('pv_erzeugung', 'm_pv', ['2024-01-01', '2025-01-01'], fn(int $m) => self::K[PvWeatherModel::GROUPS[$m]] * self::RAD[$m]);
        $fc = $this->forecast()->forMeter('pv_erzeugung', $meter, ['today' => '2025-01-15']);
        // ein Abstand: k = Erzeugung ÷ Einstrahlung des Jahres (2024 mit 29 Februartagen), für jeden Monat dasselbe
        $gen = 0.0; $rad = 0.0;
        foreach (range(1, 12) as $m) {
            $dim = (int)date('t', mktime(0, 0, 0, $m, 1, 2024));
            $gen += self::K[PvWeatherModel::GROUPS[$m]] * self::RAD[$m] * $dim;
            $rad += self::RAD[$m] * $dim;
        }
        $k = $gen / $rad;
        self::assertEqualsWithDelta($k, $fc['pv_model']['k_all'], 1e-4);
        foreach ($fc['forecast'] as $row) self::assertEqualsWithDelta($k * self::normalSum($row['month']), $row['kwh'], 0.06, $row['ym']);
        self::assertNull($fc['annual']['low'], 'ein Abstand trägt keine Streuung');
        self::assertSame('pv_few_points', $fc['annual']['withheld']);
    }

    public function testBelowSixMonthsWithoutRadiationOrWithoutANormalTheSeasonalProfileStaysAndSaysWhy(): void
    {
        $gen = fn(int $m) => 5.0 * self::RAD[$m];
        $this->weather();
        // 01.01. bis 28.06.: 179 Tage, sechs Monatszeilen (die Prognose gilt), aber unter den 182 Tagen des Modells
        $short = $this->meter('pv_erzeugung', 'm_pv', [...self::firsts('2024-01-01', '2024-06-01'), '2024-06-28'], $gen);
        $codes = fn(array $fc): array => array_column($fc['warnings'], 'reason', 'code');
        $fc = $this->forecast()->forMeter('pv_erzeugung', $short, ['today' => '2024-07-15']);
        self::assertTrue($fc['valid']);
        self::assertSame('few_measured', $codes($fc)['pv_no_weather'] ?? null);
        self::assertSame(['reason' => 'few_measured'], $fc['pv_model']);
        self::assertNotContains('pv_weather', array_column($fc['forecast'], 'method'));
        // drei Tage mehr: 182 Tage, das Modell trägt
        $enough = $this->meter('pv_erzeugung', 'm_pv', [...self::firsts('2024-01-01', '2024-06-01'), '2024-07-01'], $gen);
        self::assertSame('pv_weather', $this->forecast()->forMeter('pv_erzeugung', $enough, ['today' => '2024-07-15'])['forecast'][0]['method']);

        $meter = $this->meter('pv_erzeugung', 'm_pv', self::firsts('2023-01-01', '2025-01-01'), $gen);
        $this->weather(false);
        $fc = $this->forecast()->forMeter('pv_erzeugung', $meter, ['today' => '2025-01-15']);
        self::assertSame('no_radiation', $codes($fc)['pv_no_weather']);
        self::assertSame('seasonal_only', $fc['forecast'][0]['method']);

        $this->weather(true, false);
        $fc = $this->forecast()->forMeter('pv_erzeugung', $meter, ['today' => '2025-01-15']);
        self::assertSame('no_normal', $codes($fc)['pv_no_weather']);
    }

    public function testBatteryMetersAndOtherUtilitiesStayAsTheyWere(): void
    {
        $this->weather();
        $charge = $this->meter('pv_erzeugung', 'm_bat', self::firsts('2023-01-01', '2025-01-01'), fn(int $m) => 2.0 * self::RAD[$m], ['role' => 'battery_charge']);
        $fc = $this->forecast()->forMeter('pv_erzeugung', $charge, ['today' => '2025-01-15']);
        self::assertArrayNotHasKey('pv_model', $fc, 'Speicher: Paket J');
        self::assertNotContains('pv_no_weather', array_column($fc['warnings'], 'code'));
        $strom = $this->meter('strom', 'm_strom', self::firsts('2023-01-01', '2025-01-01'), fn(int $m) => 9.0);
        self::assertArrayNotHasKey('pv_model', $this->forecast()->forMeter('strom', $strom, ['today' => '2025-01-15']));
    }

    public function testTheMonthBandCarriesTheWeatherOfTheNormal(): void
    {
        $this->weather();
        $meter = $this->meter('pv_erzeugung', 'm_pv', self::firsts('2023-01-01', '2025-01-01'), fn(int $m) => 5.0 * self::RAD[$m]);
        $fc = $this->forecast()->forMeter('pv_erzeugung', $meter, ['today' => '2025-01-15']);
        $jun = array_values(array_filter($fc['forecast'], fn($r) => $r['month'] === 6))[0];
        // ohne Modellrauschen: halbe Breite = 1,28 × k × Streuung des Normals (8 % der Monatssumme)
        $half = 1.28 * 5.0 * round(0.08 * self::normalSum(6), 2);
        self::assertEqualsWithDelta($jun['kwh'] - $half, $jun['band_low'], 0.15);
        self::assertEqualsWithDelta($jun['kwh'] + $half, $jun['band_high'], 0.15);
        // Jahr: 1,28 × k × Streuung der Jahressumme (60 kWh/m²)
        self::assertEqualsWithDelta(1.28 * 5.0 * 60.0, $fc['annual']['high'] - $fc['annual']['value'], 0.2);
    }

    // ── Werkstatt ───────────────────────────────────────────────────────

    public function testTheWorkshopScalesTheSunnyBaseYearToNormalSun(): void
    {
        $this->weather();
        $dates = self::firsts('2024-01-01', '2025-01-01');
        $this->meter('pv_erzeugung', 'm_gen', $dates, fn(int $m) => 5.0 * self::RAD[$m]);
        $this->meter('pv_einspeisung', 'm_feed', $dates, fn(int $m) => 3.0 * self::RAD[$m]);
        $this->meter('strom', 'm_strom', $dates, fn(int $m) => 8.0);
        $forecast = $this->forecast();
        $svc = new \Energietracker\Services\ScenarioService($forecast, $this->consumption, $this->contracts, $this->meters, $this->settings,
            new \Energietracker\Services\TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n));
        $r = $svc->compute(['utility' => 'pv_erzeugung', 'meter_id' => 'm_gen'], '2025-01-15');
        self::assertTrue($r['valid']);
        // jeder Monat 2024 war 1 ÷ 0,9 so sonnig wie das Normal: Faktor 0,9 (Februar 2024 mit 29 Tagen wie jeder
        // andere), wie die Prognose zur Hälfte (PV_BLEND): 1 + 0,5 × (0,9 − 1) = 0,95
        $f = 1 + PvWeatherModel::PV_BLEND * (0.9 - 1);
        self::assertEqualsWithDelta($f, $r['pv']['normal']['gen_factor'], 1e-6);
        $measured = 0.0;
        foreach (range(1, 12) as $m) $measured += 5.0 * self::RAD[$m] * (int)date('t', mktime(0, 0, 0, $m, 1, 2024));
        self::assertEqualsWithDelta($f * $measured, $r['pv']['base']['generation'], 0.5);
        $w = array_column($r['warnings'], null, 'code');
        self::assertEqualsWithDelta(1 - $f, $w['pv_normal_down']['ratio'] ?? null, 1e-4);
    }

    // ── Einspeise-Saldo ─────────────────────────────────────────────────

    public function testTheFeedInBalanceCountsTheGapAndTheRestOfTheYearByTheSun(): void
    {
        $this->weather();
        // gemessen bis 01.06.2025; danach: Archiv bis 20.08. mit doppelter Junisonne im Juli, ab 21.08. das Normal
        $temps = $this->store->read('temperatures.json', []);
        foreach ($temps as $d => &$t) {
            if ($d >= '2025-07-01' && $d <= '2025-07-31') $t['rad'] = 8.0;
            if ($d > '2025-08-20') unset($t['rad']);
        }
        unset($t);
        $this->store->write('temperatures.json', $temps);
        $feed = fn(int $m) => 3.0 * self::RAD[$m];
        $meter = $this->meter('pv_einspeisung', 'm_feed', self::firsts('2023-01-01', '2025-06-01'), $feed);
        $this->contracts->create('pv_einspeisung', ['meter_id' => 'm_feed', 'provider' => 'Netz Musterstadt', 'tariff_name' => 'Vergütung',
            'start' => '2023-01-01', 'end' => null, 'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 10.0]]]);
        $row = $this->consumption->contractStatus('pv_einspeisung', $meter, '2025-08-15')['contracts'][0];
        self::assertSame('forecast', $row['projection_method']);
        self::assertNull($row['projection_band'], 'keine Breite: ihre Sätze sprechen von Nachzahlung und Guthaben eines Bezugsvertrags');
        self::assertNull($row['projection_uncertain']);
        // von Hand: Januar bis Mai gemessen, Juni bis Dezember je Tag 3 × Einstrahlung (Archiv, sonst Normal ÷ Tage)
        $kwh = 0.0;
        for ($t = strtotime('2025-01-01 12:00'); $t <= strtotime('2025-12-31 12:00'); $t += 86400) {
            $d = date('Y-m-d', $t); $m = (int)date('n', $t);
            $rad = $d <= '2025-08-20' ? ($d >= '2025-07-01' && $d <= '2025-07-31' ? 8.0 : self::RAD[$m]) : self::normalSum($m) / ($m === 2 ? 28.25 : (int)date('t', $t));
            $kwh += 3.0 * $rad;
        }
        self::assertEqualsWithDelta($kwh * 0.10, $row['projected_end_balance'], 0.5);
        self::assertSame('payout', $row['verdict']);
        // bis heute: gemessen bis 31.05., dann geschätzt bis 14.08. (Juli mit der Sonne aus dem Archiv)
        $toDate = 0.0;
        for ($t = strtotime('2025-01-01 12:00'); $t < strtotime('2025-08-15 12:00'); $t += 86400) {
            $d = date('Y-m-d', $t); $m = (int)date('n', $t);
            $toDate += 3.0 * ($d >= '2025-07-01' && $d <= '2025-07-31' ? 8.0 : self::RAD[$m]);
        }
        self::assertEqualsWithDelta($toDate * 0.10, $row['current_balance'], 0.5);
    }

    public function testFeedInLearnsFromTheLastTwelveMonthsOnly(): void
    {
        // bis Ende 2023 4 × Einstrahlung eingespeist, ab 2024 nur noch 3 × (neuer Speicher): k′ aus 2024
        $this->weather();
        $meter = $this->meter('pv_einspeisung', 'm_feed', self::firsts('2023-01-01', '2025-01-01'),
            fn(int $m) => 0.0);
        $rows = []; $c = 0.0; $prev = null;
        foreach (self::firsts('2023-01-01', '2025-01-01') as $i => $d) {
            if ($prev !== null) for ($t = strtotime($prev . ' 12:00'); date('Y-m-d', $t) < $d; $t += 86400) $c += (date('Y', $t) === '2023' ? 4.0 : 3.0) * self::RAD[(int)date('n', $t)];
            $rows[] = ['id' => sprintf('r_%03d', $i), 'meter_id' => 'm_feed', 'date' => $d, 'counter' => round($c, 3), 'device_id' => 'd1',
                       'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            $prev = $d;
        }
        $this->store->write('pv_einspeisung/readings.json', $rows);
        $m = $this->consumption->atDate('2025-01-15', fn() => $this->consumption->pvWeatherModel('pv_einspeisung', $meter,
            $this->consumption->forMeter('pv_einspeisung', $meter))['model']);
        self::assertEqualsWithDelta(3.0, $m['k_all'], 1e-6, 'nur die jüngsten 365 Tage');
        self::assertSame(12, $m['n']);
        self::assertSame(0.0, $m['e'], 'ohne Eigenverbrauch je Tag (FEED_BASE aus)');
        // die Erzeugung nimmt alle Abstände
        $gen = $this->meter('pv_erzeugung', 'm_gen', self::firsts('2023-01-01', '2025-01-01'), fn(int $mo) => 5.0 * self::RAD[$mo]);
        $g = $this->consumption->atDate('2025-01-15', fn() => $this->consumption->pvWeatherModel('pv_erzeugung', $gen,
            $this->consumption->forMeter('pv_erzeugung', $gen))['model']);
        self::assertSame(24, $g['n']);
    }

    public function testWithoutAClimateNormalTheFeedInBalanceUsesTheOwnRadiation(): void
    {
        // kein Normal mit Einstrahlung (Demo, Abruf gescheitert): der Rest des Jahres aus dem Mittel der eigenen Tage
        $this->weather(true, false);
        $temps = $this->store->read('temperatures.json', []);
        foreach ($temps as $d => &$t) if ($d > '2025-08-20') unset($t['rad']);
        unset($t);
        $this->store->write('temperatures.json', $temps);
        $meter = $this->meter('pv_einspeisung', 'm_feed', self::firsts('2023-01-01', '2025-06-01'), fn(int $m) => 3.0 * self::RAD[$m]);
        $this->contracts->create('pv_einspeisung', ['meter_id' => 'm_feed', 'provider' => 'Netz Musterstadt', 'tariff_name' => 'Vergütung',
            'start' => '2023-01-01', 'end' => null, 'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 10.0]]]);
        $row = $this->consumption->contractStatus('pv_einspeisung', $meter, '2025-08-15')['contracts'][0];
        self::assertSame('forecast', $row['projection_method']);
        // die eigenen Tage haben je Monat dieselbe Einstrahlung: Rest des Jahres = 3 × RAD je Tag
        $kwh = 0.0;
        for ($t = strtotime('2025-01-01 12:00'); $t <= strtotime('2025-12-31 12:00'); $t += 86400) $kwh += 3.0 * self::RAD[(int)date('n', $t)];
        self::assertEqualsWithDelta($kwh * 0.10, $row['projected_end_balance'], 0.5);
        self::assertSame(['reason' => 'no_normal'], $this->forecast()->forMeter('pv_einspeisung', $meter, ['today' => '2025-08-15'])['pv_model'],
            'die Prognose verlangt das Normal');
    }

    public function testWithoutTheWeatherModelTheFeedInBalanceStaysTheFlatAverage(): void
    {
        $this->weather(false);
        $meter = $this->meter('pv_einspeisung', 'm_feed', self::firsts('2023-01-01', '2025-06-01'), fn(int $m) => 3.0 * self::RAD[$m]);
        $this->contracts->create('pv_einspeisung', ['meter_id' => 'm_feed', 'provider' => 'Netz Musterstadt', 'tariff_name' => 'Vergütung',
            'start' => '2023-01-01', 'end' => null, 'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 10.0]]]);
        $row = $this->consumption->contractStatus('pv_einspeisung', $meter, '2025-08-15')['contracts'][0];
        self::assertSame('flat_average', $row['projection_method'], 'ohne Einstrahlung wie bisher');
        self::assertArrayNotHasKey('cost_to_date', $row);
    }
}
