<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Services\Mcp\Server;
use Energietracker\Tests\Support\HttpServerTestCase;
use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.12.0 (F1037, Basis) — `POST /api/mcp` über HTTP: ab Werk aus, `ET_MCP=off`
 * sperrt fest, Schlüssel mit Bereich mcp/mcp_write immer Pflicht (auch ohne
 * Anmeldung), KI-Schlüssel gelten nur dort (`mcp_write` dazu für das Ablegen im
 * Posteingang), Ursprung, keine Schreibsperre vorab, Geheimnisse in keiner Antwort.
 */
final class McpEndpointTest extends HttpServerTestCase
{
    /** @param list<string> $headers */
    private function mcp(array|string $msg, ?string $key, array $headers = []): array
    {
        $h = array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream'],
            $key !== null ? ["Authorization: Bearer $key"] : [], $headers);
        $r = $this->request('POST', '/api/mcp', $h, is_string($msg) ? $msg : (string)json_encode($msg));
        $r['json'] = json_decode($r['body'], true);
        return $r;
    }

    private static function init(int $id = 1): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '1']]];
    }

    /** @param list<string> $auth */
    private function key(string $scope, array $auth = []): string
    {
        return (string)$this->json('POST', '/api/auth/keys', ['name' => "KI $scope", 'scope' => $scope], $auth)['key'];
    }

    private function enable(array $auth = []): void
    {
        $this->json('PATCH', '/api/settings', ['mcp_enabled' => true], $auth);
    }

    private static function cookie(array $r): array
    {
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }

    public function testOffByDefaultThenOnWithAKey(): void
    {
        $this->startServer();
        $key = $this->key('mcp');
        $off = $this->mcp(self::init(), $key);
        self::assertSame(403, $off['status']);
        self::assertSame('application/json; charset=utf-8', $off['headers']['content-type']);
        self::assertSame(['jsonrpc' => '2.0', 'id' => null], array_slice($off['json'], 0, 2));
        self::assertSame([Server::ERR_DISABLED, 'errors.mcp.disabled'], [$off['json']['error']['code'], $off['json']['error']['data']['code']]);
        self::assertStringContainsString('ausgeschaltet', $off['json']['error']['message']);

        $this->enable();
        $on = $this->mcp(self::init(), $key);
        self::assertSame(200, $on['status'], $on['body']);
        self::assertSame('2025-06-18', $on['json']['result']['protocolVersion']);
        self::assertArrayNotHasKey('mcp-session-id', $on['headers'], 'keine Sitzung');
        self::assertSame(202, $this->mcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $key)['status']);
        self::assertSame('', $this->mcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $key)['body']);

        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $key);
        self::assertContains('list_meters', array_column($tools['json']['result']['tools'], 'name'));
        $call = $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'list_meters', 'arguments' => new \stdClass()]], $key);
        self::assertFalse($call['json']['result']['isError']);
        self::assertSame('m_gas_default', $call['json']['result']['structuredContent']['meters'][0]['id']);

        // Methoden außer POST: 405 mit Allow (kein Server-Stream, keine Sitzung zum Beenden)
        $get = $this->request('GET', '/api/mcp', ["Authorization: Bearer $key"]);
        self::assertSame(405, $get['status']);
        self::assertSame('POST, OPTIONS', $get['headers']['allow'] ?? null);
        self::assertSame(405, $this->request('DELETE', '/api/mcp', ["Authorization: Bearer $key"])['status']);

        // Geheimnisse in keiner Antwort: weder der Schlüssel noch sein Hash
        $all = $on['body'] . $tools['body'] . $call['body'];
        self::assertStringNotContainsString($key, $all);
        self::assertStringNotContainsString(hash('sha256', $key), $all);
        self::assertStringNotContainsString('etk_', $all);
    }

    public function testEnvironmentLocksItEvenWhenSwitchedOn(): void
    {
        $this->startServer(['language' => 'de', 'mcp_enabled' => true], ['ET_MCP' => 'off']);
        $r = $this->mcp(self::init(), $this->key('mcp'));
        self::assertSame([403, Server::ERR_DISABLED, 'errors.mcp.lockedByEnv'], [$r['status'], $r['json']['error']['code'], $r['json']['error']['data']['code']]);
    }

    public function testAKeyIsAlwaysRequiredAndOnlyAnAiKey(): void
    {
        $this->startServer(['language' => 'en', 'mcp_enabled' => true]);   // ohne Anmeldung, Englisch
        $none = $this->mcp(self::init(), null);
        self::assertSame(401, $none['status']);
        self::assertSame('Bearer realm="energietracker"', $none['headers']['www-authenticate'] ?? null);
        self::assertSame([Server::ERR_UNAUTHORIZED, 'errors.mcp.keyRequired'], [$none['json']['error']['code'], $none['json']['error']['data']['code']]);
        self::assertStringStartsWith('The AI interface needs a valid API key', $none['json']['error']['message']);

        $wrong = 'etk_' . str_repeat('0', 48);
        $bad = $this->mcp(self::init(), $wrong);
        self::assertSame(401, $bad['status']);
        self::assertStringNotContainsString($wrong, $bad['body'], 'der vorgelegte Schlüssel kommt nicht zurück');

        foreach (['read', 'admin', 'calendar', 'inbox'] as $scope) {
            $r = $this->mcp(self::init(), $this->key($scope));
            self::assertSame([403, Server::ERR_FORBIDDEN, 'errors.mcp.keyScope'], [$r['status'], $r['json']['error']['code'], $r['json']['error']['data']['code']], $scope);
            self::assertStringContainsString('error="insufficient_scope", scope="mcp"', $r['headers']['www-authenticate'] ?? '');
        }
        self::assertSame(200, $this->mcp(self::init(), $this->key('mcp_write'))['status']);
    }

    public function testOriginsOtherThanOwnAreRejected(): void
    {
        $this->startServer(['language' => 'de', 'mcp_enabled' => true]);
        $key = $this->key('mcp');
        $port = parse_url($this->base, PHP_URL_PORT);
        // fremde Webseite: schon der CSRF-Schutz davor lehnt ab
        self::assertSame(403, $this->mcp(self::init(), $key, ['Origin: http://evil.example'])['status']);
        // käme sie doch durch (Sec-Fetch-Site gefälscht), lehnt /api/mcp selbst ab
        $forged = $this->mcp(self::init(), $key, ['Origin: http://evil.example', 'Sec-Fetch-Site: same-origin']);
        self::assertSame([403, 'errors.mcp.origin'], [$forged['status'], $forged['json']['error']['data']['code'] ?? null]);
        self::assertSame(403, $this->mcp(self::init(), $key, ['Origin: null', 'Sec-Fetch-Site: same-origin'])['status']);
        self::assertSame(200, $this->mcp(self::init(), $key, ["Origin: http://127.0.0.1:$port", 'Sec-Fetch-Site: same-origin'])['status'], 'eigene Adresse');
        self::assertSame(403, $this->mcp(self::init(), $key, ['Origin: http://localhost:6274', 'Sec-Fetch-Site: same-origin'])['status']);
        // v3.12.0 (Paket protokoll, Entscheidung 2026-11-03) — keine Erlaubnisliste: die frühere Einstellung wirkt nicht
        self::assertSame(200, $this->request('PATCH', '/api/settings', ['Content-Type: application/json'],
            json_encode(['mcp_allowed_origins' => 'http://localhost:6274']))['status'], 'unbekannte Einstellung: übergangen');
        self::assertArrayNotHasKey('mcp_allowed_origins', $this->json('GET', '/api/settings'));
        self::assertSame(403, $this->mcp(self::init(), $key, ['Origin: http://localhost:6274', 'Sec-Fetch-Site: same-origin'])['status'], 'bleibt fremd');
        self::assertSame(200, $this->mcp(self::init(), $key)['status'], 'ohne Origin (Programme, curl)');
    }

    public function testAiKeysWorkOnlyAtMcpAndInboxWithLogin(): void
    {
        $this->startServer();
        $admin = self::cookie($this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'geheim-123'])));
        $mcp = $this->key('mcp', $admin);
        $write = $this->key('mcp_write', $admin);
        $read = $this->key('read', $admin);
        $this->enable($admin);

        self::assertSame(401, $this->request('GET', '/api/summary', ["Authorization: Bearer $mcp"])['status'], 'KI-Schlüssel nicht an der übrigen API');
        self::assertSame(401, $this->request('GET', '/api/summary', ["Authorization: Bearer $write"])['status']);
        self::assertSame(200, $this->request('GET', '/api/summary', ["Authorization: Bearer $read"])['status']);
        self::assertSame(403, $this->request('GET', '/api/mcp/journal', ["Authorization: Bearer $mcp"])['status']);
        self::assertSame(401, $this->request('GET', '/api/inbox', ["Authorization: Bearer $write"])['status']);

        $pdf = fn(string $k) => $this->request('POST', '/api/inbox?name=Rechnung.pdf', ['Content-Type: application/pdf', "Authorization: Bearer $k"], InboxFixture::pdf($k));
        self::assertSame(401, $pdf($mcp)['status'], 'Lesen-Schlüssel legt nichts ab');
        $up = $pdf($write);
        self::assertSame(201, $up['status'], $up['body']);

        // mit Anmeldung: Sitzung allein reicht für /api/mcp nicht, der Schlüssel schon (ohne Sitzung)
        self::assertSame(401, $this->mcp(self::init(), null, $admin)['status']);
        self::assertSame(200, $this->mcp(self::init(), $mcp)['status']);
        $readAtMcp = $this->mcp(self::init(), $read);
        self::assertSame([403, 'errors.mcp.keyScope'], [$readAtMcp['status'], $readAtMcp['json']['error']['data']['code'] ?? null],
            'die Anmeldung der übrigen API greift an /api/mcp nicht (kein „nur lesen“ davor)');
        // Paket protokoll: das Zugriffsprotokoll führt die Anfragen an /api/mcp (neueste zuerst)
        $journal = $this->json('GET', '/api/mcp/journal', null, $admin);
        self::assertSame(['denied', 'ok', 'denied'], array_column(array_slice($journal, 0, 3), 'result'));
        self::assertSame('errors.mcp.keyScope', $journal[0]['code']);
        self::assertSame([], $this->json('GET', '/api/mcp/proposals', null, $admin));

        // Mitglieder: Schalter und Protokoll nur für Verwalter
        $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);
        $member = self::cookie($this->request('POST', '/api/session', ['Content-Type: application/json'], json_encode(['name' => 'Anna', 'password' => 'annas-passwort'])));
        $patch = $this->request('PATCH', '/api/settings', array_merge(['Content-Type: application/json'], $member), json_encode(['mcp_enabled' => false]));
        self::assertSame([403, 'errors.auth.adminOnly'], [$patch['status'], json_decode($patch['body'], true)['code'] ?? null]);
        self::assertSame(403, $this->request('PATCH', '/api/settings', array_merge(['Content-Type: application/json'], $member),
            json_encode(['mcp_share_identifiers' => true]))['status']);
        self::assertSame(200, $this->request('PATCH', '/api/settings', array_merge(['Content-Type: application/json'], $member),
            json_encode(['mcp_enabled' => true]))['status'], 'unverändert mitgeschickt: erlaubt (wie die übrigen Verwalter-Schlüssel)');
        self::assertSame(403, $this->request('GET', '/api/mcp/proposals', $member)['status']);
    }

    public function testSettingsAreCheckedStrictly(): void
    {
        $this->startServer();
        $patch = fn(array $p) => $this->request('PATCH', '/api/settings', ['Content-Type: application/json'], json_encode($p));
        self::assertSame(400, $patch(['mcp_enabled' => 'vielleicht'])['status']);
        self::assertSame(200, $patch(['mcp_enabled' => 'false'])['status']);
        self::assertFalse($this->json('GET', '/api/settings')['mcp_enabled'], 'der Text „false“ schaltet nicht ein');
    }

    public function testNoWriteLockBeforehandAndBodyLimit(): void
    {
        $this->startServer(['language' => 'de', 'mcp_enabled' => true], ['ET_LOCK_WAIT_S' => '1']);
        $key = $this->key('mcp');
        $list = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_meters']];
        self::assertSame(200, $this->mcp($list, $key)['status']);   // vermerkt „zuletzt benutzt“ (einmal je Stunde)

        $fp = fopen($this->dataDir . '/.write.lock', 'c');
        self::assertTrue(flock($fp, LOCK_EX));
        try {
            self::assertSame(503, $this->request('PATCH', '/api/settings', ['Content-Type: application/json'], json_encode(['wohnflaeche_m2' => 90]))['status'],
                'die Sperre ist belegt');
            // bräuchte /api/mcp die Sperre, käme nach ET_LOCK_WAIT_S (1 s) ein 503 (ohne Zeitmessung, Lektion 100)
            self::assertSame(200, $this->mcp($list, $key)['status'], 'lesen über MCP wartet nicht auf die Schreibsperre');
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        $big = $this->mcp(str_repeat(' ', 1048577) . '{}', $key);
        self::assertSame(413, $big['status'], 'Körper über 1 MB');
    }
}
