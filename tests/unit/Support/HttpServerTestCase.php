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
    /** Fehlerausgabe des Servers, neben (nicht in) dem Datenordner: ein leerer Ordner bleibt leer */
    private string $serverLog = '';
    /** @var array<string,string> v3.12.0 — zusätzliche `-d`-Werte des Testservers (etwa display_errors=1 wie `php -S` von Hand) */
    protected array $serverIni = [];

    /**
     * @param array<string,mixed> $settings Inhalt von settings.json
     * @param array<string,string> $env      zusätzliche Umgebungsvariablen (ET_AUTH …)
     */
    /**
     * @param bool $firstCall v3.12.0 — nach der Bereitschaftsprobe einmal die Einstellungen abrufen wie
     *        der erste Aufruf der Oberfläche: Der Gesundheitscheck allein legt ein leeres
     *        Datenverzeichnis nicht mehr an. false lässt es leer (Tests des Erststarts).
     */
    protected function startServer(array $settings = ['language' => 'de'], array $env = [], bool $firstCall = true): void
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
        foreach (['ET_AUTH', 'ET_ADMIN_PASSWORD_HASH', 'ET_ALLOWED_HOSTS', 'ET_DEBUG', 'ET_TRUSTED_PROXIES',
                  'ET_PROXY_USER_HEADER', 'ET_LOCK_WAIT_S', 'ET_CA_FILE', 'ET_MCP'] as $k) {
            if (!array_key_exists($k, $env)) unset($all[$k]);
        }
        // v3.4.0 — Größengrenzen wie im Docker-Image (docker/php.ini), damit die
        // Grenzen der App greifen und nicht die des Entwicklungs-PHP (8 MB)
        $ini = [];
        foreach ($this->serverIni as $k => $v) array_push($ini, '-d', "$k=$v");
        $this->proc = proc_open(
            [PHP_BINARY, '-d', 'post_max_size=256M', '-d', 'display_errors=0', '-d', 'display_startup_errors=0',
             ...$ini, '-S', "127.0.0.1:$port", 'router.php'],
            // Fehlerausgabe in eine Datei: bleibt eine Antwort aus (Status 0), zeigt request() sie an
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->serverLog = $this->dataDir . '.log', 'a']],
            $pipes, $root, $all
        );
        for ($i = 0; $i < 100; $i++) {
            if (@file_get_contents($this->base . '/api/health') !== false) {
                if ($firstCall) @file_get_contents($this->base . '/api/settings', false, stream_context_create(['http' => ['ignore_errors' => true]]));
                return;
            }
            usleep(50_000);
        }
        self::fail('Testserver startet nicht');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) { proc_terminate($this->proc); proc_close($this->proc); }
        if ($this->dataDir !== '') exec('rm -rf ' . escapeshellarg($this->dataDir));
        if ($this->serverLog !== '') @unlink($this->serverLog);
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
        // v3.12.0 — keine Antwort: Zustand des Servers und Ende seines Protokolls in die Meldung des Tests
        // (McpReadEndpointTest bekam in der CI selten Status 0 bei get_contracts, ohne erkennbaren Grund)
        if ($status === 0 && $raw === false) {
            $st = is_resource($this->proc) ? proc_get_status($this->proc) : [];
            $log = $this->serverLog !== '' ? @file_get_contents($this->serverLog) : false;
            $raw = sprintf("[keine Antwort auf %s %s] Server läuft: %s, Signal: %s, Exit: %s, Fehler: %s\nProtokoll (Ende):\n%s",
                $method, $path, var_export($st['running'] ?? null, true), (string)($st['termsig'] ?? '-'),
                (string)($st['exitcode'] ?? '-'), (string)(error_get_last()['message'] ?? '-'),
                $log === false ? '(keins)' : substr($log, -2000));
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
