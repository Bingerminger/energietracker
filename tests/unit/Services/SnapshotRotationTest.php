<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\MeteringConceptService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Aufbewahrung der Snapshots: eigene und automatische rotieren getrennt.
 *
 * Bis v3.11 galt jeder Snapshot mit unbekanntem Präfix als eigener; die Snapshots vor dem
 * Messkonzept (`concept_…`) rotierten mit den eigenen, und nach zehn Läufen des Assistenten war
 * eine selbst angelegte Sicherung ohne Hinweis gelöscht. Dazu sortierten mehrere Snapshots aus
 * derselben Sekunde als Text („…-10“ vor „…-9“), und die Rotation löschte den gerade angelegten.
 */
#[CoversClass(BackupService::class)]
final class SnapshotRotationTest extends ServiceTestCase
{
    private function backups(): BackupService
    {
        return new BackupService($this->store, $this->i18n);
    }

    public function testEveryAutomaticPrefixHasItsOwnReason(): void
    {
        self::assertSame('manual', BackupService::reasonOf('backup_2026-11-09_101010.json'));
        self::assertSame('concept', BackupService::reasonOf('concept_2026-11-09_101010.json'));
        self::assertSame('inbox', BackupService::reasonOf('pre-inbox-2026-11-09_101010.json'));
        self::assertSame('restore', BackupService::reasonOf('pre-restore-2026-11-09_101010.json'));
        self::assertSame('migration', BackupService::reasonOf('pre-migration-1.7.0_2026-11-09_101010.json'));
        self::assertSame('auto', BackupService::reasonOf('etwas-2026-11-09_101010.json'), 'unbekannt ist nie „eigen“');
    }

    public function testElevenMeteringConceptRunsKeepTheOwnSnapshot(): void
    {
        $own = $this->backups()->saveSnapshot();
        $concept = new MeteringConceptService($this->meters, $this->contracts, $this->settings, $this->i18n, $this->backups(), $this->readings);
        $main = $this->meters->defaultId('strom');
        for ($i = 0; $i < 11; $i++) {
            $concept->apply(['concept' => 'single', 'tou' => 'none', 'slots' => ['main' => ['meter_id' => $main]]]);
        }
        $list = $this->backups()->listSnapshots();
        self::assertContains($own, array_column($list, 'name'), 'die eigene Sicherung bleibt');
        self::assertSame(11, count(array_filter($list, fn($s) => $s['reason'] === 'concept')), 'automatische: 30 Tage, also alle elf von heute');
    }

    public function testManySnapshotsInOneSecondDropTheOldestNotTheNewest(): void
    {
        // zwölf eigene mit demselben Zeitstempel (die App hängt -2, -3 … an), gleiche Dateizeit
        $dir = $this->store->path('backups');
        @mkdir($dir, 0755, true);
        $names = ['backup_2026-11-09_101010.json'];
        for ($n = 2; $n <= 12; $n++) $names[] = "backup_2026-11-09_101010-$n.json";
        foreach ($names as $name) {
            file_put_contents("$dir/$name", '{}');
            touch("$dir/$name", 1762683010);
        }
        $newest = $this->backups()->saveSnapshot();   // der dreizehnte, jetzt
        $kept = array_column($this->backups()->listSnapshots(), 'name');
        self::assertSame($newest, $kept[0], 'der neueste steht oben');
        self::assertCount(10, $kept);
        // neun der alten bleiben: -12 bis -4; gelöscht sind die drei ältesten (ohne Nummer, -2, -3)
        $expect = [$newest];
        for ($n = 12; $n >= 4; $n--) $expect[] = "backup_2026-11-09_101010-$n.json";
        self::assertSame($expect, $kept);
    }
}
