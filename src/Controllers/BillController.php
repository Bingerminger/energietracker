<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\BillService;

/**
 * v3.1.0 (Paket H5, UI-35) — Versorgerrechnungen: Liste, anlegen, ändern,
 * löschen, gegen die eigene Rechnung prüfen, Ergebnis buchen.
 */
final class BillController
{
    public function __construct(private BillService $bills) {}

    public function index(Request $req): never
    {
        Response::json($this->bills->list((string)$req->param('utility'), $req->queryParam('meter_id')));
    }

    public function create(Request $req): never
    {
        Response::json($this->bills->create((string)$req->param('utility'), (array)$req->body), 201);
    }

    public function update(Request $req): never
    {
        Response::json($this->bills->update((string)$req->param('utility'), (string)$req->param('id'), (array)$req->body));
    }

    public function destroy(Request $req): never
    {
        $this->bills->delete((string)$req->param('utility'), (string)$req->param('id'));
        Response::json(['deleted' => true]);
    }

    public function check(Request $req): never
    {
        Response::json($this->bills->compare((string)$req->param('utility'), (string)$req->param('id')));
    }

    public function book(Request $req): never
    {
        Response::json($this->bills->book((string)$req->param('utility'), (string)$req->param('id')));
    }
}
