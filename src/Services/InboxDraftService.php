<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Services\Inbox\DraftApplier;
use Energietracker\Services\Inbox\HouseholdDraft;
use Energietracker\Support\LocalizedException;

/**
 * v3.7.0 (Haushalt aus Dokumenten) — der Entwurf über alle ausgelesenen
 * Dokumente des Posteingangs und sein Übernehmen.
 *
 *   GET  /api/inbox/draft         Entwurf (HouseholdDraft::build), dazu der
 *                                 Stand der Warteschlange (wächst mit, solange
 *                                 ausgelesen wird)
 *   POST /api/inbox/draft/apply   {items, confirm, choose, documents?}
 *
 * Übernehmen baut den Entwurf frisch und lehnt eine veraltete Auswahl ab (wie
 * InboxService::apply), bevor etwas geschrieben wird. Davor eine Sicherung
 * (`pre-inbox-…`, Einstellungen → Daten): Ein Entwurf schreibt viel auf einmal,
 * so gibt es einen Weg zurück. Danach: Dokumente, die der Entwurf vollständig
 * abdeckt, sind „übernommen“ (mit ihrem Ergebnis), wohl doppelte „abgelegt“
 * (das Original ist übernommen).
 */
final class InboxDraftService
{
    private ?BackupService $backup = null;
    private ?ActivityService $activity = null;

    public function __construct(
        private InboxService $inbox,
        private HouseholdDraft $draft,
        private DraftApplier $applier,
    ) {}

    public function attach(?BackupService $backup = null, ?ActivityService $activity = null): void
    {
        $this->backup = $backup;
        $this->activity = $activity;
    }

    /**
     * @param list<string>|null $only
     * @return array<string,mixed>
     */
    public function draft(?array $only = null, ?string $today = null): array
    {
        return $this->draft->build($this->entries(), $only, $today) + ['queue' => $this->inbox->queueState()];
    }

    /**
     * Einträge des Posteingangs mit `duplicate_of` wie in Liste und Akte
     * (InboxService::olderDuplicateOf) — eine Quelle für „wohl doppelt“. Der
     * Wert wird gerechnet, nicht gespeichert.
     *
     * @return list<array<string,mixed>>
     */
    private function entries(): array
    {
        $all = $this->inbox->all();
        $out = $all;
        foreach ($all as $i => $e) $out[$i]['duplicate_of'] = $this->inbox->olderDuplicateOf($e, $all);
        return $out;
    }

    /**
     * @param list<string> $ids
     * @param array<string,list<string>> $confirm
     * @param array<string,array<string,int>> $choose
     * @param list<string>|null $only
     * @return array<string,mixed>
     */
    public function apply(array $ids, array $confirm = [], array $choose = [], ?array $only = null, ?string $today = null): array
    {
        if ($ids === []) throw new LocalizedException('errors.inboxDraft.nothingSelected', [], 'draft apply without items');
        $draft = $this->draft->build($this->entries(), $only, $today);
        $selected = $this->applier->select($draft, $ids, $confirm, $choose);
        $snapshot = null;
        if ($this->backup !== null) {
            try {
                $snapshot = $this->backup->saveSnapshot('pre-inbox-');
            } catch (\Throwable) {
                // ohne Sicherung weiter: das Übernehmen selbst ist dasselbe wie von Hand
            }
        }
        $res = $this->applier->apply($draft, $selected, $confirm, $choose);
        $updates = $this->settlement($draft, $res);
        $this->inbox->settle($updates);
        $done = count(array_filter($updates, fn($u) => ($u['status'] ?? null) === 'done'));
        if ($res['applied'] !== [] && $this->activity !== null) {
            try {
                $now = date('c');
                $this->activity->record([
                    'action' => 'updated',
                    'subject' => ['kind' => 'inbox', 'uid' => 'inbox:draft', 'occurrence' => 'inbox:draft@' . $now],
                    'ref' => [],
                    'title_key' => 'activity.inbox.draftApplied',
                    'params' => ['items' => count($res['applied']), 'documents' => $done],
                ]);
            } catch (\Throwable) {
                // der Verlauf darf das Übernehmen nie verhindern
            }
        }
        $created = ['meters' => 0, 'meter_changes' => 0, 'contracts' => 0, 'rows' => 0, 'readings' => 0, 'bills' => 0, 'booked' => 0, 'gas_factors' => 0, 'reminders' => 0];
        $bills = [];
        foreach ($res['applied'] as $a) {
            $r = $a['ref'];
            match ($a['kind']) {
                'meter' => $created['meters'] += !empty($r['created']) ? 1 : 0,
                'meter_change' => $created['meter_changes']++,
                'contract' => $created['contracts'] += !empty($r['created']) ? 1 : 0,
                'prices', 'advances' => $created['rows'] += (int)($r['rows'] ?? 0),
                'readings' => $created['readings'] += (int)($r['readings'] ?? 0),
                'gas_factor' => $created['gas_factors'] += count($a['fields']),
                'reminder' => $created['reminders']++,
                default => null,
            };
            if ($a['kind'] === 'bill') {
                $created['bills']++;
                $created['booked'] += !empty($r['booked']) ? 1 : 0;
                $bills[] = ['utility' => $a['utility'], 'id' => $r['id'], 'meter_id' => $r['meter_id'], 'period_from' => $r['period_from'], 'period_to' => $r['period_to'], 'booked' => $r['booked']];
            }
        }
        // was noch fehlt — nur für Verträge, die es jetzt gibt; Preise, die doch übernommen sind
        // (eine bestätigte Frage), fehlen nicht mehr
        $written = [];
        $byId = array_column($draft['items'], null, 'id');
        foreach ($res['applied'] as $a) {
            if ($a['kind'] !== 'prices') continue;
            foreach ((array)($a['fields'] ?? []) as $n) $written[(string)($byId[$a['id']]['contract_key'] ?? '')][explode(':', (string)$n)[0]] = true;
        }
        $missing = [];
        foreach ($draft['missing'] as $m) {
            $cid = $res['contracts'][$m['key']] ?? null;
            if (in_array($m['kind'], ['advance', 'prices', 'water_prices', 'working_price', 'base_price', 'prices_unconfirmed', 'working_price_gap', 'base_price_gap'], true) && $cid === null) continue;
            if (isset($m['which']) && array_filter((array)$m['which'], fn($p) => empty($written[$m['key']][$p])) === []) continue;
            $missing[] = $m + ['contract_id' => $cid];
        }
        $read = count(array_filter($this->inbox->all(), fn($e) => ($e['status'] ?? '') === 'read'));
        return [
            'applied' => $res['applied'], 'failed' => $res['failed'], 'created' => $created, 'bills' => $bills, 'missing' => $missing,
            'documents' => ['done' => $done, 'filed' => count(array_filter($updates, fn($u) => ($u['status'] ?? null) === 'filed')), 'open' => $read],
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Je Dokument: was aus ihm übernommen ist, die Zuordnung und ob der Entwurf
     * es vollständig abdeckt (jeder seiner Einträge übernommen oder schon
     * vorhanden, kein Feld aus ihm gescheitert; Angebote zählen nicht).
     *
     * @return array<string,array<string,mixed>>
     */
    private function settlement(array $draft, array $res): array
    {
        $applied = array_column($res['applied'], null, 'id');
        $failedItems = []; $failedDocs = [];
        foreach ($res['failed'] as $f) {
            if (($f['field'] ?? null) === null) $failedItems[$f['id']] = true;
            else foreach ((array)($f['docs'] ?? []) as $d) $failedDocs[$d] = true;
        }
        $byId = array_column($draft['items'], null, 'id');
        $updates = [];
        foreach ($draft['documents'] as $doc) {
            if ($doc['state'] !== 'used') continue;
            $covered = true;
            $refs = [];
            foreach ($doc['items'] as $iid) {
                $it = $byId[$iid] ?? null;
                if ($it === null || !empty($it['optional']) || empty($it['actionable'])) continue;
                if (!isset($applied[$iid]) || isset($failedItems[$iid])) { $covered = false; continue; }
                $refs[] = ['id' => $iid, 'kind' => $it['kind'], 'key' => $applied[$iid]['key'], 'ref' => $applied[$iid]['ref'], 'draft' => true];
            }
            if (isset($failedDocs[$doc['id']])) $covered = false;
            if ($refs === [] && !$covered) continue;
            $u = ['applied' => $refs];
            if ($doc['utility'] !== null) {
                $u['assignment'] = ['utility' => $doc['utility'], 'meter_id' => $res['meters'][$doc['meter_key'] ?? ''] ?? null,
                    'contract_id' => $res['contracts'][$doc['contract_key'] ?? ''] ?? null];
            }
            if ($covered) $u['status'] = 'done';
            $updates[$doc['id']] = $u;
        }
        // Wohl doppelt: abgelegt, wenn das Original jetzt oder schon vorher übernommen ist.
        // `duplicate_of` wird nicht gespeichert — Liste und Akte rechnen es aus dem
        // Inhalt (InboxService::olderDuplicateOf), ein gespeicherter Wert könnte nur veralten.
        $status = array_column($this->inbox->all(), 'status', 'id');
        foreach ($draft['documents'] as $doc) {
            if ($doc['state'] !== 'duplicate') continue;
            $of = (string)$doc['duplicate_of'];
            if (($updates[$of]['status'] ?? $status[$of] ?? null) === 'done') $updates[$doc['id']] = ['status' => 'filed'];
        }
        return $updates;
    }
}
