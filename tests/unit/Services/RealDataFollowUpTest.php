<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AnomalyService;
use Energietracker\Services\BillService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\ClimateNormalService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\RegressionService;
use Energietracker\Services\ReportAdviceService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (Paket wirklichkeit-2) — die Folgen aus dem Abgleich mit echten Daten,
 * geprüft mit erfundenen Haushalten: dem unordentlichen aus
 * tests/fixtures/messy-household/ (eigene, konvexe Physik) und kleinen
 * Haushalten nach Formel. Keine Zahl stammt aus echten Daten.
 *
 *   1  Heizgrenze-Vorschlag      6  Gründe ungültiger Heizkurven
 *   2  Klimanormal ab 6 °C       7  abgelöste Verträge, Preis fehlt
 *   3  Abschlag unsicher          8  Sommer ohne Heizkurve (Vergleichsportal)
 *   5  Band und Heizanteil nur mit Gütesperre, „bereinigt = roh“ mit Grund
 *
 * (Punkt 4 steht in BillExpectedTest, Punkt 9 in InterpolationTest.)
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ForecastService::class)]
#[CoversClass(ScenarioService::class)]
#[CoversClass(ClimateNormalService::class)]
#[CoversClass(RegressionService::class)]
#[CoversClass(ReportAdviceService::class)]
final class RealDataFollowUpTest extends ServiceTestCase
{
    use \Energietracker\Tests\Support\ReportFixture;

    private const MESSY = __DIR__ . '/../../fixtures/messy-household/';
    private const TEMP = [1 => 0.0, 2 => 1.0, 3 => 5.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
                          7 => 19.0, 8 => 18.0, 9 => 14.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];

    // ── Hilfen ──────────────────────────────────────────────────────────

    private function loadMessy(): void
    {
        $h = json_decode((string)file_get_contents(self::MESSY . 'household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings']);
        $this->store->write('temperatures.json', $h['temperatures']);
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        foreach ($h['utilities'] as $u => $pots) foreach ($pots as $pot => $rows) $this->store->write("$u/$pot.json", $rows);
    }

    /**
     * Ein Haushalt nach Formel: Tagesverbrauch aus der Tagestemperatur, Ablesung an
     * jedem Monatsersten. `$swing`: Tagesschwankung um das Monatsmittel in °C (0 = jeder
     * Tag hat das Monatsmittel); `$perDay` bekommt Datum, HGT zu 15 °C und die Temperatur.
     */
    private function formulaHousehold(callable $perDay, string $from, string $to, array $skip = [], float $swing = 0.0): array
    {
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]]]);
        $tempOf = fn(int $t) => self::TEMP[(int)date('n', $t)] + $swing * (sin($t / 86400 * 2 * M_PI / 7.3) + 0.5 * sin($t / 86400 * 2 * M_PI / 3.1));
        $temps = [];
        for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= $to; $t += 86400) {
            $a = round($tempOf($t), 2);
            $temps[date('Y-m-d', $t)] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $id = $this->setMeterDevices('gas', [['id' => 'd1', 'serial' => null, 'installed_on' => $from, 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = []; $c = 0.0;
        for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= $to; $t += 86400) {
            $d = date('Y-m-d', $t);
            if (substr($d, 8, 2) === '01' && !in_array($d, $skip, true)) $rows[] = ['date' => $d, 'counter' => round($c, 3), 'device_id' => 'd1'];
            $temp = $temps[$d]['avg'];
            $c += $perDay($d, max(0.0, 15.0 - $temp), $temp);
        }
        $this->setReadings('gas', $id, $rows);
        return $this->meters->list('gas')[0];
    }

    /**
     * v3.9.0 (V1, L12) — Stichtag ein halbes Jahr voraus: Die laufende Periode
     * endet dann in fünf bis sechs Monaten, ein Abschlagsvorschlag ist möglich
     * (ab drei Abschlägen bis zur Abrechnung), in jedem Monat des Jahres gleich.
     */
    private function anchorAhead(string $utility): void
    {
        $this->settings->set(['billing_cycle_anchor_' . $utility => date('m-01', strtotime(date('Y-m-01') . ' +6 months'))]);
    }

    private function forecastService(): ForecastService
    {
        return new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
    }

    private function scenario(): ScenarioService
    {
        $f = $this->forecastService();
        return new ScenarioService($f, $this->consumption, $this->contracts, $this->meters, $this->settings,
            new TariffSwitchService($f, $this->contracts, $this->meters, $this->i18n));
    }

    private function gasContract(array $extra = []): array
    {
        return $this->contracts->list('gas')[0] ?? throw new \RuntimeException('kein Vertrag');
    }

    // ── 1  Heizgrenze-Vorschlag ─────────────────────────────────────────

    /** Konvexe Heizkurve, Heizgrenze 12,5 °C (Generator): Vorschlag deutlich unter 15 °C, nie automatisch übernommen. */
    public function testTheHeatLimitSuggestionFindsTheLimitOfTheMeasuredMonths(): void
    {
        $this->loadMessy();
        $meter = $this->meters->list('gas')[0];
        $h = $this->consumption->heatLimitSuggestion('gas', $meter);
        self::assertTrue($h['suggest']);
        self::assertSame(15.0, $h['current']);
        self::assertGreaterThanOrEqual(10.0, $h['best']);
        self::assertLessThanOrEqual(12.5, $h['best']);
        self::assertLessThanOrEqual($h['current_ratio'] * ConsumptionService::HEAT_LIMIT_GAIN, $h['best_ratio']);
        self::assertCount(25, $h['grid'], '6 bis 18 °C in halben Grad');
        self::assertSame([6.0, 18.0], [$h['grid'][0]['base'], end($h['grid'])['base']]);
        self::assertSame(15.0, (float)$this->settings->get('hdd_base_temp', 15.0), 'nur ein Vorschlag');
        // v3.7.1 (#38) — der Vorschlag überschätzt den gemessenen Sommer nicht
        foreach ($h['grid'] as $g) {
            if (abs($g['base'] - $h['best']) < 1e-9) self::assertLessThanOrEqual(ConsumptionService::HEAT_LIMIT_SUMMER_MAX, $g['summer_ratio']);
        }
    }

    /**
     * #38 (öffentliche Zusage) — ein erfundener Haushalt in der Form des Melders: Warmwasser
     * als Grundlast, Heizung erst unter 12 °C, Alltag ± 6 % je Monat. Bei 15 °C drückt die
     * Gerade die Grundlast weit unter die gemessenen Sommer (Gütesperre greift), bei 12 °C
     * trifft das Modell die Sommer und streut höchstens halb so viel. Der Vorschlag nennt
     * etwa 12 °C und gilt je Zähler; übernommen nur, wenn man ihn setzt.
     */
    public function testTheSuggestionFindsTwelveDegreesWhereTheReporterFoundThem(): void
    {
        // Größenordnung wie beim Melder: Grundlast 6,5 kWh am Tag, 7,5 kWh je Gradtag zu 12 °C
        $noise = fn(string $d) => 1.0 + 0.01 * ((((int)substr($d, 0, 4) * 12 + (int)substr($d, 5, 2)) * 7) % 5 - 2);
        $meter = $this->formulaHousehold(fn($d, $h, $temp) => (6.5 + 7.5 * max(0.0, 12.0 - $temp)) * $noise($d),
            '2023-01-01', '2025-12-31', [], 3.0);
        $at15 = $this->anomalies->quality('gas', $this->consumption->forMeter('gas', $meter));
        self::assertSame('poor', $at15['model_quality'], 'bei 15 °C greift die Gütesperre');
        self::assertLessThan(AnomalyService::BASE_LOAD_MIN, $at15['base_load']['ratio'], 'die Gerade drückt die Grundlast weit unter die gemessenen Sommer');
        self::assertContains('base_load', $at15['reasons']);
        $h = $this->consumption->heatLimitSuggestion('gas', $meter);
        self::assertTrue($h['suggest']);
        self::assertGreaterThanOrEqual(11.5, $h['best']);
        self::assertLessThanOrEqual(12.5, $h['best']);
        self::assertLessThanOrEqual($h['current_ratio'] / 2, $h['best_ratio'], 'die Streuung mindestens halbiert');
        self::assertSame([15.0, false], [$h['default'], $h['own']]);

        // übernommen: nur dieser Zähler rechnet mit 12 °C, die Einstellung bleibt
        $this->meters->update('gas', (string)$meter['id'], ['hdd_base_temp' => $h['best']]);
        $meter = $this->meters->get('gas', (string)$meter['id']);
        self::assertSame($h['best'], $this->consumption->hddBaseFor($meter));
        self::assertSame(15.0, (float)$this->settings->get('hdd_base_temp', 15.0));
        $rows = $this->consumption->forMeter('gas', $meter);
        $at12 = $this->anomalies->quality('gas', $rows);
        self::assertSame('good', $at12['model_quality'], 'bei 12 °C nicht');
        self::assertEqualsWithDelta(1.0, $at12['base_load']['ratio'], 0.05, 'die Grundlast trifft die gemessenen Sommer');
        $temps = $this->store->read('temperatures.json', []);
        $jan = 0.0;
        foreach ($temps as $d => $tt) if (str_starts_with($d, '2025-01')) $jan += max(0.0, 12.0 - $tt['avg']);
        self::assertEqualsWithDelta($jan, (float)array_column($rows, null, 'ym')['2025-01']['hdd'], 0.5, 'Gradtage zu 12 °C');
        $fc = $this->forecastService()->forMeter('gas', $meter);
        self::assertSame($h['best'], $fc['hdd_base'], 'die Prognose rechnet mit der Grenze des Zählers');
        // das Normal für dieselbe Grenze: Januar = Mittel der drei Januare zu 12 °C (eigene Temperaturhistorie)
        $janHdd = [];
        foreach ($temps as $d => $tt) if (substr($d, 5, 2) === '01') $janHdd[substr($d, 0, 4)] = ($janHdd[substr($d, 0, 4)] ?? 0.0) + max(0.0, 12.0 - $tt['avg']);
        self::assertEqualsWithDelta(array_sum($janHdd) / count($janHdd), (float)array_column($rows, null, 'ym')['2025-01']['hdd_normal'], 0.5, 'Normal zu 12 °C');
        // die Saldo-Schätzung je Tag: ein milder Maitag ohne Heizung bei 12 °C
        $est = (new \ReflectionMethod($this->consumption, 'volumeEstimator'))->invoke($this->consumption, 'gas', $rows, 'kwh', $meter);
        $may = '2025-05-10';
        self::assertGreaterThan(12.0, $temps[$may]['avg'], 'der Tag liegt über 12 °C');
        self::assertEqualsWithDelta(6.5, $est($may, '2025-05-11')[$may], 0.3, 'nur Warmwasser — mit 15 °C hätte er geheizt');
        $after = $this->consumption->heatLimitSuggestion('gas', $meter);
        self::assertSame([false, true], [$after['suggest'], $after['own']], 'nach dem Übernehmen kein neuer Vorschlag');

        // vierteljährlich abgelesen: das Heizmodell über die Abstände rechnet ebenfalls mit 12 °C
        $quarterly = $this->formulaHousehold(fn($d, $h, $temp) => 6.5 + 7.5 * max(0.0, 12.0 - $temp), '2023-01-01', '2025-12-31',
            array_map(fn($m) => sprintf('%s-%02d-01', $m[0], $m[1]), array_merge(...array_map(fn($y) => array_map(fn($mo) => [$y, $mo], [2, 3, 5, 6, 8, 9, 11, 12]), [2023, 2024, 2025]))), 3.0);
        $this->meters->update('gas', (string)$quarterly['id'], ['hdd_base_temp' => 12.0]);
        $quarterly = $this->meters->get('gas', (string)$quarterly['id']);
        $model = $this->consumption->heatModel('gas', $this->consumption->forMeter('gas', $quarterly), $quarterly);
        self::assertSame('intervals', $model['basis']);
        self::assertEqualsWithDelta(6.5, $model['c'], 0.3, 'Grundlast über die Abstände zu 12 °C');
        self::assertEqualsWithDelta(7.5, $model['a'], 0.3);
    }

    /** Die eigene Heizgrenze eines Zählers: im Raster des Klimanormals, nur Heizarten mit Zählerständen, leer entfernt sie; im Backup enthalten. */
    public function testAMeterHeatLimitIsValidatedRemovableAndBackedUp(): void
    {
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, '2025-01-01', '2025-06-30');
        $id = (string)$meter['id'];
        foreach ([5.5, 22.5, 'zwölf'] as $bad) {
            try { $this->meters->update('gas', $id, ['hdd_base_temp' => $bad]); self::fail("angenommen: $bad"); }
            catch (\Energietracker\Support\LocalizedException $e) { self::assertSame('errors.meter.valueInvalid', $e->key); }
        }
        $this->meters->update('gas', $id, ['hdd_base_temp' => '12.25']);
        self::assertSame(12.3, $this->meters->get('gas', $id)['hdd_base_temp']);
        $backup = (new \Energietracker\Services\BackupService($this->store, $this->i18n))->export();
        $this->meters->update('gas', $id, ['hdd_base_temp' => null]);
        self::assertArrayNotHasKey('hdd_base_temp', $this->meters->get('gas', $id), 'leer entfernt sie');
        (new \Energietracker\Services\BackupService($this->store, $this->i18n))->import($backup);
        self::assertSame(12.3, $this->meters->get('gas', $id)['hdd_base_temp'], 'aus dem Backup zurück');
        $this->setMeterDevices('strom', [['id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        try { $this->meters->update('strom', (string)$this->meters->list('strom')[0]['id'], ['hdd_base_temp' => 12]); self::fail('Strom angenommen'); }
        catch (\Energietracker\Support\LocalizedException $e) { self::assertSame('errors.meter.valueInvalid', $e->key); }
    }

    /** Passt das Modell schon (Haushalt nach dem App-Modell mit 15 °C) oder fehlen gemessene Monate: kein Vorschlag. */
    public function testNoHeatLimitSuggestionForAGoodFitOrTooFewMeasuredMonths(): void
    {
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, '2023-01-01', '2025-12-31');
        $h = $this->consumption->heatLimitSuggestion('gas', $meter);
        self::assertNotNull($h);
        self::assertFalse($h['suggest'], json_encode(array_slice($h, 0, 6)));
        $short = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, '2025-01-01', '2025-11-30');
        self::assertNull($this->consumption->heatLimitSuggestion('gas', $short), 'unter zwölf gemessenen Monaten');
    }

    /** Die Wahl selbst: nur Grenzen mit bestandener Grundlast-Prüfung, nur deutlich besser, nicht die heutige. */
    public function testThePickNeedsAPassingBaseLoadAndAClearGain(): void
    {
        $g = fn(float $b, float $r, bool $ok = true) => ['base' => $b, 'ratio' => $r, 'base_load_ok' => $ok];
        $pick = fn(array $grid, float $now) => ConsumptionService::pickHeatLimit($grid, 15.0, $now);
        $p = $pick([$g(9.0, 0.02, false), $g(11.0, 0.06), $g(15.0, 0.21)], 0.21);
        self::assertSame([11.0, true], [$p['best'], $p['suggest']], 'die Grenze mit der kleinsten Streuung ohne Grundlast-Befund');
        $p = $pick([$g(12.0, 0.09), $g(15.0, 0.12)], 0.12);
        self::assertSame([12.0, false], [$p['best'], $p['suggest']], 'nur ein Viertel weniger: nicht deutlich');
        $p = $pick([$g(14.0, 0.0), $g(15.0, 0.02)], 0.02);
        // v3.9.0 (#38) — geglättet sind beide gleich gut (Plateau): Es bleibt bei der heutigen.
        // Bis v3.7.1 hieß das Ergebnis 14 °C ohne Vorschlag; vorgeschlagen wurde auch damals nichts
        self::assertSame([15.0, false, [14.0, 15.0]], [$p['best'], $p['suggest'], $p['range']], 'zwei Prozentpunkte: beide passen gut');
        $p = $pick([$g(15.0, 0.05), $g(16.0, 0.30)], 0.05);
        self::assertSame([15.0, false], [$p['best'], $p['suggest']], 'die heutige ist die beste');
    }

    /**
     * v3.7.1 (#38, öffentliche Zusage) — Die Sommer-Referenz der Grundlast-Prüfung hängt nicht
     * von der geprüften Heizgrenze ab: dieselben Monate für jede Grenze. Haushalt in der Form
     * des Melders (Grundlast 6,5 kWh am Tag, Heizung unter 12 °C). Bis 3.7.0 zählten bei
     * niedriger Grenze Übergangsmonate mit echtem Heizbedarf als „Sommer“; ein Modell mit zu
     * hoher Grundlast bestand. Jetzt überschätzt es den gemessenen Sommer sichtbar.
     */
    public function testTheSummerReferenceIsTheSameForEveryCandidate(): void
    {
        $noise = fn(string $d) => 1.0 + 0.01 * ((((int)substr($d, 0, 4) * 12 + (int)substr($d, 5, 2)) * 7) % 5 - 2);
        $meter = $this->formulaHousehold(fn($d, $h, $temp) => (6.5 + 7.5 * max(0.0, 12.0 - $temp)) * $noise($d), '2023-01-01', '2025-12-31', [], 3.0);
        $q = fn(float $b) => $this->anomalies->quality('gas', $this->consumption->forMeter('gas', $meter, $b))['base_load'];
        [$low, $mid, $high] = [$q(9.5), $q(12.0), $q(15.0)];
        self::assertNotSame([], $mid['reference_months']);
        self::assertSame([$mid['reference_months'], $mid['reference_months']], [$low['reference_months'], $high['reference_months']], 'dieselben Monate für 9,5, 12 und 15 °C');
        foreach ($mid['reference_months'] as $ym) {
            self::assertContains((int)substr($ym, 5), AnomalyService::SUMMER_REF_MONTHS, "$ym: ohne Heizgradtage zu 18 °C gibt es hier zu wenige Monate, also Juni bis August");
        }
        self::assertSame('window', $mid['reference']);
        self::assertGreaterThan(ConsumptionService::HEAT_LIMIT_SUMMER_MAX, $low['ratio'], '9,5 °C überschätzt den gemessenen Sommer');
        self::assertEqualsWithDelta(1.0, $mid['ratio'], 0.05, '12 °C trifft ihn');
        self::assertSame(ConsumptionService::HEAT_LIMIT_GRID[1], AnomalyService::SUMMER_REF_BASE, 'Referenz = oberste Rastergrenze');
        // der Vorschlag: nie eine Grenze, die den Sommer überschätzt; aus 15 °C etwa 12 °C
        $h = $this->consumption->heatLimitSuggestion('gas', $meter);
        $byBase = [];
        foreach ($h['grid'] as $row) $byBase[sprintf('%.1f', $row['base'])] = $row;
        self::assertTrue($byBase[sprintf('%.1f', $h['best'])]['summer_ok']);
        self::assertSame([false, false], [$byBase['9.5']['summer_ok'], $byBase['10.0']['summer_ok']]);
        self::assertTrue($h['suggest']);
        self::assertEqualsWithDelta(12.0, $h['best'], 0.5);
    }

    /** v3.7.1 (#38) — erfundenes Raster in der Form des Befunds: Die kleinste Streuung hat eine Grenze, die den Sommer überschätzt. */
    public function testASuggestionMustNotOverestimateTheMeasuredSummer(): void
    {
        $g = fn(float $b, float $r, bool $ok = true, ?float $summer = 1.0) => ['base' => $b, 'ratio' => $r, 'base_load_ok' => $ok,
            'summer_ratio' => $summer, 'summer_ok' => $summer !== null && $summer <= ConsumptionService::HEAT_LIMIT_SUMMER_MAX];
        $grid = [$g(9.0, 0.05, true, 1.3), $g(12.0, 0.09, true, 0.95), $g(15.0, 0.18, false, 0.2)];
        $p = ConsumptionService::pickHeatLimit($grid, 15.0, 0.18);
        self::assertSame([12.0, true], [$p['best'], $p['suggest']], 'aus 15 °C: 12 °C, nicht die Grenze mit der kleinsten Streuung, die den Sommer um 30 % überschätzt');
        $p = ConsumptionService::pickHeatLimit($grid, 12.0, 0.09);
        self::assertSame([12.0, false], [$p['best'], $p['suggest']], 'bei 12 °C still');
        $p = ConsumptionService::pickHeatLimit([$g(11.0, 0.04, true, null), $g(15.0, 0.18, false, 0.2)], 15.0, 0.18);
        self::assertFalse($p['suggest'], 'ohne Sommer-Referenz kein Vorschlag');
    }

    // ── 2  Klimanormal ab 6 °C ──────────────────────────────────────────

    /** @return array<string,float> Tagesmittel zweier Jahre */
    private static function dailyMeans(): array
    {
        $out = [];
        for ($t = strtotime('2023-01-01 12:00'); date('Y') !== '' && date('Y-m-d', $t) <= '2024-12-31'; $t += 86400) {
            $out[date('Y-m-d', $t)] = self::TEMP[(int)date('n', $t)] + (((int)date('j', $t) % 5) - 2) * 1.5;
        }
        return $out;
    }

    public function testTheClimateNormalCoversHeatingLimitsFromSixDegrees(): void
    {
        $n = ClimateNormalService::compute(self::dailyMeans(), 50.0, 8.0, ['from' => '2023-01-01', 'to' => '2024-12-31']);
        self::assertSame('6.0', array_key_first($n['hdd']));
        self::assertSame('22.0', array_key_last($n['hdd']));
        $climate = new ClimateNormalService($this->store, $this->settings);
        $climate->save($n);
        self::assertTrue($climate->covers(9.0));
        self::assertNotNull($climate->hddForMonth(1, 9.0));
        // ein Normal von vor v3.7.0: Raster erst ab 10 °C
        $old = $n;
        foreach (array_keys($old['hdd']) as $k) if ((float)$k < 10.0) unset($old['hdd'][$k]);
        $climate->save($old);
        self::assertTrue($climate->isStale(50.0, 8.0, 2025), 'ohne die neuen Rasterpunkte: einmal neu holen');
        self::assertFalse($climate->covers(9.0));
        self::assertNull($climate->hddForMonth(1, 9.0), 'nicht still mit 10 °C rechnen');
        self::assertTrue($climate->covers(15.0));
        self::assertFalse($climate->covers(23.0), 'über dem Raster');
        self::assertNull($climate->hddForMonth(1, 23.0), 'nicht still mit 22 °C rechnen');
    }

    /** Die Prognose sagt es, wenn das Normal die Heizgrenze nicht abdeckt. */
    public function testTheForecastSaysWhenTheClimateNormalLacksTheHeatingLimit(): void
    {
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, '2023-01-01', '2025-12-31');
        $n = ClimateNormalService::compute(self::dailyMeans(), 50.0, 8.0, ['from' => '2023-01-01', 'to' => '2024-12-31']);
        foreach (array_keys($n['hdd']) as $k) if ((float)$k < 10.0) unset($n['hdd'][$k]);
        $this->store->write(ClimateNormalService::FILE, $n);
        $codes = fn() => array_column($this->forecastService()->forMeter('gas', $meter)['warnings'], null, 'code');
        self::assertArrayNotHasKey('climate_normal_grid', $codes(), '15 °C liegt im Raster');
        $this->settings->set(['hdd_base_temp' => 9.0]);
        $w = $codes();
        self::assertArrayHasKey('climate_normal_grid', $w);
        self::assertSame([9.0, 6.0, 22.0], [$w['climate_normal_grid']['base'], $w['climate_normal_grid']['min'], $w['climate_normal_grid']['max']]);
        self::assertArrayNotHasKey('no_climate_normal', $w);
    }

    // ── 3  Abschlag sicher ──────────────────────────────────────────────

    /** Gütesperre (konvex): keine einzelne Zahl, sondern Spanne und Grund — auch im Jahresbericht keine Aufgabe mit Betrag. */
    public function testAPoorFitGivesARangeInsteadOfASuggestion(): void
    {
        $this->loadMessy();
        $this->store->write('strom/contracts.json', []);   // die Ampel zeigt dann den Gasvertrag
        $c = $this->consumption->contractStatus('gas', $this->meters->list('gas')[0])['contracts'][0];
        $u = $c['projection_uncertain'];
        self::assertNotNull($u);
        self::assertContains('poor_fit', $u['reasons']);
        self::assertNull($c['suggested_advance']);
        [$lo, $hi] = $u['projected_range'];
        self::assertLessThanOrEqual($c['projected_end_balance'], $lo);
        self::assertGreaterThanOrEqual($c['projected_end_balance'], $hi);
        self::assertGreaterThan($lo, $hi);
        self::assertSame(AnomalyService::POOR_FIT_RATIO < (float)$u['fit_ratio'], true);

        // Jahresbericht: ein Hinweis mit der Spanne, keine Aufgabe „Abschlag anpassen“
        $m = $this->reportServices()['model']->build((int)date('Y') - 1);
        self::assertSame([], array_values(array_filter($m['tasks'], fn($t) => $t['rule'] === 'advance' && $t['utility'] === 'gas')));
        $info = array_values(array_filter($m['infos'], fn($t) => $t['rule'] === 'advanceUncertain' && $t['utility'] === 'gas'));
        self::assertCount(1, $info);
        self::assertStringContainsString('das Heizmodell schlecht zu diesem Zähler passt', $info[0]['reason']);
        $light = array_column($m['headline']['lights'], null, 'key')['billing'];
        self::assertStringContainsString('unsicher', $light['detail']);
        // v3.7.0 (Schritt 3) — „Wohin geht mein Geld?“ bekommt die Spanne mit
        $bal = array_column($m['money']['balances'], null, 'utility')['gas'];
        self::assertSame($u['projected_range'], $bal['explainer']['projection_uncertain']['projected_range'] ?? null);
        // v3.7.0 (Schritt 3) — reicht auch das ungünstige Ende, steht trotzdem kein Haken da
        $lv = ReportAdviceService::billingLevel(max($u['projected_range']) / (float)$c['current_advance_amount']);
        self::assertSame($lv === 'ok' ? 'none' : $lv, $light['level'], 'die Ampel nimmt das ungünstige Ende der Spanne');
    }

    /** Ampel „Abrechnung“: bei unsicherer Schätzung das ungünstige Ende der Spanne, nicht der Mittelwert. */
    public function testTheBillingLightTakesTheUnfavourableEndOfTheRange(): void
    {
        $advice = new ReportAdviceService($this->i18n);
        $lights = new \ReflectionMethod($advice, 'lights');
        $f = fn(?array $unc) => ['year' => 2025, 'today' => '2026-01-31', 'totals' => [], 'agenda' => [], 'contracts' => [[
            'label' => 'Gas', 'has_advance' => true, 'is_current' => true, 'projected_end_balance' => -20.0, 'current_advance' => 100.0,
            'projection_uncertain' => $unc,
        ]]];
        $light = fn(?array $unc) => array_column($lights->invoke($advice, $f($unc)), null, 'key')['billing'];
        $level = fn(?array $unc) => $light($unc)['level'];
        self::assertSame('ok', $level(null), 'sicher: 20 € Guthaben');
        self::assertSame('alert', $level(['projected_range' => [-150.0, 300.0], 'reasons' => ['poor_fit'], 'fit_ratio' => 0.3]),
            'unsicher: bis zu drei Abschläge Nachzahlung');
        // v3.7.0 (Schritt 3) — unsicher, auch das ungünstige Ende reicht: kein „Abschläge passen ✔“
        $l = $light(['projected_range' => [-150.0, -10.0], 'reasons' => ['few_measured']]);
        self::assertSame(['none', $this->i18n->t('report.light.billing.uncertain')], [$l['level'], $l['word']], 'ohne Haken, „Schätzung unsicher“');
        self::assertNotSame($this->i18n->t('report.light.billing.ok'), $l['word']);
        self::assertStringContainsString('unsicher', $l['detail']);
    }

    /**
     * Kalibrierfaktor klemmt: Die letzten gemessenen Monate liegen bei einem
     * Drittel des Modells (neue Heizung ohne Zäsur?) — die Spanne reicht vom
     * jüngsten Niveau bis zum Modell, der Vorschlag mit klemmendem Faktor liegt darin.
     */
    public function testAClampedFactorGivesARangeFromTheRecentLevelToTheModel(): void
    {
        $first = date('Y-m-01', strtotime('-36 months'));
        $recent = date('Y-m-01', strtotime('-6 months'));
        // je Monat ± 6 % Alltag, damit die robuste Streuung nicht null ist (sonst zählten die sechs
        // niedrigen Monate in die Gütesperre, und die Spanne hinge an zwei Gründen)
        $noise = fn(string $d) => 1.0 + 0.03 * ((((int)substr($d, 0, 4) * 12 + (int)substr($d, 5, 2)) * 7) % 5 - 2);
        $meter = $this->formulaHousehold(fn($d, $h) => ($d >= $recent ? 0.3 : 1.0) * (5.0 + 0.3 * $h) * $noise($d), $first, date('Y-m-d'));
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Testgas', 'start' => $first,
            'working_prices' => [['from' => $first, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $first, 'amount_eur' => 80]]]);
        $c = $this->consumption->contractStatus('gas', $meter)['contracts'][0];
        $u = $c['projection_uncertain'];
        self::assertNotNull($u, 'Faktor klemmt');
        self::assertSame(['factor_clamped'], $u['reasons'], 'nur der Faktor, das Modell passt');
        self::assertSame(ConsumptionService::PROJECTION_FACTOR_MIN, $u['factor']);
        self::assertLessThan(ConsumptionService::PROJECTION_FACTOR_MIN, $u['factor_raw']);
        self::assertNull($c['suggested_advance']);
        [$lo, $hi] = $u['projected_range'];
        self::assertTrue($lo <= $c['projected_end_balance'] && $c['projected_end_balance'] <= $hi, 'gerechnet mit 0,5: zwischen dem Niveau und dem Modell');
        self::assertLessThan($c['projected_end_balance'] - 1, $lo, 'das untere Ende folgt dem jüngsten Niveau ganz (Faktor unter 0,5)');
        self::assertGreaterThan($c['projected_end_balance'] + 1, $hi, 'das obere Ende ist das Modell ohne Kalibrierung');
    }

    /** Passt alles, bleibt es beim einzelnen Vorschlag (wie bis v3.6). */
    public function testACertainEstimateKeepsTheSuggestion(): void
    {
        $first = date('Y-m-01', strtotime('-36 months'));
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, $first, date('Y-m-d'));
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Testgas', 'start' => $first,
            'working_prices' => [['from' => $first, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $first, 'amount_eur' => 80]]]);
        $this->anchorAhead('gas');
        $c = $this->consumption->contractStatus('gas', $meter)['contracts'][0];
        self::assertNull($c['projection_uncertain']);
        self::assertIsFloat($c['suggested_advance']);
        self::assertEqualsWithDelta(1.0, $c['projection_factor'], 0.02);
    }

    /** Werkstatt: Gütesperre → Spanne statt Abschlag, kein Band, kein Heizanteil, ein Satz dazu. */
    public function testTheWorkshopWithholdsAdvanceBandAndHeatShareOnAPoorFit(): void
    {
        $this->loadMessy();
        $this->anchorAhead('gas');   // v3.9.0 — mit Abschlägen bis zur Abrechnung (L12)
        $r = $this->scenario()->compute(['utility' => 'gas', 'meter_id' => $this->meters->list('gas')[0]['id']]);
        self::assertTrue($r['valid']);
        self::assertContains('poor_fit', array_column($r['warnings'], 'code'));
        self::assertNull($r['band']);
        self::assertNull($r['basis']['heat_share_year']);
        self::assertNotNull($r['advance']['uncertain']);
        self::assertNull($r['advance']['scen_recommended']);
        [$lo, $hi] = $r['advance']['uncertain']['scen_range'];
        self::assertGreaterThan($lo, $hi);
    }

    // ── 5  Band nur mit Gütesperre; „bereinigt = roh“ mit Grund ─────────

    public function testTheForecastBandNeedsAPassingQualityGate(): void
    {
        $this->loadMessy();
        $fc = $this->forecastService()->forMeter('gas', $this->meters->list('gas')[0]);
        self::assertSame('poor', $fc['fit_quality']['model_quality']);
        self::assertSame(['poor_fit', null, null], [$fc['annual']['withheld'], $fc['annual']['low'], $fc['annual']['high']]);
        self::assertSame([null], array_values(array_unique(array_column($fc['forecast'], 'band_low'))));
        self::assertContains('poor_fit', array_column($fc['warnings'], 'code'));
        self::assertSame(['seasonal_only'], array_values(array_unique(array_column($fc['forecast'], 'method'))),
            'mit gemessenem Saisonwert keine Heizkurve, die schlecht passt');

        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h + ((int)substr($d, 8, 2) % 3) * 0.2, '2023-01-01', '2025-12-31');
        $good = $this->forecastService()->forMeter('gas', $meter);
        self::assertSame('good', $good['fit_quality']['model_quality']);
        self::assertNotNull($good['annual']['low'] ?? null, 'bestanden: mit Band');
    }

    /** Ein Jahr Temperaturen, kein Klimanormal: Das Normal ist der Monat selbst — „bereinigt“ gleich roh, mit Grund. */
    public function testAdjustedEqualsRawSaysWhy(): void
    {
        // Temperaturen genau für 2025 (der 1.1.2026 ergibt keinen vollen Monat)
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, '2025-01-01', '2026-01-01');
        $rows = $this->consumption->forMeter('gas', $meter);
        $heating = array_values(array_filter($rows, fn($m) => (int)$m['year'] === 2025 && (float)$m['hdd'] > 5.0));
        self::assertNotSame([], $heating);
        foreach ($heating as $m) {
            self::assertSame('normal_is_actual', $m['heat_adjusted_basis'], $m['ym']);
            self::assertEqualsWithDelta($m['kwh'], $m['heat_adjusted'], 0.1, $m['ym']);
        }
        $summer = array_column($rows, null, 'ym')['2025-07'];
        self::assertSame('summer', $summer['heat_adjusted_basis']);
    }

    // ── 6  Warum eine Heizkurve nicht gilt ──────────────────────────────

    public function testAnInvalidFitSaysWhy(): void
    {
        $r = new RegressionService();
        self::assertSame('too_few_points', $r->fit('linear', [1.0, 2.0], [3.0, 4.0])['reason']);
        self::assertSame('too_few_points', $r->fit('sigmoid', range(1.0, 7.0), range(2.0, 8.0))['reason']);
        $x = []; $y = [];
        for ($i = 0; $i < 50; $i++) { $x[] = 10.0 + ($i % 10) * 40.0; $y[] = 100.0 + (($i * 37) % 23) * 25.0; }
        $sig = $r->fit('sigmoid', $x, $y);
        self::assertFalse($sig['valid']);
        self::assertSame(['poor_fit', 50], [$sig['reason'], $sig['n']], 'bis v3.6: „zu wenige Datenpunkte“ bei 50 Punkten');
        self::assertSame('degenerate', $r->fit('linear', [5.0, 5.0, 5.0, 5.0], [1.0, 2.0, 3.0, 4.0])['reason']);
        self::assertArrayNotHasKey('reason', $r->fit('linear', [1.0, 2.0, 3.0], [2.0, 4.0, 6.1]));
    }

    /** Die Prognose sagt, wenn die gewählte Heizkurve nicht gilt (sie rechnet ohne sie weiter). */
    public function testTheForecastSaysWhenTheChosenCurveDoesNotHold(): void
    {
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, '2025-01-01', '2025-09-30');
        $fc = $this->forecastService()->forMeter('gas', $meter, ['model' => 'sigmoid']);
        $w = array_column($fc['warnings'], null, 'code')['regression_invalid'] ?? null;
        self::assertSame(['sigmoid', 'too_few_points'], [$w['model'] ?? null, $w['reason'] ?? null]);
    }

    // ── 7  Abgelöste Verträge, Preis fehlt ──────────────────────────────

    /** Ein offener Vertrag, den ein späterer ablöst: Seine Abschläge enden am Tag vor dem Folgevertrag. */
    public function testASupersededContractStopsItsAdvancesWhenTheSuccessorStarts(): void
    {
        $first = date('Y-m-01', strtotime('-36 months'));
        $switch = date('Y-m-01', strtotime('-12 months'));
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, $first, date('Y-m-d'));
        $old = $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Altgas', 'start' => $first,
            'working_prices' => [['from' => $first, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $first, 'amount_eur' => 100]]]);
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Neugas', 'start' => $switch,
            'working_prices' => [['from' => $switch, 'ct_per_kwh' => 9]], 'advance_payments' => [['from' => $switch, 'amount_eur' => 90]]]);
        $byId = array_column($this->consumption->contractStatus('gas', $meter)['contracts'], null, 'contract_id');
        $a = $byId[$old['id']];
        self::assertSame(date('Y-m-d', strtotime("$switch -1 day")), $a['superseded_on']);
        self::assertNull($a['end'], 'gespeichert bleibt offen');
        self::assertTrue($a['is_past']);
        self::assertFalse($a['is_current']);
        self::assertEqualsWithDelta(24 * 100.0, $a['advance_paid'], 0.01, 'genau die 24 Monate bis zum Wechsel, nicht bis heute');
    }

    /** Ohne Nachfolger und in einer lückenlosen Kette bleibt alles, wie es war. */
    public function testContractsWithoutSuccessorAndGaplessChainsStayAsTheyWere(): void
    {
        $first = date('Y-m-01', strtotime('-36 months'));
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, $first, date('Y-m-d'));
        $alone = $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Allein', 'start' => $first,
            'working_prices' => [['from' => $first, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $first, 'amount_eur' => 100]]]);
        self::assertNull(ConsumptionService::supersededOn($alone, $this->contracts->list('gas')));
        $s = $this->consumption->contractStatus('gas', $meter)['contracts'][0];
        self::assertNull($s['superseded_on']);
        // v3.9.0 (V1) — gezählt ab dem Beginn der laufenden Abrechnungsperiode (Stichtag 01.01.)
        self::assertSame(date('Y') . '-01-01', $s['balance_from']);
        $months = 0;
        for ($t = strtotime($s['balance_from']); date('Y-m', $t) <= date('Y-m'); $t = strtotime('+1 month', $t)) $months++;
        self::assertEqualsWithDelta($months * 100.0, $s['advance_paid'], 0.01, 'Abschläge bis einschließlich des laufenden Monats');
        self::assertTrue($s['is_current']);

        $end = date('Y-m-d', strtotime(date('Y-m-01', strtotime('-12 months')) . ' -1 day'));
        $chainA = ['id' => 'a', 'start' => $first, 'end' => $end];
        $chainB = ['id' => 'b', 'start' => date('Y-m-d', strtotime("$end +1 day"))];
        self::assertNull(ConsumptionService::supersededOn($chainA, [$chainA, $chainB]), 'lückenlos: nichts abzulösen');
        self::assertNull(ConsumptionService::supersededOn($chainB, [$chainA, $chainB]));
        $overlap = ['id' => 'a', 'start' => $first, 'end' => date('Y-m-d', strtotime("$end +40 days"))];
        self::assertSame($end, ConsumptionService::supersededOn($overlap, [$overlap, $chainB]), 'Ende nach dem Folgebeginn: abgelöst');
    }

    /** Vertrag ohne Arbeitspreis: Vertragsstatus und Abrechnungsjahr sagen beide „Preis fehlt“. */
    public function testAContractWithoutUnitPriceIsSaidByStatusAndBillingYear(): void
    {
        $first = date('Y-m-01', strtotime('-24 months'));
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, $first, date('Y-m-d'));
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Ohnepreis', 'start' => $first,
            'base_prices' => [['from' => $first, 'eur_per_month' => 10]], 'advance_payments' => [['from' => $first, 'amount_eur' => 50]]]);
        self::assertTrue($this->consumption->contractStatus('gas', $meter)['contracts'][0]['price_missing']);
        $bp = (new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption,
            new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n)))->forTarget('gas', $meter);
        self::assertNotSame([], $bp['periods']);
        self::assertSame([true], array_values(array_unique(array_column($bp['periods'], 'price_missing'))));
        self::assertFalse(ConsumptionService::lacksWorkingPrice('gas', ['working_prices' => [['from' => $first, 'ct_per_kwh' => 9]]]));
        self::assertFalse(ConsumptionService::lacksWorkingPrice('gas', ['price_model' => 'dynamic']));
        self::assertFalse(ConsumptionService::lacksWorkingPrice('pv_einspeisung', []));
    }

    // ── 8  Sommer ohne Heizkurve (Menge fürs Vergleichsportal) ──────────

    /**
     * Konvexe Heizkurve mit viel Warmwasser: Die Gerade durch die Heizmonate
     * lag im Sommer auf null, mit Gewicht 0,8 fehlte das Warmwasser — die Menge
     * fürs Vergleichsportal lag zu niedrig. Jetzt: Sommer aus den gemessenen
     * Monaten, das Jahr trifft.
     */
    public function testTheYearKeepsTheHotWaterOfTheSummer(): void
    {
        $use = fn($d, $h) => 8.0 + 0.012 * $h ** 3;
        $meter = $this->formulaHousehold($use, '2023-01-01', '2026-01-01');
        $truth = 0.0;
        for ($t = strtotime('2025-01-01 12:00'); date('Y', $t) === '2025'; $t += 86400) {
            $truth += $use(date('Y-m-d', $t), max(0.0, 15.0 - self::TEMP[(int)date('n', $t)]));
        }
        $fc = $this->forecastService()->forMeter('gas', $meter);
        self::assertEqualsWithDelta($truth, $fc['annual']['value'], 0.01 * $truth, 'das Jahr wie gemessen');
        $byYm = array_column($fc['forecast'], null, 'ym');
        self::assertSame('seasonal_only', $byYm['2026-07']['method']);
        self::assertEqualsWithDelta(8.0 * 31, $byYm['2026-07']['kwh'], 1.0, 'Juli: das Warmwasser');
        $switch = new TariffSwitchService($this->forecastService(), $this->contracts, $this->meters, $this->i18n);
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Testgas', 'start' => '2023-01-01',
            'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => '2023-01-01', 'amount_eur' => 60]]]);
        self::assertEqualsWithDelta($truth, $switch->analyze('gas', (string)$meter['id'])['expected_consumption'], 0.01 * $truth, 'Menge fürs Vergleichsportal');
    }

    // ── Schritt 3 (Nachlauf auf echten Daten) ───────────────────────────

    /** Zähler ohne Wetterbezug, abgelesen an jedem Monatsersten; `$perMonth` gibt den Verbrauch eines Monats (ym). */
    private function monthlyMeter(string $utility, string $from, int $months, callable $perMonth): array
    {
        $id = $this->setMeterDevices($utility, [['id' => 'd1', 'serial' => null, 'installed_on' => $from, 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = []; $c = 0.0;
        for ($i = 0; $i <= $months; $i++) {
            $d = date('Y-m-d', strtotime("$from +$i months"));
            $rows[] = ['date' => $d, 'counter' => round($c, 3), 'device_id' => 'd1'];
            $c += $perMonth(substr($d, 0, 7));
        }
        $this->setReadings($utility, $id, $rows);
        return $this->meters->get($utility, $id);
    }

    /** Gaszähler, nur vierteljährlich abgelesen (1.1., 1.4., 1.7., 1.10.) — das Heizmodell steht auf den Abständen. */
    private function quarterlyGas(float $advance = 20.0): array
    {
        $first = date('Y-01-01', strtotime('-3 years'));
        $skip = [];
        for ($t = strtotime($first); $t <= time(); $t = strtotime('+1 month', $t)) {
            if (!in_array((int)date('n', $t), [1, 4, 7, 10], true)) $skip[] = date('Y-m-d', $t);
        }
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, $first, date('Y-m-d'), $skip);
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Testgas', 'start' => $first,
            'working_prices' => [['from' => $first, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $first, 'amount_eur' => $advance]]]);
        return $meter;
    }

    /** Schritt 3 — keine Spanne der Breite null: Fallen beide Enden ins Guthaben, kein „Abschlag zwischen 0 € und 0 €“. */
    public function testNoZeroWidthAdvanceRange(): void
    {
        $meter = $this->quarterlyGas(2000.0);
        // v3.9.0 — mit Abschlägen bis zur Abrechnung (L12): Sonst gibt es in den letzten zwei
        // Monaten vor dem Stichtag gar keine Spanne, und die Regel unten bliebe ungeprüft
        $this->anchorAhead('gas');
        $u = $this->consumption->contractStatus('gas', $meter)['contracts'][0]['projection_uncertain'];
        self::assertLessThan(0, max($u['projected_range']), 'beide Enden Guthaben');
        self::assertNull($u['suggested_range']);
        // Gegenstück: mit 20 € im Monat gibt es die Spanne, die Regel oben wird also erreicht
        $c = $this->contracts->list('gas')[0];
        $this->contracts->update('gas', (string)$c['id'], ['advance_payments' => [['from' => (string)$c['start'], 'amount_eur' => 20]]]);
        $u = $this->consumption->contractStatus('gas', $meter)['contracts'][0]['projection_uncertain'];
        self::assertIsArray($u['suggested_range'] ?? null, 'Spanne mit kleinem Abschlag');
        self::assertLessThan($u['suggested_range'][1], $u['suggested_range'][0]);
        // Werkstatt: dieselbe Regel
        $months = array_fill(0, 12, ['unit' => 0.1]);
        $adv = ScenarioService::uncertainAdvance(['method' => 'year_twelfth', 'months_left' => null, 'current' => 50.0,
            'base_recommended' => 0.0, 'scen_recommended' => 40.0], $months, array_fill(0, 12, 0.0), array_fill(0, 12, 300.0), 0.5, ['few_measured']);
        self::assertSame([null, [25.0, 55.0]], [$adv['uncertain']['base_range'], $adv['uncertain']['scen_range']], 'nichts zu schätzen: keine Spanne; sonst ± die halbe Arbeit');
        // v3.9.0 (V1) — auch die Spanne aus dem Vertragsstatus (status_*_range): gleiche Enden, keine Spanne
        $adv = ScenarioService::uncertainAdvance(['method' => 'billing_period', 'months_left' => 5, 'current' => 50.0,
            'base_recommended' => 0.0, 'scen_recommended' => 0.0, 'status_base_range' => [0.0, 0.0], 'status_scen_range' => [0.0, 0.0]],
            $months, array_fill(0, 12, 0.0), array_fill(0, 12, 0.0), 0.5, ['few_measured']);
        self::assertSame([null, null], [$adv['uncertain']['base_range'], $adv['uncertain']['scen_range']], 'Spanne der Karte mit gleichen Enden');
    }

    /** Schritt 3 — ein abgerechneter Vertrag, dessen Zeit ganz gemessen ist: nichts zu schätzen, also nicht „unsicher“ mit einer Spanne der Breite null. */
    public function testABilledContractWithNothingToEstimateIsNotUncertain(): void
    {
        $meter = $this->quarterlyGas();
        $last = date('Y-12-31', strtotime('-1 year'));
        $old = $this->contracts->list('gas')[0];
        $this->contracts->update('gas', (string)$old['id'], ['end' => $last]);
        $next = date('Y-01-01');
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Folgegas', 'start' => $next,
            'working_prices' => [['from' => $next, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $next, 'amount_eur' => 20]]]);
        $byId = array_column($this->consumption->contractStatus('gas', $meter)['contracts'], null, 'contract_id');
        self::assertTrue($byId[$old['id']]['is_past']);
        self::assertSame((float)$byId[$old['id']]['projected_end_balance'], (float)$byId[$old['id']]['current_balance'], 'nichts mehr zu schätzen');
        self::assertNull($byId[$old['id']]['projection_uncertain']);
    }

    /** Schritt 3 — der Jahresbericht nennt jeden Grund, auch drei zusammen. */
    public function testTheReportNamesEveryReason(): void
    {
        $advice = new ReportAdviceService($this->i18n);
        self::assertSame($this->i18n->t('balanceUncertain.why.few_measured'), $advice->uncertainWhy(['reasons' => ['few_measured']]));
        $three = $advice->uncertainWhy(['reasons' => ['factor_clamped', 'few_measured', 'poor_fit'], 'factor' => 1.5, 'factor_raw' => 2.1, 'fit_ratio' => 0.2]);
        self::assertStringContainsString('zu wenige Monate gemessen sind', $three);
        // v3.9.0 (L3) — in Worten statt „Faktor 2,1, gerechnet mit 1,5“
        self::assertStringContainsString('rund 2,1-mal so viel', $three);
        self::assertStringContainsString('1,5-Fachen', $three);
        self::assertStringNotContainsString('Faktor', $three);
        $low = $advice->uncertainWhy(['reasons' => ['factor_clamped'], 'factor' => 0.5, 'factor_raw' => 0.32]);
        self::assertStringContainsString('nur rund ' . $advice->share(32) . ' der Erwartung', $low);
        self::assertStringContainsString('bis ' . $advice->share(50), $low);
        self::assertStringContainsString('das Heizmodell schlecht zu diesem Zähler passt', $three);
    }

    /**
     * N3 — Rückfall über die Ablesungsabstände: Die Gütesperre kann nichts prüfen
     * (kein gemessener Monat). Saldo: Spanne über die ganze Kalibrierspanne statt
     * Betrag; Prognose ohne Band; Werkstatt mit Spanne und ohne Heizanteil.
     */
    public function testFewMeasuredMonthsGiveARangeAndNoBand(): void
    {
        $meter = $this->quarterlyGas();
        $rows = $this->consumption->forMeter('gas', $meter);
        self::assertSame('intervals', $this->consumption->heatModel('gas', $rows, $meter)['basis']);
        self::assertNull($this->anomalies->quality('gas', $rows), 'die Gütesperre kann nicht prüfen');
        $this->anchorAhead('gas');
        $c = $this->consumption->contractStatus('gas', $meter)['contracts'][0];
        $u = $c['projection_uncertain'];
        self::assertSame(['few_measured'], $u['reasons'] ?? null);
        self::assertNull($c['suggested_advance']);
        [$lo, $hi] = $u['projected_range'];
        $p = (float)$c['projected_end_balance'];
        self::assertTrue($lo < $p - 1 && $p + 1 < $hi, "$lo < $p < $hi");
        self::assertEqualsWithDelta($p - $lo, $hi - $p, 0.02, 'symmetrisch: die halbe Schätzung nach unten und oben (0,5 … 1,5)');
        self::assertIsArray($u['suggested_range']);
        self::assertLessThan($u['suggested_range'][1], $u['suggested_range'][0]);

        $fc = $this->forecastService()->forMeter('gas', $meter);
        self::assertSame(['few_measured', null], [$fc['annual']['withheld'], $fc['annual']['low']]);
        self::assertSame([null], array_values(array_unique(array_column($fc['forecast'], 'band_low'))));
        self::assertContains('few_measured', array_column($fc['warnings'], 'code'));

        $r = $this->scenario()->compute(['utility' => 'gas', 'meter_id' => $meter['id']]);
        self::assertContains('few_measured', array_column($r['warnings'], 'code'));
        self::assertSame(['few_measured'], $r['advance']['uncertain']['reasons']);
        self::assertNull($r['advance']['uncertain']['fit_ratio']);
        self::assertEqualsWithDelta(ConsumptionService::PROJECTION_FACTOR_MAX - 1, $r['advance']['uncertain']['spread'], 0.001);
        self::assertNull($r['basis']['heat_share_year']);
        self::assertNull($r['advance']['scen_recommended']);
    }

    /**
     * N3 — drei Jahre vierteljährlich, erst die letzten sieben Monate monatlich: Die
     * Gütesperre kann diese Monate prüfen, das Heizmodell steht aber noch auf den
     * Abständen (unter acht gemessenen Monaten). Auch das bleibt eine Spanne.
     */
    public function testAModelOnTheReadingIntervalsStaysUncertainEvenIfTheGateCanCheck(): void
    {
        $first = date('Y-01-01', strtotime('-3 years'));
        $monthlyFrom = date('Y-m-01', strtotime(date('Y-m-01') . ' -7 months'));
        $skip = [];
        for ($t = strtotime($first); date('Y-m-d', $t) < $monthlyFrom; $t = strtotime('+1 month', $t)) {
            if (!in_array((int)date('n', $t), [1, 4, 7, 10], true)) $skip[] = date('Y-m-d', $t);
        }
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, $first, date('Y-m-d'), $skip);
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Testgas', 'start' => $first,
            'working_prices' => [['from' => $first, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $first, 'amount_eur' => 20]]]);
        $rows = $this->consumption->forMeter('gas', $meter);
        self::assertSame('intervals', $this->consumption->heatModel('gas', $rows, $meter)['basis']);
        self::assertNotNull($this->anomalies->quality('gas', $rows), 'die Gütesperre kann prüfen');
        self::assertContains('few_measured', $this->consumption->contractStatus('gas', $meter)['contracts'][0]['projection_uncertain']['reasons'] ?? []);
        self::assertSame('few_measured', $this->forecastService()->forMeter('gas', $meter)['annual']['withheld'] ?? null);
    }

    /** N3 — ein dünner Zähler (vier gemessene Monate): Die Gütesperre kann nicht prüfen, also eine Spanne. */
    public function testAThinMeterTheQualityGateCannotCheckIsUncertain(): void
    {
        $first = date('Y-m-01', strtotime('-4 months'));
        $meter = $this->formulaHousehold(fn($d, $h) => 5.0 + 0.3 * $h, $first, date('Y-m-d'));
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Testgas', 'start' => $first,
            'working_prices' => [['from' => $first, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $first, 'amount_eur' => 80]]]);
        self::assertNull($this->anomalies->quality('gas', $this->consumption->forMeter('gas', $meter)));
        $c = $this->consumption->contractStatus('gas', $meter)['contracts'][0];
        self::assertContains('few_measured', $c['projection_uncertain']['reasons'] ?? []);
        self::assertNull($c['suggested_advance']);
    }

    /**
     * N5 — Der Faktor wirkt nur auf den Teil, aus dem er stammt: Im letzten Jahr ist
     * nur die Grundlast gestiegen (×1,4, mehr Warmwasser), die Heizung nicht. Gemessen
     * sind darin zwei Sommer- und zwei Wintermonate. Bis v3.6 skalierte ein Faktor
     * aus allen die ganze Schätzung, auch den Heizanteil eines Wintertags.
     */
    public function testTheCalibrationScalesOnlyThePartItComesFrom(): void
    {
        $first = date('Y-m-01', strtotime('-40 months'));
        $cut = date('Y-m-01', strtotime(date('Y-m-01') . ' -12 months'));
        $keep = [$cut, date('Y-m-01')];
        for ($k = 12; $k >= 1; $k--) {
            $m0 = date('Y-m-01', strtotime(date('Y-m-01') . " -$k months"));
            if (in_array((int)date('n', strtotime($m0)), [1, 2, 7, 8], true)) array_push($keep, $m0, date('Y-m-d', strtotime("$m0 +1 month")));
        }
        $skip = [];
        for ($t = strtotime("$cut +1 month"); $t <= time(); $t = strtotime('+1 month', $t)) {
            if (!in_array(date('Y-m-d', $t), $keep, true)) $skip[] = date('Y-m-d', $t);
        }
        $meter = $this->formulaHousehold(fn($d, $h) => ($d >= $cut ? 1.4 : 1.0) * 5.0 + 0.3 * $h, $first, date('Y-m-d'), $skip);
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Testgas', 'start' => $first,
            'working_prices' => [['from' => $first, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $first, 'amount_eur' => 80]]]);
        $c = $this->consumption->contractStatus('gas', $meter)['contracts'][0];
        self::assertEqualsWithDelta(1.4, $c['projection_factor_base'], 0.04, 'die Grundlast folgt dem jüngsten Niveau');
        self::assertEqualsWithDelta(1.0, $c['projection_factor_heat'], 0.04, 'der Heizanteil nicht');
        // ein Wintertag: Grundlast × 1,4 plus Heizung × 1 — nicht beides × Mischfaktor
        $rows = $this->consumption->forMeter('gas', $meter);
        $model = $this->consumption->heatModel('gas', $rows, $meter);
        $est = (new \ReflectionMethod($this->consumption, 'volumeEstimator'))->invoke($this->consumption, 'gas', $rows, 'kwh', $meter);
        $day = date('Y-m-d', strtotime($cut . ' +3 months'));
        $hdd = max(0.0, 15.0 - (float)$this->store->read('temperatures.json', [])[$day]['avg']);
        $p = (new \ReflectionProperty($this->consumption, 'lastProjection'))->getValue($this->consumption);
        self::assertEqualsWithDelta($model['a'] * $hdd * $p['heat'] + $model['c'] * $p['base'], $est($day, date('Y-m-d', strtotime("$day +1 day")))[$day], 1e-6);
        self::assertNotEqualsWithDelta($p['base'], $p['heat'], 0.2, 'zwei Faktoren, nicht einer');
    }

    /**
     * N4 — Fällt die Grundlast-Prüfung, schätzt das Modell Sommertage fast mit null:
     * Die Spanne reicht bis zur gemessenen Sommer-Grundlast (nach oben weiter als
     * nach unten). Haushalt in der Form des Melders, Heizgrenze 15 °C.
     */
    public function testAFailedBaseLoadCheckWidensTheRangeToTheMeasuredBaseLoad(): void
    {
        $noise = fn(string $d) => 1.0 + 0.01 * ((((int)substr($d, 0, 4) * 12 + (int)substr($d, 5, 2)) * 7) % 5 - 2);
        $first = date('Y-m-01', strtotime('-36 months'));
        $meter = $this->formulaHousehold(fn($d, $h, $temp) => (6.5 + 7.5 * max(0.0, 12.0 - $temp)) * $noise($d), $first, date('Y-m-d'), [], 3.0);
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Testgas', 'start' => date('Y-01-01'),
            'working_prices' => [['from' => $first, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $first, 'amount_eur' => 120]]]);
        $q = $this->anomalies->quality('gas', $this->consumption->forMeter('gas', $meter));
        self::assertContains('base_load', $q['reasons']);
        $c = $this->consumption->contractStatus('gas', $meter)['contracts'][0];
        $u = $c['projection_uncertain'];
        self::assertContains('poor_fit', $u['reasons']);
        self::assertSame([$q['base_load']['model_per_day'], $q['base_load']['measured_per_day']], [$u['base_load_per_day']['model'], $u['base_load_per_day']['measured']]);
        [$lo, $hi] = $u['projected_range'];
        $p = (float)$c['projected_end_balance'];
        self::assertGreaterThan($p - $lo + 1, $hi - $p, 'nach oben bis zur gemessenen Grundlast');
    }

    /** N1 — Wasser mit einem Wert je Kalendermonat: keine Streuung messbar, also kein Band (bis v3.6 eines der Breite null). */
    public function testOneValuePerCalendarMonthGivesNoBand(): void
    {
        $meter = $this->monthlyMeter('wasser', '2025-01-01', 12, fn($ym) => (3.0 + (int)substr($ym, 5) % 3) * 30);
        $fc = $this->forecastService()->forMeter('wasser', $meter);
        self::assertSame(['few_years', null, null], [$fc['annual']['withheld'] ?? null, $fc['annual']['low'], $fc['annual']['high']]);
        self::assertSame([null], array_values(array_unique(array_column($fc['forecast'], 'band_low'))));
    }

    /** N1 — zwei gleiche Jahre Strom: Das Band hat trotzdem die Mindestbreite (10 % je Monat), nicht ±1 bis 2 %. */
    public function testTwoEqualYearsKeepAMinimumBand(): void
    {
        $meter = $this->monthlyMeter('strom', '2024-01-01', 24, fn($ym) => 300.0 + 20 * ((int)substr($ym, 5) % 4));
        $fc = $this->forecastService()->forMeter('strom', $meter);
        $z = (float)$this->settings->get('confidence_band_sigma', 1.28);
        foreach ($fc['forecast'] as $m) {
            self::assertEqualsWithDelta(2 * $z * AnomalyService::RELATIVE_NOISE_FLOOR * $m['kwh'], $m['band_high'] - $m['band_low'], 0.3, $m['ym']);
        }
        self::assertGreaterThan(0.05 * $fc['annual']['value'], $fc['annual']['high'] - $fc['annual']['low']);
        self::assertArrayNotHasKey('withheld', $fc['annual']);
    }

    /**
     * N1 — gepoolte Streuung mit Σ(n − 1) Freiheitsgraden: zwei Jahre, im zweiten die erste
     * Jahreshälfte 30 % höher (Tagesrate 13 statt 10). Je Monat Januar–Juni (13 − 11,5)² × 2 =
     * 4,5, zusammen 27 bei 12 Freiheitsgraden → σ = 1,5 je Tag. Ein Juli ohne eigene Streuung
     * bekommt diese, über der Mindestbreite (10 % = 1,0 je Tag).
     */
    public function testThePooledSpreadCountsDegreesOfFreedomPerCalendarMonth(): void
    {
        $meter = $this->monthlyMeter('strom', '2024-01-01', 24, fn($ym) => ($ym >= '2025-01' && $ym <= '2025-06' ? 13.0 : 10.0) * (int)date('t', strtotime("$ym-01")));
        $z = (float)$this->settings->get('confidence_band_sigma', 1.28);
        $jul = array_column($this->forecastService()->forMeter('strom', $meter)['forecast'], null, 'month')[7];
        self::assertEqualsWithDelta(310.0, $jul['kwh'], 0.05);
        self::assertEqualsWithDelta(2 * $z * 1.5 * 31, $jul['band_high'] - $jul['band_low'], 0.3, 'σ = √(27 / 12) je Tag');
    }

    /**
     * N1 — ein Urlaubsmonat (halber Wert): Mit drei Jahren zieht er die Prognose nicht
     * mit (Median), mit zwei Jahren trägt sein Band den Widerspruch der Jahre.
     */
    public function testAVacationMonthDoesNotEnterTheForecastWithoutUncertainty(): void
    {
        $normal = fn($ym) => 10.0 * (int)date('t', strtotime("$ym-01"));
        $meter = $this->monthlyMeter('strom', '2023-01-01', 36, fn($ym) => $ym === '2024-08' ? $normal($ym) / 2 : $normal($ym));
        $aug = array_column($this->forecastService()->forMeter('strom', $meter)['forecast'], null, 'month')[8];
        self::assertEqualsWithDelta(310.0, $aug['kwh'], 0.5, 'drei Jahre: der Median, nicht 83 %');
        self::assertLessThan(0.75 * 310.0, $aug['band_low'], 'und ein weites Band');

        $meter = $this->monthlyMeter('strom', '2023-09-01', 24, fn($ym) => $ym === '2024-08' ? $normal($ym) / 2 : $normal($ym));
        $aug = array_column($this->forecastService()->forMeter('strom', $meter)['forecast'], null, 'month')[8];
        self::assertEqualsWithDelta(0.75 * 310.0, $aug['kwh'], 0.5, 'zwei Jahre: das Mittel');
        self::assertGreaterThanOrEqual(310.0, $aug['band_high'], 'das Band reicht bis zum gewohnten Monat');
    }

    /**
     * N2 — R1 bei nicht bestandener Gütesperre: Haushalt in der Form des Melders mit einem
     * jüngsten Wintermonat +60 %. Mit 15 °C (Gütesperre greift) meldet R1 nichts; mit 12 °C am
     * Zähler (bestanden) genau diesen Monat — die Probe ist scharf.
     */
    public function testRuleOneStaysSilentOnAFailedQualityGate(): void
    {
        $noise = fn(string $d) => 1.0 + 0.01 * ((((int)substr($d, 0, 4) * 12 + (int)substr($d, 5, 2)) * 7) % 5 - 2);
        $first = date('Y-m-01', strtotime('-36 months'));
        $spike = null;
        for ($k = 2; $k <= 13 && $spike === null; $k++) {
            $ym = date('Y-m', strtotime(date('Y-m-01') . " -$k months"));
            if (in_array((int)substr($ym, 5), [12, 1, 2], true)) $spike = $ym;
        }
        $meter = $this->formulaHousehold(fn($d, $h, $temp) => (6.5 + 7.5 * max(0.0, 12.0 - $temp)) * $noise($d) * (substr($d, 0, 7) === $spike ? 1.6 : 1.0),
            $first, date('Y-m-d'), [], 3.0);
        $recs = new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $rule = new \ReflectionMethod($recs, 'ruleWeatherIndependent');
        $rows = $this->consumption->forMeter('gas', $meter);
        self::assertSame('poor', $this->anomalies->quality('gas', $rows)['model_quality']);
        self::assertSame([], $rule->invoke($recs, 'gas', $meter, $rows), '15 °C: Gütesperre greift, R1 schweigt');
        $this->meters->update('gas', (string)$meter['id'], ['hdd_base_temp' => 12.0]);
        $meter = $this->meters->get('gas', (string)$meter['id']);
        $rows = $this->consumption->forMeter('gas', $meter);
        self::assertSame('good', $this->anomalies->quality('gas', $rows)['model_quality']);
        self::assertSame([$spike], array_map(fn($r) => $r['evidence']['ym'] ?? null, $rule->invoke($recs, 'gas', $meter, $rows)), '12 °C: R1 meldet den Monat');
    }

    /**
     * N2 — Gütesperre nicht bestanden: keine Monate „mehr als das Wetter erklärt“ (R1),
     * im Jahresbericht kein witterungsbereinigter Vergleich, ein Hinweis an seiner Stelle.
     */
    public function testAFailedQualityGateDropsWeatherAdjustedComparisonsWithANote(): void
    {
        $this->loadMessy();
        $this->store->write('strom/meters.json', []);   // nur die Heizart: Kachel und Ampel hängen dann an ihr
        $meter = $this->meters->list('gas')[0];
        $rows = $this->consumption->forMeter('gas', $meter);
        self::assertSame('poor', $this->anomalies->quality('gas', $rows)['model_quality']);
        // dieselben Monate mit Ausreißern: R1 meldet bei nicht bestandener Gütesperre nichts
        $rule = new \ReflectionMethod(\Energietracker\Services\RecommendationService::class, 'ruleWeatherIndependent');
        $recs = new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        self::assertSame([], $rule->invoke($recs, 'gas', $meter, $rows));

        $year = (int)date('Y') - 1;
        $m = $this->reportServices()['model']->build($year, ['detail' => true]);
        $gas = array_column($m['pages'], null, 'utility')['gas'];
        self::assertNull($gas['weather'], 'kein „kälter oder mehr verbraucht?“');
        self::assertNotSame([], array_filter($gas['notes'], fn($n) => str_contains($n, 'vergleicht hier nicht witterungsbereinigt')), 'der Hinweis an seiner Stelle');
        $table = array_column($m['appendix'], null, 'utility')['gas'];
        $col = array_search($this->i18n->t('report.tableWeatherAdj'), $table['head'], true);
        $delta = array_search($this->i18n->t('report.tableDelta'), $table['head'], true);
        self::assertSame([['–'], ['–']], [array_values(array_unique(array_column($table['rows'], $col))), array_values(array_unique(array_column($table['rows'], $delta)))]);
        self::assertNotSame([], array_filter($table['notes'], fn($n) => str_contains($n, 'vergleicht hier nicht witterungsbereinigt')));
        self::assertNotContains($this->i18n->t('report.weatherNote'), $table['notes']);
        $rules = array_column(array_merge($m['tasks'], $m['successes'], $m['infos']), 'rule');
        self::assertSame([], array_values(array_intersect($rules, ['heatingRise', 'heatingFall', 'anomaly'])), 'keine bereinigten Vergleiche, keine Monate über der Erwartung');
        $consumption = array_column($m['headline']['lights'], null, 'key')['consumption'];
        self::assertStringNotContainsString('witterungsbereinigt', $consumption['detail']);
    }

    // ── Nachlauf vor dem Release: eine Quelle für „unsicher“ ────────────

    private function summaryService(): \Energietracker\Services\SummaryService
    {
        $benchmark = new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
        $deliveries = new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings, $benchmark, $deliveries, $this->i18n);
        $tenancies = new \Energietracker\Services\TenancyService($this->store, $this->meters, $this->i18n);
        $co2 = new \Energietracker\Services\Co2CostService($this->store, $this->settings, $this->meters, $this->consumption, $this->contracts, $this->i18n, $tenancies);
        $agenda = new \Energietracker\Services\AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new \Energietracker\Services\ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n, $tenancies,
            new \Energietracker\Services\Co2SplitService($co2, $tenancies, $this->settings, $this->i18n));
        return new \Energietracker\Services\SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            $this->forecastService(), $deliveries, new \Energietracker\Services\PvSummaryService($this->consumption),
            new \Energietracker\Services\StromSaldoService($this->consumption), $agenda, new \Energietracker\Services\InstanceService($this->store));
    }

    /** Haushalt in der Form des Melders (Gütesperre fällt bei 15 °C, Grundlast), im letzten Jahr × `$recent`. */
    private function reporterHousehold(float $recent, array $contract = []): array
    {
        $noise = fn(string $d) => 1.0 + 0.01 * ((((int)substr($d, 0, 4) * 12 + (int)substr($d, 5, 2)) * 7) % 5 - 2);
        $first = date('Y-m-01', strtotime('-36 months'));
        $cut = date('Y-m-01', strtotime(date('Y-m-01') . ' -12 months'));
        $meter = $this->formulaHousehold(fn($d, $h, $temp) => ($d >= $cut ? $recent : 1.0) * (6.5 + 7.5 * max(0.0, 12.0 - $temp)) * $noise($d),
            $first, date('Y-m-d'), [], 3.0);
        $start = date('Y-01-01');
        $this->contracts->create('gas', $contract + ['meter_id' => $meter['id'], 'provider' => 'Testgas', 'start' => $start,
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $start, 'amount_eur' => 120]]]);
        return $meter;
    }

    /**
     * Nachlauf vor dem Release (Wirklichkeitsabgleich) — Gütesperre fällt (Grundlast etwa ein
     * Fünftel des gemessenen Sommers), und das letzte Jahr liegt weit über dem Modell (Faktor
     * klemmt). Vertragsstatus, Übersicht und Werkstatt nennen dieselben Gründe. Geschätzt wird
     * mit einem Faktor für das Ganze: Die Aufteilung auf Grundlast und Heizanteil (N5) setzt ein
     * Modell voraus, das beide richtig trennt — genau das fehlt hier.
     */
    public function testAFailedGateWithAClampedFactorIsUncertainInEveryEndpoint(): void
    {
        $meter = $this->reporterHousehold(2.2);
        $q = $this->anomalies->quality('gas', $this->consumption->forMeter('gas', $meter));
        self::assertSame('poor', $q['model_quality']);
        self::assertContains('base_load', $q['reasons']);
        $cur = fn(array $rows) => array_values(array_filter($rows, fn($c) => !empty($c['is_current'])))[0];
        $c = $cur($this->consumption->contractStatus('gas', $meter)['contracts']);
        $u = $c['projection_uncertain'];
        self::assertSame(['factor_clamped', 'poor_fit'], $u['reasons'] ?? null);
        self::assertSame(ConsumptionService::PROJECTION_FACTOR_MAX, $u['factor']);
        self::assertSame([1.5, 1.5], [$c['projection_factor_base'], $c['projection_factor_heat']], 'ein Faktor für das Ganze, nicht aufgeteilt');
        self::assertNull($c['suggested_advance']);
        // Übersicht (Home Assistant): derselbe Wert
        $sm = array_values(array_filter($this->summaryService()->build('gas')['meters'], fn($m) => ($m['contract'] ?? null) !== null))[0];
        self::assertSame($u, $sm['contract']['projection_uncertain']);
        // Werkstatt: dieselben Gründe, Abschlag nur als Spanne
        $r = $this->scenario()->compute(['utility' => 'gas', 'meter_id' => $meter['id']]);
        self::assertSame([], array_values(array_diff($u['reasons'], $r['advance']['uncertain']['reasons'] ?? [])));
        self::assertNull($r['advance']['scen_recommended']);
    }

    /**
     * Nachlauf — ein klemmender Teilfaktor zählt: Gütesperre bestanden, im letzten Jahr nur die
     * Grundlast × 1,6 (gemessen sind Januar, Februar, Juli, August). Der Grundlast-Faktor
     * klemmt an 1,5, der Heizanteil nicht — trotzdem unsicher, mit `factor_part: base`.
     */
    public function testAClampedPartialFactorCounts(): void
    {
        $first = date('Y-m-01', strtotime('-40 months'));
        $cut = date('Y-m-01', strtotime(date('Y-m-01') . ' -12 months'));
        $keep = [$cut, date('Y-m-01')];
        for ($k = 12; $k >= 1; $k--) {
            $m0 = date('Y-m-01', strtotime(date('Y-m-01') . " -$k months"));
            if (in_array((int)date('n', strtotime($m0)), [1, 2, 7, 8], true)) array_push($keep, $m0, date('Y-m-d', strtotime("$m0 +1 month")));
        }
        $skip = [];
        for ($t = strtotime("$cut +1 month"); $t <= time(); $t = strtotime('+1 month', $t)) {
            if (!in_array(date('Y-m-d', $t), $keep, true)) $skip[] = date('Y-m-d', $t);
        }
        $meter = $this->formulaHousehold(fn($d, $h) => ($d >= $cut ? 1.6 : 1.0) * 5.0 + 0.3 * $h, $first, date('Y-m-d'), $skip);
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Testgas', 'start' => $first,
            'working_prices' => [['from' => $first, 'ct_per_kwh' => 10]], 'advance_payments' => [['from' => $first, 'amount_eur' => 80]]]);
        $qq = $this->anomalies->quality('gas', $this->consumption->forMeter('gas', $meter)); self::assertSame('good', $qq['model_quality'], json_encode([$qq['reasons'], $qq['ratio'], $qq['base_load']]));
        $c = $this->consumption->contractStatus('gas', $meter)['contracts'][0];
        self::assertSame([1.5, 'base', ['factor_clamped']], [$c['projection_factor_base'], $c['projection_uncertain']['factor_part'] ?? null, $c['projection_uncertain']['reasons'] ?? null]);
        self::assertGreaterThan(ConsumptionService::PROJECTION_FACTOR_MIN, $c['projection_factor_heat']);
        self::assertLessThan(ConsumptionService::PROJECTION_FACTOR_MAX, $c['projection_factor_heat']);
    }

    /** Nachlauf — unsicher bleibt unsicher, auch wenn für die geschätzten Tage kein Arbeitspreis gilt (Betrag 0 €, Menge nicht). */
    public function testUncertaintyDoesNotVanishWithoutAUnitPrice(): void
    {
        $meter = $this->reporterHousehold(1.0, ['working_prices' => [], 'base_prices' => [['from' => date('Y-01-01'), 'eur_per_month' => 10]]]);
        $c = array_values(array_filter($this->consumption->contractStatus('gas', $meter)['contracts'], fn($c) => !empty($c['is_current'])))[0];
        self::assertTrue($c['price_missing']);
        self::assertContains('poor_fit', $c['projection_uncertain']['reasons'] ?? []);
    }

    /**
     * Nachlauf (V7) — eine geschätzte Ablesung als Stütze in einem langen Abstand: Die Monate
     * sind verteilt, aber nicht „gleichmäßig“ (je Seite eine eigene Tagesrate). Die Monatszeile
     * sagt es (`reading_gap_estimated`); ein Abstand ohne Schätzung bleibt ohne Kennzeichen.
     */
    public function testAnEstimatedSupportReadingIsNamed(): void
    {
        $id = $this->setMeterDevices('strom', [['id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->setReadings('strom', $id, [
            ['date' => '2024-01-01', 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => '2024-03-01', 'counter' => 600.0, 'device_id' => 'd1', 'is_estimated' => true],
            ['date' => '2024-05-01', 'counter' => 900.0, 'device_id' => 'd1'],
            ['date' => '2024-09-01', 'counter' => 1500.0, 'device_id' => 'd1'],
        ]);
        $rows = array_column($this->consumption->forMeter('strom', $this->meters->get('strom', $id)), null, 'ym');
        self::assertSame([true, true], [$rows['2024-02']['interpolated'], $rows['2024-02']['reading_gap_estimated']], 'Januar bis April: verteilt, mit Stütze');
        self::assertSame([true, false], [$rows['2024-06']['interpolated'], $rows['2024-06']['reading_gap_estimated']], 'Mai bis August: verteilt, gleichmäßig');
        self::assertNotEqualsWithDelta($rows['2024-02']['kwh'] / 29, $rows['2024-04']['kwh'] / 30, 0.5, 'zwei Tagesraten');
    }

    // ── Doku ────────────────────────────────────────────────────────────

    /** Doku-Behauptungen brauchen Tests: Raster, Schwellen und Grenzen in Grundlagen (DE/EN) sind die Konstanten. */
    public function testTheDocsNameTheLimitsThatApply(): void
    {
        $root = dirname(__DIR__, 3) . '/docs/';
        [$from, $to, $step] = ConsumptionService::HEAT_LIMIT_GRID;
        $gain = (int)round((1 - ConsumptionService::HEAT_LIMIT_GAIN) * 100);
        $grids = ['verstehen/00-overview.md' => 'für Heizgrenzen von %s bis %s °C in halben Grad', 'en/verstehen/00-overview.md' => 'for heating limits from %s to %s °C in half-degree steps'];
        foreach (['verstehen/00-overview.md' => [',', 'bis', 'Prozentpunkte'], 'en/verstehen/00-overview.md' => ['.', 'to', 'percentage points']] as $file => [$dec, $bis, $points]) {
            $text = (string)file_get_contents($root . $file);
            $n = fn(float $v) => str_replace('.', $dec, rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.'));
            self::assertStringContainsString("{$n($from)} $bis {$n($to)} °C", $text, "$file: Raster des Vorschlags");
            self::assertStringContainsString("$gain %", $text, "$file: deutlich = ein Drittel weniger");
            self::assertStringContainsString((int)round(ConsumptionService::HEAT_LIMIT_MIN_DROP * 100) . " $points", $text, $file);
            self::assertStringContainsString(ConsumptionService::HEAT_LIMIT_MIN_MONTHS . ' ', $text, "$file: gemessene Monate");
            self::assertStringContainsString(sprintf($grids[$file], $n(ClimateNormalService::BASE_MIN), $n(ClimateNormalService::BASE_MAX)), str_replace("\n", ' ', $text), "$file: Raster des Klimanormals");
            self::assertStringContainsString("{$n(ConsumptionService::PROJECTION_FACTOR_MIN)} $bis {$n(ConsumptionService::PROJECTION_FACTOR_MAX)}", $text, "$file: Grenzen des Faktors");
            // v3.7.0 (Schritt 3) — Heizanteil der Kalibrierung, Freiheitsgrade des Bands, Untergrenze je Monat
            $flat = str_replace("\n", ' ', $text);
            self::assertStringContainsString((int)round(ConsumptionService::CALIBRATION_HEAT_SHARE * 100) . ' %', $flat, "$file: Heizanteil der Kalibrierung");
            self::assertMatchesRegularExpression('/(Unter|Below) ' . ForecastService::BAND_MIN_DOF . ' /', $flat, "$file: Freiheitsgrade");
            self::assertStringContainsString((int)round(AnomalyService::RELATIVE_NOISE_FLOOR * 100) . ' %', $flat, "$file: Untergrenze je Monat");
            // v3.7.1 (#38) — Sommer-Referenz und Grenze des Vorschlags
            self::assertStringContainsString(($dec === ',' ? 'ohne Heizgradtage zu ' : 'no degree days at ') . (int)AnomalyService::SUMMER_REF_BASE . ' °C', $flat, "$file: Sommer-Referenz");
            self::assertStringContainsString($dec === ',' ? 'Juni bis August' : 'June to August', $flat, "$file: festes warmes Fenster");
            self::assertSame([6, 7, 8], AnomalyService::SUMMER_REF_MONTHS);
            self::assertStringContainsString('höchstens ' . (int)round((ConsumptionService::HEAT_LIMIT_SUMMER_MAX - 1) * 100) . ' %', str_replace('at most', 'höchstens', $flat), "$file: Sommer höchstens 10 % überschätzt");
        }
    }
}
