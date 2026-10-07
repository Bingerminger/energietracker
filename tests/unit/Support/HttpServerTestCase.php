<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * v3.1.0 — Tests über HTTP gegen einen echten PHP-Server (`php -S` mit
 * router.php) auf einem freien Port und einem eigenen Datenverzeichnis.
 * Für Kopfzeilen, Statuscodes und Zugriffsregeln, die ein Service-Test nicht
 * sieht.
 */
abstract class HttpServerTestCase extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    protected string $base = '';
    protected string $dataDir = '';

    /**
     * @param array<string,mixed> $settings Inhalt von settings.json
     * @param array<string,string> $env      zusätzliche Umgebungsvariablen (ET_AUTH …)
     */
    protected function startServer(array $settings = ['language' => 'de'], array $env = []): void
    {
        $root = dirname(__DIR__, 3);
        $this->dataDir = sys_get_temp_dir() . '/et-http-' . bin2hex(random_bytes(4));
        mkdir($this->dataDir, 0755, true);
        file_put_contents($this->dataDir . '/settings.json', json_encode($settings));
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $this->base = "http://127.0.0.1:$port";
        $all = array_merge(getenv(), ['ET_DATA_DIR' => $this->dataDir, 'ET_LOG_DEST' => 'null'], $env);
        foreach (['ET_AUTH', 'ET_ADMIN_PASSWORD_HASH', 'ET_ALLOWED_HOSTS', 'ET_DEBUG', 'ET_TRUSTED_PROXIES'] as $k) {
            if (!array_key_exists($k, $env)) unset($all[$k]);
        }
        $this->proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", 'router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, $root, $all
        );
        for ($i = 0; $i < 100; $i++) {
            if (@file_get_contents($this->base . '/api/health') !== false) return;
            usleep(50_000);
        }
        self::fail('Testserver startet nicht');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) { proc_terminate($this->proc); proc_close($this->proc); }
        if ($this->dataDir !== '') exec('rm -rf ' . escapeshellarg($this->dataDir));
        parent::tearDown();
    }

    /**
     * @param list<string> $headers
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    protected function request(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
            'ignore_errors' => true, 'timeout' => 20,
        ]]);
        $raw = @file_get_contents($this->base . $path, false, $ctx);
        $status = 0; $out = [];
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; $out = []; continue; }
            [$k, $v] = array_pad(explode(':', $h, 2), 2, '');
            $out[strtolower(trim($k))] = trim($v);
        }
        return ['status' => $status, 'headers' => $out, 'body' => (string)$raw];
    }

    /** @return mixed `data` einer JSON-Antwort */
    protected function json(string $method, string $path, mixed $payload = null, array $headers = []): mixed
    {
        $h = array_merge($payload !== null ? ['Content-Type: application/json'] : [], $headers);
        $r = $this->request($method, $path, $h, $payload !== null ? json_encode($payload) : null);
        $d = json_decode($r['body'], true);
        return is_array($d) && array_key_exists('data', $d) ? $d['data'] : $d;
    }
}
