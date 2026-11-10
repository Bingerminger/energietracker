<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v3.12.0 (F1040) — Prognose-Werkstatt: das Profil je Fachgruppe, eine Quelle.
 *
 * Wasser ist keine Fernwärme: Jede Fachgruppe hat ihre eigenen Beispiele,
 * Maßnahmen und Worte. Die Oberfläche und MCP lesen dieses Profil aus der
 * Antwort der Werkstatt, der Browser kennt die Beispiele nicht selbst.
 *
 * Das Profil rechnet nichts. Es sagt nur, welche Eingaben zu dieser Art passen;
 * gerechnet wird wie zuvor in ScenarioService. Ein Beispiel oder eine Maßnahme,
 * die mit den Daten des Zählers nichts bewirken kann, steht nicht im Profil:
 * Strom hat kein Heizmodell (`hgt_relevant` false), dort gibt es also weder
 * Winter noch Raumtemperatur, auch nicht beim Strom der Wärmepumpe.
 *
 * v3.12.0 (F1040, Phase 2) — dazu Heizöl und Pellets (`heat_delivery`: wie Gas, dazu
 * Preis je Liter bzw. kg und Heizungstausch) und die PV (`pv`: Anlage, Speicher,
 * Eigenverbrauch, Vergütung; keine Maßnahmen, kein Modell, kein Budget).
 *
 * Die Zahlen der Beispiele sind Annahmen zum Ausprobieren, keine Richtwerte; die
 * Werkstatt sagt das dazu (`scenario.preset.*.note`).
 */
final class ScenarioProfile
{
    public const ROOF_HEAT_PCT = 15.0;
    public const WINDOWS_HEAT_PCT = 10.0;
    public const SHOWER_BASE_PCT = 10.0;
    public const TOILET_BASE_PCT = 5.0;
    public const SAVING_PCT = 10.0;
    /** Bei Strom wirkt die Grundlast auf die ganze Menge, wie Sparen. Darum ein anderer Wert als „10 % sparen“. */
    public const BASELOAD_PCT = -5.0;
    public const ROOM_DELTA = -1.0;
    public const PRICE_UP_PCT = 10.0;
    public const EV_KM = 10000.0;
    public const EV_KWH_PER_100KM = 18.0;
    public const JAZ_FROM = 3.0;
    public const JAZ_TO = 3.5;
    /** Ohne Klimanormal bleibt der Winter eine Annahme. */
    public const WINTER_FALLBACK_PCT = 10.0;
    /** Bereich der Eingaben für den eigenen Preis (ScenarioService::normalize). */
    private const MAX_WORKING_PRICE_CT = 500.0;
    private const MAX_BASE_EUR_MONTH = 1000.0;

    private const HEAT = ['heat'];
    private const BASE = ['base'];
    private const BOTH = ['heat', 'base'];
    /** Heizanteil, wenn es ihn gibt, sonst die ganze Menge (Strom der Wärmepumpe). */
    private const HEAT_OR_BASE = 'heat_or_base';

    /** @var array<string,array{presets:list<string>,without_heat?:list<string>,measures:array<string,list<string>|string>,base_word:string,baseload?:bool,ev?:bool}> */
    public const GROUPS = [
        'heat_fuel' => [
            'presets'      => ['cold_winter', 'mild_winter', 'room_minus_1', 'roof', 'windows', 'cheapest'],
            'without_heat' => ['save10'],
            'measures'     => ['roof' => self::HEAT, 'facade' => self::HEAT, 'windows' => self::HEAT, 'basement' => self::HEAT,
                'hydraulic' => self::HEAT, 'pump' => self::HEAT, 'thermostat' => self::HEAT, 'hot_water' => self::BASE, 'other' => self::BOTH],
            'base_word'    => 'hot_cook',
        ],
        'heat_grid' => [
            'presets'      => ['cold_winter', 'mild_winter', 'room_minus_1', 'roof', 'price_up', 'cheapest'],
            'without_heat' => ['save10'],
            'measures'     => ['roof' => self::HEAT, 'facade' => self::HEAT, 'windows' => self::HEAT, 'basement' => self::HEAT,
                'hydraulic' => self::HEAT, 'thermostat' => self::HEAT, 'hot_water' => self::BASE, 'other' => self::BOTH],
            'base_word'    => 'hot_water',
        ],
        'power_household' => [
            'presets'   => ['baseload_down', 'ev', 'cheapest', 'save10'],
            'measures'  => ['appliances' => self::BASE, 'standby' => self::BASE, 'lighting' => self::BASE, 'hot_water' => self::BASE, 'other' => self::BASE],
            'base_word' => 'baseload',
            'ev'        => true,
        ],
        'power_heat_pump' => [
            'presets'   => ['jaz_better', 'cold_winter', 'room_minus_1', 'cheapest', 'save10'],
            'measures'  => ['hydraulic' => self::HEAT_OR_BASE, 'roof' => self::HEAT_OR_BASE, 'facade' => self::HEAT_OR_BASE,
                'windows' => self::HEAT_OR_BASE, 'other' => self::HEAT_OR_BASE],
            'base_word' => 'baseload',
        ],
        'power_ev' => [
            'presets'   => ['ev', 'cheapest', 'save10'],
            'measures'  => ['other' => self::BASE],
            'base_word' => 'baseload',
            'ev'        => true,
        ],
        'water' => [
            'presets'   => ['shower', 'toilet', 'save10'],
            'measures'  => ['shower' => self::BASE, 'toilet' => self::BASE, 'laundry' => self::BASE, 'garden' => self::BASE, 'leak' => self::BASE, 'other' => self::BASE],
            'base_word' => 'usage',
            'baseload'  => false,
        ],
        // v3.12.0 (F1040, Phase 2) — Heizöl und Pellets: wie Gas, dazu Preis je Liter/kg und Heizungstausch
        'heat_delivery' => [
            'presets'      => ['cold_winter', 'mild_winter', 'room_minus_1', 'roof', 'fuel_price_up', 'heat_pump'],
            'without_heat' => ['save10'],
            'measures'     => ['roof' => self::HEAT, 'facade' => self::HEAT, 'windows' => self::HEAT, 'basement' => self::HEAT,
                'hydraulic' => self::HEAT, 'pump' => self::HEAT, 'thermostat' => self::HEAT, 'hot_water' => self::BASE, 'other' => self::BOTH],
            'base_word'    => 'hot_water',
        ],
        // v3.12.0 (F1040, Phase 2) — PV im Haushalt: Anlage, Speicher, Eigenverbrauch, Vergütung
        'pv' => [
            'presets'   => ['pv_bigger', 'battery', 'self_use_up', 'feed_in_half'],
            'measures'  => [],
            'base_word' => 'baseload',
            'baseload'  => false,
        ],
    ];

    /** v3.12.0 (F1040, Phase 2) — Beispielwerte der neuen Arten (angenommen, keine Richtwerte). */
    public const FUEL_PRICE_UP_PCT = 10.0;
    public const PV_BIGGER_PCT = 50.0;
    public const BATTERY_KWH = 5.0;
    public const SELF_USE_UP_PCT = 10.0;

    /** Fachgruppe eines Zählers; null für Arten, für die die Werkstatt nicht rechnet. */
    public static function groupOf(string $utility, array $meter): ?string
    {
        return match ($utility) {
            'gas'        => 'heat_fuel',
            'fernwaerme' => 'heat_grid',
            'wasser'     => 'water',
            'strom'      => match (Utilities::roleOf('strom', $meter)) {
                'heat_pump'  => 'power_heat_pump',
                'ev_charger' => 'power_ev',
                default      => 'power_household',
            },
            'heizoel', 'pellets'             => 'heat_delivery',
            'pv_einspeisung', 'pv_erzeugung' => 'pv',
            default      => null,
        };
    }

    /**
     * Das Profil zu einem Zähler.
     *
     * @param array{heat?:bool,room?:bool,tariff?:bool,has_offers?:bool,winter?:?array,months?:list<array>,computable?:bool,price?:bool,heat_pump_less?:?bool,pv?:array} $c
     *        heat: Heizmodell da, room: Raumtemperatur rechenbar, tariff: Tarif wählbar, has_offers: Angebot mit
     *        Preisen, winter: `winter_presets`, months: Monate mit `unit`/`fixed`, computable: Ausgangsfall gerechnet;
     *        v3.12.0 (Phase 2) price: Lieferpreis bekannt, heat_pump_less: Wärme der Wärmepumpe billiger,
     *        pv: feed_ct (mittlere Vergütung), surplus (es wird eingespeist), import (es wird bezogen)
     * @return array<string,mixed>|null
     */
    public static function build(string $utility, array $meter, array $c = []): ?array
    {
        $group = self::groupOf($utility, $meter);
        if ($group === null) return null;
        $def = self::GROUPS[$group];
        $heat = !empty($c['heat']);
        $hasHeatGroup = str_starts_with($group, 'heat_');

        $presets = [];
        if (!empty($c['computable'])) {
            foreach ([...$def['presets'], ...($heat ? [] : ($def['without_heat'] ?? []))] as $id) {
                $p = self::preset($id, $c);
                if ($p !== null) $presets[] = $p;
            }
        }

        $measures = [];
        foreach ($def['measures'] as $kind => $levers) {
            $levers = $levers === self::HEAT_OR_BASE
                ? [$heat ? 'heat' : 'base']
                : array_values(array_filter($levers, fn($l) => $l === 'base' || $heat));
            if ($levers !== []) $measures[] = ['kind' => $kind, 'levers' => $levers];
        }

        $offered = array_column($presets, 'id');
        return [
            'group'         => $group,
            'presets'       => $presets,
            'measures'      => $measures,
            // Alter Sparduschkopf an Gas, Fernwärme, Strom: heißt dort nun „Warmwasser sparen“
            'kind_aliases'  => array_key_exists('hot_water', $def['measures']) ? ['shower' => 'hot_water'] : (object)[],
            // Sprung ?preset=: Kennungen, die es hier früher gab
            'preset_redirects' => !in_array('save10', $offered, true) && in_array('room_minus_1', $offered, true)
                ? ['save10' => 'room_minus_1'] : (object)[],
            'levers'        => [
                'winter'    => $heat,
                'room_temp' => !empty($c['room']),
                'saving'    => $group !== 'pv',
                'baseload'  => $def['baseload'] ?? true,
                'tariff'    => !empty($c['tariff']),
                'ev'        => $def['ev'] ?? false,
                'jaz'       => $group === 'power_heat_pump',
                // v3.12.0 (F1040, Phase 2, additiv) — was es bei der PV nicht gibt, und die neuen Hebel
                'measures'    => $group !== 'pv',
                'model'       => $group !== 'pv',
                'budget'      => $group !== 'pv',
                'price'       => $group === 'heat_delivery',
                'replacement' => $group === 'heat_delivery',
                'pv_size'     => $group === 'pv',
                'battery'     => $group === 'pv',
                'self_use'    => $group === 'pv',
                'feed_in'     => $group === 'pv',
            ],
            // v3.12.0 (F1040, Phase 2, additiv) — wohin eine Lieferart die Heizung tauschen kann (nicht in sich selbst)
            'replace_targets' => $group === 'heat_delivery' ? array_values(array_diff(ScenarioDelivery::TARGETS, [$utility])) : [],
            // Das Wort für den Teil ohne Wetterbezug. Ohne Heizmodell ist das die ganze Menge.
            'base_word'     => ($hasHeatGroup && !$heat) ? 'usage' : $def['base_word'],
            'no_heat_model' => $hasHeatGroup && !$heat,
        ];
    }

    /**
     * Ein Beispiel: Eingaben (`apply`), erwartete Richtung, angenommene Zahlen.
     * Null, wenn es mit diesen Daten nichts bewirken kann.
     *
     * @return array{id:string,direction:string,apply:array<string,mixed>,assumed:array<string,float>}|null
     */
    private static function preset(string $id, array $c): ?array
    {
        $heat = !empty($c['heat']);
        $make = static fn(array $apply, array $assumed = [], string $direction = 'less'): array =>
            ['id' => $id, 'direction' => $direction, 'apply' => $apply, 'assumed' => (object)$assumed];
        $measure = static fn(string $kind, float $heatPct, float $basePct): array => ['measures' => [
            ['id' => $kind, 'kind' => $kind, 'label' => '', 'heat_pct' => $heatPct, 'base_pct' => $basePct, 'cost_eur' => null, 'active' => true],
        ]];
        switch ($id) {
            case 'cold_winter':
                if (!$heat) return null;
                $pct = (float)($c['winter']['cold_pct'] ?? self::WINTER_FALLBACK_PCT);
                return $make(['winter_pct' => $pct], ['pct' => $pct], 'more');
            case 'mild_winter':
                if (!$heat) return null;
                $pct = (float)($c['winter']['mild_pct'] ?? -self::WINTER_FALLBACK_PCT);
                return $make(['winter_pct' => $pct], ['pct' => abs($pct)]);
            case 'room_minus_1':
                return !empty($c['room']) ? $make(['room_temp_delta' => self::ROOM_DELTA]) : null;
            case 'roof':
                return $heat ? $make($measure('roof', self::ROOF_HEAT_PCT, 0.0), ['pct' => self::ROOF_HEAT_PCT]) : null;
            case 'windows':
                return $heat ? $make($measure('windows', self::WINDOWS_HEAT_PCT, 0.0), ['pct' => self::WINDOWS_HEAT_PCT]) : null;
            case 'cheapest':
                return !empty($c['tariff']) && !empty($c['has_offers']) ? $make(['tariff' => ['kind' => 'cheapest']]) : null;
            case 'price_up':
                return !empty($c['tariff']) ? self::priceUp($c['months'] ?? [], $make) : null;
            case 'save10':
                return $make(['saving_pct' => self::SAVING_PCT], ['pct' => self::SAVING_PCT]);
            case 'baseload_down':
                return $make(['baseload_pct' => self::BASELOAD_PCT], ['pct' => abs(self::BASELOAD_PCT)]);
            case 'ev':
                return $make(['ev_km' => self::EV_KM, 'ev_kwh_per_100km' => self::EV_KWH_PER_100KM],
                    ['km' => self::EV_KM, 'kwh' => self::EV_KWH_PER_100KM], 'more');
            case 'jaz_better':
                return $make(['jaz_from' => self::JAZ_FROM, 'jaz_to' => self::JAZ_TO], ['from' => self::JAZ_FROM, 'to' => self::JAZ_TO]);
            case 'shower':
                return $make($measure('shower', 0.0, self::SHOWER_BASE_PCT), ['pct' => self::SHOWER_BASE_PCT]);
            case 'toilet':
                return $make($measure('toilet', 0.0, self::TOILET_BASE_PCT), ['pct' => self::TOILET_BASE_PCT]);
            // v3.12.0 (F1040, Phase 2) — Lieferungen
            case 'fuel_price_up':
                return !empty($c['price']) ? $make(['price_pct' => self::FUEL_PRICE_UP_PCT], ['pct' => self::FUEL_PRICE_UP_PCT], 'more') : null;
            case 'heat_pump':
                if (empty($c['price']) || !isset($c['heat_pump_less'])) return null;
                $a = ScenarioDelivery::ASSUMED;
                $p = $make(['replace' => ['to' => 'heat_pump', 'efficiency_old' => $a['efficiency_old'], 'jaz' => $a['jaz'], 'power_ct' => $a['power_ct']]],
                    ['jaz' => $a['jaz'], 'ct' => $a['power_ct'], 'pct' => $a['efficiency_old'] * 100.0], $c['heat_pump_less'] ? 'less' : 'more');
                // die Richtung hängt am Preis des Brennstoffs: ein eigener Satz, wenn die Wärmepumpe teurer ist
                if (!$c['heat_pump_less']) $p['sentence'] = 'sentence_more';
                return $p;
            // v3.12.0 (F1040, Phase 2) — PV: nur, was mit diesem Haushalt etwas bewirkt
            case 'pv_bigger':
                return $make(['pv_size_pct' => self::PV_BIGGER_PCT], ['pct' => self::PV_BIGGER_PCT]);
            case 'battery':
                return !empty($c['pv']['surplus']) && !empty($c['pv']['import']) ? $make(['battery_kwh' => self::BATTERY_KWH], ['kwh' => self::BATTERY_KWH]) : null;
            case 'self_use_up':
                return !empty($c['pv']['surplus']) && !empty($c['pv']['import']) ? $make(['self_use_pct' => self::SELF_USE_UP_PCT], ['pct' => self::SELF_USE_UP_PCT]) : null;
            case 'feed_in_half':
                $ct = (float)($c['pv']['feed_ct'] ?? 0.0);
                return $ct > 0.0 && !empty($c['pv']['surplus']) ? $make(['feed_in_ct' => round($ct / 2, 2)], ['ct' => round($ct / 2, 2)], 'more') : null;
        }
        return null;
    }

    /** „Preis steigt“: der eigene Preis der Prognosemonate, angenommen teurer. Ohne Preis kein Beispiel. */
    private static function priceUp(array $months, callable $make): ?array
    {
        if ($months === []) return null;
        $factor = 1.0 + self::PRICE_UP_PCT / 100.0;
        $wp = round(array_sum(array_column($months, 'unit')) / count($months) * 100.0 * $factor, 2);
        $bp = round(array_sum(array_column($months, 'fixed')) / count($months) * $factor, 2);
        if ($wp <= 0.0 || $wp > self::MAX_WORKING_PRICE_CT || $bp < 0.0 || $bp > self::MAX_BASE_EUR_MONTH) return null;
        return $make(['tariff' => ['kind' => 'custom', 'working_price_ct' => $wp, 'base_eur_month' => $bp]], ['pct' => self::PRICE_UP_PCT], 'more');
    }
}
