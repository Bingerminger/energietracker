<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\CsvExportService;
use Energietracker\Services\EvChargingReportService;
use Energietracker\Services\I18nService;

/**
 * v3.1.0 (Paket H6, MKT-14) — Ladestrom-Nachweis als JSON, CSV und PDF.
 * Parameter: meter_id, year (Standard: Vorjahr), method contract|flat,
 * flat_ct (eigene Pauschale), bei CSV format 1|local und lang.
 */
final class EvChargingController
{
    public function __construct(
        private EvChargingReportService $reports,
        private CsvExportService $csv,
        private I18nService $i18n,
    ) {}

    public function json(Request $req): never
    {
        Response::json($this->report($req));
    }

    public function csv(Request $req): never
    {
        $format = (string)($req->queryParam('format') ?? '1');
        CsvExportService::assertFormat($format);
        $r = $this->report($req);
        Response::csv($this->csv->evCharging($r, $format, $req->queryParam('lang')),
            'energietracker-ladestrom-' . $r['year'] . '.csv');
    }

    public function pdf(Request $req): never
    {
        if (!$this->i18n->pdfSupported()) {
            Response::error($this->i18n->t('errors.report.pdfUnsupportedLanguage'), 422, null, 'errors.report.pdfUnsupportedLanguage');
        }
        $r = $this->report($req);
        $pdf = $this->reports->pdf($r);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($req->queryParam('inline') === '1' ? 'inline' : 'attachment')
            . '; filename="energietracker-ladestrom-' . $r['year'] . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    /** @return array<string,mixed> */
    private function report(Request $req): array
    {
        $y = (string)($req->queryParam('year') ?? '');
        $year = $y === '' ? (int)date('Y') - 1 : (ctype_digit($y) && (int)$y >= 2017 && (int)$y <= 2100 ? (int)$y
            : Response::error($this->i18n->t('errors.evReport.yearInvalid', ['year' => $y]), 400, null, 'errors.evReport.yearInvalid'));
        $flat = $req->queryParam('flat_ct');
        if ($flat !== null && $flat !== '' && (!is_numeric($flat) || (float)$flat < 0 || (float)$flat > 200)) {
            Response::error($this->i18n->t('errors.evReport.flatInvalid', ['value' => (string)$flat]), 400, null, 'errors.evReport.flatInvalid');
        }
        return $this->reports->report((string)($req->queryParam('meter_id') ?? ''), $year,
            (string)($req->queryParam('method') ?? 'contract'), $flat !== null && $flat !== '' ? (float)$flat : null);
    }
}
