<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Countries;
use Energietracker\Storage\JsonStore;

/**
 * Settings (`data/settings.json`). Liest und mergt die Schlüssel aus DEFAULTS über
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
    /**
     * v2.7.0 — Einheiten, in denen der Gas-Brennwert eingegeben wird
     * (Einstellung `gas_cv_unit`). Gespiegelt in public/js/lib/gas-factor.js
     * (CV_UNITS); CountriesTest hält beide Listen gleich.
     */
    public const GAS_CV_UNITS = ['kwh', 'mj', 'gj'];

    /** @var array<string,mixed> */
    private const DEFAULTS = [
        // ── Physical constants ──
        // v2.5.0 — F1012: Der Gas-Faktor ist eine DATIERTE LISTE
        // (Zustandszahl × Brennwert je Stichtag), kein Skalar mehr. Der
        // undatierte Eintrag gilt vor dem ersten Stichtag; die Migration
        // 1.4.0 → 1.5.0 macht aus dem alten `gas_conversion_factor` genau
        // diesen Eintrag. Details: ConversionFactorService.
        'gas_conversion_factors'   => [
            ['from' => null, 'zustandszahl' => null, 'brennwert' => null, 'kwh_per_m3' => 11.5],
        ],
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

        // ── Lokalisierung (N1007 / v2.0.0) ──
        // additiv, kein Schema-Bump; vom I18nService + Frontend genutzt.
        'language'                 => 'de',     // Oberflächensprache (public/locales/languages.json)

        // ── v2.7.0 — Länderprofil (N1014, src/Config/Countries.php) ──
        // Voreinstellungen je Land werden nur beim Erststart oder auf Wunsch
        // übernommen; die Defaults hier bleiben die deutschen, damit sich an
        // Bestandsinstallationen nichts ändert.
        'country'                  => 'DE',     // DE AT CH FR IT ES PT NL GB
        'currency'                 => 'EUR',    // EUR | CHF | GBP — Felder *_eur/ct_* = Haupt-/Untereinheit
        'timezone'                 => 'Europe/Berlin',
        'gas_cv_unit'              => 'kwh',    // Eingabe des Brennwerts: kwh (kWh/m³) | mj (MJ/m³) | gj (GJ/Smc)

        // ── v2.6.0 — Sicherheit ──
        // Weitere Ursprünge, die die App einbetten dürfen (frame-ancestors),
        // z. B. eine Home-Assistant-Instanz auf anderer Adresse. Leer = nur
        // die eigene Seite. Gelesen von index.php.
        'frame_ancestors'          => '',

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
        // v2.6.0 — je Anfrage einmal lesen. Die Gas-Faktoren wurden bisher je
        // Tag und Segment neu aus settings.json geholt: 44.000 Dateizugriffe
        // für einen PDF-Bericht über zehn Jahre. Jeder Schreibvorgang über den
        // Store (auch set()) macht das Memo ungültig.
        if ($this->memo !== null && $this->memoGen === $this->store->generation()) {
            return $this->memo;
        }
        $user = $this->store->read('settings.json', []);
        if (!is_array($user)) $user = [];
        $this->memoGen = $this->store->generation();
        return $this->memo = array_merge(self::DEFAULTS, $user);
    }

    /** @var array<string,mixed>|null */
    private ?array $memo = null;
    private int $memoGen = -1;

    /** v2.6.0 — ändert sich mit jedem Schreibvorgang (für abgeleitete Memos). */
    public function version(): int
    {
        return $this->store->generation();
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
            if (!array_key_exists($k, self::DEFAULTS)) continue;
            // v2.5.0 — F1012: Die Faktorliste wird beim Speichern normalisiert
            // und validiert (Ableitung z × Hs, Sortierung, Grenzen). Ein
            // Tippfehler wie 115 statt 11,5 würde sonst jeden Gasverbrauch
            // verzehnfachen — still, ohne Fehlermeldung.
            if ($k === 'gas_conversion_factors') {
                $v = ConversionFactorService::normalizeList($v, $this->translator());
                if ($v === []) $v = self::defaultGasConversionFactors();
            }
            // v2.7.0 — Länderprofil: nur bekannte Werte. Eine unbekannte
            // Zeitzone würde date_default_timezone_set() beim nächsten Start
            // mit einer Warnung quittieren und still UTC rechnen.
            $allowed = match ($k) {
                'country'     => Countries::codes(),
                'currency'    => array_keys(Countries::CURRENCIES),
                // ALL_WITH_BC: Browser bieten teils noch ältere Namen an (Europe/Kiev)
                'timezone'    => \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),
                'gas_cv_unit' => self::GAS_CV_UNITS,
                default       => null,
            };
            if ($allowed !== null && !in_array($v, $allowed, true)) {
                throw new \InvalidArgumentException(($this->translator())('errors.settings.valueInvalid', [
                    'key' => $k, 'value' => is_scalar($v) ? (string)$v : gettype($v),
                ]));
            }
            $current[$k] = $v;
        }
        $this->store->write('settings.json', $current);
        return $this->all();
    }

    /** Der ausgelieferte Default der Faktorliste — auch für die Migration. */
    public static function defaultGasConversionFactors(): array
    {
        return self::DEFAULTS['gas_conversion_factors'];
    }

    /**
     * Übersetzer für Validierungsmeldungen. SettingsService kann den
     * I18nService nicht injizieren (Zirkel: I18n → Settings → JsonStore),
     * deshalb wird er nachträglich gesetzt; ohne ihn bleibt der Schlüssel.
     */
    private ?I18nService $i18n = null;

    public function attachI18n(I18nService $i18n): void
    {
        $this->i18n = $i18n;
    }

    private function translator(): callable
    {
        $i18n = $this->i18n;
        return static fn(string $key, array $p = []): string
            => $i18n !== null ? $i18n->t($key, $p) : $key;
    }

    /** @return string[] */
    public function knownKeys(): array
    {
        return array_keys(self::DEFAULTS);
    }
}
