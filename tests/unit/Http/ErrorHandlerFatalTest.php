<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * v3.10.0 (Skeptiker, Beobachtung) — Auch eine Anfrage, die mit einem
 * fatalen Fehler endet, bekommt eine gültige JSON-Antwort.
 *
 * Auf dem Entwicklungsserver brach ein Wetterabgleich am Zeitlimit ab. PHP
 * setzt dann selbst die Statuszeile „HTTP/1.0 500 Internal Server Error“;
 * unter PHP 8.5 warnt http_response_code() danach, der Fehler-Handler machte
 * aus der Warnung eine Ausnahme mitten in ErrorHandler::respond(), und die
 * Antwort blieb leer (unter PHP-FPM genauso). Gegen einen echten
 * `php -S` mit tests/fixtures/fatal-request.php.
 */
final class ErrorHandlerFatalTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        self::$base = "http://127.0.0.1:$port";
        $env = getenv();
        unset($env['PHP_CLI_SERVER_WORKERS'], $env['ET_DEBUG']);
        self::$server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', "127.0.0.1:$port",
            dirname(__DIR__, 2) . '/fixtures/fatal-request.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__, 2) . '/fixtures', $env);
        for ($i = 0; $i < 100; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($fp) { fclose($fp); return; }
            usleep(50_000);
        }
        self::fail('Testserver startet nicht');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) { proc_terminate(self::$server); proc_close(self::$server); }
    }

    /** @return array{status:int, type:string, body:string} */
    private static function get(string $mode): array
    {
        $raw = @file_get_contents(self::$base . '/?mode=' . $mode, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]));
        $status = 0; $type = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
            if (stripos($h, 'content-type:') === 0) $type = trim(substr($h, 13));
        }
        return ['status' => $status, 'type' => $type, 'body' => (string)$raw];
    }

    private static function assertInternalErrorJson(array $r, string $label): void
    {
        self::assertSame(500, $r['status'], $label);
        self::assertStringStartsWith('application/json', $r['type'], "$label: JSON, nicht die leere HTML-Antwort von PHP");
        $j = json_decode($r['body'], true);
        self::assertIsArray($j, "$label: Körper ist gültiges JSON ({$r['body']})");
        self::assertFalse($j['success']);
        self::assertSame('errors.http.internal', $j['code']);
        self::assertMatchesRegularExpression('/^err_[0-9a-f]{8}$/', (string)$j['error_id']);
        self::assertStringContainsString($j['error_id'], (string)$j['error'], 'die Meldung nennt die Fehler-ID');
        self::assertStringNotContainsString('/', (string)$j['error'], "$label: kein Pfad an den Client");
    }

    /** Zeitlimit überschritten (fataler Fehler, Shutdown-Weg). */
    public function testATimeoutEndsWithAJsonAnswer(): void
    {
        self::assertInternalErrorJson(self::get('timeout'), 'Zeitlimit');
    }

    /** Statuszeile schon gesetzt, dann eine Ausnahme (Ausnahme-Weg): unser Status, gültiges JSON. */
    public function testAnExceptionAfterAStatusLineEndsWithAJsonAnswer(): void
    {
        self::assertInternalErrorJson(self::get('statusline'), 'Statuszeile');
    }
}
