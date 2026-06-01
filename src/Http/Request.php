<?php
declare(strict_types=1);

namespace Energietracker\Http;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    /** @var array<string,string> */
    public readonly array $query;
    /** @var mixed json-decoded body */
    public readonly mixed $body;
    public readonly string $rawBody;

    /** @param array<string,mixed> $params route params (filled by router) */
    public array $params = [];

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
        $raw = file_get_contents('php://input') ?: '';
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
