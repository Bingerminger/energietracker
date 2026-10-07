<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\ReadingService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H2, FE-32/MKT-08) — Offline-Warteschlange: ein zweites Senden
 * derselben Erfassung legt keine zweite Ablesung an; Foto-Beleg an der Ablesung.
 */
#[CoversClass(ReadingService::class)]
final class ReadingClientRefTest extends ServiceTestCase
{
    public function testTheSameClientRefTwiceGivesOneReading(): void
    {
        $first = $this->readings->create('gas', ['date' => '2026-01-01', 'counter' => 100, 'client_ref' => 'a1b2c3d4-e5f6']);
        self::assertSame('a1b2c3d4-e5f6', $first['client_ref']);
        self::assertArrayNotHasKey('duplicate', $first);

        $again = $this->readings->create('gas', ['date' => '2026-01-01', 'counter' => 100, 'client_ref' => 'a1b2c3d4-e5f6']);
        self::assertTrue($again['duplicate']);
        self::assertSame($first['id'], $again['id']);
        self::assertCount(1, $this->readings->list('gas'));
    }

    public function testWithoutClientRefNothingChanges(): void
    {
        $r = $this->readings->create('gas', ['date' => '2026-01-01', 'counter' => 100]);
        self::assertArrayNotHasKey('client_ref', $r);
        self::assertArrayNotHasKey('duplicate', $r);
    }

    public function testAMalformedClientRefIsRejected(): void
    {
        foreach (['kurz', str_repeat('a', 65), 'mit leerzeichen', '../../etc'] as $bad) {
            try {
                $this->readings->create('gas', ['date' => '2026-01-01', 'counter' => 100, 'client_ref' => $bad]);
                self::fail("angenommen: $bad");
            } catch (\InvalidArgumentException $e) {
                self::assertSame($this->i18n->t('errors.reading.clientRefInvalid'), $e->getMessage());
            }
        }
        self::assertSame([], $this->readings->list('gas'));
    }

    public function testAPhotoIsLinkedReplacedAndReleased(): void
    {
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $p1 = $att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $p2 = $att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $r = $this->readings->create('gas', ['date' => '2026-01-01', 'counter' => 100, 'attachment_id' => $p1['id']]);
        self::assertSame(['type' => 'reading', 'utility' => 'gas', 'id' => $r['id']], $att->get($p1['id'])['ref']);

        $this->readings->update('gas', $r['id'], ['attachment_id' => $p2['id']]);
        self::assertNull($att->get($p1['id'])['ref'], 'das alte Foto ist frei');
        self::assertSame($r['id'], $att->get($p2['id'])['ref']['id']);

        $this->readings->delete('gas', $r['id']);
        self::assertNull($att->get($p2['id'])['ref']);
        self::assertArrayHasKey('unlinked_at', $att->get($p2['id']), 'Aufräumen erst nach 24 h — Rückgängig bleibt möglich');
    }

    public function testOnlyAReadingPhotoBelongsToAReading(): void
    {
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $pdf = $att->store(AttachmentServiceTest::PDF, 'bill_pdf');
        try {
            $this->readings->create('gas', ['date' => '2026-01-01', 'counter' => 100, 'attachment_id' => $pdf['id']]);
            self::fail('PDF an einer Ablesung angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.attachment.wrongKind', $e->key);
        }
        self::assertSame([], $this->readings->list('gas'), 'nichts geschrieben');
    }
}
