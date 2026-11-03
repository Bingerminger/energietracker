<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v3.1.0 (Paket H7, MKT-18) — Effizienz der Wärmepumpe: Arbeitszahl je Monat
 * und Jahresarbeitszahl (JAZ) = gemessene Wärme ÷ gemessener Strom.
 *
 * Die Wärme kommt von einem Heizwärme-Zähler mit der Rolle `heat_pump_output`,
 * der Strom von den Zählern, die er unter `heat_pump_meter_ids` nennt (Strom,
 * Rolle heat_pump). Gezählt werden nur Monate, in denen beide Seiten Werte
 * haben. Keine Witterungsbereinigung: Eine Arbeitszahl bereinigt man nicht;
 * die Streuung über die Gradtage (`by_hdd`) ordnet sie ein. Bilanzgrenze und
 * Heizstab hängen an der Lage der Zähler (Erzeuger ≈ JAZ 3, System ≈ JAZ 5).
 */
final class HeatPumpService
{
    /** Kalendermonate der Heizzeit für die Arbeitszahl der Heizperiode. */
    private const SEASON = [10, 11, 12, 1, 2, 3, 4];

    /** Mittelwerte des Feldtests „WP-QS im Bestand" (Fraunhofer ISE, 2025), zur Einordnung. */
    public const FIELD_TEST = ['air_water' => 3.4, 'ground_water' => 4.3];

    public function __construct(
        private MeterService $meters,
        private ConsumptionService $consumption,
    ) {}

    /** @return array{year:int, pumps:list<array<string,mixed>>, reference:array<string,float>} */
    public function forYear(int $year): array
    {
        $pumps = [];
        $cascade = null; $cascadeRead = false;
        foreach ($this->meters->list('waerme') as $hm) {
            if (Utilities::roleOf('waerme', $hm) !== 'heat_pump_output') continue;
            $ids = array_values(array_filter((array)($hm['heat_pump_meter_ids'] ?? []), 'is_string'));
            $elec = [];
            foreach ($ids as $id) {
                $em = $this->meters->get('strom', $id);
                if ($em === null) continue;
                // v3.9.0 (F1032, #40) — der eigene Verbrauch: In der Kaskade misst der Zähler der
                // Wärmepumpe auch den Haushalt dahinter (Subzähler); die Wärmepumpe ist der Rest
                foreach ($this->consumption->ownMonthly('strom', $em) as $r) {
                    $ym = (string)$r['ym'];
                    $elec[$ym]['kwh'] = ($elec[$ym]['kwh'] ?? 0.0) + (float)($r['kwh'] ?? 0);
                    $elec[$ym]['n'] = ($elec[$ym]['n'] ?? 0) + ((int)($r['days'] ?? 0) > 0 ? 1 : 0);
                }
            }
            // v3.10.0 (#40) — Einspeisung in der Kaskade: Der Rest an Z1 ist nur der Strom aus dem
            // Netz. Mit Einspeise-Zählwerken an Z1 und Z2 kommt der Sonnenstrom ihres Strangs dazu
            // (Z2 − Z1), einmal je Monat, auch wenn HT und NT je ein Zähler sind
            if (!$cascadeRead) { $cascade = $this->cascade(); $cascadeRead = true; }
            $share = CascadeFeed::shareFor($cascade, $ids);
            foreach ($share as $ym => $kwh) {
                if (isset($elec[$ym])) $elec[$ym]['kwh'] += $kwh;
            }
            // v3.10.0 (Entscheidung des Maintainers) — für die Jahressummen (Strom, Jahresarbeitszahl)
            // in verteilten Monaten der unbegrenzte Sonnenstrom: Über eine Lücke stimmt die Summe dann
            // genau. Die Tabelle zeigt den Monat weiter begrenzt und als geschätzt.
            $shareSum = CascadeFeed::shareFor($cascade, $ids, true);
            foreach ($elec as $ym => $e) $elec[$ym]['sum'] = $e['kwh'] + ($shareSum[$ym] ?? 0.0) - ($share[$ym] ?? 0.0);
            // v3.10.0 (Skeptiker M5) — ist der Sonnenstrom des Strangs in einem Monat nicht gemessen
            // (verteilt, auf null begrenzt, nicht von allen drei Zählwerken abgedeckt), ist der Strom
            // dieses Monats geschätzt: keine Arbeitszahl je Monat (eine Lücke an Z2 ergab 12,8). Die
            // Jahresarbeitszahl rechnet weiter über alle Monate (über eine Lücke stimmt die Summe).
            $estimated = CascadeFeed::estimatedFor($cascade, $ids);
            $months = [];
            foreach ($this->consumption->forMeter('waerme', $hm) as $h) {
                $ym = (string)$h['ym'];
                if ((int)substr($ym, 0, 4) !== $year || (int)($h['days'] ?? 0) <= 0) continue;
                // beide Seiten mit Werten; bei mehreren Stromzählern alle
                if (($elec[$ym]['n'] ?? 0) < count($ids) || ($elec[$ym]['kwh'] ?? 0) <= 0) continue;
                $heat = (float)($h['kwh'] ?? 0);
                $est = isset($estimated[$ym]);
                $months[] = ['ym' => $ym, 'month' => (int)$h['month'], 'heat_kwh' => round($heat, 1), 'elec_kwh' => round($elec[$ym]['kwh'], 1),
                             '_sum' => (float)($elec[$ym]['sum'] ?? $elec[$ym]['kwh']),
                             'cop' => $est ? null : round($heat / $elec[$ym]['kwh'], 2), 'hdd' => isset($h['hdd']) ? round((float)$h['hdd'], 1) : null]
                            + ($est ? ['estimated' => true] : []);
            }
            $sum = fn(array $ms, string $k) => array_sum(array_column($ms, $k));
            $season = array_values(array_filter($months, fn($m) => in_array($m['month'], self::SEASON, true)));
            // Jahressummen mit `_sum` (s. o.), die Monatszeilen ohne das Hilfsfeld
            $elecYear = max(0.0, $sum($months, '_sum'));
            $elecSeason = max(0.0, $sum($season, '_sum'));
            $months = array_map(fn($m) => array_diff_key($m, ['_sum' => 1]), $months);
            $pumps[] = [
                'heat_meter_id'      => (string)$hm['id'],
                'name'               => (string)($hm['name'] ?? $hm['id']),
                'elec_meter_ids'     => $ids,
                'linked'             => $ids !== [],
                'months'             => $months,
                'months_covered'     => count($months),
                'heat_kwh'           => round($sum($months, 'heat_kwh'), 1),
                'elec_kwh'           => round($elecYear, 1),
                'jaz'                => $elecYear > 0 ? round($sum($months, 'heat_kwh') / $elecYear, 2) : null,
                'jaz_heating_season' => $elecSeason > 0 ? round($sum($season, 'heat_kwh') / $elecSeason, 2) : null,
                'by_hdd'             => array_values(array_map(fn($m) => ['ym' => $m['ym'], 'hdd' => $m['hdd'], 'cop' => $m['cop']],
                                            array_filter($months, fn($m) => $m['hdd'] !== null && $m['cop'] !== null))),
            ];
        }
        return ['year' => $year, 'pumps' => $pumps, 'reference' => self::FIELD_TEST];
    }

    /** v3.10.0 (#40) — Kaskade mit Einspeise-Zählwerken, nur wenn es eins hinter dem Netzanschluss gibt. */
    private function cascade(): ?array
    {
        $has = array_filter($this->meters->list('pv_einspeisung'), fn($m) => !empty($m['cascade_meter_id']));
        if ($has === []) return null;
        return CascadeFeed::compute($this->consumption->forUtility('strom'), $this->consumption->forUtility('pv_einspeisung'),
            $this->consumption->forUtility('pv_erzeugung'));
    }
}
