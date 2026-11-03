<?php
declare(strict_types=1);

/**
 * v3.10.0 (Skeptiker, Beobachtung) — eine Anfrage, die mit einem fatalen
 * Fehler endet, für ErrorHandlerFatalTest (über `php -S`).
 *
 *   ?mode=timeout     Zeitlimit überschritten (wie ein langer Wetterabgleich)
 *   ?mode=statusline  eine Statuszeile ist schon gesetzt, dann eine Ausnahme
 *
 * Bei einem fatalen Fehler setzt PHP selbst die Statuszeile
 * „HTTP/1.0 500 Internal Server Error“ (display_errors aus). Unter PHP 8.5
 * warnt http_response_code() danach; der ErrorHandler machte daraus eine
 * Ausnahme mitten in der Fehlerantwort, und die Antwort blieb leer.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Energietracker\Http\ErrorHandler;

ErrorHandler::install(null);
$mode = (string)($_GET['mode'] ?? 'timeout');
if ($mode === 'statusline') {
    header('HTTP/1.1 502 Bad Gateway');
    throw new \RuntimeException('Fehler nach gesetzter Statuszeile in /pfad/zur/datei.php');
}
set_time_limit(1);
$x = '';
while (true) $x = hash('sha256', $x . microtime());
