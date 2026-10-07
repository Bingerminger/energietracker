<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\OcrService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.1.0 (Paket H2, MKT-08 Stufe 2) — Texterkennung nur im Heimnetz.
 * Transport und Namensauflösung sind ersetzt; der Test geht nie ins Netz.
 */
#[CoversClass(OcrService::class)]
final class OcrServiceTest extends ServiceTestCase
{
    private AttachmentService $att;
    /** @var list<array{url:string, body:array, timeout:int, ip:?string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->att = new AttachmentService($this->store, $this->settings, $this->i18n);
    }

    private function ocr(array $answer = ['ok' => true, 'body' => '{"message":{"content":"{\"value\": 12345.6, \"confidence\": 0.9}"}}', 'http_code' => 200, 'error_code' => null], array $dns = []): OcrService
    {
        $transport = function (string $url, string $json, int $timeout, ?string $ip) use ($answer): array {
            $this->calls[] = ['url' => $url, 'body' => json_decode($json, true), 'timeout' => $timeout, 'ip' => $ip];
            return $answer;
        };
        return new OcrService($this->settings, $this->att, $transport, fn(string $host): array => $dns[$host] ?? []);
    }

    public static function ips(): array
    {
        return [
            ['127.0.0.1', true], ['192.168.178.10', true], ['10.0.0.5', true], ['172.16.0.1', true], ['172.31.255.1', true],
            ['169.254.10.1', true], ['100.100.1.2', true], ['::1', true], ['fd12:3456::1', true], ['fe80::1', true],
            ['::ffff:192.168.0.2', true],
            ['8.8.8.8', false], ['172.32.0.1', false], ['100.128.0.1', false], ['1.1.1.1', false], ['2001:4860::8888', false],
            ['::ffff:8.8.8.8', false], ['kein-ip', false],
        ];
    }

    #[DataProvider('ips')]
    public function testOnlyLocalNetworksCount(string $ip, bool $local): void
    {
        self::assertSame($local, OcrService::isLocalIp($ip), $ip);
    }

    public function testOffMeansNoConnection(): void
    {
        $photo = $this->att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $ocr = $this->ocr();
        self::assertFalse($ocr->enabled());
        self::assertSame('errors.ocr.off', $this->key(fn() => $ocr->read($photo['id'])));
        self::assertSame([], $this->calls);
    }

    public function testAPublicHostIsRejectedEvenBehindAName(): void
    {
        $photo = $this->att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $this->settings->set(['ocr_endpoint' => 'http://8.8.8.8:11434']);
        self::assertSame('errors.ocr.notLocal', $this->key(fn() => $this->ocr()->read($photo['id'])));

        // ein Name, der (auch) öffentlich auflöst — DNS-Rebinding
        $this->settings->set(['ocr_endpoint' => 'http://ollama.example:11434']);
        $ocr = $this->ocr(dns: ['ollama.example' => ['192.168.178.20', '93.184.216.34']]);
        self::assertSame('errors.ocr.notLocal', $this->key(fn() => $ocr->read($photo['id'])));
        self::assertSame([], $this->calls, 'keine Verbindung');
    }

    public function testOllamaGetsTheImageAndTheVerifiedAddress(): void
    {
        $photo = $this->att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $this->settings->set(['ocr_endpoint' => 'http://nas.local:11434/', 'ocr_model' => 'qwen2.5vl', 'ocr_timeout_s' => 20]);
        $r = $this->ocr(dns: ['nas.local' => ['192.168.178.4']])->read($photo['id']);

        self::assertSame(12345.6, $r['value']);
        self::assertSame(0.9, $r['confidence']);
        self::assertSame('qwen2.5vl', $r['model']);
        self::assertSame('http://nas.local:11434/api/chat', $this->calls[0]['url']);
        self::assertSame('192.168.178.4', $this->calls[0]['ip'], 'verbunden wird mit der geprüften Adresse');
        self::assertSame(20, $this->calls[0]['timeout']);
        self::assertSame([base64_encode(AttachmentServiceTest::JPEG)], $this->calls[0]['body']['messages'][0]['images']);
        self::assertFalse($this->calls[0]['body']['stream']);
    }

    public function testOpenAiCompatibleServers(): void
    {
        $photo = $this->att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $this->settings->set(['ocr_endpoint' => 'http://192.168.178.5:1234/v1', 'ocr_api' => 'openai']);
        $r = $this->ocr(['ok' => true, 'body' => '{"choices":[{"message":{"content":"Der Zähler zeigt 04711,25 m³"}}]}', 'http_code' => 200, 'error_code' => null])
            ->read($photo['id']);
        self::assertSame(4711.25, $r['value']);
        self::assertNull($r['confidence']);
        self::assertSame('http://192.168.178.5:1234/v1/chat/completions', $this->calls[0]['url']);
        $content = $this->calls[0]['body']['messages'][0]['content'];
        self::assertStringStartsWith('data:image/jpeg;base64,', $content[1]['image_url']['url']);
    }

    public function testTimeoutsAndRubbish(): void
    {
        $photo = $this->att->store(AttachmentServiceTest::JPEG, 'reading_photo');
        $this->settings->set(['ocr_endpoint' => 'http://127.0.0.1:11434']);
        self::assertSame('errors.ocr.timeout', $this->key(fn() => $this->ocr(['ok' => false, 'body' => null, 'http_code' => null, 'error_code' => 'timeout'])->read($photo['id'])));
        self::assertSame('errors.ocr.unreachable', $this->key(fn() => $this->ocr(['ok' => false, 'body' => 'x', 'http_code' => 500, 'error_code' => 'http'])->read($photo['id'])));
        self::assertSame('errors.ocr.badAnswer', $this->key(fn() => $this->ocr(['ok' => true, 'body' => '<html>', 'http_code' => 200, 'error_code' => null])->read($photo['id'])));
        $r = $this->ocr(['ok' => true, 'body' => '{"message":{"content":"Ich sehe keinen Zähler."}}', 'http_code' => 200, 'error_code' => null])->read($photo['id']);
        self::assertNull($r['value']);
    }

    public function testOnlyReadingPhotos(): void
    {
        $pdf = $this->att->store(AttachmentServiceTest::PDF, 'bill_pdf');
        $this->settings->set(['ocr_endpoint' => 'http://127.0.0.1:11434']);
        self::assertSame('errors.attachment.wrongKind', $this->key(fn() => $this->ocr()->read($pdf['id'])));
    }

    public static function answers(): array
    {
        return [
            ['{"value": 12345.678, "confidence": 0.8}', 12345.678, 0.8],
            ["```json\n{\"value\": \"12345,6\", \"confidence\": 95}\n```", 12345.6, 0.95],
            ['{"value": null, "confidence": 0}', null, null],
            ['12.345,6', 12345.6, null],
            ['1,234,567.5', 1234567.5, null],
            ['1.234.567', 1234567.0, null],
            ['Stand: 0 4 7 1 1', 4711.0, null],
            ['nichts lesbar', null, null],
            ['{"value": -5}', null, null],
        ];
    }

    #[DataProvider('answers')]
    public function testTheAnswerIsReadTolerantly(string $content, ?float $value, ?float $confidence): void
    {
        $r = OcrService::parseAnswer($content);
        self::assertSame($value, $r['value'], $content);
        self::assertSame($confidence, $r['confidence'], $content);
    }

    public function testSettingsAcceptOnlyHttpAddresses(): void
    {
        foreach (['ftp://nas/x', 'file:///etc/passwd', 'http://user:pw@nas', 'nas:11434'] as $bad) {
            try {
                $this->settings->set(['ocr_endpoint' => $bad]);
                self::fail("angenommen: $bad");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->settings->set(['ocr_endpoint' => '  http://[fd00::5]:11434  ']);
        self::assertSame('http://[fd00::5]:11434', $this->settings->get('ocr_endpoint'));
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->set(['ocr_api' => 'cloud']);
    }

    private function key(callable $fn): string
    {
        try {
            $fn();
        } catch (LocalizedException $e) {
            return $e->key;
        }
        self::fail('LocalizedException erwartet');
    }
}
