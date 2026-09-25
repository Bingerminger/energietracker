<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * Anomaly detection on monthly consumption.
 *
 * A point is anomalous when |z-score| >= threshold (default 2.0σ).
 *
 * v2.8.0 (Review CALC-06) — die Erkennung meldete auf sauberen Daten jeden
 * Sommer und übersah echte Ausreißer:
 *   - Heizarten: Monate mit HGT ≤ 5 bekamen die Erwartung 0 — der ganze
 *     Warmwasserverbrauch wurde zum „Ausreißer". Jetzt kommt die Erwartung
 *     für jeden Monat aus dem Heizmodell des Zählers
 *     (`expected_heat` = a × HGT + c × Tage, ConsumptionService::heatModel):
 *     im Sommer ist das die Grundlast.
 *   - Übrige Arten: Das Saisonmittel enthielt den geprüften Monat selbst; ein
 *     +60-%-März bei einem Jahr Historie verglich sich mit sich. Jetzt je Tag
 *     gerechnet und ohne den geprüften Monat (leave-one-out): derselbe
 *     Kalendermonat anderer Jahre, im ersten Jahr die beiden Nachbarmonate.
 *   - Streuung: Median und MAD statt Mittel und Standardabweichung — ein
 *     einzelner Ausreißer bläht die Standardabweichung auf und versteckt sich
 *     darin, und die Residuen waren nicht zentriert.
 * Lieferarten (Heizöl, Pellets) liefern keine Anomalien: Ihre Monatswerte
 * sind aus Lieferungen nach Gradtagen verteilt, jede „Abweichung" wäre ein
 * Artefakt der Verteilung.
 */
final class AnomalyService
{
    public function __construct(
        private RegressionService $regression,
        private SettingsService $settings,
    ) {}

    /**
     * Untergrenze: unter so vielen verwertbaren Monaten wird gar nicht erst
     * gerechnet. Öffentlich, damit der F1011-Hinweis dieselbe Zahl nennt, die
     * hier wirklich greift — statt sie ein zweites Mal hinzuschreiben.
     */
    public const MIN_MONTHS = 5;

    /**
     * v2.8.0 — Untergrenze der Streuung: 10 % der Erwartung, mindestens aber
     * 10 % eines typischen Monats des Zählers. Bei sehr gleichmäßigen Daten
     * wird die robuste Streuung winzig, und schon +2 % wären „2σ"; bei einem
     * Gartenzähler wären 0,3 statt 0,6 m³ im April „−50 %". Ein Monat
     * schwankt im Alltag um einige Prozent — gemeldet wird erst, was darüber
     * hinausgeht (bei 2σ ab rund 20 % eines typischen Monats).
     */
    public const RELATIVE_NOISE_FLOOR = 0.10;

    /** Streuungs-Untergrenze für einen Monat (s. RELATIVE_NOISE_FLOOR). */
    public static function noiseFloor(float $expected, float $typical): float
    {
        return self::RELATIVE_NOISE_FLOOR * max($expected, $typical, 0.0);
    }

    /** Typischer Monat: Median der positiven Erwartungswerte. */
    public static function typical(array $expected): float
    {
        $pos = array_values(array_filter(array_map('floatval', $expected), fn($v) => $v > 0));
        return $pos ? self::median($pos) : 0.0;
    }

    public function detect(string $utility, array $monthly): array
    {
        if (Utilities::isDelivery($utility)) return [];
        $u = Utilities::get($utility);
        $threshold = (float)$this->settings->get('anomaly_threshold', 2.0);
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $valueField = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        $hgt = !empty($u['hgt_relevant']);

        $valid = array_values(array_filter($monthly, fn($m) =>
            ($m['days'] ?? 0) >= $minDays
            // v1.6.1 — Issue #13: Wechsel-Monate aus z-Score-Erkennung
            // ausschließen. Ein Zählertausch ist ein erklärlicher Sonder-
            // effekt (Bridging-Übergang) und sollte nicht als statistische
            // Anomalie markiert werden, sonst poppt jedes Wechsel-Datum
            // als „⚠️ Anomalie" im Dashboard auf.
            && empty($m['device_swap'])
            // v1.4.0 — F1011: Monate vor einer Zäsur gehören zu einem anderen
            // Gebäude. Bleiben sie drin, ist der Erwartungswert ein Mittel aus
            // zwei Zuständen — und jeder Monat danach meldet dauerhaft
            // „unter dem Mittel", während echte Ausreißer untergehen.
            && empty($m['pre_baseline'])
            // v2.8.0 — Heizarten brauchen die Erwartung aus dem Heizmodell
            && (!$hgt || ($m['expected_heat'] ?? null) !== null)
        ));
        if (count($valid) < self::MIN_MONTHS) return [];

        $expected = $hgt
            ? array_map(fn($m) => (float)$m['expected_heat'], $valid)
            : $this->seasonalExpectations($valid, $valueField);

        $residuals = [];
        $idx = [];
        foreach ($valid as $i => $m) {
            if ($expected[$i] === null) continue;
            $residuals[$i] = (float)($m[$valueField] ?? 0) - $expected[$i];
            $idx[] = $i;
        }
        if (count($residuals) < self::MIN_MONTHS) return [];

        [$center, $scale] = self::robustScale(array_values($residuals));
        $typical = self::typical(array_filter($expected, fn($e) => $e !== null));
        if (max($scale, self::noiseFloor(0.0, $typical)) < 1e-6) return [];

        $anomalies = [];
        foreach ($idx as $i) {
            $m = $valid[$i];
            $z = ($residuals[$i] - $center) / max($scale, self::noiseFloor((float)$expected[$i], $typical));
            if (abs($z) < $threshold) continue;
            $anomalies[] = [
                'ym'        => $m['ym'],
                'value'     => round((float)($m[$valueField] ?? 0), 1),
                'expected'  => round($expected[$i], 1),
                'deviation' => round($residuals[$i], 1),
                'z_score'   => round($z, 2),
                'percent'   => $expected[$i] > 0 ? round($residuals[$i] / $expected[$i] * 100, 1) : 0,
                'avg_temp'  => $m['avg_temp'] ?? null,
                'hdd'       => $m['hdd'] ?? null,
                'kind'      => $z > 0 ? 'high' : 'low',
            ];
        }
        return $anomalies;
    }

    /**
     * Erwartung je Monat ohne diesen Monat selbst: Tagesrate desselben
     * Kalendermonats in anderen Jahren; gibt es den nicht (erstes Jahr), die
     * der beiden Nachbarmonate — Verbrauch ändert sich über das Jahr stetig,
     * ein Gesamtmittel dagegen machte jeden Wintermonat zum Ausreißer.
     *
     * @return list<?float>
     */
    private function seasonalExpectations(array $valid, string $valueField): array
    {
        $rates = [];
        foreach ($valid as $i => $m) {
            $days = max(1, (int)($m['days'] ?? 0));
            $rates[$i] = [(int)($m['month'] ?? 0), (float)($m[$valueField] ?? 0) / $days];
        }
        $out = [];
        foreach ($valid as $i => $m) {
            $mon = $rates[$i][0];
            $prev = $mon === 1 ? 12 : $mon - 1;
            $next = $mon === 12 ? 1 : $mon + 1;
            $same = []; $near = [];
            foreach ($rates as $j => [$mj, $rate]) {
                if ($j === $i) continue;
                if ($mj === $mon) $same[] = $rate;
                if ($mj === $prev || $mj === $next) $near[] = $rate;
            }
            $pool = $same ?: $near;
            // Median statt Mittel: Ein Jahr mit anderem Niveau (Haushalt
            // verkleinert, neue Geräte) soll die übrigen Jahre nicht zu
            // „Ausreißern" machen.
            $out[$i] = $pool ? self::median($pool) * (int)($m['days'] ?? 0) : null;
        }
        return $out;
    }

    /**
     * Median und MAD (skaliert auf die Standardabweichung einer Normal-
     * verteilung). Fällt bei vielen gleichen Werten die MAD auf 0, die
     * Standardabweichung.
     *
     * @param list<float> $vals
     * @return array{0:float,1:float} [Zentrum, Streuung]
     */
    public static function robustScale(array $vals): array
    {
        $n = count($vals);
        if ($n === 0) return [0.0, 0.0];
        $median = self::median($vals);
        $mad = self::median(array_map(fn($v) => abs($v - $median), $vals)) * 1.4826;
        if ($mad < 1e-6) {
            $mean = array_sum($vals) / $n;
            $mad = sqrt(array_sum(array_map(fn($v) => ($v - $mean) ** 2, $vals)) / $n);
        }
        return [$median, $mad];
    }

    /** @param list<float> $vals */
    private static function median(array $vals): float
    {
        sort($vals);
        $n = count($vals);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float)$vals[$mid] : ((float)$vals[$mid - 1] + (float)$vals[$mid]) / 2;
    }
}
