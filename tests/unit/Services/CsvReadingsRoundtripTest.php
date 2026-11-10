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
 * v3.12.0 — CSV-Rundreise der Ablesungen: Export → Import in denselben Zähler → gleich.
 * Bisher endete eine mehrzeilige Notiz nach der ersten Zeile, „Geschätzt“ kippte auf nein,
 * und das Schutz-Apostroph vor „=1+1“ blieb als Teil der Notiz stehen.
 */
#[CoversClass(ReadingImportService::class)]
#[CoversClass(CsvExportService::class)]
final class CsvReadingsRoundtripTest extends ServiceTestCase
{
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
    }

    private function export(): CsvExportService
    {
        return new CsvExportService($this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
    }

    /** @return list<array<string,mixed>> was die Rundreise erhalten muss */
    private function snapshot(): array
    {
        return array_map(fn($r) => [$r['date'], (float)$r['counter'], $r['price_cents'] === null ? null : (float)$r['price_cents'],
            $r['note'], (bool)$r['is_estimated']], $this->readings->list('strom', $this->meterId));
    }

    /** @return iterable<string, array{0: string}> */
    public static function formats(): iterable
    {
        yield 'Format 1' => ['1'];
        yield 'Tabellenformat der Sprache' => ['local'];
    }

    #[DataProvider('formats')]
    public function testExportAndImportGiveTheSameReadings(string $format): void
    {
        foreach ([
            ['date' => '2025-02-01', 'counter' => 100, 'note' => "Zeile 1\nZeile 2", 'is_estimated' => true, 'price_cents' => 31.5],
            ['date' => '2025-03-01', 'counter' => 200.5, 'note' => '=1+1'],
            ['date' => '2025-04-01', 'counter' => 300, 'note' => "'=schon mit Apostroph", 'is_estimated' => true],
            ['date' => '2025-05-01', 'counter' => 400, 'note' => ' =mit Leerzeichen; und "Zitat"'],
            ['date' => '2025-06-01', 'counter' => 500, 'note' => "-5 Grad\r\nund Wind"],
            ['date' => '2025-07-01', 'counter' => 600, 'note' => '-5'],
        ] as $r) {
            $this->readings->create('strom', ['meter_id' => $this->meterId] + $r);
        }
        $before = $this->snapshot();
        $csv = $this->export()->readings('strom', $format);
        $this->store->write('strom/readings.json', []);

        $report = (new ReadingImportService($this->readings, $this->meters, $this->i18n))->importCsv('strom', $this->meterId, $csv);
        self::assertSame([], $report['errors']);
        self::assertSame(6, $report['imported']);
        self::assertArrayNotHasKey('other_meter_rows', $report, 'kein Bruchstück einer Zeile als „anderer Zähler“');
        // Zeilenumbrüche normalisiert der Export nicht: \r\n in der Notiz bleibt \r\n
        self::assertSame($before, $this->snapshot());
    }

    public function testPeriodNotesSurviveTheRoundTripToo(): void
    {
        $periods = new \Energietracker\Services\PeriodService($this->store, $this->meters, $this->i18n);
        $m = (string)$this->meters->create('waerme', ['name' => 'UVI', 'capture' => 'period', 'installed_on' => '2020-01-01'])['id'];
        $periods->create('waerme', ['meter_id' => $m, 'month' => '2025-01', 'value' => 812.5, 'note' => "Ablesung 1\nAblesung 2"]);
        $periods->create('waerme', ['meter_id' => $m, 'month' => '2025-02', 'value' => 745, 'note' => '=A1']);
        $csv = $this->export()->periods('waerme', $periods->list('waerme'));
        $this->store->write('waerme/periods.json', []);
        $r = $periods->importCsv('waerme', $m, $csv);
        self::assertSame([2, []], [$r['imported'], $r['errors']]);
        self::assertSame(["Ablesung 1\nAblesung 2", '=A1'], array_column($periods->list('waerme', $m), 'note'));
    }

    public function testAFormulaInANoteIsStillGuardedInTheFile(): void
    {
        $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-02-01', 'counter' => 1, 'note' => '=HYPERLINK("x")']);
        $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2025-03-01', 'counter' => 2, 'note' => ' @SUM(A1)']);
        $csv = $this->export()->readings('strom', '1');
        self::assertStringContainsString(';"\'=HYPERLINK(""x"")";', $csv, 'Apostroph vor der Formel, Zelle gequotet');
        self::assertStringContainsString(";' @SUM(A1);", $csv, 'auch nach Leerraum');
    }
}
