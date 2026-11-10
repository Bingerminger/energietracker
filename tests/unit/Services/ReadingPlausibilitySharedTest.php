<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ReadingService;
use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 (F1037) — Rückfragen zu einem neuen Zählerstand: dieselbe Regel in
 * PHP (ReadingService::plausibilityIssues, Vorschlag einer KI) und JS
 * (lib/plausibility.js checkReading, Erfassung). Beide prüfen dieselbe Probe
 * tests/fixtures/reading-plausibility.json (Lektion 92), dazu derselbe Faktor.
 */
final class ReadingPlausibilitySharedTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testSharedFixtureGivesTheSameIssuesAsTheEntryForm(): void
    {
        $fx = json_decode((string)file_get_contents(self::root() . '/tests/fixtures/reading-plausibility.json'), true);
        self::assertGreaterThanOrEqual(10, count($fx['cases']));
        foreach ($fx['cases'] as $c) {
            $issues = ReadingService::plausibilityIssues((float)$c['value'], $c['date'], $c['prev'],
                $c['typical'] === null ? null : (float)$c['typical'], (bool)$c['deviceChanged'], $c['next'] ?? null, (bool)($c['nextDeviceChanged'] ?? false));
            self::assertSame($c['expect'], array_column($issues, 'type'), $c['name']);
        }
    }

    public function testJumpFactorIsTheOneOfTheEntryForm(): void
    {
        $js = (string)file_get_contents(self::root() . '/public/js/lib/plausibility.js');
        self::assertMatchesRegularExpression('/export const JUMP_FACTOR = ' . ReadingService::JUMP_FACTOR . ';/', $js);
    }

    public function testIssuesCarryTheFiguresForTheMessage(): void
    {
        $prev = ['date' => '2026-09-14', 'counter' => 1000.0];
        self::assertSame([['type' => 'lower', 'last' => 1000.0]], ReadingService::plausibilityIssues(990.0, '2026-09-24', $prev, 10.0));
        self::assertSame([['type' => 'jump', 'per_day' => 40.0, 'typical' => 10.0]], ReadingService::plausibilityIssues(1400.0, '2026-09-24', $prev, 10.0));
    }
}
