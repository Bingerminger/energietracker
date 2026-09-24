<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.6.0 — Sicherungen, auf die man sich verlassen kann (Review 2026-09-24).
 */
#[CoversClass(BackupService::class)]
final class BackupSafetyTest extends ServiceTestCase
{
    private BackupService $backups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backups = new BackupService($this->store, $this->i18n);
    }

    public function testABrokenPotIsRejectedBeforeAnythingIsWritten(): void
    {
        $before = $this->store->read('gas/meters.json', []);
        $backup = $this->backups->export();
        $backup['settings'] = ['language' => 'en'];
        $backup['utilities']['strom']['meters'] = [1, 2];           // Review-Beispiel
        try {
            $this->backups->import($backup);
            self::fail('ungültiges Backup wurde angenommen');
        } catch (BackupInvalidException $e) {
            self::assertSame('strom/meters', $e->problems[0]['pot']);
        }
        self::assertSame($before, $this->store->read('gas/meters.json', []));
        self::assertNotSame('en', $this->store->read('settings.json', [])['language'] ?? null,
            'auch Töpfe VOR dem kaputten dürfen nicht geschrieben sein');
    }

    public function testADryRunReportsButDoesNotWrite(): void
    {
        $backup = $this->backups->export();
        $backup['settings'] = ['language' => 'fr'];
        $report = $this->backups->import($backup, dryRun: true);
        self::assertTrue($report['dry_run']);
        self::assertSame([], $report['problems']);
        self::assertNotSame('fr', $this->store->read('settings.json', [])['language'] ?? null);
        self::assertSame([], glob($this->dataDir . '/backups/*.json') ?: [], 'kein Snapshot beim Trockenlauf');
    }

    public function testTheExportEnvelopeIsUnwrapped(): void
    {
        $envelope = ['success' => true, 'data' => $this->backups->export()];
        $report = $this->backups->import($envelope);
        self::assertArrayHasKey('utilities', $report);
    }

    public function testDismissedRecommendationsSurviveARoundtrip(): void
    {
        $this->store->write('recommendations_dismissed.json', ['trend_gas' => '2026-09-01']);
        $backup = $this->backups->export();
        $this->store->write('recommendations_dismissed.json', []);
        $this->backups->import($backup);
        self::assertSame(['trend_gas' => '2026-09-01'], $this->store->read('recommendations_dismissed.json', []));
    }

    public function testAMissingPotIsLeftUntouchedAndReported(): void
    {
        $backup = $this->backups->export();
        unset($backup['reminders']);
        $this->store->write('reminders.json', [['id' => 'keep']]);
        $report = $this->backups->import($backup);
        self::assertContains('reminders', $report['untouched']);
        self::assertSame([['id' => 'keep']], $this->store->read('reminders.json', []));
    }

    public function testSnapshotsCanBeListedRestoredAndDeleted(): void
    {
        $this->store->write('settings.json', ['language' => 'nl']);
        $name = $this->backups->saveSnapshot();
        $this->store->write('settings.json', ['language' => 'pt']);

        $list = $this->backups->listSnapshots();
        self::assertSame($name, $list[0]['name']);
        self::assertSame('manual', $list[0]['reason']);

        $snap = json_decode((string)file_get_contents($this->backups->snapshotPath($name)), true);
        self::assertSame('3.0', $snap['backup_version'], 'gestreamter Snapshot ist gültiges JSON im Format 3.0');
        self::assertSame(array_keys($this->backups->export()), array_keys($snap), 'gleiche Töpfe wie der Export');

        $this->backups->restoreSnapshot($name);
        self::assertSame('nl', $this->store->read('settings.json', [])['language']);
        self::assertSame('restore', $this->backups->listSnapshots()[0]['reason'], 'Restore legt selbst einen Snapshot an');

        $this->backups->deleteSnapshot($name);
        self::assertNotContains($name, array_column($this->backups->listSnapshots(), 'name'));
    }

    public function testTwoSnapshotsInTheSameSecondDoNotOverwriteEachOther(): void
    {
        $a = $this->backups->saveSnapshot();
        $b = $this->backups->saveSnapshot();
        self::assertNotSame($a, $b);
    }

    public function testASnapshotNameCannotEscapeTheBackupDirectory(): void
    {
        $this->expectException(\Energietracker\Http\NotFoundException::class);
        $this->backups->snapshotPath('../settings.json');
    }

    public function testManualSnapshotsAreRotated(): void
    {
        for ($i = 0; $i < 12; $i++) $this->backups->saveSnapshot();
        self::assertCount(10, $this->backups->listSnapshots());
    }

    /**
     * Lektion 18: Jeder Datentopf, den ein Dienst schreibt, gehört ins Backup.
     * Dieser Test sammelt alle `store->write("…")`-Ziele und gleicht sie mit
     * den Topf-Listen des BackupService ab.
     */
    public function testEveryFileAServiceWritesIsCoveredByTheBackup(): void
    {
        $covered = array_values(BackupService::TOP_POTS);
        foreach (BackupService::UTILITY_POTS as $pot) $covered[] = "*/$pot.json";
        $covered = array_merge($covered, ['meta.json', 'auth.json']);   // auth bewusst nicht im Backup

        $missing = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/{Services,Storage,Controllers}/*.php', GLOB_BRACE) as $file) {
            preg_match_all('/store->write\(\s*["\']([^"\']+)["\']/', (string)file_get_contents($file), $m);
            foreach ($m[1] as $target) {
                $target = preg_replace('#^(\$\w+|[a-z_]+)/#', '*/', $target);   // "$utility/readings.json", "wasser/meters.json" → "*/…"
                if (!in_array($target, $covered, true) && !str_contains($target, '$')) $missing[] = basename($file) . ': ' . $target;
            }
        }
        self::assertSame([], array_values(array_unique($missing)));
    }
}
