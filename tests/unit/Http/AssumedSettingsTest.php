<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Services\SettingsService;
use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.6.0 — `assumed_keys` in den Einstellungen: Wohnfläche und Personen, die
 * niemand eingetragen hat, tragen nur ihren Default (die Kachel „Einordnung“
 * lädt dann ein, die Einstellungen zeigen das Feld leer). Jede Antwort mit
 * allen Einstellungen führt die Liste (GET, PATCH, Land wechseln), damit der
 * Zwischenspeicher der Oberfläche sie nicht verliert.
 */
final class AssumedSettingsTest extends HttpServerTestCase
{
    public function testAssumedKeysFollowWhatWasEntered(): void
    {
        $this->startServer();
        $s = $this->json('GET', '/api/settings');
        self::assertSame(SettingsService::ENTERED_ONLY, $s['assumed_keys'], 'Neuinstallation');
        self::assertSame([100, 2], [$s['wohnflaeche_m2'], $s['wasser_personen_anzahl']], 'Werte bleiben die Defaults');

        // derselbe Wert wie der Default, eingetragen: keine Annahme mehr
        $p = $this->json('PATCH', '/api/settings', ['wohnflaeche_m2' => 100]);
        self::assertSame(['wasser_personen_anzahl'], $p['assumed_keys']);
        self::assertSame(['wasser_personen_anzahl'], $this->json('GET', '/api/settings')['assumed_keys']);

        // leer gespeichert (so schickt die Seite ein leeres Feld): wieder offen
        self::assertSame(SettingsService::ENTERED_ONLY, $this->json('PATCH', '/api/settings', ['wohnflaeche_m2' => null])['assumed_keys']);
        self::assertArrayNotHasKey('wohnflaeche_m2', (array)json_decode((string)file_get_contents($this->dataDir . '/settings.json'), true));

        // kein Einstellungsschlüssel: zurückgeschickt steht er unter ignored_keys
        $back = $this->json('PATCH', '/api/settings', ['assumed_keys' => []]);
        self::assertSame(['assumed_keys'], $back['ignored_keys']);
        self::assertSame(SettingsService::ENTERED_ONLY, $back['assumed_keys']);

        // Land wechseln antwortet wie PATCH
        $c = $this->json('POST', '/api/settings/country', ['country' => 'AT', 'profile' => true]);
        self::assertSame(['AT', SettingsService::ENTERED_ONLY], [$c['country'], $c['assumed_keys']]);
    }
}
