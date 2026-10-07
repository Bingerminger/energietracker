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
 *     DE: seit v2.10.0 Jahreswerte des Umweltbundesamts (CO2_STROM_DE_UBA),
 *     380 g/kWh nur noch für Jahre vor 2015.
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

    /**
     * v2.10.0 (Review CALC-19) — CO₂ je kWh Strom in Deutschland, je Jahr:
     * Umweltbundesamt, „Emissionsfaktor Strommix" (direktes CO₂, ohne
     * Vorkette). 2015–2022 aus Climate Change 13/2025, Tabelle 2; 2023–2025
     * aus der UBA-Veröffentlichung 2026 (2024 vorläufig, 2025 geschätzt).
     * Für Jahre danach gilt der letzte Wert, für Jahre davor `co2_strom`.
     */
    public const CO2_STROM_DE_UBA = [
        2015 => 530.0, 2016 => 524.0, 2017 => 490.0, 2018 => 474.0, 2019 => 409.0,
        2020 => 365.0, 2021 => 406.0, 2022 => 433.0, 2023 => 379.0, 2024 => 353.0,
        2025 => 344.0,
    ];

    /**
     * v3.1.0 (Paket H4, B4) — maßgeblicher CO₂-Preis in €/t (CO2KostAufG § 4,
     * BEHG § 10): 2021–2025 Festpreise, 2026 Mitte des Korridors 55–65 €/t. Für
     * spätere Jahre gilt der letzte Wert als Annahme (Co2CostService meldet das),
     * bis er hier oder in der Einstellung co2_price_eur_t_years steht.
     */
    public const CO2_PRICE_DE_BEHG = [2021 => 25.0, 2022 => 30.0, 2023 => 30.0, 2024 => 45.0, 2025 => 55.0, 2026 => 60.0];

    /**
     * v3.1.0 (H6, MKT-14) — Strompreispauschale für das Laden des Dienstwagens
     * zu Hause in ct/kWh (BMF-Schreiben vom 11.11.2025, Rn. 30): Destatis-Preis
     * des 1. Halbjahres des Vorjahres, Band 5.000–15.000 kWh, abgerundet.
     * 2026: 34 ct (1. Hj. 2025: 34,36 ct). Spätere Jahre erst, wenn veröffentlicht.
     */
    public const EV_FLAT_RATE_DE = [2026 => 34.0];

    /**
     * v3.1.0 (H4) — Standardemissionsfaktoren des Brennstoffemissionshandels
     * (EBeV 2030, Anlage 2 Teil 4) in kg CO₂ je kWh, so wie die App rechnet:
     * Gas je kWh der Rechnung (Brennwert Hs: 0,0558 t/GJ × 3,2508 GJ/MWh),
     * Heizöl je kWh Heizwert. Nicht die BAFA-Faktoren der CO₂-Bilanz (mit
     * Vorkette). Holz/Pellets und Strom kennt das BEHG nicht; Fernwärme rechnet
     * nur mit dem Wert des Versorgers.
     */
    public const CO2_BEHG_FACTORS = ['gas' => 0.18139, 'heizoel' => 0.2664];

    /** v3.1.0 — Umsatzsteuer auf den CO₂-Preis (CO2KostAufG § 3 Abs. 3), je Schema. */
    public const CO2_VAT = ['behg' => 0.19];

    /** Währungen mit Untereinheit (Katalog-Platzhalter {code}, {cur}, {minor}). */
    public const CURRENCIES = [
        'EUR' => ['symbol' => '€',   'minor' => 'ct'],
        'CHF' => ['symbol' => 'CHF', 'minor' => 'Rp.'],
        'GBP' => ['symbol' => '£',   'minor' => 'p'],
    ];

    /**
     * v3.1.0 (Review I18N-27) — `bill_terms`: Glossar-ID → Wortlaut auf der
     * Rechnung des Landes (Zitat im Landesidiom; CH je Sprache). Nur belegte
     * Begriffe, und nur wo der Rechnungsbegriff dasselbe meint — der französische
     * „coefficient de conversion" ist der Gesamtfaktor, keine Zustandszahl.
     * `comparison_portal`: amtlicher bzw. regulatorischer Tarifvergleich, sonst
     * null (DE, CH, NL, GB haben keinen). Belege: docs/verstehen/14-laenderprofile.md.
     *
     * @var array<string,array<string,mixed>>
     */
    private const PROFILES = [
        'DE' => ['languages' => ['de'], 'currency' => 'EUR', 'timezone' => 'Europe/Berlin',
                 'hdd_base_temp' => 15.0, 'co2_strom' => 380.0, 'co2_strom_source' => 'uba',
                 'co2_strom_years' => self::CO2_STROM_DE_UBA,
                 'location' => ['Leipzig Zentrum', 51.3397, 12.3731],
                 'efficiency_scale' => 'geg', 'gas_cv_unit' => 'kwh',
                 // v3.1.0 (H4) — CO₂-Preis nach BEHG, Aufteilung nach CO2KostAufG
                 'co2_price_scheme' => 'behg', 'co2_price_years' => self::CO2_PRICE_DE_BEHG,
                 // v3.1.0 (H6) — Ladestrom-Pauschale je Jahr (ct/kWh)
                 'ev_flat_rate_ct_years' => self::EV_FLAT_RATE_DE,
                 'bill_terms' => ['workingPrice' => 'Arbeitspreis / Verbrauchspreis', 'basePrice' => 'Grundpreis', 'advance' => 'Abschlag',
                                  'zNumber' => 'Zustandszahl (z-Zahl)', 'calorificValue' => 'Brennwert', 'balance' => 'Nachzahlung / Guthaben'],
                 'comparison_portal' => null],
        'AT' => ['languages' => ['de'], 'currency' => 'EUR', 'timezone' => 'Europe/Vienna',
                 'hdd_base_temp' => 15.0, 'co2_strom' => 103.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Wien', 48.2082, 16.3738],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'kwh',
                 'bill_terms' => ['workingPrice' => 'Energie-Verbrauchspreis', 'basePrice' => 'Energie-Grundpreis', 'advance' => 'Teilbetrag',
                                  'calorificValue' => 'Verrechnungsbrennwert', 'balance' => 'Nachzahlung / Guthaben'],
                 'comparison_portal' => 'https://www.e-control.at/tarifkalkulator'],
        'CH' => ['languages' => ['de', 'fr', 'it'], 'currency' => 'CHF', 'timezone' => 'Europe/Zurich',
                 'hdd_base_temp' => 15.0, 'co2_strom' => 35.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Bern', 46.9480, 7.4474],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'kwh',
                 'bill_terms' => [
                     'de' => ['workingPrice' => 'Arbeitspreis („Arbeit“)', 'basePrice' => 'Grundpreis / Grundtarif', 'advance' => 'Akontozahlung',
                              'zNumber' => 'Zustandszahl', 'calorificValue' => 'Brennwert'],
                     'fr' => ['workingPrice' => 'Prix du kilowattheure', 'basePrice' => 'Abonnement', 'advance' => 'Acompte',
                              'calorificValue' => 'Pouvoir calorifique supérieur (PCS)'],
                     'it' => ['workingPrice' => 'Prezzo dell’energia', 'basePrice' => 'Tassa base', 'advance' => 'Acconto', 'balance' => 'Conguaglio'],
                 ],
                 'comparison_portal' => null],
        'FR' => ['languages' => ['fr'], 'currency' => 'EUR', 'timezone' => 'Europe/Paris',
                 'hdd_base_temp' => 18.0, 'co2_strom' => 40.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Paris', 48.8566, 2.3522],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'kwh',
                 'bill_terms' => ['workingPrice' => 'Prix du kWh', 'basePrice' => 'Abonnement', 'advance' => 'Mensualité',
                                  'calorificValue' => 'PCS (pouvoir calorifique supérieur)', 'balance' => 'Régularisation (à payer ou trop-perçu)'],
                 'comparison_portal' => 'https://comparateur-offres.energie-info.fr/'],
        'IT' => ['languages' => ['it'], 'currency' => 'EUR', 'timezone' => 'Europe/Rome',
                 'hdd_base_temp' => 20.0, 'co2_strom' => 281.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Roma', 41.9028, 12.4964],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'gj',
                 'bill_terms' => ['workingPrice' => 'Quota consumi (prima: quota energia)', 'basePrice' => 'Quota fissa',
                                  'zNumber' => 'Coefficiente C', 'calorificValue' => 'Potere calorifico superiore (P)', 'balance' => 'Ricalcoli / conguaglio'],
                 'comparison_portal' => 'https://www.ilportaleofferte.it/portaleOfferte/'],
        'ES' => ['languages' => ['es'], 'currency' => 'EUR', 'timezone' => 'Europe/Madrid',
                 'hdd_base_temp' => 15.0, 'co2_strom' => 146.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Madrid', 40.4168, -3.7038],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'kwh',
                 'bill_terms' => ['workingPrice' => 'Término de energía / término variable', 'basePrice' => 'Término fijo / término de potencia',
                                  'calorificValue' => 'PCS (poder calorífico superior)', 'balance' => 'Regularización'],
                 'comparison_portal' => 'https://comparador.cnmc.gob.es/'],
        'PT' => ['languages' => ['pt'], 'currency' => 'EUR', 'timezone' => 'Europe/Lisbon',
                 'hdd_base_temp' => 15.0, 'co2_strom' => 111.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['Lisboa', 38.7223, -9.1393],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'kwh',
                 'bill_terms' => ['workingPrice' => 'Preço da energia', 'basePrice' => 'Termo fixo / potência contratada',
                                  'calorificValue' => 'PCS (poder calorífico superior)', 'balance' => 'Acerto de faturação'],
                 'comparison_portal' => 'https://simuladorprecos.erse.pt/'],
        'NL' => ['languages' => ['nl'], 'currency' => 'EUR', 'timezone' => 'Europe/Amsterdam',
                 'hdd_base_temp' => 18.0, 'co2_strom' => 251.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['De Bilt', 52.1017, 5.1778],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'mj',
                 'bill_terms' => ['workingPrice' => 'Leveringstarief', 'basePrice' => 'Vaste leveringskosten (vroeger: vastrecht)', 'advance' => 'Termijnbedrag',
                                  'zNumber' => 'Correctiefactor', 'calorificValue' => 'Calorische waarde', 'balance' => 'Jaarafrekening: terugbetaling of bijbetaling'],
                 'comparison_portal' => null],
        'GB' => ['languages' => ['en'], 'currency' => 'GBP', 'timezone' => 'Europe/London',
                 'hdd_base_temp' => 15.5, 'co2_strom' => 217.0, 'co2_strom_source' => 'ember-2024',
                 'location' => ['London', 51.5074, -0.1278],
                 'efficiency_scale' => null, 'gas_cv_unit' => 'mj',
                 'bill_terms' => ['workingPrice' => 'Unit rate', 'basePrice' => 'Standing charge', 'advance' => 'Direct Debit',
                                  'zNumber' => 'Correction factor (1.02264)', 'calorificValue' => 'Calorific value', 'balance' => 'Account balance (in credit / in debit)'],
                 'comparison_portal' => null],
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
            // v2.10.0 — Jahreswerte gibt es nur für Deutschland; andere Länder
            // rechnen mit ihrem einen Wert und erben keine deutschen Jahre
            'co2_strom_years' => $p['co2_strom_years'] ?? [],
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

    /** v3.1.0 (H4) — CO₂-Preis-Schema des Landes (behg) oder null. */
    public static function co2Scheme(string $code): ?string
    {
        return self::get($code)['co2_price_scheme'] ?? null;
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
