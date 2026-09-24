<?php
declare(strict_types=1);

namespace Energietracker\Tests\Storage;

use Energietracker\Services\BackupService;
use Energietracker\Services\I18nService;
use Energietracker\Services\SettingsService;
use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v2.5.3 — Sicherung vor jeder Schema-Migration (API-07/DOC-05 im Review
 * 2026-09-24). Die Update-Doku versprach sie seit Langem; angelegt wurde sie
 * nie. Der Test prüft, dass der Snapshot den Stand VOR der Migration enthält.
 */
#[CoversClass(Migrator::class)]
final class MigrationSnapshotTest extends TestCase
{
    private string $dir;
    private JsonStore $store;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/et-mig-' . bin2hex(random_bytes(5));
        mkdir($tmp, 0755, true);
        $this->dir = realpath($tmp) ?: $tmp;
        $this->store = new JsonStore($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    public function testSnapshotHoldsThePreMigrationState(): void
    {
        // Bestand auf Schema 1.4.0 mit dem alten Skalar — braucht die 1.5.0-Stufe
        (new Migrator($this->store))->initFresh();
        $this->store->write('meta.json', ['schema_version' => '1.4.0', 'migrated_at' => '2026-08-20T00:00:00+02:00', 'log' => []]);
        $settings = $this->store->read('settings.json', []);
        unset($settings['gas_conversion_factors']);
        $settings['gas_conversion_factor'] = 10.9;
        $this->store->write('settings.json', $settings);

        $i18n = new I18nService(dirname(__DIR__, 3) . '/public/locales', new SettingsService($this->store));
        $backups = new BackupService($this->store, $i18n);
        $result = (new Migrator($this->store, $i18n))->runOnStartup(
            fn(string $from): string => $backups->saveSnapshot('pre-migration-' . $from . '_')
        );

        self::assertSame('migrated', $result['action']);
        self::assertSame('1.4.0', $result['from']);
        self::assertNull($result['snapshot_error']);
        $file = $this->dir . '/backups/' . $result['snapshot'];
        self::assertFileExists($file);

        $snap = json_decode((string)file_get_contents($file), true);
        self::assertSame('1.4.0', $snap['meta']['schema_version'], 'Snapshot zeigt den alten Stand');
        self::assertSame(10.9, $snap['settings']['gas_conversion_factor']);
        self::assertSame(Migrator::SCHEMA_VERSION, $this->store->read('meta.json', [])['schema_version'], 'danach migriert');
    }

    public function testFailingSnapshotDoesNotBlockTheMigration(): void
    {
        (new Migrator($this->store))->initFresh();
        $this->store->write('meta.json', ['schema_version' => '1.4.0', 'log' => []]);
        $result = (new Migrator($this->store))->runOnStartup(fn(string $from): string => throw new \RuntimeException('Platte voll'));

        self::assertSame('migrated', $result['action']);
        self::assertSame('Platte voll', $result['snapshot_error']);
        self::assertSame(Migrator::SCHEMA_VERSION, $this->store->read('meta.json', [])['schema_version']);
    }

    public function testFreshDirectoryIsInitialisedWithoutSnapshot(): void
    {
        $called = false;
        $result = (new Migrator($this->store))->runOnStartup(function () use (&$called): string { $called = true; return 'x'; });
        self::assertSame('fresh', $result['action']);
        self::assertFalse($called, 'ein leeres Verzeichnis braucht keine Sicherung');
        self::assertNull((new Migrator($this->store))->runOnStartup(), 'danach nichts mehr zu tun');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$dir/$f";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
