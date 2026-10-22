<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Http\NotFoundException;
use Energietracker\Support\Dates;

/**
 * v1.3.0 — Termin- und Wartungserinnerungen.
 *
 * Zentrale Datei data/reminders.json. Jeder Eintrag:
 *   {
 *     id, title, category, next_due (YYYY-MM-DD),
 *     recurrence: none|yearly|semi-yearly|custom-months,
 *     recurrence_months: int|null   (nur bei custom-months),
 *     last_done: YYYY-MM-DD|null,
 *     notes, active
 *   }
 *
 * „Erledigt"-Klick (markDone) schreibt last_done = heute und schreibt
 * next_due nach der Recurrence fort (bei recurrence=none wird der
 * Eintrag auf active=false gesetzt).
 *
 * Vordefinierte Kategorien mit Default-Intervallen (Monate):
 *   heizung_wartung 12 · schornsteinfeger 6 · gaszaehler_eichung 96
 *   stromzaehler_eichung 96 · wasserzaehler_eichung 72 · waermezaehler_eichung 60
 *   dichtheitspruefung 48 · lieferung_planen — · custom —
 *
 * [Unverifiziert] Die Eich-Intervalle (MessEV) sind branchenübliche
 * Richtwerte und können je nach Messgerät/Aufstellort abweichen — sie
 * dienen nur als Vorbelegung, der Nutzer kann jeden Wert überschreiben.
 *
 * v3.5.0 (F1028) — Zuständig, optional und additiv (kein Schemaschritt):
 *   assignee         Anzeigename, getrimmt, bis 60 Zeichen; fehlt = niemand
 *   assignee_id      Personen-ID aus auth.json, nur wenn aus der Personenliste
 *                    gewählt — dann gilt der Name der Person
 *   assignee_former  true, nachdem die zuständige Person gelöscht und ihr Name
 *                    ersetzt wurde („ehemalige Person“, wie im Verlauf)
 * Die Antworten tragen alle drei immer (null bzw. false). Wiederkehrende
 * Termine behalten die Zuständigkeit beim Erledigen; der Verlaufseintrag nennt
 * sie als Momentaufnahme. Benachrichtigt wird niemand.
 *
 * v3.6.0 — Personen löschen (personDeleted): Offene Termine gehen an die
 * löschende Person; erledigte behalten den Namen, eingefroren beim Erledigen,
 * mit dem Vermerk
 *   assignee_deleted true — die Person ist gelöscht, der Name bleibt („(gelöscht)“)
 * Die Antworten tragen das Feld immer (additiv). Eine gelöschte Person lässt
 * sich nicht mehr zuweisen.
 *
 * v3.7.0 — Termin am Zähler, optional und additiv:
 *   utility, meter_id  der Zähler, zu dem der Termin gehört (Eichfrist,
 *                      Zählerwechsel, Lieferung planen …)
 * Ohne eigene Zuständigkeit gilt die des Zählers (ResponsibilityService);
 * `GET /api/reminders` trägt sie als `assignee_inherited` (sonst null), und
 * beim Erledigen nennt der Verlauf sie als Momentaufnahme.
 */
final class ReminderService
{
    public const CATEGORY_DEFAULT_MONTHS = [
        'heizung_wartung'       => 12,
        'schornsteinfeger'      => 6,
        'gaszaehler_eichung'    => 96,
        'stromzaehler_eichung'  => 96,
        'wasserzaehler_eichung' => 72,
        'waermezaehler_eichung' => 60,   // v2.9.0 (CALC-24): MessEV Anlage 7 Nr. 7.1, fünf Jahre
        'dichtheitspruefung'    => 48,
        'lieferung_planen'      => null,
        'zaehlerstand_melden'   => null,   // v3.7.0 (Paket betrieb) — Zählerstandsanforderung des Versorgers
        'custom'                => null,
    ];

    /**
     * v3.7.0 (Paket betrieb) — Termin „Zählerstand melden“ (aus dem Posteingang):
     * mit Zähler führt er direkt in dessen Erfassung (AgendaService, Termine).
     */
    public const CATEGORY_READING = 'zaehlerstand_melden';

    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
        private I18nService $i18n,
    ) {}

    /** v3.4.0 (F1024) — Verlauf: jedes Erledigen ein Eintrag (wer, wann, Notiz), Rückgängig stellt zurück. */
    private ?ActivityService $activity = null;

    public function attachActivity(ActivityService $activity): void
    {
        $this->activity = $activity;
    }

    /** v3.5.0 (F1028) — längster Name einer zuständigen Person (wie die Namen im Verlauf). */
    public const ASSIGNEE_MAX = 60;

    /** @var (callable(string): ?array)|null Person zu einer ID ({id, name, deleted?}) oder null */
    private $people = null;

    /**
     * v3.5.0 (F1028) — Personen nachschlagen (App: seit v3.6.0 das
     * Personenverzeichnis, PeopleService::find — auch gelöschte, `deleted: true`).
     */
    public function attachPeople(callable $lookup): void
    {
        $this->people = $lookup;
    }

    /** v3.7.0 — Zuständigkeit am Zähler eines Termins (ResponsibilityService). */
    private ?ResponsibilityService $responsibility = null;

    public function attachResponsibility(ResponsibilityService $responsibility): void
    {
        $this->responsibility = $responsibility;
    }

    /**
     * v3.7.0 — Zähler eines Termins aus einer Eingabe: null = unverändert,
     * [] = ohne Zähler, sonst {utility, meter_id} eines vorhandenen Zählers.
     *
     * @param array<string,mixed> $input
     * @return array{utility?:string, meter_id?:string}|null
     */
    private function meterInput(array $input): ?array
    {
        if (!array_key_exists('meter_id', $input)) return null;
        $mid = $input['meter_id'];
        if ($mid === null || $mid === '') return [];
        $u = $input['utility'] ?? null;
        $link = is_string($mid) && is_string($u) ? ['utility' => $u, 'meter_id' => $mid] : null;
        if ($link === null || !\Energietracker\Config\Utilities::exists($u) || !BackupService::safeId($mid)
            || ($this->responsibility !== null && $this->responsibility->linkedMeter($link) === null)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reminder.meterUnknown'));
        }
        return $link;
    }

    /**
     * v3.7.0 — Was für einen Termin ohne eigene Zuständigkeit gilt (die des
     * Zählers), sonst null.
     *
     * @param array<string,mixed> $r Termin mit withAssignee
     */
    private function inherited(array $r): ?array
    {
        if ($this->responsibility === null || ResponsibilityService::own($r) !== null) return null;
        return $this->responsibility->inheritedFor($r);
    }

    /** @return array<int,array<string,mixed>> */
    public function list(bool $includeInactive = false): array
    {
        $all = $this->store->read('reminders.json', []);
        if (!is_array($all)) $all = [];
        if (!$includeInactive) {
            $all = array_values(array_filter($all, fn($r) => ($r['active'] ?? true) !== false));
        }
        usort($all, fn($a, $b) => strcmp((string)($a['next_due'] ?? ''), (string)($b['next_due'] ?? '')));
        return $all;
    }

    /**
     * Liste angereichert um den Fälligkeitsstatus relativ zu heute:
     *   status ∈ { ok, due_soon, due, overdue }
     */
    public function listWithStatus(): array
    {
        $warnBefore = (int)$this->settings->get('reminder_warn_days_before', 14);
        $overdueAfter = (int)$this->settings->get('reminder_overdue_days', 0);
        $today = new \DateTime(date('Y-m-d'));

        $out = [];
        foreach ($this->list() as $r) {
            // v2.1.5 — ein kaputtes next_due (Import/Legacy) darf die Liste nicht
            // mit einer DateTime-Exception sprengen → defensiv auf null.
            $due = null;
            if (isset($r['next_due']) && $r['next_due']) {
                try { $due = new \DateTime((string)$r['next_due']); }
                catch (\Exception) { $due = null; }
            }
            $status = 'ok';
            $daysUntil = null;
            if ($due !== null) {
                $daysUntil = (int)$today->diff($due)->format('%r%a');
                if ($daysUntil < -$overdueAfter)      $status = 'overdue';
                elseif ($daysUntil <= 0)              $status = 'due';
                elseif ($daysUntil <= $warnBefore)    $status = 'due_soon';
            }
            $r['days_until'] = $daysUntil;
            $r['status']     = $status;
            // v3.4.0 (F1024) — „zuletzt erledigt am … von …" (additiv; ohne Verlauf null)
            if ($this->activity !== null) {
                $last = $this->activity->lastDone('reminder_id', (string)($r['id'] ?? ''));
                $r['last_done_by'] = $last !== null ? ($last['by'] ?? null) : null;
            }
            $r = $this->withAssignee($r);
            // v3.7.0 — ohne eigene Zuständigkeit: die des Zählers (additiv, sonst null)
            $r['assignee_inherited'] = $this->inherited($r);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * v3.5.0 (F1028) — Zuständigkeit für die Antwort: immer `assignee`,
     * `assignee_id` und `assignee_former`. Ist eine Person gewählt, gilt ihr
     * heutiger Name (umbenannt → neuer Name).
     *
     * v3.6.0 — dazu `assignee_deleted`: Die Person ist gelöscht (Vermerk am
     * Termin oder laut Personenverzeichnis); dann bleibt der gespeicherte Name.
     * Ein erledigter Termin (nicht mehr aktiv) behält den Namen vom Erledigen.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public function withAssignee(array $r): array
    {
        $name = is_string($r['assignee'] ?? null) && trim($r['assignee']) !== '' ? trim($r['assignee']) : null;
        $id = is_string($r['assignee_id'] ?? null) && $r['assignee_id'] !== '' ? $r['assignee_id'] : null;
        $deleted = !empty($r['assignee_deleted']);
        if ($id !== null && $this->people !== null && ($p = ($this->people)($id)) !== null) {
            if (!empty($p['deleted'])) {
                $deleted = true;
                $name ??= ($p['name'] ?? '') !== '' ? (string)$p['name'] : null;
            } elseif (($p['name'] ?? '') !== '' && !$deleted && ($r['active'] ?? true) !== false) {
                $name = (string)$p['name'];
            }
        }
        $r['assignee'] = $name;
        $r['assignee_id'] = $name !== null ? $id : null;
        $r['assignee_former'] = $name === null && !empty($r['assignee_former']);
        $r['assignee_deleted'] = $name !== null && $deleted;
        return $r;
    }

    /**
     * v3.5.0 (F1028) — Zuständigkeit aus einer Eingabe prüfen. Gibt die
     * Felder zurück, die am Termin stehen sollen (leer = niemand); null, wenn
     * die Eingabe nichts dazu sagt. `assignee_id` muss eine Person sein; dann
     * gilt deren Name. `assignee` ohne `assignee_id` ist ein freier Name.
     *
     * @param array<string,mixed> $input
     * @return array{assignee?:string, assignee_id?:string}|null
     */
    private function assigneeInput(array $input): ?array
    {
        if (!array_key_exists('assignee', $input) && !array_key_exists('assignee_id', $input)) return null;
        $raw = $input['assignee'] ?? null;
        if ($raw !== null && !is_string($raw)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reminder.assigneeInvalid', ['max' => self::ASSIGNEE_MAX]));
        }
        $name = trim((string)$raw);
        if (mb_strlen($name) > self::ASSIGNEE_MAX || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reminder.assigneeInvalid', ['max' => self::ASSIGNEE_MAX]));
        }
        $rawId = $input['assignee_id'] ?? null;
        if ($rawId !== null && !is_string($rawId)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reminder.assigneeUnknown'));
        }
        $id = trim((string)$rawId);
        if ($id !== '') {
            $person = $this->people !== null ? ($this->people)($id) : null;
            // v3.6.0 — eine gelöschte Person übernimmt nichts mehr
            if ($person === null || !empty($person['deleted'])) throw new \InvalidArgumentException($this->i18n->t('errors.reminder.assigneeUnknown'));
            // aus der Personenliste gewählt: es gilt ihr Name
            return ['assignee' => mb_substr((string)$person['name'], 0, self::ASSIGNEE_MAX), 'assignee_id' => $id];
        }
        return $name === '' ? [] : ['assignee' => $name];
    }

    /**
     * Zuständigkeit an einen gespeicherten Termin schreiben: ersetzt alle drei
     * Felder (auch „ehemalige Person“).
     *
     * @param array<string,mixed> $r
     * @param array{assignee?:string, assignee_id?:string} $a
     * @return array<string,mixed>
     */
    private static function applyAssignee(array $r, array $a): array
    {
        unset($r['assignee'], $r['assignee_id'], $r['assignee_former'], $r['assignee_deleted']);
        return $r + $a;
    }

    /**
     * v3.6.0 — Termine einer Person: per ID oder — ohne ID — mit demselben
     * Namen (Groß/klein egal; ein freier Name aus der Zeit vor der Person).
     */
    private static function belongsTo(array $r, string $personId, string $name): bool
    {
        if (($r['assignee_id'] ?? null) === $personId) return true;
        if (!empty($r['assignee_id']) || $name === '' || !is_string($r['assignee'] ?? null) || !empty($r['assignee_deleted'])) return false;
        return mb_strtolower(trim($r['assignee'])) === mb_strtolower(trim($name));
    }

    /** Ein Termin gilt als offen, solange er aktiv ist (erledigte einmalige Termine sind es nicht mehr). */
    private static function isOpen(array $r): bool
    {
        return ($r['active'] ?? true) !== false;
    }

    /**
     * v3.6.0 — Wie viele offene Termine beim Löschen übergehen würden (für den
     * Bestätigungsdialog). Dieselbe Regel wie personDeleted.
     */
    public function openCountFor(string $personId, string $name = ''): int
    {
        $n = 0;
        foreach ((array)$this->store->read('reminders.json', []) as $r) {
            if (is_array($r) && self::isOpen($r) && self::belongsTo($r, $personId, $name)) $n++;
        }
        return $n;
    }

    /**
     * v3.6.0 — Eine Person wurde gelöscht (v3.5.0 löste nur die ID).
     *
     * - Offene Termine gehen an die löschende Person `$to` ({id, name}).
     *   Ohne sie (API-Schlüssel) bleiben sie mit dem Vermerk „gelöscht“
     *   stehen, bis jemand sie übernimmt.
     * - Erledigte Termine behalten Namen und ID mit dem Vermerk
     *   `assignee_deleted` — die Historisierung. Mit `$anonymize` (wie
     *   „ehemalige Person“ im Verlauf) ersetzt `assignee_former` den Namen.
     *
     * @param array{id:string, name:string}|null $to
     * @return array{handed_over:int, kept:int}
     */
    public function personDeleted(string $personId, bool $anonymize, ?array $to = null, string $name = ''): array
    {
        $all = $this->store->read('reminders.json', []);
        $out = ['handed_over' => 0, 'kept' => 0];
        if (!is_array($all) || $personId === '') return $out;
        foreach ($all as $i => $r) {
            if (!is_array($r) || !self::belongsTo($r, $personId, $name)) continue;
            if (self::isOpen($r) && $to !== null && ($to['id'] ?? '') !== '' && $to['id'] !== $personId) {
                $all[$i] = self::applyAssignee($r, ['assignee' => mb_substr((string)$to['name'], 0, self::ASSIGNEE_MAX), 'assignee_id' => (string)$to['id']]);
                $out['handed_over']++;
                continue;
            }
            if ($anonymize) {
                unset($all[$i]['assignee'], $all[$i]['assignee_id'], $all[$i]['assignee_deleted']);
                $all[$i]['assignee_former'] = true;
            } else {
                $all[$i]['assignee_deleted'] = true;
            }
            $out['kept']++;
        }
        if ($out['handed_over'] + $out['kept'] > 0) $this->store->write('reminders.json', array_values($all));
        return $out;
    }

    /**
     * Zuständigkeit als Momentaufnahme für den Verlauf: {name, id?},
     * {former: true} oder null.
     *
     * @param array<string,mixed> $r Termin (gespeichert oder mit withAssignee)
     */
    private function assigneeSnapshot(array $r): ?array
    {
        $r = $this->withAssignee($r);
        if ($r['assignee'] !== null) {
            return array_filter(['name' => $r['assignee'], 'id' => $r['assignee_id'], 'deleted' => $r['assignee_deleted'] ?: null], fn($v) => $v !== null);
        }
        return $r['assignee_former'] ? ['former' => true] : null;
    }

    public function create(array $input): array
    {
        $category = (string)($input['category'] ?? 'custom');
        $rec = (string)($input['recurrence'] ?? 'none');
        $recMonths = $input['recurrence_months'] ?? null;
        if ($rec === 'custom-months' && ($recMonths === null || (int)$recMonths <= 0)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reminder.recurrenceMonths'));
        }
        if (empty($input['title'])) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reminder.titleRequired'));
        }
        if (empty($input['next_due']) || !Dates::isIsoDate((string)$input['next_due'])) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reminder.dueRequired'));
        }
        $assignee = $this->assigneeInput($input) ?? [];   // v3.5.0 (F1028)
        $meter = $this->meterInput($input) ?? [];         // v3.7.0 — Termin am Zähler

        $reminder = [
            'id'                => 'rem_' . substr(bin2hex(random_bytes(5)), 0, 8),
            'title'             => (string)$input['title'],
            'category'          => $category,
            'next_due'          => (string)$input['next_due'],
            'recurrence'        => in_array($rec, ['none','yearly','semi-yearly','custom-months'], true) ? $rec : 'none',
            'recurrence_months' => $recMonths !== null ? (int)$recMonths : null,
            'last_done'         => null,
            'notes'             => (string)($input['notes'] ?? ''),
            'active'            => true,
        ] + $assignee + $meter;
        $all = $this->store->read('reminders.json', []);
        if (!is_array($all)) $all = [];
        $all[] = $reminder;
        $this->store->write('reminders.json', $all);
        return $this->withAssignee($reminder);
    }

    public function update(string $id, array $patch): array
    {
        // v2.5.3 — dieselbe Datumsregel wie beim Anlegen; vorher nahm das
        // Ändern jeden String als Fälligkeit an.
        if (array_key_exists('next_due', $patch) && !Dates::isIsoDate((string)$patch['next_due'])) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reminder.dueRequired'));
        }
        // v2.12.0 (Review UI-22) — `last_done` änderbar, damit „Erledigt"
        // rückgängig gemacht werden kann: null oder ein Datum
        if (array_key_exists('last_done', $patch) && $patch['last_done'] !== null
            && !Dates::isIsoDate((string)$patch['last_done'])) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reminder.lastDoneInvalid'));
        }
        $assignee = $this->assigneeInput($patch);   // v3.5.0 (F1028) — null: unverändert
        $meter = $this->meterInput($patch);         // v3.7.0 — null: unverändert, []: ohne Zähler
        $all = $this->store->read('reminders.json', []);
        if (!is_array($all)) $all = [];
        $found = null;
        foreach ($all as &$r) {
            if (($r['id'] ?? null) !== $id) continue;
            foreach (['title','category','next_due','recurrence','recurrence_months','notes','active','last_done'] as $f) {
                if (array_key_exists($f, $patch)) {
                    $r[$f] = $f === 'active' ? (bool)$patch[$f]
                        : ($f === 'recurrence_months'
                            ? ($patch[$f] !== null ? (int)$patch[$f] : null)
                            : $patch[$f]);
                }
            }
            if ($assignee !== null) $r = self::applyAssignee($r, $assignee);
            if ($meter !== null) {
                unset($r['utility'], $r['meter_id']);
                $r += $meter;
            }
            $found = $r;
            break;
        }
        unset($r);
        if ($found === null) throw new NotFoundException($this->i18n->t('errors.reminder.notFound', ['id' => $id]));
        $this->store->write('reminders.json', $all);
        return $this->withAssignee($found);
    }

    public function delete(string $id): void
    {
        $all = $this->store->read('reminders.json', []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($r) => ($r['id'] ?? null) !== $id));
        if (count($kept) === count($all)) throw new NotFoundException($this->i18n->t('errors.reminder.notFound', ['id' => $id]));
        $this->store->write('reminders.json', $kept);
    }

    /**
     * „Erledigt" — last_done = heute, next_due fortschreiben.
     * Bei recurrence=none wird der Eintrag deaktiviert (kein Wieder-Fällig).
     *
     * v3.4.0 (F1024) — mit Verlauf: ein Eintrag je Erledigen (Notiz und Beleg
     * optional), die Antwort trägt `activity_id`; Rückgängig im Verlauf stellt
     * Termin, Status und `last_done` zurück. Das Datum muss ein Kalendertag sein.
     */
    public function markDone(string $id, ?string $doneDate = null, ?string $note = null, ?string $attachmentId = null): array
    {
        $done = $doneDate ?: date('Y-m-d');
        if (!Dates::isIsoDate($done)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reminder.lastDoneInvalid'));
        }
        // erst prüfen, dann ändern (Notiz zu lang, Beleg unbekannt → 400 ohne Folgen)
        $this->activity?->checkInput(['done_on' => $done, 'note' => $note, 'attachment_id' => $attachmentId]);
        $all = $this->store->read('reminders.json', []);
        if (!is_array($all)) $all = [];
        $found = null;
        $before = null;
        foreach ($all as &$r) {
            if (($r['id'] ?? null) !== $id) continue;
            $before = ['next_due' => $r['next_due'] ?? null, 'active' => $r['active'] ?? true, 'last_done' => $r['last_done'] ?? null];
            $r['last_done'] = $done;
            $rec = (string)($r['recurrence'] ?? 'none');
            $months = match ($rec) {
                'yearly'        => 12,
                'semi-yearly'   => 6,
                'custom-months' => max(1, (int)($r['recurrence_months'] ?? 12)),
                default         => null,
            };
            if ($months === null) {
                // v3.6.0 — der Name der zuständigen Person wird beim Erledigen eingefroren
                $current = $this->withAssignee($r);
                if ($current['assignee'] !== null && !empty($r['assignee_id'])) $r['assignee'] = $current['assignee'];
                $r['active'] = false;            // einmaliger Termin erledigt
            } else {
                // v2.1.5 — Tag auf die Ziel-Monatslänge clampen, sonst überläuft
                // PHP `+N months` am Monatsende (31.08. + 6 Mon. → „31.02." = 03.03.).
                $r['next_due'] = $this->addMonths($done, $months);
            }
            $found = $r;
            break;
        }
        unset($r);
        if ($found === null) throw new NotFoundException($this->i18n->t('errors.reminder.notFound', ['id' => $id]));
        $this->store->write('reminders.json', $all);
        if ($this->activity !== null && $before !== null) {
            $uid = 'reminder-' . $id;
            $entry = $this->activity->record([
                'action'        => 'done',
                'subject'       => ['kind' => 'reminder', 'uid' => $uid,
                                    'occurrence' => ActivityService::occurrence($uid, (string)($before['next_due'] ?? ''))],
                'ref'           => ['reminder_id' => $id, 'category' => (string)($found['category'] ?? '')],
                'title_key'     => 'calendar.event.reminder',
                'params'        => ['title' => (string)($found['title'] ?? '')],
                'done_on'       => $done,
                'note'          => $note,
                'attachment_id' => $attachmentId,
                // v3.5.0 (F1028) — wer zuständig war, als Momentaufnahme;
                // v3.7.0 — ohne eigene Zuständigkeit die des Zählers
                'assignee'      => $this->assigneeSnapshot($found) ?? ResponsibilityService::snapshot($this->inherited($this->withAssignee($found))),
                'revert'        => ['type' => 'reminder', 'id' => $id, 'before' => $before, 'after' => [
                    'next_due' => $found['next_due'] ?? null, 'active' => $found['active'] ?? true, 'last_done' => $found['last_done'] ?? null,
                ]],
            ]);
            $found['activity_id'] = $entry['id'];
        }
        return $this->withAssignee($found);
    }

    /**
     * v2.1.5 — N Monate addieren und den Tag auf die Ziel-Monatslänge clampen.
     * Verhindert den PHP-`+N months`-Überlauf am Monatsende (z. B.
     * 31.08. + 6 Mon. = „31.02." → 03.03.); next_due bleibt auf dem 28./30.
     */
    private function addMonths(string $date, int $months): string
    {
        $d   = new \DateTime($date);
        $day = (int)$d->format('d');
        $d->modify('first day of this month')->modify("+{$months} months");
        $daysInTarget = (int)$d->format('t');
        $d->modify('+' . (min($day, $daysInTarget) - 1) . ' days');
        return $d->format('Y-m-d');
    }

    /**
     * Auto-Vorschlag nach einer Lieferung: schätzt das mittlere
     * Lieferintervall (Tage) und schlägt ein Erstell-Payload für die
     * nächste Lieferung vor. Erstellt NICHTS selbst — gibt nur den
     * Vorschlag zurück, den der Controller dem Frontend reicht.
     *
     * @param array<int,array<string,mixed>> $deliveries
     */
    public function suggestNextDelivery(string $utilityLabel, array $deliveries): ?array
    {
        $dates = [];
        foreach ($deliveries as $d) {
            if (!empty($d['date']) && empty($d['is_planned'])) {
                $dates[] = (string)$d['date'];
            }
        }
        sort($dates);
        if (count($dates) < 2) {
            // Kein Intervall ableitbar → konservativ 12 Monate (Monatsende-sicher)
            $next = new \DateTime($this->addMonths(end($dates) ?: date('Y-m-d'), 12));
        } else {
            $gaps = [];
            for ($i = 1; $i < count($dates); $i++) {
                $gaps[] = (new \DateTime($dates[$i - 1]))->diff(new \DateTime($dates[$i]))->days;
            }
            $avg = array_sum($gaps) / count($gaps);
            $next = (new \DateTime(end($dates)))->modify('+' . max(30, (int)round($avg)) . ' days');
        }
        return [
            'title'      => $this->i18n->t('reminders.suggest.deliveryTitle', ['label' => $utilityLabel]),
            'category'   => 'lieferung_planen',
            'next_due'   => $next->format('Y-m-d'),
            'recurrence' => 'none',
            'notes'      => $this->i18n->t('reminders.suggest.deliveryNote'),
        ];
    }
}
