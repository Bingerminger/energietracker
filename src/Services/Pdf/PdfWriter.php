<?php
declare(strict_types=1);

namespace Energietracker\Services\Pdf;

/**
 * Minimaler, abhängigkeitsfreier PDF-Writer.
 *
 * Erzeugt PDF 1.4 von Hand — nur das, was der Jahresbericht braucht:
 *   - A4-Seiten (Hoch- oder Querformat)
 *   - Text mit den 14 Standard-Fonts (Helvetica / Helvetica-Bold);
 *     keine Font-Einbettung nötig, daher kein gd/mbstring/dom erforderlich
 *   - Linien, Rechtecke (gefüllt / Kontur)
 *   - Polylinien (für einfache Liniencharts)
 *
 * Bewusst KEINE externe Library (mPDF/TCPDF/DOMPDF): die App ist
 * durchgängig dependency-frei (flat-file, keine DB, kein composer). Eine
 * PDF-Library würde diese Architektur brechen und wäre in Umgebungen
 * ohne mbstring/gd nicht lauffähig.
 *
 * Koordinatensystem dieser Klasse: Ursprung OBEN LINKS, y wächst nach
 * unten (wie im Web gewohnt). Intern wird in PDF-Koordinaten (Ursprung
 * unten links) umgerechnet.
 *
 * Maße in Punkt (1 pt = 1/72 inch). A4 = 595.28 × 841.89 pt.
 */
final class PdfWriter
{
    public const A4_W = 595.28;
    public const A4_H = 841.89;

    private float $pageW;
    private float $pageH;

    /** @var string[] Content-Stream je Seite */
    private array $pages = [];
    private string $current = '';

    public function __construct(bool $landscape = false)
    {
        $this->pageW = $landscape ? self::A4_H : self::A4_W;
        $this->pageH = $landscape ? self::A4_W : self::A4_H;
        $this->current = '';
    }

    public function pageWidth(): float  { return $this->pageW; }
    public function pageHeight(): float { return $this->pageH; }

    public function addPage(): void
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }
        $this->current = '';
    }

    /** Y oben→unten in PDF-Y unten→oben. */
    private function ty(float $y): float
    {
        return $this->pageH - $y;
    }

    private function esc(string $s): string
    {
        // Häufige Sonderzeichen, die in CP1252 nicht 1:1 existieren oder
        // typografisch ersetzt werden sollen, vorab normalisieren.
        $pre = [
            '–'=>'-', '—'=>'-', '×'=>'x', '…'=>'...',
            '„'=>'"', '"'=>'"', '"'=>'"', '’'=>"'", '‚'=>"'", '·'=>'-',
            'σ'=>'sigma', 'Δ'=>'Delta', '≥'=>'>=', '≤'=>'<=', '→'=>'->',
        ];
        $s = strtr($s, $pre);

        // UTF-8 → CP1252 (WinAnsiEncoding des PDF-Fonts). iconv ist auf
        // praktisch jeder PHP-Installation vorhanden (auch ohne mbstring);
        // //TRANSLIT bildet Unbekanntes sinnvoll ab, //IGNORE wirft den
        // Rest weg statt zu scheitern.
        $conv = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        if ($conv !== false) {
            $s = $conv;
        } else {
            // Fallback ohne iconv: nur ASCII durchlassen
            $s = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? $s;
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s);
    }

    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, ?array $rgb = null): void
    {
        $font = $bold ? '/F2' : '/F1';
        $col = $rgb ? sprintf("%.3f %.3f %.3f rg\n", $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255) : "0 0 0 rg\n";
        $this->current .= $col
            . "BT $font $size Tf "
            . sprintf('%.2f %.2f Td', $x, $this->ty($y))
            . ' (' . $this->esc($s) . ') Tj ET' . "\n";
    }

    /** Rechtsbündiger Text (x = rechte Kante). */
    public function textRight(float $xRight, float $y, string $s, float $size = 10, bool $bold = false, ?array $rgb = null): void
    {
        $w = $this->textWidth($s, $size, $bold);
        $this->text($xRight - $w, $y, $s, $size, $bold, $rgb);
    }

    /** Helvetica-Breitenschätzung (avg. 0.52 em — ausreichend für Layout). */
    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        return strlen($s) * $size * ($bold ? 0.56 : 0.52);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.5, ?array $rgb = null): void
    {
        $c = $rgb ? sprintf("%.3f %.3f %.3f RG\n", $rgb[0]/255, $rgb[1]/255, $rgb[2]/255) : "0.7 0.7 0.7 RG\n";
        $this->current .= $c . sprintf("%.2f w\n%.2f %.2f m %.2f %.2f l S\n",
            $w, $x1, $this->ty($y1), $x2, $this->ty($y2));
    }

    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lw = 0.5): void
    {
        $py = $this->ty($y + $h);
        if ($fill !== null) {
            $this->current .= sprintf("%.3f %.3f %.3f rg\n%.2f %.2f %.2f %.2f re f\n",
                $fill[0]/255, $fill[1]/255, $fill[2]/255, $x, $py, $w, $h);
        }
        if ($stroke !== null) {
            $this->current .= sprintf("%.3f %.3f %.3f RG\n%.2f w\n%.2f %.2f %.2f %.2f re S\n",
                $stroke[0]/255, $stroke[1]/255, $stroke[2]/255, $lw, $x, $py, $w, $h);
        }
    }

    /** @param array<int,array{0:float,1:float}> $points (x,y in oben-links-Koordinaten) */
    public function polyline(array $points, float $w = 1.0, ?array $rgb = null): void
    {
        if (count($points) < 2) return;
        $c = $rgb ? sprintf("%.3f %.3f %.3f RG\n", $rgb[0]/255, $rgb[1]/255, $rgb[2]/255) : "0 0 0 RG\n";
        $this->current .= $c . sprintf("%.2f w\n", $w);
        [$x0, $y0] = $points[0];
        $this->current .= sprintf("%.2f %.2f m\n", $x0, $this->ty($y0));
        for ($i = 1; $i < count($points); $i++) {
            [$xi, $yi] = $points[$i];
            $this->current .= sprintf("%.2f %.2f l\n", $xi, $this->ty($yi));
        }
        $this->current .= "S\n";
    }

    public function output(): string
    {
        $this->addPage(); // letzte Seite flushen

        $objs = [];
        // 1: Catalog, 2: Pages, ab 3: je Seite (Page + Content), Fonts am Ende
        $nPages = count($this->pages);
        $kids = [];
        $objNum = 3;
        $pageObjs = [];
        for ($i = 0; $i < $nPages; $i++) {
            $pageObjs[] = $objNum;
            $kids[] = ($objNum) . ' 0 R';
            $objNum += 2; // Page + Content
        }
        $fontReg = $objNum;
        $fontBold = $objNum + 1;

        // Objekt 1 — Catalog
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        // Objekt 2 — Pages
        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $nPages >>";

        $on = 3;
        foreach ($this->pages as $content) {
            $stream = $content;
            $clen = strlen($stream);
            $objs[$on] = "<< /Type /Page /Parent 2 0 R "
                . "/MediaBox [0 0 " . sprintf('%.2f %.2f', $this->pageW, $this->pageH) . "] "
                . "/Resources << /Font << /F1 $fontReg 0 R /F2 $fontBold 0 R >> >> "
                . "/Contents " . ($on + 1) . " 0 R >>";
            $objs[$on + 1] = "<< /Length $clen >>\nstream\n$stream\nendstream";
            $on += 2;
        }
        $objs[$fontReg]  = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[$fontBold] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        // Datei zusammensetzen + xref
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objs);
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "$num 0 obj\n$body\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $count = max(array_keys($objs)) + 1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";
        return $pdf;
    }
}
