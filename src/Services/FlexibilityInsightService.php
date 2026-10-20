<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v3.6.0 — Flexibilität: was du tun kannst.
 *
 * FlexibilityService rechnet nach, was das Verschieben gebracht hat. Dieser
 * Dienst sagt, wann es sich lohnt und wie viel noch drin ist — mit denselben
 * Viertelstunden (IntervalService), nur auf dem Server (die Oberfläche
 * formatiert nur):
 *
 *   pattern   Ampel: je Wochentag und Stunde der mittlere Preis (Börse je
 *             Viertelstunde, ohne Börsenpreise die Zeitfenster für Modul 3 am
 *             Vertrag) und je Stunde die Sonne (Erzeugung), für das Jahr, das
 *             Sommerhalbjahr (April–September) und das Winterhalbjahr.
 *             Grün = das günstigste Drittel der Stunden der Woche, Rot = das
 *             teuerste; bei Modul 3 die Stufen des Netzentgelts.
 *   levers    „Dein Hebel“: Euro im Jahr, wenn Verbrauch innerhalb seines Tages
 *             in die günstigsten Viertelstunden wandert, je Gerät und Preissignal:
 *               spot   Börsenpreis je Viertelstunde — bares Geld nur mit einem
 *                      dynamischen Tarif (der hier immer ein Angebot ist)
 *               tou    Netzentgelt nach Modul 3 — mit dem Vertrag, wie er ist
 *               solar  Sonnenstrom statt Netzbezug: Arbeitspreis minus
 *                      Einspeisevergütung — mit dem Vertrag, wie er ist
 *             Gemessen: Wallbox und Wärmepumpe (Rollen der Stromzähler) und der
 *             Speicher; angenommen (Beispielwerte, ASSUMPTIONS/APPLIANCES):
 *             Wasch- und Spülmaschine.
 *   solar     Sonnenstrom: selbst genutzt, eingespeist, wie viel mehr ginge.
 *   actions   je Gerät der größte Hebel mit dem passenden Zeitfenster der
 *             laufenden Jahreszeit — Grundlage der Handlungssätze.
 *   missing   was fehlt, damit die Seite mehr zeigen kann.
 *
 * Verschoben wird nur innerhalb eines Tages (Ortszeit), höchstens mit der
 * größten gemessenen Leistung des Geräts je Viertelstunde. Der heutige Ablauf
 * ist immer eine der möglichen Verteilungen — ein Hebel ist deshalb nie
 * negativ. Er ist eine Obergrenze: Die günstigsten Viertelstunden kennt man am
 * Vortag (Day-Ahead), Auto und Wäsche müssen dann auch bereitstehen.
 *
 * v3.6.0 (Paket flexplus):
 *   • Beträge zum Börsenpreis mit Umsatzsteuer (`vat_pct`), wie ein
 *     dynamischer Tarif abrechnet: der Satz des dynamischen Angebots, sonst
 *     MarketPriceService::DEFAULT_VAT_PCT. Modul 3 und Sonnenstrom rechnen mit
 *     den Preisen am Vertrag, wie sie eingetragen sind (Arbeitspreis brutto
 *     wie auf der Rechnung).
 *   • Das Auto lädt nur, solange es angesteckt ist: Mit Ladevorgängen aus evcc
 *     (`ev_sessions`, Anstecken bis Abstecken) sind deren Fenster die
 *     Verfügbarkeit, auch über Mitternacht; ohne sie bleibt die Annahme „am
 *     Ladetag den ganzen Tag bereit“ (`availability`).
 *   • `summary`: die Zahl der Ergebniskarte für die Übersicht (Kachel „Dein
 *     Hebel“), über `GET /api/flexibility?summary=1` auch allein.
 */
final class FlexibilityInsightService
{
    public const SEASONS = ['all', 'summer', 'winter'];
    /** Sommerhalbjahr; der Rest ist Winterhalbjahr. */
    public const SUMMER = [4, 5, 6, 7, 8, 9];
    /** Länge der „besten Zeitfenster der Woche“ in Stunden. */
    public const WINDOW_HOURS = 3;
    /** Wie viele Zeitfenster der Woche genannt werden. */
    public const WINDOWS = 3;
    /** Hebel unter diesem Betrag (€ im Jahr) erscheinen nicht. */
    public const MIN_EUR = 1.0;
    /** Ab diesem Anteil der stärksten Stunde gilt die Sonne als „kräftig“, ab SUN_SOME als „etwas“. */
    public const SUN_STRONG = 0.5;
    public const SUN_SOME = 0.15;

    /**
     * Beispielwerte — Annahmen, die die Oberfläche als solche kennzeichnet.
     *   heat_pump_share  Anteil des Tagesverbrauchs der Wärmepumpe, den Gebäude
     *                    und Pufferspeicher in andere Stunden verschieben lassen
     *   ev_share         am Ladetag lässt sich der ganze Ladestrom verschieben
     *                    (das Auto steht bereit)
     */
    public const ASSUMPTIONS = ['heat_pump_share' => 0.3, 'ev_share' => 1.0];

    /** Beispielwerte für Geräte ohne eigenen Zähler: kWh je Gang, Gänge je Woche, Laufzeit in Stunden. */
    public const APPLIANCES = [
        'washer'     => ['kwh_per_cycle' => 0.9, 'cycles_per_week' => 4.0, 'hours' => 2],
        'dishwasher' => ['kwh_per_cycle' => 1.0, 'cycles_per_week' => 5.0, 'hours' => 2],
    ];

    /** Ab diesem Anteil der Ladevorgänge einer Jahreszeit gilt eine Stunde als „meist angesteckt“ (Zeitfenster des Satzes). */
    public const PLUGGED_SHARE = 0.5;

    private const EPS = 1e-6;

    /** v3.6.0 — Ladevorgänge aus evcc (Steckzeiten des Autos); ohne sie die Annahme „ganzer Ladetag“. */
    private ?EvccService $evcc = null;

    public function __construct(
        private IntervalService $intervals,
        private MeterService $meters,
        private ContractService $contracts,
        private FlexibilityService $flex,
    ) {}

    /** v3.6.0 — Ladevorgänge (Anstecken bis Abstecken) für die Ladefenster des Autos. */
    public function attachSessions(EvccService $evcc): void
    {
        $this->evcc = $evcc;
    }

    /**
     * v3.6.0 — nur die Zahl der Ergebniskarte, für die Übersicht: dieselbe
     * Rechnung wie insights(), ohne die Nachrechnung (FlexibilityService), die
     * die Karte nicht braucht.
     *
     * @return array<string,mixed>|null
     */
    public function summary(int $year, ?string $today = null): ?array
    {
        return $this->insights($year, [], $today)['summary'];
    }

    /**
     * @param array<string,mixed> $analysis Ergebnis von FlexibilityService::analyze() für dasselbe Jahr
     * @return array<string,mixed>
     */
    public function insights(int $year, array $analysis, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $clock = $this->intervals->localClock($year);
        $n = count($clock['month']);
        $days = [];
        foreach ($clock['day'] as $i => $d) $days[$d][] = $i;

        $prices = $this->intervals->prices($year);
        $hasPrices = $prices !== null && array_filter($prices, fn($v) => $v !== null) !== [];
        // Börsenpreis mit Umsatzsteuer, wie ein dynamischer Tarif ihn abrechnet
        $vat = $this->vatPct();
        $gross = 1 + $vat / 100;

        // ── Reihen ──
        $strom = $this->meters->list('strom');
        $byId = [];
        foreach ($strom as $m) $byId[(string)$m['id']] = $m;
        $energy = [];
        foreach ($strom as $m) {
            $e = $this->intervals->energy('strom', (string)$m['id'], $year);
            if ($e !== null) $energy[(string)$m['id']] = $e;
        }
        $gen = $this->sumSeries('pv_erzeugung', $year, fn($m) => Utilities::roleOf('pv_erzeugung', $m) === 'generation', $n);
        $charge = $this->sumSeries('pv_erzeugung', $year, fn($m) => Utilities::roleOf('pv_erzeugung', $m) === 'battery_charge', $n);
        $discharge = $this->sumSeries('pv_erzeugung', $year, fn($m) => Utilities::roleOf('pv_erzeugung', $m) === 'battery_discharge', $n);
        $feed = $this->sumSeries('pv_einspeisung', $year, fn($m) => true, $n);

        // Hausanschluss: Stromzähler ohne Elternzähler mit der Rolle Haushalt;
        // Sonnenstrom erreicht ihn und seine Subzähler, nicht eine Wärmepumpe
        // mit eigenem Zähler
        $houseRoots = array_values(array_filter($strom, fn($m) => empty($m['parent_meter_id'])
            && Utilities::roleOf('strom', $m) === 'household' && MeterService::countsInTotals($m)));
        $houseIds = array_map(fn($m) => (string)$m['id'], $houseRoots);
        $behind = fn(array $m) => in_array((string)$m['id'], $houseIds, true) || in_array((string)($m['parent_meter_id'] ?? ''), $houseIds, true);
        $grid = null;
        foreach ($houseIds as $id) {
            if (!isset($energy[$id])) continue;
            $grid ??= array_fill(0, $n, null);
            foreach ($energy[$id] as $i => $v) if ($v !== null) $grid[$i] = ($grid[$i] ?? 0.0) + $v;
        }

        // ── Preissignal der Ampel ──
        $tou = [];
        foreach ($energy as $id => $_) {
            $t = $this->flex->gridTouByMonth($id, $year);
            if ($t !== []) $tou[$id] = $t;
        }
        if ($tou === []) {
            // Modul 3 auch ohne Viertelstunden des Zählers: die Ampel braucht nur die Fenster
            foreach ($strom as $m) {
                $t = $this->flex->gridTouByMonth((string)$m['id'], $year);
                if ($t !== []) { $tou[(string)$m['id']] = $t; break; }
            }
        }
        $signal = $hasPrices ? 'spot' : ($tou !== [] ? 'tou' : null);
        $touCt = [];
        foreach ($tou as $id => $t) $touCt[$id] = $this->touSeries($t, $clock);
        $ampel = $signal === 'spot' ? $prices : ($signal === 'tou' ? reset($touCt) : null);
        $pattern = null;
        if ($ampel !== null || $gen !== null) {
            $pattern = [];
            foreach (self::SEASONS as $s) $pattern[$s] = $this->pattern($ampel, $gen, $clock, $s, $signal === 'tou' ? $this->standardCt(reset($tou)) : null);
        }
        $seasonNow = in_array((int)substr($today, 5, 2), self::SUMMER, true) ? 'summer' : 'winter';

        // ── Preise des Vertrags für Sonnenstrom ──
        $wp = $this->monthlyWorkingCt($houseIds, $year);
        $feedCt = $this->monthlyFeedCt($year);
        $exportRoom = $feed !== null && $gen !== null ? array_map(fn($v) => max(0.0, (float)($v ?? 0.0)), $feed) : null;

        // ── Hebel ──
        $levers = [];
        $add = function (array $l) use (&$levers): void {
            if ($l['eur'] !== null && $l['eur'] >= self::MIN_EUR) $levers[] = $l;
        };
        // Gemessene Geräte: Wallbox zuerst (sie nutzt Sonnenstrom am meisten), dann Wärmepumpe
        $devices = [];
        foreach ($energy as $id => $e) {
            $role = Utilities::roleOf('strom', $byId[$id]);
            if ($role === 'ev_charger') $devices[] = [$id, 'ev', self::ASSUMPTIONS['ev_share']];
        }
        foreach ($energy as $id => $e) {
            $role = Utilities::roleOf('strom', $byId[$id]);
            if ($role === 'heat_pump') $devices[] = [$id, 'heat_pump', self::ASSUMPTIONS['heat_pump_share']];
        }
        $evInfo = [];
        foreach ($devices as [$id, $kind, $share]) {
            $e = $energy[$id];
            $cap = max(array_map(fn($v) => (float)($v ?? 0.0), $e));
            if ($cap <= self::EPS) continue;
            $base = ['kind' => $kind, 'meter_id' => $id, 'name' => (string)($byId[$id]['name'] ?? $id), 'basis' => 'measured', 'share' => $share,
                'availability' => null, 'sessions' => null, 'session_share' => null];
            $mode = $kind === 'ev' ? 'week' : 'day';
            // v3.6.0 — das Auto: verschoben wird, solange es angesteckt ist (Ladevorgänge aus evcc)
            $groups = $days; $plugged = null;
            if ($kind === 'ev') {
                $w = $this->evWindows($id, $e, $days, $clock['day'], $year);
                $base['availability'] = $w !== null ? 'sessions' : 'day';
                if ($w !== null) {
                    $groups = $w['groups'];
                    $plugged = $w['windows'];
                    $base['sessions'] = $w['sessions'];
                    $base['session_share'] = round($w['share'], 4);
                }
                $evKwh = array_sum(array_map(fn($v) => (float)($v ?? 0.0), $e));
                $evInfo[] = ['sessions' => $w['sessions'] ?? 0, 'kwh' => $evKwh, 'in' => ($w['share'] ?? 0.0) * $evKwh];
            }
            if ($hasPrices) {
                $r = self::shiftDays($e, $prices, $groups, $share, $cap, 'min');
                $add($base + ['signal' => 'spot', 'real' => false, 'kwh' => round($r['kwh'], 1), 'shift_kwh' => round($r['moved'], 1),
                    'eur' => round($r['delta'] / 100 * $gross, 2),
                    'window' => $plugged !== null ? $this->pluggedWindows($pattern, $plugged, $clock, 'cheap', self::WINDOW_HOURS)
                        : $this->windows($pattern, $mode, 'cheap', self::WINDOW_HOURS)]);
            }
            if (isset($touCt[$id])) {
                $r = self::shiftDays($e, $touCt[$id], $groups, $share, $cap, 'min');
                $add($base + ['signal' => 'tou', 'real' => true, 'kwh' => round($r['kwh'], 1), 'shift_kwh' => round($r['moved'], 1),
                    'eur' => round($r['delta'] / 100, 2), 'window' => $this->touWindows($touCt[$id], $clock)]);
            }
            if ($exportRoom !== null && $behind($byId[$id]) && $wp !== []) {
                $moved = self::solarShift($e, $exportRoom, $grid, $groups, $clock['month'], $share, $cap);
                $add($base + ['signal' => 'solar', 'real' => true, 'kwh' => round(array_sum(array_map(fn($v) => (float)($v ?? 0), $e)), 1),
                    'shift_kwh' => round(array_sum($moved), 1), 'eur' => self::solarEur($moved, $wp, $feedCt),
                    'window' => $plugged !== null ? $this->pluggedWindows($pattern, $plugged, $clock, 'sun', self::WINDOW_HOURS)
                        : $this->windows($pattern, 'day', 'sun', self::WINDOW_HOURS)]);
            }
        }
        // Speicher: Entladen in die teuersten Viertelstunden, in denen das Haus Strom braucht
        if ($hasPrices && $charge !== null && $discharge !== null && $grid !== null) {
            $cap = max(array_map(fn($v) => (float)($v ?? 0.0), $discharge));
            $room = [];
            foreach ($discharge as $i => $v) {
                $room[$i] = ($charge[$i] ?? 0.0) > self::EPS ? 0.0 : min($cap, (float)($grid[$i] ?? 0.0) + (float)($v ?? 0.0));
            }
            $r = self::shiftDays($discharge, $prices, $days, 1.0, $cap, 'max', $room);
            $add(['kind' => 'battery', 'meter_id' => null, 'name' => null, 'basis' => 'measured', 'share' => 1.0,
                'availability' => null, 'sessions' => null, 'session_share' => null,
                'signal' => 'spot', 'real' => false, 'kwh' => round($r['kwh'], 1), 'shift_kwh' => round($r['moved'], 1),
                'eur' => round($r['delta'] / 100 * $gross, 2), 'window' => $this->windows($pattern, 'day', 'peak', self::WINDOW_HOURS)]);
        }
        // Wasch- und Spülmaschine (Beispielwerte): gegen einen Start zu beliebiger Zeit
        $applianceKwh = 0.0;
        foreach (self::APPLIANCES as $kind => $a) {
            $daily = $a['kwh_per_cycle'] * $a['cycles_per_week'] / 7;
            $w = (int)$a['hours'] * 4;
            $base = ['kind' => $kind, 'meter_id' => null, 'name' => null, 'basis' => 'assumed', 'share' => null,
                'availability' => null, 'sessions' => null, 'session_share' => null, 'assumption' => $a];
            if ($hasPrices) {
                $r = self::applianceSpot($prices, $days, $daily, $w);
                $add($base + ['signal' => 'spot', 'real' => false, 'kwh' => round($r['kwh'], 1), 'shift_kwh' => round($r['kwh'], 1),
                    'eur' => round($r['delta'] / 100 * $gross, 2), 'window' => $this->windows($pattern, 'week', 'cheap', (int)$a['hours'])]);
            }
            if ($exportRoom !== null && $wp !== [] && $houseIds !== []) {
                $moved = self::applianceSolar($exportRoom, $days, $clock['month'], $daily, $w);
                $applianceKwh += array_sum($moved);
                $add($base + ['signal' => 'solar', 'real' => true, 'kwh' => round($daily * count($days), 1),
                    'shift_kwh' => round(array_sum($moved), 1), 'eur' => self::solarEur($moved, $wp, $feedCt),
                    'window' => $this->windows($pattern, 'day', 'sun', (int)$a['hours'])]);
            }
        }
        usort($levers, fn($a, $b) => [$b['eur'], $a['kind']] <=> [$a['eur'], $b['kind']]);

        // Größter Hebel je Gerät — die Summe zählt jedes Gerät einmal
        $best = [];
        foreach ($levers as $l) {
            $k = $l['kind'] . '|' . ($l['meter_id'] ?? '');
            if (!isset($best[$k])) $best[$k] = $l;
        }
        $bestReal = [];
        foreach ($levers as $l) {
            $k = $l['kind'] . '|' . ($l['meter_id'] ?? '');
            if ($l['real'] && !isset($bestReal[$k])) $bestReal[$k] = $l;
        }
        // Handlungssätze: je Gerät der größte Hebel mit dem Vertrag, wie er ist;
        // nur wo es keinen gibt, der mit einem dynamischen Tarif. v3.6.0 — hat
        // der mit dem Vertrag in der laufenden Jahreszeit kein Zeitfenster (das
        // Auto ist im Winter mittags nicht angesteckt), der dynamische, wenn er eins hat
        $pick = [];
        foreach ($best as $k => $l) {
            $r = $bestReal[$k] ?? null;
            $pick[$k] = $r !== null && (($r['window'][$seasonNow] ?? null) !== null || ($l['window'][$seasonNow] ?? null) === null) ? $r : $l;
        }
        usort($pick, fn($a, $b) => [$b['eur'], $a['kind']] <=> [$a['eur'], $b['kind']]);
        $actions = [];
        foreach ($pick as $l) {
            $actions[] = ['kind' => $l['kind'], 'meter_id' => $l['meter_id'], 'name' => $l['name'], 'signal' => $l['signal'],
                'real' => $l['real'], 'basis' => $l['basis'], 'eur' => $l['eur'], 'season' => $seasonNow,
                'window' => $l['window'][$seasonNow] ?? null, 'other' => $l['window'][$seasonNow === 'summer' ? 'winter' : 'summer'] ?? null];
        }

        // ── Sonnenstrom ──
        $solar = null;
        if ($gen !== null && $feed !== null) {
            $g = array_sum(array_map(fn($v) => (float)($v ?? 0.0), $gen));
            $f = array_sum(array_map(fn($v) => (float)($v ?? 0.0), $feed));
            $c = $charge !== null ? array_sum(array_map(fn($v) => (float)($v ?? 0.0), $charge)) : 0.0;
            if ($g > 0.1) {
                $more = 0.0; $moreEur = 0.0;
                foreach ($levers as $l) if ($l['signal'] === 'solar') { $more += $l['shift_kwh']; $moreEur += $l['eur']; }
                $self = max(0.0, $g - $f);
                $solar = [
                    'generation_kwh' => round($g, 1), 'feed_kwh' => round($f, 1), 'charged_kwh' => round($c, 1),
                    'self_kwh' => round($self, 1), 'direct_kwh' => round(max(0.0, $g - $f - $c), 1),
                    'self_share' => round($self / $g, 4),
                    'more_kwh' => round(min($more, $f), 1), 'more_eur' => round($moreEur, 2),
                    'self_share_after' => round(min($g, $self + $more) / $g, 4),
                    'more_share' => round((min($g, $self + $more) - $self) / $g, 4),
                    'working_ct' => $wp !== [] ? round(array_sum($wp) / count($wp), 2) : null,
                    'feed_ct' => $feedCt !== [] ? round(array_sum($feedCt) / count($feedCt), 2) : null,
                ];
            }
        }

        // ── Schon erreicht (aus der Nachrechnung) ──
        $profile = 0.0;
        foreach ($analysis['meters'] ?? [] as $m) if (!empty($m['controllable'])) $profile += (float)($m['total']['advantage_eur'] ?? 0.0);
        $module3 = 0.0;
        foreach ($analysis['module3'] ?? [] as $m) $module3 += (float)($m['total']['saving_eur'] ?? 0.0);

        $totalEur = round(array_sum(array_map(fn($l) => $l['eur'], $best)), 2);
        $totalReal = round(array_sum(array_map(fn($l) => $l['eur'], $bestReal)), 2);
        // v3.6.0 — Ladefenster des Autos über alle Wallboxen: Steckzeiten, wo es Ladevorgänge gibt
        $evKwh = array_sum(array_column($evInfo, 'kwh'));
        $evSessions = array_sum(array_column($evInfo, 'sessions'));
        $evAvailability = $evInfo === [] ? null : [
            'basis' => $evSessions > 0 ? 'sessions' : 'day',
            'sessions' => $evSessions,
            'share' => $evKwh > self::EPS ? round(array_sum(array_column($evInfo, 'in')) / $evKwh, 4) : 0.0,
        ];

        return [
            'year' => $year,
            'signal' => $signal,
            'season_now' => $seasonNow,
            'pattern' => $pattern,
            'levers' => $levers,
            'total_eur' => $totalEur,
            'total_real_eur' => $totalReal,
            'vat_pct' => $vat,
            'ev_availability' => $evAvailability,
            'summary' => self::summarize($year, $actions, $totalEur, $totalReal, $vat, $energy !== [] || ($gen !== null && $feed !== null)),
            'actions' => $actions,
            'solar' => $solar,
            'achieved' => [
                'profile_eur' => array_filter($analysis['meters'] ?? [], fn($m) => !empty($m['controllable']) && $m['total'] !== null) !== [] ? round($profile, 2) : null,
                'battery_eur' => isset($analysis['battery']['total']['value_eur']) ? (float)$analysis['battery']['total']['value_eur'] : null,
                'module3_eur' => ($analysis['module3'] ?? []) !== [] ? round($module3, 2) : null,
            ],
            'missing' => $this->missing($hasPrices, $energy, $byId, $gen, $feed, $tou, $year),
            'assumptions' => self::ASSUMPTIONS + ['appliances' => self::APPLIANCES, 'window_hours' => self::WINDOW_HOURS],
        ];
    }

    /**
     * v3.6.0 — die Zahl der Ergebniskarte, eine Regel für Seite, Bericht und
     * Übersicht: mit dem Vertrag, wie er ist (`real`), sobald es dort einen
     * Hebel ab 1 € gibt, sonst mit dynamischem Tarif; daneben „bis zu …“ mit
     * dynamischem Tarif, wenn das mehr ist. `action`: der erste Handlungssatz
     * mit Zeitfenster, bei der großen Zahl mit dem Vertrag zuerst einer mit dem
     * Vertrag — er ist auch „Größter Hebel“ der Karte. `measured`: Es gibt gemessene Viertelstunden (Strom oder
     * Erzeugung und Einspeisung) — nur dann zeigt die Übersicht die Kachel.
     * null ohne Hebel.
     *
     * @param list<array<string,mixed>> $actions
     * @return array<string,mixed>|null
     */
    private static function summarize(int $year, array $actions, float $total, float $real, float $vat, bool $measured): ?array
    {
        if ($actions === []) return null;
        $useReal = $real >= self::MIN_EUR;
        $action = null;
        foreach ($actions as $a) if ($a['window'] !== null && (!$useReal || $a['real'])) { $action = $a; break; }
        if ($action === null) foreach ($actions as $a) if ($a['window'] !== null) { $action = $a; break; }
        return [
            'year' => $year,
            'eur' => $useReal ? $real : $total,
            'real' => $useReal,
            'dynamic_eur' => $useReal && $total > $real + 1 ? $total : null,
            'vat_pct' => $vat,
            'measured' => $measured,
            'action' => $action,
        ];
    }

    // ── Ampel ──────────────────────────────────────────────────────────

    /**
     * Wochenraster und Tagesband einer Jahreszeit. Ohne Preissignal nur die Sonne.
     *
     * @param list<?float>|null $c   Preis je Viertelstunde (ct/kWh)
     * @param list<?float>|null $gen Erzeugung je Viertelstunde (kWh)
     * @param ?float $standard       Modul 3: Standardtarif — Stufen statt Dritteln
     * @return array<string,mixed>
     */
    private function pattern(?array $c, ?array $gen, array $clock, string $season, ?float $standard): array
    {
        $sum = []; $cnt = []; $hs = array_fill(0, 24, 0.0); $hc = array_fill(0, 24, 0);
        $gs = array_fill(0, 24, 0.0); $gc = array_fill(0, 24, 0);
        $dayset = [];
        foreach ($clock['month'] as $i => $mo) {
            if ($season === 'summer' && !in_array($mo, self::SUMMER, true)) continue;
            if ($season === 'winter' && in_array($mo, self::SUMMER, true)) continue;
            $h = intdiv($clock['minute'][$i], 60);
            if ($c !== null && $c[$i] !== null) {
                $w = $clock['weekday'][$i];
                $sum[$w][$h] = ($sum[$w][$h] ?? 0.0) + $c[$i];
                $cnt[$w][$h] = ($cnt[$w][$h] ?? 0) + 1;
                $hs[$h] += $c[$i]; $hc[$h]++;
                $dayset[$clock['day'][$i]] = true;
            }
            if ($gen !== null && $gen[$i] !== null) { $gs[$h] += $gen[$i]; $gc[$h]++; }
        }
        $week = [];
        $vals = [];
        for ($w = 1; $w <= 7; $w++) {
            for ($h = 0; $h < 24; $h++) {
                $v = isset($cnt[$w][$h]) ? $sum[$w][$h] / $cnt[$w][$h] : null;
                $week[$w - 1][$h] = $v;
                if ($v !== null) $vals[] = $v;
            }
        }
        $hours = [];
        for ($h = 0; $h < 24; $h++) $hours[$h] = $hc[$h] > 0 ? $hs[$h] / $hc[$h] : null;
        $sun = [];
        for ($h = 0; $h < 24; $h++) $sun[$h] = $gc[$h] > 0 ? $gs[$h] / $gc[$h] : null;
        $sunMax = max(array_map(fn($v) => (float)($v ?? 0.0), $sun));
        $sunShare = array_map(fn($v) => $v === null || $sunMax <= self::EPS ? null : round($v / $sunMax, 3), $sun);
        if ($gen === null) $sunShare = array_fill(0, 24, null);

        [$lo, $hi] = $vals !== [] ? self::terciles($vals) : [null, null];
        $hourVals = array_values(array_filter($hours, fn($v) => $v !== null));
        [$hlo, $hhi] = $hourVals !== [] ? self::terciles($hourVals) : [null, null];
        $level = function (?float $v, ?float $lo, ?float $hi) use ($standard): ?string {
            if ($v === null) return null;
            if ($standard !== null) return $v < $standard - 0.001 ? 'cheap' : ($v > $standard + 0.001 ? 'expensive' : 'mid');
            if ($lo === null) return null;
            // Gleichstände: Fallen beide Grenzen auf denselben Preis, ist er „mittel“
            if ($v <= $lo && $v < $hi) return 'cheap';
            if ($v >= $hi && $v > $lo) return 'expensive';
            return 'mid';
        };
        $mean = $vals !== [] ? array_sum($vals) / count($vals) : null;
        $out = [
            'days' => count($dayset),
            'mean_ct' => $mean !== null ? round($mean, 2) : null,
            'min_ct' => $vals !== [] ? round(min($vals), 2) : null,
            'max_ct' => $vals !== [] ? round(max($vals), 2) : null,
            'cheap_ct' => $lo !== null && $standard === null ? round($lo, 2) : null,
            'expensive_ct' => $hi !== null && $standard === null ? round($hi, 2) : null,
            'week' => array_map(fn($row) => array_map(fn($v) => ['ct' => $v !== null ? round($v, 2) : null, 'level' => $level($v, $lo, $hi)], $row), $week),
            'hours' => array_map(fn($h) => [
                'ct' => $hours[$h] !== null ? round($hours[$h], 2) : null,
                'level' => $level($hours[$h], $hlo, $hhi),
                'sun' => $sunShare[$h],
                'sun_level' => $sunShare[$h] === null ? null : ($sunShare[$h] >= self::SUN_STRONG ? 'strong' : ($sunShare[$h] >= self::SUN_SOME ? 'some' : 'none')),
            ], range(0, 23)),
        ];
        $out['windows'] = $this->bestWeekWindows($week, $mean, $sunShare, self::WINDOW_HOURS, self::WINDOWS);
        // Satz unter der Ampel: günstigste und teuerste Stunden des typischen Tages
        $out['day_cheap'] = self::dayBlock($hours, self::WINDOW_HOURS, 'min');
        $out['day_peak'] = self::dayBlock($hours, self::WINDOW_HOURS, 'max');
        return $out;
    }

    /** Zusammenhängende Stunden des typischen Tages mit dem niedrigsten bzw. höchsten Mittel. @param list<?float> $hours */
    private static function dayBlock(array $hours, int $len, string $dir): ?array
    {
        $best = null;
        for ($h = 0; $h + $len <= 24; $h++) {
            $part = array_slice($hours, $h, $len);
            if (in_array(null, $part, true)) continue;
            $v = array_sum($part) / $len;
            if ($best === null || ($dir === 'min' ? $v < $best[0] - 1e-9 : $v > $best[0] + 1e-9)) $best = [$v, $h];
        }
        return $best === null ? null : ['weekday' => null, 'from' => $best[1], 'to' => $best[1] + $len, 'ct' => round($best[0], 2)];
    }

    /** Unteres und oberes Drittel (Grenzen nach Rang, lineare Interpolation). @param list<float> $v */
    private static function terciles(array $v): array
    {
        sort($v);
        $q = function (float $p) use ($v): float {
            $x = $p * (count($v) - 1);
            $i = (int)floor($x);
            return $i + 1 < count($v) ? $v[$i] + ($v[$i + 1] - $v[$i]) * ($x - $i) : $v[$i];
        };
        return [$q(1 / 3), $q(2 / 3)];
    }

    /**
     * Die günstigsten Zeitfenster der Woche: je Wochentag zusammenhängende
     * Stunden innerhalb des Tages, die sich nicht überschneiden.
     *
     * @param array<int,array<int,?float>> $week
     * @return list<array<string,mixed>>
     */
    private function bestWeekWindows(array $week, ?float $mean, array $sun, int $len, int $count): array
    {
        $cand = [];
        foreach ($week as $wi => $row) {
            for ($h = 0; $h + $len <= 24; $h++) {
                $part = array_slice($row, $h, $len);
                if (in_array(null, $part, true)) continue;
                $cand[] = [array_sum($part) / $len, $wi + 1, $h];
            }
        }
        usort($cand, fn($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        $out = []; $taken = [];
        foreach ($cand as [$ct, $w, $h]) {
            $clash = false;
            foreach ($taken[$w] ?? [] as $t) if ($h < $t + $len && $t < $h + $len) $clash = true;
            if ($clash) continue;
            $taken[$w][] = $h;
            $s = array_slice($sun, $h, $len);
            $out[] = ['weekday' => $w, 'from' => $h, 'to' => $h + $len, 'ct' => round($ct, 2),
                'below_ct' => $mean !== null ? round($mean - $ct, 2) : null,
                'below_pct' => $mean !== null && abs($mean) > 0.01 ? round(($mean - $ct) / abs($mean) * 100, 1) : null,
                'sun' => in_array(null, $s, true) ? null : round(array_sum($s) / $len, 3)];
            if (count($out) >= $count) break;
        }
        return $out;
    }

    /**
     * Zeitfenster eines Hebels je Jahreszeit.
     *   week  + cheap  günstigstes Fenster der Woche (Wochentag und Stunde)
     *   day   + cheap  günstigste Stunden des Tages, jeden Tag
     *   day   + peak   teuerste Stunden des Tages (Speicher: dann entladen)
     *   day   + sun    sonnigste Stunden des Tages
     *
     * @return array{summer:?array,winter:?array}
     */
    private function windows(?array $pattern, string $mode, string $what, int $len): array
    {
        $out = ['summer' => null, 'winter' => null];
        if ($pattern === null) return $out;
        foreach (['summer', 'winter'] as $s) {
            $p = $pattern[$s];
            if ($mode === 'week') {
                $best = $this->bestWeekWindows(array_map(fn($row) => array_map(fn($c) => $c['ct'], $row), $p['week']), null, array_column($p['hours'], 'sun'), $len, 1)[0] ?? null;
                $out[$s] = $best !== null ? ['weekday' => $best['weekday'], 'from' => $best['from'], 'to' => $best['to'], 'ct' => $best['ct'], 'what' => 'cheap'] : null;
                continue;
            }
            if ($what !== 'sun') {
                $b = self::dayBlock(array_column($p['hours'], 'ct'), $len, $what === 'cheap' ? 'min' : 'max');
                $out[$s] = $b !== null ? $b + ['what' => $what] : null;
                continue;
            }
            $b = self::dayBlock(array_column($p['hours'], 'sun'), $len, 'max');
            if ($b === null || $b['ct'] < self::SUN_SOME) continue;
            $out[$s] = ['weekday' => null, 'from' => $b['from'], 'to' => $b['to'], 'ct' => null, 'what' => 'sun'];
        }
        return $out;
    }

    /** Modul 3: das Niedrigtarif-Fenster je Jahreszeit (längster Lauf der Stunden mit dem niedrigsten Netzentgelt). */
    private function touWindows(array $touCt, array $clock): array
    {
        $out = ['summer' => null, 'winter' => null];
        foreach (['summer', 'winter'] as $s) {
            $p = $this->pattern($touCt, null, $clock, $s, 0.0);
            $vals = array_column($p['hours'], 'ct');
            $nn = array_values(array_filter($vals, fn($v) => $v !== null));
            if ($nn === []) continue;
            $min = min($nn);
            if (abs($min - max($nn)) < 0.001) continue;          // keine Stufen in dieser Jahreszeit
            $bestFrom = null; $bestLen = 0; $from = null;
            for ($h = 0; $h <= 24; $h++) {
                $low = $h < 24 && $vals[$h] !== null && $vals[$h] <= $min + 0.001;
                if ($low && $from === null) $from = $h;
                if (!$low && $from !== null) {
                    if ($h - $from > $bestLen) { $bestLen = $h - $from; $bestFrom = $from; }
                    $from = null;
                }
            }
            if ($bestFrom !== null) $out[$s] = ['weekday' => null, 'from' => $bestFrom, 'to' => $bestFrom + $bestLen, 'ct' => round($min, 2), 'what' => 'low'];
        }
        return $out;
    }

    /**
     * v3.6.0 — Zeitfenster des Satzes für das Auto mit Steckzeiten: nur
     * Stunden, in denen das Auto in der Jahreszeit meist angesteckt ist (in
     * mindestens PLUGGED_SHARE der Ladevorgänge).
     *   cheap  die günstigsten `len` Stunden am Stück darunter; gibt es keine,
     *          das günstigste Fenster der Woche wie ohne Ladevorgänge
     *   sun    die sonnigsten (mindestens „etwas Sonne“); gibt es keine, kein
     *          Fenster — dann lädt das Auto in dieser Jahreszeit nicht mit Sonne
     * Ohne Ladevorgang in einer Jahreszeit gilt dort das Fenster ohne Steckzeiten.
     *
     * @param list<array{0:int,1:int}> $windows Ladefenster (erste und letzte Viertelstunde)
     * @return array{summer:?array,winter:?array}
     */
    private function pluggedWindows(?array $pattern, array $windows, array $clock, string $what, int $len): array
    {
        $fallback = $what === 'sun' ? $this->windows($pattern, 'day', 'sun', $len) : $this->windows($pattern, 'week', 'cheap', $len);
        $out = ['summer' => null, 'winter' => null];
        if ($pattern === null) return $out;
        $count = ['summer' => 0, 'winter' => 0];
        $hours = ['summer' => array_fill(0, 24, 0), 'winter' => array_fill(0, 24, 0)];
        foreach ($windows as [$a, $b]) {
            $s = in_array($clock['month'][$a], self::SUMMER, true) ? 'summer' : 'winter';
            $count[$s]++;
            $seen = [];
            for ($i = $a; $i <= $b; $i++) $seen[intdiv($clock['minute'][$i], 60)] = true;
            foreach (array_keys($seen) as $h) $hours[$s][$h]++;
        }
        foreach (['summer', 'winter'] as $s) {
            if ($count[$s] === 0) { $out[$s] = $fallback[$s]; continue; }
            $vals = array_column($pattern[$s]['hours'], $what === 'sun' ? 'sun' : 'ct');
            foreach ($vals as $h => $v) if ($hours[$s][$h] < self::PLUGGED_SHARE * $count[$s]) $vals[$h] = null;
            $b = self::dayBlock($vals, $len, $what === 'sun' ? 'max' : 'min');
            if ($what === 'sun') {
                $out[$s] = $b !== null && $b['ct'] >= self::SUN_SOME ? ['weekday' => null, 'from' => $b['from'], 'to' => $b['to'], 'ct' => null, 'what' => 'sun', 'plugged' => true] : null;
                continue;
            }
            $out[$s] = $b !== null ? $b + ['what' => 'cheap', 'plugged' => true] : $fallback[$s];
        }
        return $out;
    }

    /** Netzentgelt nach Modul 3 je Viertelstunde (null in Monaten ohne Zeitfenster). @return list<?float> */
    private function touSeries(array $byMonth, array $clock): array
    {
        $out = [];
        foreach ($clock['month'] as $i => $mo) {
            $out[$i] = isset($byMonth[$mo]) ? FlexibilityService::touCt($byMonth[$mo], $mo, $clock['minute'][$i]) : null;
        }
        return $out;
    }

    private function standardCt(array $byMonth): ?float
    {
        $first = reset($byMonth);
        return is_array($first) ? (float)$first['standard_ct'] : null;
    }

    // ── Hebel ──────────────────────────────────────────────────────────

    /**
     * v3.6.0 — Wann das Auto angesteckt war. evcc führt je Ladevorgang das
     * Anstecken (`created`) und das Abstecken (`finished`); in diesem Fenster
     * lässt sich Laden verschieben, auch über Mitternacht. Gruppen für
     * shiftDays() und solarShift():
     *   • je Ladefenster eine Gruppe (überlappende Vorgänge zusammen),
     *   • an Tagen mit Ladefenster jede übrige Viertelstunde für sich — Laden
     *     außerhalb der Fenster bleibt, wo es ist,
     *   • Tage ohne Ladevorgang (evcc lief noch nicht, ein Vorgang fehlt) ganz
     *     wie bisher: die Annahme „am Ladetag den ganzen Tag bereit“.
     * `share`: Anteil der Ladung des Jahres, der in einem Ladefenster liegt.
     *
     * @param list<?float> $e Ladung je Viertelstunde
     * @param array<int,list<int>> $days
     * @param list<int> $dayOf Tag des Jahres je Viertelstunde
     * @return array{groups: list<list<int>>, windows: list<array{0:int,1:int}>, sessions: int, share: float}|null
     *         null ohne Ladevorgang im Jahr
     */
    private function evWindows(string $meterId, array $e, array $days, array $dayOf, int $year): ?array
    {
        if ($this->evcc === null) return null;
        $start = $this->intervals->yearStart($year);
        $n = count($dayOf);
        $raw = [];
        foreach ($this->evcc->sessions($meterId) as $s) {
            $a = strtotime((string)($s['created'] ?? ''));
            $b = strtotime((string)($s['finished'] ?? ''));
            if ($a === false || $b === false || $b <= $a) continue;
            // Viertelstunden, die das Fenster berühren
            $i0 = (int)floor(($a - $start) / IntervalService::STEP);
            $i1 = (int)ceil(($b - $start) / IntervalService::STEP) - 1;
            if ($i1 < 0 || $i0 >= $n) continue;
            $raw[] = [max(0, $i0), min($n - 1, $i1)];
        }
        if ($raw === []) return null;
        usort($raw, fn($x, $y) => $x <=> $y);
        $windows = [];
        foreach ($raw as [$a, $b]) {
            $last = count($windows) - 1;
            if ($last >= 0 && $a <= $windows[$last][1]) { $windows[$last][1] = max($windows[$last][1], $b); continue; }
            $windows[] = [$a, $b];
        }
        $groups = []; $in = []; $covered = [];
        foreach ($windows as [$a, $b]) {
            $groups[] = range($a, $b);
            for ($i = $a; $i <= $b; $i++) { $in[$i] = true; $covered[$dayOf[$i]] = true; }
        }
        foreach ($days as $d => $slots) {
            if (!isset($covered[$d])) { $groups[] = $slots; continue; }
            foreach ($slots as $i) if (!isset($in[$i])) $groups[] = [$i];
        }
        $all = 0.0; $inside = 0.0;
        foreach ($e as $i => $v) {
            $v = max(0.0, (float)($v ?? 0.0));
            $all += $v;
            if (isset($in[$i])) $inside += $v;
        }
        return ['groups' => $groups, 'windows' => $windows, 'sessions' => count($raw), 'share' => $all > self::EPS ? $inside / $all : 0.0];
    }

    /**
     * Verbrauch innerhalb jedes Tages neu verteilen: ein Anteil `share` jeder
     * Viertelstunde wird frei und füllt — günstigste (min) bzw. teuerste (max,
     * Speicher) zuerst — die Viertelstunden des Tages bis zur Leistung `cap`
     * (und höchstens bis `room`). Viertelstunden ohne Preis bleiben, wie sie sind.
     *
     * @param list<?float> $e  kWh je Viertelstunde
     * @param list<?float> $c  ct/kWh je Viertelstunde
     * @param array<int,list<int>> $days
     * @param list<float>|null $room  Obergrenze je Viertelstunde (statt `cap`)
     * @return array{kwh:float,moved:float,delta:float} delta in ct (≥ 0)
     */
    public static function shiftDays(array $e, array $c, array $days, float $share, float $cap, string $dir, ?array $room = null): array
    {
        $kwh = 0.0; $moved = 0.0; $delta = 0.0;
        foreach ($days as $slots) {
            $now = 0.0; $fixed = 0.0; $free = 0.0; $cand = []; $orig = [];
            foreach ($slots as $i) {
                if (($c[$i] ?? null) === null) continue;
                $v = max(0.0, (float)($e[$i] ?? 0.0));
                $p = (float)$c[$i];
                $kwh += $v;
                $now += $v * $p;
                $keep = $v * (1 - $share);
                $fixed += $keep * $p;
                $free += $v * $share;
                $orig[$i] = $v * $share;
                $limit = ($room !== null ? (float)($room[$i] ?? 0.0) : $cap) - $keep;
                if ($limit > self::EPS) $cand[] = [$p, $i, $limit];
            }
            if ($free <= self::EPS) continue;
            usort($cand, fn($a, $b) => $dir === 'min' ? [$a[0], $a[1]] <=> [$b[0], $b[1]] : [$b[0], $a[1]] <=> [$a[0], $b[1]]);
            $rest = $free; $new = $fixed; $placed = [];
            foreach ($cand as [$p, $i, $limit]) {
                if ($rest <= self::EPS) break;
                $q = min($limit, $rest);
                $new += $q * $p; $rest -= $q; $placed[$i] = $q;
            }
            if ($rest > self::EPS) continue;            // passt nicht in den Tag: so lassen, wie es ist
            $d = $dir === 'min' ? $now - $new : $new - $now;
            if ($d <= 0) continue;
            $delta += $d;
            foreach ($orig as $i => $v) $moved += max(0.0, $v - ($placed[$i] ?? 0.0));
        }
        return ['kwh' => $kwh, 'moved' => $moved, 'delta' => $delta];
    }

    /**
     * Sonnenstrom statt Netzbezug: Verbrauch in Viertelstunden ohne Einspeisung
     * (höchstens der Netzbezug des Hauses dort) wandert innerhalb des Tages in
     * Viertelstunden mit Einspeisung — höchstens so viel, wie eingespeist wurde
     * und das Gerät dort zusätzlich aufnehmen kann. Die Einspeisung (`room`)
     * wird dabei verbraucht: Ein zweites Gerät findet nur, was übrig ist.
     *
     * @return array<int,float> verschobene kWh je Monat
     */
    public static function solarShift(array $e, array &$room, ?array $grid, array $days, array $month, float $share, float $cap): array
    {
        $out = [];
        foreach ($days as $slots) {
            $movable = 0.0; $targets = [];
            foreach ($slots as $i) {
                $v = max(0.0, (float)($e[$i] ?? 0.0));
                if ($room[$i] > self::EPS) {
                    $lim = min($room[$i], $cap - $v);
                    if ($lim > self::EPS) $targets[] = [$i, $lim];
                } else {
                    $movable += min($v * $share, $grid !== null ? max(0.0, (float)($grid[$i] ?? 0.0)) : $v * $share);
                }
            }
            if ($movable <= self::EPS || $targets === []) continue;
            usort($targets, fn($a, $b) => [$b[1], $a[0]] <=> [$a[1], $b[0]]);
            $rest = $movable; $q0 = 0.0;
            foreach ($targets as [$i, $lim]) {
                if ($rest <= self::EPS) break;
                $q = min($lim, $rest);
                $room[$i] -= $q; $rest -= $q; $q0 += $q;
            }
            $mo = $month[$slots[0]];
            $out[$mo] = ($out[$mo] ?? 0.0) + $q0;
        }
        return $out;
    }

    /**
     * Gerät ohne Zähler (Beispielwert) gegen den Börsenpreis: täglich `daily`
     * kWh in einem zusammenhängenden Fenster von `w` Viertelstunden — im
     * günstigsten Fenster des Tages gegen den Mittelwert aller Startzeiten.
     *
     * @return array{kwh:float,delta:float} delta in ct
     */
    public static function applianceSpot(array $c, array $days, float $daily, int $w): array
    {
        $kwh = 0.0; $delta = 0.0;
        foreach ($days as $slots) {
            $means = self::windowMeans(array_map(fn($i) => $c[$i] ?? null, $slots), $w);
            if ($means === []) continue;
            $kwh += $daily;
            $delta += $daily * (array_sum($means) / count($means) - min($means));
        }
        return ['kwh' => $kwh, 'delta' => $delta];
    }

    /**
     * Gerät ohne Zähler (Beispielwert) mit Sonnenstrom: Anteil des Gangs, den die
     * Einspeisung im sonnigsten Fenster deckt, gegen den Mittelwert aller
     * Startzeiten. Verbraucht die Einspeisung im gewählten Fenster.
     *
     * @return array<int,float> verschobene kWh je Monat
     */
    public static function applianceSolar(array &$room, array $days, array $month, float $daily, int $w): array
    {
        $out = [];
        $per = $daily / $w;
        foreach ($days as $slots) {
            $k = count($slots);
            if ($k < $w) continue;
            $cover = [];
            for ($s = 0; $s + $w <= $k; $s++) {
                $x = 0.0;
                for ($j = 0; $j < $w; $j++) $x += min($per, max(0.0, $room[$slots[$s + $j]]));
                $cover[$s] = $x;
            }
            $best = max($cover);
            $avg = array_sum($cover) / count($cover);
            $q = $best - $avg;
            if ($q <= self::EPS) continue;
            $s = (int)array_search($best, $cover, true);
            for ($j = 0; $j < $w; $j++) { $i = $slots[$s + $j]; $room[$i] = max(0.0, $room[$i] - min($per, $room[$i])); }
            $mo = $month[$slots[0]];
            $out[$mo] = ($out[$mo] ?? 0.0) + $q;
        }
        return $out;
    }

    /** Mittel jedes zusammenhängenden Fensters aus `w` Werten ohne Lücke. @return list<float> */
    private static function windowMeans(array $vals, int $w): array
    {
        $out = [];
        $k = count($vals);
        for ($s = 0; $s + $w <= $k; $s++) {
            $part = array_slice($vals, $s, $w);
            if (in_array(null, $part, true)) continue;
            $out[] = array_sum($part) / $w;
        }
        return $out;
    }

    /** € aus verschobenen kWh je Monat: (Arbeitspreis − Einspeisevergütung) des Monats. */
    private static function solarEur(array $movedByMonth, array $wp, array $feedCt): ?float
    {
        $eur = 0.0;
        foreach ($movedByMonth as $mo => $kwh) {
            if (!isset($wp[$mo])) continue;
            $eur += $kwh * max(0.0, $wp[$mo] - ($feedCt[$mo] ?? 0.0)) / 100;
        }
        return round($eur, 2);
    }

    // ── Verträge und Reihen ─────────────────────────────────────────────

    /**
     * v3.6.0 — Umsatzsteuer auf den Börsenpreis in Prozent: der Satz des
     * dynamischen Angebots (`dynamic.vat_pct`, so rechnet es auch Wechsel
     * prüfen), ohne Angebot der Regelsatz (MarketPriceService::DEFAULT_VAT_PCT).
     */
    private function vatPct(): float
    {
        foreach ($this->contracts->list('strom') as $c) {
            if (($c['price_model'] ?? null) === 'dynamic' && is_numeric($c['dynamic']['vat_pct'] ?? null)) return (float)$c['dynamic']['vat_pct'];
        }
        return MarketPriceService::DEFAULT_VAT_PCT;
    }

    /** Arbeitspreis des Hausanschlusses je Monat (Vertrag zur Monatsmitte, ct/kWh). @return array<int,float> */
    private function monthlyWorkingCt(array $houseIds, int $year): array
    {
        foreach ($houseIds as $id) {
            $list = array_values(array_filter($this->contracts->list('strom', $id), fn($c) => empty($c['is_shadow'])));
            $out = [];
            for ($mo = 1; $mo <= 12; $mo++) {
                $date = sprintf('%04d-%02d-15', $year, $mo);
                $c = $this->contracts->resolveForDate($list, $date)['contract'] ?? null;
                if ($c === null) continue;
                $v = $this->contracts->valueOnDate(ContractService::workingPricesFor($c, $id), 'ct_per_kwh', $date);
                if ($v !== null) $out[$mo] = $v;
            }
            if ($out !== []) return $out;
        }
        return [];
    }

    /** Einspeisevergütung je Monat (erster Einspeisezähler mit Vertrag, ct/kWh). @return array<int,float> */
    private function monthlyFeedCt(int $year): array
    {
        if (!Utilities::exists('pv_einspeisung')) return [];
        foreach ($this->meters->list('pv_einspeisung') as $m) {
            $list = array_values(array_filter($this->contracts->list('pv_einspeisung', (string)$m['id']), fn($c) => empty($c['is_shadow'])));
            $out = [];
            for ($mo = 1; $mo <= 12; $mo++) {
                $date = sprintf('%04d-%02d-15', $year, $mo);
                $c = $this->contracts->resolveForDate($list, $date)['contract'] ?? null;
                if ($c === null) continue;
                $v = $this->contracts->valueOnDate((array)($c['working_prices'] ?? []), 'ct_per_kwh', $date);
                if ($v !== null) $out[$mo] = $v;
            }
            if ($out !== []) return $out;
        }
        return [];
    }

    /** Summe der Viertelstunden aller Zähler einer Art, die `$keep` erfüllen (null ohne Reihe). @return list<?float>|null */
    private function sumSeries(string $utility, int $year, callable $keep, int $n): ?array
    {
        if (!Utilities::exists($utility)) return null;
        $out = null;
        foreach ($this->meters->list($utility) as $m) {
            if (!$keep($m)) continue;
            $e = $this->intervals->energy($utility, (string)$m['id'], $year);
            if ($e === null) continue;
            $out ??= array_fill(0, $n, null);
            foreach ($e as $i => $v) if ($v !== null && $i < $n) $out[$i] = ($out[$i] ?? 0.0) + $v;
        }
        return $out;
    }

    /**
     * Was fehlt, damit die Seite mehr zeigt:
     *   prices     Börsenpreise je Viertelstunde des Jahres
     *   intervals  Verbrauch je Viertelstunde (irgendein Stromzähler)
     *   devices    Wallbox oder Wärmepumpe mit eigenem Zähler und Viertelstunden
     *   pv         PV-Zähler vorhanden, aber Erzeugung oder Einspeisung ohne Viertelstunden
     *   tariff     weder ein dynamisches Angebot noch Zeitfenster für Modul 3
     *
     * @return list<string>
     */
    private function missing(bool $hasPrices, array $energy, array $byId, ?array $gen, ?array $feed, array $tou, int $year): array
    {
        $out = [];
        if (!$hasPrices) $out[] = 'prices';
        if ($energy === []) $out[] = 'intervals';
        $dev = array_filter(array_keys($energy), fn($id) => in_array(Utilities::roleOf('strom', $byId[$id]), FlexibilityService::CONTROLLABLE, true));
        if ($dev === []) $out[] = 'devices';
        $pvMeters = array_merge(Utilities::exists('pv_erzeugung') ? $this->meters->list('pv_erzeugung') : [],
            Utilities::exists('pv_einspeisung') ? $this->meters->list('pv_einspeisung') : []);
        if ($pvMeters !== [] && ($gen === null || $feed === null)) $out[] = 'pv';
        $dynamic = false;
        foreach ($this->contracts->list('strom') as $c) if (($c['price_model'] ?? null) === 'dynamic') $dynamic = true;
        if (!$dynamic && $tou === []) $out[] = 'tariff';
        return $out;
    }
}
