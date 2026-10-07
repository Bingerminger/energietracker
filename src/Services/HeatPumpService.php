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
        foreach ($this->meters->list('waerme') as $hm) {
            if (Utilities::roleOf('waerme', $hm) !== 'heat_pump_output') continue;
            $ids = array_values(array_filter((array)($hm['heat_pump_meter_ids'] ?? []), 'is_string'));
            $elec = [];
            foreach ($ids as $id) {
                $em = $this->meters->get('strom', $id);
                if ($em === null) continue;
                foreach ($this->consumption->forMeter('strom', $em) as $r) {
                    $ym = (string)$r['ym'];
                    $elec[$ym]['kwh'] = ($elec[$ym]['kwh'] ?? 0.0) + (float)($r['kwh'] ?? 0);
                    $elec[$ym]['n'] = ($elec[$ym]['n'] ?? 0) + ((int)($r['days'] ?? 0) > 0 ? 1 : 0);
                }
            }
            $months = [];
            foreach ($this->consumption->forMeter('waerme', $hm) as $h) {
                $ym = (string)$h['ym'];
                if ((int)substr($ym, 0, 4) !== $year || (int)($h['days'] ?? 0) <= 0) continue;
                // beide Seiten mit Werten; bei mehreren Stromzählern alle
                if (($elec[$ym]['n'] ?? 0) < count($ids) || ($elec[$ym]['kwh'] ?? 0) <= 0) continue;
                $heat = (float)($h['kwh'] ?? 0);
                $months[] = ['ym' => $ym, 'month' => (int)$h['month'], 'heat_kwh' => round($heat, 1), 'elec_kwh' => round($elec[$ym]['kwh'], 1),
                             'cop' => round($heat / $elec[$ym]['kwh'], 2), 'hdd' => isset($h['hdd']) ? round((float)$h['hdd'], 1) : null];
            }
            $sum = fn(array $ms, string $k) => array_sum(array_column($ms, $k));
            $season = array_values(array_filter($months, fn($m) => in_array($m['month'], self::SEASON, true)));
            $pumps[] = [
                'heat_meter_id'      => (string)$hm['id'],
                'name'               => (string)($hm['name'] ?? $hm['id']),
                'elec_meter_ids'     => $ids,
                'linked'             => $ids !== [],
                'months'             => $months,
                'months_covered'     => count($months),
                'heat_kwh'           => round($sum($months, 'heat_kwh'), 1),
                'elec_kwh'           => round($sum($months, 'elec_kwh'), 1),
                'jaz'                => $sum($months, 'elec_kwh') > 0 ? round($sum($months, 'heat_kwh') / $sum($months, 'elec_kwh'), 2) : null,
                'jaz_heating_season' => $sum($season, 'elec_kwh') > 0 ? round($sum($season, 'heat_kwh') / $sum($season, 'elec_kwh'), 2) : null,
                'by_hdd'             => array_values(array_map(fn($m) => ['ym' => $m['ym'], 'hdd' => $m['hdd'], 'cop' => $m['cop']],
                                            array_filter($months, fn($m) => $m['hdd'] !== null))),
            ];
        }
        return ['year' => $year, 'pumps' => $pumps, 'reference' => self::FIELD_TEST];
    }
}
