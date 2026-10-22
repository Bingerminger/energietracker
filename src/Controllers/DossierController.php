<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\DossierService;
use Energietracker\Services\I18nService;

/**
 * v3.7.0 (Paket betrieb) — Akte je Zähler und Vertrag:
 * `GET /api/utility/{utility}/dossier?meter_id=…` bzw. `?contract_id=…`.
 */
final class DossierController
{
    public function __construct(private DossierService $dossier, private I18nService $i18n) {}

    public function show(Request $req): never
    {
        $u = (string)$req->param('utility');
        $contract = trim((string)($req->queryParam('contract_id') ?? ''));
        $meter = trim((string)($req->queryParam('meter_id') ?? ''));
        if ($contract === '' && $meter === '') {
            Response::error($this->i18n->t('errors.dossier.scope'), 400, null, 'errors.dossier.scope');
        }
        Response::json(['items' => $contract !== '' ? $this->dossier->forContract($u, $contract) : $this->dossier->forMeter($u, $meter)]);
    }
}
