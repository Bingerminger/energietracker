<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.7.0 (Qualität) — Die Release-Checkliste (docs/entwicklung/release-prozess.md,
 * DE und EN) hält, was sie verspricht:
 *   • jede mitgelieferte Skriptdatei unter public/vendor/ steht im Punkt
 *     „Mitgelieferte Bibliotheken“ (Pfad der Datei oder ihres Ordners) — kommt
 *     eine Bibliothek dazu (pdf.js für den Posteingang), fällt sie nicht aus der
 *     Prüfung,
 *   • die WebKit-Sichtprüfung nennt ein Werkzeug, das es gibt, mit den
 *     Schaltern, die es kennt,
 *   • die Messung mit großen Datenmengen nennt Werkzeuge, die es gibt.
 */
final class ReleaseChecklistTest extends TestCase
{
    private const DOCS = ['docs/entwicklung/release-prozess.md', 'docs/en/entwicklung/release-prozess.md'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** Abschnitt 2 (Checkliste) eines Release-Dokuments. */
    private static function checklist(string $rel): string
    {
        $doc = (string)file_get_contents(self::root() . '/' . $rel);
        self::assertSame(1, preg_match('/^## 2\..*?(?=^## 3\.)/ms', $doc, $m), "$rel: Abschnitt 2 (Checkliste) fehlt");
        return $m[0];
    }

    /** @return list<string> Skriptdateien unter public/vendor/, relativ zum Repo */
    private static function vendorScripts(): array
    {
        $out = [];
        $base = self::root() . '/public/vendor';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && preg_match('/\.(m?js)$/', $f->getFilename())) {
                $out[] = 'public/vendor/' . str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
            }
        }
        sort($out);
        return $out;
    }

    public function testEveryVendoredScriptIsInTheChecklist(): void
    {
        $scripts = self::vendorScripts();
        self::assertNotEmpty($scripts, 'public/vendor/ ohne Skriptdateien? (Chart.js fehlt)');
        foreach (self::DOCS as $rel) {
            $list = self::checklist($rel);
            foreach ($scripts as $s) {
                $dir = dirname($s) . '/';
                self::assertTrue(str_contains($list, "`$s`") || ($dir !== 'public/vendor/' && str_contains($list, "`$dir`")),
                    "$rel: Release-Checkliste nennt $s nicht (Punkt „Mitgelieferte Bibliotheken“)");
            }
            self::assertStringContainsString('Chart.js', $list, "$rel: Chart.js fehlt in der Checkliste");
            self::assertStringContainsString('pdf.js', $list, "$rel: pdf.js fehlt in der Checkliste");
        }
    }

    public function testWebkitCheckIsDocumentedWithItsOptions(): void
    {
        $tool = self::root() . '/tools/webkit-check.mjs';
        self::assertFileExists($tool);
        $src = (string)file_get_contents($tool);
        foreach (self::DOCS as $rel) {
            $list = self::checklist($rel);
            self::assertStringContainsString('tools/webkit-check.mjs', $list, "$rel: WebKit-Sichtprüfung ohne Werkzeug");
            self::assertStringContainsString('WebKit', $list);
            preg_match_all('/--([a-z]+)(?==|\]|\s)/', $list, $m);
            foreach (array_unique($m[1]) as $flag) {
                if (in_array($flag, ['ff', 'tags'], true)) continue;   // git-Schalter anderer Punkte
                self::assertMatchesRegularExpression("/opt\\('$flag'|--$flag/", $src, "$rel nennt --$flag, das Werkzeug kennt es nicht");
            }
            // die Breiten, die die Doku nennt, sind die des Werkzeugs
            self::assertStringContainsString("'820,1180'", $src);
            self::assertTrue(str_contains($list, '820') && str_contains($list, '1180'), "$rel: Breiten der Sichtprüfung fehlen");
        }
    }

    public function testLargeDataMeasurementNamesExistingToolsAndOptions(): void
    {
        $tools = ['tools/large-household.mjs', 'tools/measure-large-household.mjs', 'tests/large-data.test.mjs'];
        $src = '';
        foreach ($tools as $t) {
            self::assertFileExists(self::root() . '/' . $t);
            $src .= (string)file_get_contents(self::root() . '/' . $t);
        }
        foreach (['docs/entwicklung/messung-grosse-daten.md', 'docs/en/entwicklung/messung-grosse-daten.md'] as $rel) {
            $doc = (string)file_get_contents(self::root() . '/' . $rel);
            foreach ($tools as $t) self::assertStringContainsString("`$t`", $doc, "$rel nennt $t nicht");
            preg_match_all('/node tools\/[a-z-]+\.mjs[^`]*/', $doc, $cmds);
            preg_match_all('/--([a-z-]+)/', implode(' ', $cmds[0]), $m);
            self::assertNotEmpty($m[1], "$rel: keine Aufrufe der Werkzeuge");
            foreach (array_unique($m[1]) as $flag) {
                self::assertTrue(str_contains($src, "'$flag'") || str_contains($src, "'--$flag'"), "$rel nennt --$flag, die Werkzeuge kennen es nicht");
            }
        }
    }
}
