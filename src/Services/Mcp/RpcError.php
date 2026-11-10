<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

/**
 * v3.12.0 (F1037) — Ein Protokollfehler: JSON-RPC-Fehler mit HTTP-Status.
 *
 * Der Server macht daraus `{jsonrpc, id, error: {code, message, data: {code: <Katalogschlüssel>, …}}}`.
 * `message` ist der übersetzte Text (Sprache des Servers), `data.code` der
 * stabile Schlüssel (keine Logik am Wortlaut). Fachliche Fehler eines Werkzeugs
 * sind KEIN RpcError, sondern ein Ergebnis mit `isError: true` (LocalizedException).
 */
final class RpcError extends \RuntimeException
{
    /**
     * @param int $status   HTTP-Status der Antwort
     * @param int $rpcCode  JSON-RPC-Code (Server::PARSE_ERROR …, Server::ERR_*)
     * @param string $key   Katalogschlüssel `errors.mcp.*`
     * @param array<string,scalar> $params Platzhalter des Texts
     * @param array<string,mixed> $data   zusätzliche Felder in `error.data`
     * @param array<string,string> $headers zusätzliche Kopfzeilen (WWW-Authenticate, Retry-After)
     */
    public function __construct(
        public readonly int $status,
        public readonly int $rpcCode,
        public readonly string $key,
        public readonly array $params = [],
        public readonly array $data = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($key);
    }

    /**
     * JSON-RPC-Fehlerantwort. `id` null, wenn die Nachricht noch nicht (oder
     * nicht lesbar) vorlag.
     *
     * @return array<string,mixed>
     */
    public function body(\Energietracker\Services\I18nService $i18n, string $locale, string|int|null $id = null): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => [
            'code'    => $this->rpcCode,
            'message' => $i18n->t($this->key, $this->params, $locale),
            'data'    => ['code' => $this->key] + $this->data,
        ]];
    }
}
