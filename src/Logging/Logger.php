<?php
declare(strict_types=1);

namespace Energietracker\Logging;

/**
 * Minimaler strukturierter Logger (N1010, v1.7.3).
 *
 * PSR-3-*orientiert* (gleiche Level-Namen und log()-Signatur), aber bewusst
 * OHNE die psr/log-Dependency — die Laufzeit bleibt Composer-frei (siehe
 * src/bootstrap.php). Es wird ein JSON-Objekt pro Zeile geschrieben
 * ("JSON Lines"), was sich gut von `docker logs`, jq oder einem
 * Log-Sammler weiterverarbeiten lässt.
 *
 * Konfiguration über Umgebungsvariablen (alle optional):
 *   ET_LOG_LEVEL  debug|info|warning|error   (Default: info)
 *   ET_LOG_DEST   stderr|file|null           (Default: stderr)
 *   ET_LOG_FILE   Pfad bei ET_LOG_DEST=file  (Default: <dataDir>/logs/app.log)
 *
 * ET_LOG_DEST=null schaltet Logging vollständig ab (z. B. für Tests).
 */
final class Logger
{
    public const DEBUG   = 'debug';
    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    /** Numerische Priorität je Level für die Schwellwert-Prüfung. */
    private const PRIORITY = [
        self::DEBUG   => 10,
        self::INFO    => 20,
        self::WARNING => 30,
        self::ERROR   => 40,
    ];

    private string $minLevel;
    private string $dest;        // 'stderr' | 'file' | 'null'
    private ?string $file;

    public function __construct(?string $level = null, ?string $dest = null, ?string $file = null)
    {
        $level = strtolower($level ?? ((string)getenv('ET_LOG_LEVEL') ?: self::INFO));
        $this->minLevel = isset(self::PRIORITY[$level]) ? $level : self::INFO;

        $dest = strtolower($dest ?? ((string)getenv('ET_LOG_DEST') ?: 'stderr'));
        $this->dest = in_array($dest, ['stderr', 'file', 'null'], true) ? $dest : 'stderr';

        $this->file = $file ?? ((string)getenv('ET_LOG_FILE') ?: null);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Schreibt einen Log-Eintrag, sofern $level >= konfigurierter Schwellwert.
     * Logging darf den Request niemals zum Absturz bringen — alle I/O-Fehler
     * werden bewusst verschluckt.
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        $prio  = self::PRIORITY[$level] ?? self::PRIORITY[self::INFO];
        if ($this->dest === 'null' || $prio < self::PRIORITY[$this->minLevel]) {
            return;
        }

        $record = [
            'ts'    => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'msg'   => $message,
        ];

        // Request-Korrelation, falls im HTTP-Kontext aufgerufen.
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $uri    = $_SERVER['REQUEST_URI'] ?? null;
        if ($method !== null) $record['method'] = $method;
        if ($uri !== null)    $record['uri']    = $uri;

        if ($context !== []) {
            $record['ctx'] = $context;
        }

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = json_encode([
                'ts'    => gmdate('Y-m-d\TH:i:s\Z'),
                'level' => $level,
                'msg'   => $message,
                'ctx'   => ['_logger' => 'context not JSON-serializable'],
            ]);
        }

        $this->write($line . "\n");
    }

    private function write(string $line): void
    {
        if ($this->dest === 'file') {
            $path = $this->file ?? (sys_get_temp_dir() . '/energietracker.log');
            $dir  = \dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
            return;
        }

        // Default: stderr (Docker-idiomatisch — landet in `docker logs`).
        $fh = @fopen('php://stderr', 'ab');
        if ($fh !== false) {
            @fwrite($fh, $line);
            @fclose($fh);
        }
    }
}
