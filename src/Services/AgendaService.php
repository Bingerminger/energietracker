<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

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
 *
 * Jedes Ereignis: {uid, kind, date, title, params, severity, due_now, href, ref}.
 * `uid` bleibt über Zeit stabil (der Kalender aktualisiert so dasselbe
 * Ereignis), `due_now` sind die Einträge, die das Dashboard unter „Zu tun"
 * zeigt — nach denselben Regeln wie bis v3.0 (Termin fällig/überfällig,
 * Ablesung länger her als eingestellt, Vertrags- und Bestandsempfehlung nicht
 * ausgeblendet). `severity`: overdue, urgent, due, upcoming.
 */
final class AgendaService
{
    public const KINDS = ['reminder', 'reading_due', 'cancel_by', 'term_end', 'price_guarantee_end',
                          'price_increase', 'tank_reorder', 'tenancy_statement_due', 'objection_deadline',
                          'co2_claim_deadline'];

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
                 'days_until' => $r['days_until'] ?? null]);
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
                 'days_since_reading' => $since, 'icon' => (string)($row['utility_icon'] ?? '')]);
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

                    $cancel = $c['cancel_by'] ?? null;
                    if ($cancel !== null && $cancel >= $today && ($cancel <= $until || ($remind && $basis === 'cancel_by'))) {
                        $due = $remind && $basis === 'cancel_by';
                        $events[] = $this->event('cancel_by', "cancel_by-$cid", (string)$cancel, 'calendar.event.cancelBy', $params,
                            $due ? ((int)($c['days_to_cancel'] ?? 99) <= 14 ? 'urgent' : 'due') : 'upcoming', $due, $href,
                            $ref + ['days' => $c['days_to_cancel'] ?? null]);
                    }
                    $end = $c['end'] ?? null;
                    if ($end !== null && empty($c['renewed']) && $end >= $today && ($end <= $until || ($remind && $basis === 'end'))) {
                        $due = $remind && $basis === 'end';
                        $events[] = $this->event('term_end', "term_end-$cid", (string)$end, 'calendar.event.termEnd', $params,
                            $due ? ((int)($c['days_until_end'] ?? 99) <= 14 ? 'urgent' : 'due') : 'upcoming', $due, $href,
                            $ref + ['days' => $c['days_until_end'] ?? null]);
                    }
                    $guarantee = $raw[$cid]['price_guarantee_until'] ?? null;
                    if (is_string($guarantee) && $guarantee >= $today && $guarantee <= $until) {
                        $events[] = $this->event('price_guarantee_end', "price_guarantee_end-$cid", $guarantee,
                            'calendar.event.priceGuaranteeEnd', $params, 'upcoming', false, $href, $ref);
                    }
                    $increase = $c['price_increase']['from'] ?? null;
                    if (is_string($increase) && $increase >= $today && $increase <= $until) {
                        // v3.1.0 (H5, MKT-24) — „jetzt", solange die Empfehlung (Sonderkündigung prüfen) steht
                        $incId = RecommendationService::ruleId('r_price_increase', [$u, (string)$meter['id'], $cid, $increase]);
                        $incDue = isset($recIds[$incId]);
                        $events[] = $this->event('price_increase', "price_increase-$cid-$increase", $increase,
                            'calendar.event.priceIncrease', $params, $incDue ? 'due' : 'upcoming', $incDue, $href,
                            $ref + ($incDue ? ['recommendation_id' => $incId] : []));
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
            $events[] = $this->event('tank_reorder', "tank_reorder-$u-$m", $today, 'calendar.event.tankReorder',
                ['label' => $u !== '' && Utilities::exists($u) ? $this->i18n->utilityLabel($u) : '', 'meter' => $meterName],
                $r['severity'] === 'urgent' ? 'urgent' : 'due', true,
                '#/utility/' . rawurlencode($u) . '?add=delivery',
                ['utility' => $u, 'meter_id' => $m, 'recommendation_id' => (string)$r['id']]);
        }

        // ── v3.1.0 (H3, F1008) — Fristen der Nebenkostenabrechnung ──
        if ($this->tenancies !== null && $this->settings->get('wohnverhaeltnis', 'eigentum') === 'miete') {
            foreach ($this->tenancies->list() as $t) {
                $label = (string)(($t['label'] ?? '') !== '' ? $t['label'] : '—');
                $statements = $this->tenancies->statements((string)$t['id']);
                // letzter abgelaufener Abrechnungszeitraum innerhalb des Mietverhältnisses
                [$curFrom] = TenancyBudgetService::periodAround((string)($t['billing_anchor'] ?? '01-01'), $today);
                $lastEnd = date('Y-m-d', (int)strtotime("$curFrom -1 day"));
                if ($lastEnd >= (string)$t['start'] && (empty($t['end']) || (string)$t['end'] >= $lastEnd)) {
                    $have = false;
                    foreach ($statements as $s) if ((string)$s['period_to'] >= date('Y-m-d', (int)strtotime("$lastEnd -31 days"))) $have = true;
                    $deadline = date('Y-m-d', (int)strtotime("$lastEnd +1 year"));
                    if (!$have && $deadline <= $until) {
                        $events[] = $this->event('tenancy_statement_due', "tenancy_statement_due-{$t['id']}-$lastEnd", $deadline,
                            'calendar.event.tenancyStatementDue', ['label' => $label, 'period' => $this->i18n->date($lastEnd)],
                            $deadline < $today ? 'overdue' : 'upcoming', false, '#/tenancy',
                            ['tenancy_id' => (string)$t['id'], 'period_to' => $lastEnd]);
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
                        ['tenancy_id' => (string)$t['id'], 'statement_id' => (string)$s['id'], 'days' => $days]);
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
                    ['year' => $year, 'landlord_amount_eur' => (float)$r['landlord_amount_eur'], 'days' => $days]);
            }
        }

        usort($events, fn($a, $b) => [$a['date'], $a['kind'], $a['uid']] <=> [$b['date'], $b['kind'], $b['uid']]);
        return $events;
    }

    /**
     * @param array<string,scalar> $params
     * @param array<string,mixed>  $ref
     * @return array<string,mixed>
     */
    private function event(string $kind, string $uid, string $date, string $titleKey, array $params,
                           string $severity, bool $dueNow, string $href, array $ref): array
    {
        return [
            'uid'      => $uid,
            'kind'     => $kind,
            'date'     => $date,
            'title'    => $this->i18n->t($titleKey, $params),
            'params'   => $params,
            'severity' => $severity,
            'due_now'  => $dueNow,
            'href'     => $href,
            'ref'      => $ref,
        ];
    }

    /** @return list<string> */
    private function activeUtilities(): array
    {
        $a = $this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
        if (!is_array($a) || empty($a)) return Utilities::keys();
        return array_values(array_filter($a, fn($k) => is_string($k) && Utilities::exists($k)));
    }
}
