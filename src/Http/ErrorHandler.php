<?php
declare(strict_types=1);

namespace Energietracker\Http;

use Energietracker\Logging\Logger;

final class ErrorHandler
{
    private static ?Logger $logger = null;

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
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE, E_STRICT], true)) {
                return true;
            }
            throw new \ErrorException($msg, 0, $severity, $file, $line);
        });

        set_exception_handler(function (\Throwable $e): void {
            while (ob_get_level() > 0) ob_end_clean();
            $status = self::statusFor($e);
            self::$logger?->error($e->getMessage(), [
                'type'   => $e::class,
                'file'   => basename($e->getFile()),
                'line'   => $e->getLine(),
                'status' => $status,
            ]);
            if (!headers_sent()) {
                http_response_code($status);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
                'detail'  => [
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                    'type' => $e::class,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        });

        register_shutdown_function(function (): void {
            $e = error_get_last();
            if (!$e) return;
            if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
            while (ob_get_level() > 0) ob_end_clean();
            self::$logger?->error('Fataler Serverfehler: ' . $e['message'], [
                'file' => $e['file'],
                'line' => $e['line'],
                'type' => $e['type'],
            ]);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'error'   => 'Fataler Serverfehler: ' . $e['message'],
                'detail'  => "{$e['file']}:{$e['line']}",
            ]);
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
        if ($e instanceof NotFoundException) return 404;
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
