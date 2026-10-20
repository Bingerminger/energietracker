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
 *   beides: etwas Fälliges und etwas Kommendes. Die Zuständigkeit (`assignee`,
 *   v3.5.0) bleibt, wie sie ist.
 * - **Verlauf „Erledigt“ (v3.5.0, N1026):** Einträge zu Terminen wandern um
 *   dieselbe Spanne wie die Termine (`done_on`, `recorded_at`, `undone.at`, der
 *   Anker im Vorkommen, die Stände in `revert`) — so passt „zuletzt erledigt am
 *   … von …“ weiter zum Termin. Alle anderen Einträge hängen an Daten, die
 *   bleiben (Vertragsende, Rechnung, Ablesung, Abrechnung, Monat einer
 *   Empfehlung), und behalten ihr Datum.
 * - **Viertelstunden (v3.5.0, N1026):** die letzten zwei bis drei Monate bis
 *   gestern — ab dem Ersten des vorvorigen Monats, frühestens nach dem Export —
 *   je Tag aus demselben Kalendertag des jüngsten Vorjahres mit Werten, alle
 *   Reihen (Verbrauch und Börsenpreis) aus demselben Quelltag, damit jede
 *   Viertelstunde weiter aufgeht. Zugeordnet wird nach Ortszeit: Am Tag der
 *   Zeitumstellung fehlt die Stunde 2–3 Uhr bzw. kommt zweimal vor. Weil die
 *   Zählerstände desselben Zeitraums aus demselben Vorjahr stammen, passen
 *   Viertelstunden und Stände zusammen. Nur ein begrenztes Fenster, damit
 *   Datenverzeichnis und öffentliche Demo nicht mit jedem Jahr wachsen.
 *
 * - **Abrechnungsstichtag (v3.5.0, N1026):** nur wo das Demo-Backup es mit
 *   `demo_align.billing_anchor_days_ago` verlangt — die letzte Periode der Art
 *   endet dann so viele Tage vor heute, „Abrechnung erwartet“ (F1029) ist zu
 *   jedem Zeitpunkt zu sehen. `demo_align` selbst wird nicht eingespielt.
 *   Seit v3.6.0 wandern die Jahresrechnungen dieser Art mit (`alignBills`):
 *   Die jüngste endet am Tag vor dem Stichtag vor einem Jahr, die Kette davor
 *   ist lückenlos — das Abrechnungsjahr (F1030) zeigt keine Zwischenperiode und
 *   schlägt keinen anderen Stichtag vor. Die Beträge der gewanderten Rechnungen
 *   rechnet DemoService nach dem Einspielen nach (`_demo_recalc`).
 *
 * Verträge bleiben unverändert: Ein abgelaufener Vertrag läuft ohne Kündigung
 * weiter (`ContractService::resolveForDate`, v2.9.0) — genau das zeigt die Demo
 * dann auch. Versorgerrechnungen bleiben ebenso, wie sie sind (v3.5.0): Eine
 * Rechnung ist ein Beleg mit Beträgen, die sich nicht aus dem Vorjahr kopieren
 * lassen — ausgenommen die Arten mit Stichtag relativ zu heute (s. o.).
 * Bestehende Einträge werden nie verändert, nur ergänzt (Termine, ihr Verlauf,
 * ein verlangter Stichtag und die Rechnungen seiner Art ausgenommen). Reine
 * Funktion: kein Speicher, kein Systemdatum.
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
        $payload = self::alignBillingAnchors($payload, $today);
        $shift = self::reminderShift($payload['reminders'] ?? [], $today);
        $payload['reminders'] = self::alignReminders($payload['reminders'] ?? [], $shift, $today);
        // v3.5.0 (N1026) — der Verlauf zu Terminen wandert mit
        if (is_array($payload['activity'] ?? null) && $payload['activity'] !== [] && $shift !== 0) {
            $payload['activity'] = self::alignActivity($payload['activity'], $shift, $today, self::timezone($payload));
        }
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

        // v3.5.0 (N1026) — Viertelstunden der letzten Monate (Tagesfilm)
        if (is_array($payload['intervals']['series'] ?? null) && $payload['intervals']['series'] !== []
            && is_array($payload['interval_files'] ?? null)) {
            [$payload['intervals'], $payload['interval_files']] = self::extendIntervals(
                $payload['intervals'], $payload['interval_files'], $ref, $today, self::timezone($payload));
        }
        return $payload;
    }

    /**
     * v3.5.0 (N1026) — Abrechnungsstichtag relativ zu heute, nur wo das
     * Demo-Backup es verlangt: `demo_align.billing_anchor_days_ago` =
     * {Verbrauchsart: Tage}. Die zuletzt beendete Abrechnungsperiode endet
     * dann so viele Tage vor heute — die Demo zeigt „Abrechnung erwartet“
     * (F1029) zu jedem Zeitpunkt, wie Termine immer etwas Fälliges zeigen.
     * Der Schlüssel `demo_align` selbst wird nicht eingespielt.
     */
    private static function alignBillingAnchors(array $payload, string $today): array
    {
        $want = $payload['demo_align']['billing_anchor_days_ago'] ?? null;
        unset($payload['demo_align']);
        if (!is_array($want)) return $payload;
        foreach ($want as $u => $days) {
            if (!is_string($u) || !\Energietracker\Config\Utilities::exists($u) || !is_int($days) || $days < 1 || $days > 300) continue;
            $anchor = substr(self::addDays($today, 1 - $days), 5, 5);
            $anchor = $anchor === '02-29' ? '03-01' : $anchor;
            $payload['settings']['billing_cycle_anchor_' . $u] = $anchor;
            // v3.6.0 — die zuletzt beendete Periode (die „erwartete“) beginnt am
            // Stichtag vor einem Jahr; die jüngste Rechnung endet am Tag davor
            [$expectedFrom] = BillingPeriodService::gridAround($anchor, self::addDays($today, -$days));
            $payload = self::alignBills($payload, $u, self::addDays($expectedFrom, -1), $today);
        }
        return $payload;
    }

    /**
     * v3.6.0 — Versorgerrechnungen im Rhythmus des Stichtags. Ohne das endete
     * die letzte Rechnung am alten Datum, der Stichtag aber wanderte mit heute:
     * Das Abrechnungsjahr (F1030) zeigte Zwischenperioden und schlug einen
     * anderen Stichtag vor.
     *
     * Je Zähler der Art: Schlussrechnungen (`kind: final`) gehören zu einem
     * Vertragsende und bleiben. Die Jahresrechnungen bilden danach eine
     * lückenlose Kette, die am Tag nach der letzten Schlussrechnung beginnt
     * (ohne Schlussrechnung: so viele Jahre wie vorhanden) und am `$lastEnd`
     * endet — je ein Jahr, die erste Rechnung eines Vertrags darf kürzer oder
     * länger sein (kürzer als BillExpectedService::MIN_PERIOD_DAYS: mit der
     * nächsten zusammen, wie der Versorger es meist hält). Die jüngste
     * Rechnung behält ihre Daten (ID, Sonderzahlung, Notiz), fehlen Rechnungen
     * für die Kette, kommen Kopien der ältesten ohne Sonderzahlung dazu;
     * überzählige fallen mit ihrer Sonderzahlung und ihren Verlaufseinträgen
     * weg, ebenso Rechnungen vor der ersten Ablesung. Rechnungsdatum,
     * Erfassung, die verknüpfte Sonderzahlung (Datum, ab wann der neue
     * Abschlag gilt) und der Verlauf zur Rechnung wandern mit dem
     * Rechnungsende. Was sich ändert, trägt `_demo_recalc` — die Beträge
     * rechnet DemoService nach dem Einspielen nach (BillService::compare), so
     * passt „Rechnung prüfen“ weiter.
     */
    private static function alignBills(array $payload, string $u, string $lastEnd, string $today): array
    {
        $bills = $payload['utilities'][$u]['bills'] ?? null;
        if (!is_array($bills) || $bills === []) return $payload;
        $byMeter = [];
        foreach ($bills as $i => $b) {
            if (is_array($b) && self::isDate($b['period_from'] ?? null) && self::isDate($b['period_to'] ?? null)) {
                $byMeter[(string)($b['meter_id'] ?? '')][] = $i;
            }
        }
        $drop = [];
        foreach ($byMeter as $meterId => $idx) {
            $finals = array_values(array_filter($idx, fn($i) => ($bills[$i]['kind'] ?? null) === 'final'));
            $annual = array_values(array_filter($idx, fn($i) => ($bills[$i]['kind'] ?? null) !== 'final'));
            usort($annual, fn($a, $b) => strcmp((string)$bills[$b]['period_to'], (string)$bills[$a]['period_to']));   // jüngste zuerst
            $chainFrom = null;
            $lastFinal = null;
            foreach ($finals as $i) {
                $next = self::addDays((string)$bills[$i]['period_to'], 1);
                if ($chainFrom === null || $next > $chainFrom) [$chainFrom, $lastFinal] = [$next, $i];
            }
            if ($annual === [] && $chainFrom === null) continue;

            // leer, wenn eine Schlussrechnung nach dem Ziel endet: dann bleibt alles
            $periods = self::billChain($chainFrom, $lastEnd, count($annual));
            $first = self::firstReading($payload['utilities'][$u]['readings'] ?? [], $meterId);
            $periods = array_values(array_filter($periods, fn($p) => $first !== null && $p[0] >= $first));
            if ($periods === []) continue;

            $template = $annual !== [] ? $bills[end($annual)] : self::annualFrom($bills[$lastFinal]);
            foreach ($periods as $k => [$from, $to]) {
                if (isset($annual[$k])) {
                    $i = $annual[$k];
                    if ($bills[$i]['period_from'] === $from && $bills[$i]['period_to'] === $to) continue;
                    [$bills[$i], $payload] = self::moveBill($bills[$i], $from, $to, $payload, $u, $today);
                } else {
                    $copy = $template;
                    $copy['id'] = 'b_' . substr(sha1((string)($template['id'] ?? '') . '|' . $to), 0, 12);
                    $copy['contract_id'] = self::contractAt($payload['utilities'][$u]['contracts'] ?? [], $meterId, $to) ?? ($template['contract_id'] ?? null);
                    unset($copy['special_payment_id']);
                    $copy['attachment_ids'] = [];
                    [$copy] = self::moveBill($copy, $from, $to, $payload, $u, $today);
                    $bills[] = $copy;
                }
            }
            foreach (array_slice($annual, count($periods)) as $i) $drop[] = $i;
        }
        foreach ($drop as $i) $payload = self::forgetBill($payload, $u, $bills[$i]);
        $payload['utilities'][$u]['bills'] = array_values(array_diff_key($bills, array_flip($drop)));
        return $payload;
    }

    /**
     * Perioden der Kette, jüngste zuerst: je ein Jahr rückwärts ab `$lastEnd`
     * bis zum Beginn `$chainFrom` (die älteste gekürzt bzw. — kürzer als
     * MIN_PERIOD_DAYS — mit der nächsten zusammen), ohne Beginn `$count` Jahre.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function billChain(?string $chainFrom, string $lastEnd, int $count): array
    {
        $out = [];
        for ($end = $lastEnd; count($out) < 60;) {
            $from = self::addYears(self::addDays($end, 1), -1);
            if ($chainFrom !== null && $from <= $chainFrom) {
                if ($end >= $chainFrom) $out[] = [$chainFrom, $end];
                break;
            }
            $out[] = [$from, $end];
            if ($chainFrom === null && count($out) >= $count) break;
            $end = self::addDays($from, -1);
        }
        $n = count($out);
        if ($chainFrom !== null && $n >= 2 && self::days($out[$n - 1][0], $out[$n - 1][1]) + 1 < BillExpectedService::MIN_PERIOD_DAYS) {
            $out[$n - 2][0] = $out[$n - 1][0];
            array_pop($out);
        }
        return $out;
    }

    /**
     * Rechnung auf [from, to] legen: Rechnungsdatum und Erfassung wandern mit
     * dem Ende, ebenso die verknüpfte Sonderzahlung und der Verlauf.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private static function moveBill(array $b, string $from, string $to, array $payload, string $u, string $today): array
    {
        $shift = self::days((string)$b['period_to'], $to);
        $oldFrom = (string)$b['period_from'];
        $oldTo = (string)$b['period_to'];
        $b['period_from'] = $from;
        $b['period_to'] = $to;
        if (self::isDate($b['issued_on'] ?? null)) $b['issued_on'] = self::addDays((string)$b['issued_on'], $shift);
        if (is_string($b['created_at'] ?? null) && self::isDate(substr($b['created_at'], 0, 10))) {
            $b['created_at'] = self::addDays(substr($b['created_at'], 0, 10), $shift) . substr($b['created_at'], 10);
        }
        $b['_demo_recalc'] = true;
        $sp = (string)($b['special_payment_id'] ?? '');
        foreach (($payload['utilities'][$u]['contracts'] ?? []) as $ci => $c) {
            if ($sp === '' || !is_array($c['special_payments'] ?? null)) continue;
            foreach ($c['special_payments'] as $si => $s) {
                if (($s['id'] ?? null) !== $sp) continue;
                foreach (['date', 'advance_from'] as $f) {
                    if (self::isDate($s[$f] ?? null)) $s[$f] = self::addDays((string)$s[$f], $shift);
                }
                $payload['utilities'][$u]['contracts'][$ci]['special_payments'][$si] = $s;
            }
        }
        $dmy = fn(string $d) => substr($d, 8, 2) . '.' . substr($d, 5, 2) . '.' . substr($d, 0, 4);
        foreach (($payload['activity'] ?? []) as $ai => $e) {
            if (!is_array($e) || ($e['ref']['bill_id'] ?? null) !== ($b['id'] ?? null)) continue;
            if (($e['subject']['kind'] ?? null) === 'bill_expected') {
                $uid = AgendaService::uid('bill_expected', (string)($e['ref']['contract_id'] ?? ''), $to);
                $e['subject']['uid'] = $uid;
                $e['subject']['occurrence'] = $uid . '@' . $to;
            }
            if (is_array($e['params'] ?? null) && isset($e['params']['until'])) {
                $e['params']['from'] = $from;
                $e['params']['until'] = $to;
            }
            if (is_string($e['title'] ?? null)) {
                $e['title'] = str_replace([$dmy($oldFrom), $dmy($oldTo)], [$dmy($from), $dmy($to)], $e['title']);
            }
            if (self::isDate($e['done_on'] ?? null)) $e['done_on'] = min(self::addDays((string)$e['done_on'], $shift), $today);
            if (is_string($e['recorded_at'] ?? null) && self::isDate(substr($e['recorded_at'], 0, 10))) {
                $e['recorded_at'] = self::addDays(substr($e['recorded_at'], 0, 10), $shift) . substr($e['recorded_at'], 10);
            }
            $payload['activity'][$ai] = $e;
        }
        return [$b, $payload];
    }

    /** Eine weggelassene Rechnung: ihre Sonderzahlung und ihr Verlauf gehen mit. */
    private static function forgetBill(array $payload, string $u, array $b): array
    {
        $sp = (string)($b['special_payment_id'] ?? '');
        foreach (($payload['utilities'][$u]['contracts'] ?? []) as $ci => $c) {
            if ($sp === '' || !is_array($c['special_payments'] ?? null)) continue;
            $payload['utilities'][$u]['contracts'][$ci]['special_payments'] = array_values(array_filter(
                $c['special_payments'], fn($s) => ($s['id'] ?? null) !== $sp));
        }
        if (is_array($payload['activity'] ?? null)) {
            $payload['activity'] = array_values(array_filter($payload['activity'],
                fn($e) => !is_array($e) || ($e['ref']['bill_id'] ?? null) !== ($b['id'] ?? null)));
        }
        return $payload;
    }

    /** Vorlage einer Jahresrechnung aus einer Schlussrechnung (ohne Notiz, Posten und Sonderzahlung). */
    private static function annualFrom(array $final): array
    {
        $b = $final;
        $b['kind'] = 'annual';
        $b['note'] = '';
        $b['items'] = [];
        unset($b['special_payment_id']);
        return $b;
    }

    /** Der echte Vertrag des Zählers, der zuletzt vor oder an `$date` begann. */
    private static function contractAt(array $contracts, string $meterId, string $date): ?string
    {
        $best = null;
        foreach ($contracts as $c) {
            if (!is_array($c) || !empty($c['is_shadow']) || (string)($c['meter_id'] ?? '') !== $meterId) continue;
            $start = (string)($c['start'] ?? '');
            if (!self::isDate($start) || $start > $date) continue;
            if ($best === null || $start > (string)$best['start']) $best = $c;
        }
        return $best !== null ? (string)$best['id'] : null;
    }

    /** Erste gültige Ablesung eines Zählers (ohne geplante). */
    private static function firstReading(mixed $readings, string $meterId): ?string
    {
        $first = null;
        foreach ((array)$readings as $r) {
            if (!is_array($r) || (string)($r['meter_id'] ?? '') !== $meterId || !empty($r['is_future'])) continue;
            $d = (string)($r['date'] ?? '');
            if (self::isDate($d) && ($first === null || $d < $first)) $first = $d;
        }
        return $first;
    }

    private static function isDate(mixed $d): bool
    {
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
    }

    /** Zeitzone der Installation laut Backup (wie IntervalService), sonst Berlin. */
    private static function timezone(array $payload): \DateTimeZone
    {
        $tz = (string)($payload['settings']['timezone'] ?? 'Europe/Berlin');
        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? new \DateTimeZone($tz) : new \DateTimeZone('Europe/Berlin');
    }

    /** Erster Tag des Fensters für Viertelstunden: Erster des vorvorigen Monats. */
    public static function intervalWindowStart(string $today): string
    {
        return (new \DateTimeImmutable(substr($today, 0, 7) . '-01'))->modify('-2 months')->format('Y-m-d');
    }

    // ── Viertelstunden (v3.5.0, N1026) ───────────────────────────────────

    /**
     * Fenster [Erster des vorvorigen Monats, gestern], frühestens ab dem Tag
     * nach dem Export, Tag für Tag aus demselben Kalendertag des jüngsten
     * Vorjahres, in dem eine Verbrauchsreihe Werte hat. Alle Reihen kommen aus
     * demselben Quelltag; Tage, an denen eine Reihe schon Werte hat, bleiben.
     *
     * @param array<string,mixed> $index  {series, samples}
     * @param array<string,mixed> $files  id → {id, kind, year, step, start, values}
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private static function extendIntervals(array $index, array $files, string $ref, string $today, \DateTimeZone $tz): array
    {
        $from = max(self::intervalWindowStart($today), self::addDays($ref, 1));
        $to = self::addDays($today, -1);
        if ($from > $to) return [$index, $files];

        // Reihen je Schlüssel (Art, Verbrauchsart, Zähler bzw. Gebotszone) und Jahr
        $byKey = [];
        foreach ($index['series'] as $pos => $s) {
            if (!is_array($s) || !in_array($s['kind'] ?? null, ['energy', 'price'], true)) continue;
            $id = (string)($s['id'] ?? '');
            if (!is_array($files[$id]['values'] ?? null)) continue;
            $key = ($s['kind'] ?? '') . '|' . ($s['utility'] ?? '') . '|' . ($s['meter_id'] ?? '') . '|' . ($s['area'] ?? '');
            $byKey[$key][(int)$s['year']] = ['pos' => $pos, 'id' => $id];
        }
        if ($byKey === []) return [$index, $files];

        // id → Werte. Geschrieben wird an Ort und Stelle in $values (je Reihe
        // höchstens eine Kopie; eine Reihe hat 35.000 Plätze).
        $values = [];
        $touched = [];
        for ($day = $from; $day <= $to; $day = self::addDays($day, 1)) {
            $target = self::daySlots($day, $tz);
            // Quelltag: jüngstes Vorjahr, in dem eine Verbrauchsreihe an dem Tag Werte hat
            $source = null;
            for ($k = 1; $k <= 10 && $source === null; $k++) {
                $src = self::addYears($day, -$k);
                $y = (int)substr($src, 0, 4);
                $slots = self::daySlots($src, $tz);
                foreach ($byKey as $key => $years) {
                    if (!str_starts_with($key, 'energy|') || !isset($years[$y])) continue;
                    $id = $years[$y]['id'];
                    $v = $values[$id] ?? $files[$id]['values'];
                    foreach ($slots as $sl) if (($v[$sl['i']] ?? null) !== null) { $source = [$src, $y, $slots]; break 2; }
                }
            }
            if ($source === null) continue;
            [, $sy, $sslots] = $source;
            $lookup = [];
            foreach ($sslots as $sl) $lookup[$sl['min'] . '#' . $sl['occ']] = $sl['i'];
            $ty = (int)substr($day, 0, 4);
            foreach ($byKey as $key => $years) {
                if (!isset($years[$sy])) continue;
                $sid = $years[$sy]['id'];
                $src = $values[$sid] ?? $files[$sid]['values'];
                if (!isset($years[$ty])) {
                    $years[$ty] = self::newSeries($index, $files, $years[$sy], $ty, $tz);
                    $byKey[$key] = $years;
                }
                $tid = $years[$ty]['id'];
                if (!isset($values[$tid])) {
                    // einmal herauslösen, danach an Ort und Stelle schreiben
                    $values[$tid] = array_values((array)$files[$tid]['values']);
                    $files[$tid]['values'] = null;
                }
                // schon Werte an diesem Tag: unverändert lassen
                $has = false;
                foreach ($target as $sl) if (($values[$tid][$sl['i']] ?? null) !== null) { $has = true; break; }
                if ($has) continue;
                foreach ($target as $sl) {
                    $si = $lookup[$sl['min'] . '#' . $sl['occ']] ?? $lookup[$sl['min'] . '#0'] ?? null;
                    if ($si !== null && array_key_exists($sl['i'], $values[$tid])) $values[$tid][$sl['i']] = $src[$si] ?? null;
                }
                $touched[$tid] = $years[$ty]['pos'];
            }
            unset($src);
        }
        foreach ($values as $id => $v) {
            $files[$id]['values'] = $v;
            if (isset($touched[$id])) {
                $index['series'][$touched[$id]] = self::indexEntry($index['series'][$touched[$id]], $v, (int)$files[$id]['year'], $tz);
            }
        }
        return [$index, $files];
    }

    /**
     * Plätze eines Tages im Jahr (Ortszeit): je Platz Index, Minute des Tages
     * und das wievielte Mal diese Uhrzeit am Tag vorkommt (Herbst: zweimal).
     *
     * @return list<array{i:int, min:int, occ:int}>
     */
    private static function daySlots(string $date, \DateTimeZone $tz): array
    {
        $yearStart = (new \DateTimeImmutable(substr($date, 0, 4) . '-01-01 00:00:00', $tz))->getTimestamp();
        $a = (new \DateTimeImmutable("$date 00:00:00", $tz))->getTimestamp();
        $b = (new \DateTimeImmutable("$date 00:00:00", $tz))->modify('+1 day')->getTimestamp();
        $out = []; $seen = [];
        for ($ts = $a; $ts < $b; $ts += IntervalService::STEP) {
            $l = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
            $min = (int)$l->format('G') * 60 + (int)$l->format('i');
            $occ = $seen[$min] = isset($seen[$min]) ? $seen[$min] + 1 : 0;
            $out[] = ['i' => intdiv($ts - $yearStart, IntervalService::STEP), 'min' => $min, 'occ' => $occ];
        }
        return $out;
    }

    /**
     * Neue Reihe eines Jahres nach dem Vorbild der Quellreihe (gleiche Art,
     * Verbrauchsart, Zähler bzw. Gebotszone, Quelle), noch ohne Werte.
     *
     * @return array{pos:int, id:string}
     */
    private static function newSeries(array &$index, array &$files, array $like, int $year, \DateTimeZone $tz): array
    {
        $meta = $index['series'][$like['pos']];
        $id = ($meta['kind'] ?? '') === 'price'
            ? IntervalService::priceId($year)
            : IntervalService::energyId((string)$meta['utility'], (string)$meta['meter_id'], $year);
        $start = new \DateTimeImmutable("$year-01-01 00:00:00", $tz);
        $slots = intdiv($start->modify('+1 year')->getTimestamp() - $start->getTimestamp(), IntervalService::STEP);
        $files[$id] = ['id' => $id, 'kind' => $meta['kind'], 'year' => $year, 'step' => IntervalService::STEP,
            'start' => $start->format('c'), 'values' => array_fill(0, $slots, null)];
        $entry = $meta;
        $entry['id'] = $id;
        $entry['year'] = $year;
        $entry['slots'] = $slots;
        $index['series'][] = $entry;
        return ['pos' => array_key_last($index['series']), 'id' => $id];
    }

    /** Indexeintrag nachführen wie IntervalService::write (filled, from, to). */
    private static function indexEntry(array $entry, array $values, int $year, \DateTimeZone $tz): array
    {
        $start = (new \DateTimeImmutable("$year-01-01 00:00:00", $tz))->getTimestamp();
        $n = 0; $first = null; $last = null;
        foreach ($values as $i => $v) {
            if ($v === null) continue;
            $n++;
            $first ??= $i;
            $last = $i;
        }
        $iso = fn(int $i) => (new \DateTimeImmutable('@' . ($start + $i * IntervalService::STEP)))->setTimezone($tz)->format('c');
        $entry['slots'] = count($values);
        $entry['filled'] = $n;
        $entry['from'] = $first !== null ? $iso($first) : null;
        $entry['to'] = $last !== null ? $iso($last + 1) : null;
        return $entry;
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
     * Tage, um die die Termine wandern: Der früheste aktive Termin ist danach
     * seit REMINDER_OVERDUE_DAYS fällig. 0 ohne Termin.
     *
     * @param array<int,array<string,mixed>> $reminders
     */
    public static function reminderShift(array $reminders, string $today): int
    {
        $first = null;
        foreach ($reminders as $r) {
            if (!is_array($r) || ($r['active'] ?? true) === false) continue;
            $due = (string)($r['next_due'] ?? '');
            if ($due !== '' && ($first === null || $due < $first)) $first = $due;
        }
        return $first === null ? 0 : self::days($first, self::addDays($today, -self::REMINDER_OVERDUE_DAYS));
    }

    /**
     * @param array<int,array<string,mixed>> $reminders
     * @return array<int,array<string,mixed>>
     */
    private static function alignReminders(array $reminders, int $shift, string $today): array
    {
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

    // ── Verlauf (v3.5.0, N1026) ─────────────────────────────────────────

    /**
     * Einträge zu Terminen um dieselbe Spanne wie die Termine verschieben:
     * Tag, Zeitpunkt des Eintrags und der Rücknahme, Anker des Vorkommens
     * (`reminder-<id>@<fällig>`) und die Termin-Stände in `revert`. Nichts
     * landet nach heute (wie `last_done`).
     *
     * @param array<int,mixed> $entries
     * @return array<int,mixed>
     */
    private static function alignActivity(array $entries, int $shift, string $today, \DateTimeZone $tz): array
    {
        $day = function (mixed $d) use ($shift, $today): mixed {
            if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
            return min(self::addDays($d, $shift), $today);
        };
        $stamp = function (mixed $t) use ($shift, $today, $tz): mixed {
            if (!is_string($t) || strlen($t) < 10) return $t;
            try {
                $d = (new \DateTimeImmutable($t))->setTimezone($tz)->modify(($shift >= 0 ? '+' : '') . $shift . ' days');
            } catch (\Exception) {
                return $t;
            }
            return $d->format('Y-m-d') > $today ? (new \DateTimeImmutable("$today 12:00:00", $tz))->format('c') : $d->format('c');
        };
        foreach ($entries as $i => $e) {
            if (!is_array($e) || ($e['subject']['kind'] ?? null) !== 'reminder') continue;
            $e['done_on'] = $day($e['done_on'] ?? null);
            $e['recorded_at'] = $stamp($e['recorded_at'] ?? null);
            if (is_array($e['undone'] ?? null)) $e['undone']['at'] = $stamp($e['undone']['at'] ?? null);
            $occ = (string)($e['subject']['occurrence'] ?? '');
            if (preg_match('/^(.*@)(\d{4}-\d{2}-\d{2})$/', $occ, $m)) {
                $e['subject']['occurrence'] = $m[1] . self::addDays($m[2], $shift);
            }
            foreach (['before', 'after'] as $side) {
                foreach (['next_due', 'last_done'] as $f) {
                    $v = $e['revert'][$side][$f] ?? null;
                    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) continue;
                    // wie alignReminders: ein erledigt-am liegt nie nach heute
                    $e['revert'][$side][$f] = $f === 'last_done' ? $day($v) : self::addDays($v, $shift);
                }
            }
            $entries[$i] = $e;
        }
        return $entries;
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
