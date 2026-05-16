<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\BenchmarkService;

/**
 * v1.3.0 — Effizienz-Benchmark-Endpoint.
 * GET /api/benchmarks/efficiency?year=YYYY
 */
final class BenchmarkController
{
    public function __construct(private BenchmarkService $benchmark) {}

    public function efficiency(Request $req): never
    {
        $year = $req->queryParam('year');
        Response::json($this->benchmark->efficiency(
            $year !== null ? (int)$year : null
        ));
    }
}
