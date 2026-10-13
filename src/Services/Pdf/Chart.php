<?php
declare(strict_types=1);

namespace Energietracker\Services\Pdf;

/**
 * v3.4.0 (F1025) — Diagramme für den Jahresbericht, als Vektorgrafik in den
 * PdfWriter gezeichnet. Zustandslos: Jede Funktion bekommt Fläche, Daten und
 * Stil und zeichnet; Texte, Farben und Zahlenformat kommen vom Aufrufer.
 *
 * Gestaltungsregeln (Zielgruppe ohne Technikhintergrund, Druck auch in
 * Schwarz-Weiß):
 *   - Farbton der Verbrauchsart aus der Utilities-SSOT, für den Druck
 *     abgedunkelt bis ≥ 3:1 auf Weiß (WCAG 1.4.11) — printColor()
 *   - Vorjahr immer grau, Schätzung schraffiert, Prognose gestrichelt
 *   - Ampeln immer mit Symbol UND Wort, nie nur Farbe
 *   - Die Aussage eines Bildes steht als Satz daneben (Aufrufer)
 */
final class Chart
{
    public const INK   = [30, 41, 59];
    public const MUTE  = [71, 85, 105];
    public const GRID  = [215, 222, 230];
    public const PREV  = [125, 136, 152];
    public const PAPER = [255, 255, 255];
    public const SOFT  = [241, 245, 249];

    /** Ampelstufen: Farbe (Kontrast ≥ 4,5:1 auf Weiß), Symbol aus ZapfDingbats. */
    public const LEVELS = [
        'ok'    => ['rgb' => [21, 128, 61],  'symbol' => 'check'],
        'warn'  => ['rgb' => [161, 98, 7],   'symbol' => 'up'],
        'alert' => ['rgb' => [185, 28, 28],  'symbol' => 'cross'],
        'none'  => ['rgb' => [100, 116, 139], 'symbol' => 'dot'],
    ];

    /** Farben der Effizienzskala A+ … H (Energieausweis: grün → rot). */
    public const SCALE_COLORS = [
        [0, 122, 61], [38, 140, 54], [94, 160, 47], [150, 176, 40], [214, 190, 30],
        [240, 160, 30], [232, 115, 34], [214, 70, 40], [180, 30, 40],
    ];

    // ── Farben ───────────────────────────────────────────────────────────

    /** @return array{0:int,1:int,2:int} */
    public static function hexToRgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) return self::MUTE;
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    /** Relative Leuchtdichte nach WCAG 2. */
    public static function luminance(array $c): float
    {
        $f = function (int|float $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
    }

    public static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a); $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Druckfarbe einer Verbrauchsart: Farbton aus der SSOT, so weit Richtung
     * Schwarz gemischt, bis er auf Weiß mindestens $min:1 erreicht. Strom
     * (#22d3ee) und PV (#fbbf24) liegen im Original bei 1,5–1,8:1.
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function printColor(string $hex, float $min = 3.0): array
    {
        $c = self::hexToRgb($hex);
        for ($k = 1.0; $k > 0.05; $k -= 0.02) {
            $m = [(int)round($c[0] * $k), (int)round($c[1] * $k), (int)round($c[2] * $k)];
            if (self::contrast($m, self::PAPER) >= $min) return $m;
        }
        return [0, 0, 0];
    }

    /** Helle Tönung (Flächen hinter Zahlen) — Mischung mit Weiß. */
    public static function tint(array $c, float $share = 0.15): array
    {
        return [
            (int)round(255 - (255 - $c[0]) * $share),
            (int)round(255 - (255 - $c[1]) * $share),
            (int)round(255 - (255 - $c[2]) * $share),
        ];
    }

    /** Schrift auf einer Fläche: Weiß oder Tinte, je nachdem, was kräftiger absetzt. */
    public static function textOn(array $bg): array
    {
        return self::contrast($bg, self::PAPER) >= self::contrast($bg, self::INK) ? self::PAPER : self::INK;
    }

    /** „Schöner“ Achsenendwert (1, 2, 2,5, 5 × 10^n) über dem Maximum. */
    public static function niceMax(float $v): float
    {
        if ($v <= 0) return 1.0;
        $e = 10 ** floor(log10($v));
        foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $f) {
            if ($f * $e >= $v) return $f * $e;
        }
        return 10 * $e;
    }

    // ── Monatsbalken mit Vorjahr ─────────────────────────────────────────

    /**
     * Zwölf Monate, je ein grauer Vorjahresbalken und ein farbiger Balken des
     * Berichtsjahres; geschätzte Monate schraffiert. Achsenwerte links,
     * Monatsnamen unten, Legende darunter.
     *
     * @param list<array{label:string,value:?float,prev:?float,estimated?:bool}> $months
     * @param array{color:array,fmt:callable,legend?:list<array{0:string,1:string}>} $opt
     *        legend: [[Text, 'now'|'prev'|'estimated'], …]
     * @return float y unter der Legende
     */
    public static function monthBars(PdfWriter $pdf, float $x, float $y, float $w, float $h, array $months, array $opt): float
    {
        $color = $opt['color'];
        $fmt = $opt['fmt'];
        $vals = [];
        foreach ($months as $m) { $vals[] = (float)($m['value'] ?? 0); $vals[] = (float)($m['prev'] ?? 0); }
        $max = self::niceMax(max(1e-9, ...$vals));
        $axisW = 0.0;
        for ($i = 0; $i <= 4; $i++) $axisW = max($axisW, $pdf->textWidth($fmt($max * $i / 4), 8));
        $px = $x + $axisW + 6; $pw = $w - $axisW - 6;
        $py = $y; $ph = $h - 16;
        // Gitter und Achsenwerte
        for ($i = 0; $i <= 4; $i++) {
            $gy = $py + $ph - $ph * $i / 4;
            $pdf->line($px, $gy, $px + $pw, $gy, $i === 0 ? 0.8 : 0.4, $i === 0 ? self::MUTE : self::GRID);
            $pdf->textRight($px - 4, $gy + 3, $fmt($max * $i / 4), 8, false, self::MUTE);
        }
        $n = max(1, count($months));
        $slot = $pw / $n;
        $bw = min(14.0, $slot * 0.36);
        foreach (array_values($months) as $i => $m) {
            $cx = $px + $slot * ($i + 0.5);
            if (($m['prev'] ?? null) !== null && (float)$m['prev'] > 0) {
                $bh = $ph * (float)$m['prev'] / $max;
                $pdf->rect($cx - $bw - 1, $py + $ph - $bh, $bw, $bh, self::PREV);
            }
            if (($m['value'] ?? null) !== null && (float)$m['value'] > 0) {
                $bh = $ph * (float)$m['value'] / $max;
                $pdf->rect($cx + 1, $py + $ph - $bh, $bw, $bh, $color);
                if (!empty($m['estimated'])) $pdf->hatch($cx + 1, $py + $ph - $bh, $bw, $bh, self::PAPER);
            }
            $pdf->textCenter($cx, $py + $ph + 11, (string)$m['label'], 8, false, self::MUTE);
        }
        return self::legend($pdf, $px, $py + $h + 6, $opt['legend'] ?? [], $color);
    }

    /**
     * Legende in einer Zeile: farbiges Kästchen und Text. Art: now | prev |
     * estimated | projected | line.
     *
     * @param list<array{0:string,1:string,2?:array}> $items  [Text, Art, eigene Farbe?]
     */
    public static function legend(PdfWriter $pdf, float $x, float $y, array $items, array $color): float
    {
        if ($items === []) return $y;
        $cx = $x;
        $base = $color;
        foreach ($items as $it) {
            [$text, $kind] = $it;
            $color = $it[2] ?? $base;
            match ($kind) {
                'prev'      => $pdf->rect($cx, $y, 9, 9, self::PREV),
                'estimated' => (function () use ($pdf, $cx, $y, $color) { $pdf->rect($cx, $y, 9, 9, $color); $pdf->hatch($cx, $y, 9, 9, self::PAPER, 3); })(),
                'projected' => $pdf->line($cx, $y + 4.5, $cx + 14, $y + 4.5, 1.6, $color, [3, 2]),
                'line'      => $pdf->line($cx, $y + 4.5, $cx + 14, $y + 4.5, 1.6, $color),
                default     => $pdf->rect($cx, $y, 9, 9, $color),
            };
            $off = in_array($kind, ['projected', 'line'], true) ? 18 : 13;
            $pdf->text($cx + $off, $y + 8, $text, 8.5, false, self::MUTE);
            $cx += $off + $pdf->textWidth($text, 8.5) + 14;
        }
        return $y + 14;
    }

    // ── Horizontale Balken ───────────────────────────────────────────────

    /**
     * Je Zeile: Bezeichnung links, Balken, Wert dahinter; Vorjahr als dünner
     * grauer Balken darunter.
     *
     * @param list<array{label:string,value:float,prev?:?float,color:array,text:string}> $rows
     * @return float y unter der letzten Zeile
     */
    public static function hbars(PdfWriter $pdf, float $x, float $y, float $w, array $rows, float $labelW = 130.0): float
    {
        $max = 0.0;
        $textW = 0.0;
        foreach ($rows as $r) {
            $max = max($max, abs((float)$r['value']), abs((float)($r['prev'] ?? 0)));
            $textW = max($textW, $pdf->textWidth((string)$r['text'], 10, true));
        }
        $max = $max > 0 ? $max : 1.0;
        $bx = $x + $labelW;
        $bwMax = max(20.0, $w - $labelW - $textW - 10);
        foreach ($rows as $r) {
            $label = $pdf->wrap((string)$r['label'], 10, $labelW - 8)[0] ?? '';
            $pdf->text($x, $y + 10, $label, 10, false, self::INK);
            $bw = $bwMax * abs((float)$r['value']) / $max;
            if ($bw > 0) $pdf->rect($bx, $y + 1, $bw, 12, $r['color']);
            $pdf->text($bx + $bw + 6, $y + 11, (string)$r['text'], 10, true, self::INK);
            if (($r['prev'] ?? null) !== null) {
                $pw = $bwMax * abs((float)$r['prev']) / $max;
                if ($pw > 0) $pdf->rect($bx, $y + 15, $pw, 4, self::PREV);
            }
            $y += 26;
        }
        return $y;
    }

    // ── Linie mit Nulllinie (Saldo-Verlauf) ──────────────────────────────

    /**
     * Verlauf um null: oberhalb (Nachzahlung) rot getönt, unterhalb
     * (Guthaben) grün getönt — per Beschneiden an der Nulllinie. Gemessener
     * Teil durchgezogen, Prognose gestrichelt.
     *
     * @param list<array{label:string,value:float,projected:bool}> $points
     * @param array{color:array,fmt:callable,above:array,below:array} $opt
     */
    public static function lineChart(PdfWriter $pdf, float $x, float $y, float $w, float $h, array $points, array $opt): void
    {
        $n = count($points);
        if ($n < 2) return;
        $vals = array_map(fn($p) => (float)$p['value'], $points);
        $hi = max(0.0, ...$vals); $lo = min(0.0, ...$vals);
        $span = $hi - $lo;
        if ($span <= 0) $span = 1.0;
        $hi += $span * 0.1; $lo -= $span * 0.1; $span = $hi - $lo;
        $fmt = $opt['fmt'];
        $axisW = max($pdf->textWidth($fmt($hi), 8), $pdf->textWidth($fmt($lo), 8), $pdf->textWidth($fmt(0), 8));
        $px = $x + $axisW + 6; $pw = $w - $axisW - 6; $ph = $h - 14;
        $Y = fn(float $v): float => $y + $ph - ($v - $lo) / $span * $ph;
        $X = fn(int $i): float => $px + $pw * $i / ($n - 1);
        $zero = $Y(0.0);
        // Flächen: Polygon Linie + Nulllinie, oben bzw. unten beschnitten
        $area = [['M', $X(0), $zero]];
        foreach ($points as $i => $p) $area[] = ['L', $X($i), $Y((float)$p['value'])];
        $area[] = ['L', $X($n - 1), $zero];
        $area[] = ['Z'];
        $pdf->save(); $pdf->clipRect($px, $y, $pw, $zero - $y); $pdf->path($area, $opt['above']); $pdf->restore();
        $pdf->save(); $pdf->clipRect($px, $zero, $pw, $y + $ph - $zero); $pdf->path($area, $opt['below']); $pdf->restore();
        // Nulllinie und Achse
        $pdf->line($px, $zero, $px + $pw, $zero, 0.8, self::MUTE);
        $pdf->textRight($px - 4, $zero + 3, $fmt(0), 8, false, self::MUTE);
        if ($Y($hi) < $zero - 12) $pdf->textRight($px - 4, $Y($hi) + 3, $fmt($hi), 8, false, self::MUTE);
        if ($Y($lo) > $zero + 12) $pdf->textRight($px - 4, $Y($lo) + 3, $fmt($lo), 8, false, self::MUTE);
        // Linie: gemessen durchgezogen, Prognose gestrichelt
        $measured = []; $projected = [];
        foreach ($points as $i => $p) {
            $pt = [$X($i), $Y((float)$p['value'])];
            if (empty($p['projected'])) { $measured[] = $pt; }
            else {
                if ($projected === [] && $measured !== []) $projected[] = end($measured);
                $projected[] = $pt;
            }
        }
        if (count($measured) >= 2) $pdf->polyline($measured, 1.8, $opt['color']);
        if (count($projected) >= 2) $pdf->polyline($projected, 1.8, $opt['color'], [4, 3]);
        $last = end($points);
        $pdf->circle($X($n - 1), $Y((float)$last['value']), 3, $opt['color']);
        // Monatsnamen: erster, mittlerer, letzter
        foreach (array_unique([0, intdiv($n - 1, 2), $n - 1]) as $i) {
            $lab = (string)($points[$i]['label'] ?? '');
            if ($lab === '') continue;
            $lw = $pdf->textWidth($lab, 8);
            $lx = min(max($X($i) - $lw / 2, $px), $px + $pw - $lw);
            $pdf->text($lx, $y + $ph + 11, $lab, 8, false, self::MUTE);
        }
    }

    // ── Kleinteile ───────────────────────────────────────────────────────

    /** Sparkline: Verlauf über wenige Jahre, letzter Punkt betont. @param list<?float> $values */
    public static function sparkline(PdfWriter $pdf, float $x, float $y, float $w, float $h, array $values, array $color): void
    {
        $pts = [];
        $n = count($values);
        $nums = array_values(array_filter($values, fn($v) => $v !== null));
        if (count($nums) < 2) return;
        $lo = min($nums); $hi = max($nums);
        $span = $hi - $lo > 0 ? $hi - $lo : 1.0;
        foreach ($values as $i => $v) {
            if ($v === null) continue;
            $pts[] = [$x + ($n > 1 ? $w * $i / ($n - 1) : 0), $y + $h - ($v - $lo) / $span * $h];
        }
        $pdf->polyline($pts, 1.4, $color);
        $l = end($pts);
        $pdf->circle($l[0], $l[1], 2.2, $color);
    }

    /**
     * Ampel: Kreis in der Stufenfarbe mit Symbol, daneben das Wort.
     * @return float Breite
     */
    public static function light(PdfWriter $pdf, float $x, float $y, string $level, string $word, float $size = 10.5): float
    {
        $lv = self::LEVELS[$level] ?? self::LEVELS['none'];
        $r = $size * 0.62;
        $pdf->circle($x + $r, $y - $size * 0.35, $r, $lv['rgb']);
        $sw = Metrics::symbolWidth($lv['symbol'], $size * 0.72);
        $pdf->symbol($x + $r - $sw / 2, $y - $size * 0.35 + $size * 0.26, $lv['symbol'], $size * 0.72, self::PAPER);
        $pdf->text($x + 2 * $r + 5, $y, $word, $size, true, $lv['rgb']);
        return 2 * $r + 5 + $pdf->textWidth($word, $size, true);
    }

    /** Fortschrittsbalken (z. B. Amortisation). */
    public static function progress(PdfWriter $pdf, float $x, float $y, float $w, float $h, float $value, float $max, array $color): void
    {
        $pdf->roundRect($x, $y, $w, $h, $h / 2, self::SOFT, self::GRID, 0.5);
        $share = $max > 0 ? max(0.0, min(1.0, $value / $max)) : 0.0;
        if ($share > 0) $pdf->roundRect($x, $y, max($h, $w * $share), $h, $h / 2, $color);
    }

    /**
     * Effizienzskala A+ … H mit Zeiger. Gleich breite Felder; der Zeiger sitzt
     * innerhalb des Felds anteilig zwischen den Grenzen der Klasse.
     *
     * @param array<string,float> $thresholds Klasse → Obergrenze (kWh/m²·a), aufsteigend
     * @return float y unter der Skala
     */
    public static function efficiencyScale(PdfWriter $pdf, float $x, float $y, float $w, array $thresholds, ?float $value, string $valueText): float
    {
        $classes = array_keys($thresholds);
        $last = chr(ord((string)end($classes)) + 1);   // eine Klasse über der letzten Grenze (H)
        $classes[] = $last;
        $n = count($classes);
        $seg = $w / $n;
        $top = $y + 18;
        foreach ($classes as $i => $c) {
            $col = self::SCALE_COLORS[(int)round($i * (count(self::SCALE_COLORS) - 1) / max(1, $n - 1))];
            $pdf->rect($x + $i * $seg, $top, $seg - 1, 18, $col);
            $pdf->textCenter($x + ($i + 0.5) * $seg, $top + 13, (string)$c, 10, true, self::textOn($col));
        }
        if ($value !== null) {
            $bounds = array_values($thresholds);
            $pos = $n - 1; $frac = 0.5;
            foreach ($bounds as $i => $b) {
                if ($value <= $b) {
                    $lo = $i === 0 ? 0.0 : (float)$bounds[$i - 1];
                    $pos = $i; $frac = ($value - $lo) / max(1e-9, $b - $lo);
                    break;
                }
            }
            $px = $x + ($pos + max(0.1, min(0.9, $frac))) * $seg;
            $pdf->polygon([[$px - 5, $top - 8], [$px + 5, $top - 8], [$px, $top - 1]], self::INK);
            $tw = $pdf->textWidth($valueText, 9, true);
            $tx = min(max($px - $tw / 2, $x), $x + $w - $tw);
            $pdf->text($tx, $top - 11, $valueText, 9, true, self::INK);
        }
        return $top + 22;
    }

    /**
     * Zeitstrahl: Achse, Punkte mit Bezeichnung und Datum, „heute“-Marke.
     * Beschriftungen werden reihum über und unter die Achse gelegt; stoßen
     * sie an, rücken sie eine Reihe weiter.
     *
     * @param list<array{date:string,label:string,text:string}> $points
     * @return float Höhe der Grafik
     */
    public static function timeline(PdfWriter $pdf, float $x, float $y, float $w, array $points, string $today, string $todayLabel, array $color): float
    {
        $dates = array_merge(array_column($points, 'date'), [$today]);
        sort($dates);
        $t0 = strtotime($dates[0]); $t1 = strtotime(end($dates));
        if ($t0 === false || $t1 === false || $t1 <= $t0) return 0.0;
        $X = fn(string $d): float => $x + 6 + ($w - 12) * ((int)strtotime($d) - $t0) / ($t1 - $t0);
        $axis = $y + 40;
        // Linien um die halbe Strichstärke eingerückt: Sie enden am Satzspiegel, nicht dahinter
        $pdf->line($x + 1.5, $axis, $x + $w - 1.5, $axis, 1.2, self::GRID);
        $tx = $X($today);
        $pdf->line($x + 1.5, $axis, max($x + 1.5, min($tx, $x + $w - 1.5)), $axis, 2.4, $color);
        $placed = [];
        // Reihen: oben 1, unten 1, oben 2, unten 2 (Grundlinie der Bezeichnung)
        $rows = [$axis - 22, $axis + 20, $axis - 44 + 4, $axis + 42];
        $maxY = $axis + 10;
        usort($points, fn($a, $b) => strcmp($a['date'], $b['date']));
        $all = array_merge($points, [['date' => $today, 'label' => $todayLabel, 'text' => '', 'today' => true]]);
        foreach ($all as $i => $p) {
            $px = $X($p['date']);
            if (!empty($p['today'])) {
                $pdf->line($px, $axis - 8, $px, $axis + 8, 1.4, self::INK);
            } else {
                $pdf->circle($px, $axis, 4, $color, self::PAPER, 1);
            }
            $lw = max($pdf->textWidth($p['label'], 8.5, true), $pdf->textWidth($p['text'], 8));
            $lx = min(max($px - $lw / 2, $x), $x + $w - $lw);
            foreach ($rows as $k => $ry) {
                $box = [$lx - 2, $ry - 9, $lx + $lw + 2, $ry + 12];
                $hit = false;
                foreach ($placed as $b) {
                    if ($box[0] < $b[2] && $b[0] < $box[2] && $box[1] < $b[3] && $b[1] < $box[3]) { $hit = true; break; }
                }
                if ($hit && $k < count($rows) - 1) continue;
                $placed[] = $box;
                $pdf->text($lx, $ry, $p['label'], 8.5, true, self::INK);
                if ($p['text'] !== '') $pdf->text($lx, $ry + 10, $p['text'], 8, false, self::MUTE);
                $maxY = max($maxY, $ry + 12);
                break;
            }
        }
        return $maxY - $y + 4;
    }

    // ── Energiefluss ─────────────────────────────────────────────────────

    /**
     * Energiefluss im Haus: Sonne oben, Netz links, Haus rechts, Speicher
     * unten. Bögen als kubische Bézier, Strichstärke nach kWh, Pfeilspitzen.
     * Icons als einfache Vektorformen (CP1252 kennt keine Emoji).
     *
     * @param array{gen:float,direct:float,feed:float,grid:float,charged:float,discharged:float} $f
     * @param array{pv:string,grid:string,house:string,battery:string} $labels
     * @param array{pv:array,grid:array,feed:array,battery:array} $colors
     */
    public static function energyFlow(PdfWriter $pdf, float $x, float $y, float $w, float $h, array $f, array $labels, array $colors, callable $fmt): void
    {
        $bat = $f['charged'] > 0 || $f['discharged'] > 0;
        $sx = $w / 320; $sy = $h / ($bat ? 235 : 165);
        $P = fn(float $a, float $b): array => [$x + $a * $sx, $y + $b * $sy];
        $N = ['pv' => $P(160, 34), 'grid' => $P(42, 120), 'house' => $P(278, 120), 'bat' => $P(160, 190)];
        $r = 19.0;
        $max = max($f['gen'], $f['grid'], 1.0);
        // $at: Lage der Zahl auf dem Bogen (0 … 1) — Netz → Haus links, damit sie
        // nicht auf dem senkrechten Bogen zum Speicher sitzt
        $flow = function (string $a, string $b, float $v, array $col, float $at = 0.5) use ($pdf, $N, $r, $max, $fmt): void {
            if (!($v > 0)) return;
            [$x1, $y1] = $N[$a]; [$x2, $y2] = $N[$b];
            $mx = ($x1 + $x2) / 2; $my = ($y1 + $y2) / 2;
            $q = abs($x1 - $x2) < 1 ? [$mx + 26, $my] : [$mx, $my - 22];
            // Anfang und Ende auf dem Kreisrand, Richtung zum Kontrollpunkt
            $edge = function (array $c, array $toward) use ($r): array {
                $dx = $toward[0] - $c[0]; $dy = $toward[1] - $c[1]; $d = max(1e-6, hypot($dx, $dy));
                return [$c[0] + $dx / $d * ($r + 2), $c[1] + $dy / $d * ($r + 2)];
            };
            $s = $edge([$x1, $y1], $q);
            $e = $edge([$x2, $y2], $q);
            $lw = 1.5 + $v / $max * 9;
            // Pfeilspitze: Linie endet vor der Spitze
            $dx = $e[0] - $q[0]; $dy = $e[1] - $q[1]; $d = max(1e-6, hypot($dx, $dy));
            $ux = $dx / $d; $uy = $dy / $d;
            $head = 6 + $lw * 0.8;
            $e2 = [$e[0] - $ux * $head, $e[1] - $uy * $head];
            $c1 = [$s[0] + 2 / 3 * ($q[0] - $s[0]), $s[1] + 2 / 3 * ($q[1] - $s[1])];
            $c2 = [$e2[0] + 2 / 3 * ($q[0] - $e2[0]), $e2[1] + 2 / 3 * ($q[1] - $e2[1])];
            $pdf->path([['M', $s[0], $s[1]], ['C', $c1[0], $c1[1], $c2[0], $c2[1], $e2[0], $e2[1]]], null, $col, $lw);
            $hw = $head * 0.7;
            $pdf->polygon([[$e[0], $e[1]], [$e2[0] - $uy * $hw, $e2[1] + $ux * $hw], [$e2[0] + $uy * $hw, $e2[1] - $ux * $hw]], $col);
            // Zahl auf dem Bogen (quadratische Kurve an der Stelle $at), auf weißem Grund
            $t = $fmt($v);
            $tw = $pdf->textWidth($t, 8.5, true);
            $u = 1 - $at;
            $bx = $u * $u * $x1 + 2 * $u * $at * $q[0] + $at * $at * $x2;
            $by = $u * $u * $y1 + 2 * $u * $at * $q[1] + $at * $at * $y2;
            $lx = $bx - $tw / 2; $ly = $by + 3;
            $pdf->rect($lx - 2, $ly - 8.5, $tw + 4, 11.5, self::PAPER);
            $pdf->text($lx, $ly, $t, 8.5, true, self::INK);
        };
        $direct = max(0.0, $f['direct']);
        $flow('pv', 'house', $direct, $colors['pv']);
        $flow('pv', 'grid', $f['feed'], $colors['feed']);
        $flow('grid', 'house', $f['grid'], $colors['grid'], $bat ? 0.28 : 0.5);
        if ($bat) {
            $flow('pv', 'bat', $f['charged'], $colors['pv']);
            $flow('bat', 'house', $f['discharged'], $colors['battery']);
        }
        $node = function (string $k, string $label) use ($pdf, $N, $r): void {
            [$cx, $cy] = $N[$k];
            $pdf->circle($cx, $cy, $r, self::SOFT, self::MUTE, 1);
            self::icon($pdf, $k, $cx, $cy);
            if ($k === 'pv') {
                $pdf->text($cx + $r + 6, $cy + 4, $label, 9, true, self::INK);
            } else {
                $pdf->textCenter($cx, $cy + $r + 12, $label, 9, true, self::INK);
            }
        };
        $node('pv', $labels['pv']);
        $node('house', $labels['house']);
        $node('grid', $labels['grid']);
        if ($bat) $node('bat', $labels['battery']);
    }

    /** Einfache Vektor-Icons: Sonne, Haus, Strommast, Batterie. */
    private static function icon(PdfWriter $pdf, string $k, float $cx, float $cy): void
    {
        $ink = self::INK;
        switch ($k) {
            case 'pv':
                $pdf->circle($cx, $cy, 5, [234, 179, 8]);
                for ($i = 0; $i < 8; $i++) {
                    $a = $i * M_PI / 4;
                    $pdf->line($cx + cos($a) * 7.5, $cy + sin($a) * 7.5, $cx + cos($a) * 11, $cy + sin($a) * 11, 1.2, [161, 98, 7]);
                }
                break;
            case 'house':
                $pdf->polygon([[$cx - 9, $cy - 1], [$cx, $cy - 10], [$cx + 9, $cy - 1]], $ink);
                $pdf->rect($cx - 6.5, $cy - 1, 13, 10, null, $ink, 1.4);
                $pdf->rect($cx - 2, $cy + 3, 4, 6, $ink);
                break;
            case 'grid':
                $pdf->polyline([[$cx - 7, $cy + 10], [$cx - 2, $cy - 10], [$cx + 2, $cy - 10], [$cx + 7, $cy + 10]], 1.3, $ink);
                $pdf->line($cx - 8, $cy - 5, $cx + 8, $cy - 5, 1.3, $ink);
                $pdf->line($cx - 6, $cy + 1, $cx + 6, $cy + 1, 1.3, $ink);
                break;
            case 'bat':
                $pdf->rect($cx - 9, $cy - 5, 16, 10, null, $ink, 1.4);
                $pdf->rect($cx + 7, $cy - 2.5, 2.5, 5, $ink);
                $pdf->rect($cx - 7, $cy - 3, 8, 6, [21, 128, 61]);
                break;
        }
    }
}
