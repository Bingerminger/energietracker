<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\SettingsService;
use Energietracker\Support\Dates;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 — Zusagen der Doku zur Eingabeprüfung, an die Konstanten gebunden (Lektion 31/99):
 * Jahresbereich und höchster Zählerstand (API-Referenz), Grenzen von Wohnfläche und Personen
 * (Einstellungen), die Anlässe der Snapshots (API-Referenz) und der Gesundheitscheck beim
 * Erststart (Länderprofile, Dockerfile). Je Satz DE und EN.
 */
#[CoversNothing]
final class InputDocsTest extends TestCase
{
    private static function flat(string $rel): string
    {
        return (string)preg_replace('/\s+/u', ' ', (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel));
    }

    public function testTheYearRangeAndTheHighestReadingAreTheConstants(): void
    {
        [$min, $max] = [Dates::MIN_INPUT_YEAR, Dates::MAX_INPUT_YEAR];
        self::assertStringContainsString("ein Datum außerhalb der Jahre $min bis $max", self::flat('docs/referenz/api.md'));
        self::assertStringContainsString("a date outside the years $min to $max", self::flat('docs/en/referenz/api.md'));
        self::assertSame(1e12, ReadingService::MAX_COUNTER);
        self::assertStringContainsString('ein Zählerstand über 10¹²', self::flat('docs/referenz/api.md'));
        self::assertStringContainsString('a meter reading above 10¹²', self::flat('docs/en/referenz/api.md'));
    }

    public function testTheHouseholdLimitsAreTheConstants(): void
    {
        [$aLo, $aHi] = SettingsService::NUMBER_LIMITS['wohnflaeche_m2'];
        [$pLo, $pHi, $pInt] = SettingsService::NUMBER_LIMITS['wasser_personen_anzahl'];
        self::assertTrue($pInt, 'Personen ganzzahlig');
        $de = self::flat('docs/referenz/einstellungen.md');
        $en = self::flat('docs/en/referenz/einstellungen.md');
        foreach ([$de, $en] as $doc) self::assertStringContainsString('<!-- doc-claim: household-limits -->', $doc);
        self::assertStringContainsString('eine Zahl von ' . $aLo . ' bis ' . number_format($aHi, 0, ',', '.') . ' m²', $de);
        self::assertStringContainsString('eine ganze Zahl von ' . $pLo . ' bis ' . number_format($pHi, 0, ',', '.'), $de);
        self::assertStringContainsString('a number from ' . $aLo . ' to ' . number_format($aHi) . ' m²', $en);
        self::assertStringContainsString('a whole number from ' . $pLo . ' to ' . number_format($pHi), $en);
    }

    public function testEverySnapshotReasonIsDocumented(): void
    {
        $reasons = array_unique(array_map([BackupService::class, 'reasonOf'], ['backup_x', 'pre-restore-x', 'pre-migration-x',
            'pre-demo-x', 'pre-v09-x', 'pre-inbox-x', 'concept_x', 'sonst-x']));
        foreach (['docs/referenz/api.md', 'docs/en/referenz/api.md'] as $f) {
            $doc = self::flat($f);
            $start = strpos($doc, '`reason` ∈ `manual`');
            self::assertNotFalse($start, "$f: Absatz zu den Anlässen fehlt");
            $para = substr($doc, $start, 900);
            foreach ($reasons as $r) self::assertStringContainsString("`$r`", $para, "$f: Anlass $r fehlt");
        }
    }

    public function testTheHealthCheckSentenceMatchesTheImage(): void
    {
        foreach (['docs/verstehen/14-laenderprofile.md', 'docs/en/verstehen/14-laenderprofile.md'] as $f) {
            self::assertStringContainsString('<!-- doc-claim: first-start-health -->', self::flat($f));
        }
        $docker = (string)file_get_contents(dirname(__DIR__, 3) . '/Dockerfile');
        // „alle 30 Sekunden“ und ohne Sprachangabe: so fragt das Image
        self::assertGreaterThanOrEqual(2, preg_match_all('#HEALTHCHECK --interval=30s[^\n]*\\\\\s*\n\s*CMD wget -qO- http://127\.0\.0\.1(:\d+)?/api/health#', $docker));
        self::assertStringNotContainsString('Accept-Language', $docker);
        self::assertStringContainsString('alle 30 Sekunden', self::flat('docs/verstehen/14-laenderprofile.md'));
        self::assertStringContainsString('every 30 seconds', self::flat('docs/en/verstehen/14-laenderprofile.md'));
    }
}
