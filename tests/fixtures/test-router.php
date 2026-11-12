<?php
declare(strict_types=1);

/**
 * v3.13.0 — Router des Testservers (HttpServerTestCase): richtet einen Handler
 * für SIGUSR1 ein und gibt dann an router.php ab, unverändert (auch `false`
 * für statische Dateien). Auf das Signal schreibt der Server den PHP-Stack der
 * laufenden Anfrage in sein Fehlerprotokoll.
 *
 * Warum: In der CI blieb bei PHP 8.3 selten eine Antwort aus (Status 0,
 * McpReadEndpointTest, `get_contracts`), der Server lief weiter, und weder
 * lokal noch im Container ließ es sich nachstellen. Bleibt eine Antwort aus,
 * schickt `request()` das Signal und zeigt den Stack in der Meldung des Tests.
 * Ein eigener Router statt `auto_prepend_file`: Den führt `php -S` unter
 * PHP 8.3 für das Router-Skript nicht aus (nachgeprüft mit 8.3.36).
 * Ohne die Erweiterung pcntl gibt die Datei nur ab.
 */
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && defined('SIGUSR1')) {
    pcntl_async_signals(true);
    // restart_syscalls = false: Sonst startet der Kern ein blockierendes flock() oder read() nach dem
    // Signal neu, PHP bekäme die Kontrolle nie zurück und der Stack bliebe aus
    pcntl_signal(SIGUSR1, static function (): void {
        // php://stderr ist das Protokoll des Testservers; error_log() könnte die App umgelenkt haben
        @file_put_contents('php://stderr', "ET-TRACE (Stack der hängenden Anfrage)\n"
            . (new \Exception())->getTraceAsString() . "\n");
    }, false);
}

return require dirname(__DIR__, 2) . '/router.php';
