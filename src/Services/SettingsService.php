<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;

/**
 * Settings (`data/settings.json`). Liest und mergt 28 Schlüssel über
 * die Defaults der Anwendung. `get($key, $default)` bringt einen
 * type-cast für numerische Settings (float/int). `update($payload)` ist
 * eine partielle PATCH-Semantik: nur übergebene Schlüssel werden überschrieben.
 *
 * Neue Schlüssel werden über `array_merge` mit den Defaults zusammengeführt;
 * eine `settings.json` aus einer älteren Version (ohne die v1.1.0-Schlüssel)
 * funktioniert unverändert weiter — kein Migrationsschritt nötig.
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

        // ── Abrechnungszyklus (F-03, v1.1.0) ──
        // Stichtag der Jahresabrechnung je Verbrauchsart, Format 'MM-TT'.
        // Wird nur für die Saldo-Projektion offener (end = null) Verträge
        // genutzt: statt „heute + 12 Monate" projiziert die App bis zum
        // nächsten Abrechnungsstichtag. Verträge mit gepflegtem Ende
        // (`end`) verwenden weiterhin dieses Ende. Default '01-01' =
        // Kalenderjahr = identisches Verhalten wie vor v1.1.0.
        'billing_cycle_anchor_gas'    => '01-01',
        'billing_cycle_anchor_strom'  => '01-01',
        'billing_cycle_anchor_wasser' => '01-01',

        // ── Vertragserinnerungen (F-05, v1.1.0) ──
        // Tage vor Vertragsende, ab denen `should_remind` true wird.
        // Drei Stufen, absteigend (frühe Warnung → letzter Tag).
        'contract_remind_days_1'   => 90,
        'contract_remind_days_2'   => 30,
        'contract_remind_days_3'   => 1,

        // ── Wasser-Spar-Index (F-10, v1.1.0) ──
        // Index = (Liter/Person/Tag) / Referenz × 100.
        // ≤ gut = unauffällig, ≥ warnung = Sparpotenzial.
        'wasser_sparindex_gut'     => 100,
        'wasser_sparindex_warnung' => 150,
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
