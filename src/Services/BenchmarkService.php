<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Countries;
use Energietracker\Config\Utilities;

/**
 * v1.3.0 / v1.4.0 — Effizienz-Benchmark.
 *
 * Berechnet den spezifischen Heizenergiebedarf in kWh/m²·a und ordnet ihn
 * einer Effizienzklasse A+..H zu (Bandgrenzen aus den Settings, an
 * GEG/DENA angelehnt).
 *
 * v1.4.0: Ausweisung **pro Heizquelle getrennt** (`per_source`) statt nur
 * summiert. Ein Haus heizt real meist mit einer Quelle; mehrere
 * gleichzeitig aktive Heizarten würden summiert eine unsinnige Klasse
 * ergeben. Zusätzlich bleibt eine kombinierte Sicht (`combined`) für den
 * legitimen Fall mehrerer kombinierter Heizquellen (z. B. Pellets-
 * Grundlast + Gas-Spitzenlast) erhalten.
 *
 * Heizenergie-Verbrauchsarten: Gas, Fernwärme, Heizöl, Pellets — und seit
 * v2.10.0 Stromzähler, die als Heizquelle markiert sind (Wärmepumpe,
 * `heat_source`). NICHT Wasser.
 *
 * [Unverifiziert] Die Default-Bandgrenzen sind branchenüblich; die exakten
 * GEG-2024-Grenzwerte sollten bei Bedarf in den Settings überschrieben
 * werden — der Service liest sie dort, kein Hardcoding.
 *
 * v2.7.0 — Die Klassen gibt es nur, wo das Land eine Skala hat (Feld
 * `scale`, heute nur `geg` für Deutschland). Eine österreichische oder
 * französische Wohnung bekam vorher eine Klasse nach deutschem Recht; jetzt
 * steht die Kennzahl kWh/m²·a ohne Klasse, und `scale` ist null.
 *
 * v2.10.0 (Review CALC-07) — **zwei Zahlen**:
 *   1. die Hauskennzahl wie bisher (Verbrauch je m² Wohnfläche, wie gemessen),
 *   2. `certificate`: energieausweis-nah — Gas auf den Heizwert umgerechnet,
 *      witterungsbereinigt, bezogen auf die Gebäudenutzfläche AN
 *      (1,2 × Wohnfläche, 1,35 bei Ein-/Zweifamilienhaus mit beheiztem
 *      Keller, § 82 GEG), Warmwasser-Zuschlag bei dezentraler Bereitung.
 * Klassen nur für vollständige Jahre (≥ 360 Tage): Ein Nutzer, der im Juni
 * anfing, bekam bisher für sein halbes Jahr „A". Die Grenzen gelten „bis
 * einschließlich" (GEG Anlage 10: „bis 30", „bis 50" …).
 */
final class BenchmarkService
{
    /** Heizenergie-Verbrauchsarten — nur diese zählen für kWh/m². */
    private const HEAT_UTILITIES = ['gas', 'fernwaerme', 'heizoel', 'pellets'];

    /** Mindestabdeckung eines Bezugsjahrs in Tagen, damit es eine Klasse gibt. */
    public const MIN_COVERAGE_DAYS = 360;

    /** Erdgas Brennwert → Heizwert (BAFA-Infoblatt CO₂-Faktoren v3.4, Tabelle 3). */
    public const GAS_HS_TO_HI = 0.906;

    /** Warmwasser-Zuschlag bei dezentraler Bereitung, kWh/(m²·a) AN (Bekanntmachung Verbrauchswerte 2021). */
    public const DHW_SURCHARGE = 20.0;

    private ?ClimateNormalService $climate;

    public function __construct(
        private ConsumptionService $consumption,
        private MeterService $meters,
        private SettingsService $settings,
        private I18nService $i18n,
        ?ClimateNormalService $climate = null,
    ) {
        $this->climate = $climate;
    }

    /**
     * Effizienz-Kennzahl für ein Bezugsjahr (Default: letztes
     * vollständiges Kalenderjahr mit Daten).
     *
     * @return array{
     *   year: int,
     *   wohnflaeche_m2: float,
     *   per_source: array<int, array{utility:string, label:string, kwh:float, kwh_per_m2:float, class:string|null, coverage_days:int, complete:bool}>,
     *   combined: array{kwh:float, kwh_per_m2:float|null, class:string|null},
     *   primary: array{utility:string, label:string, kwh:float, kwh_per_m2:float, class:string|null}|null,
     *   certificate: array<string,mixed>|null,
     *   thresholds: array<string,int>,
     *   scale: string|null,
     *   scale_note: string|null,
     *   note: string|null,
     *   // Rückwärtskompatible Aliase (≤ v1.3.0-Konsumenten):
     *   total_kwh: float, kwh_per_m2: float|null, class: string|null,
     *   breakdown: array<string,float>
     * }
     */
    public function efficiency(?int $year = null): array
    {
        $wohnflaeche = (float)$this->settings->get('wohnflaeche_m2', 100);
        $thresholds  = $this->settings->get('efficiency_class_thresholds', []);
        if (!is_array($thresholds) || empty($thresholds)) {
            $thresholds = ['A+'=>30,'A'=>50,'B'=>75,'C'=>100,'D'=>130,'E'=>160,'F'=>200,'G'=>250];
        }

        if ($year === null) {
            $year = (int)date('Y') - 1; // letztes abgeschlossenes Jahr
        }

        $country = (string)$this->settings->get('country', Countries::DEFAULT);
        $scale   = Countries::efficiencyScale($country);

        // Pro Heizquelle einzeln
        $perSource = [];
        $breakdown = [];
        $sources   = [];
        $combinedKwh = 0.0;
        foreach (array_merge(self::HEAT_UTILITIES, ['strom']) as $utility) {
            if (!Utilities::exists($utility)) continue;
            $d = $this->yearData($utility, $year);
            $sum = $d['kwh'];
            if ($sum <= 0) continue;
            $sources[$utility] = $d;
            $breakdown[$utility] = round($sum, 1);
            $combinedKwh += $sum;
            $complete = $d['coverage_days'] >= self::MIN_COVERAGE_DAYS;
            $entry = [
                'utility' => $utility,
                // v2.2.0 — lokalisiert; vorher stand hier das deutsche
                // SSOT-Label und schlug in die Effizienzkarte des Dashboards
                // durch, auch bei englischer Oberfläche.
                'label'   => $this->i18n->utilityLabel($utility),
                'kwh'     => round($sum, 1),
                // v2.10.0 — wie viele Tage des Jahres gemessen sind
                'coverage_days' => $d['coverage_days'],
                'complete'      => $complete,
            ];
            if ($wohnflaeche > 0) {
                $entry['kwh_per_m2'] = round($sum / $wohnflaeche, 1);
                $entry['class']      = $scale !== null && $complete ? $this->classify($entry['kwh_per_m2'], $thresholds) : null;
            } else {
                $entry['kwh_per_m2'] = null;
                $entry['class']      = null;
            }
            $perSource[] = $entry;
        }

        // Primäre Heizquelle = die mit dem höchsten Verbrauch
        $primary = null;
        foreach ($perSource as $s) {
            if ($primary === null || $s['kwh'] > $primary['kwh']) $primary = $s;
        }
        $allComplete = $perSource !== [] && !in_array(false, array_column($perSource, 'complete'), true);

        $note = null;
        $combinedPerM2 = null;
        $combinedClass = null;
        if ($wohnflaeche <= 0) {
            $note = $this->i18n->t('errors.benchmark.noLivingArea');
        } elseif ($combinedKwh <= 0) {
            $note = $this->i18n->t('errors.benchmark.noHeatData', ['year' => $year]);
        } else {
            $combinedPerM2 = round($combinedKwh / $wohnflaeche, 1);
            $combinedClass = $scale !== null && $allComplete ? $this->classify($combinedPerM2, $thresholds) : null;
            if (!$allComplete) {
                $note = $this->i18n->t('errors.benchmark.incompleteYear', ['year' => $year]);
            } elseif (count($perSource) > 1) {
                $note = $this->i18n->t('errors.benchmark.multipleSources');
            }
        }

        return [
            'year'           => $year,
            'wohnflaeche_m2' => $wohnflaeche,
            'per_source'     => $perSource,
            'primary'        => $primary,
            'combined'       => [
                'kwh'        => round($combinedKwh, 1),
                'kwh_per_m2' => $combinedPerM2,
                'class'      => $combinedClass,
            ],
            'certificate'    => $wohnflaeche > 0 && $sources !== []
                ? $this->certificate($sources, $year, $wohnflaeche, $scale, $thresholds) : null,
            'thresholds'     => $thresholds,
            'scale'          => $scale,
            'scale_note'     => $scale === null
                ? $this->i18n->t('errors.benchmark.noScale', ['country' => $this->i18n->t('countries.' . $country)])
                : null,
            'note'           => $note,
            // ── Rückwärtskompatible Aliase: ≤ v1.3.0-Konsumenten lasen
            //    class/kwh_per_m2/total_kwh als Top-Level. Wir mappen sie
            //    jetzt auf die PRIMÄRE Heizquelle (nicht mehr die Summe),
            //    weil das die sinnvollere Einzelaussage ist.
            'total_kwh'      => $primary ? $primary['kwh'] : round($combinedKwh, 1),
            'kwh_per_m2'     => $primary['kwh_per_m2'] ?? $combinedPerM2,
            'class'          => $primary['class'] ?? $combinedClass,
            'breakdown'      => $breakdown,
        ];
    }

    /**
     * v2.10.0 — Energieausweis-nahe Kennzahl (Review CALC-07).
     *
     *   AN      = Wohnfläche × 1,2 (× 1,35 bei EFH/Reihenhaus mit beheiztem Keller)
     *   E       = Σ Quellen: witterungsbereinigter Verbrauch, Gas × 0,906 (Hs → Hi)
     *   Kennzahl = E / AN (+ 20 kWh/m²·a bei dezentralem Warmwasser)
     *
     * Kein Energieausweis: Der verlangt 36 Monate und die Klimafaktoren des
     * DWD am Standort; hier bereinigt das Klimanormal (Open-Meteo, 30 Jahre).
     *
     * @param array<string,array{kwh:float,adjusted:float,coverage_days:int,weather_adjusted:bool}> $sources
     * @return array<string,mixed>
     */
    private function certificate(array $sources, int $year, float $wohnflaeche, ?string $scale, array $thresholds): array
    {
        $type = (string)$this->settings->get('gebaeudetyp', 'efh');
        $cellar = (bool)$this->settings->get('beheizter_keller', false);
        $smallHouse = in_array($type, ['efh', 'rh', 'reihenhaus'], true);
        $factor = $smallHouse && $cellar ? 1.35 : 1.2;
        $area = $wohnflaeche * $factor;

        $energy = 0.0;
        $complete = true;
        $weatherAdjusted = true;
        $months = [];
        foreach ($sources as $utility => $d) {
            $energy += $d['adjusted'] * ($utility === 'gas' ? self::GAS_HS_TO_HI : 1.0);
            $complete = $complete && $d['coverage_days'] >= self::MIN_COVERAGE_DAYS;
            $weatherAdjusted = $weatherAdjusted && $d['weather_adjusted'];
            $months += $this->monthsWithData($utility, $year);
        }
        $dhw = (bool)$this->settings->get('warmwasser_dezentral', false) ? self::DHW_SURCHARGE : 0.0;
        $perM2 = round($energy / $area + $dhw, 1);

        return [
            'area_m2'          => round($area, 1),
            'area_factor'      => $factor,
            'kwh'              => round($energy, 0),
            'kwh_per_m2'       => $perM2,
            'dhw_surcharge'    => $dhw,
            'weather_adjusted' => $weatherAdjusted,
            'complete'         => $complete,
            'class'            => $scale !== null && $complete ? $this->classify($perM2, $thresholds) : null,
            // § 82 GEG: Ein Verbrauchsausweis braucht 36 zusammenhängende Monate
            'months_36'        => count($months),
        ];
    }

    /**
     * Summe, witterungsbereinigte Summe und Abdeckung einer Heizquelle im
     * Kalenderjahr — über alle Zähler, die in Summen zählen; bei Strom nur
     * die als Heizquelle markierten (Wärmepumpe).
     *
     * Bereinigung: Gas und Fernwärme monatlich mit dem Heizmodell
     * (`heat_adjusted`, v2.8.0); Heizöl, Pellets und Wärmepumpe über das
     * Jahr mit dem Klimanormal — nur der Heizanteil (1 − Grundlastanteil)
     * wird mit HGT_normal / HGT_ist umgerechnet (VDI 3807 für Jahreswerte).
     *
     * @return array{kwh:float,adjusted:float,coverage_days:int,weather_adjusted:bool}
     */
    private function yearData(string $utility, int $year): array
    {
        $sum = 0.0; $adjusted = 0.0; $daysByMonth = []; $hdd = 0.0; $hddNormal = 0.0;
        $monthly = true; $normalOk = true;
        foreach ($this->meters->list($utility) as $meter) {
            // v2.1.3 — F1006: Subzähler überspringen (im Eltern-Brutto bereits
            // enthalten), sonst Doppelzählung → zu hohe kWh/m²·a-Effizienzklasse.
            // v2.9.0 (CALC-15) — ein Zähler außer Betrieb zählt mit seiner
            // Historie: Das Jahr, in dem er noch lief, hatte diesen Verbrauch.
            if (!MeterService::countsInTotals($meter)) continue;
            if ($utility === 'strom' && empty($meter['heat_source'])) continue;
            foreach ($this->consumption->forMeter($utility, $meter) as $m) {
                if ((int)($m['year'] ?? 0) !== $year) continue;
                $k = (float)($m['kwh'] ?? 0);
                $sum += $k;
                $mn = (int)($m['month'] ?? 0);
                $daysByMonth[$mn] = max($daysByMonth[$mn] ?? 0, (int)($m['days'] ?? 0));
                if (isset($m['heat_adjusted']) && $m['heat_adjusted'] !== null) {
                    $adjusted += (float)$m['heat_adjusted'];
                } else {
                    $adjusted += $k;
                    $monthly = false;
                }
                $hdd += (float)($m['hdd'] ?? 0);
                $n = $this->climate?->hddForMonth($mn);
                if ($n === null) $normalOk = false;
                else $hddNormal += (float)$n['mean'] * ((int)($m['days'] ?? 0)) / max(1, (int)date('t', mktime(0, 0, 0, $mn, 1, $year)));
            }
        }
        if ($monthly && $sum > 0) {
            $weatherAdjusted = true;            // jeder Monat aus dem Heizmodell
        } elseif ($normalOk && $hdd > 0 && $sum > 0) {
            // Jahreswert: nur der Heizanteil wird mit dem Gradtagsverhältnis umgerechnet
            $s = max(0.0, min(1.0, (float)$this->settings->get('delivery_baseload_share', 0.15)));
            $adjusted = $sum * ($s + (1.0 - $s) * $hddNormal / $hdd);
            $weatherAdjusted = true;
        } else {
            $adjusted = $sum;                   // ohne Klimanormal: wie gemessen, und so gekennzeichnet
            $weatherAdjusted = false;
        }
        return [
            'kwh'              => $sum,
            'adjusted'         => $adjusted,
            'coverage_days'    => array_sum($daysByMonth),
            'weather_adjusted' => $weatherAdjusted,
        ];
    }

    /**
     * Monate mit Heizdaten in den 36 Monaten bis Ende des Bezugsjahrs.
     *
     * @return array<string,bool> ym → true
     */
    private function monthsWithData(string $utility, int $year): array
    {
        $out = [];
        foreach ($this->meters->list($utility) as $meter) {
            if (!MeterService::countsInTotals($meter)) continue;
            if ($utility === 'strom' && empty($meter['heat_source'])) continue;
            foreach ($this->consumption->forMeter($utility, $meter) as $m) {
                $y = (int)($m['year'] ?? 0);
                if ($y >= $year - 2 && $y <= $year && (float)($m['kwh'] ?? 0) > 0) $out[(string)$m['ym']] = true;
            }
        }
        return $out;
    }

    /**
     * kWh/m² in eine Klasse einordnen. thresholds ist eine aufsteigend
     * sortierte Map Klasse→Obergrenze; alles über der letzten Grenze
     * fällt in die Auffangklasse 'H'. v2.10.0: Grenzen „bis einschließlich"
     * wie GEG Anlage 10 (bis v2.9 „unter").
     */
    private function classify(float $kwhPerM2, array $thresholds): string
    {
        // sicherstellen, dass aufsteigend nach Grenze sortiert
        asort($thresholds);
        foreach ($thresholds as $class => $upper) {
            if ($kwhPerM2 <= (float)$upper) {
                return (string)$class;
            }
        }
        return 'H';
    }

}
