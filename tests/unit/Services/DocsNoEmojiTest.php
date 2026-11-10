<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * Die Doku zeigt keine Emoji.
 *
 * Seit v3.12.0 zeichnet die App ihre Symbole selbst (`lib/icons.js`); kein Katalog und keine
 * Oberflächen-Datei gibt mehr Emoji aus (`tests/icons.test.mjs`). Die Doku schrieb danach noch
 * rund 400 davon: „⚙️ Zähler“, „🌱 Einsteiger“, „💶 Kosten & Verträge“, „✅“ für einen Knopf,
 * der so nicht mehr aussieht. Wer der Hilfe folgte, suchte ein Bild, das es nicht gibt.
 * Knöpfe und Menüs stehen in der Doku deshalb mit ihrer Beschriftung, Zustände als Wort.
 *
 * Geprüft wird jede Markdown-Datei unter `docs/` außerhalb von Codeblöcken (``` und ~~~,
 * auch eingerückt): Dort zeigen Beispiele echte Ausgaben, etwa das Feld `icon` der API.
 */
final class DocsNoEmojiTest extends TestCase
{
    /**
     * Reine Textzeichen, die Unicode zu `Extended_Pictographic` zählt. Jede Ausnahme braucht
     * einen Grund; ein Bildzeichen gehört nicht hierher.
     */
    private const TEXT_CHARACTERS = [
        '↔' => 'Pfeil zwischen zwei Begriffen („Backend↔Frontend“), kein Symbol der App',
        '©' => 'Rechtszeichen in Lizenz- und Markenhinweisen',
        '®' => 'Rechtszeichen in Lizenz- und Markenhinweisen',
        '™' => 'Rechtszeichen in Lizenz- und Markenhinweisen',
    ];

    private const PICTOGRAPHIC = '/\p{Extended_Pictographic}/u';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return string[] repo-relative Markdown-Dateien unter docs/ */
    private static function docs(): array
    {
        $root = self::root();
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/docs", \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'md') $files[] = substr($f->getPathname(), strlen($root) + 1);
        }
        sort($files);
        return $files;
    }

    /**
     * Fundstellen einer Datei außerhalb von Codeblöcken.
     *
     * @return list<string> „Datei:Zeile: U+XXXX x │ Zeilenanfang“
     */
    private static function hits(string $rel, string $md): array
    {
        $out = [];
        $fence = null;
        foreach (preg_split('/\R/u', $md) ?: [] as $i => $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $m)) {
                $mark = $m[1][0];
                if ($fence === null) $fence = $mark;
                elseif ($fence === $mark) $fence = null;
                continue;
            }
            if ($fence !== null) continue;
            if (!preg_match_all('/\X/u', $line, $chars)) continue;
            foreach ($chars[0] as $g) {
                if (preg_match(self::PICTOGRAPHIC, $g) !== 1) continue;
                if (isset(self::TEXT_CHARACTERS[(string)preg_replace('/\x{FE0E}|\x{FE0F}/u', '', $g)])) continue;
                $out[] = sprintf('%s:%d: U+%04X %s │ %s', $rel, $i + 1, mb_ord($g), $g, mb_substr(trim($line), 0, 90));
            }
        }
        return $out;
    }

    /** Ohne die Eigenschaft im Regex-Motor prüfte der Test nichts und bliebe trotzdem grün. */
    public function testThePatternKnowsEmoji(): void
    {
        self::assertSame(1, preg_match(self::PICTOGRAPHIC, '🌱'), 'PCRE kennt \p{Extended_Pictographic} nicht: ' . preg_last_error_msg());
        self::assertSame(1, preg_match(self::PICTOGRAPHIC, '⚙️'));
        self::assertSame(0, preg_match(self::PICTOGRAPHIC, '→ ✓ – … ₂'), 'Text-Pfeil, Häkchen, Strich und Tiefstellung sind kein Emoji');
        self::assertSame([], self::hits('probe.md', "```text\n⚡ in einem Codeblock\n```\n    ~~~\n    🔥 eingerückt\n    ~~~\nBackend↔Frontend"),
            'Codeblöcke und die Textzeichen zählen nicht');
        self::assertCount(2, self::hits('probe.md', "## 🚀 Einstieg\nUnter **⚙️ Zähler**"), 'Überschrift und Fließtext zählen');
    }

    public function testDocsShowNoEmojiOutsideCodeBlocks(): void
    {
        $files = self::docs();
        self::assertGreaterThan(100, count($files), 'keine Doku gefunden — Suche kaputt');
        $hits = [];
        foreach ($files as $rel) {
            $hits = array_merge($hits, self::hits($rel, (string)file_get_contents(self::root() . '/' . $rel)));
        }
        self::assertSame([], $hits, "Emoji in der Doku. Statt des Symbols die Beschriftung aus der App nennen "
            . "(public/locales/de.json bzw. en.json), einen Zustand als Wort:\n" . implode("\n", $hits));
    }

    /** Jede Ausnahme ist ein reines Textzeichen und hat ihren Grund. */
    public function testTheExceptionsAreTextCharacters(): void
    {
        foreach (self::TEXT_CHARACTERS as $char => $why) {
            self::assertSame(1, preg_match(self::PICTOGRAPHIC, $char), "„{$char}“ ist kein Extended_Pictographic und braucht keine Ausnahme");
            self::assertSame(1, mb_strlen($char), "„{$char}“: eine Ausnahme ist ein einzelnes Zeichen");
            self::assertNotSame('', trim($why), "„{$char}“ ohne Grund");
        }
    }
}
