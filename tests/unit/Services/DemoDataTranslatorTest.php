<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\DemoDataTranslator;
use PHPUnit\Framework\TestCase;

/**
 * v3.1.0 (Review I18N-24) — Demo-Daten in der Sprache der Oberfläche.
 *
 * Die Übersetzungstabelle muss vollständig bleiben: Kommt im Demo-Backup ein
 * neuer Text dazu, ohne dass er übersetzt wird, steht er in sechs Sprachen
 * wieder deutsch da — genau das, was I18N-24 beanstandet hat.
 */
final class DemoDataTranslatorTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function backup(): array
    {
        return json_decode((string)file_get_contents(self::root() . '/demo-data/energietracker-demo-backup.json'), true);
    }

    private static function strings(): array
    {
        return DemoDataTranslator::load(self::root() . '/demo-data/translations.json');
    }

    /** @return string[] alle Texte der übersetzbaren Felder im Backup */
    private static function texts(mixed $node, array &$out = []): array
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if (is_string($v) && is_string($k) && in_array($k, DemoDataTranslator::FIELDS, true) && trim($v) !== '') $out[] = $v;
                elseif (is_array($v)) self::texts($v, $out);
            }
        }
        return array_values(array_unique($out));
    }

    public function testEveryDemoTextIsTranslatedIntoEveryLanguage(): void
    {
        $b = self::backup();
        $texts = self::texts(['utilities' => $b['utilities'], 'reminders' => $b['reminders']]);
        self::assertGreaterThan(40, count($texts));
        $langs = array_diff(array_keys(json_decode((string)file_get_contents(self::root() . '/public/locales/languages.json'), true)), ['de']);
        $strings = self::strings();
        $missing = [];
        foreach ($texts as $text) {
            foreach ($langs as $l) {
                if (trim((string)($strings[$text][$l] ?? '')) === '') $missing[] = "$l: $text";
            }
        }
        self::assertSame([], $missing, 'Demo-Texte ohne Übersetzung');
    }

    public function testTranslateChangesTextsButNotIdsNumbersOrCompanyNames(): void
    {
        $b = self::backup();
        $en = DemoDataTranslator::translate($b, 'en', self::strings());
        $gas = $en['utilities']['gas'];
        self::assertSame('Main gas meter', $gas['meters'][0]['name']);
        self::assertSame('Roof insulation', $gas['meters'][0]['baseline_events'][0]['label']);
        self::assertSame('Heating service', $en['reminders'][0]['title']);
        self::assertSame($b['utilities']['gas']['meters'][0]['id'], $gas['meters'][0]['id']);
        self::assertSame(array_column($b['utilities']['gas']['readings'], 'counter'), array_column($gas['readings'], 'counter'));
        self::assertSame(array_column($b['utilities']['gas']['contracts'], 'provider'), array_column($gas['contracts'], 'provider'),
            'Firmennamen bleiben Eigennamen');
        self::assertSame($b, DemoDataTranslator::translate($b, 'de', self::strings()), 'Deutsch bleibt unverändert');
    }

    public function testTheDockerImageShipsTheTranslations(): void
    {
        self::assertStringContainsString('!demo-data/translations.json', (string)file_get_contents(self::root() . '/.dockerignore'));
    }
}
