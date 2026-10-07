<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\CsvExportService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherService;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.1.0 (Review I18N-10) — CSV-Format 1 ist eingefroren.
 *
 * Skripte und Tabellen lesen die Exporte seit v1.1.0; CSV ist Stabilitätsklasse A
 * (nur additiv). Neben Format 1 gibt es seit v3.1.0 das Format „local" in der
 * Sprache der Installation — dieser Test hält fest, dass Format 1 dabei Byte für
 * Byte bleibt: Kopfzeilen (die Monatsübersicht über die eingefrorenen `csv.*`
 * aller Sprachen), Trenner, Dezimalkomma, „ja/nein", BOM, CRLF, Formelschutz.
 *
 * Die Vergleichsdateien unter tests/fixtures/csv-format-1/ entstanden mit dem
 * Stand v3.0.0. Neu schreiben nur, wenn sich Format 1 bewusst ändern soll
 * (ET_WRITE_GOLDEN=1) — und dann ist es kein Format 1 mehr.
 */
final class CsvFormatV1Test extends ServiceTestCase
{
    private const DIR = __DIR__ . '/../../fixtures/csv-format-1';

    private function export(): CsvExportService
    {
        return new CsvExportService(
            $this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n
        );
    }

    private function seed(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['date' => '2025-01-01', 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => '2025-02-01', 'counter' => 310.5, 'device_id' => 'd1', 'is_estimated' => true, 'note' => '=HYPERLINK("x")'],
            ['date' => '2025-03-01', 'counter' => 590.25, 'device_id' => 'd1', 'price_cents' => 31.5, 'note' => 'Notiz; mit "Zeichen"'],
            ['date' => '2025-04-01', 'counter' => 1234.5678, 'device_id' => 'd1', 'is_future' => true],
        ]);
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'X', 'start' => '2025-01-01',
            'working_prices'   => [['from' => '2025-01-01', 'ct_per_kwh' => 30]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 80]],
        ]);
        $tank = $this->meters->list('heizoel')[0]['id'] ?? 'm_heizoel';
        $this->store->write('heizoel/deliveries.json', [
            ['id' => 'del_1', 'meter_id' => $tank, 'date' => '2025-01-10', 'quantity' => 1500.0,
             'unit_price_cents' => 98.7, 'total_eur' => null, 'supplier' => 'Lieferant A', 'note' => '', 'is_planned' => false],
            ['id' => 'del_2', 'meter_id' => $tank, 'date' => '2025-09-01', 'quantity' => 2000.0,
             'unit_price_cents' => null, 'total_eur' => 1850.4, 'supplier' => '', 'note' => '+geplant', 'is_planned' => true],
        ]);
        $this->store->write('temperatures.json', [
            '2025-01-15' => ['avg' => 1.25, 'min' => -3.5, 'max' => 4.0, 'source' => 'archive'],
            '2025-01-16' => ['avg' => -0.1, 'min' => -6.0, 'max' => 2.75, 'source' => 'csv'],
        ]);
    }

    private function assertGolden(string $name, string $actual): void
    {
        $file = self::DIR . "/$name";
        if (getenv('ET_WRITE_GOLDEN') === '1') {
            @mkdir(self::DIR, 0755, true);
            file_put_contents($file, $actual);
        }
        self::assertFileExists($file, "Vergleichsdatei $name fehlt");
        self::assertSame((string)file_get_contents($file), $actual, "CSV-Format 1 hat sich geändert: $name");
    }

    public function testReadingsDeliveriesAndTemperaturesStayByteIdentical(): void
    {
        $this->seed();
        $csv = $this->export();
        $this->assertGolden('readings-strom.csv', $csv->readings('strom'));
        $this->assertGolden('deliveries-heizoel.csv', $csv->deliveries('heizoel'));
        $this->assertGolden('temperatures.csv', $csv->temperatures());

        $readings = $csv->readings('strom');
        self::assertStringStartsWith("\u{FEFF}Zaehler-ID;Zaehler;Geraet-ID;Datum;Zaehlerstand;Preis (ct);Notiz;Geschaetzt;Zukunft\r\n", $readings);
        self::assertStringContainsString(";1234,5678;", $readings, 'Dezimalkomma, bis 4 Stellen');
        self::assertStringContainsString(';"\'=HYPERLINK(""x"")";ja;nein', $readings, 'Formelschutz, Quoting und ja/nein');
    }

    /** Die Monatsübersicht trägt die eingefrorenen csv.* der Standardsprache. */
    public function testMonthlyHeaderInEveryLanguage(): void
    {
        $this->seed();
        $languages = array_keys((array)json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/public/locales/languages.json'), true));
        foreach ($languages as $lang) {
            $this->i18n->setLocale($lang);
            $actual = $this->export()->monthly('strom');
            $file = self::DIR . "/monthly-strom-$lang.csv";
            if (getenv('ET_WRITE_GOLDEN') === '1') file_put_contents($file, $actual);
            // Nur die Kopfzeile ist Format; die Zahlen darunter gehören der Verbrauchsrechnung.
            $head = static fn(string $s): string => substr($s, 0, (int)strpos($s, "\r\n") + 2);
            self::assertSame($head((string)file_get_contents($file)), $head($actual), "Kopfzeile Monatsübersicht ($lang)");
            self::assertMatchesRegularExpression('/^\x{FEFF}[^\r\n]+\r\n(\d{4}-\d{2};\d+;[^\r\n]*\r\n)+$/u', $actual, "Aufbau ($lang)");
        }
    }

    public function testFileNames(): void
    {
        $csv = $this->export();
        $date = date('Y-m-d');
        self::assertSame("energietracker-strom-monatsuebersicht-$date.csv", $csv->filename('monthly', 'strom'));
        self::assertSame("energietracker-strom-ablesungen-$date.csv", $csv->filename('readings', 'strom'));
        self::assertSame("energietracker-heizoel-lieferungen-$date.csv", $csv->filename('deliveries', 'heizoel'));
        self::assertSame("energietracker-temperaturen-$date.csv", $csv->filename('temperatures'));
    }
}
