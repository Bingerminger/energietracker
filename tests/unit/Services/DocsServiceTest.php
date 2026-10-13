<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\DocsService;
use PHPUnit\Framework\TestCase;

/**
 * v3.4.0 (N1019) — die Freigabeliste der Doku an einem eigenen Dateibestand:
 * Seiten ja, Weiterleitungen nein (aber mit Ziel), Bilder nur PNG unter
 * ui/screenshots/, Werbebilder nie, symbolische Links nie — weder als Datei
 * noch als Verzeichnis —, aus dem Wurzelverzeichnis nur die feste Liste.
 */
final class DocsServiceTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/et-docs-' . bin2hex(random_bytes(4));
        $files = [
            'docs/README.md'                          => "# Index\n",
            'docs/en/README.md'                       => "# Index (en)\n",
            'docs/einstieg/faq.md'                    => "# FAQ\n",
            'docs/nur-deutsch.md'                     => "# Nur Deutsch\n",
            'docs/ALT.md'                             => "<!-- umgezogen: v2.14.0 -->\n# Umgezogen\n\nSteht unter **[FAQ](einstieg/faq.md)**.\n\n[← Index](README.md)\n",
            'docs/en/old.md'                          => "<!-- moved: v2.14.0 -->\n# Moved\n\nNow at [FAQ](einstieg/faq.md).\n",
            'docs/ui/screenshots/a.png'               => "\x89PNG\r\n\x1a\n",
            'docs/ui/screenshots/en/b.png'            => "\x89PNG\r\n\x1a\n",
            'docs/ui/screenshots/c.svg'               => '<svg/>',
            'docs/assets/promo/werbung.png'           => "\x89PNG\r\n\x1a\n",
            'docs/root/CHANGELOG.md'                  => "# untergeschoben\n",
            'CHANGELOG.md'                            => "# Changelog\n",
            'CONTRIBUTING.md'                         => "# Contributing\n",
            'CONTRIBUTING.de.md'                      => "# Mitwirken\n",
            'LICENSE'                                 => "GNU AFFERO GENERAL PUBLIC LICENSE\n",
            'README.md'                               => "# Projekt\n",
            'roadmap.md'                              => "# Roadmap\n",
            'VERSION'                                 => "9.8.7\n",
            'geheim/secret.md'                        => "# Geheim\n",
        ];
        foreach ($files as $rel => $content) {
            @mkdir(dirname($this->root . '/' . $rel), 0755, true);
            file_put_contents($this->root . '/' . $rel, $content);
        }
        // symbolische Links: eine Seite, ein Bild und ein ganzes Verzeichnis nach außen
        symlink($this->root . '/geheim/secret.md', $this->root . '/docs/link.md');
        symlink($this->root . '/docs/ui/screenshots/a.png', $this->root . '/docs/ui/screenshots/link.png');
        symlink($this->root . '/geheim', $this->root . '/docs/verzeichnis');
        symlink($this->root . '/roadmap.md', $this->root . '/SECURITY.md');
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheAllowlistIsBuiltFromTheFilesWithoutLinksAndRedirects(): void
    {
        $d = new DocsService($this->root);
        self::assertSame([
            'README.md', 'einstieg/faq.md', 'en/README.md', 'nur-deutsch.md',
            'root/CHANGELOG.md', 'root/CONTRIBUTING.de.md', 'root/CONTRIBUTING.md', 'root/LICENSE',
        ], array_keys($d->pages()));
        self::assertSame(['ui/screenshots/a.png', 'ui/screenshots/en/b.png'], array_keys($d->assets()));
        self::assertSame(['CHANGELOG.md', 'CONTRIBUTING.de.md', 'CONTRIBUTING.md', 'LICENSE'], $d->rootFiles());
        self::assertSame('9.8.7', $d->version());
    }

    public function testAPageNamesItsLanguageAndMirror(): void
    {
        $d = new DocsService($this->root);
        self::assertSame(['README.md', 'de', 'en/README.md', "# Index\n"],
            array_values(array_intersect_key((array)$d->page('README.md'), array_flip(['path', 'lang', 'mirror', 'markdown']))));
        self::assertSame('README.md', $d->page('en/README.md')['mirror'] ?? null);
        self::assertNull($d->page('nur-deutsch.md')['mirror']);
        self::assertSame(['en', 'root/CONTRIBUTING.de.md'], [$d->page('root/CONTRIBUTING.md')['lang'], $d->page('root/CONTRIBUTING.md')['mirror']]);
        self::assertSame(['de', 'root/CONTRIBUTING.md'], [$d->page('root/CONTRIBUTING.de.md')['lang'], $d->page('root/CONTRIBUTING.de.md')['mirror']]);
        self::assertNull($d->page('root/CHANGELOG.md')['mirror'], 'ohne CHANGELOG.en.md kein Spiegel');
        self::assertSame("# Changelog\n", $d->page('root/CHANGELOG.md')['markdown'], 'docs/root/ schiebt nichts unter');
    }

    public function testEverythingElseIsNotFound(): void
    {
        $d = new DocsService($this->root);
        foreach (['', 'ALT.md', 'en/old.md', 'link.md', 'verzeichnis/secret.md', '../roadmap.md', 'root/roadmap.md',
                  'root/README.md', 'root/SECURITY.md', 'root/VERSION', 'root/../VERSION', '/README.md', './README.md',
                  'en/../README.md', "README.md\0", 'ui/screenshots/a.png', 'assets/promo/werbung.png'] as $p) {
            self::assertNull($d->page($p), "Seite $p");
        }
        foreach (['ui/screenshots/c.svg', 'ui/screenshots/link.png', 'assets/promo/werbung.png', 'README.md',
                  'ui/screenshots/../screenshots/a.png', '../docs/ui/screenshots/a.png'] as $p) {
            self::assertNull($d->asset($p), "Bild $p");
        }
        self::assertSame('image/png', $d->asset('ui/screenshots/en/b.png')['type'] ?? null);
    }

    public function testARedirectNamesItsTargetOnlyIfThatIsServed(): void
    {
        $d = new DocsService($this->root);
        self::assertSame('einstieg/faq.md', $d->movedTo('ALT.md'));
        self::assertNull($d->movedTo('en/old.md'), 'Ziel en/einstieg/faq.md gibt es nicht');
        self::assertNull($d->movedTo('README.md'), 'keine Weiterleitung');
        self::assertNull($d->movedTo('../ALT.md'));
    }
}
