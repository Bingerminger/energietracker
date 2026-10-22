<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v3.3.0 (F1021) — Flexibilität bewerten: Was hat das Verschieben gebracht?
 *
 * Gesteuert wird außerhalb (evcc, Home Assistant, der Speicher selbst); hier
 * wird nachgerechnet, mit Verbrauch und Börsenpreis je Viertelstunde
 * (IntervalService). Gerechnet wird nur über Viertelstunden, für die beides
 * vorliegt; die Abdeckung steht dabei.
 *
 *   meters    je Stromzähler mit Viertelstunden und Monat: kWh, davon in
 *             günstigen Viertelstunden (Preis unter dem Monatsmittel) und bei
 *             negativem Preis; eigener Durchschnittspreis (nach Verbrauch
 *             gewichtet) gegen das Börsenmittel, Profilfaktor = eigener ÷
 *             Börsenmittel (unter 1: mehr in günstigen Zeiten), Vorteil =
 *             (Börsenmittel − eigener) × kWh — nur der Börsenanteil, ohne
 *             Steuern, Umlagen und Netzentgelte.
 *   battery   Speicher (Zähler mit Rolle Laden/Entladen): geladen zu welchem
 *             Börsenpreis, entladen zu welchem — der Wert der Verschiebung.
 *   module3   § 14a Modul 3: zeitvariables Netzentgelt aus den Zeitfenstern am
 *             Vertrag (`grid_tou`) — Ersparnis gegen den Standardtarif.
 *
 * weightedSpot() liefert den Dynamik-Check mit eigenem Lastgang: je Monat das
 * nach Verbrauch gewichtete Börsenmittel statt des einfachen Monatsmittels.
 */
final class FlexibilityService
{
    /** Stromrollen, deren Verbrauch sich verschieben lässt (Speicher: eigene Karte, Zähler bei PV-Erzeugung). */
    public const CONTROLLABLE = ['ev_charger', 'heat_pump'];
    /** Ab dieser Abdeckung (Viertelstunden mit Verbrauch und Preis) gilt ein Monat. */
    public const MIN_COVERAGE = 0.9;

    public function __construct(
        private IntervalService $intervals,
        private MeterService $meters,
        private ContractService $contracts,
    ) {}

    /** @return array<string,mixed> */
    public function analyze(int $year): array
    {
        $prices = $this->intervals->prices($year);
        $clock = $this->intervals->localClock($year);
        $n = $this->intervals->slots($year);
        $priceFilled = $prices === null ? 0 : count(array_filter($prices, fn($v) => $v !== null));
        $out = [
            'year' => $year,
            'prices' => ['available' => $prices !== null, 'coverage' => $n > 0 ? round($priceFilled / $n, 4) : 0.0, 'area' => IntervalService::AREA],
            'meters' => [], 'battery' => null, 'module3' => [],
        ];
        $meters = $this->meters->list('strom');
        $series = [];
        foreach ($meters as $m) {
            $e = $this->intervals->energy('strom', (string)$m['id'], $year);
            if ($e !== null) $series[(string)$m['id']] = $e;
        }
        $monthMean = $prices !== null ? $this->monthMeans($prices, $clock['month']) : [];

        foreach ($meters as $m) {
            $id = (string)$m['id'];
            if (!isset($series[$id])) continue;
            $role = Utilities::roleOf('strom', $m);
            $row = ['meter_id' => $id, 'name' => (string)($m['name'] ?? $id), 'role' => $role,
                    'controllable' => in_array($role, self::CONTROLLABLE, true),
                    'coverage' => round(count(array_filter($series[$id], fn($v) => $v !== null)) / max(1, $n), 4),
                    'months' => [], 'total' => null];
            if ($prices !== null) [$row['months'], $row['total']] = $this->shift($series[$id], $prices, $clock['month'], $monthMean);
            $out['meters'][] = $row;

            // § 14a Modul 3 — Zeitfenster am Vertrag dieses Zählers
            $m3 = $this->module3($id, $series[$id], $clock, $year);
            if ($m3 !== null) $out['module3'][] = ['meter_id' => $id, 'name' => $row['name']] + $m3;
        }

        // Speicher: Laden und Entladen als zwei Zähler bei PV-Erzeugung (Rollen wie F1015)
        $charge = $discharge = null;
        foreach (Utilities::exists('pv_erzeugung') ? $this->meters->list('pv_erzeugung') : [] as $m) {
            $role = Utilities::roleOf('pv_erzeugung', $m);
            $e = $this->intervals->energy('pv_erzeugung', (string)$m['id'], $year);
            if ($e === null) continue;
            if ($role === 'battery_charge') $charge = $e;
            if ($role === 'battery_discharge') $discharge = $e;
        }
        if ($prices !== null && $charge !== null && $discharge !== null) {
            $out['battery'] = $this->battery($charge, $discharge, $prices, $clock['month']);
        }
        return $out;
    }

    /**
     * Börsenmittel je Monat, nach eigenem Verbrauch gewichtet, für Monate mit
     * ausreichender Abdeckung (Dynamik-Check mit echtem Lastgang).
     *
     * @return array<string,float> JJJJ-MM → ct/kWh (ohne Steuern)
     */
    public function weightedSpot(string $utility, string $meterId, string $fromYm, string $toYm): array
    {
        $out = [];
        for ($y = (int)substr($fromYm, 0, 4); $y <= (int)substr($toYm, 0, 4); $y++) {
            $e = $this->intervals->energy($utility, $meterId, $y);
            $p = $this->intervals->prices($y);
            if ($e === null || $p === null) continue;
            $month = $this->intervals->localClock($y)['month'];
            $slots = array_count_values($month);
            $acc = [];
            foreach ($month as $i => $mo) {
                if ($e[$i] === null || $p[$i] === null) continue;
                $acc[$mo]['e'] = ($acc[$mo]['e'] ?? 0.0) + $e[$i];
                $acc[$mo]['ep'] = ($acc[$mo]['ep'] ?? 0.0) + $e[$i] * $p[$i];
                $acc[$mo]['n'] = ($acc[$mo]['n'] ?? 0) + 1;
            }
            foreach ($acc as $mo => $a) {
                $ym = sprintf('%04d-%02d', $y, $mo);
                if ($ym < $fromYm || $ym > $toYm || $a['e'] <= 0) continue;
                if ($a['n'] / $slots[$mo] < self::MIN_COVERAGE) continue;
                $out[$ym] = round($a['ep'] / $a['e'], 4);
            }
        }
        return $out;
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** @return array<int,float> Monat → einfaches Börsenmittel */
    private function monthMeans(array $prices, array $month): array
    {
        $sum = []; $cnt = [];
        foreach ($month as $i => $mo) {
            if ($prices[$i] === null) continue;
            $sum[$mo] = ($sum[$mo] ?? 0.0) + $prices[$i];
            $cnt[$mo] = ($cnt[$mo] ?? 0) + 1;
        }
        $out = [];
        foreach ($sum as $mo => $s) $out[$mo] = $s / $cnt[$mo];
        return $out;
    }

    /** @return array{0: list<array<string,mixed>>, 1: ?array<string,mixed>} */
    private function shift(array $e, array $p, array $month, array $mean): array
    {
        $acc = [];
        $slots = array_count_values($month);
        foreach ($month as $i => $mo) {
            if ($e[$i] === null) continue;
            $a = &$acc[$mo];
            $a['kwh_all'] = ($a['kwh_all'] ?? 0.0) + $e[$i];
            $a['n_energy'] = ($a['n_energy'] ?? 0) + 1;
            if ($p[$i] === null) { unset($a); continue; }
            $a['kwh'] = ($a['kwh'] ?? 0.0) + $e[$i];
            $a['ep'] = ($a['ep'] ?? 0.0) + $e[$i] * $p[$i];
            $a['n'] = ($a['n'] ?? 0) + 1;
            if (isset($mean[$mo]) && $p[$i] < $mean[$mo]) $a['cheap'] = ($a['cheap'] ?? 0.0) + $e[$i];
            if ($p[$i] < 0) $a['neg'] = ($a['neg'] ?? 0.0) + $e[$i];
            unset($a);
        }
        $rows = []; $t = ['kwh' => 0.0, 'ep' => 0.0, 'cheap' => 0.0, 'neg' => 0.0, 'market' => 0.0];
        ksort($acc);
        foreach ($acc as $mo => $a) {
            $kwh = (float)($a['kwh'] ?? 0.0);
            $own = $kwh > 0 ? $a['ep'] / $kwh : null;
            $market = $mean[$mo] ?? null;
            $rows[] = [
                'month' => $mo,
                'kwh' => round((float)$a['kwh_all'], 2),
                'coverage' => round(($a['n'] ?? 0) / max(1, $slots[$mo]), 4),
                'priced_kwh' => round($kwh, 2),
                'cheap_kwh' => round((float)($a['cheap'] ?? 0.0), 2),
                'negative_kwh' => round((float)($a['neg'] ?? 0.0), 2),
                'own_ct' => $own !== null ? round($own, 3) : null,
                'market_ct' => $market !== null ? round($market, 3) : null,
                'profile_factor' => $own !== null && $market !== null && abs($market) > 0.01 ? round($own / $market, 3) : null,
                'advantage_eur' => $own !== null && $market !== null ? round(($market - $own) * $kwh / 100, 2) : null,
            ];
            if ($own !== null && $market !== null) {
                $t['kwh'] += $kwh; $t['ep'] += $a['ep']; $t['cheap'] += (float)($a['cheap'] ?? 0.0);
                $t['neg'] += (float)($a['neg'] ?? 0.0); $t['market'] += $market * $kwh;
            }
        }
        $total = $t['kwh'] > 0 ? [
            'priced_kwh' => round($t['kwh'], 2),
            'cheap_kwh' => round($t['cheap'], 2),
            'cheap_share' => round($t['cheap'] / $t['kwh'], 4),
            'negative_kwh' => round($t['neg'], 2),
            'own_ct' => round($t['ep'] / $t['kwh'], 3),
            'market_ct' => round($t['market'] / $t['kwh'], 3),
            'profile_factor' => abs($t['market']) > 0.01 ? round($t['ep'] / $t['market'], 3) : null,
            'advantage_eur' => round(($t['market'] - $t['ep']) / 100, 2),
        ] : null;
        return [$rows, $total];
    }

    /** @return array<string,mixed> */
    private function battery(array $charge, array $discharge, array $p, array $month): array
    {
        $acc = []; $t = ['in' => 0.0, 'out' => 0.0, 'cin' => 0.0, 'cout' => 0.0];
        foreach ($month as $i => $mo) {
            if ($p[$i] === null) continue;
            if ($charge[$i] !== null) { $acc[$mo]['in'] = ($acc[$mo]['in'] ?? 0.0) + $charge[$i]; $acc[$mo]['cin'] = ($acc[$mo]['cin'] ?? 0.0) + $charge[$i] * $p[$i]; }
            if ($discharge[$i] !== null) { $acc[$mo]['out'] = ($acc[$mo]['out'] ?? 0.0) + $discharge[$i]; $acc[$mo]['cout'] = ($acc[$mo]['cout'] ?? 0.0) + $discharge[$i] * $p[$i]; }
        }
        ksort($acc);
        $rows = [];
        foreach ($acc as $mo => $a) {
            foreach (['in', 'out', 'cin', 'cout'] as $k) { $a[$k] = (float)($a[$k] ?? 0.0); $t[$k] += $a[$k]; }
            $rows[] = $this->batteryRow($a) + ['month' => $mo];
        }
        return ['months' => $rows, 'total' => $this->batteryRow($t)];
    }

    /** @param array{in:float,out:float,cin:float,cout:float} $a */
    private function batteryRow(array $a): array
    {
        return [
            'charged_kwh' => round($a['in'], 2),
            'discharged_kwh' => round($a['out'], 2),
            'charge_ct' => $a['in'] > 0 ? round($a['cin'] / $a['in'], 3) : null,
            'discharge_ct' => $a['out'] > 0 ? round($a['cout'] / $a['out'], 3) : null,
            // Wert der Verschiebung zum Börsenpreis: entladen minus geladen
            'value_eur' => round(($a['cout'] - $a['cin']) / 100, 2),
        ];
    }

    /**
     * § 14a Modul 3: Ersparnis gegen den Standardtarif des Netzentgelts aus
     * den Zeitfenstern des Vertrags, der am Monatsersten gilt.
     *
     * @return array<string,mixed>|null
     */
    private function module3(string $meterId, array $e, array $clock, int $year): ?array
    {
        $byMonth = $this->gridTouByMonth($meterId, $year);
        if ($byMonth === []) return null;
        $acc = [];
        foreach ($clock['month'] as $i => $mo) {
            if ($e[$i] === null || !isset($byMonth[$mo])) continue;
            $tou = $byMonth[$mo];
            $std = (float)$tou['standard_ct'];
            $ct = self::touCt($tou, $mo, $clock['minute'][$i]);
            $band = $ct < $std ? 'low' : ($ct > $std ? 'high' : 'standard');
            $acc[$mo][$band] = ($acc[$mo][$band] ?? 0.0) + $e[$i];
            $acc[$mo]['saving'] = ($acc[$mo]['saving'] ?? 0.0) + $e[$i] * ($std - $ct);
        }
        ksort($acc);
        $rows = []; $t = ['low' => 0.0, 'high' => 0.0, 'standard' => 0.0, 'saving' => 0.0];
        foreach ($acc as $mo => $a) {
            $row = ['month' => $mo];
            foreach ($t as $k => $_) { $v = (float)($a[$k] ?? 0.0); $t[$k] += $v; $row[$k === 'saving' ? 'saving_eur' : $k . '_kwh'] = round($k === 'saving' ? $v / 100 : $v, 2); }
            $rows[] = $row;
        }
        // v3.7.0 — additiv: am Vertrag netto eingetragen und mit welcher USt auf brutto gerechnet
        $net = array_values(array_filter($byMonth, fn($x) => !empty($x['net'])));
        return ['months' => $rows, 'total' => [
            'low_kwh' => round($t['low'], 2), 'high_kwh' => round($t['high'], 2), 'standard_kwh' => round($t['standard'], 2),
            'saving_eur' => round($t['saving'] / 100, 2),
        ], 'prices_net' => $net !== [], 'vat_pct' => $net !== [] ? (float)$net[0]['vat_pct'] : null];
    }

    /**
     * Zeitfenster für Modul 3 je Monat: `grid_tou` des echten Vertrags, der am
     * Monatsersten gilt (Schattenverträge zählen nicht). v3.6.0 — öffentlich,
     * damit Ampel und Hebel (FlexibilityInsightService) dieselben Fenster lesen.
     *
     * @return array<int,array<string,mixed>> Monat (1–12) → grid_tou
     */
    public function gridTouByMonth(string $meterId, int $year): array
    {
        $list = array_values(array_filter($this->contracts->list('strom', $meterId), fn($c) => empty($c['is_shadow'])));
        if ($list === []) return [];
        $byMonth = [];
        $vat = null;
        for ($mo = 1; $mo <= 12; $mo++) {
            $c = $this->contracts->resolveForDate($list, sprintf('%04d-%02d-01', $year, $mo))['contract'] ?? null;
            if (!is_array($c['grid_tou'] ?? null)) continue;
            $tou = $c['grid_tou'];
            // v3.7.0 — am Vertrag netto eingetragen (`net`): mit dem Umsatzsteuersatz
            // der App (MarketPriceService::vatPctOf, wie die Börsen-Hebel) auf brutto;
            // sonst wie eingetragen (brutto, wie auf der Rechnung)
            if (!empty($tou['net'])) {
                $vat ??= MarketPriceService::vatPctOf($this->contracts->list('strom'));
                $tou = self::grossTou($tou, $vat);
            }
            $byMonth[$mo] = $tou;
        }
        return $byMonth;
    }

    /**
     * v3.7.0 — Zeitfenster für Modul 3 von netto auf brutto: Standardtarif und
     * jedes Fenster × (1 + USt/100); `vat_pct` hält fest, womit gerechnet wurde.
     *
     * @param array<string,mixed> $tou
     * @return array<string,mixed>
     */
    public static function grossTou(array $tou, float $vatPct): array
    {
        $f = 1 + $vatPct / 100;
        $tou['standard_ct'] = round((float)$tou['standard_ct'] * $f, 4);
        foreach ((array)($tou['windows'] ?? []) as $i => $w) $tou['windows'][$i]['ct'] = round((float)$w['ct'] * $f, 4);
        $tou['vat_pct'] = $vatPct;
        return $tou;
    }

    /** Netzentgelt (ct/kWh) zu einer Minute des Tages in einem Monat laut Zeitfenstern. */
    public static function touCt(array $tou, int $month, int $minute): float
    {
        $q = intdiv($month - 1, 3) + 1;
        foreach ((array)($tou['windows'] ?? []) as $w) {
            if (!in_array($q, (array)($w['quarters'] ?? []), true)) continue;
            $from = self::minutes((string)$w['from']); $to = self::minutes((string)$w['to']);
            if ($minute >= $from && $minute < $to) return (float)$w['ct'];
        }
        return (float)$tou['standard_ct'];
    }

    public static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);
        return $h * 60 + $m;
    }
}
