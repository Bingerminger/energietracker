<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AnomalyService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (Paket interpolation, GitHub #37–#39) — ein unordentlicher Haushalt.
 *
 * #37, #38 und #39 fand ein Nutzer mit echten Daten, nicht unsere Tests: Die
 * Beispielhaushalte rechnen mit dem Modell der App und werden regelmäßig
 * abgelesen, darauf passt alles. Dieser Haushalt (tests/fixtures/
 * messy-household/, erzeugt von generate.mjs mit eigener Physik) hat eine
 * konvexe Heizkurve, Lücken, eine geschätzte Ablesung, einen Zählertausch
 * mitten im Monat, PV mit zwei Ablesungen und Strom einmal im Jahr. Verlangt
 * wird, dass die App Unsicherheit zeigt statt sicher wirkender Zahlen — und
 * dass Saldo und Kosten trotzdem die Summe zwischen den Ablesungen sind.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(AnomalyService::class)]
#[CoversClass(ForecastService::class)]
#[CoversClass(PvSummaryService::class)]
final class MessyHouseholdTest extends ServiceTestCase
{
    private const DIR = __DIR__ . '/../../fixtures/messy-household/';

    /** Monate, die aus Abständen über 45 Tage stammen — aus den Ablesetagen des Generators abgeleitet. */
    private const GAS_INTERPOLATED = [
        '2023-07', '2023-08',              // 3.7. → 29.8. (57 Tage)
        '2024-01', '2024-02',              // 30.12. → 2.3., dazwischen nur die geschätzte am 10.2.
        '2024-09', '2024-10', '2024-11',   // Herbst: 7.9. → 19.12. (103 Tage)
        '2025-06', '2025-07', '2025-08',   // Sommerpause: 3.6. → 2.9.
    ];

    private array $h = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->h = json_decode((string)file_get_contents(self::DIR . 'household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $this->h['settings']);
        $this->store->write('temperatures.json', $this->h['temperatures']);
        // die Standardzähler der frischen Installation weg, der Haushalt bringt seine eigenen
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        foreach ($this->h['utilities'] as $u => $pots) foreach ($pots as $pot => $rows) $this->store->write("$u/$pot.json", $rows);
    }

    private function meter(string $u): array
    {
        return $this->meters->list($u)[0];
    }

    private function monthly(string $u): array
    {
        return $this->consumption->forMeter($u, $this->meter($u));
    }

    private static function interpolatedYms(array $monthly): array
    {
        return array_values(array_map(fn($m) => $m['ym'], array_filter($monthly, fn($m) => !empty($m['interpolated']))));
    }

    private function forecast(string $u): array
    {
        return (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))->forMeter($u, $this->meter($u));
    }

    /** Der Haushalt hat, was die Doku verspricht (Generator, Ablesetage, Zähler). */
    public function testTheHouseholdIsAsMessyAsDocumented(): void
    {
        $gas = $this->h['utilities']['gas']['readings'];
        $dates = array_column($gas, 'date');
        $gaps = [];
        for ($i = 1; $i < count($dates); $i++) $gaps[$dates[$i]] = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
        self::assertGreaterThan(92, $gaps['2024-12-19'], 'eine Lücke über drei Monate im Herbst');
        self::assertGreaterThan(80, $gaps['2025-09-02'], 'eine Sommerpause');
        $byMonth = array_count_values(array_map(fn($d) => substr($d, 0, 7), $dates));
        self::assertContains(2, $byMonth, 'zwei Ablesungen im selben Monat');
        self::assertCount(1, array_filter($gas, fn($r) => $r['is_estimated']), 'eine geschätzte Ablesung');
        $devices = $this->h['utilities']['gas']['meters'][0]['devices'];
        self::assertCount(2, $devices);
        self::assertNotSame('01', substr((string)$devices[0]['removed_on'], 8, 2), 'Zählertausch mitten im Monat');
        $pv = array_column($this->h['utilities']['pv_erzeugung']['readings'], 'date');
        self::assertCount(2, $pv);
        self::assertGreaterThan(730, (strtotime($pv[1]) - strtotime($pv[0])) / 86400, 'PV: zwei Ablesungen über mehr als zwei Jahre');
        self::assertGreaterThan(24, count($this->h['utilities']['pv_einspeisung']['readings']), 'Einspeisung monatlich');
        $strom = array_column($this->h['utilities']['strom']['readings'], 'date');
        self::assertSame(count($strom), count(array_unique(array_map(fn($d) => substr($d, 0, 4), $strom))), 'Strom: eine Ablesung je Jahr');
        // eigene Physik, nicht das App-Modell: Heizgrenze unter den 15 °C der App
        self::assertLessThan(15.0, $this->h['params']['HEAT_LIMIT_C']);
    }

    /** Die Datei ist, was der Generator schreibt (fester Seed; `--check` vergleicht nur). */
    public function testTheFixtureIsWhatTheGeneratorWrites(): void
    {
        exec('command -v node 2>/dev/null', $which, $found);
        if ($found !== 0) self::markTestSkipped('node fehlt');
        exec('node ' . escapeshellarg(self::DIR . 'generate.mjs') . ' --check 2>&1', $out, $rc);
        self::assertSame(0, $rc, implode("\n", $out));
    }

    // ── Unsicherheit zeigen ───────────────────────────────────────────────

    public function testInterpolatedMonthsAreMarked(): void
    {
        $gas = $this->monthly('gas');
        self::assertSame(self::GAS_INTERPOLATED, self::interpolatedYms($gas));
        $by = array_column($gas, null, 'ym');
        self::assertFalse($by['2023-11']['interpolated'], 'zwei Ablesungen im Monat: gemessen');
        self::assertTrue($by['2025-02']['device_swap'] && !$by['2025-02']['interpolated'], 'Tausch-Monat: gemessen, eigene Kennung');
        // Strom einmal im Jahr, PV-Erzeugung zweimal: jeder Monat verteilt; Einspeisung monatlich: keiner
        foreach (['strom', 'pv_erzeugung'] as $u) {
            $m = $this->monthly($u);
            self::assertSame(count($m), count(self::interpolatedYms($m)), $u);
        }
        self::assertSame([], self::interpolatedYms($this->monthly('pv_einspeisung')));
        // warum: Ein verteilter Herbstmonat liegt weit neben dem, was wirklich verbraucht wurde
        $truth = $this->h['truth']['gas_kwh'];
        self::assertGreaterThan(0.15, abs($by['2024-09']['kwh'] - $truth['2024-09']) / $truth['2024-09'], 'September 2024 verteilt: mehr als 15 % daneben');
    }

    public function testTheAnomalyTableReportsNoMonthThatIsOnlyInterpolated(): void
    {
        foreach (['gas', 'strom', 'pv_erzeugung', 'pv_einspeisung'] as $u) {
            $monthly = $this->monthly($u);
            $found = array_column($this->anomalies->detect($u, $monthly), 'ym');
            self::assertSame([], array_values(array_intersect($found, self::interpolatedYms($monthly))), $u);
        }
    }

    /** Die konvexe Heizkurve: Das lineare Modell drückt die Grundlast fast auf null — die Tabelle wäre leer und blind. */
    public function testTheQualityGateFiresOnTheConvexCurve(): void
    {
        $monthly = $this->monthly('gas');
        $model = $this->consumption->heatModel('gas', $monthly, $this->meter('gas'));
        self::assertLessThan(1.0, $model['c'], 'Grundlast je Tag fast null, obwohl im Sommer Warmwasser läuft');
        $q = $this->anomalies->quality('gas', $monthly);
        self::assertSame('poor', $q['model_quality']);
        // beide Prüfungen schlagen an: Streuung gegen den mittleren gemessenen Monat …
        self::assertContains('scatter', $q['reasons']);
        self::assertGreaterThan(AnomalyService::POOR_FIT_RATIO, $q['ratio']);
        self::assertEqualsWithDelta($q['resid_scale'] / $q['mean_month'], $q['ratio'], 0.002, 'gegen den mittleren gemessenen Monat, nicht den Median der Erwartung');
        self::assertLessThan($q['ratio'], $q['resid_scale'] / $q['typical'], 'der Median der Erwartung ließe das Modell besser aussehen');
        // … und die Grundlast gegen gemessene Sommermonate
        self::assertContains('base_load', $q['reasons']);
        self::assertLessThan(AnomalyService::BASE_LOAD_MIN, $q['base_load']['ratio']);
        self::assertGreaterThan(4.0, $q['base_load']['measured_per_day'], 'im Sommer läuft Warmwasser');
        self::assertGreaterThan(0.3 * $q['typical'], $q['detect_floor'], 'erkennt erst ab mehr als 30 % eines typischen Monats');
        self::assertSame([], $this->anomalies->detect('gas', $monthly), 'leer — und die Gütesperre sagt, warum das nichts heißt');
    }

    public function testThePvHintAppears(): void
    {
        $pv = (new PvSummaryService($this->consumption, $this->settings))->compute();
        self::assertContains('interpolated', $pv['hints']);
        self::assertSame('pv_erzeugung', $pv['interpolation']['utility']);
        self::assertSame(2, $pv['interpolation']['readings']);
        $covered = array_values(array_filter($pv['monthly'], fn($m) => $m['covered']));
        self::assertNotSame([], $covered);
        self::assertSame([], array_values(array_filter($covered, fn($m) => !$m['interpolated'])), 'jeder Monat mit Eigenverbrauch ist geschätzt');
    }

    /** Prognose und Erwartung verschwinden nicht still: Sie rechnen weiter und sagen, worauf. */
    public function testForecastAndExpectationDoNotVanishSilently(): void
    {
        $gas = $this->monthly('gas');
        foreach ($gas as $m) {
            if (\Energietracker\Services\ConsumptionService::hasTemperatureCoverage($m)) self::assertNotNull($m['expected_heat'], $m['ym']);
        }
        $fc = $this->forecast('gas');
        self::assertTrue($fc['valid']);
        self::assertCount(12, $fc['forecast']);
        self::assertNotNull($fc['heat_model']);
        $w = array_values(array_filter($fc['warnings'], fn($x) => $x['code'] === 'interpolated'));
        self::assertSame(count(self::GAS_INTERPOLATED), $w[0]['months'] ?? null);
        foreach (['strom', 'pv_erzeugung'] as $u) {
            $f = $this->forecast($u);
            self::assertTrue($f['valid'], $u);
            self::assertCount(12, $f['forecast'], $u);
            self::assertGreaterThan(0.0, array_sum(array_column($f['forecast'], 'kwh')), $u);
            self::assertContains('interpolated', array_column($f['warnings'], 'code'), $u);
            self::assertSame(['seasonal_interpolated'], array_values(array_unique(array_column($f['forecast'], 'method'))), "$u: nur verteilte Werte — gesagt, nicht versteckt");
        }
        $info = $this->consumption->interpolationInfo('strom', $this->meter('strom'), $this->monthly('strom'));
        self::assertSame(count($this->h['utilities']['strom']['readings']), $info['readings']);
    }

    /** Saldo und Kosten: gleich der Summe zwischen den Ablesungen, verteilt oder nicht. */
    public function testCostsAndBalanceAreTheTotalsBetweenReadings(): void
    {
        $r = $this->h['utilities']['gas']['readings'];
        $dev = $this->h['utilities']['gas']['meters'][0]['devices'];
        $m3 = ((float)$dev[0]['final_counter'] - (float)$r[0]['counter']) + ((float)end($r)['counter'] - (float)$dev[1]['initial_counter']);
        $kwh = $m3 * $this->h['params']['KWH_PER_M3'];
        $gas = $this->monthly('gas');
        $n = count($gas);
        self::assertEqualsWithDelta($m3, array_sum(array_column($gas, 'm3')), 0.05 * $n, 'm³ über alle Monate = Summe zwischen den Ablesungen');
        self::assertEqualsWithDelta($kwh, array_sum(array_column($gas, 'kwh')), 0.05 * $n);
        self::assertEqualsWithDelta($kwh * 0.112, array_sum(array_column($gas, 'kwh_cost')), 0.01 * $n, 'Arbeitspreis × Menge');
        // v3.9.0 (V1) — die Karte rechnet über die laufende Abrechnungsperiode, das
        // Abrechnungsjahr über alle: zusammen wieder die Summe zwischen den Ablesungen
        $bills = new \Energietracker\Services\BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bp = (new \Energietracker\Services\BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills))
            ->forTarget('gas', $this->meter('gas'));
        self::assertEqualsWithDelta($kwh, array_sum(array_column($bp['periods'], 'kwh')), 0.05 * $n, 'Abrechnungsjahre zusammen = Summe zwischen den Ablesungen');
        $c = $this->consumption->contractStatus('gas', $this->meter('gas'))['contracts'][0];
        $inWindow = array_sum(array_map(fn($m) => (float)$m['kwh'], array_filter($gas, fn($m) => $m['ym'] >= substr((string)$c['balance_from'], 0, 7))));
        self::assertEqualsWithDelta($inWindow, $c['actual_kwh'], 0.05, 'Karte = Monate der laufenden Periode');
        self::assertEqualsWithDelta($c['cost_to_date'] - $c['advance_paid'], $c['current_balance'], 0.02);

        $s = array_column($this->h['utilities']['strom']['readings'], 'counter');
        $strom = $this->monthly('strom');
        self::assertEqualsWithDelta(end($s) - $s[0], array_sum(array_column($strom, 'kwh')), 0.05 * count($strom));
        self::assertEqualsWithDelta((end($s) - $s[0]) * 0.34, array_sum(array_column($strom, 'kwh_cost')), 0.01 * count($strom));
        $p = array_column($this->h['utilities']['pv_erzeugung']['readings'], 'counter');
        self::assertEqualsWithDelta($p[1] - $p[0], array_sum(array_column($this->monthly('pv_erzeugung'), 'kwh')), 0.05 * 30);
    }

    /** Doku-Behauptung (docs/entwicklung/tests.md DE/EN): Zweck, Ort und Heizgrenze des Generators. */
    public function testTheTestsDocDescribesTheHousehold(): void
    {
        $root = dirname(__DIR__, 3) . '/docs/';
        $limit = $this->h['params']['HEAT_LIMIT_C'];
        foreach (['entwicklung/tests.md' => str_replace('.', ',', (string)$limit) . ' °C', 'en/entwicklung/tests.md' => $limit . ' °C'] as $file => $limitText) {
            $text = (string)file_get_contents($root . $file);
            self::assertStringContainsString('tests/fixtures/messy-household/', $text, $file);
            self::assertStringContainsString('MessyHouseholdTest', $text, $file);
            self::assertStringContainsString($limitText, $text, "$file: Heizgrenze des Generators");
        }
        self::assertStringContainsString("HEAT_LIMIT_C: $limit,", (string)file_get_contents(self::DIR . 'generate.mjs'));
    }
}
