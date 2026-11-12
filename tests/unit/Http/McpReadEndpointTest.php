<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.12.0 (F1037, Paket lesen) — „gleiche Zahl wie die Oberfläche“ über HTTP:
 * die Beispieldaten im echten Server (`php -S`, router.php, Verdrahtung aus
 * src/bootstrap.php), dieselbe Frage einmal an die Endpunkte der Oberfläche und
 * einmal an `POST /api/mcp` mit einem Schlüssel `mcp`. Beide rechnen am selben
 * Tag (Uhr des Servers).
 */
final class McpReadEndpointTest extends HttpServerTestCase
{
    private string $key = '';

    private function tool(string $name, array $args = []): array
    {
        $msg = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object)$args]];
        $r = $this->request('POST', '/api/mcp', ['Content-Type: application/json', 'Accept: application/json, text/event-stream',
            "Authorization: Bearer {$this->key}"], (string)json_encode($msg));
        $j = json_decode($r['body'], true);
        // v3.13.0 — mit dem Serverprotokoll: Bricht PHP 8.3 eine lange C-Funktion ab, steht dort Datei und Zeile
        self::assertSame(200, $r['status'], $r['body'] . "\n" . $this->serverLogTail());
        self::assertFalse($j['result']['isError'] ?? true, $r['body']);
        return $j['result']['structuredContent'];
    }

    private static function v(?array $num): ?float
    {
        return $num === null ? null : (float)$num['value'];
    }

    /** JSON der Oberfläche schreibt 10.0 als 10 (Lektion 90 c): als Kommazahl vergleichen */
    private static function f(mixed $x): ?float
    {
        return $x === null ? null : (float)$x;
    }

    public function testTheAiSeesTheSameFiguresAsTheSurface(): void
    {
        $this->startServer();
        $imp = $this->request('POST', '/api/demo/import', ['Content-Type: application/json'], (string)json_encode(['force' => true]));
        self::assertSame(200, $imp['status'], $imp['body']);
        $this->json('PATCH', '/api/settings', ['mcp_enabled' => true]);
        $this->key = (string)$this->json('POST', '/api/auth/keys', ['name' => 'KI lesen', 'scope' => 'mcp'])['key'];

        // Übersicht ⇔ GET /api/summary
        $summary = $this->json('GET', '/api/summary');
        $ov = $this->tool('get_overview');
        self::assertSame(array_column($summary['meters'], 'meter_id'), array_column($ov['meters'], 'id'));
        foreach ($summary['meters'] as $i => $s) {
            $m = $ov['meters'][$i];
            self::assertSame([self::f($s['last_counter']), self::f($s['year_to_date']['value'] ?? null),
                self::f($s['contract']['balance'] ?? null), self::f($s['contract']['projected_end_balance'] ?? null)],
                [self::v($m['last_reading']['counter'] ?? null), self::v($m['year_to_date'] ?? null),
                 self::v($m['contract']['status']['balance_today'] ?? null), self::v($m['contract']['status']['expected_balance'] ?? null)], $s['key']);
        }

        // Verträge ⇔ GET …/contract-status, Verbrauch ⇔ GET …/consumption
        $gas = $summary['meters'][array_search('gas', array_column($summary['meters'], 'utility'), true)]['meter_id'];
        $status = $this->json('GET', "/api/utility/gas/meters/$gas/contract-status");
        $contracts = $this->tool('get_contracts', ['utility' => 'gas', 'meter' => $gas, 'include_past' => true])['targets'][0]['contracts'];
        self::assertSame(array_map(fn($c) => [$c['contract_id'], $c['phase'], (float)$c['current_balance']], $status['contracts']),
            array_map(fn($c) => [$c['contract_id'], $c['phase'], self::v($c['status']['balance_today'] ?? null)], $contracts));
        $monthly = array_slice($this->json('GET', "/api/utility/gas/meters/$gas/consumption")['monthly'], -60);
        $rows = $this->tool('get_consumption', ['utility' => 'gas', 'meter' => $gas])['rows'];
        self::assertSame(array_map(fn($r) => [$r['ym'], round((float)$r['kwh'], 3), round((float)$r['cost'], 2), $r['interpolated'] === true], $monthly),
            array_map(fn($r) => [$r['period'], $r['value'], $r['cost'], $r['basis'] !== 'measured'], $rows));

        // Rechnung prüfen ⇔ GET …/bills/{id}/check
        $bills = $this->json('GET', '/api/utility/strom/bills');
        self::assertNotEmpty($bills, 'Beispieldaten mit Stromrechnung');
        $check = $this->json('GET', "/api/utility/strom/bills/{$bills[0]['id']}/check");
        $mcp = $this->tool('check_bill', ['utility' => 'strom', 'bill_id' => (string)$bills[0]['id']]);
        self::assertSame([$check['verdict'], self::f($check['ours']['kwh']), self::f($check['ours']['total']), self::f($check['delta']['eur'])],
            [$mcp['verdict'] ?? null, self::v($mcp['ours']['quantity'] ?? null), self::v($mcp['ours']['total'] ?? null), self::v($mcp['delta']['amount'] ?? null)]);
    }
}
