<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\DemoService;

/**
 * F1007 (v1.7.4) — Endpoints für den Demo-Daten-Komfort-Import.
 *   GET  /api/demo/status  → { available, is_empty }
 *   POST /api/demo/import  → importiert (Body: { force: bool })
 */
final class DemoController
{
    public function __construct(private DemoService $demo) {}

    public function status(Request $req): never
    {
        Response::json($this->demo->status());
    }

    public function import(Request $req): never
    {
        $force = (bool)$req->input('force', false);
        Response::json($this->demo->import($force));
    }
}
