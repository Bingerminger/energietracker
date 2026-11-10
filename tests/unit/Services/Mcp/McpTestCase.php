<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\I18nService;
use Energietracker\Services\Mcp\Config;
use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\Num;
use Energietracker\Services\Mcp\PendingChanges;
use Energietracker\Services\Mcp\Prompts;
use Energietracker\Services\Mcp\Provenance;
use Energietracker\Services\Mcp\Resources;
use Energietracker\Services\Mcp\Schema;
use Energietracker\Services\Mcp\Server;
use Energietracker\Services\Mcp\Services;
use Energietracker\Services\Mcp\ToolRegistry;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\SettingsService;
use Energietracker\Storage\JsonStore;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.12.0 (F1037) — Grundlage der MCP-Tests: ruft Mcp\Server direkt (ohne
 * HTTP) auf den Daten von ServiceTestCase auf.
 *
 * Für die Fachpakete:
 *  - `callTool('name', [...])` liefert `structuredContent` und prüft dabei die
 *    Form: kein Fehler, Text = dieselbe JSON-Fassung, Ausgabe passt zum
 *    `outputSchema`. `callToolError()` liefert `structuredContent.error`.
 *  - `rpc('method', $params, modern: true)` liefert die rohe Antwort
 *    `{status, body, headers, log}`, mit `_meta` und Kopfzeile der Fassung 2026-07-28.
 *  - Dienste über `buildService()` ergänzen (Summary, Agenda …); vorhanden sind
 *    die von ServiceTestCase sowie Config und PendingChanges.
 *  - `assertEveryNumberHasProvenance($out)` (Konzept §8.1, McpProvenanceTest).
 *  - Feste Uhr `$this->now` (Unix-Zeit) für Vorschläge; `today` im Kontext.
 */
abstract class McpTestCase extends ServiceTestCase
{
    /** feste Uhr der Vorschläge (2027-01-15 08:00 UTC) */
    protected int $now = 1800000000;
    protected Config $mcpConfig;
    protected PendingChanges $pending;
    /** letzter unerwarteter Fehler des Servers (sonst null) */
    protected ?\Throwable $serverError = null;
    /** @var array<class-string,object> */
    private array $built = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->i18n->setLocale('de');
        $this->mcpConfig = new Config($this->settings);
        $this->pending = new PendingChanges($this->dataDir, $this->store, fn(): int => $this->now);
    }

    /**
     * Weitere Dienste für Werkzeuge der Pakete, nach Klasse (null = gibt es nicht).
     * Überschreiben, etwa: `SummaryService::class => new SummaryService(...)`.
     */
    protected function buildService(string $class): ?object
    {
        return null;
    }

    /** @return object|null Dienst nach Klasse (einmal gebaut) */
    protected function service(string $class): ?object
    {
        $known = [
            JsonStore::class => $this->store, SettingsService::class => $this->settings, I18nService::class => $this->i18n,
            MeterService::class => $this->meters, ReadingService::class => $this->readings, ContractService::class => $this->contracts,
            ConsumptionService::class => $this->consumption, Config::class => $this->mcpConfig, PendingChanges::class => $this->pending,
        ];
        if (isset($known[$class])) return $known[$class];
        return $this->built[$class] ??= $this->buildService($class);
    }

    protected function mcpServices(): Services
    {
        return new Services(fn(string $class): ?object => $this->service($class));
    }

    protected function server(): Server
    {
        $services = $this->mcpServices();
        return new Server(new ToolRegistry($services), new Prompts($services, $this->i18n), new Resources($services, $this->i18n),
            $this->i18n, '9.9.9', function (\Throwable $e): void { $this->serverError = $e; });
    }

    protected function context(string $scope = 'mcp', string $today = '2027-01-15', string $locale = 'de', string $keyId = 'k_test0001'): McpContext
    {
        return new McpContext($keyId, 'Test-Schlüssel', $scope, $locale, $today);
    }

    /**
     * Eine Nachricht an den Server. `modern` setzt `_meta` (Fassung, Client,
     * Fähigkeiten) und die Pflicht-Kopfzeilen `MCP-Protocol-Version`, `Mcp-Method`
     * und (tools/call, resources/read, prompts/get) `Mcp-Name`, Letztere in der
     * Base64-Form, wenn der Wert kein schlichtes ASCII ist; `id` null = Benachrichtigung.
     * Eine Kopfzeile in `$headers` ersetzt die vorbelegte, der Wert null lässt sie weg.
     *
     * @param array<string,mixed> $params
     * @param array<string,?string> $headers
     * @return array{status:int, body:array<string,mixed>|null, headers:array<string,string>, log:array<string,mixed>}
     */
    protected function rpc(string $method, array $params = [], ?McpContext $ctx = null, bool $modern = false, int|string|null $id = 1, array $headers = []): array
    {
        if ($modern) {
            $params['_meta'] = ($params['_meta'] ?? []) + [
                Server::META_VERSION      => Server::MODERN[0],
                Server::META_CLIENT       => ['name' => 'test-client', 'version' => '1.0'],
                Server::META_CAPABILITIES => new \stdClass(),
            ];
            $headers += ['mcp-protocol-version' => Server::MODERN[0], 'mcp-method' => $method];
            $field = Server::NAMED[$method] ?? null;
            if ($field !== null && is_string($params[$field] ?? null)) $headers += ['mcp-name' => self::headerValue($params[$field])];
        }
        $headers = array_filter($headers, fn($v) => $v !== null);
        $msg = ['jsonrpc' => '2.0', 'method' => $method] + ($id !== null ? ['id' => $id] : []) + ($params !== [] ? ['params' => $params] : []);
        $reply = $this->server()->handle((string)json_encode($msg), $ctx ?? $this->context(), $headers);
        // wie über HTTP (Response::raw): einmal durch JSON (Objekte werden Felder, 10.0 bleibt Kommazahl)
        if ($reply['body'] !== null) $reply['body'] = json_decode((string)json_encode($reply['body'], JSON_PRESERVE_ZERO_FRACTION), true);
        return $reply;
    }

    /**
     * Wert einer gespiegelten Kopfzeile wie ein Client ihn schickt (Spezifikation
     * 2026-07-28, „Value Encoding“): schlichtes ASCII ohne Rand-Leerraum bleibt,
     * alles andere (und was wie die Base64-Form aussieht) wird `=?base64?…?=`.
     */
    public static function headerValue(string $v): string
    {
        $plain = preg_match('/^[\x21-\x7E](?:[\x20-\x7E]*[\x21-\x7E])?$/', $v) === 1
            && !(str_starts_with($v, Server::B64_PREFIX) && str_ends_with($v, Server::B64_SUFFIX));
        return $plain ? $v : Server::B64_PREFIX . base64_encode($v) . Server::B64_SUFFIX;
    }

    /**
     * `structuredContent` eines erfolgreichen Aufrufs; prüft Form und `outputSchema`.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    protected function callTool(string $name, array $args = [], ?McpContext $ctx = null): array
    {
        $r = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object)$args], $ctx);
        self::assertNull($this->serverError, 'Serverfehler: ' . ($this->serverError?->getMessage() ?? ''));
        self::assertSame(200, $r['status'], json_encode($r['body']) ?: '');
        $result = $r['body']['result'] ?? null;
        self::assertIsArray($result, json_encode($r['body']) ?: '');
        self::assertFalse($result['isError'], 'Werkzeugfehler: ' . ($result['content'][0]['text'] ?? ''));
        $structured = $result['structuredContent'];
        self::assertSame($structured, json_decode($result['content'][0]['text'], true), 'Text = dieselbe JSON-Fassung');
        $tool = (new ToolRegistry($this->mcpServices()))->get($name);
        if ($tool?->outputSchema !== null) {
            self::assertSame([], Schema::validate($structured, $tool->outputSchema), "$name: Ausgabe passt nicht zum outputSchema");
        }
        return $structured;
    }

    /**
     * `structuredContent.error` eines fachlichen Fehlers (`isError: true`).
     *
     * @param array<string,mixed> $args
     * @return array{code:string, message:string}
     */
    protected function callToolError(string $name, array $args = [], ?McpContext $ctx = null): array
    {
        $r = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object)$args], $ctx);
        self::assertNull($this->serverError, 'Serverfehler: ' . ($this->serverError?->getMessage() ?? ''));
        self::assertSame(200, $r['status']);
        self::assertTrue($r['body']['result']['isError'] ?? false, json_encode($r['body']) ?: '');
        $err = $r['body']['result']['structuredContent']['error'];
        self::assertSame($err['message'], $r['body']['result']['content'][0]['text']);
        return $err;
    }

    /**
     * Pfade von Zahlen ohne Herkunft: Jede Zahl steht in einem Zahl-Objekt
     * oder in einer Zeile mit `basis`; ausgenommen Zählwerte und Kalenderangaben.
     *
     * @param list<string> $exempt Feldnamen ohne Herkunft
     * @return list<string>
     */
    public static function numbersWithoutProvenance(mixed $data, array $exempt = [], string $path = ''): array
    {
        $exempt = array_merge(['days', 'count', 'pages', 'limit', 'year', 'month', 'months', 'members_count'], $exempt);
        if (!is_array($data)) return [];
        if (Num::isNum($data)) return [];
        $rowHasBasis = Provenance::isBasis($data['basis'] ?? null);
        $out = [];
        foreach ($data as $k => $v) {
            $p = "$path/$k";
            if (is_int($v) || is_float($v)) {
                if (!$rowHasBasis && !in_array((string)$k, $exempt, true)) $out[] = $p;
                continue;
            }
            if (is_array($v)) array_push($out, ...self::numbersWithoutProvenance($v, $exempt, $p));
        }
        return $out;
    }

    /** @param list<string> $exempt */
    protected function assertEveryNumberHasProvenance(mixed $data, array $exempt = []): void
    {
        self::assertSame([], self::numbersWithoutProvenance($data, $exempt), 'Zahlen ohne Herkunft');
    }
}
