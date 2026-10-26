<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

/**
 * v3.9.0 (F1033) — nachgebautes Home Assistant (tests/fixtures/fake-ha.php)
 * über `php -S` auf einem festen Port (Standard 18741, `ET_HA_FAKE_PORT`).
 * Die Anfragen stehen als JSON-Zeilen in einer Datei (requests()).
 */
final class FakeHomeAssistant
{
    public const TOKEN = 'fake-ha-token-0123456789abcdef';

    /** @var resource|null */
    private $proc = null;
    public readonly string $base;
    public readonly string $log;

    public function __construct()
    {
        $port = (int)(getenv('ET_HA_FAKE_PORT') ?: 18741);
        $this->base = "http://127.0.0.1:$port";
        $this->log = sys_get_temp_dir() . '/et-fake-ha-' . bin2hex(random_bytes(4)) . '.jsonl';
        $env = array_merge(getenv(), ['FAKE_HA_LOG' => $this->log, 'FAKE_HA_TOKEN' => self::TOKEN]);
        unset($env['PHP_CLI_SERVER_WORKERS']);
        $fixtures = dirname(__DIR__, 2) . '/fixtures';
        $this->proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$port", "$fixtures/fake-ha.php"],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $fixtures, $env);
        for ($i = 0; $i < 100; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($fp) { fclose($fp); return; }
            usleep(50_000);
        }
        throw new \RuntimeException("Nachgebautes Home Assistant startet nicht auf Port $port");
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) { proc_terminate($this->proc); proc_close($this->proc); }
        $this->proc = null;
        @unlink($this->log);
    }

    public function reset(): void
    {
        @unlink($this->log);
    }

    /** @return list<array{path:string, method:string, headers:array<string,string>, body:mixed}> */
    public function requests(): array
    {
        if (!is_file($this->log)) return [];
        $out = [];
        foreach (file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $r = json_decode($line, true);
            if (is_array($r)) $out[] = $r;
        }
        return $out;
    }
}
