<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v3.1.0 (Paket H8, MKT-11) — Einordnung des eigenen Verbrauchs.
 *
 * Die Tabellen aus Strom- und Heizspiegel (co2online) liegen nicht bei: Ihre
 * Nutzung verlangt eine schriftliche Genehmigung. Stattdessen trägt der
 * Haushalt seine Vergleichswerte selbst ein — etwa die Klassengrenze, die ihm
 * der StromCheck nennt — samt Quelle, und die App ordnet den gemessenen
 * Verbrauch daran ein. Dazu die Links zur Selbstprüfung (nur Deutschland).
 *
 *   Strom     Haushaltsstrom: Zähler in Summen ohne die Rollen heat_pump und
 *             ev_charger (der Stromspiegel gilt nicht für Wärmepumpe und E-Auto)
 *   Heizung   je Heizart kWh je m² Wohnfläche, witterungsbereinigt, wo es die
 *             Heizkurve gibt
 * Nur volle Jahre (alle zwölf Monate mit Werten), wie die Effizienzklassen.
 *
 * v3.6.0 — Ampel und Einladung:
 *   rating    je Zeile `good` (am oder unter dem Vergleichswert), `near` (bis
 *             NEAR_PCT % darüber), `high` (mehr) — nur im vollen Jahr
 *   water     Liter je Person und Tag gegen den Richtwert der Einstellungen
 *             (`wasser_personen_referenz`, BDEW), Ampel nach den Bändern des
 *             Wasser-Spar-Index (`wasser_sparindex_gut`/`_warnung`); gelb erst
 *             ab WATER_TOLERANCE_PCT Punkten über „gut“
 *   missing   Einstellungen, die eine Einordnung erst möglich machen — in der
 *             Reihenfolge, in der die Übersicht dazu einlädt
 *   Wohnfläche und Personen zählen erst, wenn jemand sie eingetragen hat
 *   (SettingsService::isEntered, auch ein Wert gleich dem Default); der Default
 *   (100 m², 2 Personen) ist nur eine Annahme — bis dahin keine Ampel für
 *   Heizung bzw. Wasser, sondern die Einladung zum Feld.
 * `links` bleibt in der Antwort (Stabilitätszusage); die Übersicht zeigt die
 * Startseiten der Portale seit v3.6.0 nicht mehr.
 */
final class ReferenceService
{
    private const HEAT = ['gas', 'heizoel', 'pellets', 'fernwaerme', 'waerme'];
    /** v3.6.0 — bis so viel Prozent über dem Vergleichswert: gelb, darüber rot */
    public const NEAR_PCT = 10.0;
    /** v3.6.0 — Wasser bleibt bis so viele Punkte über der Grenze „gut“ des Spar-Index grün */
    public const WATER_TOLERANCE_PCT = 5.0;
    public const LINKS = [
        'stromspiegel' => 'https://www.stromspiegel.de/',
        'heizspiegel'  => 'https://www.heizspiegel.de/',
    ];

    public function __construct(
        private ConsumptionService $consumption,
        private MeterService $meters,
        private SettingsService $settings,
    ) {}

    /** @return array<string,mixed> */
    public function compare(?int $year = null): array
    {
        $year ??= (int)date('Y') - 1;
        $active = (array)$this->settings->get('active_utilities', []);
        $isActive = fn(string $u) => $active === [] || in_array($u, $active, true);
        // v3.6.0 — der Default 100 m² ordnet nichts ein (nur eine eingetragene Fläche)
        $area = $this->settings->isEntered('wohnflaeche_m2') ? (float)$this->settings->get('wohnflaeche_m2', 0) : 0.0;
        $de = (string)$this->settings->get('country', 'DE') === 'DE';

        // Haushaltsstrom
        $kwh = 0.0; $months = []; $special = false;
        foreach ($isActive('strom') ? $this->meters->list('strom') : [] as $m) {
            $role = Utilities::roleOf('strom', $m);
            if (in_array($role, ['heat_pump', 'ev_charger'], true)) { $special = true; continue; }
            if (!MeterService::countsInTotals($m)) continue;
            foreach ($this->consumption->forMeter('strom', $m) as $r) {
                if ((int)($r['year'] ?? 0) !== $year) continue;
                $kwh += (float)($r['kwh'] ?? 0);
                if ((int)($r['days'] ?? 0) > 0) $months[(int)$r['month']] = true;
            }
        }
        foreach ($isActive('pv_erzeugung') ? $this->meters->list('pv_erzeugung') : [] as $m) $special = true;
        $ownStrom = $this->num('reference_strom_kwh');
        $stromDelta = $ownStrom !== null && $ownStrom > 0 && count($months) === 12 ? round(($kwh - $ownStrom) / $ownStrom * 100, 1) : null;
        $strom = $months === [] ? null : [
            'kwh'          => round($kwh, 0),
            'complete'     => count($months) === 12,
            'own_value'    => $ownStrom,
            'delta_pct'    => $stromDelta,
            'rating'       => self::rating($stromDelta),   // v3.6.0
            'persons'      => (int)$this->settings->get('wasser_personen_anzahl', 2),
            'building'     => in_array($this->settings->get('gebaeudetyp', 'efh'), ['mfh', 'whg'], true) ? 'flat' : 'house',
            'dhw_electric' => (bool)$this->settings->get('warmwasser_elektrisch', false),
            // PV, Wärmepumpe, Wallbox: der allgemeine Stromspiegel passt nicht
            'special_household' => $special,
        ];

        // Heizung je Heizart
        $ownHeat = $this->num('reference_heat_kwh_m2');
        $heating = [];
        $heatData = false;   // v3.6.0 — Heizdaten im Jahr, auch ohne Wohnfläche
        foreach (self::HEAT as $u) {
            if (!$isActive($u) || !Utilities::exists($u)) continue;
            $sum = 0.0; $adj = 0.0; $hasAdj = true; $mo = [];
            foreach ($this->meters->list($u) as $m) {
                if (!MeterService::countsInTotals($m)) continue;
                foreach ($this->consumption->forMeter($u, $m) as $r) {
                    if ((int)($r['year'] ?? 0) !== $year) continue;
                    $sum += (float)($r['kwh'] ?? 0);
                    if (isset($r['heat_adjusted']) && is_numeric($r['heat_adjusted'])) $adj += (float)$r['heat_adjusted']; else $hasAdj = false;
                    if ((int)($r['days'] ?? 0) > 0) $mo[(int)$r['month']] = true;
                }
            }
            if ($mo !== [] && $sum > 0) $heatData = true;
            if ($mo === [] || $sum <= 0 || $area <= 0) continue;
            $value = ($hasAdj ? $adj : $sum) / $area;
            $delta = $ownHeat !== null && $ownHeat > 0 && count($mo) === 12 ? round(($value - $ownHeat) / $ownHeat * 100, 1) : null;
            $heating[] = [
                'utility'          => $u,
                'kwh_per_m2'       => round($value, 1),
                'weather_adjusted' => $hasAdj,
                'complete'         => count($mo) === 12,
                'own_value'        => $ownHeat,
                'delta_pct'        => $delta,
                'rating'           => self::rating($delta),   // v3.6.0
            ];
        }

        // v3.6.0 — Wasser gegen den Richtwert je Person (wie der Spar-Index der Analyse)
        $water = $isActive('wasser') && Utilities::exists('wasser') ? $this->water($year) : null;

        // v3.6.0 — was fehlt, damit die Übersicht einordnen kann
        $missing = [];
        if ($strom !== null && ($ownStrom === null || $ownStrom <= 0)) $missing[] = 'reference_strom_kwh';
        if ($heatData && $area <= 0) $missing[] = 'wohnflaeche_m2';
        elseif ($heatData && ($ownHeat === null || $ownHeat <= 0)) $missing[] = 'reference_heat_kwh_m2';
        if ($water !== null && $water['persons'] <= 0) $missing[] = 'wasser_personen_anzahl';
        elseif ($water !== null && $water['reference_l'] <= 0) $missing[] = 'wasser_personen_referenz';

        return [
            'year'    => $year,
            'supported' => true,
            'source'  => trim((string)$this->settings->get('reference_source', '')) ?: null,
            'strom'   => $strom,
            'heating' => $heating,
            'water'   => $water,
            'missing' => $missing,
            'area_m2' => $area > 0 ? $area : null,
            'links'   => $de ? self::LINKS : [],
        ];
    }

    /**
     * v3.6.0 — Ampel gegen den eigenen Vergleichswert: am oder unter ihm grün,
     * bis NEAR_PCT % darüber gelb, mehr rot. Ohne Abweichung (kein Wert, Jahr
     * unvollständig) keine Ampel.
     */
    public static function rating(?float $deltaPct): ?string
    {
        if ($deltaPct === null) return null;
        if ($deltaPct <= 0) return 'good';
        return $deltaPct <= self::NEAR_PCT ? 'near' : 'high';
    }

    /**
     * v3.6.0 — Wasser im Kalenderjahr: Liter je Person und Tag gegen den
     * Richtwert, Index = Liter / Richtwert × 100 und die Ampel nach den
     * Bändern des Wasser-Spar-Index. Hauptzähler wie bei Strom (ohne
     * Subzähler, MeterService::countsInTotals).
     *
     * @return array<string,mixed>|null  null ohne Werte im Jahr
     */
    private function water(int $year): ?array
    {
        $m3 = 0.0; $days = 0; $months = [];
        foreach ($this->meters->list('wasser') as $m) {
            if (!MeterService::countsInTotals($m)) continue;
            foreach ($this->consumption->forMeter('wasser', $m) as $r) {
                if ((int)($r['year'] ?? 0) !== $year) continue;
                $m3 += (float)($r['m3'] ?? 0);
                $d = (int)($r['days'] ?? 0);
                if ($d > 0 && !isset($months[(int)$r['month']])) { $months[(int)$r['month']] = true; $days += $d; }
            }
        }
        if ($months === [] || $m3 <= 0) return null;
        // v3.6.0 — der Default 2 Personen ordnet nichts ein (nur eine eingetragene Zahl)
        $persons = $this->settings->isEntered('wasser_personen_anzahl') ? (int)$this->settings->get('wasser_personen_anzahl', 0) : 0;
        $ref = (float)$this->settings->get('wasser_personen_referenz', 0);
        $complete = count($months) === 12;
        $liters = $persons > 0 && $days > 0 ? $m3 * 1000 / $days / $persons : null;
        $index = $liters !== null && $ref > 0 ? $liters / $ref * 100 : null;
        $good = (float)$this->settings->get('wasser_sparindex_gut', 100);
        $warn = (float)$this->settings->get('wasser_sparindex_warnung', 150);
        return [
            'm3'                    => round($m3, 1),
            'complete'              => $complete,
            'persons'               => $persons,
            'reference_l'           => $ref,
            'liters_per_person_day' => $liters !== null ? round($liters, 0) : null,
            'index'                 => $index !== null ? round($index, 0) : null,
            'delta_pct'             => $index !== null && $complete ? round($index - 100, 1) : null,
            'rating'                => $index === null || !$complete ? null
                : ($index <= $good + self::WATER_TOLERANCE_PCT ? 'good' : ($index >= $warn ? 'high' : 'near')),
        ];
    }

    private function num(string $key): ?float
    {
        $v = $this->settings->get($key);
        return is_numeric($v) ? (float)$v : null;
    }
}
