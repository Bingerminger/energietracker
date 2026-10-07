<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\IngestService;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.1.0 (Paket H1, API-34) — mehrere Stände in einer Anfrage.
 *
 * Nach einem Ausfall von Home Assistant oder aus Node-RED kommen Tageswerte
 * gesammelt. Jeder Eintrag läuft wie ein Einzel-Ingest; ein fehlerhafter hält
 * die anderen nicht auf; jede Datei wird einmal geschrieben.
 */
final class BulkIngestTest extends ServiceTestCase
{
    private IngestService $ingest;
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingest = new IngestService($this->meters, $this->readings, $this->i18n, $this->store);
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['external_id'] = 'strom_haus';
        $this->store->write('strom/meters.json', $all);
    }

    /** @return list<array<string,mixed>> */
    private function days(int $n, string $from = '2025-01-01', float $start = 1000.0): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['utility' => 'strom', 'meter' => 'strom_haus', 'value' => $start + $i * 8.5,
                      'date' => date('Y-m-d', (int)strtotime("$from +$i days"))];
        }
        return $out;
    }

    public function testAYearOfDailyValuesInOneWritePerFile(): void
    {
        $before = $this->store->diskWrites();
        $t = microtime(true);
        $r = $this->ingest->ingestMany($this->days(365));
        $elapsed = microtime(true) - $t;
        self::assertSame([365, 0, 0], [$r['created'], $r['updated'], $r['failed']]);
        self::assertCount(365, $this->readings->list('strom', $this->meterId));
        self::assertSame(1, $this->store->diskWrites() - $before, 'readings.json genau einmal geschrieben');
        self::assertLessThan(2.0, $elapsed, sprintf('365 Einträge in %.2f s', $elapsed));

        $again = $this->ingest->ingestMany($this->days(365));
        self::assertSame([0, 365, 0], [$again['created'], $again['updated'], $again['failed']], 'derselbe Stapel nochmals: nur Aktualisierungen');
    }

    public function testPartialFailuresKeepTheRestAndTheirIndex(): void
    {
        $items = $this->days(3);
        $items[1] = ['utility' => 'strom', 'meter' => 'gibt_es_nicht', 'value' => 1];
        $items[] = 'kein Objekt';
        $items[] = ['utility' => 'strom', 'meter' => 'strom_haus', 'value' => 'abc', 'date' => '2025-02-01'];
        $r = $this->ingest->ingestMany($items);
        self::assertSame([2, 0, 3], [$r['created'], $r['updated'], $r['failed']]);
        self::assertSame([0, 1, 2, 3, 4], array_column($r['results'], 'index'), 'Antwort in Eingabereihenfolge');
        self::assertSame('errors.ingest.meterNotFound', $r['results'][1]['code']);
        self::assertSame('errors.ingest.bodyInvalid', $r['results'][3]['code']);
        self::assertSame('errors.ingest.valueMissing', $r['results'][4]['code']);
        self::assertSame('created', $r['results'][2]['status']);
        self::assertSame($this->meterId, $r['results'][2]['meter_id']);
    }

    public function testAFallingValueInsideTheBatchIsSuspect(): void
    {
        // Eingabe absichtlich unsortiert: Verarbeitet wird nach Datum
        $r = $this->ingest->ingestMany([
            ['utility' => 'strom', 'meter' => 'strom_haus', 'value' => 900, 'date' => '2025-03-03'],
            ['utility' => 'strom', 'meter' => 'strom_haus', 'value' => 1000, 'date' => '2025-03-01'],
            ['utility' => 'strom', 'meter' => 'strom_haus', 'value' => 1010, 'date' => '2025-03-02'],
        ]);
        self::assertSame([true, false, false], array_column($r['results'], 'suspect'));
    }

    public function testLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ingest->ingestMany($this->days(IngestService::BULK_MAX + 1));
    }

    public function testSingleObjectIsUnchanged(): void
    {
        $r = $this->ingest->ingest(['utility' => 'strom', 'meter' => 'strom_haus', 'value' => 5, 'date' => '2025-01-05']);
        self::assertSame(['status', 'utility', 'meter_id', 'date', 'counter', 'reading_id', 'suspect'], array_keys($r),
            'Einzelpfad: Antwortform wie v3.0.0');
    }
}
