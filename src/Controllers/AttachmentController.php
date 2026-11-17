<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Http\UploadBody;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\I18nService;
use Energietracker\Services\OcrService;
use Energietracker\Support\LocalizedException;

/**
 * v3.1.0 (Paket H2, B1) — Belege hochladen, ausliefern, auflisten, löschen.
 *
 * Upload als roher Body: `POST /api/attachments?kind=reading_photo` mit dem Bild oder PDF, seit v3.13.0 auch als
 * Formular (Http\UploadBody). Den Typ bestimmt der Inhalt, nicht der Content-Type.
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
        // v3.7.0 — `ocr_timeout_s` darf bis 300 s; PHP bricht sonst nach 30 s ab
        if (function_exists('set_time_limit')) @set_time_limit(360);
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
        // v3.13.0 — eine Art außerhalb der Liste ist ein Fehler, nicht still „other“ (`rechnung` statt `bill_pdf`);
        // ohne Angabe bleibt es „other“
        $kind = (string)$req->queryParam('kind', 'other');
        AttachmentService::assertKind($kind);
        // v3.13.0 — roh oder als Formular (Feld `file` oder die einzige Datei; der Dateiname gilt, wenn `&name=` fehlt);
        // Grenzen von PHP heißen „zu groß“ mit der Grenze, die griff. Dieselbe Regel wie im Posteingang (UploadBody).
        [$bytes, $fileName] = UploadBody::read($req);
        $name ??= $fileName;
        $entry = $this->attachments->store($bytes, $kind, $name);
        Response::json($entry, 201);
    }

    /**
     * v3.12.0 — Härtung: `private, no-cache` mit ETag statt ein Jahr
     * `immutable`. Der Browser fragt vor jeder Anzeige nach (unverändert: 304
     * ohne Inhalt); nach dem Abmelden antwortet der Server darauf 401, statt
     * dass der Beleg aus dem Zwischenspeicher kommt. Der ETag ist die
     * gespeicherte Prüfsumme des Inhalts.
     */
    public function show(Request $req): never
    {
        $id = (string)$req->param('id');
        $path = $this->attachments->path($id);
        $a = $this->attachments->get($id);
        $hash = (string)($a['sha256'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) $hash = sha1($id . '|' . (int)@filesize($path) . '|' . (int)@filemtime($path));
        $etag = '"' . substr($hash, 0, 32) . '"';
        while (ob_get_level() > 0) ob_end_clean();
        $match = in_array($etag, array_map('trim', explode(',', (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))), true);
        if (!headers_sent()) {
            header('Cache-Control: private, no-cache');
            header('ETag: ' . $etag);
            header('X-Content-Type-Options: nosniff');
            if ($match) {
                http_response_code(304);
                header_remove('Content-Type');
                exit;
            }
            header('Content-Type: ' . $a['mime']);
            header('Content-Length: ' . (string)filesize($path));
            header('Content-Disposition: inline; filename="' . $id . '.' . AttachmentService::extension($a['mime']) . '"');
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
