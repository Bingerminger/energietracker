<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.12.0 — Härtung: „zuletzt benutzt“ eines API-Schlüssels zählt nur, wenn die
 * App die Anfrage mit ihm angenommen hat.
 *
 * Bis v3.11 setzte schon das Nachschlagen des Schlüssels `last_used_at` — auch
 * wenn die Anfrage danach abgewiesen wurde (falscher Bereich, falsche Route,
 * Lese-Schlüssel beim Schreiben). Die Liste in den Einstellungen sagte dann
 * „gerade benutzt“ für einen Schlüssel, der nichts durfte, und verdeckte, wer
 * ihn wirklich noch braucht.
 */
final class ApiKeyLastUsedTest extends HttpServerTestCase
{
    // erfundene Schlüssel im Format der App (etk_ + 48 Zeichen)
    private const KEYS = [
        'read'      => 'etk_lese0000000000000000000000000000000000000000001',
        'calendar'  => 'etk_kalender000000000000000000000000000000000000002',
        'inbox'     => 'etk_ablage0000000000000000000000000000000000000003',
        'mcp'       => 'etk_kilesen000000000000000000000000000000000000004',
    ];

    protected function setUp(): void
    {
        $this->startServer(['language' => 'de', 'mcp_enabled' => true], ['ET_AUTH' => 'password']);
        $keys = [];
        foreach (self::KEYS as $scope => $key) {
            $keys[] = ['id' => "k_$scope", 'name' => $scope, 'scope' => $scope, 'hash' => hash('sha256', $key),
                       'created_at' => '2026-01-05T10:00:00+01:00', 'last_used_at' => null];
        }
        file_put_contents($this->dataDir . '/auth.json', json_encode(['mode' => 'password', 'api_keys' => $keys]));
    }

    /** @return array<string, ?string> Bereich → last_used_at */
    private function lastUsed(): array
    {
        clearstatcache();
        $out = [];
        foreach ((array)(json_decode((string)file_get_contents($this->dataDir . '/auth.json'), true)['api_keys'] ?? []) as $k) {
            $out[(string)$k['scope']] = $k['last_used_at'] ?? null;
        }
        return $out;
    }

    private static function bearer(string $scope): array
    {
        return ['Authorization: Bearer ' . self::KEYS[$scope]];
    }

    public function testRejectedRequestsLeaveLastUsedUntouched(): void
    {
        $rejected = [
            'Kalender-Schlüssel als Kopfzeile' => ['GET', '/api/summary', self::bearer('calendar'), null, 401],
            'Lese-Schlüssel im Kalender-Link'  => ['GET', '/api/calendar.ics?token=' . self::KEYS['read'], [], null, 401],
            'Lese-Schlüssel schreibt'          => ['PATCH', '/api/settings', array_merge(self::bearer('read'), ['Content-Type: application/json']), '{"dashboard_months":6}', 403],
            'Ablage-Schlüssel liest'           => ['GET', '/api/summary', self::bearer('inbox'), null, 401],
            'KI-Schlüssel außerhalb von /api/mcp' => ['GET', '/api/summary', self::bearer('mcp'), null, 401],
            'Lese-Schlüssel an /api/mcp'       => ['POST', '/api/mcp', array_merge(self::bearer('read'), ['Content-Type: application/json', 'Accept: application/json, text/event-stream']),
                                                   '{"jsonrpc":"2.0","id":1,"method":"ping"}', 403],
        ];
        foreach ($rejected as $what => [$method, $path, $headers, $body, $status]) {
            self::assertSame($status, $this->request($method, $path, $headers, $body)['status'], $what);
        }
        self::assertSame(['read' => null, 'calendar' => null, 'inbox' => null, 'mcp' => null], $this->lastUsed(),
            'abgewiesene Anfragen zählen nicht als Benutzung');
    }

    public function testAcceptedRequestsSetLastUsed(): void
    {
        self::assertSame(200, $this->request('GET', '/api/summary', self::bearer('read'))['status']);
        self::assertSame(200, $this->request('GET', '/api/calendar.ics?token=' . self::KEYS['calendar'])['status']);
        $mcp = $this->request('POST', '/api/mcp', array_merge(self::bearer('mcp'), ['Content-Type: application/json', 'Accept: application/json, text/event-stream']),
            '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertSame(200, $mcp['status'], $mcp['body']);
        $used = $this->lastUsed();
        foreach (['read', 'calendar', 'mcp'] as $scope) {
            self::assertNotNull($used[$scope], "$scope: angenommen, also benutzt");
            self::assertNotFalse(strtotime((string)$used[$scope]), $scope);
        }
        self::assertNull($used['inbox'], 'nicht benutzt');
    }
}
