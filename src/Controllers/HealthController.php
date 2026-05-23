<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\HealthCheckService;

/**
 * N1003 — Health-Check für Monitoring und Self-Diagnose.
 */
final class HealthController
{
    public function __construct(private HealthCheckService $health) {}

    public function index(Request $req): never
    {
        Response::json($this->health->run());
    }
}
