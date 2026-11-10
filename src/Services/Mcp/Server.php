<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

use Energietracker\Http\ConflictException;
use Energietracker\Http\HttpStatusException;
use Energietracker\Http\NotFoundException;
use Energietracker\Services\I18nService;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037) — MCP-Server: JSON-RPC über Streamable HTTP, nur JSON, ohne
 * Sitzung und ohne SSE (Entscheidung 2, Konzept §2.2, §2.3).
 *
 * Zwei Zeitalter an einem Endpunkt („dual-era“):
 *  - modern (2026-07-28): Fassung in `params._meta["io.modelcontextprotocol/protocolVersion"]`
 *    (und Kopfzeile `MCP-Protocol-Version`), kein `initialize`, `server/discover`,
 *    jede Antwort mit `resultType: "complete"`, unbekannte Methode → HTTP 404;
 *  - älter (2025-11-25, 2025-06-18; ohne Kopfzeile 2025-03-26): `initialize`
 *    wählt die Fassung, `notifications/initialized` → 202, kein `Mcp-Session-Id`.
 *
 * Der Server prüft nur das Protokoll. Schalter, Ursprung und Schlüssel prüft
 * vorher McpController; welcher Schlüssel fragt, steht im McpContext. Werkzeuge
 * filtert und prüft er nach Bereich (`mcp` sieht nur lesende).
 *
 * v3.12.0 (Paket protokoll) — Fassung 2026-07-28 vollständig: Kopfzeilen
 * `MCP-Protocol-Version`, `Mcp-Method` und (bei tools/call, resources/read,
 * prompts/get) `Mcp-Name` sind Pflicht und müssen zum Körper passen, `Mcp-Name`
 * auch in der Form `=?base64?…?=` (sonst 400, -32020); `initialize` und `ping`
 * gibt es dort nicht mehr (404); Listen, `server/discover` und `resources/read`
 * tragen `ttlMs` und `cacheScope`. Ältere Fassungen prüfen die Kopfzeilen nur,
 * wenn sie gesendet werden. Werkzeugaufrufe zählen in der Ratenbegrenzung.
 *
 * Antwort als `{status, body, headers, log}`; `log` ist der Eintrag fürs
 * Zugriffsprotokoll (ohne Argumente, ohne Inhalte): `{method, name, result,
 * version, client, code?, error_id?}`.
 */
final class Server
{
    public const NAME = 'energietracker';
    public const TITLE = 'Energietracker';
    /** Fassungen mit Angaben je Anfrage (`_meta`) */
    public const MODERN = ['2026-07-28'];
    /** Fassungen mit `initialize`; die erste ist die Antwort auf eine unbekannte */
    public const LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26'];
    /** ohne Kopfzeile und ohne `_meta` (Spezifikation: so behandeln) */
    public const DEFAULT_LEGACY = '2025-03-26';
    /** Sprachen der Texte an die KI; jede andere Installation bekommt Englisch (Konzept §9) */
    public const LOCALES = ['de', 'en'];
    public const FALLBACK_LOCALE = 'en';

    public const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    public const META_CLIENT = 'io.modelcontextprotocol/clientInfo';
    public const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    public const META_SERVER = 'io.modelcontextprotocol/serverInfo';

    // JSON-RPC 2.0
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;
    // MCP 2026-07-28 (Bereich -32020 bis -32099 gehört der Spezifikation)
    public const HEADER_MISMATCH = -32020;
    public const UNSUPPORTED_VERSION = -32022;
    // eigene Codes, außerhalb des für JSON-RPC reservierten Bereichs (-32768 bis -32000)
    public const ERR_DISABLED = -31001;       // 403: ausgeschaltet oder per ET_MCP gesperrt
    public const ERR_UNAUTHORIZED = -31002;   // 401: kein gültiger Schlüssel
    public const ERR_FORBIDDEN = -31003;      // 403: fremder Ursprung, Schlüssel ohne KI-Bereich
    public const ERR_SCOPE = -31004;          // 403: Werkzeug braucht mcp_write
    public const ERR_RATE_LIMITED = -31005;   // 429: Ratenbegrenzung oder Adresse nach falschen Schlüsseln gesperrt
    public const ERR_TOO_LARGE = -31006;      // 413: Körper über 1 MB

    /** Methoden mit Pflicht-Kopfzeile `Mcp-Name` (2026-07-28) → Feld in `params` */
    public const NAMED = ['tools/call' => 'name', 'resources/read' => 'uri', 'prompts/get' => 'name'];
    /** nur ältere Fassungen; 2026-07-28 kennt sie nicht mehr (404) */
    public const LEGACY_ONLY = ['initialize', 'ping'];
    /** Base64-Form einer gespiegelten Kopfzeile (Spezifikation 2026-07-28, „Value Encoding“) */
    public const B64_PREFIX = '=?base64?';
    public const B64_SUFFIX = '?=';
    /**
     * Ergebnisse mit `ttlMs` (Frische in Millisekunden) und `cacheScope` (2026-07-28,
     * CacheableResult). Listen ändern sich nur mit einem Update oder der Sprache
     * (5 Minuten), Ressourcen sind Daten des Haushalts (Liste 1 Minute, Inhalt
     * sofort veraltet); eine Ressource darf eigene Werte mitbringen.
     */
    public const CACHE_TTL_MS = [
        'server/discover'          => 300000,
        'tools/list'               => 300000,
        'prompts/list'             => 300000,
        'resources/templates/list' => 300000,
        'resources/list'           => 60000,
        'resources/read'           => 0,
    ];
    /** jede Antwort hängt am Schlüssel (Bereich, Haushalt): nie über Schlüssel hinweg zwischenspeichern */
    public const CACHE_SCOPE = 'private';

    /**
     * @param (\Closure(\Throwable, string): void)|null $onError Protokoll eines unerwarteten Fehlers (mit ID)
     * @param RateLimiter|null $limiter v3.12.0 (Paket protokoll) — Töpfe der Werkzeugaufrufe; null = keine Begrenzung (Tests)
     */
    public function __construct(
        private ToolRegistry $tools,
        private Prompts $prompts,
        private Resources $resources,
        private I18nService $i18n,
        private string $appVersion,
        private ?\Closure $onError = null,
        private ?RateLimiter $limiter = null,
    ) {}

    /** @return list<string> alle Fassungen, neueste zuerst */
    public static function versions(): array
    {
        return [...self::MODERN, ...self::LEGACY];
    }

    /** Sprache der Texte an die KI: die der Installation, wenn DE oder EN, sonst EN. */
    public static function localeFor(I18nService $i18n): string
    {
        $l = $i18n->installationLocale();
        return in_array($l, self::LOCALES, true) ? $l : self::FALLBACK_LOCALE;
    }

    /** @return array{name:string, title:string, version:string} */
    public function serverInfo(): array
    {
        return ['name' => self::NAME, 'title' => self::TITLE, 'version' => $this->appVersion];
    }

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        return ['tools' => ['listChanged' => false], 'resources' => new \stdClass(), 'prompts' => new \stdClass()];
    }

    /**
     * Eine Nachricht (ein JSON-RPC-Objekt) beantworten.
     *
     * @param array<string,string> $headers Kopfzeilen mit kleinen Namen (`mcp-protocol-version` …)
     * @return array{status:int, body:array<string,mixed>|null, headers:array<string,string>, log:array<string,mixed>}
     */
    public function handle(string $raw, McpContext $ctx, array $headers = []): array
    {
        $log = ['method' => null, 'name' => null, 'result' => 'error', 'version' => null, 'client' => []];
        $id = null;
        try {
            // seit 2025-06-18 keine Stapel mehr
            if (str_starts_with(ltrim($raw), '[')) throw new RpcError(400, self::INVALID_REQUEST, 'errors.mcp.batch');
            try {
                $msg = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RpcError(400, self::PARSE_ERROR, 'errors.mcp.parse');
            }
            if (!is_array($msg) || ($msg !== [] && array_is_list($msg))) throw new RpcError(400, self::INVALID_REQUEST, 'errors.mcp.invalidRequest');
            $hasId = array_key_exists('id', $msg);
            $idOk = $hasId && (is_string($msg['id']) || is_int($msg['id']));
            if ($idOk) $id = $msg['id'];
            // MCP: Kennung Zeichenkette oder Ganzzahl, nie null; keine Antworten vom Client
            if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($msg['method'] ?? null) || $msg['method'] === '' || ($hasId && !$idOk)) {
                throw new RpcError(400, self::INVALID_REQUEST, 'errors.mcp.invalidRequest');
            }
            $method = $msg['method'];
            $log['method'] = self::label($method);
            $params = $msg['params'] ?? [];
            if (!is_array($params) || ($params !== [] && array_is_list($params))) throw new RpcError(400, self::INVALID_PARAMS, 'errors.mcp.invalidParams');

            $ctx = $this->protocol($method, $params, $ctx, $headers, $hasId);
            $log['version'] = $ctx->version;
            $log['client'] = $ctx->client;

            if (!$hasId) {
                // Benachrichtigungen (notifications/initialized, …): angenommen, ohne Antwort
                if (!str_starts_with($method, 'notifications/')) throw new RpcError(400, self::INVALID_REQUEST, 'errors.mcp.invalidRequest');
                $log['result'] = 'ok';
                return ['status' => 202, 'body' => null, 'headers' => [], 'log' => $log];
            }
            $result = $this->dispatch($method, $params, $ctx, $log);
            if ($log['result'] === 'error') $log['result'] = 'ok';
            // Code eines fachlichen Fehlers fürs Protokoll (ohne Text)
            if (is_array($result) && ($result['isError'] ?? false) === true) $log['code'] = $result['structuredContent']['error']['code'] ?? null;
            return ['status' => 200, 'body' => $this->resultBody($id, $result, $ctx, $method), 'headers' => [], 'log' => $log];
        } catch (RpcError $e) {
            if ($e->rpcCode === self::ERR_SCOPE) $log['result'] = 'denied';
            if ($e->rpcCode === self::ERR_RATE_LIMITED) $log['result'] = 'rate_limited';
            $log['code'] = $e->key;
            return ['status' => $e->status, 'body' => $this->errorBody($id, $e, $ctx->locale), 'headers' => $e->headers, 'log' => $log];
        } catch (\Throwable $e) {
            $errorId = 'err_' . bin2hex(random_bytes(4));
            if ($this->onError !== null) ($this->onError)($e, $errorId);
            $err = new RpcError(500, self::INTERNAL_ERROR, 'errors.mcp.internal', ['id' => $errorId], ['error_id' => $errorId]);
            $log['code'] = $err->key;
            $log['error_id'] = $errorId;
            return ['status' => 500, 'body' => $this->errorBody($id, $err, $ctx->locale), 'headers' => [], 'log' => $log];
        }
    }

    /**
     * Fassung und Client aus Nachricht und Kopfzeile.
     *
     * @param array<string,mixed> $params
     * @param array<string,string> $headers
     * @param bool $isRequest mit Kennung (Benachrichtigungen haben in 2026-07-28 keine Pflicht-Kopfzeilen)
     */
    private function protocol(string $method, array $params, McpContext $ctx, array $headers, bool $isRequest = true): McpContext
    {
        $meta = $params['_meta'] ?? [];
        if (!is_array($meta)) throw new RpcError(400, self::INVALID_PARAMS, 'errors.mcp.invalidParams');
        $header = $headers['mcp-protocol-version'] ?? null;
        $requested = $meta[self::META_VERSION] ?? null;
        if ($header !== null && !in_array($header, self::versions(), true)) throw self::unsupported($header);
        if ($requested !== null) {
            if (!is_string($requested) || !in_array($requested, self::versions(), true)) throw self::unsupported($requested);
            if ($header !== null && $header !== $requested) {
                throw self::headerError('errors.mcp.headerMismatch', 'MCP-Protocol-Version');   // mit data.header (Paket protokoll)
            }
        }
        $modern = is_string($requested) && in_array($requested, self::MODERN, true);
        // Pflichtangaben einer modernen Anfrage (sonst -32602 mit 400)
        if ($modern && !is_array($meta[self::META_CAPABILITIES] ?? null)) {
            throw new RpcError(400, self::INVALID_PARAMS, 'errors.mcp.metaMissing', ['field' => self::META_CAPABILITIES]);
        }
        // v3.12.0 (Paket protokoll) — 2026-07-28: die Kopfzeile der Fassung ist Pflicht und muss zum Körper passen
        // (bei Anfragen; für Benachrichtigungen legt die Fassung keine Kopfzeilen fest)
        if ($modern && $isRequest && $header === null) throw self::headerError('errors.mcp.headerMissing', 'MCP-Protocol-Version');
        if ($requested === null && $header !== null && in_array($header, self::MODERN, true)) {
            throw self::headerError('errors.mcp.headerMismatch', 'MCP-Protocol-Version');   // Kopfzeile modern, Körper ohne _meta
        }
        self::checkMirroredHeaders($method, $params, $headers, $modern && $isRequest);
        if ($requested !== null) {
            $version = $requested;
        } elseif ($method === 'initialize') {
            $version = in_array($params['protocolVersion'] ?? null, self::LEGACY, true) ? (string)$params['protocolVersion'] : self::LEGACY[0];
        } else {
            $version = $header ?? self::DEFAULT_LEGACY;
        }
        $client = Sanitize::clientInfo($meta[self::META_CLIENT] ?? ($method === 'initialize' ? ($params['clientInfo'] ?? null) : null));
        return $ctx->withProtocol($version, $modern, $client);
    }

    private static function unsupported(mixed $requested): RpcError
    {
        return new RpcError(400, self::UNSUPPORTED_VERSION, 'errors.mcp.unsupportedVersion', [],
            ['supported' => self::versions(), 'requested' => is_string($requested) ? mb_substr($requested, 0, 40) : null]);
    }

    /**
     * v3.12.0 (Paket protokoll) — `Mcp-Method` und `Mcp-Name` spiegeln den Körper
     * (Spezifikation 2026-07-28, „Server Validation“), damit Proxys und Körper
     * nicht Verschiedenes meinen. Pflicht bei modernen Anfragen; sonst nur
     * geprüft, wenn gesendet. Werte werden nie zurückgegeben (nur der Name der
     * Kopfzeile), damit kein fremder Text in die Antwort gelangt.
     *
     * @param array<string,mixed> $params
     * @param array<string,string> $headers
     */
    private static function checkMirroredHeaders(string $method, array $params, array $headers, bool $required): void
    {
        $m = $headers['mcp-method'] ?? null;
        if ($m === null) {
            if ($required) throw self::headerError('errors.mcp.headerMissing', 'Mcp-Method');
        } elseif (!self::headerSafe($m)) {
            throw self::headerError('errors.mcp.headerInvalid', 'Mcp-Method');
        } elseif ($m !== $method) {
            throw self::headerError('errors.mcp.headerMismatch', 'Mcp-Method');
        }
        $field = self::NAMED[$method] ?? null;
        if ($field === null) return;
        $n = $headers['mcp-name'] ?? null;
        if ($n === null) {
            if ($required) throw self::headerError('errors.mcp.headerMissing', 'Mcp-Name');
            return;
        }
        $value = self::decodeHeaderValue($n);
        if ($value === null) throw self::headerError('errors.mcp.headerInvalid', 'Mcp-Name');
        if (!is_string($params[$field] ?? null) || $params[$field] !== $value) throw self::headerError('errors.mcp.headerMismatch', 'Mcp-Name');
    }

    /**
     * Wert einer gespiegelten Kopfzeile: Klartext aus sichtbarem ASCII, Leerzeichen
     * und Tab, oder `=?base64?…?=` (UTF-8, streng dekodiert). null = unzulässig.
     */
    public static function decodeHeaderValue(string $value): ?string
    {
        if (!self::headerSafe($value)) return null;
        $p = strlen(self::B64_PREFIX);
        $s = strlen(self::B64_SUFFIX);
        if (strlen($value) >= $p + $s && str_starts_with($value, self::B64_PREFIX) && str_ends_with($value, self::B64_SUFFIX)) {
            $decoded = base64_decode(substr($value, $p, -$s), true);
            return is_string($decoded) && mb_check_encoding($decoded, 'UTF-8') ? $decoded : null;
        }
        return $value;
    }

    private static function headerSafe(string $value): bool
    {
        return preg_match('/^[\x20-\x7E\t]*$/', $value) === 1;
    }

    private static function headerError(string $key, string $header): RpcError
    {
        return new RpcError(400, self::HEADER_MISMATCH, $key, ['header' => $header], ['header' => $header]);
    }

    /** 429 mit `Retry-After` (Ratenbegrenzung, Sperre nach falschen Schlüsseln). */
    public static function rateLimited(int $seconds, string $key = 'errors.mcp.rateLimited'): RpcError
    {
        $seconds = max(1, $seconds);
        return new RpcError(429, self::ERR_RATE_LIMITED, $key, ['seconds' => $seconds], ['retry_after' => $seconds],
            ['Retry-After' => (string)$seconds]);
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $log
     * @return array<string,mixed>|object
     */
    private function dispatch(string $method, array $params, McpContext $ctx, array &$log): array|object
    {
        // 2026-07-28 kennt kein initialize und kein ping mehr: wie eine unbekannte Methode (404)
        $legacyOnly = $ctx->modern && in_array($method, self::LEGACY_ONLY, true);
        switch ($legacyOnly ? '' : $method) {
            case 'initialize':
                return [
                    'protocolVersion' => $ctx->version,
                    'capabilities'    => $this->capabilities(),
                    'serverInfo'      => $this->serverInfo(),
                    'instructions'    => $this->i18n->t('mcp.instructions', [], $ctx->locale),
                ];
            case 'server/discover':
                return [
                    'supportedVersions' => self::versions(),
                    'capabilities'      => $this->capabilities(),
                    'instructions'      => $this->i18n->t('mcp.instructions', [], $ctx->locale),
                    '_meta'             => [self::META_SERVER => $this->serverInfo()],
                ];
            case 'ping':
                return new \stdClass();
            case 'tools/list':
                return ['tools' => array_map(fn(Tool $t) => $t->definition($this->i18n, $ctx->locale), $this->tools->forContext($ctx))];
            case 'tools/call':
                return $this->callTool($params, $ctx, $log);
            case 'prompts/list':
                return ['prompts' => $this->prompts->list($ctx)];
            case 'prompts/get':
                $name = $params['name'] ?? null;
                $args = $params['arguments'] ?? [];
                if (!is_string($name) || $name === '' || !is_array($args)) throw new RpcError(200, self::INVALID_PARAMS, 'errors.mcp.invalidParams');
                $log['name'] = self::label($name);
                $out = $this->prompts->get($name, array_map(fn($v) => is_scalar($v) ? (string)$v : '', $args), $ctx);
                if ($out === null) throw new RpcError(200, self::INVALID_PARAMS, 'errors.mcp.unknownPrompt', ['name' => self::label($name)]);
                return $out;
            case 'resources/list':
                return ['resources' => $this->resources->list($ctx)];
            case 'resources/templates/list':
                return ['resourceTemplates' => $this->resources->templates($ctx)];
            case 'resources/read':
                $uri = $params['uri'] ?? null;
                if (!is_string($uri) || $uri === '') throw new RpcError(200, self::INVALID_PARAMS, 'errors.mcp.invalidParams');
                $log['name'] = mb_substr($uri, 0, 200);
                $out = $this->resources->read($uri, $ctx);
                if ($out === null) throw new RpcError(200, self::INVALID_PARAMS, 'errors.mcp.unknownResource', ['uri' => mb_substr($uri, 0, 200)]);
                return $out;
        }
        // modern: 404 (die Spezifikation verlangt es); älter: 200, weil 404 dort „Sitzung abgelaufen“ heißt
        throw new RpcError($ctx->modern ? 404 : 200, self::METHOD_NOT_FOUND, 'errors.mcp.methodNotFound', ['method' => self::label($method)]);
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $log
     * @return array<string,mixed>
     */
    private function callTool(array $params, McpContext $ctx, array &$log): array
    {
        $name = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];
        if (!is_string($name) || $name === '' || !is_array($args) || ($args !== [] && array_is_list($args))) {
            throw new RpcError(200, self::INVALID_PARAMS, 'errors.mcp.invalidParams');
        }
        $log['name'] = self::label($name);
        $tool = $this->tools->get($name);
        if ($tool === null) throw new RpcError(200, self::INVALID_PARAMS, 'errors.mcp.unknownTool', ['name' => self::label($name)]);
        if (!$ctx->allows($tool->scope)) {
            throw new RpcError(403, self::ERR_SCOPE, 'errors.mcp.insufficientScope', ['name' => $tool->name], ['scope' => $tool->scope],
                ['WWW-Authenticate' => 'Bearer realm="energietracker", error="insufficient_scope", scope="' . $tool->scope . '"']);
        }
        // v3.12.0 (Paket protokoll) — Topf je Werkzeug (lesen, vorschlagen, übernehmen); zählt vor der Prüfung der Eingabe
        $wait = $this->limiter?->hit(RateLimiter::bucketForTool($tool->name), $ctx->keyId);
        if ($wait !== null) throw self::rateLimited($wait);
        // Eingabefehler sind Fehler des Werkzeugs (isError), damit das Modell sich korrigiert (Spezifikation „Error Handling“)
        $errors = Schema::validate($args, $tool->inputSchema);
        if ($errors !== []) {
            $log['result'] = 'tool_error';
            return $this->toolError('errors.mcp.invalidArguments', ['details' => self::describe($errors)], $ctx, ['errors' => $errors]);
        }
        try {
            $data = $tool->call($args, $ctx);
        } catch (LocalizedException $e) {
            $log['result'] = 'tool_error';
            return $this->toolError($e->key, $e->params, $ctx);
        } catch (HttpStatusException $e) {
            if ($e->status >= 500) throw $e;
            $log['result'] = 'tool_error';
            return $this->toolError($e->key, $e->params, $ctx);
        } catch (NotFoundException | ConflictException | \InvalidArgumentException $e) {
            // die Dienste werfen übersetzte Texte; der Code kommt aus dem Katalog (I18nService::errorCodeFor)
            $log['result'] = 'tool_error';
            $message = $e->getMessage();
            return $this->toolError($this->i18n->errorCodeFor($message) ?? 'errors.mcp.toolFailed', [], $ctx, [], $message);
        }
        if (!is_array($data)) throw new \LogicException("mcp: tool '{$tool->name}' must return an array");
        $log['result'] = 'ok';
        $structured = $data === [] ? new \stdClass() : $data;
        // v3.12.0 — manche Programme geben nur `content` weiter: Salden stehen dort zusätzlich als fertiger Satz
        $sentences = is_array($data) ? self::balanceSentences($data) : [];
        return [
            'content'           => [['type' => 'text', 'text' => self::encode($structured)],
                ...($sentences !== [] ? [['type' => 'text', 'text' => implode("\n", $sentences)]] : [])],
            'structuredContent' => $structured,
            'isError'           => false,
        ];
    }

    /**
     * Zeilen „Feld: Satz“ zu jedem Saldo (Zahl-Objekt mit `meaning` und `text`), mit dem Vertrag,
     * zu dem er gehört. Reihenfolge wie in der Antwort.
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private static function balanceSentences(array $data, string $context = ''): array
    {
        $out = [];
        if (is_string($data['contract_id'] ?? null)) $context = $data['contract_id'];
        foreach ($data as $k => $v) {
            if (!is_array($v)) continue;
            if (is_string($v['meaning'] ?? null) && is_string($v['text'] ?? null)) {
                $out[] = ($context !== '' ? "$context · " : '') . "$k: {$v['text']}";
                continue;
            }
            array_push($out, ...self::balanceSentences($v, $context));
        }
        return $out;
    }

    /**
     * Fachlicher Fehler als Ergebnis: Text für das Modell, dazu Code und Text
     * strukturiert (`structuredContent.error`).
     *
     * @param array<string,scalar> $params
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function toolError(string $key, array $params, McpContext $ctx, array $extra = [], ?string $message = null): array
    {
        $message ??= $this->i18n->t($key, $params, $ctx->locale);
        return [
            'content'           => [['type' => 'text', 'text' => $message]],
            'structuredContent' => ['error' => ['code' => $key, 'message' => $message] + $extra],
            'isError'           => true,
        ];
    }

    /** @param list<array{path:string, rule:string, expected?:mixed}> $errors */
    private static function describe(array $errors): string
    {
        return implode('; ', array_map(function (array $e): string {
            $exp = $e['expected'] ?? null;
            $exp = is_array($exp) ? implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : '…', $exp)) : (is_scalar($exp) ? (string)$exp : '');
            return $e['path'] . ' ' . $e['rule'] . ($exp !== '' ? " ($exp)" : '');
        }, $errors));
    }

    /**
     * @param array<string,mixed>|object $result
     * @return array<string,mixed>
     */
    private function resultBody(string|int $id, array|object $result, McpContext $ctx, string $method = ''): array
    {
        if ($ctx->modern) {
            $r = (array)$result;
            $meta = (array)($r['_meta'] ?? []);
            $meta[self::META_SERVER] ??= $this->serverInfo();
            // ein Ergebnis darf eine eigene Art tragen (später `input_required`)
            $result = ['resultType' => is_string($r['resultType'] ?? null) ? $r['resultType'] : 'complete'] + $r;
            // v3.12.0 (Paket protokoll) — CacheableResult: Frische und Reichweite (eine Ressource darf eigene mitbringen)
            if (isset(self::CACHE_TTL_MS[$method])) {
                $result['ttlMs'] ??= self::CACHE_TTL_MS[$method];
                $result['cacheScope'] ??= self::CACHE_SCOPE;
                if (!is_int($result['ttlMs']) || $result['ttlMs'] < 0 || !in_array($result['cacheScope'], ['public', 'private'], true)) {
                    throw new \LogicException("mcp: ttlMs/cacheScope of '$method' out of range");
                }
            }
            $result['_meta'] = $meta;
        } elseif (is_array($result) && $result === []) {
            $result = new \stdClass();
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string,mixed> */
    private function errorBody(string|int|null $id, RpcError $e, string $locale): array
    {
        return $e->body($this->i18n, $locale, $id);
    }

    /** Methoden- und Werkzeugnamen für Texte und Protokoll: kurz und harmlos. */
    private static function label(string $s): string
    {
        return preg_match('/^[A-Za-z0-9_.\/-]{1,128}$/', $s) ? $s : '?';
    }

    private static function encode(mixed $data): string
    {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
