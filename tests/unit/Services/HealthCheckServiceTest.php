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
        self::assertSame('1.3.0', $health['schema_version']);
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
}
