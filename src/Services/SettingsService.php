<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;

/**
 * Settings (`data/settings.json`). Liest und mergt 50 Schlüssel über
 * die Defaults der Anwendung. `get($key, $default)` bringt einen
 * type-cast für numerische Settings (float/int). `update($payload)` ist
 * eine partielle PATCH-Semantik: nur übergebene Schlüssel werden überschrieben.
 *
 * Neue Schlüssel werden über `array_merge` mit den Defaults zusammengeführt;
 * eine `settings.json` aus einer älteren Version (ohne die v1.3.0-Schlüssel)
 * funktioniert unverändert weiter — kein Migrationsschritt nötig für die
 * Settings selbst (das Schema-Bumping 1.0.3 → 1.1.0 betrifft nur die
 * Verzeichnisstruktur der Utility-Daten).
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
        'forecast_model'           => 'linear', // linear|polynomial|robust|segmented|sigmoid

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
        'wasser_personen_referenz' => 127.0,

        // ── Abrechnungszyklus (F-03, v1.1.0) ──
        'billing_cycle_anchor_gas'    => '01-01',
        'billing_cycle_anchor_strom'  => '01-01',
        'billing_cycle_anchor_wasser' => '01-01',
        // v1.3.0 — Abrechnungsanker für die neuen Utilities
        'billing_cycle_anchor_fernwaerme' => '01-01',
        'billing_cycle_anchor_heizoel'    => '01-01',
        'billing_cycle_anchor_pellets'    => '01-01',

        // ── Vertragserinnerungen (F-05, v1.1.0) ──
        'contract_remind_days_1'   => 90,
        'contract_remind_days_2'   => 30,
        'contract_remind_days_3'   => 1,

        // ── Wasser-Spar-Index (F-10, v1.1.0) ──
        'wasser_sparindex_gut'     => 100,
        'wasser_sparindex_warnung' => 150,

        // ── v1.3.0 — Aktive Verbrauchsarten ──
        // Liste der Utilities, die in Sidebar/Dashboard/Reports erscheinen.
        // Daten inaktiver Utilities bleiben auf der Platte; API-Endpoints
        // bleiben aufrufbar, nur UI blendet sie aus.
        'active_utilities'         => ['gas', 'strom', 'wasser'],

        // ── v1.3.0 — Gebäude-Stammdaten für kWh/m²-Benchmark ──
        'wohnflaeche_m2'           => 100,
        'baujahr'                  => null,
        'gebaeudetyp'              => 'efh',   // efh|mfh|reihenhaus|wohnung

        // ── v1.3.0 — Energieträger-Konstanten (Hu, CO₂) ──
        // Heizöl EL: Heizwert ≈ 10.0 kWh/L, CO₂ ≈ 266 g/kWh (≈ 2,66 kg/L)
        // Pellets DIN EN ISO 17225-2 A1: Heizwert ≈ 4.8 kWh/kg
        //   CO₂ nur ≈ 26 g/kWh (biogen, nahezu klimaneutral nach BAFA-Ansatz)
        // Fernwärme: deutscher Mix ≈ 180 g/kWh [Unverifiziert — schwankt je Versorger stark]
        'heizoel_kwh_per_l'        => 10.0,
        'pellets_kwh_per_kg'       => 4.8,
        'co2_heizoel'              => 266.0,
        'co2_pellets'              => 26.0,
        'co2_fernwaerme'           => 180.0,

        // ── v1.3.0 — Regressionsmodelle ──
        'segmented_split_mode'     => 'auto',  // auto|fixed
        'segmented_fixed_split'    => 50.0,    // HGT-Wert bei mode=fixed

        // ── v1.3.0 — Heizöl/Pellets-Verteilungsmodell ──
        // Anteil der Liefermenge, der unabhängig von der Außentemperatur
        // verbraucht wird (Warmwasser, Stand-by). Rest wird HGT-gewichtet
        // auf die Tage zwischen zwei Lieferungen verteilt.
        'delivery_baseload_share'  => 0.15,    // 15% flach, 85% HGT-gewichtet

        // ── v1.3.0 — Tank-Warnung ──
        'tank_warn_pct'            => 15,      // Tank < 15% → Empfehlung „nachbestellen"

        // ── v1.3.0 — Termin-Erinnerungen ──
        'reminder_warn_days_before' => 14,
        'reminder_overdue_days'     => 0,

        // ── v1.3.0 — Empfehlungs-Engine (statistische Insights) ──
        'recommendation_anomaly_sigma'   => 2.0,  // Mehrverbrauch ohne Wetterkontext
        'recommendation_trend_pct_year'  => 3.0,  // Trend-Detektion
        'confidence_band_sigma'          => 1.0,  // Unsicherheitsband der Prognose

        // ── v1.3.0 — Effizienzklassen kWh/m²·a (Heizenergie kombiniert) ──
        // Bandgrenzen orientiert an GEG/DENA-Klassifikation [Unverifiziert —
        // bei P4-Implementierung gegen aktuelle GEG 2024 prüfen].
        'efficiency_class_thresholds' => [
            'A+' =>  30,
            'A'  =>  50,
            'B'  =>  75,
            'C'  => 100,
            'D'  => 130,
            'E'  => 160,
            'F'  => 200,
            'G'  => 250,
            // alles darüber = H
        ],
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
