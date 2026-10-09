<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * v3.0.0 — Demo-Daten bis heute fortschreiben.
 *
 * Das Demo-Backup ist ein Schnappschuss vom Tag seines Exports (`exported_at`).
 * Ohne Fortschreibung altert es: Monate nach dem Export endeten alle Ablesungen
 * im Frühjahr, Termine waren überfällig, die öffentliche Demo zeigte eine
 * Wohnung, die seit einem halben Jahr niemand abgelesen hat.
 *
 * Fortgeschrieben wird, sobald seit dem Export Zeit vergangen ist (Δ):
 *
 * - **Zählerstände:** je Zähler ab seinem letzten Stand bis heute, mit den
 *   Stichtagen des Vorjahres — für mehr als ein Jahr in Jahresschritten. Der
 *   Verbrauch zwischen zwei neuen Ständen ist genau der Verbrauch desselben
 *   Zeitraums ein Jahr zuvor (Zählertausch berücksichtigt), die Stände laufen
 *   auf dem offenen Gerät weiter.
 * - **Lieferungen:** Was im Jahr vor dem Export geliefert wurde, kommt ein Jahr
 *   später wieder (seltene Ereignisse — daher ab dem Exporttag, nicht ab der
 *   letzten Lieferung).
 * - **Zeiträume (v3.2.0):** Monatswerte der Verbrauchsinfo, Monat für Monat mit
 *   dem Wert desselben Monats im Vorjahr, bis zum letzten ganzen Monat.
 * - **Temperaturen:** Tag für Tag der Wert vom selben Tag des Vorjahres, um Δ
 *   über den letzten Eintrag hinaus. Weil
 *   Verbrauch und Temperatur aus demselben Vorjahr stammen, passen Heizmodell
 *   und Witterungsbereinigung weiter zusammen — Winter bleibt Winter.
 * - **Termine:** relativ zu heute — der früheste aktive Termin ist seit einer
 *   Woche fällig, die übrigen behalten ihren Abstand. So zeigt die Demo immer
 *   beides: etwas Fälliges und etwas Kommendes.
 *
 * Verträge bleiben unverändert: Ein abgelaufener Vertrag läuft ohne Kündigung
 * weiter (`ContractService::resolveForDate`, v2.9.0) — genau das zeigt die Demo
 * dann auch. Bestehende Einträge werden nie verändert, nur ergänzt (Termine
 * ausgenommen). Reine Funktion: kein Speicher, kein Systemdatum.
 */
final class DemoDataAligner
{
    /** Tage, die der früheste Termin überfällig ist. */
    private const REMINDER_OVERDUE_DAYS = 7;

    /**
     * @param array<string,mixed> $payload Backup im Format 3.0
     * @return array<string,mixed>
     */
    public static function align(array $payload, string $today): array
    {
        $ref = self::referenceDate($payload);
        $payload['reminders'] = self::alignReminders($payload['reminders'] ?? [], $today);
        if ($ref === null || $today <= $ref) {
            return $payload;
        }
        $horizon = $today;   // Reihen wachsen bis heute (Export + Δ)

        foreach (($payload['utilities'] ?? []) as $u => $data) {
            if (!is_array($data)) continue;
            $meters = is_array($data['meters'] ?? null) ? $data['meters'] : [];
            if (is_array($data['readings'] ?? null)) {
                $data['readings'] = self::extendReadings($data['readings'], $meters, $horizon);
            }
            if (is_array($data['deliveries'] ?? null)) {
                $data['deliveries'] = self::extendEvents($data['deliveries'], $ref, $horizon);
            }
            if (is_array($data['periods'] ?? null) && $data['periods'] !== []) {
                $data['periods'] = self::extendPeriods($data['periods'], $horizon);
            }
            $payload['utilities'][$u] = $data;
        }

        if (is_array($payload['ev_sessions'] ?? null) && $payload['ev_sessions'] !== []) {
            $payload['ev_sessions'] = self::extendSessions($payload['ev_sessions'], $ref, $horizon);
        }

        if (is_array($payload['temperatures'] ?? null) && $payload['temperatures'] !== []) {
            $payload['temperatures'] = self::extendTemperatures(
                $payload['temperatures'], self::days($ref, $today));
        }
        return $payload;
    }

    /** Der Tag, an dem der Schnappschuss stimmte: Export, sonst die letzte Ablesung. */
    public static function referenceDate(array $payload): ?string
    {
        $exported = substr((string)($payload['exported_at'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exported)) return $exported;
        $last = null;
        foreach (($payload['utilities'] ?? []) as $data) {
            foreach ((array)($data['readings'] ?? []) as $r) {
                $d = (string)($r['date'] ?? '');
                if ($last === null || $d > $last) $last = $d;
            }
        }
        return $last;
    }

    // ── Zeiträume (v3.2.0, F1018) ────────────────────────────────────────

    /**
     * Monatszeiträume je Zähler fortschreiben: nach dem letzten Zeitraum je
     * ganzem Kalendermonat bis zum Monat vor `$horizon` der Wert desselben
     * Monats im Vorjahr. Fehlt das Vorjahr, endet die Reihe dort.
     *
     * @param array<int,array<string,mixed>> $periods
     * @return array<int,array<string,mixed>>
     */
    private static function extendPeriods(array $periods, string $horizon): array
    {
        $byMeter = [];
        foreach ($periods as $p) $byMeter[(string)($p['meter_id'] ?? '')][] = $p;
        $out = $periods;
        foreach ($byMeter as $meterId => $list) {
            usort($list, fn($a, $b) => strcmp((string)$a['from'], (string)$b['from']));
            $byFrom = [];
            foreach ($list as $p) $byFrom[(string)$p['from']] = $p;
            $last = end($list);
            $from = self::addDays((string)$last['to'], 1);
            while (true) {
                $to = date('Y-m-t', (int)strtotime($from));
                if ($to >= $horizon || substr($from, 8, 2) !== '01') break;
                $src = $byFrom[self::addYears($from, -1)] ?? null;
                if ($src === null) break;
                $new = $src;
                $new['id'] = 'p_' . substr(md5($meterId . '|' . $from), 0, 12);
                $new['from'] = $from;
                $new['to'] = $to;
                unset($new['client_ref'], $new['attachment_id']);
                $out[] = $byFrom[$from] = $new;
                $from = self::addDays($to, 1);
            }
        }
        return $out;
    }

    // ── Zählerstände ─────────────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $readings
     * @param array<int,array<string,mixed>> $meters
     * @return array<int,array<string,mixed>>
     */
    private static function extendReadings(array $readings, array $meters, string $horizon): array
    {
        $byMeter = [];
        foreach ($readings as $r) $byMeter[(string)($r['meter_id'] ?? '')][] = $r;
        $out = $readings;
        foreach ($meters as $m) {
            $id = (string)($m['id'] ?? '');
            $own = $byMeter[$id] ?? [];
            if ($own === []) continue;
            $device = self::openDevice($m);
            if ($device === null) continue;   // stillgelegter Zähler: nichts fortschreiben
            foreach (self::replayMeter($own, $m, $device, $horizon) as $new) $out[] = $new;
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $own Stände eines Zählers
     * @return array<int,array<string,mixed>> nur die neuen Stände
     */
    private static function replayMeter(array $own, array $meter, array $device, string $horizon): array
    {
        usort($own, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
        $series = $own;     // wächst mit jedem Durchgang; Quelle für den nächsten
        $new = [];
        // Je Durchgang das Jahr nach dem letzten Stand: Quelle sind die Stände
        // im Jahr davor (letzter Stand − 1 Jahr, heute − 1 Jahr]. Angesetzt wird
        // am letzten Stand des Zählers, nicht am Exporttag — sonst entstünde
        // zwischen beiden ein langes Intervall, das die Monate anders verteilt
        // als im Vorjahr (und einen Vorjahresvergleich ≠ 0 % erfände).
        for ($k = 1; $k <= 60; $k++) {
            $from = self::addYears((string)end($series)['date'], -1);
            $to   = self::addYears($horizon, -1);
            $curve = self::consumptionCurve($series, $meter);
            $sources = array_values(array_filter($series,
                fn($r) => (string)$r['date'] > $from && (string)$r['date'] <= $to));
            if ($sources === []) break;
            foreach ($sources as $s) {
                $date = self::addYears((string)$s['date'], 1);
                $last = end($series);
                if ($date > $horizon || $date <= (string)$last['date']) continue;
                // Verbrauch zwischen letztem Stand und neuem Stand = derselbe Zeitraum ein Jahr zuvor
                $inc = self::curveAt($curve, (string)$s['date'])
                     - self::curveAt($curve, self::addYears((string)$last['date'], -1));
                $counter = round((float)$last['counter'] + max(0.0, $inc), 3);
                $r = [
                    'id'           => preg_replace('/_p\d+$/', '', (string)($s['id'] ?? 'r')) . '_p' . $k,
                    'meter_id'     => (string)$meter['id'],
                    'device_id'    => (string)$device['id'],
                    'date'         => $date,
                    'counter'      => $counter,
                    'price_cents'  => null,
                    'note'         => '',
                    'is_estimated' => (bool)($s['is_estimated'] ?? false),
                    'is_future'    => false,
                ];
                $series[] = $r;
                $new[] = $r;
            }
        }
        return $new;
    }

    /**
     * Kumulierter Verbrauch über alle Geräte eines Zählers: [Datum, Menge] je Stand.
     * Über einen Zählertausch hinweg zählt der Rest des alten Geräts (Endstand)
     * und der Anlauf des neuen (ab Anfangsstand).
     *
     * @param array<int,array<string,mixed>> $series sortiert
     * @return array<int,array{0:string,1:float}>
     */
    private static function consumptionCurve(array $series, array $meter): array
    {
        $devices = [];
        foreach ((array)($meter['devices'] ?? []) as $d) $devices[(string)($d['id'] ?? '')] = $d;
        $curve = [];
        $sum = 0.0;
        $prev = null;
        foreach ($series as $r) {
            if ($prev !== null) {
                $pd = (string)($prev['device_id'] ?? '');
                $rd = (string)($r['device_id'] ?? '');
                if ($pd === $rd) {
                    $sum += max(0.0, (float)$r['counter'] - (float)$prev['counter']);
                } else {
                    $final = $devices[$pd]['final_counter'] ?? null;
                    $init  = $devices[$rd]['initial_counter'] ?? null;
                    if ($final !== null) $sum += max(0.0, (float)$final - (float)$prev['counter']);
                    if ($init !== null)  $sum += max(0.0, (float)$r['counter'] - (float)$init);
                }
            }
            $curve[] = [(string)$r['date'], $sum];
            $prev = $r;
        }
        return $curve;
    }

    /** Linear zwischen den Ständen; außerhalb des Bereichs der nächste Rand. */
    private static function curveAt(array $curve, string $date): float
    {
        if ($curve === []) return 0.0;
        if ($date <= $curve[0][0]) return $curve[0][1];
        $n = count($curve);
        if ($date >= $curve[$n - 1][0]) return $curve[$n - 1][1];
        for ($i = 1; $i < $n; $i++) {
            [$d1, $v1] = $curve[$i];
            if ($date > $d1) continue;
            [$d0, $v0] = $curve[$i - 1];
            $span = self::days($d0, $d1);
            return $span > 0 ? $v0 + ($v1 - $v0) * self::days($d0, $date) / $span : $v1;
        }
        return $curve[$n - 1][1];
    }

    /** @return array<string,mixed>|null das Gerät ohne Ausbaudatum */
    private static function openDevice(array $meter): ?array
    {
        if (($meter['active'] ?? true) === false) return null;
        foreach ((array)($meter['devices'] ?? []) as $d) {
            if (($d['removed_on'] ?? null) === null) return $d;
        }
        return null;
    }

    // ── Lieferungen ──────────────────────────────────────────────────────

    /**
     * Ereignisse (Lieferungen) des Vorjahresfensters ein Jahr später wiederholen.
     *
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array<string,mixed>>
     */
    private static function extendEvents(array $events, string $ref, string $horizon): array
    {
        $out = $events;
        for ($k = 1; self::addYears($ref, $k - 1) < $horizon; $k++) {
            $winFrom = self::addYears($ref, $k - 2);
            $winTo   = self::addYears($ref, $k - 1);
            foreach ($out as $e) {
                $d = (string)($e['date'] ?? '');
                if ($d <= $winFrom || $d > $winTo) continue;
                $date = self::addYears($d, 1);
                if ($date > $horizon) continue;
                $copy = $e;
                $copy['id'] = preg_replace('/_p\d+$/', '', (string)($e['id'] ?? 'del')) . '_p' . $k;
                $copy['date'] = $date;
                $out[] = $copy;
            }
        }
        return $out;
    }

    /**
     * v3.2.0 (F1022) — Ladevorgänge aus evcc wie Lieferungen: was im Jahr vor
     * dem Export geladen wurde, ein Jahr später noch einmal. Die Zählerstände
     * der Wallbox fallen bei den Kopien weg — sie passen nicht zu den
     * fortgeschriebenen Ablesungen; Menge, Sonnenanteil und Preis bleiben.
     *
     * @param array<int,array<string,mixed>> $sessions
     * @return array<int,array<string,mixed>>
     */
    private static function extendSessions(array $sessions, string $ref, string $horizon): array
    {
        $out = $sessions;
        for ($k = 1; self::addYears($ref, $k - 1) < $horizon; $k++) {
            $winFrom = self::addYears($ref, $k - 2);
            $winTo   = self::addYears($ref, $k - 1);
            foreach ($out as $s) {
                $d = (string)($s['date'] ?? '');
                if ($d <= $winFrom || $d > $winTo) continue;
                $date = self::addYears($d, 1);
                if ($date > $horizon) continue;
                $copy = $s;
                foreach (['created', 'finished'] as $f) {
                    $t = (string)($s[$f] ?? '');
                    if (strlen($t) >= 10) $copy[$f] = self::addYears(substr($t, 0, 10), 1) . substr($t, 10);
                }
                unset($copy['meter_start'], $copy['meter_stop']);
                $copy['date'] = $date;
                $copy['id'] = 'evs_' . substr(sha1((string)($copy['created'] ?? $date) . '|' . (string)($s['loadpoint'] ?? '')), 0, 12);
                $out[] = $copy;
            }
        }
        return $out;
    }

    // ── Temperaturen ─────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $temps Datum → Werte
     * @return array<string,mixed>
     */
    private static function extendTemperatures(array $temps, int $delta): array
    {
        ksort($temps);
        $last = (string)array_key_last($temps);
        $target = self::addDays($last, $delta);
        for ($d = self::addDays($last, 1); $d <= $target; $d = self::addDays($d, 1)) {
            $src = self::addYears($d, -1);
            if (!isset($temps[$src])) continue;
            $temps[$d] = $temps[$src];
        }
        return $temps;
    }

    // ── Termine ──────────────────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $reminders
     * @return array<int,array<string,mixed>>
     */
    private static function alignReminders(array $reminders, string $today): array
    {
        $first = null;
        foreach ($reminders as $r) {
            if (($r['active'] ?? true) === false) continue;
            $due = (string)($r['next_due'] ?? '');
            if ($due !== '' && ($first === null || $due < $first)) $first = $due;
        }
        if ($first === null) return $reminders;
        $shift = self::days($first, self::addDays($today, -self::REMINDER_OVERDUE_DAYS));
        if ($shift === 0) return $reminders;
        foreach ($reminders as $i => $r) {
            foreach (['next_due', 'last_done'] as $f) {
                if (!empty($r[$f]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r[$f])) {
                    $r[$f] = self::addDays((string)$r[$f], $shift);
                }
            }
            if (!empty($r['last_done']) && $r['last_done'] > $today) $r['last_done'] = $today;
            $reminders[$i] = $r;
        }
        return $reminders;
    }

    // ── Datum ────────────────────────────────────────────────────────────

    /** Jahre addieren; der 29. Februar wird in Nicht-Schaltjahren zum 28. (kein Überlauf in den März). */
    public static function addYears(string $date, int $years): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        $y += $years;
        // ohne ext-calendar (fehlt im Alpine-Image): Monatslänge über DateTime
        $max = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
        return sprintf('%04d-%02d-%02d', $y, $m, min($d, $max));
    }

    private static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date . ' 12:00:00', new \DateTimeZone('UTC')))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private static function days(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from . ' 12:00:00', new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable($to . ' 12:00:00', new \DateTimeZone('UTC'));
        return (int)$a->diff($b)->format('%r%a');
    }
}
