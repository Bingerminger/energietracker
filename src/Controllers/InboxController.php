<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\NotFoundException;
use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\I18nService;
use Energietracker\Services\Inbox\DuplicateDocumentException;
use Energietracker\Services\Inbox\FolderIntake;
use Energietracker\Services\Inbox\TextPrep;
use Energietracker\Services\InboxService;
use Energietracker\Support\LocalizedException;

/**
 * v3.7.0 (Posteingang) — Versorgerpost ablegen, auslesen, prüfen, übernehmen.
 *
 * Ablegen als roher Körper wie ein Beleg: `POST /api/inbox?name=…` mit dem PDF.
 * Auslesen in zwei Schritten (`classify`, dann `extract`), jeweils mit der
 * Textebene `{pages: [...]}` aus dem Browser — so bleibt jede Anfrage unter
 * dem Zeitlimit eines vorgeschalteten Webservers. Antwortet der Dienst nicht
 * oder unbrauchbar, ist das ein 502 (Fehler auf der anderen Seite).
 */
final class InboxController
{
    private const UPSTREAM = ['errors.ocr.timeout', 'errors.ocr.unreachable', 'errors.inbox.badAnswer', 'errors.inbox.modelMissing'];
    /** v3.7.0 — Laufzeit für Erkennen/Auslesen (PHP-Standard 30 s reicht lokalen Modellen nicht) */
    public const READ_TIME_LIMIT_S = 660;

    public function __construct(private InboxService $inbox, private I18nService $i18n, private ?FolderIntake $folder = null) {}

    public function index(Request $req): never
    {
        Response::json(['entries' => $this->inbox->list(), 'queue' => $this->inbox->queueState(), 'provider' => $this->inbox->providerInfo(),
            // v3.7.0 (Paket betrieb) — überwachter Ordner (Name relativ zum Datenverzeichnis)
            'folder' => $this->folder?->status()]);
    }

    /**
     * Ablegen. Roh (`Content-Type: application/pdf`, Name in `?name=`) — so
     * schickt es die Oberfläche und der Kurzbefehl „Teilen → Energietracker“.
     * v3.7.0 (Paket betrieb) — oder als JSON `{name?, pdf_base64}`: Home
     * Assistant reicht Anhänge aus der IMAP-Integration als Text weiter
     * (imap.fetch_part liefert den Anhang base64-kodiert, mit Zeilenumbrüchen).
     */
    public function create(Request $req): never
    {
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($req->rawBody === '' && $length > 0) {
            throw new LocalizedException('errors.attachment.size', ['max' => 10], 'body over post_max_size');
        }
        $bytes = $req->rawBody;
        $name = $req->queryParam('name');
        if (!str_starts_with($bytes, '%PDF-') && is_array($req->body) && array_key_exists('pdf_base64', $req->body)) {
            // streng: nur das Base64-Alphabet (Zeilenumbrüche aus der Mail überspringt PHP auch so)
            $b64 = is_string($req->body['pdf_base64']) ? $req->body['pdf_base64'] : '';
            $decoded = $b64 !== '' ? base64_decode($b64, true) : false;
            if ($decoded === false) throw new LocalizedException('errors.inbox.base64Invalid', [], 'inbox: pdf_base64 invalid');
            $bytes = $decoded;
            if (is_string($req->body['name'] ?? null) && trim($req->body['name']) !== '') $name = $req->body['name'];
        }
        try {
            $entry = $this->inbox->upload($bytes, $name);
        } catch (DuplicateDocumentException $e) {
            Response::error($this->i18n->t('errors.inbox.duplicate', [
                'date' => $this->i18n->date(substr((string)($e->existing['created_at'] ?? ''), 0, 10)),
                'name' => (string)($e->existing['name'] ?? ''),
            ]), 409, ['existing' => $e->existing], 'errors.inbox.duplicate', true);
        }
        Response::json(InboxService::summary($entry), 201);
    }

    public function show(Request $req): never
    {
        Response::json($this->inbox->preview($this->id($req)));
    }

    public function update(Request $req): never
    {
        $this->inbox->update($this->id($req), is_array($req->body) ? $req->body : []);
        Response::json($this->inbox->preview($this->id($req)));
    }

    public function destroy(Request $req): never
    {
        $this->inbox->delete($this->id($req));
        Response::json(['deleted' => true]);
    }

    public function classify(Request $req): never
    {
        $this->read($req, false);
    }

    public function extract(Request $req): never
    {
        $this->read($req, true);
    }

    private function read(Request $req, bool $fields): never
    {
        $id = $this->id($req);
        try {
            $pages = TextPrep::pages($req->input('pages'));
        } catch (\InvalidArgumentException) {
            throw new LocalizedException('errors.inbox.pagesInvalid', [], 'pages');
        }
        $consent = $req->input('consent') === true;
        $fresh = $req->input('fresh') === true;
        // Lokale Modelle brauchen je Anfrage oft länger als die 30 s, die PHP
        // vorgibt; sonst bricht PHP mitten im Auslesen ab (am Dev-Server samt
        // Server). Obergrenze: längste Wartezeit eines Anbieters (Cloud bis
        // 600 s) plus Puffer.
        if (function_exists('set_time_limit')) @set_time_limit(self::READ_TIME_LIMIT_S);
        try {
            $fields ? $this->inbox->extract($id, $pages, $consent, $fresh) : $this->inbox->classify($id, $pages, $consent, $fresh);
        } catch (LocalizedException $e) {
            Response::error($this->i18n->t($e->key, $e->params), in_array($e->key, self::UPSTREAM, true) ? 502 : 400, null, $e->key);
        }
        Response::json($this->inbox->preview($id));
    }

    public function apply(Request $req): never
    {
        $ids = $req->input('proposals', []);
        Response::json($this->inbox->apply($this->id($req), is_array($ids) ? array_values(array_map('strval', $ids)) : []));
    }

    public function queue(Request $req): never
    {
        $ids = $req->input('ids');
        Response::json($this->inbox->queue(is_array($ids) ? array_values(array_map('strval', $ids)) : null));
    }

    public function cancelQueue(Request $req): never
    {
        Response::json($this->inbox->cancelQueue());
    }

    public function review(Request $req): never
    {
        Response::json($this->inbox->review());
    }

    /** v3.7.0 (Paket betrieb) — überwachten Ordner einlesen (nur Verwalter, s. App::adminOnly). */
    public function folder(Request $req): never
    {
        if ($this->folder === null) Response::json(['dir' => FolderIntake::DIR, 'imported' => [], 'duplicates' => [], 'failed' => [], 'skipped' => [], 'waiting' => 0, 'more' => false]);
        Response::json($this->folder->scan());
    }

    /** v3.7.0 (Paket betrieb) — Termin zu einem abgelegten Dokument (Mahnung): `{date}`. */
    public function remind(Request $req): never
    {
        $this->inbox->remind($this->id($req), $req->input('date'));
        Response::json($this->inbox->preview($this->id($req)));
    }

    private function id(Request $req): string
    {
        $id = (string)$req->param('id');
        if (!preg_match(InboxService::ID, $id)) throw new NotFoundException($this->i18n->t('errors.inbox.notFound'));
        return $id;
    }
}
