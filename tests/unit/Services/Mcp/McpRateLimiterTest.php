<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\Journal;
use Energietracker\Services\Mcp\RateLimiter;
use Energietracker\Services\Mcp\Server;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 (F1037, Paket protokoll) — Ratenbegrenzung (Konzept §2.6, Entscheidung 11a):
 * feste Töpfe je Schlüssel mit verankertem Fenster, Sperre einer Adresse nach falschen
 * Schlüsseln, kleine Zustandsdatei. Dazu der Doku-Test (Lektion 99): Grenzen, Sperre,
 * Aufbewahrung des Protokolls und ttlMs stehen in Worten in API-Referenz und
 * Sicherheitskapitel, DE und EN, gebunden an die Konstanten.
 */
#[CoversNothing]
final class McpRateLimiterTest extends TestCase
{
    private string $dir;
    private int $now = 1800000000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/et-mcp-rate-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->dir, fn(): int => $this->now);
    }

    public function testTheLimitsAreFixed(): void
    {
        self::assertSame(['all' => [120, 60], 'call' => [60, 60], 'propose' => [30, 3600], 'apply' => [30, 3600], 'inbox' => [30, 3600]], RateLimiter::LIMITS);
        self::assertSame([20, 600, 300, 500], [RateLimiter::KEY_FAILURES, RateLimiter::FAILURE_WINDOW, RateLimiter::LOCK_SECONDS, RateLimiter::MAX_ENTRIES]);
    }

    public function testToolsMapToTheirBuckets(): void
    {
        $map = ['list_meters' => 'call', 'get_overview' => 'call', 'list_documents' => 'call', 'cancel_proposal' => 'call',
                'propose_reading' => 'propose', 'propose_document' => 'propose', 'read_document' => 'propose', 'apply_proposal' => 'apply'];
        foreach ($map as $tool => $bucket) self::assertSame($bucket, RateLimiter::bucketForTool($tool), $tool);
    }

    public function testTheWindowStartsWithTheFirstRequestAndRetryAfterCountsDown(): void
    {
        $l = $this->limiter();
        for ($i = 0; $i < 30; $i++) {
            self::assertNull($l->hit('apply', 'k_a'), "Übernahme $i");
            $this->now += 60;   // über eine Stunde verteilt: das Fenster läuft ab der ersten
        }
        // 30 × 60 s = 1800 s nach der ersten: voll, noch 1800 s
        self::assertSame(1800, $l->hit('apply', 'k_a'));
        $this->now += 1799;
        self::assertSame(1, $l->hit('apply', 'k_a'), 'abgewiesene zählen nicht und verlängern nichts');
        $this->now += 1;
        self::assertNull($l->hit('apply', 'k_a'), 'neues Fenster');
        // kein Kalendertakt: 120 Anfragen über einen Minutenwechsel bleiben 120
        $this->now = 1800000050;
        for ($i = 0; $i < 120; $i++) self::assertNull($l->hit('all', 'k_b'));
        $this->now = 1800000065;   // Minutenwechsel bei …060
        self::assertSame(45, $l->hit('all', 'k_b'));
        self::assertException(fn() => $l->hit('gibt_es_nicht', 'k_b'), \LogicException::class);
    }

    public function testBucketsAreSeparatePerKeyAndPerBucket(): void
    {
        $l = $this->limiter();
        for ($i = 0; $i < 60; $i++) $l->hit('call', 'k_a');
        self::assertNotNull($l->hit('call', 'k_a'));
        self::assertNull($l->hit('call', 'k_b'), 'anderer Schlüssel');
        self::assertNull($l->hit('propose', 'k_a'), 'anderer Topf');
        self::assertNull($l->hit('all', 'k_a'));
    }

    public function testWrongKeysLockTheAddressForFiveMinutes(): void
    {
        $l = $this->limiter();
        for ($i = 1; $i < 20; $i++) self::assertNull($l->failure('192.168.178.23'), "Fehlversuch $i");
        self::assertNull($l->lockedFor('192.168.178.23'));
        self::assertSame(300, $l->failure('192.168.178.23'), 'der zwanzigste sperrt');
        self::assertSame(300, $l->lockedFor('192.168.178.23'));
        self::assertNull($l->lockedFor('192.168.178.24'), 'nur diese Adresse');
        $this->now += 299;
        self::assertSame(1, $l->lockedFor('192.168.178.23'));
        $this->now += 1;
        self::assertNull($l->lockedFor('192.168.178.23'));
        self::assertNull($l->failure('192.168.178.23'), 'nach der Sperre zählt es neu');

        // verteilt über mehr als 10 Minuten: keine Sperre
        for ($i = 0; $i < 19; $i++) $l->failure('10.0.0.5');
        $this->now += 600;
        for ($i = 0; $i < 19; $i++) self::assertNull($l->failure('10.0.0.5'));

        // ein gültiger Schlüssel tilgt die Fehlversuche
        for ($i = 0; $i < 19; $i++) $l->failure('10.0.0.6');
        $l->success('10.0.0.6');
        self::assertNull($l->failure('10.0.0.6'));
        self::assertNull($l->lockedFor(''), 'ohne Adresse');
    }

    public function testTheStateFileStaysSmallAndHoldsNoKeys(): void
    {
        $l = $this->limiter();
        for ($i = 0; $i < 600; $i++) $l->failure("10.1." . intdiv($i, 250) . '.' . ($i % 250));
        $state = json_decode((string)file_get_contents($this->dir . '/mcp/rate.json'), true);
        self::assertLessThanOrEqual(RateLimiter::MAX_ENTRIES, count($state));
        $this->now += 3600;
        $l->hit('all', 'k_a');
        $state = json_decode((string)file_get_contents($this->dir . '/mcp/rate.json'), true);
        self::assertSame(['key:k_a:all'], array_keys($state), 'Abgelaufenes fällt weg');
        self::assertFileDoesNotExist($this->dir . '/rate.json');
    }

    /** Doku-Zusage (Lektion 99): die Zahlen in Worten = Konstanten, DE und EN. */
    public function testTheDocsNameTheLimits(): void
    {
        $flat = fn(string $rel) => (string)preg_replace('/\s+/u', ' ', (string)file_get_contents(dirname(__DIR__, 4) . '/' . $rel));
        $unit = ['de' => [60 => 'Minute', 3600 => 'Stunde'], 'en' => [60 => 'minute', 3600 => 'hour']];
        $per = ['de' => 'je', 'en' => 'per'];
        $lock = ['de' => RateLimiter::KEY_FAILURES . ' unbekannten Schlüsseln innerhalb von ' . (RateLimiter::FAILURE_WINDOW / 60)
                    . ' Minuten ist die Adresse für ' . (RateLimiter::LOCK_SECONDS / 60) . ' Minuten gesperrt',
                 'en' => RateLimiter::KEY_FAILURES . ' unknown keys within ' . (RateLimiter::FAILURE_WINDOW / 60)
                    . ' minutes the address is locked for ' . (RateLimiter::LOCK_SECONDS / 60) . ' minutes'];
        $mb = Journal::MAX_BYTES / 1048576;
        $rows = ['de' => ['all' => 'jede Anfrage an `/api/mcp`', 'call' => '`tools/call` mit einem lesenden Werkzeug',
                          'propose' => '`propose_*` und `read_document`', 'apply' => '`apply_proposal`', 'inbox' => '`POST /api/inbox` mit einem KI-Schlüssel'],
                 'en' => ['all' => 'every request to `/api/mcp`', 'call' => '`tools/call` with a reading tool',
                          'propose' => '`propose_*` and `read_document`', 'apply' => '`apply_proposal`', 'inbox' => '`POST /api/inbox` with an AI key']];
        foreach (['de' => ['docs/referenz/api.md', 'docs/betrieb/sicherheit.md'], 'en' => ['docs/en/referenz/api.md', 'docs/en/betrieb/sicherheit.md']] as $lang => [$api, $sec]) {
            $a = $flat($api);
            foreach (RateLimiter::LIMITS as $bucket => [$n, $w]) {
                self::assertStringContainsString("| {$rows[$lang][$bucket]} | $n {$per[$lang]} {$unit[$lang][$w]} |", $a, "$api: Topf $bucket");
            }
            self::assertStringContainsString($lock[$lang], $a, "$api: Sperre");
            self::assertStringContainsString($lang === 'de' ? 'älter als ' . Journal::KEEP_DAYS . ' Tage' : 'older than ' . Journal::KEEP_DAYS . ' days', $a);
            self::assertStringContainsString($lang === 'de' ? "Ab $mb MB wird die Datei" : "from $mb MB the file", $a);
            self::assertStringContainsString('`?limit=` ' . ($lang === 'de' ? 'von 1 bis ' . Journal::LIST_MAX . ', Standard ' : 'from 1 to ' . Journal::LIST_MAX . ', default ')
                . Journal::LIST_DEFAULT, $a, "$api: limit");
            foreach (array_unique(Server::CACHE_TTL_MS) as $ttl) self::assertStringContainsString("`ttlMs: $ttl`", $a, "$api: ttlMs $ttl");
            self::assertStringContainsString('`cacheScope: "' . Server::CACHE_SCOPE . '"`', $a);

            $s = $flat($sec);
            $l = RateLimiter::LIMITS;
            self::assertStringContainsString($lang === 'de'
                ? "höchstens {$l['all'][0]} Anfragen je Minute, {$l['call'][0]} Aufrufe lesender Werkzeuge je Minute, je {$l['propose'][0]} Vorschläge und {$l['apply'][0]} Übernahmen je Stunde"
                : "at most {$l['all'][0]} requests per minute, {$l['call'][0]} calls of reading tools per minute, {$l['propose'][0]} proposals and {$l['apply'][0]} applications per hour each", $s, "$sec: Grenzen");
            self::assertSame([60, 60, 3600, 3600, 3600], array_column(array_values($l), 1), 'die Sätze nennen Minute/Minute/Stunde/Stunde');
            self::assertStringContainsString($lock[$lang], $s, "$sec: Sperre");
            self::assertStringContainsString($lang === 'de' ? 'älter als ' . Journal::KEEP_DAYS . " Tage fallen weg, die Datei wechselt ab $mb MB"
                : 'older than ' . Journal::KEEP_DAYS . " days are dropped, the file rotates from $mb MB", $s, "$sec: Aufbewahrung");
        }
    }

    private static function assertException(callable $fn, string $class): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);
            return;
        }
        self::fail("$class erwartet");
    }
}
