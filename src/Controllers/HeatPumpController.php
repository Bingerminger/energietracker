<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\HeatPumpService;
use Energietracker\Services\I18nService;

/** v3.1.0 (Paket H7, MKT-18) — GET /api/heat-pump?year= (Standard: Vorjahr). */
final class HeatPumpController
{
    public function __construct(private HeatPumpService $pumps, private I18nService $i18n) {}

    public function index(Request $req): never
    {
        $y = (string)($req->queryParam('year') ?? '');
        if ($y !== '' && (!ctype_digit($y) || (int)$y < 1990 || (int)$y > 2100)) {
            Response::error($this->i18n->t('errors.heatPump.yearInvalid', ['year' => $y]), 400, null, 'errors.heatPump.yearInvalid');
        }
        Response::json($this->pumps->forYear($y === '' ? (int)date('Y') - 1 : (int)$y));
    }
}
