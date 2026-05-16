<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Services\PdfReportService;

/**
 * v1.3.0 — PDF-Jahresbericht.
 * GET /api/reports/yearly.pdf?year=YYYY
 */
final class ReportController
{
    public function __construct(private PdfReportService $reports) {}

    public function yearly(Request $req): never
    {
        $year = $req->queryParam('year');
        $year = $year !== null ? (int)$year : ((int)date('Y') - 1);
        $pdf = $this->reports->build($year);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="energietracker-' . $year . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}
