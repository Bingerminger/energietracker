<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\HttpStatusException;
use Energietracker\Http\NotFoundException;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\DuplicateDocumentException;
use Energietracker\Services\Inbox\Evidence;
use Energietracker\Services\Inbox\ExtractionProvider;
use Energietracker\Services\Inbox\Gist;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Services\Inbox\LocalProvider;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\Inbox\TextPrep;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;
use Energietracker\Support\LocalizedException;

/**
 * v3.7.0 (Posteingang) — Versorgerpost ablegen, auslesen, prüfen, übernehmen.
 *
 * Topf `inbox.json` (Schlüssel `inbox` im Backup), eine Liste. Kein
 * Schemaschritt (Vorbild `activity`): Die Datei entsteht beim ersten Dokument.
 * Ein Eintrag:
 *
 *   id             inb_<12 hex>
 *   attachment_id  das PDF im Belegspeicher (AttachmentService, Art inbox_pdf)
 *   sha256, size, name, pages, created_at, updated_at
 *   status         new | queued | read | failed | done | filed
 *                  (filed: abgelegt ohne Übernahme — auch Mahnung, Widerruf, Sonstiges)
 *   type           Dokumentart (InboxSchema::TYPES), type_confidence
 *   extraction     {provider, model, schema_version, type, fields (geprüft,
 *                  s. Inbox\Evidence), flags, seconds, extracted_at, text}
 *   cache          je „Anbieter|Modell|Schema-Version“ die Rohantworten
 *                  (Art, Felder je Art) — der Zwischenspeicher je Prüfsumme
 *   assignment     {utility, meter_id, contract_id} — vom Nutzer gesetzt;
 *                  ohne gilt die Vorbelegung (Proposals::suggest)
 *   edits          vom Nutzer bestätigte oder korrigierte Feldwerte
 *   applied        [{id, kind, key, ref, at}] — was übernommen wurde
 *   error          {key, params, at} beim letzten Fehlschlag
 *
 * Die Textebene liest der Browser (pdf.js) und schickt sie mit jedem Auslesen;
 * gespeichert wird sie nicht — sie steckt im PDF, und das liegt im Backup.
 *
 * Ohne KI bleibt der Posteingang ein Ablageort mit Zuordnung; „Von Hand
 * erfassen“ öffnet das passende Formular neben dem Dokument (Oberfläche).
 *
 * Schreiben erst nach der Antwort des Anbieters: Die Schreibsperre gilt bis
 * zum Ende der Anfrage, und ein Auslesen dauert lokal rund eine Minute
 * (App::LOCK_LATE).
 */
final class InboxService
{
    public const FILE = 'inbox.json';
    public const ID = '/^inb_[0-9a-f]{12}$/';
    public const STATUSES = ['new', 'queued', 'read', 'failed', 'done', 'filed'];
    private const CACHE_KEEP = 3;
    private const NOTE_MAX = 500;

    private ?ActivityService $activity = null;

    public function __construct(
        private JsonStore $store,
        private I18nService $i18n,
        private AttachmentService $attachments,
        private ProviderRegistry $providers,
        private Proposals $proposals,
        private Applier $applier,
    ) {
        // v3.7.0 (treffsicher, Nachbesserung 2) — die Dokumente am Vertrag (nach Anhang) für
        // „gleicher Preis, anderer Steuersatz“; gelesen erst, wenn ein Preis nicht passt
        $this->proposals->attachDocuments(fn(array $ids): array => array_values(array_filter($this->all(),
            fn(array $e) => in_array((string)($e['attachment_id'] ?? ''), $ids, true))));
    }

    public function attachActivity(ActivityService $activity): void
    {
        $this->activity = $activity;
    }

    /**
     * v3.7.0 (Paket betrieb) — Kernaussage je Dokument (Inbox\Gist). Gespeichert
     * als `gist` nach jedem Schreiben am Eintrag (Auslesen, Zuordnung, Übernehmen,
     * Termin), damit die Liste nicht für jedes Dokument Prognose und
     * Rechnungsprüfung rechnet; die Vorschau rechnet sie frisch.
     */
    private ?Gist $gist = null;

    public function attachGist(Gist $gist): void
    {
        $this->gist = $gist;
    }

    /** v3.9.0 (F1033) — wer erfährt, dass ein Dokument abgelegt wurde (Benachrichtigung an Home Assistant). */
    private ?\Closure $onUpload = null;

    public function onUpload(callable $fn): void
    {
        $this->onUpload = $fn(...);
    }

    public function providers(): ProviderRegistry
    {
        return $this->providers;
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $all = $this->store->read(self::FILE, []);
        return is_array($all) ? array_values(array_filter($all, fn($e) => is_array($e) && isset($e['id']))) : [];
    }

    /** @return array<string,mixed> */
    public function get(string $id): array
    {
        foreach ($this->all() as $e) if ($e['id'] === $id) return $e;
        throw new NotFoundException($this->i18n->t('errors.inbox.notFound'));
    }

    /**
     * Liste für die Oberfläche, neueste zuerst — ohne Felder und Zwischenspeicher.
     *
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        // v3.7.0 (treffsicher) — Marken nach dem heutigen Bestand (Zahl „N Felder prüfen“)
        // und das ältere inhaltsgleiche Dokument (`duplicate_of`); (Paket betrieb) ohne
        // gespeicherte Kernaussage (etwa Beispieldaten) frisch gerechnet
        $all = $this->all();
        $out = [];
        foreach ($all as $e) {
            $s = self::summary(($e['status'] ?? '') === 'read' ? $this->effective($e) : $e);
            $s['duplicate_of'] = $this->olderDuplicateOf($e, $all);
            if ($s['gist'] === null && !empty($e['type'])) $s['gist'] = $this->gistOf($e);
            $out[] = $s;
        }
        usort($out, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
        return $out;
    }

    /** @return array<string,mixed> */
    public static function summary(array $e): array
    {
        return [
            'id' => $e['id'], 'attachment_id' => $e['attachment_id'] ?? null, 'name' => $e['name'] ?? '',
            'size' => $e['size'] ?? 0, 'pages' => $e['pages'] ?? null, 'sha256' => $e['sha256'] ?? '',
            'created_at' => $e['created_at'] ?? '', 'updated_at' => $e['updated_at'] ?? null,
            'status' => $e['status'] ?? 'new', 'queued_at' => $e['queued_at'] ?? null,
            'type' => $e['type'] ?? null, 'type_confidence' => $e['type_confidence'] ?? null,
            'flags' => $e['extraction']['flags'] ?? null, 'utility' => $e['assignment']['utility'] ?? null,
            'applied' => count((array)($e['applied'] ?? [])), 'error' => $e['error'] ?? null,
            'provider' => $e['extraction']['provider'] ?? null, 'model' => $e['extraction']['model'] ?? null,
            // v3.7.0 (Paket betrieb) — Kernaussage und Zuordnung für Liste und Akte
            'gist' => is_array($e['gist'] ?? null) ? $e['gist'] : null,
            'meter_id' => $e['assignment']['meter_id'] ?? null, 'contract_id' => $e['assignment']['contract_id'] ?? null,
        ];
    }

    /**
     * Anbieter laut Einstellung, für Hinweis und Zustimmung. `available`: Der
     * Anbieter sendet wirklich (lokal: Adresse eingetragen; Cloud: eingeschaltet,
     * gewählt, mit Schlüssel — `enabled()` des Anschlusses). `destination` und
     * `notice` (Paket cloud) zeigt der Dialog „Dieses Dokument geht an …“: Ziel
     * und was der Dienst zu sehen bekommt, in der Sprache der Anfrage.
     */
    public function providerInfo(): array
    {
        $id = $this->providers->activeId();
        $known = $this->providers->has($id);
        $p = $known ? $this->providers->get($id) : null;
        $str = static fn(string $m): ?string => $p !== null && method_exists($p, $m) ? (string)$p->{$m}() : null;
        return [
            'id' => $id,
            'known' => $known,
            'label' => $p?->label() ?? $id,
            'local' => $id === ProviderRegistry::DEFAULT,
            'available' => $known && (!method_exists($p, 'enabled') || $p->enabled()),
            'model' => $str('model'),
            'destination' => $str('destination'),
            'notice' => $str('notice'),
            'pdf' => $p?->supportsPdf() ?? false,
            // v3.7.0 (Karte „Auslesen“) — jeder Cloud-Weg, auch Claude: noch nicht mit echten Rückmeldungen geprüft
            'experimental' => $id !== ProviderRegistry::DEFAULT,
            'recommended_model' => LocalProvider::RECOMMENDED_MODEL,
        ];
    }

    /** Zähler je Status und der nächste Eintrag der Warteschlange. */
    public function queueState(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        $next = null;
        $queued = array_values(array_filter($this->all(), fn($e) => ($e['status'] ?? '') === 'queued'));
        usort($queued, fn($a, $b) => strcmp((string)($a['queued_at'] ?? ''), (string)($b['queued_at'] ?? '')));
        foreach ($this->all() as $e) $counts[$e['status'] ?? 'new'] = ($counts[$e['status'] ?? 'new'] ?? 0) + 1;
        if ($queued !== []) $next = $queued[0]['id'];
        return ['counts' => $counts, 'queued' => $counts['queued'], 'next' => $next];
    }

    // ── Ablegen ──────────────────────────────────────────────────────────

    /**
     * PDF ablegen. Gleiche Prüfsumme wie ein vorhandener Eintrag → Fehler mit
     * dem vorhandenen (DuplicateDocumentException).
     *
     * @return array<string,mixed>
     */
    public function upload(string $bytes, ?string $name = null): array
    {
        if (AttachmentService::sniff($bytes) !== 'application/pdf') {
            throw new LocalizedException('errors.inbox.pdfOnly', [], 'inbox: not a pdf');
        }
        $sha = hash('sha256', $bytes);
        foreach ($this->all() as $e) {
            if (($e['sha256'] ?? '') === $sha) throw new DuplicateDocumentException(self::summary($e));
        }
        // v3.7.0 (Paket betrieb) — ohne Namen (Kurzbefehl, E-Mail ohne Dateinamen) ein erkennbarer
        if (trim((string)$name) === '') $name = $this->i18n->t('inbox.defaultName', ['date' => date('Y-m-d H.i')]) . '.pdf';
        $att = $this->attachments->store($bytes, 'inbox_pdf', $name);
        $id = 'inb_' . bin2hex(random_bytes(6));
        $entry = [
            'id' => $id, 'attachment_id' => $att['id'], 'sha256' => $sha, 'size' => strlen($bytes),
            'name' => (string)($att['original_name'] ?? ''), 'pages' => null,
            'created_at' => date('c'), 'updated_at' => date('c'),
            'status' => 'new', 'type' => null, 'type_confidence' => null,
            'extraction' => null, 'cache' => [], 'assignment' => null, 'edits' => [], 'applied' => [], 'error' => null,
        ];
        $all = $this->all();
        $all[] = $entry;
        $this->store->write('inbox.json', $all);
        AttachmentService::link($this->store, (string)$att['id'], 'inbox_pdf', ['type' => 'inbox', 'id' => $id]);
        if ($this->onUpload !== null) ($this->onUpload)($entry);
        return $entry;
    }

    /**
     * Zuordnung, Korrekturen, Art, Status oder Notiz ändern.
     *
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public function update(string $id, array $patch): array
    {
        return $this->storeGist($this->persistMarks($this->mutate($id, function (array $e) use ($patch): array {
            if (array_key_exists('assignment', $patch)) $e['assignment'] = $this->assignment($patch['assignment']);
            if (array_key_exists('type', $patch)) {
                $type = (string)$patch['type'];
                if (!InboxSchema::isType($type)) throw new LocalizedException('errors.inbox.typeInvalid', ['type' => $type], 'inbox type');
                if ($type !== ($e['type'] ?? null)) {
                    $e['type'] = $type;
                    $e['type_confidence'] = null;
                    $e['extraction'] = null;
                    $e['edits'] = [];
                    $e['status'] = InboxSchema::extracts($type) ? 'new' : 'filed';
                }
            }
            if (array_key_exists('edits', $patch)) {
                if (!is_array($patch['edits'])) throw new LocalizedException('errors.inbox.editInvalid', ['field' => '', 'value' => ''], 'edits');
                foreach ($patch['edits'] as $field => $value) {
                    $field = (string)$field;
                    if ($value === null) { unset($e['edits'][$field]); continue; }
                    $e['edits'][$field] = $this->editValue((string)($e['type'] ?? ''), $field, $value);
                }
            }
            if (array_key_exists('status', $patch)) {
                $s = (string)$patch['status'];
                if (!in_array($s, ['new', 'filed'], true)) throw new LocalizedException('errors.inbox.statusInvalid', ['status' => $s], 'status');
                $e['status'] = $s === 'new' ? ($e['extraction'] !== null ? 'read' : 'new') : 'filed';
            }
            if (array_key_exists('note', $patch)) $e['note'] = mb_substr(trim((string)$patch['note']), 0, self::NOTE_MAX);
            return $e;
        })));
    }

    /**
     * Löschen: der Eintrag, und das PDF, solange es nur am Posteingang hängt.
     * Hängt es schon als Beleg an einer Rechnung oder einem Vertrag, bleibt es dort.
     */
    public function delete(string $id): void
    {
        $e = $this->get($id);
        $this->store->write('inbox.json', array_values(array_filter($this->all(), fn($x) => $x['id'] !== $id)));
        $att = (string)($e['attachment_id'] ?? '');
        $a = $att !== '' ? $this->attachments->get($att) : null;
        if ($a !== null && ($a['ref']['type'] ?? null) === 'inbox' && ($a['ref']['id'] ?? null) === $id) {
            $this->attachments->delete($att);
        }
    }

    // ── Auslesen ─────────────────────────────────────────────────────────

    /**
     * Schritt 1: Art erkennen. Zwischengespeichert je Anbieter, Modell und
     * Schema-Version. Nur erkannte Arten (Mahnung, Widerruf, Sonstiges) sind
     * danach abgelegt.
     *
     * @param list<string> $pages Textebene je Seite (Browser)
     * @return array<string,mixed> Eintrag
     */
    public function classify(string $id, array $pages, bool $consent = false, bool $fresh = false): array
    {
        $e = $this->get($id);
        $p = $this->provider($consent);
        $prep = $this->prepared($e, $pages, $p);
        $key = self::cacheKey($p);
        $cached = $fresh ? null : ($e['cache'][$key]['classify'] ?? null);
        try {
            $r = $cached ?? $p->classify(TextPrep::prepare($pages, TextPrep::CLASSIFY_CHARS)['text'], $prep['pdf']);
        } catch (\Throwable $ex) {
            $this->fail($id, $ex);
            throw $ex;
        }
        if (!InboxSchema::isType((string)($r['type'] ?? ''))) {
            $ex = new LocalizedException('errors.inbox.badAnswer', [], 'classify: invalid type');
            $this->fail($id, $ex);
            throw $ex;
        }
        return $this->storeGist($this->mutate($id, function (array $x) use ($r, $key, $pages, $cached): array {
            $x['type'] = (string)$r['type'];
            $x['type_confidence'] = $r['confidence'] ?? null;
            $x['pages'] = count($pages);
            if ($cached === null) $x['cache'] = self::remember((array)($x['cache'] ?? []), $key, ['classify' => $r]);
            if (!InboxSchema::extracts($x['type'])) {
                $x['status'] = 'filed';
                $x['extraction'] = ['provider' => self::providerOfKey($key), 'model' => (string)($r['model'] ?? ''), 'schema_version' => InboxSchema::VERSION,
                    'type' => $x['type'], 'fields' => [], 'flags' => 0, 'seconds' => ['classify' => $r['seconds'] ?? null], 'extracted_at' => date('c')];
            }
            $x['error'] = null;
            return $x;
        }));
    }

    /**
     * Schritt 2: die Felder der Art (ohne Art erst Schritt 1), geprüft gegen die
     * Textebene (Inbox\Evidence).
     *
     * @param list<string> $pages
     * @return array<string,mixed> Eintrag
     */
    public function extract(string $id, array $pages, bool $consent = false, bool $fresh = false): array
    {
        $e = $this->get($id);
        if (empty($e['type'])) $e = $this->classify($id, $pages, $consent, $fresh);
        $type = (string)$e['type'];
        if (!InboxSchema::extracts($type)) return $e;
        $p = $this->provider($consent);
        $prep = $this->prepared($e, $pages, $p);
        $key = self::cacheKey($p);
        $cached = $fresh ? null : ($e['cache'][$key]['extract'][$type] ?? null);
        try {
            $r = $cached ?? $p->extract($type, InboxSchema::forProvider($type), $prep['text']['text'], $prep['pdf']);
        } catch (\Throwable $ex) {
            $this->fail($id, $ex);
            throw $ex;
        }
        $fields = Evidence::check($type, (array)($r['fields'] ?? []), $pages);
        // v3.7.0 (treffsicher) — gespeichert werden die Marken nach dem Bestand (Proposals::marks)
        $probe = ['type' => $type, 'extraction' => ['fields' => $fields], 'edits' => [], 'assignment' => $e['assignment'] ?? null] + $e;
        $probe = $this->proposals->marks($probe, is_array($e['assignment'] ?? null) ? $e['assignment'] : $this->proposals->suggest($probe));
        $fields = $probe['extraction']['fields'];
        $flags = Evidence::flagCount($fields);
        return $this->storeGist($this->mutate($id, function (array $x) use ($r, $key, $type, $fields, $flags, $prep, $cached, $pages): array {
            if ($cached === null) {
                $c = (array)($x['cache'][$key] ?? []);
                $c['extract'][$type] = ['fields' => $r['fields'] ?? [], 'model' => $r['model'] ?? '', 'seconds' => $r['seconds'] ?? null];
                $x['cache'] = self::remember((array)($x['cache'] ?? []), $key, $c);
            }
            $x['pages'] = count($pages);
            $x['extraction'] = [
                'provider' => self::providerOfKey($key), 'model' => (string)($r['model'] ?? ''), 'schema_version' => InboxSchema::VERSION,
                'type' => $type, 'fields' => $fields, 'flags' => $flags,
                'seconds' => ['classify' => $x['cache'][$key]['classify']['seconds'] ?? null, 'extract' => $r['seconds'] ?? null],
                'cached' => $cached !== null, 'extracted_at' => date('c'),
                'text' => ['chars' => $prep['text']['chars'], 'kept' => $prep['text']['kept'], 'dropped' => $prep['text']['dropped'], 'truncated' => $prep['text']['truncated']],
            ];
            $x['edits'] = [];
            if (!in_array($x['status'] ?? 'new', ['done'], true)) $x['status'] = 'read';
            $x['error'] = null;
            return $x;
        }));
    }

    private function provider(bool $consent): ExtractionProvider
    {
        $id = $this->providers->activeId();
        $p = $this->providers->get($id);
        if ($p instanceof LocalProvider && !$p->enabled()) throw new LocalizedException('errors.inbox.off', [], 'inbox: off');
        // Ohne ausdrückliche Zustimmung verlässt kein Dokument das Heimnetz
        if ($id !== ProviderRegistry::DEFAULT && !$consent) {
            throw new HttpStatusException(409, 'errors.inbox.consentRequired', ['provider' => $p->label()], [], 'inbox: consent required');
        }
        return $p;
    }

    /**
     * Text (und PDF, wenn der Anbieter es nimmt) für einen Anbieter.
     *
     * @return array{text: array<string,mixed>, pdf: ?string}
     */
    private function prepared(array $e, array $pages, ExtractionProvider $p): array
    {
        $pdf = null;
        if ($p->supportsPdf() && !empty($e['attachment_id'])) {
            $pdf = base64_encode((string)file_get_contents($this->attachments->path((string)$e['attachment_id'])));
        }
        if (!TextPrep::hasText($pages) && $pdf === null) {
            $ex = new LocalizedException('errors.inbox.noText', [], 'inbox: no text layer');
            $this->fail((string)$e['id'], $ex);
            throw $ex;
        }
        return ['text' => TextPrep::prepare($pages), 'pdf' => $pdf];
    }

    /** Schlüssel des Zwischenspeichers: Anbieter|Modell|Schema-Version (je Eintrag = je Prüfsumme). */
    public static function cacheKey(ExtractionProvider $p): string
    {
        $model = method_exists($p, 'model') ? (string)$p->model() : '';
        return $p->id() . '|' . $model . '|' . InboxSchema::VERSION;
    }

    private static function providerOfKey(string $key): string
    {
        return explode('|', $key)[0];
    }

    /** Ein Modell ohne bekannten Namen (Anbieter ohne model()) speichert nichts zwischen. */
    private static function remember(array $cache, string $key, array $value): array
    {
        if (explode('|', $key)[1] === '') return $cache;
        unset($cache[$key]);
        $cache[$key] = $value;
        return array_slice($cache, -self::CACHE_KEEP, null, true);
    }

    private function fail(string $id, \Throwable $ex): void
    {
        try {
            $this->mutate($id, function (array $x) use ($ex): array {
                $x['status'] = in_array($x['status'] ?? '', ['done', 'filed'], true) ? $x['status'] : 'failed';
                $x['error'] = ['key' => $ex instanceof LocalizedException || $ex instanceof HttpStatusException ? $ex->key : 'errors.inbox.failed',
                               'params' => $ex instanceof LocalizedException || $ex instanceof HttpStatusException ? $ex->params : [], 'at' => date('c')];
                return $x;
            });
        } catch (\Throwable) {
            // der Fehler des Anbieters zählt, nicht der beim Vermerken
        }
    }

    // ── Vorschau und Übernehmen ──────────────────────────────────────────

    /**
     * Vorschau: Auslesung, Zuordnung (gespeichert oder vorbelegt) und die
     * vorgeschlagenen Änderungen mit Abgleich.
     *
     * @return array<string,mixed>
     */
    public function preview(string $id): array
    {
        $e = $this->get($id);
        $suggested = $this->proposals->suggest($e);
        $as = is_array($e['assignment'] ?? null) ? $e['assignment'] : $suggested;
        $e = $this->proposals->marks($e, $as);   // v3.7.0 (treffsicher) — Marken nach dem heutigen Bestand
        $dups = $this->duplicatesOf($e);
        $type = (string)($e['type'] ?? '');
        $schema = [];
        foreach (InboxSchema::fields($type) as $name => $f) {
            $schema[$name] = array_filter(['kind' => $f['kind'], 'core' => !empty($f['core']), 'review' => !empty($f['review']), 'aux' => !empty($f['aux']),
                'options' => $f['options'] ?? null, 'items' => isset($f['items']) ? array_map(fn($i) => $i['kind'], $f['items']) : null], fn($v) => $v !== null);
        }
        $entry = $e;
        unset($entry['cache']);
        $proposals = $this->proposals->build($this->withDuplicates($e, $dups), $as);
        return [
            'entry' => $entry,
            'assignment' => ['utility' => $as['utility'] ?? null, 'meter_id' => $as['meter_id'] ?? null, 'contract_id' => $as['contract_id'] ?? null],
            'assignment_saved' => is_array($e['assignment'] ?? null),
            'suggested' => $suggested,
            'proposals' => $proposals,
            // v3.7.0 (Paket betrieb) — Kernaussage und nächster Schritt, frisch gerechnet
            // (treffsicher: kennt Doppel, offene Punkte und „keine Preise“)
            'gist' => $this->gistOf($e, $as, $proposals, $dups),
            'schema' => $schema,
            'review_all' => in_array($type, InboxSchema::REVIEW_ALL, true),
            'recognize_only' => $type !== '' && !InboxSchema::extracts($type),
            // v3.7.0 (treffsicher) — wohl dasselbe Dokument (nach Inhalt, nicht nach Prüfsumme); nichts wird gelöscht
            'duplicates' => array_map(fn($d) => ['id' => $d['id'], 'name' => $d['name'] ?? '', 'created_at' => $d['created_at'] ?? '',
                'status' => $d['status'] ?? 'new'], $dups),
        ];
    }

    /**
     * Angehakte Vorschläge übernehmen. Die IDs stammen aus der Vorschau; ist
     * eine darunter, die es nicht mehr gibt (Auslesung oder Bestand haben sich
     * geändert), passiert nichts.
     *
     * @param list<string> $ids
     * @return array<string,mixed>
     */
    public function apply(string $id, array $ids): array
    {
        $e = $this->get($id);
        if (!is_array($e['extraction']['fields'] ?? null) || !InboxSchema::extracts((string)($e['type'] ?? ''))) {
            throw new LocalizedException('errors.inbox.notRead', [], 'apply without extraction');
        }
        $as = is_array($e['assignment'] ?? null) ? $e['assignment'] : $this->proposals->suggest($e);
        if (empty($as['utility'])) throw new LocalizedException('errors.inbox.assignmentMissing', [], 'apply without utility');
        $all = $this->proposals->build($this->withDuplicates($e, $this->duplicatesOf($e)), $as);
        $byId = array_column($all, null, 'id');
        $selected = [];
        foreach (array_values(array_unique(array_map('strval', $ids))) as $pid) {
            if (!isset($byId[$pid])) throw new LocalizedException('errors.inbox.proposalStale', [], "stale proposal $pid");
            $selected[] = $byId[$pid];
        }
        $res = $this->applier->apply((string)$as['utility'], $selected, $e);
        $now = date('c');
        $entry = $this->persistMarks($this->mutate($id, function (array $x) use ($res, $as, $now): array {
            foreach ($res['applied'] as $a) $x['applied'][] = $a + ['at' => $now];
            if (!is_array($x['assignment'] ?? null)) $x['assignment'] = ['utility' => $as['utility'], 'meter_id' => $as['meter_id'] ?? null, 'contract_id' => $as['contract_id'] ?? null];
            if ($res['failed'] === [] && ($res['applied'] !== [] || $res['skipped'] !== [])) $x['status'] = 'done';
            return $x;
        }));
        // v3.7.0 (Paket betrieb) — danach die Kernaussage; bei einer Rechnung läuft
        // damit „Rechnung prüfen“ (BillService::compare) gleich mit
        $entry = $this->storeGist($entry);
        if ($res['applied'] !== [] && $this->activity !== null) {
            try {
                $this->activity->record([
                    'action' => 'updated',
                    'subject' => ['kind' => 'inbox', 'uid' => 'inbox:' . $id, 'occurrence' => 'inbox:' . $id . '@' . $now],
                    'ref' => array_filter(['inbox_id' => $id, 'utility' => (string)$as['utility'], 'meter_id' => $as['meter_id'] ?? null,
                                           'contract_id' => $as['contract_id'] ?? null], fn($v) => is_string($v) && $v !== ''),
                    'title_key' => 'activity.inbox.applied',
                    'params' => ['name' => (string)($e['name'] ?? ''), 'count' => count($res['applied'])],
                ]);
            } catch (\Throwable) {
                // der Verlauf darf das Übernehmen nie verhindern
            }
        }
        return $res + ['entry' => self::summary($entry), 'proposals' => $this->proposals->build($this->withDuplicates($entry, $this->duplicatesOf($entry)), $as),
            'gist' => $entry['gist'] ?? null];
    }

    /**
     * v3.7.0 (Paket betrieb) — Termin zu einem Dokument, das nur abgelegt wird
     * (Mahnung: „bis wann klären“), am zugeordneten Zähler und bei der
     * zuständigen Person. Das Dokument ist danach abgelegt.
     *
     * @return array<string,mixed> Eintrag
     */
    public function remind(string $id, mixed $due): array
    {
        $e = $this->get($id);
        if (!is_string($due) || !Dates::isIsoDate($due)) throw new LocalizedException('errors.reminder.dueRequired', [], 'inbox remind: due');
        $as = is_array($e['assignment'] ?? null) ? $e['assignment'] : $this->proposals->suggest($e);
        $label = ($e['type'] ?? '') === 'dunning' ? 'dunning' : 'document';
        $ref = $this->applier->documentReminder(
            $this->proposals->documentReminderData($e, $as, $label, $due, ['name' => (string)($e['name'] ?? '')]), $e);
        $entry = $this->mutate($id, function (array $x) use ($ref, $label, $due): array {
            $x['applied'][] = ['id' => 'p_' . substr(sha1("reminder|$label|$due"), 0, 12), 'kind' => 'reminder', 'ref' => $ref, 'key' => "$label@$due", 'at' => date('c')];
            if (($x['status'] ?? '') !== 'done') $x['status'] = 'filed';
            return $x;
        });
        return $this->storeGist($entry);
    }

    /**
     * v3.7.0 (Paket betrieb) — Kernaussage rechnen (Zuordnung und Vorschläge wie
     * in der Vorschau); ein Fehler darf nichts anderes aufhalten.
     *
     * @param array<string,mixed> $e
     * @return array<string,mixed>|null
     */
    private function gistOf(array $e, ?array $as = null, ?array $proposals = null, ?array $dups = null): ?array
    {
        if ($this->gist === null) return null;
        try {
            $as ??= is_array($e['assignment'] ?? null) ? $e['assignment'] : $this->proposals->suggest($e);
            // v3.7.0 (treffsicher) — inhaltsgleiche Dokumente: an der Kernaussage als Hinweis,
            // und was sie übernommen haben, zählt beim Abgleich der Vorschläge
            $dups ??= $this->duplicatesOf($e);
            $proposals ??= $this->proposals->build($this->withDuplicates($e, $dups), $as);
            $e['duplicates'] = array_map(fn($d) => ['id' => $d['id'], 'name' => $d['name'] ?? '', 'created_at' => $d['created_at'] ?? '',
                'status' => $d['status'] ?? 'new'], $dups);
            return $this->gist->describe($e, $as, $proposals);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Kernaussage am Eintrag speichern (nach jedem Schreiben am Eintrag). */
    private function storeGist(array $entry): array
    {
        if ($this->gist === null) return $entry;
        $g = $this->gistOf($entry);
        if ($g === null && !array_key_exists('gist', $entry)) return $entry;
        try {
            return $this->mutate((string)$entry['id'], function (array $x) use ($g): array {
                $x['gist'] = $g;
                return $x;
            });
        } catch (\Throwable) {
            return $entry;
        }
    }

    /**
     * v3.7.0 (Haushalt aus Dokumenten) — nach dem Übernehmen eines Entwurfs
     * (InboxDraftService): je Dokument das Übernommene vermerken, die Zuordnung
     * setzen, wo keine gespeichert ist, und den Stand (`done`: vom Entwurf
     * vollständig abgedeckt; `filed`: wohl doppelt, das Original ist
     * übernommen — `duplicate_of` rechnet die Liste). Ein Schreibvorgang für
     * alle; danach wie apply() je Eintrag Marken und Kernaussage.
     *
     * @param array<string,array{applied?: list<array<string,mixed>>, assignment?: array<string,mixed>, status?: string}> $updates
     */
    public function settle(array $updates): void
    {
        if ($updates === []) return;
        $now = date('c');
        $all = $this->all();
        foreach ($all as $i => $e) {
            $u = $updates[$e['id']] ?? null;
            if ($u === null) continue;
            foreach ((array)($u['applied'] ?? []) as $a) $all[$i]['applied'][] = $a + ['at' => $now];
            if (!is_array($e['assignment'] ?? null) && is_array($u['assignment'] ?? null)) $all[$i]['assignment'] = $this->assignment($u['assignment']);
            if (in_array($u['status'] ?? null, ['done', 'filed'], true)) $all[$i]['status'] = $u['status'];
            $all[$i]['updated_at'] = $now;
        }
        $this->store->write(self::FILE, $all);
        // wie apply(): Marken nach dem Bestand und die Kernaussage nach dem Übernehmen
        foreach ($all as $e) if (isset($updates[$e['id']])) $this->storeGist($this->persistMarks($e));
    }

    // ── Warteschlange ────────────────────────────────────────────────────

    /**
     * Dokumente zum Auslesen vormerken: die angegebenen, sonst alle neuen und
     * fehlgeschlagenen. Abgearbeitet wird Schritt für Schritt, angestoßen von
     * der geöffneten Seite (der Browser liest die Textebene und ruft
     * classify/extract) — ohne Cron. Ein abgebrochener Lauf setzt dort fort,
     * wo er stand: Vorgemerkt bleibt, was noch nicht ausgelesen ist.
     *
     * @param list<string>|null $ids
     */
    public function queue(?array $ids = null): array
    {
        $now = date('c');
        $all = $this->all();
        $n = 0;
        foreach ($all as $i => $e) {
            if ($ids !== null && !in_array($e['id'], $ids, true)) continue;
            if (!in_array($e['status'] ?? 'new', ['new', 'failed'], true) || empty($e['attachment_id'])) continue;
            $all[$i]['status'] = 'queued';
            $all[$i]['queued_at'] = $now . sprintf('#%05d', $n);   // Reihenfolge der Liste
            $n++;
        }
        if ($n > 0) $this->store->write('inbox.json', $all);
        return $this->queueState() + ['added' => $n];
    }

    /** Warteschlange abbrechen: Vorgemerktes ist wieder neu. */
    public function cancelQueue(): array
    {
        $all = $this->all();
        $n = 0;
        foreach ($all as $i => $e) {
            if (($e['status'] ?? '') !== 'queued') continue;
            $all[$i]['status'] = $e['extraction'] !== null ? 'read' : 'new';
            unset($all[$i]['queued_at']);
            $n++;
        }
        if ($n > 0) $this->store->write('inbox.json', $all);
        return $this->queueState() + ['cancelled' => $n];
    }

    /**
     * Prüfliste: nur die markierten Felder aller ausgelesenen, noch nicht
     * übernommenen Dokumente (nach der Massenverarbeitung).
     *
     * @return list<array<string,mixed>>
     */
    public function review(): array
    {
        $out = [];
        foreach ($this->all() as $e) {
            if (($e['status'] ?? '') !== 'read' || !is_array($e['extraction']['fields'] ?? null)) continue;
            $e = $this->effective($e);   // v3.7.0 (treffsicher) — Marken nach dem heutigen Bestand
            foreach ($e['extraction']['fields'] as $name => $f) {
                if (array_key_exists($name, (array)($e['edits'] ?? []))) continue;
                $rows = !empty($f['items']) ? $f['items'] : [$f];
                foreach ($rows as $i => $row) {
                    if (($row['status'] ?? '') !== 'check') continue;
                    $out[] = ['entry_id' => $e['id'], 'name' => $e['name'] ?? '', 'type' => $e['type'], 'field' => $name,
                              'item' => !empty($f['items']) ? $i : null, 'value' => $row['value'] ?? null,
                              'quote' => $row['quote'] ?? null, 'page' => $row['page'] ?? null, 'reasons' => $row['reasons'] ?? []];
                }
            }
        }
        return $out;
    }

    // ── Dubletten nach Inhalt (v3.7.0, treffsicher) ──────────────────────

    /**
     * Felder, an denen ein Dokument zu erkennen ist (Zeitraum bzw. Stichtag
     * zuerst, dann Nummern und Beträge). Die Prüfsumme erkennt nur dieselbe
     * Datei; im Nutzentest lagen 8 inhaltsgleiche Dokumente in verschiedenen
     * Dateien.
     */
    private const IDENTITY = [
        'invoice' => ['period_from', 'period_to', 'total_amount', 'result_amount', 'consumption', 'reading_new', 'contract_number', 'customer_number', 'meter_number'],
        'contract_confirmation' => ['supply_start', 'working_price', 'base_price', 'advance', 'tariff_name', 'contract_number', 'customer_number', 'meter_number'],
        'price_change' => ['valid_from', 'working_price_new', 'base_price_new', 'contract_number', 'customer_number'],
        'cancellation' => ['supply_end', 'cancel_kind', 'contract_number', 'customer_number', 'meter_number'],
        'supply_start' => ['supply_start', 'meter_number', 'reading_start', 'contract_number', 'customer_number'],
        'meter_change' => ['change_date', 'old_meter_number', 'new_meter_number', 'old_reading', 'new_reading'],
        'reading_request' => ['requested_by', 'meter_number'],
        'advance_change' => ['valid_from', 'advance_new', 'contract_number', 'customer_number'],
    ];

    /**
     * Wohl dasselbe Dokument? Gleiche Art, gleicher Zeitraum bzw. Stichtag (das
     * erste Feld der Liste), und jedes weitere Feld, das beide nennen, stimmt
     * überein — mindestens zwei weitere. Nummern ohne Leerraum und Satzzeichen,
     * Beträge auf den Cent.
     */
    public static function sameContent(array $a, array $b): bool
    {
        $type = (string)($a['type'] ?? '');
        $fields = self::IDENTITY[$type] ?? null;
        if ($fields === null || $type !== (string)($b['type'] ?? '') || !is_array($a['extraction']['fields'] ?? null) || !is_array($b['extraction']['fields'] ?? null)) return false;
        $both = 0;
        foreach ($fields as $i => $f) {
            $x = Proposals::value($a, $f);
            $y = Proposals::value($b, $f);
            if ($x === null || $x === '' || $y === null || $y === '') {
                if ($i === 0) return false;
                continue;
            }
            $same = is_numeric($x) && is_numeric($y) ? abs((float)$x - (float)$y) < 0.005
                : (is_string($x) && is_string($y) && (Proposals::sameId($x, $y) || mb_strtolower(trim($x)) === mb_strtolower(trim($y))));
            if (!$same) return false;
            if ($i > 0) $both++;
        }
        return $both >= 2;
    }

    /**
     * Die anderen Einträge mit demselben Inhalt, älteste zuerst.
     *
     * @return list<array<string,mixed>>
     */
    private function duplicatesOf(array $e): array
    {
        $out = array_values(array_filter($this->all(), fn($x) => $x['id'] !== $e['id'] && self::sameContent($e, $x)));
        usort($out, fn($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
        return $out;
    }

    /**
     * Das ältere inhaltsgleiche Dokument (ID) oder null — für Liste und Akte. Bei
     * gleicher Sekunde gilt die Reihenfolge des Ablegens.
     *
     * @param list<array<string,mixed>>|null $all
     */
    public function olderDuplicateOf(array $e, ?array $all = null): ?string
    {
        $all ??= $this->all();
        $pos = array_flip(array_column($all, 'id'));
        $rank = fn(array $x): string => (string)($x['created_at'] ?? '') . '|' . sprintf('%06d', $pos[$x['id']] ?? 0);
        foreach ($all as $o) {
            if ($o['id'] !== $e['id'] && $rank($o) < $rank($e) && self::sameContent($e, $o)) return (string)$o['id'];
        }
        return null;
    }

    /** Eintrag mit dem, was inhaltsgleiche Dokumente schon übernommen haben (Termine erkennt Proposals daran). */
    private function withDuplicates(array $e, array $dups): array
    {
        $e['duplicates_applied'] = array_merge(...array_map(fn($d) => array_values((array)($d['applied'] ?? [])), [[], ...$dups]));
        return $e;
    }

    // ── intern ───────────────────────────────────────────────────────────

    /**
     * v3.7.0 (treffsicher) — Eintrag mit den Marken nach dem heutigen Bestand
     * (ohne zu schreiben). Vorschau, Liste und Prüfliste rechnen so bei jedem
     * Abruf, auch für Dokumente, die eine ältere Version ausgelesen hat.
     * Gespeichert werden die Marken beim Auslesen, Ändern (auch ein leeres
     * PATCH) und Übernehmen (persistMarks).
     */
    private function effective(array $e): array
    {
        if (!is_array($e['extraction']['fields'] ?? null) || !InboxSchema::extracts((string)($e['type'] ?? ''))) return $e;
        return $this->proposals->marks($e, is_array($e['assignment'] ?? null) ? $e['assignment'] : $this->proposals->suggest($e));
    }

    /** Marken nach dem Bestand speichern, wenn sie sich geändert haben. */
    private function persistMarks(array $e): array
    {
        $x = $this->effective($e);
        if (($x['extraction'] ?? null) === ($e['extraction'] ?? null)) return $e;
        return $this->mutate((string)$e['id'], function (array $y) use ($x): array {
            if (!is_array($y['extraction']['fields'] ?? null)) return $y;
            $y['extraction']['fields'] = $x['extraction']['fields'];
            $y['extraction']['flags'] = $x['extraction']['flags'];
            return $y;
        });
    }

    /**
     * Frisch lesen, ändern, schreiben — nach einem langen Aufruf darf kein
     * zwischenzeitlich geschriebener Eintrag verloren gehen.
     *
     * @param callable(array<string,mixed>): array<string,mixed> $fn
     * @return array<string,mixed>
     */
    private function mutate(string $id, callable $fn): array
    {
        $all = $this->all();
        foreach ($all as $i => $e) {
            if ($e['id'] !== $id) continue;
            $all[$i] = $fn($e);
            $all[$i]['updated_at'] = date('c');
            $this->store->write('inbox.json', $all);
            return $all[$i];
        }
        throw new NotFoundException($this->i18n->t('errors.inbox.notFound'));
    }

    /** @return array{utility: ?string, meter_id: ?string, contract_id: ?string}|null */
    private function assignment(mixed $in): ?array
    {
        if ($in === null) return null;
        if (!is_array($in)) throw new LocalizedException('errors.inbox.assignmentInvalid', [], 'assignment');
        $u = $in['utility'] ?? null;
        if ($u !== null && $u !== '' && !Utilities::exists((string)$u)) {
            throw new LocalizedException('errors.common.unknownUtility', ['utility' => (string)$u], 'assignment utility');
        }
        $u = $u === '' ? null : $u;
        $mid = isset($in['meter_id']) && $in['meter_id'] !== '' ? (string)$in['meter_id'] : null;
        $cid = isset($in['contract_id']) && $in['contract_id'] !== '' ? (string)$in['contract_id'] : null;
        if ($u === null && ($mid !== null || $cid !== null)) throw new LocalizedException('errors.inbox.assignmentInvalid', [], 'assignment without utility');
        return ['utility' => $u, 'meter_id' => $mid, 'contract_id' => $cid];
    }

    /** Korrektur eines Felds nach dessen Art (Datum, Zahl, Auswahl, Text). */
    private function editValue(string $type, string $field, mixed $value): mixed
    {
        $def = InboxSchema::fields($type)[$field] ?? null;
        $bad = fn() => new LocalizedException('errors.inbox.editInvalid', ['field' => $field, 'value' => is_scalar($value) ? (string)$value : ''], 'edit');
        if ($def === null || $def['kind'] === 'list') throw $bad();
        switch ($def['kind']) {
            case 'date':
                $v = is_string($value) && Dates::isIsoDate($value) ? $value : Evidence::dateValue($value);
                if ($v === null) throw $bad();
                return $v;
            case 'number': case 'money': case 'price_ct':
                // Text wie im Dokument (deutsch, Tausenderpunkt zuerst); die Oberfläche schickt Zahlen
                $n = is_string($value) ? (Evidence::valueReadings($value)[0] ?? null) : (is_int($value) || is_float($value) ? (float)$value : null);
                if ($n === null || !is_finite($n) || $n < 0) throw $bad();
                return round($n, 6);
            case 'enum':
                if (!in_array($value, $def['options'], true)) throw $bad();
                return $value;
            default:
                $v = is_scalar($value) ? trim((string)$value) : '';
                if ($v === '') throw $bad();
                return mb_substr($v, 0, 200);
        }
    }
}
