<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\Server;

/**
 * v3.13.0 — JSON-RPC-Stapel in der Fassung 2025-03-26. Die Spezifikation dieser Fassung verlangt, dass ein Server
 * Stapel annimmt („MCP implementations MAY support sending JSON-RPC batches, but MUST support receiving JSON-RPC
 * batches“, Basic › Batching); 2025-06-18 hat sie entfernt (Changelog, Major changes 1). Bis dahin wies der Server
 * jeden Stapel ab, obwohl er 2025-03-26 anbietet. `initialize` gehört nie in einen Stapel (Lifecycle).
 */
final class McpBatchTest extends McpTestCase
{
    /** @param array<string,string> $headers */
    private function batch(string $raw, array $headers = ['mcp-protocol-version' => '2025-03-26'], string $scope = 'mcp'): array
    {
        $r = $this->server()->handle($raw, $this->context($scope), $headers);
        if ($r['body'] !== null) $r['body'] = json_decode((string)json_encode($r['body']), true);
        return $r;
    }

    public function testABatchIsAnsweredInOrderWithOneResponsePerRequest(): void
    {
        $r = $this->batch('[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","method":"notifications/initialized"},'
            . '{"jsonrpc":"2.0","id":"zwei","method":"tools/list"}]');
        self::assertSame(200, $r['status']);
        self::assertTrue(array_is_list($r['body']), 'Antwort ist eine Liste');
        self::assertSame([1, 'zwei'], array_column($r['body'], 'id'), 'Benachrichtigung ohne Antwort, Reihenfolge bleibt');
        self::assertSame([], $r['body'][0]['result']);
        self::assertNotEmpty($r['body'][1]['result']['tools']);
        // je Nachricht ein Eintrag fürs Zugriffsprotokoll
        self::assertSame(['ping', 'notifications/initialized', 'tools/list'], array_map(fn($l) => $l['log']['method'], $r['logs']));
        self::assertSame(['2025-03-26'], array_values(array_unique(array_map(fn($l) => $l['log']['version'], $r['logs']))));
        // ohne Kopfzeile ist es 2025-03-26: ebenso angenommen
        self::assertSame(200, $this->batch('[{"jsonrpc":"2.0","id":1,"method":"ping"}]', [])['status']);
    }

    public function testOnlyNotificationsGive202WithoutBody(): void
    {
        $r = $this->batch('[{"jsonrpc":"2.0","method":"notifications/initialized"},{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":1}}]');
        self::assertSame([202, null], [$r['status'], $r['body']]);
    }

    public function testErrorsStayWithTheirMessage(): void
    {
        $r = $this->batch('[{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{}}},'
            . '{"jsonrpc":"2.0","id":2,"method":"gibt/es/nicht"},"ping",[{"jsonrpc":"2.0","id":3,"method":"ping"}],'
            . '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"propose_reading","arguments":{}}},'
            . '{"jsonrpc":"2.0","id":5,"method":"ping","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2025-06-18"}}},'
            . '{"jsonrpc":"2.0","id":6,"method":"ping"}]');
        self::assertSame(200, $r['status']);
        $codes = array_map(fn($b) => [$b['id'] ?? null, $b['error']['data']['code'] ?? 'ok'], $r['body']);
        self::assertSame([[1, 'errors.mcp.batchInitialize'], [2, 'errors.mcp.methodNotFound'], [null, 'errors.mcp.invalidRequest'],
            [null, 'errors.mcp.invalidRequest'], [4, 'errors.mcp.insufficientScope'], [5, 'errors.mcp.batch'], [6, 'ok']], $codes);
    }

    public function testLaterVersionsAndBadBatchesAreRejectedAsAWhole(): void
    {
        foreach (['2025-06-18', '2025-11-25', '2026-07-28'] as $v) {
            $r = $this->batch('[{"jsonrpc":"2.0","id":1,"method":"ping"}]', ['mcp-protocol-version' => $v]);
            self::assertSame([400, Server::INVALID_REQUEST, 'errors.mcp.batch'], [$r['status'], $r['body']['error']['code'], $r['body']['error']['data']['code']], $v);
        }
        $empty = $this->batch('[]');
        self::assertSame([400, 'errors.mcp.invalidRequest', null], [$empty['status'], $empty['body']['error']['data']['code'], $empty['body']['id']]);
        self::assertSame([400, Server::PARSE_ERROR], [$this->batch('[{"jsonrpc":')['status'], $this->batch('[{"jsonrpc":')['body']['error']['code']]);
        $many = '[' . implode(',', array_fill(0, Server::BATCH_MAX + 1, '{"jsonrpc":"2.0","id":1,"method":"ping"}')) . ']';
        $r = $this->batch($many);
        self::assertSame([400, 'errors.mcp.batchTooLarge'], [$r['status'], $r['body']['error']['data']['code']]);
        self::assertSame([Server::BATCH_MAX + 1, 0], [Server::batchSize($many), Server::batchSize('{"jsonrpc":"2.0"}')]);
    }
}
