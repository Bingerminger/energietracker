<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\BackupService;
use Energietracker\Storage\Migrator;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * N1004 — Restore-Sicherungen, die in v1.7.1 ergänzt wurden:
 *  - Schema-Version aus dem Backup darf nicht NEUER sein als App-Schema.
 *  - Vor jedem Restore wird automatisch ein Snapshot der aktuellen Daten
 *    unter `data/backups/pre-restore-<ts>.json` abgelegt.
 */
#[CoversClass(BackupService::class)]
final class BackupServiceRestoreGuardTest extends ServiceTestCase
{
    public function testRestoreRejectsBackupWithNewerSchema(): void
    {
        $svc = new BackupService($this->store);
        $payload = $svc->export();
        // Backup-Schema künstlich hochsetzen — simuliert ein Backup aus
        // einer noch nicht ausgerollten App-Version.
        $payload['meta']['schema_version'] = '99.0.0';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Schema 99\.0\.0 ist neuer/');
        $svc->import($payload);
    }

    public function testRestoreAcceptsBackupWithSameSchema(): void
    {
        $svc = new BackupService($this->store);
        $payload = $svc->export();
        // Schema-Version ist die aktuelle der App.
        self::assertSame(Migrator::SCHEMA_VERSION, $payload['meta']['schema_version']);

        $report = $svc->import($payload);
        self::assertArrayHasKey('utilities', $report);
        self::assertArrayHasKey('auto_snapshot_before_restore', $report,
            'Jeder Restore muss einen Auto-Snapshot-Hinweis im Report tragen');
    }

    public function testRestoreCreatesAutoSnapshotInBackupsDir(): void
    {
        // Erst etwas Inhalt anlegen, damit der Pre-Restore-Snapshot
        // erkennbar ist.
        $this->meters->create('strom', [
            'name' => 'Vor-Restore-Zähler',
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);

        $svc = new BackupService($this->store);
        $payload = $svc->export();
        $svc->import($payload);

        $backupsDir = $this->dataDir . '/backups';
        self::assertTrue(is_dir($backupsDir), 'backups/-Verzeichnis muss existieren');
        $snapshots = array_values(array_filter(
            scandir($backupsDir) ?: [],
            fn($f) => str_starts_with($f, 'pre-restore-') && str_ends_with($f, '.json')
        ));
        self::assertNotEmpty($snapshots,
            'Vor jedem Restore muss ein pre-restore-Snapshot abgelegt werden');
    }
}
