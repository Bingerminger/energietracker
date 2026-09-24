<?php
declare(strict_types=1);

namespace Energietracker\Http;

final class Response
{
    /**
     * v2.6.0 — liefert zu einer Fehlermeldung den stabilen Code
     * (Katalogschlüssel), s. I18nService::errorCodeFor(). Vom App-Container
     * gesetzt; ohne ihn (Router-Fehler vor dem Start) bleibt der Code generisch.
     *
     * @var (callable(string): ?string)|null
     */
    private static $codeResolver = null;

    public static function setErrorCodeResolver(?callable $resolver): void
    {
        self::$codeResolver = $resolver;
    }

    /**
     * v2.6.0 — Fehlerdetails (Datei, Zeile, Ausnahmetyp) nur mit ET_DEBUG=1.
     * Bis v2.5.3 gingen sie an jeden Client, samt absoluter Pfade.
     */
    public static function debug(): bool
    {
        return in_array(strtolower((string)getenv('ET_DEBUG')), ['1', 'true', 'yes', 'on'], true);
    }

    /** Stabiler Fehlercode zu einer Meldung; Rückfall je Statusklasse. */
    public static function codeFor(string $message, int $status): string
    {
        $code = self::$codeResolver ? (self::$codeResolver)($message) : null;
        if (is_string($code) && $code !== '') return $code;
        return match (true) {
            $status === 401 => 'errors.http.unauthorized',
            $status === 403 => 'errors.http.forbidden',
            $status === 404 => 'errors.http.notFound',
            $status === 405 => 'errors.http.methodNotAllowed',
            $status === 503 => 'errors.http.unavailable',
            $status >= 500  => 'errors.http.internal',
            default         => 'errors.http.badRequest',
        };
    }

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

    /**
     * Fehlerantwort `{success:false, error, code[, detail]}`.
     *
     * `code` (v2.6.0) ist der Katalogschlüssel der Meldung — stabil über
     * Sprachen und Formulierungen hinweg. `detail` erscheint nur mit
     * ET_DEBUG=1 oder wenn es fachlicher Inhalt ist (`$publicDetail`).
     */
    public static function error(string $message, int $status = 400, ?array $detail = null, ?string $code = null, bool $publicDetail = false): never
    {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }
        $payload = ['success' => false, 'error' => $message, 'code' => $code ?? self::codeFor($message, $status)];
        if ($detail !== null && ($publicDetail || self::debug())) $payload['detail'] = $detail;
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
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
