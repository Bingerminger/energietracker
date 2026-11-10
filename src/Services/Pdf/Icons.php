<?php
declare(strict_types=1);

namespace Energietracker\Services\Pdf;

/**
 * v3.12.0 (Sichtung S7) — die Grafiken des Berichts als Vektorzeichnung, dieselbe Hand wie `public/js/lib/icons.js`:
 * 24er-Raster, Strich 1,5, eine Farbe. Bis dahin zeichnete das PDF Symbole aus ZapfDingbats (❶ ★ ◆ ✔ ✖ ▲), die wie Emoji wirkten.
 */
final class Icons
{
    /** Die Namen entsprechen den Grafiken der Oberfläche. */
    public const NAMES = ['erfolg', 'achtung', 'fehler', 'neutral', 'info'];

    /**
     * @param float $x     linke Kante
     * @param float $y     obere Kante
     * @param float $size  Kantenlänge
     * @param array{0:int,1:int,2:int} $rgb
     */
    public static function draw(PdfWriter $pdf, string $name, float $x, float $y, float $size, array $rgb): void
    {
        $k = $size / 24.0;
        $lw = max(0.7, 1.5 * $k);
        $p = static fn(float $a, float $b): array => [$x + $a * $k, $y + $b * $k];
        $line = static function (array ...$pts) use ($pdf, $p, $lw, $rgb): void {
            $pdf->polyline(array_map(static fn(array $q): array => $p($q[0], $q[1]), $pts), $lw, $rgb);
        };
        $ring = static fn() => $pdf->circle($x + 12 * $k, $y + 12 * $k, 8.5 * $k, null, $rgb, $lw);
        $dot = static fn(float $a, float $b) => $pdf->circle($x + $a * $k, $y + $b * $k, 1.0 * $k, $rgb);
        switch ($name) {
            case 'erfolg':
                $ring();
                $line([8, 12.5], [10.8, 15.3], [16.2, 9.5]);
                break;
            case 'achtung':
                $pdf->polygon([$p(12, 4), $p(21, 19.5), $p(3, 19.5)], null, $rgb, $lw);
                $line([12, 10], [12, 14.5]);
                $dot(12, 17.0);
                break;
            case 'fehler':
                $ring();
                $line([9, 9], [15, 15]);
                $line([15, 9], [9, 15]);
                break;
            case 'info':
                $ring();
                $line([12, 11], [12, 16.5]);
                $dot(12, 7.9);
                break;
            default:   // neutral
                $ring();
                $line([8, 12], [16, 12]);
        }
    }
}
