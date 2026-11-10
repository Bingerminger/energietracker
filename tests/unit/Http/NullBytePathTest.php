<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.12.0 — Härtung: ein Null-Byte in der Adresse ist 404, nie eine
 * Fehlerausgabe.
 *
 * Der Entwicklungsserver (`php -S … router.php`) gab bis v3.11 bei `%00` im Pfad
 * den PHP-Fehler samt Stacktrace und absolutem Pfad aus, weil `realpath()` vor
 * der App lief. Der Testserver läuft hier wie ein von Hand gestarteter: mit
 * `display_errors=1`. Im Docker-Image (nginx) und unter Apache erreicht so eine
 * Anfrage PHP nicht; Abfrageparameter mit Null-Byte dagegen schon — auch sie
 * dürfen keinen Pfad und keinen 500 liefern.
 */
final class NullBytePathTest extends HttpServerTestCase
{
    protected function setUp(): void
    {
        $this->serverIni = ['display_errors' => '1', 'html_errors' => '0'];
        $this->startServer();
    }

    private function assertQuiet(array $r, string $what): void
    {
        $root = dirname(__DIR__, 3);
        self::assertNotSame(500, $r['status'], "$what: Serverfehler");
        foreach (['Fatal error', 'ValueError', 'Stack trace', 'router.php', $root, $this->dataDir] as $leak) {
            self::assertStringNotContainsString($leak, $r['body'], "$what: Antwort verrät „{$leak}“");
        }
    }

    public function testNullByteInThePathIsNotFound(): void
    {
        foreach (['/api/health%00', '/%00', '/public/js/app.js%00', '/sw.js%00.txt', '/api.php/api/health%00',
                  '/api/attachments/att_0000%00', '/api/attachments/x%00y', '/api/backup/snapshots/a%00.json/download',
                  '/index.php%00'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(404, $r['status'], "$path: Status");
            $this->assertQuiet($r, $path);
        }
    }

    public function testNullByteInAQueryParameterReachesNoFile(): void
    {
        foreach (['/api/docs?path=README.md%00', '/api/docs/asset?path=ui%00.png', '/api/backup/snapshots?name=a%00.json',
                  '/api/calendar.ics?token=etk_%00', '/api/settings?lang=de%00'] as $path) {
            $this->assertQuiet($this->request('GET', $path), $path);
        }
    }
}
