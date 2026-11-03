<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v3.10.0 (GitHub #40) — Einspeisung in der Kaskade: Sonnenstrom, den jeder
 * Strang selbst verbraucht, aus den Einspeise-Zählwerken (2.8.0) der Zähler.
 *
 *   Netz ─ Z1 ─┬─ Strang vorn (der Rest von Z1, etwa die Wärmepumpe)
 *              └─ Z2 ─┬─ Strang dahinter (etwa Haushalt mit Wallbox)
 *                     └─ Z3 ─ PV
 *
 * Z3 zählt rückwärts den ganzen Sonnenstrom (die Erzeugung), Z2 was der Strang
 * dahinter davon übrig lässt, Z1 was nach dem Strang vorn ins Netz geht. Daraus:
 *
 *   selbst genutzt dahinter = Erzeugung (Z3)       − Einspeisung an Z2
 *   selbst genutzt vorn     = Einspeisung an Z2    − Einspeisung an Z1 (Netz)
 *   zusammen                = Erzeugung            − Einspeisung an Z1 (wie bisher)
 *
 * Das Einspeise-Zählwerk von Z2 ist ein Zähler der PV-Einspeisung mit
 * `cascade_meter_id` (ein Zählwerk von Z2, ein Stromzähler hinter einem
 * anderen). Es zählt nicht in Summen (MeterService::countsInTotals): Ins Netz
 * geht nur, was Z1 zählt. Rolle und Name eines Strangs kommen vom Stromzähler:
 * dahinter die Rolle von Z2, vorn die seines Elternzählers.
 *
 * Reine Rechnung auf den Ergebnissen von ConsumptionService::forUtility, ohne
 * Speicherzugriff. Ein Monat zählt nur, wenn alle drei Reihen ihn über
 * denselben Zeitraum erfassen (PvSummaryService::SAME_SPAN_SHARE). Ein Strang
 * unter null wird nicht negativ gerechnet, sondern auf null begrenzt
 * (`clamped`). v3.10.0 (Skeptiker M5): Ein Widerspruch (`inconsistent`, „bitte
 * die Ablesungen prüfen“) ist das nur, wenn alle drei Reihen den Monat messen.
 * Ist eine verteilt (Lücke, Erzeugung nur vierteljährlich abgelesen), ist die
 * Aufteilung auf die Monate geschätzt (`interpolated`), kein Ablesefehler.
 */
final class CascadeFeed
{
    /**
     * @param array<string,mixed> $strom forUtility('strom')
     * @param array<string,mixed> $feed  forUtility('pv_einspeisung')
     * @param array<string,mixed> $gen   forUtility('pv_erzeugung')
     * @return array{behind:array<string,mixed>,front:array<string,mixed>,feed_meter_ids:list<string>,monthly:array<string,array<string,mixed>>}|null
     */
    public static function compute(array $strom, array $feed, array $gen): ?array
    {
        $byId = [];
        foreach ((array)($strom['meters'] ?? []) as $pm) {
            $m = (array)($pm['meter'] ?? []);
            if (isset($m['id'])) $byId[(string)$m['id']] = $m;
        }
        // Einspeise-Zählwerke hinter dem Netzanschluss, deren Stromzähler bekannt ist und hinter einem anderen hängt
        $links = []; $series = [];
        foreach ((array)($feed['meters'] ?? []) as $pm) {
            $m = (array)($pm['meter'] ?? []);
            $link = (string)($m['cascade_meter_id'] ?? '');
            if ($link === '' || empty($byId[$link]['parent_meter_id']) || empty($byId[(string)$byId[$link]['parent_meter_id']])) continue;
            $links[] = $link;
            $series[(string)$m['id']] = (array)($pm['monthly'] ?? []);
        }
        if ($links === []) return null;
        // die Stelle: das erste Zählwerk und die übrigen Zählwerke desselben Zählers (Gruppe, HT/NT)
        $pos = $byId[$links[0]];
        $gid = (string)($pos['meter_group_id'] ?? '');
        $behindIds = $gid === '' ? [(string)$pos['id']]
            : array_values(array_map(fn($m) => (string)$m['id'], array_filter($byId, fn($m) => (string)($m['meter_group_id'] ?? '') === $gid && !empty($m['parent_meter_id']))));
        $frontIds = array_values(array_unique(array_filter(array_map(fn($id) => (string)($byId[$id]['parent_meter_id'] ?? ''), $behindIds), fn($id) => isset($byId[$id]))));
        $front = $byId[$frontIds[0]];
        $groups = [];
        foreach ((array)($strom['meter_groups'] ?? []) as $g) if (isset($g['id'])) $groups[(string)$g['id']] = (string)($g['name'] ?? '');
        $nameOf = fn(array $m) => ($groups[(string)($m['meter_group_id'] ?? '')] ?? '') !== '' ? $groups[(string)$m['meter_group_id']] : (string)($m['name'] ?? $m['id']);

        // Reihen je Monat: Erzeugung (Summe), Einspeisung am Netz (Summe), Einspeisung an Z2 (die Zählwerke)
        $rows = [];
        $take = function (string $key, array $list) use (&$rows): void {
            foreach ($list as $r) {
                $ym = (string)($r['ym'] ?? '');
                if ($ym === '') continue;
                $rows[$ym][$key]['kwh'] = ($rows[$ym][$key]['kwh'] ?? 0.0) + (float)($r['kwh'] ?? 0);
                $rows[$ym][$key]['days'] = max((int)($rows[$ym][$key]['days'] ?? 0), (int)($r['days'] ?? 0));
                if (!empty($r['interpolated'])) $rows[$ym][$key]['interpolated'] = true;
            }
        };
        $take('gen', (array)($gen['monthly_total'] ?? []));
        $take('grid', (array)($feed['monthly_total'] ?? []));
        foreach ($series as $list) $take('cascade', $list);
        ksort($rows);

        $monthly = [];
        foreach ($rows as $ym => $r) {
            $days = [];
            foreach (['gen', 'grid', 'cascade'] as $k) if ((int)($r[$k]['days'] ?? 0) > 0) $days[$k] = (int)$r[$k]['days'];
            $covered = count($days) === 3 && PvSummaryService::partialSeries($days) === [];
            if (!$covered) { $monthly[$ym] = ['covered' => false]; continue; }
            $g = (float)$r['gen']['kwh']; $z2 = (float)$r['cascade']['kwh']; $z1 = (float)$r['grid']['kwh'];
            $behind = $g - $z2;
            $frontKwh = $z2 - $z1;
            // v3.10.0 (Skeptiker M5) — je Strang: verteilt, wenn eine seiner zwei Reihen verteilt ist
            // (dahinter Erzeugung und Z2, vorn Z2 und Z1); unter null begrenzt; ein Widerspruch nur
            // gemessen. Verteilt ist die Aufteilung auf die Monate geschätzt, kein Ablesefehler.
            $iv = fn(string $k) => !empty($r[$k]['interpolated']);
            $bInterp = $iv('gen') || $iv('cascade');
            $fInterp = $iv('cascade') || $iv('grid');
            $bClamp = $behind < -PvSummaryService::FEED_ABOVE_GEN_KWH;
            $fClamp = $frontKwh < -PvSummaryService::FEED_ABOVE_GEN_KWH;
            $monthly[$ym] = [
                'covered'          => true,
                'feed_kwh'         => round($z2, 1),
                'behind_kwh'       => round(max(0.0, $behind), 1),
                'front_kwh'        => round(max(0.0, $frontKwh), 1),
                // v3.10.0 (Entscheidung des Maintainers) — für Jahressummen: In einem verteilten Monat
                // zählt der unbegrenzte Wert. Die Verteilung schiebt Menge zwischen den Monaten einer
                // Lücke hin und her; nur so stimmt die Summe über die Lücke genau. Gemessen bleibt es
                // der begrenzte Wert: Ein Strang unter null ist dort ein Ablesefehler (gemeldet,
                // `inconsistent`), kein verschobener Anteil eines Nachbarmonats; ihn mit anderen
                // Monaten zu verrechnen, würde den Fehler in der Summe verstecken.
                'behind_sum_kwh'   => round($bInterp ? $behind : max(0.0, $behind), 1),
                'front_sum_kwh'    => round($fInterp ? $frontKwh : max(0.0, $frontKwh), 1),
                'clamped'          => $bClamp || $fClamp,
                'behind_estimated' => $bInterp || $bClamp,
                'front_estimated'  => $fInterp || $fClamp,
                'inconsistent'     => ($bClamp && !$bInterp) || ($fClamp && !$fInterp),
                'interpolated'     => $bInterp || $fInterp,
            ];
        }
        return [
            'behind' => ['role' => (string)Utilities::roleOf('strom', $pos), 'meter_ids' => $behindIds, 'name' => $nameOf($pos)],
            'front'  => ['role' => (string)Utilities::roleOf('strom', $front), 'meter_ids' => $frontIds, 'name' => $nameOf($front)],
            'feed_meter_ids' => array_keys($series),
            'monthly' => $monthly,
        ];
    }

    /**
     * v3.10.0 (Skeptiker M5) — Monate, in denen der Sonnenstrom eines Strangs nicht gemessen ist:
     * eine seiner zwei Reihen verteilt, der Strang auf null begrenzt oder der Monat nicht von allen
     * drei Reihen abgedeckt. Für die Arbeitszahl je Monat (eine Lücke an Z2 ergab sonst 12,8).
     *
     * @param array<string,mixed>|null $cascade compute()
     * @param list<string> $meterIds Stromzähler, deren Strang gemeint ist
     * @return array<string,true> ym → true
     */
    public static function estimatedFor(?array $cascade, array $meterIds): array
    {
        if ($cascade === null || $meterIds === []) return [];
        $hit = false;
        foreach (['front', 'behind'] as $pos) if (array_intersect($cascade[$pos]['meter_ids'], $meterIds) !== []) $hit = true;
        if (!$hit) return [];
        $out = [];
        foreach ($cascade['monthly'] as $ym => $m) {
            if (empty($m['covered'])) { $out[$ym] = true; continue; }
            foreach (['front', 'behind'] as $pos) {
                if (!empty($m[$pos . '_estimated']) && array_intersect($cascade[$pos]['meter_ids'], $meterIds) !== []) $out[$ym] = true;
            }
        }
        return $out;
    }

    /**
     * Sonnenstrom eines Strangs je Monat für Zähler einer Rolle bzw. mit diesen IDs
     * (Jahresarbeitszahl, Energiefluss): ym → kWh, nur abgedeckte Monate.
     *
     * @param array<string,mixed>|null $cascade compute()
     * @param list<string> $meterIds Stromzähler, deren Strang gemeint ist
     * @return array<string,float>
     */
    public static function shareFor(?array $cascade, array $meterIds, bool $forSum = false): array
    {
        if ($cascade === null || $meterIds === []) return [];
        $out = [];
        // v3.10.0 — `$forSum`: der Wert für Jahressummen (verteilt unbegrenzt, s. compute())
        foreach (['front' => $forSum ? 'front_sum_kwh' : 'front_kwh', 'behind' => $forSum ? 'behind_sum_kwh' : 'behind_kwh'] as $pos => $field) {
            if (array_intersect($cascade[$pos]['meter_ids'], $meterIds) === []) continue;
            foreach ($cascade['monthly'] as $ym => $m) {
                if (empty($m['covered'])) continue;
                $out[$ym] = ($out[$ym] ?? 0.0) + (float)$m[$field];
            }
        }
        return $out;
    }
}
