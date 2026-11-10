<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp\Tools;

use Energietracker\Config\Utilities;
use Energietracker\Services\ActivityService;
use Energietracker\Services\ContractService;
use Energietracker\Services\I18nService;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Services\InboxService;
use Energietracker\Services\Mcp\Config;
use Energietracker\Services\Mcp\DocumentOriginal;
use Energietracker\Services\Mcp\IdentifierMask;
use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\Sanitize;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolGroup;
use Energietracker\Services\MeterService;
use Energietracker\Services\SettingsService;
use Energietracker\Support\Dates;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037, Paket schreiben) — Dokumente aus dem Posteingang für die KI
 * (Konzept §3.2, §3.3, §3.4, Entscheidung 10a):
 *
 *   list_documents       (mcp)        Liste, neueste zuerst
 *   get_document         (mcp)        Art, geprüfte Felder, Vorschläge der Übernahme
 *   get_document_schema  (mcp)        welche Felder die KI beim Auslesen liefert
 *   read_document        (mcp_write)  die KI liest selbst aus: Felder mit Zitat und
 *                                     Seite, der Server prüft sie (Inbox\Evidence)
 *
 * Dokumente kommen nur über den Posteingang herein (Oberfläche, Kurzbefehl, Ordner,
 * E-Mail, `curl` mit dem Schlüssel `mcp_write` an POST /api/inbox); kein Werkzeug
 * nimmt ein PDF entgegen. Das Original geht nur mit `mcp_share_documents` hinaus
 * (Mcp\DocumentOriginal), Kennnummern nur mit `mcp_share_identifiers`.
 *
 * Alles, was aus einem Dokument stammt (Dateiname, Textwerte, Zitate), steht nur
 * unter `untrusted`, bereinigt (Konzept §4.2). Kein Werkzeug gibt die ganze
 * Textebene zurück. Werte aus dem Dokument tragen `basis: entered` je Zeile.
 */
final class DocumentTools extends ToolGroup
{
    /** höchstens so viele Dokumente je Antwort von list_documents (danach `truncated: true`) */
    public const MAX_DOCUMENTS = 100;
    /** read_document: höchstens so viele Seiten und Zeichen Textebene je Aufruf */
    public const MAX_PAGES = 40;
    public const MAX_TEXT_CHARS = 200000;
    /** read_document: höchstens so viele Felder (die Arten haben bis rund 40) */
    public const MAX_FIELDS = 100;
    /** Kennnummern (Konzept §4.1): nur mit `mcp_share_identifiers` */
    public const IDENTIFIERS = ['customer_number', 'contract_number', 'invoice_number', 'meter_number', 'market_location',
        'old_meter_number', 'new_meter_number', 'file_number', 'corrects_invoice'];
    /** Kennnummern in Einträgen von Listenfeldern (Stände je Zähler) */
    public const ITEM_IDENTIFIERS = ['meter_number'];
    public const ID_PATTERN = '^inb_[0-9a-f]{12}$';

    public function tools(): array
    {
        $doc = ['type' => 'string', 'pattern' => self::ID_PATTERN];
        return [
            new Tool('list_documents', Tool::READ, [
                'type'                 => 'object',
                'properties'           => ['status' => ['type' => 'string', 'enum' => InboxService::STATUSES]],
                'additionalProperties' => false,
            ], fn(array $a, McpContext $ctx): array => $this->listDocuments($a, $ctx), self::listSchema(),
                ['readOnlyHint' => true, 'idempotentHint' => true]),
            new Tool('get_document', Tool::READ, [
                'type'                 => 'object',
                'properties'           => ['document_id' => $doc],
                'required'             => ['document_id'],
                'additionalProperties' => false,
            ], fn(array $a, McpContext $ctx): array => $this->document((string)$a['document_id'], $ctx), self::documentSchema(),
                ['readOnlyHint' => true, 'idempotentHint' => true]),
            new Tool('get_document_schema', Tool::READ, [
                'type'                 => 'object',
                'properties'           => ['type' => ['type' => 'string', 'enum' => InboxSchema::TYPES]],
                'required'             => ['type'],
                'additionalProperties' => false,
            ], fn(array $a, McpContext $ctx): array => $this->schemaOf((string)$a['type'], $ctx), self::schemaSchema(),
                ['readOnlyHint' => true, 'idempotentHint' => true]),
            new Tool('read_document', Tool::WRITE, [
                'type'                 => 'object',
                'properties'           => [
                    'document_id' => $doc,
                    'type'        => ['type' => 'string', 'enum' => InboxSchema::TYPES],
                    'pages'       => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => self::MAX_TEXT_CHARS],
                                      'minItems' => 1, 'maxItems' => self::MAX_PAGES],
                    'fields'      => ['type' => 'object', 'additionalProperties' => [
                        'type'       => 'object',
                        'properties' => [
                            'value' => ['type' => ['string', 'number', 'boolean', 'array', 'null']],
                            'quote' => ['type' => ['string', 'null'], 'maxLength' => 2000],
                            'page'  => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => self::MAX_PAGES],
                        ],
                        'additionalProperties' => false,
                    ]],
                ],
                'required'             => ['document_id', 'type', 'pages', 'fields'],
                'additionalProperties' => false,
            ], fn(array $a, McpContext $ctx): array => $this->readDocument($a, $ctx), self::documentSchema(),
                // v3.12.0 (Skeptiker H3) — schreibt keine Daten des Haushalts, ersetzt aber eine Auslesung (nie eine
                // Korrektur der Person, nie ein übernommenes Dokument) und hinterlässt jedes Mal einen Verlaufseintrag
                ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false]),
        ];
    }

    /**
     * v3.12.0 (Skeptiker H1) — Die eine Stelle für Kennnummern in Fremdtext: mit Schalter nichts,
     * sonst die Nummern dieses Dokuments und die, die der Haushalt schon kennt.
     *
     * @param array<string,mixed> $entry Eintrag des Posteingangs (mit Auslesung, sonst leer)
     */
    public function mask(array $entry): IdentifierMask
    {
        // v3.12.0 (m4b) — die Angaben für Briefe (Absender, Lieferanschrift) immer, auch mit dem Schalter
        $personal = $this->householdPersonal();
        if ($this->services->get(Config::class)->shareIdentifiers()) return IdentifierMask::off($personal);
        return IdentifierMask::of([...self::identifierValues($entry), ...$this->householdIdentifiers()], $personal);
    }

    /**
     * v3.12.0 (m4b) — Angaben für Briefe aus dem Haushalt (SettingsService::LETTER_KEYS), Name in Wörtern.
     * Die Anschrift des Anbieters am Vertrag gehört nicht dazu: Sie steht ohnehin auf jedem seiner Schreiben;
     * kein Werkzeug gibt das Feld heraus (ContractTools nennt Felder einzeln).
     *
     * @return list<string>
     */
    private function householdPersonal(): array
    {
        $s = $this->services->get(SettingsService::class);
        $get = fn(string $k) => SettingsService::cleanLetterValue($k, $s->get($k, '')) ?? '';
        $name = $get('letter_sender_name');
        $lines = array_filter([...explode("\n", $get('letter_sender_address')), $get('letter_supply_address')], fn($l) => $l !== '');
        return IdentifierMask::personal($name === '' ? [] : [$name], array_values($lines));
    }

    /**
     * Werte der Kennnummern-Felder eines Dokuments: gelesen, korrigiert und in Listen (Stände je Zähler).
     *
     * @param array<string,mixed> $entry
     * @return list<mixed>
     */
    private static function identifierValues(array $entry): array
    {
        $out = [];
        $fields = (array)($entry['extraction']['fields'] ?? []);
        $edits = (array)($entry['edits'] ?? []);
        foreach (self::IDENTIFIERS as $name) {
            if (isset($fields[$name]['value'])) $out[] = $fields[$name]['value'];
            if (isset($edits[$name])) $out[] = $edits[$name];
        }
        foreach ($fields as $f) {
            foreach (is_array($f) ? (array)($f['items'] ?? []) : [] as $item) {
                foreach (self::ITEM_IDENTIFIERS as $sub) {
                    if (isset($item['value'][$sub])) $out[] = $item['value'][$sub];
                }
            }
        }
        return $out;
    }

    /** @var list<string>|null Nummern, die der Haushalt kennt (einmal je Anfrage) */
    private ?array $household = null;

    /** @return list<string> Gerätenummern, Markt- und Messlokationen, Vertrags- und Kundennummern */
    private function householdIdentifiers(): array
    {
        if ($this->household !== null) return $this->household;
        $out = [];
        foreach (Utilities::keys() as $u) {
            foreach ($this->services->get(MeterService::class)->list($u) as $m) {
                foreach (['malo_id', 'melo_id'] as $k) if (is_string($m[$k] ?? null)) $out[] = $m[$k];
                foreach ((array)($m['devices'] ?? []) as $d) if (is_string($d['serial'] ?? null)) $out[] = $d['serial'];
            }
            if (!Utilities::hasContracts($u)) continue;
            foreach ($this->services->get(ContractService::class)->list($u) as $c) {
                foreach (['contract_number', 'customer_number'] as $k) if (is_string($c[$k] ?? null)) $out[] = $c[$k];
            }
        }
        return $this->household = array_values(array_unique($out));
    }

    // ── Werkzeuge ────────────────────────────────────────────────────────

    /**
     * @param array{status?:string} $args
     * @return array<string,mixed>
     */
    private function listDocuments(array $args, McpContext $ctx): array
    {
        $inbox = $this->services->get(InboxService::class);
        $out = [];
        $truncated = false;
        $entries = array_column($inbox->rawEntries(), null, 'id');
        foreach ($inbox->list() as $s) {
            if (isset($args['status']) && ($s['status'] ?? null) !== $args['status']) continue;
            if (count($out) >= self::MAX_DOCUMENTS) { $truncated = true; break; }
            $type = is_string($s['type'] ?? null) ? $s['type'] : null;
            $out[] = array_filter([
                'id'           => (string)$s['id'],
                'created_at'   => (string)($s['created_at'] ?? ''),
                'status'       => (string)($s['status'] ?? 'new'),
                'type'         => $type,
                'type_label'   => $type !== null ? $this->typeLabel($type, $ctx) : null,
                'pages'        => is_int($s['pages'] ?? null) ? $s['pages'] : null,
                'utility'      => is_string($s['utility'] ?? null) ? $s['utility'] : null,
                'needs_review' => ($s['status'] ?? '') === 'read' ? ((int)($s['flags'] ?? 0)) > 0 : null,
                'applied'      => ((int)($s['applied'] ?? 0)) > 0,
                'duplicate_of' => is_string($s['duplicate_of'] ?? null) ? $s['duplicate_of'] : null,
                'untrusted'    => Sanitize::untrusted(['name' => $this->mask((array)($entries[$s['id']] ?? []))->fileName((string)($s['name'] ?? ''))]),
            ], fn($v) => $v !== null && $v !== '' && $v !== []);
        }
        return ['documents' => $out] + ($truncated ? ['truncated' => true] : []);
    }

    /**
     * Ein Dokument: Art, geprüfte Felder (Kennnummern nur mit Schalter), die
     * Vorschläge der Übernahme mit Prüfmarken und was als Nächstes geht.
     *
     * @return array<string,mixed>
     */
    public function document(string $id, McpContext $ctx): array
    {
        $inbox = $this->services->get(InboxService::class);
        $config = $this->services->get(Config::class);
        $pv = $inbox->preview($id);
        $e = $pv['entry'];
        $type = is_string($e['type'] ?? null) && $e['type'] !== '' ? (string)$e['type'] : null;
        $x = is_array($e['extraction'] ?? null) ? $e['extraction'] : null;
        $read = $type !== null && InboxSchema::extracts($type) && is_array($x['fields'] ?? null) && $x['fields'] !== [];
        $proposals = [];
        foreach ($read ? (array)$pv['proposals'] : [] as $p) $proposals[] = $this->proposalRow($p, $ctx);
        $next = match (true) {
            $type !== null && !InboxSchema::extracts($type) => 'none',
            !$read                                          => 'read_document',
            array_filter($proposals, fn($p) => $p['status'] !== 'exists') !== [] => 'propose_document',
            default                                         => 'none',
        };
        $mask = $this->mask($e);
        $original = ['available' => false, 'reason' => 'sharing_off'];
        if ($config->shareDocuments()) {
            $original = !empty($e['attachment_id']) ? ['available' => true, 'uri' => DocumentOriginal::uri($id)] : ['available' => false, 'reason' => 'missing'];
        }
        $as = (array)($pv['assignment'] ?? []);
        return array_filter([
            'id'         => (string)$e['id'],
            'created_at' => (string)($e['created_at'] ?? ''),
            'status'     => (string)($e['status'] ?? 'new'),
            'type'       => $type,
            'type_label' => $type !== null ? $this->typeLabel($type, $ctx) : null,
            'pages'      => is_int($e['pages'] ?? null) ? $e['pages'] : null,
            'extraction' => $x === null ? null : array_filter([
                'by'           => ($x['provider'] ?? null) === InboxService::CLIENT_PROVIDER ? 'ai_client' : 'app',
                'extracted_at' => is_string($x['extracted_at'] ?? null) ? $x['extracted_at'] : null,
                'needs_review' => ((int)($x['flags'] ?? 0)) > 0,
            ], fn($v) => $v !== null),
            'assignment' => array_filter(['utility' => $as['utility'] ?? null, 'meter_id' => $as['meter_id'] ?? null,
                'contract_id' => $as['contract_id'] ?? null, 'saved' => !empty($pv['assignment_saved'])], fn($v) => $v !== null && $v !== ''),
            'fields'     => $read ? $this->fieldRows((string)$type, (array)$x['fields'], (array)($e['edits'] ?? []), $config->shareIdentifiers(), $mask) : [],
            'proposals'  => $proposals,
            'duplicates' => array_map(fn($d) => ['id' => (string)$d['id'], 'status' => (string)($d['status'] ?? 'new')], (array)($pv['duplicates'] ?? [])),
            'next'       => $next,
            'original'   => $original,
            'untrusted'  => Sanitize::untrusted(['name' => $mask->fileName((string)($e['name'] ?? ''))]),
        ], fn($v) => $v !== null && $v !== '');
    }

    /** @return array<string,mixed> */
    private function schemaOf(string $type, McpContext $ctx): array
    {
        $i18n = $this->services->get(I18nService::class);
        $s = InboxSchema::forProvider($type);
        $fields = [];
        foreach ((array)$s['fields'] as $name => $f) {
            $row = ['name' => (string)$name, 'kind' => (string)$f['kind'], 'description' => (string)$f['description']];
            if (isset($f['options'])) $row['options'] = array_values(array_map('strval', (array)$f['options']));
            if (isset($f['items'])) {
                $row['items'] = [];
                foreach ((array)$f['items'] as $sub => $i) $row['items'][] = ['name' => (string)$sub, 'kind' => (string)$i['kind'], 'description' => (string)$i['description']];
            }
            if (in_array($name, self::IDENTIFIERS, true)) $row['identifier'] = true;
            $fields[] = $row;
        }
        return [
            'type'           => $type,
            'type_label'     => $this->typeLabel($type, $ctx),
            'description'    => (string)$s['description'],
            'recognize_only' => !InboxSchema::extracts($type),
            'fields'         => $fields,
            'how_to'         => $i18n->t('mcp.document.howTo', ['pages' => self::MAX_PAGES, 'chars' => self::MAX_TEXT_CHARS], $ctx->locale),
        ];
    }

    /**
     * @param array{document_id:string, type:string, pages:list<string>, fields:array<string,mixed>} $args
     * @return array<string,mixed>
     */
    private function readDocument(array $args, McpContext $ctx): array
    {
        $pages = array_values(array_map('strval', (array)$args['pages']));
        $chars = array_sum(array_map('mb_strlen', $pages));
        if ($chars > self::MAX_TEXT_CHARS) {
            throw new LocalizedException('errors.mcp.document.tooMuchText', ['max' => self::MAX_TEXT_CHARS, 'pages' => self::MAX_PAGES], 'read_document: text too long');
        }
        $fields = (array)$args['fields'];
        if (count($fields) > self::MAX_FIELDS) {
            throw new LocalizedException('errors.mcp.document.tooManyFields', ['max' => self::MAX_FIELDS], 'read_document: too many fields');
        }
        $type = (string)$args['type'];
        $known = InboxSchema::fields($type);
        $ignored = array_values(array_filter(array_map('strval', array_keys($fields)), fn($n) => !isset($known[$n])));
        $id = (string)$args['document_id'];
        // v3.12.0 (Skeptiker H3) — lehnt ab, wenn eine Person korrigiert hat oder das Dokument übernommen ist;
        // sonst bleibt die neue Auslesung nicht ohne Spur
        $inbox = $this->services->get(InboxService::class);
        $entry = $inbox->extractFromClient($id, $type, $pages, array_intersect_key($fields, $known), (string)($ctx->client['name'] ?? ''));
        if ($this->services->has(ActivityService::class)) {
            $activity = $this->services->get(ActivityService::class);
            $activity->withActor(['type' => 'key', 'name' => $ctx->keyName, 'via' => 'mcp'], fn(): array => $activity->record([
                'action'    => 'updated',
                'subject'   => ['kind' => 'inbox', 'uid' => "inbox:$id", 'occurrence' => "inbox:$id@read@" . (string)($entry['extraction']['extracted_at'] ?? '')],
                'ref'       => ['inbox_id' => $id],
                'title_key' => 'activity.mcp.documentRead',
                'params'    => ['name' => (string)($entry['name'] ?? ''), 'type' => $type],
            ]));
        }
        $out = $this->document($id, $ctx);
        // Feldnamen außerhalb des Schemas: nur Schema-Namen (Muster), nie der Text der KI
        if ($ignored !== []) $out['ignored_fields'] = array_slice(array_values(array_filter($ignored, fn($n) => preg_match('/^[a-z][a-z0-9_]{0,60}$/', $n) === 1)), 0, 20);
        if (($out['ignored_fields'] ?? null) === []) unset($out['ignored_fields']);
        return $out;
    }

    // ── Bausteine ────────────────────────────────────────────────────────

    private function typeLabel(string $type, McpContext $ctx): string
    {
        $i18n = $this->services->get(I18nService::class);
        $label = $i18n->t("inbox.type.$type", [], $ctx->locale);
        return $label !== "inbox.type.$type" ? $label : $type;
    }

    /**
     * Ein Vorschlag der Übernahme (Inbox\Proposals) ohne Werte: Art, Prüfstand,
     * offene Punkte. Werte und Texte stehen in den Feldern bzw. in der
     * Zusammenfassung von propose_document.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function proposalRow(array $p, McpContext $ctx): array
    {
        $codes = fn(mixed $list, string $key): array => array_values(array_map(fn($r) => array_filter([
            'field' => is_string($r['field'] ?? null) ? $r['field'] : null,
            $key    => $key === 'reasons' ? array_values(array_map('strval', (array)($r['reasons'] ?? []))) : (is_string($r['reason'] ?? null) ? $r['reason'] : null),
        ], fn($v) => $v !== null && $v !== []), array_filter((array)$list, 'is_array')));
        return array_filter([
            'id'       => (string)$p['id'],
            'kind'     => (string)$p['kind'],
            'label'    => WriteTools::kindLabel($this->services->get(I18nService::class), (string)$p['kind'], $ctx->locale),
            'status'   => (string)($p['status'] ?? 'new'),
            'checked'  => !empty($p['checked']),
            'check'    => !empty($p['check']),
            'optional' => !empty($p['optional']) ? true : null,
            'requires' => is_string($p['requires'] ?? null) ? $p['requires'] : null,
            'reasons'  => $codes($p['reasons'] ?? [], 'reasons'),
            'open'     => $codes($p['open'] ?? [], 'reason'),
            'hints'    => $codes($p['hints'] ?? [], 'reason'),
        ], fn($v) => $v !== null && $v !== []);
    }

    /**
     * Geprüfte Felder als Zeilen. Zahlen, Tage, Auswahlwerte und Schalter stehen
     * als Wert da (Herkunft der Zeile: eingetragen), Text aus dem Dokument nur
     * bereinigt unter `untrusted`. Kennnummern ohne Freigabe: nur `withheld`, auch
     * ohne Zitat (das trüge die Nummer). Leere Felder fehlen.
     * v3.12.0 (Skeptiker H1) — dieselben Nummern auch nicht über Zitate und Textwerte anderer
     * Felder (IdentifierMask): ersetzt, und was sonst noch nach einer Nummer aussieht, fällt weg.
     *
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $edits bestätigte oder korrigierte Werte aus der Oberfläche
     * @return list<array<string,mixed>>
     */
    private function fieldRows(string $type, array $fields, array $edits, bool $share, IdentifierMask $mask): array
    {
        $rows = [];
        foreach (InboxSchema::fields($type) as $name => $def) {
            $f = $fields[$name] ?? null;
            if (!is_array($f)) continue;
            $edited = array_key_exists($name, $edits);
            if (($f['status'] ?? '') === 'empty' && !$edited) continue;
            $row = ['name' => (string)$name, 'kind' => (string)$def['kind'], 'status' => $edited ? 'ok' : (string)($f['status'] ?? 'ok'), 'basis' => 'entered'];
            $reasons = $edited ? [] : array_values(array_map('strval', (array)($f['reasons'] ?? [])));
            if ($reasons !== []) $row['reasons'] = $reasons;
            if (is_int($f['page'] ?? null) && $f['page'] > 0) $row['page'] = $f['page'];
            if ($edited) $row['edited'] = true;
            if (!$share && in_array($name, self::IDENTIFIERS, true)) {
                $rows[] = $row + ['withheld' => 'identifier'];
                continue;
            }
            if ($def['kind'] === 'list') {
                $row['items'] = $this->itemRows((array)$def['items'], (array)($f['items'] ?? []), $share, $mask);
                $rows[] = $row;
                continue;
            }
            [$value, $text] = self::safe($def, $edited ? $edits[$name] : ($f['value'] ?? null));
            if ($value !== null) $row['value'] = $value;
            $untrusted = Sanitize::untrusted(['value' => $mask->quote($text), 'quote' => is_string($f['quote'] ?? null) ? $mask->quote($f['quote']) : null]);
            if ($untrusted !== []) $row['untrusted'] = $untrusted;
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<string,array<string,mixed>> $defs Felder je Eintrag
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function itemRows(array $defs, array $items, bool $share, IdentifierMask $mask): array
    {
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $row = ['status' => (string)($item['status'] ?? 'ok'), 'basis' => 'entered'];
            $reasons = array_values(array_map('strval', (array)($item['reasons'] ?? [])));
            if ($reasons !== []) $row['reasons'] = $reasons;
            if (is_int($item['page'] ?? null) && $item['page'] > 0) $row['page'] = $item['page'];
            $values = [];
            $texts = [];
            $withheld = false;
            foreach ($defs as $sub => $subDef) {
                $v = $item['value'][$sub] ?? null;
                if ($v === null || $v === '') continue;
                if (!$share && in_array($sub, self::ITEM_IDENTIFIERS, true)) { $withheld = true; continue; }
                [$safe, $text] = self::safe($subDef, $v);
                // je Wert eine Zeile mit Herkunft (ein Feld der Einträge heißt selbst `basis`: netto/brutto)
                if ($safe !== null) $values[] = ['name' => (string)$sub, 'value' => $safe, 'basis' => 'entered'];
                if ($text !== null && ($clean = Sanitize::text($mask->quote($text), Sanitize::NAME_MAX)) !== '') $texts[$sub] = $clean;
            }
            if ($values !== []) $row['values'] = $values;
            // mit zurückgehaltener Kennnummer auch kein Zitat (es trüge die Nummer)
            $quote = !$withheld && is_string($item['quote'] ?? null) ? Sanitize::quote($mask->quote($item['quote'])) : '';
            if ($quote !== '') $texts['quote'] = $quote;
            if ($texts !== []) $row['untrusted'] = $texts;
            if ($withheld) $row['withheld'] = 'identifier';
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Wert eines Felds: [für die Ausgabe, Text für `untrusted`]. Zahlen, echte
     * Tage, Auswahlwerte aus der Liste und Schalter gelten als Daten; alles
     * andere (Freitext, unlesbare Werte) ist Fremdtext.
     *
     * @param array<string,mixed> $def
     * @return array{0: float|string|bool|null, 1: ?string}
     */
    private static function safe(array $def, mixed $v): array
    {
        if ($v === null || $v === '' || is_array($v)) return [null, null];
        return match ((string)$def['kind']) {
            'number', 'money', 'price_ct' => is_int($v) || is_float($v) ? (is_finite((float)$v) ? [(float)$v, null] : [null, null]) : [null, is_scalar($v) ? (string)$v : null],
            'date'  => is_string($v) && Dates::isIsoDate($v) ? [$v, null] : [null, is_scalar($v) ? (string)$v : null],
            'enum'  => is_string($v) && in_array($v, (array)($def['options'] ?? []), true) ? [$v, null] : [null, is_scalar($v) ? (string)$v : null],
            'bool'  => is_bool($v) ? [$v, null] : [null, null],
            default => [null, is_scalar($v) ? (string)$v : null],
        };
    }

    // ── Ausgabeschemas ───────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function untrustedSchema(array $fields): array
    {
        return ['type' => 'object', 'properties' => array_fill_keys($fields, ['type' => 'string']), 'additionalProperties' => false];
    }

    /** @return array<string,mixed> */
    private static function listSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'documents' => ['type' => 'array', 'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'           => ['type' => 'string', 'pattern' => self::ID_PATTERN],
                        'created_at'   => ['type' => 'string'],
                        'status'       => ['type' => 'string', 'enum' => InboxService::STATUSES],
                        'type'         => ['type' => 'string', 'enum' => InboxSchema::TYPES],
                        'type_label'   => ['type' => 'string'],
                        'pages'        => ['type' => 'integer', 'minimum' => 0],
                        'utility'      => ['type' => 'string', 'enum' => Utilities::keys()],
                        'needs_review' => ['type' => 'boolean'],
                        'applied'      => ['type' => 'boolean'],
                        'duplicate_of' => ['type' => 'string'],
                        'untrusted'    => self::untrustedSchema(['name']),
                    ],
                    'required'             => ['id', 'status', 'applied'],
                    'additionalProperties' => false,
                ]],
                'truncated' => ['type' => 'boolean'],
            ],
            'required'             => ['documents'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function documentSchema(): array
    {
        $codes = ['type' => 'array', 'items' => ['type' => 'string']];
        $item = [
            'type'       => 'object',
            'properties' => [
                'status'    => ['type' => 'string'],
                'basis'     => ['type' => 'string', 'enum' => ['entered']],
                'reasons'   => $codes,
                'page'      => ['type' => 'integer', 'minimum' => 1],
                'values'    => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'name'  => ['type' => 'string'],
                    'value' => ['type' => ['number', 'string', 'boolean']],
                    'basis' => ['type' => 'string', 'enum' => ['entered']],
                ], 'required' => ['name', 'value', 'basis'], 'additionalProperties' => false]],
                'untrusted' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                'withheld'  => ['type' => 'string', 'enum' => ['identifier']],
            ],
            'required'             => ['status', 'basis'],
            'additionalProperties' => false,
        ];
        $pointList = fn(string $key, array $schema): array => ['type' => 'array', 'items' => [
            'type' => 'object', 'properties' => ['field' => ['type' => 'string'], $key => $schema], 'additionalProperties' => false]];
        return [
            'type'       => 'object',
            'properties' => [
                'id'         => ['type' => 'string', 'pattern' => self::ID_PATTERN],
                'created_at' => ['type' => 'string'],
                'status'     => ['type' => 'string', 'enum' => InboxService::STATUSES],
                'type'       => ['type' => 'string', 'enum' => InboxSchema::TYPES],
                'type_label' => ['type' => 'string'],
                'pages'      => ['type' => 'integer', 'minimum' => 0],
                'extraction' => ['type' => 'object', 'properties' => [
                    'by'           => ['type' => 'string', 'enum' => ['app', 'ai_client']],
                    'extracted_at' => ['type' => 'string'],
                    'needs_review' => ['type' => 'boolean'],
                ], 'required' => ['by', 'needs_review'], 'additionalProperties' => false],
                'assignment' => ['type' => 'object', 'properties' => [
                    'utility'     => ['type' => 'string', 'enum' => Utilities::keys()],
                    'meter_id'    => ['type' => 'string'],
                    'contract_id' => ['type' => 'string'],
                    'saved'       => ['type' => 'boolean'],
                ], 'additionalProperties' => false],
                'fields'     => ['type' => 'array', 'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'      => ['type' => 'string'],
                        'kind'      => ['type' => 'string'],
                        'status'    => ['type' => 'string'],
                        'basis'     => ['type' => 'string', 'enum' => ['entered']],
                        'reasons'   => $codes,
                        'page'      => ['type' => 'integer', 'minimum' => 1],
                        'edited'    => ['type' => 'boolean'],
                        'value'     => ['type' => ['number', 'string', 'boolean']],
                        'items'     => ['type' => 'array', 'items' => $item],
                        'withheld'  => ['type' => 'string', 'enum' => ['identifier']],
                        'untrusted' => self::untrustedSchema(['value', 'quote']),
                    ],
                    'required'             => ['name', 'kind', 'status', 'basis'],
                    'additionalProperties' => false,
                ]],
                'proposals'  => ['type' => 'array', 'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'       => ['type' => 'string'],
                        'kind'     => ['type' => 'string'],
                        'label'    => ['type' => 'string'],
                        'status'   => ['type' => 'string'],
                        'checked'  => ['type' => 'boolean'],
                        'check'    => ['type' => 'boolean'],
                        'optional' => ['type' => 'boolean'],
                        'requires' => ['type' => 'string'],
                        'reasons'  => $pointList('reasons', $codes),
                        'open'     => $pointList('reason', ['type' => 'string']),
                        'hints'    => $pointList('reason', ['type' => 'string']),
                    ],
                    'required'             => ['id', 'kind', 'label', 'status', 'checked', 'check'],
                    'additionalProperties' => false,
                ]],
                'duplicates' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'string'], 'status' => ['type' => 'string']], 'required' => ['id', 'status'], 'additionalProperties' => false]],
                'next'       => ['type' => 'string', 'enum' => ['read_document', 'propose_document', 'none']],
                'original'   => ['type' => 'object', 'properties' => [
                    'available' => ['type' => 'boolean'],
                    'uri'       => ['type' => 'string'],
                    'reason'    => ['type' => 'string', 'enum' => ['sharing_off', 'missing']],
                ], 'required' => ['available'], 'additionalProperties' => false],
                'ignored_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                'untrusted'  => self::untrustedSchema(['name']),
            ],
            'required'             => ['id', 'status', 'fields', 'proposals', 'next', 'original'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function schemaSchema(): array
    {
        $row = ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'], 'kind' => ['type' => 'string'], 'description' => ['type' => 'string'],
        ], 'required' => ['name', 'kind', 'description'], 'additionalProperties' => false];
        $field = $row;
        $field['properties'] += [
            'options'    => ['type' => 'array', 'items' => ['type' => 'string']],
            'items'      => ['type' => 'array', 'items' => $row],
            'identifier' => ['type' => 'boolean'],
        ];
        return [
            'type'       => 'object',
            'properties' => [
                'type'           => ['type' => 'string', 'enum' => InboxSchema::TYPES],
                'type_label'     => ['type' => 'string'],
                'description'    => ['type' => 'string'],
                'recognize_only' => ['type' => 'boolean'],
                'fields'         => ['type' => 'array', 'items' => $field],
                'how_to'         => ['type' => 'string'],
            ],
            'required'             => ['type', 'description', 'recognize_only', 'fields', 'how_to'],
            'additionalProperties' => false,
        ];
    }
}
