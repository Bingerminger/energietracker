<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * v3.9.0 (F1032, GitHub #40) — Rechenbausteine der Rest-Abrechnung am Elternzähler.
 *
 * Hat ein Subzähler einen eigenen Vertrag, rechnet der Vertrag des
 * Elternzählers nur den Rest: gemessen am Elternzähler minus die Menge des
 * Subzählers an den Tagen, an denen dieser nach einem eigenen Vertrag rechnet.
 * Beide Zähler werden an verschiedenen Tagen abgelesen. Angeglichen wird über
 * die Tagesverteilung, die die Verbrauchsrechnung ohnehin hat: Zwischen zwei
 * Ablesungen gilt je Zähler eine Tagesrate (ConsumptionService::distributeToMonths,
 * die Abschnitte `[von, bis, Menge]` einer Monatszeile). Hier stehen nur die
 * reinen Rechnungen auf diesen Abschnitten, ohne Speicherzugriff.
 *
 * Nach der letzten Ablesung des Subzählers wird seine letzte Tagesrate
 * fortgeschrieben (ausgewiesen als `estimated`), damit der Elternzähler für die
 * Tage bis zu seiner eigenen jüngeren Ablesung nicht alles rechnet. Vor der
 * ersten Ablesung und in Lücken gibt es keinen Wert (`open` Tage): Dort zieht
 * nichts ab, und der Hinweis sagt es.
 */
final class SubmeterRest
{
    /**
     * Abschnitte vorbereiten: aus `ym → [[von, bis, Menge], …]` (Coverage einer
     * Monatsreihe) eine aufsteigende Liste `[Starttag, Endtag, Menge je Tag]`
     * mit Tagesnummern (ganze Tage seit 1970, UTC).
     *
     * @param array<string,list<array{0:string,1:string,2?:float}>> $coverage
     * @return list<array{0:int,1:int,2:float}>
     */
    public static function prepare(array $coverage): array
    {
        $out = [];
        foreach ($coverage as $list) {
            foreach ((array)$list as $s) {
                if (!is_array($s) || !isset($s[0], $s[1])) continue;
                $a = self::day((string)$s[0]);
                $b = self::day((string)$s[1]);
                if ($b <= $a) continue;
                $out[] = [$a, $b, (float)($s[2] ?? 0.0) / ($b - $a)];
            }
        }
        usort($out, fn($x, $y) => $x[0] <=> $y[0]);
        return $out;
    }

    /**
     * Menge in den Tagen [a, b) aus vorbereiteten Abschnitten.
     *
     * @param list<array{0:int,1:int,2:float}> $spans
     * @param int $maxEstDays so viele Tage nach dem letzten Abschnitt höchstens fortschreiben
     * @return array{0:float,1:float,2:int} gemessen (aus der Verteilung), fortgeschrieben
     *         (nach dem letzten Abschnitt, mit dessen Tagesrate), Tage ohne Wert
     */
    public static function between(array $spans, int $a, int $b, int $maxEstDays = PHP_INT_MAX): array
    {
        if ($b <= $a) return [0.0, 0.0, 0];
        $n = count($spans);
        if ($n === 0) return [0.0, 0.0, $b - $a];
        // erster Abschnitt, der nach a endet (binäre Suche — bei täglichen Ständen tausende Abschnitte)
        $lo = 0; $hi = $n;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($spans[$mid][1] <= $a) $lo = $mid + 1; else $hi = $mid;
        }
        $measured = 0.0; $covered = 0;
        for ($i = $lo; $i < $n && $spans[$i][0] < $b; $i++) {
            $x = max($a, $spans[$i][0]);
            $y = min($b, $spans[$i][1]);
            if ($y <= $x) continue;
            $measured += ($y - $x) * $spans[$i][2];
            $covered += $y - $x;
        }
        $last = $spans[$n - 1];
        $estEnd = $maxEstDays === PHP_INT_MAX ? $b : min($b, $last[1] + max(0, $maxEstDays));
        $estDays = $estEnd > $last[1] ? $estEnd - max($a, $last[1]) : 0;
        $estDays = max(0, $estDays);
        $estimated = $estDays * $last[2];
        return [$measured, $estimated, max(0, ($b - $a) - $covered - $estDays)];
    }

    /** Tagesnummer eines ISO-Datums (UTC, ohne Sommerzeitsprung). */
    public static function day(string $date): int
    {
        $y = (int)substr($date, 0, 4); $m = (int)substr($date, 5, 2); $d = (int)substr($date, 8, 2);
        return intdiv((int)gmmktime(0, 0, 0, $m, $d, $y), 86400);
    }

    /** ISO-Datum einer Tagesnummer. */
    public static function date(int $day): string
    {
        return gmdate('Y-m-d', $day * 86400);
    }

    /**
     * Der Hinweis „wie gerechnet wird“ als API-Feld (`rest_of`): gemessen am
     * Elternzähler, je Subzähler die abgezogene Menge, der Rest. Zusätze nur,
     * wenn es sie gibt: `estimated_kwh` (fortgeschrieben), `open_days` (Tage
     * ohne Wert des Subzählers), `short_kwh` (Rest unter null, nicht berechnet).
     *
     * @param array<string,float> $by Menge je Subzähler-ID
     * @param array<string,string> $names Name je Subzähler-ID
     * @return array<string,mixed>
     */
    public static function restOf(float $measured, array $by, array $names, float $estimated = 0.0, int $openDays = 0, float $short = 0.0): array
    {
        $subs = [];
        foreach ($by as $id => $kwh) {
            $subs[] = ['meter_id' => (string)$id, 'name' => (string)($names[$id] ?? $id), 'kwh' => round($kwh, 1)];
        }
        $out = ['measured' => round($measured, 1), 'submeters' => $subs, 'rest' => round($measured - array_sum($by), 1)];
        if ($estimated > 0.05) $out['estimated_kwh'] = round($estimated, 1);
        if ($openDays > 0) $out['open_days'] = $openDays;
        if ($short > 0.05) $out['short_kwh'] = round($short, 1);
        return $out;
    }

    /**
     * Zwei Hinweise zusammenzählen (Gruppe aus Mitgliedern, Jahr aus Monaten).
     *
     * @param array<string,mixed>|null $acc
     * @param array<string,mixed> $add
     * @return array<string,mixed>
     */
    public static function mergeRestOf(?array $acc, array $add): array
    {
        if ($acc === null) return $add;
        $by = [];
        foreach ([$acc, $add] as $r) {
            foreach ((array)($r['submeters'] ?? []) as $s) {
                $id = (string)$s['meter_id'];
                $by[$id] = ['meter_id' => $id, 'name' => (string)$s['name'], 'kwh' => round(($by[$id]['kwh'] ?? 0.0) + (float)$s['kwh'], 1)];
            }
        }
        $out = ['measured' => round((float)$acc['measured'] + (float)$add['measured'], 1), 'submeters' => array_values($by),
                'rest' => round((float)$acc['rest'] + (float)$add['rest'], 1)];
        foreach (['estimated_kwh', 'short_kwh'] as $k) {
            $v = (float)($acc[$k] ?? 0) + (float)($add[$k] ?? 0);
            if ($v > 0.05) $out[$k] = round($v, 1);
        }
        $open = (int)($acc['open_days'] ?? 0) + (int)($add['open_days'] ?? 0);
        if ($open > 0) $out['open_days'] = $open;
        return $out;
    }
}
