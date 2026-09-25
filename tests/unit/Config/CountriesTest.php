<?php
declare(strict_types=1);

namespace Energietracker\Tests\Config;

use Energietracker\Config\Countries;
use Energietracker\Services\SettingsService;
use Energietracker\Storage\JsonStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v2.7.0 — Länderprofile (N1014). Die Profile sind Daten; geprüft wird, dass
 * sie in sich stimmen, dass jede Oberfläche die Länder benennen kann und dass
 * das deutsche Profil genau die bisherigen Voreinstellungen trägt — sonst
 * änderte das Update still jede Bestandsinstallation.
 */
#[CoversClass(Countries::class)]
final class CountriesTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,mixed> */
    private static function catalog(string $lang): array
    {
        return json_decode((string)file_get_contents(self::root() . "/public/locales/$lang.json"), true);
    }

    public function testGermanProfileEqualsTheShippedDefaults(): void
    {
        $dir = sys_get_temp_dir() . '/et-countries-' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        try {
            $defaults = (new SettingsService(new JsonStore(realpath($dir) ?: $dir)))->all();
            foreach (Countries::settingsFor('DE') as $key => $value) {
                self::assertSame($value, $defaults[$key] ?? null,
                    "Das DE-Profil weicht bei „$key\" vom ausgelieferten Default ab — Bestandsinstallationen würden sich ändern");
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testEveryProfileIsComplete(): void
    {
        $zones = \DateTimeZone::listIdentifiers();
        foreach (Countries::all() as $p) {
            $c = $p['code'];
            self::assertNotEmpty($p['languages'], "$c: keine Sprache");
            foreach ($p['languages'] as $lang) {
                self::assertFileExists(self::root() . "/public/locales/$lang.json", "$c: Sprache $lang hat keinen Katalog");
            }
            self::assertArrayHasKey($p['currency'], Countries::CURRENCIES, "$c: unbekannte Währung");
            self::assertContains($p['timezone'], $zones, "$c: unbekannte Zeitzone");
            self::assertGreaterThanOrEqual(-90, $p['latitude']);
            self::assertLessThanOrEqual(90, $p['latitude']);
            self::assertGreaterThanOrEqual(-180, $p['longitude']);
            self::assertLessThanOrEqual(180, $p['longitude']);
            self::assertNotSame('', $p['location_name'], "$c: Standort ohne Namen");
            self::assertGreaterThanOrEqual(10.0, $p['hdd_base_temp'], "$c: Heizgrenze unplausibel");
            self::assertLessThanOrEqual(22.0, $p['hdd_base_temp'], "$c: Heizgrenze unplausibel");
            self::assertGreaterThan(0.0, $p['co2_strom'], "$c: CO₂-Faktor fehlt");
            self::assertLessThan(1000.0, $p['co2_strom'], "$c: CO₂-Faktor unplausibel");
            self::assertContains($p['co2_strom_source'], ['uba', 'ember-2024'], "$c: Quelle des CO₂-Faktors unbekannt");
            self::assertContains($p['efficiency_scale'], [null, 'geg'], "$c: unbekannte Effizienzskala");
            self::assertContains($p['gas_cv_unit'], SettingsService::GAS_CV_UNITS, "$c: unbekannte Brennwert-Einheit");
        }
    }

    /**
     * Die Klassen A+ bis H stammen aus dem deutschen GEG. Eine Klasse aus
     * gemessenem Verbrauch wäre anderswo irreführend (in Frankreich liest
     * man „E" als DPE-Klasse).
     */
    public function testOnlyGermanyHasAnEfficiencyScale(): void
    {
        $withScale = array_values(array_filter(Countries::codes(), fn($c) => Countries::efficiencyScale($c) !== null));
        self::assertSame(['DE'], $withScale);
    }

    public function testEveryCountryHasANameInEveryCatalog(): void
    {
        $languages = array_keys(json_decode((string)file_get_contents(self::root() . '/public/locales/languages.json'), true));
        foreach ($languages as $lang) {
            $names = self::catalog($lang)['countries'] ?? [];
            foreach (Countries::codes() as $code) {
                self::assertNotEmpty($names[$code] ?? null, "$lang.json: countries.$code fehlt");
            }
        }
    }

    public function testProfileValuesAreKnownSettings(): void
    {
        $dir = sys_get_temp_dir() . '/et-countries-' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        try {
            $known = (new SettingsService(new JsonStore(realpath($dir) ?: $dir)))->knownKeys();
            foreach (Countries::codes() as $code) {
                self::assertSame([], array_diff(array_keys(Countries::settingsFor($code)), $known),
                    "$code: Profil setzt Schlüssel, die SettingsService nicht kennt");
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /** Frontend (Ein-/Ausgabe) und Backend (Validierung) kennen dieselben Einheiten. */
    public function testFrontendCalorificUnitsMatchTheBackend(): void
    {
        $js = (string)file_get_contents(self::root() . '/public/js/lib/gas-factor.js');
        preg_match_all("/^\s*(\w+):\s*\{\s*label:/m", $js, $m);
        self::assertSame(SettingsService::GAS_CV_UNITS, $m[1]);
    }

    /** @return array<string,array{0:?string,1:?string}> */
    public static function acceptLanguageCases(): array
    {
        return [
            'Österreich'               => ['de-AT,de;q=0.9,en;q=0.8', 'AT'],
            'Schweiz, französisch'     => ['fr-CH, fr;q=0.9', 'CH'],
            'Vereinigtes Königreich'   => ['en-GB,en;q=0.9', 'GB'],
            'UK statt GB'              => ['en-UK', 'GB'],
            'Unterstrich'              => ['es_ES', 'ES'],
            'unbekannte Region'        => ['en-US,en;q=0.9', null],
            'nur Sprache'              => ['de', null],
            'erste bekannte Region'    => ['en-US,de-DE;q=0.5', 'DE'],
            'höhere Gewichtung gewinnt'=> ['nl-NL;q=0.2,it-IT;q=0.8', 'IT'],
            'q=0 heißt „nicht"'        => ['pt-PT;q=0', null],
            'leer'                     => ['', null],
            'fehlt'                    => [null, null],
        ];
    }

    #[DataProvider('acceptLanguageCases')]
    public function testCountryFromAcceptLanguage(?string $header, ?string $expected): void
    {
        self::assertSame($expected, Countries::fromAcceptLanguage($header));
    }

    public function testCountryForALanguageWithoutRegion(): void
    {
        self::assertSame('GB', Countries::forLanguage('en'));
        self::assertSame('DE', Countries::forLanguage('de'));
        self::assertSame('NL', Countries::forLanguage('nl'));
        self::assertSame('DE', Countries::forLanguage('xx'), 'Unbekannte Sprache fällt auf das Standardland');
    }
}
