<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\AuthService;
use Energietracker\Services\IngestService;

/**
 * F1009 — Push-Ingest-Endpoint für Home Assistant.
 *
 *   POST /api/ingest
 *   Header: Authorization: Bearer <token>   (nur falls ein Token gesetzt ist)
 *   Body:   { "utility":"strom", "meter":"stromzaehler_haus",
 *             "value":12345.6, "date":"2026-06-01" }   // date optional
 *
 * Auth-Modell (opt-in): Ist KEIN Token konfiguriert, ist der Endpoint offen
 * (unverändertes LAN-Verhalten). Sobald ein Token existiert, muss er als
 * Bearer mitgeschickt werden — sonst 401.
 */
final class IngestController
{
    public function __construct(
        private IngestService $ingest,
        private AuthService $auth,
    ) {}

    public function store(Request $req): never
    {
        if ($this->auth->requiresAuth() && !$this->auth->verify($req->bearerToken())) {
            Response::error('Nicht autorisiert — gültigen API-Token als „Authorization: Bearer …" senden', 401);
        }
        $result = $this->ingest->ingest((array)$req->body);
        // 201 bei neu angelegt, 200 bei Aktualisierung (upsert-by-date).
        Response::json($result, $result['status'] === 'created' ? 201 : 200);
    }
}
