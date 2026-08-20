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

    /**
     * v2.2.2 — Dieselbe Klasse wie die Lieferungen ein Release zuvor: Das
     * Demo-Backup trägt eine feste Feldliste, und `reminders` fehlte darin.
     * Wer die Demo-Daten über den Knopf in den Einstellungen lud, bekam eine
     * leere Termin-Ansicht, obwohl die Anwendung das Modul mitbringt.
     */
    public function testImportRestoresReminders(): void
    {
        $svc = $this->service();
        $svc->import();

        $reminders = $this->store->read('reminders.json', []);
        $this->assertNotEmpty($reminders,
            'Das Demo-Backup muss Termine mitbringen — sonst ist die Ansicht nach dem Demo-Import leer');

        foreach ($reminders as $r) {
            foreach (['id', 'title', 'category', 'next_due', 'recurrence'] as $field) {
                $this->assertArrayHasKey($field, $r, "Terminfeld $field fehlt");
            }
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string)$r['next_due'],
                'next_due muss ein ISO-Datum sein');
            $this->assertContains($r['recurrence'],
                ['none', 'yearly', 'semi-yearly', 'custom-months'],
                'Unbekannte Wiederholung: ' . $r['recurrence']);
        }
    }

    /**
     * Die Demo soll die Bandbreite zeigen, nicht nur einen Fall: mehrere
     * Kategorien und mindestens ein Termin, der bereits fällig ist.
     */
    public function testDemoRemindersCoverSeveralCategoriesAndStates(): void
    {
        $svc = $this->service();
        $svc->import();
        $reminders = $this->store->read('reminders.json', []);

        $categories = array_unique(array_column($reminders, 'category'));
        $this->assertGreaterThanOrEqual(3, count($categories),
            'Die Demo sollte mehrere Terminarten zeigen');

        $due = array_filter($reminders, fn($r) => (string)$r['next_due'] <= date('Y-m-d'));
        $this->assertNotEmpty($due,
            'Mindestens ein Demo-Termin sollte fällig sein, damit die Statusfarben sichtbar werden');
    }

    /**
     * Datei-Pfad und Backup-Pfad müssen dieselben Termine liefern. Beide Wege
     * existieren (Verzeichnis kopieren bzw. „Demo laden") und liefen bisher
     * auseinander.
     */
    public function testDemoDirectoryAndBackupCarryTheSameReminders(): void
    {
        $root = dirname(__DIR__, 3);
        $fromFile = json_decode(
            (string)file_get_contents("$root/demo-data/reminders.json"), true);
        $backup = json_decode(
            (string)file_get_contents("$root/demo-data/energietracker-demo-backup.json"), true);

        $this->assertIsArray($fromFile);
        $this->assertArrayHasKey('reminders', $backup,
            'Das Demo-Backup braucht ein reminders-Feld');
        $this->assertSame(
            array_column($fromFile, 'id'),
            array_column($backup['reminders'], 'id'),
            'demo-data/reminders.json und das Demo-Backup müssen dieselben Termine führen'
        );
    }

    /**
     * v2.4.0 — F1011: Die Demo führt eine Analyse-Zäsur vor, damit der
     * Vorher/Nachher-Vergleich nach „Demo laden" tatsächlich zu sehen ist.
     *
     * Dieselbe Doppelpflege wie bei den Terminen: Verzeichnisform UND Backup.
     * In v2.1.2 und v2.2.2 landete ein neuer Datentopf jeweils nur an einer
     * der beiden Stellen — deshalb prüft der Test beide.
     */
    public function testDemoDataDemonstratesTheBaselineCutoff(): void
    {
        $root = dirname(__DIR__, 3);
        $fromFile = json_decode(
            (string)file_get_contents("$root/demo-data/gas/meters.json"), true);
        $backup = json_decode(
            (string)file_get_contents("$root/demo-data/energietracker-demo-backup.json"), true);

        $pick = static function (array $meters): ?array {
            foreach ($meters as $m) {
                if (($m['id'] ?? null) === 'm_gas_main') return $m;
            }
            return null;
        };

        $dir = $pick($fromFile ?? []);
        $bak = $pick($backup['utilities']['gas']['meters'] ?? $backup['gas']['meters'] ?? []);

        $this->assertNotNull($dir, 'Demo-Verzeichnis führt den Gas-Hauptzähler');
        $this->assertNotNull($bak, 'Demo-Backup führt den Gas-Hauptzähler');
        $this->assertNotEmpty(
            $dir['baseline_events'] ?? [],
            'demo-data/gas/meters.json soll eine Zäsur vorführen'
        );
        $this->assertSame(
            $dir['baseline_events'],
            $bak['baseline_events'] ?? [],
            'Verzeichnisform und Backup müssen dieselbe Zäsur führen'
        );
    }
}
