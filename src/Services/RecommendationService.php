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

    /**
     * v3.4.0 (F1024) — Verlauf: Ausblenden wird protokolliert; ein von Hand
     * abgehakter Hinweis (Kündigung, Vertragsende, Preiserhöhung, Tank)
     * erscheint für dieses Vorkommen nicht mehr als Empfehlung.
     */
    private ?ActivityService $activity = null;

    public function attachActivity(ActivityService $activity): void
    {
        $this->activity = $activity;
    }

    /** Für dieses Vorkommen von Hand (oder automatisch) erledigt? */
    private function doneFor(string $uid, string $anchor): bool
    {
        return $this->activity?->doneEntry(ActivityService::occurrence($uid, $anchor)) !== null;
    }

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
                // v3.9.0 (#38) — Heizgrenze anpassen
                $recs = array_merge($recs, $this->ruleHeatLimit($utility, $meter));
            }
            // v3.1.0 (H6, #17) — Gruppenverträge: Fristen und Preiserhöhung wie beim Zähler
            foreach ($this->consumption->contractTargets($utility) as $target) {
                if (!empty($target['is_group'])) {
                    $recs = array_merge($recs, $this->ruleContractEnd($utility, $target));
                    $recs = array_merge($recs, $this->ruleHeatLimit($utility, $target));   // v3.9.0 (#38)
                }
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

    /**
     * v3.4.0 (F1025) — IDs, die am Stichtag ausgeblendet sind. Der
     * Jahresbericht lässt dieselben Hinweise weg wie die Oberfläche.
     *
     * @return list<string>
     */
    public function dismissedIds(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        return array_values(array_map('strval', array_keys(array_filter($this->dismissedMap(), fn($until) => (string)$until >= $today))));
    }

    /**
     * Ausblenden bis `$until`, Standard 30 Tage.
     *
     * v3.4.0 (F1024) — Eine Empfehlung mit Frist (Kündigung, Vertragsende,
     * Preiserhöhung: `evidence.deadline`) bleibt mindestens bis zu diesem Tag
     * ausgeblendet; bis v3.3 kam „Kündigung bis 30.11." am 11.11. zurück. Mit
     * Verlauf entsteht ein Eintrag `dismissed`.
     *
     * @return array{until:string, activity_id:?string}
     */
    public function dismiss(string $id, ?string $until = null): array
    {
        $rec = $this->activity !== null || !$until ? $this->find($id) : null;
        if (!$until) {
            $until = date('Y-m-d', strtotime('+30 days'));
            $deadline = (string)($rec['evidence']['deadline'] ?? '');
            if ($deadline > $until) $until = $deadline;
        }
        $map = $this->dismissedMap();
        $map[$id] = $until;
        $this->store->write('recommendations_dismissed.json', $map);
        $entry = $this->activity?->dismissed($rec ?? ['id' => $id, 'title' => $id], $until);
        return ['until' => $until, 'activity_id' => $entry['id'] ?? null];
    }

    /**
     * v2.12.0 (Review UI-22) — Ausblenden zurücknehmen („Rückgängig").
     * Eine nicht ausgeblendete ID ist kein Fehler: Das Ergebnis ist dasselbe.
     */
    public function restore(string $id): void
    {
        $map = $this->dismissedMap();
        if (array_key_exists($id, $map)) {
            unset($map[$id]);
            $this->store->write('recommendations_dismissed.json', $map);
        }
        $this->activity?->dismissalRestored($id);   // v3.4.0 (F1024)
    }

    /**
     * v3.4.0 (F1024) — Rückgängig im Verlauf: wieder einblenden, solange die
     * Empfehlung noch so ausgeblendet ist wie damals.
     */
    public function restoreIfUntil(string $id, string $until): bool
    {
        if (($this->dismissedMap()[$id] ?? null) !== $until) return false;
        $this->restore($id);
        return true;
    }

    /** Eine Empfehlung (auch ausgeblendet) über ihre ID. */
    private function find(string $id): ?array
    {
        foreach ($this->all(true) as $r) if ($r['id'] === $id) return $r;
        return null;
    }

    /**
     * v3.4.0 (F1024) — Ist der Bestand eines Tanks niedrig (wie R5)? Vor dem
     * Speichern einer Lieferung gefragt: Nur dann erledigt sie „Lieferung planen".
     */
    public function tankLow(string $utility, string $meterId): bool
    {
        $meter = Utilities::isDelivery($utility) ? $this->meters->get($utility, $meterId) : null;
        return $meter !== null && $this->tankStatus($utility, $meter) !== null;
    }

    /**
     * v3.7.0 (#37) — Empfehlungen aus Monaten (R1, R3, R4) nur aus den letzten
     * 24 Monaten. Eine Empfehlung soll zu einer Handlung führen; ein auffälliger
     * Monat vor drei Jahren beschreibt einen Zustand, den eine Prüfung heute
     * nicht mehr findet — bei echten Daten kamen so Empfehlungen aus fünf Jahre
     * alten Monaten. 24 Monate decken zwei Heizperioden ab, R3 (Juli gegen
     * Januar) hat damit auch das Vorjahr. Die Abweichungstabelle der Analyse
     * zeigt weiter die ganze Geschichte.
     */
    public const RECENT_MONTHS = 24;

    /** Liegt der Monat `YYYY-MM` in den letzten RECENT_MONTHS Monaten (ab heute)? */
    public static function isRecent(string $ym, ?string $today = null): bool
    {
        $first = (new \DateTimeImmutable(($today ?? date('Y-m-d')) . ' 12:00'))->modify('first day of this month')
            ->modify('-' . self::RECENT_MONTHS . ' months')->format('Y-m');
        return $ym >= $first;
    }

    /** Stichtag für das Fenster der Empfehlungen (Tests mit festen Daten); sonst heute. */
    private ?string $today = null;

    public function atDate(?string $today): static
    {
        $this->today = $today;
        return $this;
    }

    private function recent(string $ym): bool
    {
        return self::isRecent($ym, $this->today);
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
        // v3.7.0 (wirklichkeit-2, Schritt 3, N2) — besteht das Heizmodell die Gütesperre
        // nicht, misst „mehr als das Wetter erklärt“ vor allem den Fehler des Modells
        // (AnomalyService::quality); Analyse und Jahresbericht sagen das an seiner Stelle
        if (($this->anomalies()->quality($utility, $monthly)['model_quality'] ?? null) === 'poor') return [];
        $sigma = (float)$this->settings->get('recommendation_anomaly_sigma', 2.0);
        $valueField = $this->valueField($utility);
        // v1.4.0 — F1011: nur die aktuelle Epoche (`expected_heat` ist davor null)
        $res = [];
        foreach ($monthly as $i => $m) {
            if (($m['weather_delta_pct'] ?? null) === null) continue;
            // v3.7.0 (#37) — verteilte Monate sind nicht gemessen (wie AnomalyService::detect)
            if (!empty($m['interpolated'])) continue;
            $res[$i] = (float)($m[$valueField] ?? 0) - (float)$m['expected_heat'];
        }
        if (count($res) < 6) return [];
        [$center, $scale] = AnomalyService::robustScale(array_values($res));
        $typical = AnomalyService::typical(array_map(fn($i) => (float)$monthly[$i]['expected_heat'], array_keys($res)));
        if (max($scale, AnomalyService::noiseFloor(0.0, $typical)) <= 0) return [];

        // v3.9.0 (#38) — ein Monat, der jedes Jahr gleich danebenliegt, ist ein Muster des
        // Heizmodells, kein Mehrverbrauch (AnomalyService::markPatterns)
        $patterns = AnomalyService::patternMonths($this->anomalies()->detect($utility, $monthly));
        $out = [];
        foreach ($res as $i => $r) {
            $m = $monthly[$i];
            if (!$this->recent((string)$m['ym'])) continue;   // v3.7.0 — nur aus den letzten 24 Monaten
            if (isset($patterns[(string)$m['ym']])) continue;
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
            // v3.7.0 (#37) — verteilte Monate bleiben drin: Verglichen werden Summen
            // über zwölf Monate, und innerhalb eines Ablesungsabstands ändert die
            // Verteilung die Summe nicht; die Bereinigung ist additiv
            // (a × (HGT_normal − HGT_ist) je Monat, Lektion 50).
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
            // v3.7.0 (#37) — ein verteilter Juli oder Januar hat die Tagesrate eines
            // längeren Abstands: Zwei Ablesungen im Jahr ergäben „Juli = Januar“
            if (!empty($m['interpolated'])) continue;
            $y = (int)($m['year'] ?? 0); $mn = (int)($m['month'] ?? 0);
            if ($mn === 1)  $byYear[$y]['jan'] = (float)($m[$valueField] ?? 0);
            if ($mn === 7)  $byYear[$y]['jul'] = (float)($m[$valueField] ?? 0);
        }
        $out = [];
        foreach ($byYear as $y => $v) {
            if (!isset($v['jan'], $v['jul']) || $v['jan'] <= 0) continue;
            if (!$this->recent(sprintf('%d-07', $y))) continue;   // v3.7.0 — nur aus den letzten 24 Monaten
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
            if (!empty($a['pattern'])) continue;   // v3.9.0 (#38) — jedes Jahr derselbe Monat: ein Muster, kein Ereignis
            if (!$this->recent((string)$a['ym'])) continue;   // v3.7.0 — nur aus den letzten 24 Monaten
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

    /**
     * v3.9.0 (#38) — Heizgrenze anpassen: Es gibt für diesen Zähler (oder diese Gruppe
     * mit eigenem Vertrag) eine Heizgrenze, mit der das Heizmodell deutlich besser passt
     * (ConsumptionService::heatLimitSuggestion). Prognose, Saldo und Abschlag hängen am
     * Heizmodell; der Vorschlag stand bis v3.7 nur in der Analyse, beim Maintainer zu weit
     * hinten. Jetzt auch in „Zu tun“ (AgendaService, `heat_limit`) mit Vorher/Nachher und
     * der Wirkung auf Saldo und Abschlag (`evidence.effect`).
     *
     * Die ID trägt den Datenstand (vorgeschlagene Grenze, Zahl der geprüften Monate):
     * „Nicht jetzt“ blendet genau diesen Stand aus; kommt ein gemessener Monat dazu oder
     * ändert sich der Vorschlag, erscheint er wieder.
     */
    private function ruleHeatLimit(string $utility, array $meter): array
    {
        if (!Utilities::isHgtRelevant($utility) || Utilities::isDelivery($utility)) return [];
        $h = $this->consumption->heatLimitSuggestion($utility, $meter);
        if (empty($h['suggest']) || $h['best'] === null) return [];
        $best = (float)$h['best'];
        $effect = $this->consumption->heatLimitEffect($utility, $meter, $best);
        $isGroup = !empty($meter['is_group']);
        $unit = (string)(Utilities::get($utility)['consumption_unit'] ?? 'kWh');
        $deg = fn(float $v) => $this->i18n->number($v, 1);
        $pct = fn(?float $v) => $v === null ? '–' : $this->i18n->number($v * 100, 0) . ' %';
        $detail = [$this->i18n->t('recommendations.engine.heatLimit.detail', [
            'best' => $deg($best), 'current' => $deg((float)$h['current']),
            'now' => $pct($h['before']['ratio'] ?? null), 'then' => $pct($h['after']['ratio'] ?? null)])];
        $bNow = $h['before']['base_per_day'] ?? null; $bThen = $h['after']['base_per_day'] ?? null; $meas = $h['before']['measured_per_day'] ?? null;
        if ($bNow !== null && $bThen !== null && $meas !== null) {
            $detail[] = $this->i18n->t('recommendations.engine.heatLimit.baseLoad', ['best' => $deg($best), 'unit' => $unit,
                'now' => $this->i18n->number((float)$bNow, 1), 'then' => $this->i18n->number((float)$bThen, 1), 'measured' => $this->i18n->number((float)$meas, 1)]);
        }
        $detail[] = $this->i18n->t($isGroup ? 'recommendations.engine.heatLimit.scopeGroup' : 'recommendations.engine.heatLimit.scope');
        return [$this->mk(
            'r_heat_limit', [$utility, (string)$meter['id'], sprintf('%.1f', $best), (string)(int)$h['months']],
            'warning', 'modell',
            $this->i18n->t('recommendations.engine.heatLimit.title', ['best' => $deg($best)]),
            implode(' ', $detail),
            ['utility' => $utility, 'meter_id' => (string)$meter['id'], 'meter' => (string)($meter['name'] ?? ''), 'is_group' => $isGroup,
             'best' => $best, 'current' => (float)$h['current'], 'own' => (bool)$h['own'], 'default' => (float)$h['default'],
             'range' => $h['range'], 'months' => (int)$h['months'],
             'before' => $h['before'], 'after' => $h['after'], 'effect' => $effect, 'unit' => $unit]
        )];
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
        $s = $this->tankStatus($utility, $meter);
        if ($s === null) return [];
        // v3.4.0 (F1024) — Vorkommen: seit der letzten Lieferung; von Hand
        // abgehakt („nachbestellt") ruht der Hinweis bis zur nächsten
        $anchor = DeliveryService::lastDelivered($this->deliveries->list($utility, (string)$meter['id'])) ?? 'none';
        if ($this->doneFor(AgendaService::uid('tank_reorder', $utility, (string)$meter['id']), $anchor)) return [];
        $warnPct = (float)$this->settings->get('tank_warn_pct', 15);
        return [$this->mk(
            'r5', [$utility, $meter['id']],
            $s['pct'] <= ($warnPct / 2) ? 'urgent' : 'warning', 'bestand',
            $this->i18n->t('recommendations.engine.r5.title', ['label' => $this->utilLabel($utility), 'pct' => (int)round($s['pct'])]),
            $this->i18n->t('recommendations.engine.r5.detail', [
                'name'  => (string)($meter['name'] ?? $meter['id']),
                'stock' => $this->i18n->number($s['stock'], 0),
                'unit'  => $s['unit'],
                'pct'   => (int)round($s['pct']),
            ]),
            ['utility' => $utility, 'meter_id' => $meter['id'], 'anchor' => $anchor]
        )];
    }

    /** Bestand unter `tank_warn_pct`: {pct, stock, unit}, sonst null. */
    private function tankStatus(string $utility, array $meter): ?array
    {
        $warnPct = (float)$this->settings->get('tank_warn_pct', 15);
        try {
            $hist = $this->deliveries->stockHistory($utility, (string)$meter['id'], $this->consumption);
        } catch (\Throwable) {
            return null;
        }
        $cap = $hist['capacity'] ?? null;
        $days = $hist['days'] ?? [];
        if (!$cap || $cap <= 0 || empty($days)) return null;
        $last = end($days);
        $stock = (float)($last['stock'] ?? 0);
        $pct = $stock / $cap * 100;
        return $pct <= $warnPct ? ['pct' => $pct, 'stock' => $stock, 'unit' => (string)($hist['capacity_unit'] ?? '')] : null;
    }

    /** R6 — Vertragsende naht. */
    private function ruleContractEnd(string $utility, array $meter): array
    {
        try {
            $status = $this->consumption->contractStatus($utility, $meter, $this->today);   // v3.9.0 (V1) — derselbe Stichtag
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        // v3.1.0 (H5, MKT-24) — angekündigte Preiserhöhung: Sonderkündigungsrecht
        // prüfen (§ 41 Abs. 5 EnWG; nur Deutschland, nur wenn sie noch kommt)
        if ((string)$this->settings->get('country', 'DE') === 'DE') {
            $today = date('Y-m-d');
            foreach ($status['contracts'] ?? [] as $c) {
                $from = $c['price_increase']['from'] ?? null;
                if (!is_string($from) || $from < $today || (empty($c['is_current']) && empty($c['is_future']))) continue;
                $cid = (string)($c['contract_id'] ?? $c['id'] ?? '');
                // v3.4.0 (F1024) — mit lückenlosem Folgevertrag oder von Hand
                // abgehakt ist die Frage „Sonderkündigung?" beantwortet
                if (self::gapless($c) || $this->doneFor(AgendaService::uid('price_increase', $cid, $from), ActivityService::contractAnchor($c))) continue;
                $out[] = $this->mk(
                    'r_price_increase', [$utility, $meter['id'], $cid, $from],
                    'warning', 'vertrag',
                    $this->i18n->t('recommendations.engine.rPriceIncrease.title', ['label' => $this->utilLabel($utility), 'date' => $this->i18n->date($from)]),
                    $this->i18n->t('recommendations.engine.rPriceIncrease.detail', [
                        'provider' => (string)($c['provider'] ?? $c['tariff_name'] ?? '—'), 'date' => $this->i18n->date($from),
                    ]),
                    ['utility' => $utility, 'meter_id' => $meter['id'], 'contract_id' => $cid, 'from' => $from, 'deadline' => $from]
                );
            }
        }
        foreach ($status['contracts'] ?? [] as $c) {
            if (!($c['should_remind'] ?? false)) continue;
            // v2.9.0 (Review CALC-11) — mit gepflegter Frist zählt der
            // Kündigungsstichtag; bisher kam die Erinnerung zum Vertragsende,
            // oft nach Ablauf der Frist
            $byCancel = ($c['remind_basis'] ?? null) === 'cancel_by';
            $days = $byCancel ? ($c['days_to_cancel'] ?? null) : ($c['days_until_end'] ?? null);
            if ($days === null || $days < 0) continue;
            $cid = (string)($c['contract_id'] ?? $c['id'] ?? '');
            // v3.4.0 (F1024) — von Hand abgehakt (gekündigt bzw. erledigt): ruht bis zum nächsten Vertragsende
            if ($this->doneFor(AgendaService::uid($byCancel ? 'cancel_by' : 'term_end', $cid), ActivityService::contractAnchor($c))) continue;
            $provider = (string)($c['provider'] ?? $c['tariff_name'] ?? '—');
            // v3.4.0 (F1025, Mangel 6) — Wasser lässt sich nicht wechseln: kein
            // „Tarifvergleich starten und ggf. wechseln“
            $switch = Utilities::isSwitchable($utility) ? '' : 'NoSwitch';
            $detail = $byCancel
                ? $this->i18n->t('recommendations.engine.r6.detailCancel' . $switch, [
                    'provider' => $provider,
                    'date'     => $this->i18n->date((string)$c['cancel_by']),
                    'end'      => $this->i18n->date((string)($c['end'] ?? '')),
                ])
                : $this->i18n->tp('recommendations.engine.r6.detail' . $switch, (int)$days, ['provider' => $provider, 'days' => $days]);
            // v3.4.0 (F1024) — Folgevertrag mit Lücke: die Erinnerung bleibt, der Text nennt die Lücke
            $succ = $c['successor'] ?? null;
            if (is_array($succ) && (int)$succ['gap_days'] > 0) {
                $detail .= ' ' . $this->i18n->tp('recommendations.engine.r6.gap', (int)$succ['gap_days'], [
                    'provider' => (string)($succ['provider'] ?: '—'), 'date' => $this->i18n->date((string)$succ['start']),
                ]);
            }
            $out[] = $this->mk(
                'r6', [$utility, $meter['id'], $cid],
                $days <= 14 ? 'urgent' : 'warning', 'vertrag',
                $byCancel
                    ? $this->i18n->tp('recommendations.engine.r6.titleCancel', (int)$days, ['label' => $this->utilLabel($utility), 'days' => $days, 'date' => $this->i18n->date((string)$c['cancel_by'])])
                    : $this->i18n->tp('recommendations.engine.r6.title', (int)$days, ['label' => $this->utilLabel($utility), 'days' => $days]),
                $detail,
                // v3.4.0 (F1024) — additiv: Vertrag und Frist (Ausblenden hält mindestens bis dahin)
                ['utility' => $utility, 'meter_id' => $meter['id'], 'contract_id' => $cid,
                 'deadline' => (string)($byCancel ? $c['cancel_by'] : ($c['end'] ?? ''))]
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
            // v3.9.0 (Skeptiker N6) — ohne eingetragene Wohnfläche rechnet die Klasse mit dem Default
            // (BenchmarkService::efficiency, `area_assumed`); die Empfehlung sagt es wie die Effizienzkarte
            $assumed = !empty($eff['area_assumed']);
            $detail = $this->i18n->t('recommendations.engine.r7.detail', [
                'year'  => $eff['year'],
                'label' => $this->utilLabel((string)$s['utility']),
                'class' => $class,
                'kwh'   => $this->i18n->number((float)$kwhM2, 0),
            ]);
            if ($assumed) {
                $detail .= ' ' . $this->i18n->t('recommendations.engine.r7.areaAssumed', ['area' => $this->i18n->number((float)$eff['wohnflaeche_m2'], 0)]);
            }
            $out[] = $this->mk(
                'r7', ['efficiency', (string)$s['utility'], (string)$eff['year']],
                ($class === 'H' || $class === 'G') ? 'warning' : 'info', 'effizienz',
                $this->i18n->t('recommendations.engine.r7.title', ['label' => $this->utilLabel((string)$s['utility']), 'class' => $class, 'kwh' => $this->i18n->number((float)$kwhM2, 0)]),
                $detail,
                ['utility' => (string)$s['utility'], 'meter_id' => null, 'area_assumed' => $assumed,
                 'area_m2' => (float)$eff['wohnflaeche_m2']]
            );
        }
        return $out;
    }

    // ─── Helfer ──────────────────────────────────────────────────────────

    /** v3.4.0 (F1024) — Vertragszeile mit lückenlosem Folgevertrag? */
    private static function gapless(array $statusRow): bool
    {
        $s = $statusRow['successor'] ?? null;
        return is_array($s) && (int)($s['gap_days'] ?? 1) === 0;
    }

    /**
     * v2.7.0 — Vorzeichenbehaftete Zahl in der Schreibweise von Sprache und
     * Land („+3,5" / „+3.5"). Vorher sprintf('%+.1f') — immer mit Punkt.
     */
    private function signed(float $v, int $decimals): string
    {
        return ($v < 0 ? '-' : '+') . $this->i18n->number(abs($v), $decimals);
    }

    /**
     * Stabile ID einer Empfehlung (Regel + Kontext). v3.1.0 — öffentlich für die
     * Agenda, die so erkennt, ob eine Vertragserinnerung ausgeblendet ist.
     *
     * @param list<string> $ctx
     */
    public static function ruleId(string $rule, array $ctx): string
    {
        return $rule . '_' . substr(md5($rule . '|' . implode('|', $ctx)), 0, 12);
    }

    private function mk(string $rule, array $ctx, string $severity, string $category, string $title, string $detail, array $evidence): array
    {
        $id = self::ruleId($rule, $ctx);
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
