<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;

/**
 * Settings (`data/settings.json`). Liest und mergt 20 Schlüssel über
 * die Defaults der Anwendung. `get($key, $default)` bringt einen
 * type-cast für numerische Settings (float/int). `update($payload)` ist
 * eine partielle PATCH-Semantik: nur übergebene Schlüssel werden überschrieben.
 */
final class SettingsService
{
    /** @var array<string,mixed> */
    private const DEFAULTS = [
        // ── Physical constants ──
        'gas_conversion_factor'    => 11.5,    // kWh per m³
        'hdd_base_temp'            => 15.0,    // °C

        // ── CO2 emission factors (g per consumption unit) ──
        'co2_gas'                  => 201.0,   // g/kWh
        'co2_strom'                => 380.0,   // g/kWh — German grid mix 2024 [Unverifiziert — Defaultwert übernommen aus v0.9.0]
        'co2_wasser'               => 350.0,   // g/m³ frisch+abwasser — [Unverifiziert] grober Richtwert, in Einstellungen anpassbar

        // ── Regression filtering ──
        'min_days_period'          => 20,
        'min_hdd_regression'       => 5.0,

        // ── Forecast ──
        'blend_max'                => 0.80,
        'forecast_months'          => 12,
        'min_temp_days_forecast'   => 20,
        'forecast_model'           => 'linear', // linear|polynomial|robust|segmented

        // ── Dashboard ──
        'dashboard_months'         => 12,
        'alert_days_since_reading' => 45,

        // ── Anomaly detection ──
        'anomaly_threshold'        => 2.0,     // standard deviations

        // ── Weather (Leipzig Zentrum default) ──
        'location_name'            => 'Leipzig Zentrum',
        'latitude'                 => 51.3397,
        'longitude'                => 12.3731,
        'weather_auto_fill'        => true,

        // ── Wasser ──
        'wasser_personen_anzahl'   => 2,
        'wasser_personen_referenz' => 127.0,   // L/Person/Tag [Unverifiziert — UBA-Richtwert-Größenordnung]
    ];

    public function __construct(private JsonStore $store) {}

    public function all(): array
    {
        $user = $this->store->read('settings.json', []);
        if (!is_array($user)) $user = [];
        return array_merge(self::DEFAULTS, $user);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(array $patch): array
    {
        $current = $this->store->read('settings.json', []);
        if (!is_array($current)) $current = [];
        foreach ($patch as $k => $v) {
            if (array_key_exists($k, self::DEFAULTS)) {
                $current[$k] = $v;
            }
        }
        $this->store->write('settings.json', $current);
        return $this->all();
    }

    /** @return string[] */
    public function knownKeys(): array
    {
        return array_keys(self::DEFAULTS);
    }
}
