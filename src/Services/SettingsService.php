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
        // v2.10.0 (Review CALC-19) — je Faktor eine Quelle, bezogen auf die
        // Einheit, in der die App zählt. Bestandsinstallationen behalten ihre
        // bisherigen Werte (Migration 1.6.0, LEGACY_DEFAULTS; Lektion 36).
        //   Gas: BAFA-Infoblatt CO₂-Faktoren v3.4 (2026) nennt 201 g/kWh
        //        bezogen auf den HEIZWERT; die App zählt Gas nach Brennwert
        //        (z × Hs) → × 0,906 (BAFA, Tabelle 3) = 182 g/kWh.
        //   Strom: Umweltbundesamt je Jahr (co2_strom_years), `co2_strom`
        //        gilt für Jahre davor (s. Countries::CO2_STROM_DE_UBA).
        'co2_gas'                  => 182.0,   // g/kWh (Brennwert)
        'co2_strom'                => 380.0,   // g/kWh — für Jahre vor dem ersten Eintrag in co2_strom_years
        'co2_strom_years'          => Countries::CO2_STROM_DE_UBA,   // Jahr → g/kWh
        'co2_wasser'               => 350.0,   // g/m³ frisch+abwasser — [Unverifiziert] grober Richtwert, in Einstellungen anpassbar

        // ── Regression filtering ──
        'min_days_period'          => 20,
        'min_hdd_regression'       => 5.0,

        // ── Forecast ──
        'blend_max'                => 0.80,
        'forecast_months'          => 12,
        'min_temp_days_forecast'   => 20,       // veraltet (v2.9.0): ohne Wirkung, ersetzt durch die 90-%-Regel (ConsumptionService::hasTemperatureCoverage); entfällt mit v3.0.0
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
        'wasser_personen_referenz' => 122.0,   // L/Person·Tag, BDEW 2024 (v2.10.0; vorher 127)

        // ── Abrechnungszyklus (F-03, v1.1.0) ──
        'billing_cycle_anchor_gas'    => '01-01',
        'billing_cycle_anchor_strom'  => '01-01',
        'billing_cycle_anchor_wasser' => '01-01',
        // v1.3.0 — Abrechnungsanker für die neuen Utilities
        'billing_cycle_anchor_fernwaerme' => '01-01',
        // veraltet (v2.13.0): Heizöl und Pellets haben keine Abschläge und damit
        // keinen Saldo bis zum Stichtag — ohne Wirkung, nicht mehr in der
        // Oberfläche; entfallen mit v3.0.0
        'billing_cycle_anchor_heizoel'    => '01-01',
        'billing_cycle_anchor_pellets'    => '01-01',
        // v2.13.0 — die Einspeisevergütung rechnet ebenfalls nach einem Stichtag
        'billing_cycle_anchor_pv_einspeisung' => '01-01',

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
        'baujahr'                  => null,    // veraltet (v2.9.0): ohne Wirkung, nicht mehr in der Oberfläche; entfällt mit v3.0.0
        'gebaeudetyp'              => 'efh',   // efh|rh|mfh|whg (Oberfläche; „reihenhaus" wird auch gelesen)
        // v2.10.0 (CALC-07) — energieausweis-nahe Kennzahl: Gebäudenutzfläche
        // 1,35 × Wohnfläche bei EFH/RH mit beheiztem Keller (sonst 1,2);
        // dezentrales Warmwasser bekommt 20 kWh/m²·a Zuschlag
        'beheizter_keller'         => false,
        'warmwasser_dezentral'     => false,

        // ── v1.3.0 — Energieträger-Konstanten (Hu, CO₂) ──
        // Heizöl EL: Heizwert ≈ 10.0 kWh/L; CO₂ 266 g/kWh (BAFA v3.4, Heizwert
        //   — passt, weil die App Heizöl mit dem Heizwert rechnet)
        // Pellets DIN EN ISO 17225-2 A1: Heizwert ≈ 4.8 kWh/kg
        //   v2.10.0: CO₂ 36 g/kWh (BAFA v3.4, CO₂-Äquivalente inkl. Vorkette; vorher 26)
        // Fernwärme: v2.10.0 BAFA-Pauschale 280 g/kWh (vorher 180) — der Wert des
        //   eigenen Netzes steht beim Versorger und ist der bessere
        'heizoel_kwh_per_l'        => 10.0,
        'pellets_kwh_per_kg'       => 4.8,
        'co2_heizoel'              => 266.0,
        'co2_pellets'              => 36.0,
        'co2_fernwaerme'           => 280.0,

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
        'confidence_band_sigma'          => 1.28, // Breite des Prognosebands in σ (1,28 ≈ 80 %); bis v2.7 ohne Wirkung

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

    /**
     * v2.10.0 — Defaults vor ihrer Korrektur (Lektion 36: „ein Update darf
     * keine Zahl ändern, die der Nutzer nicht selbst angefasst hat").
     *
     * Die Migration 1.6.0 schreibt diese Werte in die settings.json jeder
     * Bestandsinstallation, die den Schlüssel nie gespeichert hat — deren
     * Zahlen bleiben also, wie sie waren. Neue Installationen bekommen die
     * korrigierten Defaults. `defaultUpdates()` bietet den Wechsel an.
     */
    public const LEGACY_DEFAULTS = [
        'co2_gas'                  => 201.0,
        'co2_strom_years'          => [],
        'co2_pellets'              => 26.0,
        'co2_fernwaerme'           => 180.0,
        'wasser_personen_referenz' => 127.0,
    ];

    public function __construct(private JsonStore $store) {}

    /** v2.10.0 — der aktuelle Default eines Schlüssels (für Migration und Angebot). */
    public static function defaultFor(string $key): mixed
    {
        return self::DEFAULTS[$key] ?? null;
    }

    /**
     * v2.10.0 — CO₂-Faktor einer Verbrauchsart für ein Jahr (g je Einheit).
     * Strom: der Wert des letzten eingetragenen Jahres bis einschließlich
     * `$year` aus `co2_strom_years`, davor `co2_strom`. Alle anderen: ihr
     * einer Wert.
     */
    public function co2Factor(string $key, int $year): float
    {
        if ($key === 'co2_strom') {
            $best = null;
            foreach ((array)$this->get('co2_strom_years', []) as $y => $v) {
                if ((int)$y <= $year && ($best === null || (int)$y > $best[0]) && is_numeric($v)) {
                    $best = [(int)$y, (float)$v];
                }
            }
            if ($best !== null) return $best[1];
        }
        return (float)$this->get($key, 0.0);
    }

    /**
     * v2.10.0 — Schlüssel, die noch den bei der Migration festgeschriebenen
     * alten Default tragen, obwohl ein korrigierter vorliegt. Die
     * Einstellungen bieten den Wechsel an („Neuere Standardwerte").
     *
     * @return list<array{key:string, current:mixed, recommended:mixed}>
     */
    public function defaultUpdates(): array
    {
        $user = $this->store->read('settings.json', []);
        if (!is_array($user)) $user = [];
        $out = [];
        foreach (self::LEGACY_DEFAULTS as $key => $legacy) {
            if (!array_key_exists($key, $user)) continue;          // nie festgeschrieben: gilt schon der neue
            $current = $user[$key];
            $sameAsLegacy = is_array($legacy) ? $current == $legacy : is_numeric($current) && abs((float)$current - $legacy) < 1e-9;
            if ($sameAsLegacy && self::DEFAULTS[$key] != $legacy) {
                $out[] = ['key' => $key, 'current' => $current, 'recommended' => self::DEFAULTS[$key]];
            }
        }
        return $out;
    }

    /**
     * v2.10.0 — Jahreswerte `Jahr → g/kWh` (Objekt) oder Liste
     * `[{year, g_per_kwh}]`; streng beim Speichern.
     *
     * @return array<int,float>
     */
    private static function normalizeYearMap(mixed $v, callable $t): array
    {
        if ($v === null || $v === '' || $v === []) return [];
        if (!is_array($v)) {
            throw new \InvalidArgumentException($t('errors.settings.valueInvalid', ['key' => 'co2_strom_years', 'value' => gettype($v)]));
        }
        $out = [];
        foreach ($v as $k => $val) {
            if (is_array($val)) { $k = $val['year'] ?? null; $val = $val['g_per_kwh'] ?? null; }
            $year = is_numeric($k) ? (int)$k : 0;
            $num = is_string($val) ? str_replace(',', '.', trim($val)) : $val;
            if ($year < 1990 || $year > 2100 || (string)$year !== trim((string)$k)
                || is_bool($num) || !is_numeric($num) || (float)$num < 0 || (float)$num > 2000) {
                throw new \InvalidArgumentException($t('errors.settings.valueInvalid', [
                    'key' => 'co2_strom_years', 'value' => trim((string)$k) . ': ' . (is_scalar($val) ? (string)$val : ''),
                ]));
            }
            $out[$year] = round((float)$num, 1);
        }
        ksort($out);
        return $out;
    }

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
            // v2.10.0 (CALC-19) — Strom-CO₂ je Jahr
            if ($k === 'co2_strom_years') {
                $v = self::normalizeYearMap($v, $this->translator());
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
            // v2.9.0 (Review CALC-24) — Abrechnungsstichtag als echter
            // Kalendertag (MM-TT). „13-45" ergab bisher ein unmögliches Datum
            // und null verbleibende Monate im Saldo.
            if (str_starts_with($k, 'billing_cycle_anchor_') && !self::isMonthDay($v)) {
                throw new \InvalidArgumentException(($this->translator())('errors.settings.valueInvalid', [
                    'key' => $k, 'value' => is_scalar($v) ? (string)$v : gettype($v),
                ]));
            }
            $current[$k] = $v;
        }
        $this->store->write('settings.json', $current);
        return $this->all();
    }

    /** v2.9.0 — „MM-TT" als gültiger Kalendertag (der 29.02. zählt, Schaltjahr). */
    public static function isMonthDay(mixed $v): bool
    {
        return is_string($v) && preg_match('/^(\d{2})-(\d{2})$/', $v, $m) === 1
            && checkdate((int)$m[1], (int)$m[2], 2024);
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
