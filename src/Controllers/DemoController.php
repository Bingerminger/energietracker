<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\DemoService;
use Energietracker\Support\Input;

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
        // v3.13.0 — Schalter nach der einen Regel (Input::flag): `(bool)"nein"` war ja und ersetzte den
        // Haushalt durch die Beispieldaten; Unlesbares ist 400 statt ersetzen
        $force = Input::flag($req->input('force', false), 'force');
        // v3.2.0 (F1018) — Beispielhaushalt einer Persona (leer = alle Arten); v3.13.0 — eine Liste statt Text: 400 statt 500
        $persona = (string)Input::text(['persona' => $req->input('persona', '')], 'persona');
        Response::json($this->demo->import($force, null, $persona === '' ? null : $persona));
    }
}
