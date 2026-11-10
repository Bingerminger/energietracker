<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ScenarioProfile;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.12.0 (F1040) — Prognose-Werkstatt je Fachgruppe: Profil und Beleg, dass die Rechnung unberührt ist.
 *
 * Alle Daten sind erfunden: 0,40 Einheiten je Heizgradtag und 2 je Tag, feste Monatsmittel der
 * Temperatur, ein Vertrag mit 10 ct und 10 € im Monat.
 */
#[CoversClass(ScenarioProfile::class)]
#[CoversClass(ScenarioService::class)]
final class ScenarioProfileTest extends ServiceTestCase
{
    private const AVG_TEMP = [1 => -1.0, 2 => 0.5, 3 => 4.0, 4 => 9.0, 5 => 14.0, 6 => 17.0, 7 => 19.0, 8 => 18.5, 9 => 14.5, 10 => 9.5, 11 => 4.5, 12 => 1.0];

    private ScenarioService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]]]);
        $first = new \DateTimeImmutable(date('Y-m-01') . ' -36 months');
        $temps = [];
        for ($d = $first; $d <= new \DateTimeImmutable('+1 year'); $d = $d->modify('+1 day')) {
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

    /** Zähler mit 36 Monaten Ablesungen und einem Vertrag; `$offer` legt ein Angebot dazu. */
    private function household(string $utility, ?string $role = null, bool $withHeat = true, bool $offer = false): string
    {
        $first = new \DateTimeImmutable(date('Y-m-01') . ' -36 months');
        $start = $first->format('Y-m-d');
        $id = $this->setMeterDevices($utility, [[
            'id' => 'd1', 'serial' => null, 'installed_on' => $start, 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        if ($role !== null) {
            $all = $this->store->read("$utility/meters.json", []);
            $all[0]['role'] = $role;
            if ($role === 'heat_pump') $all[0]['heat_source'] = true;
            $this->store->write("$utility/meters.json", $all);
        }
        if (!$withHeat) $this->store->write('temperatures.json', []);
        $rows = [['date' => $start, 'counter' => 0.0, 'device_id' => 'd1']];
        $counter = 0.0;
        for ($cur = $first; $cur < new \DateTimeImmutable(date('Y-m-01')); ) {
            $days = (int)$cur->format('t');
            $counter += 0.40 * $days * max(0.0, 15.0 - self::AVG_TEMP[(int)$cur->format('n')]) + 2.0 * $days;
            $cur = $cur->modify('first day of next month');
            $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => round($counter, 3), 'device_id' => 'd1'];
        }
        $this->setReadings($utility, $id, $rows);
        $this->contracts->create($utility, [
            'meter_id' => $id, 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis', 'start' => $start, 'end' => null,
            'advance_payments' => [['from' => $start, 'amount_eur' => 100.0]],
        ] + ($utility === 'wasser'
            ? ['trinkwasser' => ['working_prices' => [['from' => $start, 'ct_per_m3' => 200.0]], 'base_prices' => [['from' => $start, 'eur_per_month' => 5.0]]]]
            : ['working_prices' => [['from' => $start, 'ct_per_kwh' => 10.0]], 'base_prices' => [['from' => $start, 'eur_per_month' => 10.0]]]));
        if ($offer && $utility !== 'wasser') {
            $today = date('Y-m-d');
            $this->contracts->create($utility, ['meter_id' => $id, 'tariff_name' => 'Angebot', 'shadow_label' => 'Angebot', 'is_shadow' => true, 'start' => $today, 'end' => null,
                'working_prices' => [['from' => $today, 'ct_per_kwh' => 8.0]], 'base_prices' => [['from' => $today, 'eur_per_month' => 12.0]]]);
        }
        return $id;
    }

    private function run_(string $utility, string $id, array $in = []): array
    {
        return $this->svc->compute(['utility' => $utility, 'meter_id' => $id] + $in);
    }

    /** @return array<string,array{string,?string,string}> */
    public static function arten(): array
    {
        return [
            'Gas'              => ['gas', null, 'heat_fuel'],
            'Fernwärme'        => ['fernwaerme', null, 'heat_grid'],
            'Strom Haushalt'   => ['strom', null, 'power_household'],
            'Strom Wärmepumpe' => ['strom', 'heat_pump', 'power_heat_pump'],
            'Strom Wallbox'    => ['strom', 'ev_charger', 'power_ev'],
            'Wasser'           => ['wasser', null, 'water'],
        ];
    }

    #[DataProvider('arten')]
    public function testFachgruppeJeArtUndRolle(string $utility, ?string $role, string $group): void
    {
        $r = $this->run_($utility, $this->household($utility, $role));
        self::assertTrue($r['valid']);
        self::assertSame($group, $r['profile']['group']);
    }

    /** Jedes angebotene Beispiel ändert das Ergebnis; was nichts bewirkt, wird gar nicht angeboten. */
    #[DataProvider('arten')]
    public function testJedesBeispielBewirktEtwasUndNimmtDieErwarteteRichtung(string $utility, ?string $role, string $group): void
    {
        $id = $this->household($utility, $role, true, true);
        $base = $this->run_($utility, $id);
        self::assertNotSame([], $base['profile']['presets']);
        foreach ($base['profile']['presets'] as $p) {
            $r = $this->run_($utility, $id, $p['apply']);
            self::assertTrue($r['valid'], $p['id']);
            self::assertSame([], array_values(array_filter($r['warnings'], fn($w) => str_ends_with($w['code'], '_unsupported') || $w['code'] === 'no_offers')), "$group/{$p['id']}");
            $d = $r['totals']['delta'];
            self::assertNotEquals(0.0, $d['cost'], "$group/{$p['id']} ändert nichts");
            self::assertSame($p['direction'], $d['cost'] > 0 ? 'more' : 'less', "$group/{$p['id']}");
        }
    }

    /** Jede angebotene Maßnahme wirkt über ihren Hebel. */
    #[DataProvider('arten')]
    public function testJedeMassnahmeBewirktEtwas(string $utility, ?string $role, string $group): void
    {
        $id = $this->household($utility, $role);
        foreach ($this->run_($utility, $id)['profile']['measures'] as $m) {
            foreach ($m['levers'] as $lever) {
                $r = $this->run_($utility, $id, ['measures' => [['id' => 'x', 'kind' => $m['kind'], $lever . '_pct' => 10.0]]]);
                self::assertLessThan(0.0, $r['totals']['delta']['qty'], "$group/{$m['kind']}/$lever");
            }
        }
    }

    public function testTabelleJeGruppe(): void
    {
        $ids = fn(array $r) => array_column($r['profile']['presets'], 'id');
        $kinds = fn(array $r) => array_column($r['profile']['measures'], 'kind');

        $gas = $this->run_('gas', $this->household('gas', null, true, true));
        self::assertSame(['cold_winter', 'mild_winter', 'room_minus_1', 'roof', 'windows', 'cheapest'], $ids($gas));
        self::assertSame(['roof', 'facade', 'windows', 'basement', 'hydraulic', 'pump', 'thermostat', 'hot_water', 'other'], $kinds($gas));
        self::assertSame('hot_cook', $gas['profile']['base_word']);

        $fw = $this->run_('fernwaerme', $this->household('fernwaerme'));
        self::assertSame(['cold_winter', 'mild_winter', 'room_minus_1', 'roof', 'price_up'], $ids($fw), 'ohne Angebot kein „Günstigstes Angebot“');
        self::assertNotContains('pump', $kinds($fw));
        self::assertSame('hot_water', $fw['profile']['base_word']);

        $wasser = $this->run_('wasser', $this->household('wasser'));
        self::assertSame(['shower', 'toilet', 'save10'], $ids($wasser));
        self::assertSame(['shower', 'toilet', 'laundry', 'garden', 'leak', 'other'], $kinds($wasser));
        foreach ($wasser['profile']['measures'] as $m) self::assertSame(['base'], $m['levers'], 'kein Heizanteil beim Wasser');
        self::assertFalse($wasser['profile']['levers']['tariff']);
        self::assertFalse($wasser['profile']['levers']['winter']);
        self::assertFalse($wasser['profile']['levers']['baseload']);
        self::assertSame('usage', $wasser['profile']['base_word']);
    }

    /** Strom hat kein Heizmodell (`hgt_relevant`): auch die Wärmepumpe bekommt weder Winter noch Raumtemperatur. */
    public function testStromOhneHeizmodell(): void
    {
        $wp = $this->run_('strom', $this->household('strom', 'heat_pump'));
        self::assertSame(['jaz_better', 'save10'], array_column($wp['profile']['presets'], 'id'));
        self::assertFalse($wp['profile']['levers']['winter']);
        self::assertFalse($wp['profile']['levers']['room_temp']);
        self::assertSame('baseload', $wp['profile']['base_word']);
        foreach ($wp['profile']['measures'] as $m) self::assertSame(['base'], $m['levers']);
        self::assertTrue($wp['profile']['levers']['jaz']);
        self::assertFalse($wp['profile']['levers']['ev']);
    }

    /** Gas ohne Heizmodell: nur was wirkt, und der Hinweis, warum. */
    public function testGasOhneHeizmodellBietetNurWasWirkt(): void
    {
        $id = $this->household('gas', null, false);
        $r = $this->run_('gas', $id);
        self::assertTrue($r['valid']);
        self::assertFalse($r['capabilities']['heat_model']);
        self::assertSame(['save10'], array_column($r['profile']['presets'], 'id'));
        self::assertTrue($r['profile']['no_heat_model']);
        self::assertSame('usage', $r['profile']['base_word'], 'ohne Heizmodell ist der Rest die ganze Menge');
        self::assertSame(['hot_water', 'other'], array_column($r['profile']['measures'], 'kind'));
        foreach ($r['profile']['measures'] as $m) self::assertSame(['base'], $m['levers']);
    }

    public function testPreisSteigtBrauchtEinenPreis(): void
    {
        $id = $this->household('fernwaerme');
        $r = $this->run_('fernwaerme', $id);
        $p = array_values(array_filter($r['profile']['presets'], fn($x) => $x['id'] === 'price_up'))[0];
        self::assertSame(11.0, $p['apply']['tariff']['working_price_ct']);
        self::assertSame(11.0, $p['apply']['tariff']['base_eur_month']);
        $this->store->write('fernwaerme/contracts.json', []);
        self::assertNotContains('price_up', array_column($this->run_('fernwaerme', $id)['profile']['presets'], 'id'), 'ohne Preis keine Annahme');
    }

    /** Winter aus dem Klimanormal; ohne Klimanormal 10 % als Annahme, wie bis v3.11. */
    public function testWinterAusDemKlimanormalSonstAlsAnnahme(): void
    {
        $winter = function (?array $normal): array {
            $p = ScenarioProfile::build('gas', [], ['computable' => true, 'heat' => true, 'winter' => $normal]);
            return array_column($p['presets'], 'apply', 'id');
        };
        self::assertSame(9.0, $winter(['cold_pct' => 9.0, 'mild_pct' => -9.0])['cold_winter']['winter_pct']);
        self::assertSame(-9.0, $winter(['cold_pct' => 9.0, 'mild_pct' => -9.0])['mild_winter']['winter_pct']);
        self::assertSame(10.0, $winter(null)['cold_winter']['winter_pct']);
        self::assertSame(-10.0, $winter(null)['mild_winter']['winter_pct']);
    }

    /** v3.12.0 (F1040, Phase 2) — die Wahl der Heizkurve nur, wo ein Heizmodell wirkt: nicht bei Wasser und Strom. */
    public function testHeizkurveNurWoEinHeizmodellWirkt(): void
    {
        self::assertTrue($this->run_('gas', $this->household('gas'))['capabilities']['regression']);
        foreach ([['strom', null], ['strom', 'heat_pump'], ['wasser', null]] as [$u, $role]) {
            self::assertFalse($this->run_($u, $this->household($u, $role))['capabilities']['regression'], "$u/$role");
        }
        self::assertFalse($this->run_('gas', $this->household('gas', null, false))['capabilities']['regression'], 'Gas ohne Heizmodell');
    }

    /** v3.12.0 (F1040, Phase 2) — umbenannt (vorher testKeineArtOhneWerkstattBekommtEinProfil): ohne Werkstatt ist nur noch die Heizwärme. */
    public function testHeizwaermeOhneWerkstattOhneProfil(): void
    {
        foreach (['waerme'] as $u) {
            $r = $this->svc->compute(['utility' => $u, 'meter_id' => 'm1']);
            self::assertFalse($r['supported'], $u);
            self::assertSame('utility_unsupported', $r['reason_code'], $u);
            self::assertNull($r['profile'], $u);
        }
    }

    public function testWenigeMonateOhneBeispiele(): void
    {
        $id = $this->setMeterDevices('wasser', [['id' => 'd1', 'serial' => null, 'installed_on' => date('Y-m-d'), 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $r = $this->run_('wasser', $id);
        self::assertFalse($r['valid']);
        self::assertSame('water', $r['profile']['group']);
        self::assertSame([], $r['profile']['presets']);
    }

    /** Sprung `?preset=`: Was es hier früher gab und nun fehlt, leitet weiter. */
    public function testWeiterleitungFuerAlteKennungen(): void
    {
        $gas = $this->run_('gas', $this->household('gas'));
        self::assertSame(['save10' => 'room_minus_1'], $gas['profile']['preset_redirects']);
        $ohne = $this->run_('gas', $this->household('gas', null, false));
        self::assertEquals((object)[], $ohne['profile']['preset_redirects'], 'ohne Heizmodell bleibt save10 selbst');
    }

    // ── Vergleich alt gegen neu ─────────────────────────────────────────

    /**
     * Beleg für die Rechnung: Die Antwort ist die alte plus genau ein Feld. Die Felder
     * standen so in v3.11; die Zahlen selbst vergleicht `.claude/plans/v3.12/werkstatt-vergleich.php`
     * gegen den alten Stand (Bericht).
     */
    #[DataProvider('arten')]
    public function testAntwortIstDieAltePlusEinFeld(string $utility, ?string $role, string $group): void
    {
        $r = $this->run_($utility, $this->household($utility, $role));
        self::assertSame(['utility', 'meter_id', 'unit', 'inputs', 'supported', 'valid', 'model', 'from', 'to', 'months', 'totals', 'band', 'models',
            'advance', 'billing', 'tariff', 'offers', 'ranking', 'optimization', 'winter_presets', 'profile', 'capabilities', 'basis', 'warnings'],
            array_keys($r));
    }

    /**
     * Gespeicherte Szenarien: Eine Maßnahme, die nicht mehr zur Art passt, rechnet wie zuvor. Die
     * Art fließt nicht in die Rechnung ein, dieselben Prozente ergeben dieselben Zahlen.
     */
    #[DataProvider('arten')]
    public function testGespeicherteMassnahmeRechnetUnabhaengigVonDerArt(string $utility, ?string $role, string $group): void
    {
        $id = $this->household($utility, $role);
        $old = $this->run_($utility, $id, ['measures' => [['id' => 'm1', 'kind' => 'roof', 'heat_pct' => 15.0, 'base_pct' => 5.0]]]);
        $new = $this->run_($utility, $id, ['measures' => [['id' => 'm1', 'kind' => 'other', 'heat_pct' => 15.0, 'base_pct' => 5.0]]]);
        self::assertSame($old['totals'], $new['totals']);
        self::assertSame($old['months'], $new['months']);
    }

    /**
     * Rechenkern mit festen Monaten: Die Sollwerte stammen aus dem Stand vor F1040 (d3538cf2).
     * Rechnet er anders, bricht dieser Test, unabhängig vom Datum des Testlaufs.
     */
    public function testRechenkernAufDenCentWieVorher(): void
    {
        $months = [
            ['qty' => 300.0, 'heat_share' => 0.8, 'hdd_delta' => -20.0, 'days' => 31, 'fixed' => 10.0, 'unit' => 0.1, 'deduct' => 0.0],
            ['qty' => 100.0, 'heat_share' => 0.1, 'hdd_delta' => -2.0, 'days' => 30, 'fixed' => 10.0, 'unit' => 0.1, 'deduct' => 0.0],
            ['qty' => 60.0, 'heat_share' => null, 'hdd_delta' => 0.0, 'days' => 31, 'fixed' => 12.5, 'unit' => 0.3, 'deduct' => 5.0],
        ];
        $base = array_column($months, 'qty');
        $price = array_fill(0, 3, ['wp' => 11.0, 'bp' => 11.0]);
        $cases = [
            'Winter'        => [['winter_pct' => 10.0], null, 485.0, 91.5],
            'Raumtemperatur'=> [['heat_a' => 0.4, 'hdd' => true], null, 451.2, 88.12],
            'Dach'          => [['measures' => [['heat_pct' => 15.0, 'base_pct' => 0.0]]], null, 422.5, 85.25],
            'Sparbrause'    => [['measures' => [['heat_pct' => 0.0, 'base_pct' => 10.0]]], null, 439.0, 85.7],
            'Grundlast'     => [['baseload_pct' => -5.0], null, 449.5, 87.35],
            'Sparen'        => [['saving_pct' => 10.0], null, 414.0, 83.2],
            'E-Auto'        => [['ev_kwh_year' => 1800.0], null, 913.6986, 164.95],
            'Arbeitszahl'   => [['jaz_ratio' => 3.0 / 3.5], null, 394.2857, 80.71],
            'Eigener Preis' => [[], $price, 460.0, 83.05],
        ];
        foreach ($cases as $name => [$lv, $prices, $qty, $cost]) {
            $q = ScenarioService::quantities($months, $base, $lv);
            self::assertEqualsWithDelta($qty, array_sum($q), 0.0001, "$name: Menge");
            self::assertEqualsWithDelta($cost, array_sum(ScenarioService::costs($months, $q, $prices)), 0.005, "$name: Kosten");
        }
    }
}
