<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\ContractService;

/**
 * Vertrags-CRUD (Provider, Tarif, Stichtag-Preise, Boni). Strikte
 * F4-Validierung: halb-ausgefüllte Subzeilen werden mit HTTP 400 und
 * präziser Fehlermeldung wie `working_prices-Eintrag #N: ct_per_kwh fehlt`
 * abgelehnt.
 */
final class ContractController
{
    public function __construct(private ContractService $contracts) {}

    public function index(Request $req): never
    {
        $meterId = $req->queryParam('meter_id');
        Response::json($this->contracts->list($req->param('utility'), $meterId));
    }

    public function show(Request $req): never
    {
        $c = $this->contracts->get($req->param('utility'), $req->param('id'));
        if (!$c) Response::error('Vertrag nicht gefunden', 404);
        Response::json($c);
    }

    public function create(Request $req): never
    {
        $c = $this->contracts->create($req->param('utility'), (array)$req->body);
        Response::json($c, 201);
    }

    public function update(Request $req): never
    {
        $c = $this->contracts->update($req->param('utility'), $req->param('id'), (array)$req->body);
        Response::json($c);
    }

    public function destroy(Request $req): never
    {
        $this->contracts->delete($req->param('utility'), $req->param('id'));
        Response::json(['deleted' => true]);
    }
}
