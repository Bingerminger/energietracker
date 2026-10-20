<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\UpdateCheckService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.6.0 — Hinweis auf neue Versionen: ohne Einstellung keine Anfrage nach
 * außen; eingeschaltet höchstens eine Anfrage am Tag, auch bei Fehlern.
 */
#[CoversClass(UpdateCheckService::class)]
final class UpdateCheckServiceTest extends ServiceTestCase
{
    private int $calls = 0;
    private int $now = 1_800_000_000;

    private function service(string|\Throwable $answer, string $current = '3.5.1'): UpdateCheckService
    {
        $fetch = function (string $url) use ($answer): string {
            $this->calls++;
            self::assertSame(UpdateCheckService::URL, $url);
            if ($answer instanceof \Throwable) throw $answer;
            return $answer;
        };
        return new UpdateCheckService($this->store, $this->settings, $current, \Closure::fromCallable($fetch), fn(): int => $this->now);
    }

    private static function release(string $tag, string $url = 'https://github.com/Bingerminger/energietracker/releases/tag/'): string
    {
        return json_encode(['tag_name' => $tag, 'html_url' => $url . $tag]);
    }

    public function testOffByDefaultAndNothingGoesOut(): void
    {
        $r = $this->service(self::release('v9.9.9'))->check();
        self::assertSame(['enabled' => false, 'current' => '3.5.1'], $r);
        self::assertSame(0, $this->calls, 'ohne Einstellung keine Anfrage');
    }

    public function testNewerVersionOnceADay(): void
    {
        $this->settings->set(['update_check' => true]);
        $s = $this->service(self::release('v3.6.0'));
        $r = $s->check();
        self::assertTrue($r['enabled']);
        self::assertSame('3.6.0', $r['latest']);
        self::assertTrue($r['newer']);
        self::assertStringEndsWith('/releases/tag/v3.6.0', (string)$r['url']);
        $s->check();
        $this->now += UpdateCheckService::TTL_S - 1;
        $s->check();
        self::assertSame(1, $this->calls, 'innerhalb eines Tages aus dem Zwischenstand');
        $this->now += 2;
        $s->check();
        self::assertSame(2, $this->calls, 'nach einem Tag erneut');
    }

    public function testSameOrOlderIsNotNewer(): void
    {
        $this->settings->set(['update_check' => true]);
        self::assertFalse($this->service(self::release('v3.5.1'))->check()['newer']);
        $this->store->write(UpdateCheckService::FILE, []);
        self::assertFalse($this->service(self::release('v3.4.0'))->check()['newer']);
    }

    public function testFailureIsSilentAndAlsoWaitsADay(): void
    {
        $this->settings->set(['update_check' => true]);
        $s = $this->service(new \RuntimeException('offline'));
        $r = $s->check();
        self::assertNull($r['latest']);
        self::assertFalse($r['newer']);
        $s->check();
        self::assertSame(1, $this->calls, 'ein Fehlschlag fragt nicht bei jeder Anfrage erneut');
    }

    /** Randfälle: Vorabversion, kaputte Antwort, nachträglich ausgeschaltet. */
    public function testPrereleaseTagsAndBrokenAnswersAreIgnored(): void
    {
        $this->settings->set(['update_check' => true]);
        self::assertNull($this->service(self::release('v3.7.0-rc1'))->check()['latest'], 'Vorabversion zählt nicht');
        $this->store->write(UpdateCheckService::FILE, []);
        $r = $this->service('<html>rate limited</html>')->check();
        self::assertNull($r['latest']);
        self::assertFalse($r['newer']);
    }

    public function testSwitchedOffLaterAsksNothingAndShowsNothing(): void
    {
        $this->settings->set(['update_check' => true]);
        $this->service(self::release('v9.0.0'))->check();
        $this->settings->set(['update_check' => false]);
        $this->now += 2 * UpdateCheckService::TTL_S;
        $r = $this->service(self::release('v9.0.0'))->check();
        self::assertSame(['enabled' => false, 'current' => '3.5.1'], $r, 'ausgeschaltet: auch kein alter Zwischenstand');
        self::assertSame(1, $this->calls);
    }

    public function testOnlyVersionTagsAndOwnReleaseLinks(): void
    {
        $this->settings->set(['update_check' => true]);
        $r = $this->service(self::release('nightly'))->check();
        self::assertNull($r['latest'], 'kein Versions-Tag');
        $this->store->write(UpdateCheckService::FILE, []);
        $r = $this->service(self::release('v4.0.0', 'https://example.org/'))->check();
        self::assertSame('4.0.0', $r['latest']);
        self::assertNull($r['url'], 'fremder Link wird nicht weitergegeben');
    }
}
