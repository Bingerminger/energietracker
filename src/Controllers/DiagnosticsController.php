<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\DiagnosticsService;

/**
 * System-Diagnose als Read-only-Endpoint. Nützlich für ein einfaches
 * Self-Check beim Deployen.
 */
final class DiagnosticsController
{
    public function __construct(private DiagnosticsService $diag) {}

    public function index(Request $req): never
    {
        Response::json($this->diag->run());
    }
}
