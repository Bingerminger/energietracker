<?php
declare(strict_types=1);

namespace Energietracker\Support;

/**
 * v3.6.0 (F1031) — kleiner Simplex-Löser ohne Abhängigkeit.
 *
 * Löst lineare Programme der Form
 *
 *   maximiere  c · x
 *   unter      A · x ≤ b,   x ≥ 0,   b ≥ 0
 *
 * mit dem Tableau-Verfahren (Dantzig) und der Regel von Bland: Es tritt die
 * Spalte mit dem kleinsten Index ein, deren reduzierte Kosten negativ sind,
 * und bei gleichem Quotienten verlässt die Zeile mit der kleinsten
 * Basisvariable die Basis. So kann das Verfahren an entarteten Ecken nicht
 * kreisen (Beales Beispiel steht im Test).
 *
 * Mit b ≥ 0 ist der Ursprung zulässig, die Schlupfvariablen bilden die
 * Startbasis — eine erste Phase entfällt. Mehr braucht die Prognose-Werkstatt
 * nicht: Budget- und Obergrenzen sind „≤“-Bedingungen mit nicht negativer
 * rechter Seite. Andere Formen weist der Löser ab, statt falsch zu rechnen.
 *
 * Neben der Lösung liefert er die Schattenpreise (duale Werte) jeder
 * Bedingung: Um wie viel stiege das Optimum je Einheit mehr auf der rechten
 * Seite — beim Budget also „was ein Euro mehr Budget im Jahr bringt“.
 */
final class Simplex
{
    private const EPS = 1e-9;

    /**
     * @param list<float|int>             $c  Zielfunktion (n Werte)
     * @param list<list<float|int>>       $a  Bedingungen (m Zeilen × n Spalten)
     * @param list<float|int>             $b  rechte Seiten (m Werte, alle ≥ 0)
     * @return array{status:string,x:list<float>,value:float,duals:list<float>,iterations:int}
     *         status: optimal | unbounded
     * @throws \InvalidArgumentException bei falscher Form
     */
    public static function maximize(array $c, array $a, array $b, int $maxIterations = 5000): array
    {
        $n = count($c);
        $m = count($b);
        if ($n === 0) throw new \InvalidArgumentException('simplex: no variables');
        if (count($a) !== $m) throw new \InvalidArgumentException('simplex: rows of A and b differ');
        foreach ($a as $i => $row) {
            if (!is_array($row) || count($row) !== $n) throw new \InvalidArgumentException("simplex: row $i has the wrong length");
            if ((float)$b[$i] < 0) throw new \InvalidArgumentException("simplex: b[$i] < 0 needs a first phase");
        }

        // Tableau: m Bedingungszeilen + Zielzeile; Spalten x (n), Schlupf (m), rechte Seite
        $w = $n + $m + 1;
        $t = [];
        for ($i = 0; $i < $m; $i++) {
            $row = array_fill(0, $w, 0.0);
            for ($j = 0; $j < $n; $j++) $row[$j] = (float)$a[$i][$j];
            $row[$n + $i] = 1.0;
            $row[$w - 1] = (float)$b[$i];
            $t[] = $row;
        }
        $z = array_fill(0, $w, 0.0);
        for ($j = 0; $j < $n; $j++) $z[$j] = -(float)$c[$j];
        $basis = [];
        for ($i = 0; $i < $m; $i++) $basis[$i] = $n + $i;

        $iter = 0;
        while (true) {
            // Eintretende Spalte: kleinster Index mit negativen reduzierten Kosten (Bland)
            $enter = -1;
            for ($j = 0; $j < $w - 1; $j++) {
                if ($z[$j] < -self::EPS) { $enter = $j; break; }
            }
            if ($enter < 0) break;   // optimal

            // Quotiententest; bei Gleichstand die kleinste Basisvariable (Bland)
            $leave = -1; $best = INF;
            for ($i = 0; $i < $m; $i++) {
                if ($t[$i][$enter] <= self::EPS) continue;
                $ratio = $t[$i][$w - 1] / $t[$i][$enter];
                if ($ratio < $best - self::EPS || (abs($ratio - $best) <= self::EPS && $leave >= 0 && $basis[$i] < $basis[$leave])) {
                    $best = $ratio; $leave = $i;
                }
            }
            if ($leave < 0) {
                return ['status' => 'unbounded', 'x' => array_fill(0, $n, 0.0), 'value' => INF,
                        'duals' => array_fill(0, $m, 0.0), 'iterations' => $iter];
            }
            if (++$iter > $maxIterations) throw new \RuntimeException('simplex: iteration limit');

            // Pivot
            $p = $t[$leave][$enter];
            for ($k = 0; $k < $w; $k++) $t[$leave][$k] /= $p;
            for ($i = 0; $i < $m; $i++) {
                if ($i === $leave) continue;
                $f = $t[$i][$enter];
                if (abs($f) <= self::EPS) continue;
                for ($k = 0; $k < $w; $k++) $t[$i][$k] -= $f * $t[$leave][$k];
            }
            $f = $z[$enter];
            for ($k = 0; $k < $w; $k++) $z[$k] -= $f * $t[$leave][$k];
            $basis[$leave] = $enter;
        }

        $x = array_fill(0, $n, 0.0);
        foreach ($basis as $i => $var) {
            if ($var < $n) $x[$var] = self::clean($t[$i][$w - 1]);
        }
        $duals = [];
        for ($i = 0; $i < $m; $i++) $duals[] = self::clean($z[$n + $i]);
        return ['status' => 'optimal', 'x' => $x, 'value' => self::clean($z[$w - 1]), 'duals' => $duals, 'iterations' => $iter];
    }

    /** Rundungsrauschen um null wegnehmen (−0,0000000001 → 0). */
    private static function clean(float $v): float
    {
        return abs($v) < 1e-9 ? 0.0 : $v;
    }
}
