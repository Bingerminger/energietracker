<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v3.1.0 (Paket H, API-33) — Kennzahlen für Home Assistant und Skripte in einer
 * flachen, stabilen Antwort: `GET /api/summary`.
 *
 * Wer bisher Saldo, Prognose oder „Tage seit Ablesung" in Home Assistant sehen
 * wollte, musste je Zähler `consumption` (rund 86 KB), `contract-status` und
 * `forecast` abrufen und auswerten. Stabilitätsklasse A: Jeder Schlüssel ist
 * immer da (Wert oder null), neue kommen nur dazu, `summary_version` steigt nur
 * bei einem Bruch. SummaryServiceTest hält den Schlüsselsatz fest.
 *
 * Werte: Verbrauch in `consumption_unit`, Beträge in der Währung der
 * Einstellung (`currency`). Saldo wie contract-status: positiv = Nachzahlung
 * (bei der Einspeisung: Auszahlung), negativ = Guthaben.
 */
final class SummaryService
{
    public const VERSION = 1;

    public function __construct(
        private SettingsService $settings,
        private MeterService $meters,
        private ReadingService $readings,
        private ConsumptionService $consumption,
        private ForecastService $forecasts,
        private DeliveryService $deliveries,
        private PvSummaryService $pv,
        private StromSaldoService $stromSaldo,
        private AgendaService $agenda,
        private InstanceService $instance,
    ) {}

    /**
     * @param string|null $utility nur diese Verbrauchsart
     * @param string|null $meterRef nur dieser Zähler (ID oder Alias/external_id)
     * @return array<string,mixed>
     */
    public function build(?string $utility = null, ?string $meterRef = null, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $alertDays = max(1, (int)$this->settings->get('alert_days_since_reading', 45));
        $overview = [];
        foreach ($this->readings->overview($this->activeUtilities()) as $row) {
            $overview[$row['utility'] . '.' . $row['meter_id']] = $row;
        }

        $meters = [];
        $warnings = 0;
        foreach ($this->activeUtilities() as $u) {
            if ($utility !== null && $u !== $utility) continue;
            $def = Utilities::get($u);
            foreach ($this->meters->list($u) as $m) {
                if (!MeterService::inService($m)) continue;
                $mid = (string)$m['id'];
                $alias = (string)($m['external_id'] ?? '');
                if ($meterRef !== null && $meterRef !== $mid && ($alias === '' || $meterRef !== $alias)) continue;
                $row = $overview["$u.$mid"] ?? null;
                $warnings += (int)($row['suspect_count'] ?? 0);
                $meters[] = $this->meter($u, $def, $m, $row, $alertDays, $today);
            }
        }

        $events = $this->agenda->events(365, $today);
        $due = array_values(array_filter($events, fn($e) => $e['due_now']));
        $next = null;
        foreach ($events as $e) {
            if ($e['date'] >= $today && in_array($e['kind'], ['cancel_by', 'term_end', 'reminder', 'price_increase', 'price_guarantee_end'], true)) {
                $next = ['kind' => $e['kind'], 'date' => $e['date'], 'title' => $e['title']];
                break;
            }
        }

        return [
            'summary_version' => self::VERSION,
            'instance_id'     => $this->instance->id(),
            'generated_at'    => date('c'),
            'app_version'     => trim((string)@file_get_contents(dirname(__DIR__, 2) . '/VERSION')),
            'currency'        => (string)$this->settings->get('currency', 'EUR'),
            'meters'          => $meters,
            'pv'              => $this->pvBlock((int)substr($today, 0, 4)),
            'agenda'          => [
                'due'     => count($due),
                'overdue' => count(array_filter($due, fn($e) => in_array($e['severity'], ['overdue', 'urgent'], true))),
                'next'    => $next,
            ],
            'warnings'        => $warnings,
        ];
    }

    /** @return array<string,mixed> */
    private function meter(string $u, array $def, array $m, ?array $row, int $alertDays, string $today): array
    {
        $isKwh = ($def['consumption_unit'] ?? 'kWh') === 'kWh';
        $vf = $isKwh ? 'kwh' : 'm3';
        $last = $row['last_reading'] ?? null;
        // Zähler mit Verbrauch je Zeitraum (H3): Stand = Ende des letzten Zeitraums, kein Zählerstand
        $isPeriod = (($row['capture'] ?? $m['capture'] ?? 'counter')) === 'period';
        $lastDate = $isPeriod ? ($row['last_period']['to'] ?? null) : ($last['date'] ?? null);
        $since = $lastDate !== null ? (int)round((strtotime($today) - strtotime((string)$lastDate)) / 86400) : null;

        $monthly = [];
        try { $monthly = $this->consumption->forMeter($u, $m); } catch (\Throwable) { /* ohne Verbrauch */ }
        $ym = substr($today, 0, 7);
        $year = (int)substr($today, 0, 4);
        $mtd = null; $ytd = 0.0; $ytdEst = false; $hasYear = false;
        foreach ($monthly as $r) {
            if ((int)($r['year'] ?? 0) !== $year) continue;
            $hasYear = true;
            $ytd += (float)($r[$vf] ?? 0);
            $est = (int)($r['estimated_days'] ?? 0) > 0;
            $ytdEst = $ytdEst || $est;
            if (($r['ym'] ?? '') === $ym) $mtd = ['value' => round((float)($r[$vf] ?? 0), 3), 'estimated' => $est];
        }

        return [
            'key'                => "$u.{$m['id']}",
            'utility'            => $u,
            'meter_id'           => (string)$m['id'],
            'external_id'        => ($m['external_id'] ?? '') !== '' ? (string)$m['external_id'] : null,
            'name'               => (string)($m['name'] ?? $m['id']),
            'unit'               => (string)($def['unit'] ?? ''),
            'consumption_unit'   => (string)($def['consumption_unit'] ?? ''),
            'role'               => Utilities::roleOf($u, $m),
            'is_sub_meter'       => !empty($m['parent_meter_id']),
            'last_reading_date'  => $lastDate,
            'last_counter'       => !$isPeriod && $last !== null ? (float)$last['counter'] : null,
            'days_since_reading' => $since,
            'reading_due'        => Utilities::isCumulative($u) ? ($since === null || $since > $alertDays) : false,
            'month_to_date'      => $mtd ?? ($hasYear ? ['value' => 0.0, 'estimated' => false] : null),
            'year_to_date'       => $hasYear ? ['value' => round($ytd, 3), 'estimated' => $ytdEst] : null,
            'contract'           => Utilities::hasContracts($u) ? $this->contract($u, $m) : null,
            'forecast_12m'       => $this->forecast($u, $m, $vf),
            'tank'               => Utilities::isDelivery($u) ? $this->tank($u, $m) : null,
            'capture'            => $isPeriod ? 'period' : 'counter',   // H3: Verbrauch je Zeitraum
        ];
    }

    /** @return array<string,mixed>|null laufender Vertrag (ohne Schattenverträge) */
    private function contract(string $u, array $m): ?array
    {
        try {
            $status = $this->consumption->contractStatus($u, $m);
        } catch (\Throwable) {
            return null;
        }
        foreach ($status['contracts'] ?? [] as $c) {
            if (empty($c['is_current'])) continue;
            return [
                'contract_id'               => (string)($c['contract_id'] ?? ''),
                'provider'                  => (string)($c['provider'] ?? ''),
                'tariff_name'               => (string)($c['tariff_name'] ?? ''),
                'working_price_ct'          => $c['current_working_price_ct'] ?? null,
                'base_price_month'          => $c['current_base_price_eur'] ?? null,
                'advance_month'             => $c['current_advance_amount'] ?? null,
                'suggested_advance'         => $c['suggested_advance'] ?? null,
                'balance'                   => $c['current_balance'] ?? null,
                'projected_end_balance'     => $c['projected_end_balance'] ?? null,
                'verdict'                   => $c['verdict'] ?? null,
                'balance_as_of'             => $c['balance_as_of'] ?? null,
                'period_end'                => $c['effective_end'] ?? null,
                'cancel_by'                 => $c['cancel_by'] ?? null,
                'days_to_cancel'            => $c['days_to_cancel'] ?? null,
                'price_increase_from'       => $c['price_increase']['from'] ?? null,
            ];
        }
        return null;
    }

    /** @return array{value:?float,cost:?float,low:?float,high:?float}|null */
    private function forecast(string $u, array $m, string $vf): ?array
    {
        if (Utilities::isDelivery($u)) return null;
        try {
            $f = $this->forecasts->forMeter($u, $m);
        } catch (\Throwable) {
            return null;
        }
        if (empty($f['valid']) || empty($f['forecast'])) return null;
        $months = array_slice($f['forecast'], 0, 12);
        $value = array_sum(array_map(fn($r) => (float)($r[$vf] ?? 0), $months));
        $cost = array_sum(array_map(fn($r) => (float)($r['cost_estimated'] ?? 0), $months));
        return [
            'value' => round($value, 1),
            'cost'  => Utilities::isGenerationOnly($u) ? null : round($cost, 2),
            'low'   => $f['annual']['low'] ?? null,
            'high'  => $f['annual']['high'] ?? null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function tank(string $u, array $m): ?array
    {
        try {
            $h = $this->deliveries->stockHistory($u, (string)$m['id'], $this->consumption);
        } catch (\Throwable) {
            return null;
        }
        $days = $h['days'] ?? [];
        $cap = (float)($h['capacity'] ?? 0);
        if (empty($days)) return null;
        $last = end($days);
        $stock = (float)($last['stock'] ?? 0);
        return [
            'stock'          => round($stock, 1),
            'capacity'       => $cap > 0 ? $cap : null,
            'unit'           => (string)($h['capacity_unit'] ?? ''),
            'percent'        => $cap > 0 ? round($stock / $cap * 100, 1) : null,
            'estimated_from' => $h['estimated_from'] ?? null,
        ];
    }

    /** @return array{autarky_pct_ytd:?float,self_consumption_pct_ytd:?float,strom_saldo_ytd:?float} */
    private function pvBlock(int $year): array
    {
        $out = ['autarky_pct_ytd' => null, 'self_consumption_pct_ytd' => null, 'strom_saldo_ytd' => null];
        try {
            foreach ($this->pv->compute()['yearly'] ?? [] as $y) {
                if ((int)($y['year'] ?? 0) !== $year) continue;
                $out['autarky_pct_ytd'] = isset($y['autarkiequote']) ? round((float)$y['autarkiequote'] * 100, 1) : null;
                $out['self_consumption_pct_ytd'] = isset($y['eigenverbrauchsquote']) ? round((float)$y['eigenverbrauchsquote'] * 100, 1) : null;
            }
            foreach ($this->stromSaldo->compute()['yearly'] ?? [] as $y) {
                if ((int)($y['year'] ?? 0) === $year) $out['strom_saldo_ytd'] = round((float)($y['saldo_netto'] ?? 0), 2);
            }
        } catch (\Throwable) {
            // PV/Saldo fehlen → null
        }
        return $out;
    }

    /** @return list<string> */
    private function activeUtilities(): array
    {
        $a = $this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
        if (!is_array($a) || empty($a)) return Utilities::keys();
        return array_values(array_filter($a, fn($k) => is_string($k) && Utilities::exists($k)));
    }
}
