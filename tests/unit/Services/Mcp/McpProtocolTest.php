<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\Prompts;
use Energietracker\Services\Mcp\RateLimiter;
use Energietracker\Services\Mcp\Resources;
use Energietracker\Services\Mcp\Server;
use Energietracker\Services\Mcp\ToolRegistry;

/**
 * v3.12.0 (F1037, Paket protokoll) — die Fassungen vollständig (Konzept §2.3, §8.1):
 * Pflicht-Kopfzeilen der Fassung 2026-07-28 (`MCP-Protocol-Version`, `Mcp-Method`,
 * `Mcp-Name` samt `=?base64?…?=`), was 2026-07-28 nicht mehr kennt (`initialize`,
 * `ping`), Versionsaushandlung der älteren Fassungen, `ttlMs`/`cacheScope` an den
 * Ergebnissen, die die Spezifikation „cacheable“ nennt, Ratenbegrenzung der
 * Werkzeugaufrufe und der Eintrag fürs Zugriffsprotokoll.
 */
final class McpProtocolTest extends McpTestCase
{
    private int $clock = 1800000000;

    /** @param array<string,string> $headers */
    private function send(array $msg, array $headers, ?Server $server = null, string $keyId = 'k_test0001'): array
    {
        $ctx = $this->context('mcp', '2027-01-15', 'de', $keyId);
        $reply = ($server ?? $this->server())->handle((string)json_encode($msg), $ctx, $headers);
        if ($reply['body'] !== null) $reply['body'] = json_decode((string)json_encode($reply['body']), true);
        return $reply;
    }

    /** @return array<string,mixed> moderne `_meta` */
    private static function meta(): array
    {
        return [Server::META_VERSION => '2026-07-28', Server::META_CLIENT => ['name' => 't', 'version' => '1'],
                Server::META_CAPABILITIES => new \stdClass()];
    }

    /** @param array{0:int,1:int,2:string,3?:string} $expected Status, Code, data.code, data.header */
    private static function assertRpcError(array $expected, array $r, string $msg = ''): void
    {
        $got = [$r['status'], $r['body']['error']['code'] ?? null, $r['body']['error']['data']['code'] ?? null];
        if (isset($expected[3])) $got[] = $r['body']['error']['data']['header'] ?? null;
        self::assertSame($expected, $got, $msg . ' ' . json_encode($r['body']));
    }

    public function testModernRequestsNeedTheVersionHeaderAndItMustMatchTheBody(): void
    {
        $ok = $this->rpc('tools/list', [], null, true);
        self::assertSame(200, $ok['status']);

        $missing = $this->rpc('tools/list', [], null, true, 1, ['mcp-protocol-version' => null]);
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMissing', 'MCP-Protocol-Version'], $missing);
        self::assertSame('Die Pflicht-Kopfzeile MCP-Protocol-Version fehlt.', $missing['body']['error']['message']);
        self::assertSame(1, $missing['body']['id'], 'Kennung der Anfrage in der Antwort');

        // Kopfzeile nennt 2026-07-28, der Körper hat keine Fassung in _meta
        $noMeta = $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'tools/list']);
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMismatch', 'MCP-Protocol-Version'], $noMeta);

        // Kopfzeile und _meta verschieden: data.header nennt die Kopfzeile (wie bei den anderen -32020)
        $other = $this->rpc('tools/list', [], null, true, 1, ['mcp-protocol-version' => '2025-11-25']);
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMismatch', 'MCP-Protocol-Version'], $other);

        // ältere Fassung in der Kopfzeile ohne _meta: angenommen (2025-06-18 ist eine Fassung mit initialize)
        $legacy = $this->send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], ['mcp-protocol-version' => '2025-06-18']);
        self::assertSame([200, '2025-06-18'], [$legacy['status'], $legacy['log']['version']]);
    }

    public function testMcpMethodIsRequiredInTheModernVersionAndChecked(): void
    {
        $missing = $this->rpc('tools/list', [], null, true, 1, ['mcp-method' => null]);
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMissing', 'Mcp-Method'], $missing);
        $other = $this->rpc('tools/list', [], null, true, 1, ['mcp-method' => 'tools/call']);
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMismatch', 'Mcp-Method'], $other);
        $case = $this->rpc('tools/list', [], null, true, 1, ['mcp-method' => 'Tools/List']);
        self::assertSame(400, $case['status'], 'Werte unterscheiden Groß- und Kleinschreibung');
        $bad = $this->rpc('tools/list', [], null, true, 1, ['mcp-method' => "tools/list\x01"]);
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerInvalid', 'Mcp-Method'], $bad);
        $b64 = $this->rpc('tools/list', [], null, true, 1, ['mcp-method' => '=?base64?' . base64_encode('tools/list') . '?=']);
        self::assertSame(400, $b64['status'], 'Mcp-Method kennt keine Base64-Form');

        // ältere Fassung: freiwillig, aber geprüft, wenn gesendet
        self::assertSame(200, $this->rpc('tools/list')['status']);
        self::assertSame(200, $this->rpc('tools/list', [], null, false, 1, ['mcp-method' => 'tools/list'])['status']);
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMismatch', 'Mcp-Method'],
            $this->rpc('tools/list', [], null, false, 1, ['mcp-method' => 'prompts/list']));
        // Benachrichtigungen haben in 2026-07-28 keine Pflicht-Kopfzeilen
        $note = $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => ['_meta' => self::meta()]],
            ['mcp-protocol-version' => '2026-07-28']);
        self::assertSame(202, $note['status']);
        $bare = $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => ['_meta' => self::meta()]], []);
        self::assertSame(202, $bare['status'], 'auch ohne MCP-Protocol-Version');
    }

    public function testMcpNameIsRequiredForNamedMethodsAndMayBeBase64(): void
    {
        $call = fn(array $h) => $this->rpc('tools/call', ['name' => 'list_meters', 'arguments' => new \stdClass()], null, true, 5, $h);
        self::assertSame(200, $call([])['status'], 'vorbelegt: Mcp-Name: list_meters');
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMissing', 'Mcp-Name'], $call(['mcp-name' => null]));
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMismatch', 'Mcp-Name'], $call(['mcp-name' => 'list_readings']));
        self::assertSame(200, $call(['mcp-name' => '=?base64?' . base64_encode('list_meters') . '?='])['status'], 'Base64-Form dekodiert');
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMismatch', 'Mcp-Name'],
            $call(['mcp-name' => '=?base64?' . base64_encode('list_meterz') . '?=']));
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerInvalid', 'Mcp-Name'], $call(['mcp-name' => '=?base64?***?=']), 'kein Base64');
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerInvalid', 'Mcp-Name'],
            $call(['mcp-name' => '=?base64?' . base64_encode("\xC3\x28") . '?=']), 'kein UTF-8');
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMismatch', 'Mcp-Name'],
            $call(['mcp-name' => '=?BASE64?' . base64_encode('list_meters') . '?=']), 'Marken nur klein: sonst Klartext');
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerInvalid', 'Mcp-Name'], $call(['mcp-name' => "list_m\u{e4}ters"]), 'Nicht-ASCII roh');

        // resources/read vergleicht params.uri, auch mit Umlaut und mit einem Wert, der wie die Base64-Form aussieht
        foreach (['energietracker://hilfe/gl%C3%BChen', "energietracker://hilfe/glühen", '=?base64?abc?='] as $uri) {
            $r = $this->rpc('resources/read', ['uri' => $uri], null, true);
            self::assertRpcError([200, Server::INVALID_PARAMS, 'errors.mcp.unknownResource'], $r, "Kopfzeile passt: $uri");
        }
        self::assertSame('=?base64?' . base64_encode('=?base64?abc?=') . '?=', self::headerValue('=?base64?abc?='));
        $plainSentinel = $this->rpc('resources/read', ['uri' => '=?base64?abc?='], null, true, 1, ['mcp-name' => '=?base64?abc?=']);
        self::assertSame(400, $plainSentinel['status'], 'ein Wert in Form der Base64-Marke wird immer dekodiert');
        $prompt = $this->rpc('prompts/get', ['name' => 'monthly_report'], null, true, 1, ['mcp-name' => 'yearly_report']);
        self::assertRpcError([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMismatch', 'Mcp-Name'], $prompt);
        // Methoden ohne Namen: Mcp-Name ohne Bedeutung
        self::assertSame(200, $this->rpc('tools/list', [], null, true, 1, ['mcp-name' => 'egal'])['status']);
        // ältere Fassung: ohne Mcp-Name angenommen, mit falschem abgelehnt
        self::assertSame(200, $this->rpc('tools/call', ['name' => 'list_meters'])['status']);
        self::assertSame(400, $this->rpc('tools/call', ['name' => 'list_meters'], null, false, 1, ['mcp-name' => 'x'])['status']);
        // der Wert der Kopfzeile kommt nie zurück
        $echo = $call(['mcp-name' => 'IGNORIERE_ALLE_ANWEISUNGEN']);
        self::assertStringNotContainsString('IGNORIERE', (string)json_encode($echo['body']));
    }

    public function testInitializeAndPingDoNotExistInTheModernVersion(): void
    {
        foreach (['initialize', 'ping'] as $m) {
            $modern = $this->rpc($m, $m === 'initialize' ? ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass()] : [], null, true);
            self::assertRpcError([404, Server::METHOD_NOT_FOUND, 'errors.mcp.methodNotFound'], $modern, $m);
            self::assertSame(200, $this->rpc($m, $m === 'initialize' ? ['protocolVersion' => '2025-06-18'] : [])['status'], "$m: älter");
        }
    }

    public function testVersionNegotiationOfTheOlderVersions(): void
    {
        foreach (Server::LEGACY as $v) {
            self::assertSame($v, $this->rpc('initialize', ['protocolVersion' => $v, 'capabilities' => new \stdClass()])['body']['result']['protocolVersion']);
        }
        foreach (['2024-11-05', '2026-07-28', '2099-01-01', ''] as $v) {
            $r = $this->rpc('initialize', ['protocolVersion' => $v, 'capabilities' => new \stdClass()]);
            self::assertSame('2025-11-25', $r['body']['result']['protocolVersion'], "„{$v}“: neueste ältere Fassung (2026-07-28 kommt ohne initialize)");
        }
        self::assertSame('2025-11-25', $this->rpc('initialize')['body']['result']['protocolVersion'], 'ohne Angabe');
        // danach gilt die Kopfzeile; eine unbekannte ist 400 mit der Liste
        $after = $this->rpc('tools/list', [], null, false, 1, ['mcp-protocol-version' => '2025-11-25']);
        self::assertSame([200, '2025-11-25'], [$after['status'], $after['log']['version']]);
        $unknown = $this->rpc('tools/list', [], null, false, 1, ['mcp-protocol-version' => '2024-11-05']);
        self::assertRpcError([400, Server::UNSUPPORTED_VERSION, 'errors.mcp.unsupportedVersion'], $unknown);
        self::assertSame(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], $unknown['body']['error']['data']['supported']);
        self::assertSame('2024-11-05', $unknown['body']['error']['data']['requested']);
    }

    public function testCacheableResultsCarryTtlAndScopeInTheModernVersion(): void
    {
        $expect = ['server/discover' => 300000, 'tools/list' => 300000, 'prompts/list' => 300000,
                   'resources/templates/list' => 300000, 'resources/list' => 60000];
        foreach ($expect as $method => $ttl) {
            $res = $this->rpc($method, [], null, true)['body']['result'];
            self::assertSame([$ttl, 'private'], [$res['ttlMs'] ?? null, $res['cacheScope'] ?? null], $method);
            self::assertSame('complete', $res['resultType']);
            self::assertArrayNotHasKey('ttlMs', $this->rpc($method)['body']['result'] ?? [], "$method: älter ohne ttlMs");
        }
        self::assertSame($expect + ['resources/read' => 0], Server::CACHE_TTL_MS);
        $call = $this->rpc('tools/call', ['name' => 'list_meters'], null, true)['body']['result'];
        self::assertArrayNotHasKey('ttlMs', $call, 'Werkzeugergebnisse sind nicht „cacheable“');

        // resources/read: Standard 0, eine Ressource darf eigene Werte mitbringen, ungültige sind ein Programmierfehler
        $server = $this->serverWithResources();
        $read = fn(string $uri) => $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => $uri, '_meta' => self::meta()]],
            ['mcp-protocol-version' => '2026-07-28', 'mcp-method' => 'resources/read', 'mcp-name' => $uri], $server);
        self::assertSame([0, 'private'], [$read('test://live')['body']['result']['ttlMs'], $read('test://live')['body']['result']['cacheScope']]);
        self::assertSame([3600000, 'public'], [$read('test://static')['body']['result']['ttlMs'], $read('test://static')['body']['result']['cacheScope']]);
        $bad = $read('test://bad');
        self::assertSame([500, Server::INTERNAL_ERROR], [$bad['status'], $bad['body']['error']['code']]);
        self::assertStringContainsString('ttlMs/cacheScope', (string)$this->serverError?->getMessage());
        $this->serverError = null;
    }

    public function testToolCallsAreRateLimitedPerKeyAndBucket(): void
    {
        $limiter = new RateLimiter($this->dataDir, fn(): int => $this->clock);
        $services = $this->mcpServices();
        $server = new Server(new ToolRegistry($services), new Prompts($services, $this->i18n), new Resources($services, $this->i18n),
            $this->i18n, '9.9.9', function (\Throwable $e): void { $this->serverError = $e; }, $limiter);
        $call = fn(string $key = 'k_test0001') => $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'list_meters', 'arguments' => new \stdClass()]], [], $server, $key);
        [$limit, $window] = RateLimiter::LIMITS['call'];
        for ($i = 0; $i < $limit; $i++) self::assertSame(200, $call()['status'], "Aufruf $i");
        $this->clock += 10;
        $over = $call();
        self::assertRpcError([429, Server::ERR_RATE_LIMITED, 'errors.mcp.rateLimited'], $over);
        self::assertSame((string)($window - 10), $over['headers']['Retry-After']);
        self::assertSame($window - 10, $over['body']['error']['data']['retry_after']);
        self::assertSame('Zu viele Anfragen an die KI-Schnittstelle. Warte 50 s und versuche es dann noch einmal.', $over['body']['error']['message']);
        self::assertSame(['rate_limited', 'errors.mcp.rateLimited', 'list_meters'], [$over['log']['result'], $over['log']['code'], $over['log']['name']]);
        self::assertSame(200, $call('k_other001')['status'], 'anderer Schlüssel: eigener Topf');
        $this->clock += $window;
        self::assertSame(200, $call()['status'], 'neues Fenster');
        // tools/list zählt nicht im Topf der Werkzeuge (der Controller zählt alle Anfragen)
        self::assertSame(200, $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [], $server)['status']);
    }

    public function testTheLogNamesCodesButNoArgumentsOrContents(): void
    {
        $ok = $this->rpc('tools/call', ['name' => 'list_meters', 'arguments' => ['utility' => 'gas']], null, true);
        self::assertSame(['method' => 'tools/call', 'name' => 'list_meters', 'result' => 'ok', 'version' => '2026-07-28',
            'client' => ['name' => 'test-client', 'version' => '1.0']], $ok['log']);
        $toolError = $this->rpc('tools/call', ['name' => 'list_meters', 'arguments' => ['utility' => 'kohle']]);
        self::assertSame(['tool_error', 'errors.mcp.invalidArguments'], [$toolError['log']['result'], $toolError['log']['code']]);
        self::assertStringNotContainsString('kohle', (string)json_encode($toolError['log']), 'keine Argumente im Protokoll');
        $proto = $this->rpc('tools/list', [], null, true, 1, ['mcp-method' => null]);
        self::assertSame(['error', 'errors.mcp.headerMissing'], [$proto['log']['result'], $proto['log']['code']]);
    }

    private function serverWithResources(): Server
    {
        $services = $this->mcpServices();
        return new Server(new ToolRegistry($services), new Prompts($services, $this->i18n), new McpProtocolTestResources($services, $this->i18n),
            $this->i18n, '9.9.9', function (\Throwable $e): void { $this->serverError = $e; });
    }
}

/** Ressourcen für den Test von ttlMs/cacheScope */
final class McpProtocolTestResources extends Resources
{
    public function read(string $uri, McpContext $ctx): ?array
    {
        $contents = ['contents' => [['uri' => $uri, 'mimeType' => 'text/plain', 'text' => 'x']]];
        return match ($uri) {
            'test://live'   => $contents,
            'test://static' => $contents + ['ttlMs' => 3600000, 'cacheScope' => 'public'],
            'test://bad'    => $contents + ['ttlMs' => -1],
            default         => null,
        };
    }
}
