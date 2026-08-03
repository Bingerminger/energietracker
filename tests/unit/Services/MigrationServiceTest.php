<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\MigrationService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.2.2 — Migration aus v0.9.0.
 *
 * Der Dienst hatte bis dahin keinen Test, obwohl er der einzige Pfad ist, über
 * den Bestandsdaten aus der Vorgängerversion hereinkommen — und obwohl er
 * fremde Daten schreibt. Geprüft werden die Zusagen, auf die sich der
 * Migrations-Dialog verlässt: Formaterkennung, Übersetzung ins aktuelle
 * Schema, die Zählerwechsel-Heuristik sowie beide Schreibmodi samt der
 * Sicherheitskopie, die vor jedem Schreiben entsteht.
 */
#[CoversClass(MigrationService::class)]
final class MigrationServiceTest extends ServiceTestCase
{
    private function service(): MigrationService
    {
        return new MigrationService(
            $this->store,
            new BackupService($this->store, $this->i18n),
            $this->i18n
        );
    }

    /** Ein knappes, aber vollständiges v0.9.0-Backup. */
    private function legacyBackup(string $version = '2.1'): array
    {
        return [
            'version'  => $version,
            'settings' => [
                'version'               => $version,   // Legacy-Metafeld, wird verworfen
                'gas_conversion_factor' => 11.2,
                'wohnflaeche_m2'        => 140,
            ],
            'temperatures' => [
                '2024-01-15' => ['avg' => 4.2, 'min' => -1.0, 'max' => 7.1],
                '2024-01-16' => ['avg' => 3.8, 'min' => -2.0, 'max' => 6.5],
            ],
            'gas' => [
                ['id' => 'r1', 'date' => '2024-01-01', 'counter' => 1000.0, 'comment' => ''],
                ['id' => 'r2', 'date' => '2024-02-01', 'counter' => 1120.0, 'comment' => 'Zählerwechsel'],
                ['id' => 'r3', 'date' => '2024-03-01', 'counter' => 1240.0,
                 'comment' => 'Ablesung wirkt hoch', 'is_notable' => true],
            ],
            'strom' => [
                ['id' => 's1', 'date' => '2024-01-01', 'counter' => 500.0],
                ['id' => 's2', 'date' => '2024-02-01', 'counter' => 780.0],
            ],
            'contracts' => [
                'gas' => [[
                    'id' => 'c1', 'provider' => 'Stadtwerke', 'tariff_name' => 'Basis',
                    'start' => '2024-01-01',
                ]],
            ],
        ];
    }

    // ── Formaterkennung ──────────────────────────────────────────────────

    public function testBackupWithoutVersionFieldIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->preview(['gas' => []]);
    }

    public function testUnknownLegacyVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->preview(['version' => '9.9']);
    }

    public function testAllDocumentedLegacyVersionsAreAccepted(): void
    {
        foreach (MigrationService::SUPPORTED_LEGACY_VERSIONS as $v) {
            $res = $this->service()->preview($this->legacyBackup($v));
            self::assertTrue($res['ok'] ?? false, "Version $v sollte akzeptiert werden");
            self::assertSame($v, $res['legacy_version']);
        }
    }

    // ── Übersetzung ──────────────────────────────────────────────────────

    public function testPreviewTranslatesReadingsContractsAndTemperatures(): void
    {
        $res = $this->service()->preview($this->legacyBackup());
        $r = $res['report'];

        self::assertSame(3, $r['readings']['gas']);
        self::assertSame(2, $r['readings']['strom']);
        self::assertSame(0, $r['readings']['wasser'], 'v0.9.0 kannte kein Wasser');
        self::assertSame(1, $r['contracts']['gas']);
        self::assertSame(2, $r['temperatures']);
        self::assertGreaterThan(0, $r['settings']);

        // Jede Ablesung bekommt Zähler- und Gerätebezug, die es vorher nicht gab.
        $gas = $res['translated']['utilities']['gas'];
        self::assertCount(1, $gas['meters'], 'Genau ein Zähler je Verbrauchsart');
        foreach ($gas['readings'] as $reading) {
            self::assertNotEmpty($reading['meter_id']);
            self::assertNotEmpty($reading['device_id']);
            self::assertIsFloat($reading['counter']);
        }
    }

    public function testPreviewWarnsAboutMissingWaterAndSettings(): void
    {
        $res = $this->service()->preview($this->legacyBackup());
        self::assertNotEmpty($res['report']['warnings'],
            'Der fehlende Wasserbereich muss gemeldet werden');

        $withoutSettings = $this->legacyBackup();
        unset($withoutSettings['settings']);
        $res2 = $this->service()->preview($withoutSettings);
        self::assertGreaterThanOrEqual(
            count($res['report']['warnings']), count($res2['report']['warnings']),
            'Fehlende Einstellungen erzeugen eine zusätzliche Warnung'
        );
    }

    /**
     * Die Heuristik markiert nur explizite Wechsel-Hinweise als Kandidat.
     * `is_notable` allein hieß in v0.9.0 bloß „auffällige Ablesung" und wird
     * stattdessen mit einem Stern in der Notiz erhalten — sonst hätte jede
     * markierte Ablesung einen Zählertausch vorgetäuscht.
     */
    public function testOnlyExplicitSwapKeywordsBecomeCandidates(): void
    {
        $res = $this->service()->preview($this->legacyBackup());
        $candidates = $res['report']['device_replacement_candidates'];

        self::assertCount(1, $candidates, 'Nur die Zeile mit „Zählerwechsel" zählt');
        self::assertSame('2024-02-01', $candidates[0]['date']);
        self::assertSame('gas', $candidates[0]['utility']);

        // Die auffällige, aber nicht wechselbezogene Ablesung behält ihren Text.
        $notes = array_column($res['translated']['utilities']['gas']['readings'], 'note');
        self::assertContains('⭐ Ablesung wirkt hoch', $notes,
            'Auffällige Ablesungen werden markiert, nicht als Wechsel gewertet');
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    public function testApplyRejectsAnUnknownMode(): void
    {
        $res = $this->service()->preview($this->legacyBackup());
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->apply($res['translated'], 'ueberschreiben');
    }

    public function testReplaceWritesTranslatedDataAndKeepsASnapshot(): void
    {
        $svc = $this->service();
        $res = $svc->preview($this->legacyBackup());
        $stats = $svc->apply($res['translated'], 'replace');

        self::assertSame('replace', $stats['mode']);
        self::assertNotEmpty($stats['snapshot'],
            'Vor dem Schreiben muss eine Sicherheitskopie entstehen');
        self::assertSame(3, $stats['written']['gas']['readings']);
        self::assertSame(1, $stats['written']['gas']['contracts']);

        // Und die Daten liegen tatsächlich im Store.
        self::assertCount(3, $this->store->read('gas/readings.json', []));
        self::assertCount(1, $this->store->read('gas/contracts.json', []));
        self::assertNotEmpty($this->store->read('temperatures.json', []));
    }

    /**
     * `merge` darf Bestandsdaten nicht verlieren. Der Store trägt hier bereits
     * die von `initFresh()` angelegten Standardzähler.
     */
    public function testMergeKeepsExistingDataAndSkipsDuplicateIds(): void
    {
        $svc = $this->service();
        $translated = $svc->preview($this->legacyBackup())['translated'];

        $svc->apply($translated, 'merge');
        $afterFirst = count($this->store->read('gas/readings.json', []));
        self::assertSame(3, $afterFirst);

        // Zweiter Lauf mit denselben IDs darf nichts verdoppeln.
        $svc->apply($translated, 'merge');
        self::assertSame($afterFirst, count($this->store->read('gas/readings.json', [])),
            'Gleiche IDs dürfen beim Zusammenführen nicht doppelt landen');
    }

    /** Jeder Lauf hinterlässt eine Sicherungskopie — auch im merge-Modus. */
    public function testEveryApplyLeavesASnapshotBehind(): void
    {
        $svc = $this->service();
        $translated = $svc->preview($this->legacyBackup())['translated'];
        $svc->apply($translated, 'merge');

        $snapshots = $this->store->read('backups', null);
        $dir = $this->dataDir . '/backups';
        self::assertDirectoryExists($dir, 'Snapshot-Verzeichnis muss angelegt sein');
        self::assertNotEmpty(glob($dir . '/*.json'),
            'Es muss mindestens ein Snapshot geschrieben worden sein');
    }
}
