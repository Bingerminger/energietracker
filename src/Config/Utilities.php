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
            'conversion_setting' => 'gas_conversion_factor',
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
            throw new \InvalidArgumentException("Unbekannte Verbrauchsart: $key");
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
     * Saldierung. Single source of truth für den F1003-Scope.
     */
    public static function hasAdvancePaymentContracts(string $key): bool
    {
        return self::isCumulative($key) && $key !== 'wasser';
    }

    public static function dataPath(string $key, string $rootDir): string
    {
        return $rootDir . '/' . $key;
    }
}
