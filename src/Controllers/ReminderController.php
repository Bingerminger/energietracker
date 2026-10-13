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
 *   POST   /api/reminders/{id}/done   {done_date?, note?, attachment_id?}
 *          v3.4.0 (F1024) — mit Verlaufseintrag, Antwort zusätzlich `activity_id`
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
        $b = is_array($req->body) ? $req->body : [];
        $str = fn(string $k): ?string => isset($b[$k]) && is_scalar($b[$k]) && $b[$k] !== '' ? (string)$b[$k] : null;
        Response::json($this->reminders->markDone($req->param('id'), $str('done_date'), $str('note'), $str('attachment_id')));
    }
}
