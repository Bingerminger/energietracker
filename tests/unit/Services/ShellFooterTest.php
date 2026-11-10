<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 (M6) — Fußzeile der Seitenleiste: „Version · Hilfe · Stern · Herz“.
 *
 * Der Stern ersetzt den Link „GitHub“ und führt auf die Projektseite (nicht auf
 * die Liste der Sterne), das Herz zum Unterstützen. Beide sind eigene Grafiken
 * aus dem Sprite, öffnen einen neuen Tab und tragen eine Beschriftung für Maus
 * und Vorleser, die den Dienst nennt — in jeder der sieben Sprachen. Logo und
 * Name oben links führen zur Übersicht. Am iPhone steht die Fußzeile in der
 * Schublade „Mehr“, weil die Seitenleiste dort die Schublade ist.
 */
final class ShellFooterTest extends TestCase
{
    private const REPO = 'https://github.com/Bingerminger/energietracker';
    private const SUPPORT = 'https://buymeacoffee.com/bingerminger';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function footer(): string
    {
        $src = (string)file_get_contents(self::root() . '/index.php');
        self::assertSame(1, preg_match('#<div class="sidebar__footer">(.*?)</div>#s', $src, $m), 'Fußzeile der Seitenleiste fehlt');
        return $m[1];
    }

    /** @return array<string,array<string,mixed>> */
    private static function catalogs(): array
    {
        $out = [];
        foreach (glob(self::root() . '/public/locales/*.json') ?: [] as $f) {
            if (basename($f) === 'languages.json') continue;
            $out[basename($f, '.json')] = json_decode((string)file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
        }
        return $out;
    }

    private static function lookup(array $cat, string $key): ?string
    {
        $ref = $cat;
        foreach (explode('.', $key) as $k) {
            if (!is_array($ref) || !array_key_exists($k, $ref)) return null;
            $ref = $ref[$k];
        }
        return is_string($ref) ? $ref : null;
    }

    public function testOrderIsVersionHelpStarHeart(): void
    {
        $f = self::footer();
        $pos = [
            'Version' => strpos($f, '<span>v<?= $h($version) ?></span>'),
            'Hilfe' => strpos($f, 'href="#/help"'),
            'Stern' => strpos($f, 'href="' . self::REPO . '"'),
            'Herz' => strpos($f, 'href="' . self::SUPPORT . '"'),
        ];
        foreach ($pos as $what => $p) self::assertIsInt($p, "$what fehlt in der Fußzeile");
        $sorted = $pos;
        asort($sorted);
        self::assertSame(array_keys($pos), array_keys($sorted), 'Reihenfolge der Fußzeile: Version · Hilfe · Stern · Herz');
        // Der Stern ersetzt den Textlink „GitHub“; er zeigt auf die Projektseite, nicht auf /stargazers
        self::assertStringNotContainsString('>GitHub</a>', $f);
        self::assertStringNotContainsString('stargazers', $f);
    }

    public function testStarAndHeartAreOwnGraphicsInANewTab(): void
    {
        $f = self::footer();
        foreach (['stern' => self::REPO, 'herz' => self::SUPPORT] as $icon => $url) {
            self::assertSame(1, preg_match('#<a [^>]*href="' . preg_quote($url, '#') . '"[^>]*>(.*?)</a>#s', $f, $m), "Link $url fehlt");
            // öffnendes Tag bis zur Grafik (die Attribute enthalten PHP-Ausgaben mit spitzer Klammer)
            $tag = substr($m[0], 0, (int)strpos($m[0], '<svg'));
            self::assertStringContainsString('target="_blank"', $tag, "$icon öffnet keinen neuen Tab");
            self::assertStringContainsString('rel="noopener"', $tag);
            self::assertStringContainsString('data-shell-label="nav.footer.', $tag, "$icon ohne Beschriftung aus dem Katalog");
            self::assertStringContainsString('aria-label=', $tag);
            self::assertStringContainsString('title=', $tag);
            self::assertStringContainsString('<use href="#i-' . $icon . '"/>', $m[1], "$icon ist keine Grafik aus dem Sprite");
            self::assertStringNotContainsString('<img', $m[1], 'keine Bilder von außen');
        }
        $icons = (string)file_get_contents(self::root() . '/public/js/lib/icons.js');
        self::assertMatchesRegularExpression('/^\s+stern: \'/m', $icons);
        self::assertMatchesRegularExpression('/^\s+herz: \'/m', $icons);
    }

    public function testLabelsNameTheServiceInEveryLanguage(): void
    {
        $cats = self::catalogs();
        self::assertCount(7, $cats);
        foreach ($cats as $lang => $cat) {
            self::assertStringContainsString('GitHub', (string)self::lookup($cat, 'nav.footer.star'), "$lang: Stern nennt GitHub nicht");
            self::assertStringContainsString('Buy Me a Coffee', (string)self::lookup($cat, 'nav.footer.heart'), "$lang: Herz nennt den Dienst nicht");
            self::assertNotSame('', (string)self::lookup($cat, 'nav.footer.home'), "$lang: Tooltip des Logos fehlt");
        }
    }

    public function testLogoLeadsToTheOverview(): void
    {
        $src = (string)file_get_contents(self::root() . '/index.php');
        self::assertSame(1, preg_match('#<a class="topbar__home" href="\#/dashboard"[^>]*>(.*?)</a>#s', $src, $m), 'Logo führt nicht zur Übersicht');
        self::assertStringContainsString('topbar__logo', $m[1]);
        self::assertStringContainsString('topbar__title', $m[1]);
    }
}
