<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.13.0 (Paket K1, offene Frage aus Paket N) — Schritt 6 der Home-Assistant-Anleitung beschreibt die Umleitung
 * neutral („leitet Home Assistant die Adresse weiter, etwa …“). Die Beobachtung „Home Assistant OS 18.4 antwortet auf
 * Port 80 und leitet :8123 um“ stammt nur aus der VM der Farm und ist keine Aussage über alle Installationen; die
 * Meldung der App („Home Assistant leitet auf … um“) bleibt.
 */
final class HomeAssistantRedirectDocsTest extends TestCase
{
    public function testStepSixDescribesTheRedirectWithoutAClaimAboutAllInstallations(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docs/anleitungen/home-assistant.md' => ['Leitet Home Assistant um?', 'Leitet Home Assistant die eingetragene Adresse weiter, etwa von'],
                  'docs/en/anleitungen/home-assistant.md' => ['Does Home Assistant redirect?', 'If Home Assistant redirects the address you entered, for example from']] as $rel => [$head, $neutral]) {
            $text = (string)preg_replace('/\s+/u', ' ', (string)file_get_contents("$root/$rel"));
            self::assertStringContainsString($head, $text, $rel);
            self::assertStringContainsString($neutral, $text, "$rel: neutral mit Beispiel");
            foreach (preg_split('/(?<=[.!?])\s+/u', $text) ?: [] as $sentence) {
                self::assertFalse(str_contains($sentence, '18.4') && preg_match('/[Pp]ort 80|:8123/u', $sentence) === 1,
                    "$rel: Aussage über eine Version und den Port: $sentence");
            }
        }
    }
}
