<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\ContractService;
use Energietracker\Services\I18nService;
use Energietracker\Services\ResponsibilityService;

/**
 * Vertrags-CRUD (Provider, Tarif, Stichtag-Preise, Boni). Strikte
 * F4-Validierung: halb-ausgefüllte Subzeilen werden mit HTTP 400 und
 * präziser Fehlermeldung wie `working_prices-Eintrag #N: ct_per_kwh fehlt`
 * abgelehnt.
 *
 * v3.7.0 — Zuständig: `assignee_id` (Person aus dem Verzeichnis) schreiben;
 * die Antworten tragen dazu `assignee`, `assignee_former`, `assignee_deleted`.
 */
final class ContractController
{
    public function __construct(
        private ContractService $contracts,
        private I18nService $i18n,
        private ?ResponsibilityService $responsibility = null,
    ) {}

    /** v3.7.0 — Vertrag mit Zuständigkeit für die Antwort. */
    private function out(array $c): array
    {
        return $this->responsibility?->decorate($c) ?? $c;
    }

    /** v3.1.0 (H6, MKT-12) — POST /api/utility/{u}/contracts/{id}/prices/import-csv[?dry_run=1], Body: CSV */
    public function importPrices(Request $req): never
    {
        Response::json($this->contracts->importMonthlyPrices((string)$req->param('utility'), (string)$req->param('id'),
            (string)$req->rawBody, $req->queryParam('dry_run') === '1'));
    }

    // v3.4.0 (N1021) — je Vertrag additiv `phase` (future, current, renewed,
    // past, shadow) und `values_on` (Preise und Abschlag am Stichtag)
    public function index(Request $req): never
    {
        $utility = (string)$req->param('utility');
        $meterId = $req->queryParam('meter_id');
        $list = $this->contracts->list($utility, $meterId);
        $all = $meterId === null ? $list : $this->contracts->list($utility);
        $out = $this->contracts->withPhase($list, $all);
        Response::json($this->responsibility?->decorateAll($out) ?? $out);
    }

    public function show(Request $req): never
    {
        $utility = (string)$req->param('utility');
        $c = $this->contracts->get($utility, $req->param('id'));
        if (!$c) Response::error($this->i18n->t('errors.contract.notFound'), 404);
        Response::json($this->out($this->contracts->withPhase([$c], $this->contracts->list($utility))[0]));
    }

    public function create(Request $req): never
    {
        $c = $this->contracts->create($req->param('utility'), (array)$req->body);
        Response::json($this->out($c), 201);
    }

    public function update(Request $req): never
    {
        $c = $this->contracts->update($req->param('utility'), $req->param('id'), (array)$req->body);
        Response::json($this->out($c));
    }

    public function destroy(Request $req): never
    {
        $this->contracts->delete($req->param('utility'), $req->param('id'));
        Response::json(['deleted' => true]);
    }
}
