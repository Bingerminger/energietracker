<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * F1005 — Kombinierte Strom-Saldo-Sicht.
 *
 * Aggregiert die Monatswerte aus `strom` (Bezugskosten) und
 * `pv_einspeisung` (Einspeisevergütung) zu einem Netto-Saldo:
 *
 *   saldo_netto = bezug_cost − einspeisung_revenue
 *
 * Vorzeichen-Konvention:
 *   positiv  → Netto-Kosten (du zahlst dem Versorger)
 *   negativ  → Netto-Erlös  (der Versorger zahlt dir)
 *
 * Liefert sowohl die monatliche als auch die jährliche Sicht — wichtig
 * für den PV-Eigentümer-Use-Case („Wie hat sich die PV dieses Jahr
 * gerechnet?" ist meistens eine Jahres-, keine Monatsfrage).
 */
final class StromSaldoService
{
    public function __construct(
        private ConsumptionService $consumption,
    ) {}

    /**
     * @return array{
     *   monthly: array<int,array{
     *     ym:string, year:int, month:int,
     *     bezug_kwh:float, bezug_cost:float,
     *     einspeisung_kwh:float, einspeisung_revenue:float,
     *     saldo_netto:float
     *   }>,
     *   yearly: array<int,array{
     *     year:int,
     *     bezug_kwh:float, bezug_cost:float,
     *     einspeisung_kwh:float, einspeisung_revenue:float,
     *     saldo_netto:float
     *   }>
     * }
     */
    public function compute(): array
    {
        $strom = $this->consumption->forUtility('strom');
        $pv    = $this->consumption->forUtility('pv_einspeisung');

        $byYm = [];
        foreach ($strom['monthly_total'] ?? [] as $m) {
            $ym = (string)($m['ym'] ?? '');
            if ($ym === '') continue;
            $byYm[$ym] = $this->emptyRow($ym);
            $byYm[$ym]['bezug_kwh']  = (float)($m['kwh']  ?? 0);
            $byYm[$ym]['bezug_cost'] = (float)($m['cost'] ?? 0);
        }
        foreach ($pv['monthly_total'] ?? [] as $m) {
            $ym = (string)($m['ym'] ?? '');
            if ($ym === '') continue;
            if (!isset($byYm[$ym])) $byYm[$ym] = $this->emptyRow($ym);
            $byYm[$ym]['einspeisung_kwh']     = (float)($m['kwh']  ?? 0);
            $byYm[$ym]['einspeisung_revenue'] = (float)($m['cost'] ?? 0);
        }

        foreach ($byYm as &$row) {
            $row['saldo_netto'] = round($row['bezug_cost'] - $row['einspeisung_revenue'], 2);
        }
        unset($row);
        ksort($byYm);
        $monthly = array_values($byYm);

        // Jährliche Sicht — für den „hat sich die Anlage gerechnet?"-Blick.
        $yearly = [];
        foreach ($monthly as $row) {
            $yr = (int)$row['year'];
            if (!isset($yearly[$yr])) {
                $yearly[$yr] = [
                    'year' => $yr,
                    'bezug_kwh' => 0.0, 'bezug_cost' => 0.0,
                    'einspeisung_kwh' => 0.0, 'einspeisung_revenue' => 0.0,
                    'saldo_netto' => 0.0,
                ];
            }
            $yearly[$yr]['bezug_kwh']            += $row['bezug_kwh'];
            $yearly[$yr]['bezug_cost']           += $row['bezug_cost'];
            $yearly[$yr]['einspeisung_kwh']      += $row['einspeisung_kwh'];
            $yearly[$yr]['einspeisung_revenue']  += $row['einspeisung_revenue'];
        }
        foreach ($yearly as &$y) {
            $y['bezug_kwh']           = round($y['bezug_kwh'], 1);
            $y['bezug_cost']          = round($y['bezug_cost'], 2);
            $y['einspeisung_kwh']     = round($y['einspeisung_kwh'], 1);
            $y['einspeisung_revenue'] = round($y['einspeisung_revenue'], 2);
            $y['saldo_netto']         = round($y['bezug_cost'] - $y['einspeisung_revenue'], 2);
        }
        unset($y);
        ksort($yearly);

        return [
            'monthly' => $monthly,
            'yearly'  => array_values($yearly),
        ];
    }

    /** @return array{ym:string,year:int,month:int,bezug_kwh:float,bezug_cost:float,einspeisung_kwh:float,einspeisung_revenue:float,saldo_netto:float} */
    private function emptyRow(string $ym): array
    {
        [$yr, $mn] = array_map('intval', explode('-', $ym));
        return [
            'ym' => $ym, 'year' => $yr, 'month' => $mn,
            'bezug_kwh' => 0.0, 'bezug_cost' => 0.0,
            'einspeisung_kwh' => 0.0, 'einspeisung_revenue' => 0.0,
            'saldo_netto' => 0.0,
        ];
    }
}
