<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Services\Inbox\LocalProvider;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\OcrService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (Posteingang) — der lokale Anschluss: dieselbe Einstellung und
 * Heimnetz-Regel wie die Texterkennung, so angefragt, wie es in der Probe trug
 * (temperature 0, JSON, ohne Denkmodus). Erst mit ersetztem Transport (wie der
 * OCR-Test), dann gegen einen nachgebauten Ollama-Dienst über echtes HTTP.
 */
#[CoversClass(LocalProvider::class)]
#[CoversClass(ProviderRegistry::class)]
final class InboxLocalProviderTest extends ServiceTestCase
{
    /** @var list<array{url:string, body:array, timeout:int, ip:?string}> */
    private array $calls = [];
    /** @var resource|null */
    private $proc = null;

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) { proc_terminate($this->proc); proc_close($this->proc); }
        parent::tearDown();
    }

    private function provider(array $answers, array $dns = []): LocalProvider
    {
        $ocr = new OcrService($this->settings, new AttachmentService($this->store, $this->settings, $this->i18n), null,
            fn(string $host): array => $dns[$host] ?? []);
        $transport = function (string $url, string $json, int $timeout, ?string $ip) use (&$answers): array {
            $this->calls[] = ['url' => $url, 'body' => json_decode($json, true), 'timeout' => $timeout, 'ip' => $ip];
            return array_shift($answers) ?? ['ok' => false, 'body' => null, 'http_code' => null, 'error_code' => 'network'];
        };
        return new LocalProvider($this->settings, $ocr, $this->i18n, $transport);
    }

    private static function ok(string $content): array
    {
        return ['ok' => true, 'body' => json_encode(['message' => ['content' => $content]]), 'http_code' => 200, 'error_code' => null];
    }

    private function key(callable $fn): string
    {
        try { $fn(); } catch (LocalizedException $e) { return $e->key; }
        self::fail('LocalizedException erwartet');
    }

    public function testOffMeansNoConnectionAndTheRecommendedModelIsTheDefault(): void
    {
        $p = $this->provider([]);
        self::assertFalse($p->enabled());
        self::assertSame('errors.inbox.off', $this->key(fn() => $p->classify('Text')));
        self::assertSame([], $this->calls);
        self::assertSame('qwen3.8', $p->model());
        self::assertSame('qwen3.8', $this->settings->get('inbox_model'));
        self::assertSame(LocalProvider::RECOMMENDED_MODEL, $this->settings->get('inbox_model'));
        $this->settings->set(['inbox_model' => '']);
        self::assertSame('qwen3.8', $p->model(), 'leer = empfohlenes Modell');
        self::assertSame('local', $this->settings->get('inbox_provider'));
    }

    public function testTheHomeNetworkRuleOfTheTextRecognitionApplies(): void
    {
        $this->settings->set(['ocr_endpoint' => 'http://ollama.example:11434']);
        $p = $this->provider([self::ok('{"type":"invoice"}')], ['ollama.example' => ['192.168.178.20', '93.184.216.34']]);
        self::assertSame('errors.ocr.notLocal', $this->key(fn() => $p->classify('Jahresabrechnung')));
        self::assertSame([], $this->calls, 'DNS-Rebinding: keine Verbindung');
    }

    public function testOllamaIsAskedLikeInTheProbe(): void
    {
        $this->settings->set(['ocr_endpoint' => 'http://nas.local:11434', 'inbox_timeout_s' => 120]);
        $p = $this->provider([self::ok("<think>…</think>```json\n{\"type\": \"invoice\", \"confidence\": 97}\n```"),
                              self::ok(json_encode(['fields' => ['period_from' => ['value' => '01.01.2025', 'quote' => 'Abrechnungszeitraum: 01.01.2025', 'page' => 1], 'reading_new' => '13.580']]))],
            ['nas.local' => ['192.168.178.4']]);
        $c = $p->classify('[Seite 1] Jahresabrechnung Erdgas');
        self::assertSame(['type' => 'invoice', 'confidence' => 0.97, 'model' => 'qwen3.8'], array_intersect_key($c, ['type' => 1, 'confidence' => 1, 'model' => 1]));
        $b = $this->calls[0]['body'];
        self::assertSame('http://nas.local:11434/api/chat', $this->calls[0]['url']);
        self::assertSame('192.168.178.4', $this->calls[0]['ip'], 'verbunden wird mit der geprüften Adresse');
        self::assertSame(120, $this->calls[0]['timeout']);
        self::assertSame('qwen3.8', $b['model']);
        self::assertSame(0, $b['options']['temperature']);
        self::assertSame('json', $b['format']);
        self::assertFalse($b['think'], 'ohne Denkmodus');
        self::assertFalse($b['stream']);
        self::assertGreaterThanOrEqual(8192, $b['options']['num_ctx'], 'Kontextfenster über dem Standard von Ollama');

        $x = $p->extract('invoice', InboxSchema::forProvider('invoice'), '[Seite 1] Abrechnungszeitraum: 01.01.2025');
        self::assertSame('01.01.2025', $x['fields']['period_from']['value']);
        self::assertSame(['value' => '13.580', 'quote' => null, 'page' => null], $x['fields']['reading_new'], 'nur ein Wert: ohne Zitat (→ prüfen)');
        $prompt = $this->calls[1]['body']['messages'][0]['content'];
        foreach (['Copy every value exactly as printed', 'Do not calculate', 'quote', '[Seite N]', 'voraussichtlich', 'gross (brutto)',
                  'not an average', 'one item for every line', '- advances_paid (list', '- kind (one of: annual, final)'] as $s) {
            self::assertStringContainsString($s, $prompt, $s);
        }
    }

    public function testOpenAiCompatibleServers(): void
    {
        $this->settings->set(['ocr_endpoint' => 'http://192.168.178.5:1234/v1', 'ocr_api' => 'openai', 'inbox_model' => 'qwen3-8b']);
        $p = $this->provider([['ok' => true, 'body' => json_encode(['choices' => [['message' => ['content' => '{"type":"price_change","confidence":0.8}']]]]), 'http_code' => 200, 'error_code' => null]]);
        self::assertSame('price_change', $p->classify('Preisanpassung')['type']);
        self::assertSame('http://192.168.178.5:1234/v1/chat/completions', $this->calls[0]['url']);
        self::assertSame(0, $this->calls[0]['body']['temperature']);
        self::assertSame('qwen3-8b', $this->calls[0]['body']['model']);
        self::assertStringContainsString('192.168.178.5', $p->label());
    }

    public function testErrorsOfTheServiceAreNamed(): void
    {
        $this->settings->set(['ocr_endpoint' => 'http://127.0.0.1:11434']);
        self::assertSame('errors.ocr.timeout', $this->key(fn() => $this->provider([['ok' => false, 'body' => null, 'http_code' => null, 'error_code' => 'timeout']])->classify('x')));
        self::assertSame('errors.inbox.modelMissing', $this->key(fn() => $this->provider([['ok' => false, 'body' => '{"error":"model \'qwen3.8\' not found"}', 'http_code' => 404, 'error_code' => 'http']])->classify('x')));
        self::assertSame('errors.ocr.unreachable', $this->key(fn() => $this->provider([['ok' => false, 'body' => 'x', 'http_code' => 500, 'error_code' => 'http']])->classify('x')));
        self::assertSame('errors.inbox.badAnswer', $this->key(fn() => $this->provider([self::ok('Das ist eine Rechnung.')])->classify('x')));
        self::assertSame('errors.inbox.badAnswer', $this->key(fn() => $this->provider([self::ok('{"type":"kochrezept"}')])->classify('x')));
    }

    /** Ollama-Antwort mit `done_reason` (`length`: am Fenster abgeschnitten). */
    private static function done(string $content, string $reason): array
    {
        return ['ok' => true, 'body' => json_encode(['message' => ['content' => $content], 'done_reason' => $reason]), 'http_code' => 200, 'error_code' => null];
    }

    public function testACutOffAnswerIsAskedOnceMoreWithTheDoubleBudget(): void
    {
        // v3.7.0 (treffsicher, Nachbesserung 5) — Attrappe: erst abgeschnitten (`length`), dann vollständig
        $this->settings->set(['ocr_endpoint' => 'http://127.0.0.1:11434', 'inbox_timeout_s' => 300]);
        $full = json_encode(['fields' => ['period_from' => ['value' => '01.01.2025', 'quote' => 'Abrechnungszeitraum: 01.01.2025', 'page' => 1]]]);
        $text = '[Seite 1] ' . str_repeat('Abrechnungszeitraum: 01.01.2025 bis 31.12.2025. ', 360);
        $p = $this->provider([self::done('{"fields": {"period_from": {"value": "01.01.20', 'length'), self::done($full, 'stop')]);
        $x = $p->extract('invoice', InboxSchema::forProvider('invoice'), $text);
        self::assertSame('01.01.2025', $x['fields']['period_from']['value']);
        self::assertCount(2, $this->calls, 'genau ein zweiter Versuch');
        [$a, $b] = [$this->calls[0]['body']['options']['num_ctx'], $this->calls[1]['body']['options']['num_ctx']];
        self::assertGreaterThan($a, $b, 'mit größerem Fenster');
        $chars = mb_strlen($this->calls[0]['body']['messages'][0]['content']) + mb_strlen($this->calls[0]['body']['messages'][1]['content']);
        self::assertSame([LocalProvider::contextFor($chars, LocalProvider::EXTRACT_TOKENS), LocalProvider::contextFor($chars, LocalProvider::EXTRACT_TOKENS_RETRY)], [$a, $b]);
        self::assertLessThanOrEqual(300, $this->calls[0]['timeout'] + 0);
        self::assertLessThanOrEqual(300, $this->calls[1]['timeout'], 'der zweite Versuch bekommt die übrige Zeit');

        // auch der zweite abgeschnitten: eine verständliche Meldung, kein „badAnswer“
        $this->calls = [];
        $p = $this->provider([self::done('{"fields": {', 'length'), self::done('{"fields": {"period', 'length')]);
        self::assertSame('errors.inbox.truncated', $this->key(fn() => $p->extract('invoice', InboxSchema::forProvider('invoice'), $text)));
        self::assertCount(2, $this->calls, 'nicht öfter als einmal wiederholt');
        // nicht abgeschnitten und kein JSON: wie bisher
        $this->calls = [];
        self::assertSame('errors.inbox.badAnswer', $this->key(fn() => $this->provider([self::done('Das ist eine Rechnung.', 'stop')])
            ->extract('invoice', InboxSchema::forProvider('invoice'), $text)));
        self::assertCount(1, $this->calls);
    }

    public function testACutOffAnswerOfAnOpenAiCompatibleServerIsAskedOnceMore(): void
    {
        $this->settings->set(['ocr_endpoint' => 'http://192.168.178.5:1234/v1', 'ocr_api' => 'openai', 'inbox_model' => 'qwen3-8b']);
        $cut = ['ok' => true, 'body' => json_encode(['choices' => [['message' => ['content' => '{"fields": {'], 'finish_reason' => 'length']]]), 'http_code' => 200, 'error_code' => null];
        $ok = ['ok' => true, 'body' => json_encode(['choices' => [['message' => ['content' => '{"fields": {"period_from": {"value": "01.01.2025", "quote": "x", "page": 1}}}'], 'finish_reason' => 'stop']]]), 'http_code' => 200, 'error_code' => null];
        $x = $this->provider([$cut, $ok])->extract('invoice', InboxSchema::forProvider('invoice'), '[Seite 1] Abrechnungszeitraum: 01.01.2025');
        self::assertSame('01.01.2025', $x['fields']['period_from']['value']);
        self::assertSame([LocalProvider::EXTRACT_TOKENS, LocalProvider::EXTRACT_TOKENS_RETRY], array_map(fn($c) => $c['body']['max_tokens'], $this->calls));
    }

    public function testTheContextGrowsWithTheText(): void
    {
        // v3.7.0 (treffsicher, Nachbesserung 5) — 2,5 Zeichen je Token: die Rechnung mit Zählertausch (≈ 7.270 Token
        // Eingabe) bekommt mit 8.192 Token Antwort 16.384 statt der 11.264, an denen sie abbrach
        self::assertSame(16384, LocalProvider::contextFor(18178, LocalProvider::EXTRACT_TOKENS));
        self::assertSame(32768, LocalProvider::CTX_MAX);
        self::assertSame(8192, LocalProvider::contextFor(3000, 200));
        self::assertGreaterThan(8192, LocalProvider::contextFor(40000, 4096));
        self::assertSame(32768, LocalProvider::contextFor(500000, 4096));
        self::assertSame(['a' => 1], LocalProvider::parseJson("Antwort: ```json\n{\"a\": 1}\n``` fertig"));
        self::assertNull(LocalProvider::parseJson('kein JSON'));
    }

    public function testAgainstAFakeOllamaOverRealHttp(): void
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $log = $this->dataDir . '/ollama.log';
        $env = array_merge(getenv(), ['FAKE_OLLAMA_LOG' => $log]);
        $this->proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", dirname(__DIR__, 2) . '/fixtures/fake-ollama.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__, 3), $env);
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) usleep(50_000);

        $this->settings->set(['ocr_endpoint' => "http://127.0.0.1:$port"]);
        $ocr = new OcrService($this->settings, new AttachmentService($this->store, $this->settings, $this->i18n));
        $p = new LocalProvider($this->settings, $ocr, $this->i18n);   // echter Transport (curl)
        $registry = new ProviderRegistry($this->settings, $p);
        self::assertSame($p, $registry->active());
        $c = $registry->active()->classify("[Seite 1]\n" . InboxFixture::invoicePages()[0]);
        self::assertSame('invoice', $c['type']);
        self::assertSame(0.97, $c['confidence']);
        $x = $p->extract('invoice', InboxSchema::forProvider('invoice'), "[Seite 1]\n" . InboxFixture::invoicePages()[0]);
        self::assertSame('13.580', $x['fields']['reading_new']['value']);
        self::assertSame('840,00', $x['fields']['advances_paid']['value'][0]['amount']);
        $requests = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents($log))));
        self::assertCount(2, $requests);
        self::assertFalse($requests[0]['think']);
        self::assertStringContainsString('Jahresabrechnung Erdgas 2025', $requests[1]['messages'][1]['content']);

        $this->settings->set(['inbox_model' => 'unbekannt']);
        self::assertSame('errors.inbox.modelMissing', $this->key(fn() => $p->classify('x')));
    }
}
