<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\IntervalService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\ScenarioPv;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 (F1040, Phase 2) — Prognose-Werkstatt für die PV.
 *
 * Bausteine mit festen, von Hand gerechneten Monaten und Viertelstunden; dazu ein erfundener
 * Haushalt mit Monatswerten (Spanne) und einer mit einem vollen Jahr Viertelstunden (ein Wert).
 * Das Datum ist fest (`$today`), kein Test hängt am Tag des Laufs.
 */
#[CoversClass(ScenarioPv::class)]
#[CoversClass(ScenarioService::class)]
final class ScenarioPvTest extends ServiceTestCase
{
    private const TODAY = '2026-03-10';
    /** Erfundene Sonne je Monat (kWh). */
    private const SUN = [1 => 120, 2 => 210, 3 => 420, 4 => 610, 5 => 760, 6 => 820, 7 => 800, 8 => 700, 9 => 500, 10 => 300, 11 => 140, 12 => 90];
    private const LV = ['k' => 1.0, 'shift' => 0.0, 'battery' => 0.0, 'feed_ct' => null, 'eta' => 0.9, 'rate' => 0.5];

    private function service(?IntervalService $iv = null): ScenarioService
    {
        $forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $switch = new TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $billing = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills);
        return new ScenarioService($forecast, $this->consumption, $this->contracts, $this->meters, $this->settings, $switch, $billing,
            new PvSummaryService($this->consumption, $this->settings), $iv);
    }

    // ── Ein Monat ohne Viertelstunden, von Hand ─────────────────────────

    /**
     * Erzeugung 500, Einspeisung 300, Bezug 200 kWh: 200 kWh Sonne im Haus, 200 kWh Netz im Haus.
     * Anlage + 50 %: 250 kWh mehr Sonne. Unten geht alles ins Netz (Einspeisung 550, Bezug 200),
     * oben deckt sie höchstens min(250, Netzbezug 200, 0,5 × 200 = 100) = 100 (Einspeisung 450, Bezug 100).
     */
    public function testAnlageGroesserSpanneVonHand(): void
    {
        $b = ['gen' => 500.0, 'feed' => 300.0, 'import' => 200.0, 'charge' => 0.0, 'discharge' => 0.0];
        $lv = ['k' => 1.5] + self::LV;
        $lo = ScenarioPv::monthEdge($b, 30, $lv, false);
        $hi = ScenarioPv::monthEdge($b, 30, $lv, true);
        self::assertEqualsWithDelta(['gen' => 750.0, 'feed' => 550.0, 'import' => 200.0, 'charge' => 0.0, 'discharge' => 0.0], $lo, 1e-6);
        self::assertEqualsWithDelta(['gen' => 750.0, 'feed' => 450.0, 'import' => 100.0, 'charge' => 0.0, 'discharge' => 0.0], $hi, 1e-6);
    }

    /**
     * Speicher 5 kWh, 30 Tage: oben geladen min(150, Einspeisung 300, Bezug 200 ÷ 0,9 = 222,2) = 150,
     * entladen 0,9 × 150 = 135 (Einspeisung 150, Bezug 65); unten bleibt er leer.
     * Eigenverbrauch + 10 %: 20 kWh wandern aus Bezug und Einspeisung (180 und 280), in beiden Grenzen.
     */
    public function testSpeicherUndVerlagernVonHand(): void
    {
        $b = ['gen' => 500.0, 'feed' => 300.0, 'import' => 200.0, 'charge' => 0.0, 'discharge' => 0.0];
        $bat = ['battery' => 5.0] + self::LV;
        self::assertEqualsWithDelta(['gen' => 500.0, 'feed' => 150.0, 'import' => 65.0, 'charge' => 150.0, 'discharge' => 135.0], ScenarioPv::monthEdge($b, 30, $bat, true), 1e-6);
        self::assertEqualsWithDelta($b, ScenarioPv::monthEdge($b, 30, $bat, false), 1e-6);
        $shift = ['shift' => 0.1] + self::LV;
        foreach ([false, true] as $edge) {
            self::assertEqualsWithDelta(['gen' => 500.0, 'feed' => 280.0, 'import' => 180.0, 'charge' => 0.0, 'discharge' => 0.0], ScenarioPv::monthEdge($b, 30, $shift, $edge), 1e-6);
        }
        // alles zusammen, obere Grenze: +100 Sonne im Haus, dann 10 % von 300 = 30 verlagert (Bezug 70,
        // Einspeisung 420), dann der Speicher: min(150, 420, 70 ÷ 0,9 = 77,78) → Bezug 0, Einspeisung 342,22
        $all = ScenarioPv::monthEdge($b, 30, ['k' => 1.5, 'shift' => 0.1, 'battery' => 5.0] + self::LV, true);
        self::assertEqualsWithDelta(['gen' => 750.0, 'feed' => 342.2222, 'import' => 0.0, 'charge' => 77.7778, 'discharge' => 70.0], $all, 1e-4);
        // Plausibel: nie mehr selbst genutzt als erzeugt, nie mehr geladen als die Kapazität je Tag
        self::assertLessThanOrEqual($all['gen'], $all['gen'] - $all['feed']);
        self::assertLessThanOrEqual(5.0 * 30, $all['charge']);
        // Verlagern höchstens bis zur Einspeisung: 50 % von 450 = 225, eingespeist sind nur 50 → Einspeisung 0, Bezug 150
        $tight = ['gen' => 500.0, 'feed' => 50.0, 'import' => 200.0, 'charge' => 0.0, 'discharge' => 0.0];
        self::assertEqualsWithDelta(['gen' => 500.0, 'feed' => 0.0, 'import' => 150.0, 'charge' => 0.0, 'discharge' => 0.0],
            ScenarioPv::monthEdge($tight, 30, ['shift' => 0.5] + self::LV, true), 1e-6);
    }

    /**
     * Das Monatsbeispiel der Anleitung (DE und EN, nach `<!-- doc-claim: werkstatt-pv-month-example`):
     * die Zahlen der Grenzen sind die gerechneten, in derselben Reihenfolge.
     */
    public function testDasBeispielDerAnleitungRechnetDieApp(): void
    {
        $b = ['gen' => 500.0, 'feed' => 300.0, 'import' => 200.0, 'charge' => 0.0, 'discharge' => 0.0];
        $lo = ScenarioPv::monthEdge($b, 30, ['k' => 1.5] + self::LV, false);
        $hi = ScenarioPv::monthEdge($b, 30, ['k' => 1.5] + self::LV, true);
        $bat = ScenarioPv::monthEdge($b, 30, ['battery' => 5.0] + self::LV, true);
        $want = [500.0, 300.0, 200.0, $lo['feed'], $lo['import'], $hi['feed'], $hi['import'], $bat['charge'], $bat['discharge'], $bat['feed'], $bat['import']];
        foreach (['docs/anleitungen/was-waere-wenn.md', 'docs/en/anleitungen/was-waere-wenn.md'] as $rel) {
            $text = (string)file_get_contents(dirname(__DIR__, 3) . "/$rel");
            $pos = strpos($text, '<!-- doc-claim: werkstatt-pv-month-example');
            self::assertNotFalse($pos, "$rel: Beispiel fehlt");
            preg_match('/```text\n(.*?)```/s', substr($text, (int)$pos), $mm);
            preg_match_all('/(\d+)\s*kWh(?!\w)/u', $mm[1] ?? '', $all);
            // „Speicher 5 kWh“ ist die Eingabe, keine gerechnete Zahl
            $got = array_values(array_filter(array_map('floatval', $all[1]), fn($v, $i) => !($v === 5.0 && $i === 7), ARRAY_FILTER_USE_BOTH));
            self::assertEqualsWithDelta($want, $got, 1e-6, $rel);
        }
    }

    // ── Viertelstunden, von Hand ────────────────────────────────────────

    /** Ein Tag mit acht Plätzen: Sonne 2 kWh in vier Plätzen (davon 1,5 ins Netz), 0,5 kWh Bezug in den anderen vier. */
    private static function day(): array
    {
        return ['year' => 2025, 'gen' => [0, 0, 2.0, 2.0, 2.0, 2.0, 0, 0], 'feed' => [0, 0, 1.5, 1.5, 1.5, 1.5, 0, 0],
                'import' => [0.5, 0.5, 0, 0, 0, 0, 0.5, 0.5], 'charge' => array_fill(0, 8, 0.0), 'discharge' => array_fill(0, 8, 0.0),
                'month' => array_fill(0, 8, 1), 'day' => array_fill(0, 8, 0)];
    }

    public function testViertelstundenVonHand(): void
    {
        $x = self::day();
        // Anlage + 50 %: +1 kWh je Sonnenplatz, dort gibt es keinen Bezug → alles ins Netz
        $s = ScenarioPv::simulateSlots($x, ['k' => 1.5] + self::LV)[0];
        self::assertEqualsWithDelta([12.0, 10.0, 2.0], [$s['gen'], $s['feed'], $s['import']], 1e-9);
        // Speicher 2 kWh, Leistung 0,25 kWh je Platz: lädt 4 × 0,25 = 1; abends zweimal 0,25 entladen
        $s = ScenarioPv::simulateSlots($x, ['battery' => 2.0] + self::LV)[0];
        self::assertEqualsWithDelta([5.0, 1.5, 1.0, 0.5], [$s['feed'], $s['import'], $s['charge'], $s['discharge']], 1e-9);
        // Nie über die Kapazität: 0,5 kWh, Leistung unbegrenzt → lädt 0,5, entlädt höchstens 0,45
        $s = ScenarioPv::simulateSlots($x, ['battery' => 0.5, 'rate' => 40.0] + self::LV)[0];
        self::assertEqualsWithDelta([5.5, 1.55, 0.5, 0.45], [$s['feed'], $s['import'], $s['charge'], $s['discharge']], 1e-9);
        // Verlagern + 10 %: selbst genutzt 4 × 0,5 = 2 → 0,2 kWh aus Bezug und Einspeisung
        $s = ScenarioPv::simulateSlots($x, ['shift' => 0.1] + self::LV)[0];
        self::assertEqualsWithDelta([5.8, 1.8], [$s['feed'], $s['import']], 1e-9);
        // Ausgangsfall: die Messung
        self::assertEqualsWithDelta(['gen' => 8.0, 'feed' => 6.0, 'import' => 2.0, 'charge' => 0.0, 'discharge' => 0.0], ScenarioPv::slotSums($x)[0], 1e-9);
        // Mehr Sonne deckt zuerst den Bezug desselben Platzes: 1 kWh Sonne, 0,5 Bezug, doppelte Anlage → Bezug 0, Netz 0,5
        $one = ['year' => 2025, 'gen' => [1.0], 'feed' => [0.0], 'import' => [0.5], 'charge' => [0.0], 'discharge' => [0.0], 'month' => [6], 'day' => [0]];
        $s = ScenarioPv::simulateSlots($one, ['k' => 2.0] + self::LV)[5];
        self::assertEqualsWithDelta([2.0, 0.5, 0.0], [$s['gen'], $s['feed'], $s['import']], 1e-9);
    }

    public function testGrundjahrZwoelfVolleMonateVorDemLaufenden(): void
    {
        $rows = [];
        for ($m = new \DateTimeImmutable('2025-01-01'); $m->format('Y-m') <= '2026-03'; $m = $m->modify('+1 month')) {
            $rows[] = ['ym' => $m->format('Y-m'), 'covered' => true];
        }
        self::assertSame('2025-03', ScenarioPv::baseYear($rows, self::TODAY)[0]['ym']);
        self::assertSame('2026-02', ScenarioPv::baseYear($rows, self::TODAY)[11]['ym'], 'März 2026 läuft noch');
        $rows[10]['covered'] = false;   // 2025-11 ohne alle drei Reihen
        self::assertNull(ScenarioPv::baseYear($rows, self::TODAY));
    }

    // ── Haushalt mit Monatswerten ───────────────────────────────────────

    /** Strom, Einspeisung und Erzeugung abgelesen am Monatsersten, 2024-01 bis 2026-03 (erfunden). */
    private function household(): void
    {
        $this->store->write('settings.json', ['active_utilities' => ['strom', 'pv_einspeisung', 'pv_erzeugung']]);
        $ids = [];
        foreach (['pv_erzeugung', 'pv_einspeisung', 'strom'] as $u) {
            $ids[$u] = $this->setMeterDevices($u, [['id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
                'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        }
        $per = ['pv_erzeugung' => fn($mo) => self::SUN[$mo], 'pv_einspeisung' => fn($mo) => 0.6 * self::SUN[$mo], 'strom' => fn($mo) => 380.0 - 0.2 * self::SUN[$mo]];
        foreach ($per as $u => $f) {
            $rows = [['date' => '2024-01-01', 'counter' => 0.0, 'device_id' => 'd1']];
            $c = 0.0;
            for ($m = new \DateTimeImmutable('2024-01-01'); $m < new \DateTimeImmutable('2026-03-01'); ) {
                $c += $f((int)$m->format('n'));
                $m = $m->modify('first day of next month');
                $rows[] = ['date' => $m->format('Y-m-d'), 'counter' => round($c, 3), 'device_id' => 'd1'];
            }
            $this->setReadings($u, $ids[$u], $rows);
        }
        $this->contracts->create('strom', ['meter_id' => $ids['strom'], 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis', 'start' => '2024-01-01', 'end' => null,
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 60.0]], 'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]]]);
        $this->contracts->create('pv_einspeisung', ['meter_id' => $ids['pv_einspeisung'], 'provider' => 'Netz Musterstadt', 'tariff_name' => 'Vergütung',
            'start' => '2024-01-01', 'end' => null, 'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 8.0]]]);
    }

    private function run_(array $in = [], ?IntervalService $iv = null): array
    {
        $id = (string)$this->meters->list('pv_erzeugung')[0]['id'];
        return $this->service($iv)->compute(['utility' => 'pv_erzeugung', 'meter_id' => $id] + $in, self::TODAY);
    }

    public function testAusgangsfallAusDenMonatenMitPreisen(): void
    {
        $this->household();
        $r = $this->run_();
        self::assertTrue($r['valid']);
        self::assertSame('pv', $r['calc']);
        self::assertSame('months', $r['pv']['source']);
        self::assertSame(['2025-03', '2026-02'], [$r['from'], $r['to']]);
        // Jahr: Erzeugung = Σ Sonne = 5.470, Einspeisung 0,6 × 5.470 = 3.282, Bezug 12 × 380 − 0,2 × 5.470 = 3.466
        self::assertEqualsWithDelta(5470.0, $r['pv']['base']['generation'], 0.5);
        self::assertEqualsWithDelta(3282.0, $r['pv']['base']['feed_in'], 0.5);
        self::assertEqualsWithDelta(3466.0, $r['pv']['base']['import'], 0.5);
        // Kosten 3.466 × 0,30 = 1.039,80 €, Erlös 3.282 × 0,08 = 262,56 €, netto 777,24 €
        self::assertEqualsWithDelta(1039.80, $r['pv']['base']['import_cost'], 0.2);
        self::assertEqualsWithDelta(262.56, $r['pv']['base']['feed_revenue'], 0.2);
        self::assertEqualsWithDelta(777.24, $r['totals']['base']['cost'], 0.3);
        // Autarkie = 1 − 3.466 ÷ (2.188 + 3.466) = 0,387
        self::assertEqualsWithDelta(1 - 3466 / (2188 + 3466), $r['pv']['base']['autarky'], 0.001);
        self::assertSame(['pv_bigger', 'battery', 'self_use_up', 'feed_in_half'], array_column($r['profile']['presets'], 'id'));
        self::assertSame(4.0, $r['profile']['presets'][3]['apply']['feed_in_ct'], 'die Hälfte von 8 ct');
        self::assertSame(['pv_range'], array_column($r['warnings'], 'code'));
        foreach (['saving', 'measures', 'model', 'budget', 'winter', 'tariff'] as $off) self::assertFalse($r['profile']['levers'][$off], $off);
        foreach (['pv_size', 'battery', 'self_use', 'feed_in'] as $on) self::assertTrue($r['profile']['levers'][$on], $on);
    }

    /** Jedes Beispiel bewirkt etwas, in der erwarteten Richtung; die Spanne umschließt die Mitte. */
    public function testBeispieleUndSpanne(): void
    {
        $this->household();
        foreach ($this->run_()['profile']['presets'] as $p) {
            $r = $this->run_($p['apply']);
            self::assertNotEquals(0.0, $r['totals']['delta']['cost'], $p['id']);
            self::assertSame($p['direction'], $r['totals']['delta']['cost'] > 0 ? 'more' : 'less', $p['id']);
            $lo = $r['pv']['scenario_low']; $hi = $r['pv']['scenario_high']; $mid = $r['pv']['scenario'];
            // untere Grenze der Wirkung = mehr Bezug; die Mitte liegt dazwischen
            self::assertGreaterThanOrEqual($hi['import'] - 0.1, $mid['import'], $p['id']);
            self::assertLessThanOrEqual($lo['import'] + 0.1, $mid['import'], $p['id']);
            self::assertLessThanOrEqual($mid['generation'] + 0.1, $mid['self_use'], 'kein Eigenverbrauch über der Erzeugung');
        }
    }

    /** Der Juli bekommt von der größeren Anlage mehr als der Dezember: je Monat aus seinen eigenen Werten. */
    public function testDezemberIstNichtJuli(): void
    {
        $this->household();
        $r = $this->run_(['pv_size_pct' => 100.0]);
        $by = array_column($r['months'], null, 'ym');
        $gain = fn(string $ym) => $by[$ym]['pv']['scenario']['generation'] - $by[$ym]['pv']['base']['generation'];
        self::assertEqualsWithDelta(800.0, $gain('2025-07'), 0.5, 'Juli verdoppelt: + 800');
        self::assertEqualsWithDelta(90.0, $gain('2025-12'), 0.5, 'Dezember: + 90');
        // Vergütung 4 ct: nur der Erlös ändert sich, 3.282 × 0,04 = 131,28 € weniger
        $f = $this->run_(['feed_in_ct' => 4.0]);
        self::assertEqualsWithDelta(131.28, $f['totals']['delta']['cost'], 0.2);
        self::assertSame(0.0, $f['totals']['delta']['qty']);
    }

    public function testOhneZwoelfMonateKeineRechnung(): void
    {
        $this->household();
        $r = $this->service()->compute(['utility' => 'pv_einspeisung', 'meter_id' => (string)$this->meters->list('pv_einspeisung')[0]['id']], '2024-09-15');
        self::assertFalse($r['valid']);
        self::assertSame('pv_incomplete', $r['reason_code']);
        self::assertSame('pv', $r['profile']['group']);
    }

    // ── Haushalt mit Viertelstunden ─────────────────────────────────────

    /**
     * 2025 je Viertelstunde (Zeitzone UTC, 96 Plätze am Tag): 10 bis 14 Uhr Sonne 0,5 kWh, davon 0,3 ins Netz;
     * sonst 0,1 kWh Bezug. Je Tag Erzeugung 8, Einspeisung 4,8, Bezug 80 × 0,1 = 8 kWh.
     */
    private function intervals(): IntervalService
    {
        $this->household();
        $this->store->write('settings.json', ['active_utilities' => ['strom', 'pv_einspeisung', 'pv_erzeugung'], 'timezone' => 'UTC']);
        $iv = new IntervalService($this->store, $this->settings);
        $gen = []; $feed = []; $imp = [];
        for ($ts = $iv->yearStart(2025); $ts < $iv->yearStart(2026); $ts += 900) {
            $slot = intdiv($ts % 86400, 900);
            $sun = $slot >= 40 && $slot < 56;
            $gen[$ts] = $sun ? 0.5 : 0.0; $feed[$ts] = $sun ? 0.3 : 0.0; $imp[$ts] = $sun ? 0.0 : 0.1;
        }
        $iv->mergeEnergy('pv_erzeugung', (string)$this->meters->list('pv_erzeugung')[0]['id'], $gen, 'test');
        $iv->mergeEnergy('pv_einspeisung', (string)$this->meters->list('pv_einspeisung')[0]['id'], $feed, 'test');
        $iv->mergeEnergy('strom', (string)$this->meters->list('strom')[0]['id'], $imp, 'test');
        return $iv;
    }

    public function testMitViertelstundenEinWertKeineSpanne(): void
    {
        $iv = $this->intervals();
        $r = $this->run_([], $iv);
        self::assertSame(['intervals', 2025], [$r['pv']['source'], $r['pv']['year']]);
        self::assertNull($r['pv']['scenario_low']);
        self::assertNotContains('pv_range', array_column($r['warnings'], 'code'));
        // 365 Tage: Erzeugung 2.920, Einspeisung 1.752, Bezug 2.920
        self::assertEqualsWithDelta([2920.0, 1752.0, 2920.0], [$r['pv']['base']['generation'], $r['pv']['base']['feed_in'], $r['pv']['base']['import']], 0.05);
        // Anlage + 50 %: zur Sonne gibt es keinen Bezug, also bleibt er; die Einspeisung wächst um 1.460
        $s = $this->run_(['pv_size_pct' => 50.0], $iv)['pv']['scenario'];
        self::assertEqualsWithDelta([4380.0, 3212.0, 2920.0], [$s['generation'], $s['feed_in'], $s['import']], 0.05);
        // Eigenverbrauch + 10 %: je Tag 10 % von 3,2 = 0,32 kWh weniger Bezug und Einspeisung
        $s = $this->run_(['self_use_pct' => 10.0], $iv)['pv']['scenario'];
        self::assertEqualsWithDelta([2920.0 - 116.8, 1752.0 - 116.8], [$s['import'], $s['feed_in']], 0.05);
    }

    /**
     * Speicher 5 kWh (Leistung 0,625 kWh je Platz): lädt täglich 16 × 0,3 = 4,8 kWh, abends 40 × 0,1 = 4,0
     * und am nächsten Morgen den Rest 4,8 × 0,9 − 4,0 = 0,32 kWh. Im Jahr geladen 365 × 4,8 = 1.752,
     * entladen 364 × 4,32 + 4,0 = 1.576,48 (der Rest vom 31.12. bleibt im Speicher); Bezug 2.920 − 1.576,48.
     */
    public function testSpeicherMitViertelstundenVonHand(): void
    {
        $iv = $this->intervals();
        $r = $this->run_(['battery_kwh' => 5.0], $iv);
        $s = $r['pv']['scenario'];
        self::assertEqualsWithDelta(0.0, $s['feed_in'], 0.05);
        self::assertEqualsWithDelta(2920.0 - 1576.48, $s['import'], 0.05);
        // Januar: 31 Abende × 4,0 + 30 Morgen × 0,32 = 133,6 kWh aus dem Speicher
        $jan = $r['months'][0]['pv'];
        self::assertEqualsWithDelta(31 * 8.0 - 133.6, $jan['scenario']['import'], 0.05);
        self::assertSame(2025, $r['pv']['year']);
    }
}
