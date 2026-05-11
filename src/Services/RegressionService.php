<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * HGT-vs-consumption regression models.
 *
 * Used only for HGT-relevant utilities (gas). For others (strom, wasser),
 * the regression is bypassed and the forecast falls back to seasonal profile only.
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

    /** Two-segment linear: heating season vs. shoulder/summer */
    public function segmented(array $x, array $y, float $split = 50.0): array
    {
        $n = count($x);
        if ($n < 6) return ['model' => 'segmented', 'valid' => false, 'r2' => 0.0, 'n' => $n];
        $xH = $yH = $xB = $yB = [];
        foreach ($x as $i => $xi) {
            if ($xi >= $split) { $xH[] = $xi; $yH[] = $y[$i]; }
            else { $xB[] = $xi; $yB[] = $y[$i]; }
        }
        if (count($xH) < 3 || count($xB) < 3) {
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
            'model' => 'segmented', 'split' => $split,
            'heat' => $heat, 'base' => $base,
            'r2' => round($r2, 4), 'n' => $n, 'valid' => true,
            'predict' => sprintf('HGT≥%.0f: %.2f×HGT+%.0f · HGT<%.0f: %.2f×HGT+%.0f',
                $split, $heat['a'], $heat['b'], $split, $base['a'], $base['b']),
        ];
    }

    public function fit(string $model, array $x, array $y): array
    {
        return match ($model) {
            'polynomial' => $this->polynomial($x, $y),
            'robust'     => $this->robust($x, $y),
            'segmented'  => $this->segmented($x, $y),
            default      => $this->linear($x, $y),
        };
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
            default            => 0.0,
        };
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
