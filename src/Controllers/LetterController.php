<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\LetterService;

/**
 * v3.11.0 (F1036, Paket briefe) — Brief zu einem Befund „Deine Rechte als Kunde“.
 *   POST /api/letters/render
 *     {finding_id, kind?, format: text|pdf, sender, recipient, place, refs{}, extra{}}
 *     text → {kind, subject, lines[], text, gaps[], form, norms[], defaults, finding{…}}
 *     pdf  → application/pdf (DIN 5008 Form B, Fensterumschlag)
 *
 * Schreibt nichts (auch mit Lese-Schlüssel erlaubt, sperrt nicht): Absender und Empfänger
 * bleiben im Dialog (Entscheidung 10a). Der Brief ist immer Deutsch; die Angaben zum Befund
 * (`finding`) kommen in der Sprache der Anfrage, für den Hinweis im Dialog.
 */
final class LetterController
{
    public function __construct(private LetterService $letters) {}

    public function render(Request $req): never
    {
        $r = $this->letters->render(is_array($req->body) ? $req->body : []);
        $m = $r['model'];
        if ($r['format'] === 'pdf') {
            $pdf = $this->letters->pdf($m);
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/pdf');
            // v3.11.0 (Skeptiker N12) — deutscher Name der Vorlage statt des internen Schlüssels
            header('Content-Disposition: attachment; filename="' . LetterService::fileName((string)$m['kind'], date('Y-m-d')) . '"');
            header('Cache-Control: no-store');   // trägt Name und Anschrift
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        }
        header('Cache-Control: no-store');
        Response::json($this->letters->publicModel($m));
    }
}
