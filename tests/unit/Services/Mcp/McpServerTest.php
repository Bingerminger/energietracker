<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\Prompts;
use Energietracker\Services\Mcp\Resources;
use Energietracker\Services\Mcp\Server;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolGroup;
use Energietracker\Services\Mcp\ToolRegistry;
use Energietracker\Services\Mcp\Tools\MeterTools;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037, Basis) — Mcp\Server ohne HTTP: beide Zeitalter (2026-07-28
 * mit `_meta` und `server/discover`; 2025-11-25/2025-06-18 mit `initialize`),
 * JSON-RPC-Fehler mit Status, Werkzeuge nach Bereich, Fehler der Werkzeuge als
 * `isError`, Serverfehler ohne Einzelheiten.
 */
final class McpServerTest extends McpTestCase
{
    /** Server mit Testgruppe (ein schreibendes Werkzeug, Fehlerfälle) */
    private function testServer(): Server
    {
        $services = $this->mcpServices();
        return new Server(new ToolRegistry($services, [MeterTools::class, McpServerTestTools::class]),
            new Prompts($services, $this->i18n), new Resources($services, $this->i18n), $this->i18n, '9.9.9',
            function (\Throwable $e): void { $this->serverError = $e; });
    }

    /** @param array<string,mixed> $msg */
    private function raw(array|string $msg, ?McpContext $ctx = null, array $headers = [], ?Server $server = null): array
    {
        $raw = is_string($msg) ? $msg : (string)json_encode($msg);
        return ($server ?? $this->testServer())->handle($raw, $ctx ?? $this->context(), $headers);
    }

    public function testLegacyInitializeChoosesTheClientVersionOrTheNewestLegacyOne(): void
    {
        $r = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => "Test\u{202E} Client", 'version' => '2.1']]);
        self::assertSame(200, $r['status']);
        $res = $r['body']['result'];
        self::assertSame('2025-06-18', $res['protocolVersion']);
        self::assertSame(['tools' => ['listChanged' => false], 'resources' => [], 'prompts' => []], $res['capabilities']);
        self::assertSame(['name' => 'energietracker', 'title' => 'Energietracker', 'version' => '9.9.9'], $res['serverInfo']);
        self::assertStringStartsWith('Energietracker ist die Akte des Haushalts', $res['instructions']);
        self::assertArrayNotHasKey('resultType', $res, 'ältere Fassungen ohne resultType');
        self::assertSame(['name' => 'Test Client', 'version' => '2.1'], $r['log']['client'], 'Client bereinigt, nur zur Anzeige');
        self::assertSame('2025-06-18', $r['log']['version']);

        $older = $this->rpc('initialize', ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass()]);
        self::assertSame(Server::LEGACY[0], $older['body']['result']['protocolVersion'], 'unbekannt → neueste ältere Fassung');
        self::assertSame('2025-11-25', Server::LEGACY[0]);

        // Objekte bleiben Objekte (nicht [])
        $json = json_encode($this->raw(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']])['body']);
        self::assertStringContainsString('"resources":{},"prompts":{}', (string)$json);
    }

    public function testNotificationsGet202WithoutBodyAndRequestsWithoutIdAreRejected(): void
    {
        $r = $this->rpc('notifications/initialized', [], null, false, null);
        self::assertSame([202, null, 'ok'], [$r['status'], $r['body'], $r['log']['result']]);
        $bad = $this->rpc('tools/list', [], null, false, null);
        self::assertSame([400, Server::INVALID_REQUEST], [$bad['status'], $bad['body']['error']['code']]);
    }

    public function testPingAnswersWithAnEmptyObject(): void
    {
        $r = $this->raw(['jsonrpc' => '2.0', 'id' => 'p1', 'method' => 'ping']);
        self::assertSame('{"jsonrpc":"2.0","id":"p1","result":{}}', json_encode($r['body']));
        // v3.12.0 (Paket protokoll) — 2026-07-28 hat ping gestrichen: dort eine unbekannte Methode
        $modern = $this->rpc('ping', [], null, true);
        self::assertSame([404, Server::METHOD_NOT_FOUND], [$modern['status'], $modern['body']['error']['code']]);
    }

    public function testModernDiscoverAndResultsCarryResultTypeAndServerInfo(): void
    {
        $r = $this->rpc('server/discover', [], null, true, 'discover-1');
        self::assertSame(200, $r['status']);
        $res = $r['body']['result'];
        self::assertSame('complete', $res['resultType']);
        self::assertSame(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], $res['supportedVersions']);
        self::assertSame(Server::versions(), $res['supportedVersions']);
        self::assertSame(['tools' => ['listChanged' => false], 'resources' => [], 'prompts' => []], $res['capabilities']);
        self::assertSame('energietracker', $res['_meta'][Server::META_SERVER]['name']);
        self::assertNotSame('', $res['instructions']);
        self::assertSame(['name' => 'test-client', 'version' => '1.0'], $r['log']['client']);

        $list = $this->rpc('tools/list', [], null, true);
        self::assertSame('complete', $list['body']['result']['resultType']);
        self::assertSame('9.9.9', $list['body']['result']['_meta'][Server::META_SERVER]['version']);
        self::assertArrayNotHasKey('resultType', $this->rpc('tools/list')['body']['result']);
    }

    public function testVersionsHeadersAndModernMetaAreChecked(): void
    {
        $r = $this->rpc('tools/list', ['_meta' => [Server::META_VERSION => '1900-01-01']], null, false, 3);
        self::assertSame(400, $r['status']);
        self::assertSame(Server::UNSUPPORTED_VERSION, $r['body']['error']['code']);
        self::assertSame(['code' => 'errors.mcp.unsupportedVersion', 'supported' => Server::versions(), 'requested' => '1900-01-01'], $r['body']['error']['data']);
        self::assertSame(3, $r['body']['id']);

        $header = $this->rpc('tools/list', [], null, false, 4, ['mcp-protocol-version' => '2099-01-01']);
        self::assertSame([400, Server::UNSUPPORTED_VERSION], [$header['status'], $header['body']['error']['code']]);

        $mismatch = $this->rpc('tools/list', [], null, true, 5, ['mcp-protocol-version' => '2025-06-18']);
        self::assertSame([400, Server::HEADER_MISMATCH, 'errors.mcp.headerMismatch'],
            [$mismatch['status'], $mismatch['body']['error']['code'], $mismatch['body']['error']['data']['code']]);

        $noCaps = $this->raw(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/list', 'params' => ['_meta' => [Server::META_VERSION => '2026-07-28']]]);
        self::assertSame([400, Server::INVALID_PARAMS, 'errors.mcp.metaMissing'],
            [$noCaps['status'], $noCaps['body']['error']['code'], $noCaps['body']['error']['data']['code']]);

        // ältere Fassung mit Kopfzeile, ohne _meta: angenommen
        $legacy = $this->rpc('tools/list', [], null, false, 8, ['mcp-protocol-version' => '2025-06-18']);
        self::assertSame([200, '2025-06-18'], [$legacy['status'], $legacy['log']['version']]);
        self::assertSame(Server::DEFAULT_LEGACY, $this->rpc('tools/list')['log']['version'], 'ohne Kopfzeile: 2025-03-26');
    }

    public function testUnknownMethodIs404ModernAnd200Legacy(): void
    {
        $modern = $this->rpc('subscriptions/listen', [], null, true);
        self::assertSame([404, Server::METHOD_NOT_FOUND], [$modern['status'], $modern['body']['error']['code']]);
        self::assertSame('Unbekannte Methode: subscriptions/listen.', $modern['body']['error']['message']);
        $legacy = $this->rpc('logging/setLevel', ['level' => 'info']);
        self::assertSame([200, Server::METHOD_NOT_FOUND], [$legacy['status'], $legacy['body']['error']['code']]);
    }

    public function testMalformedMessages(): void
    {
        $batch = $this->raw('[{"jsonrpc":"2.0","id":1,"method":"ping"}]');
        self::assertSame([400, Server::INVALID_REQUEST, 'errors.mcp.batch', null],
            [$batch['status'], $batch['body']['error']['code'], $batch['body']['error']['data']['code'], $batch['body']['id']]);
        self::assertSame([400, Server::PARSE_ERROR], [$this->raw('kein json')['status'], $this->raw('kein json')['body']['error']['code']]);
        foreach ([
            ['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => ''],
            '"ping"',
        ] as $i => $m) {
            $r = $this->raw($m);
            self::assertSame([400, Server::INVALID_REQUEST], [$r['status'], $r['body']['error']['code']], "Fall $i");
        }
        $listParams = $this->raw(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [1, 2]]);
        self::assertSame([400, Server::INVALID_PARAMS], [$listParams['status'], $listParams['body']['error']['code']]);
    }

    public function testToolsAreListedAndCheckedByScope(): void
    {
        $read = $this->raw(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        self::assertSame(['list_meters', 'echo', 'boom', 'empty'], array_column($read['body']['result']['tools'], 'name'),
            'Lese-Schlüssel sieht nur lesende, feste Reihenfolge');
        $write = $this->raw(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $this->context('mcp_write'));
        self::assertSame(['list_meters', 'echo', 'boom', 'empty', 'write_thing'], array_column($write['body']['result']['tools'], 'name'));

        $denied = $this->raw(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'write_thing', 'arguments' => new \stdClass()]]);
        self::assertSame(403, $denied['status']);
        self::assertSame(Server::ERR_SCOPE, $denied['body']['error']['code']);
        self::assertSame(['code' => 'errors.mcp.insufficientScope', 'scope' => 'mcp_write'], $denied['body']['error']['data']);
        self::assertSame('Bearer realm="energietracker", error="insufficient_scope", scope="mcp_write"', $denied['headers']['WWW-Authenticate']);
        self::assertSame('denied', $denied['log']['result']);

        $ok = $this->raw(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'write_thing']], $this->context('mcp_write'));
        self::assertSame([200, false, ['written' => 'mcp_write']], [$ok['status'], $ok['body']['result']['isError'], $ok['body']['result']['structuredContent']]);

        $unknown = $this->raw(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'gibt_es_nicht']]);
        self::assertSame([200, Server::INVALID_PARAMS, 'errors.mcp.unknownTool'],
            [$unknown['status'], $unknown['body']['error']['code'], $unknown['body']['error']['data']['code']]);
        $bad = $this->raw(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => [1]]]);
        self::assertSame(Server::INVALID_PARAMS, $bad['body']['error']['code'], 'Argumente als Liste');
    }

    public function testDefinitionsComeFromTheCatalogAndExportSchemasAsObjects(): void
    {
        $raw = $this->raw(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['body']['result']['tools'];
        self::assertSame('{"type":"object","properties":{},"additionalProperties":false}', json_encode($raw[3]['inputSchema']), 'leere properties als Objekt');
        $tools = json_decode((string)json_encode($raw), true);
        $meters = $tools[0];
        self::assertSame('Zähler und Gruppen', $meters['title']);
        self::assertStringStartsWith('Zähler und Zählergruppen auflisten', $meters['description']);
        self::assertSame('Auch stillgelegte Zähler zeigen', $meters['inputSchema']['properties']['include_inactive']['description']);
        self::assertSame(['readOnlyHint' => true, 'openWorldHint' => false, 'idempotentHint' => true], $meters['annotations']);
        self::assertArrayNotHasKey('_meta', $meters);
        $write = json_decode((string)json_encode($this->raw(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $this->context('mcp_write'))['body']), true);
        $w = $write['result']['tools'][4];
        self::assertSame(['readOnlyHint' => false, 'openWorldHint' => false, 'destructiveHint' => false], $w['annotations'], 'schreibend: readOnlyHint false');
        self::assertSame(['org.example/confirm' => true], $w['_meta']);

        $en = $this->raw(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $this->context('mcp', '2027-01-15', 'en'));
        self::assertSame('Meters and groups', $en['body']['result']['tools'][0]['title']);
        $init = $this->raw(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], $this->context('mcp', '2027-01-15', 'en'));
        self::assertStringStartsWith('Energietracker is the household’s record', $init['body']['result']['instructions']);
    }

    public function testToolErrorsBecomeIsErrorAndServerErrorsHideDetails(): void
    {
        $call = fn(string $name, array $args = []) => $this->raw(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object)$args]]);

        $invalid = $call('echo', ['text' => 5, 'extra' => true]);
        self::assertSame(200, $invalid['status']);
        $res = $invalid['body']['result'];
        self::assertTrue($res['isError']);
        self::assertSame('errors.mcp.invalidArguments', $res['structuredContent']['error']['code']);
        self::assertSame([['path' => '/text', 'rule' => 'type', 'expected' => 'string'], ['path' => '/extra', 'rule' => 'additionalProperties']],
            $res['structuredContent']['error']['errors']);
        self::assertStringContainsString('/text type (string); /extra additionalProperties', $res['content'][0]['text']);
        self::assertSame('tool_error', $invalid['log']['result']);

        $loc = $call('boom', ['kind' => 'localized']);
        self::assertSame(['errors.meter.notFound', 'Zähler nicht gefunden'], [$loc['body']['result']['structuredContent']['error']['code'],
            $loc['body']['result']['content'][0]['text']]);
        $iae = $call('boom', ['kind' => 'invalid']);
        self::assertSame('errors.meter.nameEmpty', $iae['body']['result']['structuredContent']['error']['code'], 'Code aus dem übersetzten Text');

        $crash = $call('boom', ['kind' => 'crash']);
        self::assertSame([500, Server::INTERNAL_ERROR], [$crash['status'], $crash['body']['error']['code']]);
        self::assertStringNotContainsString('geheim', (string)json_encode($crash['body']));
        self::assertStringNotContainsString('/var/', (string)json_encode($crash['body']));
        self::assertMatchesRegularExpression('/^err_[0-9a-f]{8}$/', $crash['body']['error']['data']['error_id']);
        self::assertSame('geheim /var/data/auth.json', $this->serverError?->getMessage(), 'Einzelheiten nur ins Server-Log');
        $this->serverError = null;

        self::assertSame('{}', json_encode($call('empty')['body']['result']['structuredContent']), 'leeres Ergebnis als Objekt');
    }

    /** v3.12.0 (doku-vorlagen) — Inhalte in McpPromptsTest/McpResourcesTest; hier nur die Fehlerform. */
    public function testUnknownPromptAndResourceAreInvalidParams(): void
    {
        $p = $this->rpc('prompts/get', ['name' => 'weekly_report']);
        self::assertSame([Server::INVALID_PARAMS, 'errors.mcp.unknownPrompt'], [$p['body']['error']['code'], $p['body']['error']['data']['code']]);
        $res = $this->rpc('resources/read', ['uri' => 'energietracker://hilfe/gibt-es-nicht.md']);
        self::assertSame([Server::INVALID_PARAMS, 'errors.mcp.unknownResource'], [$res['body']['error']['code'], $res['body']['error']['data']['code']]);
    }

    public function testLanguageOfTheServerIsGermanOrEnglish(): void
    {
        self::assertSame('de', Server::localeFor($this->i18n));
        $this->settings->set(['language' => 'fr']);
        self::assertSame('en', Server::localeFor($this->i18n), 'andere Sprachen: Englisch');
        $this->settings->set(['language' => 'en']);
        self::assertSame('en', Server::localeFor($this->i18n));
    }

    /** Doku-Zusage (Lektion 99): Fassungen und Fehlercodes der API-Referenz = Konstanten des Servers. */
    public function testApiReferenceNamesTheVersionsAndCodesOfTheServer(): void
    {
        $codes = array_filter((new \ReflectionClass(Server::class))->getConstants(), fn($v, $k) => is_int($v)
            && (str_starts_with($k, 'ERR_') || in_array($k, ['PARSE_ERROR', 'INVALID_REQUEST', 'METHOD_NOT_FOUND', 'INVALID_PARAMS',
                'INTERNAL_ERROR', 'HEADER_MISMATCH', 'UNSUPPORTED_VERSION'], true)), ARRAY_FILTER_USE_BOTH);
        foreach (['docs/referenz/api.md' => '### KI-Schnittstelle (MCP)', 'docs/en/referenz/api.md' => '### AI interface (MCP)'] as $rel => $head) {
            $doc = (string)file_get_contents(dirname(__DIR__, 4) . '/' . $rel);
            $at = strpos($doc, $head);
            self::assertNotFalse($at, "$rel: Abschnitt fehlt");
            $section = substr($doc, $at, (int)strpos($doc, "\n### ", $at + 5) - $at);
            foreach (Server::versions() as $v) self::assertStringContainsString("`$v`", $section, "$rel: Fassung $v");
            preg_match_all('/^\| `(-3\d{4})` \|/m', $section, $m);
            $documented = array_map('intval', $m[1]);
            sort($documented);
            $expected = array_values($codes);
            sort($expected);
            self::assertSame($expected, $documented, "$rel: Fehlercodes");
            self::assertStringContainsString((\Energietracker\App::BODY_PUBLIC / 1048576) . ' MB', $section, "$rel: Körpergrenze");
        }
    }

    public function testRegistryRejectsDuplicatesAndBadNames(): void
    {
        try {
            new ToolRegistry($this->mcpServices(), [MeterTools::class, MeterTools::class]);
            self::fail('doppelter Name');
        } catch (\LogicException $e) {
            self::assertStringContainsString("'list_meters' registered twice", $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        new Tool('kein name', Tool::READ, ['type' => 'object'], fn() => []);
    }
}

/** Testgruppe: ein schreibendes Werkzeug und Fehlerfälle. */
final class McpServerTestTools extends ToolGroup
{
    public function tools(): array
    {
        $none = ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
        return [
            new Tool('echo', Tool::READ, ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'additionalProperties' => false],
                fn(array $a): array => ['text' => $a['text'] ?? '']),
            new Tool('boom', Tool::READ, ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['localized', 'invalid', 'crash']]],
                'required' => ['kind'], 'additionalProperties' => false], function (array $a): array {
                    $i18n = $this->services->get(\Energietracker\Services\I18nService::class);
                    match ($a['kind']) {
                        'localized' => throw new LocalizedException('errors.meter.notFound', [], 'meter'),
                        'invalid'   => throw new \InvalidArgumentException($i18n->t('errors.meter.nameEmpty')),
                        default     => throw new \RuntimeException('geheim /var/data/auth.json'),
                    };
                }),
            new Tool('empty', Tool::READ, $none, fn(): array => []),
            new Tool('write_thing', Tool::WRITE, $none, fn(array $a, McpContext $c): array => ['written' => $c->scope],
                annotations: ['destructiveHint' => false], meta: ['org.example/confirm' => true]),
        ];
    }
}
