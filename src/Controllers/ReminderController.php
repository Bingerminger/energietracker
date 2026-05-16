<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\ReminderService;

/**
 * v1.3.0 — Termin-/Wartungserinnerungen.
 *   GET    /api/reminders
 *   POST   /api/reminders
 *   PATCH  /api/reminders/{id}
 *   DELETE /api/reminders/{id}
 *   POST   /api/reminders/{id}/done   {done_date?}
 */
final class ReminderController
{
    public function __construct(private ReminderService $reminders) {}

    public function index(Request $req): never
    {
        Response::json($this->reminders->listWithStatus());
    }

    public function create(Request $req): never
    {
        Response::json($this->reminders->create((array)$req->body), 201);
    }

    public function update(Request $req): never
    {
        Response::json($this->reminders->update($req->param('id'), (array)$req->body));
    }

    public function destroy(Request $req): never
    {
        $this->reminders->delete($req->param('id'));
        Response::json(['deleted' => true]);
    }

    public function done(Request $req): never
    {
        $doneDate = is_array($req->body) ? ($req->body['done_date'] ?? null) : null;
        Response::json($this->reminders->markDone($req->param('id'), $doneDate));
    }
}
