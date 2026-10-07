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
 */
final class ReferenceService
{
    private const HEAT = ['gas', 'heizoel', 'pellets', 'fernwaerme', 'waerme'];
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
        $area = (float)$this->settings->get('wohnflaeche_m2', 0);
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
        $strom = $months === [] ? null : [
            'kwh'          => round($kwh, 0),
            'complete'     => count($months) === 12,
            'own_value'    => $ownStrom,
            'delta_pct'    => $ownStrom !== null && $ownStrom > 0 && count($months) === 12 ? round(($kwh - $ownStrom) / $ownStrom * 100, 1) : null,
            'persons'      => (int)$this->settings->get('wasser_personen_anzahl', 2),
            'building'     => in_array($this->settings->get('gebaeudetyp', 'efh'), ['mfh', 'whg'], true) ? 'flat' : 'house',
            'dhw_electric' => (bool)$this->settings->get('warmwasser_elektrisch', false),
            // PV, Wärmepumpe, Wallbox: der allgemeine Stromspiegel passt nicht
            'special_household' => $special,
        ];

        // Heizung je Heizart
        $ownHeat = $this->num('reference_heat_kwh_m2');
        $heating = [];
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
            if ($mo === [] || $sum <= 0 || $area <= 0) continue;
            $value = ($hasAdj ? $adj : $sum) / $area;
            $heating[] = [
                'utility'          => $u,
                'kwh_per_m2'       => round($value, 1),
                'weather_adjusted' => $hasAdj,
                'complete'         => count($mo) === 12,
                'own_value'        => $ownHeat,
                'delta_pct'        => $ownHeat !== null && $ownHeat > 0 && count($mo) === 12 ? round(($value - $ownHeat) / $ownHeat * 100, 1) : null,
            ];
        }

        return [
            'year'    => $year,
            'supported' => true,
            'source'  => trim((string)$this->settings->get('reference_source', '')) ?: null,
            'strom'   => $strom,
            'heating' => $heating,
            'area_m2' => $area > 0 ? $area : null,
            'links'   => $de ? self::LINKS : [],
        ];
    }

    private function num(string $key): ?float
    {
        $v = $this->settings->get($key);
        return is_numeric($v) ? (float)$v : null;
    }
}
