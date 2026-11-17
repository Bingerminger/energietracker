<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (K2d) — Mehrere eigene Sicherungen in derselben Sekunde bekommen eindeutige Namen, und die Rotation löscht
 * nie die eben gemeldete. Bisher suchte die Vergabe den ersten freien Namen ab dem Grundnamen: Nachdem die Rotation
 * (zehn eigene) den Grundnamen gelöscht hatte, bekam die zwölfte Sicherung derselben Sekunde ihn wieder, galt damit
 * als älteste, wurde sofort gelöscht, und die Antwort nannte eine Datei, die es nicht gab.
 */
#[CoversClass(BackupService::class)]
final class SnapshotSameSecondTest extends ServiceTestCase
{
    private function touchAll(string $dir, array $names): void
    {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        foreach ($names as $n) file_put_contents("$dir/$n", '{}');
    }

    public function testTheNextNameIsAboveTheHighestOfThatSecond(): void
    {
        $dir = $this->dataDir . '/backups';
        $base = 'backup_2025-11-03_074512';
        self::assertSame("$base.json", BackupService::nextSnapshotName($dir, $base), 'leer: der Grundname');
        $this->touchAll($dir, ["$base.json"]);
        self::assertSame("$base-2.json", BackupService::nextSnapshotName($dir, $base));
        // der Grundname rotiert weg, -2 bis -11 bleiben: der nächste ist -12 (bisher wieder der Grundname)
        @unlink("$dir/$base.json");
        $this->touchAll($dir, array_map(fn($n) => "$base-$n.json", range(2, 11)));
        self::assertSame("$base-12.json", BackupService::nextSnapshotName($dir, $base));
        // eine Lücke in der Mitte zählt nicht: über der höchsten, nicht in der Lücke
        @unlink("$dir/$base-5.json");
        self::assertSame("$base-12.json", BackupService::nextSnapshotName($dir, $base));
        // andere Sekunden und andere Anlässe zählen nicht
        $this->touchAll($dir, ['backup_2025-11-03_074513-30.json', 'pre-restore-2025-11-03_074512-40.json']);
        self::assertSame("$base-12.json", BackupService::nextSnapshotName($dir, $base));
        self::assertSame('pre-restore-2025-11-03_074512-41.json', BackupService::nextSnapshotName($dir, 'pre-restore-2025-11-03_074512'));
    }

    public function testThirteenOwnSnapshotsInOneSecondKeepTheTenNewestAndTheReportedOne(): void
    {
        $backup = new BackupService($this->store, $this->i18n);
        $dir = $this->dataDir . '/backups';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            foreach (glob("$dir/*.json") ?: [] as $f) @unlink($f);
            $names = [];
            for ($i = 0; $i < 13; $i++) $names[] = $backup->saveSnapshot();
            $seconds = array_unique(array_map(fn($n) => substr($n, 0, strlen('backup_2025-11-03_074512')), $names));
            if (count($seconds) === 1) break;   // alle in derselben Sekunde, sonst noch einmal
        }
        self::assertCount(1, $seconds, 'dreizehn Sicherungen in einer Sekunde (fünf Versuche)');
        self::assertCount(13, array_unique($names), 'dreizehn verschiedene Namen: ' . implode(', ', $names));
        $left = array_column($backup->listSnapshots(), 'name');
        self::assertCount(10, $left);
        self::assertContains(end($names), $left, 'die eben gemeldete Sicherung gibt es');
        sort($left);
        $newest = array_slice($names, 3);
        sort($newest);
        self::assertSame($newest, $left, 'es bleiben die zehn neuesten');
    }
}
