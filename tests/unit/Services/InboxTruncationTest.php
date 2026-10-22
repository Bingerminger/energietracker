<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Http\HttpStatusException;
use Energietracker\Services\Inbox\CloudConfig;
use Energietracker\Services\Inbox\OpenAiCompatibleProvider;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (treffsicher, Nachbesserung 5) — eine abgeschnittene Antwort (`length`)
 * des OpenAI-kompatiblen Cloud-Anschlusses wird einmal mit großem Antwortbudget
 * wiederholt, in der übrigen Zeit des Zeitlimits; erst danach die Meldung
 * „unvollständig“. Attrappe statt Dienst; alles erfunden.
 */
#[CoversClass(OpenAiCompatibleProvider::class)]
final class InboxTruncationTest extends ServiceTestCase
{
    /** @var list<array{body: array<string,mixed>, timeout: int}> */
    private array $sent = [];

    /** @param list<string> $finish je Antwort der Grund des Endes */
    private function provider(array $finish): OpenAiCompatibleProvider
    {
        $cfg = new CloudConfig($this->settings, $this->store);
        $cfg->update(['provider' => 'openai', 'enabled' => true, 'api_key' => 'sk-oa-test-ok', 'endpoint' => 'https://llm.example.com/v1', 'model' => 'test-model']);
        $transport = function (string $url, array $headers, ?string $body, int $timeout, ?string $ip) use (&$finish): array {
            $this->sent[] = ['body' => (array)json_decode((string)$body, true), 'timeout' => $timeout];
            $why = array_shift($finish) ?? 'stop';
            $content = $why === 'length' ? '{"period_from": {"value": "01.01.20' : '{"period_from": {"value": "01.01.2025", "quote": "Abrechnungszeitraum: 01.01.2025", "page": 1}}';
            return ['ok' => true, 'status' => 200, 'headers' => [], 'error' => null,
                    'body' => json_encode(['model' => 'test-model', 'choices' => [['message' => ['content' => $content], 'finish_reason' => $why]]])];
        };
        return new OpenAiCompatibleProvider($cfg, $this->i18n, $transport, fn(string $h): array => ['8.8.8.8']);
    }

    public function testACutOffAnswerIsAskedOnceMoreWithALargeBudget(): void
    {
        $x = $this->provider(['length', 'stop'])->extract('invoice', InboxSchema::forProvider('invoice'), '[Seite 1] Abrechnungszeitraum: 01.01.2025');
        self::assertSame('2025-01-01', \Energietracker\Services\Inbox\Evidence::dateValue((string)$x['fields']['period_from']['value']));
        self::assertCount(2, $this->sent);
        self::assertArrayNotHasKey('max_tokens', $this->sent[0]['body'], 'erst ohne Vorgabe (Standard des Dienstes)');
        self::assertSame(OpenAiCompatibleProvider::RETRY_MAX_TOKENS, $this->sent[1]['body']['max_tokens'] ?? null);
        self::assertLessThanOrEqual($this->sent[0]['timeout'], $this->sent[1]['timeout'], 'der zweite Versuch in der übrigen Zeit');
    }

    public function testTwiceCutOffEndsWithTheMessageIncompleteNotBadAnswer(): void
    {
        try {
            $this->provider(['length', 'length'])->extract('invoice', InboxSchema::forProvider('invoice'), '[Seite 1] Abrechnungszeitraum: 01.01.2025');
            self::fail('Meldung erwartet');
        } catch (HttpStatusException $e) {
            self::assertSame([502, 'errors.inboxCloud.truncated'], [$e->status, $e->key]);
        }
        self::assertCount(2, $this->sent, 'nur einmal wiederholt');
    }
}
