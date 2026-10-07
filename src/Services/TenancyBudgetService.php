<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Http\NotFoundException;

/**
 * v3.1.0 (Paket H3, F1008/MKT-07) — Vorauszahlung gegen erwartete Kosten im
 * laufenden Abrechnungszeitraum. Eine Hilfsrechnung, keine Abrechnung.
 *
 * Je Monat des Zeitraums (aus `billing_anchor`):
 *   erwartet = Wärme-kWh × Preis je kWh + Warmwasser-m³ × Preis + Kaltwasser-m³ × Preis
 *              + pauschale Umlagen / 12
 *   bezahlt  = Vorauszahlung Heizung + Betriebskosten
 * Gemessene Monate kommen aus den zugeordneten Zählern (Stände oder Zeiträume),
 * die übrigen aus dem Heizmodell mit dem Klimanormal (Wärme) bzw. aus dem
 * Vorjahresmonat oder dem Tagesmittel (Wasser). Die Preise stammen aus der
 * letzten Abrechnung oder einer Schätzung; fehlt einer, steht das unter
 * `assumptions`. Monate am Rand des Zeitraums zählen anteilig.
 *
 * Ergebnis positiv = Nachzahlung (wie Saldo und Abrechnung).
 */
final class TenancyBudgetService
{
    public function __construct(
        private TenancyService $tenancies,
        private MeterService $meters,
        private ConsumptionService $consumption,
        private I18nService $i18n,
    ) {}

    /** @return array<string,mixed> */
    public function budget(string $tenancyId, ?string $asOf = null): array
    {
        $t = $this->tenancies->get($tenancyId) ?? throw new NotFoundException($this->i18n->t('errors.tenancy.notFound'));
        $asOf ??= date('Y-m-d');
        [$from, $to] = self::periodAround((string)($t['billing_anchor'] ?? '01-01'), $asOf);
        if ((string)$t['start'] > $from) $from = (string)$t['start'];
        if (!empty($t['end']) && (string)$t['end'] < $to) $to = (string)$t['end'];

        $series = [];   // role → meter → ym → value
        foreach (TenancyService::METER_ROLES as $role => $utility) {
            foreach ((array)($t['meter_ids'][$role] ?? []) as $mid) {
                $meter = $this->meters->get($utility, (string)$mid);
                if (!$meter) continue;
                $series[$role][] = ['utility' => $utility, 'monthly' => $this->consumption->forMeter($utility, $meter)];
            }
        }

        $assumptions = [];
        $months = [];
        $sum = ['expected' => 0.0, 'prepaid' => 0.0, 'expected_to_date' => 0.0, 'prepaid_to_date' => 0.0];
        $parts = ['heat_eur' => 0.0, 'warm_water_eur' => 0.0, 'cold_water_eur' => 0.0, 'fixed_eur' => 0.0,
                  'heat_kwh' => 0.0, 'warm_water_m3' => 0.0, 'cold_water_m3' => 0.0];
        $estimatedMonths = 0;
        $cumulative = 0.0;
        for ($cur = substr($from, 0, 7) . '-01'; $cur <= $to; $cur = date('Y-m-01', (int)strtotime("$cur +1 month"))) {
            $ym = substr($cur, 0, 7);
            $dim = (int)date('t', (int)strtotime($cur));
            $monthEnd = "$ym-" . sprintf('%02d', $dim);
            $a = max($cur, $from);
            $b = min($monthEnd, $to);
            $share = ((int)((strtotime($b) - strtotime($a)) / 86400) + 1) / $dim;
            $mid = "$ym-15";
            $measured = $monthEnd <= $asOf;

            $values = [];
            $allMeasured = true;
            foreach (['heat' => 'kwh', 'warm_water' => 'm3', 'cold_water' => 'm3'] as $role => $field) {
                $v = 0.0;
                foreach ($series[$role] ?? [] as $s) {
                    [$val, $isMeasured] = $this->monthValue($s['utility'], $s['monthly'], $ym, $field, $dim);
                    $v += $val;
                    if (!$isMeasured) $allMeasured = false;
                }
                $values[$role] = $v * $share;
            }
            if (!$allMeasured || !$measured) $estimatedMonths++;

            $price = [];
            foreach (['heat' => 'heat_eur_per_kwh', 'warm_water' => 'warm_water_eur_per_m3', 'cold_water' => 'cold_water_eur_per_m3'] as $role => $field) {
                $price[$role] = TenancyService::priceAt($t, $field, $mid);
                if ($price[$role] === null && !empty($t['meter_ids'][$role])) $assumptions["price_missing_$role"] = true;
            }
            $heatEur = $values['heat'] * ($price['heat'] ?? 0.0);
            $wwEur = $values['warm_water'] * ($price['warm_water'] ?? 0.0);
            $cwEur = $values['cold_water'] * ($price['cold_water'] ?? 0.0);
            $fixedEur = TenancyService::fixedCostsAt($t, $mid) / 12 * $share;
            $expected = $heatEur + $wwEur + $cwEur + $fixedEur;
            $pp = TenancyService::validAt((array)($t['prepayments'] ?? []), $mid);
            if ($pp === null) $assumptions['prepayment_missing'] = true;
            $prepaid = (((float)($pp['heating_eur_month'] ?? 0)) + ((float)($pp['operating_eur_month'] ?? 0))) * $share;

            $sum['expected'] += $expected;
            $sum['prepaid'] += $prepaid;
            if ($measured && $allMeasured) {
                $sum['expected_to_date'] += $expected;
                $sum['prepaid_to_date'] += $prepaid;
            }
            $cumulative += $expected - $prepaid;
            foreach (['heat' => 'heat', 'warm_water' => 'warm_water', 'cold_water' => 'cold_water'] as $role => $p) {
                $parts[$p . '_eur'] += $role === 'heat' ? $heatEur : ($role === 'warm_water' ? $wwEur : $cwEur);
            }
            $parts['fixed_eur'] += $fixedEur;
            $parts['heat_kwh'] += $values['heat'];
            $parts['warm_water_m3'] += $values['warm_water'];
            $parts['cold_water_m3'] += $values['cold_water'];

            $months[] = [
                'ym'            => $ym,
                'share'         => round($share, 4),
                'heat_kwh'      => round($values['heat'], 1),
                'warm_water_m3' => round($values['warm_water'], 2),
                'cold_water_m3' => round($values['cold_water'], 2),
                'expected_eur'  => round($expected, 2),
                'prepaid_eur'   => round($prepaid, 2),
                'balance_eur'   => round($cumulative, 2),
                'measured'      => $measured && $allMeasured,
            ];
        }
        if ($estimatedMonths > 0) $assumptions['months_estimated'] = $estimatedMonths;
        if (empty($t['meter_ids']['heat']) && empty($t['meter_ids']['warm_water']) && empty($t['meter_ids']['cold_water'])) {
            $assumptions['no_meters'] = true;
        }

        $result = $sum['expected'] - $sum['prepaid'];
        $ratio = $sum['prepaid'] > 0 ? $result / $sum['prepaid'] : null;
        $nMonths = max(1, count($months));
        return [
            'tenancy_id'               => (string)$t['id'],
            'period_from'              => $from,
            'period_to'                => $to,
            'as_of'                    => $asOf,
            'months'                   => $months,
            'expected_eur'             => round($sum['expected'], 2),
            'prepaid_eur'              => round($sum['prepaid'], 2),
            'to_date'                  => ['expected_eur' => round($sum['expected_to_date'], 2), 'prepaid_eur' => round($sum['prepaid_to_date'], 2),
                                           'result_eur' => round($sum['expected_to_date'] - $sum['prepaid_to_date'], 2)],
            'projected_result_eur'     => round($result, 2),
            'risk'                     => $ratio === null ? null : ($result <= 0 ? 'low' : ($ratio <= 0.1 ? 'medium' : 'high')),
            'suggested_prepayment_eur' => (float)ceil($sum['expected'] / $nMonths),
            'components'               => array_map(fn($v) => round($v, 2), $parts),
            'assumptions'              => array_keys(array_filter($assumptions)),
            'months_estimated'         => $estimatedMonths,
        ];
    }

    /** Laufender Abrechnungszeitraum: Stichtag (MM-TT) ≤ Datum, ein Jahr lang. */
    public static function periodAround(string $anchor, string $date): array
    {
        $y = (int)substr($date, 0, 4);
        $start = "$y-$anchor";
        if ($anchor === '02-29' && !checkdate(2, 29, $y)) $start = "$y-02-28";
        if ($start > $date) $start = ($y - 1) . '-' . ($anchor === '02-29' && !checkdate(2, 29, $y - 1) ? '02-28' : $anchor);
        $end = date('Y-m-d', (int)strtotime("$start +1 year -1 day"));
        return [$start, $end];
    }

    /**
     * Wert eines Monats: gemessen (aus der Monatsreihe, ganze Abdeckung) oder
     * geschätzt — Wärme aus dem Heizmodell mit dem Klimanormal, sonst
     * Vorjahresmonat, sonst Tagesmittel der gemessenen Monate.
     *
     * @return array{0: float, 1: bool} [Wert für den ganzen Monat, gemessen?]
     */
    private function monthValue(string $utility, array $monthly, string $ym, string $field, int $dim): array
    {
        $byYm = [];
        foreach ($monthly as $m) $byYm[(string)$m['ym']] = $m;
        $m = $byYm[$ym] ?? null;
        if ($m !== null && (int)($m['days'] ?? 0) >= $dim) return [(float)($m[$field] ?? 0), true];

        if ($field === 'kwh') {
            $model = $this->consumption->heatModel($utility, $monthly);
            $normal = $this->consumption->hddNormal((int)substr($ym, 5, 2));
            if ($model !== null && $normal !== null) {
                return [max(0.0, $model['a'] * (float)$normal['mean'] + $model['c'] * $dim), false];
            }
        }
        $prevYear = ((int)substr($ym, 0, 4) - 1) . substr($ym, 4);
        $p = $byYm[$prevYear] ?? null;
        if ($p !== null && (int)($p['days'] ?? 0) > 0) return [(float)($p[$field] ?? 0) / (int)$p['days'] * $dim, false];
        $days = 0; $total = 0.0;
        foreach ($monthly as $x) { $days += (int)($x['days'] ?? 0); $total += (float)($x[$field] ?? 0); }
        return [$days > 0 ? $total / $days * $dim : 0.0, false];
    }
}
