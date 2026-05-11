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
            'unit_to_kwh'     => true,   // raw counter is m³, converted to kWh via factor
            'conversion_setting' => 'gas_conversion_factor',
            'hgt_relevant'    => true,
            'color'           => '#f59e0b', // amber/orange
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
            'unit_to_kwh'     => false,  // counter already in kWh
            'conversion_setting' => null,
            'hgt_relevant'    => false,
            'color'           => '#22d3ee', // cyan-green
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
            'conversion_setting' => null,
            'hgt_relevant'    => false,
            'color'           => '#3b82f6', // blue
            'co2_setting'     => 'co2_wasser',
            'default_meter_name' => 'Hauptzähler',
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

    public static function dataPath(string $key, string $rootDir): string
    {
        return $rootDir . '/' . $key;
    }
}
