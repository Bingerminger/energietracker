<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

/**
 * v3.4.0 (F1025) — Prüfwerkzeug für die selbst geschriebenen PDFs.
 *
 * Ein PDF lässt sich schlecht auf Aussehen prüfen, wohl aber auf das, was
 * schiefgehen kann und niemandem auffällt: kaputte Querverweise (ein strenger
 * Betrachter verweigert die Datei), eine falsche Seitenzahl im Seitenbaum,
 * Streams mit falscher Länge — und, über das Box-Protokoll des Writers, Text
 * über dem Rand oder Texte übereinander (Mängel 1 und 9 des Berichts v3.3).
 */
final class PdfInspector
{
    /** @return list<string> Befunde zur Dateistruktur (leer = in Ordnung) */
    public static function structureIssues(string $pdf): array
    {
        $issues = [];
        if (!str_starts_with($pdf, '%PDF-1.')) $issues[] = 'Kopf fehlt';
        if (!preg_match('/startxref\n(\d+)\n%%EOF$/', $pdf, $m)) return array_merge($issues, ['startxref fehlt']);
        $xref = (int)$m[1];
        if (substr($pdf, $xref, 4) !== 'xref') $issues[] = 'startxref zeigt nicht auf xref';
        if (!preg_match('/xref\n0 (\d+)\n/', substr($pdf, $xref), $h)) return array_merge($issues, ['xref-Kopf fehlt']);
        $n = (int)$h[1];
        $body = substr($pdf, $xref + strlen($h[0]), $n * 20);
        for ($i = 1; $i < $n; $i++) {
            $off = (int)substr($body, $i * 20, 10);
            if (!str_starts_with(substr($pdf, $off, 20), "$i 0 obj")) $issues[] = "xref $i zeigt auf Offset $off ohne „$i 0 obj“";
        }
        // Seitenbaum: /Count = Zahl der /Type /Page-Objekte
        preg_match_all('/\/Type \/Page\b(?!s)/', $pdf, $pages);
        if (!preg_match('/\/Type \/Pages \/Kids \[([^\]]*)\] \/Count (\d+)/', $pdf, $tree)) {
            $issues[] = 'Seitenbaum fehlt';
        } else {
            if ((int)$tree[2] !== count($pages[0])) $issues[] = "/Count {$tree[2]} ≠ " . count($pages[0]) . ' Seiten';
            if ((int)$tree[2] !== preg_match_all('/\d+ 0 R/', $tree[1])) $issues[] = '/Kids und /Count passen nicht';
        }
        // /Length je Stream
        if (preg_match_all('/<< \/Length (\d+) >>\nstream\n/', $pdf, $s, PREG_OFFSET_CAPTURE)) {
            foreach ($s[0] as $k => [$hdr, $pos]) {
                $len = (int)$s[1][$k][0];
                $start = $pos + strlen($hdr);
                if (substr($pdf, $start + $len, 10) !== "\nendstream") $issues[] = "Stream bei $pos: /Length $len stimmt nicht";
            }
        }
        return $issues;
    }

    /** Seitenzahl laut Seitenbaum. */
    public static function pageCount(string $pdf): int
    {
        return preg_match('/\/Type \/Pages \/Kids \[[^\]]*\] \/Count (\d+)/', $pdf, $m) ? (int)$m[1] : 0;
    }

    /** @return list<string> Content-Streams in Seitenreihenfolge */
    public static function pageStreams(string $pdf): array
    {
        preg_match_all('/<< \/Length (\d+) >>\nstream\n/', $pdf, $s, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($s[0] as $k => [$hdr, $pos]) $out[] = substr($pdf, $pos + strlen($hdr), (int)$s[1][$k][0]);
        return $out;
    }

    /** @return list<string> sichtbare Texte (UTF-8) */
    public static function texts(string $pdf): array
    {
        preg_match_all('/\((.*?)(?<!\\\\)\) Tj/s', $pdf, $m);
        return array_values(array_filter(array_map(
            fn($t) => (string)iconv('CP1252', 'UTF-8//IGNORE', stripcslashes($t)), $m[1]
        ), fn($t) => trim($t) !== ''));
    }

    /**
     * Textboxen aus den Content-Streams nachgerechnet (Schriftgröße, Lage,
     * Breite nach den Metriken) — für PDFs, deren Writer der Test nicht in der
     * Hand hat. Format wie PdfWriter::boxes(), nur Texte.
     *
     * @return list<array{page:int,kind:string,x0:float,y0:float,x1:float,y1:float,size:?float,text:?string,role:string}>
     */
    public static function textBoxes(string $pdf, float $pageH = 841.89): array
    {
        $out = [];
        foreach (self::pageStreams($pdf) as $i => $s) {
            preg_match_all('/BT \/F(\d) ([\d.]+) Tf ([\d.-]+) ([\d.-]+) Td \((.*?)(?<!\\\\)\) Tj ET/s', $s, $m, PREG_SET_ORDER);
            foreach ($m as [, $font, $size, $x, $y, $raw]) {
                if ($font === '3') continue;   // Symbole
                $text = stripcslashes($raw);
                $w = \Energietracker\Services\Pdf\Metrics::width($text, (float)$size, $font === '2');
                $top = $pageH - (float)$y;
                $out[] = ['page' => $i + 1, 'kind' => 'text', 'x0' => (float)$x, 'x1' => (float)$x + $w,
                          'y0' => $top - 0.718 * (float)$size, 'y1' => $top + 0.207 * (float)$size,
                          'size' => (float)$size, 'text' => (string)iconv('CP1252', 'UTF-8//IGNORE', $text), 'role' => 'text'];
            }
        }
        return $out;
    }

    /**
     * Befunde aus dem Box-Protokoll: Texte und Flächen außerhalb des
     * Satzspiegels, Texte, die sich überdecken.
     *
     * @param list<array{page:int,kind:string,x0:float,y0:float,x1:float,y1:float,size:?float,text:?string,role:string}> $boxes
     * @return list<string>
     */
    public static function boxIssues(array $boxes, float $w, float $h, float $margin = 48.0, float $tol = 0.6): array
    {
        $issues = [];
        foreach ($boxes as $b) {
            if ($b['x0'] < $margin - $tol || $b['x1'] > $w - $margin + $tol || $b['y0'] < $margin - 12 - $tol || $b['y1'] > $h - 12 + $tol) {
                $issues[] = sprintf('S. %d %s außerhalb: [%.1f %.1f %.1f %.1f] %s', $b['page'], $b['kind'], $b['x0'], $b['y0'], $b['x1'], $b['y1'], (string)($b['text'] ?? ''));
            }
        }
        $byPage = [];
        foreach ($boxes as $b) if ($b['kind'] !== 'shape') $byPage[$b['page']][] = $b;
        foreach ($byPage as $page => $list) {
            $n = count($list);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i]; $c = $list[$j];
                    if ($a['x0'] < $c['x1'] - $tol && $c['x0'] < $a['x1'] - $tol && $a['y0'] < $c['y1'] - $tol && $c['y0'] < $a['y1'] - $tol) {
                        $issues[] = sprintf('S. %d überdeckt: „%s“ / „%s“', $page, (string)$a['text'], (string)$c['text']);
                    }
                }
            }
        }
        return $issues;
    }
}
