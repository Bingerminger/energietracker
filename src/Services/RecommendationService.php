<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;

/**
 * v1.3.0 — Empfehlungs-Engine (rein statistisch, keine externen APIs).
 *
 * Sieben Regelfamilien, jede liefert 0..n Empfehlungen mit:
 *   id        stabil (Hash aus Regel+Kontext) → Dismiss bleibt haften
 *   severity  info | warning | urgent
 *   category  effizienz | vertrag | bestand | anomalie | trend
 *   title     kurze Überschrift
 *   detail    erklärender Satz mit konkreten Zahlen
 *   evidence  {utility, meter_id, ym?} für UI-Verlinkung
 *
 * Dismiss-State liegt in data/recommendations_dismissed.json:
 *   { "<id>": "YYYY-MM-DD" }  (ausgeblendet bis Datum)
 */
final class RecommendationService
{
    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private ConsumptionService $consumption,
        private SettingsService $settings,
        private BenchmarkService $benchmark,
        private DeliveryService $deliveries,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function all(bool $includeDismissed = false): array
    {
        $recs = [];
        $activeUtilities = $this->activeUtilities();

        foreach ($activeUtilities as $utility) {
            foreach ($this->meters->list($utility) as $meter) {
                if (($meter['active'] ?? true) === false) continue;
                $monthly = $this->consumption->forMeter($utility, $meter);
                if (!empty($monthly)) {
                    $recs = array_merge($recs, $this->ruleWeatherIndependent($utility, $meter, $monthly));
                    $recs = array_merge($recs, $this->ruleTrend($utility, $meter, $monthly));
                    $recs = array_merge($recs, $this->ruleSummerHeating($utility, $meter, $monthly));
                    $recs = array_merge($recs, $this->ruleAnomaly($utility, $meter, $monthly));
                }
                if (Utilities::isDelivery($utility)) {
                    $recs = array_merge($recs, $this->ruleTankLevel($utility, $meter));
                }
                $recs = array_merge($recs, $this->ruleContractEnd($utility, $meter));
            }
        }
        $recs = array_merge($recs, $this->ruleEfficiencyClass());

        // Dismiss-Filter
        if (!$includeDismissed) {
            $dismissed = $this->dismissedMap();
            $today = date('Y-m-d');
            $recs = array_values(array_filter($recs, function ($r) use ($dismissed, $today) {
                $until = $dismissed[$r['id']] ?? null;
                return $until === null || $until < $today;
            }));
        }

        // Sortierung: severity-Rang, dann Kategorie
        $rank = ['urgent' => 0, 'warning' => 1, 'info' => 2];
        usort($recs, fn($a, $b) =>
            ($rank[$a['severity']] ?? 3) <=> ($rank[$b['severity']] ?? 3)
        );
        return $recs;
    }

    public function dismiss(string $id, ?string $until = null): void
    {
        $map = $this->dismissedMap();
        // Default: 30 Tage ausblenden
        $map[$id] = $until ?: date('Y-m-d', strtotime('+30 days'));
        $this->store->write('recommendations_dismissed.json', $map);
    }

    // ─── Regelfamilien ───────────────────────────────────────────────────

    /** R1 — wetterbereinigter Mehrverbrauch (> Mittel + Nσ). */
    private function ruleWeatherIndependent(string $utility, array $meter, array $monthly): array
    {
        if (!Utilities::isHgtRelevant($utility)) return [];
        $sigma = (float)$this->settings->get('recommendation_anomaly_sigma', 2.0);
        $adj = [];
        foreach ($monthly as $m) {
            if (($m['weather_adjusted'] ?? null) !== null) $adj[] = (float)$m['weather_adjusted'];
        }
        if (count($adj) < 6) return [];
        $mean = array_sum($adj) / count($adj);
        $sd = $this->stddev($adj, $mean);
        if ($sd <= 0) return [];

        $out = [];
        foreach ($monthly as $m) {
            $wa = $m['weather_adjusted'] ?? null;
            if ($wa === null) continue;
            $z = ((float)$wa - $mean) / $sd;
            if ($z >= $sigma) {
                $pct = round(((float)$wa - $mean) / $mean * 100);
                $out[] = $this->mk(
                    'r1', [$utility, $meter['id'], $m['ym']],
                    'warning', 'anomalie',
                    sprintf('%s %s: wetterbereinigt %+d %% über Mittel', Utilities::get($utility)['label'], $m['ym'], $pct),
                    sprintf('Im Monat %s lag der wetterbereinigte Verbrauch %+d %% über deinem Durchschnitt — und es war nicht kälter. Ein Geräte- oder Heizungs-Check kann sich lohnen.', $m['ym'], $pct),
                    ['utility' => $utility, 'meter_id' => $meter['id'], 'ym' => $m['ym']]
                );
            }
        }
        return $out;
    }

    /** R2 — kontinuierlicher Trend der wetterbereinigten Reihe. */
    private function ruleTrend(string $utility, array $meter, array $monthly): array
    {
        if (!Utilities::isHgtRelevant($utility)) return [];
        $pts = [];
        $i = 0;
        foreach ($monthly as $m) {
            if (($m['weather_adjusted'] ?? null) !== null) {
                $pts[] = [$i, (float)$m['weather_adjusted']];
            }
            $i++;
        }
        if (count($pts) < 12) return [];
        // einfache lineare Regression der letzten 12 Punkte
        $last = array_slice($pts, -12);
        $n = count($last);
        $sx = $sy = $sxy = $sxx = 0.0;
        foreach ($last as [$x, $y]) { $sx += $x; $sy += $y; $sxy += $x * $y; $sxx += $x * $x; }
        $den = $n * $sxx - $sx * $sx;
        if (abs($den) < 1e-9) return [];
        $slope = ($n * $sxy - $sx * $sy) / $den;
        $meanY = $sy / $n;
        if ($meanY <= 0) return [];
        $pctPerYear = ($slope * 12) / $meanY * 100;
        $threshold = (float)$this->settings->get('recommendation_trend_pct_year', 3.0);
        if ($pctPerYear >= $threshold) {
            return [$this->mk(
                'r2', [$utility, $meter['id']],
                'warning', 'trend',
                sprintf('%s: steigender Trend %+.1f %%/Jahr', Utilities::get($utility)['label'], $pctPerYear),
                sprintf('Der wetterbereinigte Verbrauch steigt um etwa %+.1f %% pro Jahr — eine schleichende Verschlechterung. Prüfe, ob Geräte altern oder sich Nutzungsgewohnheiten geändert haben.', $pctPerYear),
                ['utility' => $utility, 'meter_id' => $meter['id']]
            )];
        }
        return [];
    }

    /** R3 — Sommer-Heizverbrauch unplausibel hoch (Gas/Heizöl/Pellets). */
    private function ruleSummerHeating(string $utility, array $meter, array $monthly): array
    {
        if (!Utilities::isHgtRelevant($utility)) return [];
        // pro Jahr: Juli vs. Januar
        $byYear = [];
        foreach ($monthly as $m) {
            $y = (int)($m['year'] ?? 0); $mn = (int)($m['month'] ?? 0);
            if ($mn === 1)  $byYear[$y]['jan'] = (float)($m['kwh'] ?? 0);
            if ($mn === 7)  $byYear[$y]['jul'] = (float)($m['kwh'] ?? 0);
        }
        $out = [];
        foreach ($byYear as $y => $v) {
            if (!isset($v['jan'], $v['jul']) || $v['jan'] <= 0) continue;
            $ratio = $v['jul'] / $v['jan'];
            if ($ratio > 0.30) {
                $out[] = $this->mk(
                    'r3', [$utility, $meter['id'], (string)$y],
                    'info', 'effizienz',
                    sprintf('%s %d: hoher Sommerverbrauch', Utilities::get($utility)['label'], $y),
                    sprintf('Im Juli %d lag der Verbrauch bei %d %% des Januarwerts. Bei reiner Raumheizung sollte das deutlich niedriger sein — vermutlich ineffiziente Warmwasserbereitung.', $y, round($ratio * 100)),
                    ['utility' => $utility, 'meter_id' => $meter['id'], 'ym' => sprintf('%d-07', $y)]
                );
            }
        }
        return $out;
    }

    /** R4 — Anomalie ohne erklärenden Wetterkontext. */
    private function ruleAnomaly(string $utility, array $meter, array $monthly): array
    {
        // grobe Anomalie auf dem Rohverbrauch, aber nur melden wenn der
        // wetterbereinigte Wert ebenfalls auffällig ist (sonst durch
        // Wetter erklärt → R1 greift bereits)
        $vals = array_map(fn($m) => (float)($m['kwh'] ?? 0), $monthly);
        $vals = array_values(array_filter($vals, fn($v) => $v > 0));
        if (count($vals) < 8) return [];
        $mean = array_sum($vals) / count($vals);
        $sd = $this->stddev($vals, $mean);
        if ($sd <= 0) return [];
        $sigma = (float)$this->settings->get('anomaly_threshold', 2.0);

        $out = [];
        foreach ($monthly as $m) {
            $v = (float)($m['kwh'] ?? 0);
            if ($v <= 0) continue;
            $z = abs($v - $mean) / $sd;
            $waNull = ($m['weather_adjusted'] ?? null) === null;
            if ($z >= $sigma && $waNull) {
                $out[] = $this->mk(
                    'r4', [$utility, $meter['id'], $m['ym']],
                    'info', 'anomalie',
                    sprintf('%s %s: statistische Anomalie', Utilities::get($utility)['label'], $m['ym']),
                    sprintf('Der Verbrauch in %s weicht ungewöhnlich stark ab (%.1fσ) und ist nicht durch das Wetter erklärbar. Lohnt einen prüfenden Blick.', $m['ym'], $z),
                    ['utility' => $utility, 'meter_id' => $meter['id'], 'ym' => $m['ym']]
                );
            }
        }
        return $out;
    }

    /** R5 — Tank-/Lagerbestand kritisch (Heizöl/Pellets). */
    private function ruleTankLevel(string $utility, array $meter): array
    {
        $warnPct = (float)$this->settings->get('tank_warn_pct', 15);
        try {
            $hist = $this->deliveries->stockHistory($utility, (string)$meter['id'], $this->consumption);
        } catch (\Throwable) {
            return [];
        }
        $cap = $hist['capacity'] ?? null;
        $days = $hist['days'] ?? [];
        if (!$cap || $cap <= 0 || empty($days)) return [];
        $last = end($days);
        $stock = (float)($last['stock'] ?? 0);
        $pct = $stock / $cap * 100;
        if ($pct <= $warnPct) {
            return [$this->mk(
                'r5', [$utility, $meter['id']],
                $pct <= ($warnPct / 2) ? 'urgent' : 'warning', 'bestand',
                sprintf('%s: Tank bei %d %%', Utilities::get($utility)['label'], round($pct)),
                sprintf('Der modellierte Bestand von „%s" liegt bei rund %s %s (%d %% der Kapazität). Eine Lieferung sollte eingeplant werden.',
                    (string)($meter['name'] ?? $meter['id']), round($stock), $hist['capacity_unit'] ?? '', round($pct)),
                ['utility' => $utility, 'meter_id' => $meter['id']]
            )];
        }
        return [];
    }

    /** R6 — Vertragsende naht. */
    private function ruleContractEnd(string $utility, array $meter): array
    {
        try {
            $status = $this->consumption->contractStatus($utility, $meter);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($status['contracts'] ?? [] as $c) {
            if (!($c['should_remind'] ?? false)) continue;
            $days = $c['days_until_end'] ?? null;
            if ($days === null || $days < 0) continue;
            $out[] = $this->mk(
                'r6', [$utility, $meter['id'], (string)($c['contract_id'] ?? $c['id'] ?? '')],
                $days <= 14 ? 'urgent' : 'warning', 'vertrag',
                sprintf('%s: Vertrag endet in %d Tagen', Utilities::get($utility)['label'], $days),
                sprintf('Der Vertrag „%s" endet in %d Tagen. Jetzt Tarifvergleich starten und ggf. wechseln.',
                    (string)($c['provider'] ?? $c['tariff_name'] ?? '—'), $days),
                ['utility' => $utility, 'meter_id' => $meter['id']]
            );
        }
        return $out;
    }

    /** R7 — Effizienzklassen-Hinweis, PRO Heizquelle (v1.4.0). */
    private function ruleEfficiencyClass(): array
    {
        $eff = $this->benchmark->efficiency();
        $perSource = $eff['per_source'] ?? [];
        if (empty($perSource)) return [];

        $weak = ['E', 'F', 'G', 'H'];
        $out = [];
        foreach ($perSource as $s) {
            $class = $s['class'] ?? null;
            $kwhM2 = $s['kwh_per_m2'] ?? null;
            if ($class === null || $kwhM2 === null) continue;
            if (!in_array($class, $weak, true)) continue;
            $out[] = $this->mk(
                'r7', ['efficiency', (string)$s['utility'], (string)$eff['year']],
                ($class === 'H' || $class === 'G') ? 'warning' : 'info', 'effizienz',
                sprintf('%s: Effizienzklasse %s (%.0f kWh/m²·a)', $s['label'], $class, $kwhM2),
                sprintf('Der Heizenergiebedarf %d über %s entspricht Effizienzklasse %s (%.0f kWh/m²·a). Dämmung, Heizungsoptimierung oder ein hydraulischer Abgleich können die Klasse spürbar verbessern.',
                    $eff['year'], $s['label'], $class, $kwhM2),
                ['utility' => (string)$s['utility'], 'meter_id' => null]
            );
        }
        return $out;
    }

    // ─── Helfer ──────────────────────────────────────────────────────────

    private function mk(string $rule, array $ctx, string $severity, string $category, string $title, string $detail, array $evidence): array
    {
        $id = $rule . '_' . substr(md5($rule . '|' . implode('|', $ctx)), 0, 12);
        return [
            'id'       => $id,
            'severity' => $severity,
            'category' => $category,
            'title'    => $title,
            'detail'   => $detail,
            'evidence' => $evidence,
        ];
    }

    private function stddev(array $vals, float $mean): float
    {
        $n = count($vals);
        if ($n < 2) return 0.0;
        $s = 0.0;
        foreach ($vals as $v) $s += ($v - $mean) ** 2;
        return sqrt($s / ($n - 1));
    }

    private function dismissedMap(): array
    {
        $m = $this->store->read('recommendations_dismissed.json', []);
        return is_array($m) ? $m : [];
    }

    private function activeUtilities(): array
    {
        $a = $this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
        if (!is_array($a) || empty($a)) return Utilities::keys();
        return array_values(array_filter($a, fn($k) => Utilities::exists($k)));
    }
}
