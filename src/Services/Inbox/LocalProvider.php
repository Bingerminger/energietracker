<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Services\I18nService;
use Energietracker\Services\OcrService;
use Energietracker\Services\SettingsService;
use Energietracker\Support\LocalizedException;

/**
 * v3.7.0 (Posteingang) — lokaler Anschluss: dieselbe Einstellung wie die
 * Texterkennung (`ocr_endpoint`, `ocr_api`), dieselbe Heimnetz-Regel und
 * derselbe Schutz gegen DNS-Rebinding (OcrService::checkLocal: Namensauflösung
 * bei jedem Aufruf, Verbindung genau an die geprüfte Adresse, keine
 * Weiterleitungen). Ein eigenes Modell (`inbox_model`, empfohlen qwen3.8 —
 * die Texterkennung braucht ein Bildmodell, der Posteingang ein Textmodell).
 *
 * So, wie es in der Machbarkeitsprobe trug: `temperature: 0`, Antwort als
 * JSON, ohne Denkmodus (der verbrauchte das Tokenbudget ohne Antwort). Bei
 * Ollama `think: false` und ein Kontextfenster passend zur Textlänge (der
 * Standard von 4.096 Token schnitte eine Rechnung mittendrin ab).
 */
final class LocalProvider implements ExtractionProvider
{
    public const ID = 'local';
    public const RECOMMENDED_MODEL = 'qwen3.8';
    private const CTX_MIN = 8192;
    /** Obergrenze des Kontextfensters (Token). */
    public const CTX_MAX = 32768;
    /**
     * v3.7.0 (treffsicher, Nachbesserung 5) — Antwortbudget fürs Auslesen der Felder:
     * 8.192 Token (vorher 4.096 — mit Ständen je Zähler, Datum und Titel reichte das
     * nicht mehr); schneidet das Fenster trotzdem ab, einmal 16.384.
     */
    public const EXTRACT_TOKENS = 8192;
    public const EXTRACT_TOKENS_RETRY = 16384;
    /** Zweiter Versuch nur, wenn vom Zeitlimit noch so viele Sekunden übrig sind. */
    private const RETRY_MIN_SECONDS = 30;
    private const CHARS_PER_TOKEN = 2.5;

    /** @var callable(string, string, int, ?string): array{ok:bool, body:?string, http_code:?int, error_code:?string} */
    private $transport;

    public function __construct(
        private SettingsService $settings,
        private OcrService $ocr,
        private I18nService $i18n,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? OcrService::post(...);
    }

    public function id(): string { return self::ID; }

    public function supportsPdf(): bool { return false; }

    /** Ist ein Dienst eingetragen? Ohne Adresse baut der Posteingang keine Verbindung auf. */
    public function enabled(): bool
    {
        return trim((string)$this->settings->get('ocr_endpoint', '')) !== '';
    }

    public function model(): string
    {
        $m = trim((string)$this->settings->get('inbox_model', ''));
        return $m !== '' ? $m : self::RECOMMENDED_MODEL;
    }

    private function api(): string
    {
        return in_array($this->settings->get('ocr_api', 'ollama'), OcrService::APIS, true) ? (string)$this->settings->get('ocr_api') : 'ollama';
    }

    public function label(): string
    {
        $host = (string)parse_url(trim((string)$this->settings->get('ocr_endpoint', '')), PHP_URL_HOST);
        return $this->i18n->t('inbox.provider.local', [
            'api' => $this->api() === 'openai' ? 'OpenAI-API' : 'Ollama',
            'host' => $host !== '' ? $host : '—',
        ]);
    }

    public function classify(string $text, ?string $pdfBase64 = null): array
    {
        $options = [];
        foreach (InboxSchema::TYPES as $t) $options[] = "- $t: " . InboxSchema::TYPE_DESC[$t];
        $system = "You read letters from energy and water suppliers. Decide which kind of document this is.\n"
            . "Options:\n" . implode("\n", $options) . "\n"
            . 'Answer only with JSON: {"type": "<one option>", "confidence": <number between 0 and 1>}.';
        $user = "Document:\n" . mb_substr($text, 0, TextPrep::CLASSIFY_CHARS);
        $r = $this->chat($system, $user, 200);
        $j = self::parseJson($r['content']);
        $type = is_array($j) && is_string($j['type'] ?? null) ? strtolower(trim($j['type'])) : '';
        if (!InboxSchema::isType($type)) {
            throw new LocalizedException('errors.inbox.badAnswer', [], 'classify: no valid type');
        }
        $c = is_numeric($j['confidence'] ?? null) ? (float)$j['confidence'] : null;
        if ($c !== null && $c > 1 && $c <= 100) $c /= 100;
        return ['type' => $type, 'confidence' => $c !== null && $c >= 0 && $c <= 1 ? round($c, 2) : null,
                'model' => $this->model(), 'seconds' => $r['seconds']];
    }

    public function extract(string $type, array $schema, string $text, ?string $pdfBase64 = null): array
    {
        $system = self::extractPrompt($schema);
        $user = "Document:\n" . $text;
        // v3.7.0 (treffsicher, Nachbesserung 5) — schneidet das Fenster die Antwort ab (`length`),
        // einmal mit dem doppelten Antwortbudget; die Zeit beider Versuche zusammen bleibt im Zeitlimit
        $limit = $this->timeoutSeconds();
        $t0 = hrtime(true);
        $r = $this->chat($system, $user, self::EXTRACT_TOKENS);
        if ($r['truncated']) {
            $left = $limit - (int)ceil((hrtime(true) - $t0) / 1e9);
            if ($left >= self::RETRY_MIN_SECONDS) $r = $this->chat($system, $user, self::EXTRACT_TOKENS_RETRY, $left);
            if ($r['truncated']) {
                throw new LocalizedException('errors.inbox.truncated', ['tokens' => self::EXTRACT_TOKENS_RETRY,
                    'window' => self::contextFor(mb_strlen($system) + mb_strlen($user), self::EXTRACT_TOKENS_RETRY)], 'extract: answer cut off (length)');
            }
        }
        $j = self::parseJson($r['content']);
        if (!is_array($j)) throw new LocalizedException('errors.inbox.badAnswer', [], 'extract: no JSON');
        $fields = is_array($j['fields'] ?? null) ? $j['fields'] : $j;
        $out = [];
        foreach (array_keys((array)($schema['fields'] ?? [])) as $name) {
            if (!array_key_exists($name, $fields)) continue;
            $f = $fields[$name];
            // ein Modell, das nur den Wert schreibt, bekommt kein Zitat (→ prüfen)
            $out[$name] = is_array($f) && (array_key_exists('value', $f) || array_key_exists('items', $f) || array_key_exists('quote', $f))
                ? ['value' => $f['value'] ?? ($f['items'] ?? null), 'quote' => isset($f['quote']) && is_scalar($f['quote']) ? (string)$f['quote'] : null,
                   'page' => isset($f['page']) && is_numeric($f['page']) ? (int)$f['page'] : null]
                : ['value' => $f, 'quote' => null, 'page' => null];
        }
        return ['fields' => $out, 'model' => $this->model(), 'seconds' => $r['seconds']];
    }

    /**
     * Anweisung für die Felder einer Art: die Regeln aus der Probe (wörtlich
     * abschreiben, nicht rechnen, Zitat und Seite, brutto, Abschlag wie
     * genannt, jede Zeile einer Liste einzeln) und die Felder nach Schema.
     *
     * @param array<string,mixed> $schema InboxSchema::forProvider()
     */
    public static function extractPrompt(array $schema): string
    {
        $lines = [];
        foreach ((array)($schema['fields'] ?? []) as $name => $f) {
            $kind = (string)$f['kind'];
            if ($kind === 'list') {
                $subs = [];
                foreach ((array)($f['items'] ?? []) as $sub => $sf) $subs[] = "$sub ({$sf['kind']}: {$sf['description']})";
                $lines[] = "- $name (list; every item has " . implode(', ', $subs) . ', quote, page): ' . $f['description'];
            } elseif ($kind === 'enum') {
                $lines[] = "- $name (one of: " . implode(', ', (array)$f['options']) . '): ' . $f['description'];
            } else {
                $lines[] = "- $name ($kind): " . $f['description'];
            }
        }
        return "You extract data from a German letter of an energy or water supplier: " . ($schema['description'] ?? '') . ".\n"
            . "Rules:\n"
            . "1. Copy every value exactly as printed (German number format such as 1.234,56; dates such as 31.12.2025). Do not calculate, round or convert.\n"
            . "2. For every value give \"quote\": a short passage copied word for word from the document that contains the value, and \"page\": the number of the marker [Seite N] before it.\n"
            . "3. If a field is not in the document, use null.\n"
            . "4. Dates that are only expected (voraussichtlich) still count.\n"
            . "5. Prices and amounts: as printed; if gross (brutto) and net (netto) are both given, take gross. Never a partial amount of a single line.\n"
            . "6. Advance payments (Abschlag): the amount as stated, not an average.\n"
            . "7. Lists: one item for every line in the document, each with its own quote and page.\n"
            . "Fields:\n" . implode("\n", $lines) . "\n"
            . "Answer only with JSON in this form: {\"fields\": {\"<field>\": {\"value\": <value or null>, \"quote\": \"<passage>\", \"page\": <number>}, "
            . "\"<list field>\": {\"value\": [{\"<sub field>\": <value>, \"quote\": \"<passage>\", \"page\": <number>}]}}}";
    }

    /** Zeitlimit einer Anfrage (Sekunden, Einstellung `inbox_timeout_s`, 30–300). */
    private function timeoutSeconds(): int
    {
        return max(30, min(300, (int)$this->settings->get('inbox_timeout_s', 300)));
    }

    /**
     * Eine Anfrage an den Dienst im Heimnetz. `truncated`: Die Antwort endete am
     * Antwortbudget bzw. Kontextfenster (Ollama `done_reason`, OpenAI-API
     * `finish_reason` = `length`) — dann ist auch ein Text ohne JSON kein „badAnswer“.
     *
     * @return array{content: string, seconds: float, truncated: bool}
     */
    private function chat(string $system, string $user, int $maxTokens, ?int $timeout = null): array
    {
        $endpoint = trim((string)$this->settings->get('ocr_endpoint', ''));
        if ($endpoint === '') throw new LocalizedException('errors.inbox.off', [], 'inbox: no local endpoint');
        $api = $this->api();
        $model = $this->model();
        $timeout ??= $this->timeoutSeconds();
        $url = OcrService::requestUrl($endpoint, $api);
        $ip = $this->ocr->checkLocal($url);   // Heimnetz und DNS-Rebinding wie die Texterkennung

        $messages = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
        $body = $api === 'openai'
            ? ['model' => $model, 'temperature' => 0, 'max_tokens' => $maxTokens, 'stream' => false, 'messages' => $messages]
            : ['model' => $model, 'stream' => false, 'format' => 'json', 'think' => false,
               'options' => ['temperature' => 0, 'num_ctx' => self::contextFor(mb_strlen($system) + mb_strlen($user), $maxTokens)],
               'messages' => $messages];

        $t0 = hrtime(true);
        $resp = ($this->transport)($url, (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $timeout, $ip);
        $seconds = round((hrtime(true) - $t0) / 1e9, 1);
        if (!$resp['ok']) {
            $err = is_string($resp['body'] ?? null) ? $resp['body'] : '';
            if (($resp['http_code'] ?? null) === 404 && preg_match('/model.*not found|not found.*model/i', $err)) {
                throw new LocalizedException('errors.inbox.modelMissing', ['model' => $model], 'inbox: model missing');
            }
            $key = ($resp['error_code'] ?? '') === 'timeout' ? 'errors.ocr.timeout' : 'errors.ocr.unreachable';
            throw new LocalizedException($key, ['seconds' => $timeout, 'code' => (string)($resp['http_code'] ?? '')], 'inbox request failed');
        }
        $json = json_decode((string)$resp['body'], true);
        $content = is_array($json)
            ? (string)($api === 'openai' ? ($json['choices'][0]['message']['content'] ?? '') : ($json['message']['content'] ?? ''))
            : '';
        $truncated = is_array($json) && ($api === 'openai' ? ($json['choices'][0]['finish_reason'] ?? '') : ($json['done_reason'] ?? '')) === 'length';
        if (trim($content) === '' && !$truncated) throw new LocalizedException('errors.inbox.badAnswer', [], 'inbox answer without content');
        return ['content' => $content, 'seconds' => $seconds, 'truncated' => $truncated];
    }

    /**
     * Kontextfenster in Token: Text + Antwort + 512 Reserve, auf 1.024 gerundet,
     * zwischen CTX_MIN und CTX_MAX. v3.7.0 (treffsicher, Nachbesserung 5): 2,5 Zeichen
     * je Token statt 3 — die echte Post hatte rund 2,6 (Zahlen, Abkürzungen, Umlaute),
     * mit 3 war das Fenster zu klein und die Antwort brach ab.
     */
    public static function contextFor(int $chars, int $answerTokens): int
    {
        $need = (int)ceil($chars / self::CHARS_PER_TOKEN) + $answerTokens + 512;
        return max(self::CTX_MIN, min(self::CTX_MAX, (int)ceil($need / 1024) * 1024));
    }

    /** JSON aus der Antwort: ohne Denkblock, auch in ```-Zäunen oder mitten im Text. */
    public static function parseJson(string $content): ?array
    {
        $s = preg_replace('/<think>.*?<\/think>/s', '', $content) ?? $content;
        $start = strpos($s, '{');
        $end = strrpos($s, '}');
        if ($start === false || $end === false || $end < $start) return null;
        $j = json_decode(substr($s, $start, $end - $start + 1), true);
        return is_array($j) ? $j : null;
    }
}
