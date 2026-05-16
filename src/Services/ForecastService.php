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
 *
 * Cost projection (F-02, v1.1.0)
 * ------------------------------
 * The monthly cost forecast is no longer "last working price × volume".
 * For every forecast month the contract active in that month is resolved,
 * and the working price / base price / advance payment **valid for that
 * month** are looked up from the contract's price history. A price change
 * pflegt in a contract for a future month is therefore reflected in
 * `cost_estimated`. The projection is buchhalterisch vollständig:
 *
 *   cost_estimated    = Arbeitspreis × Menge + Grundpreis − bekannte Boni
 *   advance_estimated = der für den Monat gültige Abschlag (oder null)
 *   balance_running   = kumulierte (cost_estimated − advance_estimated)
 *
 * Future bonuses are NOT extrapolated — only bonuses explicitly pflegt in
 * the contract with a credit_date in the forecast window count.
 *
 * Limitation: for Wasser with a Schmutzwasser `separater_zaehler` basis the
 * forecast uses the Trinkwasser volume as the Schmutzwasser basis (the
 * separate meter is not itself forecast). The historical view computes the
 * separate volume correctly; only the forward projection simplifies.
 */
final class ForecastService
{
    public function __construct(
        private ConsumptionService $consumption,
        private RegressionService $regression,
        private SettingsService $settings,
        private ContractService $contracts,
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
            $reg = $this->regression->fit($model, $rx, $ry, $this->settings);
        }

        // Fallback working price — the last month that carries one. Used only
        // when the active contract has no working price pflegt for a forecast
        // month (or there is no contract at all).
        $fallbackPrice = null;
        for ($i = count($monthly) - 1; $i >= 0; $i--) {
            if (!empty($monthly[$i]['working_price_ct'])) {
                $fallbackPrice = (float)$monthly[$i]['working_price_ct'];
                break;
            }
            if (!empty($monthly[$i]['price_cents'])) {
                $fallbackPrice = (float)$monthly[$i]['price_cents'];
                break;
            }
        }
        if ($fallbackPrice === null) {
            foreach ($monthly as $m) {
                $costPerUnit = ($m[$valueField] ?? 0) > 0
                    ? ($m['kwh_cost'] ?? $m['cost'] ?? 0) / $m[$valueField] * 100
                    : null;
                if ($costPerUnit !== null) $fallbackPrice = $costPerUnit;
            }
        }
        $fallbackPrice = ($fallbackPrice ?? 0.0) * $priceFactor;

        // Contracts of this meter — for the per-month price/advance lookup.
        $contracts = $this->contracts->list($utility, (string)($meter['id'] ?? ''));

        // Build forecast months
        $start = new \DateTime((end($monthly)['ym'] ?? date('Y-m')) . '-01');
        $start->modify('first day of next month');
        $blendW = $reg && ($reg['valid'] ?? false) ? min($blendMax, (float)$reg['r2']) : 0.0;

        $forecast = [];
        $runningBalance = 0.0;
        for ($i = 0; $i < $fcMonths; $i++) {
            $ym = $start->format('Y-m');
            $mn = (int)$start->format('n');
            $yr = (int)$start->format('Y');
            $hdd = $avgHdd[$mn] ?? 0.0;
            // Apply temp offset (positive = warmer = less HGT). Scaled by the
            // actual number of days in the month, not a flat 30.
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $mn, 1, $yr));
            $hdd = max(0.0, $hdd - $tempOffset * $daysInMonth);

            $seasonalVal = $avg[$mn] ?? 0.0;
            $regressionVal = $reg ? $this->regression->predict($reg, $hdd) : $seasonalVal;
            $blended = $blendW * $regressionVal + (1 - $blendW) * $seasonalVal;

            // F-02: full contract-aware monthly finance projection.
            $fin = $this->projectMonthFinances(
                $utility, $contracts, $yr, $mn, $blended, $fallbackPrice, $priceFactor
            );
            $runningBalance += $fin['cost'] - ($fin['advance'] ?? 0.0);

            $forecast[] = [
                'ym'                => $ym,
                'year'              => $yr,
                'month'             => $mn,
                $valueField         => round($blended, 1),
                'hdd_estimated'     => round($hdd, 1),
                'cost_estimated'    => round($fin['cost'], 2),
                'advance_estimated' => $fin['advance'] !== null ? round($fin['advance'], 2) : null,
                'balance_running'   => round($runningBalance, 2),
                'working_price_ct'  => $fin['working_price_ct'] !== null
                                       ? round($fin['working_price_ct'], 4) : null,
                'contract_id'       => $fin['contract_id'],
                'method'            => $reg ? sprintf('blend(reg=%.2f, seasonal=%.2f)', $blendW, 1 - $blendW)
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
            'last_price_ct'=> round($fallbackPrice, 4),
            'options'      => [
                'temp_offset'   => $tempOffset,
                'price_factor'  => $priceFactor,
                'forecast_months' => $fcMonths,
            ],
        ];
    }

    /**
     * Project the full monthly finances for one forecast month.
     *
     * Resolves the contract active in (year, month) and looks up the price /
     * base / advance valid for that month. Returns:
     *   cost             — Arbeitspreis × Menge + Grundpreis − bekannte Boni
     *   advance          — der gültige Abschlag, oder null wenn keiner pflegt
     *   working_price_ct — der angesetzte Arbeitspreis (Headline-Tarif)
     *   contract_id      — id des aktiven Vertrags, oder null
     *
     * @param array<int,array<string,mixed>> $contracts
     * @return array{cost:float,advance:?float,working_price_ct:?float,contract_id:?string}
     */
    private function projectMonthFinances(
        string $utility,
        array $contracts,
        int $year,
        int $month,
        float $volume,
        float $fallbackPriceCt,
        float $priceFactor
    ): array {
        $first = sprintf('%04d-%02d-01', $year, $month);
        $c = $this->contracts->findActiveForDate($contracts, $first);

        if (!$c) {
            // No contract — fall back to the last known unit price.
            return [
                'cost'             => $volume * $fallbackPriceCt / 100.0,
                'advance'          => null,
                'working_price_ct' => $fallbackPriceCt,
                'contract_id'      => null,
            ];
        }

        $bonus = $this->contracts->bonusForMonth($c, $year, $month);

        if ($utility === 'wasser') {
            $tw = $c['trinkwasser']         ?? [];
            $sw = $c['schmutzwasser']       ?? [];
            $nw = $c['niederschlagswasser'] ?? [];

            $twWp = $this->contracts->valueValidOn($tw['working_prices'] ?? [], 'ct_per_m3', $year, $month);
            $twBp = $this->contracts->valueValidOn($tw['base_prices']    ?? [], 'eur_per_month', $year, $month);
            $swWp = $this->contracts->valueValidOn($sw['working_prices'] ?? [], 'ct_per_m3', $year, $month);
            // Niederschlagswasser rates carry two fields per entry; valueValidOn
            // walks the same sorted list for each, so both resolve to the same
            // stichtag entry.
            $nwRate = $this->contracts->valueValidOn($nw['rates'] ?? [], 'eur_per_m2_year', $year, $month);
            $nwArea = $this->contracts->valueValidOn($nw['rates'] ?? [], 'versiegelte_flaeche_m2', $year, $month);

            $twPrice   = $twWp !== null ? (float)$twWp * $priceFactor : $fallbackPriceCt;
            $twWorking = $volume * $twPrice / 100.0;
            $twBase    = $twBp !== null ? (float)$twBp : 0.0;
            // See class docblock: the separate-meter volume is not forecast;
            // the Trinkwasser volume is used as the Schmutzwasser basis here.
            $swCost = $swWp !== null ? $volume * (float)$swWp * $priceFactor / 100.0 : 0.0;
            $nwMonthly = ($nwRate !== null && $nwArea !== null)
                ? (float)$nwRate * (float)$nwArea / 12.0
                : 0.0;

            $cost = $twWorking + $twBase + $swCost + $nwMonthly - $bonus;
            $ap   = $this->contracts->valueValidOn($c['advance_payments'] ?? [], 'amount_eur', $year, $month);

            return [
                'cost'             => $cost,
                'advance'          => $ap !== null ? (float)$ap : null,
                'working_price_ct' => $twWp !== null ? $twPrice : null,
                'contract_id'      => $c['id'] ?? null,
            ];
        }

        // Gas / Strom — flat shape.
        $wp = $this->contracts->valueValidOn($c['working_prices'] ?? [], 'ct_per_kwh', $year, $month);
        $bp = $this->contracts->valueValidOn($c['base_prices']    ?? [], 'eur_per_month', $year, $month);
        $ap = $this->contracts->valueValidOn($c['advance_payments'] ?? [], 'amount_eur', $year, $month);

        $price       = $wp !== null ? (float)$wp * $priceFactor : $fallbackPriceCt;
        $workingCost = $volume * $price / 100.0;
        $base        = $bp !== null ? (float)$bp : 0.0;
        $cost        = $workingCost + $base - $bonus;

        return [
            'cost'             => $cost,
            'advance'          => $ap !== null ? (float)$ap : null,
            'working_price_ct' => $wp !== null ? $price : $fallbackPriceCt,
            'contract_id'      => $c['id'] ?? null,
        ];
    }
}
