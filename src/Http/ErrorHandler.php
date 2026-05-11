<?php
declare(strict_types=1);

namespace Energietracker\Http;

final class ErrorHandler
{
    public static function install(): void
    {
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
     * Map common exception types to HTTP status codes:
     *   InvalidArgumentException → 400 (Bad Request — validation)
     *   RuntimeException with "nicht gefunden"/"not found" → 404
     *   everything else → 500
     */
    private static function statusFor(\Throwable $e): int
    {
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
