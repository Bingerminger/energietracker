<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Http\NotFoundException;

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
 *   stromzaehler_eichung 96 · wasserzaehler_eichung 72
 *   dichtheitspruefung 48 · lieferung_planen — · custom —
 *
 * [Unverifiziert] Die Eich-Intervalle (MessEV) sind branchenübliche
 * Richtwerte und können je nach Messgerät/Aufstellort abweichen — sie
 * dienen nur als Vorbelegung, der Nutzer kann jeden Wert überschreiben.
 */
final class ReminderService
{
    public const CATEGORY_DEFAULT_MONTHS = [
        'heizung_wartung'       => 12,
        'schornsteinfeger'      => 6,
        'gaszaehler_eichung'    => 96,
        'stromzaehler_eichung'  => 96,
        'wasserzaehler_eichung' => 72,
        'dichtheitspruefung'    => 48,
        'lieferung_planen'      => null,
        'custom'                => null,
    ];

    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
        private I18nService $i18n,
    ) {}

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
            $out[] = $r;
        }
        return $out;
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
        if (empty($input['next_due']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['next_due'])) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reminder.dueRequired'));
        }

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
        ];
        $all = $this->store->read('reminders.json', []);
        if (!is_array($all)) $all = [];
        $all[] = $reminder;
        $this->store->write('reminders.json', $all);
        return $reminder;
    }

    public function update(string $id, array $patch): array
    {
        $all = $this->store->read('reminders.json', []);
        if (!is_array($all)) $all = [];
        $found = null;
        foreach ($all as &$r) {
            if (($r['id'] ?? null) !== $id) continue;
            foreach (['title','category','next_due','recurrence','recurrence_months','notes','active'] as $f) {
                if (array_key_exists($f, $patch)) {
                    $r[$f] = $f === 'active' ? (bool)$patch[$f]
                        : ($f === 'recurrence_months'
                            ? ($patch[$f] !== null ? (int)$patch[$f] : null)
                            : $patch[$f]);
                }
            }
            $found = $r;
            break;
        }
        unset($r);
        if ($found === null) throw new NotFoundException($this->i18n->t('errors.reminder.notFound', ['id' => $id]));
        $this->store->write('reminders.json', $all);
        return $found;
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
     */
    public function markDone(string $id, ?string $doneDate = null): array
    {
        $done = $doneDate ?: date('Y-m-d');
        $all = $this->store->read('reminders.json', []);
        if (!is_array($all)) $all = [];
        $found = null;
        foreach ($all as &$r) {
            if (($r['id'] ?? null) !== $id) continue;
            $r['last_done'] = $done;
            $rec = (string)($r['recurrence'] ?? 'none');
            $months = match ($rec) {
                'yearly'        => 12,
                'semi-yearly'   => 6,
                'custom-months' => max(1, (int)($r['recurrence_months'] ?? 12)),
                default         => null,
            };
            if ($months === null) {
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
        return $found;
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
