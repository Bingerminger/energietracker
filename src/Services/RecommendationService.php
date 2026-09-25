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
                // v2.9.0 (CALC-15) — außer Betrieb: keine Empfehlungen
                if (!MeterService::inService($meter)) continue;
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

    /**
     * v2.12.0 (Review UI-22) — Ausblenden zurücknehmen („Rückgängig").
     * Eine nicht ausgeblendete ID ist kein Fehler: Das Ergebnis ist dasselbe.
     */
    public function restore(string $id): void
    {
        $map = $this->dismissedMap();
        if (!array_key_exists($id, $map)) return;
        unset($map[$id]);
        $this->store->write('recommendations_dismissed.json', $map);
    }

    // ─── Regelfamilien ───────────────────────────────────────────────────

    /**
     * R1 — Mehrverbrauch, den das Wetter nicht erklärt.
     *
     * v2.8.0 (Review CALC-05) — gemessen am Heizmodell desselben Monats
     * (`expected_heat`: so viel wäre bei diesem Wetter zu erwarten), nicht
     * mehr am Mittel aller wetterbereinigten Monate. Das alte Maß verglich
     * Januar mit Oktober und übersah einen echten +35-%-Februar. Streuung robust
     * (Median/MAD der Residuen), nur Monate mit genug Tagen.
     */
    private function ruleWeatherIndependent(string $utility, array $meter, array $monthly): array
    {
        if (!Utilities::isHgtRelevant($utility) || Utilities::isDelivery($utility)) return [];
        $sigma = (float)$this->settings->get('recommendation_anomaly_sigma', 2.0);
        $valueField = $this->valueField($utility);
        // v1.4.0 — F1011: nur die aktuelle Epoche (`expected_heat` ist davor null)
        $res = [];
        foreach ($monthly as $i => $m) {
            if (($m['weather_delta_pct'] ?? null) === null) continue;
            $res[$i] = (float)($m[$valueField] ?? 0) - (float)$m['expected_heat'];
        }
        if (count($res) < 6) return [];
        [$center, $scale] = AnomalyService::robustScale(array_values($res));
        $typical = AnomalyService::typical(array_map(fn($i) => (float)$monthly[$i]['expected_heat'], array_keys($res)));
        if (max($scale, AnomalyService::noiseFloor(0.0, $typical)) <= 0) return [];

        $out = [];
        foreach ($res as $i => $r) {
            $m = $monthly[$i];
            // gleiche Untergrenze wie die Anomalie-Erkennung: kleine Schwankungen sind Alltag
            $z = ($r - $center) / max($scale, AnomalyService::noiseFloor((float)$m['expected_heat'], $typical));
            if ($z >= $sigma) {
                $pct = round((float)$m['weather_delta_pct']);
                $out[] = $this->mk(
                    'r1', [$utility, $meter['id'], $m['ym']],
                    'warning', 'anomalie',
                    $this->i18n->t('recommendations.engine.r1.title', ['label' => $this->utilLabel($utility), 'ym' => $this->i18n->month($m['ym']), 'pct' => $this->signed($pct, 0)]),
                    $this->i18n->t('recommendations.engine.r1.detail', ['ym' => $this->i18n->month($m['ym']), 'pct' => $this->signed($pct, 0)]),
                    ['utility' => $utility, 'meter_id' => $meter['id'], 'ym' => $m['ym']]
                );
            }
        }
        return $out;
    }

    /**
     * R2 — Verbrauch steigt von Jahr zu Jahr.
     *
     * v2.8.0 (Review CALC-05) — Vorjahresvergleich derselben Kalendermonate
     * auf der witterungsbereinigten Reihe (`heat_adjusted`). Die alte
     * Regression über die letzten zwölf Punkte maß vor allem, in welchem Monat
     * die Daten enden: Auf trendfreien Daten meldete sie je nach Endmonat
     * +7,1 % oder +36 % „pro Jahr".
     */
    private function ruleTrend(string $utility, array $meter, array $monthly): array
    {
        if (!Utilities::isHgtRelevant($utility) || Utilities::isDelivery($utility)) return [];
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $byYm = [];
        foreach ($monthly as $m) {
            // F1011: nur die aktuelle Epoche; Teilmonate verzerren den Vergleich
            if (!empty($m['pre_baseline']) || (int)($m['days'] ?? 0) < $minDays) continue;
            if (($m['heat_adjusted'] ?? null) === null) continue;
            $byYm[(string)$m['ym']] = (float)$m['heat_adjusted'];
        }
        if (!$byYm) return [];
        $yms = array_keys($byYm);
        sort($yms);
        $lastYm = end($yms);
        $cur = $prev = 0.0; $pairs = 0;
        for ($k = 0; $k < 12; $k++) {
            $ym  = date('Y-m', strtotime($lastYm . '-01 -' . $k . ' months'));
            $pym = date('Y-m', strtotime($lastYm . '-01 -' . ($k + 12) . ' months'));
            if (!isset($byYm[$ym], $byYm[$pym])) continue;
            $cur += $byYm[$ym]; $prev += $byYm[$pym]; $pairs++;
        }
        if ($pairs < 9 || $prev <= 0) return [];
        $pctPerYear = ($cur / $prev - 1) * 100;
        $threshold = (float)$this->settings->get('recommendation_trend_pct_year', 3.0);
        if ($pctPerYear >= $threshold) {
            return [$this->mk(
                'r2', [$utility, $meter['id']],
                'warning', 'trend',
                $this->i18n->t('recommendations.engine.r2.title', ['label' => $this->utilLabel($utility), 'pct' => $this->signed($pctPerYear, 1)]),
                $this->i18n->t('recommendations.engine.r2.detail', ['pct' => $this->signed($pctPerYear, 1)]),
                ['utility' => $utility, 'meter_id' => $meter['id']]
            )];
        }
        return [];
    }

    /** R3 — Sommer-Heizverbrauch unplausibel hoch (Gas/Fernwärme). */
    private function ruleSummerHeating(string $utility, array $meter, array $monthly): array
    {
        // v2.8.0 — Lieferarten ausgenommen: Ihr Sommeranteil ist ein Produkt der
        // Verteilung nach Gradtagen, keine Messung. Und nur volle Monate — ein
        // 14-Tage-Juli halbiert den Anteil.
        if (!Utilities::isHgtRelevant($utility) || Utilities::isDelivery($utility)) return [];
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $valueField = $this->valueField($utility);
        // pro Jahr: Juli vs. Januar
        $byYear = [];
        foreach ($monthly as $m) {
            if ((int)($m['days'] ?? 0) < $minDays) continue;
            $y = (int)($m['year'] ?? 0); $mn = (int)($m['month'] ?? 0);
            if ($mn === 1)  $byYear[$y]['jan'] = (float)($m[$valueField] ?? 0);
            if ($mn === 7)  $byYear[$y]['jul'] = (float)($m[$valueField] ?? 0);
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

    /**
     * R4 — ungewöhnlich hoher Monat bei Arten ohne Wetterbezug.
     *
     * v2.8.0 (Review CALC-06) — aus der Anomalie-Erkennung statt eines eigenen,
     * rohen z-Scores: Der alte Weg kannte keine Jahreszeit und keinen
     * Teilmonat (ein 14-Tage-März galt als „3,9σ") und lief für Wasser nie,
     * weil er `kwh` las, wo Wasser `m3` führt. Heizarten deckt R1 ab.
     */
    private function ruleAnomaly(string $utility, array $meter, array $monthly): array
    {
        if (Utilities::isHgtRelevant($utility) || Utilities::isDelivery($utility)) return [];
        // PV: ein ertragreicher Monat ist kein Warnsignal
        if (Utilities::isFeedIn($utility) || Utilities::isGenerationOnly($utility)) return [];
        $out = [];
        foreach ($this->anomalies()->detect($utility, $monthly) as $a) {
            if (($a['kind'] ?? '') !== 'high') continue;
            $out[] = $this->mk(
                'r4', [$utility, $meter['id'], $a['ym']],
                'info', 'anomalie',
                $this->i18n->t('recommendations.engine.r4.title', ['label' => $this->utilLabel($utility), 'ym' => $this->i18n->month($a['ym'])]),
                $this->i18n->t('recommendations.engine.r4.detail', ['ym' => $this->i18n->month($a['ym']), 'sigma' => $this->i18n->number(abs((float)$a['z_score']), 1)]),
                ['utility' => $utility, 'meter_id' => $meter['id'], 'ym' => $a['ym']]
            );
        }
        return $out;
    }

    private function anomalies(): AnomalyService
    {
        return $this->anomalyService ??= new AnomalyService(new RegressionService(), $this->settings);
    }

    private ?AnomalyService $anomalyService = null;

    private function valueField(string $utility): string
    {
        return (Utilities::get($utility)['consumption_unit'] ?? '') === 'kWh' ? 'kwh' : 'm3';
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
                    'stock' => $this->i18n->number($stock, 0),
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
            // v2.9.0 (Review CALC-11) — mit gepflegter Frist zählt der
            // Kündigungsstichtag; bisher kam die Erinnerung zum Vertragsende,
            // oft nach Ablauf der Frist
            $byCancel = ($c['remind_basis'] ?? null) === 'cancel_by';
            $days = $byCancel ? ($c['days_to_cancel'] ?? null) : ($c['days_until_end'] ?? null);
            if ($days === null || $days < 0) continue;
            $provider = (string)($c['provider'] ?? $c['tariff_name'] ?? '—');
            $out[] = $this->mk(
                'r6', [$utility, $meter['id'], (string)($c['contract_id'] ?? $c['id'] ?? '')],
                $days <= 14 ? 'urgent' : 'warning', 'vertrag',
                $byCancel
                    ? $this->i18n->t('recommendations.engine.r6.titleCancel', ['label' => $this->utilLabel($utility), 'days' => $days, 'date' => $this->i18n->date((string)$c['cancel_by'])])
                    : $this->i18n->t('recommendations.engine.r6.title', ['label' => $this->utilLabel($utility), 'days' => $days]),
                $byCancel
                    ? $this->i18n->t('recommendations.engine.r6.detailCancel', [
                        'provider' => $provider,
                        'date'     => $this->i18n->date((string)$c['cancel_by']),
                        'end'      => $this->i18n->date((string)($c['end'] ?? '')),
                    ])
                    : $this->i18n->t('recommendations.engine.r6.detail', ['provider' => $provider, 'days' => $days]),
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
                $this->i18n->t('recommendations.engine.r7.title', ['label' => $this->utilLabel((string)$s['utility']), 'class' => $class, 'kwh' => $this->i18n->number((float)$kwhM2, 0)]),
                $this->i18n->t('recommendations.engine.r7.detail', [
                    'year'  => $eff['year'],
                    'label' => $this->utilLabel((string)$s['utility']),
                    'class' => $class,
                    'kwh'   => $this->i18n->number((float)$kwhM2, 0),
                ]),
                ['utility' => (string)$s['utility'], 'meter_id' => null]
            );
        }
        return $out;
    }

    // ─── Helfer ──────────────────────────────────────────────────────────

    /**
     * v2.7.0 — Vorzeichenbehaftete Zahl in der Schreibweise von Sprache und
     * Land („+3,5" / „+3.5"). Vorher sprintf('%+.1f') — immer mit Punkt.
     */
    private function signed(float $v, int $decimals): string
    {
        return ($v < 0 ? '-' : '+') . $this->i18n->number(abs($v), $decimals);
    }

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
