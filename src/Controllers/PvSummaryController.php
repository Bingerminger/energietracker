<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\PvSummaryService;

/**
 * F1005 — PV-Eigenverbrauch + Autarkiequote.
 */
final class PvSummaryController
{
    public function __construct(private PvSummaryService $summary) {}

    public function index(Request $req): never
    {
        Response::json($this->summary->compute());
    }
}
