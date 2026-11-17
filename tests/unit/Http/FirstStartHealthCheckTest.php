<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.12.0 — Der Gesundheitscheck legt ein leeres Datenverzeichnis nicht an.
 *
 * Im Container fragt der HEALTHCHECK alle 30 Sekunden `/api/health` ab (unter Home
 * Assistant beobachtet der Supervisor genau diesen), ohne Accept-Language. Kam er vor
 * dem ersten Browser, legte er den Erststart auf Deutsch fest: Sprache, Land, Wetterort
 * und die Namen der ersten Zähler. Jetzt entscheidet der erste andere Aufruf.
 */
#[CoversNothing]
final class FirstStartHealthCheckTest extends HttpServerTestCase
{
    public function testTheHealthCheckLeavesAnEmptyDataDirectoryToTheBrowser(): void
    {
        // die Bereitschaftsprobe von startServer() ist schon der erste Aufruf: /api/health ohne Kopfzeile
        $this->startServer([], [], false);
        $h = json_decode($this->request('GET', '/api/health')['body'], true);
        self::assertSame('ok', $h['data']['status'] ?? $h['status'] ?? null, 'ein leeres Verzeichnis ist gesund, es wartet nur auf den ersten Aufruf');
        self::assertFileDoesNotExist("$this->dataDir/meta.json");
        self::assertFileDoesNotExist("$this->dataDir/gas/meters.json");

        // der Browser: Sprache und Land aus Accept-Language, Zählername in seiner Sprache
        $s = $this->json('GET', '/api/settings', null, ['Accept-Language: fr-CH,fr;q=0.9']);
        self::assertSame(['fr', 'CH', 'CHF', 'Europe/Zurich'], [$s['language'] ?? null, $s['country'] ?? null, $s['currency'] ?? null, $s['timezone'] ?? null]);
        self::assertSame('Compteur principal', $this->json('GET', '/api/utility/gas/meters')[0]['name'] ?? null);
        self::assertTrue($s['setup_pending'] ?? null, 'der Einrichtungsassistent beginnt trotzdem');
    }

    /**
     * v3.12.0 — „*“ heißt „jede Sprache“ und ist keine Sprachangabe. So fragen Programme auf Node-Basis (eingebautes
     * fetch) und `curl -H 'Accept-Language: *'`; bisher legte ein solcher Gesundheitscheck den Erststart auf Deutsch fest.
     */
    public function testAHealthCheckWithAStarIsNoLanguageEither(): void
    {
        $this->startServer([], [], false);
        foreach (['*', ' * ', '*;q=0.5', '*, *;q=0.1', 'de;q=0, *'] as $header) {
            $h = json_decode($this->request('GET', '/api/health', ["Accept-Language: $header"])['body'], true);
            self::assertSame('ok', $h['data']['status'] ?? $h['status'] ?? null, $header);
            self::assertFileDoesNotExist("$this->dataDir/meta.json", "„{$header}“ legt nichts an");
        }
        $s = $this->json('GET', '/api/settings', null, ['Accept-Language: nl-NL,nl;q=0.9']);
        self::assertSame(['nl', 'NL'], [$s['language'] ?? null, $s['country'] ?? null], 'der Browser entscheidet');
    }

    /** Eine echte Sprache neben dem Stern zählt wie bisher: Dann entscheidet auch der Gesundheitscheck. */
    public function testAHealthCheckWithALanguageBesideTheStarStillDecides(): void
    {
        $this->startServer([], [], false);
        $this->request('GET', '/api/health', ['Accept-Language: fr-FR, *;q=0.5']);
        self::assertFileExists("$this->dataDir/meta.json");
        self::assertSame('fr', $this->json('GET', '/api/settings')['language'] ?? null);
    }

    /**
     * v3.13.0 — Ein frisches Datenverzeichnis meldet sich ehrlich: `data_initialized: false` und als Schema das,
     * das der erste Aufruf anlegt. Bis dahin stand dort „?“ und `data_initialized_at: null`, für einen Monitor
     * dasselbe wie eine beschädigte meta.json. Health legt weiterhin nichts an.
     */
    public function testAFreshDataDirectorySaysSoInTheHealthCheck(): void
    {
        $this->startServer([], [], false);
        for ($i = 0; $i < 2; $i++) {   // auch beim zweiten Mal: Health richtet nichts ein
            $h = json_decode($this->request('GET', '/api/health')['body'], true)['data'] ?? [];
            self::assertSame('ok', $h['status'] ?? null);
            self::assertFalse($h['data_initialized'] ?? null, 'noch nicht eingerichtet');
            self::assertSame(\Energietracker\Storage\Migrator::SCHEMA_VERSION, $h['schema_version'] ?? null, 'das Schema, das der erste Aufruf anlegt');
            self::assertSame(0, $h['migrations_pending'] ?? null);
            self::assertTrue(array_key_exists('data_initialized_at', $h) && $h['data_initialized_at'] === null, 'noch kein Zeitpunkt');
            self::assertFileDoesNotExist("$this->dataDir/meta.json");
        }

        $this->json('GET', '/api/settings');
        $h = json_decode($this->request('GET', '/api/health')['body'], true)['data'] ?? [];
        self::assertTrue($h['data_initialized'] ?? null, 'nach dem ersten gewöhnlichen Aufruf eingerichtet');
        self::assertSame(\Energietracker\Storage\Migrator::SCHEMA_VERSION, $h['schema_version'] ?? null);
        self::assertIsString($h['data_initialized_at'] ?? null);
        self::assertFileExists("$this->dataDir/meta.json");
    }

    public function testAnyOtherFirstCallStillStartsTheInstallation(): void
    {
        // ein Skript ohne Accept-Language bekommt wie bisher die deutschen Vorgaben
        $this->startServer([], [], false);
        $meters = $this->json('GET', '/api/utility/gas/meters');
        self::assertSame('Hauptzähler', $meters[0]['name'] ?? null);
        self::assertFileExists("$this->dataDir/meta.json");
        // danach ändert die Kopfzeile nichts mehr
        self::assertSame('de', $this->json('GET', '/api/settings', null, ['Accept-Language: fr-CH'])['language'] ?? null);
    }
}
