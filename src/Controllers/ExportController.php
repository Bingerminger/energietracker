<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\CsvExportService;

/**
 * Tabellarischer CSV-Export (F-07, v1.1.0).
 *
 * Drei Datensätze, jeweils als Datei-Download (text/csv):
 *   GET /api/export/{utility}/monthly.csv   — Monatsaggregate
 *   GET /api/export/{utility}/readings.csv  — Rohablesungen
 *   GET /api/export/temperatures.csv        — Temperaturreihe
 *
 * Ergänzt das JSON-Backup; für eine vollständige, wieder-importierbare
 * Sicherung weiterhin `GET /api/backup/export` verwenden.
 */
final class ExportController
{
    public function __construct(private CsvExportService $export) {}

    public function monthly(Request $req): never
    {
        $utility = $req->param('utility');
        Response::csv(
            $this->export->monthly($utility),
            $this->export->filename('monatsuebersicht', $utility)
        );
    }

    public function readings(Request $req): never
    {
        $utility = $req->param('utility');
        Response::csv(
            $this->export->readings($utility),
            $this->export->filename('ablesungen', $utility)
        );
    }

    public function deliveries(Request $req): never
    {
        $utility = $req->param('utility');
        Response::csv(
            $this->export->deliveries($utility),
            $this->export->filename('lieferungen', $utility)
        );
    }

    public function temperatures(Request $req): never
    {
        Response::csv(
            $this->export->temperatures(),
            $this->export->filename('temperaturen')
        );
    }
}
