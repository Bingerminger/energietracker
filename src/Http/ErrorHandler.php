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
     * v3.10.0 (#41) — was verdeckt wird, entscheidet payload(): eine
     * erwartete Ausnahme mit eigenem Katalogtext (HttpStatusException, etwa die
     * Absage eines Cloud-Diensts mit 502) bleibt stehen, auch bei 5xx;
     * Fehler-ID und Verweis aufs Server-Log gibt es nur bei unerwarteten.
     *
     * v3.10.0 (Skeptiker) — Nach einem fatalen Fehler (etwa dem Zeitlimit)
     * hat PHP selbst schon „HTTP/1.0 500 …“ gesetzt; PHP 8.5 warnt dann bei
     * http_response_code(). Der Fehler-Handler machte daraus eine Ausnahme
     * mitten in der Fehlerantwort, und die Antwort blieb leer (seit v2.6.0,
     * sichtbar ab PHP 8.5). Jetzt setzt header() den Status mit, und eine
     * Warnung während der Antwort geht nur noch ins PHP-Log.
     *
     * @param array<string,mixed> $payload aus payload()
     * @param array<string,string> $headers zusätzliche Kopfzeilen (Retry-After)
     */
    private static function respond(int $status, array $payload, array $headers = []): void
    {
        set_error_handler(static fn(): bool => false);   // keine Ausnahme mehr aus einer Warnung, PHP protokolliert sie
        try {
            while (ob_get_level() > 0) ob_end_clean();
            if (!headers_sent()) {
                foreach ($headers as $k => $v) header($k . ': ' . $v);
                header('Content-Type: application/json; charset=utf-8', true, $status);
                header('Vary: X-ET-Language, Accept-Language');
                header('Cache-Control: no-cache, no-store, must-revalidate');
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * v3.10.0 (#41) — Körper der Fehlerantwort, ohne Ausgabe (testbar).
     * Verdeckt wird ein 5xx außer 503, und nur mit Fehler-ID.
     *
     * @param array<string,mixed> $detail
     * @return array<string,mixed>
     */
    public static function payload(int $status, string $message, array $detail, ?string $errorId, ?string $code = null): array
    {
        $masked = $errorId !== null && $status >= 500 && $status !== 503;
        $public = !$masked ? $message
            : (self::$translate ? (self::$translate)('errors.http.internal', ['id' => $errorId]) : "Internal error ({$errorId})");
        $payload = ['success' => false, 'error' => $public, 'code' => $code ?? Response::codeFor($public, $status)];
        if ($masked) $payload['error_id'] = $errorId;
        if (Response::debug()) $payload['detail'] = $detail + ['message' => $message];
        return $payload;
    }

    /**
     * v3.10.0 (#41) — Antwort und Log-Eintrag zu einer Ausnahme, ohne Ausgabe.
     * Eine HttpStatusException trägt einen Katalogschlüssel und einen eigenen
     * Text (ohne Pfade): Sie ist erwartet (der Dienst lehnt ab, die Sperre
     * ist belegt …), steht als `warning` im Log und bekommt keine Fehler-ID.
     * Alles andere bleibt wie bisher: `error` im Log, 5xx verdeckt mit ID.
     *
     * @return array{status:int, payload:array<string,mixed>, headers:array<string,string>, level:string, context:array<string,mixed>}
     */
    public static function describe(\Throwable $e, string $errorId): array
    {
        $status = self::statusFor($e);
        $expected = $e instanceof HttpStatusException;
        $detail = ['file' => basename($e->getFile()), 'line' => $e->getLine(), 'type' => $e::class];
        $code = $e instanceof \Energietracker\Storage\StorageCorruptedException ? 'errors.storage.corrupted' : null;
        $message = $e->getMessage();
        $headers = [];
        // v3.1.0 (Review I18N-14) — Schlüssel statt Rohtext: in der Sprache der Anfrage
        // v3.4.0 (N1020) — 413/503 mit Schlüssel und Kopfzeilen (Retry-After)
        if ($e instanceof HttpStatusException) {
            $code = $e->key;
            if (self::$translate) $message = (self::$translate)($e->key, $e->params);
            $headers = $e->headers;
        } elseif ($e instanceof \Energietracker\Support\LocalizedException) {
            $code = $e->key;
            if (self::$translate) $message = (self::$translate)($e->key, $e->params);
        } elseif ($e instanceof \Energietracker\Storage\StorageCorruptedException && self::$translate) {
            $message = (self::$translate)('errors.storage.' . $e->kind, ['file' => $e->dataFile, 'copy' => (string)$e->copy]);
        }
        return [
            'status'  => $status,
            'payload' => self::payload($status, $message, $detail, $expected ? null : $errorId, $code),
            'headers' => $headers,
            'level'   => $expected ? Logger::WARNING : Logger::ERROR,
            'context' => $detail + ['status' => $status] + ($expected ? ['code' => $code] : ['error_id' => $errorId]),
        ];
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
            $d = self::describe($e, 'err_' . bin2hex(random_bytes(4)));
            self::$logger?->log($d['level'], $e->getMessage(), $d['context']);
            self::respond($d['status'], $d['payload'], $d['headers']);
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
            self::respond(500, self::payload(500, (string)$e['message'], ['file' => basename((string)$e['file']), 'line' => $e['line']], $errorId));
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
        if ($e instanceof HttpStatusException) return $e->status;   // v3.4.0 (N1020)
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
