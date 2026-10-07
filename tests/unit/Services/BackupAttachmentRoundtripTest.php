<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H2, B1) — Belege im Backup: Index als Topf, Dateien als
 * base64 unter `attachment_files`; Format bleibt 3.0.
 */
#[CoversClass(BackupService::class)]
final class BackupAttachmentRoundtripTest extends ServiceTestCase
{
    private BackupService $backups;
    private AttachmentService $att;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backups = new BackupService($this->store, $this->i18n);
        $this->att = new AttachmentService($this->store, $this->settings, $this->i18n);
    }

    public function testExportImportBringsTheSameBytesBack(): void
    {
        $photo = $this->att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $pdf = $this->att->store(AttachmentServiceTest::PDF, 'bill_pdf');
        $r = $this->readings->create('gas', ['date' => '2026-01-01', 'counter' => 100, 'attachment_id' => $photo['id']]);

        $backup = $this->backups->export();
        self::assertSame('3.0', $backup['backup_version']);
        self::assertCount(2, $backup['attachments']);
        self::assertSame(base64_encode(AttachmentServiceTest::PDF), $backup['attachment_files'][$pdf['id']]);
        self::assertArrayNotHasKey('attachment_files', $this->backups->export(false), 'ohne Belege: nur der Index');

        // alles weg …
        foreach ($this->att->list() as $a) $this->att->delete($a['id']);
        self::assertSame([], $this->att->list());
        // … und zurück
        $report = $this->backups->import($backup);
        self::assertSame(2, $report['attachment_files']);
        self::assertSame(hash('sha256', AttachmentServiceTest::JPEG), hash_file('sha256', $this->att->path($photo['id'])));
        self::assertSame(AttachmentServiceTest::PDF, file_get_contents($this->att->path($pdf['id'])));
        $reading = array_values(array_filter($this->readings->list('gas'), fn($x) => $x['id'] === $r['id']))[0];
        self::assertSame($photo['id'], $reading['attachment_id']);
    }

    public function testTheSnapshotStreamsTheFilesAndRestoresThem(): void
    {
        $photo = $this->att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $name = $this->backups->saveSnapshot();
        $raw = json_decode((string)file_get_contents($this->backups->snapshotPath($name)), true);
        self::assertSame(base64_encode(AttachmentServiceTest::JPEG), $raw['attachment_files'][$photo['id']]);

        $this->att->delete($photo['id']);
        $this->backups->restoreSnapshot($name);
        self::assertSame(AttachmentServiceTest::JPEG, file_get_contents($this->att->path($photo['id'])));
    }

    public function testTheStreamedExportEqualsTheArrayExport(): void
    {
        $this->att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $out = '';
        $this->backups->streamExport(function (string $s) use (&$out): void { $out .= $s; });
        $streamed = json_decode($out, true);
        $array = $this->backups->export();
        unset($streamed['exported_at'], $array['exported_at']);
        self::assertEquals($array, $streamed);
    }

    public function testATamperedFileIsRejectedBeforeAnythingIsWritten(): void
    {
        $photo = $this->att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $backup = $this->backups->export();
        $this->att->delete($photo['id']);

        $bad = $backup;
        $bad['attachment_files'][$photo['id']] = base64_encode(AttachmentServiceTest::JPEG . 'x');
        $bad['settings'] = ['language' => 'nl'];
        try {
            $this->backups->import($bad);
            self::fail('manipulierte Datei angenommen');
        } catch (BackupInvalidException $e) {
            self::assertSame('sha256:' . $photo['id'], $e->problems[0]['problem']);
        }
        self::assertNotSame('nl', $this->settings->get('language'), 'nichts geschrieben');

        // falscher Typ trotz passender Prüfsumme: HTML als „Foto"
        $html = '<html><script>1</script></html>';
        $bad = $backup;
        $bad['attachments'][0]['sha256'] = hash('sha256', $html);
        $bad['attachment_files'][$photo['id']] = base64_encode($html);
        try {
            $this->backups->import($bad);
            self::fail('HTML als Beleg angenommen');
        } catch (BackupInvalidException $e) {
            self::assertSame('mime:' . $photo['id'], $e->problems[0]['problem']);
        }

        // ID mit Pfad-Trick im Index
        $bad = $backup;
        $bad['attachments'][0]['id'] = '../meta';
        unset($bad['attachment_files']);
        $this->expectException(BackupInvalidException::class);
        $this->backups->import($bad);
    }

    public function testAnOlderBackupWithoutAttachmentsLeavesThemUntouched(): void
    {
        $photo = $this->att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $backup = $this->backups->export();
        unset($backup['attachments'], $backup['attachment_files']);
        $report = $this->backups->import($backup);
        self::assertContains('attachments', $report['untouched']);
        self::assertNotNull($this->att->get($photo['id']));
    }
}
