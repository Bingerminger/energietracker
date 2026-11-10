<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\HomeAssistant\NotifyConfig;
use Energietracker\Services\HomeAssistant\NotifyTransport;
use Energietracker\Services\HomeAssistantNotifyService;
use Energietracker\Services\InstanceService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Fremdtext in Nachrichten an Home Assistant: Home Assistant stellt `message` als Markdown dar. Den Namen
 * eines Dokuments bestimmt beim Weg über den überwachten Ordner oder die Weiterleitung der Absender; ein Name wie
 * `[Jetzt zahlen](https://…)` stand als anklickbarer Link in der Benachrichtigung der App, ein Bild lud ein fremder
 * Server nach. Dasselbe galt für Titel und Text von Terminen und Hinweisen. Jetzt ist Fremdtext ohne Link, Bild,
 * HTML und Zeilenumbruch und hat eine Höchstlänge; ein gewöhnlicher Name bleibt, wie er ist. Alle Namen erfunden.
 */
#[CoversClass(HomeAssistantNotifyService::class)]
final class HomeAssistantNotifyForeignTextTest extends ServiceTestCase
{
    /** @var list<array{url:string, body:mixed}> */
    private array $calls = [];
    private array $events = [];
    private array $recs = [];
    private array $docs = [];
    private int $now;

    /** Name eines Dokuments mit Link, Bild, HTML, Zeilenumbruch und Überschrift */
    private const NASTY = "[Jetzt bezahlen](https://pay.example.invalid/a) ![b](https://img.example.invalid/p.png)\n# Wichtig <img src=x onerror=alert(1)> www.example.invalid kasse@example.invalid";

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = mktime(9, 30, 0, 3, 10, 2026);
    }

    private function service(): HomeAssistantNotifyService
    {
        $transport = new NotifyTransport(function (string $url, string $json, array $headers, int $timeout, ?string $ip): array {
            $this->calls[] = ['url' => $url, 'body' => json_decode($json, true)];
            return ['ok' => true, 'http_code' => 200, 'error_code' => null];
        }, fn(string $h): array => ['homeassistant.local' => ['192.168.178.10']][$h] ?? []);
        $cfg = new NotifyConfig($this->settings, $this->store, fn(string $k) => false);
        $s = new HomeAssistantNotifyService($cfg, $transport, $this->store, $this->i18n, new InstanceService($this->store),
            fn(int $days, string $today): array => $this->events, fn(): array => $this->recs, fn(): array => $this->docs, fn(): int => $this->now);
        $s->update(['target' => 'token', 'url' => 'http://homeassistant.local:8123', 'token' => str_repeat('t', 40), 'enabled' => true,
            'service' => 'persistent_notification.create']);
        return $s;
    }

    /** Was Markdown zu Link, Bild, HTML oder Block macht, steht nicht in der Nachricht. */
    private static function assertInert(string $message, string $what): void
    {
        foreach (['](', '![', '[', '<img', '<', '>', 'https://', 'www.example', 'kasse@example', "\n#"] as $bad) {
            self::assertStringNotContainsString($bad, $message, "$what: „{$bad}“");
        }
    }

    public function testADocumentNameCarriesNoLinkNoImageNoHtml(): void
    {
        $s = $this->service();
        $this->now += 5;
        $this->docs = [['id' => 'inb_x', 'name' => self::NASTY, 'created_at' => date('c', $this->now)]];
        $s->documentArrived($this->docs[0]);
        $s->tick();
        self::assertCount(1, $this->calls);
        $msg = (string)$this->calls[0]['body']['message'];
        self::assertInert($msg, 'Dokument');
        self::assertStringStartsWith('Neues Dokument im Posteingang: (Jetzt bezahlen)', $msg, 'lesbar bleibt der Text');
        self::assertStringNotContainsString("\n", $msg, 'ein Name, eine Zeile');
    }

    public function testAnOrdinaryNameStaysAsItIs(): void
    {
        $s = $this->service();
        $this->now += 5;
        $this->docs = [['id' => 'inb_y', 'name' => 'Rechnung_2026_01 (Gas).pdf', 'created_at' => date('c', $this->now)]];
        $s->documentArrived($this->docs[0]);
        $s->tick();
        self::assertSame('Neues Dokument im Posteingang: Rechnung_2026_01 (Gas).pdf', $this->calls[0]['body']['message']);
    }

    public function testALongNameIsCut(): void
    {
        $name = str_repeat('Abrechnung ', 60) . '.pdf';
        self::assertSame(HomeAssistantNotifyService::FOREIGN_MAX, mb_strlen(HomeAssistantNotifyService::plain($name)));
        self::assertStringEndsWith('…', HomeAssistantNotifyService::plain($name));
    }

    public function testTitlesAndTextsOfTheDailyCheckToo(): void
    {
        $s = $this->service();
        $s->update(['events' => ['reminder', 'deadline', 'warning']]);
        $date = date('Y-m-d', $this->now);
        $this->events = [['uid' => 'rem-1', 'kind' => 'reminder', 'date' => $date, 'title' => self::NASTY, 'severity' => 'due',
            'due_now' => true, 'done' => null, 'occurrence' => "rem-1@$date", 'href' => '#/']];
        $this->recs = [['id' => 'rec_1', 'severity' => 'warning', 'category' => 'consumption', 'title' => 'Hinweis ' . self::NASTY, 'detail' => self::NASTY]];
        $this->now = mktime(10, 0, 0, 3, 10, 2026);
        $s->tick();
        self::assertCount(2, $this->calls);
        foreach ($this->calls as $i => $c) {
            // der Satz der App darf umbrechen (Titel, darunter das Wann), der Fremdtext nicht
            foreach (explode("\n", (string)$c['body']['message']) as $line) self::assertInert($line, "Nachricht $i");
        }
    }
}
