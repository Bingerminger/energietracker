<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.1.0 (Review I18N-23) — Jeder Katalogschlüssel wird benutzt.
 *
 * Bis v3.0 lagen 23 tote Schlüssel in allen sieben Katalogen — 161 Werte, die
 * jede neue Sprache mit übersetzen musste. Genutzt ist ein Schlüssel, wenn er
 * wörtlich im Code steht oder unter einem Präfix liegt, das der Code
 * zusammensetzt: `t(\`utilityNames.${key}\`)`, `"errors.weather.$code"`,
 * `'csv.' . $name`, `tp('x.days', n)` (→ x.days.one/.other) und
 * `optionLabels: 'a.b'` (→ a.b.<Option>). Was davon nicht erfasst ist, steht
 * in EXTRA mit Begründung.
 */
final class CatalogUsageTest extends TestCase
{
    /** Präfixe, die der Code auf Wegen zusammensetzt, die die Muster nicht sehen. */
    private const EXTRA = [
        'format.' => 'Formatangaben, gelesen über lookup()/t() mit Schlüssel aus Variablen und von Countries',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> */
    private static function flatten(array $node, string $prefix = ''): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            $key = $prefix === '' ? (string)$k : "$prefix.$k";
            if (is_array($v)) $out += self::flatten($v, $key);
            else $out[$key] = (string)$v;
        }
        return $out;
    }

    private static function sources(): string
    {
        $src = (string)file_get_contents(self::root() . '/index.php');
        foreach (['/public/js', '/src'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (str_contains($f->getPathname(), '/vendor/')) continue;
                if (in_array($f->getExtension(), ['js', 'mjs', 'php'], true)) $src .= "\n" . file_get_contents($f->getPathname());
            }
        }
        return $src;
    }

    /** @return list<string> */
    private static function dynamicPrefixes(string $src): array
    {
        $id = '[a-zA-Z]\w*(?:\.\w+)*';
        $patterns = [
            "/['\"`]($id\.)['\"`]/",                    // 'csvLocal.monthly.' als Präfix
            "/[\"`]($id\.?)(?:\\$\\{|\\{\\$|\\$[a-zA-Z_])/", // `a.b.${x}`, "a.b.{$x}", "a.b.$x", `a.stage${n}`
            "/['\"]($id\.?)['\"]\s*[.+]\s*[\$a-zA-Z(]/",  // 'a.b.' . $x  /  'a.b.' + x
            "/tp\(\s*['\"]($id)['\"]/",                  // tp('a.b', n) → a.b.one/.other
            "/optionLabels:\s*['\"]($id)['\"]/",          // optionLabels: 'a.b' → a.b.<opt>
        ];
        $out = array_keys(self::EXTRA);
        foreach ($patterns as $i => $re) {
            preg_match_all($re, $src, $m);
            foreach ($m[1] as $p) $out[] = $i >= 3 ? "$p." : $p;
        }
        return array_values(array_unique($out));
    }

    public function testEveryKeyIsUsed(): void
    {
        $keys = array_keys(self::flatten((array)json_decode((string)file_get_contents(self::root() . '/public/locales/de.json'), true)));
        $src = self::sources();
        $prefixes = self::dynamicPrefixes($src);
        $dead = [];
        foreach ($keys as $key) {
            if (preg_match('/[\'"`]' . preg_quote($key, '/') . '[\'"`]/', $src)) continue;
            foreach ($prefixes as $p) if (str_starts_with($key, $p)) continue 2;
            $dead[] = $key;
        }
        self::assertSame([], $dead, "Ungenutzte Katalogschlüssel (löschen oder nutzen):\n" . implode("\n", $dead));
    }

    /** Die Erkennung selbst: ein erfundener Schlüssel fällt auf. */
    public function testDetectionFindsAnUnusedKey(): void
    {
        $src = self::sources();
        $prefixes = self::dynamicPrefixes($src);
        $key = 'settings.zzzNeverUsedKey';
        $used = preg_match('/[\'"`]' . preg_quote($key, '/') . '[\'"`]/', $src)
            || array_filter($prefixes, fn($p) => str_starts_with($key, $p));
        self::assertFalse((bool)$used, 'Ein Präfix ist zu breit — „settings." darf nicht als dynamisch gelten');
    }
}
