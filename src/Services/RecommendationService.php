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
        private I18nService $i18n,
    ) {}

    /** Lokalisiertes Verbrauchsart-Label (zentraler Fallback seit v2.2.0). */
    private function utilLabel(string $utility): string
    {
        return $this->i18n->utilityLabel($utility);
    }

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
        // v1.4.0 — F1011: Der Vergleichsmaßstab darf nur aus der aktuellen
        // Epoche kommen. Sonst liegt der Mittelwert bei einem sanierten Haus
        // dauerhaft zu hoch, R1 löst nie mehr aus — und ein echter
        // Mehrverbrauch nach der Maßnahme bleibt unbemerkt.
        $adj = [];
        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline'])) continue;
            if (($m['weather_adjusted'] ?? null) !== null) $adj[] = (float)$m['weather_adjusted'];
        }
        if (count($adj) < 6) return [];
        $mean = array_sum($adj) / count($adj);
        $sd = $this->stddev($adj, $mean);
        if ($sd <= 0) return [];

        $out = [];
        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline'])) continue;
            $wa = $m['weather_adjusted'] ?? null;
            if ($wa === null) continue;
            $z = ((float)$wa - $mean) / $sd;
            if ($z >= $sigma) {
                $pct = round(((float)$wa - $mean) / $mean * 100);
                $out[] = $this->mk(
                    'r1', [$utility, $meter['id'], $m['ym']],
                    'warning', 'anomalie',
                    $this->i18n->t('recommendations.engine.r1.title', ['label' => $this->utilLabel($utility), 'ym' => $m['ym'], 'pct' => sprintf('%+d', $pct)]),
                    $this->i18n->t('recommendations.engine.r1.detail', ['ym' => $m['ym'], 'pct' => sprintf('%+d', $pct)]),
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
        // v1.4.0 — F1011: Ein Trend über die Zäsur hinweg misst den Umbau,
        // nicht das Verbrauchsverhalten — und meldet auf Jahre hinaus einen
        // starken Rückgang. `$i` läuft trotzdem über alle Monate weiter, damit
        // die Abstände auf der Zeitachse stimmen.
        $pts = [];
        $i = 0;
        foreach ($monthly as $m) {
            if (empty($m['pre_baseline']) && ($m['weather_adjusted'] ?? null) !== null) {
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
                $this->i18n->t('recommendations.engine.r2.title', ['label' => $this->utilLabel($utility), 'pct' => sprintf('%+.1f', $pctPerYear)]),
                $this->i18n->t('recommendations.engine.r2.detail', ['pct' => sprintf('%+.1f', $pctPerYear)]),
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
                    $this->i18n->t('recommendations.engine.r3.title', ['label' => $this->utilLabel($utility), 'year' => $y]),
                    $this->i18n->t('recommendations.engine.r3.detail', ['year' => $y, 'pct' => (int)round($ratio * 100)]),
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
        // v1.4.0 — F1011: auch der Rohmittelwert nur aus der aktuellen Epoche.
        $vals = [];
        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline'])) continue;
            $v = (float)($m['kwh'] ?? 0);
            if ($v > 0) $vals[] = $v;
        }
        if (count($vals) < 8) return [];
        $mean = array_sum($vals) / count($vals);
        $sd = $this->stddev($vals, $mean);
        if ($sd <= 0) return [];
        $sigma = (float)$this->settings->get('anomaly_threshold', 2.0);

        $out = [];
        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline'])) continue;
            $v = (float)($m['kwh'] ?? 0);
            if ($v <= 0) continue;
            $z = abs($v - $mean) / $sd;
            $waNull = ($m['weather_adjusted'] ?? null) === null;
            if ($z >= $sigma && $waNull) {
                $out[] = $this->mk(
                    'r4', [$utility, $meter['id'], $m['ym']],
                    'info', 'anomalie',
                    $this->i18n->t('recommendations.engine.r4.title', ['label' => $this->utilLabel($utility), 'ym' => $m['ym']]),
                    $this->i18n->t('recommendations.engine.r4.detail', ['ym' => $m['ym'], 'sigma' => sprintf('%.1f', $z)]),
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
                $this->i18n->t('recommendations.engine.r5.title', ['label' => $this->utilLabel($utility), 'pct' => (int)round($pct)]),
                $this->i18n->t('recommendations.engine.r5.detail', [
                    'name'  => (string)($meter['name'] ?? $meter['id']),
                    'stock' => (int)round($stock),
                    'unit'  => $hist['capacity_unit'] ?? '',
                    'pct'   => (int)round($pct),
                ]),
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
                $this->i18n->t('recommendations.engine.r6.title', ['label' => $this->utilLabel($utility), 'days' => $days]),
                $this->i18n->t('recommendations.engine.r6.detail', [
                    'provider' => (string)($c['provider'] ?? $c['tariff_name'] ?? '—'),
                    'days'     => $days,
                ]),
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
                $this->i18n->t('recommendations.engine.r7.title', ['label' => $this->utilLabel((string)$s['utility']), 'class' => $class, 'kwh' => sprintf('%.0f', $kwhM2)]),
                $this->i18n->t('recommendations.engine.r7.detail', [
                    'year'  => $eff['year'],
                    'label' => $this->utilLabel((string)$s['utility']),
                    'class' => $class,
                    'kwh'   => sprintf('%.0f', $kwhM2),
                ]),
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
