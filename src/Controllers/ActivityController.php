<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\HttpStatusException;
use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\ActivityService;
use Energietracker\Services\CsvExportService;

/**
 * v3.4.0 (F1024) — Verlauf erledigter Hinweise (Stabilitätsklasse C):
 *   GET    /api/activity?from&to&by&kind&ref&limit   Einträge, neueste zuerst
 *   POST   /api/activity/{id}/undo                   Rückgängig (streicht durch, stellt zurück)
 *   PATCH  /api/activity/{id}          {note}        Notiz ändern
 *   DELETE /api/activity?before=JJJJ-MM-TT           Verlauf leeren (nur Verwalter)
 *   GET    /api/activity.csv?…&format=1|local&lang=  v3.5.0 — derselbe Verlauf als
 *          CSV zum Nachweis (Filter wie die Liste, Durchgestrichenes nicht)
 * Abhaken selbst: POST /api/agenda/done (AgendaController).
 */
final class ActivityController
{
    public function __construct(private ActivityService $activity, private ?CsvExportService $csv = null) {}

    public function index(Request $req): never
    {
        Response::json($this->activity->list($this->filter($req)));
    }

    /** v3.5.0 — Spalten Datum, Aktion, Titel, Wer, Grund, Notiz; Format wie die übrigen CSV-Exporte. */
    public function csv(Request $req): never
    {
        $format = (string)($req->queryParam('format') ?? '1');
        CsvExportService::assertFormat($format);
        $lang = $req->queryParam('lang');
        $f = $this->filter($req);
        $f['limit'] ??= (string)ActivityService::LIMIT_MAX;
        $entries = array_values(array_filter($this->activity->list($f), fn($e) => empty($e['undone'])));
        $csv = $this->csv ?? throw new \LogicException('csv export not attached');
        Response::csv($csv->activity($entries, $this->activity, $format, $lang), $csv->filename('activity', null, $format, $lang));
    }

    /** @return array<string,string> Filter aus der Anfrage */
    private function filter(Request $req): array
    {
        $f = [];
        foreach (['from', 'to', 'by', 'kind', 'ref', 'limit'] as $k) {
            $v = $req->queryParam($k);
            if ($v !== null && $v !== '') $f[$k] = $v;
        }
        return $f;
    }

    public function undo(Request $req): never
    {
        Response::json($this->activity->undo((string)$req->param('id')));
    }

    public function update(Request $req): never
    {
        $body = is_array($req->body) ? $req->body : [];
        if (!array_key_exists('note', $body)) throw new HttpStatusException(400, 'errors.activity.noteInvalid', ['max' => ActivityService::NOTE_MAX]);
        Response::json($this->activity->setNote((string)$req->param('id'), $body['note']));
    }

    public function clear(Request $req): never
    {
        Response::json(['deleted' => $this->activity->clear($req->queryParam('before'))]);
    }
}
