<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.1.0 (Review I18N-14) — Keine harten Meldungstexte im Backend.
 *
 * v2.2.1 meldete „restliche Backend-Texte katalogisiert"; der Wetterabgleich
 * schickte trotzdem „Antwort 200 OK, aber 0 verwendbare Tage" in jede Sprache,
 * Utilities::get() „Unbekannte Verbrauchsart: …" als 400 an den Client. Dieser
 * Test sucht Literale an den Stellen, an denen Text beim Client ankommt
 * (throw, Response::error, 'error' =>, $errors[] =), mit Umlaut oder einem
 * typischen Meldungswort. Meldungen gehören in den Katalog (->t()) oder in
 * eine LocalizedException.
 */
final class HardcodedTextTest extends TestCase
{
    /**
     * Datei → Begründung. Nur Texte, die den Client nicht roh erreichen.
     */
    private const ALLOW = [
        'Storage/JsonStore.php'               => 'steht vor dem I18nService (Zirkel); Text ins Log, der Client bekommt errors.storage.<kind>',
        'Services/BackupService.php'          => 'Snapshot-Fehler sind 500er: Text ins Log, der Client bekommt errors.http.internal',
        'Services/WeatherService.php'         => 'technischer Text fürs Log; der Client bekommt errors.weather.<error_code>',
    ];

    public function testNoHardcodedMessagesReachTheClient(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $lit = '(["\'])((?:\\\\.|(?!\1).)*)\1';
        $patterns = [
            '/throw\s+new\s+[\\\\\w]+\(\s*' . $lit . '/s',
            '/Response::error\(\s*' . $lit . '/s',
            '/[\'"]error[\'"]\s*=>\s*' . $lit . '/s',
            '/\$errors\[\]\s*=\s*' . $lit . '/s',
        ];
        $flag = '/[äöüÄÖÜß]|\b(nicht|fehlt|ungültig|unbekannt|fehlgeschlagen|Datei|Antwort|Zähler|failed|invalid|unknown|missing)\b/iu';
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $rel = substr($f->getPathname(), strlen($root) + 1);
            if (isset(self::ALLOW[$rel])) continue;
            $src = (string)file_get_contents($f->getPathname());
            foreach ($patterns as $re) {
                if (!preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) continue;
                foreach ($m[2] as [$text, $pos]) {
                    if (!preg_match($flag, $text)) continue;
                    $hits[] = "$rel:" . (substr_count(substr($src, 0, $pos), "\n") + 1) . " „{$text}“";
                }
            }
        }
        self::assertSame([], $hits, "Harte Meldungstexte (Katalog oder LocalizedException benutzen):\n" . implode("\n", $hits));
    }

    /** Die erlaubten Dateien gibt es noch — sonst ist die Liste veraltet. */
    public function testAllowlistIsCurrent(): void
    {
        foreach (array_keys(self::ALLOW) as $rel) {
            self::assertFileExists(dirname(__DIR__, 3) . "/src/$rel");
        }
    }
}
