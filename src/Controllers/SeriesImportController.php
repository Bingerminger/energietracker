<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\SeriesImportService;

/** v3.1.0 (Paket H8, MKT-19) — POST /api/utility/{u}/meters/{id}/import-series[?dry_run=1], Body {csv, mapping}. */
final class SeriesImportController
{
    public function __construct(private SeriesImportService $series) {}

    public function import(Request $req): never
    {
        $body = (array)$req->body;
        // v3.12.0 — `csv` als Liste ergab 500, `mapping` als Text eine Liste mit einem Eintrag (Support\Input)
        if (isset($body['mapping']) && !is_array($body['mapping'])) throw \Energietracker\Support\Input::objectExpected('mapping');
        Response::json($this->series->import((string)$req->param('utility'), (string)$req->param('id'),
            (string)\Energietracker\Support\Input::text($body, 'csv'), (array)($body['mapping'] ?? []), $req->queryParam('dry_run') === '1'));
    }
}
