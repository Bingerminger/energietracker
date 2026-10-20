<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\UpdateCheckService;

/**
 * v3.6.0 — GET /api/update-check: ob es eine neuere Version gibt (nur mit
 * eingeschaltetem Hinweis; sonst `enabled: false` ohne Anfrage nach außen).
 */
final class UpdateCheckController
{
    public function __construct(private UpdateCheckService $updates) {}

    public function check(Request $req): never
    {
        Response::json($this->updates->check());
    }
}
