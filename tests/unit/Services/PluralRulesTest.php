<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\I18nService;
use Energietracker\Services\SettingsService;
use Energietracker\Storage\JsonStore;
use PHPUnit\Framework\TestCase;

/**
 * v3.1.0 (Review I18N-09) — Pluralformen im Backend.
 *
 * Bis v3.0 meldete die Empfehlung zum Vertragsende „Vertrag endet in 1 Tagen":
 * Das Backend kannte keine Pluralformen. I18nService::tp() wählt sie jetzt nach
 * einer kleinen CLDR-Tabelle (ohne ext-intl). Dieselben Fälle prüft
 * tests/plural.test.mjs gegen Intl.PluralRules im Browser.
 */
final class PluralRulesTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testBackendRulesMatchTheBrowser(): void
    {
        $fixture = json_decode((string)file_get_contents(self::root() . '/tests/fixtures/plural-cases.json'), true);
        self::assertIsArray($fixture['cases'] ?? null);
        foreach ($fixture['cases'] as $lang => $cases) {
            foreach ($cases as $n => $expected) {
                self::assertSame($expected, I18nService::pluralCategory($lang, (float)$n), "$lang $n");
            }
        }
    }

    /** Eine neue Sprache braucht eine Pluralregel und einen Eintrag in der Fixture. */
    public function testEveryLanguageHasAPluralRule(): void
    {
        $languages = array_keys((array)json_decode((string)file_get_contents(self::root() . '/public/locales/languages.json'), true));
        $fixture = json_decode((string)file_get_contents(self::root() . '/tests/fixtures/plural-cases.json'), true);
        foreach ($languages as $lang) {
            self::assertArrayHasKey($lang, I18nService::PLURAL_RULES, "I18nService::PLURAL_RULES: $lang fehlt");
            self::assertArrayHasKey($lang, $fixture['cases'], "tests/fixtures/plural-cases.json: $lang fehlt");
        }
    }

    public function testContractEndsInOneDay(): void
    {
        $tmp = sys_get_temp_dir() . '/et-plural-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0755, true);
        try {
            $i18n = new I18nService(self::root() . '/public/locales', new SettingsService(new JsonStore($tmp)));
            $p = ['label' => 'Gas', 'days' => 1];
            self::assertSame('Gas: Vertrag endet in 1 Tag', $i18n->tp('recommendations.engine.r6.title', 1, $p, 'de'));
            self::assertSame('Gas: Vertrag endet in 3 Tagen', $i18n->tp('recommendations.engine.r6.title', 3, ['days' => 3] + $p, 'de'));
            self::assertSame('Gas: contract ends in 1 day', $i18n->tp('recommendations.engine.r6.title', 1, $p, 'en'));
            // Französisch: auch 0 ist Singular
            self::assertStringEndsWith("dans 0\u{00A0}jour", str_replace(' jour', "\u{00A0}jour", $i18n->tp('recommendations.engine.r6.title', 0, ['days' => 0] + $p, 'fr')));
            // fehlende Form (many) fällt auf other zurück
            self::assertSame('Gas: Vertrag endet in 1000000 Tagen', $i18n->tp('recommendations.engine.r6.title', 1000000, ['days' => 1000000] + $p, 'de'));
            self::assertStringContainsString('1000000 giorni', $i18n->tp('recommendations.engine.r6.title', 1000000, ['days' => 1000000] + $p, 'it'));
        } finally {
            foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
            @rmdir($tmp);
        }
    }
}
