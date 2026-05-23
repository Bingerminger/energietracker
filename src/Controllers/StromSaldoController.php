<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\StromSaldoService;

/**
 * F1005 — Kombinierte Strom-Saldo-Sicht (Bezug − PV-Einspeisung).
 * Liefert monatliche und jährliche Aggregate für das Hauptdashboard.
 */
final class StromSaldoController
{
    public function __construct(private StromSaldoService $saldo) {}

    public function index(Request $req): never
    {
        Response::json($this->saldo->compute());
    }
}
