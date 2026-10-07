<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * F1005 — PV-Eigenverbrauch und Autarkiequote.
 *
 * Definitionen (Branchenstandard):
 *   eigenverbrauch_kwh   = pv_erzeugung − pv_einspeisung
 *                          (was die Anlage produzierte UND ich selbst verbrauchte)
 *   eigenverbrauchsquote = eigenverbrauch_kwh / pv_erzeugung_kwh
 *                          (Anteil der Produktion, der nicht ins Netz ging)
 *   autarkiequote        = eigenverbrauch_kwh / (eigenverbrauch_kwh + bezug_kwh)
 *                          (Anteil meines Stroms, der aus eigener PV kam)
 *
 * Eigenverbrauchs- und Autarkiequote sind nur sinnvoll definiert, wenn
 * sowohl pv_erzeugung als auch pv_einspeisung Daten haben. Ohne
 * Erzeugungszähler kann die App den Eigenverbrauch nicht berechnen —
 * in diesem Fall liefert der Service die Aggregate, die er hat, und
 * setzt die abgeleiteten Quoten auf null.
 */
final class PvSummaryService
{
    public function __construct(
        private ConsumptionService $consumption,
        private ?SettingsService $settings = null,   // v3.1.0 (H7)
    ) {}

    /**
     * @return array{
     *   monthly: array<int,array<string,mixed>>,
     *   yearly:  array<int,array<string,mixed>>,
     *   has_generation_meter: bool
     * }
     */
    public function compute(): array
    {
        $strom      = $this->consumption->forUtility('strom');
        $einspeisung = $this->consumption->forUtility('pv_einspeisung');
        $erzeugung  = $this->consumption->forUtility('pv_erzeugung');

        $hasErz = !empty($erzeugung['meters'] ?? []);

        // v2.10.0 (Review CALC-18) — welche Zähler haben in welchem Monat Daten
        $seen = [];
        $byYm = [];
        $collect = function (array $util, string $field) use (&$byYm, &$seen): void {
            foreach ($util['monthly_total'] ?? [] as $m) {
                $ym = (string)($m['ym'] ?? '');
                if ($ym === '') continue;
                if (!isset($byYm[$ym])) $byYm[$ym] = $this->emptyRow($ym);
                $byYm[$ym][$field] = (float)($m['kwh'] ?? 0);
                if ((int)($m['days'] ?? 0) > 0) $seen[$ym][$field] = true;
                if ($field === 'einspeisung_kwh') $byYm[$ym]['_revenue'] = (float)($m['cost'] ?? 0);
            }
        };
        $collect($strom,       'bezug_kwh');
        $collect($einspeisung, 'einspeisung_kwh');
        $collect($erzeugung,   'erzeugung_kwh');
        $prices = $this->bezugPrices($strom);
        // v3.1.0 (H7, CALC-29) — Balkonkraftwerk ohne Einspeisezähler: Eigenverbrauch
        // nach der Einstellung pv_assumed_self_consumption_pct (leer = wie bisher)
        $genMeters = array_map(fn($pm) => $pm['meter'] ?? [], $erzeugung['meters'] ?? []);
        $pct = $this->settings?->get('pv_assumed_self_consumption_pct');
        $plugInPct = is_numeric($pct) && array_filter($genMeters, fn($m) => !empty($m['plug_in'])) && empty($einspeisung['meters'] ?? [])
            ? (float)$pct : null;

        foreach ($byYm as $ym => &$row) {
            // Quoten nur, wo alle drei Zähler Daten haben: Ein Erzeugungszähler
            // ab Juli ergab bis v2.9 für das Jahr „Eigenverbrauch 0, Autarkie 0"
            $assumed = $plugInPct !== null && !empty($seen[$ym]['erzeugung_kwh']) && !empty($seen[$ym]['bezug_kwh']);
            $row['covered'] = $assumed || count($seen[$ym] ?? []) === 3;
            $this->enrichRow($row);
            if ($assumed) {
                $eigen = round($row['erzeugung_kwh'] * $plugInPct / 100, 1);
                $row['eigenverbrauch_kwh'] = $eigen;
                $row['eigenverbrauchsquote'] = $row['erzeugung_kwh'] > 0.1 ? round($plugInPct / 100, 4) : null;
                $row['autarkiequote'] = ($eigen + $row['bezug_kwh']) > 0.1 ? round($eigen / ($eigen + $row['bezug_kwh']), 4) : null;
                $row['self_consumption_assumed'] = true;
            }
            if (!$row['covered']) {
                $row['eigenverbrauch_kwh'] = null;
                $row['eigenverbrauchsquote'] = null;
                $row['autarkiequote'] = null;
            }
            $this->addValue($row, $prices[$ym] ?? null);
        }
        unset($row);
        ksort($byYm);
        $monthly = array_values($byYm);

        // Jährliche Aggregation — Quoten auf Jahresbasis sind die
        // belastbare Größe (Monatsquoten schwanken stark mit den
        // Jahreszeiten, Jahresquote ist die PV-Performance-KPI). Seit v2.10.0
        // nur über die Monate, in denen alle drei Zähler Daten haben.
        $yearly = [];
        foreach ($monthly as $row) {
            $yr = (int)$row['year'];
            if (!isset($yearly[$yr])) {
                $yearly[$yr] = [
                    'year' => $yr,
                    'bezug_kwh' => 0.0, 'einspeisung_kwh' => 0.0, 'erzeugung_kwh' => 0.0,
                    'months_with_data' => 0, 'months_covered' => 0,
                    '_ev' => 0.0, '_erz' => 0.0, '_bez' => 0.0,
                    'savings_eur' => null, 'feed_in_revenue_eur' => 0.0,
                ];
            }
            $y = &$yearly[$yr];
            $y['bezug_kwh']        += $row['bezug_kwh'];
            $y['einspeisung_kwh']  += $row['einspeisung_kwh'];
            $y['erzeugung_kwh']    += $row['erzeugung_kwh'];
            $y['months_with_data']++;
            $y['feed_in_revenue_eur'] += (float)$row['feed_in_revenue_eur'];
            if ($row['covered']) {
                $y['months_covered']++;
                $y['_ev']  += (float)$row['eigenverbrauch_kwh'];
                $y['_erz'] += $row['erzeugung_kwh'];
                $y['_bez'] += $row['bezug_kwh'];
                if ($row['savings_eur'] !== null) $y['savings_eur'] = ($y['savings_eur'] ?? 0.0) + $row['savings_eur'];
            }
            unset($y);
        }
        $battery = $this->batteryByYear($erzeugung);
        foreach ($yearly as &$y) {
            $y['battery'] = $battery[$y['year']] ?? null;   // v3.1.0 (H7) — Speicher, nur mit Speicherrollen
            $covered = $y['months_covered'] > 0;
            $y['eigenverbrauch_kwh']   = $covered ? round($y['_ev'], 1) : null;
            $y['eigenverbrauchsquote'] = $covered && $y['_erz'] > 0.1 ? round($y['_ev'] / $y['_erz'], 4) : null;
            $y['autarkiequote']        = $covered && ($y['_ev'] + $y['_bez']) > 0.1 ? round($y['_ev'] / ($y['_ev'] + $y['_bez']), 4) : null;
            foreach (['bezug_kwh', 'einspeisung_kwh', 'erzeugung_kwh'] as $k) $y[$k] = round($y[$k], 1);
            $y['feed_in_revenue_eur'] = round($y['feed_in_revenue_eur'], 2);
            $y['savings_eur'] = $y['savings_eur'] !== null ? round($y['savings_eur'], 2) : null;
            $y['pv_benefit_eur'] = $y['savings_eur'] !== null ? round($y['savings_eur'] + $y['feed_in_revenue_eur'], 2) : null;
            unset($y['_ev'], $y['_erz'], $y['_bez']);
        }
        unset($y);
        ksort($yearly);

        $payback = $this->payback($genMeters, $monthly);
        $since = $payback['commissioned_on'] ?? null;
        foreach ($genMeters as $m) if (!empty($m['commissioned_on']) && ($since === null || $m['commissioned_on'] < $since)) $since = $m['commissioned_on'];
        return [
            'monthly'              => $monthly,
            'yearly'               => array_values($yearly),
            'has_generation_meter' => $hasErz,
            // v3.1.0 (H7, CALC-29) — additiv
            'payback'              => $payback,
            'self_consumption_assumed_pct' => $plugInPct,
            'hints'                => $since !== null && $since >= '2025-02-25'
                && (string)($this->settings?->get('country', 'DE') ?? 'DE') === 'DE' ? ['negative_prices'] : [],
        ];
    }

    /**
     * v3.1.0 (H7, CALC-29) — Speicher je Jahr aus den Zählern mit den Rollen
     * battery_charge und battery_discharge: geladen, entladen, Verluste,
     * Wirkungsgrad, Vollzyklen (nur mit battery_capacity_kwh am Ladezähler).
     * Die Quoten für Eigenverbrauch und Autarkie ändern sich dadurch nicht.
     *
     * @return array<int,array<string,mixed>>
     */
    private function batteryByYear(array $erzeugung): array
    {
        $acc = []; $capacity = 0.0;
        foreach ($erzeugung['meters'] ?? [] as $pm) {
            $role = \Energietracker\Config\Utilities::roleOf('pv_erzeugung', $pm['meter'] ?? []);
            if (!in_array($role, ['battery_charge', 'battery_discharge'], true)) continue;
            if ($role === 'battery_charge') $capacity += (float)($pm['meter']['battery_capacity_kwh'] ?? 0);
            foreach ($pm['monthly'] ?? [] as $m) {
                $yr = (int)($m['year'] ?? 0);
                $acc[$yr][$role] = ($acc[$yr][$role] ?? 0.0) + (float)($m['kwh'] ?? 0);
            }
        }
        $out = [];
        foreach ($acc as $yr => $v) {
            $in = (float)($v['battery_charge'] ?? 0); $outKwh = (float)($v['battery_discharge'] ?? 0);
            $out[$yr] = [
                'charged_kwh'    => round($in, 1),
                'discharged_kwh' => round($outKwh, 1),
                'losses_kwh'     => $in > 0 && $outKwh > 0 ? round($in - $outKwh, 1) : null,
                'efficiency_pct' => $in > 0 && $outKwh > 0 ? round($outKwh / $in * 100, 1) : null,
                'full_cycles'    => $capacity > 0 ? round($in / $capacity, 1) : null,
            ];
        }
        return $out;
    }

    /**
     * v3.1.0 (H7, CALC-29) — Amortisation, wenn am Erzeugungszähler eine
     * Investition steht: Nutzen = vermiedener Bezug + Einspeiseerlös seit der
     * Inbetriebnahme; ist die Investition noch nicht zurück, die Hochrechnung mit
     * dem Nutzen der letzten zwölf Monate. Ohne Investition null.
     *
     * @return array<string,mixed>|null
     */
    private function payback(array $genMeters, array $monthly): ?array
    {
        $inv = 0.0; $since = null;
        foreach ($genMeters as $m) {
            if (!isset($m['investment_eur']) || !is_numeric($m['investment_eur'])) continue;
            $inv += (float)$m['investment_eur'];
            $c = (string)($m['commissioned_on'] ?? '');
            if ($c !== '' && ($since === null || $c < $since)) $since = $c;
        }
        if ($inv <= 0) return null;
        $sinceYm = $since !== null ? substr($since, 0, 7) : null;
        $cum = 0.0; $series = []; $breakEven = null;
        foreach ($monthly as $row) {
            if ($sinceYm !== null && $row['ym'] < $sinceYm) continue;
            $benefit = (float)($row['savings_eur'] ?? 0) + (float)($row['feed_in_revenue_eur'] ?? 0);
            $cum += $benefit; $series[] = $benefit;
            if ($breakEven === null && $cum >= $inv) $breakEven = (string)$row['ym'];
        }
        $last = array_slice($series, -12);
        $perYear = $last !== [] ? array_sum($last) * 12 / count($last) : null;
        $projected = false;
        if ($breakEven === null && $perYear !== null && $perYear > 0 && $monthly !== []) {
            $left = (int)ceil(($inv - $cum) / ($perYear / 12));
            $breakEven = date('Y-m', (int)strtotime(end($monthly)['ym'] . "-01 +$left months"));
            $projected = true;
        }
        return [
            'investment_eur'      => round($inv, 2),
            'commissioned_on'     => $since,
            'benefit_to_date_eur' => round($cum, 2),
            'avg_benefit_12m_eur' => $perYear !== null ? round($perYear, 2) : null,
            'years_to_break_even' => $perYear !== null && $perYear > 0 ? round($inv / $perYear, 1) : null,
            'break_even_ym'       => $breakEven,
            'break_even_projected' => $projected,
        ];
    }

    /**
     * v2.10.0 (CALC-18) — Bezugsarbeitspreis je Monat (ct/kWh), mengengewichtet
     * über die Stromzähler, die in Summen zählen. Grundpreis gehört nicht dazu:
     * Eigenverbrauch spart nur den Arbeitspreis.
     *
     * @return array<string,float>
     */
    private function bezugPrices(array $strom): array
    {
        $acc = [];
        foreach ($strom['meters'] ?? [] as $pm) {
            if (!MeterService::countsInTotals($pm['meter'] ?? [])) continue;
            foreach ($pm['monthly'] ?? [] as $m) {
                $wp = $m['working_price_ct'] ?? null;
                if ($wp === null) continue;
                $k = max(0.0, (float)($m['kwh'] ?? 0));
                $ym = (string)$m['ym'];
                $acc[$ym][0] = ($acc[$ym][0] ?? 0.0) + (float)$wp * ($k > 0 ? $k : 1.0);
                $acc[$ym][1] = ($acc[$ym][1] ?? 0.0) + ($k > 0 ? $k : 1.0);
            }
        }
        return array_map(fn($a) => $a[0] / $a[1], $acc);
    }

    /** Wert der PV im Monat: vermiedener Bezug (Eigenverbrauch × Arbeitspreis) und Einspeiseerlös. */
    private function addValue(array &$row, ?float $priceCt): void
    {
        $row['feed_in_revenue_eur'] = round((float)($row['_revenue'] ?? 0.0), 2);
        unset($row['_revenue']);
        $row['bezug_price_ct'] = $priceCt !== null ? round($priceCt, 4) : null;
        $row['savings_eur'] = $row['covered'] && $priceCt !== null && $row['eigenverbrauch_kwh'] !== null
            ? round($row['eigenverbrauch_kwh'] * $priceCt / 100.0, 2) : null;
    }

    /**
     * Setzt die abgeleiteten Felder eigenverbrauch_kwh, eigenverbrauchsquote
     * und autarkiequote. Quoten sind null, wenn der Nenner nicht ≥ 0.1 kWh
     * ist (vermeidet sinnlose 0/0-Anzeigen in Wintermonaten ohne Erzeugung).
     */
    private function enrichRow(array &$row): void
    {
        $erz  = (float)$row['erzeugung_kwh'];
        $eins = (float)$row['einspeisung_kwh'];
        $bez  = (float)$row['bezug_kwh'];

        // Eigenverbrauch kann nie negativ sein (Erzeugungszähler ≥
        // Einspeisezähler). Bei Datenfehlern auf 0 klemmen.
        $eigen = max(0.0, $erz - $eins);
        $row['eigenverbrauch_kwh'] = round($eigen, 1);

        $row['eigenverbrauchsquote'] = $erz > 0.1
            ? round($eigen / $erz, 4)
            : null;
        $row['autarkiequote'] = ($eigen + $bez) > 0.1
            ? round($eigen / ($eigen + $bez), 4)
            : null;

        // Anzeige-Rundung der Roh-kWh-Werte (mehrere Aufrufer schreiben rein).
        $row['bezug_kwh']        = round($bez,  1);
        $row['einspeisung_kwh']  = round($eins, 1);
        $row['erzeugung_kwh']    = round($erz,  1);
    }

    /** @return array{ym:string,year:int,month:int,bezug_kwh:float,einspeisung_kwh:float,erzeugung_kwh:float} */
    private function emptyRow(string $ym): array
    {
        [$yr, $mn] = array_map('intval', explode('-', $ym));
        return [
            'ym' => $ym, 'year' => $yr, 'month' => $mn,
            'bezug_kwh' => 0.0, 'einspeisung_kwh' => 0.0, 'erzeugung_kwh' => 0.0,
        ];
    }
}
