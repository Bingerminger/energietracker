<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\HealthCheckService;

/**
 * N1003 — Health-Check für Monitoring und Self-Diagnose.
 *
 * v2.6.0 — HTTP 503 bei `status: "error"` (Docker-HEALTHCHECK und Monitore
 * erkennen die Störung jetzt am Statuscode). Ist die Anmeldung aktiv und der
 * Aufrufer nicht angemeldet, gibt es nur die Minimalform {status, version}.
 */
final class HealthController
{
    /** @param (callable(): bool)|null $authenticated */
    public function __construct(private HealthCheckService $health, private $authenticated = null) {}

    public function index(Request $req): never
    {
        $full = $this->authenticated === null || ($this->authenticated)();
        $data = $full ? $this->health->run() : $this->health->minimal();
        Response::json($data, $data['status'] === 'error' ? 503 : 200);
    }
}
