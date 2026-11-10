<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp\Tools;

use Energietracker\Config\Utilities;
use Energietracker\Services\ContractService;
use Energietracker\Services\I18nService;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\InboxService;
use Energietracker\Services\Mcp\Config;
use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\Num;
use Energietracker\Services\Mcp\PendingChanges;
use Energietracker\Services\Mcp\ProposalApplier;
use Energietracker\Services\Mcp\Provenance;
use Energietracker\Services\Mcp\Sanitize;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolGroup;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Support\Dates;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037, Paket schreiben) — Ändern nur in zwei Schritten (Konzept §3.3,
 * Entscheidung 8a):
 *
 *   propose_reading   (mcp_write)  Ablesung vorschlagen: geprüft wie beim Speichern,
 *                                  dazu die Rückfragen der Erfassung; schreibt nichts
 *                                  außer dem Vorschlag
 *   propose_document  (mcp_write)  Übernahme aus einem Dokument des Posteingangs
 *                                  vorschlagen (nur Vorschläge ohne Prüfmarke)
 *   apply_proposal    (mcp_write)  übernehmen: an den Schlüssel gebunden, einmalig, nur
 *                                  mit der Zusammenfassung wörtlich. v3.12.0 (Skeptiker M1):
 *                                  Mit `mcp_apply_in_app` (ab Werk an) schreibt es nichts und
 *                                  antwortet `status: awaiting_confirmation`, die Person bestätigt
 *                                  in der App (bis 24 Stunden). Aus: es schreibt (15 Minuten);
 *                                  der Client wird gebeten nachzufragen (META_CONFIRM), prüfen
 *                                  kann der Server das nicht
 *   cancel_proposal   (mcp_write)  verwerfen
 *
 * Die Zusammenfassung baut der Server aus Strukturdaten in der Sprache der
 * Installation: Verbrauchsart, Zahlen, Tage, Arten aus dem Katalog. v3.12.0 (Skeptiker H2):
 * alles, was Geld oder Fristen bewegt (Beträge, Preise, Laufzeit, Frist, Nachzahlung,
 * Guthaben), denn sie ist der einzige Schutz gegen ein Dokument mit falschen Zahlen. Nie
 * Fremdtext (Zählernamen, Notizen, Anbieter, Dateinamen, Zitate); die stehen nur
 * unter `untrusted` (Konzept §4.2). Übernehmen: Mcp\ProposalApplier (eine Fassung für
 * `apply_proposal` und die App).
 *
 * Strenger als die Oberfläche, weil niemand das Formular sieht: kein Datum in der
 * Zukunft, kein zweiter Stand am selben Tag (die Oberfläche ersetzt ihn), kein
 * stilles Vorverlegen des ersten Geräts, keine Vorschläge mit Prüfmarke.
 */
final class WriteTools extends ToolGroup
{
    /** `_meta` an apply_proposal: der Client fragt vor jedem Aufruf die Person (auch bei „immer erlauben“) */
    public const META_CONFIRM = 'anthropic/requiresUserInteraction';
    public const NOTE_MAX = 200;
    /** v3.12.0 (Skeptiker H2) — die Zusammenfassung nennt jetzt jeden Betrag und Preis */
    public const SUMMARY_MAX = 6000;
    /** v3.12.0 (Skeptiker N14) — kein Zählwerk hat mehr als zehn Stellen vor dem Komma */
    public const MAX_COUNTER = 9999999999;
    public const PROPOSAL_PATTERN = '^mp_[0-9a-f]{24}$';
    /** Arten einer Übernahme aus dem Posteingang (Inbox\Applier) — Beschriftung `mcp.summary.kind.<art>` */
    public const KINDS = ['reading', 'bill', 'bill_book', 'contract_create', 'contract_start', 'contract_end', 'contract_ids',
        'contract_supply_kind', 'contract_price', 'contract_base_price', 'contract_water_price', 'contract_advance', 'meter_ids',
        'meter_change', 'gas_factor', 'reminder'];
    /** Wer bestätigt: die Person in der App (`mcp_apply_in_app`) oder die Nachfrage des KI-Programms */
    public const CONFIRM_IN = ['app', 'client'];
    /** Arten der Vorschläge der KI (PendingChanges) */
    public const PROPOSAL_KINDS = ['reading', 'document'];

    public function tools(): array
    {
        $pid = ['type' => 'string', 'pattern' => self::PROPOSAL_PATTERN];
        return [
            new Tool('propose_reading', Tool::WRITE, [
                'type'       => 'object',
                'properties' => [
                    'utility'      => ['type' => 'string', 'enum' => self::counterUtilities()],
                    'meter'        => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                    'date'         => ['type' => 'string', 'format' => 'date'],
                    'counter'      => ['type' => 'number', 'minimum' => 0, 'maximum' => self::MAX_COUNTER],
                    'is_estimated' => ['type' => 'boolean', 'default' => false],
                    'note'         => ['type' => 'string', 'maxLength' => self::NOTE_MAX],
                ],
                'required'             => ['utility', 'counter'],
                'additionalProperties' => false,
            ], fn(array $a, McpContext $ctx): array => $this->proposeReading($a, $ctx), self::readingSchema(),
                // schreibt nur den Vorschlag, nichts am Haushalt
                ['readOnlyHint' => true, 'idempotentHint' => false]),
            new Tool('propose_document', Tool::WRITE, [
                'type'       => 'object',
                'properties' => [
                    'document_id' => ['type' => 'string', 'pattern' => DocumentTools::ID_PATTERN],
                    'proposals'   => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^p_[0-9a-f]{12}$'], 'minItems' => 1, 'maxItems' => 50],
                ],
                'required'             => ['document_id'],
                'additionalProperties' => false,
            ], fn(array $a, McpContext $ctx): array => $this->proposeDocument($a, $ctx), self::documentProposalSchema(),
                ['readOnlyHint' => true, 'idempotentHint' => false]),
            new Tool('apply_proposal', Tool::WRITE, [
                'type'       => 'object',
                'properties' => [
                    'proposal_id' => $pid,
                    'summary'     => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::SUMMARY_MAX],
                ],
                'required'             => ['proposal_id', 'summary'],
                'additionalProperties' => false,
            ], fn(array $a, McpContext $ctx): array => $this->apply($a, $ctx), self::applySchema(),
                // v3.12.0 (Skeptiker M1) — ändert bestehende Daten (Vertragsende, Preise); die Rückfrage bleibt auch mit
                // Bestätigung in der App stehen: Ein Client merkt sich die Liste, der Schalter kann sich ändern
                ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
                [self::META_CONFIRM => true]),
            new Tool('cancel_proposal', Tool::WRITE, [
                'type'                 => 'object',
                'properties'           => ['proposal_id' => $pid],
                'required'             => ['proposal_id'],
                'additionalProperties' => false,
            ], fn(array $a, McpContext $ctx): array => ['cancelled' => $this->services->get(PendingChanges::class)->cancel((string)$a['proposal_id'], $ctx)],
                ['type' => 'object', 'properties' => ['cancelled' => ['type' => 'boolean']], 'required' => ['cancelled'], 'additionalProperties' => false],
                ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true]),
        ];
    }

    /** Beschriftung einer Übernahme-Art in der Sprache des Servers (Katalog, sonst die Art). */
    public static function kindLabel(I18nService $i18n, string $kind, string $locale): string
    {
        $key = "mcp.summary.kind.$kind";
        $label = $i18n->t($key, [], $locale);
        return $label !== $key ? $label : $kind;
    }

    /** @return list<string> Verbrauchsarten mit Zählerständen (SSOT) */
    private static function counterUtilities(): array
    {
        return array_values(array_filter(Utilities::keys(), fn(string $u) => Utilities::isCumulative($u)));
    }

    // ── Ablesung vorschlagen ─────────────────────────────────────────────

    /**
     * @param array{utility:string, meter?:string, date?:string, counter:float|int, is_estimated?:bool, note?:string} $args
     * @return array<string,mixed>
     */
    private function proposeReading(array $args, McpContext $ctx): array
    {
        $meters = $this->services->get(MeterService::class);
        $readings = $this->services->get(ReadingService::class);
        $i18n = $this->services->get(I18nService::class);
        $u = (string)$args['utility'];
        $unit = (string)Utilities::get($u)['unit'];
        $meter = $this->meterFor($u, isset($args['meter']) ? (string)$args['meter'] : null);
        ReadingService::assertCounterMeter($meter);
        $mid = (string)$meter['id'];
        $date = (string)($args['date'] ?? $ctx->today);
        $counter = (float)$args['counter'];
        $estimated = ($args['is_estimated'] ?? false) === true;
        if ($date > $ctx->today) throw new LocalizedException('errors.mcp.reading.future', ['date' => $i18n->date($date)], 'propose_reading: future');
        // ein Gerät an dem Tag; das erste Gerät verlegt die KI nie still vor (die Oberfläche tut es)
        if ($meters->deviceOnDate($meter, $date) === null) throw new LocalizedException('errors.reading.noDevice', ['date' => $date], 'propose_reading: no device');

        $check = $readings->plausibilityFor($u, $meter, $date, $counter);
        foreach ($check['real'] as $r) {
            if ($r['date'] === $date) {
                throw new LocalizedException('errors.mcp.reading.sameDay', ['date' => $i18n->date($date),
                    'counter' => self::num($i18n, (float)$r['counter']), 'unit' => $unit], 'propose_reading: same day');
            }
        }
        $prev = $check['prev'];
        // Gerätetausch zwischen vorigem und neuem Stand erklärt Rückgang und Sprung (wie lib/plausibility.js)
        $swapped = $check['device_changed'];
        $typical = $check['typical'];
        $issues = $check['issues'];

        $note = Sanitize::text((string)($args['note'] ?? ''), self::NOTE_MAX);
        $summary = $this->readingSummary($u, $mid, $date, $counter, $estimated, $issues, $ctx);
        // v3.12.0 — der Zählername wie in get_meters maskiert (er kann eine Kennnummer tragen)
        $untrusted = Sanitize::untrusted(['name' => $this->masked((string)($meter['name'] ?? '')), 'note' => $note]);
        $p = $this->services->get(PendingChanges::class)->create($ctx, 'reading',
            ['utility' => $u, 'meter_id' => $mid, 'date' => $date, 'counter' => $counter, 'is_estimated' => $estimated, 'note' => $note,
             'issues' => array_column($issues, 'type')], $summary, $this->ttl(), array_intersect_key($untrusted, ['name' => 1, 'note' => 1]));

        $checks = ['device_changed' => $swapped, 'issues' => array_map(fn($x) => ['type' => $x['type'],
            'message' => $this->issueText($x, $unit, $ctx)], $issues)];
        if ($prev !== null) {
            $prevBasis = empty($prev['is_estimated']) ? 'measured' : 'estimated';
            $days = (int)round((strtotime($date . 'T00:00:00Z') - strtotime($prev['date'] . 'T00:00:00Z')) / 86400);
            $delta = $counter - (float)$prev['counter'];
            $basis = Provenance::weakest('entered', $prevBasis);
            $checks['previous'] = ['date' => (string)$prev['date'], 'counter' => Num::of((float)$prev['counter'], $unit, $prevBasis, ['as_of' => (string)$prev['date']])];
            $checks['days'] = $days;
            $checks['delta'] = Num::of($delta, $unit, $basis, ['derived' => true]);
            if ($days >= 1) $checks['per_day'] = Num::of($delta / $days, "$unit/d", $basis, ['derived' => true]);
        }
        if ($typical !== null) $checks['typical_per_day'] = Num::of($typical, "$unit/d", 'measured', ['derived' => true]);
        return [
            'proposal_id' => $p['id'],
            'expires_at'  => $p['expires_at'],
            'summary'     => $summary,
            'reading'     => ['utility' => $u, 'meter_id' => $mid, 'date' => $date,
                              'counter' => Num::of($counter, $unit, $estimated ? 'estimated' : 'entered'), 'is_estimated' => $estimated],
            'checks'      => $checks,
            'untrusted'   => $untrusted,
        ] + $this->confirmation($p, $ctx);
    }

    /**
     * Zähler zu einer Angabe: ID oder Alias (`external_id`); ohne Angabe der eine
     * Zähler mit Ständen in Betrieb, sonst Fehler mit Hinweis auf list_meters.
     *
     * @return array<string,mixed>
     */
    private function meterFor(string $u, ?string $ref): array
    {
        $meters = $this->services->get(MeterService::class);
        if ($ref !== null) {
            $m = $meters->get($u, $ref) ?? $meters->getByExternalId($u, $ref);
            if ($m === null) throw new LocalizedException('errors.common.meterNotFound', ['id' => mb_substr($ref, 0, 40)], 'propose_reading: unknown meter');
            return $m;
        }
        $candidates = array_values(array_filter($meters->list($u), fn($m) => MeterService::inService($m) && (($m['capture'] ?? 'counter') !== 'period')));
        if (count($candidates) !== 1) throw new LocalizedException('errors.mcp.reading.meterRequired', [], 'propose_reading: meter ambiguous');
        return $candidates[0];
    }

    private function readingSummary(string $u, string $mid, string $date, float $counter, bool $estimated, array $issues, McpContext $ctx): string
    {
        $i18n = $this->services->get(I18nService::class);
        $s = $i18n->t('mcp.summary.reading', [
            'where'     => $this->meterWhere($u, $mid, $ctx),
            'counter'   => self::num($i18n, $counter),
            'unit'      => (string)Utilities::get($u)['unit'],
            'date'      => $i18n->date($date),
            'estimated' => $i18n->t($estimated ? 'mcp.summary.yes' : 'mcp.summary.no', [], $ctx->locale),
        ], $ctx->locale);
        foreach ($issues as $x) $s .= ' ' . $i18n->t('mcp.summary.issue.' . $x['type'], [], $ctx->locale);
        return $s;
    }

    /**
     * v3.12.0 (Skeptiker M2) — welcher Zähler, ohne freien Text und ohne Kennung: die Art, bei
     * mehreren „Zähler 2 von 2“ bzw. „Subzähler 1 von 1“ in der Reihenfolge der App. Den Namen
     * liefert `untrusted.name` daneben, die Karte zeigt ihn beim Bestätigen.
     */
    private function meterWhere(string $u, string $mid, McpContext $ctx): string
    {
        $name = $this->utilityName($u, $ctx);
        $list = array_values(array_filter($this->services->get(MeterService::class)->list($u),
            fn($m) => ($m['capture'] ?? 'counter') !== 'period' && (MeterService::inService($m) || (string)($m['id'] ?? '') === $mid)));
        $self = null;
        foreach ($list as $m) if ((string)($m['id'] ?? '') === $mid) $self = $m;
        if ($self === null || count($list) < 2) return $name;
        $sub = !empty($self['parent_meter_id']);
        $same = array_values(array_filter($list, fn($m) => !empty($m['parent_meter_id']) === $sub));
        $pos = (int)array_search($mid, array_map(fn($m) => (string)($m['id'] ?? ''), $same), true) + 1;
        $i18n = $this->services->get(I18nService::class);
        return $name . ', ' . $i18n->t($sub ? 'mcp.summary.subMeter' : 'mcp.summary.meter', ['n' => $pos, 'of' => count($same)], $ctx->locale);
    }

    /** Gültigkeit eines neuen Vorschlags: Über die App bis zu einem Tag, sonst 15 Minuten. */
    private function ttl(): int
    {
        return $this->services->get(Config::class)->applyInApp() ? PendingChanges::TTL_APP_SECONDS : PendingChanges::TTL_SECONDS;
    }

    /**
     * v3.12.0 (Skeptiker M1) — Wer bestätigt, und was die KI der Person sagt.
     *
     * @param array<string,mixed> $p Vorschlag
     * @return array{confirm_in:string, message?:string}
     */
    private function confirmation(array $p, McpContext $ctx): array
    {
        if (!$this->services->get(Config::class)->applyInApp()) return ['confirm_in' => 'client'];
        $i18n = $this->services->get(I18nService::class);
        $path = implode(' → ', array_map(fn(string $k) => $i18n->t($k, [], $ctx->locale),
            ['nav.todo', 'todo.filter.ki', 'settings.mcp.apply']));
        $ts = strtotime((string)$p['expires_at']);
        return ['confirm_in' => 'app', 'message' => $i18n->t('mcp.proposal.confirmInApp', ['path' => $path,
            'date' => $i18n->date(date('Y-m-d', (int)$ts)), 'time' => date('H:i', (int)$ts)], $ctx->locale)];
    }

    /** Text einer Rückfrage wie in der Erfassung (Katalog `readingCheck.*`). */
    private function issueText(array $x, string $unit, McpContext $ctx): string
    {
        $i18n = $this->services->get(I18nService::class);
        $p = ['unit' => $unit];
        if (isset($x['last'])) $p['last'] = self::num($i18n, (float)$x['last']);
        if (isset($x['per_day'])) $p['perDay'] = $i18n->number((float)$x['per_day'], 1);
        if (isset($x['typical'])) $p['typical'] = $i18n->number((float)$x['typical'], 1);
        if (isset($x['next'])) $p['next'] = self::num($i18n, (float)$x['next']);
        return $i18n->t('readingCheck.' . $x['type'], $p, $ctx->locale);
    }

    // ── Übernahme aus einem Dokument vorschlagen ─────────────────────────

    /**
     * @param array{document_id:string, proposals?:list<string>} $args
     * @return array<string,mixed>
     */
    private function proposeDocument(array $args, McpContext $ctx): array
    {
        $id = (string)$args['document_id'];
        $pv = $this->services->get(InboxService::class)->preview($id);
        $e = $pv['entry'];
        $type = (string)($e['type'] ?? '');
        if (!InboxSchema::extracts($type) || !is_array($e['extraction']['fields'] ?? null) || $e['extraction']['fields'] === []) {
            throw new LocalizedException('errors.inbox.notRead', [], 'propose_document: not read');
        }
        $u = $pv['assignment']['utility'] ?? null;
        if (!is_string($u) || $u === '') throw new LocalizedException('errors.inbox.assignmentMissing', [], 'propose_document: no utility');
        $byId = array_column((array)$pv['proposals'], null, 'id');
        if (isset($args['proposals'])) {
            $selected = [];
            foreach (array_values(array_unique(array_map('strval', $args['proposals']))) as $pid) {
                if (!isset($byId[$pid])) throw new LocalizedException('errors.inbox.proposalStale', [], "propose_document: unknown $pid");
                $selected[] = $byId[$pid];
            }
        } else {
            $selected = array_values(array_filter($byId, fn($p) => !empty($p['checked'])));
        }
        // Konzept §4.2: nur Vorschläge, deren Felder Inbox\Evidence belegt; mit Prüfmarke nur in der App
        foreach ($selected as $p) {
            if (!empty($p['check'])) throw new LocalizedException('errors.mcp.document.needsReview', ['id' => (string)$p['id']], 'propose_document: flagged');
        }
        $selected = array_values(array_filter($selected, fn($p) => ($p['status'] ?? '') !== 'exists'));
        if ($selected === []) throw new LocalizedException('errors.mcp.document.nothingToApply', [], 'propose_document: nothing');

        $picked = array_flip(array_column($selected, 'id'));
        $warnings = [];
        $issues = [];
        foreach ($selected as $p) {
            if (is_string($p['requires'] ?? null) && !isset($picked[$p['requires']])) $warnings[] = ['proposal' => (string)$p['id'], 'reason' => 'requires', 'requires' => $p['requires']];
            if (in_array($p['status'] ?? '', ['conflict', 'overlap'], true)) $warnings[] = ['proposal' => (string)$p['id'], 'reason' => (string)$p['status']];
            if (!empty($p['open'])) $warnings[] = ['proposal' => (string)$p['id'], 'reason' => 'open'];
            // dieselben Rückfragen wie bei propose_reading: ein Stand aus dem Dokument, der nicht zum Verlauf passt
            foreach ($p['kind'] === 'reading' ? $this->readingIssues($p, $u) : [] as $x) {
                $warnings[] = ['proposal' => (string)$p['id'], 'reason' => $x['type']];
                $issues[$x['type']] = $x;
            }
        }
        $as = ['utility' => $u, 'meter_id' => $pv['assignment']['meter_id'] ?? null, 'contract_id' => $pv['assignment']['contract_id'] ?? null];
        $summary = $this->documentSummary($id, $type, $u, $selected, $ctx);
        foreach ($issues as $x) $summary .= ' ' . $this->services->get(I18nService::class)->t('mcp.summary.issue.' . $x['type'], [], $ctx->locale);
        // v3.12.0 (Skeptiker H2) — der Anbieter laut Dokument: Fremdtext, also nie im Satz, sondern daneben und auf der Karte
        $tools = new DocumentTools($this->services);
        $supplier = $tools->mask($e)->quote(is_scalar(Proposals::value($e, 'supplier')) ? (string)Proposals::value($e, 'supplier') : null);
        $untrusted = Sanitize::untrusted(['provider' => $supplier]);
        $p = $this->services->get(PendingChanges::class)->create($ctx, 'document',
            ['document_id' => $id, 'proposal_ids' => array_keys($picked), 'assignment' => $as], $summary, $this->ttl(), $untrusted);
        $i18n = $this->services->get(I18nService::class);
        return [
            'proposal_id' => $p['id'],
            'expires_at'  => $p['expires_at'],
            'summary'     => $summary,
            'document_id' => $id,
            'utility'     => $u,
            'items'       => array_map(fn($x) => ['id' => (string)$x['id'], 'kind' => (string)$x['kind'],
                'label' => self::kindLabel($i18n, (string)$x['kind'], $ctx->locale), 'status' => (string)($x['status'] ?? 'new'),
                'text' => $this->itemText($x, $u, $ctx, $selected)], $selected),
            'warnings'    => $warnings,
        ] + ($untrusted !== [] ? ['untrusted' => $untrusted] : []) + $this->confirmation($p, $ctx);
    }

    /**
     * Rückfragen zu einem Stand aus dem Dokument (ReadingService::plausibilityIssues)
     * gegen die echten Stände des Zählers davor.
     *
     * @param array<string,mixed> $p Vorschlag `reading` aus Inbox\Proposals
     * @return list<array{type:string}>
     */
    private function readingIssues(array $p, string $u): array
    {
        $d = (array)($p['data'] ?? []);
        $ru = is_string($d['utility'] ?? null) && Utilities::exists($d['utility']) ? $d['utility'] : $u;
        $meter = is_string($d['meter_id'] ?? null) ? $this->services->get(MeterService::class)->get($ru, $d['meter_id']) : null;
        if ($meter === null || !is_string($d['date'] ?? null) || !is_numeric($d['counter'] ?? null)) return [];
        return $this->services->get(ReadingService::class)->plausibilityFor($ru, $meter, (string)$d['date'], (float)$d['counter'])['issues'];
    }

    /** „Gas: aus dem Dokument inb_… (Rechnung) übernehmen: Ablesung (31.12.2025, 13.580 m³); …“ */
    private function documentSummary(string $id, string $type, string $u, array $selected, McpContext $ctx): string
    {
        $i18n = $this->services->get(I18nService::class);
        $typeLabel = $i18n->t("inbox.type.$type", [], $ctx->locale);
        return $i18n->t('mcp.summary.document', [
            'utility' => $this->utilityName($u, $ctx),
            'id'      => $id,
            'type'    => $typeLabel !== "inbox.type.$type" ? $typeLabel : $type,
            'items'   => implode('; ', array_map(fn($p) => $this->itemText($p, $u, $ctx, $selected), $selected)),
        ], $ctx->locale);
    }

    /**
     * Eine Änderung in Worten, nur aus Art, Tagen und Zahlen (nie Anbieter,
     * Tarif, Zählername oder Nummer aus dem Dokument). v3.12.0 (Skeptiker H2) — mit allem, was
     * Geld oder Fristen bewegt: Rechnung (Zeitraum, Menge, Betrag, Abschläge), Ergebnis
     * (Nachzahlung oder Guthaben), Vertrag (Beginn, Ende, Preise, Abschlag, Frist, Laufzeit,
     * Preisgarantie, Bonus). Die Kennnummern bleiben draußen: Der Satz geht an die KI.
     *
     * @param array<string,mixed> $p Vorschlag aus Inbox\Proposals
     */
    private function itemText(array $p, string $u, McpContext $ctx, array $selected = []): string
    {
        $i18n = $this->services->get(I18nService::class);
        $pr = (array)($p['params'] ?? []);
        $d = (array)($p['data'] ?? []);
        $day = fn(string $k): ?string => is_string($pr[$k] ?? null) && Dates::isIsoDate($pr[$k]) ? $i18n->date($pr[$k]) : null;
        $n = fn(string $k): ?float => is_int($pr[$k] ?? null) || is_float($pr[$k] ?? null) ? (float)$pr[$k] : null;
        $span = fn(string $a, string $b): ?string => $day($a) !== null ? $day($a) . ($day($b) !== null ? ' – ' . $day($b) : '') : null;
        $ru = is_string($d['utility'] ?? null) && Utilities::exists($d['utility']) ? $d['utility'] : $u;
        $parts = match ((string)$p['kind']) {
            'reading'              => [$day('date'), $n('counter') !== null ? self::num($i18n, (float)$n('counter')) . ' ' . Utilities::get($ru)['unit'] : null],
            'bill'                 => [$span('from', 'to'), ...$this->billParts((array)($d['invoice'] ?? []), $ru, $ctx), ...$this->billExisting($p, $selected, $ctx)],
            'bill_book'            => [$this->resultPart($n('result'), $ctx), $n('before') !== null ? $this->part('before', ['amount' => $i18n->money(abs((float)$n('before')))], $ctx) : null],
            'contract_create'      => $this->contractParts($d, $ru, $ctx),
            'contract_start', 'contract_end', 'reminder' => [$day('date')],
            'meter_change'         => $this->meterChangeParts($day('date'), $d, $ru, $ctx),
            'contract_price'       => [$day('from'), $n('value') !== null ? self::num($i18n, (float)$n('value'), 4) . ' ' . $i18n->currencyParams()['minor'] . '/kWh' : null],
            'contract_base_price' => [$day('from'), $n('value') !== null ? $i18n->money((float)$n('value')) : null],
            'contract_advance'     => [$day('from'), $n('value') !== null ? $i18n->money((float)$n('value')) : null, $this->everyPart($pr['every'] ?? null, $ctx)],
            'contract_water_price' => [$day('from'), $this->waterPricePart($d, $pr, $ctx)],
            'gas_factor'           => [$day('from'), $n('factor') !== null ? self::num($i18n, (float)$n('factor'), 4) : null],
            // v3.11.0 — ohne den Wert wüsste die Person nicht, was sie bestätigt
            'contract_supply_kind' => [in_array($d['supply_kind'] ?? null, ContractService::SUPPLY_KINDS, true)
                ? $i18n->t('contracts.modal.supplyKindOption.' . $d['supply_kind'], [], $ctx->locale) : null],
            default                => [],
        };
        $parts = array_values(array_filter($parts, fn($x) => $x !== null && $x !== ''));
        $label = self::kindLabel($i18n, (string)$p['kind'], $ctx->locale);
        return $parts === [] ? $label : $label . ' (' . implode(', ', $parts) . ')';
    }

    /** @param array<string,scalar> $params */
    private function part(string $key, array $params, McpContext $ctx): string
    {
        return $this->services->get(I18nService::class)->t("mcp.summary.part.$key", $params, $ctx->locale);
    }

    /** Nachzahlung oder Guthaben in Worten (positiv = Nachzahlung, wie `result_eur`). */
    private function resultPart(?float $result, McpContext $ctx): ?string
    {
        if ($result === null || abs($result) < 0.005) return null;
        return $this->part($result > 0 ? 'due' : 'credit', ['amount' => $this->services->get(I18nService::class)->money(abs($result))], $ctx);
    }

    /** v3.12.0 (Nachprüfung B3) — Rhythmus eines Abschlags: Der Satz sagte 300,00 €, gebucht war alle zwei Monate. */
    private function everyPart(mixed $months, McpContext $ctx): ?string
    {
        return is_numeric($months) && (int)$months > 1 ? $this->services->get(I18nService::class)->tp('mcp.summary.part.everyMonths', (int)$months, [], $ctx->locale) : null;
    }

    /**
     * v3.12.0 (Nachprüfung B3) — was eine Rechnung mit dem Bestand macht: Sie ersetzt eine vorhandene desselben Zeitraums (eine
     * Buchung dazu bleibt, wenn keine neue dabei ist) oder steht neben einer, die sich überschneidet. Bis dahin stand das nur in `warnings`.
     *
     * @param array<string,mixed> $p Vorschlag `bill`
     * @param list<array<string,mixed>> $selected alle gewählten Vorschläge
     * @return list<?string>
     */
    private function billExisting(array $p, array $selected, McpContext $ctx): array
    {
        $i18n = $this->services->get(I18nService::class);
        $det = (array)($p['detail'] ?? []);
        if (($p['status'] ?? '') === 'overlap') {
            $date = fn(mixed $v): string => is_string($v) && Dates::isIsoDate($v) ? $i18n->date($v) : '';
            return [$this->part('overlapsBill', ['from' => $date($det['period_from'] ?? null), 'to' => $date($det['period_to'] ?? null)], $ctx)];
        }
        if (($p['status'] ?? '') !== 'conflict') return [];
        $old = (array)($det['invoice'] ?? []);
        $out = [is_numeric($old['amount_eur'] ?? null)
            ? $this->part('replacesBill', ['amount' => $i18n->money((float)$old['amount_eur'])], $ctx)
            : $this->part('replacesBillBare', [], $ctx)];
        $booked = !empty($det['special_payment_id']) && is_numeric($old['result_eur'] ?? null);
        if ($booked && !in_array('bill_book', array_column($selected, 'kind'), true)) {
            $result = $this->resultPart((float)$old['result_eur'], $ctx);
            if ($result !== null) $out[] = $this->part('keepsBooking', ['result' => $result], $ctx);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $inv Beträge und Menge der Rechnung (Inbox\Proposals)
     * @return list<?string>
     */
    private function billParts(array $inv, string $u, McpContext $ctx): array
    {
        $i18n = $this->services->get(I18nService::class);
        $f = fn(string $k): ?float => is_numeric($inv[$k] ?? null) ? (float)$inv[$k] : null;
        $qty = $u === 'wasser' ? $f('volume_m3') : $f('energy_kwh');
        return [
            $qty !== null ? self::num($i18n, $qty) . ' ' . Utilities::get($u)['consumption_unit'] : null,
            $f('amount_eur') !== null ? $this->part('amount', ['amount' => $i18n->money((float)$f('amount_eur'))], $ctx) : null,
            $f('advances_paid_eur') !== null ? $this->part('advances', ['amount' => $i18n->money((float)$f('advances_paid_eur'))], $ctx) : null,
            $this->resultPart($f('result_eur'), $ctx),
        ];
    }

    /**
     * Ein neuer Vertrag in Zahlen: alles, was er festlegt, aus den Daten des Vorschlags.
     *
     * @param array<string,mixed> $d Daten aus Inbox\Proposals
     * @return list<?string>
     */
    private function contractParts(array $d, string $u, McpContext $ctx): array
    {
        $i18n = $this->services->get(I18nService::class);
        $date = fn(mixed $v): ?string => is_string($v) && Dates::isIsoDate($v) ? $i18n->date($v) : null;
        $start = is_string($d['start'] ?? null) ? $d['start'] : '';
        // mehrere Preisstufen: jede mit ihrem Tag; die Stufe ab Vertragsbeginn ohne Tag
        $rows = function (mixed $list, string $key, callable $text) use ($date, $start, $ctx): array {
            $out = [];
            foreach ((array)$list as $r) {
                if (!is_array($r) || !is_numeric($r[$key] ?? null)) continue;
                $from = (string)($r['from'] ?? '') !== $start ? $date($r['from'] ?? null) : null;
                $out[] = $text((float)$r[$key], $r) . ($from !== null ? ' ' . $this->part('from', ['date' => $from], $ctx) : '');
            }
            return $out;
        };
        $out = [
            $date($d['start'] ?? null) !== null ? $this->part('from', ['date' => (string)$date($d['start'])], $ctx) : null,
            $date($d['end'] ?? null) !== null ? $this->part('until', ['date' => (string)$date($d['end'])], $ctx) : null,
        ];
        $minor = $i18n->currencyParams()['minor'];
        array_push($out, ...$rows($d['working_prices'] ?? [], 'ct_per_kwh', fn(float $v) => $this->part(Utilities::isFeedIn($u) ? 'feedIn' : 'working',
            ['value' => self::num($i18n, $v, 4), 'unit' => "$minor/" . Utilities::get($u)['consumption_unit']], $ctx)));
        array_push($out, ...$rows($d['base_prices'] ?? [], 'eur_per_month', fn(float $v) => $this->part('base', ['amount' => $i18n->money($v)], $ctx)));
        foreach (['trinkwasser', 'schmutzwasser'] as $section) {
            $sec = (array)($d[$section] ?? []);
            array_push($out, ...$rows($sec['working_prices'] ?? [], 'ct_per_m3', fn(float $v) => $this->part("water.$section", [
                'value' => self::num($i18n, $v, 4) . " $minor/m³"], $ctx)));
            array_push($out, ...$rows($sec['base_prices'] ?? [], 'eur_per_month', fn(float $v) => $this->part("water.$section", [
                'value' => $this->part('base', ['amount' => $i18n->money($v)], $ctx)], $ctx)));
        }
        array_push($out, ...$rows(((array)($d['niederschlagswasser'] ?? []))['rates'] ?? [], 'eur_per_m2_year', fn(float $v) => $this->part('water.niederschlagswasser', [
            'value' => $i18n->money($v) . '/m²'], $ctx)));
        array_push($out, ...$rows($d['advance_payments'] ?? [], 'amount_eur', fn(float $v, array $r) => trim($this->part('advance', ['amount' => $i18n->money($v)], $ctx) . ' ' . ($this->everyPart($r['every_months'] ?? null, $ctx) ?? ''))));
        if (is_numeric($d['notice_period_months'] ?? null) && (int)$d['notice_period_months'] > 0) {
            $out[] = $i18n->tp('mcp.summary.part.noticeMonths', (int)$d['notice_period_months'], [], $ctx->locale);
        } elseif (is_numeric($d['notice_period_days'] ?? null) && (int)$d['notice_period_days'] > 0) {
            $out[] = $i18n->tp('mcp.summary.part.noticeDays', (int)$d['notice_period_days'], [], $ctx->locale);
        }
        if ($date($d['min_term_end'] ?? null) !== null) $out[] = $this->part('minTerm', ['date' => (string)$date($d['min_term_end'])], $ctx);
        if ($date($d['price_guarantee_until'] ?? null) !== null) $out[] = $this->part('guarantee', ['date' => (string)$date($d['price_guarantee_until'])], $ctx);
        if (is_numeric($d['signup_bonus_eur'] ?? null)) $out[] = $this->part('bonus', ['amount' => $i18n->money((float)$d['signup_bonus_eur'])], $ctx);
        return $out;
    }

    /**
     * @param array<string,mixed> $d
     * @return list<?string>
     */
    private function meterChangeParts(?string $day, array $d, string $u, McpContext $ctx): array
    {
        $i18n = $this->services->get(I18nService::class);
        $unit = (string)Utilities::get($u)['unit'];
        return [$day,
            is_numeric($d['old_final_counter'] ?? null) ? $this->part('oldFinal', ['value' => self::num($i18n, (float)$d['old_final_counter']) . " $unit"], $ctx) : null,
            is_numeric($d['new_initial_counter'] ?? null) ? $this->part('newInitial', ['value' => self::num($i18n, (float)$d['new_initial_counter']) . " $unit"], $ctx) : null];
    }

    /**
     * Wasserpreis mit Sparte und Einheit (Liste und Schlüssel aus den Daten des Vorschlags).
     *
     * @param array<string,mixed> $d
     * @param array<string,mixed> $pr
     */
    private function waterPricePart(array $d, array $pr, McpContext $ctx): ?string
    {
        if (!is_numeric($pr['value'] ?? null)) return null;
        $i18n = $this->services->get(I18nService::class);
        $v = (float)$pr['value'];
        $text = match ((string)($d['key'] ?? '')) {
            'ct_per_m3'       => self::num($i18n, $v, 4) . ' ' . $i18n->currencyParams()['minor'] . '/m³',
            'eur_per_month'   => $this->part('base', ['amount' => $i18n->money($v)], $ctx),
            'eur_per_m2_year' => $i18n->money($v) . '/m²',
            default           => self::num($i18n, $v, 4),
        };
        $section = is_string($pr['section'] ?? null) ? $pr['section'] : '';
        return in_array($section, ['trinkwasser', 'schmutzwasser', 'niederschlagswasser'], true) ? $this->part("water.$section", ['value' => $text], $ctx) : $text;
    }

    // ── Übernehmen ───────────────────────────────────────────────────────

    /**
     * v3.12.0 (Skeptiker M1) — Mit `mcp_apply_in_app` schreibt dieses Werkzeug nichts: normales
     * Ergebnis mit `status: awaiting_confirmation`, damit die KI es sagt statt es zu wiederholen.
     * In der App schon übernommen → dessen Ergebnis (`repeated`); abgelehnt oder abgelaufen → die
     * Fehler wie sonst. Aus: es schreibt (Mcp\ProposalApplier, eine Fassung mit der App).
     *
     * @param array{proposal_id:string, summary:string} $args
     * @return array<string,mixed>
     */
    private function apply(array $args, McpContext $ctx): array
    {
        $id = (string)$args['proposal_id'];
        $summary = (string)$args['summary'];
        if ($this->services->get(Config::class)->applyInApp()) {
            $st = $this->services->get(PendingChanges::class)->status($id, $ctx, $summary);
            $p = $st['proposal'];
            if ($st['result'] !== null) {
                return ['proposal_id' => (string)$p['id'], 'kind' => (string)$p['kind'], 'status' => 'applied', 'repeated' => true] + $st['result'];
            }
            return ['proposal_id' => (string)$p['id'], 'kind' => (string)$p['kind'], 'status' => 'awaiting_confirmation', 'repeated' => false,
                    'summary' => (string)$p['summary'], 'expires_at' => (string)$p['expires_at']] + $this->confirmation($p, $ctx);
        }
        $r = (new ProposalApplier($this->services))->viaKey($id, $summary, $ctx);
        return ['proposal_id' => (string)$r['proposal']['id'], 'kind' => (string)$r['proposal']['kind'], 'status' => 'applied', 'repeated' => $r['repeated']] + $r['result'];
    }

    // ── Bausteine ────────────────────────────────────────────────────────

    private function utilityName(string $u, McpContext $ctx): string
    {
        $i18n = $this->services->get(I18nService::class);
        $name = $i18n->t("utilityNames.$u", [], $ctx->locale);
        return $name !== "utilityNames.$u" ? $name : $u;
    }

    /** Zahl mit den Stellen, die sie hat (höchstens `$max`), in der Schreibweise der Installation. */
    private static function num(I18nService $i18n, float $v, int $max = 3): string
    {
        return ProposalApplier::num($i18n, $v, $max);
    }

    // ── Ausgabeschemas ───────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function readingSchema(): array
    {
        $num = Num::schema();
        return [
            'type'       => 'object',
            'properties' => [
                'proposal_id' => ['type' => 'string', 'pattern' => self::PROPOSAL_PATTERN],
                'expires_at'  => ['type' => 'string'],
                'summary'     => ['type' => 'string'],
                'reading'     => ['type' => 'object', 'properties' => [
                    'utility' => ['type' => 'string'], 'meter_id' => ['type' => 'string'], 'date' => ['type' => 'string', 'format' => 'date'],
                    'counter' => $num, 'is_estimated' => ['type' => 'boolean'],
                ], 'required' => ['utility', 'meter_id', 'date', 'counter', 'is_estimated'], 'additionalProperties' => false],
                'checks'      => ['type' => 'object', 'properties' => [
                    'previous'        => ['type' => 'object', 'properties' => ['date' => ['type' => 'string', 'format' => 'date'], 'counter' => $num],
                                          'required' => ['date', 'counter'], 'additionalProperties' => false],
                    'days'            => ['type' => 'integer'],
                    'delta'           => $num,
                    'per_day'         => $num,
                    'typical_per_day' => $num,
                    'device_changed'  => ['type' => 'boolean'],
                    'issues'          => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['lower', 'jump', 'magnitude', 'higher_than_next']], 'message' => ['type' => 'string'],
                    ], 'required' => ['type', 'message'], 'additionalProperties' => false]],
                ], 'required' => ['device_changed', 'issues'], 'additionalProperties' => false],
                'untrusted'   => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'note' => ['type' => 'string']],
                                  'additionalProperties' => false],
                'confirm_in'  => ['type' => 'string', 'enum' => self::CONFIRM_IN],
                'message'     => ['type' => 'string'],
            ],
            'required'             => ['proposal_id', 'expires_at', 'summary', 'reading', 'checks', 'confirm_in'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function documentProposalSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'proposal_id' => ['type' => 'string', 'pattern' => self::PROPOSAL_PATTERN],
                'expires_at'  => ['type' => 'string'],
                'summary'     => ['type' => 'string'],
                'document_id' => ['type' => 'string', 'pattern' => DocumentTools::ID_PATTERN],
                'utility'     => ['type' => 'string', 'enum' => Utilities::keys()],
                'items'       => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'string'], 'kind' => ['type' => 'string'], 'label' => ['type' => 'string'], 'status' => ['type' => 'string'], 'text' => ['type' => 'string'],
                ], 'required' => ['id', 'kind', 'label', 'status', 'text'], 'additionalProperties' => false]],
                'warnings'    => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'proposal' => ['type' => 'string'], 'reason' => ['type' => 'string', 'enum' => ['requires', 'conflict', 'overlap', 'open', 'lower', 'jump', 'magnitude', 'higher_than_next']],
                    'requires' => ['type' => 'string'],
                ], 'required' => ['proposal', 'reason'], 'additionalProperties' => false]],
                'untrusted'   => ['type' => 'object', 'properties' => ['provider' => ['type' => 'string']], 'additionalProperties' => false],
                'confirm_in'  => ['type' => 'string', 'enum' => self::CONFIRM_IN],
                'message'     => ['type' => 'string'],
            ],
            'required'             => ['proposal_id', 'expires_at', 'summary', 'document_id', 'utility', 'items', 'warnings', 'confirm_in'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function applySchema(): array
    {
        $kindRow = fn(array $extra, array $required): array => ['type' => 'array', 'items' => ['type' => 'object',
            'properties' => ['kind' => ['type' => 'string']] + $extra, 'required' => ['kind', ...$required], 'additionalProperties' => false]];
        return [
            'type'       => 'object',
            'properties' => [
                'proposal_id'  => ['type' => 'string', 'pattern' => self::PROPOSAL_PATTERN],
                'kind'         => ['type' => 'string', 'enum' => self::PROPOSAL_KINDS],
                // v3.12.0 (Skeptiker M1) — geschrieben, oder es wartet auf die Bestätigung in der App
                'status'       => ['type' => 'string', 'enum' => ['applied', 'awaiting_confirmation']],
                'repeated'     => ['type' => 'boolean'],
                'summary'      => ['type' => 'string'],
                'expires_at'   => ['type' => 'string'],
                'confirm_in'   => ['type' => 'string', 'enum' => self::CONFIRM_IN],
                'message'      => ['type' => 'string'],
                'applied'      => $kindRow(['ref' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]], ['ref']),
                'skipped'      => $kindRow(['reason' => ['type' => 'string']], ['reason']),
                'failed'       => $kindRow(['code' => ['type' => 'string']], ['code']),
                'activity_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                'document'     => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'status' => ['type' => 'string']],
                                   'required' => ['id', 'status'], 'additionalProperties' => false],
            ],
            'required'             => ['proposal_id', 'kind', 'status', 'repeated'],
            'additionalProperties' => false,
        ];
    }
}
