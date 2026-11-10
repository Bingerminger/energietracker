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
        // v3.12.0 — eine Zahl, die JSON nicht kennt (INF, NaN), ergab bisher 200 mit leerem
        // Körper. Jetzt ein unerwarteter Fehler wie jeder andere: 500 mit Fehler-ID, Log
        // (ungültiges UTF-8 wird wie in der Fehlerantwort ersetzt statt den ganzen Körper zu verlieren)
        $body = json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) throw new \RuntimeException('response not encodable: ' . json_last_error_msg());
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Content-Type-Options: nosniff');
            // v3.1.0 (Review I18N-29) — Texte hängen an der Sprache des Geräts;
            // ein Cache (Service Worker offline) darf sie nicht vermischen.
            header('Vary: X-ET-Language, Accept-Language');
        }
        echo $body;
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
            header('Vary: X-ET-Language, Accept-Language');   // v3.1.0 — Meldung in der Sprache des Geräts
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }
        $payload = ['success' => false, 'error' => $message, 'code' => $code ?? self::codeFor($message, $status)];
        if ($detail !== null && ($publicDetail || self::debug())) $payload['detail'] = $detail;
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /**
     * v3.12.0 (F1037) — JSON ohne Hülle `{success, data}` (MCP spricht JSON-RPC),
     * mit Status und eigenen Kopfzeilen; ohne Körper (null) etwa `202 Accepted`.
     *
     * @param array<string,mixed>|object|null $body
     * @param array<string,string> $headers
     */
    public static function raw(array|object|null $body, int $status = 200, array $headers = []): never
    {
        // v3.12.0 — erst kodieren, dann senden: eine nicht kodierbare Zahl ergab sonst den Status ohne Körper
        $json = null;
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
            if ($json === false) throw new \RuntimeException('response not encodable: ' . json_last_error_msg());
        }
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            if ($body === null) header_remove('Content-Type');
            else header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Content-Type-Options: nosniff');
            foreach ($headers as $k => $v) header($k . ': ' . str_replace(["\r", "\n"], '', $v));
            // zuletzt: PHP setzt mit WWW-Authenticate selbst 401, ein 403 „insufficient_scope“ ginge sonst verloren
            http_response_code($status);
        }
        // Kommazahlen bleiben Kommazahlen (10.0, nicht 10): Werte der Zahl-Objekte sind immer number
        if ($json !== null) echo $json;
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
