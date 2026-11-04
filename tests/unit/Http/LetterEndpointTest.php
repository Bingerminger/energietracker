<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\App;
use Energietracker\Http\Request;
use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.11.0 (F1036, Paket briefe) — `POST /api/letters/render` über HTTP: die Route gibt es,
 * Fehler kommen mit Status und Code (Katalogschlüssel), der Brief schreibt nichts (auch mit
 * Lese-Schlüssel erlaubt, sperrt nicht vorab). Den Inhalt prüfen LetterServiceTest und
 * LetterLayoutTest an erfundenen Befunden; hier meldet sich keine Prüfung an.
 */
final class LetterEndpointTest extends HttpServerTestCase
{
    public function testTheRouteAnswersWithCodes(): void
    {
        $this->startServer(['language' => 'de', 'country' => 'DE']);
        $post = fn(array $body) => $this->request('POST', '/api/letters/render', ['Content-Type: application/json'], json_encode($body));

        $r = $post(['finding_id' => 'rights-bill_late-0123456789ab', 'format' => 'text']);
        self::assertSame(404, $r['status']);
        self::assertSame('errors.letter.findingNotFound', json_decode($r['body'], true)['code'] ?? null);
        self::assertStringContainsString('Hinweis', json_decode($r['body'], true)['error'] ?? '', 'Meldung aus dem Katalog');

        $r = $post(['finding_id' => 'rights-bill_late-0123456789ab', 'format' => 'docx']);
        self::assertSame([400, 'errors.letter.formatInvalid'], [$r['status'], json_decode($r['body'], true)['code'] ?? null]);

        $r = $this->request('GET', '/api/letters/render');
        self::assertContains($r['status'], [404, 405], 'nur POST');
    }

    public function testRenderingIsAReadOnlyPost(): void
    {
        $r = new \ReflectionClass(Request::class);
        $req = $r->newInstanceWithoutConstructor();
        foreach (['method' => 'POST', 'path' => '/api/letters/render'] as $k => $v) $r->getProperty($k)->setValue($req, $v);
        self::assertTrue(App::readOnlyPost($req));
        self::assertTrue(App::locksLate($req));
    }
}
