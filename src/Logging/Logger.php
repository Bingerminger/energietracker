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
        // v3.12.0 — Härtung: die URI ohne Zugangsdaten (safeUri)
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $rawUri = $_SERVER['REQUEST_URI'] ?? null;
        if ($method !== null) $record['method'] = $method;
        if (is_string($rawUri)) $record['uri'] = self::safeUri($rawUri);

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

    /**
     * v3.12.0 — Härtung: Namen von Abfrageparametern, deren Wert nie ins
     * Protokoll gehört (auch mit Präfix, etwa `access_token`, `api_key`).
     */
    private const SECRET_PARAMS = '/(^|[_-])(token|key|apikey|secret|password|passwd|pass|auth|session|signature|sig)$/i';

    /**
     * v3.12.0 — Härtung: Anfrage-URI fürs Protokoll. Das Kalender-Abo trägt seinen
     * Schlüssel im Link (`?token=…`), bis v3.11 stand er mit jeder Meldung
     * während eines Abrufs im Protokoll. Pfad und harmlose Parameter bleiben für
     * die Fehlersuche; ersetzt (`***`) werden die Werte der Zugangsparameter
     * (SECRET_PARAMS) und jeder Wert, der wie ein Schlüssel der App aussieht
     * (`etk_…`, `et_…`), auch im Pfad.
     */
    public static function safeUri(string $uri): string
    {
        $keyShape = '/\b(etk|et)_[0-9a-f]{16,}/i';
        $q = strpos($uri, '?');
        $path = (string)preg_replace($keyShape, '$1_***', $q === false ? $uri : substr($uri, 0, $q));
        if ($q === false) return $path;
        $pairs = [];
        foreach (explode('&', substr($uri, $q + 1)) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) { $pairs[] = $pair; continue; }
            $name = substr($pair, 0, $eq);
            $value = substr($pair, $eq + 1);
            $secret = preg_match(self::SECRET_PARAMS, rawurldecode($name)) === 1
                || preg_match($keyShape, rawurldecode($value)) === 1;
            $pairs[] = $name . '=' . ($secret && $value !== '' ? '***' : $value);
        }
        return $path . '?' . implode('&', $pairs);
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
