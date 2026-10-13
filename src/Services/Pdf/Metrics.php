<?php
declare(strict_types=1);

namespace Energietracker\Services\Pdf;

/**
 * v3.4.0 (F1025) — Zeichenbreiten der eingebauten PDF-Schriften.
 *
 * Bis v3.3 schätzte der Writer jede Zeile mit 0,52 em (fett 0,56 em). Das
 * reichte für linksbündige Tabellen, aber nicht für Umbruch, Zentrierung und
 * rechtsbündige Zahlen: „Wärmepumpe“ ist schmaler als geschätzt, „WWW“ breiter,
 * und eine Spalte lief über den Rand (Mangel 1 im Bericht v3.3).
 *
 * Die Werte stammen aus den AFM-Dateien der 14 Standardschriften (Helvetica,
 * Helvetica-Bold, ZapfDingbats; Adobe), in 1/1000 em, je
 * Code der WinAnsi-Kodierung (CP1252) ab 32. Das Euro-Zeichen fehlt in den
 * alten AFM-Dateien; seine Breite (556) ist die der metrisch gleichen Nimbus
 * Sans. Codes, die CP1252 nicht belegt, stehen auf 0 — iconv liefert sie nie.
 */
final class Metrics
{
    /** Helvetica, Codes 32–255 */
    private const REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 350,
        556, 0, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 0, 611, 0,
        0, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 0, 500, 667,
        278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500,
    ];

    /** Helvetica-Bold, Codes 32–255 */
    private const BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 350,
        556, 0, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 0, 611, 0,
        0, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 0, 500, 667,
        278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333,
        400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611,
        722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278,
        722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611,
        556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278,
        611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556,
    ];

    /**
     * ZapfDingbats: Symbole statt Emoji (die CP1252 nicht kennt). Name →
     * [Code, Breite]. Ampeln tragen immer Symbol UND Wort, nie nur Farbe.
     */
    public const SYMBOLS = [
        'check'   => [52, 846],    // ✔ a20
        'cross'   => [54, 761],    // ✖ a22
        'dot'     => [108, 791],   // ● a71
        'square'  => [110, 761],   // ■ a73
        'up'      => [115, 892],   // ▲ a76
        'down'    => [116, 892],   // ▼ a77
        'diamond' => [117, 788],   // ◆ a78
        'arrow'   => [212, 894],   // ➔ a160
        'star'    => [72, 816],    // ★ a35
        'one'     => [182, 788],   // ❶ a130
        'two'     => [183, 788],   // ❷ a131
        'three'   => [184, 788],   // ❸ a132
    ];

    /** Höhe über bzw. unter der Grundlinie (Helvetica, 1/1000 em) — für das Box-Protokoll. */
    public const ASCENT = 718;
    public const DESCENT = 207;

    /** Breite eines CP1252-Strings in Punkt. */
    public static function width(string $cp1252, float $size, bool $bold = false): float
    {
        $table = $bold ? self::BOLD : self::REGULAR;
        $sum = 0;
        $n = strlen($cp1252);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($cp1252[$i]);
            $sum += $c >= 32 ? $table[$c - 32] : 0;
        }
        return $sum * $size / 1000;
    }

    /** Breite eines Symbols in Punkt (0 für unbekannte Namen). */
    public static function symbolWidth(string $name, float $size): float
    {
        return (self::SYMBOLS[$name][1] ?? 0) * $size / 1000;
    }
}
