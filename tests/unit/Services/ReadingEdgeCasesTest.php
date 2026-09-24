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
 *    wenn am Lesedatum kein Device aktiv ist (Lücke in der Gerätekette).
 *    Seit v2.5.3 verlegt eine Ablesung vor dem ERSTEN Gerät dessen Einbau
 *    vor, statt abgelehnt zu werden.
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
    /**
     * v2.5.3 — Eine Ablesung vor dem Einbau des ERSTEN Geräts verlegt dessen
     * Einbau vor. Bis v2.5.2 wurde sie abgelehnt; weil die Standardzähler beim
     * Erststart „heute" eingebaut sind, kam dadurch niemand mit seiner Historie
     * in eine Neuinstallation (Dialog und CSV-Import scheiterten zeilenweise).
     */
    public function testReadingBeforeFirstDeviceBackdatesItsInstallation(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $r = $this->readings->create('strom', [
            'meter_id' => $meterId,
            'date'     => '2023-12-15',
            'counter'  => 100.0,
        ]);

        self::assertSame('d_strom_1', $r['device_id']);
        $meter = $this->meters->get('strom', $meterId);
        self::assertSame('2023-12-15', $meter['devices'][0]['installed_on'],
            'Einbau des ersten Geräts ist auf die ältere Ablesung vorverlegt');
    }

    /**
     * Der Schutz aus N1002 bleibt für spätere Geräte: Liegt ein Datum in einer
     * Lücke der Gerätekette (altes Gerät ausgebaut, neues noch nicht eingebaut),
     * gibt es kein Gerät — und die Ablesung wird abgelehnt.
     */
    public function testReadingInAGapOfTheDeviceChainIsStillRejected(): void
    {
        $meterId = $this->setMeterDevices('strom', [
            ['id' => 'd_alt', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
             'removed_on' => '2024-06-15', 'final_counter' => 1500.0, 'reason' => 'Tausch'],
            ['id' => 'd_neu', 'serial' => null, 'installed_on' => '2024-07-01', 'initial_counter' => 0.0,
             'removed_on' => null, 'final_counter' => null, 'reason' => null],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/kein Gerät/');

        $this->readings->create('strom', [
            'meter_id' => $meterId,
            'date'     => '2024-06-20',   // ⚠ nach Ausbau alt, vor Einbau neu
            'counter'  => 10.0,
        ]);
    }

    public function testBulkImportSkipsInvalidRowsAndKeepsValidOnes(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $import = new ReadingImportService($this->readings, $this->meters, $this->i18n);
        $result = $import->importRows('strom', $meterId, [
            ['date' => '2023-11-01', 'counter' => 0.0],     // vor dem Einbau: verlegt ihn vor
            ['date' => '2024-02-30', 'counter' => 50.0],    // ⚠ kein Kalenderdatum
            ['date' => '2024-02-01', 'counter' => 100.0],
            ['date' => '2024-03-01', 'counter' => 200.0],
        ]);

        self::assertSame(3, $result['imported'],   'gültige Zeilen werden importiert');
        self::assertSame(1, $result['skipped'],    'die Zeile mit dem 30.02. wird übersprungen');
        self::assertSame(0, $result['overwritten']);
        self::assertNotEmpty($result['errors'],    'Fehlermeldung muss vorhanden sein');
        self::assertStringContainsString('2024-02-30', implode(' ', $result['errors']),
            'Fehlermeldung muss das problematische Datum nennen');

        $dates = array_column($this->readings->list('strom', $meterId), 'date');
        self::assertSame(['2023-11-01', '2024-02-01', '2024-03-01'], $dates);
        self::assertSame('2023-11-01', $this->meters->get('strom', $meterId)['devices'][0]['installed_on']);
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

        $import = new ReadingImportService($this->readings, $this->meters, $this->i18n);
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
