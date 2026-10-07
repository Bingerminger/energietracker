<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\CsvExportService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.1.0 (Review I18N-10/11) — CSV im Format „local" und der Import fremder Tabellen.
 *
 * Bis v3.0 waren Ablesungen, Lieferungen und Temperaturen immer deutsch
 * („Zaehler-ID", „ja/nein", Dezimalkomma), und der Import las nur TT.MM.JJJJ
 * oder ISO mit deutscher oder englischer Kopfzeile: Eine französische Tabelle
 * scheiterte Zeile für Zeile, eine spanische Kopfzeile galt als Datenzeile.
 */
final class CsvLocalFormatTest extends ServiceTestCase
{
    private function export(): CsvExportService
    {
        return new CsvExportService(
            $this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n
        );
    }

    private function seed(): string
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['date' => '2025-01-01', 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => '2025-02-01', 'counter' => 1234.5678, 'device_id' => 'd1', 'is_estimated' => true, 'note' => 'a, b; c'],
        ]);
        return $meterId;
    }

    /** @return list<list<string>> */
    private static function parse(string $csv, string $sep): array
    {
        $csv = ltrim($csv, "\u{FEFF}");
        self::assertStringEndsWith("\r\n", $csv, 'CRLF');
        return array_map(fn($l) => str_getcsv($l, $sep, '"', ''), explode("\r\n", rtrim($csv, "\r\n")));
    }

    public function testFrenchSpreadsheet(): void
    {
        $this->seed();
        $this->settings->set(['language' => 'fr']);
        $csv = $this->export()->readings('strom', 'local');
        self::assertStringStartsWith("\u{FEFF}", $csv, 'BOM');
        $rows = self::parse($csv, ';');
        self::assertSame(['ID compteur', 'Compteur', 'ID appareil', 'Date', 'Index', 'Prix (ct)', 'Note', 'Estimé', 'Futur'], $rows[0]);
        self::assertSame(['01/02/2025', '1234,5678', 'a, b; c', 'Oui', 'Non'], [$rows[2][3], $rows[2][4], $rows[2][6], $rows[2][7], $rows[2][8]]);
        self::assertMatchesRegularExpression('/^energietracker-strom-releves-\d{4}-\d{2}-\d{2}\.csv$/', $this->export()->filename('readings', 'strom', 'local'));
        // Format 1 bleibt daneben unverändert
        self::assertStringStartsWith("\u{FEFF}Zaehler-ID;", $this->export()->readings('strom'));
    }

    public function testEnglishUsesCommaSeparatorAndDecimalPoint(): void
    {
        $this->seed();
        $rows = self::parse($this->export()->readings('strom', 'local', 'en'), ',');
        self::assertSame('Reading', $rows[0][4]);
        self::assertSame(['01/02/2025', '1234.5678', 'a, b; c', 'Yes', 'No'], [$rows[2][3], $rows[2][4], $rows[2][6], $rows[2][7], $rows[2][8]]);
    }

    public function testDutchDateAndSwissGermanDecimalPoint(): void
    {
        $this->seed();
        $rows = self::parse($this->export()->readings('strom', 'local', 'nl'), ';');
        self::assertSame('01-02-2025', $rows[2][3]);

        $this->settings->set(['country' => 'CH', 'currency' => 'CHF']);
        $csv = $this->export()->readings('strom', 'local', 'de');
        $rows = self::parse($csv, ',');
        self::assertSame(['Zählerstand', 'Preis (Rp.)'], [$rows[0][4], $rows[0][5]], 'Kopf ohne ASCII-Ersatz, Untereinheit der Währung');
        self::assertSame('1234.5678', $rows[2][4], 'de-CH: Dezimalpunkt, darum Komma als Feldtrenner');
        self::assertStringContainsString('Kosten (CHF)', $this->export()->monthly('strom', 'local', 'de'));
    }

    public function testUnknownFormatIsRejectedWithCode(): void
    {
        try {
            $this->export()->temperatures('xlsx');
            self::fail('Ausnahme erwartet');
        } catch (LocalizedException $e) {
            self::assertSame('errors.export.formatInvalid', $e->key);
        }
    }

    public function testTheRequestLanguageIsRestored(): void
    {
        $this->seed();
        $this->i18n->setLocale('it');
        $this->export()->readings('strom', 'local', 'fr');
        self::assertSame('it', $this->i18n->locale());
    }

    // ── Import ───────────────────────────────────────────────────────────

    private function import(string $csv): array
    {
        $meterId = $this->meters->list('strom')[0]['id'];
        return (new ReadingImportService($this->readings, $this->meters, $this->i18n))->importCsv('strom', $meterId, $csv, true);
    }

    public function testFrenchFileFromTheReview(): void
    {
        $this->seed();
        $r = $this->import("date;relevé;note\n15/01/2026;1234,5;test\n2026-02-15;1250,0;iso\n");
        self::assertSame([], $r['errors']);
        self::assertSame(['2026-01-15', '2026-02-15'], array_column($r['rows'], 'date'));
        self::assertSame(1234.5, $r['rows'][0]['counter']);
    }

    public function testSpanishHeaderAndBoolean(): void
    {
        $this->seed();
        $r = $this->import("Fecha;Lectura;Nota;Estimada\n15/01/2026;1.234,5;;sí\n");
        self::assertSame([], $r['errors']);
        self::assertCount(1, $r['rows'], 'Kopfzeile ist keine Datenzeile');
        self::assertTrue($r['rows'][0]['is_estimated']);
        self::assertSame(1234.5, $r['rows'][0]['counter']);
    }

    public function testItalianDutchEnglish(): void
    {
        $this->seed();
        self::assertSame('2026-01-15', $this->import("Data;Lettura\n15/01/2026;100\n")['rows'][0]['date']);
        self::assertSame('2026-01-15', $this->import("Datum;Meterstand\n15-01-2026;100\n")['rows'][0]['date']);
        $en = $this->import("date,reading\n15/01/2026,100.5\n");
        self::assertSame([], $en['errors']);
        self::assertSame(100.5, $en['rows'][0]['counter']);
    }

    public function testImpossibleAndMonthFirstDates(): void
    {
        $this->seed();
        $bad = $this->import("Date;Index\n31/02/2026;100\n");
        self::assertCount(0, $bad['rows']);
        self::assertStringContainsString('31/02/2026', $bad['errors'][0]);

        $us = $this->import("Date;Reading\n01/13/2026;100\n");
        self::assertCount(0, $us['rows'], 'MM/TT wird nie still umgedeutet');
        self::assertSame('errors.import.dateMonthFirst', $this->i18n->errorCodeFor($us['errors'][0]));
    }

    /** Jede Export-Datei lässt sich wieder einlesen — Format 1 und local in jeder Sprache. */
    public function testEveryExportCanBeImportedAgain(): void
    {
        $this->seed();
        $languages = array_keys((array)json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/public/locales/languages.json'), true));
        $files = ['1' => $this->export()->readings('strom')];
        foreach ($languages as $lang) $files[$lang] = $this->export()->readings('strom', 'local', $lang);
        foreach ($files as $name => $csv) {
            $r = $this->import($csv);
            self::assertSame([], $r['errors'], "Import von $name");
            self::assertSame(['2025-01-01', '2025-02-01'], array_column($r['rows'], 'date'), "Zeilen von $name");
            self::assertSame(1234.5678, $r['rows'][1]['counter'], "Stand von $name");
            self::assertTrue($r['rows'][1]['is_estimated'], "geschätzt von $name");
            self::assertSame('a, b; c', $r['rows'][1]['note'], "Notiz von $name");
        }
    }

    public function testTemperaturesInEveryLanguageCanBeImported(): void
    {
        $temps = new TemperatureService($this->store, $this->settings, new WeatherService());
        $fr = $temps->importCsv("Date;Moyenne (°C);Min (°C);Max (°C)\n15/01/2024;4,2;-1;7,1\n");
        self::assertSame(1, $fr['imported']);
        self::assertSame(0, $fr['skipped']);
        $this->store->write('temperatures.json', [
            '2024-01-16' => ['avg' => -0.5, 'min' => -3.25, 'max' => 2.0, 'source' => 'csv'],
        ]);
        foreach (['de', 'en', 'nl', 'pt'] as $lang) {
            $csv = $this->export()->temperatures('local', $lang);
            $this->store->write('temperatures.json', []);
            $r = $temps->importCsv($csv);
            self::assertSame([1, 0], [$r['imported'], $r['skipped']], "Temperaturen $lang");
            self::assertSame(-3.25, $temps->all()['2024-01-16']['min'] ?? null, "Wert $lang");
            $this->store->write('temperatures.json', ['2024-01-16' => ['avg' => -0.5, 'min' => -3.25, 'max' => 2.0, 'source' => 'csv']]);
        }
    }
}
