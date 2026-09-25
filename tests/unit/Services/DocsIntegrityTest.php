<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * v2.14.0 (Review DOC-11) — die Doku hält, was sie verlinkt.
 *
 * Mit dem Umbau nach Zielgruppen zogen 61 Dateien um. Die alten Pfade leiten
 * weiter; alles andere prüft dieser Test: Links und Bilder zeigen auf
 * vorhandene Dateien — mit exakter Schreibweise, denn GitHub unterscheidet
 * Groß und klein, macOS nicht —, Anker gibt es im Ziel, jede Seite hat ihren
 * englischen Spiegel, niemand verlinkt eine Weiterleitung, und der Index
 * führt jede Seite. Bis v2.13 prüfte kein Test die Doku unterhalb von README
 * und INSTALL; „Alle 68 Endpunkte" stand dort, als es 86 waren.
 */
final class DocsIntegrityTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return string[] repo-relative Markdown-Dateien, die zur Doku gehören */
    private static function markdownFiles(): array
    {
        $root = self::root();
        $files = [];
        foreach (['README.md', 'README.de.md', 'INSTALL.md', 'INSTALL.de.md', 'CHANGELOG.md', 'SECURITY.md',
                  'CONTRIBUTING.md', 'CONTRIBUTING.de.md', 'roadmap.md', 'demo-data/README.md', 'tests/README.md'] as $f) {
            if (is_file("$root/$f")) $files[] = $f;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/docs", \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'md') $files[] = substr($f->getPathname(), strlen($root) + 1);
        }
        sort($files);
        return $files;
    }

    private static function isStub(string $rel): bool
    {
        $head = (string)file_get_contents(self::root() . '/' . $rel, false, null, 0, 40);
        return str_starts_with($head, '<!-- umgezogen:') || str_starts_with($head, '<!-- moved:');
    }

    /** Text ohne Code-Blöcke und Inline-Code (dort stehen Beispiele, keine Links). */
    private static function prose(string $md): string
    {
        $md = (string)preg_replace('/^```.*?^```/ms', '', $md);
        return (string)preg_replace('/`[^`\n]*`/', '', $md);
    }

    /** @return string[] Linkziele (relativ oder Anker) einer Datei */
    private static function links(string $md): array
    {
        $text = self::prose($md);
        preg_match_all('/\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $text, $a);
        preg_match_all('/<(?:img|a)\b[^>]*?\s(?:src|href)="([^"]+)"/', $text, $b);
        preg_match_all('/^\[[^\]]+\]:\s*(\S+)/m', $text, $c);
        return array_values(array_filter(array_merge($a[1], $b[1], $c[1]),
            fn($t) => !preg_match('#^(?:[a-z][a-z0-9+.-]*:|/)#i', $t)));
    }

    /** Gibt es den Pfad genau so (Groß/klein) im Dateisystem? */
    private static function existsExact(string $rel): bool
    {
        $cur = self::root();
        foreach (array_filter(explode('/', rtrim($rel, '/')), 'strlen') as $seg) {
            if ($seg === '.') continue;
            if ($seg === '..') { $cur = dirname($cur); continue; }
            if (!is_dir($cur) || !in_array($seg, scandir($cur) ?: [], true)) return false;
            $cur .= '/' . $seg;
        }
        return file_exists($cur);
    }

    private static function normalize(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($out); continue; }
            $out[] = $seg;
        }
        return implode('/', $out);
    }

    /** @return array<string,true> Anker einer Markdown-Datei nach GitHubs Regel */
    private static function anchors(string $rel): array
    {
        static $cache = [];
        if (isset($cache[$rel])) return $cache[$rel];
        $md = (string)preg_replace('/^```.*?^```/ms', '', (string)file_get_contents(self::root() . '/' . $rel));
        $seen = [];
        $out = [];
        foreach (preg_split('/\R/', $md) ?: [] as $line) {
            if (!preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m)) continue;
            $t = (string)preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $m[1]);   // Links → Text
            $t = (string)preg_replace('/<[^>]+>/', '', $t);                      // HTML
            $t = str_replace(['`', '*'], '', $t);
            $t = mb_strtolower($t);
            $t = (string)preg_replace('/[^\p{L}\p{N}\p{M} _-]/u', '', $t);
            $slug = str_replace(' ', '-', $t);
            $n = $seen[$slug] ?? 0;
            $seen[$slug] = $n + 1;
            $out[$n === 0 ? $slug : "$slug-$n"] = true;
        }
        foreach (['top' => true] as $k => $v) $out[$k] = $v;
        // explizite Anker <a id="…"> bzw. name
        if (preg_match_all('/<a\s+(?:id|name)="([^"]+)"/', $md, $ids)) foreach ($ids[1] as $id) $out[$id] = true;
        return $cache[$rel] = $out;
    }

    public function testEveryRelativeLinkAndAnchorResolves(): void
    {
        $broken = [];
        foreach (self::markdownFiles() as $src) {
            $md = (string)file_get_contents(self::root() . '/' . $src);
            foreach (self::links($md) as $target) {
                [$file, $anchor] = array_pad(explode('#', $target, 2), 2, null);
                $file = rawurldecode((string)$file);
                $abs = $file === '' ? $src : self::normalize(dirname($src) . '/' . $file);
                if ($file !== '' && !self::existsExact($abs)) {
                    $broken[] = "$src → $target (Datei fehlt oder Schreibweise weicht ab)";
                    continue;
                }
                if ($anchor !== null && $anchor !== '' && str_ends_with($abs, '.md')
                    && !isset(self::anchors($abs)[rawurldecode($anchor)])) {
                    $broken[] = "$src → $target (Anker fehlt)";
                }
            }
        }
        self::assertSame([], $broken, "Kaputte Doku-Links:\n" . implode("\n", $broken));
    }

    public function testEveryPageHasItsMirror(): void
    {
        $de = $en = [];
        foreach (self::markdownFiles() as $f) {
            if (self::isStub($f)) continue;   // Weiterleitungen tragen die alten Namen je Sprache
            if (str_starts_with($f, 'docs/en/')) $en[] = substr($f, strlen('docs/en/'));
            elseif (str_starts_with($f, 'docs/')) $de[] = substr($f, strlen('docs/'));
        }
        self::assertSame([], array_values(array_diff($de, $en)), 'Deutsche Seite ohne englischen Spiegel');
        self::assertSame([], array_values(array_diff($en, $de)), 'Englische Seite ohne deutsches Original');
    }

    public function testNoPageLinksToARedirect(): void
    {
        $hits = [];
        foreach (self::markdownFiles() as $src) {
            if ($src === 'CHANGELOG.md' || self::isStub($src)) continue;
            foreach (self::links((string)file_get_contents(self::root() . '/' . $src)) as $target) {
                $file = explode('#', $target, 2)[0];
                if ($file === '' || !str_ends_with($file, '.md')) continue;
                $abs = self::normalize(dirname($src) . '/' . rawurldecode($file));
                if (self::existsExact($abs) && self::isStub($abs)) $hits[] = "$src → $target";
            }
        }
        self::assertSame([], $hits, "Links auf Weiterleitungen statt aufs Ziel:\n" . implode("\n", $hits));
    }

    public function testTheIndexListsEveryPage(): void
    {
        foreach (['docs' => 'docs/README.md', 'docs/en' => 'docs/en/README.md'] as $dir => $index) {
            $linked = [];
            foreach (self::links((string)file_get_contents(self::root() . "/$index")) as $t) {
                $linked[self::normalize($dir . '/' . explode('#', $t, 2)[0])] = true;
            }
            $missing = [];
            foreach (self::markdownFiles() as $f) {
                if (!str_starts_with($f, "$dir/") || $f === $index || self::isStub($f)) continue;
                if ($dir === 'docs' && str_starts_with($f, 'docs/en/')) continue;
                if (!isset($linked[$f])) $missing[] = $f;
            }
            self::assertSame([], $missing, "$index verlinkt diese Seiten nicht");
        }
    }

    /** Die Einstellungsreferenz nennt jeden Schlüssel, den es gibt. */
    public function testSettingsReferenceCoversEveryKey(): void
    {
        foreach (['docs/referenz/einstellungen.md', 'docs/en/referenz/einstellungen.md'] as $rel) {
            $doc = (string)file_get_contents(self::root() . '/' . $rel);
            $defaults = (new \ReflectionClassConstant(SettingsService::class, 'DEFAULTS'))->getValue();
            $missing = array_values(array_filter(array_keys($defaults),
                fn($k) => !str_contains($doc, "`$k`")));
            self::assertSame([], $missing, "$rel: Schlüssel fehlen");
        }
    }

    /** Die App verlinkt nur Seiten, die es gibt, und keine Weiterleitung. */
    public function testInAppDocLinksPointToRealPages(): void
    {
        $js = (string)file_get_contents(self::root() . '/public/js/lib/docs.js');
        preg_match_all("#'(docs/[^']+\\.md)'#", $js, $m);
        self::assertGreaterThanOrEqual(8, count($m[1]));
        foreach ($m[1] as $rel) {
            self::assertTrue(self::existsExact($rel), "$rel fehlt");
            self::assertFalse(self::isStub($rel), "$rel ist nur eine Weiterleitung");
        }
    }
}
