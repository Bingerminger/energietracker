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

    /**
     * v3.12.0 — über HTTP: Die Vertragsliste sagt je Vertrag `letters`, und `contract_id` statt Befund liefert
     * den Brief; Sonderkündigung ohne Befund nie, unbekannter Vertrag 404, das Land ohne Regeln nur die Kündigung.
     */
    public function testALetterFromTheContract(): void
    {
        $this->startServer(['language' => 'de', 'country' => 'DE']);
        $json = ['Content-Type: application/json'];
        $meters = json_decode($this->request('GET', '/api/utility/strom/meters')['body'], true)['data'];
        $created = $this->request('POST', '/api/utility/strom/contracts', $json, json_encode(['meter_id' => $meters[0]['id'], 'provider' => 'Stadtwerke Musterstadt',
            'start' => '2025-01-01', 'customer_number' => 'K-4711', 'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]]]));
        self::assertContains($created['status'], [200, 201], $created['body']);
        $id = json_decode($created['body'], true)['data']['id'];

        $list = json_decode($this->request('GET', '/api/utility/strom/contracts')['body'], true)['data'];
        self::assertSame(['advance_adjustment', 'termination'], $list[0]['letters']);
        $one = json_decode($this->request('GET', "/api/utility/strom/contracts/$id")['body'], true)['data'];
        self::assertSame(['advance_adjustment', 'termination'], $one['letters']);

        $post = fn(array $body) => $this->request('POST', '/api/letters/render', $json, json_encode($body));
        $r = $post(['utility' => 'strom', 'contract_id' => $id, 'kind' => 'termination', 'format' => 'text']);
        self::assertSame(200, $r['status'], $r['body']);
        $m = json_decode($r['body'], true)['data'];
        self::assertSame(['termination', null, $id], [$m['kind'], $m['finding_id'], $m['contract_id']]);
        self::assertStringContainsString('Kundennummer: K-4711', $m['text']);
        self::assertStringContainsString('"finding":{}', $r['body'], 'leer als Objekt');

        $r = $post(['utility' => 'strom', 'contract_id' => $id, 'kind' => 'special_termination']);
        self::assertSame([422, 'errors.letter.notOfferedContract'], [$r['status'], json_decode($r['body'], true)['code'] ?? null]);
        $r = $post(['utility' => 'strom', 'contract_id' => 'c_gibt_es_nicht', 'kind' => 'termination']);
        self::assertSame([404, 'errors.letter.contractNotFound'], [$r['status'], json_decode($r['body'], true)['code'] ?? null]);
        self::assertStringContainsString('Vertrag', json_decode($r['body'], true)['error'] ?? '', 'Meldung aus dem Katalog');

        $pdf = $post(['utility' => 'strom', 'contract_id' => $id, 'kind' => 'termination', 'format' => 'pdf']);
        self::assertSame(200, $pdf['status']);
        self::assertStringStartsWith('%PDF-', $pdf['body']);
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
