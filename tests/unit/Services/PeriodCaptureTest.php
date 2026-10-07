<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\IngestService;
use Energietracker\Services\PeriodService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H3, B2) — Verbrauch je Zeitraum: tagesgenau auf die Monate,
 * Lücken bleiben Lücken, keine Überlappung, Gas in m³ oder kWh, Ingest und
 * Ablesungen sind gesperrt, die Erfassungsart lässt sich mit Daten nicht wechseln.
 */
#[CoversClass(PeriodService::class)]
final class PeriodCaptureTest extends ServiceTestCase
{
    private PeriodService $periods;

    protected function setUp(): void
    {
        parent::setUp();
        $this->periods = new PeriodService($this->store, $this->meters, $this->i18n);
    }

    private function periodMeter(string $utility = 'waerme'): string
    {
        return (string)$this->meters->create($utility, ['name' => 'UVI', 'capture' => 'period', 'installed_on' => '2020-01-01'])['id'];
    }

    private function monthly(string $utility, string $meterId): array
    {
        $out = [];
        foreach ($this->consumption->forMeter($utility, $this->meters->get($utility, $meterId)) as $m) $out[$m['ym']] = $m;
        return $out;
    }

    public function testAMonthLandsInItsMonthAndAPeriodIsSplitByDay(): void
    {
        $m = $this->periodMeter();
        $this->periods->create('waerme', ['meter_id' => $m, 'month' => '2025-01', 'value' => 620]);
        // 15.02.–16.03.: 30 Tage zu je 10 kWh → 14 Tage Februar, 16 Tage März
        $this->periods->create('waerme', ['meter_id' => $m, 'from' => '2025-02-15', 'to' => '2025-03-16', 'value' => 300]);
        $mo = $this->monthly('waerme', $m);
        self::assertEqualsWithDelta(620.0, $mo['2025-01']['kwh'], 0.05);
        self::assertSame(31, $mo['2025-01']['days']);
        self::assertEqualsWithDelta(140.0, $mo['2025-02']['kwh'], 0.05);
        self::assertSame(14, $mo['2025-02']['days'], 'Lücke vom 1. bis 14. Februar bleibt eine Lücke');
        self::assertEqualsWithDelta(160.0, $mo['2025-03']['kwh'], 0.05);
    }

    public function testOverlapAndOrderAreRejected(): void
    {
        $m = $this->periodMeter();
        $this->periods->create('waerme', ['meter_id' => $m, 'month' => '2025-01', 'value' => 100]);
        self::assertSame('errors.period.overlap', $this->key(fn() => $this->periods->create('waerme', ['meter_id' => $m, 'from' => '2025-01-31', 'to' => '2025-02-05', 'value' => 1])));
        self::assertSame('errors.period.order', $this->key(fn() => $this->periods->create('waerme', ['meter_id' => $m, 'from' => '2025-03-05', 'to' => '2025-03-01', 'value' => 1])));
        self::assertSame('errors.period.valueInvalid', $this->key(fn() => $this->periods->create('waerme', ['meter_id' => $m, 'month' => '2025-04', 'value' => -1])));
        self::assertSame('errors.period.dateInvalid', $this->key(fn() => $this->periods->create('waerme', ['meter_id' => $m, 'from' => '2025-02-30', 'to' => '2025-03-01', 'value' => 1])));
        // anderer Zähler darf denselben Monat haben
        $m2 = $this->periodMeter();
        self::assertSame('2025-01-01', $this->periods->create('waerme', ['meter_id' => $m2, 'month' => '2025-01', 'value' => 5])['from']);
    }

    public function testGasInCubicMetresUsesTheDatedFactorsAndKwhStaysKwh(): void
    {
        $m = $this->periodMeter('gas');
        $this->periods->create('gas', ['meter_id' => $m, 'month' => '2025-01', 'value' => 100, 'value_unit' => 'meter']);
        $this->periods->create('gas', ['meter_id' => $m, 'month' => '2025-02', 'value' => 1000, 'value_unit' => 'consumption']);
        $mo = $this->monthly('gas', $m);
        $factor = (new \Energietracker\Services\ConversionFactorService($this->settings, $this->i18n))->factorOn('gas', '2025-01-15');
        self::assertEqualsWithDelta(100 * $factor, $mo['2025-01']['kwh'], 0.1);
        self::assertEqualsWithDelta(100.0, $mo['2025-01']['m3'], 0.1);
        self::assertEqualsWithDelta(1000.0, $mo['2025-02']['kwh'], 0.1, 'kWh bleiben kWh');
        self::assertEqualsWithDelta(1000 / $factor, $mo['2025-02']['m3'], 0.2, 'm³ zurückgerechnet');
    }

    public function testReadingsAndIngestAreRefusedOnAPeriodMeter(): void
    {
        $m = $this->periodMeter();
        try {
            $this->readings->create('waerme', ['meter_id' => $m, 'date' => '2025-01-01', 'counter' => 1]);
            self::fail('Ablesung angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.reading.periodMeter', $e->key);
        }
        $ingest = new IngestService($this->meters, $this->readings, $this->i18n, $this->store);
        try {
            $ingest->ingest(['utility' => 'waerme', 'meter' => $m, 'value' => 5]);
            self::fail('Ingest angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.ingest.periodMeter', $e->key);
        }
    }

    public function testCaptureIsLockedWhileDataExists(): void
    {
        $m = $this->periodMeter();
        $p = $this->periods->create('waerme', ['meter_id' => $m, 'month' => '2025-01', 'value' => 1]);
        self::assertSame('errors.meter.captureLocked', $this->key(fn() => $this->meters->update('waerme', $m, ['capture' => 'counter'])));
        $this->periods->delete('waerme', $p['id']);
        self::assertArrayNotHasKey('capture', $this->meters->update('waerme', $m, ['capture' => 'counter']));

        $gas = (string)$this->meters->defaultId('gas');
        $this->readings->create('gas', ['meter_id' => $gas, 'date' => '2025-01-01', 'counter' => 1]);
        self::assertSame('errors.meter.captureLocked', $this->key(fn() => $this->meters->update('gas', $gas, ['capture' => 'period'])));
        self::assertSame('errors.meter.captureInvalid', $this->key(fn() => $this->meters->create('heizoel', ['name' => 'T', 'capture' => 'period', 'capacity' => 1000, 'initial_stock' => 0])));
    }

    public function testCsvImportBothFormatsSkipsOverlapsAndReadsItsOwnExport(): void
    {
        $m = $this->periodMeter();
        $csv = "monat;wert\n01.2025;812,5\n2025-02;745\n15.03.2025;31.03.2025;300;Teil\n2025-02;1\nkaputt;3\n";
        $dry = $this->periods->importCsv('waerme', $m, $csv, true);
        self::assertTrue($dry['dry_run']);
        self::assertSame(3, $dry['would_import']);
        self::assertSame([], $this->periods->list('waerme', $m), 'Trockenlauf schreibt nichts');

        $r = $this->periods->importCsv('waerme', $m, $csv);
        self::assertSame(3, $r['imported']);
        self::assertSame(2, $r['skipped'], 'doppelter Februar und unlesbare Zeile');
        $list = $this->periods->list('waerme', $m);
        self::assertSame(['2025-01-01', '2025-02-01', '2025-03-15'], array_column($list, 'from'));
        self::assertSame(812.5, $list[0]['value']);
        self::assertSame('csv', $list[0]['source']);

        // eigener Export (Format 1) eines zweiten Zählers wieder einlesen: nur dessen Zeilen
        $other = $this->periodMeter();
        $export = (new \Energietracker\Services\CsvExportService($this->consumption, $this->readings, $this->meters,
            new \Energietracker\Services\TemperatureService($this->store, $this->settings, new \Energietracker\Services\WeatherService(), null, $this->i18n),
            new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n))
            ->periods('waerme', $this->periods->list('waerme'));
        self::assertStringStartsWith("\xEF\xBB\xBFVon;Bis;Wert;Notiz;Zaehler-ID", $export);
        self::assertSame(0, $this->periods->importCsv('waerme', $other, $export)['imported'], 'Zeilen anderer Zähler bleiben draußen');
    }

    public function testClientRefMakesASecondSendHarmless(): void
    {
        $m = $this->periodMeter();
        $a = $this->periods->create('waerme', ['meter_id' => $m, 'month' => '2025-01', 'value' => 1, 'client_ref' => 'abcdefgh-1']);
        $b = $this->periods->create('waerme', ['meter_id' => $m, 'month' => '2025-01', 'value' => 1, 'client_ref' => 'abcdefgh-1']);
        self::assertTrue($b['duplicate']);
        self::assertSame($a['id'], $b['id']);
    }

    public function testTheOverviewNamesTheLastPeriod(): void
    {
        $this->settings->set(['active_utilities' => ['gas', 'strom', 'wasser', 'waerme']]);
        $m = $this->periodMeter();
        $this->periods->create('waerme', ['meter_id' => $m, 'month' => '2025-01', 'value' => 1]);
        $this->periods->create('waerme', ['meter_id' => $m, 'month' => '2025-02', 'value' => 2]);
        $row = array_values(array_filter($this->readings->overview(['waerme']), fn($r) => $r['meter_id'] === $m))[0];
        self::assertSame('period', $row['capture']);
        self::assertSame('2025-02-28', $row['last_period']['to']);
        self::assertSame('consumption', $row['role']);
    }

    private function key(callable $fn): string
    {
        try {
            $fn();
        } catch (LocalizedException $e) {
            return $e->key;
        }
        self::fail('LocalizedException erwartet');
    }
}
