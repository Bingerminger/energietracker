<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

use Energietracker\Http\NotFoundException;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\InboxService;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037, Paket schreiben) — das Original-PDF eines Dokuments aus dem
 * Posteingang für die KI (Konzept §3.5, Entscheidung 12a): Ressource
 * `energietracker://dokument/{id}.pdf`, Base64 bis MAX_BYTES, **nur** mit der
 * Einstellung `mcp_share_documents` (ab Werk aus).
 *
 * Die Ressourcenliste baut das Paket doku-vorlagen (Mcp\Resources); es ruft für
 * eine solche URI `read()` und für die Vorlage `TEMPLATE`. Ohne Freigabe nennt
 * get_document keine URI, und `read()` lehnt ab, auch wenn die KI die URI rät.
 */
final class DocumentOriginal
{
    public const TEMPLATE = 'energietracker://dokument/{id}.pdf';
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const URI = '#^energietracker://dokument/(inb_[0-9a-f]{12})\.pdf$#';

    public function __construct(private Services $services) {}

    public static function uri(string $documentId): string
    {
        return str_replace('{id}', $documentId, self::TEMPLATE);
    }

    /** Kennung des Dokuments einer URI, sonst null (keine Dokument-URI). */
    public static function idOf(string $uri): ?string
    {
        return preg_match(self::URI, $uri, $m) ? $m[1] : null;
    }

    /**
     * Inhalt der Ressource `{uri, mimeType, blob}`; null, wenn die URI kein
     * Dokument meint (dann ist es eine andere Ressource).
     *
     * @return array{uri:string, mimeType:string, blob:string}|null
     * @throws LocalizedException errors.mcp.document.originalOff|originalTooLarge
     * @throws NotFoundException unbekanntes Dokument oder ohne PDF
     */
    public function read(string $uri): ?array
    {
        $id = self::idOf($uri);
        if ($id === null) return null;
        if (!$this->services->get(Config::class)->shareDocuments()) {
            throw new LocalizedException('errors.mcp.document.originalOff', [], 'mcp: sharing documents is off');
        }
        $entry = $this->services->get(InboxService::class)->get($id);
        $attachments = $this->services->get(AttachmentService::class);
        $att = is_string($entry['attachment_id'] ?? null) ? $attachments->get($entry['attachment_id']) : null;
        $path = $att !== null ? $attachments->path((string)$entry['attachment_id']) : null;
        if ($path === null || !is_file($path)) throw new LocalizedException('errors.mcp.document.originalMissing', [], 'mcp: no pdf');
        $size = (int)filesize($path);
        if ($size > self::MAX_BYTES) {
            throw new LocalizedException('errors.mcp.document.originalTooLarge', ['max' => intdiv(self::MAX_BYTES, 1024 * 1024)], 'mcp: pdf too large');
        }
        return ['uri' => $uri, 'mimeType' => 'application/pdf', 'blob' => base64_encode((string)file_get_contents($path))];
    }
}
