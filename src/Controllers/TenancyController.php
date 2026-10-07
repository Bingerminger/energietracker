<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\TenancyBudgetService;
use Energietracker\Services\TenancyService;

/**
 * v3.1.0 (Paket H3, F1008) — Mietverhältnisse, Abrechnungen, Budget.
 */
final class TenancyController
{
    public function __construct(private TenancyService $tenancies, private TenancyBudgetService $budget) {}

    public function index(Request $req): never
    {
        Response::json($this->tenancies->list());
    }

    public function create(Request $req): never
    {
        Response::json($this->tenancies->create((array)$req->body), 201);
    }

    public function update(Request $req): never
    {
        Response::json($this->tenancies->update((string)$req->param('id'), (array)$req->body));
    }

    public function destroy(Request $req): never
    {
        $this->tenancies->delete((string)$req->param('id'));
        Response::json(['deleted' => true]);
    }

    public function budget(Request $req): never
    {
        $asOf = $req->queryParam('as_of');
        Response::json($this->budget->budget((string)$req->param('id'),
            $asOf !== null && \Energietracker\Support\Dates::isIsoDate($asOf) ? $asOf : null));
    }

    public function statements(Request $req): never
    {
        Response::json($this->tenancies->statementsOf((string)$req->param('id')));
    }

    public function createStatement(Request $req): never
    {
        Response::json($this->tenancies->createStatement((string)$req->param('id'), (array)$req->body), 201);
    }

    public function updateStatement(Request $req): never
    {
        Response::json($this->tenancies->updateStatement((string)$req->param('id'), (string)$req->param('sid'), (array)$req->body));
    }

    public function destroyStatement(Request $req): never
    {
        $this->tenancies->deleteStatement((string)$req->param('id'), (string)$req->param('sid'));
        Response::json(['deleted' => true]);
    }
}
