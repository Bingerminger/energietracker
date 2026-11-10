<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v3.12.0 (F1040, Phase 2) — Prognose-Werkstatt für die PV: ein Modell des Haushalts über
 * Erzeugung, Einspeisung und Netzbezug. Liest nur, schreibt nichts; Prognose und Saldo
 * bleiben unberührt.
 *
 * ── Grundlage ───────────────────────────────────────────────────────────
 * Ein gemessenes Jahr, kein Wetter-Modell (das kommt mit der wetterabhängigen Prognose):
 *   Viertelstunden   ein volles Kalenderjahr mit Erzeugung, Einspeisung und Netzbezug je
 *                    Viertelstunde (IntervalService, F1021): Jede Viertelstunde wird
 *                    nachgerechnet, das Ergebnis ist ein Wert.
 *   Monate           sonst die letzten zwölf vollen Monate aus PvSummaryService: Ohne
 *                    Viertelstunden weiß die App nicht, ob Sonne und Verbrauch zur selben
 *                    Stunde anfallen. Das Ergebnis ist eine Spanne zwischen zwei genannten
 *                    Grenzen (unten: der zusätzliche Sonnenstrom geht ganz ins Netz, der
 *                    Speicher bleibt leer; oben: er deckt Verbrauch, solange welcher da ist,
 *                    und der Speicher lädt und entlädt einmal am Tag wie im Mittel des Monats).
 *
 * ── Stellschrauben ──────────────────────────────────────────────────────
 *   Anlage größer (%)     Erzeugung × (1 + %). Mehr Sonne deckt erst Netzbezug derselben
 *                         Viertelstunde, der Rest geht ins Netz. Je Monat gilt
 *                         0 ≤ Zuwachs ≤ min(Mehrerzeugung, Netzbezug, % × Eigenverbrauch),
 *                         denn je Augenblick ist min(k·Sonne, Bedarf) ≤ k · min(Sonne, Bedarf).
 *   Eigenverbrauch ± %    Verbrauch wandert innerhalb des Tages in die Sonne (Wärmepumpe,
 *                         Wallbox tagsüber): selbst genutzt × %, höchstens die Einspeisung und
 *                         der Netzbezug; der Bedarf bleibt gleich.
 *   Speicher dazu (kWh)   lädt nur aus Sonnenstrom, der sonst ins Netz ginge, entlädt in den
 *                         Netzbezug; nie über die Kapazität. Wirkungsgrad Laden und Entladen
 *                         zusammen BATTERY_EFFICIENCY, Leistung BATTERY_C_RATE × Kapazität je
 *                         Stunde (beides genannte Annahmen). Ein vorhandener Speicher arbeitet
 *                         wie gemessen weiter.
 *   Einspeisevergütung    ct je kWh statt der Vergütung deines Vertrags.
 *
 * Kosten: Netzbezug × Arbeitspreis des Stroms je Monat, Erlös: Einspeisung × Vergütung.
 * Der Grundpreis ändert sich nicht und steht nicht in der Rechnung. Autarkie wie in der
 * PV-Übersicht (PvSummaryService::flows).
 */
final class ScenarioPv
{
    /** Wirkungsgrad eines Speichers, Laden und Entladen zusammen (genannte Annahme). */
    public const BATTERY_EFFICIENCY = 0.9;
    /** Leistung eines Speichers je Stunde als Anteil seiner Kapazität (genannte Annahme). */
    public const BATTERY_C_RATE = 0.5;
    /** Ein Monat zählt mit Viertelstunden, wenn alle drei Reihen so viele seiner Plätze haben. */
    public const SLOT_SHARE = 0.9;
    /** So viele Jahre zurück sucht die Werkstatt ein volles Jahr mit Viertelstunden. */
    private const INTERVAL_YEARS_BACK = 3;
    private const KEYS = ['generation', 'feed_in', 'import', 'self_use', 'autarky', 'import_cost', 'feed_revenue', 'net_cost'];

    public function __construct(
        private PvSummaryService $pv,
        private ?IntervalService $intervals,
        private MeterService $meters,
        private SettingsService $settings,
    ) {}

    /**
     * @param array<string,mixed> $s geprüfte Eingaben (ScenarioService::normalize)
     * @return array<string,mixed> Antwort ohne Kopf
     */
    public function compute(array $s, string $utility, array $meter, string $today): array
    {
        $lv = self::levers($s);
        $summary = $this->pv->compute();
        $series = $this->intervals !== null ? $this->intervalYear($today) : null;
        $rows = $series === null ? self::baseYear($summary['monthly'] ?? [], $today) : null;
        if ($series === null && $rows === null) {
            return ['supported' => true, 'valid' => false, 'reason_code' => 'pv_incomplete', 'calc' => 'pv',
                'profile' => ScenarioProfile::build($utility, $meter)];
        }
        $warnings = [];
        $byYm = [];
        foreach ($summary['monthly'] ?? [] as $r) $byYm[(string)$r['ym']] = $r;

        // ── Monate des Grundjahrs: Mengen und Preise ────────────────────
        $months = [];
        if ($series !== null) {
            $sums = self::slotSums($series);
            $sim = self::simulateSlots($series, $lv);
            foreach ($sums as $i => $b) {
                $ym = sprintf('%04d-%02d', $series['year'], $i + 1);
                $months[] = ['ym' => $ym, 'days' => (int)date('t', (int)strtotime($ym . '-01')), 'base' => $b, 'scen' => $sim[$i], 'low' => null, 'high' => null];
            }
        } else {
            foreach ($rows as $r) {
                $b = ['gen' => (float)$r['erzeugung_kwh'], 'feed' => (float)$r['einspeisung_kwh'], 'import' => (float)$r['bezug_kwh'],
                      'charge' => (float)($r['battery']['charged_kwh'] ?? 0.0), 'discharge' => (float)($r['battery']['discharged_kwh'] ?? 0.0)];
                $days = (int)date('t', (int)strtotime($r['ym'] . '-01'));
                $low = self::monthEdge($b, $days, $lv, false);
                $high = self::monthEdge($b, $days, $lv, true);
                $mid = [];
                foreach ($b as $k => $_) $mid[$k] = ($low[$k] + $high[$k]) / 2;
                $months[] = ['ym' => (string)$r['ym'], 'days' => $days, 'base' => $b, 'scen' => $mid, 'low' => $low, 'high' => $high];
            }
            $warnings[] = ['code' => 'pv_range'];
            $interp = count(array_filter($rows, fn($r) => !empty($r['interpolated'])));
            if ($interp > 0) $warnings[] = ['code' => 'interpolated', 'months' => $interp];
        }
        if (array_filter($months, fn($m) => $m['base']['feed'] > $m['base']['gen'] + PvSummaryService::FEED_ABOVE_GEN_KWH) !== []) {
            $warnings[] = ['code' => 'feed_above_generation'];
        }
        [$importCt, $feedCt] = self::prices($months, $byYm);
        if ($importCt === null) $warnings[] = ['code' => 'no_price'];
        if ($feedCt === null && $lv['feed_ct'] === null) $warnings[] = ['code' => 'no_feed_price'];
        $feedScen = $lv['feed_ct'];

        // ── Je Monat: Fluss, Kosten, Erlös ──────────────────────────────
        $view = function (array $q, array $m, ?float $feedOverride) use ($importCt, $feedCt): array {
            $f = PvSummaryService::flows($q['gen'], $q['feed'], $q['import'], $q['charge'], $q['discharge']);
            $ip = $importCt[$m['ym']] ?? null;
            $fp = $feedOverride ?? ($feedCt[$m['ym']] ?? null);
            $cost = $q['import'] * (float)($ip ?? 0.0) / 100.0;
            $rev = $q['feed'] * (float)($fp ?? 0.0) / 100.0;
            return ['generation' => $q['gen'], 'feed_in' => $q['feed'], 'import' => $q['import'], 'self_use' => max(0.0, $q['gen'] - $q['feed']),
                    'house' => $f['house'], 'grid_in_house' => $f['grid_in_house'], 'autarky' => $f['autarky'],
                    'import_cost' => $cost, 'feed_revenue' => $rev, 'net_cost' => $cost - $rev];
        };
        $out = []; $acc = ['base' => [], 'scen' => [], 'low' => [], 'high' => []];
        $cb = 0.0; $cs = 0.0;
        foreach ($months as $m) {
            $vb = $view($m['base'], $m, null);
            $vs = $view($m['scen'], $m, $feedScen);
            $vl = $m['low'] !== null ? $view($m['low'], $m, $feedScen) : null;
            $vh = $m['high'] !== null ? $view($m['high'], $m, $feedScen) : null;
            if ($vl !== null && $vh !== null) $vs['autarky'] = self::mid($vl['autarky'], $vh['autarky']);
            foreach (['base' => $vb, 'scen' => $vs, 'low' => $vl, 'high' => $vh] as $k => $v) if ($v !== null) $acc[$k][] = $v;
            $cb += $vb['net_cost']; $cs += $vs['net_cost'];
            // Netzbezug als Menge der Werkstatt, die Kosten netto (Bezug − Erlös)
            $out[] = [
                'ym' => $m['ym'], 'base_qty' => round($vb['import'], 1), 'scen_qty' => round($vs['import'], 1),
                'base_cost' => round($vb['net_cost'], 2), 'scen_cost' => round($vs['net_cost'], 2),
                'base_cum_cost' => round($cb, 2), 'scen_cum_cost' => round($cs, 2), 'advance' => null, 'heat_share' => null,
                'band_low' => null, 'band_high' => null,
                // ohne Viertelstunden: die Spanne des Netzbezugs (untere Grenze des Bezugs = obere Wirkung)
                'scen_band_low' => $vh !== null ? round($vh['import'], 1) : null,
                'scen_band_high' => $vl !== null ? round($vl['import'], 1) : null,
                'pv' => ['base' => self::rounded($vb), 'scenario' => self::rounded($vs),
                         'scenario_low' => $vl !== null ? self::rounded($vl) : null, 'scenario_high' => $vh !== null ? self::rounded($vh) : null],
            ];
        }
        $year = fn(array $list): ?array => $list === [] ? null : self::yearOf($list);
        $yb = $year($acc['base']); $ys = $year($acc['scen']); $yl = $year($acc['low']); $yh = $year($acc['high']);
        if ($yl !== null && $yh !== null) $ys['autarky'] = self::mid($yl['autarky'], $yh['autarky']);

        $totals = ['base' => ['qty' => $yb['import'], 'cost' => $yb['net_cost'], 'co2_kg' => null],
                   'scenario' => ['qty' => $ys['import'], 'cost' => $ys['net_cost'], 'co2_kg' => null]];
        $totals['delta'] = [
            'qty' => round($ys['import'] - $yb['import'], 1), 'cost' => round($ys['net_cost'] - $yb['net_cost'], 2), 'co2_kg' => null,
            'qty_pct' => $yb['import'] > 0 ? round(($ys['import'] - $yb['import']) / $yb['import'] * 100, 1) : null,
            'cost_pct' => abs($yb['net_cost']) > 0.005 ? round(($ys['net_cost'] - $yb['net_cost']) / abs($yb['net_cost']) * 100, 1) : null,
        ];

        // ── Rangliste: jede Stellschraube allein ────────────────────────
        $ranking = [];
        $alone = ['pv_size' => ['k' => $lv['k']], 'self_use' => ['shift' => $lv['shift']], 'battery' => ['battery' => $lv['battery']], 'feed_in' => ['feed_ct' => $lv['feed_ct']]];
        $none = ['k' => 1.0, 'shift' => 0.0, 'battery' => 0.0, 'feed_ct' => null] + $lv;
        foreach ($alone as $kind => $set) {
            $one = $set + $none;
            if ($one == $none) continue;   // diese Stellschraube ist nicht bewegt
            [$imp, $net] = $this->yearFor($one, $series, $months, $view);
            $ranking[] = ['kind' => $kind, 'saving_eur' => round($yb['net_cost'] - $net, 2), 'saving_qty' => round($yb['import'] - $imp, 1), 'payback_years' => null];
        }
        usort($ranking, fn($x, $y) => $y['saving_eur'] <=> $x['saving_eur']);

        $avgFeed = $feedCt !== null && $feedCt !== [] ? array_sum($feedCt) / count($feedCt) : null;
        $kwpNow = $s['pv_kwp_now'];
        return [
            'supported'      => true,
            'valid'          => true,
            'calc'           => 'pv',
            'model'          => 'standard',
            'from'           => $months[0]['ym'],
            'to'             => $months[count($months) - 1]['ym'],
            'months'         => $out,
            'totals'         => $totals,
            'band'           => $yl !== null ? ['level_pct' => null, 'base_low' => $yb['import'], 'base_high' => $yb['import'],
                                                'scen_low' => $yh['import'], 'scen_high' => $yl['import']] : null,
            'models'         => ['standard' => null, 'last_year' => null, 'trend' => null],
            'advance'        => null,
            'billing'        => null,
            'tariff'         => ['kind' => 'current', 'contract_id' => null],
            'offers'         => [],
            'ranking'        => $ranking,
            'optimization'   => null,
            'winter_presets' => null,
            'profile'        => ScenarioProfile::build($utility, $meter, ['computable' => true, 'pv' => [
                'feed_ct' => $avgFeed, 'surplus' => $yb['feed_in'] > 0.5, 'import' => $yb['import'] > 0.5]]),
            'capabilities'   => ['heat_model' => false, 'room_temp' => false, 'tariff' => false, 'ev' => false, 'jaz' => false, 'billing' => false,
                                 'co2' => false, 'band' => $yl !== null, 'models' => ['standard' => false, 'last_year' => false, 'trend' => false],
                                 'regression' => false, 'intervals' => $series !== null],
            'basis'          => ['history_months' => count($months), 'last_measured' => $months[count($months) - 1]['ym'], 'hdd_source' => null,
                                 'heat_share_year' => null, 'co2_kg_per_unit' => null],
            'warnings'       => $warnings,
            // v3.12.0 (F1040, Phase 2) — das Jahr des Haushalts mit PV, Ausgangsfall und Szenario; ohne
            // Viertelstunden mit unterer und oberer Grenze (`scenario_low`, `scenario_high`)
            'pv'             => [
                'source'        => $series !== null ? 'intervals' : 'months',
                'year'          => $series['year'] ?? null,
                'kwp'           => $kwpNow !== null ? ['now' => round($kwpNow, 2), 'new' => round($kwpNow * $lv['k'], 2)] : null,
                'size_pct'      => $s['pv_size_pct'],
                'battery_kwh'   => $lv['battery'],
                'self_use_pct'  => $s['self_use_pct'],
                'assumed'       => ['battery_efficiency' => self::BATTERY_EFFICIENCY, 'battery_c_rate' => $series !== null ? self::BATTERY_C_RATE : null,
                                    'battery_cycles_per_day' => $series !== null ? null : 1],
                'prices'        => ['import_ct' => $importCt !== null ? round(array_sum($importCt) / count($importCt), 2) : null,
                                    'feed_ct' => $avgFeed !== null ? round($avgFeed, 2) : null, 'feed_ct_scenario' => $feedScen],
                'base'          => $yb,
                'scenario'      => $ys,
                'scenario_low'  => $yl,
                'scenario_high' => $yh,
            ],
        ];
    }

    // ══ Reine Bausteine (testbar ohne Daten) ═══════════════════════════════

    /** @return array{k:float,shift:float,battery:float,feed_ct:?float,eta:float,rate:float} */
    public static function levers(array $s): array
    {
        return ['k' => 1.0 + (float)($s['pv_size_pct'] ?? 0) / 100.0, 'shift' => (float)($s['self_use_pct'] ?? 0) / 100.0,
                'battery' => (float)($s['battery_kwh'] ?? 0), 'feed_ct' => isset($s['feed_in_ct']) ? (float)$s['feed_in_ct'] : null,
                'eta' => self::BATTERY_EFFICIENCY, 'rate' => self::BATTERY_C_RATE];
    }

    /**
     * Ein Monat ohne Viertelstunden, untere (`$high` false) oder obere Grenze.
     *
     *   P = Sonne im Haus und im vorhandenen Speicher, R = Netz ins Haus, E = Einspeisung
     *   Anlage   ΔG = (k − 1) × Erzeugung, Zuwachs ΔP = 0 bzw. min(ΔG, R, (k − 1) × P)
     *   Verlagern  V = Anteil × (P + ΔP), höchstens Einspeisung und Netzbezug (ins Minus
     *            höchstens P + ΔP)
     *   Speicher   geladen L = 0 bzw. min(Kapazität × Tage, Einspeisung, Bezug ÷ η), entladen η × L
     *
     * @param array{gen:float,feed:float,import:float,charge:float,discharge:float} $b
     * @return array{gen:float,feed:float,import:float,charge:float,discharge:float}
     */
    public static function monthEdge(array $b, int $days, array $lv, bool $high): array
    {
        $f = PvSummaryService::flows($b['gen'], $b['feed'], $b['import'], $b['charge'], $b['discharge'], 6);
        $k = (float)$lv['k'];
        $gen = $k * $b['gen'];
        $dG = $gen - $b['gen'];
        $P = (float)$f['pv_house'] + (float)$f['pv_battery'];
        $R = (float)$f['grid_house'];
        $E = max(0.0, $b['feed']);
        $dP = $high ? max(0.0, min($dG, $R, ($k - 1.0) * $P)) : 0.0;
        $R -= $dP; $E += $dG - $dP; $P += $dP;
        $v = (float)$lv['shift'] * $P;
        $v = $v >= 0 ? min($v, $E, $R) : -min(-$v, $P);
        $R -= $v; $E -= $v;
        $eta = (float)$lv['eta'];
        $load = $high ? max(0.0, min((float)$lv['battery'] * $days, $E, $R / $eta)) : 0.0;
        $E -= $load; $R -= $eta * $load;
        return ['gen' => $gen, 'feed' => max(0.0, $E), 'import' => max(0.0, $R + (float)$f['grid_battery']),
                'charge' => $b['charge'] + $load, 'discharge' => $b['discharge'] + $eta * $load];
    }

    /**
     * Viertelstunden eines Jahres nachrechnen (Ausgangsfall bleibt die Messung). Je Platz:
     *   Anlage   mehr Sonne ΔG = (k − 1) × Erzeugung deckt erst den Netzbezug dieses Platzes
     *   Verlagern  je Tag V = Anteil × selbst genutzt, höchstens Einspeisung und Netzbezug des
     *            Tages, verteilt nach ihrem Anteil an den Plätzen
     *   Speicher   der Reihe nach: laden aus der Einspeisung, entladen in den Netzbezug,
     *            Leistung je Platz Kapazität × Rate ÷ 4, Bestand 0 … Kapazität, Verluste beim
     *            Entladen (η), Start leer am 1. Januar
     *
     * @param array{year:int,gen:list<float>,feed:list<float>,import:list<float>,charge:list<float>,discharge:list<float>,month:list<int>,day:list<int>} $x
     * @return list<array{gen:float,feed:float,import:float,charge:float,discharge:float}> je Monat (Index 0 = Januar)
     */
    public static function simulateSlots(array $x, array $lv): array
    {
        $n = count($x['gen']);
        $k = (float)$lv['k'];
        $e = []; $r = []; $g = [];
        for ($t = 0; $t < $n; $t++) {
            $gen = (float)$x['gen'][$t];
            $dg = ($k - 1.0) * $gen;
            $imp = (float)$x['import'][$t];
            $u = max(0.0, min($dg, $imp));
            $g[$t] = $k * $gen;
            $r[$t] = $imp - $u;
            $e[$t] = (float)$x['feed'][$t] + $dg - $u;
        }
        // Verlagern innerhalb des Tages
        $shift = (float)$lv['shift'];
        if ($shift != 0.0) {
            $byDay = [];
            for ($t = 0; $t < $n; $t++) $byDay[$x['day'][$t]][] = $t;
            foreach ($byDay as $slots) {
                $self = 0.0; $E = 0.0; $R = 0.0; $G = 0.0;
                foreach ($slots as $t) { $self += max(0.0, $g[$t] - $e[$t]); $E += $e[$t]; $R += $r[$t]; $G += $g[$t]; }
                $v = $shift * $self;
                $v = $v >= 0 ? min($v, $E, $R) : -min(-$v, $self);
                if (abs($v) < 1e-12) continue;
                foreach ($slots as $t) {
                    $r[$t] -= $v * ($R > 0 ? $r[$t] / $R : 1.0 / count($slots));
                    $e[$t] -= $v * ($E > 0 ? $e[$t] / $E : ($G > 0 ? $g[$t] / $G : 1.0 / count($slots)));
                    $r[$t] = max(0.0, $r[$t]); $e[$t] = max(0.0, $e[$t]);
                }
            }
        }
        // Speicher dazu
        $cap = (float)$lv['battery'];
        $ch = array_fill(0, $n, 0.0); $dis = array_fill(0, $n, 0.0);
        if ($cap > 0) {
            $eta = (float)$lv['eta'];
            $pmax = $cap * (float)$lv['rate'] / 4.0;
            $soc = 0.0;
            for ($t = 0; $t < $n; $t++) {
                $c = min($e[$t], $pmax, $cap - $soc);
                if ($c > 0) { $soc += $c; $e[$t] -= $c; $ch[$t] = $c; }
                $d = min($r[$t], $pmax, $soc * $eta);
                if ($d > 0) { $soc -= $d / $eta; $r[$t] -= $d; $dis[$t] = $d; }
            }
        }
        $out = array_fill(0, 12, ['gen' => 0.0, 'feed' => 0.0, 'import' => 0.0, 'charge' => 0.0, 'discharge' => 0.0]);
        for ($t = 0; $t < $n; $t++) {
            $i = (int)$x['month'][$t] - 1;
            $out[$i]['gen'] += $g[$t]; $out[$i]['feed'] += $e[$t]; $out[$i]['import'] += $r[$t];
            $out[$i]['charge'] += (float)$x['charge'][$t] + $ch[$t];
            $out[$i]['discharge'] += (float)$x['discharge'][$t] + $dis[$t];
        }
        return $out;
    }

    /** Summen der gemessenen Viertelstunden je Monat (Ausgangsfall). @return list<array<string,float>> */
    public static function slotSums(array $x): array
    {
        $out = array_fill(0, 12, ['gen' => 0.0, 'feed' => 0.0, 'import' => 0.0, 'charge' => 0.0, 'discharge' => 0.0]);
        foreach ($x['gen'] as $t => $v) {
            $i = (int)$x['month'][$t] - 1;
            $out[$i]['gen'] += (float)$v; $out[$i]['feed'] += (float)$x['feed'][$t]; $out[$i]['import'] += (float)$x['import'][$t];
            $out[$i]['charge'] += (float)$x['charge'][$t]; $out[$i]['discharge'] += (float)$x['discharge'][$t];
        }
        return $out;
    }

    /**
     * Die letzten zwölf vollen Monate vor dem laufenden, alle mit Erzeugung, Einspeisung und
     * Netzbezug (`covered`), lückenlos. Null, wenn es sie nicht gibt.
     *
     * @param list<array<string,mixed>> $monthly PvSummaryService::compute()['monthly']
     * @return list<array<string,mixed>>|null
     */
    public static function baseYear(array $monthly, string $today): ?array
    {
        $cur = substr($today, 0, 7);
        $byYm = [];
        foreach ($monthly as $r) if ((string)$r['ym'] < $cur) $byYm[(string)$r['ym']] = $r;
        $covered = array_keys(array_filter($byYm, fn($r) => !empty($r['covered'])));
        if ($covered === []) return null;
        sort($covered);
        $last = end($covered);
        $out = [];
        for ($i = 11; $i >= 0; $i--) {
            $ym = date('Y-m', (int)strtotime($last . '-01 -' . $i . ' months'));
            if (empty($byYm[$ym]['covered'])) return null;
            $out[] = $byYm[$ym];
        }
        return $out;
    }

    /**
     * Preise je Monat: Arbeitspreis des Stroms (PvSummaryService, `bezug_price_ct`) und Vergütung
     * (Erlös ÷ Einspeisung). Ein Monat ohne Preis bekommt das Mittel der übrigen. Null ohne jeden Preis.
     *
     * @return array{0:?array<string,float>,1:?array<string,float>}
     */
    public static function prices(array $months, array $byYm): array
    {
        $imp = []; $feed = [];
        foreach ($months as $m) {
            $r = $byYm[$m['ym']] ?? null;
            if (is_numeric($r['bezug_price_ct'] ?? null)) $imp[$m['ym']] = (float)$r['bezug_price_ct'];
            if ($r !== null && (float)($r['einspeisung_kwh'] ?? 0) > 0.05 && (float)($r['feed_in_revenue_eur'] ?? 0) > 0) {
                $feed[$m['ym']] = (float)$r['feed_in_revenue_eur'] / (float)$r['einspeisung_kwh'] * 100.0;
            }
        }
        $fill = function (array $p) use ($months): ?array {
            if ($p === []) return null;
            $avg = array_sum($p) / count($p);
            $out = [];
            foreach ($months as $m) $out[$m['ym']] = $p[$m['ym']] ?? $avg;
            return $out;
        };
        return [$fill($imp), $fill($feed)];
    }

    // ══ Intern ════════════════════════════════════════════════════════════

    /** Ausgangsfall und eine andere Kombination: Netzbezug und Netto-Kosten des Jahres. @return array{0:float,1:float} */
    private function yearFor(array $lv, ?array $series, array $months, callable $view): array
    {
        $imp = 0.0; $net = 0.0;
        $sim = $series !== null ? self::simulateSlots($series, $lv) : null;
        foreach ($months as $i => $m) {
            if ($sim !== null) $q = $sim[$i];
            else {
                $lo = self::monthEdge($m['base'], $m['days'], $lv, false);
                $hi = self::monthEdge($m['base'], $m['days'], $lv, true);
                $q = [];
                foreach ($lo as $k => $v) $q[$k] = ($v + $hi[$k]) / 2;
            }
            $v = $view($q, $m, $lv['feed_ct']);
            $imp += $v['import']; $net += $v['net_cost'];
        }
        return [$imp, $net];
    }

    /**
     * Ein volles Kalenderjahr mit Viertelstunden (das jüngste vor dem laufenden): Erzeugung
     * (Rolle generation), Einspeisung (Zähler in Summen) und Netzbezug des Hausanschlusses
     * (Stromzähler ohne Elternzähler, Rolle Haushalt, wie FlexibilityInsightService). Ein
     * vorhandener Speicher (Rollen battery_charge/battery_discharge) fließt mit ein.
     *
     * @return array<string,mixed>|null
     */
    private function intervalYear(string $today): ?array
    {
        $roots = array_filter($this->meters->list('strom'), fn($m) => empty($m['parent_meter_id'])
            && Utilities::roleOf('strom', $m) === 'household' && MeterService::countsInTotals($m));
        $gens = array_filter($this->meters->list('pv_erzeugung'), fn($m) => Utilities::roleOf('pv_erzeugung', $m) === 'generation');
        $feeds = array_filter($this->meters->list('pv_einspeisung'), fn($m) => MeterService::countsInTotals($m));
        if ($roots === [] || $gens === [] || $feeds === []) return null;
        $batt = fn(string $role) => array_filter($this->meters->list('pv_erzeugung'), fn($m) => Utilities::roleOf('pv_erzeugung', $m) === $role);
        $y0 = (int)substr($today, 0, 4) - 1;
        for ($y = $y0; $y > $y0 - self::INTERVAL_YEARS_BACK; $y--) {
            $gen = $this->sum('pv_erzeugung', $gens, $y);
            $feed = $this->sum('pv_einspeisung', $feeds, $y);
            $imp = $this->sum('strom', $roots, $y);
            if ($gen === null || $feed === null || $imp === null) continue;
            $clock = $this->intervals->localClock($y);
            $n = count($clock['month']);
            $have = array_fill(1, 12, 0); $all = array_fill(1, 12, 0);
            for ($t = 0; $t < $n; $t++) {
                $mo = $clock['month'][$t];
                $all[$mo]++;
                if (($gen[$t] ?? null) !== null && ($feed[$t] ?? null) !== null && ($imp[$t] ?? null) !== null) $have[$mo]++;
            }
            $ok = true;
            for ($mo = 1; $mo <= 12; $mo++) if ($all[$mo] === 0 || $have[$mo] < self::SLOT_SHARE * $all[$mo]) { $ok = false; break; }
            if (!$ok) continue;
            $ch = $this->sum('pv_erzeugung', $batt('battery_charge'), $y);
            $dis = $this->sum('pv_erzeugung', $batt('battery_discharge'), $y);
            $x = ['year' => $y, 'gen' => [], 'feed' => [], 'import' => [], 'charge' => [], 'discharge' => [], 'month' => $clock['month'], 'day' => $clock['day']];
            for ($t = 0; $t < $n; $t++) {
                $full = ($gen[$t] ?? null) !== null && ($feed[$t] ?? null) !== null && ($imp[$t] ?? null) !== null;
                // ein Platz ohne alle drei Reihen zählt in Ausgangsfall und Szenario gleich nicht
                $x['gen'][] = $full ? (float)$gen[$t] : 0.0;
                $x['feed'][] = $full ? (float)$feed[$t] : 0.0;
                $x['import'][] = $full ? (float)$imp[$t] : 0.0;
                $x['charge'][] = $full ? (float)($ch[$t] ?? 0.0) : 0.0;
                $x['discharge'][] = $full ? (float)($dis[$t] ?? 0.0) : 0.0;
            }
            return $x;
        }
        return null;
    }

    /** Summe der Viertelstunden mehrerer Zähler; ein Platz ist null, wenn kein Zähler einen Wert hat. @return list<?float>|null */
    private function sum(string $utility, array $meters, int $year): ?array
    {
        $acc = null;
        foreach ($meters as $m) {
            $e = $this->intervals->energy($utility, (string)$m['id'], $year);
            if ($e === null) continue;
            $acc ??= array_fill(0, count($e), null);
            foreach ($e as $i => $v) if ($v !== null) $acc[$i] = ($acc[$i] ?? 0.0) + $v;
        }
        return $acc;
    }

    /** Summe eines Jahres aus den Monatsansichten; Autarkie aus den summierten Flüssen. */
    private static function yearOf(array $list): array
    {
        $sum = array_fill_keys(['generation', 'feed_in', 'import', 'self_use', 'house', 'grid_in_house', 'import_cost', 'feed_revenue', 'net_cost'], 0.0);
        foreach ($list as $v) foreach ($sum as $k => $_) $sum[$k] += (float)$v[$k];
        $sum['autarky'] = $sum['house'] > 0.1 ? 1 - $sum['grid_in_house'] / $sum['house'] : null;
        return self::rounded($sum);
    }

    private static function rounded(array $v): array
    {
        $out = [];
        foreach (self::KEYS as $k) {
            $x = $v[$k] ?? null;
            $out[$k] = $x === null ? null : round((float)$x, in_array($k, ['import_cost', 'feed_revenue', 'net_cost'], true) ? 2 : ($k === 'autarky' ? 4 : 1));
        }
        return $out;
    }

    private static function mid(?float $a, ?float $b): ?float
    {
        return $a === null || $b === null ? ($a ?? $b) : ($a + $b) / 2;
    }
}
