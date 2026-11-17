<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\FeedInPayoutService;

/**
 * v3.13.0 (Paket vertrag) — Auszahlungen der Einspeisung: Liste (optional je Zähler), anlegen, ändern, löschen.
 * Rechte wie beim Erfassen einer Rechnung: Verwalter und Mitglieder, Nur-Lese-Schlüssel nicht.
 */
final class FeedInPayoutController
{
    public function __construct(private FeedInPayoutService $payouts) {}

    public function index(Request $req): never
    {
        $mid = $req->queryParam('meter_id');
        Response::json($this->payouts->list(is_string($mid) && $mid !== '' ? $mid : null));
    }

    public function create(Request $req): never
    {
        Response::json($this->payouts->create(is_array($req->body) ? $req->body : []), 201);
    }

    public function update(Request $req): never
    {
        Response::json($this->payouts->update((string)$req->param('id'), is_array($req->body) ? $req->body : []));
    }

    /** Antwort: der gelöschte Eintrag (für „Rückgängig“). */
    public function destroy(Request $req): never
    {
        Response::json(['deleted' => true, 'payout' => $this->payouts->delete((string)$req->param('id'))]);
    }
}
