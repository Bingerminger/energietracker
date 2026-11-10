<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 — Browser-Render und Sichten prüfen gegen den festen Stand von `demo-data/`. Mit der
 * echten Uhr würden ihre Stichtage (fällige Ablesungen, Vorjahr, Viertelstunden) ab Ende Dezember
 * 2026 rot, ohne dass sich Code ändert. Die CI lässt Server und Suiten dieser beiden Schritte
 * deshalb an einem festen Tag laufen (libfaketime). Dieser Test hält fest, dass jeder Prozess der
 * Schritte unter derselben Uhr läuft und der Tag zum Stand der Beispieldaten passt.
 */
final class RenderClockTest extends TestCase
{
    private static function file(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        self::assertFileExists($path);
        return (string)file_get_contents($path);
    }

    /** Rumpf eines Schritts der CI (bis zum nächsten `- name:` derselben Ebene). */
    private static function step(string $ci, string $name): string
    {
        $at = strpos($ci, "- name: $name");
        self::assertNotFalse($at, "Schritt „{$name}“ fehlt");
        $next = strpos($ci, "\n      - name: ", $at + 1);
        return substr($ci, $at, $next === false ? null : $next - $at);
    }

    public function testRenderStepsRunServerAndSuitesAtTheFixedDay(): void
    {
        $ci = self::file('.github/workflows/ci.yml');
        $clock = self::step($ci, 'Fester Tag für Browser-Render und Sichten (libfaketime)');
        self::assertStringContainsString('apt-get install -y -qq faketime', $clock);
        self::assertStringContainsString('ET_FAKETIME=', $clock);
        self::assertStringContainsString('>> "$GITHUB_ENV"', $clock);

        $render = self::step($ci, 'Tests (Server + Frontend-API-Shape + Browser-Render)');
        self::assertStringContainsString('FT="faketime -f $ET_FAKETIME"', $render);
        self::assertStringContainsString('$FT php -S 127.0.0.1:8899 router.php', $render, 'Server am festen Tag');
        self::assertStringContainsString('$FT node tests/frontend-api-shape.test.js', $render);
        self::assertMatchesRegularExpression('/\$FT node \\\\\s*\n\s*--import=[^\n]*\n\s*tests\/browser-render\.test\.mjs/', $render, 'Browser-Render am festen Tag');
        self::assertDoesNotMatchRegularExpression('/^\s*(php -S|node tests\/)/m', $render, 'kein Prozess mit der echten Uhr');

        $sichten = self::step($ci, 'Übersicht, Sichten und Verträge (im Browser)');
        self::assertStringContainsString('faketime -f "$ET_FAKETIME" node tests/sichten.test.mjs', $sichten);
    }

    public function testFixedDayFitsTheExportOfTheSampleData(): void
    {
        $ci = self::file('.github/workflows/ci.yml');
        self::assertSame(1, preg_match('/ET_RENDER_TODAY: "(\d{4}-\d{2}-\d{2})"/', $ci, $m), 'ET_RENDER_TODAY fehlt');
        $day = new \DateTimeImmutable($m[1]);
        $backup = json_decode(self::file('demo-data/energietracker-demo-backup.json'), true);
        $export = new \DateTimeImmutable(substr((string)($backup['exported_at'] ?? ''), 0, 10));
        // nach dem Export, und nicht so weit danach, dass das Vorjahr des festen Tages vor dem
        // ersten vollen Jahr der Beispieldaten läge: höchstens ein Jahr
        self::assertGreaterThan($export, $day, 'fester Tag vor dem Export der Beispieldaten');
        self::assertLessThanOrEqual(366, (int)$export->diff($day)->days, 'fester Tag mehr als ein Jahr nach dem Export');
    }
}
