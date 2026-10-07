<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\I18nService;
use Energietracker\Services\OcrService;
use Energietracker\Support\LocalizedException;

/**
 * v3.1.0 (Paket H2, B1) — Belege hochladen, ausliefern, auflisten, löschen.
 *
 * Upload als roher Body (kein multipart): `POST /api/attachments?kind=reading_photo`
 * mit dem Bild oder PDF. Den Typ bestimmt der Inhalt, nicht der Content-Type.
 */
final class AttachmentController
{
    public function __construct(
        private AttachmentService $attachments,
        private OcrService $ocr,
        private I18nService $i18n,
    ) {}

    /**
     * `POST /api/ocr/reading` `{attachment_id}` — Texterkennung über den
     * eigenen Dienst im Heimnetz. Antwortet der Dienst nicht oder unbrauchbar,
     * ist das ein 502 (Fehler auf der anderen Seite), keine falsche Eingabe.
     */
    public function ocr(Request $req): never
    {
        try {
            $r = $this->ocr->read((string)$req->input('attachment_id', ''));
        } catch (LocalizedException $e) {
            $upstream = in_array($e->key, ['errors.ocr.timeout', 'errors.ocr.unreachable', 'errors.ocr.badAnswer'], true);
            Response::error($this->i18n->t($e->key, $e->params), $upstream ? 502 : 400, null, $e->key);
        }
        Response::json($r);
    }

    public function index(Request $req): never
    {
        Response::json(['attachments' => $this->attachments->list(), 'usage' => $this->attachments->usage()]);
    }

    public function create(Request $req): never
    {
        $name = $req->queryParam('name');
        // Größer als post_max_size: PHP liefert einen leeren Körper — das ist
        // „zu groß", nicht „falscher Typ"
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($req->rawBody === '' && $length > 0) {
            throw new LocalizedException('errors.attachment.size',
                ['max' => max(1, (int)floor(self::iniBytes((string)ini_get('post_max_size')) / 1048576))], 'body over post_max_size');
        }
        $entry = $this->attachments->store($req->rawBody, (string)$req->queryParam('kind', 'other'), $name);
        Response::json($entry, 201);
    }

    /** „8M" → Bytes */
    private static function iniBytes(string $v): int
    {
        $n = (int)$v;
        return match (strtolower(substr(trim($v), -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        };
    }

    public function show(Request $req): never
    {
        $id = (string)$req->param('id');
        $path = $this->attachments->path($id);
        $a = $this->attachments->get($id);
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: ' . $a['mime']);
            header('Content-Length: ' . (string)filesize($path));
            header('Content-Disposition: inline; filename="' . $id . '.' . AttachmentService::extension($a['mime']) . '"');
            // Ein Beleg ändert sich nie (neue Datei = neue ID)
            header('Cache-Control: private, max-age=31536000, immutable');
            header('X-Content-Type-Options: nosniff');
            // Ein PDF läuft ohne Skripte und ohne Zugriff auf die App
            if ($a['mime'] === 'application/pdf') header('Content-Security-Policy: sandbox');
        }
        readfile($path);
        exit;
    }

    public function destroy(Request $req): never
    {
        $this->attachments->delete((string)$req->param('id'));
        Response::json(['deleted' => true]);
    }
}
