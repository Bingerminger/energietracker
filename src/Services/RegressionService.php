<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * HGT-vs-consumption regression models.
 *
 * Used only for HGT-relevant utilities (gas, fernwaerme, heizoel, pellets).
 * For others (strom, wasser), the regression is bypassed and the forecast
 * falls back to seasonal profile only.
 *
 * v1.3.0 — neue Modelle: `sigmoid` (TU-München/BDEW-Form) und
 * `segmented` mit datenbasiertem Knickpunkt (Grid-Search Q1..Q3).
 */
final class RegressionService
{
    /** Simple linear y = a*x + b */
    public function linear(array $x, array $y): array
    {
        $n = count($x);
        if ($n < 3) return ['model' => 'linear', 'a' => 0.0, 'b' => 0.0, 'r2' => 0.0, 'n' => $n, 'valid' => false];
        $sx = array_sum($x); $sy = array_sum($y);
        $sxy = $sxx = $ssT = $ssR = 0.0;
        $ym = $sy / $n;
        for ($i = 0; $i < $n; $i++) {
            $sxy += $x[$i] * $y[$i]; $sxx += $x[$i] ** 2;
            $ssT += ($y[$i] - $ym) ** 2;
        }
        $den = $n * $sxx - $sx * $sx;
        if (abs($den) < 1e-10) {
            return ['model' => 'linear', 'a' => 0.0, 'b' => round($ym, 2), 'r2' => 0.0, 'n' => $n, 'valid' => false];
        }
        $a = ($n * $sxy - $sx * $sy) / $den;
        $b = ($sy - $a * $sx) / $n;
        for ($i = 0; $i < $n; $i++) $ssR += ($y[$i] - ($a * $x[$i] + $b)) ** 2;
        $r2 = $ssT > 0 ? max(0.0, 1.0 - $ssR / $ssT) : 0.0;
        return [
            'model' => 'linear', 'a' => round($a, 4), 'b' => round($b, 2),
            'r2' => round($r2, 4), 'n' => $n, 'valid' => true,
            'predict' => sprintf('kWh = %.2f × HGT + %.0f', $a, $b),
        ];
    }

    /** Polynomial degree 2 via Cramer's rule */
    public function polynomial(array $x, array $y): array
    {
        $n = count($x);
        if ($n < 4) return ['model' => 'polynomial', 'valid' => false, 'r2' => 0.0, 'n' => $n];
        $sx = $sx2 = $sx3 = $sx4 = $sy = $sxy = $sx2y = 0.0;
        foreach ($x as $i => $xi) {
            $yi = $y[$i];
            $sx += $xi; $sx2 += $xi ** 2; $sx3 += $xi ** 3; $sx4 += $xi ** 4;
            $sy += $yi; $sxy += $xi * $yi; $sx2y += ($xi ** 2) * $yi;
        }
        $M = [[$sx4, $sx3, $sx2], [$sx3, $sx2, $sx], [$sx2, $sx, (float)$n]];
        $V = [$sx2y, $sxy, $sy];
        $coef = $this->solve3x3($M, $V);
        if ($coef === null) return ['model' => 'polynomial', 'valid' => false, 'r2' => 0.0, 'n' => $n];
        [$a, $b, $c] = $coef;
        $ym = $sy / $n; $ssT = $ssR = 0.0;
        foreach ($x as $i => $xi) {
            $pred = $a * $xi ** 2 + $b * $xi + $c;
            $ssT += ($y[$i] - $ym) ** 2;
            $ssR += ($y[$i] - $pred) ** 2;
        }
        $r2 = $ssT > 0 ? max(0.0, 1.0 - $ssR / $ssT) : 0.0;
        return [
            'model' => 'polynomial', 'a' => round($a, 6), 'b' => round($b, 4), 'c' => round($c, 2),
            'r2' => round($r2, 4), 'n' => $n, 'valid' => true,
            'predict' => sprintf('kWh = %.4f × HGT² + %.2f × HGT + %.0f', $a, $b, $c),
        ];
    }

    /** Huber-style robust regression with iterative re-weighting */
    public function robust(array $x, array $y): array
    {
        $n = count($x);
        if ($n < 4) return ['model' => 'robust', 'valid' => false, 'r2' => 0.0, 'n' => $n];
        $init = $this->linear($x, $y);
        $a = $init['a']; $b = $init['b'];

        for ($iter = 0; $iter < 5; $iter++) {
            $res = [];
            foreach ($x as $i => $xi) $res[] = abs($y[$i] - ($a * $xi + $b));
            sort($res);
            $mad = $res[(int)floor($n / 2)] ?: 1.0;
            $th = 2.5 * $mad;
            $sw = $swx = $swy = $swxy = $swxx = 0.0;
            foreach ($x as $i => $xi) {
                $r = abs($y[$i] - ($a * $xi + $b));
                $w = $r <= $th ? 1.0 : $th / max($r, 1e-10);
                $sw += $w; $swx += $w * $xi; $swy += $w * $y[$i];
                $swxy += $w * $xi * $y[$i]; $swxx += $w * $xi * $xi;
            }
            $den = $sw * $swxx - $swx * $swx;
            if (abs($den) < 1e-10) break;
            $a = ($sw * $swxy - $swx * $swy) / $den;
            $b = ($swy - $a * $swx) / $sw;
        }
        $ym = array_sum($y) / $n; $ssT = $ssR = 0.0;
        foreach ($x as $i => $xi) {
            $pred = $a * $xi + $b;
            $ssT += ($y[$i] - $ym) ** 2;
            $ssR += ($y[$i] - $pred) ** 2;
        }
        $r2 = $ssT > 0 ? max(0.0, 1.0 - $ssR / $ssT) : 0.0;
        return [
            'model' => 'robust', 'a' => round($a, 4), 'b' => round($b, 2),
            'r2' => round($r2, 4), 'n' => $n, 'valid' => true,
            'predict' => sprintf('kWh = %.2f × HGT + %.0f (Huber)', $a, $b),
        ];
    }

    /**
     * Two-segment linear: heating season vs. shoulder/summer.
     *
     * v1.3.0 — der Knickpunkt kann datenbasiert gefittet werden:
     *   $split = null  → Grid-Search über Q1..Q3 der HGT-Werte, der Split
     *                    mit minimaler Residuenquadratsumme (SSR) gewinnt.
     *   $split = float → fester Knickpunkt (Verhalten wie ≤ v1.2.0).
     *
     * Mindestanforderung pro Segment: 4 Punkte (vorher 3) — verhindert,
     * dass der Auto-Split in instabile Mini-Segmente kippt. Findet die
     * Grid-Search keinen gültigen Split, fällt sie auf den Default 50 zurück.
     */
    public function segmented(array $x, array $y, ?float $split = 50.0): array
    {
        $n = count($x);
        if ($n < 8) return ['model' => 'segmented', 'valid' => false, 'r2' => 0.0, 'n' => $n];

        $splitMode = 'fixed';
        if ($split === null) {
            $best = $this->findBestSplit($x, $y);
            if ($best === null) {
                $split = 50.0;          // Fallback
                $splitMode = 'fallback';
            } else {
                $split = $best;
                $splitMode = 'auto';
            }
        }

        $xH = $yH = $xB = $yB = [];
        foreach ($x as $i => $xi) {
            if ($xi >= $split) { $xH[] = $xi; $yH[] = $y[$i]; }
            else { $xB[] = $xi; $yB[] = $y[$i]; }
        }
        if (count($xH) < 4 || count($xB) < 4) {
            return ['model' => 'segmented', 'valid' => false, 'r2' => 0.0, 'n' => $n];
        }
        $heat = $this->linear($xH, $yH);
        $base = $this->linear($xB, $yB);
        $ym = array_sum($y) / $n; $ssT = $ssR = 0.0;
        foreach ($x as $i => $xi) {
            $pred = $xi >= $split
                ? $heat['a'] * $xi + $heat['b']
                : $base['a'] * $xi + $base['b'];
            $ssT += ($y[$i] - $ym) ** 2;
            $ssR += ($y[$i] - $pred) ** 2;
        }
        $r2 = $ssT > 0 ? max(0.0, 1.0 - $ssR / $ssT) : 0.0;
        return [
            'model' => 'segmented', 'split' => round($split, 2),
            'split_mode' => $splitMode,
            'heat' => $heat, 'base' => $base,
            'r2' => round($r2, 4), 'n' => $n, 'valid' => true,
            'predict' => sprintf('HGT≥%.0f: %.2f×HGT+%.0f · HGT<%.0f: %.2f×HGT+%.0f',
                $split, $heat['a'], $heat['b'], $split, $base['a'], $base['b']),
        ];
    }

    /**
     * Grid-Search nach dem Knickpunkt mit minimaler SSR.
     *
     * Sucht zwischen dem 1. und 3. Quartil der HGT-Werte (sinnvoller
     * Bereich — am Rand würde ein Segment zu klein). 20 Kandidaten.
     * Verlangt ≥ 4 Punkte je Segment, sonst gilt der Kandidat nicht.
     *
     * @return float|null bester Split oder null wenn keiner zulässig ist
     */
    private function findBestSplit(array $x, array $y): ?float
    {
        $sorted = $x;
        sort($sorted);
        $n = count($sorted);
        $q1 = $sorted[(int)floor($n * 0.25)];
        $q3 = $sorted[(int)floor($n * 0.75)];
        if ($q3 <= $q1) return null;

        $steps = 20;
        $bestSplit = null;
        $bestSsr = INF;
        for ($s = 0; $s <= $steps; $s++) {
            $cand = $q1 + ($q3 - $q1) * $s / $steps;
            $xH = $yH = $xB = $yB = [];
            foreach ($x as $i => $xi) {
                if ($xi >= $cand) { $xH[] = $xi; $yH[] = $y[$i]; }
                else { $xB[] = $xi; $yB[] = $y[$i]; }
            }
            if (count($xH) < 4 || count($xB) < 4) continue;
            $h = $this->linear($xH, $yH);
            $b = $this->linear($xB, $yB);
            if (!($h['valid'] ?? false) || !($b['valid'] ?? false)) continue;
            $ssr = 0.0;
            foreach ($x as $i => $xi) {
                $pred = $xi >= $cand
                    ? $h['a'] * $xi + $h['b']
                    : $b['a'] * $xi + $b['b'];
                $ssr += ($y[$i] - $pred) ** 2;
            }
            if ($ssr < $bestSsr) {
                $bestSsr = $ssr;
                $bestSplit = $cand;
            }
        }
        return $bestSplit;
    }

    /**
     * Sigmoid-Heizsignatur (Form nach TU München / BDEW-SLP).
     *
     *   h(θ) = A / (1 + (B / (θ − θ₀))^C) + D
     *
     * mit θ = HGT (nicht Temperatur — die App rechnet konsistent in
     * Heizgradtagen; die Sigmoide bildet damit „Sättigung bei großer
     * Heizlast, Grundlast bei HGT→0" ab). C ist in v1.3.0 auf 3 fixiert
     * (typischer Wohnhauswert; reduziert die Parameterzahl von 5 auf 4
     * und stabilisiert den Fit auf den ~24–36 Monatspunkten).
     *
     * Fit: Coarse-Grid für gute Startwerte, dann Nelder-Mead-Refinement
     * (derivatfrei, robust). Bei R² < 0.5 → valid=false → der Forecast
     * fällt automatisch auf das Saisonprofil zurück.
     */
    public function sigmoid(array $x, array $y): array
    {
        $n = count($x);
        if ($n < 8) return ['model' => 'sigmoid', 'valid' => false, 'r2' => 0.0, 'n' => $n];

        $maxY = max($y);
        $minY = min($y);
        $maxX = max($x);
        if ($maxX <= 0 || $maxY <= 0) {
            return ['model' => 'sigmoid', 'valid' => false, 'r2' => 0.0, 'n' => $n];
        }
        $C = 3.0; // fixiert

        // Sinnvolle Parametergrenzen — verhindern, dass Nelder-Mead in ein
        // entartetes Pseudo-Optimum läuft (A→∞, D→−∞ heben sich auf und
        // liefern zufällig brauchbares R², aber unbrauchbare Extrapolation).
        $loA = 1e-3;            $hiA = 3.0 * $maxY;
        $loB = 1e-3;            $hiB = 5.0 * $maxX;
        $loT = -1.0 * $maxX;    $hiT = $maxX;
        $loD = 0.0;             $hiD = max($maxY, 1.0);

        // Zielfunktion: SSR für Parameter [A, B, theta0, D] mit Box-Penalty
        $ssr = function (array $p) use ($x, $y, $C, $loA,$hiA,$loB,$hiB,$loT,$hiT,$loD,$hiD): float {
            [$A, $B, $t0, $D] = $p;
            // Box-Constraints: außerhalb → unendliche Strafe
            if ($A < $loA || $A > $hiA) return INF;
            if ($B < $loB || $B > $hiB) return INF;
            if ($t0 < $loT || $t0 > $hiT) return INF;
            if ($D < $loD || $D > $hiD) return INF;
            $s = 0.0;
            foreach ($x as $i => $xi) {
                $denomBase = $xi - $t0;
                if ($denomBase <= 1e-6) {
                    $pred = $D; // unterhalb θ₀ → Grundlast
                } else {
                    $pred = $A / (1.0 + ($B / $denomBase) ** $C) + $D;
                }
                $s += ($y[$i] - $pred) ** 2;
            }
            return $s;
        };

        // ── Coarse-Grid für Startwerte ──
        $bestP = null; $bestVal = INF;
        $As  = [$maxY * 0.6, $maxY * 0.9, $maxY * 1.2];
        $Bs  = [$maxX * 0.2, $maxX * 0.4, $maxX * 0.6];
        $T0s = [0.0, $maxX * 0.1, $maxX * 0.2];
        $Ds  = [max(0.0, $minY), max(0.0, $minY) + $maxY * 0.1];
        foreach ($As as $A) foreach ($Bs as $B) foreach ($T0s as $t0) foreach ($Ds as $D) {
            $v = $ssr([$A, $B, $t0, $D]);
            if ($v < $bestVal) { $bestVal = $v; $bestP = [$A, $B, $t0, $D]; }
        }
        if ($bestP === null) {
            return ['model' => 'sigmoid', 'valid' => false, 'r2' => 0.0, 'n' => $n];
        }

        // ── Nelder-Mead-Refinement ──
        $opt = $this->nelderMead($ssr, $bestP, 200);
        [$A, $B, $t0, $D] = $opt;

        $ym = array_sum($y) / $n; $ssT = 0.0; $ssRfinal = $ssr($opt);
        foreach ($y as $yi) $ssT += ($yi - $ym) ** 2;
        $r2 = $ssT > 0 ? max(0.0, 1.0 - $ssRfinal / $ssT) : 0.0;

        $valid = $r2 >= 0.5 && $A > 0 && $B > 0;
        return [
            'model' => 'sigmoid',
            'A' => round($A, 3), 'B' => round($B, 3),
            'C' => $C, 'theta0' => round($t0, 3), 'D' => round($D, 3),
            'r2' => round($r2, 4), 'n' => $n, 'valid' => $valid,
            'predict' => sprintf('kWh = %.1f / (1 + (%.1f/(HGT−%.1f))^%.0f) + %.0f',
                $A, $B, $t0, $C, $D),
        ];
    }

    /**
     * Nelder-Mead-Simplex (derivatfrei). Minimal-Implementierung,
     * ausreichend für die 4-Parameter-Sigmoide auf Monatsdaten.
     *
     * @param callable $f      Zielfunktion (array $params): float
     * @param array    $start  Startpunkt
     * @param int      $maxIter
     */
    private function nelderMead(callable $f, array $start, int $maxIter): array
    {
        $nDim = count($start);
        // Simplex aufbauen
        $simplex = [$start];
        for ($i = 0; $i < $nDim; $i++) {
            $pt = $start;
            $pt[$i] += ($pt[$i] != 0.0 ? $pt[$i] * 0.1 : 0.1);
            $simplex[] = $pt;
        }
        $val = array_map($f, $simplex);

        $alpha = 1.0; $gamma = 2.0; $rho = 0.5; $sigma = 0.5;
        for ($iter = 0; $iter < $maxIter; $iter++) {
            // Sortieren nach Funktionswert
            array_multisort($val, $simplex);
            $best = $simplex[0];
            $worst = $simplex[$nDim];
            $secondWorst = $simplex[$nDim - 1];

            // Konvergenz
            if (abs($val[$nDim] - $val[0]) < 1e-9) break;

            // Schwerpunkt ohne den schlechtesten
            $centroid = array_fill(0, $nDim, 0.0);
            for ($i = 0; $i < $nDim; $i++) {
                for ($j = 0; $j < $nDim; $j++) $centroid[$j] += $simplex[$i][$j];
            }
            for ($j = 0; $j < $nDim; $j++) $centroid[$j] /= $nDim;

            // Reflexion
            $refl = [];
            for ($j = 0; $j < $nDim; $j++) $refl[$j] = $centroid[$j] + $alpha * ($centroid[$j] - $worst[$j]);
            $fRefl = $f($refl);

            if ($fRefl < $val[0]) {
                // Expansion
                $exp = [];
                for ($j = 0; $j < $nDim; $j++) $exp[$j] = $centroid[$j] + $gamma * ($refl[$j] - $centroid[$j]);
                $fExp = $f($exp);
                if ($fExp < $fRefl) { $simplex[$nDim] = $exp; $val[$nDim] = $fExp; }
                else { $simplex[$nDim] = $refl; $val[$nDim] = $fRefl; }
            } elseif ($fRefl < $val[$nDim - 1]) {
                $simplex[$nDim] = $refl; $val[$nDim] = $fRefl;
            } else {
                // Kontraktion
                $con = [];
                for ($j = 0; $j < $nDim; $j++) $con[$j] = $centroid[$j] + $rho * ($worst[$j] - $centroid[$j]);
                $fCon = $f($con);
                if ($fCon < $val[$nDim]) { $simplex[$nDim] = $con; $val[$nDim] = $fCon; }
                else {
                    // Schrumpfen
                    for ($i = 1; $i <= $nDim; $i++) {
                        for ($j = 0; $j < $nDim; $j++) {
                            $simplex[$i][$j] = $best[$j] + $sigma * ($simplex[$i][$j] - $best[$j]);
                        }
                        $val[$i] = $f($simplex[$i]);
                    }
                }
            }
        }
        array_multisort($val, $simplex);
        return $simplex[0];
    }

    /**
     * Dispatch nach Modellname. `segmented` respektiert den Settings-Modus:
     * bei `segmented_split_mode = 'auto'` wird der Knickpunkt gefittet
     * (split=null), bei `'fixed'` der konfigurierte feste Wert genutzt.
     */
    public function fit(string $model, array $x, array $y, ?SettingsService $settings = null): array
    {
        return match ($model) {
            'polynomial' => $this->polynomial($x, $y),
            'robust'     => $this->robust($x, $y),
            'segmented'  => $this->segmentedFromSettings($x, $y, $settings),
            'sigmoid'    => $this->sigmoid($x, $y),
            default      => $this->linear($x, $y),
        };
    }

    private function segmentedFromSettings(array $x, array $y, ?SettingsService $settings): array
    {
        $mode = $settings ? (string)$settings->get('segmented_split_mode', 'auto') : 'auto';
        if ($mode === 'fixed') {
            $fixed = $settings ? (float)$settings->get('segmented_fixed_split', 50.0) : 50.0;
            return $this->segmented($x, $y, $fixed);
        }
        return $this->segmented($x, $y, null); // auto
    }

    public function predict(array $reg, float $x): float
    {
        if (!($reg['valid'] ?? false)) return 0.0;
        return match ($reg['model']) {
            'linear', 'robust' => max(0.0, ($reg['a'] ?? 0) * $x + ($reg['b'] ?? 0)),
            'polynomial'       => max(0.0, ($reg['a'] ?? 0) * $x ** 2 + ($reg['b'] ?? 0) * $x + ($reg['c'] ?? 0)),
            'segmented'        => $x >= ($reg['split'] ?? 50)
                                    ? max(0.0, ($reg['heat']['a'] ?? 0) * $x + ($reg['heat']['b'] ?? 0))
                                    : max(0.0, ($reg['base']['a'] ?? 0) * $x + ($reg['base']['b'] ?? 0)),
            'sigmoid'          => $this->sigmoidPredict($reg, $x),
            default            => 0.0,
        };
    }

    private function sigmoidPredict(array $reg, float $x): float
    {
        $A = (float)($reg['A'] ?? 0);
        $B = (float)($reg['B'] ?? 1);
        $C = (float)($reg['C'] ?? 3);
        $t0 = (float)($reg['theta0'] ?? 0);
        $D = (float)($reg['D'] ?? 0);
        $denomBase = $x - $t0;
        if ($denomBase <= 1e-6) return max(0.0, $D);
        return max(0.0, $A / (1.0 + ($B / $denomBase) ** $C) + $D);
    }

    private function solve3x3(array $M, array $V): ?array
    {
        $det = static fn(array $m) =>
            $m[0][0] * ($m[1][1] * $m[2][2] - $m[1][2] * $m[2][1])
            - $m[0][1] * ($m[1][0] * $m[2][2] - $m[1][2] * $m[2][0])
            + $m[0][2] * ($m[1][0] * $m[2][1] - $m[1][1] * $m[2][0]);
        $D = $det($M);
        if (abs($D) < 1e-12) return null;
        $r = [];
        for ($i = 0; $i < 3; $i++) {
            $Mi = $M;
            for ($j = 0; $j < 3; $j++) $Mi[$j][$i] = $V[$j];
            $r[$i] = $det($Mi) / $D;
        }
        return $r;
    }
}
