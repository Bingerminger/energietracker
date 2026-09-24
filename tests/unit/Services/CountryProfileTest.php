<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BenchmarkService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\I18nService;
use Energietracker\Services\PdfReportService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\SettingsService;
use Energietracker\Services\WeatherService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v2.7.0 — Länderprofile (N1014): was das Land im Backend verändert.
 *
 * Bis v2.6.0 schrieb das Backend Zahlen fest deutsch („10.359 kWh" auch im
 * englischen Bericht), Beträge fest in Euro und vergab Effizienzklassen nach
 * deutschem Recht in jedem Land. Die Tests prüfen die Schreibweise je Sprache
 * und Land, die Währung in den Katalogtexten, die Validierung der neuen
 * Einstellungen, die Klasse nur mit Skala und den PDF-Bericht.
 */
#[CoversClass(I18nService::class)]
#[CoversClass(SettingsService::class)]
#[CoversClass(BenchmarkService::class)]
#[CoversClass(WeatherService::class)]
final class CountryProfileTest extends ServiceTestCase
{
    /**
     * Open-Meteo bildet die Tagesmittel in der übergebenen Zeitzone. Bis
     * v2.6.0 stand dort fest Europe/Berlin — in Lissabon lag jede Tagesgrenze
     * eine Stunde daneben. Maßgeblich ist die Zeitzone der Installation.
     */
    public function testWeatherDaysFollowTheInstallationTimezone(): void
    {
        $before = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Lisbon');
            $w = new WeatherService();
            self::assertStringContainsString('timezone=Europe%2FLisbon', $w->archiveUrl(38.7, -9.1, '2024-01-01', '2024-01-31'));
            self::assertStringContainsString('timezone=Europe%2FLisbon', $w->forecastUrl(38.7, -9.1));
        } finally {
            date_default_timezone_set($before);
        }
    }

    private function useRegion(string $language, string $country, string $currency = 'EUR'): void
    {
        $this->settings->set(['language' => $language, 'country' => $country, 'currency' => $currency]);
        $this->i18n->setLocale($language);
    }

    /** @return array<string,array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}> */
    public static function formats(): array
    {
        $nb = "\u{00A0}";
        return [
            // Sprache, Land, Währung, Zahl, Betrag, Datum, Monat
            'Deutschland'     => ['de', 'DE', 'EUR', '1.234,5', '1.234,56 €', '05.01.2026', 'Sept. 2026'],
            'UK'              => ['en', 'GB', 'GBP', '1,234.5', '£1,234.56', '05/01/2026', 'Sept 2026'],
            'Frankreich'      => ['fr', 'FR', 'EUR', "1{$nb}234,5", "1{$nb}234,56{$nb}€", '05/01/2026', 'sept. 2026'],
            'Niederlande'     => ['nl', 'NL', 'EUR', '1.234,5', '€ 1.234,56', '05-01-2026', 'sep 2026'],
            'Italien'         => ['it', 'IT', 'EUR', '1.234,5', '1.234,56 €', '05/01/2026', 'set 2026'],
            'Schweiz deutsch' => ['de', 'CH', 'CHF', "1'234.5", "CHF 1'234.56", '05.01.2026', 'Sept. 2026'],
            'Schweiz franz.'  => ['fr', 'CH', 'CHF', "1'234,5", "1'234.56 CHF", '05.01.2026', 'sept. 2026'],
            'Österreich'      => ['de', 'AT', 'EUR', "1{$nb}234,5", '€ 1.234,56', '05.01.2026', 'Sept. 2026'],
        ];
    }

    #[DataProvider('formats')]
    public function testNumbersMoneyDatesFollowLanguageAndCountry(
        string $lang, string $country, string $currency,
        string $number, string $money, string $date, string $month,
    ): void {
        $this->useRegion($lang, $country, $currency);
        self::assertSame($number, $this->i18n->number(1234.5, 1), 'Zahl');
        self::assertSame($money, $this->i18n->money(1234.56), 'Betrag');
        self::assertSame($date, $this->i18n->date('2026-01-05'), 'Datum');
        self::assertSame($month, $this->i18n->month('2026-09'), 'Monat');
    }

    public function testNegativeAmountsKeepTheirSign(): void
    {
        self::assertSame('-5,00 €', $this->i18n->money(-5));
        $this->useRegion('en', 'GB', 'GBP');
        self::assertSame('-£5.00', $this->i18n->money(-5));
    }

    public function testCatalogTextsCarryTheConfiguredCurrency(): void
    {
        self::assertSame('ct/kWh', $this->i18n->t('contracts.unit.ctPerKwh'));
        $this->settings->set(['currency' => 'CHF']);
        self::assertSame('Rp./kWh', $this->i18n->t('contracts.unit.ctPerKwh'));
        $this->settings->set(['currency' => 'GBP']);
        self::assertSame('p/kWh', $this->i18n->t('contracts.unit.ctPerKwh'));
        self::assertSame('x/kWh', $this->i18n->t('contracts.unit.ctPerKwh', ['minor' => 'x']),
            'Ausdrückliche Parameter haben Vorrang vor der Währung');
    }

    /** Kein Katalogtext darf eine Währung fest einschreiben. */
    public function testNoCatalogHardcodesACurrency(): void
    {
        $flat = function (array $node, string $prefix = '') use (&$flat): array {
            $out = [];
            foreach ($node as $k => $v) {
                $key = $prefix === '' ? (string)$k : "$prefix.$k";
                if (is_array($v)) $out += $flat($v, $key);
                elseif (is_string($v)) $out[$key] = $v;
            }
            return $out;
        };
        foreach ($this->i18n->supported() as $lang) {
            $catalog = json_decode((string)file_get_contents(dirname(__DIR__, 3) . "/public/locales/$lang.json"), true);
            foreach ($flat($catalog) as $key => $value) {
                self::assertDoesNotMatchRegularExpression('/€|£|\bEUR\b|\bCHF\b|\bGBP\b/u', $value,
                    "$lang.json: $key schreibt eine Währung fest ein — {cur}, {minor} oder {code} verwenden");
            }
        }
    }

    /** @return array<string,array{0:string,1:mixed}> */
    public static function invalidRegionValues(): array
    {
        return [
            'Land'      => ['country', 'XX'],
            'Land klein'=> ['country', 'de'],
            'Währung'   => ['currency', 'USD'],
            'Zeitzone'  => ['timezone', 'Mars/Olympus_Mons'],
            'Einheit'   => ['gas_cv_unit', 'btu'],
        ];
    }

    #[DataProvider('invalidRegionValues')]
    public function testUnknownRegionValuesAreRejected(string $key, mixed $value): void
    {
        $this->settings->attachI18n($this->i18n);   // wie im Bootstrap: Meldung aus dem Katalog
        try {
            $this->settings->set([$key => $value]);
            self::fail("$key = $value hätte abgelehnt werden müssen");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($key, $e->getMessage());
            self::assertStringContainsString((string)$value, $e->getMessage());
        }
        self::assertNotSame($value, $this->settings->get($key), 'Der ungültige Wert darf nicht gespeichert sein');
    }

    public function testValidRegionValuesAreStored(): void
    {
        $this->settings->set([
            'country' => 'CH', 'currency' => 'CHF', 'timezone' => 'Europe/Zurich', 'gas_cv_unit' => 'gj',
        ]);
        self::assertSame('CH', $this->settings->get('country'));
        self::assertSame('CHF', $this->settings->get('currency'));
        self::assertSame('Europe/Zurich', $this->settings->get('timezone'));
        self::assertSame('gj', $this->settings->get('gas_cv_unit'));
        // ältere Namen, die Browser noch anbieten
        $this->settings->set(['timezone' => 'Europe/Kiev']);
        self::assertSame('Europe/Kiev', $this->settings->get('timezone'));
    }

    private function seedGasYear(): void
    {
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2023-12-01',
            'initial_counter' => 0.0, 'removed_on' => null,
            'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [];
        for ($i = 0; $i <= 12; $i++) {
            $d = (new \DateTimeImmutable('2024-01-01'))->modify("+$i months");
            $rows[] = ['date' => $d->format('Y-m-d'), 'counter' => 1000.0 + $i * 100.0, 'device_id' => 'd1'];
        }
        $this->setReadings('gas', $meterId, $rows);
        $this->settings->set(['wohnflaeche_m2' => 100]);
    }

    private function benchmark(): BenchmarkService
    {
        return new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
    }

    public function testGermanyGetsAnEfficiencyClass(): void
    {
        $this->seedGasYear();
        $eff = $this->benchmark()->efficiency(2024);
        self::assertSame('geg', $eff['scale']);
        self::assertNull($eff['scale_note']);
        self::assertNotNull($eff['per_source'][0]['class']);
        self::assertNotNull($eff['class']);
    }

    public function testCountriesWithoutScaleGetTheFigureButNoClass(): void
    {
        $this->seedGasYear();
        $de = $this->benchmark()->efficiency(2024);

        $this->settings->set(['country' => 'AT']);
        $at = $this->benchmark()->efficiency(2024);
        self::assertNull($at['scale']);
        self::assertNull($at['per_source'][0]['class'], 'Klasse je Quelle ohne Skala');
        self::assertNull($at['combined']['class'], 'kombinierte Klasse ohne Skala');
        self::assertNull($at['class'], 'Rückwärtskompatibles Top-Level-Feld ohne Skala');
        self::assertSame($de['per_source'][0]['kwh_per_m2'], $at['per_source'][0]['kwh_per_m2'],
            'Die Kennzahl selbst hängt nicht vom Land ab');
        self::assertStringContainsString('Österreich', (string)$at['scale_note']);
    }

    private function pdf(): PdfReportService
    {
        $benchmark = $this->benchmark();
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recommendations = new RecommendationService(
            $this->store, $this->meters, $this->consumption, $this->settings,
            $benchmark, $deliveries, $this->i18n
        );
        return new PdfReportService($this->meters, $this->consumption, $this->settings, $benchmark, $recommendations, $this->i18n);
    }

    /** @return string[] sichtbare Texte, zurück nach UTF-8 gewandelt (der PdfWriter schreibt CP1252) */
    private function pdfTexts(string $pdf): array
    {
        preg_match_all('/\((.*?)\) Tj/s', $pdf, $m);
        return array_map(fn($t) => (string)iconv('CP1252', 'UTF-8', stripslashes($t)), $m[1]);
    }

    public function testEnglishReportUsesEnglishNumbersAndThePound(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'ds', 'serial' => null, 'installed_on' => '2023-12-01',
            'initial_counter' => 0.0, 'removed_on' => null,
            'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [];
        for ($i = 0; $i <= 12; $i++) {
            $d = (new \DateTimeImmutable('2024-01-01'))->modify("+$i months");
            $rows[] = ['date' => $d->format('Y-m-d'), 'counter' => 1000.0 + $i * 250.0, 'device_id' => 'ds'];
        }
        $this->setReadings('strom', $meterId, $rows);

        $this->useRegion('en', 'GB', 'GBP');
        $texts = $this->pdfTexts($this->pdf()->build(2024));
        self::assertContains('3,000 kWh', $texts, 'Englische Tausendertrennung');
        self::assertNotContains('3.000 kWh', $texts);
        self::assertNotEmpty(array_filter($texts, fn($t) => str_starts_with($t, '£')), 'Beträge in Pfund');
        self::assertEmpty(array_filter($texts, fn($t) => str_contains($t, '€')), 'Kein Euro im britischen Bericht');
    }

    public function testReportWithoutScaleShowsTheFigureAndTheReason(): void
    {
        $this->seedGasYear();
        $this->settings->set(['country' => 'AT']);
        $texts = $this->pdfTexts($this->pdf()->build(2024));
        self::assertNotEmpty(array_filter($texts, fn($t) => str_starts_with($t, 'Heizenergie')),
            'Ohne Skala heißt der Kasten „Heizenergie", nicht „Effizienzklasse"');
        self::assertEmpty(array_filter($texts, fn($t) => str_starts_with($t, 'Effizienzklasse')));
        self::assertNotEmpty(array_filter($texts, fn($t) => str_contains($t, 'Österreich')),
            'Der Bericht nennt den Grund für die fehlende Klasse');
    }
}
