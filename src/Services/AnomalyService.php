<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * Anomaly detection on monthly consumption.
 *
 * For HGT-relevant utilities: residuals against linear HGT regression.
 * For non-HGT: residuals against seasonal monthly mean.
 *
 * A point is anomalous when |z-score| >= threshold (default 2.0σ).
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

    public function detect(string $utility, array $monthly): array
    {
        $u = Utilities::get($utility);
        $threshold = (float)$this->settings->get('anomaly_threshold', 2.0);
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $valueField = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';

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
        ));
        if (count($valid) < self::MIN_MONTHS) return [];

        // Compute expected values
        $expected = [];
        if (!empty($u['hgt_relevant'])) {
            $x = []; $y = [];
            foreach ($valid as $m) {
                if (($m['hdd'] ?? 0) > 5) {
                    $x[] = (float)$m['hdd'];
                    $y[] = (float)($m[$valueField] ?? 0);
                }
            }
            $reg = $this->regression->linear($x, $y);
            foreach ($valid as $m) {
                $expected[] = ($m['hdd'] ?? 0) > 5
                    ? $this->regression->predict($reg, (float)$m['hdd'])
                    : 0.0;
            }
        } else {
            // Seasonal mean per month
            $byMonth = array_fill(1, 12, []);
            foreach ($valid as $m) {
                $byMonth[(int)$m['month']][] = (float)($m[$valueField] ?? 0);
            }
            $means = array_map(
                fn($arr) => count($arr) ? array_sum($arr) / count($arr) : 0.0,
                $byMonth
            );
            foreach ($valid as $m) {
                $expected[] = $means[(int)$m['month']] ?? 0.0;
            }
        }

        // Residuals
        $residuals = [];
        foreach ($valid as $i => $m) {
            $residuals[] = (float)($m[$valueField] ?? 0) - $expected[$i];
        }
        $mean = array_sum($residuals) / count($residuals);
        $var = array_sum(array_map(fn($r) => ($r - $mean) ** 2, $residuals)) / count($residuals);
        $std = sqrt($var);
        if ($std < 1e-6) return [];

        $anomalies = [];
        foreach ($valid as $i => $m) {
            $z = $residuals[$i] / $std;
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
}
