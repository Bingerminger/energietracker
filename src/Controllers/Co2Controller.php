<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\Co2CostService;
use Energietracker\Services\Co2SplitService;
use Energietracker\Services\I18nService;

/**
 * v3.1.0 (Paket H4) — CO₂-Preis im Brennstoff (`GET /api/co2-costs`), Aufteilung
 * zwischen Mieter und Vermieter (`GET /api/co2-split`) und das Anschreiben als
 * PDF (`GET /api/reports/co2-split.pdf`). `?year=` (Standard: Vorjahr).
 */
final class Co2Controller
{
    public function __construct(
        private Co2CostService $costs,
        private Co2SplitService $split,
        private I18nService $i18n,
    ) {}

    public function costs(Request $req): never
    {
        Response::json($this->costs->forYear($this->year($req)));
    }

    public function split(Request $req): never
    {
        Response::json($this->split->forYear($this->year($req)));
    }

    public function letter(Request $req): never
    {
        if (!$this->i18n->pdfSupported()) {
            Response::error($this->i18n->t('errors.report.pdfUnsupportedLanguage'), 422, null, 'errors.report.pdfUnsupportedLanguage');
        }
        $year = $this->year($req);
        $pdf = $this->split->letterPdf($year);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($req->queryParam('inline') === '1' ? 'inline' : 'attachment')
            . '; filename="energietracker-co2-' . $year . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    private function year(Request $req): int
    {
        $y = $req->queryParam('year');
        if ($y === null || $y === '') return (int)date('Y') - 1;
        if (!ctype_digit($y) || (int)$y < 2021 || (int)$y > 2100) {
            Response::error($this->i18n->t('errors.co2.yearInvalid', ['year' => $y]), 400, null, 'errors.co2.yearInvalid');
        }
        return (int)$y;
    }
}
