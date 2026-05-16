<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\TariffComparisonService;

/**
 * v1.3.0 — Tarifvergleich (echt vs. Schattenverträge).
 * GET /api/utility/{utility}/meters/{id}/tariff-comparison?year=YYYY
 */
final class TariffComparisonController
{
    public function __construct(private TariffComparisonService $tariffs) {}

    public function compare(Request $req): never
    {
        $year = $req->queryParam('year');
        Response::json($this->tariffs->compare(
            $req->param('utility'),
            $req->param('id'),
            $year !== null ? (int)$year : null
        ));
    }
}
