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

/**
 * v2.6.0 — CSV-Import, wie ihn Excel-Nutzer tatsächlich füttern
 * (Review 2026-09-24, API-13/14/22).
 */
#[CoversClass(ReadingImportService::class)]
#[CoversClass(CsvExportService::class)]
final class CsvImportRobustnessTest extends ServiceTestCase
{
    private string $meterId;
    private ReadingImportService $import;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->import = new ReadingImportService($this->readings, $this->meters, $this->i18n);
    }

    private function export(): CsvExportService
    {
        return new CsvExportService(
            $this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()),
            new DeliveryService($this->store, $this->meters, $this->i18n),
            $this->i18n
        );
    }

    public function testAWindows1252FileIsConvertedInsteadOfBreakingMidway(): void
    {
        $csv = mb_convert_encoding("datum;zählerstand;notiz\n01.02.2024;100;Zählerwechsel geprüft\n01.03.2024;200;\n", 'Windows-1252', 'UTF-8');
        $res = $this->import->importCsv('strom', $this->meterId, $csv);
        self::assertSame(2, $res['imported']);
        self::assertSame('Windows-1252', $res['encoding_converted_from']);
        $notes = array_column($this->readings->list('strom', $this->meterId), 'note');
        self::assertContains('Zählerwechsel geprüft', $notes);
    }

    public function testTheOwnExportCanBeImportedAgain(): void
    {
        $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2024-02-01', 'counter' => 100, 'note' => 'A; mit Semikolon']);
        $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2024-03-01', 'counter' => 250.5]);
        $csv = $this->export()->readings('strom');

        $this->store->write('strom/readings.json', []);
        $res = $this->import->importCsv('strom', $this->meterId, $csv);
        self::assertSame(2, $res['imported'], 'Export → Import ohne Nacharbeit');
        $rows = $this->readings->list('strom', $this->meterId);
        self::assertSame([100.0, 250.5], array_map(fn($r) => (float)$r['counter'], $rows));
        self::assertSame('A; mit Semikolon', $rows[0]['note'], 'gequotete Zelle bleibt ganz');
    }

    public function testRowsOfOtherMetersInAnExportAreLeftOut(): void
    {
        $csv = "Zaehler-ID;Zaehler;Geraet-ID;Datum;Zaehlerstand\n"
            . "{$this->meterId};Haupt;d1;2024-02-01;100\n"
            . "m_strom_other;Garten;dx;2024-02-01;5\n";
        $res = $this->import->importCsv('strom', $this->meterId, $csv);
        self::assertSame(1, $res['imported']);
        self::assertSame(1, $res['other_meter_rows']);
    }

    public function testAnImportIsOneWrite(): void
    {
        $lines = ["datum;zählerstand"];
        for ($d = 0; $d < 400; $d++) $lines[] = date('Y-m-d', strtotime("2024-01-02 +$d days")) . ';' . (1000 + $d * 10);
        $before = $this->store->generation();
        $res = $this->import->importCsv('strom', $this->meterId, implode("\n", $lines));
        self::assertSame(400, $res['imported']);
        self::assertLessThanOrEqual(2, $this->store->generation() - $before, 'höchstens Geräte-Vorverlegung + ein Schreibvorgang');
    }

    public function testFormulaLikeTextIsNeutralisedInTheExport(): void
    {
        $this->readings->create('strom', ['meter_id' => $this->meterId, 'date' => '2024-02-01', 'counter' => 100, 'note' => '=HYPERLINK("x")']);
        $csv = $this->export()->readings('strom');
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringNotContainsString(';=HYPERLINK', $csv);
    }
}
