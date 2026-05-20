<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\ReadingService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\SettingsService;

/**
 * Ablesungs-CRUD. Auto-Zuweisung von device_id zum aktiven Device
 * des Meters.
 *
 * F-06 (v1.1.0): `importCsv` nimmt einen rohen CSV-Text-Body und importiert
 * ihn zähler-gebunden über den ReadingImportService — vorhandene Ablesungen
 * am selben Datum werden überschrieben und im Report gemeldet.
 *
 * F1004 (v1.6.0): `overview()` liefert den Aggregat-Endpunkt für den
 * zentralen Zählerstand-Erfassungs-View (alle aktiven kumulativen Zähler +
 * jeweils letzte Ablesung in einem Roundtrip).
 */
final class ReadingController
{
    public function __construct(
        private ReadingService $readings,
        private ReadingImportService $import,
        private SettingsService $settings,
    ) {}

    public function index(Request $req): never
    {
        $meterId = $req->queryParam('meter_id');
        Response::json($this->readings->list($req->param('utility'), $meterId));
    }

    public function create(Request $req): never
    {
        $r = $this->readings->create($req->param('utility'), (array)$req->body);
        Response::json($r, 201);
    }

    public function update(Request $req): never
    {
        $r = $this->readings->update($req->param('utility'), $req->param('id'), (array)$req->body);
        Response::json($r);
    }

    public function destroy(Request $req): never
    {
        $this->readings->delete($req->param('utility'), $req->param('id'));
        Response::json(['deleted' => true]);
    }

    /**
     * POST /api/utility/{utility}/meters/{id}/readings/import-csv
     * Body: raw CSV text (Content-Type: text/plain).
     */
    public function importCsv(Request $req): never
    {
        $report = $this->import->importCsv(
            $req->param('utility'),
            $req->param('id'),
            $req->rawBody
        );
        Response::json($report);
    }

    /**
     * GET /api/readings-overview
     * F1004 (v1.6.0) — Aggregat für die zentrale Zählerstand-Erfassung.
     */
    public function overview(Request $req): never
    {
        $active = $this->settings->get('active_utilities', []);
        if (!is_array($active)) $active = [];
        Response::json(['rows' => $this->readings->overview($active)]);
    }
}
