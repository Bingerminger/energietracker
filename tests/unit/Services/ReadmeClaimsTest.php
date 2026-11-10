<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Rights;
use Energietracker\Services\LetterService;
use Energietracker\Services\RightsService;
use Energietracker\Services\SettingsService;
use Energietracker\Tests\Services\Mcp\McpDocsTest;
use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 (M6) — Die Tabelle „Auf einen Blick“ im README wirbt; jede Zahl und jede Zusage darin hängt an
 * Code oder an einem anderen Test. Ohne diese Bindung driften Werbung und Wirklichkeit auseinander (Lektion
 * „Doku-Behauptungen brauchen Tests“).
 */
final class ReadmeClaimsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** README ohne Zeilenumbrüche, damit Sätze über Zeilen hinweg gefunden werden */
    private static function flat(string $file): string
    {
        return (string)preg_replace('/\s+/u', ' ', (string)file_get_contents(self::root() . '/' . $file));
    }

    public function testBothReadmesCarryTheClaimMarker(): void
    {
        foreach (['README.md', 'README.de.md'] as $f) {
            self::assertStringContainsString('<!-- doc-claim: readme-auf-einen-blick (ReadmeClaimsTest) -->', self::flat($f), $f);
        }
    }

    /** „20 Werkzeugen“: McpDocsTest bindet die Liste an die Registry. */
    public function testToolCountMatchesTheRegistry(): void
    {
        $n = count(McpDocsTest::TOOLS);
        self::assertStringContainsString("mit $n Werkzeugen", self::flat('README.de.md'));
        self::assertStringContainsString("with $n tools", self::flat('README.md'));
    }

    /** „Ab Werk aus“ und „bestätigst du jeden Vorschlag in der App“: die Standardwerte der Einstellungen. */
    public function testAiIsOffByDefaultAndProposalsAreConfirmedInTheApp(): void
    {
        $defaults = (new \ReflectionClassConstant(SettingsService::class, 'DEFAULTS'))->getValue();
        self::assertFalse($defaults['mcp_enabled']);
        self::assertTrue($defaults['mcp_apply_in_app']);
        self::assertStringContainsString('Ab Werk aus', self::flat('README.de.md'));
        self::assertStringContainsString('Ab Werk bestätigst du jeden Vorschlag in der App', self::flat('README.de.md'));
        self::assertStringContainsString('Off by default', self::flat('README.md'));
        self::assertStringContainsString('by default you confirm every proposal in the app', self::flat('README.md'));
    }

    /** „geprüft mit Open WebUI … Ollama“, der Rest experimentell: wie die Anleitung (McpValueDocsTest). */
    public function testTestedClientsMatchTheGuide(): void
    {
        foreach (['docs/anleitungen/ki-assistent.md', 'docs/en/anleitungen/ki-assistent.md'] as $guide) {
            $g = self::flat($guide);
            self::assertStringContainsString('<!-- doc-claim: mcp-tested-with -->', $g);
            self::assertStringContainsString('Open WebUI', $g);
            self::assertStringContainsString('Ollama', $g);
        }
        self::assertStringContainsString('geprüft mit Open WebUI und einem lokalen Modell über Ollama, andere Programme und Cloud-KI experimentell', self::flat('README.de.md'));
        self::assertStringContainsString('tested with Open WebUI and a local model through Ollama, other programs and cloud AI experimental', self::flat('README.md'));
    }

    /** „Sieben Prüfungen nach deutschem Recht“, Brief „als Text und PDF, auf Deutsch“. */
    public function testRightsAndLettersAsPromised(): void
    {
        self::assertCount(7, RightsService::CHECKS);
        self::assertSame(['DE'], array_keys(array_filter(Rights::RULES)), 'Prüfungen nur nach deutschem Recht');
        self::assertSame(['text', 'pdf'], LetterService::FORMATS);
        $letters = array_map('basename', glob(self::root() . '/src/Services/Letters/*.json') ?: []);
        self::assertSame(['de.json'], $letters, 'Briefe nur auf Deutsch');
        self::assertStringContainsString('Sieben Prüfungen nach deutschem Recht', self::flat('README.de.md'));
        self::assertStringContainsString('als Text und PDF, auf Deutsch', self::flat('README.de.md'));
        self::assertStringContainsString('Seven checks under German law', self::flat('README.md'));
        self::assertStringContainsString('as text and PDF, in German', self::flat('README.md'));
    }

    /** Linkreihe oben: Demo, Hilfe, Roadmap, Discussions, in dieser Reihenfolge, vor dem ersten Abschnitt. */
    public function testLinkRowOnTop(): void
    {
        foreach (['README.de.md' => ['Demo', 'Hilfe', 'Roadmap', 'Discussions'], 'README.md' => ['Demo', 'Help', 'Roadmap', 'Discussions']] as $f => $labels) {
            $txt = (string)file_get_contents(self::root() . "/$f");
            $head = substr($txt, 0, (int)strpos($txt, "\n## "));
            $pos = array_map(fn($l) => strpos($head, "<b>$l</b></a>"), $labels);
            foreach ($pos as $i => $p) self::assertIsInt($p, "$f: „{$labels[$i]}“ fehlt in der Linkreihe oben");
            $sorted = $pos;
            sort($sorted);
            self::assertSame($sorted, $pos, "$f: Reihenfolge Demo, Hilfe, Roadmap, Discussions");
            self::assertStringContainsString('https://bingerminger.github.io/energietracker/', $head);
            self::assertStringContainsString('https://github.com/Bingerminger/energietracker/discussions', $head);
        }
        // dieselbe Reihe in der Hilfe der App
        $help = (string)file_get_contents(self::root() . '/public/js/views/help.js');
        self::assertSame(1, preg_match('#<nav class="help-links-row".*?</nav>#s', $help, $row), 'Hilfe: Linkreihe fehlt');
        $last = -1;
        foreach (['${DEMO_URL}', "docUrl('compendium')", '${roadmapUrl()}', '${DISCUSSIONS_URL}'] as $needle) {
            $p = strpos($row[0], $needle);
            self::assertIsInt($p, "Hilfe: Linkreihe ohne $needle");
            self::assertGreaterThan($last, $p, 'Hilfe: Reihenfolge Demo, Handbuch, Roadmap, Discussions');
            $last = $p;
        }
        $docs = (string)file_get_contents(self::root() . '/public/js/lib/docs.js');
        self::assertStringContainsString("DEMO_URL = 'https://bingerminger.github.io/energietracker/'", $docs);
    }

    /** Jedes Bild, das das README zeigt, liegt im Repository. */
    public function testReadmeImagesExist(): void
    {
        foreach (['README.md', 'README.de.md'] as $f) {
            preg_match_all('#(?:src|srcset)="((?:docs|public)/[^"]+)"#', (string)file_get_contents(self::root() . "/$f"), $m);
            self::assertGreaterThanOrEqual(10, count($m[1]), $f);
            foreach ($m[1] as $img) self::assertFileExists(self::root() . '/' . $img, "$f: $img");
        }
    }
}
