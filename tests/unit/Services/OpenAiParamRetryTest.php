<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Http\HttpStatusException;
use Energietracker\Services\Inbox\CloudConfig;
use Energietracker\Services\Inbox\OpenAiCompatibleProvider;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.10.0 (GitHub #41) — Neuere OpenAI-Modelle nehmen nur ihre
 * Standard-Temperatur und statt `max_tokens` nur `max_completion_tokens`. Bis
 * v3.9.0 schickte der Anschluss jedem Modell `temperature: 0` und beim
 * zweiten Versuch nach `length` `max_tokens`; die Antwort war 400 und das
 * Auslesen scheiterte ganz.
 *
 * Jetzt: bei 400 mit `error.param` einmal ohne `temperature` bzw. mit
 * `max_completion_tokens`. Entschieden wird am Feld, nie am Meldungstext, und
 * ohne Liste von Modellnamen. Die Antworten des Diensts sind aufgezeichnet
 * und erfunden (tests/fixtures/openai-params); kein echter Dienst.
 */
final class OpenAiParamRetryTest extends ServiceTestCase
{
    /** @var list<array{body:array<string,mixed>, timeout:int}> */
    private array $requests = [];

    private static function fixture(string $name): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/openai-params/' . $name);
    }

    private static function answer(int $status, string $fixture): array
    {
        return ['ok' => $status === 200, 'status' => $status, 'body' => self::fixture($fixture), 'headers' => [], 'error' => $status === 200 ? null : 'http'];
    }

    /**
     * Ein nachgebauter Dienst: `$model` bekommt den Körper der Anfrage und gibt
     * [Status, Fixture] zurück.
     */
    private function provider(callable $model): OpenAiCompatibleProvider
    {
        $cfg = new CloudConfig($this->settings, $this->store);
        $cfg->update(['provider' => 'openai', 'enabled' => true, 'api_key' => 'sk-oa-test-0123456789',
            'endpoint' => 'https://llm.example.com/v1', 'model' => 'example-model-2026']);
        $transport = function (string $url, array $headers, ?string $body, int $timeout, ?string $ip) use ($model): array {
            $req = (array)json_decode((string)$body, true);
            $this->requests[] = ['body' => $req, 'timeout' => $timeout];
            return self::answer(...$model($req));
        };
        return new OpenAiCompatibleProvider($cfg, $this->i18n, $transport, fn(string $h): array => ['8.8.8.8']);
    }

    /** Ein neueres Modell: lehnt `temperature` und `max_tokens` ab, schneidet ohne Budget ab. */
    private static function newerModel(): callable
    {
        return static function (array $req): array {
            if (array_key_exists('temperature', $req)) return [400, 'temperature-unsupported.json'];
            if (array_key_exists('max_tokens', $req)) return [400, 'max-tokens-unsupported.json'];
            return [200, isset($req['max_completion_tokens']) ? 'answer-classify.json' : 'answer-length.json'];
        };
    }

    public function testANewerModelGetsTheRequestWithoutTemperatureAndWithMaxCompletionTokens(): void
    {
        $r = $this->provider(self::newerModel())->classify('Preisänderung ab 01.03.2026');
        self::assertSame(['price_change', 0.88], [$r['type'], $r['confidence']], 'das Auslesen gelingt');
        // 1: mit temperature → 400; 2: ohne → length; 3: Budget als max_tokens → 400; 4: als max_completion_tokens → ok
        self::assertCount(4, $this->requests);
        self::assertSame(0, $this->requests[0]['body']['temperature']);
        self::assertArrayNotHasKey('temperature', $this->requests[1]['body']);
        self::assertSame(OpenAiCompatibleProvider::RETRY_MAX_TOKENS, $this->requests[2]['body']['max_tokens']);
        self::assertArrayNotHasKey('temperature', $this->requests[2]['body'], 'gelernt: auch der zweite Versuch ohne');
        self::assertSame(OpenAiCompatibleProvider::RETRY_MAX_TOKENS, $this->requests[3]['body']['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $this->requests[3]['body']);
        foreach ($this->requests as $q) {
            self::assertSame(['type' => 'json_object'], $q['body']['response_format'], 'sonst bleibt die Anfrage gleich');
            self::assertSame('example-model-2026', $q['body']['model']);
        }
    }

    /** Was einmal gelernt ist, gilt für die nächste Frage derselben Anfrage (Art, dann Felder). */
    public function testTheSecondQuestionStartsWithWhatWasLearned(): void
    {
        $p = $this->provider(static fn(array $req): array => array_key_exists('temperature', $req)
            ? [400, 'temperature-unsupported.json'] : [200, 'answer-classify.json']);
        $p->classify('x');
        $p->classify('y');
        self::assertSame([true, false, false], array_map(fn($q) => array_key_exists('temperature', $q['body']), $this->requests));
    }

    /** Ein Dienst, der die alten Parameter nimmt (ältere Modelle, Mistral, OpenRouter): dieselbe Anfrage wie bisher. */
    public function testAServiceThatAcceptsTheOldParametersSeesNoChange(): void
    {
        $this->provider(static fn(array $req): array => [200, isset($req['max_tokens']) ? 'answer-classify.json' : 'answer-length.json'])->classify('x');
        self::assertCount(2, $this->requests, 'nur der Versuch nach length, wie seit v3.7.0');
        self::assertSame(0, $this->requests[0]['body']['temperature']);
        self::assertArrayNotHasKey('max_tokens', $this->requests[0]['body']);
        self::assertSame([0, OpenAiCompatibleProvider::RETRY_MAX_TOKENS], [$this->requests[1]['body']['temperature'], $this->requests[1]['body']['max_tokens']]);
        self::assertArrayNotHasKey('max_completion_tokens', $this->requests[1]['body']);
    }

    /** Entschieden am Feld: Nennt nur der Meldungstext `temperature`, wird nicht wiederholt. */
    public function testTheMessageTextDecidesNothing(): void
    {
        $e = $this->failure(fn() => $this->provider(static fn(array $req): array => [400, 'param-null.json'])->classify('x'));
        self::assertSame([502, 'errors.inboxCloud.rejected'], [$e->status, $e->key]);
        self::assertCount(1, $this->requests);
    }

    /** Ein anderer Parameter: keine Wiederholung, die Absage mit dem Text des Diensts wie bisher. */
    public function testAnotherParameterIsNotRetried(): void
    {
        $e = $this->failure(fn() => $this->provider(static fn(array $req): array => [400, 'response-format-unsupported.json'])->classify('x'));
        self::assertSame([502, 'errors.inboxCloud.rejected'], [$e->status, $e->key]);
        self::assertStringContainsString('response_format', (string)$e->params['detail']);
        self::assertCount(1, $this->requests);
    }

    /** Einmal je Parameter: Lehnt der Dienst auch die angepasste Anfrage ab, bleibt es bei der Absage. */
    public function testOnlyOnce(): void
    {
        $e = $this->failure(fn() => $this->provider(static fn(array $req): array => [400, 'temperature-unsupported.json'])->classify('x'));
        self::assertSame('errors.inboxCloud.rejected', $e->key);
        self::assertCount(2, $this->requests);
        // max_tokens im ersten Versuch gibt es nicht (ohne Budget): dort hilft der Tausch nicht, keine Wiederholung
        $this->requests = [];
        $e = $this->failure(fn() => $this->provider(static fn(array $req): array => [400, 'max-tokens-unsupported.json'])->classify('x'));
        self::assertCount(1, $this->requests);
    }

    public function testRejectedParamReadsOnlyTheFieldOfA400(): void
    {
        self::assertSame('temperature', OpenAiCompatibleProvider::rejectedParam(['status' => 400, 'body' => self::fixture('temperature-unsupported.json')]));
        self::assertSame('max_tokens', OpenAiCompatibleProvider::rejectedParam(['status' => 400, 'body' => self::fixture('max-tokens-unsupported.json')]));
        self::assertNull(OpenAiCompatibleProvider::rejectedParam(['status' => 400, 'body' => self::fixture('param-null.json')]));
        self::assertNull(OpenAiCompatibleProvider::rejectedParam(['status' => 422, 'body' => self::fixture('temperature-unsupported.json')]), 'nur 400');
        self::assertNull(OpenAiCompatibleProvider::rejectedParam(['status' => 400, 'body' => 'kein JSON']));
    }

    private function failure(callable $fn): HttpStatusException
    {
        try {
            $fn();
        } catch (HttpStatusException $e) {
            return $e;
        }
        self::fail('keine Ausnahme');
    }
}
