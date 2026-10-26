<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.6.0 (F1031) — Prognose-Werkstatt: die Szenario-Rechnung.
 *
 * Synthetisch und von Hand nachrechenbar wie BaselineCutoffTest: feste
 * Monatsmittel der Temperatur, Verbrauch = 0,40 kWh × HGT + 2 kWh je Tag,
 * Umrechnungsfaktor 1. Das Heizmodell trifft die Daten exakt, der Heizanteil
 * eines Monats ist bekannt.
 */
#[CoversClass(ScenarioService::class)]
#[CoversClass(ForecastService::class)]
final class ScenarioServiceTest extends ServiceTestCase
{
    private const SLOPE = 0.40;
    private const BASE_PER_DAY = 2.0;
    private const AVG_TEMP = [
        1 => -1.0, 2 => 0.5, 3 => 4.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
        7 => 19.0, 8 => 18.5, 9 => 14.5, 10 => 9.5, 11 => 4.5, 12 => 1.0,
    ];

    private ScenarioService $svc;
    private ForecastService $forecast;
    private string $meterId;

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

        $this->meterId = $this->setMeterDevices('gas', [[
            'id' => 'd_gas_1', 'serial' => null, 'installed_on' => $first->format('Y-m-d'), 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [['date' => $first->format('Y-m-d'), 'counter' => 0.0, 'device_id' => 'd_gas_1']];
        $counter = 0.0;
        for ($cur = $first; $cur < new \DateTimeImmutable(date('Y-m-01')); ) {
            $counter += self::consumptionOf($cur->format('Y-m'));
            $cur = $cur->modify('first day of next month');
            $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => round($counter, 3), 'device_id' => 'd_gas_1'];
        }
        $this->setReadings('gas', $this->meterId, $rows);

        $start = $first->format('Y-m-d');
        $this->contracts->create('gas', [
            'meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis',
            'start' => $start, 'end' => null,
            'working_prices'   => [['from' => $start, 'ct_per_kwh' => 10.0]],
            'base_prices'      => [['from' => $start, 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 100.0]],
        ]);
        foreach ([['Günstig', 8.0, 12.0], ['Teuer', 14.0, 5.0]] as [$name, $wp, $bp]) {
            $this->contracts->create('gas', [
                'meter_id' => $this->meterId, 'tariff_name' => $name, 'shadow_label' => $name, 'is_shadow' => true,
                'start' => date('Y-m-d'), 'end' => null,
                'working_prices' => [['from' => date('Y-m-d'), 'ct_per_kwh' => $wp]],
                'base_prices'    => [['from' => date('Y-m-d'), 'eur_per_month' => $bp]],
            ]);
        }

        $this->forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $switch = new TariffSwitchService($this->forecast, $this->contracts, $this->meters, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $billing = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills);
        $this->svc = new ScenarioService($this->forecast, $this->consumption, $this->contracts, $this->meters,
            $this->settings, $switch, $billing);
    }

    private static function hddOf(string $ym, float $base = 15.0): float
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        return (int)date('t', mktime(0, 0, 0, $m, 1, $y)) * max(0.0, $base - self::AVG_TEMP[$m]);
    }

    private static function consumptionOf(string $ym): float
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        return self::SLOPE * self::hddOf($ym) + self::BASE_PER_DAY * (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    }

    private function scenario(array $in = []): array
    {
        $r = $this->svc->compute(['utility' => 'gas', 'meter_id' => $this->meterId] + $in);
        self::assertTrue($r['valid'], 'Szenario gültig: ' . ($r['reason_code'] ?? ''));
        return $r;
    }

    private function shadowId(string $name): string
    {
        foreach ($this->contracts->list('gas', $this->meterId) as $c) {
            if (($c['tariff_name'] ?? '') === $name) return (string)$c['id'];
        }
        self::fail("Schattenvertrag $name fehlt");
    }

    // ── Ausgangsfall = Prognose ─────────────────────────────────────────

    public function testWithoutLeversTheScenarioIsTheForecast(): void
    {
        $r = $this->scenario();
        $fc = $this->forecast->forMeter('gas', $this->meters->get('gas', $this->meterId), ['forecast_months' => 12]);
        self::assertEqualsWithDelta(array_sum(array_column($fc['forecast'], 'kwh')), $r['totals']['base']['qty'], 0.5);
        self::assertEqualsWithDelta(array_sum(array_column($fc['forecast'], 'cost_estimated')), $r['totals']['base']['cost'], 0.05,
            'fester Teil + Preis je Einheit ergibt genau die Kosten der Prognose');
        self::assertSame($r['totals']['base'], $r['totals']['scenario']);
        self::assertSame(0.0, $r['totals']['delta']['cost']);
        foreach ($fc['forecast'] as $row) {
            self::assertEqualsWithDelta($row['cost_estimated'], $row['fixed_eur'] + $row['unit_eur'] * $row['kwh'], 0.02, $row['ym']);
        }
        self::assertTrue($r['capabilities']['heat_model']);
        self::assertSame('standard', $r['model']);
        self::assertCount(12, $r['months']);
        self::assertSame($r['months'][11]['scen_cum_cost'], $r['totals']['scenario']['cost']);
    }

    // ── Winter, Dämmung, Raumtemperatur: nur der heizabhängige Teil ─────

    public function testWinterScalesOnlyTheHeatDependentPart(): void
    {
        $base = $this->scenario();
        $cold = $this->scenario(['winter_pct' => 20]);
        $heat = 0.0;
        foreach ($base['months'] as $i => $m) {
            $heat += $m['base_qty'] * (float)($m['heat_share'] ?? 0);
            if ((float)($m['heat_share'] ?? 0) === 0.0) {
                self::assertSame($m['base_qty'], $cold['months'][$i]['scen_qty'], "Sommermonat {$m['ym']} unverändert");
            }
        }
        self::assertGreaterThan(0, $heat);
        self::assertEqualsWithDelta(0.20 * $heat, $cold['totals']['delta']['qty'], 1.0);
        $mild = $this->scenario(['winter_pct' => -20]);
        self::assertEqualsWithDelta(-0.20 * $heat, $mild['totals']['delta']['qty'], 1.0);
    }

    public function testInsulationActsOnTheHeatPartOnlyAndMeasuresMultiply(): void
    {
        $base = $this->scenario();
        $one = $this->scenario(['measures' => [['id' => 'roof', 'heat_pct' => 30, 'cost_eur' => 10000]]]);
        $heat = array_sum(array_map(fn($m) => $m['base_qty'] * (float)($m['heat_share'] ?? 0), $base['months']));
        self::assertEqualsWithDelta(-0.30 * $heat, $one['totals']['delta']['qty'], 1.0);
        $july = array_values(array_filter($one['months'], fn($m) => str_ends_with($m['ym'], '-07')))[0];
        self::assertSame($july['base_qty'], $july['scen_qty'], 'Juli: keine Heizung, keine Wirkung');

        // zwei Maßnahmen: (1 − 0,3) × (1 − 0,3) = 0,49 statt 0,4
        $two = $this->scenario(['measures' => [['id' => 'a', 'heat_pct' => 30], ['id' => 'b', 'heat_pct' => 30]]]);
        self::assertEqualsWithDelta(-0.51 * $heat, $two['totals']['delta']['qty'], 1.0);
        // inaktive Maßnahmen zählen nicht im Szenario, aber in der Rangliste
        $off = $this->scenario(['measures' => [['id' => 'a', 'heat_pct' => 30, 'active' => false]]]);
        self::assertSame(0.0, $off['totals']['delta']['qty']);
        self::assertSame('a', $off['ranking'][0]['id']);
        self::assertGreaterThan(0, $off['ranking'][0]['saving_eur']);
    }

    public function testRoomTemperatureShiftsTheHeatingLimit(): void
    {
        $base = $this->scenario();
        $r = $this->scenario(['room_temp_delta' => -1]);
        $expected = 0.0;
        foreach ($base['months'] as $m) $expected += self::SLOPE * (self::hddOf($m['ym'], 14.0) - self::hddOf($m['ym'], 15.0));
        self::assertLessThan(0, $expected);
        self::assertEqualsWithDelta($expected, $r['totals']['delta']['qty'], abs($expected) * 0.02);
        $warm = $this->scenario(['room_temp_delta' => 1]);
        self::assertGreaterThan(0, $warm['totals']['delta']['qty']);
        self::assertSame('room_temp', array_column($r['ranking'], 'kind')[0]);
    }

    // ── Tarif ───────────────────────────────────────────────────────────

    public function testTariffCustomOfferAndCheapest(): void
    {
        $base = $this->scenario();
        $qty = array_column($base['months'], 'base_qty');

        $custom = $this->scenario(['tariff' => ['kind' => 'custom', 'working_price_ct' => 20, 'base_eur_month' => 0]]);
        self::assertEqualsWithDelta(array_sum($qty) * 0.20, $custom['totals']['scenario']['cost'], 0.1);
        self::assertSame($base['totals']['base']['qty'], $custom['totals']['scenario']['qty'], 'Tarif ändert die Menge nicht');

        $cheap = $this->shadowId('Günstig');
        $offer = $this->scenario(['tariff' => ['kind' => 'contract', 'contract_id' => $cheap]]);
        self::assertEqualsWithDelta(array_sum($qty) * 0.08 + 12 * 12.0, $offer['totals']['scenario']['cost'], 0.1);

        $best = $this->scenario(['tariff' => ['kind' => 'cheapest']]);
        self::assertSame($cheap, $best['tariff']['contract_id']);
        self::assertSame($offer['totals']['scenario']['cost'], $best['totals']['scenario']['cost']);
        self::assertCount(2, $best['offers']);
        self::assertSame('tariff', array_values(array_filter($best['ranking'], fn($x) => $x['kind'] === 'tariff'))[0]['kind']);

        $this->expectException(LocalizedException::class);
        $this->scenario(['tariff' => ['kind' => 'contract', 'contract_id' => 'c_gibtsnicht']]);
    }

    // ── Modelle und Band ────────────────────────────────────────────────

    public function testModelsAndBand(): void
    {
        $std = $this->scenario();
        self::assertNotNull($std['band']);
        self::assertLessThanOrEqual($std['band']['base_high'], $std['totals']['base']['qty'] + 0.1);
        $last = $this->scenario(['model' => 'last_year']);
        self::assertSame('last_year', $last['model']);
        self::assertNull($last['band'], 'Band nur zum Prognosemodell');
        foreach ($last['months'] as $m) {
            $ym = sprintf('%04d-%s', (int)substr($m['ym'], 0, 4) - 1, substr($m['ym'], 5, 2));
            self::assertEqualsWithDelta(self::consumptionOf($ym), $m['base_qty'], 0.2, "Vorjahr $ym");
        }
        $trend = $this->scenario(['model' => 'trend']);
        self::assertSame('trend', $trend['model']);
        self::assertEqualsWithDelta(0.0, $trend['models']['trend']['growth_pct'], 0.1, 'gleiche Jahre: kein Trend');
    }

    public function testTrendIsCappedAndNeedsTwoYears(): void
    {
        $months = [];
        $hist = [];
        for ($i = 1; $i <= 12; $i++) {
            $ym = sprintf('2026-%02d', $i);
            $months[] = ['ym' => $ym, 'qty' => 100.0, 'days' => 30];
            $hist[] = ['ym' => sprintf('2025-%02d', $i), 'kwh' => 110.0 * 30 / 30, 'days' => 30];
            $hist[] = ['ym' => sprintf('2024-%02d', $i), 'kwh' => 100.0, 'days' => 30];
        }
        $r = ScenarioService::modelQuantities($months, $hist, 'kwh', 20);
        self::assertEqualsWithDelta(0.10, $r['growth'], 1e-9);
        self::assertEqualsWithDelta(110 * 1.1 * 12, array_sum($r['trend']), 1e-6);
        $hist2 = array_map(fn($h) => str_starts_with($h['ym'], '2025') ? ['kwh' => 300.0] + $h : $h, $hist);
        self::assertSame(ScenarioService::TREND_CAP, ScenarioService::modelQuantities($months, $hist2, 'kwh', 20)['growth']);
        $short = array_values(array_filter($hist, fn($h) => str_starts_with($h['ym'], '2025')));
        $r3 = ScenarioService::modelQuantities($months, $short, 'kwh', 20);
        self::assertNull($r3['trend']);
        self::assertNotNull($r3['last_year']);
        // ein halbes Jahr davor reicht nicht: zwei VOLLE Jahre
        $half = array_values(array_filter($hist, fn($h) => str_starts_with($h['ym'], '2025') || $h['ym'] <= '2024-06'));
        self::assertNull(ScenarioService::modelQuantities($months, $half, 'kwh', 20)['trend'], 'Trend nur mit zwei vollen Jahren');
    }

    // ── Abschlag und Saldo zur nächsten Abrechnung (F1030) ──────────────

    /**
     * v3.9.0 (V1) — eine Projektion: Der Ausgangsfall der Werkstatt ist die Saldo-Karte
     * (Vertragsstatus, laufende Abrechnungsperiode), das Szenario ändert nur, was ab heute
     * noch kommt. Bis v3.8 rechnete die Werkstatt ab der letzten Ablesung selbst und legte
     * das Szenario auch auf schon vergangene, nicht abgelesene Monate.
     */
    public function testAdvanceAndBalanceAtTheNextBill(): void
    {
        // Stichtag ein halbes Jahr voraus: genug Abschläge bis zur Abrechnung (L12), in jedem Monat gleich
        $anchor = date('m-01', strtotime(date('Y-m-01') . ' +6 months'));
        $this->settings->set(['billing_cycle_anchor_gas' => $anchor]);
        $base = $this->scenario();
        $r = $this->scenario(['saving_pct' => 25]);
        self::assertNotNull($r['billing'], 'laufende Abrechnungsperiode');
        $until = date('Y-m-d', strtotime(date('Y-m-01') . ' +6 months -1 day'));
        self::assertSame([$until, $until], [$r['billing']['until'], $r['billing']['settle_on']]);
        $card = $this->consumption->contractStatus('gas', $this->meters->get('gas', $this->meterId))['contracts'][0];
        self::assertSame($card['projected_end_balance'], $r['billing']['base_end_balance'], 'Ausgangsfall = Saldo-Karte');
        self::assertSame($card['current_balance'], $r['billing']['balance_so_far']);
        self::assertSame($card['months_left'], $r['billing']['months_left']);
        self::assertSame(5, $r['billing']['months_left'], 'Abschläge nach dem laufenden Monat bis zur Abrechnung');
        // das Szenario wirkt ab heute: je Prognosemonat der Anteil seiner Tage in [heute, Abrechnungstag]
        $today = date('Y-m-d');
        $diff = 0.0;
        foreach ($r['months'] as $m) {
            $ms = $m['ym'] . '-01'; $me = date('Y-m-t', strtotime($ms));
            $a = max($ms, $today); $b = min($me, $until);
            if ($a > $b) continue;
            $diff += ($m['scen_cost'] - $m['base_cost']) * ((strtotime("$b 12:00") - strtotime("$a 12:00")) / 86400 + 1) / (int)date('t', strtotime($ms));
        }
        self::assertEqualsWithDelta($diff, $r['billing']['scen_end_balance'] - $r['billing']['base_end_balance'], 0.05);
        self::assertSame($base['billing']['base_end_balance'], $r['billing']['base_end_balance']);
        self::assertSame('billing_period', $r['advance']['method']);
        self::assertSame($card['suggested_advance'], $r['advance']['base_recommended'], 'Vorschlag wie die Karte');
        self::assertEqualsWithDelta(max(0, round(100 + $r['billing']['scen_end_balance'] / 5)), $r['advance']['scen_recommended'], 0.01);
        self::assertLessThanOrEqual($r['advance']['base_recommended'], $r['advance']['scen_recommended'], 'sparen senkt den Vorschlag');
        // mit Periode wie der Vorschlag im Vertragsstatus: Abschlag + Endsaldo ÷ verbleibende Monate
        $byPeriod = ScenarioService::advance([['advance' => 100.0]], [0.0], [0.0],
            ['months_left' => 3, 'base_end_balance' => 60.0, 'scen_end_balance' => 120.0]);
        self::assertSame([120.0, 140.0, 'billing_period'], [$byPeriod['base_recommended'], $byPeriod['scen_recommended'], $byPeriod['method']]);
        self::assertSame(0.0, ScenarioService::advance([['advance' => 100.0]], [0.0], [0.0],
            ['months_left' => 2, 'base_end_balance' => -500.0, 'scen_end_balance' => -500.0])['scen_recommended'], 'nie negativ');
        // ohne Periode: ein Zwölftel der Jahreskosten
        $twelfth = ScenarioService::advance([['advance' => 100.0]], [600.0, 600.0], [300.0, 301.0], null);
        self::assertSame(['current' => 100.0, 'method' => 'year_twelfth', 'months_left' => null,
                          'base_recommended' => 100.0, 'scen_recommended' => 51.0], $twelfth);
    }

    public function testCo2FollowsTheQuantity(): void
    {
        self::assertSame(0.2, ScenarioService::co2Factor([['kwh' => 100.0, 'co2_kg' => 20.0], ['kwh' => 0.0, 'co2_kg' => 5.0]], 'kwh'));
        self::assertNull(ScenarioService::co2Factor([['kwh' => 100.0, 'co2_kg' => null]], 'kwh'));
        $r = $this->scenario(['saving_pct' => 10]);
        if ($r['capabilities']['co2']) {
            self::assertEqualsWithDelta($r['totals']['base']['co2_kg'] * -0.10, $r['totals']['delta']['co2_kg'], 1.0);
        }
    }

    // ── Rangliste und Optimierung ───────────────────────────────────────

    public function testRankingAndBestCombinationUnderBudget(): void
    {
        $r = $this->scenario([
            'measures' => [
                ['id' => 'roof', 'heat_pct' => 20, 'cost_eur' => 8000],
                ['id' => 'windows', 'heat_pct' => 15, 'cost_eur' => 12000],
                ['id' => 'pump', 'heat_pct' => 5, 'cost_eur' => 500],
                ['id' => 'shower', 'base_pct' => 20, 'cost_eur' => 100],
            ],
            'budget_eur' => 10000,
        ]);
        $savings = array_column($r['ranking'], 'saving_eur');
        $sorted = $savings; rsort($sorted);
        self::assertSame($sorted, $savings, 'Rangliste nach Ersparnis');
        $roof = array_values(array_filter($r['ranking'], fn($x) => ($x['id'] ?? '') === 'roof'))[0];
        self::assertEqualsWithDelta(8000 / $roof['saving_eur'], $roof['payback_years'], 0.1);

        $o = $r['optimization'];
        sort($o['chosen']);
        self::assertSame(['pump', 'roof', 'shower'], $o['chosen'], 'Fenster passen nicht ins Budget');
        self::assertEqualsWithDelta(8600, $o['cost_eur'], 0.01);
        self::assertSame(4, $o['candidates']);
        self::assertNotNull($o['lp']);
        self::assertGreaterThanOrEqual($o['saving_eur'] - 0.01, $o['lp']['bound_eur'], 'LP-Relaxierung ist obere Schranke');
        $singles = array_sum(array_map(fn($id) => array_values(array_filter($r['ranking'], fn($x) => ($x['id'] ?? '') === $id))[0]['saving_eur'], $o['chosen']));
        self::assertLessThan($singles, $o['saving_eur'], 'Heizmaßnahmen sparen zusammen weniger als die Summe (Wechselwirkung)');

        $zero = $this->scenario(['measures' => [['id' => 'roof', 'heat_pct' => 20, 'cost_eur' => 8000]], 'budget_eur' => 0]);
        self::assertSame([], $zero['optimization']['chosen']);
        self::assertSame(0.0, $zero['optimization']['saving_eur']);
    }

    public function testOptimizerTextbookKnapsack(): void
    {
        $items = [['id' => 'A', 'cost_eur' => 5.0], ['id' => 'B', 'cost_eur' => 4.0], ['id' => 'C', 'cost_eur' => 6.0], ['id' => 'D', 'cost_eur' => 3.0]];
        $value = ['A' => 10.0, 'B' => 40.0, 'C' => 30.0, 'D' => 50.0];
        $sum = fn(array $subset) => array_sum(array_map(fn($m) => $value[$m['id']], $subset));
        $r = ScenarioService::bestUnderBudget($items, 10.0, $value, $sum);
        sort($r['chosen']);
        self::assertSame(['B', 'D'], $r['chosen']);
        self::assertSame(90.0, $r['saving_eur']);
        self::assertSame(105.0, $r['lp']['bound_eur'], 'D und B ganz, C zur Hälfte');
        self::assertEqualsWithDelta(5.0, $r['lp']['budget_value_per_eur'], 1e-6, 'Schattenpreis = Verhältnis von C');
        self::assertEqualsWithDelta(0.5, $r['lp']['x']['C'], 1e-6);

        // keine Maßnahme lohnt
        $none = ScenarioService::bestUnderBudget($items, 100.0, array_map(fn() => -5.0, $value), fn() => -5.0);
        self::assertSame([], $none['chosen']);
        self::assertSame(0.0, $none['lp']['bound_eur']);
        // Gleichstand: die billigere Kombination
        $tie = ScenarioService::bestUnderBudget([['id' => 'x', 'cost_eur' => 9.0], ['id' => 'y', 'cost_eur' => 2.0]], 10.0,
            ['x' => 7.0, 'y' => 7.0], fn(array $s) => count($s) ? 7.0 : 0.0);
        self::assertSame(['y'], $tie['chosen']);
    }

    // ── Eingaben und Grenzen ────────────────────────────────────────────

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function invalidInputs(): array
    {
        return [
            'Winter zu groß'        => [['winter_pct' => 80], 'winter_pct'],
            'Winter kein Wert'      => [['winter_pct' => 'kalt'], 'winter_pct'],
            'sparen negativ'        => [['saving_pct' => -5], 'saving_pct'],
            'Raumtemperatur'        => [['room_temp_delta' => 9], 'room_temp_delta'],
            'Modell unbekannt'      => [['model' => 'orakel'], 'model'],
            'Regression unbekannt'  => [['regression' => 'cubic'], 'regression'],
            'E-Auto ohne Verbrauch' => [['ev_km' => 10000], 'ev_kwh_per_100km'],
            'JAZ einseitig'         => [['jaz_from' => 3], 'jaz_to'],
            'Tarif unbekannt'       => [['tariff' => ['kind' => 'gratis']], 'tariff.kind'],
            'eigener Preis fehlt'   => [['tariff' => ['kind' => 'custom']], 'tariff.working_price_ct'],
            'zu viele Maßnahmen'    => [['measures' => array_map(fn($i) => ['id' => "m$i"], range(1, 11))], 'measures'],
            'Maßnahme ohne id'      => [['measures' => [['heat_pct' => 10]]], 'measures[0].id'],
            'Maßnahme doppelt'      => [['measures' => [['id' => 'a'], ['id' => 'a']]], 'measures[1].id'],
            'Maßnahme 100 %'        => [['measures' => [['id' => 'a', 'heat_pct' => 100]]], 'measures[0].heat_pct'],
            'Budget negativ'        => [['budget_eur' => -1], 'budget_eur'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsAreRejected(array $in, string $field): void
    {
        try {
            ScenarioService::normalize(['utility' => 'gas', 'meter_id' => 'm'] + $in);
            self::fail('keine Ausnahme');
        } catch (LocalizedException $e) {
            self::assertSame('errors.forecast.paramInvalid', $e->key);
            self::assertSame($field, $e->params['param']);
        }
    }

    public function testUnknownUtilityAndUnsupportedKinds(): void
    {
        try {
            ScenarioService::normalize(['utility' => 'kohle', 'meter_id' => 'm']);
            self::fail('keine Ausnahme');
        } catch (LocalizedException $e) {
            self::assertSame('utility', $e->params['param']);
        }
        foreach (['heizoel', 'pellets', 'pv_einspeisung', 'pv_erzeugung', 'waerme'] as $u) self::assertFalse(ScenarioService::supports($u), $u);
        foreach (['gas', 'strom', 'wasser', 'fernwaerme'] as $u) self::assertTrue(ScenarioService::supports($u), $u);
        $r = $this->svc->compute(['utility' => 'heizoel', 'meter_id' => 'x']);
        self::assertSame([false, 'utility_unsupported'], [$r['supported'], $r['reason_code']]);
        // Normalisieren ist idempotent (der Controller prüft vorab)
        $n = ScenarioService::normalize(['utility' => 'gas', 'meter_id' => 'm', 'measures' => [['id' => 'a', 'heat_pct' => 10]],
            'tariff' => ['kind' => 'custom', 'working_price_ct' => 9]]);
        self::assertSame($n, ScenarioService::normalize($n));
    }

    public function testQuantitiesHandleEvJazAndBaseLoad(): void
    {
        $months = [['qty' => 300.0, 'heat_share' => null, 'hdd_delta' => 0.0, 'days' => 30],
                   ['qty' => 310.0, 'heat_share' => null, 'hdd_delta' => 0.0, 'days' => 31]];
        $q = ScenarioService::quantities($months, [300.0, 310.0], ['ev_kwh_year' => 365.0 * 5, 'saving_pct' => 10]);
        self::assertEqualsWithDelta([300 * 0.9 + 150, 310 * 0.9 + 155], $q, 1e-9, 'E-Auto: 5 kWh je Tag, nicht mitgespart');
        $q2 = ScenarioService::quantities($months, [300.0, 310.0], ['jaz_ratio' => 3.0 / 4.0, 'baseload_pct' => 20]);
        self::assertEqualsWithDelta([300 * 1.2 * 0.75, 310 * 1.2 * 0.75], $q2, 1e-9);
        // Raumtemperatur kann den Heizanteil nicht unter null drücken
        $heat = [['qty' => 300.0, 'heat_share' => 0.5, 'hdd_delta' => -1000.0, 'days' => 30]];
        self::assertSame([150.0], ScenarioService::quantities($heat, [300.0], ['heat_a' => 1.0, 'hdd' => true]));
        self::assertSame([300.0], ScenarioService::quantities($heat, [300.0], ['heat_a' => 1.0, 'hdd' => false]), 'ohne Schalter keine Verschiebung');
    }
}
