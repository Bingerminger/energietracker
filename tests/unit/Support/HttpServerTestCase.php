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
    /** v3.13.0 — Wartezeit von request() in Sekunden (der Selbsttest der Diagnose wartet kürzer) */
    protected int $requestTimeout = 20;

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
        // v3.13.0 — Diagnose für eine ausbleibende Antwort: Eine Rechenschleife bricht nach 12 s Rechenzeit
        // mit Datei und Zeile im Protokoll ab, eine lange C-Funktion spätestens nach 12 + 2 s (harte
        // Grenze von PHP), beides vor der Wartezeit von request(); wartet der Server (Sperre, Netz),
        // schreibt er auf SIGUSR1 seinen Stack (Test-Router vor router.php)
        $ini = ['-d', 'max_execution_time=12'];
        foreach ($this->serverIni as $k => $v) array_push($ini, '-d', "$k=$v");
        $this->proc = proc_open(
            [PHP_BINARY, '-d', 'post_max_size=256M', '-d', 'display_errors=0', '-d', 'display_startup_errors=0',
             ...$ini, '-S', "127.0.0.1:$port", 'tests/fixtures/test-router.php'],
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
            'ignore_errors' => true, 'timeout' => $this->requestTimeout,
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
            $err = (string)(error_get_last()['message'] ?? '-');
            $st = is_resource($this->proc) ? proc_get_status($this->proc) : [];
            // v3.13.0 — wo der Server steckt: erst der Zustand aus /proc (Linux; rechnet oder wartet er,
            // welche Dateien des Datenordners hält er offen), dann sein PHP-Stack auf SIGUSR1
            $where = $this->serverWhereabouts((int)($st['pid'] ?? 0), !empty($st['running']));
            $log = $this->serverLog !== '' ? @file_get_contents($this->serverLog) : false;
            $raw = sprintf("[keine Antwort auf %s %s] Server läuft: %s, Signal: %s, Exit: %s, Fehler: %s%s\nProtokoll (Ende):\n%s",
                $method, $path, var_export($st['running'] ?? null, true), (string)($st['termsig'] ?? '-'),
                (string)($st['exitcode'] ?? '-'), $err, $where,
                $log === false ? '(keins)' : substr($log, -4000));
        }
        return ['status' => $status, 'headers' => $out, 'body' => (string)$raw];
    }

    /**
     * v3.13.0 — Zustand des hängenden Testservers für die Meldung: unter Linux Zustand und Wartestelle
     * des Kernels und die offenen Dateien im Datenordner (etwa eine Sperrdatei). Danach SIGUSR1: Der
     * Server schreibt seinen PHP-Stack ins Protokoll (tests/fixtures/test-router.php), request() hängt es an.
     * Die Reihenfolge zählt: Das Signal unterbricht ein blockierendes flock().
     */
    private function serverWhereabouts(int $pid, bool $running): string
    {
        if ($pid <= 0 || !$running) return '';
        $out = '';
        if (is_readable("/proc/$pid/status")) {
            preg_match('/^State:\s*(.+)$/m', (string)@file_get_contents("/proc/$pid/status"), $m);
            $wchan = trim((string)@file_get_contents("/proc/$pid/wchan"));
            $files = [];
            foreach (@scandir("/proc/$pid/fd") ?: [] as $fd) {
                $t = @readlink("/proc/$pid/fd/$fd");
                if (is_string($t) && str_starts_with($t, $this->dataDir . '/')) $files[] = substr($t, strlen($this->dataDir) + 1);
            }
            $out .= sprintf(', Zustand: %s, wartet in: %s, offen im Datenordner: %s',
                $m[1] ?? '-', $wchan !== '' && $wchan !== '0' ? $wchan : '-', $files !== [] ? implode(' ', $files) : '-');
        }
        if (function_exists('posix_kill') && defined('SIGUSR1') && $this->serverLog !== '') {
            @posix_kill($pid, SIGUSR1);
            for ($i = 0; $i < 30 && !str_contains((string)@file_get_contents($this->serverLog), 'ET-TRACE'); $i++) usleep(100_000);
            if (!str_contains((string)@file_get_contents($this->serverLog), 'ET-TRACE')) $out .= ', Stack: keiner (Signal ohne Antwort)';
        } else {
            $out .= ', Stack: keiner (ohne pcntl/posix)';
        }
        return $out;
    }

    /** v3.13.0 — Ende des Serverprotokolls für Meldungen von Tests (etwa „Maximum execution time … on line …“) */
    protected function serverLogTail(int $bytes = 3000): string
    {
        $log = $this->serverLog !== '' ? @file_get_contents($this->serverLog) : false;
        return $log === false ? '(kein Protokoll)' : "Protokoll (Ende):\n" . substr($log, -$bytes);
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
