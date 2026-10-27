<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * Anomaly detection on monthly consumption.
 *
 * A point is anomalous when |z-score| >= threshold (default 2.0σ).
 *
 * v2.8.0 (Review CALC-06) — die Erkennung meldete auf sauberen Daten jeden
 * Sommer und übersah echte Ausreißer:
 *   - Heizarten: Monate mit HGT ≤ 5 bekamen die Erwartung 0 — der ganze
 *     Warmwasserverbrauch wurde zum „Ausreißer". Jetzt kommt die Erwartung
 *     für jeden Monat aus dem Heizmodell des Zählers
 *     (`expected_heat` = a × HGT + c × Tage, ConsumptionService::heatModel):
 *     im Sommer ist das die Grundlast.
 *   - Übrige Arten: Das Saisonmittel enthielt den geprüften Monat selbst; ein
 *     +60-%-März bei einem Jahr Historie verglich sich mit sich. Jetzt je Tag
 *     gerechnet und ohne den geprüften Monat (leave-one-out): derselbe
 *     Kalendermonat anderer Jahre, im ersten Jahr die beiden Nachbarmonate.
 *   - Streuung: Median und MAD statt Mittel und Standardabweichung — ein
 *     einzelner Ausreißer bläht die Standardabweichung auf und versteckt sich
 *     darin, und die Residuen waren nicht zentriert.
 * Lieferarten (Heizöl, Pellets) liefern keine Anomalien: Ihre Monatswerte
 * sind aus Lieferungen nach Gradtagen verteilt, jede „Abweichung" wäre ein
 * Artefakt der Verteilung.
 */
final class AnomalyService
{
    public function __construct(
        private RegressionService $regression,
        private SettingsService $settings,
    ) {}

    /**
     * Untergrenze: unter so vielen verwertbaren Monaten wird gar nicht erst
     * gerechnet. Öffentlich, damit der F1011-Hinweis dieselbe Zahl nennt, die
     * hier wirklich greift — statt sie ein zweites Mal hinzuschreiben.
     */
    public const MIN_MONTHS = 5;

    /**
     * v3.7.1 (GitHub #38) — Sommer-Referenz der Grundlast-Prüfung, für jede geprüfte
     * Heizgrenze dieselbe: gemessene Monate ohne Heizgradtage zur obersten Grenze des
     * Heizgrenze-Rasters (`hdd_top` der Monatszeile, ConsumptionService::HEAT_LIMIT_GRID),
     * also ohne einen einzigen Tag unter 18 °C im Mittel. Gibt es davon weniger als
     * SUMMER_REF_MIN (in Mitteleuropa die Regel: fast jeder Juli hat kühle Tage), die
     * gemessenen Monate des festen warmen Fensters Juni bis August — in allen
     * Länderprofilen (Nordhalbkugel) die drei wärmsten Monate, in denen keine Heizgrenze
     * des Rasters nennenswert Heizbedarf ergibt.
     */
    public const SUMMER_REF_BASE = 18.0;
    public const SUMMER_REF_MONTHS = [6, 7, 8];
    public const SUMMER_REF_MIN = 2;

    /**
     * v3.9.0 (#38) — die warmen Monate Mai bis September (`warm` in quality()):
     * Erwartung ÷ Verbrauch über alle geprüften Monate dieser Kalendermonate. Die
     * Sommer-Referenz kennt nur Juni bis August; Mai und September sind
     * Übergangsmonate. Der Heizgrenze-Vorschlag darf sie zusammen höchstens um
     * ConsumptionService::HEAT_LIMIT_SUMMER_MAX überschätzen: Beim Maintainer lag der
     * Rückblick Mai bis September mit der vorgeschlagenen niedrigen Grenze ein Drittel
     * über dem Gemessenen.
     */
    public const WARM_MONTHS = [5, 6, 7, 8, 9];

    /**
     * Die Sommermonate der Grundlast-Prüfung (Indizes in `$valid`) und ihre Herkunft.
     *
     * @param list<array<string,mixed>> $valid
     * @param list<int> $checked  Indizes mit Abweichung (geprüfte Monate)
     * @return array{0:list<int>,1:string}
     */
    public static function summerReference(array $valid, array $checked): array
    {
        // nur mit Temperaturen für (fast) jeden Tag: ohne sie wären es null Heizgradtage aus Mangel an Daten
        $noHdd = array_values(array_filter($checked, fn($i) => isset($valid[$i]['hdd_top']) && (float)$valid[$i]['hdd_top'] < 0.05
            && (int)($valid[$i]['days'] ?? 0) > 0 && (int)($valid[$i]['temp_days'] ?? 0) >= (int)floor((int)$valid[$i]['days'] * 0.9)));
        if (count($noHdd) >= self::SUMMER_REF_MIN) return [$noHdd, 'no_hdd_top'];
        return [array_values(array_filter($checked, fn($i) => in_array((int)($valid[$i]['month'] ?? 0), self::SUMMER_REF_MONTHS, true))), 'window'];
    }

    /**
     * v2.8.0 — Untergrenze der Streuung: 10 % der Erwartung, mindestens aber
     * 10 % eines typischen Monats des Zählers. Bei sehr gleichmäßigen Daten
     * wird die robuste Streuung winzig, und schon +2 % wären „2σ"; bei einem
     * Gartenzähler wären 0,3 statt 0,6 m³ im April „−50 %". Ein Monat
     * schwankt im Alltag um einige Prozent — gemeldet wird erst, was darüber
     * hinausgeht (bei 2σ ab rund 20 % eines typischen Monats).
     */
    public const RELATIVE_NOISE_FLOOR = 0.10;

    /** Streuungs-Untergrenze für einen Monat (s. RELATIVE_NOISE_FLOOR). */
    public static function noiseFloor(float $expected, float $typical): float
    {
        return self::RELATIVE_NOISE_FLOOR * max($expected, $typical, 0.0);
    }

    /** Typischer Monat: Median der positiven Erwartungswerte. */
    public static function typical(array $expected): float
    {
        $pos = array_values(array_filter(array_map('floatval', $expected), fn($v) => $v > 0));
        return $pos ? self::median($pos) : 0.0;
    }

    /**
     * v3.7.0 (GitHub #38) — Gütesperre, erste Prüfung: Ab diesem Verhältnis der
     * robusten Streuung der Abweichungen zum **mittleren gemessenen Monat**
     * passt das Heizmodell schlecht. Mit gut passendem Modell bestimmt die
     * Rauschuntergrenze (10 %) die Streuung, die Tabelle meldet bei 2σ ab 20 %;
     * über 15 % erst ab 30 % — anderthalbmal so spät —, und eine leere Tabelle
     * hieße nicht „alles in Ordnung“. 15 % ist zugleich die Grenze, die ASHRAE
     * Guideline 14 für kalibrierte Modelle auf Monatsdaten nennt (CV(RMSE)).
     * Gemessen wird gegen den Mittelwert der gemessenen Monate, nicht gegen
     * `typical()`: Der Median der positiven Erwartungen lässt die Sommer eines
     * Modells mit Grundlast 0 fallen, das Modell sah dadurch rund ein Viertel
     * besser aus, als es ist.
     */
    public const POOR_FIT_RATIO = 0.15;

    /**
     * v3.7.0 (GitHub #38) — Gütesperre, zweite Prüfung: Die Erwartung des
     * Modells in gemessenen Sommermonaten (praktisch ohne Heizgradtage, also
     * Warmwasser und Kochen) gegen den Verbrauch dieser Monate. Liegt sie unter
     * der Hälfte oder über dem Anderthalbfachen, beschreibt die Grundlast `c`
     * das Haus nicht — bei einer konvexen Heizkurve fällt sie auf fast null
     * (#38: rund ein Fünftel des Sommerverbrauchs). Alltagsschwankung im Sommer
     * liegt bei 10 bis 20 %, weit innerhalb des Bandes. Mindestens zwei
     * gemessene Sommermonate, sonst entfällt diese Prüfung.
     */
    public const BASE_LOAD_MIN = 0.5;
    public const BASE_LOAD_MAX = 1.5;

    /**
     * Monate, die die Erkennung prüft — eine Auswahl für detect() und quality().
     *
     * @return list<array<string,mixed>>
     */
    private function validMonths(string $utility, array $monthly): array
    {
        $u = Utilities::get($utility);
        $minDays = (int)$this->settings->get('min_days_period', 20);
        $hgt = !empty($u['hgt_relevant']);
        return array_values(array_filter($monthly, fn($m) =>
            ($m['days'] ?? 0) >= $minDays
            // v1.6.1 — Issue #13: Wechsel-Monate aus z-Score-Erkennung
            // ausschließen. Ein Zählertausch ist ein erklärlicher Sonder-
            // effekt (Bridging-Übergang) und sollte nicht als statistische
            // Anomalie markiert werden, sonst poppt jedes Wechsel-Datum
            // als „⚠️ Anomalie" im Dashboard auf.
            && empty($m['device_swap'])
            // v1.4.0 — F1011: Monate vor einer Zäsur gehören zu einem anderen
            // Gebäude. Bleiben sie drin, ist der Erwartungswert ein Mittel aus
            // zwei Zuständen — und jeder Monat danach meldet dauerhaft
            // „unter dem Mittel", während echte Ausreißer untergehen.
            && empty($m['pre_baseline'])
            // v3.7.0 (#37) — verteilte Monate sind nicht gemessen: Ihr Wert ist
            // die Tagesrate eines längeren Ablesungsabstands. Als „+98 % über
            // der Erwartung“ gemeldet, wirkten sie überzeugend und waren falsch.
            && empty($m['interpolated'])
            // v2.8.0 — Heizarten brauchen die Erwartung aus dem Heizmodell
            && (!$hgt || ($m['expected_heat'] ?? null) !== null)
        ));
    }

    /**
     * Abweichungen der geprüften Monate von ihrer Erwartung, mit Zentrum,
     * Streuung und typischem Monat. null, wenn zu wenig Monate da sind.
     *
     * @return array{valid:list<array>,expected:array<int,?float>,residuals:array<int,float>,center:float,scale:float,typical:float}|null
     */
    private function residuals(string $utility, array $monthly): ?array
    {
        if (Utilities::isDelivery($utility)) return null;
        $u = Utilities::get($utility);
        $valueField = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        $valid = $this->validMonths($utility, $monthly);
        if (count($valid) < self::MIN_MONTHS) return null;

        $expected = !empty($u['hgt_relevant'])
            ? array_map(fn($m) => (float)$m['expected_heat'], $valid)
            : $this->seasonalExpectations($valid, $valueField);

        $residuals = [];
        foreach ($valid as $i => $m) {
            if ($expected[$i] === null) continue;
            $residuals[$i] = (float)($m[$valueField] ?? 0) - $expected[$i];
        }
        if (count($residuals) < self::MIN_MONTHS) return null;

        [$center, $scale] = self::robustScale(array_values($residuals));
        $typical = self::typical(array_filter($expected, fn($e) => $e !== null));
        return compact('valid', 'expected', 'residuals', 'center', 'scale', 'typical');
    }

    /**
     * v3.7.0 (GitHub #38) — Gütesperre der Abweichungstabelle. Das lineare
     * Heizmodell kann eine gekrümmte Heizkurve nicht abbilden; dann misst die
     * Erkennung vor allem den eigenen Modellfehler und bleibt leer, obwohl sie
     * blind ist. `poor`, wenn eine der beiden Prüfungen anschlägt (`reasons`):
     *
     *   scatter    robuste Streuung ÷ mittlerer gemessener Monat > POOR_FIT_RATIO
     *   base_load  Erwartung ÷ Verbrauch gemessener Sommermonate außerhalb
     *              BASE_LOAD_MIN … BASE_LOAD_MAX
     *
     * Geprüft wird nur über gemessene Monate (dieselbe Auswahl wie detect()).
     * `detect_floor`: Abweichung, die ein typischer Monat mindestens braucht, um
     * gemeldet zu werden — so rechnet die Erkennung selbst (σ-Schwelle ×
     * Streuung, mindestens die Rauschuntergrenze des typischen Monats). Nur für
     * Arten mit Heizmodell, sonst null.
     *
     * @return array<string,mixed>|null
     */
    public function quality(string $utility, array $monthly): ?array
    {
        if (!Utilities::isHgtRelevant($utility) || Utilities::isDelivery($utility)) return null;
        $r = $this->residuals($utility, $monthly);
        if ($r === null || $r['typical'] <= 0) return null;
        $u = Utilities::get($utility);
        $vf = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        $values = array_map(fn($m) => (float)($m[$vf] ?? 0), array_intersect_key($r['valid'], $r['residuals']));
        $mean = $values ? array_sum($values) / count($values) : 0.0;
        if ($mean <= 0) return null;
        $threshold = (float)$this->settings->get('anomaly_threshold', 2.0);
        $ratio = $r['scale'] / $mean;

        // Sommer: gemessene Monate ohne Heizbedarf — v3.7.1 (#38) unabhängig von der
        // geprüften Heizgrenze (summerReference): Bis 3.7.0 hieß Sommer „HGT ≤
        // min_hdd_regression zur Grenze des Modells“; bei niedriger Grenze rutschten
        // Übergangsmonate mit echtem Heizbedarf hinein, die Referenz stieg, und ein Modell
        // mit zu hoher Grundlast bestand die Prüfung
        [$summer, $source] = self::summerReference($r['valid'], array_keys($r['residuals']));
        $exp = 0.0; $expBase = 0.0; $baseKnown = true; $days = 0; $n = 0; $rates = [];
        foreach ($summer as $i) {
            $m = $r['valid'][$i];
            $d = (int)($m['days'] ?? 0);
            if ($d <= 0) continue;
            $exp += (float)$r['expected'][$i]; $days += $d; $n++;
            $rates[] = (float)($m[$vf] ?? 0) / $d;
            // v3.9.0 (#38) — der Anteil der Grundlast c × Tage (ConsumptionService::applyHeatModel)
            if (is_numeric($m['expected_base'] ?? null)) $expBase += (float)$m['expected_base'];
            else $baseKnown = false;
        }
        // v3.7.1 — gemessen: der Median der Tagesraten. Mit festen Sommermonaten sind es
        // weniger als früher; ein einzelner Ausreißer (Urlaub, defekte Therme — genau der
        // Monat, den R1 finden soll) darf die Prüfung nicht allein entscheiden
        $actPerDay = $rates ? self::median($rates) : 0.0;
        $act = $actPerDay * $days;
        $base = $n >= 2 && $act > 0 && $days > 0 ? [
            'model_per_day'    => round($exp / $days, 2),
            'measured_per_day' => round($actPerDay, 2),
            'ratio'            => round($exp / $act, 3),
            'allowed'          => [self::BASE_LOAD_MIN, self::BASE_LOAD_MAX],
            'months'           => $n,
            // v3.7.1 — woher die Sommermonate kommen: `no_hdd_top` (ohne Heizgradtage zu
            // SUMMER_REF_BASE) oder `window` (SUMMER_REF_MONTHS); die Monate selbst
            'reference'        => $source,
            'reference_months' => array_map(fn($i) => (string)$r['valid'][$i]['ym'], $summer),
            // v3.9.0 (#38) — die Grundlast c des Modells allein (ohne den Heizanteil der
            // Sommermonate) und ihr Verhältnis zum gemessenen Sommer; null ohne Angabe
            'base_per_day'     => $baseKnown ? round($expBase / $days, 2) : null,
            'base_ratio'       => $baseKnown ? round($expBase / $act, 3) : null,
        ] : null;
        $reasons = [];
        if ($ratio > self::POOR_FIT_RATIO) $reasons[] = 'scatter';
        // v3.9.0 (#38, Nachmessung der öffentlichen Zusage) — nach unten zählt die Grundlast c
        // selbst, nach oben die ganze Erwartung. Bis v3.7.1 maß auch die Untergrenze die
        // Erwartung der Sommermonate: Bei hohen Heizgrenzen fällt c auf null, aber die kühlen
        // Sommertage bekommen Heizgradtage, und a × HGT hob die Erwartung wieder über die Hälfte
        // des Gemessenen — die Prüfung schlug bis etwa 17 °C an und bei 17,5 bis 18 °C nicht mehr
        // (nicht monoton, im unordentlichen Testhaushalt und beim Melder). Eine Grundlast von null
        // beschreibt das Haus bei keiner Grenze.
        $low = $base !== null ? ($base['base_ratio'] ?? $exp / $act) : null;
        if ($base !== null && ($low < self::BASE_LOAD_MIN || $exp / $act > self::BASE_LOAD_MAX)) $reasons[] = 'base_load';
        // v3.9.0 (#38) — die warmen Monate Mai bis September (WARM_MONTHS), für den Vorschlag
        $wExp = $wAct = 0.0; $wN = 0;
        foreach (array_keys($r['residuals']) as $i) {
            if (!in_array((int)($r['valid'][$i]['month'] ?? 0), self::WARM_MONTHS, true)) continue;
            $wExp += (float)$r['expected'][$i]; $wAct += (float)($r['valid'][$i][$vf] ?? 0); $wN++;
        }
        $warm = $wN >= 2 && $wAct > 0 ? ['ratio' => round($wExp / $wAct, 3), 'months' => $wN] : null;
        return [
            'model_quality'   => $reasons !== [] ? 'poor' : 'good',
            'reasons'         => $reasons,
            'ratio'           => round($ratio, 3),
            'poor_above'      => self::POOR_FIT_RATIO,
            'resid_scale'     => round($r['scale'], 1),
            'mean_month'      => round($mean, 1),
            'base_load'       => $base,
            'warm'            => $warm,   // v3.9.0 (#38)
            'typical'         => round($r['typical'], 1),
            'threshold_sigma' => $threshold,
            'detect_floor'    => round($threshold * max($r['scale'], self::noiseFloor($r['typical'], $r['typical'])), 1),
            'unit'            => (string)($u['consumption_unit'] ?? ''),
            'months'          => count($r['residuals']),
        ];
    }

    public function detect(string $utility, array $monthly): array
    {
        $r = $this->residuals($utility, $monthly);
        if ($r === null) return [];
        $u = Utilities::get($utility);
        $threshold = (float)$this->settings->get('anomaly_threshold', 2.0);
        $valueField = $u['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
        ['valid' => $valid, 'expected' => $expected, 'residuals' => $residuals,
         'center' => $center, 'scale' => $scale, 'typical' => $typical] = $r;
        $idx = array_keys($residuals);
        if (max($scale, self::noiseFloor(0.0, $typical)) < 1e-6) return [];

        // v3.9.0 (Skeptiker, Wärmepumpe im Schaufenster) — das früheste Jahr je Kalendermonat unter den
        // geprüften Monaten: Gibt es denselben Monat ein Jahr früher, vergleicht die Erwartung dieselbe
        // Jahreszeit; sonst nur die Nachbarmonate (der erste Heizmonat gegen den Sommer)
        $firstYear = [];
        foreach ($valid as $v) {
            $mo = (int)($v['month'] ?? 0);
            $firstYear[$mo] = min($firstYear[$mo] ?? PHP_INT_MAX, (int)($v['year'] ?? 0));
        }
        $anomalies = [];
        foreach ($idx as $i) {
            $m = $valid[$i];
            $z = ($residuals[$i] - $center) / max($scale, self::noiseFloor((float)$expected[$i], $typical));
            if (abs($z) < $threshold) continue;
            $anomalies[] = [
                // additiv: derselbe Kalendermonat liegt in einem früheren Jahr vor (RecommendationService R4)
                'prior_same_month' => ($firstYear[(int)($m['month'] ?? 0)] ?? PHP_INT_MAX) < (int)($m['year'] ?? 0),
                'ym'        => $m['ym'],
                'value'     => round((float)($m[$valueField] ?? 0), 1),
                'expected'  => round($expected[$i], 1),
                'deviation' => round($residuals[$i], 1),
                'z_score'   => round($z, 2),
                'percent'   => $expected[$i] > 0 ? round($residuals[$i] / $expected[$i] * 100, 1) : 0,
                'avg_temp'  => $m['avg_temp'] ?? null,
                'hdd'       => $m['hdd'] ?? null,
                'kind'      => $z > 0 ? 'high' : 'low',
            ];
        }
        return self::markPatterns($anomalies, $valid, $residuals, $center);
    }

    /**
     * v3.9.0 (#38) — Ab so vielen Jahren mit Meldung ist ein Kalendermonat ein Muster.
     */
    public const PATTERN_MIN_YEARS = 2;

    /**
     * v3.9.0 (#38, Wirklichkeitsabgleich) — Ein Kalendermonat, der in mindestens
     * PATTERN_MIN_YEARS Jahren in dieselbe Richtung gemeldet wird und in jedem
     * geprüften Jahr auf derselben Seite der Erwartung liegt, ist ein **Muster**, kein
     * Ereignis: Bei einer gekrümmten Heizkurve liegen April und Oktober jedes Jahr unter
     * der Geraden. Die Meldungen bleiben (`pattern: true`, `pattern_years` mit allen
     * gemeldeten Jahren dieses Monats); die Analyse fasst sie zu einer Zeile zusammen,
     * Empfehlungen R1 und R4 entstehen daraus nicht mehr jedes Jahr neu.
     *
     * @param list<array<string,mixed>> $anomalies
     * @param list<array<string,mixed>> $valid
     * @param array<int,float> $residuals
     * @return list<array<string,mixed>>
     */
    private static function markPatterns(array $anomalies, array $valid, array $residuals, float $center): array
    {
        // je Kalendermonat: die Seite jedes geprüften Jahres (+1 über, −1 unter der Erwartung)
        $sides = [];
        foreach ($residuals as $i => $res) {
            $mo = (int)($valid[$i]['month'] ?? 0);
            $sides[$mo][] = $res - $center >= 0 ? 1 : -1;
        }
        $flagged = [];
        foreach ($anomalies as $a) {
            $flagged[(int)substr((string)$a['ym'], 5, 2) . '|' . $a['kind']][] = substr((string)$a['ym'], 0, 4);
        }
        foreach ($anomalies as &$a) {
            $mo = (int)substr((string)$a['ym'], 5, 2);
            $years = array_values(array_unique($flagged[$mo . '|' . $a['kind']] ?? []));
            $want = $a['kind'] === 'high' ? 1 : -1;
            $a['pattern'] = count($years) >= self::PATTERN_MIN_YEARS
                && array_filter($sides[$mo] ?? [], fn($s) => $s !== $want) === [];
            $a['pattern_years'] = $a['pattern'] ? $years : null;
        }
        unset($a);
        return $anomalies;
    }

    /**
     * v3.9.0 (#38) — Monate (`YYYY-MM`), deren Meldung ein Muster ist (s. markPatterns).
     *
     * @param list<array<string,mixed>> $anomalies Ergebnis von detect()
     * @return array<string,true>
     */
    public static function patternMonths(array $anomalies): array
    {
        $out = [];
        foreach ($anomalies as $a) if (!empty($a['pattern'])) $out[(string)$a['ym']] = true;
        return $out;
    }

    /**
     * Erwartung je Monat ohne diesen Monat selbst: Tagesrate desselben
     * Kalendermonats in anderen Jahren; gibt es den nicht (erstes Jahr), die
     * der beiden Nachbarmonate — Verbrauch ändert sich über das Jahr stetig,
     * ein Gesamtmittel dagegen machte jeden Wintermonat zum Ausreißer.
     *
     * @return list<?float>
     */
    private function seasonalExpectations(array $valid, string $valueField): array
    {
        $rates = [];
        foreach ($valid as $i => $m) {
            $days = max(1, (int)($m['days'] ?? 0));
            $rates[$i] = [(int)($m['month'] ?? 0), (float)($m[$valueField] ?? 0) / $days];
        }
        $out = [];
        foreach ($valid as $i => $m) {
            $mon = $rates[$i][0];
            $prev = $mon === 1 ? 12 : $mon - 1;
            $next = $mon === 12 ? 1 : $mon + 1;
            $same = []; $near = [];
            foreach ($rates as $j => [$mj, $rate]) {
                if ($j === $i) continue;
                if ($mj === $mon) $same[] = $rate;
                if ($mj === $prev || $mj === $next) $near[] = $rate;
            }
            $pool = $same ?: $near;
            // Median statt Mittel: Ein Jahr mit anderem Niveau (Haushalt
            // verkleinert, neue Geräte) soll die übrigen Jahre nicht zu
            // „Ausreißern" machen.
            $out[$i] = $pool ? self::median($pool) * (int)($m['days'] ?? 0) : null;
        }
        return $out;
    }

    /**
     * Median und MAD (skaliert auf die Standardabweichung einer Normal-
     * verteilung). Fällt bei vielen gleichen Werten die MAD auf 0, die
     * Standardabweichung.
     *
     * @param list<float> $vals
     * @return array{0:float,1:float} [Zentrum, Streuung]
     */
    public static function robustScale(array $vals): array
    {
        $n = count($vals);
        if ($n === 0) return [0.0, 0.0];
        $median = self::median($vals);
        $mad = self::median(array_map(fn($v) => abs($v - $median), $vals)) * 1.4826;
        if ($mad < 1e-6) {
            $mean = array_sum($vals) / $n;
            $mad = sqrt(array_sum(array_map(fn($v) => ($v - $mean) ** 2, $vals)) / $n);
        }
        return [$median, $mad];
    }

    /** @param list<float> $vals */
    private static function median(array $vals): float
    {
        sort($vals);
        $n = count($vals);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float)$vals[$mid] : ((float)$vals[$mid - 1] + (float)$vals[$mid]) / 2;
    }
}
