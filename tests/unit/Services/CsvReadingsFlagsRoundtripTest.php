<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\CsvExportService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 (K2d) — Die CSV-Rundreise der Ablesungen ist verlustfrei, wie die Doku sagt: auch „nur für die Abrechnung“
 * (`is_billing_only`), „Stand ist richtig“ (`is_verified`) und „gemeldet am“ (`reported_on`) gehen hin und zurück.
 * Bisher hatte die Datei für diese drei keine Spalte: Nach Export und Import zählte ein Stand „nur für die Abrechnung“
 * wieder als Monatsstand, und die Monatswerte verschoben sich.
 *
 * Handrechnung (eigene Werte, Strom, linear nach Tagen): Stände 01.03.2025 = 0, 20.04.2025 = 60 (nur für die
 * Abrechnung), 01.06.2025 = 184. Mit Merker überbrückt die Rechnung den mittleren Stand: 184 kWh auf 92 Tage
 * (31 + 30 + 31) = 2 kWh/Tag, März 62, April 60, Mai 62. Ohne Merker: 60 auf 50 Tage = 1,2/Tag (März 37,2,
 * April 19 Tage = 22,8), dann 124 auf 42 Tage = 2,952…/Tag (April 11 Tage = 32,5; Mai 31 Tage = 91,5):
 * März 37,2 statt 62, April 55,3 statt 60, Mai 91,5 statt 62.
 */
#[CoversClass(ReadingImportService::class)]
#[CoversClass(CsvExportService::class)]
final class CsvReadingsFlagsRoundtripTest extends ServiceTestCase
{
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
    }

    private function export(): CsvExportService
    {
        return new CsvExportService($this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
    }

    private function import(string $csv): array
    {
        return (new ReadingImportService($this->readings, $this->meters, $this->i18n))->importCsv('strom', $this->meterId, $csv);
    }

    /** @return array<string,float> Monat → kWh bis Mai 2025 */
    private function months(): array
    {
        $out = [];
        $rows = $this->consumption->forMeter('strom', (array)$this->meters->get('strom', $this->meterId), null, '2025-07-01');
        foreach ($rows['monthly'] ?? $rows as $m) {
            if (is_array($m) && ($m['ym'] ?? '') >= '2025-03' && ($m['ym'] ?? '') <= '2025-05') $out[$m['ym']] = round((float)$m['kwh'], 1);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> was die Rundreise erhalten muss */
    private function snapshot(): array
    {
        return array_map(fn($r) => [$r['date'], (float)$r['counter'], $r['note'], (bool)($r['is_estimated'] ?? false),
            $r['is_billing_only'] ?? null, $r['is_verified'] ?? null, $r['reported_on'] ?? null], $this->readings->stored('strom', $this->meterId));
    }

    /** @return iterable<string, array{0: string}> */
    public static function formats(): iterable
    {
        yield 'Format 1' => ['1'];
        yield 'Tabellenformat der Sprache' => ['local'];
    }

    #[DataProvider('formats')]
    public function testBillingOnlyVerifiedAndReportedOnSurviveTheRoundTrip(string $format): void
    {
        $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-03-01', 'counter' => 0]);
        $mid = $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-04-20', 'counter' => 60,
            'is_billing_only' => true, 'reported_on' => '2025-04-22', 'note' => "Rechnung\nSchluss"]);
        $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-06-01', 'counter' => 184]);
        $this->readings->update('strom', (string)$this->readings->list('strom', $this->meterId)[2]['id'], ['is_verified' => true]);
        self::assertSame(['2025-03' => 62.0, '2025-04' => 60.0, '2025-05' => 62.0], $this->months(), 'Handrechnung mit Merker');
        $before = $this->snapshot();

        $csv = $this->export()->readings('strom', $format);
        $this->store->write('strom/readings.json', []);
        $report = $this->import($csv);
        self::assertSame([[], 3], [$report['errors'], $report['imported']]);

        self::assertSame($before, $this->snapshot(), 'dieselben Ablesungen');
        self::assertSame(['2025-03' => 62.0, '2025-04' => 60.0, '2025-05' => 62.0], $this->months(), 'dieselben Monatswerte');
        self::assertNotSame('', (string)$mid['id']);
    }

    /** Variante: der Import über einen vorhandenen Stand (Überschreiben) setzt und nimmt die Merker wie die Datei. */
    public function testOverwritingTakesTheFlagsOfTheFile(): void
    {
        $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-03-01', 'counter' => 0]);
        $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-04-20', 'counter' => 60, 'is_billing_only' => true, 'reported_on' => '2025-04-22']);
        $csv = $this->export()->readings('strom', '1');
        // die Datei ohne Merker (wie von Hand bearbeitet): „nein“ und leer
        $csv = str_replace([';ja;2025-04-22', ';2025-04-22'], [';nein;', ';'], $csv);
        $this->import($csv);
        $r = $this->readings->stored('strom', $this->meterId)[1];
        self::assertArrayNotHasKey('is_billing_only', $r, $csv);
        self::assertArrayNotHasKey('reported_on', $r, $csv);
    }

    /** Variante: eine Datei ohne die neuen Spalten (älterer Export, eigene Tabelle) lässt die Merker, wie sie sind. */
    public function testAFileWithoutTheNewColumnsKeepsTheFlags(): void
    {
        $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-04-20', 'counter' => 60, 'is_billing_only' => true, 'reported_on' => '2025-04-22']);
        $report = $this->import("Datum;Zaehlerstand;Notiz\n2025-04-20;60;neu\n");
        self::assertSame(1, $report['overwritten']);
        $r = $this->readings->stored('strom', $this->meterId)[0];
        self::assertSame([true, '2025-04-22', 'neu'], [$r['is_billing_only'] ?? null, $r['reported_on'] ?? null, $r['note']]);
    }
}
