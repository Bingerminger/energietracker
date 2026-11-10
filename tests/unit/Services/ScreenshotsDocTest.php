<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 — Zusagen von `docs/entwicklung/screenshots.md` (DE und EN, Markierung
 * `doc-claim: screenshots-de-en`): Jedes Bild der Doku gibt es auf Deutsch und Englisch, jedes steht
 * in der Tabelle, das Aufnahmeskript nimmt jedes auf, und keins ist größer als 150 KB (nach pngquant
 * sind die größten gut 120 KB; ein Bild ohne pngquant ist etwa doppelt so groß).
 * Dass jedes verlinkte Bild existiert, prüft DocsIntegrityTest.
 */
#[CoversNothing]
final class ScreenshotsDocTest extends TestCase
{
    private const MAX_BYTES = 150 * 1024;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> Dateinamen der deutschen Bilder */
    private static function images(): array
    {
        $files = array_map('basename', glob(self::root() . '/docs/ui/screenshots/*.png') ?: []);
        sort($files);
        return $files;
    }

    /** @return list<string> Dateinamen aus der Bildertabelle (erste Spalte, nur der Abschnitt „Die Bilder“) */
    private static function tableRows(string $rel): array
    {
        $doc = (string)file_get_contents(self::root() . '/' . $rel);
        $start = strpos($doc, '<!-- doc-claim: screenshots-de-en');
        self::assertNotFalse($start, "$rel: Markierung fehlt");
        $end = strpos($doc, "\n## ", $start);
        $section = substr($doc, $start, $end === false ? null : $end - $start);
        preg_match_all('/^\| `([a-z0-9-]+\.png)` \|/m', $section, $m);
        return $m[1];
    }

    public function testBothPagesCarryTheClaimMarker(): void
    {
        foreach (['docs/entwicklung/screenshots.md', 'docs/en/entwicklung/screenshots.md'] as $rel) {
            self::assertStringContainsString('<!-- doc-claim: screenshots-de-en (ScreenshotsDocTest) -->',
                (string)file_get_contents(self::root() . '/' . $rel), $rel);
        }
    }

    public function testEveryImageHasItsEnglishTwin(): void
    {
        $de = self::images();
        self::assertGreaterThan(40, count($de));
        $en = array_map('basename', glob(self::root() . '/docs/ui/screenshots/en/*.png') ?: []);
        sort($en);
        self::assertSame($de, $en, 'docs/ui/screenshots und docs/ui/screenshots/en enthalten dieselben Namen');
    }

    public function testEveryImageHasExactlyOneRowInBothTables(): void
    {
        $de = self::images();
        foreach (['docs/entwicklung/screenshots.md', 'docs/en/entwicklung/screenshots.md'] as $rel) {
            $rows = self::tableRows($rel);
            self::assertSame(count($rows), count(array_unique($rows)), "$rel: eine Zeile doppelt");
            $sorted = $rows;
            sort($sorted);
            self::assertSame($de, $sorted, "$rel: Tabelle und Bilder stimmen nicht überein");
        }
    }

    /** Die englische Doku zeigt die englischen Bilder, die deutsche die deutschen (bis v3.11 fehlten fünf auf Englisch). */
    public function testEachLanguageShowsItsOwnImages(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/docs', \FilesystemIterator::SKIP_DOTS));
        $wrong = [];
        $seen = 0;
        foreach ($it as $f) {
            if (!str_ends_with($f->getPathname(), '.md')) continue;
            $rel = substr($f->getPathname(), strlen(self::root()) + 1);
            $english = str_starts_with($rel, 'docs/en/');
            // nur eingebundene Bilder: ![…](…) und <img src="…">, keine Beispieladressen der API
            preg_match_all('#(?:\]\(|src=")(?:\.\./)+ui/screenshots/((?:en/)?[a-z0-9-]+\.png)#', (string)file_get_contents($f->getPathname()), $m);
            foreach ($m[1] as $img) {
                if ($rel === 'docs/entwicklung/screenshots.md' || $rel === 'docs/en/entwicklung/screenshots.md') continue;
                $seen++;
                if ($english !== str_starts_with($img, 'en/')) $wrong[] = "$rel → $img";
            }
        }
        self::assertGreaterThan(60, $seen);
        self::assertSame([], $wrong);
    }

    public function testTheCaptureScriptTakesEveryImage(): void
    {
        $script = (string)file_get_contents(self::root() . '/tools/build-screenshots.mjs');
        foreach (self::images() as $file) {
            $name = substr($file, 0, -4);
            self::assertStringContainsString("['$name',", $script, "tools/build-screenshots.mjs nimmt $file nicht auf");
        }
    }

    public function testNoImageIsLargerThan150Kb(): void
    {
        foreach (self::images() as $file) {
            foreach (["docs/ui/screenshots/$file", "docs/ui/screenshots/en/$file"] as $rel) {
                self::assertLessThanOrEqual(self::MAX_BYTES, filesize(self::root() . '/' . $rel), "$rel ist größer als 150 KB");
            }
        }
    }
}
