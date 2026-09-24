<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\I18nService;
use Energietracker\Services\PdfReportService;

/**
 * v1.3.0 — PDF-Jahresbericht.
 * GET /api/reports/yearly.pdf?year=YYYY
 */
final class ReportController
{
    public function __construct(private PdfReportService $reports, private ?I18nService $i18n = null) {}

    public function yearly(Request $req): never
    {
        $raw = $req->queryParam('year');
        // v2.6.0 — `year=abc` ergab bisher ein PDF für das Jahr 0.
        if ($raw !== null && (!ctype_digit($raw) || (int)$raw < 2000 || (int)$raw > 2100)) {
            Response::error($this->i18n?->t('errors.report.yearRange') ?? 'year must be 2000–2100');
        }
        $year = $raw !== null ? (int)$raw : ((int)date('Y') - 1);
        $pdf = $this->reports->build($year);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="energietracker-' . $year . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}
