<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

use Energietracker\Config\Utilities;
use Energietracker\Services\ActivityService;
use Energietracker\Services\I18nService;
use Energietracker\Services\InboxService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (Skeptiker M1) — die eine Fassung der Übernahme eines Vorschlags, für beide Wege:
 *
 *   viaKey()  `apply_proposal` mit `mcp_apply_in_app` aus (Schlüssel, Zusammenfassung wörtlich)
 *   inApp()   `POST /api/mcp/proposals/{id}/apply` (Verwalter, Karte „KI-Assistenten“)
 *
 * Beide holen zuerst die globale Schreibsperre (PendingChanges), lesen dann frisch und
 * schreiben über die Dienste wie die Handeingabe: Ablesung mit `source: mcp` und
 * `client_ref` des Vorschlags, Übernahme aus dem Posteingang über InboxService::apply,
 * jeweils mit Verlaufseintrag. Der Akteur nennt den Schlüssel, in der App dazu die
 * Person, die bestätigt hat. Die Liste der Änderungen (`changes`) ist die Zusammenfassung,
 * die bestätigt wurde.
 *
 * Ein Vorschlag kann in der App bis zu einem Tag warten: Beim Übernehmen laufen die
 * Prüfungen noch einmal; kommt eine Rückfrage dazu, die der bestätigte Satz nicht nennt
 * (etwa ein später erfasster Stand), wird nichts geschrieben und die KI legt neu an.
 */
final class ProposalApplier
{
    public function __construct(private Services $services) {}

    /**
     * @return array{result: array<string,mixed>, repeated: bool, proposal: array<string,mixed>}
     */
    public function viaKey(string $id, string $summary, McpContext $ctx): array
    {
        $by = ['type' => 'key', 'name' => $ctx->keyName, 'via' => 'mcp'];
        return $this->services->get(PendingChanges::class)->consume($id, $ctx, $summary,
            fn(array $payload, array $proposal): array => $this->write($payload, $proposal, $by));
    }

    /**
     * @param array{type?:string, id?:string, name?:string}|null $person wer bestätigt (ohne Anmeldung null)
     * @param list<string>|null $activeKeys gültige Schlüssel: Der Vorschlag eines widerrufenen gilt nicht mehr
     * @return array{result: array<string,mixed>, repeated: bool, proposal: array<string,mixed>}
     */
    public function inApp(string $id, ?array $person, ?array $activeKeys = null): array
    {
        $confirmed = is_array($person) && ($person['type'] ?? null) === 'person'
            ? array_filter(['type' => 'person', 'id' => $person['id'] ?? null, 'name' => $person['name'] ?? null], fn($v) => $v !== null)
            : ['type' => 'app'];
        return $this->services->get(PendingChanges::class)->consumeInApp($id,
            fn(array $payload, array $proposal): array => $this->write($payload, $proposal,
                ['type' => 'key', 'name' => (string)$proposal['key_name'], 'via' => 'mcp', 'confirmed_by' => $confirmed]),
            $activeKeys, is_string($confirmed['name'] ?? null) ? $confirmed['name'] : null);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $proposal öffentliche Felder des Vorschlags
     * @param array<string,mixed> $by Akteur im Verlauf
     * @return array<string,mixed>
     */
    private function write(array $payload, array $proposal, array $by): array
    {
        $activity = $this->services->get(ActivityService::class);
        return $activity->withActor($by, fn(): array => match ($proposal['kind']) {
            'reading'  => $this->applyReading($payload, (string)$proposal['id'], (string)$proposal['summary']),
            'document' => $this->applyDocument($payload, (string)$proposal['summary']),
            // Programmierfehler: Client bekommt errors.mcp.internal mit Fehler-ID
            default    => throw new \LogicException("mcp: proposal kind {$proposal['kind']} has no apply step"),
        });
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyReading(array $payload, string $proposalId, string $summary): array
    {
        $meters = $this->services->get(MeterService::class);
        $readings = $this->services->get(ReadingService::class);
        $i18n = $this->services->get(I18nService::class);
        $u = (string)$payload['utility'];
        $mid = (string)$payload['meter_id'];
        $date = (string)$payload['date'];
        $ref = PendingChanges::clientRef($proposalId);
        $meter = $meters->get($u, $mid);
        if ($meter === null) throw new LocalizedException('errors.common.meterNotFound', ['id' => $mid], 'apply: meter gone');
        ReadingService::assertCounterMeter($meter);
        if ($meters->deviceOnDate($meter, $date) === null) throw new LocalizedException('errors.reading.noDevice', ['date' => $date], 'apply: no device');
        // seit dem Vorschlag ein Stand am selben Tag dazugekommen? Dann nicht doppeln
        foreach ($readings->list($u, $mid) as $x) {
            if (($x['date'] ?? null) === $date && empty($x['is_future']) && empty($x['is_suspect']) && ($x['client_ref'] ?? null) !== $ref) {
                throw new LocalizedException('errors.mcp.reading.sameDay', ['date' => $i18n->date($date),
                    'counter' => self::num($i18n, (float)($x['counter'] ?? 0)), 'unit' => (string)Utilities::get($u)['unit']], 'apply: same day');
            }
        }
        // eine neue Rückfrage, die der bestätigte Satz nicht nennt: nichts schreiben, neu vorschlagen
        $known = array_map('strval', (array)($payload['issues'] ?? []));
        $now = array_column($readings->plausibilityFor($u, $meter, $date, (float)$payload['counter'])['issues'], 'type');
        if (array_diff($now, $known) !== []) throw new LocalizedException('errors.mcp.proposal.stale', [], 'apply: new issues');
        $reading = $readings->create($u, [
            'meter_id' => $mid, 'date' => $date, 'counter' => (float)$payload['counter'], 'is_estimated' => !empty($payload['is_estimated']),
            'note' => (string)($payload['note'] ?? ''), 'source' => 'mcp', 'client_ref' => $ref,
        ]);
        $ids = [];
        if (empty($reading['duplicate'])) {
            $ids[] = (string)$this->services->get(ActivityService::class)->record([
                'action'    => 'updated',
                'subject'   => ['kind' => 'reading', 'uid' => "reading:$u:" . $reading['id'], 'occurrence' => "reading:$u:" . $reading['id'] . '@mcp'],
                'ref'       => ['utility' => $u, 'meter_id' => $mid, 'reading_id' => (string)$reading['id'], 'proposal_id' => $proposalId],
                'title_key' => 'activity.mcp.reading',
                'params'    => ['label' => $i18n->utilityLabel($u), 'date' => $date],
                'changes'   => $summary,
            ])['id'];
        }
        return ['applied' => [['kind' => 'reading', 'ref' => ['type' => 'reading', 'utility' => $u, 'meter_id' => $mid, 'id' => (string)$reading['id']]]],
                'skipped' => [], 'failed' => [], 'activity_ids' => $ids];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyDocument(array $payload, string $summary): array
    {
        $inbox = $this->services->get(InboxService::class);
        $id = (string)$payload['document_id'];
        // Zuordnung seit dem Vorschlag geändert? Die Vorschläge zielten dann woanders hin
        $now = $inbox->preview($id)['assignment'];
        $was = (array)$payload['assignment'];
        foreach (['utility', 'meter_id', 'contract_id'] as $k) {
            if (($now[$k] ?? null) !== ($was[$k] ?? null)) throw new LocalizedException('errors.inbox.proposalStale', [], 'apply: assignment changed');
        }
        // die IDs der Vorschläge hängen am Inhalt (Art und Daten): geändert → errors.inbox.proposalStale
        $res = $inbox->apply($id, array_values(array_map('strval', (array)$payload['proposal_ids'])), $summary);
        $ref = fn(mixed $r): array => is_array($r) ? array_filter($r, fn($v) => is_string($v) && $v !== '') : [];
        return [
            'applied'      => array_map(fn($a) => ['kind' => (string)$a['kind'], 'ref' => $ref($a['ref'] ?? null)], $res['applied']),
            'skipped'      => array_map(fn($a) => ['kind' => (string)$a['kind'], 'reason' => (string)($a['reason'] ?? '')], $res['skipped']),
            'failed'       => array_map(fn($a) => ['kind' => (string)$a['kind'], 'code' => (string)($a['code'] ?? 'errors.mcp.toolFailed')], $res['failed']),
            'activity_ids' => is_string($res['activity_id'] ?? null) ? [$res['activity_id']] : [],
            'document'     => ['id' => $id, 'status' => (string)($res['entry']['status'] ?? '')],
        ];
    }

    /** Zahl mit den Stellen, die sie hat (höchstens `$max`), in der Schreibweise der Installation. */
    public static function num(I18nService $i18n, float $v, int $max = 3): string
    {
        $s = rtrim(rtrim(number_format($v, $max, '.', ''), '0'), '.');
        $dot = strpos($s, '.');
        return $i18n->number($v, $dot === false ? 0 : strlen($s) - $dot - 1);
    }
}
