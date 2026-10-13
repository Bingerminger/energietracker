<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\HttpStatusException;
use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\ActivityService;

/**
 * v3.4.0 (F1024) — Verlauf erledigter Hinweise (Stabilitätsklasse C):
 *   GET    /api/activity?from&to&by&kind&ref&limit   Einträge, neueste zuerst
 *   POST   /api/activity/{id}/undo                   Rückgängig (streicht durch, stellt zurück)
 *   PATCH  /api/activity/{id}          {note}        Notiz ändern
 *   DELETE /api/activity?before=JJJJ-MM-TT           Verlauf leeren (nur Verwalter)
 * Abhaken selbst: POST /api/agenda/done (AgendaController).
 */
final class ActivityController
{
    public function __construct(private ActivityService $activity) {}

    public function index(Request $req): never
    {
        $f = [];
        foreach (['from', 'to', 'by', 'kind', 'ref', 'limit'] as $k) {
            $v = $req->queryParam($k);
            if ($v !== null && $v !== '') $f[$k] = $v;
        }
        Response::json($this->activity->list($f));
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
