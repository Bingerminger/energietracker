<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Controllers\InboxController;
use PHPUnit\Framework\TestCase;

/**
 * v3.7.0 — Erkennen, Auslesen und Texterkennung warten auf ein KI-Modell
 * länger als die 30 s, die PHP je Anfrage vorgibt. Ohne eigene Zeitgrenze bricht
 * PHP mitten im Auslesen ab; der eingebaute Server (`php -S`) beendet sich dabei
 * ganz. Gefunden am Dev-Server mit einem lokalen Modell (Auslesen ~60–120 s).
 */
final class LongRequestTimeLimitTest extends TestCase
{
    private function source(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/src/Controllers/' . $file);
    }

    public function testInboxReadRaisesTheTimeLimitAboveTheLongestProviderTimeout(): void
    {
        $src = $this->source('InboxController.php');
        self::assertStringContainsString('set_time_limit(self::READ_TIME_LIMIT_S)', $src, 'Erkennen und Auslesen heben die Zeitgrenze an');
        // Cloud-Anschlüsse warten bis 600 s, lokal bis 300 s — dazu Puffer
        self::assertGreaterThanOrEqual(630, InboxController::READ_TIME_LIMIT_S);
    }

    public function testOcrRaisesTheTimeLimitAboveTheOcrTimeout(): void
    {
        $src = $this->source('AttachmentController.php');
        self::assertMatchesRegularExpression('/set_time_limit\((\d+)\)/', $src);
        preg_match('/set_time_limit\((\d+)\)/', $src, $m);
        self::assertGreaterThan(300, (int)$m[1], 'ocr_timeout_s darf bis 300 s, dazu Puffer');
    }
}
