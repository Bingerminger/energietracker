<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\HttpStatusException;
use Energietracker\Http\NotFoundException;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;

/**
 * v3.4.0 (F1024) — „Erledigt mit Verlauf": wer hat einen Hinweis wann erledigt.
 *
 * Topf `activity.json` (Schlüssel `activity` im Backup), eine Liste. Kein
 * Schemaschritt (Vorbild `ev_sessions`): Die Datei entsteht beim ersten
 * Eintrag, fehlt sie, gilt []. Ein Eintrag:
 *
 *   id           act_<12 hex>
 *   action       done (von Hand) | auto_done (aus den Daten) | dismissed
 *                (Empfehlung ausgeblendet)
 *   subject      {kind, uid, occurrence} — Art und UID wie in der Agenda;
 *                occurrence = uid@anker, damit ein Haken nur für dieses
 *                Vorkommen gilt (Vertrag: Ende bzw. renewed/open, Tank: letzte
 *                Lieferung, Termin: next_due, Fristen: ihr Datum). Ändert sich
 *                die Quelle (etwa das Vertragsende), ist der Hinweis wieder offen.
 *   ref          {utility, meter_id, contract_id | reminder_id | statement_id …}
 *   title_key    Katalogschlüssel + `params` — in jeder Sprache neu darstellbar
 *   title        Momentaufnahme in der Sprache beim Erledigen
 *   done_on      Tag der Tat (rückdatierbar), `recorded_at` Zeitpunkt des Eintrags
 *   by           {type: person, id, name} | {type: key, name} |
 *                {type: integration, name} | {type: auto, reason}; ohne Anmeldung null
 *   reason       {key, params} — bei auto_done: warum
 *   note, attachment_id   optional
 *   assignee     v3.5.0 (F1028) — beim Termin: wer zuständig war, als
 *                Momentaufnahme {name, id?} bzw. {former: true}
 *   undone       {at, by} — Rückgängig streicht durch, statt zu löschen
 *   revert       was Rückgängig zurückstellt (Termin, Kündigung, Ausblenden)
 *
 * v3.5.0 — `updated` (geänderte Nebenkostenabrechnung) steht nur zum Nachweis
 * im Verlauf und erledigt keinen Hinweis; `bill_expected` (F1029) erledigt
 * sich mit der erfassten Versorgerrechnung (billRecorded) oder von Hand.
 *
 * Namen stehen als Momentaufnahme im Eintrag und damit im Backup; die
 * Personenliste selbst bleibt in auth.json. Beim Löschen einer Person kann der
 * Verwalter sie durch „ehemalige Person" ersetzen lassen (anonymize()).
 *
 * Geschrieben wird wie überall über den JsonStore — also unter der
 * Schreibsperre der Anfrage (App::lockOrFail()).
 */
final class ActivityService
{
    public const FILE = 'activity.json';
    /** v3.5.0 — `updated`: eine Änderung zum Nachweis (geänderte Nebenkostenabrechnung), erledigt nichts. */
    public const ACTIONS = ['done', 'auto_done', 'dismissed', 'updated'];
    /** Agenda-Arten, die man von Hand abhaken kann (`can_mark_done`); v3.5.0 (F1029) `bill_expected`. */
    public const MANUAL_KINDS = ['reminder', 'cancel_by', 'term_end', 'price_increase', 'tank_reorder', 'bill_expected',
                                 'objection_deadline', 'co2_claim_deadline'];
    public const ID = '/^act_[0-9a-f]{12}$/';
    public const NOTE_MAX = 500;
    public const LIMIT_DEFAULT = 500;
    public const LIMIT_MAX = 5000;
    /** Personen-, Schlüssel- und Integrationsnamen im Eintrag (wie AuthService). */
    private const NAME_MAX = 60;

    /** @var (callable(): ?array)|null Person bzw. Schlüssel dieser Anfrage (App::actor) */
    private $actor;
    /** Integration, die diese Anfrage stellt (Ingest, evcc) — geht der Anmeldung vor. */
    private ?array $actAs = null;
    private ?ReminderService $reminders = null;
    private ?ContractService $contracts = null;
    private ?RecommendationService $recommendations = null;
    /** @var (callable(string, string): bool)|null Bestand eines Tanks niedrig? (Empfehlung R5) */
    private $tankProbe = null;
    /** @var array<string,array<string,mixed>>|null occurrence → letzter offener Erledigt-Eintrag */
    private ?array $doneIndex = null;
    private int $indexGen = -1;

    /** @param (callable(): ?array)|null $actor */
    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
        private I18nService $i18n,
        ?callable $actor = null,
    ) {
        $this->actor = $actor;
    }

    /** Dienste, deren Zustand Rückgängig zurückstellt (nachträglich, sie kennen den Verlauf ihrerseits). */
    public function attach(?ReminderService $reminders = null, ?ContractService $contracts = null,
                           ?RecommendationService $recommendations = null): void
    {
        $this->reminders = $reminders ?? $this->reminders;
        $this->contracts = $contracts ?? $this->contracts;
        $this->recommendations = $recommendations ?? $this->recommendations;
    }

    /** @param callable(string, string): bool $probe */
    public function setTankProbe(callable $probe): void
    {
        $this->tankProbe = $probe;
    }

    // ── Wer ──────────────────────────────────────────────────────────────

    /** Für diese Anfrage handelt eine Integration (Home Assistant, evcc). */
    public function actAs(string $integration): void
    {
        $this->actAs = ['type' => 'integration', 'name' => mb_substr($integration, 0, self::NAME_MAX)];
    }

    /** Wer handelt: Integration, Person, API-Schlüssel — ohne Anmeldung null. */
    public function actor(): ?array
    {
        if ($this->actAs !== null) return $this->actAs;
        $a = $this->actor === null ? null : ($this->actor)();
        if (!is_array($a) || !in_array($a['type'] ?? null, ['person', 'key'], true)) return null;
        return array_filter([
            'type' => (string)$a['type'],
            'id'   => isset($a['id']) ? (string)$a['id'] : null,
            'name' => mb_substr((string)($a['name'] ?? ''), 0, self::NAME_MAX),
        ], fn($v) => $v !== null);
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $all = $this->store->read(self::FILE, []);
        return is_array($all) ? array_values(array_filter($all, fn($e) => is_array($e) && isset($e['id']))) : [];
    }

    public function get(string $id): ?array
    {
        foreach ($this->all() as $e) if ($e['id'] === $id) return $e;
        return null;
    }

    /**
     * Gefiltert, neueste zuerst. Filter: from/to (done_on, JJJJ-MM-TT), by
     * (Personen-ID oder person|key|integration|auto|none), kind (Art,
     * kommagetrennt), ref (Wert eines ref-Felds, etwa eine Vertrags-ID), limit.
     *
     * @param array<string,mixed> $f
     * @return list<array<string,mixed>>
     */
    public function list(array $f = []): array
    {
        foreach (['from', 'to'] as $k) {
            if (isset($f[$k]) && $f[$k] !== '' && !Dates::isIsoDate($f[$k])) {
                throw new HttpStatusException(400, 'errors.activity.dateInvalid', ['value' => (string)$f[$k]]);
            }
        }
        $kinds = isset($f['kind']) && $f['kind'] !== '' ? array_map('trim', explode(',', (string)$f['kind'])) : null;
        $limit = isset($f['limit']) && $f['limit'] !== '' ? (int)$f['limit'] : self::LIMIT_DEFAULT;
        $limit = max(1, min(self::LIMIT_MAX, $limit));
        $out = array_values(array_filter($this->all(), function (array $e) use ($f, $kinds): bool {
            $on = (string)($e['done_on'] ?? '');
            if (!empty($f['from']) && $on < $f['from']) return false;
            if (!empty($f['to']) && $on > $f['to']) return false;
            if ($kinds !== null && !in_array($e['subject']['kind'] ?? '', $kinds, true)) return false;
            if (!empty($f['ref']) && !in_array((string)$f['ref'], array_map('strval', array_filter((array)($e['ref'] ?? []), 'is_scalar')), true)) return false;
            if (!empty($f['by'])) {
                $by = is_array($e['by'] ?? null) ? $e['by'] : null;
                $match = $f['by'] === 'none' ? $by === null
                    : ($by !== null && (($by['id'] ?? null) === $f['by'] || ($by['type'] ?? null) === $f['by']));
                if (!$match) return false;
            }
            return true;
        }));
        usort($out, fn($a, $b) => [(string)($b['done_on'] ?? ''), (string)($b['recorded_at'] ?? '')]
                              <=> [(string)($a['done_on'] ?? ''), (string)($a['recorded_at'] ?? '')]);
        return array_slice($out, 0, $limit);
    }

    /**
     * Offener Erledigt-Eintrag (done oder auto_done, nicht zurückgenommen) zu
     * einem Vorkommen — die Agenda markiert das Ereignis damit als erledigt.
     */
    public function doneEntry(string $occurrence): ?array
    {
        if ($this->doneIndex === null || $this->indexGen !== $this->store->generation()) {
            $idx = [];
            foreach ($this->all() as $e) {
                if (!in_array($e['action'] ?? '', ['done', 'auto_done'], true) || !empty($e['undone'])) continue;
                $occ = (string)($e['subject']['occurrence'] ?? '');
                if ($occ === '') continue;
                if (!isset($idx[$occ]) || (string)$e['recorded_at'] >= (string)$idx[$occ]['recorded_at']) $idx[$occ] = $e;
            }
            $this->doneIndex = $idx;
            $this->indexGen = $this->store->generation();
        }
        return $this->doneIndex[$occurrence] ?? null;
    }

    /** Letzter offener Erledigt-Eintrag mit `ref[$field] === $value` (etwa „zuletzt erledigt" am Termin). */
    public function lastDone(string $field, string $value): ?array
    {
        $best = null;
        foreach ($this->all() as $e) {
            if (!in_array($e['action'] ?? '', ['done', 'auto_done'], true) || !empty($e['undone'])) continue;
            if ((string)($e['ref'][$field] ?? '') !== $value) continue;
            if ($best === null || [(string)$e['done_on'], (string)$e['recorded_at']] >= [(string)$best['done_on'], (string)$best['recorded_at']]) $best = $e;
        }
        return $best;
    }

    /** Vorkommensschlüssel: UID der Agenda @ Anker. */
    public static function occurrence(string $uid, string $anchor): string
    {
        return $uid . '@' . ($anchor !== '' ? $anchor : 'none');
    }

    /**
     * Anker eines Vertrags (Zeile aus contractStatus): sein Ende, bei einem
     * weiterlaufenden Vertrag `renewed`, ohne Ende `open`. Ein geändertes
     * Vertragsende öffnet den Hinweis wieder.
     */
    public static function contractAnchor(array $statusRow): string
    {
        if (!empty($statusRow['renewed'])) return 'renewed';
        return !empty($statusRow['end']) ? (string)$statusRow['end'] : 'open';
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /**
     * Neuer Eintrag. Pflicht: action, subject {kind, uid, occurrence}; dazu
     * ref, title_key + params, done_on (sonst heute), note, attachment_id,
     * reason, revert und by (sonst die handelnde Person/Integration).
     *
     * @param array<string,mixed> $e
     * @return array<string,mixed>
     */
    public function record(array $e): array
    {
        $action = (string)($e['action'] ?? 'done');
        if (!in_array($action, self::ACTIONS, true)) throw new \LogicException("activity action $action");
        [$doneOn, $note, $attachment] = $this->checkInput($e);
        $params = array_filter((array)($e['params'] ?? []), fn($v) => is_scalar($v) || $v === null);
        $titleKey = isset($e['title_key']) && is_string($e['title_key']) && $e['title_key'] !== '' ? $e['title_key'] : null;

        $entry = [
            'id'          => 'act_' . bin2hex(random_bytes(6)),
            'action'      => $action,
            'subject'     => [
                'kind'       => (string)($e['subject']['kind'] ?? ''),
                'uid'        => (string)($e['subject']['uid'] ?? ''),
                'occurrence' => (string)($e['subject']['occurrence'] ?? ''),
            ],
            'ref'         => array_filter((array)($e['ref'] ?? []), fn($v) => is_scalar($v)),
            'title_key'   => $titleKey,
            'params'      => $params,
            'title'       => $titleKey !== null ? $this->i18n->t($titleKey, $this->display($params)) : (string)($e['title'] ?? ''),
            'done_on'     => $doneOn,
            'recorded_at' => date('c'),
            'by'          => array_key_exists('by', $e) ? $e['by'] : $this->actor(),
            'note'        => $note,
        ];
        if (!empty($e['reason']['key'])) {
            $entry['reason'] = ['key' => (string)$e['reason']['key'], 'params' => array_filter((array)($e['reason']['params'] ?? []), 'is_scalar')];
            // „von selbst erledigt": der Grund steht auch an `by` ({type: auto, reason})
            if (is_array($entry['by']) && ($entry['by']['type'] ?? null) === 'auto') $entry['by']['reason'] = $entry['reason'];
        }
        if ($attachment !== null) $entry['attachment_id'] = $attachment;
        // v3.5.0 (F1028) — Zuständigkeit als Momentaufnahme
        if (is_array($e['assignee'] ?? null)) {
            $a = $e['assignee'];
            $entry['assignee'] = !empty($a['former']) ? ['former' => true] : array_filter([
                'name' => mb_substr((string)($a['name'] ?? ''), 0, self::NAME_MAX),
                'id'   => isset($a['id']) && is_scalar($a['id']) ? (string)$a['id'] : null,
            ], fn($v) => $v !== null && $v !== '');
            if ($entry['assignee'] === []) unset($entry['assignee']);
        }
        if (!empty($e['revert']) && is_array($e['revert'])) $entry['revert'] = $e['revert'];

        $all = $this->all();
        $all[] = $entry;
        [$all, $dropped] = $this->prune($all);
        $this->store->write('activity.json', array_values($all));
        $this->releaseAttachments($dropped);
        if ($attachment !== null) AttachmentService::link($this->store, $attachment, null, ['type' => 'activity', 'id' => $entry['id']]);
        return $entry;
    }

    /**
     * Rückgängig: streicht den Eintrag durch (`undone {at, by}`) und stellt
     * zurück, was er verändert hat — einen Termin, die Kündigung am Vertrag,
     * eine ausgeblendete Empfehlung —, solange niemand es inzwischen anders
     * geändert hat. `reverted` sagt, ob zurückgestellt wurde.
     *
     * @return array<string,mixed>
     */
    public function undo(string $id): array
    {
        $all = $this->all();
        $i = $this->indexOf($all, $id);
        if (!empty($all[$i]['undone'])) return $all[$i] + ['reverted' => false];
        $all[$i]['undone'] = ['at' => date('c'), 'by' => $this->actor()];
        $this->store->write('activity.json', $all);
        return $all[$i] + ['reverted' => $this->revert($all[$i]['revert'] ?? null)];
    }

    /** Notiz ändern (leer = keine). @return array<string,mixed> */
    public function setNote(string $id, mixed $note): array
    {
        $all = $this->all();
        $i = $this->indexOf($all, $id);
        $all[$i]['note'] = self::cleanNote($note);
        $this->store->write('activity.json', $all);
        return $all[$i];
    }

    /**
     * Verlauf leeren (nur Verwalter, s. App::adminOnly): alle Einträge, die vor
     * `$before` erledigt wurden — ohne Datum alle. @return int Anzahl entfernt
     */
    public function clear(?string $before = null): int
    {
        if ($before !== null && $before !== '' && !Dates::isIsoDate($before)) {
            throw new HttpStatusException(400, 'errors.activity.dateInvalid', ['value' => $before]);
        }
        $all = $this->all();
        $keep = $drop = [];
        foreach ($all as $e) {
            if ($before === null || $before === '' || (string)($e['done_on'] ?? '') < $before) $drop[] = $e; else $keep[] = $e;
        }
        if ($drop === []) return 0;
        $this->store->write('activity.json', $keep);
        $this->releaseAttachments($drop);
        return count($drop);
    }

    /**
     * Datenschutz beim Löschen einer Person: Ihr Name im Verlauf wird durch
     * „ehemalige Person" ersetzt (`former: true`, ohne ID und Namen).
     * v3.5.0 (F1028) — ebenso als Zuständige eines Termins (`assignee`).
     */
    public function anonymize(string $personId): int
    {
        $all = $this->all();
        $strip = fn(mixed $by): mixed => is_array($by) && ($by['type'] ?? null) === 'person' && ($by['id'] ?? null) === $personId
            ? ['type' => 'person', 'former' => true] : $by;
        $n = 0;
        foreach ($all as $i => $e) {
            $all[$i]['by'] = $strip($e['by'] ?? null);
            if (is_array($e['undone'] ?? null)) $all[$i]['undone']['by'] = $strip($e['undone']['by'] ?? null);
            if (is_array($e['assignee'] ?? null) && ($e['assignee']['id'] ?? null) === $personId) $all[$i]['assignee'] = ['former' => true];
            if ($all[$i] !== $e) $n++;
        }
        if ($n > 0) $this->store->write('activity.json', $all);
        return $n;
    }

    // ── Automatische Einträge (aus den Daten) ─────────────────────────────

    /**
     * Ablesung erfasst — nur wenn der Hinweis „Ablesung fällig" offen war
     * (letzte Ablesung länger her als `alert_days_since_reading` oder noch
     * keine). Sonst flutete der tägliche Home-Assistant-Ingest den Verlauf.
     *
     * v3.5.0 — `$reason` für den Zeitraum (periodRecorded); Standard: Ablesung.
     *
     * @param array<string,mixed> $meter
     * @param array{key:string, params:array<string,scalar>}|null $reason
     */
    public function readingRecorded(string $utility, array $meter, string $date, ?string $prevLast, ?array $reason = null): ?array
    {
        if (!Utilities::isCumulative($utility) || !MeterService::inService($meter) || !$this->utilityActive($utility)) return null;
        $today = date('Y-m-d');
        if (!Dates::isIsoDate($date) || $date > $today) return null;
        // nachgetragen: Der Hinweis hängt an der letzten Ablesung, nicht an dieser
        if ($prevLast !== null && $date <= $prevLast) return null;
        $alert = max(1, (int)$this->settings->get('alert_days_since_reading', 45));
        if ($prevLast !== null && (int)round((strtotime($today) - strtotime($prevLast)) / 86400) <= $alert) return null;
        $mid = (string)$meter['id'];
        $uid = "reading-$utility-$mid";
        return $this->record([
            'action'    => 'auto_done',
            'subject'   => ['kind' => 'reading_due', 'uid' => $uid, 'occurrence' => self::occurrence($uid, $prevLast ?? 'none')],
            'ref'       => ['utility' => $utility, 'meter_id' => $mid],
            'title_key' => 'calendar.event.readingDue',
            'params'    => ['label' => $this->i18n->utilityLabel($utility), 'meter' => (string)($meter['name'] ?? $mid)],
            'done_on'   => $date,
            'by'        => $this->actor() ?? ['type' => 'auto'],
            'reason'    => $reason ?? ['key' => 'activity.reason.reading', 'params' => ['date' => $date]],
        ]);
    }

    /**
     * v3.5.0 (Nachtrag F1024) — Zähler mit Verbrauch je Zeitraum: Ein neuer
     * Zeitraum erledigt „Zählerstand ablesen“ wie eine Ablesung — nur, wenn
     * der Hinweis offen war (dieselbe Regel, kein Fluten durch Importe). Der
     * Hinweis hängt am Ende des letzten Zeitraums (`$prevLast`).
     *
     * @param array<string,mixed> $meter
     * @param array<string,mixed> $period der neue Zeitraum {from, to}
     */
    public function periodRecorded(string $utility, array $meter, array $period, ?string $prevLast): ?array
    {
        $to = (string)($period['to'] ?? '');
        return $this->readingRecorded($utility, $meter, $to, $prevLast,
            ['key' => 'activity.reason.period', 'params' => ['from' => (string)($period['from'] ?? ''), 'to' => $to]]);
    }

    /** Vor dem Speichern einer Lieferung: war der Bestand niedrig (Empfehlung R5)? */
    public function tankWasLow(string $utility, string $meterId): bool
    {
        if ($this->tankProbe === null || !$this->utilityActive($utility)) return false;
        try {
            return (bool)($this->tankProbe)($utility, $meterId);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Lieferung erfasst — nur wenn der Bestand vorher niedrig war (der Hinweis
     * „Lieferung planen" also offen) und es die neueste Lieferung ist.
     *
     * @param array<string,mixed> $delivery
     */
    public function deliveryRecorded(string $utility, array $meter, array $delivery, ?string $prevLatest, bool $wasLow): ?array
    {
        $date = (string)($delivery['date'] ?? '');
        if (!$wasLow || !empty($delivery['is_planned']) || !Dates::isIsoDate($date) || $date > date('Y-m-d')) return null;
        if ($prevLatest !== null && $date < $prevLatest) return null;
        $mid = (string)$meter['id'];
        $uid = AgendaService::uid('tank_reorder', $utility, $mid);
        return $this->record([
            'action'    => 'auto_done',
            'subject'   => ['kind' => 'tank_reorder', 'uid' => $uid, 'occurrence' => self::occurrence($uid, $prevLatest ?? 'none')],
            'ref'       => ['utility' => $utility, 'meter_id' => $mid, 'delivery_id' => (string)($delivery['id'] ?? '')],
            'title_key' => 'calendar.event.tankReorder',
            'params'    => ['label' => $this->i18n->utilityLabel($utility), 'meter' => (string)($meter['name'] ?? $mid)],
            'done_on'   => $date,
            'by'        => $this->actor() ?? ['type' => 'auto'],
            'reason'    => ['key' => 'activity.reason.delivery', 'params' => ['date' => $date]],
        ]);
    }

    /**
     * Nebenkostenabrechnung erfasst — wenn sie den abgelaufenen Zeitraum
     * abdeckt, für den die Agenda „Nebenkostenabrechnung fällig" führte.
     *
     * @param array<string,mixed>       $tenancy
     * @param array<string,mixed>       $statement die neue Abrechnung
     * @param list<array<string,mixed>> $before    die Abrechnungen davor
     */
    public function statementRecorded(array $tenancy, array $statement, array $before): ?array
    {
        if ($this->settings->get('wohnverhaeltnis', 'eigentum') !== 'miete') return null;
        $today = date('Y-m-d');
        $open = AgendaService::openStatementPeriod($tenancy, $before, $today);
        if ($open === null || AgendaService::openStatementPeriod($tenancy, [...$before, $statement], $today) !== null) return null;
        $tid = (string)$tenancy['id'];
        $uid = "tenancy_statement_due-$tid-$open";
        $label = (string)(($tenancy['label'] ?? '') !== '' ? $tenancy['label'] : '—');
        $received = (string)($statement['received_on'] ?? '');
        return $this->record([
            'action'    => 'auto_done',
            'subject'   => ['kind' => 'tenancy_statement_due', 'uid' => $uid, 'occurrence' => self::occurrence($uid, $open)],
            'ref'       => ['tenancy_id' => $tid, 'statement_id' => (string)($statement['id'] ?? '')],
            'title_key' => 'calendar.event.tenancyStatementDue',
            'params'    => ['label' => $label, 'period' => $open],
            'done_on'   => Dates::isIsoDate($received) && $received <= $today ? $received : $today,
            'by'        => $this->actor() ?? ['type' => 'auto'],
            'reason'    => ['key' => 'activity.reason.statement', 'params' => ['date' => (string)($statement['period_to'] ?? '')]],
        ]);
    }

    /**
     * v3.5.0 (Nachtrag F1024) — Nebenkostenabrechnung geändert: ein Eintrag
     * „geändert“ (`updated`) zum Nachweis. Schließt erst die Änderung den
     * offenen Zeitraum (etwa ein korrigiertes Ende), ist es die Erledigung wie
     * beim Erfassen (`auto_done`) — nie beides, und nie ein zweites
     * `auto_done` für denselben Zeitraum. Öffnet die Änderung ihn wieder, wird
     * die Erledigung durch diese Abrechnung durchgestrichen.
     *
     * @param array<string,mixed>       $tenancy
     * @param array<string,mixed>       $old    die Abrechnung vorher
     * @param array<string,mixed>       $new    die Abrechnung nachher
     * @param list<array<string,mixed>> $others die übrigen Abrechnungen des Mietverhältnisses
     */
    public function statementChanged(array $tenancy, array $old, array $new, array $others): ?array
    {
        // unverändert gespeichert (Zahlen kommen aus JSON mal als 1250, mal als 1250.0): kein Eintrag
        $strip = fn(array $s) => array_diff_key($s, array_flip(['updated_at']));
        if ($strip($old) == $strip($new)) return null;
        $today = date('Y-m-d');
        $tid = (string)$tenancy['id'];
        $sid = (string)($new['id'] ?? '');
        $label = (string)(($tenancy['label'] ?? '') !== '' ? $tenancy['label'] : '—');
        $miete = $this->settings->get('wohnverhaeltnis', 'eigentum') === 'miete';
        $openBefore = $miete ? AgendaService::openStatementPeriod($tenancy, [...$others, $old], $today) : null;
        $openAfter = $miete ? AgendaService::openStatementPeriod($tenancy, [...$others, $new], $today) : null;

        if ($openBefore !== null && $openAfter === null) {
            $occ = self::occurrence("tenancy_statement_due-$tid-$openBefore", $openBefore);
            $existing = $this->doneEntry($occ);
            if ($existing === null || ($existing['action'] ?? '') !== 'auto_done') {
                $received = (string)($new['received_on'] ?? '');
                return $this->record([
                    'action'    => 'auto_done',
                    'subject'   => ['kind' => 'tenancy_statement_due', 'uid' => "tenancy_statement_due-$tid-$openBefore", 'occurrence' => $occ],
                    'ref'       => ['tenancy_id' => $tid, 'statement_id' => $sid],
                    'title_key' => 'calendar.event.tenancyStatementDue',
                    'params'    => ['label' => $label, 'period' => $openBefore],
                    'done_on'   => Dates::isIsoDate($received) && $received <= $today ? $received : $today,
                    'by'        => $this->actor() ?? ['type' => 'auto'],
                    'reason'    => ['key' => 'activity.reason.statement', 'params' => ['date' => (string)($new['period_to'] ?? '')]],
                ]);
            }
        }
        if ($openAfter !== null && $openBefore === null) {
            // die Änderung öffnet den Zeitraum wieder: Erledigung durch diese Abrechnung zurücknehmen
            $occ = self::occurrence("tenancy_statement_due-$tid-$openAfter", $openAfter);
            $all = $this->all();
            $n = 0;
            foreach ($all as $i => $e) {
                if (($e['action'] ?? '') !== 'auto_done' || !empty($e['undone']) || ($e['subject']['occurrence'] ?? '') !== $occ) continue;
                if (($e['ref']['statement_id'] ?? null) !== $sid) continue;
                $all[$i]['undone'] = ['at' => date('c'), 'by' => ['type' => 'auto']];
                $n++;
            }
            if ($n > 0) $this->store->write(self::FILE, $all);
        }
        $uid = "tenancy_statement-$sid";
        return $this->record([
            'action'    => 'updated',
            'subject'   => ['kind' => 'tenancy_statement', 'uid' => $uid, 'occurrence' => self::occurrence($uid, (string)($new['period_to'] ?? ''))],
            'ref'       => ['tenancy_id' => $tid, 'statement_id' => $sid],
            'title_key' => 'activity.changed.statement',
            'params'    => ['label' => $label, 'from' => (string)($new['period_from'] ?? ''), 'to' => (string)($new['period_to'] ?? '')],
            'done_on'   => $today,
        ]);
    }

    /**
     * Lückenloser Folgevertrag angelegt: Kündigungsstichtag, Vertragsende,
     * Preisgarantie und Preiserhöhung des Vorgängers sind erledigt. Die
     * Agenda erkennt das ohnehin aus den Daten (contractStatus → successor);
     * der Eintrag hält fest, wann und von wem.
     *
     * @param array<string,mixed> $predecessor
     * @param array{contract_id:string, provider:string, start:string, gap_days:int} $successor
     */
    public function successorRecorded(string $utility, array $predecessor, array $successor, bool $renewed): ?array
    {
        $pid = (string)$predecessor['id'];
        $uid = "contract-$pid";
        $occ = self::occurrence($uid, self::contractAnchor(['renewed' => $renewed, 'end' => $predecessor['end'] ?? null]));
        if ($this->doneEntry($occ) !== null) return null;
        return $this->record([
            'action'    => 'auto_done',
            'subject'   => ['kind' => 'contract', 'uid' => $uid, 'occurrence' => $occ],
            'ref'       => array_filter(['utility' => $utility, 'meter_id' => $predecessor['meter_id'] ?? null,
                'meter_group_id' => $predecessor['meter_group_id'] ?? null, 'contract_id' => $pid,
                'successor_id' => $successor['contract_id']], fn($v) => $v !== null && $v !== ''),
            'title_key' => 'calendar.event.termEnd',
            'params'    => ['label' => $this->i18n->utilityLabel($utility),
                            'provider' => (string)(($predecessor['provider'] ?? '') ?: ($predecessor['tariff_name'] ?? '') ?: '—')],
            'done_on'   => date('Y-m-d'),
            'by'        => $this->actor() ?? ['type' => 'auto'],
            'reason'    => ['key' => 'activity.reason.successor', 'params' => ['provider' => $successor['provider'], 'date' => $successor['start']]],
        ]);
    }

    /**
     * Folgevertrag geändert oder gelöscht: Automatische Einträge, deren
     * Vorgänger ihn nicht mehr als lückenlosen Folgevertrag hat, werden
     * durchgestrichen. `$stillValid(predecessorId)` prüft das je Eintrag.
     *
     * @param callable(string): bool $stillValid
     */
    public function successorRevoked(string $successorId, callable $stillValid): int
    {
        $all = $this->all();
        $n = 0;
        foreach ($all as $i => $e) {
            if (($e['action'] ?? '') !== 'auto_done' || !empty($e['undone']) || ($e['ref']['successor_id'] ?? null) !== $successorId) continue;
            if ($stillValid((string)($e['ref']['contract_id'] ?? ''))) continue;
            $all[$i]['undone'] = ['at' => date('c'), 'by' => ['type' => 'auto']];
            $n++;
        }
        if ($n > 0) $this->store->write('activity.json', $all);
        return $n;
    }

    /**
     * Ausblenden einer Empfehlung protokollieren (`dismissed`); Rückgängig
     * blendet wieder ein.
     *
     * @param array<string,mixed> $rec Empfehlung (id, title, evidence)
     */
    public function dismissed(array $rec, string $until): array
    {
        $id = (string)$rec['id'];
        $ev = (array)($rec['evidence'] ?? []);
        return $this->record([
            'action'  => 'dismissed',
            'subject' => ['kind' => 'recommendation', 'uid' => $id, 'occurrence' => self::occurrence($id, $until)],
            'ref'     => array_filter(['recommendation_id' => $id, 'utility' => $ev['utility'] ?? null, 'meter_id' => $ev['meter_id'] ?? null,
                'contract_id' => $ev['contract_id'] ?? null], fn($v) => is_scalar($v) && $v !== ''),
            'title'   => (string)($rec['title'] ?? $id),
            'reason'  => ['key' => 'activity.reason.dismissed', 'params' => ['date' => $until]],
            'revert'  => ['type' => 'recommendation_dismiss', 'id' => $id, 'until' => $until],
        ]);
    }

    /** Eine Empfehlung wurde wieder eingeblendet: offene Ausblende-Einträge durchstreichen. */
    public function dismissalRestored(string $recId): void
    {
        $all = $this->all();
        $n = 0;
        foreach ($all as $i => $e) {
            if (($e['action'] ?? '') !== 'dismissed' || !empty($e['undone']) || ($e['subject']['uid'] ?? null) !== $recId) continue;
            $all[$i]['undone'] = ['at' => date('c'), 'by' => $this->actor()];
            $n++;
        }
        if ($n > 0) $this->store->write('activity.json', $all);
    }

    /**
     * v3.5.0 (F1029) — Eine Versorgerrechnung deckt eine erwartete Abrechnung
     * ab: „Abrechnung erwartet“ ist erledigt (`auto_done`, Grund „Rechnung
     * erfasst“). Die Agenda erkennt das ohnehin aus den Daten; der Eintrag hält
     * fest, wann und von wem. Kein zweites `auto_done` für denselben Zeitraum.
     *
     * @param array<string,mixed> $exp  Erwartung aus BillExpectedService::expected()
     * @param array<string,mixed> $bill die erfasste Rechnung
     */
    public function billRecorded(array $exp, array $bill): ?array
    {
        $uid = (string)$exp['uid'];
        $occ = self::occurrence($uid, (string)$exp['until']);
        $existing = $this->doneEntry($occ);
        if ($existing !== null && ($existing['action'] ?? '') === 'auto_done') return null;
        $today = date('Y-m-d');
        $issued = (string)($bill['issued_on'] ?? '');
        return $this->record([
            'action'    => 'auto_done',
            'subject'   => ['kind' => 'bill_expected', 'uid' => $uid, 'occurrence' => $occ],
            'ref'       => ['utility' => (string)$exp['utility'], 'meter_id' => (string)$exp['target_id'],
                            'contract_id' => (string)$exp['contract_id'], 'bill_id' => (string)($bill['id'] ?? '')],
            'title_key' => BillExpectedService::titleKey($exp, false),
            'params'    => BillExpectedService::params($exp, $this->i18n),
            'done_on'   => Dates::isIsoDate($issued) && $issued <= $today ? $issued : $today,
            'by'        => $this->actor() ?? ['type' => 'auto'],
            'reason'    => ['key' => 'activity.reason.bill', 'params' => ['date' => (string)($bill['period_to'] ?? '')]],
        ]);
    }

    /**
     * v3.5.0 (F1029) — Rechnung gelöscht oder geändert: Erledigungen durch sie
     * werden durchgestrichen, außer `$covered(occurrence)` sagt, dass sie das
     * Vorkommen weiter abdeckt. Die Agenda zeigt den Hinweis dann wieder.
     *
     * @param (callable(string): bool)|null $covered
     */
    public function billRevoked(string $billId, ?callable $covered = null): int
    {
        $all = $this->all();
        $n = 0;
        foreach ($all as $i => $e) {
            if (($e['action'] ?? '') !== 'auto_done' || !empty($e['undone']) || ($e['ref']['bill_id'] ?? null) !== $billId) continue;
            if ($covered !== null && $covered((string)($e['subject']['occurrence'] ?? ''))) continue;
            $all[$i]['undone'] = ['at' => date('c'), 'by' => ['type' => 'auto']];
            $n++;
        }
        if ($n > 0) $this->store->write(self::FILE, $all);
        return $n;
    }

    /** Integrationen tragen Produktnamen — gleich in jeder Sprache (wie lib/activity.js). */
    private const INTEGRATIONS = ['home_assistant' => 'Home Assistant', 'evcc' => 'evcc'];

    /**
     * v3.5.0 (Nachtrag F1024) — ein Eintrag in Worten, in der Sprache der
     * Anfrage bzw. des Exports: Aktion, Titel (aus `title_key`, sonst die
     * Momentaufnahme), wer, Grund. Für den Verlauf als CSV.
     *
     * @param array<string,mixed> $e
     * @return array{action:string, title:string, by:string, reason:string}
     */
    public function describe(array $e): array
    {
        $text = function (mixed $key, mixed $params): ?string {
            if (!is_string($key) || $key === '') return null;
            $s = $this->i18n->t($key, $this->display(is_array($params) ? $params : []));
            return $s !== $key ? $s : null;
        };
        $by = is_array($e['by'] ?? null) ? $e['by'] : null;
        $who = match ($by['type'] ?? null) {
            'person'      => !empty($by['former']) ? $this->i18n->t('activity.by.former') : (string)($by['name'] ?? ''),
            'key'         => $this->i18n->t('activity.by.key', ['name' => (string)($by['name'] ?? '')]),
            'integration' => self::INTEGRATIONS[(string)($by['name'] ?? '')] ?? (string)($by['name'] ?? ''),
            'auto'        => $this->i18n->t('activity.by.auto'),
            default       => '',
        };
        $action = (string)($e['action'] ?? '');
        return [
            'action' => in_array($action, self::ACTIONS, true) ? $this->i18n->t("activity.actionName.$action") : $action,
            'title'  => $text($e['title_key'] ?? null, $e['params'] ?? []) ?? (string)($e['title'] ?? ''),
            'by'     => $who,
            'reason' => $text($e['reason']['key'] ?? null, $e['reason']['params'] ?? []) ?? '',
        ];
    }

    /**
     * Eingaben einer Tat prüfen, bevor etwas geändert wird: `done_on` ein
     * Kalendertag, nicht in der Zukunft (rückdatieren ja); Notiz bis 500
     * Zeichen; Beleg vorhanden. Wirft 400.
     *
     * @param array<string,mixed> $in
     * @return array{0:string, 1:string, 2:?string} done_on, Notiz, Beleg
     */
    public function checkInput(array $in): array
    {
        $today = date('Y-m-d');
        $doneOn = isset($in['done_on']) && $in['done_on'] !== '' && $in['done_on'] !== null ? $in['done_on'] : $today;
        if (!is_string($doneOn) || !Dates::isIsoDate($doneOn) || $doneOn > $today) {
            throw new HttpStatusException(400, 'errors.activity.dateInvalid', ['value' => is_scalar($doneOn) ? (string)$doneOn : '']);
        }
        $attachment = isset($in['attachment_id']) && $in['attachment_id'] !== '' && $in['attachment_id'] !== null ? (string)$in['attachment_id'] : null;
        if ($attachment !== null) $this->assertAttachment($attachment);
        return [$doneOn, self::cleanNote($in['note'] ?? null), $attachment];
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $all */
    private function indexOf(array $all, string $id): int
    {
        foreach ($all as $i => $e) if ($e['id'] === $id) return $i;
        throw new NotFoundException($this->i18n->t('errors.activity.notFound'));
    }

    private static function cleanNote(mixed $note): string
    {
        if ($note === null) return '';
        if (!is_scalar($note)) throw new HttpStatusException(400, 'errors.activity.noteInvalid', ['max' => self::NOTE_MAX]);
        $note = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$note) ?? '');
        if (mb_strlen($note) > self::NOTE_MAX) throw new HttpStatusException(400, 'errors.activity.noteInvalid', ['max' => self::NOTE_MAX]);
        return $note;
    }

    private function assertAttachment(string $id): void
    {
        if (preg_match(AttachmentService::ID, $id)) {
            foreach ((array)$this->store->read(AttachmentService::INDEX, []) as $a) {
                if (is_array($a) && ($a['id'] ?? null) === $id) return;
            }
        }
        throw new HttpStatusException(400, 'errors.attachment.notFound');
    }

    /** Parameter für die Momentaufnahme: Tage (JJJJ-MM-TT) in der Schreibweise der Sprache. */
    private function display(array $params): array
    {
        return array_map(fn($v) => is_string($v) && Dates::isIsoDate($v) ? $this->i18n->date($v) : $v, $params);
    }

    /**
     * Aufbewahrung (`activity_keep_months`, 0 = unbegrenzt): beim Schreiben
     * fallen Einträge heraus, die länger her sind.
     *
     * @param list<array<string,mixed>> $all
     * @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>}
     */
    private function prune(array $all): array
    {
        $months = max(0, (int)$this->settings->get('activity_keep_months', 60));
        if ($months === 0) return [$all, []];
        // über den Monatsersten gerechnet (Lektion 26)
        $now = new \DateTimeImmutable(date('Y-m-d'));
        $first = $now->modify('first day of this month')->sub(new \DateInterval("P{$months}M"));
        $cutoff = $first->setDate((int)$first->format('Y'), (int)$first->format('n'),
            min((int)$now->format('j'), (int)$first->format('t')))->format('Y-m-d');
        $keep = $drop = [];
        foreach ($all as $e) {
            if ((string)($e['done_on'] ?? '') < $cutoff) $drop[] = $e; else $keep[] = $e;
        }
        return [$keep, $drop];
    }

    /** Belege entfernter Einträge lösen (der Belege-Dienst räumt sie nach 24 h auf). */
    private function releaseAttachments(array $entries): void
    {
        foreach ($entries as $e) {
            if (!empty($e['attachment_id'])) AttachmentService::unlinkRef($this->store, 'activity', (string)$e['id']);
        }
    }

    private function utilityActive(string $utility): bool
    {
        $a = $this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
        return !is_array($a) || $a === [] || in_array($utility, $a, true);
    }

    /** @param array<string,mixed>|null $revert */
    private function revert(?array $revert): bool
    {
        if ($revert === null) return false;
        try {
            switch ($revert['type'] ?? null) {
                case 'reminder':
                    if ($this->reminders === null) return false;
                    $cur = null;
                    foreach ($this->reminders->list(true) as $r) if (($r['id'] ?? null) === $revert['id']) $cur = $r;
                    if ($cur === null) return false;
                    // fehlendes `active` heißt aktiv (wie ReminderService::list)
                    $now = ['next_due' => $cur['next_due'] ?? null, 'active' => $cur['active'] ?? true, 'last_done' => $cur['last_done'] ?? null];
                    foreach ((array)$revert['after'] as $k => $v) {
                        if (($now[$k] ?? null) !== $v) return false;   // inzwischen anders geändert
                    }
                    $this->reminders->update((string)$revert['id'], (array)$revert['before']);
                    return true;
                case 'contract_auto_renews':
                    if ($this->contracts === null) return false;
                    $c = $this->contracts->get((string)$revert['utility'], (string)$revert['id']);
                    if ($c === null || ($c['auto_renews'] ?? null) !== $revert['after']) return false;
                    $this->contracts->update((string)$revert['utility'], (string)$revert['id'], ['auto_renews' => $revert['before']]);
                    return true;
                case 'recommendation_dismiss':
                    if ($this->recommendations === null) return false;
                    return $this->recommendations->restoreIfUntil((string)$revert['id'], (string)$revert['until']);
            }
        } catch (NotFoundException) {
            return false;
        }
        return false;
    }
}
