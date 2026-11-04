<?php
declare(strict_types=1);

namespace Energietracker\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.11.0 (Skeptiker H3, N4, N6) — Aussagen, die die App nicht belegen kann, stehen in keiner Sprache mehr:
 *   - verlängerter Vertrag „zu den letzten Preisen“ und „jederzeit mit höchstens einem Monat Frist“
 *     (H3: für Fernwärme falsch, § 32 AVBFernwärmeV; für Strom und Gas nicht sicher belegt, Prüfbericht 59);
 *   - Empfehlungsseite „keine externen Quellen“ (N4: die Rechte-Hinweise nennen Gesetze und Quellen);
 *   - A5 mit dem Wortlaut von Prüfbericht 8: „trotz entsprechender Pflicht“ (N6).
 * Geprüft am deutschen und englischen Katalog (die übrigen Sprachen kommen aus derselben Schlüsseldatei).
 */
final class SkeptikerTextsTest extends TestCase
{
    private static function cat(string $lang): array
    {
        return json_decode((string)file_get_contents(__DIR__ . '/../../../public/locales/' . $lang . '.json'), true);
    }

    private static function get(array $cat, string $path): string
    {
        foreach (explode('.', $path) as $k) $cat = $cat[$k] ?? [];
        return is_string($cat) ? $cat : '';
    }

    /** @return array<string, array{0:string, 1:list<string>, 2:list<string>}> */
    public static function cases(): array
    {
        $renewal = ['utility.balance.renewed', 'utility.balance.cancelMissed', 'contracts.modal.autoRenewsHint', 'explainer.timeline.textRenewed'];
        return [
            'de' => ['de', $renewal, ['letzten Preisen', 'höchstens einem Monat', 'jederzeit']],
            'en' => ['en', $renewal, ['last prices', 'at most one month', 'any time']],
        ];
    }

    /** @param list<string> $keys @param list<string> $banned */
    #[DataProvider('cases')]
    public function testRenewedContractsMakeNoUnprovenClaims(string $lang, array $keys, array $banned): void
    {
        $cat = self::cat($lang);
        foreach ($keys as $k) {
            $v = self::get($cat, $k);
            self::assertNotSame('', $v, "$lang/$k fehlt");
            foreach ($banned as $b) self::assertStringNotContainsStringIgnoringCase($b, $v, "$lang/$k");
        }
        // Fernwärme mit der Regel, die der Prüfbericht belegt (Nr. 30)
        self::assertStringContainsString('§ 32 AVBFernwärmeV', self::get($cat, 'utility.balance.renewedHeat'));
        self::assertStringContainsString('§ 32 AVBFernwärmeV', self::get($cat, 'explainer.timeline.textRenewedHeat'));
    }

    public function testTheRecommendationsSubtitleAndTheEstimateWording(): void
    {
        $de = self::cat('de');
        self::assertStringNotContainsString('keine externen Quellen', self::get($de, 'recommendations.subtitle'));
        self::assertStringContainsString('Gesetze', self::get($de, 'recommendations.subtitle'));
        foreach (['text', 'textReported', 'textReportedBasic'] as $k) {
            self::assertStringContainsString('trotz entsprechender Pflicht keine Ablesedaten übermittelt wurden', self::get($de, "rights.estimate.$k"), $k);
        }
        self::assertSame('', self::get($de, 'rights.estimate.textBasic'), 'Anspruch ohne gemeldeten Stand gestrichen (M3)');
        // N7 — Prüfbericht 32 hat Status G, Nr. 31 (§ 310 Abs. 2 BGB) schränkt ein: „zu prüfen“
        $term = self::get($de, 'contracts.hint.termOver24');
        self::assertStringContainsString('ist zu prüfen (§ 310 Abs. 2 BGB)', $term);
        self::assertStringNotContainsString('lässt das Gesetz', $term);
    }
}
