<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ContractService;
use Energietracker\Tests\Support\WaterBillFixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * v3.9.0 (F1034) — Doku-Behauptungen zu „Vertrag aus der Rechnung“ und zum
 * Abschlagsrhythmus an die Wirklichkeit gebunden (Lektion: Text und Code
 * driften sonst): die Beispielzahlen stehen so im Ende-zu-Ende-Test
 * (WaterBillContractTest), die Rhythmen so in ContractService, die zitierten
 * Texte so im Katalog. DE und EN.
 */
#[CoversNothing]
final class WaterContractDocsTest extends TestCase
{
    private static function read(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    private static function catalog(string $lang, string $key): string
    {
        $j = json_decode(self::read("public/locales/$lang.json"), true);
        foreach (explode('.', $key) as $k) $j = $j[$k] ?? null;
        return (string)$j;
    }

    public function testTheWaterPageNamesTheRhythmsAndTheNumbersOfTheTest(): void
    {
        self::assertSame([1, 2, 3], ContractService::ADVANCE_EVERY, 'die Doku nennt monatlich, alle 2 und alle 3 Monate');
        $de = self::read('docs/verstehen/03-wasser.md');
        $en = self::read('docs/en/verstehen/03-wasser.md');
        foreach (['monatlich, alle 2 Monate oder', 'alle 3 Monate', 'je Termin', '330,00 €', '495,00 €', '13,375 €', '224,7 ct', 'schmutzwasser.base_prices'] as $s) {
            self::assertStringContainsString($s, $de, $s);
        }
        foreach (['monthly, every 2 months or every 3', 'per due date', '€330.00', '€495.00', '€13.375', '224.7 ct', 'schmutzwasser.base_prices'] as $s) {
            self::assertStringContainsString($s, $en, $s);
        }
        // das Beispiel ist der Grundpreis des Testdokuments (Basis 50 + Bereitstellung 100 netto, 7 %)
        self::assertEqualsWithDelta(13.375, WaterBillFixture::GROSS['trinkwasser']['base']['2025-04-01'], 1e-4);
        self::assertEqualsWithDelta(224.7, WaterBillFixture::GROSS['trinkwasser']['working']['2025-04-01'], 1e-9);
        // der Rhythmus im Formular heißt so, wie die Doku ihn nennt
        self::assertSame('Rhythmus', self::catalog('de', 'contracts.advance.every'));
        self::assertSame('Rhythm', self::catalog('en', 'contracts.advance.every'));
    }

    public function testTheInboxGuideQuotesTheAppTexts(): void
    {
        $de = self::read('docs/anleitungen/posteingang.md');
        $en = self::read('docs/en/anleitungen/posteingang.md');
        self::assertStringContainsString('„' . self::catalog('de', 'inbox.proposal.requiresContract') . '“', $de);
        self::assertStringContainsString('“' . self::catalog('en', 'inbox.proposal.requiresContract') . '”', $en);
        self::assertStringContainsString('„' . explode(' (', self::catalog('de', 'inbox.proposal.billBook'))[0] . '“', $de);
        self::assertStringContainsString('“' . explode(' (', self::catalog('en', 'inbox.proposal.billBook'))[0] . '”', $en);
        foreach ([$de, $en] as $doc) {
            self::assertStringContainsString('S=Schätzung I=Internetablesung', $doc);
            self::assertStringContainsString('3 1 5,5 9 €', $doc);
        }
        self::assertStringNotContainsString('einen neuen Wasservertrag legst du', $de, 'Wasserverträge entstehen jetzt aus der Rechnung');
        self::assertStringNotContainsString('you create a new water contract by', $en);
    }
}
