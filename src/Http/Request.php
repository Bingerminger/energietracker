<?php
declare(strict_types=1);

namespace Energietracker\Http;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    /** @var array<string,string> */
    public readonly array $query;
    /**
     * @var mixed json-decoded body
     *
     * v3.4.0 (N1020) — `body` und `rawBody` werden erst beim ersten Zugriff
     * gelesen (__get). Die App prüft vorher Anmeldung und Größe: Ein Gerät
     * ohne Anmeldung bringt den Server nicht mehr mit großen Körpern an die
     * Speichergrenze, denn gelesen und dekodiert wurde bis v3.3 vor allem.
     */
    public readonly mixed $body;
    public readonly string $rawBody;

    /** @param array<string,mixed> $params route params (filled by router) */
    public array $params = [];

    /** v3.4.0 (N1020) — Obergrenze des Körpers in Bytes (null = keine eigene). */
    private ?int $bodyLimit = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Strip script-name prefix so routing patterns like /api/utility/...
        // work regardless of whether the user calls api.php/api/... or has
        // an .htaccess rewrite that hides api.php. Also handles deployments
        // in a subdirectory (e.g. /energietracker/api.php/api/...).
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($scriptName !== '' && str_starts_with($path, $scriptName)) {
            $path = substr($path, strlen($scriptName)) ?: '/';
        } else {
            // Same idea, but with the script directory only (rewrite case)
            $base = rtrim(dirname($scriptName), '/');
            if ($base !== '' && $base !== '.' && str_starts_with($path, $base . '/')) {
                $path = substr($path, strlen($base)) ?: '/';
            }
        }
        if ($path === '' || $path[0] !== '/') $path = '/' . $path;
        // Defensive: collapse accidental // to / (e.g. when a client appends
        // a leading-slash path to a trailing-slash base URL).
        $path = preg_replace('#/{2,}#', '/', $path);
        $this->path = $path;

        /** @var array<string,string> $q */
        $q = [];
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $q);
        $this->query = $q;
        // v3.4.0 (N1020) — Körper erst bei Bedarf lesen, s. __get()
        unset($this->body, $this->rawBody);
    }

    /** Liest den Körper beim ersten Zugriff auf `body` oder `rawBody`. */
    public function __get(string $name): mixed
    {
        if ($name !== 'body' && $name !== 'rawBody') {
            throw new \Error('Undefined property: ' . self::class . '::$' . $name);
        }
        $this->loadBody();
        return $this->$name;
    }

    public function __isset(string $name): bool
    {
        if ($name !== 'body' && $name !== 'rawBody') return false;
        $this->loadBody();
        return $this->$name !== null;
    }

    /** Länge laut `Content-Length` (null ohne Angabe, etwa bei chunked). */
    public static function declaredLength(): ?int
    {
        $len = $_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? null;
        return $len === null || $len === '' || !is_numeric($len) ? null : max(0, (int)$len);
    }

    /**
     * v3.4.0 (N1020) — Obergrenze setzen und gegen `Content-Length` prüfen,
     * bevor ein Byte gelesen ist. Ohne Längenangabe greift die Grenze beim
     * Lesen (es wird höchstens eine Grenze plus ein Byte gelesen).
     *
     * @throws HttpStatusException 413
     */
    public function limitBody(int $bytes): void
    {
        $this->bodyLimit = $bytes;
        $declared = self::declaredLength();
        if ($declared !== null && $declared > $bytes) throw self::tooLarge($bytes);
    }

    private static function tooLarge(int $bytes): HttpStatusException
    {
        return new HttpStatusException(413, 'errors.http.tooLarge',
            ['max' => max(1, (int)ceil($bytes / 1048576))], [], "request body over $bytes bytes");
    }

    private function loadBody(): void
    {
        if ((new \ReflectionProperty(self::class, 'rawBody'))->isInitialized($this)) return;
        $raw = '';
        $fp = @fopen('php://input', 'rb');
        if ($fp !== false) {
            $raw = (string)($this->bodyLimit === null
                ? stream_get_contents($fp)
                : stream_get_contents($fp, $this->bodyLimit + 1));
            fclose($fp);
        }
        if ($this->bodyLimit !== null && strlen($raw) > $this->bodyLimit) throw self::tooLarge($this->bodyLimit);
        $this->rawBody = $raw;
        $decoded = json_decode($raw, true);
        $this->body = is_array($decoded) ? $decoded : null;
    }

    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    public function queryParam(string $name, ?string $default = null): ?string
    {
        $v = $this->query[$name] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (!is_array($this->body)) return $default;
        return $this->body[$key] ?? $default;
    }

    /**
     * Bearer-Token aus dem Authorization-Header (F1009 — HA-Ingest).
     *
     * Der Header landet je nach Server an unterschiedlichen Stellen:
     *   - `$_SERVER['HTTP_AUTHORIZATION']` (häufigster Fall),
     *   - `$_SERVER['REDIRECT_HTTP_AUTHORIZATION']` (bei .htaccess-Rewrites,
     *     z. B. Apache + mod_rewrite, wo der Header sonst verschluckt wird),
     *   - via `getallheaders()` (Apache/CGI) als letzte Rückfallebene.
     *
     * Liefert den reinen Token (ohne „Bearer "-Präfix) oder null.
     */
    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() ?: [] as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $header = (string)$v; break; }
            }
        }
        if ($header === '') return null;
        if (preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }
}
