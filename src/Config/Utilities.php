<?php
declare(strict_types=1);

namespace Energietracker\Config;

/**
 * Central registry of all supported utility types.
 *
 * Adding a new utility is a single-file change here, plus:
 * - data/<key>/ directory
 * - demo-data/<key>/ directory (optional)
 * - frontend icon/color in design tokens
 *
 * No other file should hardcode utility-specific behavior.
 */
final class Utilities
{
    /** @var array<string,array<string,mixed>> */
    private static array $defs = [
        'gas' => [
            'key'             => 'gas',
            'label'           => 'Gas',
            'icon'            => '🔥',
            'unit'            => 'm³',
            'consumption_unit'=> 'kWh',
            'unit_to_kwh'     => true,
            'reading_kind'    => 'cumulative',
            // v2.5.0 — F1012: Gas rechnet NICHT über einen Skalar, sondern über
            // die datierte Liste `gas_conversion_factors` (Zustandszahl ×
            // Brennwert je Stichtag) im ConversionFactorService. Der frühere
            // Schlüssel `gas_conversion_factor` existiert nicht mehr; ein
            // Leser bekäme still 1,0 und rechnete m³ = kWh.
            'conversion_setting' => null,
            'hgt_relevant'    => true,
            'color'           => '#f59e0b',
            'co2_setting'     => 'co2_gas',
            'default_meter_name' => 'Hauptzähler',
            'allow_multiple_meters' => true,
        ],
        'strom' => [
            'key'             => 'strom',
            'label'           => 'Strom',
            'icon'            => '⚡',
            'unit'            => 'kWh',
            'consumption_unit'=> 'kWh',
            'unit_to_kwh'     => false,
            'reading_kind'    => 'cumulative',
            'conversion_setting' => null,
            'hgt_relevant'    => false,
            'color'           => '#22d3ee',
            'co2_setting'     => 'co2_strom',
            'default_meter_name' => 'Hauptzähler',
            'allow_multiple_meters' => true,
            // v3.1.0 (H3, B9) — Rollen der Zähler; die erste ist der Standard.
            // heat_pump = Wärmepumpe (Alias des älteren Felds heat_source),
            // ev_charger = Wallbox
            'meter_roles'     => ['household', 'heat_pump', 'ev_charger'],
        ],
        'wasser' => [
            'key'             => 'wasser',
            'label'           => 'Wasser',
            'icon'            => '💧',
            'unit'            => 'm³',
            'consumption_unit'=> 'm³',
            'unit_to_kwh'     => false,
            'reading_kind'    => 'cumulative',
            'conversion_setting' => null,
            'hgt_relevant'    => false,
            'color'           => '#3b82f6',
            'co2_setting'     => 'co2_wasser',
            'default_meter_name' => 'Hauptzähler',
            'allow_multiple_meters' => true,
            // v3.1.0 (H3, B9/CALC-28) — warm: Warmwasserzähler (Wärme nach
            // HeizkostenV § 9 als Rechenwert), garden: Gartenwasser
            'meter_roles'     => ['cold', 'warm', 'garden'],
        ],

        // ── v1.3.0 — Fernwärme: kumulativer kWh-Zähler, analog Strom, aber HGT-relevant ──
        'fernwaerme' => [
            'key'             => 'fernwaerme',
            'label'           => 'Fernwärme',
            'icon'            => '🌡️',
            'unit'            => 'kWh',
            'consumption_unit'=> 'kWh',
            'unit_to_kwh'     => false,
            'reading_kind'    => 'cumulative',
            'conversion_setting' => null,
            'hgt_relevant'    => true,
            'color'           => '#f43f5e',           // rosé
            'co2_setting'     => 'co2_fernwaerme',
            'default_meter_name' => 'Wärmemengenzähler',
            'allow_multiple_meters' => true,
        ],

        // ── v1.3.0 — Heizöl: Lieferungs-basiert (kein kumulativer Zähler) ──
        'heizoel' => [
            'key'             => 'heizoel',
            'label'           => 'Heizöl',
            'icon'            => '🛢️',
            'unit'            => 'L',
            'consumption_unit'=> 'kWh',
            'unit_to_kwh'     => true,
            'reading_kind'    => 'delivery',         // wichtiger Unterschied
            'volume_unit'     => 'L',                 // Eingabeeinheit für Lieferungen
            'conversion_setting' => 'heizoel_kwh_per_l',  // Default 10.0 (Hu Heizöl EL)
            'hgt_relevant'    => true,
            'color'           => '#8b5cf6',           // violett
            'co2_setting'     => 'co2_heizoel',
            'default_meter_name' => 'Heizöltank',
            'allow_multiple_meters' => true,
        ],

        // ── v1.3.0 — Pellets: Lieferungs-basiert ──
        'pellets' => [
            'key'             => 'pellets',
            'label'           => 'Holzpellets',
            'icon'            => '🪵',
            'unit'            => 'kg',
            'consumption_unit'=> 'kWh',
            'unit_to_kwh'     => true,
            'reading_kind'    => 'delivery',
            'volume_unit'     => 'kg',
            'conversion_setting' => 'pellets_kwh_per_kg',  // Default 4.8 (DIN EN ISO 17225-2 A1)
            'hgt_relevant'    => true,
            'color'           => '#a16207',           // dunkles senf-braun
            'co2_setting'     => 'co2_pellets',
            'default_meter_name' => 'Pelletlager',
            'allow_multiple_meters' => true,
        ],

        // ── v1.7.0 — F1005 PV-Einspeisung: kumulativer kWh-Zähler beim
        //     Verteilnetzbetreiber (was die Anlage tatsächlich ins Netz abgibt).
        //     accounting_kind 'feed_in' → der berechnete „Verbrauch" ist
        //     in Wirklichkeit ein Ertrag; Saldo = Vergütung (positiv = Erlös).
        //     CO2-Faktor = co2_strom (vermiedener Strommix); das Frontend
        //     rendert ihn als „vermieden" (negativ). KEIN Default-Meter
        //     (PV ist optional, User legt selbst an).
        'pv_einspeisung' => [
            'key'             => 'pv_einspeisung',
            'label'           => 'PV-Einspeisung',
            'icon'            => '☀️',
            'unit'            => 'kWh',
            'consumption_unit'=> 'kWh',
            'unit_to_kwh'     => false,
            'reading_kind'    => 'cumulative',
            'conversion_setting' => null,
            'hgt_relevant'    => false,
            'color'           => '#10b981',          // emerald
            'co2_setting'     => 'co2_strom',
            'default_meter_name' => 'Einspeisezähler',
            'allow_multiple_meters' => true,
            'accounting_kind' => 'feed_in',
        ],

        // ── v1.7.0 — F1005 PV-Erzeugung: kumulativer kWh-Zähler am
        //     Wechselrichter (Gesamtproduktion, Eigenverbrauch+Einspeisung).
        //     accounting_kind 'generation' → rein statistisch, KEINE Verträge,
        //     keine Kosten/Erlöse. Wird für Autarkiequote (Eigenverbrauch =
        //     erzeugung − einspeisung) und PvSummaryService gebraucht.
        'pv_erzeugung' => [
            'key'             => 'pv_erzeugung',
            'label'           => 'PV-Erzeugung',
            'icon'            => '🔆',
            'unit'            => 'kWh',
            'consumption_unit'=> 'kWh',
            'unit_to_kwh'     => false,
            'reading_kind'    => 'cumulative',
            'conversion_setting' => null,
            'hgt_relevant'    => false,
            'color'           => '#fbbf24',          // amber
            'co2_setting'     => 'co2_strom',
            'default_meter_name' => 'Erzeugungszähler',
            'allow_multiple_meters' => true,
            'accounting_kind' => 'generation',
            'has_contracts'   => false,
            // v3.1.0 (H3, B9) — Speicher: Ladung/Entladung als eigene Zähler
            'meter_roles'     => ['generation', 'battery_charge', 'battery_discharge'],
        ],

        // ── v3.1.0 (H3, B8) — Heizwärme: was in der Wohnung ankommt, nicht der
        //     Brennstoff. Für Mieter mit Wärmezähler oder monatlicher
        //     Verbrauchsinfo (HeizkostenV § 6a; Erfassung „je Zeitraum", B2)
        //     und für die Wärmemenge einer Wärmepumpe (Rolle heat_pump_output).
        //     Keine Versorgerverträge — bezahlt wird über das Mietverhältnis.
        //     CO₂ über den Energieträger der Heizung (Einstellung
        //     waerme_energietraeger), ohne Angabe 0. Nicht standardmäßig aktiv.
        'waerme' => [
            'key'             => 'waerme',
            'label'           => 'Heizwärme',
            'icon'            => '♨️',
            'unit'            => 'kWh',
            'consumption_unit'=> 'kWh',
            'unit_to_kwh'     => false,
            'reading_kind'    => 'cumulative',
            'conversion_setting' => null,
            'hgt_relevant'    => true,
            'color'           => '#ea580c',          // orange
            'co2_setting'     => null,               // aus waerme_energietraeger
            'default_meter_name' => 'Wärmezähler',
            'allow_multiple_meters' => true,
            'has_contracts'   => false,
            'meter_roles'     => ['consumption', 'heat_pump_output'],
        ],
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::$defs);
    }

    public static function exists(string $key): bool
    {
        return isset(self::$defs[$key]);
    }

    /** @return array<string,mixed> */
    public static function get(string $key): array
    {
        if (!isset(self::$defs[$key])) {
            throw new \Energietracker\Support\LocalizedException('errors.common.unknownUtility', ['utility' => $key], "Unknown utility: $key");
        }
        return self::$defs[$key];
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return array_values(self::$defs);
    }

    public static function isHgtRelevant(string $key): bool
    {
        return (bool)(self::get($key)['hgt_relevant'] ?? false);
    }

    /** v1.3.0 — true wenn die Utility über Lieferungen geführt wird (Heizöl, Pellets). */
    public static function isDelivery(string $key): bool
    {
        return (self::get($key)['reading_kind'] ?? 'cumulative') === 'delivery';
    }

    /** v1.3.0 — true wenn die Utility über kumulative Zählerstände geführt wird. */
    public static function isCumulative(string $key): bool
    {
        return (self::get($key)['reading_kind'] ?? 'cumulative') === 'cumulative';
    }

    /** v1.3.0 — Liste aller cumulative Utilities (Backward-Compat-Helper). */
    public static function cumulativeKeys(): array
    {
        return array_values(array_filter(self::keys(), fn($k) => self::isCumulative($k)));
    }

    /** v1.3.0 — Liste aller delivery-Utilities. */
    public static function deliveryKeys(): array
    {
        return array_values(array_filter(self::keys(), fn($k) => self::isDelivery($k)));
    }

    /**
     * F1003 — true wenn die Utility ein Standard-Vertragsmodell mit
     * monatlichen Abschlägen und Saldo-Abrechnung hat (Gas, Strom,
     * Fernwärme). Wasser hat ein abweichendes 3-Komponenten-Modell,
     * Lieferungs-Utilities (Heizöl/Pellets) haben keine Abschlags-
     * Saldierung. PV-Einspeisung hat zwar Verträge, aber kein
     * Abschlagsmodell (Verteilnetzbetreiber überweist nach Erzeugung,
     * nicht nach Plan). PV-Erzeugung hat gar keine Verträge.
     * Single source of truth für den F1003-Scope.
     */
    public static function hasAdvancePaymentContracts(string $key): bool
    {
        return self::isCumulative($key)
            && $key !== 'wasser'
            && self::hasContracts($key)
            && self::accountingKind($key) === 'consumption';
    }

    /**
     * F1005 — true wenn die Utility überhaupt Verträge führen kann.
     * Default true; pv_erzeugung setzt es auf false (rein statistischer
     * Zähler, keine Tarife).
     */
    public static function hasContracts(string $key): bool
    {
        return (bool)(self::get($key)['has_contracts'] ?? true);
    }

    /**
     * F1005 — Abrechnungs-Charakter der Utility:
     *   'consumption' (Default) → Verbrauch, Kosten, klassischer Saldo
     *   'feed_in'               → PV-Einspeisung: positiver Cost-Wert ist
     *                              Erlös; Frontend rendert CO2 als „vermieden"
     *   'generation'            → reine Statistik (PV-Erzeugung am WR);
     *                              keine Verträge, keine Kosten/Erlöse
     */
    public static function accountingKind(string $key): string
    {
        return (string)(self::get($key)['accounting_kind'] ?? 'consumption');
    }

    /** F1005 — true für pv_einspeisung (PV-Einspeisung mit Vergütung). */
    public static function isFeedIn(string $key): bool
    {
        return self::accountingKind($key) === 'feed_in';
    }

    /** F1005 — true für pv_erzeugung (reine Statistik, keine Verträge). */
    public static function isGenerationOnly(string $key): bool
    {
        return self::accountingKind($key) === 'generation';
    }

    /**
     * v3.1.0 (H3, B9) — Rollen der Zähler einer Art; leer = keine Rollen.
     * Die erste ist der Standard für Zähler ohne Angabe.
     *
     * @return list<string>
     */
    public static function meterRoles(string $key): array
    {
        return array_values((array)(self::get($key)['meter_roles'] ?? []));
    }

    /** v3.1.0 — Rolle eines Zählers (fehlt = Standard; strom: heat_source = heat_pump). */
    public static function roleOf(string $key, array $meter): ?string
    {
        $roles = self::meterRoles($key);
        if ($roles === []) return null;
        $role = $meter['role'] ?? null;
        if ($key === 'strom' && $role === null && !empty($meter['heat_source'])) return 'heat_pump';
        return in_array($role, $roles, true) ? $role : $roles[0];
    }

    /**
     * v3.1.0 (H3, B8) — CO₂-Einstellung einer Art. Heizwärme hat keine
     * eigene: Sie kommt vom Energieträger der Heizung (waerme_energietraeger).
     */
    public static function co2Setting(string $key, ?string $heatSource = null): ?string
    {
        if ($key === 'waerme') {
            return ($heatSource !== null && $heatSource !== 'waerme' && self::exists($heatSource))
                ? (self::get($heatSource)['co2_setting'] ?? null) : null;
        }
        return self::get($key)['co2_setting'] ?? null;
    }

    /**
     * v3.1.0 (Paket H5, UI-35/MKT-17) — Rechnungsprüfung möglich: Zählerstände,
     * Verträge, Verbrauch (nicht Erzeugung, nicht Einspeisung — die bekommt eine
     * Gutschrift, keine Rechnung).
     */
    public static function supportsBillCheck(string $key): bool
    {
        return self::exists($key) && self::isCumulative($key) && self::hasContracts($key) && self::accountingKind($key) === 'consumption';
    }

    public static function dataPath(string $key, string $rootDir): string
    {
        return $rootDir . '/' . $key;
    }
}
