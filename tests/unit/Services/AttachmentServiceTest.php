<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Http\NotFoundException;
use Energietracker\Services\AttachmentService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H2, B1) — Belege: Inhalt statt Endung, Grenzen, Verweise,
 * Aufräumen nach 24 Stunden.
 */
#[CoversClass(AttachmentService::class)]
final class AttachmentServiceTest extends ServiceTestCase
{
    public const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
    public const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private AttachmentService $att;

    protected function setUp(): void
    {
        parent::setUp();
        $this->att = new AttachmentService($this->store, $this->settings, $this->i18n);
    }

    public function testTheContentDecidesTheTypeNotTheName(): void
    {
        self::assertSame('image/jpeg', AttachmentService::sniff(self::JPEG));
        self::assertSame('image/png', AttachmentService::sniff("\x89PNG\r\n\x1A\n" . str_repeat("\0", 8)));
        self::assertSame('image/webp', AttachmentService::sniff('RIFF' . "\0\0\0\0" . 'WEBPVP8 '));
        self::assertSame('application/pdf', AttachmentService::sniff(self::PDF));
        self::assertNull(AttachmentService::sniff('<svg xmlns="http://www.w3.org/2000/svg"/>'));
        self::assertNull(AttachmentService::sniff('<!doctype html><script>alert(1)</script>'));

        $e = $this->catch(fn() => $this->att->store('<svg onload="x"/>', 'reading_photo', 'zaehler.jpg'));
        self::assertSame('errors.attachment.type', $e->key);
    }

    public function testStoreWritesFileAndIndex(): void
    {
        $a = $this->att->store(self::JPEG, 'reading_photo', '../../etc/IMG_0001.JPG');
        self::assertMatchesRegularExpression(AttachmentService::ID, $a['id']);
        self::assertSame('image/jpeg', $a['mime']);
        self::assertSame(hash('sha256', self::JPEG), $a['sha256']);
        self::assertSame('IMG_0001.JPG', $a['original_name'], 'nur der Dateiname, kein Pfad');
        self::assertNull($a['ref']);
        self::assertSame(self::JPEG, file_get_contents($this->att->path($a['id'])));
        self::assertStringEndsWith('/attachments/' . $a['id'] . '.jpg', $this->att->path($a['id']));
        self::assertCount(1, $this->att->list());
        self::assertSame('other', $this->att->store(self::PDF, 'quatsch')['kind'], 'unbekannte Art → other');
    }

    public function testLimitsPerTypeAndTotal(): void
    {
        $big = self::JPEG . str_repeat("\0", 3 * 1024 * 1024);
        self::assertSame('errors.attachment.size', $this->catch(fn() => $this->att->store($big, 'reading_photo'))->key);
        // PDF darf größer sein als ein Foto
        $pdf = self::PDF . str_repeat(' ', 4 * 1024 * 1024);
        self::assertSame('application/pdf', $this->att->store($pdf, 'bill_pdf')['mime']);

        $this->settings->set(['attachments_max_mb' => 10]);
        $this->att->store(self::PDF . str_repeat(' ', 5 * 1024 * 1024), 'bill_pdf');
        self::assertSame('errors.attachment.quota', $this->catch(fn() => $this->att->store($pdf, 'bill_pdf'))->key);
    }

    public function testIdsCannotReachOutsideTheDirectory(): void
    {
        self::assertNull($this->att->get('../meta'));
        self::assertNull($this->att->get('att_../../x'));
        $this->expectException(NotFoundException::class);
        $this->att->path('att_0000000000000000');
    }

    public function testLinkChecksKindAndUnlinkStartsTheGracePeriod(): void
    {
        $photo = $this->att->store(self::JPEG, 'reading_photo');
        $pdf = $this->att->store(self::PDF, 'bill_pdf');
        $e = $this->catch(fn() => AttachmentService::link($this->store, $pdf['id'], 'reading_photo', ['type' => 'reading', 'id' => 'r1']));
        self::assertSame('errors.attachment.wrongKind', $e->key);
        self::assertSame('errors.attachment.notFound',
            $this->catch(fn() => AttachmentService::link($this->store, 'att_ffffffffffffffff', null, null))->key);

        AttachmentService::link($this->store, $photo['id'], 'reading_photo', ['type' => 'reading', 'utility' => 'gas', 'id' => 'r1']);
        self::assertSame('r1', $this->att->get($photo['id'])['ref']['id']);
        AttachmentService::unlinkRef($this->store, 'reading', 'r1');
        $after = $this->att->get($photo['id']);
        self::assertNull($after['ref']);
        self::assertArrayHasKey('unlinked_at', $after);
    }

    public function testOrphansGoAfter24HoursNotBefore(): void
    {
        $linked = $this->att->store(self::JPEG, 'reading_photo');
        AttachmentService::link($this->store, $linked['id'], 'reading_photo', ['type' => 'reading', 'utility' => 'gas', 'id' => 'r1']);
        $orphan = $this->att->store(self::JPEG, 'reading_photo');
        // fremde Datei ohne Indexeintrag
        $stray = $this->store->path('attachments/att_1111111111111111.jpg');
        file_put_contents($stray, self::JPEG);
        touch($stray, time() - 2 * 86400);

        self::assertSame(1, $this->att->cleanupOrphans(time() + 3600), 'nach 1 h: nur die fremde Datei');
        self::assertNotNull($this->att->get($orphan['id']));
        self::assertFileDoesNotExist($stray);

        self::assertSame(1, $this->att->cleanupOrphans(time() + 86400 + 60));
        self::assertNull($this->att->get($orphan['id']));
        self::assertNotNull($this->att->get($linked['id']), 'verknüpft bleibt');
        self::assertFileExists($this->att->path($linked['id']));
    }

    public function testDeleteRemovesTheReferenceInTheRecord(): void
    {
        $a = $this->att->store(self::JPEG, 'reading_photo');
        $r = $this->readings->create('gas', ['date' => '2026-01-01', 'counter' => 100, 'attachment_id' => $a['id']]);
        self::assertSame($a['id'], $r['attachment_id']);

        $this->att->delete($a['id']);
        self::assertNull($this->att->get($a['id']));
        $stored = array_values(array_filter($this->readings->list('gas'), fn($x) => $x['id'] === $r['id']))[0];
        self::assertArrayNotHasKey('attachment_id', $stored);
    }

    public function testWriteBinaryOnlyUnderAttachments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->writeBinary('settings.json', '{}');
    }

    private function catch(callable $fn): LocalizedException
    {
        try {
            $fn();
        } catch (LocalizedException $e) {
            return $e;
        }
        self::fail('LocalizedException erwartet');
    }
}
