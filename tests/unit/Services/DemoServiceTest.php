<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;
use Energietracker\Storage\JsonStore;
use Energietracker\Services\DemoService;
use Energietracker\Services\BackupService;

/**
 * F1007 (v1.7.4) — Demo-Daten-Komfort-Import.
 *
 * Bewusst NICHT auf ServiceTestCase aufgebaut (das seedet bereits Zähler);
 * F1007 braucht einen echt leeren Store, daher ein eigener Temp-JsonStore.
 * Als Fixture dient das mitgelieferte Demo-Backup demo-data/…json.
 */
final class DemoServiceTest extends TestCase
{
    private string $dir;
    private JsonStore $store;

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $this->dir = $base . '/et_demo_' . uniqid();
        mkdir($this->dir, 0777, true);
        $this->store = new JsonStore($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    private function rmrf(string $p): void
    {
        if (!is_dir($p)) { @unlink($p); return; }
        foreach (scandir($p) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $this->rmrf("$p/$f");
        }
        @rmdir($p);
    }

    private function service(): DemoService
    {
        return new DemoService($this->store, new BackupService($this->store));
    }

    public function testFreshStoreIsEmptyAndBackupAvailable(): void
    {
        $svc = $this->service();
        $this->assertTrue($svc->isAvailable(), 'Demo-Backup-Datei sollte im Repo vorhanden sein');
        $this->assertTrue($svc->isEmpty(), 'frischer Store hat keine Zähler');
        $status = $svc->status();
        $this->assertTrue($status['available']);
        $this->assertTrue($status['is_empty']);
    }

    public function testImportIntoEmptyPopulatesMeters(): void
    {
        $svc = $this->service();
        $report = $svc->import(); // force nicht nötig, da leer
        $this->assertTrue($report['demo_import']);
        $this->assertArrayHasKey('utilities', $report);

        $this->assertFalse($svc->isEmpty());
        $strom = $this->store->read('strom/meters.json', []);
        $this->assertNotEmpty($strom, 'Strom sollte nach Demo-Import Zähler haben');
    }

    public function testImportRefusesWhenDataPresentWithoutForce(): void
    {
        $svc = $this->service();
        $svc->import(); // füllt den Store

        $this->expectException(\InvalidArgumentException::class);
        $svc->import(false); // ohne force bei vorhandenen Daten → Abbruch
    }

    public function testForcedImportOverwritesExistingData(): void
    {
        $svc = $this->service();
        $svc->import();
        $report = $svc->import(true); // mit force erneut — darf nicht werfen
        $this->assertTrue($report['demo_import']);
        $this->assertFalse($svc->isEmpty());
    }
}
