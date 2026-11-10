<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\EvccService;
use Energietracker\Services\HomeAssistant\NotifyTransport;
use Energietracker\Services\Inbox\CloudHttp;
use Energietracker\Services\OcrService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Support\IpAddress;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 — Metadatendienste gemieteter Server sind auf allen drei Wegen mit
 * einer eingetragenen Adresse gesperrt.
 *
 * `169.254.169.254` liegt in Link-local und war schon gesperrt, `fd00:ec2::254`
 * trotz ULA ebenso. `100.100.100.200` (der Metadatendienst mancher Anbieter)
 * liegt dagegen in `100.64.0.0/10`, das die Texterkennung, evcc und Home
 * Assistant als Heimnetz annehmen (CGNAT beim Anbieter, Tailscale). Bis v3.12
 * konnte eine eingetragene Adresse dorthin zeigen. Gesperrt ist **nur diese eine
 * Adresse**, nicht das Netz: Die Nachbarn (`100.100.100.100`, `…199`, `…201`)
 * bleiben Heimnetz.
 *
 * Geprüft wird jeder Weg so, wie er die Adresse bekommt: direkt eingetragen und
 * hinter einem Namen (Namensauflösung ersetzt, der Test geht nie ins Netz).
 * Keiner der gesperrten Fälle darf eine Verbindung aufbauen.
 */
final class MetadataAddressTest extends ServiceTestCase
{
    private const META = '100.100.100.200';
    /** 100 = 0x64, 200 = 0xc8 → 100.100.100.200 = 6464:64c8 */
    private const META_FORMS = ['100.100.100.200', '::ffff:100.100.100.200', '::ffff:6464:64c8',
                                '0:0:0:0:0:ffff:6464:64c8', '64:ff9b::6464:64c8', '2002:6464:64c8::1', '::6464:64c8'];
    /** dasselbe Netz 100.64.0.0/10, aber kein Metadatendienst */
    private const NEIGHBOURS = ['100.100.100.100', '100.100.100.199', '100.100.100.201', '100.64.0.1', '100.127.255.254'];

    /** @var list<string> Adressen, mit denen ein Weg verbinden wollte */
    private array $connected = [];

    private function key(callable $fn): string
    {
        try {
            $fn();
        } catch (LocalizedException $e) {
            return $e->key;
        }
        self::fail('LocalizedException erwartet');
    }

    /** @param list<string> $ips */
    private static function dns(array $ips): callable
    {
        return fn(string $host): array => $ips;
    }

    public function testTheListNamesEveryMetadataServiceAndSeesThroughEveryForm(): void
    {
        foreach (self::META_FORMS as $ip) self::assertTrue(IpAddress::isMetadata($ip), "$ip ist der Metadatendienst");
        foreach (['169.254.169.254', '::ffff:a9fe:a9fe', 'fd00:ec2::254'] as $ip) self::assertTrue(IpAddress::isMetadata($ip), $ip);
        foreach (array_merge(self::NEIGHBOURS, ['192.168.178.20', '8.8.8.8', 'fd00:ec2::253', '', 'kein-ip']) as $ip) {
            self::assertFalse(IpAddress::isMetadata($ip), "$ip ist kein Metadatendienst");
        }
    }

    /** Texterkennung (und der lokale Posteingang über dieselbe Prüfung): nie Heimnetz. */
    public function testTextRecognitionRefusesTheMetadataService(): void
    {
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        foreach (['http://' . self::META . ':11434', 'http://[::ffff:6464:64c8]:11434'] as $url) {
            self::assertSame('errors.ocr.notLocal', $this->key(fn() => (new OcrService($this->settings, $att, null, self::dns([])))->checkLocal($url)), $url);
        }
        // hinter einem Namen, auch wenn der Name zusätzlich auf eine Heimnetz-Adresse zeigt
        $ocr = new OcrService($this->settings, $att, null, self::dns(['192.168.178.20', self::META]));
        self::assertSame('errors.ocr.notLocal', $this->key(fn() => $ocr->checkLocal('http://ollama.example:11434')));
        // die Nachbarn bleiben Heimnetz
        foreach (self::NEIGHBOURS as $ip) {
            $ocr = new OcrService($this->settings, $att, null, self::dns([$ip]));
            self::assertSame($ip, $ocr->checkLocal('http://ollama.example:11434'), "$ip bleibt Heimnetz");
        }
    }

    /** evcc: dieselbe Regel, keine Verbindung. */
    public function testEvccRefusesTheMetadataService(): void
    {
        $wallbox = (string)$this->meters->create('strom', ['name' => 'Wallbox', 'installed_on' => '2026-01-01',
            'parent_meter_id' => (string)$this->meters->defaultId('strom'), 'role' => 'ev_charger'])['id'];
        $evcc = fn(array $ips): EvccService => new EvccService($this->store, $this->meters, $this->readings,
            new ReadingImportService($this->readings, $this->meters, $this->i18n), $this->settings,
            function (string $url, int $timeout, ?string $ip): array {
                $this->connected[] = (string)$ip;
                return ['ok' => true, 'body' => '[]', 'http_code' => 200, 'error_code' => null];
            }, self::dns($ips));

        $this->settings->set(['evcc_endpoint' => 'http://' . self::META . ':7070']);
        self::assertSame('errors.evcc.notLocal', $this->key(fn() => $evcc([])->sync($wallbox)));
        $this->settings->set(['evcc_endpoint' => 'http://evcc.example:7070']);
        self::assertSame('errors.evcc.notLocal', $this->key(fn() => $evcc([self::META])->sync($wallbox)));
        self::assertSame([], $this->connected, 'keine Verbindung zum Metadatendienst');

        // der Nachbar im selben Netz: verbunden, die leere Liste meldet „keine Ladevorgänge“
        self::assertSame('errors.evcc.noSessions', $this->key(fn() => $evcc(['100.100.100.100'])->sync($wallbox)));
        self::assertSame(['100.100.100.100'], $this->connected, 'der Nachbar im selben Netz bleibt erreichbar');
    }

    /** Home Assistant: gesperrt wie Link-local, in jeder Form der Adresse. */
    public function testHomeAssistantRefusesTheMetadataService(): void
    {
        $send = function (string $url, string $json, array $headers, int $timeout, ?string $ip): array {
            $this->connected[] = (string)$ip;
            return ['ok' => true, 'http_code' => 200, 'error_code' => null];
        };
        foreach (self::META_FORMS as $ip) {
            $host = str_contains($ip, ':') ? "[$ip]" : $ip;
            $r = (new NotifyTransport($send, self::dns([])))->post("https://$host:8123/api/webhook/et", ['message' => 'x']);
            self::assertSame(['ok' => false, 'status' => null, 'error' => 'address'], $r, $ip);
            self::assertTrue(NotifyTransport::forbidden($ip), $ip);
        }
        $r = (new NotifyTransport($send, self::dns(['192.168.178.20', self::META])))->post('http://ha.example:8123/api/webhook/et', ['message' => 'x']);
        self::assertSame('address', $r['error'], 'hinter einem Namen');
        self::assertSame([], $this->connected, 'keine Verbindung zum Metadatendienst');

        foreach (self::NEIGHBOURS as $ip) self::assertFalse(NotifyTransport::forbidden($ip), "$ip nicht gesperrt");
        $r = (new NotifyTransport($send, self::dns(['100.100.100.100'])))->post('http://ha.example:8123/api/webhook/et', ['message' => 'x']);
        self::assertTrue($r['ok'], 'der Nachbar im Heimnetz darf HTTP');
        self::assertSame(['100.100.100.100'], $this->connected);
    }

    /** Cloud: nie öffentlich, direkt eingetragen und hinter einem Namen. */
    public function testTheCloudRefusesTheMetadataService(): void
    {
        foreach (self::META_FORMS as $ip) self::assertFalse(CloudHttp::isPublicIp($ip), $ip);
        self::assertSame('errors.inboxCloud.notPublic', $this->key(fn() => CloudHttp::checkUrl('https://' . self::META . '/v1')));
        self::assertSame('errors.inboxCloud.notPublic',
            $this->key(fn() => CloudHttp::publicAddress('https://api.example.com/v1', self::dns(['93.184.216.34', self::META]))));
        self::assertSame('93.184.216.34', CloudHttp::publicAddress('https://api.example.com/v1', self::dns(['93.184.216.34'])));
    }

    /** Die Doku (sicherheit.md, `doc-claim: metadata-blocked`) nennt genau die gesperrten Adressen. */
    public function testTheDocumentedListIsTheCodesList(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docs/betrieb/sicherheit.md' => 'nur diese eine Adresse', 'docs/en/betrieb/sicherheit.md' => 'Only this one address'] as $rel => $only) {
            $text = (string)file_get_contents("$root/$rel");
            $pos = strpos($text, '<!-- doc-claim: metadata-blocked');
            self::assertNotFalse($pos, "$rel: Zusage fehlt");
            $para = substr($text, (int)$pos);
            $para = (string)preg_replace('/\s+/', ' ', substr($para, 0, (int)strpos($para, "\n\n")));   // bis zur Leerzeile
            foreach (IpAddress::METADATA as $ip) self::assertStringContainsString("`$ip`", $para, "$rel nennt $ip");
            self::assertStringContainsString('`100.64.0.0/10`', $para, "$rel: das Netz bleibt Heimnetz");
            self::assertStringContainsString($only, $para, $rel);
        }
    }
}
