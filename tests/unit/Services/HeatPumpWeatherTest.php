<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ClimateNormalService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\HeatPumpWeather;
use Energietracker\Services\UtilityWeather;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket waermepumpe) — Wärmepumpe nach dem Wetter an von Hand gerechneten Kleinfällen.
 *
 * Erfundener Standort ohne Rauschen: Tagesmittel 9 − 9 × cos(Jahresgang, kältester Tag 20. Januar), dazu je Jahr ein
 * Versatz (2022 kalt − 2,5 K, nur im Klimanormal; die gemessenen Jahre 2023 und 2024 mild + 1,5 K und + 1 K). Die
 * Wärmepumpe braucht je Tag A × max(0, LIMIT − T) + C kWh, also
 * genau das lineare Modell zur Heizgrenze LIMIT. Damit ist bekannt, was herauskommen muss: a = A, c = C, die
 * Heizgrenze LIMIT (die Rechnung findet sie selbst), je Monat a × Gradtage des Normals + c × Tage. Der alte Weg
 * (Saisonprofil) wiederholt die gemessenen Jahre. Alle Tage fest, kein „heute“.
 */
#[CoversClass(HeatPumpWeather::class)]
#[CoversClass(UtilityWeather::class)]
#[CoversClass(ForecastService::class)]
final class HeatPumpWeatherTest extends ServiceTestCase
{
    private const A = 1.5;
    private const C = 2.0;
    private const LIMIT = 12.0;
    private const OFFSET = [2022 => -2.5, 2023 => 1.5, 2024 => 1.0, 2025 => 0.0];
    private const TODAY = '2025-07-01';
    /** E2 — Periode eines Normals mit Trend (dreißig Jahre bis zum Vorjahr von heute, nicht veraltet) */
    private const THIRTY = ['from' => '1996-01-01', 'to' => '2025-12-31'];

    protected function setUp(): void
    {
        parent::setUp();
        // die Rechnung selbst eingeschaltet prüfen; den Auslieferungszustand testShippedSwitches
        $this->consumption->setHeatPumpWeather(true, true, true);
        $this->weather();
    }

    private function forecast(): ForecastService
    {
        return new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
    }

    private static function temp(int $t): float
    {
        $doy = (int)date('z', $t) + 1;
        return 9.0 - 9.0 * cos(2 * M_PI * ($doy - 20) / 365.25) + self::OFFSET[(int)date('Y', $t)];
    }

    /** Verbrauch der Wärmepumpe an einem Tag (kWh). */
    private static function wp(int $t): float
    {
        return self::A * max(0.0, self::LIMIT - self::temp($t)) + self::C;
    }

    private function weather(bool $normal = true): void
    {
        $temps = []; $means = [];
        for ($t = strtotime('2022-01-01 12:00'); $t <= strtotime('2025-12-31 12:00'); $t += 86400) {
            $v = round(self::temp($t), 3);
            $temps[date('Y-m-d', $t)] = ['avg' => $v, 'min' => $v - 4, 'max' => $v + 4, 'source' => 'archive'];
            $means[date('Y-m-d', $t)] = $v;
        }
        $this->store->write('temperatures.json', $temps);
        if ($normal) $this->store->write(ClimateNormalService::FILE, ClimateNormalService::compute($means, 51.0, 10.0, ['from' => '2022-01-01', 'to' => '2025-12-31']));
        $this->settings->set(['active_utilities' => ['strom'], 'latitude' => 51.0, 'longitude' => 10.0]);
    }

    /**
     * Zähler mit Ablesungen an den angegebenen Tagen; je Tag $perDay(Zeitstempel) kWh.
     *
     * @param list<array{0:array<string,mixed>,1:callable(int):float}> $spec [Zähler, Verbrauch je Tag]
     * @param list<string> $dates
     */
    private function meters(array $spec, array $dates, string $utility = 'strom'): void
    {
        $meters = []; $rows = [];
        foreach ($spec as [$m, $perDay]) {
            $m += ['name' => (string)$m['id'], 'icon' => '', 'created_at' => $dates[0], 'active' => true, 'notes' => '',
                   'parent_meter_id' => null, 'meter_group_id' => null,
                   'devices' => [['id' => 'd_' . $m['id'], 'serial' => null, 'installed_on' => $dates[0], 'initial_counter' => 0.0,
                                  'removed_on' => null, 'final_counter' => null, 'reason' => null]]];
            $meters[] = $m;
            $c = 0.0; $prev = null;
            foreach ($dates as $i => $d) {
                if ($prev !== null) for ($t = strtotime($prev . ' 12:00'); date('Y-m-d', $t) < $d; $t += 86400) $c += $perDay($t);
                $rows[] = ['id' => sprintf('r_%s_%03d', $m['id'], $i), 'meter_id' => $m['id'], 'date' => $d, 'counter' => round($c, 3),
                           'device_id' => 'd_' . $m['id'], 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
                $prev = $d;
            }
        }
        $this->store->write("$utility/meters.json", $meters);
        $this->store->write("$utility/readings.json", $rows);
    }

    /** @return list<string> Monatserste von $from bis $to */
    private static function firsts(string $from, string $to, int $step = 1): array
    {
        $out = [];
        for ($t = strtotime($from); date('Y-m-d', $t) <= $to; $t = strtotime("+$step month", $t)) $out[] = date('Y-m-d', $t);
        return $out;
    }

    /** Summe des Verbrauchs eines Kalendermonats (kWh). */
    private static function monthSum(string $ym, callable $perDay): float
    {
        $s = 0.0;
        for ($t = strtotime("$ym-01 12:00"); date('Y-m', $t) === $ym; $t += 86400) $s += $perDay($t);
        return $s;
    }

    private static function row(array $fc, string $ym): array
    {
        foreach ($fc['forecast'] as $r) if ($r['ym'] === $ym) return $r;
        self::fail("Monat $ym fehlt");
    }

    private function wpMeter(array $extra = []): array
    {
        $this->meters([[['id' => 'wp', 'role' => 'heat_pump'] + $extra, fn(int $t) => self::wp($t)]], self::firsts('2023-01-01', self::TODAY));
        return $this->meters->get('strom', 'wp');
    }

    // ── Auslieferung ────────────────────────────────────────────────────

    public function testShippedSwitches(): void
    {
        $this->consumption->setHeatPumpWeather(null, null, null);
        $m = $this->wpMeter();
        $fc = $this->forecast()->forMeter('strom', $m, ['today' => self::TODAY]);
        self::assertSame(HeatPumpWeather::FORECAST_ENABLED, $fc['heat_pump_weather']['on']);
        $codes = array_column($fc['warnings'], 'reason', 'code');
        if (!HeatPumpWeather::FORECAST_ENABLED) {
            self::assertSame('not_validated', $codes['heat_pump_no_weather'] ?? null, 'der alte Weg sagt, warum');
            self::assertNull($fc['heat_model'], 'Strom ohne Heizmodell wie bis v3.12');
            self::assertSame('seasonal_only', self::row($fc, '2026-01')['method']);
        }
        $cs = $this->consumption->contractStatus('strom', $m, self::TODAY);
        self::assertSame(HeatPumpWeather::SALDO_ENABLED, $cs['heat_pump_weather']['on']);
        if (!HeatPumpWeather::SALDO_ENABLED) self::assertSame('not_validated', $cs['heat_pump_weather']['reason']);
    }

    // ── Prognose ────────────────────────────────────────────────────────

    public function testTheHeatPumpForecastFollowsTheWeatherAndFindsItsHeatLimit(): void
    {
        $m = $this->wpMeter();
        $fc = $this->forecast()->forMeter('strom', $m, ['today' => self::TODAY]);
        self::assertTrue($fc['heat_pump_weather']['on']);
        self::assertSame(UtilityWeather::KIND_HEAT_PUMP, $fc['heat_pump_weather']['kind']);
        self::assertNotContains('heat_pump_no_weather', array_column($fc['warnings'], 'code'));
        self::assertSame(self::LIMIT, $fc['hdd_base'], 'ohne eigene Heizgrenze die, mit der das Modell passt');
        self::assertEqualsWithDelta(self::A, $fc['heat_model']['a'], 1e-3);
        self::assertEqualsWithDelta(self::C, $fc['heat_model']['c'], 1e-3);
        self::assertSame('good', $fc['fit_quality']['model_quality']);

        // Januar: Mischung aus Heizmodell (Normal zur Heizgrenze 12 °C, Kalibrierung 1) und Saisonprofil (Mittel der Januare)
        $hddN = $this->consumption->hddNormal(1, self::LIMIT)['mean'];
        $model = self::A * $hddN + self::C * 31;
        $seasonal = (self::monthSum('2023-01', fn($t) => self::wp($t)) + self::monthSum('2024-01', fn($t) => self::wp($t))
            + self::monthSum('2025-01', fn($t) => self::wp($t))) / 3;
        $w = (float)$fc['blend_weight'];
        self::assertGreaterThan(0.7, $w);
        $jan = self::row($fc, '2026-01');
        self::assertEqualsWithDelta($w * $model + (1 - $w) * $seasonal, $jan['kwh'], 0.15);
        self::assertNotNull($jan['band_low'], 'Band aus dem Heizmodell');
        self::assertEqualsWithDelta(self::A * $hddN / $model, $jan['heat_share'], 1e-3);
        // Juli: kein Heizbedarf, Grundlast
        self::assertEqualsWithDelta(self::C * 31, self::row($fc, '2025-07')['kwh'], 0.15);
        // der alte Weg zum Vergleich: nur das Saisonprofil (ohne Wetterbezug ab drei Jahren der Median)
        $this->consumption->setHeatPumpWeather(false, false, false);
        $old = $this->forecast()->forMeter('strom', $m, ['today' => self::TODAY]);
        $jans = [self::monthSum('2023-01', fn($t) => self::wp($t)), self::monthSum('2024-01', fn($t) => self::wp($t)), self::monthSum('2025-01', fn($t) => self::wp($t))];
        sort($jans);
        self::assertEqualsWithDelta($jans[1], self::row($old, '2026-01')['kwh'], 0.15);
        self::assertNull($old['heat_model']);
    }

    /**
     * E2 — Das Normal der Wärmepumpe rechnet mit dem Trend der Temperatur: Heizgradtage der Heizgrenze − ΔT. Gas
     * behält das flache Mittel (byte-gleich), auch wenn das Normal den Trend trägt.
     */
    public function testTheHeatPumpNormalFollowsTheTemperatureTrendGasKeepsTheFlatOne(): void
    {
        $m = $this->wpMeter();
        $n = $this->store->read(ClimateNormalService::FILE, []);
        // ein Trend gehört zu dreißig Jahren (die Gradtage des Testnormals bleiben die vier erfundenen Jahre)
        $this->store->write(ClimateNormalService::FILE, ['temp_trend' => 1.0, 'period' => self::THIRTY] + $n);
        $fc = $this->forecast()->forMeter('strom', $m, ['today' => self::TODAY]);
        self::assertSame(1.0, $fc['heat_pump_weather']['normal_shift']);
        self::assertEqualsWithDelta($this->consumption->hddNormal(1, self::LIMIT - 1.0)['mean'], self::row($fc, '2026-01')['hdd_estimated'], 0.051,
            'Januar: Gradtage der Heizgrenze 11 °C statt 12 °C');
        self::assertLessThan($this->consumption->hddNormal(1, self::LIMIT)['mean'], self::row($fc, '2026-01')['hdd_estimated']);
        // die Schätzung je Tag der Saldo-Karte nimmt dasselbe Normal (Januar 2026 ohne Tageswerte)
        $est = $this->consumption->atDate(self::TODAY, fn() => $this->consumption->estimatedMonths('strom', $m, ['2026-01']));
        self::assertEqualsWithDelta(self::A * $this->consumption->hddNormal(1, self::LIMIT - 1.0)['mean'] + self::C * 31, $est['2026-01'], 0.3);
        // Gas mit demselben Normal: flach (die Heizgrenze des Gases bleibt 15 °C, kein Versatz)
        $this->meters([[['id' => 'g'], fn(int $t) => 2.0 * max(0.0, 15.0 - self::temp($t)) + 5.0]], self::firsts('2023-01-01', self::TODAY), 'gas');
        $this->settings->set(['active_utilities' => ['strom', 'gas']]);
        $gas = $this->forecast()->forMeter('gas', $this->meters->get('gas', 'g'), ['today' => self::TODAY]);
        self::assertArrayNotHasKey('heat_pump_weather', $gas);
        self::assertEqualsWithDelta($this->consumption->hddNormal(1, 15.0)['mean'], self::row($gas, '2026-01')['hdd_estimated'], 0.051);
        // ein Normal von vor E2 (ohne Merker) gilt als zu ergänzen: derselbe Abruf, nur die Zusätze (lacksOnlyRadiation)
        $climate = $this->consumption->climate();
        self::assertFalse($climate->isStale(51.0, 10.0, 2026), 'mit Merker aktuell');
        $old = $this->store->read(ClimateNormalService::FILE, []);
        unset($old['temp_trend']);
        $this->store->write(ClimateNormalService::FILE, $old);
        self::assertTrue($climate->isStale(51.0, 10.0, 2026));
        self::assertTrue($climate->lacksOnlyRadiation(51.0, 10.0, 2026));
        self::assertNull($climate->tempTrend());
    }

    public function testAnOwnHeatLimitAtTheMeterAlwaysWins(): void
    {
        $m = $this->wpMeter(['hdd_base_temp' => 15.0]);
        $fc = $this->forecast()->forMeter('strom', $m, ['today' => self::TODAY]);
        self::assertSame(15.0, $fc['hdd_base']);
        // gefittet mit den Gradtagen zu 15 °C, nicht zu der Grenze, die die Rechnung selbst gewählt hätte
        $at15 = $this->consumption->atDate(self::TODAY, fn() => $this->consumption->heatModel('strom',
            $this->consumption->weatherSeries('strom', $m, $this->consumption->forMeter('strom', $m)), $m, null, true));
        self::assertEqualsWithDelta($at15['a'], $fc['heat_model']['a'], 1e-6);
        self::assertGreaterThan(0.05, abs($fc['heat_model']['a'] - self::A), 'zu 15 °C passt die Gerade nicht genau');
    }

    public function testTheRestOfACascadeIsModelledNotTheFrontMeter(): void
    {
        // Z1 vorn (Rolle Wärmepumpe) misst Wärmepumpe und Haushalt (3 kWh am Tag), Z2 dahinter den Haushalt mit eigenem Vertrag
        $this->meters([
            [['id' => 'z1', 'role' => 'heat_pump'], fn(int $t) => self::wp($t) + 3.0],
            [['id' => 'z2', 'parent_meter_id' => 'z1'], fn(int $t) => 3.0],
        ], self::firsts('2023-01-01', self::TODAY));
        $this->contracts->create('strom', ['meter_id' => 'z1', 'provider' => 'Testversorger', 'start' => '2023-01-01', 'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 25.0]]]);
        $this->contracts->create('strom', ['meter_id' => 'z2', 'provider' => 'Testversorger', 'start' => '2023-01-01', 'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 30.0]]]);
        $z1 = $this->meters->get('strom', 'z1');
        $fc = $this->forecast()->forMeter('strom', $z1, ['today' => self::TODAY]);
        self::assertSame(UtilityWeather::KIND_CASCADE, $fc['heat_pump_weather']['kind']);
        self::assertTrue($fc['heat_pump_weather']['on']);
        self::assertEqualsWithDelta(self::C, $fc['heat_model']['c'], 1e-3, 'Grundlast der Wärmepumpe, nicht von Z1 gesamt (5 kWh)');
        self::assertEqualsWithDelta(self::A, $fc['heat_model']['a'], 1e-3);
        $jul = self::row($fc, '2025-07');
        self::assertEqualsWithDelta(self::C * 31, $jul['billed_kwh'], 0.15, 'der Vertrag von Z1 rechnet den Rest');
        self::assertEqualsWithDelta($jul['kwh'] - $jul['deducted_kwh'], $jul['billed_kwh'], 0.11, 'gemessen = Rest + Abzug');
        self::assertEqualsWithDelta(3.0 * 31, $jul['deducted_kwh'], 0.15);
        // der Haushalt dahinter rechnet wie bisher
        self::assertArrayNotHasKey('heat_pump_weather', $this->forecast()->forMeter('strom', $this->meters->get('strom', 'z2'), ['today' => self::TODAY]));
    }

    /**
     * Kaskade mit HT/NT wie #40 und Gruppenvertrag mit Preis je Zählwerk (Paket vertrag): Die Gruppe ist die Summe ihrer
     * Zählwerke, jedes rechnet seinen Rest selbst nach dem Wetter. Der Abzug des Haushalts kommt nicht noch einmal dazu.
     */
    public function testATwoRateCascadeGroupStaysTheSumOfItsRegisters(): void
    {
        // NT-Anteil der Wärmepumpe je Jahreszeit verschieden (Winter 30 %, sonst 50 %): Die Zählwerke für sich folgen dann
        // keiner Geraden, die Gruppe schon (E2: das Modell der Gruppe gilt, die Zählwerke nehmen ihren Anteil)
        $nt = fn(int $t) => in_array((int)date('n', $t), [11, 12, 1, 2, 3], true) ? 0.3 : 0.5;
        $this->meters([
            [['id' => 'z1_ht', 'role' => 'heat_pump', 'meter_group_id' => 'g1'], fn(int $t) => (1 - $nt($t)) * self::wp($t) + 0.7 * 3.0],
            [['id' => 'z1_nt', 'role' => 'heat_pump', 'meter_group_id' => 'g1'], fn(int $t) => $nt($t) * self::wp($t) + 0.3 * 3.0],
            [['id' => 'z2_ht', 'parent_meter_id' => 'z1_ht', 'meter_group_id' => 'g2'], fn(int $t) => 0.7 * 3.0],
            [['id' => 'z2_nt', 'parent_meter_id' => 'z1_nt', 'meter_group_id' => 'g2'], fn(int $t) => 0.3 * 3.0],
        ], self::firsts('2023-01-01', self::TODAY));
        $this->store->write('strom/meter_groups.json', [['id' => 'g1', 'name' => 'Vorn', 'created_at' => '2023-01-01'], ['id' => 'g2', 'name' => 'Haushalt', 'created_at' => '2023-01-01']]);
        foreach (['g1' => [27.0, 21.0, 'z1_nt'], 'g2' => [33.0, 26.0, 'z2_nt']] as $g => [$ht, $nt, $ntId]) {
            $this->contracts->create('strom', ['meter_group_id' => $g, 'provider' => 'Testversorger', 'tariff_name' => 'HT/NT', 'start' => '2023-01-01',
                'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => $ht]],
                'working_prices_by_meter' => [$ntId => [['from' => '2023-01-01', 'ct_per_kwh' => $nt]]],
                'base_prices' => [['from' => '2023-01-01', 'eur_per_month' => 10.0]]]);
        }
        $g1 = $this->meters->target('strom', 'g1');
        $fc = $this->forecast()->forMeter('strom', $g1, ['today' => self::TODAY]);
        self::assertSame(UtilityWeather::KIND_CASCADE, $fc['heat_pump_weather']['kind']);
        self::assertTrue($fc['heat_pump_weather']['on']);
        $ht = $this->forecast()->forMeter('strom', $this->meters->get('strom', 'z1_ht'), ['today' => self::TODAY]);
        $nt = $this->forecast()->forMeter('strom', $this->meters->get('strom', 'z1_nt'), ['today' => self::TODAY]);
        foreach (['2025-07', '2026-01'] as $ym) {
            $r = self::row($fc, $ym);
            self::assertEqualsWithDelta(self::row($ht, $ym)['kwh'] + self::row($nt, $ym)['kwh'], $r['kwh'], 1e-6, "$ym: Gruppe = HT + NT");
            self::assertEqualsWithDelta($r['kwh'] - $r['deducted_kwh'], $r['billed_kwh'], 0.15, "$ym: gemessen = Rest + Abzug");
            self::assertEqualsWithDelta(3.0 * (int)date('t', strtotime("$ym-01")), $r['deducted_kwh'], 0.2, "$ym: Abzug = Haushalt");
            // E2: Das Zählwerk nimmt den Anteil seines Kalendermonats am Rest der Gruppe (NT im Januar 30 %, im Juli 50 %)
            $share = $ym === '2026-01' ? 0.3 : 0.5;
            self::assertEqualsWithDelta($share * $r['billed_kwh'], self::row($nt, $ym)['billed_kwh'], 0.11, "$ym: NT = Anteil am Rest der Gruppe");
        }
        self::assertEqualsWithDelta(self::C * 31, self::row($fc, '2025-07')['billed_kwh'], 0.3, 'Juli: der Rest ist die Grundlast der Wärmepumpe');
        // das Modell der Gruppe (eine Gerade über den ganzen Rest), nicht die Summe zweier Modelle der Zählwerke
        self::assertEqualsWithDelta(self::A, $fc['heat_model']['a'], 1e-3);
        $hddN = $this->consumption->hddNormal(1, (float)$fc['hdd_base'])['mean'];
        self::assertGreaterThan(0.0, $hddN);
    }

    /**
     * E2 — Ein Zählwerk unter dem Mindestmaß (NT nur im Dezember und Januar über seinem Haushalt: fünf Punkte), die Gruppe
     * besteht es: Das Zählwerk nimmt trotzdem seinen Anteil am Modell der Gruppe, sonst wäre die Gruppe die Summe der
     * Anteile und das Zählwerk nach dem alten Weg (Gruppe ≠ HT + NT).
     */
    public function testARegisterBelowTheMinimumStillTakesItsShareOfTheGroup(): void
    {
        $nt = fn(int $t) => in_array((int)date('n', $t), [12, 1], true) ? 0.3 : 0.0;
        $this->meters([
            [['id' => 'z1_ht', 'role' => 'heat_pump', 'meter_group_id' => 'g1'], fn(int $t) => (1 - $nt($t)) * self::wp($t) + 2.1],
            [['id' => 'z1_nt', 'role' => 'heat_pump', 'meter_group_id' => 'g1'], fn(int $t) => $nt($t) * self::wp($t) + 0.9],
            [['id' => 'z2_ht', 'parent_meter_id' => 'z1_ht', 'meter_group_id' => 'g2'], fn(int $t) => 2.1],
            [['id' => 'z2_nt', 'parent_meter_id' => 'z1_nt', 'meter_group_id' => 'g2'], fn(int $t) => 1.0],
        ], self::firsts('2023-01-01', self::TODAY));
        $this->store->write('strom/meter_groups.json', [['id' => 'g1', 'name' => 'Vorn', 'created_at' => '2023-01-01'], ['id' => 'g2', 'name' => 'Haushalt', 'created_at' => '2023-01-01']]);
        foreach (['g1' => [27.0, 21.0, 'z1_nt'], 'g2' => [33.0, 26.0, 'z2_nt']] as $g => [$ht, $ntp, $ntId]) {
            $this->contracts->create('strom', ['meter_group_id' => $g, 'provider' => 'Testversorger', 'tariff_name' => 'HT/NT', 'start' => '2023-01-01',
                'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => $ht]],
                'working_prices_by_meter' => [$ntId => [['from' => '2023-01-01', 'ct_per_kwh' => $ntp]]],
                'base_prices' => [['from' => '2023-01-01', 'eur_per_month' => 10.0]]]);
        }
        $g1 = $this->meters->target('strom', 'g1');
        $fc = $this->forecast()->forMeter('strom', $g1, ['today' => self::TODAY]);
        self::assertTrue($fc['heat_pump_weather']['on']);
        $ht = $this->forecast()->forMeter('strom', $this->meters->get('strom', 'z1_ht'), ['today' => self::TODAY]);
        $ntFc = $this->forecast()->forMeter('strom', $this->meters->get('strom', 'z1_nt'), ['today' => self::TODAY]);
        self::assertFalse($ntFc['heat_pump_weather']['on']);
        self::assertSame('few_measured', $ntFc['heat_pump_weather']['reason']);
        $shares = $this->consumption->registerShares('strom', $g1)['shares']['z1_nt'];
        foreach (['2025-07', '2025-12', '2026-01'] as $ym) {
            $r = self::row($fc, $ym);
            self::assertEqualsWithDelta(self::row($ht, $ym)['kwh'] + self::row($ntFc, $ym)['kwh'], $r['kwh'], 1e-6, "$ym: Gruppe = HT + NT");
            $mo = (int)substr($ym, 5, 2);
            self::assertEqualsWithDelta($shares[$mo] * $r['billed_kwh'], self::row($ntFc, $ym)['billed_kwh'], 0.11, "$ym: NT = Anteil am Rest der Gruppe");
        }
        self::assertEqualsWithDelta(0.0, $shares[7], 1e-9, 'im Juli trägt NT nichts von der Wärmepumpe');
        self::assertGreaterThan(0.2, $shares[1]);
    }

    /**
     * E2 — Der Versatz gilt je Jahr (Steigung × Abstand zur Mitte der Periode), auch gebrochen (0,775 K zwischen den
     * Rasterpunkten des Normals), und schiebt die Heizgrenze nie unter den Rand des Rasters.
     */
    public function testTheShiftFollowsTheYearAndStaysOnTheGrid(): void
    {
        $n = $this->store->read(ClimateNormalService::FILE, []);
        // Mitte 2009,5, 0,05 K je Jahr: 2025 → 0,775 K, 2026 → 0,825 K, 2015 → 0,275 K
        $this->store->write(ClimateNormalService::FILE, ['temp_trend' => 0.775, 'temp_trend_slope' => 0.05, 'temp_trend_center' => 2009.5, 'period' => self::THIRTY] + $n);
        self::assertEqualsWithDelta(0.775, $this->consumption->weatherNormalShift(2025), 1e-9);
        self::assertEqualsWithDelta(0.825, $this->consumption->weatherNormalShift(2026), 1e-9);
        self::assertEqualsWithDelta(0.275, $this->consumption->weatherNormalShift(2015), 1e-9);
        self::assertEqualsWithDelta(0.775, $this->consumption->weatherNormalShift(), 1e-9, 'ohne Jahr: das Jahr nach der Periode');
        // gebrochen zwischen den Rasterpunkten: linear dazwischen, nicht auf einen Punkt gerundet
        $lo = $this->consumption->hddNormal(1, 11.0)['mean'];
        $hi = $this->consumption->hddNormal(1, 11.5)['mean'];
        $mid = $this->consumption->hddNormal(1, 12.0 - 0.775)['mean'];
        self::assertEqualsWithDelta($lo + (0.225 / 0.5) * ($hi - $lo), $mid, 1e-9);
        // am Rand des Rasters (Heizgrenze 6 °C) kein Versatz nach unten, das Normal bleibt da
        self::assertSame(0.0, $this->consumption->weatherNormalShift(2026, ClimateNormalService::BASE_MIN));
        self::assertEqualsWithDelta(0.3, $this->consumption->weatherNormalShift(2026, ClimateNormalService::BASE_MIN + 0.3), 1e-9);
        self::assertNotNull($this->consumption->hddNormal(1, ClimateNormalService::BASE_MIN - $this->consumption->weatherNormalShift(2026, ClimateNormalService::BASE_MIN)));
        // die Prognose 2026 rechnet mit dem Versatz ihres Jahres
        $m = $this->wpMeter();
        $fc = $this->forecast()->forMeter('strom', $m, ['today' => self::TODAY]);
        $base = (float)$fc['hdd_base'];
        self::assertEqualsWithDelta($this->consumption->hddNormal(1, $base - 0.825)['mean'], self::row($fc, '2026-01')['hdd_estimated'], 0.051);
        self::assertEqualsWithDelta($this->consumption->hddNormal(8, $base - 0.775)['mean'], self::row($fc, '2025-08')['hdd_estimated'], 0.051);
        self::assertSame(0.775, $fc['heat_pump_weather']['normal_shift'], 'Versatz des ersten Prognosemonats');
        // die Schätzung je Tag der Saldo-Karte ebenso je Jahr des Tages (Januar 2026 ohne Tageswerte)
        $est = $this->consumption->atDate(self::TODAY, fn() => $this->consumption->estimatedMonths('strom', $m, ['2026-01']));
        self::assertEqualsWithDelta(self::A * $this->consumption->hddNormal(1, self::LIMIT - 0.825)['mean'] + self::C * 31, $est['2026-01'], 0.3);
        // ein Normal über ein einzelnes Jahr (das Ist-Wetter, wie es die Rückrechnung „nachgerechnet“ einsetzt) trägt
        // keinen Trend, auch wenn die Felder mitkommen: sonst würde das wirkliche Wetter noch einmal verschoben
        $this->store->write(ClimateNormalService::FILE, ['period' => ['from' => '2025-01-01', 'to' => '2025-12-31']] + $this->store->read(ClimateNormalService::FILE, []));
        self::assertSame(0.0, $this->consumption->weatherNormalShift(2026));
        self::assertNull($this->consumption->climate()->tempTrend());
    }

    public function testE4TheMainMeterOfAHouseHeatingWithPower(): void
    {
        $this->settings->set(['waerme_energietraeger' => 'strom']);
        $this->meters([[['id' => 'main'], fn(int $t) => self::wp($t) + 6.0]], self::firsts('2023-01-01', self::TODAY));
        $fc = $this->forecast()->forMeter('strom', $this->meters->get('strom', 'main'), ['today' => self::TODAY]);
        self::assertSame(UtilityWeather::KIND_HEATING, $fc['heat_pump_weather']['kind']);
        self::assertTrue($fc['heat_pump_weather']['on']);
        self::assertEqualsWithDelta(self::C + 6.0, $fc['heat_model']['c'], 1e-3);
        // ohne Heizung mit Strom: wie bisher, ohne Hinweis
        $this->settings->set(['waerme_energietraeger' => 'gas']);
        $old = $this->forecast()->forMeter('strom', $this->meters->get('strom', 'main'), ['today' => self::TODAY]);
        self::assertArrayNotHasKey('heat_pump_weather', $old);
        self::assertNotContains('heat_pump_no_weather', array_column($old['warnings'], 'code'));
    }

    // ── Mindestmaß: zeigen statt raten ──────────────────────────────────

    public function testBelowTheMinimumTheSeasonalProfileStaysWithAReason(): void
    {
        // sieben Monate: zu wenig für ein Heizmodell (8 Monate oder 8 Abstände)
        $this->meters([[['id' => 'wp', 'role' => 'heat_pump'], fn(int $t) => self::wp($t)]], self::firsts('2024-12-01', self::TODAY));
        $fc = $this->forecast()->forMeter('strom', $this->meters->get('strom', 'wp'), ['today' => self::TODAY]);
        self::assertFalse($fc['heat_pump_weather']['on']);
        self::assertSame('few_measured', array_column($fc['warnings'], 'reason', 'code')['heat_pump_no_weather'] ?? null);
        self::assertNull($fc['heat_model']);
        // ohne Klimanormal (kein Standort): kein Normal, also kein Wetterweg
        $this->store->write(ClimateNormalService::FILE, []);
        $this->wpMeter();
        $fc = $this->forecast()->forMeter('strom', $this->meters->get('strom', 'wp'), ['today' => self::TODAY]);
        self::assertSame('no_normal', $fc['heat_pump_weather']['reason']);
    }

    public function testTheMinimumNeedsAHeatingAndASummerMonth(): void
    {
        $normals = [];
        foreach (range(1, 12) as $mo) $normals[$mo] = ['mean' => [1 => 465.0, 2 => 400, 3 => 330, 4 => 200, 5 => 80, 6 => 15, 7 => 0, 8 => 0, 9 => 40, 10 => 160, 11 => 300, 12 => 420][$mo], 'source' => 'climate_normal'];
        // kältester Monat Januar: 465 ÷ 31 = 15 Gradtage je Tag; Heizmonat ab 7,5, Sommer bis 1,5
        $model = fn(array $perDay) => ['a' => 1.0, 'c' => 1.0, 'n' => 8, 'hdd_per_day' => $perDay];
        self::assertNull(HeatPumpWeather::minimumReason($model([0.0, 3.0, 8.0, 12.0]), $normals));
        self::assertSame('no_summer', HeatPumpWeather::minimumReason($model([2.0, 8.0, 14.0]), $normals));
        self::assertSame('no_heating', HeatPumpWeather::minimumReason($model([0.0, 1.0, 7.0]), $normals));
        self::assertSame('few_measured', HeatPumpWeather::minimumReason(null, $normals));
        self::assertSame('no_normal', HeatPumpWeather::minimumReason($model([0.0, 12.0]), array_slice($normals, 0, 11, true)));
        $history = array_map(fn($n) => ['source' => 'temperature_history'] + $n, $normals);
        self::assertSame('no_normal', HeatPumpWeather::minimumReason($model([0.0, 12.0]), $history), 'eigene Temperaturhistorie ist kein Klimanormal');
    }

    // ── Gruppe über Ablesungsabstände ───────────────────────────────────

    public function testATwoRateGroupReadQuarterlyFitsOverTheReadingSpans(): void
    {
        // HT 70 %, NT 30 %, beide am selben Tag abgelesen, alle drei Monate: Jeder Monat ist verteilt
        $dates = self::firsts('2023-01-01', self::TODAY, 3);
        $this->meters([
            [['id' => 'ht', 'role' => 'heat_pump', 'meter_group_id' => 'g'], fn(int $t) => 0.7 * self::wp($t)],
            [['id' => 'nt', 'role' => 'heat_pump', 'meter_group_id' => 'g'], fn(int $t) => 0.3 * self::wp($t)],
        ], $dates);
        $this->store->write('strom/meter_groups.json', [['id' => 'g', 'name' => 'Wärmepumpe', 'created_at' => '2023-01-01']]);
        $g = $this->meters->target('strom', 'g');
        self::assertSame(UtilityWeather::KIND_HEAT_PUMP, $this->consumption->weatherKind('strom', $g));
        $rows = $this->consumption->atDate(self::TODAY, fn() => $this->consumption->weatherSeries('strom', $g, $this->consumption->forMeter('strom', $g, self::LIMIT), self::LIMIT));
        self::assertNull($this->consumption->heatModel('strom', $rows, $g, self::LIMIT), 'ohne das Wetter-Argument wie bisher: Strom hat kein Heizmodell');
        $model = $this->consumption->atDate(self::TODAY, fn() => $this->consumption->heatModel('strom', $rows, $g, self::LIMIT, true));
        self::assertSame('intervals', $model['basis']);
        self::assertSame(count($dates) - 1, $model['n'], 'jeder Abstand ein Punkt (HT und NT zusammen)');
        self::assertEqualsWithDelta(self::A, $model['a'], 1e-3);
        self::assertEqualsWithDelta(self::C, $model['c'], 1e-3);
    }

    // ── Erwartet bei Abrechnung ─────────────────────────────────────────

    public function testTheSaldoEstimatesTheHeatPumpWithTheWeather(): void
    {
        $m = $this->wpMeter();
        $this->contracts->create('strom', ['meter_id' => 'wp', 'provider' => 'Testversorger', 'start' => '2023-01-01', 'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 25.0]],
            'advance_payments' => [['from' => '2023-01-01', 'amount_eur' => 60.0]]]);
        $est = $this->consumption->atDate(self::TODAY, fn() => $this->consumption->estimatedMonths('strom', $m, ['2025-07', '2026-01']));
        // Juli mit den Tagestemperaturen (kein Heizbedarf), Januar 2026 aus dem Normal (keine Tageswerte)
        // (Stände auf 0,001 kWh, Monate auf 0,1 kWh gerundet: Kalibrierung knapp neben 1)
        self::assertEqualsWithDelta(self::C * 31, $est['2025-07'], 0.1);
        self::assertEqualsWithDelta(self::A * $this->consumption->hddNormal(1, self::LIMIT)['mean'] + self::C * 31, $est['2026-01'], 0.3);
        $cs = $this->consumption->contractStatus('strom', $m, self::TODAY);
        self::assertTrue($cs['heat_pump_weather']['on']);
        // der alte Weg rechnet ohne Wetter: Januar = Tagesrate der gemessenen Januare
        $this->consumption->setHeatPumpWeather(false, false, false);
        $old = $this->consumption->atDate(self::TODAY, fn() => $this->consumption->estimatedMonths('strom', $m, ['2026-01']));
        self::assertNotEqualsWithDelta($est['2026-01'], $old['2026-01'], 1.0);
        self::assertSame('not_validated', $this->consumption->contractStatus('strom', $m, self::TODAY)['heat_pump_weather']['reason']);
    }

    public function testOtherPowerMetersStayAsTheyWere(): void
    {
        $this->meters([[['id' => 'haus'], fn(int $t) => 8.0]], self::firsts('2023-01-01', self::TODAY));
        $h = $this->meters->get('strom', 'haus');
        $fc = $this->forecast()->forMeter('strom', $h, ['today' => self::TODAY]);
        self::assertArrayNotHasKey('heat_pump_weather', $fc);
        self::assertNull($fc['heat_model']);
        self::assertNull($this->consumption->weatherKind('strom', $h));
        self::assertArrayNotHasKey('heat_pump_weather', $this->consumption->contractStatus('strom', $h, self::TODAY));
        self::assertNull($this->consumption->heatPumpWeather('strom', $h, []));
        // Monatszeilen der Wärmepumpe bleiben ohne Felder des Heizmodells (Auswertung, Anomalien: Paket I)
        $wp = $this->wpMeter();
        $rows = $this->consumption->atDate(self::TODAY, fn() => $this->consumption->forMeter('strom', $wp));
        self::assertArrayNotHasKey('expected_heat', $rows[0]);
        self::assertNull($this->anomalies->quality('strom', $rows), 'die Gütesperre ohne das Wetter-Argument wie bisher');
        self::assertSame(ConsumptionService::MIN_POINTS_REGRESSION, 8);
    }
}
