<?php
declare(strict_types=1);

namespace Energietracker\Http;

use Energietracker\Logging\Logger;

final class ErrorHandler
{
    private static ?Logger $logger = null;

    /**
     * v2.6.0 — übersetzt die generische Meldung für unerwartete Fehler.
     * Vom App-Container gesetzt, sobald der I18nService steht.
     *
     * @var (callable(string, array<string,mixed>): string)|null
     */
    private static $translate = null;

    public static function attachTranslator(?callable $translate): void
    {
        self::$translate = $translate;
    }

    /**
     * v2.6.0 — eine Fehlerantwort für alle Wege (Ausnahme, fataler Fehler).
     *
     * Unerwartete Fehler (5xx außer 503) melden dem Client nur noch eine
     * generische Meldung mit Fehler-ID; die eigentliche Meldung samt Datei und
     * Zeile steht unter derselben ID im Log. Bis v2.5.3 ging sie an jeden
     * Client — bei TypeErrors mit absolutem Pfad und Benutzernamen.
     *
     * @param array<string,mixed> $detail
     */
    private static function respond(int $status, string $message, array $detail, string $errorId, ?string $code = null): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        $public = $message;
        if ($status >= 500 && $status !== 503) {
            $public = self::$translate
                ? (self::$translate)('errors.http.internal', ['id' => $errorId])
                : "Internal error ({$errorId})";
        }
        $code ??= Response::codeFor($status >= 500 && $status !== 503 ? $public : $message, $status);
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
        }
        $payload = ['success' => false, 'error' => $public, 'code' => $code];
        if ($status >= 500 && $status !== 503) $payload['error_id'] = $errorId;
        if (Response::debug()) $payload['detail'] = $detail + ['message' => $message];
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param Logger|null $logger Optionaler strukturierter Logger (N1010).
     *        Ist er gesetzt, werden Exceptions und fatale Fehler als
     *        Log-Eintrag (Level error) geschrieben, bevor die JSON-Antwort
     *        rausgeht.
     */
    public static function install(?Logger $logger = null): void
    {
        self::$logger = $logger;
        ob_start();
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        set_error_handler(function (int $severity, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) return false;
            // v2.5.3 — E_STRICT entfernt: seit PHP 8.0 nie mehr ausgelöst, seit 8.4
            // selbst als Konstante veraltet.
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE], true)) {
                return true;
            }
            throw new \ErrorException($msg, 0, $severity, $file, $line);
        });

        set_exception_handler(function (\Throwable $e): void {
            $status  = self::statusFor($e);
            $errorId = 'err_' . bin2hex(random_bytes(4));
            $detail  = ['file' => basename($e->getFile()), 'line' => $e->getLine(), 'type' => $e::class];
            self::$logger?->error($e->getMessage(), $detail + ['status' => $status, 'error_id' => $errorId]);
            $code = $e instanceof \Energietracker\Storage\StorageCorruptedException ? 'errors.storage.corrupted' : null;
            self::respond($status, $e->getMessage(), $detail, $errorId, $code);
            exit;
        });

        register_shutdown_function(function (): void {
            $e = error_get_last();
            if (!$e) return;
            if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
            $errorId = 'err_' . bin2hex(random_bytes(4));
            self::$logger?->error('Fataler Serverfehler: ' . $e['message'], [
                'file'     => $e['file'],
                'line'     => $e['line'],
                'type'     => $e['type'],
                'error_id' => $errorId,
            ]);
            // Die Meldung eines fatalen Fehlers enthält oft absolute Pfade —
            // an den Client geht nur die generische Meldung mit ID.
            self::respond(500, (string)$e['message'], ['file' => basename((string)$e['file']), 'line' => $e['line']], $errorId);
        });
    }

    /**
     * Ordnet Ausnahmen einem HTTP-Status zu:
     *   NotFoundException          → 404
     *   InvalidArgumentException   → 400 (Validierung)
     *   alles andere               → 500
     *
     * v2.2.1 — Der 404-Fall hing zuvor am Wortlaut der Meldung
     * (`str_contains($msg, 'nicht gefunden')` bzw. `'not found'`). Seit die
     * Dienste lokalisiert werfen, traf das nur noch auf Deutsch und Englisch
     * zu: Eine spanische Oberfläche meldet „Contador no encontrado", eine
     * französische „Compteur introuvable" — beide ergaben 500 statt 404.
     * Jetzt entscheidet der Typ. Die Textprüfung bleibt als Rückfall für
     * Stellen, die noch eine nackte RuntimeException werfen.
     */
    private static function statusFor(\Throwable $e): int
    {
        // v2.5.3 — beschädigte Datendatei: vorübergehend nicht verfügbar,
        // nicht „Serverfehler" (die Anwendung arbeitet korrekt, sie weigert sich
        // nur, eine kaputte Datei zu überschreiben).
        if ($e instanceof \Energietracker\Storage\StorageCorruptedException) return 503;
        if ($e instanceof NotFoundException) return 404;
        if ($e instanceof ConflictException) return 409;   // v2.6.0
        if ($e instanceof \InvalidArgumentException) return 400;
        if ($e instanceof \RuntimeException) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'nicht gefunden') || str_contains($msg, 'not found')) {
                return 404;
            }
        }
        return 500;
    }
}
