<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\HealthCheckService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * N1003 — Health-Check liefert die Self-Diagnose-Felder, die der
 * Operator vor dem Aufmachen der Logs sehen will.
 */
#[CoversClass(HealthCheckService::class)]
final class HealthCheckServiceTest extends ServiceTestCase
{
    public function testHealthShapeIsStableAndDataDirIsWritable(): void
    {
        $health = (new HealthCheckService($this->store))->run();

        foreach (['version', 'schema_version', 'data_dir_writable',
                  'migrations_pending', 'data_initialized_at',
                  'php_version', 'timezone'] as $field) {
            self::assertArrayHasKey($field, $health,
                "Health-Antwort muss Feld '$field' tragen");
        }
        self::assertTrue($health['data_dir_writable'],
            'Test-data-dir muss schreibbar sein');
        self::assertSame(0, $health['migrations_pending'],
            'Frisch initialisiertes Verzeichnis darf keine ausstehende Migration melden');
        self::assertSame('1.5.0', $health['schema_version']);
        self::assertNotNull($health['data_initialized_at'],
            'Migrator hat created_at oder migrated_at in meta.json geschrieben');
    }

    public function testVersionMatchesVersionFile(): void
    {
        $health = (new HealthCheckService($this->store))->run();
        $expected = trim((string)file_get_contents(__DIR__ . '/../../../VERSION'));
        self::assertSame($expected, $health['version'],
            'health.version muss exakt der VERSION-Datei entsprechen');
    }

    /** v2.6.0 — Status und Einzelprüfungen; bei error antwortet der Controller 503. */
    public function testAHealthyInstallIsOk(): void
    {
        $health = (new HealthCheckService($this->store))->run();
        self::assertSame('ok', $health['status']);
        self::assertSame('ok', $health['checks']['files']['level']);
    }

    public function testACorruptDataFileMakesTheStatusError(): void
    {
        file_put_contents($this->dataDir . '/gas/readings.json', '{kaputt');
        $health = (new HealthCheckService($this->store))->run();
        self::assertSame('error', $health['status']);
        self::assertSame(['gas/readings.json'], $health['checks']['files']['corrupt']);
    }

    public function testDataFromANewerVersionIsAnErrorAndStaysUntouched(): void
    {
        $meta = $this->store->read('meta.json', []);
        $meta['schema_version'] = '9.0.0';
        $this->store->write('meta.json', $meta);
        $before = (string)file_get_contents($this->dataDir . '/meta.json');

        $health = (new HealthCheckService($this->store))->run();
        self::assertSame('error', $health['status']);
        self::assertSame('9.0.0', $health['checks']['schema']['data_schema']);

        $result = (new \Energietracker\Storage\Migrator($this->store))->runOnStartup();
        self::assertSame('too-new', $result['action']);
        self::assertSame($before, (string)file_get_contents($this->dataDir . '/meta.json'),
            'bis v2.5.3 stempelte initFresh() die Daten still auf das alte Schema');
    }

    public function testStaleTempFilesAreRemoved(): void
    {
        $tmp = $this->dataDir . '/gas/readings.json.tmp.deadbeef';
        file_put_contents($tmp, '[]');
        touch($tmp, time() - 7200);
        $health = (new HealthCheckService($this->store))->run();
        self::assertSame(1, $health['checks']['temp_files']['removed']);
        self::assertFileDoesNotExist($tmp);
    }
}
