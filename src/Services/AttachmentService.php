<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\LocalizedException;

/**
 * v3.1.0 (Paket H2, B1) — Belege: Fotos von Zählerständen, PDFs von
 * Abrechnungen.
 *
 * Dateien liegen unter `data/attachments/<id>.<ext>` (das Datenverzeichnis ist
 * für den Webserver gesperrt; ausgeliefert wird nur über die API), der Index
 * im Topf `attachments.json`:
 *   {id, kind, mime, size, sha256, created_at, ref: {type, utility?, id}|null, original_name?}
 *
 * Geprüft wird der Inhalt, nicht die Endung: JPEG, PNG, WebP oder PDF anhand
 * der ersten Bytes; SVG und HTML werden nie angenommen. Grenzen: Foto 3 MB,
 * PDF 10 MB, insgesamt `attachments_max_mb` (Standard 500). Ein Beleg ohne
 * Verweis (Ablesung gelöscht, Upload ohne Speichern) wird nach 24 Stunden
 * aufgeräumt — so bleibt „Rückgängig" möglich.
 */
final class AttachmentService
{
    public const KINDS = ['reading_photo', 'bill_pdf', 'statement_pdf', 'other'];
    public const INDEX = 'attachments.json';
    public const ID = '/^att_[0-9a-f]{16}$/';
    private const IMAGE_MAX = 3 * 1024 * 1024;
    private const PDF_MAX = 10 * 1024 * 1024;
    private const ORPHAN_AFTER = 86400;
    private const EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
        private I18nService $i18n,
    ) {}

    /** MIME-Typ aus den ersten Bytes, sonst null. */
    public static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF")                                       => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n")                                 => 'image/png',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP'           => 'image/webp',
            str_starts_with($bytes, '%PDF-')                                             => 'application/pdf',
            default                                                                       => null,
        };
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $idx = $this->store->read(self::INDEX, []);
        return is_array($idx) ? array_values(array_filter($idx, 'is_array')) : [];
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        if (!preg_match(self::ID, $id)) return null;
        foreach ($this->list() as $a) if (($a['id'] ?? '') === $id) return $a;
        return null;
    }

    /** Absoluter Pfad der Datei eines Belegs (für das Ausliefern). */
    public function path(string $id): string
    {
        $a = $this->get($id) ?? throw new NotFoundException($this->i18n->t('errors.attachment.notFound'));
        $path = $this->store->path('attachments/' . $id . '.' . (self::EXT[$a['mime']] ?? 'bin'));
        if (!is_file($path)) throw new NotFoundException($this->i18n->t('errors.attachment.notFound'));
        return $path;
    }

    /**
     * Legt einen Beleg an.
     *
     * @return array<string,mixed> Indexeintrag
     */
    public function store(string $bytes, string $kind, ?string $originalName = null): array
    {
        if (!in_array($kind, self::KINDS, true)) $kind = 'other';
        $mime = self::sniff($bytes);
        if ($mime === null) {
            throw new LocalizedException('errors.attachment.type', [], 'Attachment: unsupported content');
        }
        $limit = $mime === 'application/pdf' ? self::PDF_MAX : self::IMAGE_MAX;
        if (strlen($bytes) > $limit) {
            throw new LocalizedException('errors.attachment.size', ['max' => (int)($limit / 1048576)], 'Attachment too large');
        }
        $this->cleanupOrphans();
        $usage = $this->usage();
        if ($usage['bytes'] + strlen($bytes) > $usage['max_bytes']) {
            throw new LocalizedException('errors.attachment.quota', ['max' => (int)round($usage['max_bytes'] / 1048576)], 'Attachment quota exceeded');
        }
        $id = 'att_' . bin2hex(random_bytes(8));
        $this->store->writeBinary('attachments/' . $id . '.' . self::EXT[$mime], $bytes);
        $entry = [
            'id'         => $id,
            'kind'       => $kind,
            'mime'       => $mime,
            'size'       => strlen($bytes),
            'sha256'     => hash('sha256', $bytes),
            'created_at' => date('c'),
            'ref'        => null,
        ];
        $name = trim((string)$originalName);
        if ($name !== '') $entry['original_name'] = mb_substr(basename($name), 0, 120);
        $all = $this->list();
        $all[] = $entry;
        $this->store->write(self::INDEX, $all);
        return $entry;
    }

    /** Löscht Datei und Indexeintrag; der Verweis im Datensatz verschwindet mit. */
    public function delete(string $id): void
    {
        $a = $this->get($id) ?? throw new NotFoundException($this->i18n->t('errors.attachment.notFound'));
        if (is_array($a['ref'] ?? null)) $this->detach($a['ref'], $id);
        @unlink($this->store->path('attachments/' . $id . '.' . (self::EXT[$a['mime']] ?? 'bin')));
        $this->store->write(self::INDEX, array_values(array_filter($this->list(), fn($x) => ($x['id'] ?? '') !== $id)));
    }

    /** Datei eines Datensatzes je Verweisart; null = unbekannt (dann bleibt der Datensatz, wie er ist). */
    public static function recordFile(array $ref): ?string
    {
        $u = (string)($ref['utility'] ?? '');
        return match ((string)($ref['type'] ?? '')) {
            'reading' => Utilities::exists($u) ? "$u/readings.json" : null,
            'period'  => Utilities::exists($u) ? "$u/periods.json" : null,
            'bill'    => Utilities::exists($u) ? "$u/bills.json" : null,
            'tenancy_statement' => 'tenancy_statements.json',
            default   => null,
        };
    }

    /** Entfernt `attachment_id` bzw. den Eintrag in `attachment_ids` am Datensatz. */
    private function detach(array $ref, string $id): void
    {
        $file = self::recordFile($ref);
        if ($file === null || !$this->store->exists($file)) return;
        $rows = $this->store->read($file, []);
        if (!is_array($rows)) return;
        $changed = false;
        foreach ($rows as $i => $r) {
            if (!is_array($r) || ($r['id'] ?? null) !== ($ref['id'] ?? '')) continue;
            if (($r['attachment_id'] ?? null) === $id) { unset($rows[$i]['attachment_id']); $changed = true; }
            if (is_array($r['attachment_ids'] ?? null) && in_array($id, $r['attachment_ids'], true)) {
                $rows[$i]['attachment_ids'] = array_values(array_diff($r['attachment_ids'], [$id]));
                $changed = true;
            }
        }
        if ($changed) $this->store->write($file, array_values($rows));
    }

    /** @return array{count:int, bytes:int, max_bytes:int} */
    public function usage(): array
    {
        $list = $this->list();
        $max = max(1, (int)$this->settings->get('attachments_max_mb', 500)) * 1048576;
        return ['count' => count($list), 'bytes' => array_sum(array_map(fn($a) => (int)($a['size'] ?? 0), $list)), 'max_bytes' => $max];
    }

    /**
     * Belege ohne Verweis, älter als 24 Stunden, und Dateien ohne Indexeintrag
     * entfernen.
     */
    public function cleanupOrphans(?int $now = null): int
    {
        return self::sweep($this->store, $now);
    }

    /** Wie cleanupOrphans(), statisch für den Backup-Dienst (läuft beim Rotieren der Snapshots). */
    public static function sweep(JsonStore $store, ?int $now = null): int
    {
        $now ??= time();
        $keep = []; $removed = 0;
        $idx = $store->read(self::INDEX, []);
        foreach (is_array($idx) ? array_filter($idx, 'is_array') : [] as $a) {
            $since = (string)($a['unlinked_at'] ?? $a['created_at'] ?? '');
            $orphan = empty($a['ref']) && strtotime($since) < $now - self::ORPHAN_AFTER;
            if ($orphan && preg_match(self::ID, (string)($a['id'] ?? ''))) {
                @unlink($store->path('attachments/' . $a['id'] . '.' . (self::EXT[$a['mime'] ?? ''] ?? 'bin')));
                $removed++;
                continue;
            }
            $keep[] = $a;
        }
        if ($removed > 0) $store->write(self::INDEX, $keep);
        $known = array_flip(array_map(fn($a) => ($a['id'] ?? '') . '.' . (self::EXT[$a['mime'] ?? ''] ?? 'bin'), $keep));
        foreach (glob($store->path('attachments') . '/att_*') ?: [] as $file) {
            $base = basename($file);
            if (str_contains($base, '.tmp.')) continue;
            if (!isset($known[$base]) && filemtime($file) < $now - self::ORPHAN_AFTER) { @unlink($file); $removed++; }
        }
        return $removed;
    }

    /**
     * Verknüpft einen Beleg mit seinem Datensatz (oder löst ihn mit `$ref = null`).
     * Statisch, damit Dienste ohne eigene AttachmentService-Instanz (ReadingService)
     * es nutzen können. Prüft Existenz und Art.
     *
     * @param array{type:string, utility?:string, id:string}|null $ref
     */
    public static function link(JsonStore $store, string $id, ?string $kind, ?array $ref): void
    {
        $all = $store->read(self::INDEX, []);
        if (!is_array($all)) $all = [];
        foreach ($all as $i => $a) {
            if (!is_array($a) || ($a['id'] ?? '') !== $id) continue;
            if ($kind !== null && ($a['kind'] ?? '') !== $kind) {
                throw new LocalizedException('errors.attachment.wrongKind', ['kind' => $kind], "Attachment $id is not $kind");
            }
            $all[$i]['ref'] = $ref;
            unset($all[$i]['unlinked_at']);
            $store->write(self::INDEX, array_values($all));
            return;
        }
        throw new LocalizedException('errors.attachment.notFound', [], "Attachment $id not found");
    }

    /** Belege eines Datensatzes lösen (z. B. beim Löschen einer Ablesung). */
    public static function unlinkRef(JsonStore $store, string $type, string $refId): void
    {
        $all = $store->read(self::INDEX, []);
        if (!is_array($all)) return;
        $changed = false;
        foreach ($all as $i => $a) {
            if (is_array($a) && ($a['ref']['type'] ?? null) === $type && ($a['ref']['id'] ?? null) === $refId) {
                $all[$i]['ref'] = null;
                $all[$i]['unlinked_at'] = date('c');   // ab jetzt 24 h bis zum Aufräumen
                $changed = true;
            }
        }
        if ($changed) $store->write(self::INDEX, array_values($all));
    }

    /** Datei-Endung je MIME-Typ (für Backup/Restore). */
    public static function extension(string $mime): string
    {
        return self::EXT[$mime] ?? 'bin';
    }
}
