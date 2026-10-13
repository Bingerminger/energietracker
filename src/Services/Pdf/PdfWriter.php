<?php
declare(strict_types=1);

namespace Energietracker\Services\Pdf;

/**
 * Minimaler, abhängigkeitsfreier PDF-Writer.
 *
 * Erzeugt PDF 1.4 von Hand — nur das, was die Berichte brauchen:
 *   - A4-Seiten (Hoch- oder Querformat)
 *   - Text mit den 14 Standard-Fonts (Helvetica / Helvetica-Bold, Symbole aus
 *     ZapfDingbats); keine Font-Einbettung nötig, daher kein gd/mbstring/dom
 *   - Linien, Rechtecke, Polylinien, Pfade mit Bézier-Kurven, Kreise
 *   - Strichmuster, Grafikzustand (q/Q) und Beschneiden (Schraffur)
 *   - Fußzeile mit „Seite x von y“, Dokumentinfo, Sprache, Lesezeichen
 *
 * Bewusst KEINE externe Library (mPDF/TCPDF/DOMPDF): die App ist
 * durchgängig dependency-frei (flat-file, keine DB, kein composer). Eine
 * PDF-Library würde diese Architektur brechen und wäre in Umgebungen
 * ohne mbstring/gd nicht lauffähig.
 *
 * Koordinatensystem dieser Klasse: Ursprung OBEN LINKS, y wächst nach
 * unten (wie im Web gewohnt). Intern wird in PDF-Koordinaten (Ursprung
 * unten links) umgerechnet. Text-y ist die Grundlinie.
 *
 * Maße in Punkt (1 pt = 1/72 inch). A4 = 595.28 × 841.89 pt.
 *
 * v3.4.0 (F1025) — echte Zeichenbreiten (Metrics), Umbruch zentral hier statt
 * dreimal in den Diensten, Pfade/Kreise/Strichmuster/Beschneiden für die
 * Diagramme, ZapfDingbats für Symbole, Fußzeile, /Info, /Lang, Lesezeichen.
 * Dazu ein Box-Protokoll: Jede gezeichnete Fläche und jede Textzeile wird mit
 * ihrem Rechteck festgehalten, damit Tests prüfen können, dass nichts über
 * den Rand läuft und sich keine Texte überdecken.
 */
final class PdfWriter
{
    public const A4_W = 595.28;
    public const A4_H = 841.89;

    /** Kappa für Kreisbögen aus vier kubischen Bézier-Segmenten. */
    private const KAPPA = 0.5522847498;

    private float $pageW;
    private float $pageH;

    /** @var string[] Content-Stream je abgeschlossener Seite */
    private array $pages = [];
    private string $current = '';
    private bool $open = false;

    /** Seite (1-basiert), auf die gerade gezeichnet wird — beim Fußzeilen-Lauf eine frühere. */
    private int $drawPage = 0;

    /** @var list<array{page:int,kind:string,x0:float,y0:float,x1:float,y1:float,size:?float,text:?string,role:string}> */
    private array $boxes = [];
    private string $role = 'text';

    /** @var list<array{page:int,title:string,level:int,y:float}> */
    private array $bookmarks = [];

    /** @var array<string,string> */
    private array $info = [];
    private string $lang = '';

    /** @var (callable(PdfWriter,int,int):void)|null */
    private $footer = null;

    public function __construct(bool $landscape = false)
    {
        $this->pageW = $landscape ? self::A4_H : self::A4_W;
        $this->pageH = $landscape ? self::A4_W : self::A4_H;
        $this->current = '';
    }

    public function pageWidth(): float  { return $this->pageW; }
    public function pageHeight(): float { return $this->pageH; }

    /** Neue Seite beginnen (die laufende wird abgeschlossen, auch wenn sie leer ist). */
    public function addPage(): void
    {
        if ($this->open) {
            $this->pages[] = $this->current;
        }
        $this->current = '';
        $this->open = true;
        $this->drawPage = count($this->pages) + 1;
    }

    /** Nummer der Seite, auf die gezeichnet wird (1-basiert; 0 vor der ersten Seite). */
    public function currentPage(): int
    {
        return $this->drawPage;
    }

    /** Anzahl der Seiten bis jetzt (die laufende eingeschlossen). */
    public function pageCount(): int
    {
        return count($this->pages) + ($this->open ? 1 : 0);
    }

    // ── Dokument ─────────────────────────────────────────────────────────

    /**
     * Dokumentinfo (/Info). Titel erscheint dank DisplayDocTitle in der
     * Titelleiste des Betrachters statt des Dateinamens.
     *
     * @param array<string,string> $info Title, Subject, Author, Creator, Producer, Keywords
     */
    public function setInfo(array $info): void
    {
        foreach ($info as $k => $v) {
            if (in_array($k, ['Title', 'Subject', 'Author', 'Creator', 'Producer', 'Keywords'], true) && $v !== '') {
                $this->info[$k] = $v;
            }
        }
    }

    /** Sprache des Dokuments (/Lang, BCP 47, z. B. „de“) — Vorlesefunktionen wählen danach die Stimme. */
    public function setLang(string $lang): void
    {
        $this->lang = preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $lang) ? $lang : '';
    }

    /**
     * Fußzeile: wird in output() für jede Seite aufgerufen, wenn die
     * Seitenzahl feststeht — fn(PdfWriter $pdf, int $seite, int $seiten).
     */
    public function setFooter(?callable $fn): void
    {
        $this->footer = $fn;
    }

    /** Lesezeichen auf die laufende Seite (Ebene 0 oder 1), Sprungziel y. */
    public function bookmark(string $title, int $level = 0, float $y = 0.0): void
    {
        if (!$this->open) $this->addPage();
        $this->bookmarks[] = ['page' => $this->drawPage, 'title' => $title, 'level' => $level > 0 ? 1 : 0, 'y' => $y];
    }

    /** @return list<array{page:int,title:string,level:int,y:float}> */
    public function bookmarks(): array
    {
        return $this->bookmarks;
    }

    /**
     * Box-Protokoll: jede Textzeile und jede Fläche mit Seite und Rechteck
     * (oben-links-Koordinaten). kind = text | symbol | shape; role = text |
     * para (Fließtext) | footer.
     *
     * @return list<array{page:int,kind:string,x0:float,y0:float,x1:float,y1:float,size:?float,text:?string,role:string}>
     */
    public function boxes(): array
    {
        return $this->boxes;
    }

    private function record(string $kind, float $x0, float $y0, float $x1, float $y1, ?float $size = null, ?string $text = null): void
    {
        if (!$this->open && $this->drawPage === 0) return;
        $this->boxes[] = [
            'page' => $this->drawPage, 'kind' => $kind,
            'x0' => min($x0, $x1), 'y0' => min($y0, $y1), 'x1' => max($x0, $x1), 'y1' => max($y0, $y1),
            'size' => $size, 'text' => $text, 'role' => $this->role,
        ];
    }

    /**
     * Stand merken, um eine Probezeichnung zurückzunehmen (Höhe eines Blocks
     * messen, bevor sein Kasten gezeichnet wird). Nur innerhalb einer Seite.
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    public function checkpoint(): array
    {
        return [strlen($this->current), count($this->boxes), count($this->bookmarks), $this->drawPage];
    }

    /** Alles seit checkpoint() Gezeichnete verwerfen. */
    public function rollback(array $cp): void
    {
        if ($cp[3] !== $this->drawPage) throw new \LogicException('rollback across pages');
        $this->current = substr($this->current, 0, $cp[0]);
        $this->boxes = array_slice($this->boxes, 0, $cp[1]);
        $this->bookmarks = array_slice($this->bookmarks, 0, $cp[2]);
    }

    /** Y oben→unten in PDF-Y unten→oben. */
    private function ty(float $y): float
    {
        return $this->pageH - $y;
    }

    // ── Text ─────────────────────────────────────────────────────────────

    /**
     * UTF-8 → CP1252 (WinAnsiEncoding der eingebauten Helvetica).
     *
     * v3.1.0 (Review I18N-12) — Zeichen abbilden statt wegwerfen: „CO₂" wurde
     * zu „CO", der Mittelpunkt in „kWh/m²·a" zu „-" (0xB7 gibt es in CP1252),
     * das schmale geschützte Leerzeichen der französischen Typografie
     * verschwand. Sprachen mit anderem Alphabet druckt die Druckansicht im
     * Browser (format.pdfCharset = none).
     */
    public static function toCp1252(string $s): string
    {
        $s = self::normalize($s);
        // iconv ist auf praktisch jeder PHP-Installation vorhanden (auch ohne
        // mbstring); //TRANSLIT bildet Unbekanntes sinnvoll ab, //IGNORE wirft
        // den Rest weg statt zu scheitern.
        $conv = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $conv !== false ? $conv : (preg_replace('/[^\x20-\x7E]/', '?', $s) ?? $s);
    }

    /**
     * Zeichen, die in CP1252 fehlen, vorab abbilden (UTF-8 → UTF-8). Was danach
     * übrig bleibt, muss CP1252 kennen — PdfCharsetTest prüft das für alle
     * Katalogtexte der PDFs.
     *
     * v3.4.0 — Gedankenstriche, typografische Anführungszeichen und „…“ bleiben
     * erhalten: CP1252 kennt sie (0x96, 0x97, 0x84, 0x93, 0x94, 0x85).
     */
    public static function normalize(string $s): string
    {
        return strtr($s, [
            '−' => '-', '×' => 'x',
            '₂' => '2', '₃' => '3', "\u{202F}" => "\u{00A0}", "\u{2009}" => "\u{00A0}",
            'σ' => 'sigma', 'Δ' => 'Delta', '≥' => '>=', '≤' => '<=', '≈' => '~', '→' => '->', '←' => '<-',
        ]);
    }

    private function esc(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::toCp1252($s));
    }

    private static function rgb(?array $c, string $op, string $default = '0 0 0'): string
    {
        if ($c === null) return "$default $op\n";
        return sprintf("%.3f %.3f %.3f %s\n", $c[0] / 255, $c[1] / 255, $c[2] / 255, $op);
    }

    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, ?array $rgb = null): void
    {
        $font = $bold ? '/F2' : '/F1';
        $this->current .= self::rgb($rgb, 'rg')
            . "BT $font " . self::n($size) . ' Tf '
            . sprintf('%.2f %.2f Td', $x, $this->ty($y))
            . ' (' . $this->esc($s) . ') Tj ET' . "\n";
        $w = $this->textWidth($s, $size, $bold);
        if ($w > 0) {
            $this->record('text', $x, $y - Metrics::ASCENT * $size / 1000, $x + $w, $y + Metrics::DESCENT * $size / 1000, $size, $s);
        }
    }

    /** Rechtsbündiger Text (x = rechte Kante). */
    public function textRight(float $xRight, float $y, string $s, float $size = 10, bool $bold = false, ?array $rgb = null): void
    {
        $this->text($xRight - $this->textWidth($s, $size, $bold), $y, $s, $size, $bold, $rgb);
    }

    /** Zentrierter Text (x = Mitte). */
    public function textCenter(float $xCenter, float $y, string $s, float $size = 10, bool $bold = false, ?array $rgb = null): void
    {
        $this->text($xCenter - $this->textWidth($s, $size, $bold) / 2, $y, $s, $size, $bold, $rgb);
    }

    /**
     * Breite in Punkt nach den Schriftmetriken. Gezählt wird nach der
     * Umwandlung in CP1252, nicht nach UTF-8-Bytes: „Énergie“ hat 7 Zeichen.
     */
    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        return Metrics::width(self::toCp1252($s), $size, $bold);
    }

    /**
     * Zeilenumbruch nach Textbreite — der eine Ort dafür (v3.4.0; vorher je eine
     * Kopie in PdfReportService, Co2SplitService und EvChargingReportService,
     * die erste zählte UTF-8-Bytes). Ein Wort, das allein zu breit ist, wird
     * hart getrennt, statt über den Rand zu laufen. "\n" bricht immer um.
     *
     * @return list<string>
     */
    public function wrap(string $s, float $size, float $width, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $s) ?: [] as $para) {
            $line = '';
            foreach (preg_split('/[ \t]+/u', trim($para)) ?: [] as $word) {
                if ($word === '') continue;
                $try = $line === '' ? $word : "$line $word";
                if ($this->textWidth($try, $size, $bold) <= $width) { $line = $try; continue; }
                if ($line !== '') $lines[] = $line;
                // allein zu breit: zeichenweise trennen (ohne mbstring)
                $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [$word];
                while (count($chars) > 1 && $this->textWidth(implode('', $chars), $size, $bold) > $width) {
                    $cut = count($chars) - 1;
                    while ($cut > 1 && $this->textWidth(implode('', array_slice($chars, 0, $cut)), $size, $bold) > $width) $cut--;
                    $lines[] = implode('', array_slice($chars, 0, $cut));
                    $chars = array_slice($chars, $cut);
                }
                $line = implode('', $chars);
            }
            if ($line !== '') $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Absatz: umbrechen und zeilenweise setzen. Liefert die Grundlinie der
     * nächsten Zeile (y + Zeilen × Zeilenabstand).
     */
    public function paragraph(float $x, float $y, string $s, float $size, float $width, bool $bold = false, ?array $rgb = null, ?float $leading = null): float
    {
        $leading ??= round($size * 1.35, 2);
        $prev = $this->role;
        $this->role = $this->role === 'text' ? 'para' : $this->role;
        foreach ($this->wrap($s, $size, $width, $bold) as $line) {
            $this->text($x, $y, $line, $size, $bold, $rgb);
            $y += $leading;
        }
        $this->role = $prev;
        return $y;
    }

    /** Symbol aus ZapfDingbats (Name aus Metrics::SYMBOLS), Grundlinie y. */
    public function symbol(float $x, float $y, string $name, float $size = 10, ?array $rgb = null): void
    {
        $sym = Metrics::SYMBOLS[$name] ?? null;
        if ($sym === null) return;
        $chr = chr($sym[0]);
        $lit = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $chr);
        $this->current .= self::rgb($rgb, 'rg')
            . 'BT /F3 ' . self::n($size) . ' Tf ' . sprintf('%.2f %.2f Td', $x, $this->ty($y)) . " ($lit) Tj ET\n";
        $this->record('symbol', $x, $y - 0.705 * $size, $x + Metrics::symbolWidth($name, $size), $y + 0.014 * $size, $size, $name);
    }

    // ── Grafik ───────────────────────────────────────────────────────────

    private static function n(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.2f', $v), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    /** Strichstärke, Strichmuster, runde Enden — als Präfix vor einem Strich. */
    private static function strokeState(float $lw, ?array $dash): string
    {
        $d = $dash !== null && $dash !== []
            ? '[' . implode(' ', array_map(fn($v) => self::n((float)$v), $dash)) . '] 0 d'
            : '[] 0 d';
        return self::n($lw) . " w $d\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.5, ?array $rgb = null, ?array $dash = null): void
    {
        $this->current .= self::rgb($rgb, 'RG', '0.7 0.7 0.7') . self::strokeState($w, $dash)
            . sprintf("%.2f %.2f m %.2f %.2f l S\n", $x1, $this->ty($y1), $x2, $this->ty($y2));
        $this->record('shape', $x1 - $w / 2, $y1 - $w / 2, $x2 + $w / 2, $y2 + $w / 2);
    }

    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lw = 0.5): void
    {
        $py = $this->ty($y + $h);
        if ($fill !== null) {
            $this->current .= self::rgb($fill, 'rg') . sprintf("%.2f %.2f %.2f %.2f re f\n", $x, $py, $w, $h);
        }
        if ($stroke !== null) {
            $this->current .= self::rgb($stroke, 'RG') . self::strokeState($lw, null)
                . sprintf("%.2f %.2f %.2f %.2f re S\n", $x, $py, $w, $h);
        }
        $this->record('shape', $x, $y, $x + $w, $y + $h);
    }

    /** @param array<int,array{0:float,1:float}> $points (x,y in oben-links-Koordinaten) */
    public function polyline(array $points, float $w = 1.0, ?array $rgb = null, ?array $dash = null): void
    {
        if (count($points) < 2) return;
        $segs = [['M', $points[0][0], $points[0][1]]];
        for ($i = 1; $i < count($points); $i++) $segs[] = ['L', $points[$i][0], $points[$i][1]];
        $this->path($segs, null, $rgb ?? [0, 0, 0], $w, false, $dash);
    }

    /**
     * Pfad aus Segmenten: ['M', x, y], ['L', x, y], ['C', x1, y1, x2, y2, x, y], ['Z'].
     * Füllung und/oder Kontur; even-odd für Ringe (f* / B*).
     *
     * @param list<array> $segs
     */
    public function path(array $segs, ?array $fill = null, ?array $stroke = null, float $lw = 0.5, bool $evenOdd = false, ?array $dash = null): void
    {
        $ops = $this->pathOps($segs, $bbox);
        if ($ops === '') return;
        $pre = '';
        if ($fill !== null) $pre .= self::rgb($fill, 'rg');
        if ($stroke !== null) $pre .= self::rgb($stroke, 'RG') . self::strokeState($lw, $dash);
        $op = match (true) {
            $fill !== null && $stroke !== null => $evenOdd ? 'B*' : 'B',
            $fill !== null                     => $evenOdd ? 'f*' : 'f',
            default                            => 'S',
        };
        $this->current .= $pre . $ops . $op . "\n";
        $pad = $stroke !== null ? $lw / 2 : 0.0;
        $this->record('shape', $bbox[0] - $pad, $bbox[1] - $pad, $bbox[2] + $pad, $bbox[3] + $pad);
    }

    /** @param list<array> $segs */
    private function pathOps(array $segs, ?array &$bbox): string
    {
        $out = '';
        $bbox = [INF, INF, -INF, -INF];
        $grow = function (float $x, float $y) use (&$bbox): void {
            $bbox = [min($bbox[0], $x), min($bbox[1], $y), max($bbox[2], $x), max($bbox[3], $y)];
        };
        foreach ($segs as $s) {
            switch ($s[0]) {
                case 'M':
                case 'L':
                    $out .= sprintf("%.2f %.2f %s\n", $s[1], $this->ty($s[2]), strtolower($s[0]));
                    $grow((float)$s[1], (float)$s[2]);
                    break;
                case 'C':
                    $out .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n",
                        $s[1], $this->ty($s[2]), $s[3], $this->ty($s[4]), $s[5], $this->ty($s[6]));
                    // Kontrollpunkte liegen auf der sicheren Seite der Hülle
                    $grow((float)$s[1], (float)$s[2]); $grow((float)$s[3], (float)$s[4]); $grow((float)$s[5], (float)$s[6]);
                    break;
                case 'Z':
                    $out .= "h\n";
                    break;
            }
        }
        return $out;
    }

    /** Segmente eines Kreises (vier Bézier-Viertel, je ≤ 90°). @return list<array> */
    public static function circleSegments(float $cx, float $cy, float $r): array
    {
        $k = self::KAPPA * $r;
        return [
            ['M', $cx + $r, $cy],
            ['C', $cx + $r, $cy + $k, $cx + $k, $cy + $r, $cx, $cy + $r],
            ['C', $cx - $k, $cy + $r, $cx - $r, $cy + $k, $cx - $r, $cy],
            ['C', $cx - $r, $cy - $k, $cx - $k, $cy - $r, $cx, $cy - $r],
            ['C', $cx + $k, $cy - $r, $cx + $r, $cy - $k, $cx + $r, $cy],
            ['Z'],
        ];
    }

    public function circle(float $cx, float $cy, float $r, ?array $fill = null, ?array $stroke = null, float $lw = 0.5): void
    {
        $this->path(self::circleSegments($cx, $cy, $r), $fill, $stroke, $lw);
    }

    /** Rechteck mit runden Ecken. */
    public function roundRect(float $x, float $y, float $w, float $h, float $r, ?array $fill = null, ?array $stroke = null, float $lw = 0.5): void
    {
        $r = max(0.0, min($r, $w / 2, $h / 2));
        $k = self::KAPPA * $r;
        $this->path([
            ['M', $x + $r, $y],
            ['L', $x + $w - $r, $y],
            ['C', $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r],
            ['L', $x + $w, $y + $h - $r],
            ['C', $x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h],
            ['L', $x + $r, $y + $h],
            ['C', $x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r],
            ['L', $x, $y + $r],
            ['C', $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y],
            ['Z'],
        ], $fill, $stroke, $lw);
    }

    /** Geschlossenes Vieleck (Pfeilspitzen, Zeiger). @param list<array{0:float,1:float}> $pts */
    public function polygon(array $pts, ?array $fill = null, ?array $stroke = null, float $lw = 0.5): void
    {
        if (count($pts) < 3) return;
        $segs = [['M', $pts[0][0], $pts[0][1]]];
        foreach (array_slice($pts, 1) as $p) $segs[] = ['L', $p[0], $p[1]];
        $segs[] = ['Z'];
        $this->path($segs, $fill, $stroke, $lw);
    }

    /** Grafikzustand sichern (q) — Gegenstück restore(). */
    public function save(): void
    {
        $this->current .= "q\n";
    }

    /** Grafikzustand zurück (Q): hebt Beschneiden und Strichmuster wieder auf. */
    public function restore(): void
    {
        $this->current .= "Q\n";
    }

    /** Beschneiden auf ein Rechteck (nur zwischen save() und restore()). */
    public function clipRect(float $x, float $y, float $w, float $h): void
    {
        $this->current .= sprintf("%.2f %.2f %.2f %.2f re W n\n", $x, $this->ty($y + $h), $w, $h);
    }

    /**
     * Schraffur (Schätzung): diagonale Linien, auf das Rechteck beschnitten —
     * bleibt im Schwarz-Weiß-Druck erkennbar, anders als ein hellerer Farbton.
     */
    public function hatch(float $x, float $y, float $w, float $h, ?array $rgb = null, float $spacing = 3.5, float $lw = 0.6): void
    {
        if ($w <= 0 || $h <= 0) return;
        $this->save();
        $this->clipRect($x, $y, $w, $h);
        $ops = self::rgb($rgb ?? [255, 255, 255], 'RG') . self::strokeState($lw, null);
        for ($d = -$h; $d < $w; $d += $spacing) {
            $ops .= sprintf("%.2f %.2f m %.2f %.2f l\n", $x + $d, $this->ty($y + $h), $x + $d + $h, $this->ty($y));
        }
        $this->current .= $ops . "S\n";
        $this->restore();
    }

    // ── Ausgabe ──────────────────────────────────────────────────────────

    /** PDF-Textstring (UTF-16BE mit BOM) für /Info und Lesezeichen. */
    private static function pdfString(string $s): string
    {
        $u = @iconv('UTF-8', 'UTF-16BE//IGNORE', $s);
        return '<FEFF' . strtoupper(bin2hex($u === false ? '' : $u)) . '>';
    }

    public function output(): string
    {
        if ($this->open || $this->current !== '') {
            $this->pages[] = $this->current;
            $this->open = false;
        }
        if ($this->pages === []) $this->pages[] = '';
        $nPages = count($this->pages);

        // Fußzeile erst jetzt: Die Seitenzahl steht fest
        if ($this->footer !== null) {
            $prevRole = $this->role;
            $this->role = 'footer';
            foreach ($this->pages as $i => $content) {
                $this->current = '';
                $this->drawPage = $i + 1;
                ($this->footer)($this, $i + 1, $nPages);
                $this->pages[$i] = $content . $this->current;
            }
            $this->current = '';
            $this->role = $prevRole;
        }

        // Objektnummern: 1 Catalog, 2 Pages, 3 Info, 4–6 Schriften, dann je
        // Seite Page + Content, zuletzt die Lesezeichen
        $objs = [];
        $fontReg = 4; $fontBold = 5; $fontSym = 6;
        $pageObj = [];
        $num = 7;
        for ($i = 0; $i < $nPages; $i++) { $pageObj[$i + 1] = $num; $num += 2; }

        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($n) => "$n 0 R", $pageObj)) . "] /Count $nPages >>";
        $info = $this->info + ['Producer' => 'Energietracker'];
        $tz = date('O');
        $info['CreationDate'] = 'D:' . date('YmdHis') . substr($tz, 0, 3) . "'" . substr($tz, 3, 2) . "'";
        $objs[3] = '<< ' . implode(' ', array_map(
            fn($k, $v) => "/$k " . ($k === 'CreationDate' ? "($v)" : self::pdfString($v)),
            array_keys($info), $info
        )) . ' >>';
        $objs[$fontReg]  = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[$fontBold] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[$fontSym]  = '<< /Type /Font /Subtype /Type1 /BaseFont /ZapfDingbats >>';

        foreach ($this->pages as $i => $stream) {
            $on = $pageObj[$i + 1];
            $objs[$on] = '<< /Type /Page /Parent 2 0 R '
                . '/MediaBox [0 0 ' . sprintf('%.2f %.2f', $this->pageW, $this->pageH) . '] '
                . "/Resources << /Font << /F1 $fontReg 0 R /F2 $fontBold 0 R /F3 $fontSym 0 R >> >> "
                . '/Contents ' . ($on + 1) . ' 0 R >>';
            $objs[$on + 1] = '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream";
        }

        // Lesezeichen: Ebene 0 mit Kindern der Ebene 1
        $outlineRoot = null;
        if ($this->bookmarks !== []) {
            $outlineRoot = $num++;
            $items = [];
            foreach ($this->bookmarks as $b) {
                $b['obj'] = $num++;
                $b['kids'] = [];
                if ($b['level'] === 1 && $items !== []) {
                    $items[count($items) - 1]['kids'][] = $b;
                } else {
                    $items[] = $b;
                }
            }
            $dest = fn(array $b) => '[' . $pageObj[$b['page']] . ' 0 R /XYZ 0 ' . sprintf('%.2f', $this->ty($b['y'])) . ' 0]';
            $link = function (array $list, int $parent) use (&$objs, $dest, &$link): int {
                $count = 0;
                foreach ($list as $k => $b) {
                    $body = '<< /Title ' . self::pdfString($b['title']) . " /Parent $parent 0 R /Dest " . $dest($b);
                    if ($k > 0) $body .= ' /Prev ' . $list[$k - 1]['obj'] . ' 0 R';
                    if ($k < count($list) - 1) $body .= ' /Next ' . $list[$k + 1]['obj'] . ' 0 R';
                    if ($b['kids'] !== []) {
                        $kidCount = $link($b['kids'], $b['obj']);
                        $body .= ' /First ' . $b['kids'][0]['obj'] . ' 0 R /Last ' . end($b['kids'])['obj'] . " 0 R /Count -$kidCount";
                    }
                    $objs[$b['obj']] = $body . ' >>';
                    $count++;
                }
                return $count;
            };
            $top = $link($items, $outlineRoot);
            $objs[$outlineRoot] = '<< /Type /Outlines /First ' . $items[0]['obj'] . ' 0 R /Last ' . end($items)['obj'] . " 0 R /Count $top >>";
        }

        $catalog = '<< /Type /Catalog /Pages 2 0 R /ViewerPreferences << /DisplayDocTitle true >>';
        if ($this->lang !== '') $catalog .= ' /Lang (' . $this->lang . ')';
        if ($outlineRoot !== null) $catalog .= " /Outlines $outlineRoot 0 R /PageMode /UseOutlines";
        $objs[1] = $catalog . ' >>';

        // Datei zusammensetzen + xref
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objs);
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "$n 0 obj\n$body\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $count = max(array_keys($objs)) + 1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size $count /Root 1 0 R /Info 3 0 R >>\nstartxref\n$xrefPos\n%%EOF";
        return $pdf;
    }
}
