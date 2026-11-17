<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 (Paket F) — Rücknahme einer Übernahme der KI im Verlauf, über HTTP mit der Verdrahtung aus
 * src/bootstrap.php: `POST /api/activity/{id}/undo` stellt die Ablesung zurück (bis v3.12 `409`), der Eintrag
 * steht durchgestrichen da, das Zugriffsprotokoll der KI zeigt die Rücknahme mit dem Schlüsselnamen, und ein
 * zweites `apply_proposal` meldet sie. Hat sich der Datensatz seitdem geändert, geht nichts zurück (`409`).
 */
#[CoversNothing]
final class McpUndoEndpointTest extends HttpServerTestCase
{
    private string $key = '';

    /** @return array<string,mixed> `result` der Antwort */
    private function tool(string $name, array $args): array
    {
        $msg = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object)$args]];
        $r = $this->request('POST', '/api/mcp', ['Content-Type: application/json', 'Accept: application/json, text/event-stream',
            "Authorization: Bearer {$this->key}"], (string)json_encode($msg));
        self::assertSame(200, $r['status'], $r['body']);
        return (array)(json_decode($r['body'], true)['result'] ?? []);
    }

    /** @return array{status:int, code:?string, data:mixed} */
    private function post(string $path): array
    {
        $r = $this->request('POST', $path, ['Content-Type: application/json', "Origin: {$this->base}", 'X-ET-Language: de'], '{}');
        $j = json_decode($r['body'], true);
        return ['status' => $r['status'], 'code' => $j['code'] ?? null, 'data' => $j['data'] ?? null];
    }

    /** Vorschlag der KI für den Gaszähler, übernommen; @return array{0:string, 1:string} Vorschlag, Verlaufseintrag */
    private function applyReading(string $date, float $counter): array
    {
        $p = $this->tool('propose_reading', ['utility' => 'gas', 'date' => $date, 'counter' => $counter])['structuredContent'];
        $a = $this->tool('apply_proposal', ['proposal_id' => $p['proposal_id'], 'summary' => $p['summary']])['structuredContent'];
        return [(string)$p['proposal_id'], (string)$a['activity_ids'][0]];
    }

    private function gasDates(): array
    {
        return array_column((array)$this->json('GET', '/api/utility/gas/readings'), 'date');
    }

    public function testAnAiReadingIsUndoneAndTheJournalShowsIt(): void
    {
        $this->startServer(['language' => 'de', 'mcp_enabled' => true, 'mcp_apply_in_app' => false]);
        $this->key = (string)$this->json('POST', '/api/auth/keys', ['name' => 'KI Laptop', 'scope' => 'mcp_write'])['key'];
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        $this->json('POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => '2025-01-01', 'counter' => 100], ["Origin: {$this->base}"]);

        [$proposal, $act] = $this->applyReading('2025-02-01', 150.0);
        self::assertContains('2025-02-01', $this->gasDates());
        $u = $this->post("/api/activity/$act/undo");
        self::assertSame(200, $u['status'], (string)$u['code']);
        self::assertTrue($u['data']['reverted']);
        self::assertNotEmpty($u['data']['undone']);
        self::assertNotContains('2025-02-01', $this->gasDates(), 'die Ablesung der KI ist wieder weg');

        $journal = (array)$this->json('GET', '/api/mcp/journal');
        $entries = $journal['entries'] ?? $journal;
        self::assertSame(['apply_proposal', 'undone', 'KI Laptop'], [$entries[0]['name'], $entries[0]['result'], $entries[0]['key_name']],
            'die Rücknahme steht im Zugriffsprotokoll');
        // ein zweites apply_proposal schreibt nicht wieder und liefert nicht das alte Ergebnis: die KI erfährt es
        $again = $this->tool('apply_proposal', ['proposal_id' => $proposal, 'summary' => $this->json('GET', '/api/activity')[0]['changes'] ?? '']);
        self::assertSame([true, 'errors.mcp.proposal.undone'], [$again['isError'] ?? false, $again['structuredContent']['error']['code'] ?? null]);
        self::assertNotContains('2025-02-01', $this->gasDates());
    }

    public function testOnlyTheLatestStateIsUndone(): void
    {
        $this->startServer(['language' => 'de', 'mcp_enabled' => true, 'mcp_apply_in_app' => false]);
        $this->key = (string)$this->json('POST', '/api/auth/keys', ['name' => 'KI', 'scope' => 'mcp_write'])['key'];
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        $this->json('POST', '/api/utility/gas/readings', ['meter_id' => $gas, 'date' => '2025-01-01', 'counter' => 100], ["Origin: {$this->base}"]);
        [, $act] = $this->applyReading('2025-02-01', 150.0);
        $reading = array_values(array_filter((array)$this->json('GET', '/api/utility/gas/readings'), fn($r) => $r['date'] === '2025-02-01'))[0];
        $this->request('PATCH', "/api/utility/gas/readings/{$reading['id']}", ['Content-Type: application/json', "Origin: {$this->base}"],
            (string)json_encode(['note' => 'von Hand nachgesehen']));

        $u = $this->post("/api/activity/$act/undo");
        self::assertSame([409, 'errors.activity.undoChanged'], [$u['status'], $u['code']]);
        self::assertContains('2025-02-01', $this->gasDates(), 'nichts zurückgestellt');
        $e = array_values(array_filter((array)$this->json('GET', '/api/activity'), fn($x) => $x['id'] === $act))[0];
        self::assertArrayNotHasKey('undone', $e, 'nicht durchgestrichen');
    }
}
