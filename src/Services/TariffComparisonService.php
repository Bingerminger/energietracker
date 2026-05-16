<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v1.3.0 — Tarif-Was-wäre-wenn (Schattenverträge).
 *
 * Wendet jeden Vertrag eines Zählers (echt + Schatten) auf die TATSÄCHLICH
 * gemessenen historischen Monatsverbräuche an und stellt die fiktiven
 * Gesamtkosten gegenüber. So lässt sich beantworten: „Was hätte ich bei
 * Tarif X im Jahr Y bezahlt?"
 *
 * Bewusst NICHT für Wasser (Drei-Komponenten-Tarifmodell — ein
 * Tarifvergleich dort wäre ein eigenes Feature mit eigener UI; out of
 * scope für v1.3.0). Für Wasser liefert der Service eine leere Liste mit
 * Hinweis.
 *
 * Die Verbrauchsdaten kommen aus ConsumptionService::forMeter() OHNE
 * Vertragsanwendung-Verfälschung — wir nutzen das rohe `kwh`/`m3` und
 * rechnen die Verträge hier frisch drauf.
 */
final class TariffComparisonService
{
    public function __construct(
        private ConsumptionService $consumption,
        private ContractService $contracts,
        private MeterService $meters,
    ) {}

    /**
     * @return array{
     *   utility: string,
     *   meter_id: string,
     *   period: array{from: ?string, to: ?string, label: string},
     *   supported: bool,
     *   note: ?string,
     *   rows: array<int, array{
     *     contract_id: string, label: string, is_shadow: bool,
     *     provider: string, tariff_name: string,
     *     consumption: float, total_eur: ?float,
     *     vs_real_eur: ?float
     *   }>
     * }
     */
    public function compare(string $utility, string $meterId, ?int $year = null): array
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException('Unbekannte Verbrauchsart: ' . $utility);
        }
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) {
            throw new \RuntimeException('Zähler nicht gefunden: ' . $meterId);
        }

        if ($utility === 'wasser') {
            return [
                'utility'  => $utility, 'meter_id' => $meterId,
                'period'   => ['from' => null, 'to' => null, 'label' => '—'],
                'supported'=> false,
                'note'     => 'Tarifvergleich für Wasser (Drei-Komponenten-Tarif) ist in dieser Version nicht enthalten.',
                'rows'     => [],
            ];
        }

        // Rohe Monatsverbräuche (Vertragsanwendung interessiert uns hier
        // nicht — wir rechnen jeden Vertrag selbst frisch drauf).
        $monthly = $this->consumption->forMeter($utility, $meter);
        if (empty($monthly)) {
            return [
                'utility'  => $utility, 'meter_id' => $meterId,
                'period'   => ['from' => null, 'to' => null, 'label' => '—'],
                'supported'=> true,
                'note'     => 'Keine Verbrauchsdaten vorhanden.',
                'rows'     => [],
            ];
        }

        // Zeitraum filtern
        if ($year !== null) {
            $monthly = array_values(array_filter(
                $monthly, fn($m) => (int)($m['year'] ?? 0) === $year
            ));
            $label = (string)$year;
        } else {
            $label = 'Gesamter Zeitraum';
        }
        if (empty($monthly)) {
            return [
                'utility'  => $utility, 'meter_id' => $meterId,
                'period'   => ['from' => null, 'to' => null, 'label' => $label],
                'supported'=> true,
                'note'     => 'Keine Verbrauchsdaten im gewählten Zeitraum.',
                'rows'     => [],
            ];
        }

        $from = $monthly[0]['ym'] ?? null;
        $to   = $monthly[count($monthly) - 1]['ym'] ?? null;

        $allContracts = $this->contracts->list($utility, $meterId);

        // Den/die echten Vertrag/Verträge im Zeitraum als Referenz: deren
        // Summe der tatsächlichen kwh_cost ist die „real bezahlt"-Linie.
        $realTotal = 0.0;
        $realSeen  = false;
        foreach ($monthly as $m) {
            // forMeter() hat bereits die echten (Nicht-Schatten) Verträge
            // angewandt → m['cost'] ist die reale Vertragsrechnung.
            if (isset($m['cost'])) {
                $realTotal += (float)$m['cost'];
                $realSeen = true;
            }
        }

        $rows = [];
        foreach ($allContracts as $c) {
            $isShadow = !empty($c['is_shadow']);
            $total = $this->totalForContract($c, $monthly);
            $label2 = $isShadow
                ? ((string)($c['shadow_label'] ?: $c['tariff_name'] ?: 'Hypothese'))
                : trim(((string)($c['provider'] ?? '')) . ' ' . ((string)($c['tariff_name'] ?? '')));
            if ($label2 === '') $label2 = $c['id'];

            $rows[] = [
                'contract_id' => (string)$c['id'],
                'label'       => $label2,
                'is_shadow'   => $isShadow,
                'provider'    => (string)($c['provider'] ?? ''),
                'tariff_name' => (string)($c['tariff_name'] ?? ''),
                'consumption' => round($this->sumConsumption($monthly), 1),
                'total_eur'   => $total !== null ? round($total, 2) : null,
                'vs_real_eur' => ($total !== null && $realSeen)
                    ? round($total - $realTotal, 2)
                    : null,
            ];
        }

        // Sortierung: echte zuerst, dann Schatten nach Kosten aufsteigend
        usort($rows, function ($a, $b) {
            if ($a['is_shadow'] !== $b['is_shadow']) {
                return $a['is_shadow'] <=> $b['is_shadow'];
            }
            return ($a['total_eur'] ?? PHP_FLOAT_MAX) <=> ($b['total_eur'] ?? PHP_FLOAT_MAX);
        });

        return [
            'utility'  => $utility,
            'meter_id' => $meterId,
            'period'   => ['from' => $from, 'to' => $to, 'label' => $label],
            'supported'=> true,
            'note'     => null,
            'rows'     => $rows,
        ];
    }

    /**
     * Gesamtkosten eines Vertrags über die Monatsreihe:
     *   Σ (kwh × Arbeitspreis/100 + Grundpreis − Bonus)
     * Monate außerhalb der Vertragslaufzeit zählen nicht mit.
     * Gibt null zurück, wenn kein einziger Monat im Vertrag liegt.
     */
    private function totalForContract(array $c, array $monthly): ?float
    {
        $total = 0.0;
        $any = false;
        foreach ($monthly as $m) {
            $first = ($m['ym'] ?? '') . '-01';
            // findActiveForDate berücksichtigt start/end des Vertrags
            $active = $this->contracts->findActiveForDate([$c], $first);
            if (!$active) continue;

            $y = (int)($m['year'] ?? 0);
            $mn = (int)($m['month'] ?? 0);
            $wp = $this->contracts->valueValidOn($c['working_prices'] ?? [], 'ct_per_kwh', $y, $mn);
            $bp = $this->contracts->valueValidOn($c['base_prices']    ?? [], 'eur_per_month', $y, $mn);
            $bn = $this->contracts->bonusForMonth($c, $y, $mn);

            $value = (float)($m['kwh'] ?? 0);
            if ($wp === null) continue; // ohne Arbeitspreis nicht rechenbar
            $monthCost = $value * $wp / 100.0 + (float)($bp ?? 0) - $bn;
            $total += $monthCost;
            $any = true;
        }
        return $any ? $total : null;
    }

    private function sumConsumption(array $monthly): float
    {
        $s = 0.0;
        foreach ($monthly as $m) $s += (float)($m['kwh'] ?? 0);
        return $s;
    }
}
