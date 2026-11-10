<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 — Verhaltensregeln: Melden geht nur über GitHub.
 *
 * Bis v3.11 stand im Abschnitt „Melden“ ein Platzhalter für einen Kontakt. Jetzt
 * nennen beide Hälften (Englisch, Deutsch) genau zwei Wege, beide auf GitHub:
 * „Report content“ am Beitrag und für Vertrauliches den privaten Meldeweg aus
 * SECURITY.md. Keine E-Mail-Adresse, kein Platzhalter.
 */
final class CodeOfConductTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . "/$rel");
    }

    /** @return array{en: string, de: string} der Abschnitt „Melden“ je Sprache */
    private static function reporting(): array
    {
        $md = self::read('CODE_OF_CONDUCT.md');
        preg_match('/^## Reporting\n(.*?)^## /ms', $md, $en);
        preg_match('/^### Melden\n(.*?)^### /ms', $md, $de);
        return ['en' => $en[1] ?? '', 'de' => $de[1] ?? ''];
    }

    public function testNoPlaceholderAndNoMailAddress(): void
    {
        $md = self::read('CODE_OF_CONDUCT.md');
        self::assertStringNotContainsString('‹', $md, 'Platzhalter für den Kontakt');
        self::assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[a-z]{2,}/i', $md, 'keine E-Mail-Adresse');
        self::assertStringNotContainsString('mailto:', $md);
    }

    public function testBothHalvesNameTheTwoGithubWays(): void
    {
        // der private Meldeweg ist derselbe wie in SECURITY.md
        self::assertSame(1, preg_match('#\((https://github\.com/[^)]+/security/advisories/new)\)#', self::read('SECURITY.md'), $m), 'SECURITY.md: privater Meldeweg');
        foreach (self::reporting() as $lang => $text) {
            self::assertNotSame('', $text, "$lang: Abschnitt fehlt");
            self::assertStringContainsString('**Report content**', $text, "$lang: Inhalt melden");
            self::assertStringContainsString("({$m[1]})", $text, "$lang: privater Meldeweg wie in SECURITY.md");
            self::assertStringContainsString('[SECURITY.md](SECURITY.md)', $text, $lang);
        }
    }
}
