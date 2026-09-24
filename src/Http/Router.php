<?php
declare(strict_types=1);

namespace Energietracker\Http;

/**
 * Minimal pattern-based router.
 *
 * Patterns support {param} segments:
 *   /api/utility/{utility}/readings
 *   /api/utility/{utility}/readings/{id}
 *
 * Handlers are callables receiving (Request).
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:string[],handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void    { $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, callable $handler): void   { $this->add('POST', $pattern, $handler); }
    public function put(string $pattern, callable $handler): void    { $this->add('PUT', $pattern, $handler); }
    public function patch(string $pattern, callable $handler): void  { $this->add('PATCH', $pattern, $handler); }
    public function delete(string $pattern, callable $handler): void { $this->add('DELETE', $pattern, $handler); }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            function ($m) use (&$params) {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );
        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $req): void
    {
        // v2.6.0 — HEAD wie GET (Uptime-Monitore prüfen oft per HEAD; der
        // Webserver verwirft den Körper). Bis v2.5.3 gab es darauf 404.
        $method  = $req->method === 'HEAD' ? 'GET' : $req->method;
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $req->path, $matches)) continue;
            if ($route['method'] !== $method) { $allowed[] = $route['method']; continue; }
            array_shift($matches);
            foreach ($route['params'] as $i => $name) {
                $req->params[$name] = $matches[$i] ?? null;
            }
            ($route['handler'])($req);
            return; // handler is expected to exit
        }
        // v2.6.0 — Pfad bekannt, Methode nicht: 405 mit Allow statt 404;
        // OPTIONS nennt die erlaubten Methoden.
        if ($allowed !== []) {
            if (in_array('GET', $allowed, true)) $allowed[] = 'HEAD';
            $allowed[] = 'OPTIONS';
            if (!headers_sent()) header('Allow: ' . implode(', ', array_unique($allowed)));
            if ($req->method === 'OPTIONS') Response::noContent();
            Response::error('Method not allowed: ' . $req->method . ' ' . $req->path, 405);
        }
        if ($req->method === 'OPTIONS') Response::noContent();
        // Kein I18nService: Der Router läuft vor der Anwendungsschicht und
        // soll auch dann antworten können, wenn der Container nicht steht.
        // Diese Meldung richtet sich an Entwickler, nicht an Nutzer.
        Response::error('Route not found: ' . $req->method . ' ' . $req->path, 404);
    }
}
