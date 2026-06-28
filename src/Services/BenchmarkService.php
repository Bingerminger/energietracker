<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
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
 * Heizenergie-Verbrauchsarten: Gas, Fernwärme, Heizöl, Pellets —
 * NICHT Strom, NICHT Wasser.
 *
 * [Unverifiziert] Die Default-Bandgrenzen sind branchenüblich; die exakten
 * GEG-2024-Grenzwerte sollten bei Bedarf in den Settings überschrieben
 * werden — der Service liest sie dort, kein Hardcoding.
 */
final class BenchmarkService
{
    /** Heizenergie-Verbrauchsarten — nur diese zählen für kWh/m². */
    private const HEAT_UTILITIES = ['gas', 'fernwaerme', 'heizoel', 'pellets'];

    public function __construct(
        private ConsumptionService $consumption,
        private MeterService $meters,
        private SettingsService $settings,
    ) {}

    /**
     * Effizienz-Kennzahl für ein Bezugsjahr (Default: letztes
     * vollständiges Kalenderjahr mit Daten).
     *
     * @return array{
     *   year: int,
     *   wohnflaeche_m2: float,
     *   per_source: array<int, array{utility:string, label:string, kwh:float, kwh_per_m2:float, class:string}>,
     *   combined: array{kwh:float, kwh_per_m2:float|null, class:string|null},
     *   primary: array{utility:string, label:string, kwh:float, kwh_per_m2:float, class:string}|null,
     *   thresholds: array<string,int>,
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

        // Pro Heizquelle einzeln
        $perSource = [];
        $breakdown = [];
        $combinedKwh = 0.0;
        foreach (self::HEAT_UTILITIES as $utility) {
            if (!Utilities::exists($utility)) continue;
            $sum = $this->yearKwhForUtility($utility, $year);
            if ($sum <= 0) continue;
            $breakdown[$utility] = round($sum, 1);
            $combinedKwh += $sum;
            $entry = [
                'utility' => $utility,
                'label'   => Utilities::get($utility)['label'] ?? $utility,
                'kwh'     => round($sum, 1),
            ];
            if ($wohnflaeche > 0) {
                $entry['kwh_per_m2'] = round($sum / $wohnflaeche, 1);
                $entry['class']      = $this->classify($entry['kwh_per_m2'], $thresholds);
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

        $note = null;
        $combinedPerM2 = null;
        $combinedClass = null;
        if ($wohnflaeche <= 0) {
            $note = 'Wohnfläche nicht gesetzt — in den Einstellungen eintragen für die Effizienzklasse.';
        } elseif ($combinedKwh <= 0) {
            $note = sprintf('Keine Heizenergiedaten für %d gefunden.', $year);
        } else {
            $combinedPerM2 = round($combinedKwh / $wohnflaeche, 1);
            $combinedClass = $this->classify($combinedPerM2, $thresholds);
            if (count($perSource) > 1) {
                $note = 'Mehrere Heizquellen aktiv — die Klasse je Quelle ist '
                      . 'aussagekräftiger als die kombinierte Summe. Die '
                      . 'kombinierte Sicht ist nur bei bewusst kombiniertem '
                      . 'Heizbetrieb (z. B. Grund- + Spitzenlast) sinnvoll.';
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
            'thresholds'     => $thresholds,
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

    /** Summe kWh einer Utility über alle aktiven Meter im Kalenderjahr. */
    private function yearKwhForUtility(string $utility, int $year): float
    {
        $sum = 0.0;
        foreach ($this->meters->list($utility) as $meter) {
            if (($meter['active'] ?? true) === false) continue;
            // v2.1.3 — F1006: Subzähler überspringen (im Eltern-Brutto bereits
            // enthalten), sonst Doppelzählung → zu hohe kWh/m²·a-Effizienzklasse.
            if (($meter['parent_meter_id'] ?? null) !== null) continue;
            $monthly = $this->consumption->forMeter($utility, $meter);
            foreach ($monthly as $m) {
                if ((int)($m['year'] ?? 0) === $year) {
                    $sum += (float)($m['kwh'] ?? 0);
                }
            }
        }
        return $sum;
    }

    /**
     * kWh/m² in eine Klasse einordnen. thresholds ist eine aufsteigend
     * sortierte Map Klasse→Obergrenze; alles über der letzten Grenze
     * fällt in die Auffangklasse 'H'.
     */
    private function classify(float $kwhPerM2, array $thresholds): string
    {
        // sicherstellen, dass aufsteigend nach Grenze sortiert
        asort($thresholds);
        foreach ($thresholds as $class => $upper) {
            if ($kwhPerM2 < (float)$upper) {
                return (string)$class;
            }
        }
        return 'H';
    }
}
