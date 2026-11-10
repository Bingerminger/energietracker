<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\DocumentOriginal;
use Energietracker\Services\Mcp\PendingChanges;
use Energietracker\Services\Mcp\Tools\DocumentTools;
use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 (F1037, Paket schreiben) — Doku-Zusagen zum Ändern über die KI rechnet
 * ein Test nach (Lektion 31, 99): Grenzen in Worten gleich den Konstanten, in
 * `api.md` DE und EN, in der Beschreibung von apply_proposal (alle Sprachen) und
 * in den Einstellungen; genannte Fehlercodes stehen im Katalog.
 */
final class McpWriteDocsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /** Abschnitt „Ändern: Vorschlag und Übernahme“ (ab dem Marker bis zur nächsten Überschrift ###). */
    private static function section(string $file): string
    {
        $src = (string)file_get_contents(self::root() . "/$file");
        $at = strpos($src, '<!-- doc-claim: mcp-proposal-limits -->');
        self::assertNotFalse($at, "$file: Marker doc-claim fehlt");
        $start = strrpos(substr($src, 0, $at), "\n#### ");
        $end = strpos($src, "\n### ", $at);
        return substr($src, (int)$start, ($end === false ? strlen($src) : $end) - (int)$start);
    }

    public function testApiReferenceStatesTheLimitsOfTheCode(): void
    {
        $minutes = intdiv(PendingChanges::TTL_SECONDS, 60);
        $mb = intdiv(DocumentOriginal::MAX_BYTES, 1024 * 1024);
        $de = self::section('docs/referenz/api.md');
        $en = self::section('docs/en/referenz/api.md');
        foreach ([
            "**$minutes Minuten**", '**' . PendingChanges::MAX_OPEN_PER_KEY . '**', '**' . DocumentTools::MAX_PAGES . ' Seiten**',
            '**' . number_format(DocumentTools::MAX_TEXT_CHARS, 0, ',', ' ') . ' Zeichen**', "bis $mb MB",
        ] as $claim) self::assertStringContainsString($claim, $de, "api.md: $claim");
        foreach ([
            "**$minutes minutes**", '**' . PendingChanges::MAX_OPEN_PER_KEY . '**', '**' . DocumentTools::MAX_PAGES . ' pages**',
            '**' . number_format(DocumentTools::MAX_TEXT_CHARS) . ' characters**', "up to $mb MB",
        ] as $claim) self::assertStringContainsString($claim, $en, "en/api.md: $claim");
        foreach (['source: "mcp"', 'via:', '"mcp_client"', 'requiresUserInteraction', 'mcp_share_identifiers', 'mcp_share_documents'] as $s) {
            self::assertStringContainsString($s, $de);
            self::assertStringContainsString($s, $en);
        }
    }

    public function testNamedErrorCodesExistInTheCatalog(): void
    {
        $de = (string)file_get_contents(self::root() . '/public/locales/de.json');
        $cat = json_decode($de, true);
        preg_match_all('/`(errors\.[a-zA-Z.]+)`/', self::section('docs/referenz/api.md'), $m);
        self::assertGreaterThanOrEqual(6, count(array_unique($m[1])));
        foreach (array_unique($m[1]) as $key) {
            $n = $cat;
            foreach (explode('.', $key) as $p) $n = is_array($n) ? ($n[$p] ?? null) : null;
            self::assertIsString($n, "$key steht in api.md, aber nicht im Katalog");
        }
    }

    public function testApplyDescriptionNamesTheTtlInEveryLanguage(): void
    {
        $minutes = (string)intdiv(PendingChanges::TTL_SECONDS, 60);
        foreach (['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'] as $lang) {
            $cat = json_decode((string)file_get_contents(self::root() . "/public/locales/$lang.json"), true);
            $text = (string)($cat['mcp']['tools']['apply_proposal']['description'] ?? '');
            self::assertMatchesRegularExpression('/(?<!\d)' . $minutes . '(?!\d)/', $text, "$lang: Gültigkeit in der Beschreibung");
        }
    }

    public function testSettingsReferenceNamesTheSizeOfOriginals(): void
    {
        $mb = intdiv(DocumentOriginal::MAX_BYTES, 1024 * 1024);
        foreach (['docs/referenz/einstellungen.md' => "bis $mb MB", 'docs/en/referenz/einstellungen.md' => "up to $mb MB"] as $file => $claim) {
            $row = array_values(array_filter(explode("\n", (string)file_get_contents(self::root() . "/$file")),
                fn($l) => str_starts_with($l, '| `mcp_share_documents`')));
            self::assertCount(1, $row, $file);
            self::assertStringContainsString($claim, $row[0], $file);
        }
    }
}
