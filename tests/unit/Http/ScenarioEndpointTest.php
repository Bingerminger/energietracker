<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.6.0 (F1031) — `POST /api/forecast/scenario` über HTTP: Statuscodes,
 * Fehlercodes, und dass die Route nur liest (Lese-Schlüssel erlaubt).
 */
final class ScenarioEndpointTest extends HttpServerTestCase
{
    private function send(array|string|null $payload, array $headers = []): array
    {
        $body = is_array($payload) ? json_encode($payload) : $payload;
        $r = $this->request('POST', '/api/forecast/scenario', array_merge(['Content-Type: application/json'], $headers), $body);
        $r['json'] = json_decode($r['body'], true);
        return $r;
    }

    public function testStatusCodesAndReadOnlyKey(): void
    {
        $this->startServer();
        $gas = $this->json('GET', '/api/utility/gas/meters')[0]['id'];

        // frische Installation: Zähler ohne Ablesungen → gültige Antwort ohne Rechnung
        $ok = $this->send(['utility' => 'gas', 'meter_id' => $gas]);
        self::assertSame(200, $ok['status']);
        self::assertSame([true, false, 'too_few_months'], [$ok['json']['data']['supported'], $ok['json']['data']['valid'], $ok['json']['data']['reason_code']]);

        $bad = $this->send(['utility' => 'gas', 'meter_id' => $gas, 'winter_pct' => 300]);
        self::assertSame(400, $bad['status']);
        self::assertSame('errors.forecast.paramInvalid', $bad['json']['code'] ?? null);
        self::assertStringContainsString('winter_pct', $bad['json']['error'] ?? '');

        self::assertSame(400, $this->send('kein json')['status']);
        $missing = $this->send(['utility' => 'gas', 'meter_id' => 'm_gibtsnicht']);
        self::assertSame(404, $missing['status']);
        self::assertSame('errors.meter.notFound', $missing['json']['code'] ?? null, 'übersetzte Meldung mit stabilem Code');
        $oil = $this->send(['utility' => 'heizoel', 'meter_id' => 'egal']);
        self::assertSame([200, false], [$oil['status'], $oil['json']['data']['supported']]);

        // Lese-Schlüssel: die Route schreibt nichts und ist erlaubt; Schreiben bleibt gesperrt
        $admin = $this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'geheim-123']));
        preg_match('/et_session=([^;]+)/', $admin['headers']['set-cookie'] ?? '', $m);
        $cookie = ['Cookie: et_session=' . ($m[1] ?? '')];
        $read = $this->json('POST', '/api/auth/keys', ['name' => 'Anzeige', 'scope' => 'read'], $cookie)['key'];
        self::assertSame(200, $this->send(['utility' => 'gas', 'meter_id' => $gas], ["Authorization: Bearer $read"])['status']);
        $w = $this->request('POST', '/api/reminders', ['Content-Type: application/json', "Authorization: Bearer $read"],
            json_encode(['title' => 'x', 'category' => 'custom', 'next_due' => date('Y-m-d'), 'recurrence' => 'yearly']));
        self::assertSame(403, $w['status']);
    }
}
