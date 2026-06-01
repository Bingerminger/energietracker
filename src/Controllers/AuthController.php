<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\AuthService;

/**
 * F1009 — Verwaltung des API-Tokens für externe Schreibzugriffe (HA-Ingest).
 *
 * Routen:
 *   GET    /api/auth/token   → Status { enabled, created_at }  (nie der Token)
 *   POST   /api/auth/token   → erzeugt/erneuert, gibt EINMALIG den Klartext zurück
 *   DELETE /api/auth/token   → widerruft (API wieder offen)
 */
final class AuthController
{
    public function __construct(private AuthService $auth) {}

    public function status(Request $req): never
    {
        Response::json($this->auth->status());
    }

    public function generate(Request $req): never
    {
        $token = $this->auth->generate();
        // Der Klartext-Token wird hier zum EINZIGEN Mal ausgeliefert.
        Response::json([
            'token'      => $token,
            'created_at' => date('c'),
            'hint'       => 'Diesen Token jetzt kopieren — er wird nicht erneut angezeigt.',
        ], 201);
    }

    public function revoke(Request $req): never
    {
        $this->auth->revoke();
        Response::json(['enabled' => false]);
    }
}
