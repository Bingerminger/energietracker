<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\PeriodService;

/**
 * v3.1.0 (Paket H3, B2) — Verbrauch je Zeitraum: Liste, anlegen, ändern,
 * löschen, CSV-Import (mit Trockenlauf). Der CSV-Export liegt beim ExportController.
 */
final class PeriodController
{
    public function __construct(private PeriodService $periods) {}

    public function index(Request $req): never
    {
        Response::json($this->periods->list((string)$req->param('utility'), $req->queryParam('meter_id')));
    }

    public function create(Request $req): never
    {
        $p = $this->periods->create((string)$req->param('utility'), (array)$req->body);
        Response::json($p, !empty($p['duplicate']) ? 200 : 201);
    }

    public function update(Request $req): never
    {
        Response::json($this->periods->update((string)$req->param('utility'), (string)$req->param('id'), (array)$req->body));
    }

    public function destroy(Request $req): never
    {
        $this->periods->delete((string)$req->param('utility'), (string)$req->param('id'));
        Response::json(['deleted' => true]);
    }

    public function importCsv(Request $req): never
    {
        Response::json($this->periods->importCsv(
            (string)$req->param('utility'), (string)$req->param('id'), $req->rawBody, $req->queryParam('dry_run') === '1'
        ));
    }

}
