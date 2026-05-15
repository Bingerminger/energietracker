<?php
declare(strict_types=1);

namespace Energietracker\Http;

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode(
            ['success' => true, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    public static function error(string $message, int $status = 400, ?array $detail = null): never
    {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }
        $payload = ['success' => false, 'error' => $message];
        if ($detail !== null) $payload['detail'] = $detail;
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function noContent(): never
    {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) http_response_code(204);
        exit;
    }

    /**
     * Emit a CSV body as a file download (F-07). The body is expected to be
     * a complete CSV string (including any BOM); this only sets the headers
     * and writes it out.
     */
    public static function csv(string $body, string $filename, int $status = 200): never
    {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            http_response_code($status);
            // Override the JSON content type set by App::handle().
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }
        echo $body;
        exit;
    }
}
