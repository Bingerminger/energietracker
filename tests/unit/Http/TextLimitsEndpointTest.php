<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Support\TextLimits;
use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 (Paket F) — Längengrenzen über die API: zu lang → 400 `errors.common.tooLong` mit Feld, Grenze und
 * Länge in der Sprache der Anfrage, nichts gespeichert; ein gespeicherter Wert über der Grenze (älterer Stand)
 * bleibt lesbar, ein Ändern anderer Felder geht, und erst wer ihn mitschickt, bekommt die Meldung.
 */
#[CoversNothing]
final class TextLimitsEndpointTest extends HttpServerTestCase
{
    /** @return array{status:int, code:?string, error:string, detail:mixed} */
    private function call(string $method, string $path, array $payload): array
    {
        $r = $this->request($method, $path, ['Content-Type: application/json', "Origin: {$this->base}", 'X-ET-Language: de'],
            (string)json_encode($payload));
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => is_array($j) ? ($j['code'] ?? null) : null,
                'error' => is_array($j) ? (string)($j['error'] ?? '') : '', 'detail' => is_array($j) ? ($j['detail'] ?? null) : null];
    }

    public function testTooLongTextIs400WithFieldAndLimit(): void
    {
        $this->startServer();
        $gas = (string)($this->json('GET', '/api/utility/gas/meters')[0]['id'] ?? '');
        $r = $this->call('POST', '/api/utility/gas/meters', ['name' => str_repeat('Z', TextLimits::NAME + 1), 'installed_on' => '2025-01-01']);
        self::assertSame([400, 'errors.common.tooLong'], [$r['status'], $r['code']], $r['error']);
        self::assertSame('„name“ ist zu lang: 121 Zeichen, erlaubt sind höchstens 120.', $r['error']);
        self::assertCount(1, $this->json('GET', '/api/utility/gas/meters'), 'nichts angelegt');
        foreach ([
            ['POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => '2025-01-01', 'counter' => 1, 'note' => str_repeat('n', 2001)]],
            ['POST', '/api/utility/gas/contracts', ['meter_id' => $gas, 'provider' => 'P', 'start' => '2025-01-01', 'notes' => str_repeat('n', 2001)]],
            ['POST', '/api/reminders', ['title' => str_repeat('t', 301), 'next_due' => '2026-12-01']],
            ['POST', '/api/utility/gas/meter-groups', ['name' => str_repeat('g', 121)]],
            ['PATCH', '/api/settings', ['location_name' => str_repeat('o', 121)]],
        ] as [$m, $path, $body]) {
            $x = $this->call($m, $path, $body);
            self::assertSame([400, 'errors.common.tooLong'], [$x['status'], $x['code']], "$m $path: {$x['error']}");
        }
    }

    public function testExistingTextOverTheLimitStaysReadableAndIsReportedWhenSaved(): void
    {
        $this->startServer();
        $gas = (string)($this->json('GET', '/api/utility/gas/meters')[0]['id'] ?? '');
        $long = str_repeat('Notiz aus einer älteren Version. ', 80);   // 2640 Zeichen
        $file = $this->dataDir . '/gas/meters.json';
        $all = json_decode((string)file_get_contents($file), true);
        $all[0]['notes'] = $long;
        file_put_contents($file, json_encode($all));

        self::assertSame($long, $this->json('GET', '/api/utility/gas/meters')[0]['notes'], 'lesbar');
        $ok = $this->call('PATCH', "/api/utility/gas/meters/$gas", ['name' => 'Gas Keller']);
        self::assertSame(200, $ok['status'], $ok['error']);
        $bad = $this->call('PATCH', "/api/utility/gas/meters/$gas", ['name' => 'Gas Keller', 'notes' => $long]);
        self::assertSame([400, 'errors.common.tooLong'], [$bad['status'], $bad['code']]);
        self::assertStringContainsString('2000', $bad['error']);
        $now = $this->json('GET', '/api/utility/gas/meters')[0];
        self::assertSame(['Gas Keller', $long], [$now['name'], $now['notes']], 'nie still gekürzt');
    }
}
