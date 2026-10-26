<?php
declare(strict_types=1);

/**
 * v3.9.0 (F1033) — nachgebautes Home Assistant für die Tests (Router für
 * `php -S`). Kein echtes Home Assistant wird je angesprochen.
 *
 *   POST /api/webhook/<id>                 200, ohne Prüfung (wie Home Assistant: nur die ID zählt)
 *   POST /api/services/<domain>/<service>  Bearer FAKE_HA_TOKEN, sonst 401; `[]`
 *   POST /core/api/services/<domain>/<service>  dasselbe für den Weg über den Supervisor
 *   Dienst notify.unbekannt                400 (wie bei einer unbekannten Aktion)
 *
 * Jede Anfrage steht als JSON-Zeile in FAKE_HA_LOG (Pfad, Kopfzeilen, Körper),
 * für Aussagen im Test.
 */

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
}
if (isset($_SERVER['CONTENT_TYPE'])) $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
$raw = (string)file_get_contents('php://input');
$log = getenv('FAKE_HA_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode(['path' => $path, 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'headers' => $headers, 'body' => json_decode($raw, true)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"message":"Method not allowed"}'; return true; }
if (preg_match('#^/api/webhook/[^/]+$#', $path)) { http_response_code(200); return true; }
if (preg_match('#^(/core)?/api/services/([a-z_]+)/([a-z0-9_]+)$#', $path, $m)) {
    $want = 'Bearer ' . (string)getenv('FAKE_HA_TOKEN');
    if (($headers['authorization'] ?? '') !== $want) { http_response_code(401); echo '{"message":"401: Unauthorized"}'; return true; }
    if ($m[2] === 'notify' && $m[3] === 'unbekannt') { http_response_code(400); echo '{"message":"Service not found."}'; return true; }
    echo '[]';
    return true;
}
http_response_code(404);
echo '{"message":"Not found"}';
return true;
