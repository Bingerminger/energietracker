<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.11.0 (F1035, Paket basis) — `GET /api/rights` über HTTP: die Antwort hat ihre Form auch
 * ohne angemeldete Prüfung, nennt das Land, und ein Land ohne Regeln bekommt keine Hinweise.
 */
final class RightsEndpointTest extends HttpServerTestCase
{
    public function testTheListAnswersForGermanyAndStaysEmptyElsewhere(): void
    {
        $this->startServer(['language' => 'de', 'country' => 'DE']);
        $r = $this->request('GET', '/api/rights');
        self::assertSame(200, $r['status']);
        $d = json_decode($r['body'], true)['data'] ?? null;
        self::assertSame(['enabled', 'country', 'today', 'bills_recorded', 'findings', 'failed'], array_keys($d));
        // v3.11.0 (Skeptiker M7) — ohne erfasste Rechnung: 0 (die Empfehlungsseite sagt dann, was fehlt)
        self::assertSame([true, 'DE', 0, [], []], [$d['enabled'], $d['country'], $d['bills_recorded'], $d['findings'], $d['failed']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $d['today']);
        self::assertSame(200, $this->request('GET', '/api/rights?utility=strom&check=bill_late&todo=1')['status']);

        $this->json('PATCH', '/api/settings', ['country' => 'AT']);
        $at = $this->json('GET', '/api/rights');
        self::assertSame([false, 'AT', []], [$at['enabled'], $at['country'], $at['findings']]);
    }
}
