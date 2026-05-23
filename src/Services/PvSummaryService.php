<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * F1005 — PV-Eigenverbrauch und Autarkiequote.
 *
 * Definitionen (Branchenstandard):
 *   eigenverbrauch_kwh   = pv_erzeugung − pv_einspeisung
 *                          (was die Anlage produzierte UND ich selbst verbrauchte)
 *   eigenverbrauchsquote = eigenverbrauch_kwh / pv_erzeugung_kwh
 *                          (Anteil der Produktion, der nicht ins Netz ging)
 *   autarkiequote        = eigenverbrauch_kwh / (eigenverbrauch_kwh + bezug_kwh)
 *                          (Anteil meines Stroms, der aus eigener PV kam)
 *
 * Eigenverbrauchs- und Autarkiequote sind nur sinnvoll definiert, wenn
 * sowohl pv_erzeugung als auch pv_einspeisung Daten haben. Ohne
 * Erzeugungszähler kann die App den Eigenverbrauch nicht berechnen —
 * in diesem Fall liefert der Service die Aggregate, die er hat, und
 * setzt die abgeleiteten Quoten auf null.
 */
final class PvSummaryService
{
    public function __construct(
        private ConsumptionService $consumption,
    ) {}

    /**
     * @return array{
     *   monthly: array<int,array<string,mixed>>,
     *   yearly:  array<int,array<string,mixed>>,
     *   has_generation_meter: bool
     * }
     */
    public function compute(): array
    {
        $strom      = $this->consumption->forUtility('strom');
        $einspeisung = $this->consumption->forUtility('pv_einspeisung');
        $erzeugung  = $this->consumption->forUtility('pv_erzeugung');

        $hasErz = !empty($erzeugung['meters'] ?? []);

        $byYm = [];
        $collect = function (array $util, string $field) use (&$byYm): void {
            foreach ($util['monthly_total'] ?? [] as $m) {
                $ym = (string)($m['ym'] ?? '');
                if ($ym === '') continue;
                if (!isset($byYm[$ym])) $byYm[$ym] = $this->emptyRow($ym);
                $byYm[$ym][$field] = (float)($m['kwh'] ?? 0);
            }
        };
        $collect($strom,       'bezug_kwh');
        $collect($einspeisung, 'einspeisung_kwh');
        $collect($erzeugung,   'erzeugung_kwh');

        foreach ($byYm as &$row) {
            $this->enrichRow($row);
        }
        unset($row);
        ksort($byYm);
        $monthly = array_values($byYm);

        // Jährliche Aggregation — Quoten auf Jahresbasis sind die
        // belastbare Größe (Monatsquoten schwanken stark mit den
        // Jahreszeiten, Jahresquote ist die PV-Performance-KPI).
        $yearly = [];
        foreach ($monthly as $row) {
            $yr = (int)$row['year'];
            if (!isset($yearly[$yr])) {
                $yearly[$yr] = [
                    'year' => $yr,
                    'bezug_kwh' => 0.0, 'einspeisung_kwh' => 0.0, 'erzeugung_kwh' => 0.0,
                ];
            }
            $yearly[$yr]['bezug_kwh']        += $row['bezug_kwh'];
            $yearly[$yr]['einspeisung_kwh']  += $row['einspeisung_kwh'];
            $yearly[$yr]['erzeugung_kwh']    += $row['erzeugung_kwh'];
        }
        foreach ($yearly as &$y) {
            $this->enrichRow($y);
        }
        unset($y);
        ksort($yearly);

        return [
            'monthly'              => $monthly,
            'yearly'               => array_values($yearly),
            'has_generation_meter' => $hasErz,
        ];
    }

    /**
     * Setzt die abgeleiteten Felder eigenverbrauch_kwh, eigenverbrauchsquote
     * und autarkiequote. Quoten sind null, wenn der Nenner nicht ≥ 0.1 kWh
     * ist (vermeidet sinnlose 0/0-Anzeigen in Wintermonaten ohne Erzeugung).
     */
    private function enrichRow(array &$row): void
    {
        $erz  = (float)$row['erzeugung_kwh'];
        $eins = (float)$row['einspeisung_kwh'];
        $bez  = (float)$row['bezug_kwh'];

        // Eigenverbrauch kann nie negativ sein (Erzeugungszähler ≥
        // Einspeisezähler). Bei Datenfehlern auf 0 klemmen.
        $eigen = max(0.0, $erz - $eins);
        $row['eigenverbrauch_kwh'] = round($eigen, 1);

        $row['eigenverbrauchsquote'] = $erz > 0.1
            ? round($eigen / $erz, 4)
            : null;
        $row['autarkiequote'] = ($eigen + $bez) > 0.1
            ? round($eigen / ($eigen + $bez), 4)
            : null;

        // Anzeige-Rundung der Roh-kWh-Werte (mehrere Aufrufer schreiben rein).
        $row['bezug_kwh']        = round($bez,  1);
        $row['einspeisung_kwh']  = round($eins, 1);
        $row['erzeugung_kwh']    = round($erz,  1);
    }

    /** @return array{ym:string,year:int,month:int,bezug_kwh:float,einspeisung_kwh:float,erzeugung_kwh:float} */
    private function emptyRow(string $ym): array
    {
        [$yr, $mn] = array_map('intval', explode('-', $ym));
        return [
            'ym' => $ym, 'year' => $yr, 'month' => $mn,
            'bezug_kwh' => 0.0, 'einspeisung_kwh' => 0.0, 'erzeugung_kwh' => 0.0,
        ];
    }
}
