<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\HttpStatusException;
use Energietracker\Support\Dates;

/**
 * v3.1.0 (Paket H, B5) — Fristen und Termine aus einer Quelle.
 *
 * Bis v3.0 setzte das Dashboard „Zu tun" im Browser zusammen: Termine,
 * Empfehlungen der Kategorien Vertrag und Bestand, fällige Ablesungen. Den
 * Kalender (MKT-09) und Home Assistant (API-33) hätte das ein zweites und
 * drittes Mal gebraucht. Jetzt liefert dieser Dienst die Ereignisse einmal:
 *
 *   reminder             Termin (Wartung, Eichfrist …), Datum `next_due`
 *   reading_due          Ablesung fällig: letzte Ablesung + `alert_days_since_reading`
 *   cancel_by            Kündigungsstichtag eines laufenden Vertrags
 *   term_end             festes Vertragsende
 *   price_guarantee_end  Ende der Preisgarantie
 *   price_increase       gepflegte Preiserhöhung (Sonderkündigung)
 *   tank_reorder         Heizöl/Pellets: Bestand niedrig (Empfehlung R5)
 *   tenancy_statement_due  v3.1.0 (F1008) — Nebenkostenabrechnung des letzten
 *                        abgelaufenen Zeitraums ist spätestens 12 Monate nach
 *                        dessen Ende fällig (§ 556 Abs. 3 BGB)
 *   objection_deadline   v3.1.0 — Einwände gegen eine Abrechnung bis 12 Monate
 *                        nach Zugang
 *   co2_claim_deadline   v3.1.0 (H5) — Etagenheizung zur Miete: Vermieteranteil
 *                        der CO₂-Kosten bis 12 Monate nach Zugang der
 *                        Gasrechnung einfordern (CO2KostAufG § 6 Abs. 2)
 *   bill_expected        v3.5.0 (F1029) — Abrechnungszeitraum eines Vertrags
 *                        beendet, keine Versorgerrechnung erfasst
 *                        (BillExpectedService); nach der gesetzlichen Frist
 *                        „überfällig“ (EnWG § 40c Abs. 2, Strom/Gas in DE)
 *
 * v3.5.0 (F1028) — Jedes Ereignis trägt `assignee`, `assignee_id` und
 * `assignee_former` (bei Terminen die Zuständigkeit, sonst null/false).
 *
 * Jedes Ereignis: {uid, kind, date, title, title_key, params, severity,
 * due_now, href, ref, occurrence, can_mark_done, done}.
 * `uid` bleibt über Zeit stabil (der Kalender aktualisiert so dasselbe
 * Ereignis), `due_now` sind die Einträge, die das Dashboard unter „Zu tun"
 * zeigt — nach denselben Regeln wie bis v3.0 (Termin fällig/überfällig,
 * Ablesung länger her als eingestellt, Vertrags- und Bestandsempfehlung nicht
 * ausgeblendet). `severity`: overdue, urgent, due, upcoming.
 *
 * v3.4.0 (F1024) — Erledigt mit Verlauf:
 *   - `occurrence` (uid@anker) benennt das Vorkommen, für das ein Haken gilt
 *     (ActivityService); `can_mark_done` sagt, ob man es von Hand abhaken
 *     kann; `done` ist null oder {auto, on, by, activity_id, note, reason}.
 *     Erledigtes ist nie `due_now`.
 *   - Hat ein Vertrag einen lückenlosen Folgevertrag (contractStatus →
 *     `successor`), sind seine Ereignisse von selbst erledigt — aus den Daten,
 *     also auch nach einem Backup-Import, in der Demo oder nach dem Ingest.
 *   - Weiterlaufende und unbefristete Verträge sind jederzeit kündbar; ihr
 *     „letzter Tag für die Kündigung" war immer heute und wanderte täglich
 *     durch den Kalender. Sie haben kein datiertes `cancel_by` mehr.
 */
final class AgendaService
{
    public const KINDS = ['reminder', 'reading_due', 'cancel_by', 'term_end', 'price_guarantee_end',
                          'price_increase', 'tank_reorder', 'tenancy_statement_due', 'objection_deadline',
                          'co2_claim_deadline', 'bill_expected'];

    /** v3.4.0 (F1024) — Verlauf (optional; ohne ihn gibt es nur die automatische Erledigung aus den Daten). */
    private ?ActivityService $activity = null;
    /** v3.5.0 (F1029) — erwartete Abrechnungen (optional; ohne ihn keine `bill_expected`). */
    private ?BillExpectedService $billsExpected = null;

    public function attachBillExpectations(BillExpectedService $billsExpected): void
    {
        $this->billsExpected = $billsExpected;
    }

    public function __construct(
        private SettingsService $settings,
        private MeterService $meters,
        private ReadingService $readings,
        private ContractService $contracts,
        private ConsumptionService $consumption,
        private ReminderService $reminders,
        private RecommendationService $recommendations,
        private I18nService $i18n,
        private ?TenancyService $tenancies = null,   // v3.1.0 (H3)
        private ?Co2SplitService $co2Split = null,   // v3.1.0 (H5)
    ) {}

    public function attachActivity(ActivityService $activity): void
    {
        $this->activity = $activity;
    }

    /**
     * v3.4.0 (F1024) — UID eines Ereignisses `<art>-<teil>-<teil>` (Verträge,
     * Preiserhöhung, Tank). Eine Stelle für Agenda, Empfehlungen und Verlauf.
     */
    public static function uid(string $kind, string ...$parts): string
    {
        return $kind . '-' . implode('-', $parts);
    }

    /**
     * Ereignisse von heute bis `$days` Tage voraus, dazu alles Überfällige.
     *
     * @return list<array<string,mixed>>
     */
    public function events(int $days = 90, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $days = max(0, min(730, $days));
        $until = date('Y-m-d', (int)strtotime("$today +$days days"));
        $recs = $this->recommendations->all();
        $recIds = array_flip(array_column($recs, 'id'));
        $events = [];

        // ── Termine ──
        foreach ($this->reminders->listWithStatus() as $r) {
            $date = (string)($r['next_due'] ?? '');
            if ($date === '' || $date > $until) continue;
            $status = (string)($r['status'] ?? 'ok');
            $due = in_array($status, ['due', 'overdue'], true);
            $events[] = $this->event('reminder', 'reminder-' . $r['id'], $date, 'calendar.event.reminder',
                ['title' => (string)($r['title'] ?? '')],
                $status === 'overdue' ? 'overdue' : ($due ? 'due' : 'upcoming'), $due, '#/reminders',
                ['reminder_id' => (string)$r['id'], 'category' => (string)($r['category'] ?? ''),
                 'days_until' => $r['days_until'] ?? null], $date, null, $r);
        }

        // ── fällige Ablesungen (kumulative Zähler in Betrieb) ──
        $alertDays = max(1, (int)$this->settings->get('alert_days_since_reading', 45));
        foreach ($this->readings->overview($this->activeUtilities()) as $row) {
            // v3.1.0 (H3) — Zähler mit Verbrauch je Zeitraum: Ende des letzten Zeitraums
            $last = ($row['capture'] ?? 'counter') === 'period'
                ? ($row['last_period']['to'] ?? null)
                : ($row['last_reading']['date'] ?? null);
            $date = $last !== null ? date('Y-m-d', (int)strtotime("$last +$alertDays days")) : $today;
            $since = $last !== null ? (int)round((strtotime($today) - strtotime($last)) / 86400) : null;
            $due = $since === null || $since > $alertDays;
            if (!$due && $date > $until) continue;
            $events[] = $this->event('reading_due', "reading-{$row['utility']}-{$row['meter_id']}", $due ? $today : $date,
                'calendar.event.readingDue', ['label' => $row['utility_label'], 'meter' => $row['meter_name']],
                $due ? 'due' : 'upcoming', $due, '#/zaehlerstaende?meter=' . rawurlencode((string)$row['meter_id']),
                ['utility' => $row['utility'], 'meter_id' => $row['meter_id'], 'last_reading' => $last,
                 'days_since_reading' => $since, 'icon' => (string)($row['utility_icon'] ?? '')], $last ?? 'none');
        }

        // ── Verträge: Kündigungsstichtag, Vertragsende, Preisgarantie, Preiserhöhung ──
        foreach ($this->activeUtilities() as $u) {
            if (!Utilities::hasContracts($u)) continue;
            $label = $this->i18n->utilityLabel($u);
            // v3.1.0 (H6, #17) — Zähler in Betrieb und Gruppen mit Gruppenvertrag
            foreach ($this->contracts->targets($u) as $meter) {
                if (!MeterService::inService($meter)) continue;
                try {
                    $status = $this->consumption->contractStatus($u, $meter);
                } catch (\Throwable) {
                    continue;
                }
                $raw = [];
                foreach ($this->contracts->list($u, (string)$meter['id']) as $c) $raw[(string)($c['id'] ?? '')] = $c;
                foreach ($status['contracts'] ?? [] as $c) {
                    if (empty($c['is_current']) && empty($c['is_future'])) continue;
                    $cid = (string)($c['contract_id'] ?? '');
                    $provider = (string)(($c['provider'] ?? '') ?: ($c['tariff_name'] ?? '') ?: '—');
                    $params = ['label' => $label, 'provider' => $provider];
                    $ref = ['utility' => $u, 'meter_id' => (string)$meter['id'], 'contract_id' => $cid];
                    $href = '#/utility/' . rawurlencode($u) . '/contracts';
                    // „jetzt" wie Empfehlung R6 (gleiche Stufen, Ausblenden gilt)
                    $recId = RecommendationService::ruleId('r6', [$u, (string)$meter['id'], $cid]);
                    $remind = isset($recIds[$recId]);
                    if ($remind) $ref['recommendation_id'] = $recId;
                    $basis = (string)($c['remind_basis'] ?? '');
                    // v3.4.0 (F1024) — Anker des Vorkommens und Folgevertrag
                    $anchor = ActivityService::contractAnchor($c);
                    $successor = is_array($c['successor'] ?? null) ? $c['successor'] : null;
                    if ($successor !== null) $ref['successor'] = $successor;
                    $auto = $successor !== null && (int)$successor['gap_days'] === 0
                        ? $this->successorDone($successor, ActivityService::occurrence("contract-$cid", $anchor)) : null;
                    $renewed = !empty($c['renewed']);

                    // v3.4.0 (F1024) — nur ein datierter Stichtag (s. Klassenkommentar)
                    $cancel = ContractService::hasFixedCancelBy($raw[$cid] ?? [], $renewed) ? ($c['cancel_by'] ?? null) : null;
                    if ($cancel !== null && $cancel >= $today && ($cancel <= $until || ($remind && $basis === 'cancel_by'))) {
                        $due = $remind && $basis === 'cancel_by';
                        $events[] = $this->event('cancel_by', self::uid('cancel_by', $cid), (string)$cancel, 'calendar.event.cancelBy', $params,
                            $due ? ((int)($c['days_to_cancel'] ?? 99) <= 14 ? 'urgent' : 'due') : 'upcoming', $due, $href,
                            $ref + ['days' => $c['days_to_cancel'] ?? null], $anchor, $auto);
                    }
                    $end = $c['end'] ?? null;
                    if ($end !== null && !$renewed && $end >= $today && ($end <= $until || ($remind && $basis === 'end'))) {
                        $due = $remind && $basis === 'end';
                        $events[] = $this->event('term_end', self::uid('term_end', $cid), (string)$end, 'calendar.event.termEnd', $params,
                            $due ? ((int)($c['days_until_end'] ?? 99) <= 14 ? 'urgent' : 'due') : 'upcoming', $due, $href,
                            $ref + ['days' => $c['days_until_end'] ?? null], $anchor, $auto);
                    }
                    $guarantee = $raw[$cid]['price_guarantee_until'] ?? null;
                    if (is_string($guarantee) && $guarantee >= $today && $guarantee <= $until) {
                        $events[] = $this->event('price_guarantee_end', self::uid('price_guarantee_end', $cid), $guarantee,
                            'calendar.event.priceGuaranteeEnd', $params, 'upcoming', false, $href, $ref, $anchor, $auto);
                    }
                    $increase = $c['price_increase']['from'] ?? null;
                    if (is_string($increase) && $increase >= $today && $increase <= $until) {
                        // v3.1.0 (H5, MKT-24) — „jetzt", solange die Empfehlung (Sonderkündigung prüfen) steht
                        $incId = RecommendationService::ruleId('r_price_increase', [$u, (string)$meter['id'], $cid, $increase]);
                        $incDue = isset($recIds[$incId]);
                        $events[] = $this->event('price_increase', self::uid('price_increase', $cid, $increase), $increase,
                            'calendar.event.priceIncrease', $params, $incDue ? 'due' : 'upcoming', $incDue, $href,
                            $ref + ($incDue ? ['recommendation_id' => $incId] : []), $anchor, $auto);
                    }
                }
            }
        }

        // ── Heizöl/Pellets: Bestand niedrig (Empfehlung R5, Ausblenden gilt) ──
        foreach ($recs as $r) {
            if (($r['category'] ?? '') !== 'bestand') continue;
            $u = (string)($r['evidence']['utility'] ?? '');
            $m = (string)($r['evidence']['meter_id'] ?? '');
            $meterName = $m;
            foreach ($u !== '' && Utilities::exists($u) ? $this->meters->list($u) : [] as $x) {
                if (($x['id'] ?? '') === $m) $meterName = (string)($x['name'] ?? $m);
            }
            $events[] = $this->event('tank_reorder', self::uid('tank_reorder', $u, $m), $today, 'calendar.event.tankReorder',
                ['label' => $u !== '' && Utilities::exists($u) ? $this->i18n->utilityLabel($u) : '', 'meter' => $meterName],
                $r['severity'] === 'urgent' ? 'urgent' : 'due', true,
                '#/utility/' . rawurlencode($u) . '?add=delivery',
                ['utility' => $u, 'meter_id' => $m, 'recommendation_id' => (string)$r['id']],
                // v3.4.0 (F1024) — Vorkommen: seit der letzten Lieferung
                (string)($r['evidence']['anchor'] ?? 'none'));
        }

        // ── v3.5.0 (F1029) — Abrechnung erwartet: Zeitraum beendet, keine Versorgerrechnung ──
        // Fällig ab dem Ende des Zeitraums; nach der gesetzlichen Frist überfällig
        // (Datum = Frist), ohne Frist bleibt es bei „erwartet“ (Datum = Tag nach dem Ende).
        // Mit erfasster Rechnung entfällt das Ereignis (aus den Daten).
        foreach ($this->billsExpected?->open($today) ?? [] as $x) {
            $params = BillExpectedService::params($x, $this->i18n);
            $titleKey = BillExpectedService::titleKey($x);
            $ev = $this->event('bill_expected', (string)$x['uid'],
                (string)($x['deadline'] ?? date('Y-m-d', (int)strtotime($x['until'] . ' +1 day'))),
                $titleKey, $params, !empty($x['overdue']) ? 'overdue' : 'due', true, BillExpectedService::href($x),
                ['utility' => (string)$x['utility'], 'meter_id' => (string)$x['target_id'], 'contract_id' => (string)$x['contract_id'],
                 'period_from' => (string)$x['from'], 'period_until' => (string)$x['until'], 'final' => (bool)$x['final'],
                 'deadline' => $x['deadline'], 'overdue' => (bool)$x['overdue']],
                (string)$x['until']);
            // Tage im Titel in der Schreibweise der Sprache (params bleiben JJJJ-MM-TT)
            $ev['title'] = $this->i18n->t($titleKey, array_map(fn($v) => is_string($v) && Dates::isIsoDate($v) ? $this->i18n->date($v) : $v, $params));
            $events[] = $ev;
        }

        // ── v3.1.0 (H3, F1008) — Fristen der Nebenkostenabrechnung ──
        if ($this->tenancies !== null && $this->settings->get('wohnverhaeltnis', 'eigentum') === 'miete') {
            foreach ($this->tenancies->list() as $t) {
                $label = (string)(($t['label'] ?? '') !== '' ? $t['label'] : '—');
                $statements = $this->tenancies->statements((string)$t['id']);
                $lastEnd = self::openStatementPeriod($t, $statements, $today);
                if ($lastEnd !== null) {
                    $deadline = date('Y-m-d', (int)strtotime("$lastEnd +1 year"));
                    if ($deadline <= $until) {
                        $events[] = $this->event('tenancy_statement_due', "tenancy_statement_due-{$t['id']}-$lastEnd", $deadline,
                            'calendar.event.tenancyStatementDue', ['label' => $label, 'period' => $this->i18n->date($lastEnd)],
                            $deadline < $today ? 'overdue' : 'upcoming', false, '#/tenancy',
                            ['tenancy_id' => (string)$t['id'], 'period_to' => $lastEnd], $lastEnd);
                    }
                }
                foreach ($statements as $s) {
                    $rec = $s['received_on'] ?? null;
                    if (!is_string($rec) || $rec === '') continue;
                    $deadline = date('Y-m-d', (int)strtotime("$rec +1 year"));
                    if ($deadline < $today || $deadline > $until) continue;
                    $days = (int)round((strtotime($deadline) - strtotime($today)) / 86400);
                    $events[] = $this->event('objection_deadline', "objection_deadline-{$s['id']}", $deadline,
                        'calendar.event.objectionDeadline', ['label' => $label],
                        $days <= 30 ? 'due' : 'upcoming', $days <= 30, '#/tenancy',
                        ['tenancy_id' => (string)$t['id'], 'statement_id' => (string)$s['id'], 'days' => $days], $deadline);
                }
            }
        }

        // ── v3.1.0 (H5, MKT-15) — Etagenheizung: Erstattung der CO₂-Kosten einfordern ──
        if ($this->co2Split !== null && $this->settings->get('wohnverhaeltnis', 'eigentum') === 'miete') {
            $y = (int)substr($today, 0, 4);
            foreach ([$y - 2, $y - 1, $y] as $year) {
                $deadline = $this->co2Split->claimDeadline($year);
                if ($deadline === null || $deadline < $today || $deadline > $until) continue;
                $r = $this->co2Split->forYear($year);
                if (($r['case'] ?? null) !== 'self_supplied' || (float)($r['landlord_amount_eur'] ?? 0) <= 0) continue;
                $days = (int)round((strtotime($deadline) - strtotime($today)) / 86400);
                $events[] = $this->event('co2_claim_deadline', "co2_claim_deadline-$year", $deadline,
                    'calendar.event.co2ClaimDeadline', ['year' => $year, 'amount' => $this->i18n->money((float)$r['landlord_amount_eur'])],
                    $days <= 30 ? 'due' : 'upcoming', $days <= 30, '#/tenancy',
                    ['year' => $year, 'landlord_amount_eur' => (float)$r['landlord_amount_eur'], 'days' => $days], $deadline);
            }
        }

        usort($events, fn($a, $b) => [$a['date'], $a['kind'], $a['uid']] <=> [$b['date'], $b['kind'], $b['uid']]);
        return $events;
    }

    /**
     * v3.4.0 (F1024) — Hinweis von Hand abhaken (`POST /api/agenda/done`).
     * Nur Arten aus ActivityService::MANUAL_KINDS (sonst 422); ein Termin
     * läuft über ReminderService::markDone (nächster Termin, wie bisher). Bei
     * `cancel_by` setzt `cancelled: true` den Vertrag auf „verlängert sich
     * nicht" (`auto_renews: false`) — Rückgängig stellt das zurück.
     *
     * @param array<string,mixed> $in {uid, occurrence?, done_on?, note?, attachment_id?, cancelled?}
     * @return array{activity_id:?string, entry:?array, event:array, duplicate?:bool}
     */
    public function markDone(array $in): array
    {
        if ($this->activity === null) throw new \LogicException('activity log not attached');
        $uid = is_scalar($in['uid'] ?? null) ? (string)$in['uid'] : '';
        $event = null;
        foreach ($uid === '' ? [] : $this->events(730) as $e) {
            if ($e['uid'] === $uid) { $event = $e; break; }
        }
        if ($event === null) throw new HttpStatusException(404, 'errors.activity.eventNotFound', ['uid' => mb_substr($uid, 0, 80)]);
        if (!in_array($event['kind'], ActivityService::MANUAL_KINDS, true)) {
            throw new HttpStatusException(422, 'errors.activity.notManual');
        }
        $occurrence = isset($in['occurrence']) && $in['occurrence'] !== '' ? (string)$in['occurrence'] : $event['occurrence'];
        if ($occurrence !== $event['occurrence']) throw new HttpStatusException(409, 'errors.activity.occurrenceChanged');
        if ($event['done'] !== null) {
            // schon erledigt (zweiter Klick, anderes Gerät): derselbe Stand, kein zweiter Eintrag
            $id = $event['done']['activity_id'] ?? null;
            return ['activity_id' => $id, 'entry' => $id !== null ? $this->activity->get($id) : null, 'event' => $event, 'duplicate' => true];
        }
        [$doneOn, $note, $attachment] = $this->activity->checkInput($in);

        if ($event['kind'] === 'reminder') {
            $r = $this->reminders->markDone((string)$event['ref']['reminder_id'], $doneOn, $note, $attachment);
            $entry = isset($r['activity_id']) ? $this->activity->get((string)$r['activity_id']) : null;
            return ['activity_id' => $entry['id'] ?? null, 'entry' => $entry, 'event' => self::markedDone($event, $entry)];
        }

        $revert = null;
        if ($event['kind'] === 'cancel_by' && !empty($in['cancelled'])) {
            $u = (string)$event['ref']['utility'];
            $cid = (string)$event['ref']['contract_id'];
            $c = $this->contracts->get($u, $cid);
            if ($c !== null && ($c['auto_renews'] ?? null) !== false) {
                $this->contracts->update($u, $cid, ['auto_renews' => false]);
                $revert = ['type' => 'contract_auto_renews', 'utility' => $u, 'id' => $cid,
                           'before' => $c['auto_renews'] ?? null, 'after' => false];
            }
        }
        $ref = array_filter($event['ref'], fn($v, $k) => is_scalar($v) && !in_array($k, ['days', 'days_until', 'icon'], true), ARRAY_FILTER_USE_BOTH);
        $entry = $this->activity->record([
            'action'        => 'done',
            'subject'       => ['kind' => $event['kind'], 'uid' => $event['uid'], 'occurrence' => $event['occurrence']],
            'ref'           => $ref + ($revert !== null ? ['cancelled' => true] : []),
            'title_key'     => $event['title_key'],
            'params'        => $event['params'],
            'done_on'       => $doneOn,
            'note'          => $note,
            'attachment_id' => $attachment,
            'revert'        => $revert,
            // v3.5.0 (F1029) — von Hand abgehakt heißt: kam ohne Erfassung
            'reason'        => $event['kind'] === 'bill_expected' ? ['key' => 'activity.reason.billWithout', 'params' => []] : null,
        ]);
        return ['activity_id' => $entry['id'], 'entry' => $entry, 'event' => self::markedDone($event, $entry)];
    }

    /**
     * v3.1.0 (F1008) — Abrechnungszeitraum, dessen Nebenkostenabrechnung noch
     * aussteht: Ende des letzten abgelaufenen Zeitraums innerhalb des
     * Mietverhältnisses, wenn keine Abrechnung ihn abdeckt; sonst null.
     * v3.4.0 (F1024) — eine Stelle für Agenda und Verlauf.
     *
     * @param array<string,mixed>       $tenancy
     * @param list<array<string,mixed>> $statements
     */
    public static function openStatementPeriod(array $tenancy, array $statements, string $today): ?string
    {
        [$curFrom] = TenancyBudgetService::periodAround((string)($tenancy['billing_anchor'] ?? '01-01'), $today);
        $lastEnd = date('Y-m-d', (int)strtotime("$curFrom -1 day"));
        if ($lastEnd < (string)($tenancy['start'] ?? '9999') || (!empty($tenancy['end']) && (string)$tenancy['end'] < $lastEnd)) return null;
        $min = date('Y-m-d', (int)strtotime("$lastEnd -31 days"));
        foreach ($statements as $s) if ((string)($s['period_to'] ?? '') >= $min) return null;
        return $lastEnd;
    }

    /**
     * @param array<string,scalar> $params
     * @param array<string,mixed>  $ref
     * @param string               $anchor Anker des Vorkommens (s. ActivityService)
     * @param array<string,mixed>|null $autoDone aus den Daten erledigt (Folgevertrag)
     * @param array<string,mixed>|null $assignee v3.5.0 (F1028) — Termin mit Zuständigkeit (ReminderService::withAssignee)
     * @return array<string,mixed>
     */
    private function event(string $kind, string $uid, string $date, string $titleKey, array $params,
                           string $severity, bool $dueNow, string $href, array $ref,
                           string $anchor = '', ?array $autoDone = null, ?array $assignee = null): array
    {
        $occurrence = ActivityService::occurrence($uid, $anchor !== '' ? $anchor : $date);
        $done = $autoDone ?? $this->doneOf($occurrence);
        return [
            'uid'       => $uid,
            'kind'      => $kind,
            'date'      => $date,
            'title'     => $this->i18n->t($titleKey, $params),
            'title_key' => $titleKey,
            'params'    => $params,
            // Erledigtes drängt nicht mehr
            'severity'  => $done !== null ? 'upcoming' : $severity,
            'due_now'   => $done === null && $dueNow,
            'href'      => $href,
            'ref'       => $ref,
            // v3.4.0 (F1024)
            'occurrence'    => $occurrence,
            'can_mark_done' => $done === null && in_array($kind, ActivityService::MANUAL_KINDS, true),
            'done'          => $done,
            // v3.5.0 (F1028) — Zuständigkeit (nur Termine haben eine)
            'assignee'        => is_string($assignee['assignee'] ?? null) ? $assignee['assignee'] : null,
            'assignee_id'     => is_string($assignee['assignee_id'] ?? null) ? $assignee['assignee_id'] : null,
            'assignee_former' => !empty($assignee['assignee_former']),
        ];
    }

    /** Erledigt-Vermerk aus dem Verlauf (von Hand oder automatisch), sonst null. */
    private function doneOf(string $occurrence): ?array
    {
        $e = $this->activity?->doneEntry($occurrence);
        return $e === null ? null : self::doneFromEntry($e);
    }

    /** @param array<string,mixed> $e */
    private static function doneFromEntry(array $e): array
    {
        return array_filter([
            'auto'        => ($e['action'] ?? '') === 'auto_done',
            'on'          => $e['done_on'] ?? null,
            'by'          => $e['by'] ?? null,
            'activity_id' => $e['id'] ?? null,
            'note'        => ($e['note'] ?? '') !== '' ? $e['note'] : null,
            'reason'      => $e['reason'] ?? null,
        ], fn($v) => $v !== null);
    }

    /**
     * Lückenloser Folgevertrag: erledigt aus den Daten. Gibt es dazu einen
     * Eintrag im Verlauf (wann, von wem), kommt er dazu.
     *
     * @param array{contract_id:string, provider:string, start:string, gap_days:int} $successor
     */
    private function successorDone(array $successor, string $occurrence): array
    {
        $reason = ['key' => 'activity.reason.successor',
                   'params' => ['provider' => (string)$successor['provider'], 'date' => (string)$successor['start']]];
        $log = $this->activity?->doneEntry($occurrence);
        return ['auto' => true, 'reason' => $reason, 'successor_id' => (string)$successor['contract_id']]
            + ($log !== null ? array_intersect_key(self::doneFromEntry($log), array_flip(['on', 'by', 'activity_id'])) : []);
    }

    /** Ereignis nach dem Abhaken (ohne die Agenda neu zu rechnen). */
    private static function markedDone(array $event, ?array $entry): array
    {
        $event['due_now'] = false;
        $event['severity'] = 'upcoming';
        $event['can_mark_done'] = false;
        $event['done'] = $entry !== null ? self::doneFromEntry($entry) : ['auto' => false];
        return $event;
    }

    /** @return list<string> */
    private function activeUtilities(): array
    {
        $a = $this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
        if (!is_array($a) || empty($a)) return Utilities::keys();
        return array_values(array_filter($a, fn($k) => is_string($k) && Utilities::exists($k)));
    }
}
