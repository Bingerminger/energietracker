<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v2.2.0 — Strukturprüfung der Sprachkataloge.
 *
 * Anlass: Beim Einpflegen der Tarifvergleich-Schlüssel überschrieb ein
 * Migrationsschritt versehentlich `reminders.col` statt `tariff.col` — die
 * Termine-Tabelle trug danach in allen sieben Sprachen Tarif-Spaltenköpfe. Ein
 * zweiter Schlüssel (`tariff.legend`) wurde doppelt eingefügt; beim Parsen
 * gewinnt der spätere, der alte Text blieb sichtbar. Beides fiel erst bei der
 * Sichtprüfung im Browser auf.
 *
 * Diese Prüfungen kosten Millisekunden und hätten beides sofort gemeldet.
 */
final class LocaleCatalogTest extends TestCase
{
    private const REFERENCE = 'de';

    private static function localeDir(): string
    {
        return dirname(__DIR__, 3) . '/public/locales';
    }

    /** @return string[] */
    private static function languages(): array
    {
        $out = [];
        foreach (glob(self::localeDir() . '/*.json') ?: [] as $f) {
            $name = basename($f, '.json');
            if ($name !== 'languages.json' && $name !== 'languages') $out[] = $name;
        }
        sort($out);
        return $out;
    }

    /** @return array<string,mixed> */
    private static function catalog(string $lang): array
    {
        $data = json_decode((string)file_get_contents(self::localeDir() . "/$lang.json"), true);
        self::assertIsArray($data, "$lang.json ist kein gültiges JSON");
        return $data;
    }

    /** @return array<string,string> flache Punkt-Notation */
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

    public function testEveryCatalogIsValidJson(): void
    {
        foreach (self::languages() as $lang) {
            self::assertIsArray(self::catalog($lang));
        }
        self::assertContains(self::REFERENCE, self::languages());
    }

    /** Alle Sprachen tragen exakt denselben Schlüsselsatz. */
    public function testAllLanguagesShareTheSameKeys(): void
    {
        $ref = array_keys(self::flatten(self::catalog(self::REFERENCE)));
        sort($ref);
        foreach (self::languages() as $lang) {
            if ($lang === self::REFERENCE) continue;
            $keys = array_keys(self::flatten(self::catalog($lang)));
            sort($keys);
            self::assertSame([], array_values(array_diff($ref, $keys)),
                "$lang.json fehlen Schlüssel");
            self::assertSame([], array_values(array_diff($keys, $ref)),
                "$lang.json hat Schlüssel, die im Referenzkatalog fehlen");
        }
    }

    /**
     * Kein Schlüssel darf innerhalb desselben Objekts zweimal vorkommen. JSON
     * erlaubt das formal, der spätere gewinnt — und der frühere verschwindet
     * lautlos, samt der Änderung, die man gerade eingepflegt hat.
     */
    public function testNoDuplicateKeysWithinAnObject(): void
    {
        foreach (self::languages() as $lang) {
            $raw = (string)file_get_contents(self::localeDir() . "/$lang.json");
            $lines = explode("\n", $raw);

            $stack = [[]];          // je Verschachtelungstiefe die gesehenen Schlüssel
            $duplicates = [];
            foreach ($lines as $n => $line) {
                if (preg_match('/^\s*"([^"]+)"\s*:/', $line, $m)) {
                    $depth = count($stack) - 1;
                    if (isset($stack[$depth][$m[1]])) {
                        $duplicates[] = "$lang.json:" . ($n + 1) . " → \"{$m[1]}\""
                            . " (erstmals in Zeile {$stack[$depth][$m[1]]})";
                    } else {
                        $stack[$depth][$m[1]] = $n + 1;
                    }
                }
                // Verschachtelung nachführen: Objekte, die in derselben Zeile
                // geöffnet und geschlossen werden, zählen nicht.
                $delta = substr_count($line, '{') - substr_count($line, '}');
                for ($i = 0; $i < $delta; $i++)  $stack[] = [];
                for ($i = 0; $i < -$delta; $i++) array_pop($stack);
                if ($stack === []) $stack = [[]];
            }
            self::assertSame([], $duplicates,
                "Doppelte Schlüssel:\n" . implode("\n", $duplicates));
        }
    }

    /**
     * Jeder Schlüssel, den der Code fest verdrahtet an `t()` übergibt, muss im
     * Katalog stehen. Sonst zeigt die Oberfläche den rohen Schlüssel — genau
     * das passierte den Spaltenköpfen des Tarifvergleichs.
     *
     * Dynamisch zusammengesetzte Schlüssel (`t('x.' + wert)`) lassen sich
     * statisch nicht auflösen und bleiben außen vor.
     */
    public function testEveryLiteralKeyUsedInTheCodeExists(): void
    {
        $catalog = self::flatten(self::catalog(self::REFERENCE));
        $root = dirname(__DIR__, 3);

        $files = [];
        foreach (['public/js', 'src'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir"));
            foreach ($it as $f) {
                if ($f->isFile() && in_array($f->getExtension(), ['js', 'php'], true)) {
                    $files[] = $f->getPathname();
                }
            }
        }

        $missing = [];
        foreach ($files as $path) {
            $rel = substr($path, strlen($root) + 1);
            foreach (file($path) ?: [] as $n => $line) {
                // Kommentarzeilen tragen Beispiel-Schlüssel, keine Aufrufe.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                // t('key'…) bzw. ->t('key'…) mit literalem Schlüssel. Der
                // Schlüssel muss unmittelbar von einem Komma oder der
                // schließenden Klammer gefolgt sein — dann ist er vollständig
                // und nicht der Kopf einer Verkettung wie t('x.' + wert).
                $pattern = '/(?<![\w$])(?:->)?t\(\s*[\'"]([a-zA-Z][a-zA-Z0-9_.]*)[\'"]\s*(?:[,)])/';
                if (!preg_match_all($pattern, $line, $m1)) {
                    continue;
                }
                foreach ($m1[1] as $key) {
                    // Ohne Punkt ist es kein Katalogpfad (lokale Hilfsfunktion).
                    if (!str_contains($key, '.')) continue;
                    if (!isset($catalog[$key])) {
                        $missing[] = "$rel:" . ($n + 1) . " → $key";
                    }
                }
            }
        }
        self::assertSame([], $missing,
            "Im Code verwendete Schlüssel fehlen im Katalog:\n" . implode("\n", $missing));
    }

    /** Platzhalter müssen in jeder Sprache dieselben sein. */
    public function testPlaceholdersMatchAcrossLanguages(): void
    {
        $ref = self::flatten(self::catalog(self::REFERENCE));
        $mismatches = [];
        foreach (self::languages() as $lang) {
            if ($lang === self::REFERENCE) continue;
            foreach (self::flatten(self::catalog($lang)) as $key => $value) {
                if (!isset($ref[$key])) continue;
                preg_match_all('/\{(\w+)\}/', $ref[$key], $a);
                preg_match_all('/\{(\w+)\}/', $value, $b);
                $x = array_unique($a[1]); sort($x);
                $y = array_unique($b[1]); sort($y);
                if ($x !== $y) {
                    $mismatches[] = "$lang → $key: de=[" . implode(',', $x)
                        . "] vs [" . implode(',', $y) . "]";
                }
            }
        }
        self::assertSame([], $mismatches, implode("\n", $mismatches));
    }
}
