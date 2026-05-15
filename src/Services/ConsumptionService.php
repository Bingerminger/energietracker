<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;

/**
 * Monthly consumption aggregation.
 *
 * F2 (Zählertausch) handling:
 *   When two consecutive readings sit on DIFFERENT devices of the same meter,
 *   the consumption between them is split:
 *     part_a = old_device.final_counter - prev_reading.counter
 *     part_b = curr_reading.counter   - new_device.initial_counter
 *     total  = (part_a + part_b) × conversion_factor
 *   This is distributed across days as if it were one continuous interval.
 *
 * F3 (multiple meters) handling:
 *   Each meter is aggregated INDEPENDENTLY. The "utility total" sums across
 *   active meters of the same utility. Each meter can have its own contract.
 *
 * Output is per-month, per-meter consumption plus a utility-aggregated view.
 */
final class ConsumptionService
{
    /**
     * Meter ids currently being computed — recursion guard for the
     * Schmutzwasser `separater_zaehler` lookup (a meter referencing another
     * meter as its waste-water measurement basis). Prevents infinite
     * recursion if two meters reference each other.
     *
     * @var string[]
     */
    private array $meterComputeStack = [];

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private ReadingService $readings,
        private ContractService $contracts,
        private SettingsService $settings,
    ) {}

    /**
     * Compute monthly aggregates for all meters of a utility.
     *
     * @return array{
     *   meters: array<int,array{meter:array,monthly:array<int,array>}>,
     *   monthly_total: array<int,array>
     * }
     */
    public function forUtility(string $utility, ?float $hddBaseOverride = null): array
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException('Unbekannte Verbrauchsart: ' . $utility);
        }
        $u = Utilities::get($utility);
        $perMeter = [];

        foreach ($this->meters->list($utility) as $meter) {
            $monthly = $this->forMeter($utility, $meter, $hddBaseOverride);
            $perMeter[] = ['meter' => $meter, 'monthly' => $monthly];
        }

        // Aggregate across meters by YM
        $totals = [];
        foreach ($perMeter as $entry) {
            foreach ($entry['monthly'] as $m) {
                $ym = $m['ym'];
                if (!isset($totals[$ym])) {
                    $totals[$ym] = [
                        'ym' => $ym, 'year' => $m['year'], 'month' => $m['month'],
                        'kwh' => 0.0, 'm3' => 0.0, 'cost' => 0.0, 'days' => 0,
                        'avg_temp' => $m['avg_temp'], 'min_temp' => $m['min_temp'],
                        'max_temp' => $m['max_temp'], 'hdd' => $m['hdd'],
                    ];
                }
                $totals[$ym]['kwh']  += (float)($m['kwh']  ?? 0);
                $totals[$ym]['m3']   += (float)($m['m3']   ?? 0);
                $totals[$ym]['cost'] += (float)($m['cost'] ?? 0);
                $totals[$ym]['days'] = max($totals[$ym]['days'], (int)($m['days'] ?? 0));
            }
        }
        ksort($totals);
        $totalsList = array_values($totals);

        // Add moving averages to the totals
        $totalsList = $this->addMovingAverages($totalsList, $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3');

        return [
            'utility'      => $u,
            'meters'       => $perMeter,
            'monthly_total'=> $totalsList,
        ];
    }

    /**
     * Per-contract aggregation for the Saldo / Vertragstabelle UI.
     *
     * For every contract of the given meter, compute:
     *   - actual_kwh, actual_kwh_cost, actual_base_total, actual_bonus_total, actual_cost
     *   - advance_paid (monthly advances summed across actual months)
     *   - current_balance  = actual_cost − advance_paid  (positive = Nachzahlung)
     *   - projected_end_balance = balance extrapolated until contract end
     *   - verdict ∈ { Nachzahlung | Erstattung | Ausgeglichen }
     *   - current_working_price_ct / current_base_price_eur / current_advance_amount
     *     (the values valid today, for the active contract)
     *   - is_current / is_past / is_future / is_open_ended / effective_end
     *
     * Future months past `today` are projected using the average kwh/HGT of the
     * actual months in this contract (linear with seasonality from HDD).
     */
    public function contractStatus(string $utility, array $meter): array
    {
        $contracts = $this->contracts->list($utility, $meter['id']);
        $monthly   = $this->forMeter($utility, $meter);
        $today     = date('Y-m-d');

        // Index monthly by (contract_id, ym)
        $byContract = [];
        foreach ($monthly as $m) {
            $cid = $m['contract_id'] ?? null;
            if (!$cid) continue;
            $byContract[$cid][] = $m;
        }

        $out = [];
        foreach ($contracts as $c) {
            $cid     = $c['id'];
            $mList   = $byContract[$cid] ?? [];
            $isCur   = ($c['start'] ?? '9999') <= $today && (empty($c['end']) || $c['end'] >= $today);
            $isPast  = !empty($c['end']) && $c['end'] < $today;
            $isFut   = ($c['start'] ?? '9999') > $today;
            $isOpen  = empty($c['end']);
            // F-03: a contract with a pflegtem Ende ends on that date. An open
            // contract (end = null) is projected to the next billing-cycle
            // anchor of its utility (Settings: billing_cycle_anchor_<utility>),
            // not the arbitrary "today + 12 months" of v1.0.x.
            $effEnd  = !empty($c['end'])
                ? $c['end']
                : $this->nextBillingAnchor($utility, $today);

            // Aggregate over the months we actually have for this contract
            $actualKwh   = 0.0; $actualCost = 0.0; $actualKwhCost = 0.0;
            $actualBase  = 0.0; $actualBonus = 0.0; $advancePaid = 0.0;
            // Water component aggregates (v1.0.3)
            $twWorking = 0.0; $twBase = 0.0; $twTotal = 0.0;
            $swTotal   = 0.0;
            $nwTotal   = 0.0;
            $actualM3  = 0.0;
            foreach ($mList as $m) {
                $actualKwh     += (float)($m['kwh'] ?? 0);
                $actualM3      += (float)($m['m3']  ?? 0);
                $actualCost    += (float)($m['cost'] ?? 0);
                $actualKwhCost += (float)($m['kwh_cost'] ?? 0);
                $actualBase    += (float)($m['base_price_eur'] ?? 0);
                $actualBonus   += (float)($m['bonus_eur'] ?? 0);
                $advancePaid   += (float)($m['advance_eur'] ?? 0);
                if ($utility === 'wasser') {
                    $tw = $m['trinkwasser']         ?? null;
                    $sw = $m['schmutzwasser']       ?? null;
                    $nw = $m['niederschlagswasser'] ?? null;
                    if (is_array($tw)) {
                        $twWorking += (float)($tw['working_cost'] ?? 0);
                        $twBase    += (float)($tw['base_cost']    ?? 0);
                        $twTotal   += (float)($tw['total']        ?? 0);
                    }
                    if (is_array($sw)) $swTotal += (float)($sw['total'] ?? 0);
                    if (is_array($nw)) $nwTotal += (float)($nw['total'] ?? 0);
                }
            }
            $monthsActual = count($mList);
            $currentBalance = round($actualCost - $advancePaid, 2);

            // Current tariff values (those valid today, for the active contract).
            [$y, $mn] = [(int)date('Y'), (int)date('n')];
            if ($utility === 'wasser') {
                $tw = $c['trinkwasser']         ?? [];
                $sw = $c['schmutzwasser']       ?? [];
                $nw = $c['niederschlagswasser'] ?? [];
                $curWp = $this->contracts->valueValidOn($tw['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
                $curBp = $this->contracts->valueValidOn($tw['base_prices']    ?? [], 'eur_per_month', $y, $mn);
                $curSwWp = $this->contracts->valueValidOn($sw['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
                $curNwRate = $this->valueValidOnGeneric($nw['rates'] ?? [], 'eur_per_m2_year',        $y, $mn);
                $curNwArea = $this->valueValidOnGeneric($nw['rates'] ?? [], 'versiegelte_flaeche_m2', $y, $mn);
            } else {
                $curWp = $this->contracts->valueValidOn($c['working_prices'] ?? [], 'ct_per_kwh', $y, $mn);
                $curBp = $this->contracts->valueValidOn($c['base_prices']    ?? [], 'eur_per_month', $y, $mn);
                $curSwWp = $curNwRate = $curNwArea = null;
            }
            $curAp = $this->contracts->valueValidOn($c['advance_payments'] ?? [], 'amount_eur', $y, $mn);

            // Project balance to contract end:
            //   project remaining months at the current avg cost / monthly advance.
            //   for past contracts: projected = current.
            $projected = $currentBalance;
            if ($isCur || $isFut) {
                $monthsToEnd = max(0, $this->monthsBetween($today, $effEnd));
                $avgMonthlyCost = $monthsActual > 0 ? $actualCost / $monthsActual : 0.0;
                $monthlyAdvance = (float)($curAp ?? 0);
                $delta = ($avgMonthlyCost - $monthlyAdvance) * $monthsToEnd;
                $projected = round($currentBalance + $delta, 2);
            }

            $verdict = $projected > 5 ? 'Nachzahlung'
                     : ($projected < -5 ? 'Erstattung' : 'Ausgeglichen');

            // F-05: contract-end reminder. Only meaningful for contracts with a
            // pflegtem Ende that is still in the future — an open contract has
            // no real end to remind about. days_until_end is signed (negative
            // = end already passed). remind_stage ∈ {0,1,2,3}: 0 = no reminder,
            // 1 = within the earliest threshold, 3 = within the last.
            $daysUntilEnd = null;
            $shouldRemind = false;
            $remindStage  = 0;
            if (!empty($c['end'])) {
                try {
                    $diff = (new \DateTime($today))->diff(new \DateTime($c['end']));
                    $daysUntilEnd = (int)$diff->days * ($c['end'] < $today ? -1 : 1);
                } catch (\Exception) {
                    $daysUntilEnd = null;
                }
                if ($daysUntilEnd !== null && $daysUntilEnd >= 0) {
                    $r1 = (int)$this->settings->get('contract_remind_days_1', 90);
                    $r2 = (int)$this->settings->get('contract_remind_days_2', 30);
                    $r3 = (int)$this->settings->get('contract_remind_days_3', 1);
                    if ($daysUntilEnd <= $r3)      { $remindStage = 3; }
                    elseif ($daysUntilEnd <= $r2)  { $remindStage = 2; }
                    elseif ($daysUntilEnd <= $r1)  { $remindStage = 1; }
                    $shouldRemind = $remindStage > 0;
                }
            }

            $row = [
                'contract_id'                 => $cid,
                'provider'                    => $c['provider']    ?? '',
                'tariff_name'                 => $c['tariff_name'] ?? '',
                'start'                       => $c['start']       ?? null,
                'end'                         => $c['end']         ?? null,
                'effective_end'               => $effEnd,
                'is_current'                  => $isCur,
                'is_past'                     => $isPast,
                'is_future'                   => $isFut,
                'is_open_ended'               => $isOpen,
                'current_working_price_ct'    => $curWp,
                'current_base_price_eur'      => $curBp,
                'current_advance_amount'      => $curAp,
                'months_actual'               => $monthsActual,
                'actual_kwh'                  => round($actualKwh, 1),
                'actual_kwh_cost'             => round($actualKwhCost, 2),
                'actual_base_total'           => round($actualBase, 2),
                'actual_bonus_total'          => round($actualBonus, 2),
                'actual_cost'                 => round($actualCost, 2),
                'advance_paid'                => round($advancePaid, 2),
                'current_balance'             => $currentBalance,
                'projected_end_balance'       => $projected,
                'verdict'                     => $verdict,
                // F-05 — contract-end reminder
                'days_until_end'              => $daysUntilEnd,
                'should_remind'               => $shouldRemind,
                'remind_stage'                => $remindStage,
            ];
            if ($utility === 'wasser') {
                $row['actual_m3']    = round($actualM3, 1);
                $row['components']   = [
                    'trinkwasser' => [
                        'working_cost'     => round($twWorking, 2),
                        'base_cost'        => round($twBase, 2),
                        'total'            => round($twTotal, 2),
                        'current_ct_per_m3'   => $curWp,
                        'current_eur_per_month' => $curBp,
                    ],
                    'schmutzwasser' => [
                        'total'                => round($swTotal, 2),
                        'current_ct_per_m3'    => $curSwWp,
                        'basis'                => $c['schmutzwasser']['basis'] ?? 'trinkwasser',
                    ],
                    'niederschlagswasser' => [
                        'total'                       => round($nwTotal, 2),
                        'current_eur_per_m2_year'     => $curNwRate,
                        'current_versiegelte_m2'      => $curNwArea,
                        'current_monthly'             => ($curNwRate !== null && $curNwArea !== null)
                            ? round((float)$curNwRate * (float)$curNwArea / 12.0, 2) : null,
                    ],
                ];
            }
            $out[] = $row;
        }

        // Sort by start ascending so the table reads chronologically
        usort($out, fn($a, $b) => strcmp($a['start'] ?? '', $b['start'] ?? ''));

        return ['contracts' => $out];
    }

    private function monthsBetween(string $a, string $b): int
    {
        try {
            $da = new \DateTime($a); $db = new \DateTime($b);
            $d = $da->diff($db);
            $months = $d->y * 12 + $d->m + ($d->d > 15 ? 1 : 0);
            return $db < $da ? 0 : $months;
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * F-03: the next billing-cycle anchor date (>= today) for a utility.
     *
     * The anchor is a Settings value `billing_cycle_anchor_<utility>` in
     * 'MM-TT' form (default '01-01' = Kalenderjahr). Used to project the
     * Saldo of open-ended contracts up to the next Jahresabrechnung instead
     * of the arbitrary "today + 12 months" of v1.0.x.
     */
    private function nextBillingAnchor(string $utility, string $today): string
    {
        $anchor = (string)$this->settings->get('billing_cycle_anchor_' . $utility, '01-01');
        if (!preg_match('/^(\d{2})-(\d{2})$/', $anchor, $mm)) {
            $anchor = '01-01';
            $mm = [null, '01', '01'];
        }
        // Clamp 29.–31. Feb. to 28 to keep the resulting date constructible
        // in every (also non-leap) year.
        if ($mm[1] === '02' && (int)$mm[2] > 28) {
            $anchor = '02-28';
        }
        $year = (int)substr($today, 0, 4);
        $candidate = sprintf('%04d-%s', $year, $anchor);
        if ($candidate < $today) {
            $candidate = sprintf('%04d-%s', $year + 1, $anchor);
        }
        return $candidate;
    }

    /**
     * Compute monthly aggregates for a single meter, honouring device chain.
     *
     * Wraps {@see computeForMeter()} in a recursion guard so a Schmutzwasser
     * `separater_zaehler` reference (see {@see applyWaterContracts()}) cannot
     * trigger infinite recursion.
     */
    public function forMeter(string $utility, array $meter, ?float $hddBaseOverride = null): array
    {
        $meterId = (string)($meter['id'] ?? '');
        if ($meterId !== '' && in_array($meterId, $this->meterComputeStack, true)) {
            return []; // cycle detected — break it
        }
        $this->meterComputeStack[] = $meterId;
        try {
            return $this->computeForMeter($utility, $meter, $hddBaseOverride);
        } finally {
            array_pop($this->meterComputeStack);
        }
    }

    /** @internal actual implementation, wrapped by forMeter()'s recursion guard */
    private function computeForMeter(string $utility, array $meter, ?float $hddBaseOverride = null): array
    {
        $u = Utilities::get($utility);
        $readings = $this->readings->list($utility, $meter['id']);
        $temps    = $this->store->read('temperatures.json', []);
        if (!is_array($temps)) $temps = [];
        $today    = date('Y-m-d');
        $hddBase  = $hddBaseOverride ?? (float)$this->settings->get('hdd_base_temp', 15.0);
        $conv     = !empty($u['unit_to_kwh'])
                    ? (float)$this->settings->get($u['conversion_setting'], 1.0)
                    : 1.0;

        // Filter actual (non-future, non-flagged) readings
        $actual = array_values(array_filter(
            $readings,
            fn($r) => ($r['date'] ?? '') <= $today && empty($r['is_future'])
        ));
        if (count($actual) < 2) return [];

        // Forward-fill prices
        $prices = [];
        $last = null;
        foreach ($actual as $r) {
            if (isset($r['price_cents']) && $r['price_cents'] !== null) {
                $last = (float)$r['price_cents'];
            }
            $prices[$r['date']] = $last;
        }

        // Devices index by id
        $devicesById = [];
        foreach ($meter['devices'] ?? [] as $d) {
            $devicesById[$d['id']] = $d;
        }

        $monthly = [];
        for ($i = 1; $i < count($actual); $i++) {
            $prev = $actual[$i - 1];
            $curr = $actual[$i];
            $days = (int)(new \DateTime($prev['date']))->diff(new \DateTime($curr['date']))->days;
            if ($days <= 0) continue;

            // ── F2: consumption across device replacements ──
            $consumptionRaw = $this->consumptionBetween(
                $prev, $curr, $devicesById, $meter
            );
            if ($consumptionRaw === null || $consumptionRaw < 0) continue;

            $consumptionKwh = $consumptionRaw * $conv;
            $unitsPerDay = $consumptionKwh / $days;
            $pricePerUnit = (float)($prices[$prev['date']] ?? $prices[$curr['date']] ?? 0);

            foreach ($this->distributeToMonths(
                $prev['date'], $curr['date'], $unitsPerDay, $pricePerUnit
            ) as $ym => $v) {
                if (!isset($monthly[$ym])) {
                    $monthly[$ym] = ['kwh' => 0.0, 'days' => 0, 'cost' => 0.0];
                }
                $monthly[$ym]['kwh']  += $v['kwh'];
                $monthly[$ym]['days'] += $v['days'];
                $monthly[$ym]['cost'] += $v['cost'];
            }
        }

        $monthly = $this->enrichWithWeather($monthly, $temps, $hddBase);
        $monthly = $this->applyUtilityFields($monthly, $utility);
        $monthly = $this->applyContracts($monthly, $utility, $meter['id']);
        ksort($monthly);
        $monthly = array_values($monthly);
        $valueField = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        return $this->addMovingAverages($monthly, $valueField);
    }

    /**
     * F2 core: compute raw counter consumption between two readings,
     * correctly bridging device replacements.
     *
     * Returns null if readings sit on devices the meter doesn't know about.
     */
    private function consumptionBetween(array $prev, array $curr, array $devicesById, array $meter): ?float
    {
        $prevDev = $prev['device_id'] ?? null;
        $currDev = $curr['device_id'] ?? null;

        // If reading lacks device_id (legacy or fresh import), re-resolve by date
        if (!$prevDev) $prevDev = $this->deviceIdOnDate($meter, $prev['date']);
        if (!$currDev) $currDev = $this->deviceIdOnDate($meter, $curr['date']);

        if ($prevDev === $currDev) {
            return ((float)$curr['counter']) - ((float)$prev['counter']);
        }

        // Different devices → bridge via final_counter / initial_counter
        $oldDev = $devicesById[$prevDev] ?? null;
        $newDev = $devicesById[$currDev] ?? null;
        if (!$oldDev || !$newDev) return null;

        $finalOld = $oldDev['final_counter'];
        $initNew  = $newDev['initial_counter'];
        if ($finalOld === null) return null; // can't bridge — old device not closed

        $partA = (float)$finalOld - (float)$prev['counter'];
        $partB = (float)$curr['counter'] - (float)$initNew;
        return $partA + $partB;
    }

    private function deviceIdOnDate(array $meter, string $date): ?string
    {
        foreach ($meter['devices'] ?? [] as $d) {
            if ($date < ($d['installed_on'] ?? '9999')) continue;
            if (!empty($d['removed_on']) && $date > $d['removed_on']) continue;
            return $d['id'];
        }
        return null;
    }

    /** Linear distribution of total kWh/day across month boundaries. */
    private function distributeToMonths(string $start, string $end, float $kwhPerDay, float $priceCents): array
    {
        $cur = new \DateTime($start);
        $fin = new \DateTime($end);
        $out = [];
        while ($cur < $fin) {
            $ym = $cur->format('Y-m');
            $nextMonth = (clone $cur)->modify('first day of next month');
            $seg = $fin < $nextMonth ? $fin : $nextMonth;
            $d = (int)$cur->diff($seg)->days;
            if ($d > 0) {
                if (!isset($out[$ym])) $out[$ym] = ['kwh' => 0.0, 'days' => 0, 'cost' => 0.0];
                $kwh = $kwhPerDay * $d;
                $out[$ym]['kwh']  += $kwh;
                $out[$ym]['days'] += $d;
                $out[$ym]['cost'] += $kwh * $priceCents / 100.0;
            }
            $cur = $nextMonth;
        }
        return $out;
    }

    private function enrichWithWeather(array $monthly, array $temps, float $hddBase): array
    {
        foreach ($monthly as $ym => &$data) {
            [$yr, $mn] = array_map('intval', explode('-', $ym));
            $dim = (int)date('t', mktime(0, 0, 0, $mn, 1, $yr));
            $sum = 0.0; $min = PHP_FLOAT_MAX; $max = -PHP_FLOAT_MAX; $cnt = 0; $hdd = 0.0;
            for ($d = 1; $d <= $dim; $d++) {
                $k = sprintf('%04d-%02d-%02d', $yr, $mn, $d);
                if (!isset($temps[$k])) continue;
                $avg = (float)$temps[$k]['avg'];
                $sum += $avg; $cnt++;
                $min = min($min, (float)$temps[$k]['min']);
                $max = max($max, (float)$temps[$k]['max']);
                $hdd += max(0.0, $hddBase - $avg);
            }
            $data['ym']        = $ym;
            $data['year']      = $yr;
            $data['month']     = $mn;
            $data['kwh']       = round($data['kwh'], 1);
            $data['cost']      = round($data['cost'], 2);
            $data['kwh_per_day'] = $data['days'] > 0 ? round($data['kwh'] / $data['days'], 2) : 0.0;
            $data['avg_temp']  = $cnt > 0 ? round($sum / $cnt, 1) : null;
            $data['min_temp']  = $cnt > 0 ? round($min, 1) : null;
            $data['max_temp']  = $cnt > 0 ? round($max, 1) : null;
            $data['hdd']       = round($hdd, 1);
            $data['temp_days'] = $cnt;
            $data['kwh_per_hdd'] = $data['hdd'] > 5 ? round($data['kwh'] / $data['hdd'], 2) : null;
        }
        unset($data);
        return $monthly;
    }

    private function applyUtilityFields(array $monthly, string $utility): array
    {
        $u = Utilities::get($utility);
        $co2Factor = (float)$this->settings->get($u['co2_setting'], 0.0);
        $conv = !empty($u['unit_to_kwh'])
                ? (float)$this->settings->get($u['conversion_setting'], 1.0)
                : 1.0;

        foreach ($monthly as &$m) {
            if ($u['consumption_unit'] === 'kWh') {
                $m['co2_kg'] = round($m['kwh'] * $co2Factor / 1000.0, 1);
            } else {
                // For m³-native (wasser): kwh field actually holds m³
                $m['m3']     = $m['kwh'];
                $m['kwh']    = 0.0;
                $m['co2_kg'] = round($m['m3'] * $co2Factor / 1000.0, 1);
            }
            // For gas: also report m³
            if ($utility === 'gas' && $conv > 0) {
                $m['m3'] = round($m['kwh'] / $conv, 1);
            }
        }
        unset($m);
        return $monthly;
    }

    private function applyContracts(array $monthly, string $utility, string $meterId): array
    {
        $contracts = $this->contracts->list($utility, $meterId);
        if (empty($contracts)) {
            return $this->applyEmptyContractFields($monthly, $utility);
        }
        return $utility === 'wasser'
            ? $this->applyWaterContracts($monthly, $contracts, $utility)
            : $this->applyStandardContracts($monthly, $contracts);
    }

    private function applyEmptyContractFields(array $monthly, string $utility): array
    {
        foreach ($monthly as &$m) {
            $m['contract_id']        = null;
            $m['advance_eur']        = null;
            $m['bonus_eur']          = null;
            $m['monthly_balance']    = null;
            $m['cumulative_balance'] = null;
            if ($utility === 'wasser') {
                $m['trinkwasser']         = null;
                $m['schmutzwasser']       = null;
                $m['niederschlagswasser'] = null;
            } else {
                $m['base_price_eur']   = null;
                $m['working_price_ct'] = null;
                $m['kwh_cost']         = $m['cost'] ?? null;
            }
        }
        unset($m);
        return $monthly;
    }

    /** Gas / Strom — original flat shape with working_prices / base_prices. */
    private function applyStandardContracts(array $monthly, array $contracts): array
    {
        $running = [];
        foreach ($monthly as &$m) {
            $first = $m['ym'] . '-01';
            $c = $this->contracts->findActiveForDate($contracts, $first);
            if (!$c) {
                $m['contract_id'] = null; $m['advance_eur'] = null;
                $m['base_price_eur'] = null; $m['working_price_ct'] = null;
                $m['bonus_eur'] = null;
                $m['kwh_cost'] = $m['cost'] ?? null;
                $m['monthly_balance'] = null; $m['cumulative_balance'] = null;
                continue;
            }
            $y = (int)$m['year']; $mn = (int)$m['month'];
            $wp = $this->contracts->valueValidOn($c['working_prices'] ?? [], 'ct_per_kwh', $y, $mn);
            $bp = $this->contracts->valueValidOn($c['base_prices']    ?? [], 'eur_per_month', $y, $mn);
            $ap = $this->contracts->valueValidOn($c['advance_payments'] ?? [], 'amount_eur', $y, $mn);
            $bn = $this->contracts->bonusForMonth($c, $y, $mn);

            $value = (float)($m['kwh'] ?? 0);
            $kwhCost = (float)($m['cost'] ?? 0);
            if ($wp !== null && $value > 0) {
                $kwhCost = round($value * $wp / 100.0, 2);
            }
            $combined = round($kwhCost + (float)($bp ?? 0) - $bn, 2);

            $m['contract_id']      = $c['id'];
            $m['advance_eur']      = $ap;
            $m['base_price_eur']   = $bp;
            $m['working_price_ct'] = $wp;
            $m['bonus_eur']        = round($bn, 2);
            $m['kwh_cost']         = $kwhCost;
            $m['cost']             = $combined;

            if ($ap !== null) {
                $delta = $combined - (float)$ap;
                $m['monthly_balance'] = round($delta, 2);
                $running[$c['id']] = ($running[$c['id']] ?? 0.0) + $delta;
                $m['cumulative_balance'] = round($running[$c['id']], 2);
            } else {
                $m['monthly_balance'] = null;
                $m['cumulative_balance'] = null;
            }
        }
        unset($m);
        return $monthly;
    }

    /**
     * Water — three priced components per contract (v1.0.3).
     *
     * Per month, computes:
     *   trinkwasser   = m³_trinkwasser × ct/m³ + Grundpreis
     *   schmutzwasser = m³_schmutzwasser × ct/m³
     *                 (m³_schmutzwasser = m³_trinkwasser when basis = 'trinkwasser';
     *                  when basis = 'separater_zaehler' the volume is read from
     *                  the referenced meter — F-01 fix, v1.1.0)
     *   niederschlagswasser = versiegelte_m² × eur/m²/Jahr / 12
     *
     * Result fields per month:
     *   m.trinkwasser         = { m3, ct_per_m3, base_price_eur, working_cost, base_cost, total }
     *   m.schmutzwasser       = { basis, m3, ct_per_m3, total }
     *   m.niederschlagswasser = { eur_per_m2_year, versiegelte_flaeche_m2, total }
     *   m.cost                = trinkwasser.total + schmutzwasser.total + niederschlagswasser.total − bonus
     */
    private function applyWaterContracts(array $monthly, array $contracts, string $utility): array
    {
        $running = [];
        // Lazily-filled cache: separate-meter id → (ym → m³). Computed on first
        // use so a contract without a separater_zaehler reference costs nothing,
        // and so two contracts referencing the same meter share one computation.
        $sepMeterM3Cache = [];

        foreach ($monthly as &$m) {
            $first = $m['ym'] . '-01';
            $c = $this->contracts->findActiveForDate($contracts, $first);
            if (!$c) {
                $m['contract_id'] = null; $m['advance_eur'] = null;
                $m['trinkwasser'] = null; $m['schmutzwasser'] = null; $m['niederschlagswasser'] = null;
                $m['bonus_eur'] = null;
                $m['monthly_balance'] = null; $m['cumulative_balance'] = null;
                continue;
            }
            $y = (int)$m['year']; $mn = (int)$m['month'];

            $m3 = (float)($m['m3'] ?? 0);
            $tw = $c['trinkwasser']         ?? [];
            $sw = $c['schmutzwasser']       ?? [];
            $nw = $c['niederschlagswasser'] ?? [];

            // ── Trinkwasser ─────────────────────────────────────────
            $twWp = $this->contracts->valueValidOn($tw['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
            $twBp = $this->contracts->valueValidOn($tw['base_prices']    ?? [], 'eur_per_month', $y, $mn);
            $twWorking = ($twWp !== null && $m3 > 0) ? round($m3 * $twWp / 100.0, 2) : 0.0;
            $twBase    = $twBp !== null ? (float)$twBp : 0.0;
            $twTotal   = round($twWorking + $twBase, 2);

            // ── Schmutzwasser ──────────────────────────────────────
            // basis = 'trinkwasser'       → identical volume as Trinkwasser
            // basis = 'separater_zaehler' → volume read from the referenced
            //   meter's monthly m³ (F-01 fix). If the reference is missing or
            //   produces no data for the month, the volume falls back to 0 so
            //   the cost is not silently wrong-by-trinkwasser-volume.
            $swWp    = $this->contracts->valueValidOn($sw['working_prices'] ?? [], 'ct_per_m3', $y, $mn);
            $swBasis = (string)($sw['basis'] ?? 'trinkwasser');
            $swMeterId = $sw['separater_zaehler_meter_id'] ?? null;
            if ($swBasis === 'separater_zaehler' && $swMeterId) {
                if (!array_key_exists($swMeterId, $sepMeterM3Cache)) {
                    $sepMeterM3Cache[$swMeterId] = $this->monthlyM3ForMeterId($utility, (string)$swMeterId);
                }
                $swM3 = (float)($sepMeterM3Cache[$swMeterId][$m['ym']] ?? 0.0);
            } else {
                $swM3 = $m3;
            }
            $swTotal = ($swWp !== null && $swM3 > 0) ? round($swM3 * $swWp / 100.0, 2) : 0.0;

            // ── Niederschlagswasser ────────────────────────────────
            $nwRate = $this->valueValidOnGeneric($nw['rates'] ?? [], 'eur_per_m2_year', $y, $mn);
            $nwArea = $this->valueValidOnGeneric($nw['rates'] ?? [], 'versiegelte_flaeche_m2', $y, $mn);
            $nwTotal = ($nwRate !== null && $nwArea !== null)
                ? round(((float)$nwArea * (float)$nwRate) / 12.0, 2)
                : 0.0;

            $ap = $this->contracts->valueValidOn($c['advance_payments'] ?? [], 'amount_eur', $y, $mn);
            $bn = $this->contracts->bonusForMonth($c, $y, $mn);

            $combined = round($twTotal + $swTotal + $nwTotal - $bn, 2);

            $m['contract_id']  = $c['id'];
            $m['advance_eur']  = $ap;
            $m['bonus_eur']    = round($bn, 2);
            $m['trinkwasser']  = [
                'm3'             => $m3,
                'ct_per_m3'      => $twWp,
                'base_price_eur' => $twBp,
                'working_cost'   => $twWorking,
                'base_cost'      => $twBase,
                'total'          => $twTotal,
            ];
            $m['schmutzwasser'] = [
                'basis'                       => $swBasis,
                'separater_zaehler_meter_id'  => $swMeterId,
                'm3'                          => $swM3,
                'ct_per_m3'                   => $swWp,
                'total'                       => $swTotal,
            ];
            $m['niederschlagswasser'] = [
                'eur_per_m2_year'        => $nwRate,
                'versiegelte_flaeche_m2' => $nwArea,
                'total'                  => $nwTotal,
            ];
            $m['cost'] = $combined;

            // For the legacy/standard fields the UI's monthly table reads,
            // we keep working_price_ct = trinkwasser ct/m³ (the headline
            // tariff) and base_price_eur = trinkwasser base + niederschlag/month.
            $m['working_price_ct'] = $twWp;
            $m['base_price_eur']   = round($twBase + $nwTotal, 2);
            $m['kwh_cost']         = round($twWorking + $swTotal, 2); // Verbrauchsabhängig

            if ($ap !== null) {
                $delta = $combined - (float)$ap;
                $m['monthly_balance'] = round($delta, 2);
                $running[$c['id']] = ($running[$c['id']] ?? 0.0) + $delta;
                $m['cumulative_balance'] = round($running[$c['id']], 2);
            } else {
                $m['monthly_balance'] = null;
                $m['cumulative_balance'] = null;
            }
        }
        unset($m);
        return $monthly;
    }

    /**
     * Monthly m³ map (ym → m³) for a meter referenced by id.
     *
     * Used by {@see applyWaterContracts()} to resolve a Schmutzwasser
     * `separater_zaehler` reference. Goes through {@see forMeter()} so the
     * recursion guard applies — a self- or mutually-referential configuration
     * yields an empty map rather than an infinite loop.
     *
     * @return array<string,float>
     */
    private function monthlyM3ForMeterId(string $utility, string $meterId): array
    {
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) return [];
        $out = [];
        foreach ($this->forMeter($utility, $meter) as $m) {
            if (isset($m['ym'])) {
                $out[$m['ym']] = (float)($m['m3'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Generic stichtag-lookup for entries with an arbitrary numeric field
     * (used by the Niederschlagswasser rates which have two fields per entry).
     */
    private function valueValidOnGeneric(array $entries, string $field, int $year, int $month): ?float
    {
        if (empty($entries)) return null;
        $first = sprintf('%04d-%02d-01', $year, $month);
        usort($entries, fn($a, $b) => strcmp($a['from'] ?? '', $b['from'] ?? ''));
        $val = null;
        foreach ($entries as $e) {
            if (($e['from'] ?? '9999') <= $first) {
                if (isset($e[$field]) && is_numeric($e[$field])) $val = (float)$e[$field];
            } else break;
        }
        return $val;
    }

    private function addMovingAverages(array $monthly, string $field): array
    {
        $n = count($monthly);
        if ($n === 0) return $monthly;
        $vals = array_map(fn($m) => (float)($m[$field] ?? 0), $monthly);
        foreach ([3, 6, 12] as $w) {
            for ($i = 0; $i < $n; $i++) {
                if ($i + 1 < $w) {
                    $monthly[$i]['ma' . $w] = null;
                    continue;
                }
                $sum = 0.0;
                for ($j = $i - $w + 1; $j <= $i; $j++) $sum += $vals[$j];
                $monthly[$i]['ma' . $w] = round($sum / $w, 1);
            }
        }
        return $monthly;
    }
}
