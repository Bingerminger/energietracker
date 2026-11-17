<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.13.0 (Paket K1, aus Paket D) — `docs/referenz/ansichten.md` §11 „Einstellungen“ nennt die Seiten mit den Namen
 * der App (Katalog: `settings.page.*.title`, `household.*.tab`, `connections.*.title`; Reihenfolge in
 * public/js/lib/nav-model.js). Bis v3.12 standen dort noch die Seiten von v2.12 („Seite Allgemein“, „Zugriff“,
 * „Integrationen“, „Haushalt & Gebäude“), die es seit v3.12.0 so nicht mehr gibt.
 */
final class ViewsPageNamesDocsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** §11 aus der Datei, Leerraum zu einem Leerzeichen. */
    private static function section(string $rel, string $head): string
    {
        $text = (string)file_get_contents(self::root() . '/' . $rel);
        $start = strpos($text, $head);
        self::assertNotFalse($start, "$rel: Abschnitt $head");
        $end = strpos($text, "\n## ", $start + strlen($head));
        return (string)preg_replace('/\s+/u', ' ', substr($text, $start, $end === false ? null : $end - $start));
    }

    /** @return array{settings: list<string>, household: list<string>, connections: list<string>} Seitennamen der Sprache */
    private static function pages(string $lang): array
    {
        $cat = json_decode((string)file_get_contents(self::root() . "/public/locales/$lang.json"), true, 512, JSON_THROW_ON_ERROR);
        $pick = fn(array $tree, string $k) => array_values(array_map(fn($p) => (string)$p[$k], array_filter($tree, fn($p) => is_array($p) && is_string($p[$k] ?? null))));
        // Wetterdaten steht unter Verbindungen mit dem Titel aus der Navigation (lib/settings-index.js PLACES.weather)
        return ['settings' => $pick((array)$cat['settings']['page'], 'title'), 'household' => $pick((array)$cat['household'], 'tab'),
                'connections' => [...$pick((array)$cat['connections'], 'title'), (string)$cat['nav']['weather']]];
    }

    public function testSectionElevenNamesThePagesOfTheApp(): void
    {
        $cases = [
            ['de', 'docs/referenz/ansichten.md', '## 11. Einstellungen', '/\(v[\d.]+, Seite ([^,):]+)[,):]/u', '/(?:^|[ (])Die Seite ([^ ]+(?: und [^ ]+)?) zeigt/u',
             ['Haushalt' => 'household', 'Verbindungen' => 'connections'],
             ['Seite Allgemein', 'Seite Zugriff', 'Seite Integrationen', 'Haushalt & Gebäude', 'Verbrauchsarten & Abrechnung', 'Einstellungen → Allgemein', 'wie unter Zugriff)']],
            ['en', 'docs/en/referenz/ansichten.md', '## 11. Settings', '/\(v[\d.]+, ([^,)]+?) page[,)]/u', '/the ([A-Z][a-z]+(?: and [a-z]+)?) page shows/u',
             ['Household' => 'household', 'Connections' => 'connections'],
             ['General page', 'Access page', 'Integrations page', 'Household & building page', 'Utilities & billing page', 'Settings → General', 'as under Access)']],
        ];
        foreach ($cases as [$lang, $rel, $head, $labelled, $inline, $areas, $retired]) {
            $s = self::section($rel, $head);
            $names = self::pages($lang);
            foreach ($retired as $old) self::assertStringNotContainsString($old, $s, "$rel §11: alter Name „{$old}“");
            preg_match_all($labelled, $s, $m1);
            preg_match_all($inline, $s, $m2);
            $pages = array_values(array_unique(array_map('trim', array_merge($m1[1], $m2[1]))));
            self::assertGreaterThanOrEqual(2, count($pages), "$rel §11: Seitennamen gefunden: " . implode(', ', $pages));
            foreach ($pages as $p) self::assertContains($p, $names['settings'], "$rel §11: Seite „{$p}“ gibt es in den Einstellungen nicht");
            // „Haushalt → …“ und „Verbindungen → …“: die Namen der Reiter bzw. Seiten
            $seen = 0;
            foreach ($areas as $area => $key) {
                preg_match_all('/' . preg_quote($area, '/') . ' → ([^,)|*:\]—]+?)(?= ?[,)|*:\]—])/u', $s, $m);
                foreach ($m[1] as $p) {
                    $seen++;
                    self::assertContains(trim($p), $names[$key], "$rel §11: „$area → {$p}“ gibt es nicht");
                }
            }
            self::assertGreaterThanOrEqual(10, $seen, "$rel §11: Verweise auf Haushalt und Verbindungen");
        }
    }
}
