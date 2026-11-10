<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ScenarioDelivery;
use Energietracker\Services\ScenarioProfile;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 (F1040, Phase 2) — Prognose-Werkstatt für Heizöl und Pellets.
 *
 * Die Bausteine mit festen, von Hand gerechneten Werten; der Haushalt (ein erfundener
 * Öltank mit drei Lieferungen) prüft Beziehungen, die von keinem Datum abhängen.
 */
#[CoversClass(ScenarioDelivery::class)]
#[CoversClass(ScenarioService::class)]
#[CoversClass(ScenarioProfile::class)]
final class ScenarioDeliveryTest extends ServiceTestCase
{
    private const AVG_TEMP = [1 => -1.0, 2 => 0.5, 3 => 4.0, 4 => 9.0, 5 => 14.0, 6 => 17.0, 7 => 19.0, 8 => 18.5, 9 => 14.5, 10 => 9.5, 11 => 4.5, 12 => 1.0];

    /**
     * v3.12.0 — fester Stichtag des Haushalts und der Rechnung (an compute(), Tankbuch und Prognose
     * übergeben). Mit „heute“ verschoben sich Lieferungen, Monate und Jahreszeiten jeden Tag, und die
     * Summen der gerundeten Monatswerte lagen an manchen Tagen knapp außerhalb der Rundungsgrenze.
     */
    private const TODAY = '2026-11-09';

    private ScenarioService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $first = new \DateTimeImmutable(substr(self::TODAY, 0, 7) . '-01 -36 months');
        $temps = [];
        for ($d = $first; $d <= new \DateTimeImmutable(self::TODAY . ' +1 year'); $d = $d->modify('+1 day')) {
            $avg = self::AVG_TEMP[(int)$d->format('n')];
            $temps[$d->format('Y-m-d')] = ['avg' => $avg, 'min' => $avg - 3, 'max' => $avg + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $switch = new TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $billing = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills);
        $this->svc = new ScenarioService($forecast, $this->consumption, $this->contracts, $this->meters, $this->settings, $switch, $billing);
    }

    // ── Bausteine, von Hand gerechnet ───────────────────────────────────

    /** Wärme = 1000 × 0,9 = 900 kWh; Strom = 900 ÷ 4 = 225 kWh; Kosten = 225 × 0,25 € = 56,25 €. Zweiter Monat die Hälfte. */
    public function testHeizungstauschWaermepumpeVonHand(): void
    {
        $r = ScenarioDelivery::replacement([1000.0, 500.0], ['to' => 'heat_pump', 'efficiency_old' => 0.9, 'jaz' => 4.0, 'power_ct' => 25.0], 4.8);
        self::assertEqualsWithDelta([900.0, 450.0], $r['heat'], 1e-9);
        self::assertEqualsWithDelta([225.0, 112.5], $r['energy'], 1e-9);
        self::assertEqualsWithDelta([56.25, 28.125], $r['cost'], 1e-9);
        self::assertNull($r['units']);
        // fehlt eine Annahme, gilt der genannte Beispielwert (JAZ 3, 30 ct, Nutzungsgrad 0,9): 900 ÷ 3 = 300 kWh, 90 €
        $d = ScenarioDelivery::replacement([1000.0], ['to' => 'heat_pump'], 4.8);
        self::assertEqualsWithDelta([300.0], $d['energy'], 1e-9);
        self::assertEqualsWithDelta([90.0], $d['cost'], 1e-9);
        self::assertSame(['efficiency_old' => 0.9, 'jaz' => 3.0, 'power_ct' => 30.0], $d['assumed']);
    }

    /** Wärme = 1000 × 0,85 = 850; Pellets 850 ÷ 0,9 = 944,44 kWh = 188,89 kg (5 kWh/kg); × 0,40 € = 75,56 €. */
    public function testHeizungstauschPelletsVonHand(): void
    {
        $r = ScenarioDelivery::replacement([1000.0], ['to' => 'pellets', 'efficiency_old' => 0.85, 'efficiency_new' => 0.9, 'pellet_ct' => 40.0], 5.0);
        self::assertEqualsWithDelta(850.0, $r['heat'][0], 1e-9);
        self::assertEqualsWithDelta(944.4444, $r['energy'][0], 1e-4);
        self::assertEqualsWithDelta(188.8889, $r['units'][0], 1e-4);
        self::assertEqualsWithDelta(75.5556, $r['cost'][0], 1e-4);
    }

    /**
     * Tank: 1000 L am 20.10., Rest des Oktobers 11 Tage × 5 L = 55 → 945; November 300 L → 645;
     * Dezember 465 L → 180; Januar 20 L je Tag: am 2.1. 140 L (≤ 150, nachfüllen), am 9.1. 0 (leer).
     */
    public function testTankLeerUndNachfuellenVonHand(): void
    {
        $months = [['ym' => '2025-11', 'days' => 30], ['ym' => '2025-12', 'days' => 31], ['ym' => '2026-01', 'days' => 31], ['ym' => '2026-02', 'days' => 28]];
        $units = [300.0, 465.0, 620.0, 560.0];
        self::assertSame(['empty_on' => '2026-01-09', 'refill_on' => '2026-01-02'],
            ScenarioDelivery::tankOutlook(1000.0, '2025-10-20', 5.0, $months, $units, 150.0));
        // reicht über den Horizont: weder leer noch nachfüllen
        self::assertSame(['empty_on' => null, 'refill_on' => null], ScenarioDelivery::tankOutlook(5000.0, '2025-10-20', 5.0, $months, $units, 150.0));
        // heute schon unter der Schwelle: nachfüllen heute
        self::assertSame('2025-10-20', ScenarioDelivery::tankOutlook(100.0, '2025-10-20', 5.0, $months, $units, 150.0)['refill_on']);
        // ohne Kapazität keine Schwelle
        self::assertNull(ScenarioDelivery::tankOutlook(1000.0, '2025-10-20', 5.0, $months, $units, null)['refill_on']);
    }

    /**
     * Das Rechenbeispiel der Anleitung (DE und EN, nach `<!-- doc-claim: werkstatt-heat-pump-example`):
     * jede Zahl hinter „=“ ist die gerechnete (Wärme, Strom, Kosten).
     */
    public function testDasBeispielDerAnleitungRechnetDieApp(): void
    {
        $r = ScenarioDelivery::replacement([1000.0], ['to' => 'heat_pump', 'efficiency_old' => 0.9, 'jaz' => 3.0, 'power_ct' => 30.0], 4.8);
        $want = [$r['heat'][0], $r['energy'][0], $r['cost'][0]];
        foreach (['docs/anleitungen/was-waere-wenn.md' => 'de', 'docs/en/anleitungen/was-waere-wenn.md' => 'en'] as $rel => $lang) {
            $text = (string)file_get_contents(dirname(__DIR__, 3) . "/$rel");
            $pos = strpos($text, '<!-- doc-claim: werkstatt-heat-pump-example');
            self::assertNotFalse($pos, "$rel: Beispiel fehlt");
            preg_match('/```text\n(.*?)```/s', substr($text, (int)$pos), $mm);
            preg_match_all('/=\s*€?([\d.,]+)/u', $mm[1] ?? '', $all);
            $num = fn(string $s) => (float)($lang === 'de' ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s));
            self::assertEqualsWithDelta($want, array_map($num, $all[1]), 1e-9, $rel);
        }
    }

    // ── Haushalt ────────────────────────────────────────────────────────

    /** Öltank mit 3.000 L, Anfangsbestand 2.000 L und drei Lieferungen (erfunden); `$price` ct je Einheit. */
    private function tank(string $utility = 'heizoel', float $price = 98.0): string
    {
        $first = new \DateTimeImmutable(substr(self::TODAY, 0, 7) . '-01 -36 months');
        $id = $this->setMeterDevices($utility, [['id' => 'd1', 'serial' => null, 'installed_on' => $first->format('Y-m-d'),
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $all = $this->store->read("$utility/meters.json", []);
        $all[0] += ['capacity' => 3000.0, 'capacity_unit' => $utility === 'heizoel' ? 'L' : 'kg', 'initial_stock' => 2000.0];
        $this->store->write("$utility/meters.json", $all);
        $dl = [];
        foreach ([[8, 1500.0, 1.0], [20, 1400.0, 1.06], [31, 1600.0, 0.94]] as $k => [$mo, $q, $f]) {
            $dl[] = ['id' => "dl_$k", 'meter_id' => $id, 'date' => $first->modify("+$mo months")->format('Y-m-d'), 'quantity' => $q,
                'unit_price_cents' => round($price * $f, 2), 'total_eur' => null, 'fill_to_full' => false, 'is_planned' => false, 'note' => ''];
        }
        $this->store->write("$utility/deliveries.json", $dl);
        return $id;
    }

    private function run_(string $id, array $in = [], string $utility = 'heizoel'): array
    {
        return $this->svc->compute(['utility' => $utility, 'meter_id' => $id] + $in, self::TODAY);
    }

    public function testAusgangsfallIstDiePrognoseOhneAbschlagUndAbrechnung(): void
    {
        $r = $this->run_($this->tank());
        self::assertTrue($r['valid']);
        self::assertSame('delivery', $r['calc']);
        self::assertSame('heat_delivery', $r['profile']['group']);
        self::assertNull($r['advance']);
        self::assertNull($r['billing']);
        self::assertFalse($r['capabilities']['tariff']);
        self::assertFalse($r['capabilities']['regression'], 'die Heizkurve wirkt bei Lieferungen nicht');
        self::assertTrue($r['capabilities']['heat_model'], 'Heizanteil aus dem Tankbuch');
        // Kosten = Menge × Preis des Tanks (fester Teil 0): Preis je L = ct/kWh × 10 kWh/L
        self::assertEqualsWithDelta($r['totals']['base']['qty'] * $r['delivery']['base_ct_per_unit'] / 10.0 / 100.0, $r['totals']['base']['cost'], 0.05);
        self::assertEqualsWithDelta($r['totals']['base']['qty'] / 10.0, $r['delivery']['base_units'], 0.05);
        self::assertSame(['cold_winter', 'mild_winter', 'room_minus_1', 'roof', 'fuel_price_up', 'heat_pump'], array_column($r['profile']['presets'], 'id'));
        self::assertTrue($r['profile']['levers']['price']);
        self::assertTrue($r['profile']['levers']['replacement']);
    }

    /** Der Heizanteil je Monat ist per_hdd × HGT ÷ (per_hdd × HGT + je Tag × Tage), wie das Tankbuch verteilt. */
    public function testHeizanteilAusDemTankbuch(): void
    {
        $id = $this->tank();
        $r = $this->run_($id);
        $cal = $this->consumption->tankModel('heizoel', $this->meters->target('heizoel', $id), self::TODAY)['calibration'];
        $fc = (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))
            ->forMeter('heizoel', $this->meters->target('heizoel', $id), ['forecast_months' => 12, 'today' => self::TODAY]);
        foreach ($fc['forecast'] as $i => $f) {
            $days = (int)date('t', (int)strtotime($f['ym'] . '-01'));
            $heat = $cal['per_hdd'] * $f['hdd_estimated'];
            self::assertEqualsWithDelta($heat / ($heat + $cal['base_per_day'] * $days), (float)$r['months'][$i]['heat_share'], 1e-4, $f['ym']);
            self::assertEqualsWithDelta((float)$f['kwh'], $r['months'][$i]['base_qty'], 0.05, 'Ausgangsfall = Prognose');
        }
    }

    public function testJedesBeispielBewirktEtwasInDerErwartetenRichtung(): void
    {
        $id = $this->tank();
        $base = $this->run_($id);
        foreach ($base['profile']['presets'] as $p) {
            $r = $this->run_($id, $p['apply']);
            self::assertTrue($r['valid'], $p['id']);
            self::assertSame([], $r['warnings'], $p['id']);
            self::assertNotEquals(0.0, $r['totals']['delta']['cost'], $p['id']);
            self::assertSame($p['direction'], $r['totals']['delta']['cost'] > 0 ? 'more' : 'less', $p['id']);
        }
    }

    /** Preis + 10 %: dieselbe Menge, Kosten genau × 1,1. */
    public function testPreisPlusZehnProzent(): void
    {
        $r = $this->run_($this->tank(), ['price_pct' => 10.0]);
        self::assertSame(0.0, $r['totals']['delta']['qty']);
        self::assertEqualsWithDelta($r['totals']['base']['cost'] * 1.1, $r['totals']['scenario']['cost'], 0.02);
        self::assertEqualsWithDelta($r['delivery']['base_ct_per_unit'] * 1.1, $r['delivery']['scen_ct_per_unit'], 0.01);
        self::assertSame('price', $r['ranking'][0]['kind']);
    }

    /** Winter + 10 %: zusätzlich genau ein Zehntel des Heizanteils (Σ Menge × Heizanteil × 0,1). */
    public function testWinterWirktNurAufDenHeizanteil(): void
    {
        $id = $this->tank();
        $base = $this->run_($id);
        $heat = array_sum(array_map(fn($m) => $m['base_qty'] * (float)$m['heat_share'], $base['months']));
        $r = $this->run_($id, ['winter_pct' => 10.0]);
        self::assertEqualsWithDelta($heat * 0.1, $r['totals']['delta']['qty'], 0.5);
        self::assertGreaterThan(0.0, $heat);
        self::assertLessThan($base['totals']['base']['qty'], $heat, 'Warmwasser bleibt');
    }

    /** Wärmepumpe mit JAZ 3, 30 ct, Nutzungsgrad 0,9: Strom = Öl × 0,9 ÷ 3 = 0,3 × Öl, Kosten = Strom × 0,30 €. */
    public function testHeizungstauschImHaushalt(): void
    {
        $r = $this->run_($this->tank(), ['replace' => ['to' => 'heat_pump', 'jaz' => 3.0, 'power_ct' => 30.0, 'efficiency_old' => 0.9, 'invest_eur' => 20000.0]]);
        self::assertEqualsWithDelta($r['totals']['base']['qty'] * 0.3, $r['totals']['scenario']['qty'], 0.5);
        self::assertEqualsWithDelta($r['totals']['scenario']['qty'] * 0.30, $r['totals']['scenario']['cost'], 0.05);
        self::assertSame('heat_pump', $r['replacement']['to']);
        self::assertEqualsWithDelta($r['replacement']['fuel_cost'] - $r['replacement']['cost'], $r['replacement']['saving_eur'], 0.01);
        self::assertNull($r['tank']['scenario'], 'nach dem Tausch gibt es den Öltank im Szenario nicht');
        self::assertNull($r['delivery']['scen_units']);
        $rank = array_values(array_filter($r['ranking'], fn($x) => $x['kind'] === 'replacement'))[0];
        self::assertSame(20000.0, $rank['cost_eur']);
        if ($rank['saving_eur'] > 0) self::assertEqualsWithDelta(20000.0 / $rank['saving_eur'], $rank['payback_years'], 0.1);
        // CO₂ der Wärmepumpe mit dem Faktor des Stroms (g/kWh)
        $year = (int)substr($r['from'], 0, 4);
        self::assertEqualsWithDelta($r['totals']['scenario']['qty'] * $this->settings->co2Factor('co2_strom', $year) / 1000, $r['totals']['scenario']['co2_kg'], 0.5);
    }

    /**
     * Billige Pellets (30 ct/kg bei 4,8 kWh/kg = 6,25 ct/kWh, Wärme 6,94 ct) gegen die Wärmepumpe mit
     * 30 ct ÷ JAZ 3 = 10 ct: Die Wärmepumpe kostet mehr, das Beispiel sagt es mit eigenem Satz.
     */
    public function testWaermepumpeKannTeurerSein(): void
    {
        $id = $this->tank('pellets', 30.0);
        $p = array_values(array_filter($this->run_($id, [], 'pellets')['profile']['presets'], fn($x) => $x['id'] === 'heat_pump'))[0];
        self::assertSame('more', $p['direction']);
        self::assertSame('sentence_more', $p['sentence']);
        self::assertGreaterThan(0.0, $this->run_($id, $p['apply'], 'pellets')['totals']['delta']['cost']);
        self::assertSame(['heat_pump'], $this->run_($id, [], 'pellets')['profile']['replace_targets'], 'Pellets tauschen nicht in Pellets');
    }

    public function testPelletsNachPelletsTauschtNicht(): void
    {
        $r = $this->run_($this->tank('pellets'), ['replace' => ['to' => 'pellets']], 'pellets');
        self::assertSame(['replace_same'], array_column($r['warnings'], 'code'));
        self::assertSame(0.0, $r['totals']['delta']['cost']);
        self::assertNull($r['replacement']);
    }

    /** Der Tank sinkt im Szenario langsamer, wenn weniger verbraucht wird; leer frühestens wie im Ausgangsfall. */
    public function testTankImSzenario(): void
    {
        $id = $this->tank();
        $r = $this->run_($id, ['saving_pct' => 50.0]);
        $t = $r['tank'];
        self::assertSame('L', $t['unit']);
        self::assertSame(3000.0, $t['capacity']);
        self::assertSame(450.0, $t['refill_level'], '15 % von 3.000 L');
        foreach (['empty_on', 'refill_on'] as $k) {
            if ($t['base'][$k] !== null) self::assertTrue($t['scenario'][$k] === null || $t['scenario'][$k] >= $t['base'][$k], $k);
        }
    }
}
