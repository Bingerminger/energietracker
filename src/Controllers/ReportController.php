<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Config\Utilities;
use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\I18nService;
use Energietracker\Services\PdfReportService;

/**
 * v1.3.0 — PDF-Jahresbericht.
 *   GET /api/reports/yearly.pdf?year=JJJJ
 * v3.1.0 (Review I18N-12) — dieselben Inhalte als Daten für die Druckansicht:
 *   GET /api/reports/yearly?year=JJJJ
 * v3.4.0 (F1025) — für beide, additiv:
 *   lang=de|en|…        Sprache je Empfänger (wie beim Manifest); ein Download
 *                       aus der Oberfläche schickt keinen Sprach-Header
 *   utilities=gas,wasser  Teilbericht, z. B. für den Hausmeister
 *   detail=1            Anhang „Zahlen im Detail“ (Monatstabellen)
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

    /**
     * Sprache, Teilbericht und Anhang aus der Anfrage. Eine unbekannte Sprache
     * fällt still auf die Sprache der Anfrage zurück (wie /api/manifest); eine
     * unbekannte Verbrauchsart ist ein Fehler — ein stiller Teilbericht ohne
     * sie sähe aus wie ein vollständiger.
     *
     * @return array{utilities:?list<string>,detail:bool}
     */
    private function options(Request $req): array
    {
        if ($this->i18n !== null) {
            $lang = $this->i18n->normalize($req->queryParam('lang'));
            if ($lang !== null) $this->i18n->setLocale($lang);
        }
        $utilities = null;
        $raw = $req->queryParam('utilities');
        if ($raw !== null && trim($raw) !== '') {
            $utilities = [];
            foreach (explode(',', $raw) as $u) {
                $u = trim($u);
                if ($u === '') continue;
                if (!Utilities::exists($u)) {
                    Response::error($this->i18n?->t('errors.common.unknownUtility', ['utility' => $u]) ?? "unknown utility: $u",
                        400, null, 'errors.common.unknownUtility');
                }
                $utilities[] = $u;
            }
            $utilities = array_values(array_unique($utilities));
        }
        return ['utilities' => $utilities, 'detail' => $req->queryParam('detail') === '1'];
    }

    public function yearlyData(Request $req): never
    {
        $year = $this->year($req);
        Response::json($this->reports->yearlyData($year, $this->options($req)));
    }

    public function yearly(Request $req): never
    {
        $year = $this->year($req);
        $opts = $this->options($req);
        // v3.1.0 — Die PDF-Schriften kennen nur CP1252. Eine Sprache mit anderem
        // Alphabet (format.pdfCharset = none) bekommt den Weg zur Druckansicht.
        if ($this->i18n !== null && !$this->i18n->pdfSupported()) {
            Response::error($this->i18n->t('errors.report.pdfUnsupportedLanguage'), 422, null, 'errors.report.pdfUnsupportedLanguage');
        }
        $pdf = $this->reports->build($year, $opts);

        header('Content-Type: application/pdf');
        // v2.11.0 — `inline=1` zeigt das PDF im Browser (Home-Bildschirm-App auf
        // dem iPhone: Downloads kamen dort oft nicht an). Ohne bleibt es ein
        // Download wie bisher.
        $disposition = $req->queryParam('inline') === '1' ? 'inline' : 'attachment';
        $suffix = $opts['utilities'] !== null ? '-' . implode('-', $opts['utilities']) : '';
        header('Content-Disposition: ' . $disposition . '; filename="energietracker-' . $year . $suffix . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}
