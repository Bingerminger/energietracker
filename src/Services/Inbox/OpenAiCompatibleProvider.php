<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Http\HttpStatusException;
use Energietracker\Services\I18nService;
use Energietracker\Support\LocalizedException;

/**
 * v3.7.0 (Paket cloud) — Auslesen über einen OpenAI-kompatiblen Dienst in
 * der Cloud (`/v1/chat/completions`), mit eigener Adresse. Nur als Opt-in
 * (CloudConfig::allows), nur HTTPS, nie eine Adresse im Heimnetz — das
 * Gegenstück zur Texterkennung, deren Heimnetz-Regel bleibt (OcrService).
 *
 * Der Dienst bekommt nur die Textebene, die der Browser aus dem PDF liest;
 * Belegstellen liefert das Modell als Zitat mit, der Posteingang prüft sie
 * gegen den Text. `temperature: 0`, Antwort als JSON-Objekt.
 */
final class OpenAiCompatibleProvider implements ExtractionProvider
{
    public const ID = 'openai';
    /** v3.7.0 (treffsicher, Nachbesserung 5) — Antwortbudget des zweiten Versuchs nach `length` (Token). */
    public const RETRY_MAX_TOKENS = 16384;
    /** Zweiter Versuch nur, wenn vom Zeitlimit noch so viele Sekunden übrig sind. */
    private const RETRY_MIN_SECONDS = 20;

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
        // ohne Körper (null) ein GET — die Modellliste
        $this->transport = $transport ?? static fn(string $url, array $headers, ?string $body, int $timeout, ?string $ip): array
            => $body === null ? CloudHttp::get($url, $headers, $timeout, $ip) : CloudHttp::post($url, $headers, $body, $timeout, $ip);
        $this->resolver = $resolver ?? CloudHttp::resolve(...);
    }

    /**
     * v3.7.0 (Karte „Auslesen“) — Modelle des Dienstes (`GET <basis>/models`),
     * ohne Dokument. Braucht Adresse und Schlüssel, nicht das Einschalten: Die
     * Karte lädt die Liste, sobald der Schlüssel gespeichert ist (nur
     * Verwalter, der Schlüssel bleibt hier). Dieselben Regeln wie beim Auslesen.
     *
     * @return list<array{id:string, label:string}>
     */
    public function models(): array
    {
        $endpoint = $this->config->endpoint();
        if ($endpoint === '') throw new LocalizedException('errors.inboxCloud.noEndpoint', [], 'openai-compatible needs an address');
        $key = $this->config->key(self::ID);
        if ($key === '') throw new LocalizedException('errors.inboxCloud.noKey', ['name' => $this->label()], 'no key for this address');
        $modelsUrl = CloudHttp::apiUrl($endpoint, 'models');
        $ip = CloudHttp::publicAddress($modelsUrl, $this->resolver);
        $timeout = min(30, $this->config->timeout());
        $resp = ($this->transport)($modelsUrl, ['Accept: application/json', 'Authorization: Bearer ' . $key], null, $timeout, $ip);
        if (!$resp['ok']) CloudHttp::fail($resp, $timeout, $this->destination(), $key);
        return CloudHttp::models((string)$resp['body']);
    }

    /** Arten des Posteingangs (Kennung → Beschreibung fürs Modell). @param array<string,string> $types */
    public function setDocumentTypes(array $types): void
    {
        if ($types !== []) $this->types = $types;
    }

    public function id(): string { return self::ID; }

    public function supportsPdf(): bool { return false; }

    /** Sendet dieser Anschluss? Für den Posteingang (`provider.available`): eingeschaltet, gewählt, mit Schlüssel. */
    public function enabled(): bool { return $this->config->allows(self::ID); }

    /** Name für Oberfläche und Zustimmungsdialog. */
    public function label(): string
    {
        // v3.7.0 (Karte „Auslesen“) — eine Voreinstellung nennt den Dienst beim Namen (Mistral, OpenRouter …)
        $preset = $this->config->provider() === self::ID ? $this->config->presetLabel() : '';
        return $preset !== '' ? $preset : $this->i18n->t('inboxCloud.provider.openai');
    }

    /** Ziel-Host, den der Dialog nennt; leer, solange keine Adresse eingetragen ist. */
    public function destination(): string
    {
        return strtolower((string)parse_url($this->config->endpoint(), PHP_URL_HOST));
    }

    /** Hinweis vor jedem Auslesen: wohin, was, wer sieht es. */
    public function notice(): string
    {
        return $this->i18n->t('inboxCloud.notice.text', ['name' => $this->label(), 'host' => $this->destination()]);
    }

    public function model(): string
    {
        return $this->config->model(self::ID);
    }

    public function classify(string $text, ?string $pdfBase64 = null): array
    {
        $r = $this->ask(CloudPrompt::classifyPrompt($this->types, true), $text);
        return CloudPrompt::parseClassify($r['content'], $this->types) + ['model' => $r['model'], 'seconds' => $r['seconds']];
    }

    public function extract(string $type, array $schema, string $text, ?string $pdfBase64 = null): array
    {
        $fields = CloudPrompt::fields($schema);
        $label = is_string($schema['description'] ?? null) && $schema['description'] !== '' ? $schema['description'] : ($this->types[$type] ?? $type);
        $r = $this->ask(CloudPrompt::extractPrompt($label, $fields, true), $text);
        return ['fields' => CloudPrompt::assemble($fields, CloudPrompt::parseJsonFields($r['content'], $fields)),
                'model' => $r['model'], 'seconds' => $r['seconds']];
    }

    /** @return array{content:string, model:string, seconds:float} */
    private function ask(string $prompt, string $text): array
    {
        if (!$this->config->allows(self::ID)) {
            throw new LocalizedException($this->config->enabled() && $this->config->provider() === self::ID ? 'errors.inboxCloud.noKey' : 'errors.inboxCloud.off',
                ['name' => $this->label()], 'cloud reading is not switched on for openai');
        }
        $endpoint = $this->config->endpoint();
        $model = $this->model();
        if ($endpoint === '') throw new LocalizedException('errors.inboxCloud.noEndpoint', [], 'openai-compatible needs an address');
        if ($model === '') throw new LocalizedException('errors.inboxCloud.noModel', [], 'openai-compatible needs a model');
        if (trim($text) === '') throw new LocalizedException('errors.inboxCloud.emptyDocument', [], 'nothing to read');
        $url = CloudHttp::apiUrl($endpoint, 'chat/completions');   // auch …/v1beta/openai (Gemini)
        $ip = CloudHttp::publicAddress($url, $this->resolver);   // bei jedem Aufruf, nach der Namensauflösung
        $key = $this->config->key(self::ID);
        $timeout = $this->config->timeout();
        $request = fn(?int $maxTokens): string => (string)json_encode([
            'model' => $model,
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => CloudPrompt::SYSTEM],
                ['role' => 'user', 'content' => $prompt . "\n\n<document>\n" . $text . "\n</document>"],
            ],
        ] + ($maxTokens !== null ? ['max_tokens' => $maxTokens] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
        $t0 = hrtime(true);
        $resp = ($this->transport)($url, $headers, $request(null), $timeout, $ip);
        if (!$resp['ok']) CloudHttp::fail($resp, $timeout, $this->destination(), $key);
        $json = json_decode((string)$resp['body'], true);
        // v3.7.0 (treffsicher, Nachbesserung 5) — am Antwortbudget abgeschnitten (`length`): einmal mit
        // ausdrücklich großem Budget, in der übrigen Zeit des Zeitlimits; dann erst die Meldung „unvollständig“
        $left = $timeout - (int)ceil((hrtime(true) - $t0) / 1e9);
        if (is_array($json) && ($json['choices'][0]['finish_reason'] ?? '') === 'length' && $left >= self::RETRY_MIN_SECONDS) {
            $resp = ($this->transport)($url, $headers, $request(self::RETRY_MAX_TOKENS), $left, $ip);
            if (!$resp['ok']) CloudHttp::fail($resp, $left, $this->destination(), $key);
            $json = json_decode((string)$resp['body'], true);
        }
        $seconds = round((hrtime(true) - $t0) / 1e9, 2);
        $choice = is_array($json) ? ($json['choices'][0] ?? null) : null;
        if (!is_array($choice)) throw new HttpStatusException(502, 'errors.inboxCloud.badAnswer', [], [], 'openai answer without choices');
        $finish = (string)($choice['finish_reason'] ?? '');
        if ($finish === 'content_filter' || !empty($choice['message']['refusal'])) {
            throw new HttpStatusException(422, 'errors.inboxCloud.refusal', ['name' => $this->label()], [], 'openai refused');
        }
        if ($finish === 'length') throw new HttpStatusException(502, 'errors.inboxCloud.truncated', [], [], 'openai answer cut off (length)');
        $content = (string)($choice['message']['content'] ?? '');
        if (trim($content) === '') throw new HttpStatusException(502, 'errors.inboxCloud.badAnswer', [], [], 'openai answer without content');
        return ['content' => $content, 'model' => is_string($json['model'] ?? null) && $json['model'] !== '' ? $json['model'] : $model, 'seconds' => $seconds];
    }
}
