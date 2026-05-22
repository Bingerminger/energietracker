<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\ReadingService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * N1002 — Case 10: Bulk-Import vor `installed_on`. Ergänzend zu Case 3
 * (doppelte Daten) auf der Schreibseite.
 *
 * Erwartetes Verhalten:
 *  - {@see ReadingService::create()} wirft `InvalidArgumentException`,
 *    wenn am Lesedatum kein Device aktiv ist (vor `installed_on`).
 *  - {@see ReadingImportService::importRows()} fängt die Exception ab,
 *    erhöht `skipped`, sammelt die Fehler in `errors` und importiert die
 *    übrigen Zeilen sauber.
 *  - Doppelte Daten im Import → die zweite Zeile aktualisiert die erste
 *    (`overwritten++`, kein zweiter Datensatz).
 */
#[CoversClass(ReadingService::class)]
#[CoversClass(ReadingImportService::class)]
final class ReadingEdgeCasesTest extends ServiceTestCase
{
    public function testCreatingReadingBeforeInstalledOnRejected(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/kein Gerät/');

        $this->readings->create('strom', [
            'meter_id' => $meterId,
            'date'     => '2023-12-15',   // ⚠ vor installed_on
            'counter'  => 100.0,
        ]);
    }

    public function testBulkImportSkipsRowsBeforeInstalledOnAndKeepsValidOnes(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $import = new ReadingImportService($this->readings, $this->meters);
        $result = $import->importRows('strom', $meterId, [
            ['date' => '2023-11-01', 'counter' => 0.0],     // ⚠ vor installed_on
            ['date' => '2024-02-01', 'counter' => 100.0],
            ['date' => '2024-03-01', 'counter' => 200.0],
        ]);

        self::assertSame(2, $result['imported'],   'gültige Zeilen werden importiert');
        self::assertSame(1, $result['skipped'],    'Zeile vor installed_on wird übersprungen');
        self::assertSame(0, $result['overwritten']);
        self::assertNotEmpty($result['errors'],    'Fehlermeldung muss vorhanden sein');
        self::assertStringContainsString('2023-11-01', $result['errors'][0],
            'Fehlermeldung muss das problematische Datum nennen');

        // Bestätigen, dass die problematische Zeile NICHT in den Daten landete.
        $stored = $this->readings->list('strom', $meterId);
        $dates  = array_column($stored, 'date');
        self::assertNotContains('2023-11-01', $dates,
            'Ablesung vor installed_on darf nicht persistiert sein');
        self::assertSame(['2024-02-01', '2024-03-01'], $dates);
    }

    /**
     * Case 3 — Schreibseite: zwei Import-Zeilen mit identischem Datum.
     * Die zweite überschreibt die erste (idempotenter Import), kein
     * zweiter Eintrag.
     */
    public function testDuplicateDatesInBulkImportOverwriteRatherThanDuplicate(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $import = new ReadingImportService($this->readings, $this->meters);
        $result = $import->importRows('strom', $meterId, [
            ['date' => '2024-02-01', 'counter' => 100.0],
            ['date' => '2024-02-01', 'counter' => 105.0],   // ⚠ identisches Datum
        ]);

        self::assertSame(1, $result['imported'],
            'erste Zeile zählt als Neu-Import');
        self::assertSame(1, $result['overwritten'],
            'zweite Zeile aktualisiert die erste (idempotenter Import)');

        $stored = $this->readings->list('strom', $meterId);
        self::assertCount(1, $stored, 'es darf nur eine Ablesung am 01.02. existieren');
        self::assertSame(105.0, (float)$stored[0]['counter'],
            'gespeicherter Zählerstand ist der zuletzt importierte Wert');
    }
}
