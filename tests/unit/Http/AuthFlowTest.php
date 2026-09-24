<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\App;
use PHPUnit\Framework\TestCase;

/**
 * v2.6.0 — Anmeldung und HTTP-Verhalten gegen einen echten PHP-Server
 * (php -S … router.php mit eigenem, frischem Datenverzeichnis).
 *
 * Deckt ab, was nur über HTTP sichtbar ist: Cookie, 401 für geschützte
 * Routen, Minimalform von /api/health, Token-Pflicht für den Ingest,
 * Lese-Schlüssel, Sperre nach Fehlversuchen, HEAD, 405 und `code` in
 * Fehlerantworten ohne interne Details.
 */
final class AuthFlowTest extends TestCase
{
    private static $proc = null;
    private static string $base = '';
    private static string $dataDir = '';

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        self::$dataDir = sys_get_temp_dir() . '/et-auth-' . bin2hex(random_bytes(4));
        mkdir(self::$dataDir, 0755, true);
        $port = self::freePort();
        self::$base = "http://127.0.0.1:$port";
        $env = array_merge(getenv(), ['ET_DATA_DIR' => self::$dataDir, 'ET_LOG_DEST' => 'null']);
        unset($env['ET_AUTH'], $env['ET_ADMIN_PASSWORD_HASH'], $env['ET_ALLOWED_HOSTS'], $env['ET_DEBUG']);
        self::$proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", 'router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, $root, $env
        );
        for ($i = 0; $i < 100; $i++) {
            if (@file_get_contents(self::$base . '/api/health') !== false) return;
            usleep(50_000);
        }
        self::fail('Testserver startet nicht');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) { proc_terminate(self::$proc); proc_close(self::$proc); }
        exec('rm -rf ' . escapeshellarg(self::$dataDir));
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        return $port;
    }

    /** @return array{status:int, headers:array<string,string>, json:mixed} */
    private function req(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $h = [];
        foreach ($headers as $k => $v) $h[] = "$k: $v";
        if ($body !== null) $h[] = 'Content-Type: application/json';
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $h), 'ignore_errors' => true,
            'content' => $body !== null ? json_encode($body) : null,
        ]]);
        $raw = @file_get_contents(self::$base . $path, false, $ctx);
        $status = 0; $out = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) { $status = (int)$m[1]; continue; }
            [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
            $out[strtolower(trim($k))] = trim($v);
        }
        return ['status' => $status, 'headers' => $out, 'json' => json_decode((string)$raw, true)];
    }

    public function testErrorsCarryACodeAndNoInternals(): void
    {
        $r = $this->req('PATCH', '/api/utility/strom/readings/does-not-exist', ['note' => 'x']);
        self::assertSame(404, $r['status']);
        self::assertSame('errors.reading.notFound', $r['json']['code']);
        self::assertArrayNotHasKey('detail', $r['json'], 'Datei/Zeile nur mit ET_DEBUG');
    }

    public function testHeadAndMethodNotAllowed(): void
    {
        self::assertSame(200, $this->req('HEAD', '/api/health')['status']);
        $r = $this->req('PUT', '/api/settings', []);
        self::assertSame(405, $r['status']);
        self::assertStringContainsString('PATCH', $r['headers']['allow'] ?? '');
    }

    public function testTheLoginFlow(): void
    {
        self::assertSame(200, $this->req('GET', '/api/utilities')['status'], 'ohne Anmeldung offen wie bisher');

        $r = $this->req('POST', '/api/session/password', ['password' => 'kurz']);
        self::assertSame(400, $r['status']);

        $r = $this->req('POST', '/api/session/password', ['password' => 'geheim-123']);
        self::assertSame(200, $r['status']);
        self::assertSame('password', $r['json']['data']['mode']);
        self::assertMatchesRegularExpression('/et_session=\d+\.[0-9a-f]{64}/', $r['headers']['set-cookie'] ?? '');
        self::assertStringContainsString('HttpOnly', $r['headers']['set-cookie']);
        self::assertStringContainsString('SameSite=Strict', $r['headers']['set-cookie']);
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'], $m);
        $cookie = ['Cookie' => 'et_session=' . $m[1]];

        $r = $this->req('GET', '/api/utilities');
        self::assertSame(401, $r['status']);
        self::assertSame('errors.auth.required', $r['json']['code']);
        self::assertSame(200, $this->req('GET', '/api/utilities', null, $cookie)['status']);
        self::assertSame(401, $this->req('GET', '/api/utilities', null, ['Cookie' => 'et_session=' . time() + 999 . '.' . str_repeat('0', 64)])['status'],
            'gefälschtes Cookie');

        $h = $this->req('GET', '/api/health')['json']['data'];
        self::assertSame(['status', 'version'], array_keys($h), 'Minimalform ohne Anmeldung');
        self::assertArrayHasKey('checks', $this->req('GET', '/api/health', null, $cookie)['json']['data']);

        $r = $this->req('POST', '/api/ingest', ['utility' => 'strom', 'meter' => 'x', 'value' => 1]);
        self::assertSame(401, $r['status'], 'mit Anmeldung braucht der Ingest einen Token');

        // Lese-Schlüssel: lesen ja, schreiben nein
        $key = $this->req('POST', '/api/auth/keys', ['name' => 'HA', 'scope' => 'read'], $cookie)['json']['data']['key'];
        $bearer = ['Authorization' => 'Bearer ' . $key];
        self::assertSame(200, $this->req('GET', '/api/settings', null, $bearer)['status']);
        $r = $this->req('PATCH', '/api/settings', ['language' => 'en'], $bearer);
        self::assertSame(403, $r['status']);
        self::assertSame('errors.auth.readOnlyKey', $r['json']['code']);

        // Ausschalten verlangt das Passwort
        self::assertSame(401, $this->req('DELETE', '/api/session/password', ['current' => 'falsch'], $cookie)['status']);
        self::assertSame(200, $this->req('DELETE', '/api/session/password', ['current' => 'geheim-123'], $cookie)['status']);
        self::assertSame(200, $this->req('GET', '/api/utilities')['status'], 'wieder offen');
    }

    public function testRepeatedWrongPasswordsLock(): void
    {
        $this->req('POST', '/api/session/password', ['password' => 'noch-geheimer']);
        $codes = [];
        for ($i = 0; $i < 6; $i++) $codes[] = $this->req('POST', '/api/session', ['password' => 'falsch'])['status'];
        self::assertSame([401, 401, 401, 401, 429, 429], $codes);
        self::assertSame(429, $this->req('POST', '/api/session', ['password' => 'noch-geheimer'])['status'],
            'auch das richtige Passwort wartet die Sperre ab');
    }

    /** ET_ALLOWED_HOSTS — reine Funktion, ohne Server. */
    public function testHostAllowList(): void
    {
        putenv('ET_ALLOWED_HOSTS=energie.example.org,*.home.arpa');
        try {
            self::assertTrue(App::hostAllowed('energie.example.org'));
            self::assertTrue(App::hostAllowed('nas.home.arpa:8005'));
            self::assertTrue(App::hostAllowed('192.168.1.4:8005'), 'IP-Adressen immer');
            self::assertTrue(App::hostAllowed('[::1]:8080'));
            self::assertTrue(App::hostAllowed('localhost'));
            self::assertFalse(App::hostAllowed('rebind.attacker.example'));
        } finally {
            putenv('ET_ALLOWED_HOSTS');
        }
        self::assertTrue(App::hostAllowed('beliebig.example'), 'ohne Liste alle');
    }
}
