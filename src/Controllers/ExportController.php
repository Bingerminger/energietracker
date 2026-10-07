<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\CsvExportService;
use Energietracker\Services\PeriodService;

/**
 * Tabellarischer CSV-Export (F-07, v1.1.0).
 *
 * Vier Datensätze, jeweils als Datei-Download (text/csv):
 *   GET /api/export/{utility}/monthly.csv     — Monatsaggregate
 *   GET /api/export/{utility}/readings.csv    — Rohablesungen
 *   GET /api/export/{utility}/deliveries.csv  — Lieferungen (Heizöl, Pellets)
 *   GET /api/export/temperatures.csv          — Temperaturreihe
 *   GET /api/export/{utility}/periods.csv     — Verbrauch je Zeitraum (v3.1.0)
 *
 * v3.1.0 (Review I18N-10) — `?format=1` (Standard, eingefroren) oder
 * `?format=local` (Sprache der Installation, optional `&lang=xx`).
 *
 * Ergänzt das JSON-Backup; für eine vollständige, wieder-importierbare
 * Sicherung weiterhin `GET /api/backup/export` verwenden.
 */
final class ExportController
{
    public function __construct(private CsvExportService $export, private ?PeriodService $periods = null) {}

    /** v3.1.0 (H3, B2) — Zeiträume einer Verbrauchsart, über alle Zähler */
    public function periods(Request $req): never
    {
        $utility = (string)$req->param('utility');
        [$format, $lang] = $this->options($req);
        Response::csv(
            $this->export->periods($utility, $this->periods?->list($utility) ?? [], $format, $lang),
            $this->export->filename('periods', $utility, $format, $lang)
        );
    }

    /** @return array{0:string,1:?string} Format und Sprache aus der Anfrage */
    private function options(Request $req): array
    {
        $format = (string)($req->queryParam('format') ?? '1');
        CsvExportService::assertFormat($format);
        return [$format, $req->queryParam('lang')];
    }

    public function monthly(Request $req): never
    {
        $utility = $req->param('utility');
        [$format, $lang] = $this->options($req);
        Response::csv(
            $this->export->monthly($utility, $format, $lang),
            $this->export->filename('monthly', $utility, $format, $lang)
        );
    }

    public function readings(Request $req): never
    {
        $utility = $req->param('utility');
        [$format, $lang] = $this->options($req);
        Response::csv(
            $this->export->readings($utility, $format, $lang),
            $this->export->filename('readings', $utility, $format, $lang)
        );
    }

    public function deliveries(Request $req): never
    {
        $utility = $req->param('utility');
        [$format, $lang] = $this->options($req);
        Response::csv(
            $this->export->deliveries($utility, $format, $lang),
            $this->export->filename('deliveries', $utility, $format, $lang)
        );
    }

    public function temperatures(Request $req): never
    {
        [$format, $lang] = $this->options($req);
        Response::csv(
            $this->export->temperatures($format, $lang),
            $this->export->filename('temperatures', null, $format, $lang)
        );
    }
}
