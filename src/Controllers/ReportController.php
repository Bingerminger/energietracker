<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\I18nService;
use Energietracker\Services\PdfReportService;

/**
 * v1.3.0 — PDF-Jahresbericht.
 *   GET /api/reports/yearly.pdf?year=JJJJ
 * v3.1.0 (Review I18N-12) — dieselben Inhalte als Daten für die Druckansicht:
 *   GET /api/reports/yearly?year=JJJJ
 */
final class ReportController
{
    public function __construct(private PdfReportService $reports, private ?I18nService $i18n = null) {}

    /** Jahr aus der Anfrage; ohne Angabe das Vorjahr. */
    private function year(Request $req): int
    {
        $raw = $req->queryParam('year');
        // v2.6.0 — `year=abc` ergab bisher ein PDF für das Jahr 0.
        if ($raw !== null && (!ctype_digit($raw) || (int)$raw < 2000 || (int)$raw > 2100)) {
            Response::error($this->i18n?->t('errors.report.yearRange') ?? 'year must be 2000–2100', 400, null, 'errors.report.yearRange');
        }
        return $raw !== null ? (int)$raw : ((int)date('Y') - 1);
    }

    public function yearlyData(Request $req): never
    {
        Response::json($this->reports->yearlyData($this->year($req)));
    }

    public function yearly(Request $req): never
    {
        $year = $this->year($req);
        // v3.1.0 — Die PDF-Schriften kennen nur CP1252. Eine Sprache mit anderem
        // Alphabet (format.pdfCharset = none) bekommt den Weg zur Druckansicht.
        if ($this->i18n !== null && !$this->i18n->pdfSupported()) {
            Response::error($this->i18n->t('errors.report.pdfUnsupportedLanguage'), 422, null, 'errors.report.pdfUnsupportedLanguage');
        }
        $pdf = $this->reports->build($year);

        header('Content-Type: application/pdf');
        // v2.11.0 — `inline=1` zeigt das PDF im Browser (Home-Bildschirm-App auf
        // dem iPhone: Downloads kamen dort oft nicht an). Ohne bleibt es ein
        // Download wie bisher.
        $disposition = $req->queryParam('inline') === '1' ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disposition . '; filename="energietracker-' . $year . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}
