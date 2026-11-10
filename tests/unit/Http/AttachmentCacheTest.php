<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Services\AttachmentServiceTest;
use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.12.0 — Härtung: Belege bleiben nach dem Abmelden nicht im Browser.
 *
 * Bis v3.11 kam ein Beleg (Zählerfoto, Rechnung) mit
 * `private, max-age=31536000, immutable`: Der Browser hielt ihn ein Jahr und
 * zeigte ihn nach dem Abmelden ohne Rückfrage aus dem Zwischenspeicher. Jetzt:
 *   - Belege `private, no-cache` mit ETag: Der Browser fragt jedes Mal nach,
 *     unverändert kostet das ein 304 ohne Inhalt. Nach dem Abmelden antwortet
 *     der Server 401, auch auf die Nachfrage mit ETag.
 *   - Die Abmelde-Antworten (auch „überall abmelden“) tragen
 *     `Clear-Site-Data: "cache"`: Der Browser leert seinen Zwischenspeicher für
 *     diese Adresse (nur über HTTPS oder localhost; sonst greift die Nachfrage).
 * Die Doku-Zusage (sicherheit.md § 3, `doc-claim: logout-clears-cache`) hängt
 * an diesem Test.
 */
final class AttachmentCacheTest extends HttpServerTestCase
{
    protected function setUp(): void
    {
        $this->startServer();
    }

    /** @return array{0: array<int,string>, 1: string} Cookie-Kopfzeile, Beleg-ID */
    private function loginWithAttachment(): array
    {
        $r = $this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'Beleg-Probe-42']));
        self::assertSame(200, $r['status'], $r['body']);
        self::assertSame(1, preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m));
        $cookie = ['Cookie: et_session=' . $m[1]];
        $up = $this->request('POST', '/api/attachments?kind=reading_photo', array_merge($cookie, ['Content-Type: image/jpeg']), AttachmentServiceTest::JPEG);
        self::assertSame(201, $up['status'], $up['body']);
        $id = (string)(json_decode($up['body'], true)['data']['id'] ?? '');
        self::assertNotSame('', $id);
        return [$cookie, $id];
    }

    public function testAttachmentsAreRevalidatedNotKeptForAYear(): void
    {
        [$cookie, $id] = $this->loginWithAttachment();
        $r = $this->request('GET', "/api/attachments/$id", $cookie);
        self::assertSame(200, $r['status']);
        self::assertSame(AttachmentServiceTest::JPEG, $r['body']);
        $cc = $r['headers']['cache-control'] ?? '';
        self::assertStringContainsString('private', $cc);
        self::assertStringContainsString('no-cache', $cc);
        self::assertStringNotContainsString('immutable', $cc);
        self::assertStringNotContainsString('max-age=31536000', $cc);
        $etag = $r['headers']['etag'] ?? '';
        self::assertMatchesRegularExpression('/^"[0-9a-f]{16,}"$/', $etag, 'ETag fehlt');

        // unverändert: 304 ohne Inhalt, mit demselben ETag und derselben Regel
        $again = $this->request('GET', "/api/attachments/$id", array_merge($cookie, ["If-None-Match: $etag"]));
        self::assertSame(304, $again['status']);
        self::assertSame('', $again['body']);
        self::assertSame($etag, $again['headers']['etag'] ?? '');
        self::assertStringContainsString('no-cache', $again['headers']['cache-control'] ?? '');
        // ein anderer ETag (andere Datei) bekommt den Inhalt
        self::assertSame(200, $this->request('GET', "/api/attachments/$id", array_merge($cookie, ['If-None-Match: "0000000000000000"']))['status']);
    }

    public function testLogoutClearsTheBrowserCacheAndRevalidationFailsAfterwards(): void
    {
        [$cookie, $id] = $this->loginWithAttachment();
        $etag = $this->request('GET', "/api/attachments/$id", $cookie)['headers']['etag'] ?? '';

        $out = $this->request('DELETE', '/api/session', $cookie);
        self::assertSame(200, $out['status']);
        self::assertSame('"cache"', $out['headers']['clear-site-data'] ?? null, 'Abmelden leert den Zwischenspeicher');

        // die Nachfrage des Browsers ohne Sitzung: 401, kein 304
        $r = $this->request('GET', "/api/attachments/$id", ["If-None-Match: $etag"]);
        self::assertSame(401, $r['status']);
        self::assertStringNotContainsString(AttachmentServiceTest::JPEG, $r['body']);
    }

    public function testLogoutEverywhereClearsTheCacheToo(): void
    {
        [$cookie] = $this->loginWithAttachment();
        $out = $this->request('POST', '/api/session/logout-everywhere', $cookie);
        self::assertSame(200, $out['status'], $out['body']);
        self::assertSame('"cache"', $out['headers']['clear-site-data'] ?? null);
    }

    /** Die Doku sagt, was Abmelden löscht; ihr Absatz hängt an diesem Test. */
    public function testTheDocumentedLogoutPromiseNamesWhatTheServerSends(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docs/betrieb/sicherheit.md' => ['Clear-Site-Data', 'no-cache'], 'docs/en/betrieb/sicherheit.md' => ['Clear-Site-Data', 'no-cache']] as $rel => $needles) {
            $text = (string)file_get_contents("$root/$rel");
            $pos = strpos($text, '<!-- doc-claim: logout-clears-cache');
            self::assertNotFalse($pos, "$rel: Zusage fehlt");
            $para = substr($text, (int)$pos, 1200);
            foreach ($needles as $n) self::assertStringContainsString($n, $para, "$rel: „{$n}“ fehlt im Absatz");
        }
    }
}
