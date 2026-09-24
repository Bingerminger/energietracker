<?php
declare(strict_types=1);

namespace Energietracker\Config;

/**
 * v2.7.0 — Länderprofile (N1014, Review 2026-09-24, I18N-03/04/05).
 *
 * Bis v2.6.0 war die App in allem außer der Oberflächensprache deutsch:
 * Euro, GEG-Effizienzklassen, der deutsche Strommix als CO₂-Faktor, eine
 * Heizgrenze von 15 °C, Leipzig als Wetterstandort und die Zeitzone
 * Europe/Berlin. Ein Profil bündelt diese Voreinstellungen je Land. Es wird
 * nur beim Erststart (aus Accept-Language) oder auf ausdrücklichen Wunsch in
 * den Einstellungen übernommen — jeder Wert bleibt danach einzeln änderbar,
 * und Bestandsinstallationen bleiben unberührt (Defaults = DE).
 *
 * Quellen:
 *   - CO₂ Strom (außer DE): Ember 2024, Erzeugungsmix des Landes, über
 *     Our World in Data „carbon-intensity-electricity" (abgerufen 2026-09-25).
 *     DE behält vorerst den bisherigen Default 380 g/kWh.
 *   - Heizgrenze: nationale Gradtag-Konvention, wo sie eine reine Basis-
 *     temperatur ist (FR DJU 18 °C, IT gradi giorno 20 °C nach DPR 412/93,
 *     NL graaddagen 18 °C, UK HDD 15,5 °C); sonst 15 °C wie bisher.
 *   - Effizienzklassen: nur die deutsche GEG-Skala (Endenergie) ist
 *     hinterlegt. Andere Länder rechnen mit Primärenergie, Kosten oder
 *     Referenzgebäuden — eine Klasse aus gemessenem Verbrauch wäre dort
 *     irreführend (ein französischer Nutzer liest „E" als DPE-Klasse).
 *   - Gas: Die Rechnung nennt den Brennwert in UK und NL in MJ/m³ (UK dazu
 *     den Volumenkorrekturfaktor 1,02264), in IT als PCS in GJ/Smc;
 *     gespeichert wird immer kWh/m³ (Umrechnung im Frontend,
 *     public/js/lib/gas-factor.js).
 */
final class Countries
{
    public const DEFAULT = 'DE';

    /** Währungen mit Untereinheit (Katalog-Platzhalter {code}, {cur}, {minor}). */
    public const CURRENCIES = [
        'EUR' => ['symbol' => '€',   'minor' => 'ct'],
        'CHF' => ['symbol' => 'CHF', 'minor' => 'Rp.'],
        'GBP' => ['symbol' => '£',   'minor' => 'p'],
    ];

    /** @var array<string,array<string,mixed>> */
    private const PROFILES = [
        'DE' => ['languages' => ['de'], 'currency' => 'EUR', 'timezone' => 'Europe/Berlin',
                 'hdd_base_temp' => 15.0, 'co2_strom' => 380.0, 'co2_strom_source' => 'default',
                 'location' => ['Leipzig Zentrum', 51.3397, 12.3731],
                 'efficiency_scale' => 'geg', 'gas_cv_unit' => 'kwh'],
        'AT' => ['languages' => ['de'], 'currency' => 'EUR', 'timezone' => 'Europe/Vienna',
                 'hdd_base_temp' => 15.0, 'co2_strom' => 103.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Wien', 48.2082, 16.3738],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'kwh'],
        'CH' => ['languages' => ['de', 'fr', 'it'], 'currency' => 'CHF', 'timezone' => 'Europe/Zurich',
                 'hdd_base_temp' => 15.0, 'co2_strom' => 35.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Bern', 46.9480, 7.4474],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'kwh'],
        'FR' => ['languages' => ['fr'], 'currency' => 'EUR', 'timezone' => 'Europe/Paris',
                 'hdd_base_temp' => 18.0, 'co2_strom' => 40.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Paris', 48.8566, 2.3522],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'kwh'],
        'IT' => ['languages' => ['it'], 'currency' => 'EUR', 'timezone' => 'Europe/Rome',
                 'hdd_base_temp' => 20.0, 'co2_strom' => 281.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Roma', 41.9028, 12.4964],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'gj'],
        'ES' => ['languages' => ['es'], 'currency' => 'EUR', 'timezone' => 'Europe/Madrid',
                 'hdd_base_temp' => 15.0, 'co2_strom' => 146.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Madrid', 40.4168, -3.7038],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'kwh'],
        'PT' => ['languages' => ['pt'], 'currency' => 'EUR', 'timezone' => 'Europe/Lisbon',
                 'hdd_base_temp' => 15.0, 'co2_strom' => 111.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Lisboa', 38.7223, -9.1393],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'kwh'],
        'NL' => ['languages' => ['nl'], 'currency' => 'EUR', 'timezone' => 'Europe/Amsterdam',
                 'hdd_base_temp' => 18.0, 'co2_strom' => 251.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['De Bilt', 52.1017, 5.1778],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'mj'],
        'GB' => ['languages' => ['en'], 'currency' => 'GBP', 'timezone' => 'Europe/London',
                 'hdd_base_temp' => 15.5, 'co2_strom' => 217.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['London', 51.5074, -0.1278],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'mj'],
    ];

    /** Land je Oberflächensprache, wenn der Browser keine Region nennt. */
    private const BY_LANGUAGE = [
        'de' => 'DE', 'en' => 'GB', 'fr' => 'FR', 'it' => 'IT', 'es' => 'ES', 'pt' => 'PT', 'nl' => 'NL',
    ];

    /**
     * Zahlen-, Datums- und Währungsschreibweise, wo das Land von der Sprache
     * abweicht (Schweiz: 1'234.50, „CHF 12.50"; Österreich: „€ 12,50"). Sonst
     * gilt der Sprachkatalog (`format.*`). Die Werte folgen dem, was `Intl`
     * im Browser für dieselbe Kombination ausgibt — PDF und Oberfläche sollen
     * gleich schreiben.
     */
    private const FORMAT_OVERRIDES = [
        'AT' => [
            'de' => ['group' => "\u{00A0}", 'money' => '{cur} {amount}', 'money_group' => '.'],
        ],
        'CH' => [
            'de' => ['decimal' => '.', 'group' => "'", 'money' => '{cur} {amount}'],
            'it' => ['decimal' => '.', 'group' => "'", 'money' => '{cur} {amount}', 'date' => 'd.m.Y'],
            'fr' => ['group' => "'", 'money' => '{amount} {cur}', 'money_decimal' => '.', 'date' => 'd.m.Y'],
        ],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::PROFILES);
    }

    public static function exists(string $code): bool
    {
        return isset(self::PROFILES[$code]);
    }

    /** @return array<string,mixed> */
    public static function get(string $code): array
    {
        return ['code' => $code] + (self::PROFILES[$code] ?? self::PROFILES[self::DEFAULT]);
    }

    /** @return list<array<string,mixed>> für GET /api/countries */
    public static function all(): array
    {
        $out = [];
        foreach (self::codes() as $code) {
            $p = self::get($code);
            [$name, $lat, $lon] = $p['location'];
            unset($p['location']);
            $out[] = $p + ['location_name' => $name, 'latitude' => $lat, 'longitude' => $lon];
        }
        return $out;
    }

    /**
     * Die Einstellungswerte eines Profils (was „Übernehmen" setzt).
     *
     * @return array<string,mixed>
     */
    public static function settingsFor(string $code): array
    {
        $p = self::get($code);
        [$name, $lat, $lon] = $p['location'];
        return [
            'country'       => $p['code'],
            'currency'      => $p['currency'],
            'timezone'      => $p['timezone'],
            'hdd_base_temp' => $p['hdd_base_temp'],
            'co2_strom'     => $p['co2_strom'],
            'location_name' => $name,
            'latitude'      => $lat,
            'longitude'     => $lon,
            'gas_cv_unit'   => $p['gas_cv_unit'],
        ];
    }

    public static function forLanguage(string $language): string
    {
        return self::BY_LANGUAGE[$language] ?? self::DEFAULT;
    }

    /**
     * Land aus einer Accept-Language-Kopfzeile: der erste Eintrag mit einer
     * bekannten Region („de-AT", „fr-CH", „en-GB", auch „en-UK"). Ohne Region
     * null — dann entscheidet die Sprache (forLanguage()).
     */
    public static function fromAcceptLanguage(?string $header): ?string
    {
        if ($header === null || trim($header) === '') return null;
        $best = null;
        $bestQ = -1.0;
        foreach (explode(',', $header) as $part) {
            $segments = array_map('trim', explode(';', $part));
            $tag = strtolower($segments[0]);
            $q = 1.0;
            foreach (array_slice($segments, 1) as $s) {
                if (str_starts_with($s, 'q=')) $q = (float)substr($s, 2);
            }
            if ($q <= 0 || !preg_match('/^[a-z]{2,3}[-_]([a-z]{2})$/', $tag, $m)) continue;
            $region = strtoupper($m[1]) === 'UK' ? 'GB' : strtoupper($m[1]);
            if (!self::exists($region)) continue;
            // gleiche Gewichtung: der frühere Eintrag gewinnt
            if ($q > $bestQ) { $best = $region; $bestQ = $q; }
        }
        return $best;
    }

    public static function efficiencyScale(string $code): ?string
    {
        return self::get($code)['efficiency_scale'];
    }

    /** @return array{symbol:string,minor:string} */
    public static function currency(string $code): array
    {
        return self::CURRENCIES[$code] ?? self::CURRENCIES['EUR'];
    }

    /** @return array<string,string> Abweichende Zahlen-/Währungsschreibweise für Land + Sprache */
    public static function formatOverride(string $country, string $language): array
    {
        return self::FORMAT_OVERRIDES[$country][$language] ?? [];
    }
}
