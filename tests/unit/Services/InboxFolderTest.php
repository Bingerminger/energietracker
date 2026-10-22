<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\BillService;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\FolderIntake;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (Paket betrieb) — überwachter Ordner `posteingang/`: PDFs wandern in
 * den Posteingang und danach nach `übernommen/`, Abgelehntes nach `fehler/`.
 * Sicherheitsfälle: Inhalt statt Endung, Größengrenze, keine Symlinks (Datei,
 * Ordner, Ablage), keine versteckten Dateien, nichts außerhalb, frisch
 * geschriebene Dateien warten.
 */
#[CoversClass(FolderIntake::class)]
final class InboxFolderTest extends ServiceTestCase
{
    private InboxService $inbox;
    private FolderIntake $folder;
    private string $dir;
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->inbox = new InboxService($this->store, $this->i18n, $att, new ProviderRegistry($this->settings, InboxFixture::provider()),
            new Proposals($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings),
            new Applier($this->meters, $this->contracts, $this->readings, $bills, $reminders, $this->settings, $this->i18n));
        $this->folder = new FolderIntake($this->store, $this->inbox);
        $this->dir = $this->dataDir . '/' . FolderIntake::DIR;
        $this->outside = sys_get_temp_dir() . '/et-outside-' . bin2hex(random_bytes(4));
        mkdir($this->outside);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outside . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->outside);
        parent::tearDown();
    }

    /** Datei in den Ordner legen, „vor einer Minute“ geschrieben. */
    private function drop(string $name, string $bytes, int $age = 60): string
    {
        if (!is_dir($this->dir)) mkdir($this->dir, 0775, true);
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);
        touch($path, time() - $age);
        return $path;
    }

    public function testCreatesTheFolderOnFirstScan(): void
    {
        self::assertDirectoryDoesNotExist($this->dir);
        $r = $this->folder->scan();
        self::assertDirectoryExists($this->dir, 'damit man ihn in der Freigabe findet');
        self::assertSame([], $r['imported']);
        self::assertSame(['dir' => 'posteingang', 'present' => true, 'pending' => 0], $this->folder->status());
    }

    public function testImportsPdfsAndMovesThemToTheTakenOverFolder(): void
    {
        $this->drop('Abrechnung 2025.pdf', InboxFixture::pdf('f1'));
        $this->drop('zweite.PDF', InboxFixture::pdf('f2'));
        self::assertSame(2, $this->folder->status()['pending']);
        $r = $this->folder->scan();
        self::assertSame(['Abrechnung 2025.pdf', 'zweite.PDF'], array_column($r['imported'], 'name'));
        self::assertSame('übernommen/Abrechnung 2025.pdf', $r['imported'][0]['moved']);
        self::assertFileExists($this->dir . '/übernommen/Abrechnung 2025.pdf');
        self::assertFileDoesNotExist($this->dir . '/Abrechnung 2025.pdf');
        $names = array_column($this->inbox->list(), 'name');
        sort($names);
        self::assertSame(['Abrechnung 2025.pdf', 'zweite.PDF'], $names);
        self::assertSame([], $this->folder->scan()['imported'], 'ein zweiter Lauf findet nichts mehr');
    }

    public function testADuplicateIsTakenOverWithoutASecondEntryAndKeepsBothFiles(): void
    {
        $this->drop('a.pdf', InboxFixture::pdf('dup'));
        $this->folder->scan();
        $this->drop('a.pdf', InboxFixture::pdf('dup'));
        $r = $this->folder->scan();
        self::assertCount(1, $r['duplicates']);
        self::assertSame('übernommen/a (2).pdf', $r['duplicates'][0]['moved'], 'gleicher Name: keine Datei wird überschrieben');
        self::assertCount(1, $this->inbox->list());
    }

    public function testOnlyPdfByContentAndWithinTheUploadLimit(): void
    {
        $this->drop('foto.pdf', "\xFF\xD8\xFF\xE0 kein pdf");
        $big = $this->drop('gross.pdf', '');
        $fp = fopen($big, 'r+');
        fwrite($fp, '%PDF-1.4');
        ftruncate($fp, AttachmentService::PDF_MAX + 1);
        fclose($fp);
        touch($big, time() - 60);
        $r = $this->folder->scan();
        $failed = array_column($r['failed'], 'reason', 'name');
        self::assertSame(['foto.pdf' => 'type', 'gross.pdf' => 'size'], $failed);
        self::assertFileExists($this->dir . '/fehler/foto.pdf');
        self::assertFileExists($this->dir . '/fehler/gross.pdf');
        self::assertSame([], $this->inbox->list());
    }

    public function testSymlinksHiddenFilesAndFoldersAreNeverRead(): void
    {
        file_put_contents($this->outside . '/geheim.pdf', InboxFixture::pdf('outside'));
        $this->drop('echt.pdf', InboxFixture::pdf('real'));
        symlink($this->outside . '/geheim.pdf', $this->dir . '/link.pdf');
        $this->drop('.versteckt.pdf', InboxFixture::pdf('hidden'));
        $this->drop('._echt.pdf', "\x00\x05\x16\x07 AppleDouble");
        mkdir($this->dir . '/unterordner');
        file_put_contents($this->dir . '/unterordner/tief.pdf', InboxFixture::pdf('deep'));
        $r = $this->folder->scan();
        self::assertSame(['echt.pdf'], array_column($r['imported'], 'name'));
        self::assertSame(['link.pdf' => 'symlink', 'unterordner' => 'not_a_file'], array_column($r['skipped'], 'reason', 'name'));
        self::assertTrue(is_link($this->dir . '/link.pdf'), 'der Link bleibt unangetastet liegen');
        self::assertFileExists($this->outside . '/geheim.pdf');
        self::assertFileExists($this->dir . '/.versteckt.pdf');
        self::assertCount(1, $this->inbox->list());
    }

    public function testASymlinkedFolderOrStoreIsRefused(): void
    {
        file_put_contents($this->outside . '/x.pdf', InboxFixture::pdf('lnk'));
        touch($this->outside . '/x.pdf', time() - 60);
        symlink($this->outside, $this->dir);
        $r = $this->folder->scan();
        self::assertSame([['name' => 'posteingang', 'reason' => 'symlink']], $r['skipped']);
        self::assertSame([], $this->inbox->list());
        unlink($this->dir);

        // Ablage als Link nach draußen: eingelesen ja, verschoben nicht
        $this->drop('y.pdf', InboxFixture::pdf('store'));
        symlink($this->outside, $this->dir . '/' . FolderIntake::DONE);
        $r = $this->folder->scan();
        self::assertCount(1, $r['imported']);
        self::assertNull($r['imported'][0]['moved']);
        self::assertFileDoesNotExist($this->outside . '/y.pdf');
        unlink($this->dir . '/' . FolderIntake::DONE);
    }

    public function testAFileStillBeingCopiedWaits(): void
    {
        $this->drop('frisch.pdf', InboxFixture::pdf('fresh'), 0);
        $r = $this->folder->scan();
        self::assertSame(1, $r['waiting']);
        self::assertSame([], $r['imported']);
        self::assertSame(1, count($this->folder->scan(time() + FolderIntake::SETTLE_SECONDS + 1)['imported']));
    }

    public function testAtMostAFixedNumberPerRun(): void
    {
        for ($i = 0; $i <= FolderIntake::MAX_PER_RUN; $i++) $this->drop(sprintf('d%02d.pdf', $i), InboxFixture::pdf("n$i"));
        $r = $this->folder->scan();
        self::assertCount(FolderIntake::MAX_PER_RUN, $r['imported']);
        self::assertTrue($r['more']);
        self::assertCount(1, $this->folder->scan()['imported']);
    }
}
