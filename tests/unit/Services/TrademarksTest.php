<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\Inbox\CloudConfig;
use PHPUnit\Framework\TestCase;

/**
 * v3.10.0 — TRADEMARKS.md nennt jeden Dienst, den die App beim Namen anbietet.
 * Bis 3.9 stand dort, der Energietracker verbinde sich nicht mit OpenAI — seit
 * dem Auslesen in der Cloud (3.7.0, Opt-in) stimmte das nicht mehr, und die
 * Anbieter der Voreinstellungen fehlten.
 */
final class TrademarksTest extends TestCase
{
    public function testEveryNamedCloudServiceIsListed(): void
    {
        $md = (string)file_get_contents(dirname(__DIR__, 3) . '/TRADEMARKS.md');
        preg_match_all('/^\| (\*\*[^|]+)\|/m', $md, $rows);
        $names = [];
        foreach ($rows[1] as $cell) {
            preg_match_all('/\*\*([^*]+)\*\*/', $cell, $b);
            foreach ($b[1] as $n) $names[] = $n;
        }
        self::assertNotEmpty($names);
        foreach (CloudConfig::PRESETS as $key => $preset) {
            if ($preset['label'] === null) continue;   // eigene Adresse
            // jedes Wort der Bezeichnung, etwa „Google Gemini“ → Gemini (Google steht beim Inhaber)
            preg_match_all('/[A-Z][A-Za-z]+/', $preset['label'], $words);
            $words = array_values(array_diff($words[0], ['Google']));
            foreach ($words as $w) self::assertContains($w, $names, "TRADEMARKS.md: $w (Voreinstellung $key)");
        }
        self::assertStringNotContainsString('does not connect to OpenAI', $md, 'seit 3.7.0 falsch (Auslesen in der Cloud)');
        self::assertMatchesRegularExpression('/^\| \*\*OpenAI\*\* \|[^\n]*cloud[^\n]*opt-in/m', $md);
    }
}
