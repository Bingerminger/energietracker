<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\CrossSiteGuard;
use Energietracker\Http\HttpStatusException;
use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\AuthService;
use Energietracker\Services\I18nService;
use Energietracker\Services\Mcp\Config;
use Energietracker\Services\Mcp\Journal;
use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\PendingChanges;
use Energietracker\Services\Mcp\ProposalApplier;
use Energietracker\Services\Mcp\RateLimiter;
use Energietracker\Services\Mcp\RpcError;
use Energietracker\Services\Mcp\Server;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037) — Energietracker für deine KI: der MCP-Endpunkt.
 *
 *   POST /api/mcp            JSON-RPC (Streamable HTTP, nur JSON, ohne Sitzung)
 *   GET  /api/mcp/journal    Zugriffsprotokoll (nur Verwalter), `?limit=` 1–500
 *   GET  /api/mcp/proposals  offene Vorschläge der KI (nur Verwalter)
 *   GET  /api/mcp/status     Schalter und Sperre für die Oberfläche (nur Verwalter)
 *   DELETE /api/mcp/proposals/{id}  Vorschlag in der App ablehnen (nur Verwalter)
 *   POST /api/mcp/proposals/{id}/apply  Vorschlag in der App übernehmen (nur Verwalter, Skeptiker M1)
 *
 * Reihenfolge der Prüfungen an /api/mcp (Konzept §2.4), jede vor dem Lesen des Körpers:
 *  1. eingeschaltet? (`mcp_enabled`, `ET_MCP=off` sperrt fest) → 403 mit Hinweis
 *  2. `Origin` vorhanden und nicht die eigene Adresse → 403. Kein Browser-Zugriff
 *     (Entscheidung 2026-11-03: keine Erlaubnisliste); Programme senden keinen Ursprung
 *  3. Adresse nach falschen Schlüsseln gesperrt? → 429 mit `Retry-After`
 *  4. Bearer-Schlüssel mit Bereich `mcp` oder `mcp_write` — immer, auch ohne
 *     Anmeldung und im Proxy-Modus (Entscheidung 5): fehlt → 401 mit
 *     `WWW-Authenticate`, unbekannt → 401 mit `error="invalid_token"` und ein
 *     Fehlversuch der Adresse (der zwanzigste sperrt: 429), anderer Bereich → 403
 *  5. Ratenbegrenzung je Schlüssel (alle Anfragen) → 429 mit `Retry-After`
 *  6. Nachricht → Mcp\Server (Protokoll, Werkzeuge, Töpfe je Werkzeug)
 *  7. Zugriffsprotokoll (Mcp\Journal), dann die Antwort
 * Die App lässt /api/mcp ohne allgemeine Anmeldung durch (`isPublic`) und holt
 * keine Schreibsperre vorab (`LOCK_LATE`); schreibende Werkzeuge sperren selbst.
 * Der Schlüssel erscheint in keiner Antwort und keinem Protokoll; ein Schlüssel
 * in der URL zählt nicht (nur die Kopfzeile `Authorization`).
 */
final class McpController
{
    private const REALM = 'Bearer realm="energietracker"';

    /**
     * @param \Closure(): Server $server erst bei Bedarf gebaut
     * @param \Closure(): PendingChanges $pending
     * @param (\Closure(): ProposalApplier)|null $applier die eine Fassung der Übernahme (dieselbe wie `apply_proposal`)
     * @param (\Closure(): ?array<string,mixed>)|null $actor wer in der App handelt (App::actor)
     */
    public function __construct(
        private Config $config,
        private AuthService $auth,
        private I18nService $i18n,
        private \Closure $server,
        private \Closure $pending,
        private RateLimiter $limiter,
        private Journal $journal,
        private ?\Closure $applier = null,
        private ?\Closure $actor = null,
    ) {}

    public function handle(Request $req): never
    {
        $started = hrtime(true);
        $locale = Server::localeFor($this->i18n);
        $this->i18n->setLocale($locale);   // auch Meldungen der Dienste in der Sprache des Servers

        // ausgeschaltet: kein Protokoll, keine Dateien unter data/mcp/
        if (Config::lockedByEnv()) $this->deny(new RpcError(403, Server::ERR_DISABLED, 'errors.mcp.lockedByEnv'), $locale);
        if (!$this->config->enabled()) $this->deny(new RpcError(403, Server::ERR_DISABLED, 'errors.mcp.disabled'), $locale);
        if (!self::originAllowed($_SERVER)) $this->reject(new RpcError(403, Server::ERR_FORBIDDEN, 'errors.mcp.origin'), $locale, $started);

        $ip = AuthService::clientIp($_SERVER);
        $locked = $this->limiter->lockedFor($ip);
        // während der Sperre nicht protokolliert (sonst schöbe ein Angreifer die Einträge hinaus)
        if ($locked !== null) $this->deny(Server::rateLimited($locked, 'errors.mcp.keyLocked'), $locale);

        $token = $req->bearerToken();
        $key = $this->auth->apiKey($token);
        if ($key === null) {
            $presented = $token !== null && $token !== '';
            $lock = $presented ? $this->limiter->failure($ip) : null;
            if ($lock !== null) $this->reject(Server::rateLimited($lock, 'errors.mcp.keyLocked'), $locale, $started);
            $this->reject(new RpcError(401, Server::ERR_UNAUTHORIZED, 'errors.mcp.keyRequired', [], [],
                ['WWW-Authenticate' => self::REALM . ($presented ? ', error="invalid_token"' : '')]), $locale, $started);
        }
        if (!in_array($key['scope'], McpContext::SCOPES, true)) {
            $this->reject(new RpcError(403, Server::ERR_FORBIDDEN, 'errors.mcp.keyScope', [], [],
                ['WWW-Authenticate' => self::REALM . ', error="insufficient_scope", scope="mcp"']), $locale, $started, $key);
        }
        // v3.12.0 — erst ein Schlüssel mit KI-Bereich setzt die Fehlversuche zurück; ein Kalender- oder Leseschlüssel
        // dazwischen (403) hob die Sperre nach 20 falschen sonst immer wieder auf
        $this->limiter->success($ip);
        $wait = $this->limiter->hit('all', $key['id']);
        if ($wait !== null) $this->reject(Server::rateLimited($wait), $locale, $started, $key);
        // v3.12.0 — Härtung: „zuletzt benutzt“ erst für eine angenommene Anfrage
        // (nicht für einen Schlüssel mit falschem Bereich oder über der Grenze)
        $this->auth->touchApiKey($key['id']);

        try {
            $raw = $req->rawBody;
        } catch (HttpStatusException $e) {
            if ($e->status !== 413) throw $e;
            $this->reject(new RpcError(413, Server::ERR_TOO_LARGE, 'errors.mcp.tooLarge', ['max' => (int)($e->params['max'] ?? 1)]), $locale, $started, $key);
        }
        $ctx = new McpContext($key['id'], $key['name'], $key['scope'], $locale);
        $reply = ($this->server)()->handle($raw, $ctx, self::headers($_SERVER));
        $this->journal->append($this->journal->entry($reply['log'], $key, $reply['status'], self::ms($started)));
        Response::raw($reply['body'], $reply['status'], $reply['headers']);
    }

    /** Zugriffsprotokoll, neueste zuerst (`?limit=`, Standard 100, höchstens 500). */
    public function journal(Request $req): never
    {
        $limit = $req->queryParam('limit');
        $n = is_string($limit) && ctype_digit($limit) ? (int)$limit : Journal::LIST_DEFAULT;
        Response::json($this->journal->recent($n));
    }

    /** Offene Vorschläge aller noch gültigen Schlüssel, ohne Nutzdaten (Skeptiker N8: ein widerrufener zählt nicht mehr). */
    public function proposals(Request $req): never
    {
        Response::json(($this->pending)()->listOpen($this->activeKeys()));
    }

    /** @return list<string> */
    private function activeKeys(): array
    {
        return array_values(array_map(fn(array $k): string => (string)$k['id'], $this->auth->apiKeys()));
    }

    /**
     * v3.12.0 (Skeptiker M1) — einen offenen Vorschlag in der App übernehmen: dieselbe Schreibsperre und
     * dieselben Prüfungen wie `apply_proposal` (Mcp\ProposalApplier), CSRF und Rolle wie jede Aktion der
     * Oberfläche (App::adminOnly). Ausgeschaltet oder per `ET_MCP` gesperrt schreibt die KI-Schnittstelle nichts.
     */
    public function applyProposal(Request $req): never
    {
        $this->onlyFromOwnPage();
        if (Config::lockedByEnv()) Response::error($this->i18n->t('errors.mcp.lockedByEnv'), 403, null, 'errors.mcp.lockedByEnv');
        if (!$this->config->enabled()) Response::error($this->i18n->t('errors.mcp.disabled'), 403, null, 'errors.mcp.disabled');
        $person = $this->actor !== null ? ($this->actor)() : null;
        try {
            $r = ($this->applier)()->inApp((string)$req->param('id'), is_array($person) ? $person : null, $this->activeKeys());
        } catch (LocalizedException $e) {
            Response::error($this->i18n->t($e->key, $e->params), $e->key === 'errors.mcp.proposal.gone' ? 404 : 409, null, $e->key);
        }
        Response::json(['proposal_id' => (string)$r['proposal']['id'], 'kind' => (string)$r['proposal']['kind'], 'status' => 'applied'] + $r['result']);
    }

    /**
     * v3.12.0 (Paket oberflaeche) — Stand für die Karte „KI-Assistenten“: Schalter,
     * feste Sperre per `ET_MCP`, die beiden Freigaben (Config::state(), ohne Geheimnisse).
     */
    public function status(Request $req): never
    {
        // v3.12.0 (Nachprüfung A1, additiv) — ohne Anmeldung sagt die Karte, was der Schalter dann nicht schützt
        Response::json($this->config->state() + ['login_active' => $this->auth->mode() !== 'off']);
    }

    /** v3.12.0 (Paket oberflaeche) — einen offenen Vorschlag in der App ablehnen. */
    public function rejectProposal(Request $req): never
    {
        $this->onlyFromOwnPage();
        if (!($this->pending)()->reject((string)$req->param('id'))) {
            Response::error($this->i18n->t('errors.mcp.proposal.gone'), 404, null, 'errors.mcp.proposal.gone');
        }
        Response::json(['rejected' => true]);
    }

    /**
     * v3.12.0 (Nachprüfung A1) — Übernehmen und Ablehnen gibt es nur aus der eigenen Oberfläche: Die sendet ihren
     * Ursprung ohnehin, ein Aufruf per curl oder aus einem Skript von sich aus nicht. Schlüssel weist App::handle ab.
     */
    private function onlyFromOwnPage(): void
    {
        if (!CrossSiteGuard::isOwnPage($_SERVER)) {
            Response::error($this->i18n->t('errors.mcp.proposal.appOnly'), 403, null, 'errors.mcp.proposal.appOnly');
        }
    }

    /**
     * Kein `Origin` (Programme wie VS Code, Open WebUI, LM Studio, curl): erlaubt.
     * Sonst nur die eigene Adresse (die eigene Oberfläche); jede andere Seite und
     * `null` nie (Spezifikation: ungültiger Ursprung → 403). Browser-Zugriff von
     * fremden Seiten gibt es bewusst nicht (keine Erlaubnisliste, kein CORS).
     *
     * @param array<string,mixed> $server
     */
    private static function originAllowed(array $server): bool
    {
        $origin = $server['HTTP_ORIGIN'] ?? null;
        if (!is_string($origin) || trim($origin) === '') return true;
        if (strtolower(trim($origin)) === 'null') return false;
        return CrossSiteGuard::isOwnOrigin($origin, $server);
    }

    /** Ablehnung als JSON-RPC-Fehler ohne Kennung (der Körper ist noch nicht gelesen). */
    private function deny(RpcError $e, string $locale): never
    {
        Response::raw($e->body($this->i18n, $locale), $e->status, $e->headers);
    }

    /**
     * Ablehnung mit Eintrag im Zugriffsprotokoll (Ergebnis `denied`,
     * `rate_limited` bzw. `error`, dazu der Code).
     *
     * @param array{id:string, name:string, scope:string}|null $key
     */
    private function reject(RpcError $e, string $locale, int $started, ?array $key = null): never
    {
        $result = match (true) {
            $e->status === 429 => 'rate_limited',
            $e->status === 401 || $e->status === 403 => 'denied',
            default => 'error',
        };
        $log = ['method' => null, 'name' => null, 'result' => $result, 'version' => null, 'client' => [], 'code' => $e->key];
        $this->journal->append($this->journal->entry($log, $key, $e->status, self::ms($started)));
        $this->deny($e, $locale);
    }

    private static function ms(int $started): int
    {
        return (int)round((hrtime(true) - $started) / 1e6);
    }

    /**
     * MCP-Kopfzeilen mit kleinen Namen.
     *
     * @param array<string,mixed> $server
     * @return array<string,string>
     */
    public static function headers(array $server): array
    {
        $out = [];
        foreach (['mcp-protocol-version' => 'HTTP_MCP_PROTOCOL_VERSION', 'mcp-method' => 'HTTP_MCP_METHOD',
                  'mcp-name' => 'HTTP_MCP_NAME', 'accept' => 'HTTP_ACCEPT'] as $name => $key) {
            if (isset($server[$key]) && is_string($server[$key])) $out[$name] = trim($server[$key]);
        }
        return $out;
    }
}
