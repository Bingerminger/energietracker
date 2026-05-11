<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * 12-month forecast per meter.
 *
 * For HGT-relevant utilities (gas): blend of HGT regression (using future
 * seasonal HGT means) and seasonal kWh profile, weighted by regression R².
 *
 * For non-HGT utilities (strom, wasser): pure seasonal profile —
 * the regression step is skipped.
 */
final class ForecastService
{
    public function __construct(
        private ConsumptionService $consumption,
        private RegressionService $regression,
        private SettingsService $settings,
    ) {}

    public function forMeter(string $utility, array $meter, array $opts = []): array
    {
        $u = Utilities::get($utility);
        $monthly = $this->consumption->forMeter($utility, $meter);
        if (count($monthly) < 6) {
            return ['valid' => false, 'reason' => 'Zu wenig historische Daten (< 6 Monate)'];
        }

        $valueField = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        $fcMonths   = (int)($opts['forecast_months'] ?? $this->settings->get('forecast_months', 12));
        $minDays    = (int)$this->settings->get('min_days_period', 20);
        $blendMax   = (float)$this->settings->get('blend_max', 0.80);
        $tempOffset = (float)($opts['temp_offset'] ?? 0.0);
        $priceFactor= (float)($opts['price_factor'] ?? 1.0);

        // Seasonal averages by month
        $seasonal = array_fill(1, 12, []);
        $seasonalHdd = array_fill(1, 12, []);
        foreach ($monthly as $m) {
            if (($m['days'] ?? 0) < $minDays) continue;
            $seasonal[(int)$m['month']][] = (float)($m[$valueField] ?? 0);
            if (!empty($u['hgt_relevant']) && ($m['hdd'] ?? 0) > 0) {
                $seasonalHdd[(int)$m['month']][] = (float)$m['hdd'];
            }
        }
        $avg = array_map(
            fn($arr) => count($arr) ? array_sum($arr) / count($arr) : 0.0,
            $seasonal
        );
        $avgHdd = array_map(
            fn($arr) => count($arr) ? array_sum($arr) / count($arr) : 0.0,
            $seasonalHdd
        );

        // Regression fit (only for HGT-relevant)
        $reg = null;
        if (!empty($u['hgt_relevant'])) {
            $rx = []; $ry = [];
            foreach ($monthly as $m) {
                if (($m['days'] ?? 0) >= $minDays && ($m['hdd'] ?? 0) > $this->settings->get('min_hdd_regression', 5.0)) {
                    $rx[] = (float)$m['hdd'];
                    $ry[] = (float)($m[$valueField] ?? 0);
                }
            }
            $model = (string)($opts['model'] ?? $this->settings->get('forecast_model', 'linear'));
            $reg = $this->regression->fit($model, $rx, $ry);
        }

        // Last working price
        $lastPrice = null;
        for ($i = count($monthly) - 1; $i >= 0; $i--) {
            if (!empty($monthly[$i]['working_price_ct'])) {
                $lastPrice = (float)$monthly[$i]['working_price_ct'];
                break;
            }
            if (!empty($monthly[$i]['price_cents'])) {
                $lastPrice = (float)$monthly[$i]['price_cents'];
                break;
            }
        }
        if ($lastPrice === null) {
            foreach ($monthly as $m) {
                $costPerUnit = ($m[$valueField] ?? 0) > 0
                    ? ($m['kwh_cost'] ?? $m['cost'] ?? 0) / $m[$valueField] * 100
                    : null;
                if ($costPerUnit !== null) $lastPrice = $costPerUnit;
            }
        }
        $lastPrice = $lastPrice ?? 0.0;
        $lastPrice *= $priceFactor;

        // Build forecast months
        $start = new \DateTime((end($monthly)['ym'] ?? date('Y-m')) . '-01');
        $start->modify('first day of next month');
        $blendW = $reg && ($reg['valid'] ?? false) ? min($blendMax, (float)$reg['r2']) : 0.0;

        $forecast = [];
        for ($i = 0; $i < $fcMonths; $i++) {
            $ym = $start->format('Y-m');
            $mn = (int)$start->format('n');
            $hdd = $avgHdd[$mn] ?? 0.0;
            // Apply temp offset (positive = warmer = less HGT)
            $hdd = max(0.0, $hdd - $tempOffset * 30); // rough: 1°C × ~30 days

            $seasonalVal = $avg[$mn] ?? 0.0;
            $regressionVal = $reg ? $this->regression->predict($reg, $hdd) : $seasonalVal;
            $blended = $blendW * $regressionVal + (1 - $blendW) * $seasonalVal;

            $forecast[] = [
                'ym'           => $ym,
                'year'         => (int)$start->format('Y'),
                'month'        => $mn,
                $valueField    => round($blended, 1),
                'hdd_estimated'=> round($hdd, 1),
                'cost_estimated' => round($blended * $lastPrice / 100.0, 2),
                'method'       => $reg ? sprintf('blend(reg=%.2f, seasonal=%.2f)', $blendW, 1 - $blendW)
                                       : 'seasonal_only',
            ];
            $start->modify('first day of next month');
        }

        return [
            'valid'        => true,
            'utility'      => $u['key'],
            'meter_id'     => $meter['id'],
            'historical'   => $monthly,
            'forecast'     => $forecast,
            'regression'   => $reg,
            'blend_weight' => round($blendW, 4),
            'last_price_ct'=> round($lastPrice, 4),
            'options'      => [
                'temp_offset'   => $tempOffset,
                'price_factor'  => $priceFactor,
                'forecast_months' => $fcMonths,
            ],
        ];
    }
}
