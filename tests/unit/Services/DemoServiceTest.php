<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;
use Energietracker\Storage\JsonStore;
use Energietracker\Services\DemoService;
use Energietracker\Services\BackupService;
use Energietracker\Services\I18nService;
use Energietracker\Services\SettingsService;

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
    private I18nService $i18n;

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $this->dir = $base . '/et_demo_' . uniqid();
        mkdir($this->dir, 0777, true);
        $this->store = new JsonStore($this->dir);
        $this->i18n  = new I18nService(dirname(__DIR__, 3) . '/public/locales', new SettingsService($this->store));
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
        return new DemoService($this->store, new BackupService($this->store, $this->i18n), $this->i18n);
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

    /**
     * v2.1.2 — Das Demo-Backup enthält Heizöl-/Pellets-Lieferungen, und der
     * Restore-Pfad muss sie wiederherstellen. Vorher fielen deliveries still
     * aus Export+Import → leere Demo-Tanks ohne Verbrauch/Kosten.
     */
    public function testImportRestoresDeliveriesForDeliveryUtilities(): void
    {
        $svc = $this->service();
        $svc->import();

        $oil = $this->store->read('heizoel/deliveries.json', []);
        $pellets = $this->store->read('pellets/deliveries.json', []);
        $this->assertCount(3, $oil, 'Heizöl-Lieferungen müssen aus dem Demo-Backup kommen');
        $this->assertCount(3, $pellets, 'Pellets-Lieferungen müssen aus dem Demo-Backup kommen');
    }
}
