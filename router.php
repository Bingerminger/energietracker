<?php
/**
 * Router-Skript für den PHP-Built-in-Server (`php -S host:port router.php`).
 *
 * Wird AUSSCHLIESSLICH für lokale Entwicklung und die CI-Tests benutzt —
 * im Produktivbetrieb übernimmt nginx/Apache das Routing (siehe INSTALL.md).
 *
 * Spiegelt die nginx-Konfiguration:
 *   1. existierende statische Dateien direkt ausliefern
 *      (entspricht `try_files $uri`)
 *   2. /data/ und /src/ sowie *.php-Quelltext NICHT als Datei ausliefern
 *      (entspricht `location ~ ^/data/ { deny all; }`)
 *   3. Requests auf /api.php(/…) ODER /api/… an api.php delegieren
 *      (Frontend ruft api.php/api/…; die Test-Harnesses rufen /api/…
 *      direkt — beides landet via SCRIPT_NAME='/api.php' korrekt im
 *      Router, weil Request den Skriptnamen strippt)
 *   4. alles andere → index.php (SPA-Shell, entspricht try_files-Fallback)
 *
 * Ohne dieses Skript würde `php -S … api.php` jeden Request (auch
 * /public/js/app.js) durch api.php schleifen → 404 für statische Assets,
 * was den Modulgraph-Crawl des Browser-Render-Tests scheitern lässt.
 */
declare(strict_types=1);

$root = __DIR__;
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri  = '/' . ltrim(rawurldecode($uri), '/');

// ── 2. /data/ und /src/ sowie *.php-Quelltext sind tabu (wie nginx
//       `location ~ ^/data/ { deny all; return 404; }`) ──────────────
if (preg_match('#^/(data|src)(/|$)#', $uri)
    || (str_ends_with($uri, '.php') && $uri !== '/api.php' && !str_starts_with($uri, '/api.php/'))) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found";
    return true;
}

// ── 1. existierende statische Datei direkt ausliefern ───────────────
$candidate = realpath($root . $uri);
$isInsideRoot = $candidate !== false
    && str_starts_with($candidate . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR);

if ($isInsideRoot && is_file($candidate)) {
    return false; // PHP-Built-in-Server liefert die Datei selbst aus
}

// ── 3. API → api.php (SCRIPT_NAME so setzen, dass Request es strippt;
//       deckt sowohl /api.php/… als auch /api/… ab) ──────────────────
if ($uri === '/api.php' || str_starts_with($uri, '/api.php/')
    || str_starts_with($uri, '/api/')) {
    $_SERVER['SCRIPT_NAME']     = '/api.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/api.php';
    require $root . '/api.php';
    return true;
}

// ── 4. Fallback → SPA-Shell ─────────────────────────────────────────
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
return true;
