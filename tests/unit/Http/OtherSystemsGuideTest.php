<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.5.0 — Die openHAB-Regel aus docs/anleitungen/andere-systeme.md (und dem
 * EN-Spiegel) passt zum Eingang: Der Körper, den sie zusammensetzt, wird
 * angenommen, und die Teilstrings, nach denen sie die Antwort durchsucht
 * (`"success":true`, `"suspect":true`), stehen genau so in der Antwort —
 * auch für einen großen Wert, den Java als 1.2345678E7 schreibt. Ausprobiert
 * in openHAB ist die Regel nicht; das sagt die Anleitung.
 */
final class OtherSystemsGuideTest extends HttpServerTestCase
{
    /** @return array{body:string, url:string, checks:list<string>} */
    private static function rule(string $doc): array
    {
        $md = (string)file_get_contents(dirname(__DIR__, 3) . "/$doc");
        self::assertSame(1, preg_match('/^## openHAB\n.*?```java\n(.*?)```/ms', $md, $m), "$doc: Regel fehlt");
        $rule = $m[1];
        self::assertSame(1, preg_match('/val body = (.+)$/m', $rule, $b));
        self::assertSame(1, preg_match('/val String ET_URL\s+= "([^"]+)"/', $rule, $u));
        preg_match_all("/resp\\.contains\\('([^']+)'\\)/", $rule, $c);
        self::assertStringContainsString('sendHttpPostRequest(ET_URL, "application/json", body, headers, 10000)', $rule);
        return ['body' => $b[1], 'url' => $u[1], 'checks' => $c[1]];
    }

    /** Setzt den Ausdruck der Regel ('…' + value + '…' + today + '…') wie Xtend zusammen. */
    private static function build(string $expr, string $value, string $today): string
    {
        $parts = preg_split("/'\\s*\\+\\s*(value|today)\\s*\\+\\s*'/", trim($expr), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        foreach ($parts as $i => $p) {
            $out .= $i % 2 === 1 ? ($p === 'value' ? $value : $today) : trim($p, "'");
        }
        return $out;
    }

    public function testTheOpenHabRuleMatchesTheIngestContract(): void
    {
        $this->startServer();
        $meter = $this->json('GET', '/api/utility/strom/meters')[0]['id'] ?? '';
        $updated = $this->json('PATCH', "/api/utility/strom/meters/$meter", ['external_id' => 'strom_haus']);
        self::assertSame('strom_haus', $updated['external_id'] ?? null, 'Alias wie in der Anleitung');
        $today = date('Y-m-d');
        foreach (['docs/anleitungen/andere-systeme.md', 'docs/en/anleitungen/andere-systeme.md'] as $doc) {
            $r = self::rule($doc);
            self::assertStringEndsWith('/api.php/api/ingest', $r['url'], $doc);
            self::assertSame(['"success":true', '"suspect":true'], $r['checks'], $doc);

            // Double.toString: 12345.6 bzw. ab 10^7 in Exponentschreibweise
            foreach (['12345.6', '1.2345678E7'] as $value) {
                $body = self::build($r['body'], $value, $today);
                self::assertIsArray(json_decode($body, true), "$doc: gültiges JSON ($body)");
                $resp = $this->request('POST', '/api/ingest', ['Content-Type: application/json'], $body);
                self::assertContains($resp['status'], [200, 201], "$doc: $body");
                self::assertStringContainsString($r['checks'][0], $resp['body'], 'die Regel erkennt den Erfolg');
            }
            // kleiner als zuvor → die Regel warnt
            $lower = self::build($r['body'], '100.5', date('Y-m-d', strtotime('+1 day')));
            $resp = $this->request('POST', '/api/ingest', ['Content-Type: application/json'], $lower);
            self::assertStringContainsString($r['checks'][1], $resp['body'], 'die Regel erkennt den Verdacht');
            // unbekannter Zähler → Antworttext ohne Erfolg (openHAB liefert ihn auch bei 400)
            $bad = str_replace('strom_haus', 'gibt_es_nicht', self::build($r['body'], '1', $today));
            $resp = $this->request('POST', '/api/ingest', ['Content-Type: application/json'], $bad);
            self::assertSame(400, $resp['status']);
            self::assertStringNotContainsString($r['checks'][0], $resp['body']);
        }
    }
}
