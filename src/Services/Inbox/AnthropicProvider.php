<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Http\HttpStatusException;
use Energietracker\Services\I18nService;
use Energietracker\Support\LocalizedException;

/**
 * v3.7.0 (Paket cloud) — Auslesen über Claude (Anthropic), Messages-
 * Schnittstelle `POST https://api.anthropic.com/v1/messages`, ohne SDK. Nur als
 * Opt-in (CloudConfig::allows).
 *
 * Das PDF geht selbst mit (Dokument-Block vor dem Text-Block, Belegstellen
 * eingeschaltet); ohne PDF der Text als Text-Dokument. Die Belegstelle liefert
 * der Dienst: `citations` mit `cited_text` und bei PDFs `page_location`
 * (`start_page_number`, 1-basiert).
 *
 * Belegstellen und ein fest vorgegebenes Antwortformat (`output_config.format`)
 * schließen sich in einer Anfrage aus (400). Gewählt: EINE Anfrage, die Felder
 * als Zeilen „Feld: Wert“ — die Belegstellen hängen an den Textblöcken der
 * Antwort, jede Zeile bekommt die ihrer Blöcke (CloudPrompt::parseCitedLines).
 * Zwei Schritte (erst JSON mit festem Format, dann Belegstellen suchen) kosteten
 * die doppelte Zeit und Tokens, und die zweite Antwort müsste dem ersten Wert
 * wieder zugeordnet werden. Ein Feld ohne Beleg bleibt ohne Zitat und wird im
 * Posteingang zur Prüfung markiert — der sichere Ausfall.
 *
 * Artenerkennung und Felder schicken denselben Dokument-Block mit
 * `cache_control`: Die zweite Anfrage liest das PDF aus dem Zwischenspeicher
 * des Dienstes (kostet ein Zehntel).
 *
 * `temperature` schickt der Anschluss nicht: Die aktuellen Modelle lehnen sie
 * ab (400). Lehnt das Modell den Auftrag ab, versucht es der Dienst bei den
 * Modellen in FALLBACK_MODELS mit einem anderen eigenen Modell
 * (`fallbacks: "default"`); kennt das Konto die Beta nicht, wiederholt der
 * Anschluss die Anfrage einmal ohne.
 */
final class AnthropicProvider implements ExtractionProvider
{
    public const ID = 'anthropic';
    public const LABEL = 'Claude (Anthropic)';
    public const HOST = 'api.anthropic.com';
    public const URL = 'https://api.anthropic.com/v1/messages';
    public const MODELS_URL = 'https://api.anthropic.com/v1/models';   // v3.7.0 (Karte „Auslesen“)
    public const API_VERSION = '2023-06-01';
    public const DEFAULT_MODEL = 'claude-opus-5-5';
    public const MAX_TOKENS = 16000;
    /**
     * v3.9.0 (Posteingang 3.7.1) — endet die Antwort am Budget (`max_tokens`,
     * das Denken des Modells zählt mit), einmal mit dem doppelten Budget, wenn
     * vom Zeitlimit noch RETRY_MIN_SECONDS übrig sind; wie LocalProvider.
     * Ohne Datenstrom: 32 000 bleiben im Zeitlimit von höchstens 600 s.
     */
    public const MAX_TOKENS_RETRY = 32000;
    public const RETRY_MIN_SECONDS = 20;
    /** Grenzen des Dienstes: 32 MB je Anfrage, 600 Seiten je Anfrage. */
    public const MAX_REQUEST_BYTES = 32 * 1024 * 1024;
    public const MAX_PAGES = 600;
    /** Modelle, für die der Dienst den Rückfall bei Ablehnung anbietet. */
    public const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-fable-5-1'];
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** @var callable(string, list<string>, ?string, int, ?string): array  Körper null = GET */
    private $transport;
    /** @var callable(string): list<string> */
    private $resolver;
    /** @var array<string,string> */
    private array $types = CloudPrompt::DEFAULT_TYPES;

    public function __construct(
        private CloudConfig $config,
        private I18nService $i18n,
        ?callable $transport = null,
        ?callable $resolver = null,
    ) {
        $this->transport = $transport ?? static fn(string $url, array $headers, ?string $body, int $timeout, ?string $ip): array
            => $body === null ? CloudHttp::get($url, $headers, $timeout, $ip) : CloudHttp::post($url, $headers, $body, $timeout, $ip);
        $this->resolver = $resolver ?? CloudHttp::resolve(...);
    }

    /**
     * v3.7.0 (Karte „Auslesen“) — Modelle des Kontos (`GET /v1/models`, bis
     * 1000 auf einmal, neueste zuerst), ohne Dokument. Braucht nur den
     * Schlüssel; die Karte lädt die Liste, sobald er gespeichert ist.
     *
     * @return list<array{id:string, label:string}>
     */
    public function models(): array
    {
        $key = $this->config->key(self::ID);
        if ($key === '') throw new LocalizedException('errors.inboxCloud.noKey', ['name' => self::LABEL], 'no key for anthropic');
        $ip = CloudHttp::publicAddress(self::MODELS_URL, $this->resolver);
        $timeout = min(30, $this->config->timeout());
        $resp = ($this->transport)(self::MODELS_URL . '?limit=1000', $this->authHeaders($key), null, $timeout, $ip);
        if (!$resp['ok']) CloudHttp::fail($resp, $timeout, self::HOST, $key);
        return CloudHttp::models((string)$resp['body']);
    }

    /** @return list<string> Schlüssel und Version — für jede Anfrage dieselben */
    private function authHeaders(string $key): array
    {
        return ['x-api-key: ' . $key, 'anthropic-version: ' . self::API_VERSION];
    }

    /** @param array<string,string> $types */
    public function setDocumentTypes(array $types): void
    {
        if ($types !== []) $this->types = $types;
    }

    public function id(): string { return self::ID; }

    public function supportsPdf(): bool { return true; }

    /** Sendet dieser Anschluss? Für den Posteingang (`provider.available`): eingeschaltet, gewählt, mit Schlüssel. */
    public function enabled(): bool { return $this->config->allows(self::ID); }

    /** Name für Oberfläche und Zustimmungsdialog. */
    public function label(): string { return self::LABEL; }

    /** Ziel-Host, den der Dialog nennt. */
    public function destination(): string { return self::HOST; }

    /** Hinweis vor jedem Auslesen: wohin, was, wer sieht es. */
    public function notice(): string
    {
        return $this->i18n->t('inboxCloud.notice.pdf', ['name' => self::LABEL, 'host' => self::HOST]);
    }

    public function model(): string
    {
        $m = $this->config->model(self::ID);
        return $m !== '' ? $m : self::DEFAULT_MODEL;
    }

    public function classify(string $text, ?string $pdfBase64 = null): array
    {
        $r = $this->ask(CloudPrompt::classifyPrompt($this->types, false), $text, $pdfBase64);
        return CloudPrompt::parseClassify($r['text'], $this->types) + ['model' => $r['model'], 'seconds' => $r['seconds']];
    }

    public function extract(string $type, array $schema, string $text, ?string $pdfBase64 = null): array
    {
        $fields = CloudPrompt::fields($schema);
        $label = is_string($schema['description'] ?? null) && $schema['description'] !== '' ? $schema['description'] : ($this->types[$type] ?? $type);
        $r = $this->ask(CloudPrompt::extractPrompt($label, $fields, false), $text, $pdfBase64);
        return ['fields' => CloudPrompt::assemble($fields, CloudPrompt::parseCitedLines($r['blocks'], $fields)),
                'model' => $r['model'], 'seconds' => $r['seconds']];
    }

    /**
     * Anfrage (POST /v1/messages) zusammensetzen — öffentlich für den Test der Form.
     *
     * @return array<string,mixed>
     */
    public function requestBody(string $prompt, string $text, ?string $pdfBase64, bool $withFallback = true): array
    {
        $model = $this->model();
        $source = $pdfBase64 !== null
            ? ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdfBase64]
            : ['type' => 'text', 'media_type' => 'text/plain', 'data' => $text];
        $document = ['type' => 'document', 'source' => $source, 'citations' => ['enabled' => true], 'cache_control' => ['type' => 'ephemeral']];
        return [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => CloudPrompt::SYSTEM,
            'messages' => [['role' => 'user', 'content' => [$document, ['type' => 'text', 'text' => $prompt]]]],
        ] + ($withFallback && in_array($model, self::FALLBACK_MODELS, true) ? ['fallbacks' => 'default'] : []);
    }

    /**
     * @return array{text:string, blocks:list<array{text:string, citations:list<array<string,mixed>>}>, model:string, seconds:float}
     */
    private function ask(string $prompt, string $text, ?string $pdfBase64): array
    {
        if (!$this->config->allows(self::ID)) {
            throw new LocalizedException($this->config->enabled() && $this->config->provider() === self::ID ? 'errors.inboxCloud.noKey' : 'errors.inboxCloud.off',
                ['name' => self::LABEL], 'cloud reading is not switched on for anthropic');
        }
        $pdf = $pdfBase64 !== null ? self::checkPdf($pdfBase64) : null;
        if ($pdf === null && trim($text) === '') throw new LocalizedException('errors.inboxCloud.emptyDocument', [], 'nothing to read');
        $ip = CloudHttp::publicAddress(self::URL, $this->resolver);
        $key = $this->config->key(self::ID);
        $timeout = $this->config->timeout();
        $headers = ['Content-Type: application/json', ...$this->authHeaders($key)];

        $t0 = hrtime(true);
        $body = $this->requestBody($prompt, $text, $pdf);
        $resp = $this->send($body, $headers, $timeout, $ip);
        // Die Beta des Rückfalls kennt nicht jedes Konto: dann einmal ohne
        if (!$resp['ok'] && isset($body['fallbacks']) && (int)($resp['status'] ?? 0) === 400
            && preg_match('/anthropic-beta|fallback/i', CloudHttp::errorDetail((string)($resp['body'] ?? '')))) {
            $body = $this->requestBody($prompt, $text, $pdf, false);
            $resp = $this->send($body, $headers, $timeout, $ip);
        }
        $json = $this->answer($resp, $timeout, $key);
        $stop = (string)($json['stop_reason'] ?? '');
        // v3.9.0 (Posteingang 3.7.1) — abgeschnitten am Budget: einmal mit dem doppelten, in der Restzeit.
        // Am Kontextfenster hilft ein größeres Budget nicht (model_context_window_exceeded bleibt ein Fehler).
        if ($stop === 'max_tokens') {
            $left = $timeout - (int)ceil((hrtime(true) - $t0) / 1e9);
            if ($left >= self::RETRY_MIN_SECONDS) {
                $json = $this->answer($this->send(['max_tokens' => self::MAX_TOKENS_RETRY] + $body, $headers, $left, $ip), $left, $key);
                $stop = (string)($json['stop_reason'] ?? '');
            }
        }
        $seconds = round((hrtime(true) - $t0) / 1e9, 2);
        if ($stop === 'max_tokens' || $stop === 'model_context_window_exceeded') {
            throw new HttpStatusException(502, 'errors.inboxCloud.truncated', [], [], "anthropic answer cut off ($stop)");
        }
        $blocks = [];
        foreach ($json['content'] as $b) {
            // nur Text zählt: Denk-, Rückfall- und andere Blöcke fallen heraus
            if (!is_array($b) || ($b['type'] ?? '') !== 'text') continue;
            $blocks[] = ['text' => (string)($b['text'] ?? ''), 'citations' => is_array($b['citations'] ?? null) ? array_values($b['citations']) : []];
        }
        $all = implode('', array_column($blocks, 'text'));
        if (trim($all) === '') throw new HttpStatusException(502, 'errors.inboxCloud.badAnswer', [], [], 'anthropic answer without text');
        $served = is_string($json['model'] ?? null) && $json['model'] !== '' ? $json['model'] : $this->model();
        return ['text' => $all, 'blocks' => $blocks, 'model' => $served, 'seconds' => $seconds];
    }

    /**
     * Antwort des Dienstes prüfen und lesen: Fehlerstatus, Inhalt, Ablehnung.
     *
     * @param array<string,mixed> $resp
     * @return array<string,mixed>
     */
    private function answer(array $resp, int $timeout, string $key): array
    {
        if (!$resp['ok']) CloudHttp::fail($resp, $timeout, self::HOST, $key);
        $json = json_decode((string)$resp['body'], true);
        if (!is_array($json) || !is_array($json['content'] ?? null)) {
            throw new HttpStatusException(502, 'errors.inboxCloud.badAnswer', [], [], 'anthropic answer without content');
        }
        if (($json['stop_reason'] ?? '') === 'refusal') throw new HttpStatusException(422, 'errors.inboxCloud.refusal', ['name' => self::LABEL], [], 'anthropic refused');
        return $json;
    }

    /** @param array<string,mixed> $body */
    private function send(array $body, array $headers, int $timeout, string $ip): array
    {
        if (isset($body['fallbacks'])) $headers[] = 'anthropic-beta: ' . self::FALLBACK_BETA;
        $json = (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json) > self::MAX_REQUEST_BYTES) {
            throw new HttpStatusException(413, 'errors.inboxCloud.tooLargeLimits', self::limits(), [], 'anthropic request above 32 MB');
        }
        return ($this->transport)(self::URL, $headers, $json, $timeout, $ip);
    }

    /**
     * PDF prüfen: gültiges base64 (ohne Zeilenumbrüche, wie der Dienst es
     * verlangt), beginnt mit `%PDF-`, höchstens MAX_PAGES Seiten (gezählt an
     * den Seitenobjekten; komprimierte Objektströme zählen dabei nicht mit —
     * dann entscheidet der Dienst).
     */
    private static function checkPdf(string $b64): string
    {
        $b64 = (string)preg_replace('/\s+/', '', $b64);
        $raw = base64_decode($b64, true);
        if ($raw === false || !str_starts_with($raw, '%PDF-')) {
            throw new LocalizedException('errors.inboxCloud.notPdf', [], 'document is not a PDF');
        }
        if (preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $raw) > self::MAX_PAGES) {
            throw new HttpStatusException(413, 'errors.inboxCloud.tooLargeLimits', self::limits(), [], 'pdf above 600 pages');
        }
        return $b64;
    }

    /** @return array{name:string, mb:int, pages:int} Grenzen für die Meldung, aus den Konstanten */
    private static function limits(): array
    {
        return ['name' => self::LABEL, 'mb' => intdiv(self::MAX_REQUEST_BYTES, 1024 * 1024), 'pages' => self::MAX_PAGES];
    }
}
