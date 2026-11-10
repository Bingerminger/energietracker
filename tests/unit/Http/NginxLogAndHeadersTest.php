<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 — Härtung am nginx des Images (docker/nginx.conf, die Vorlage für
 * eigene nginx-Server):
 *   - Das Zugriffsprotokoll nennt nur den Pfad: Das Kalender-Abo trägt seinen
 *     Schlüssel im Link (`?token=…`), das Standardformat `combined` schrieb ihn
 *     über `$request` in `docker logs`.
 *   - Jede location, die Dateien ausliefert, setzt `X-Content-Type-Options:
 *     nosniff`. Ein add_header in einer location ersetzt die des Servers, eine
 *     einzige Zeile oben reicht deshalb nicht.
 * Im laufenden Container einmal von Hand geprüft (Bericht des Pakets).
 */
final class NginxLogAndHeadersTest extends TestCase
{
    private static function conf(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/docker/nginx.conf');
    }

    /** Konfiguration ohne Kommentare. */
    private static function code(): string
    {
        return (string)preg_replace('/^\s*#.*$/m', '', self::conf());
    }

    public function testAccessLogNamesThePathButNeverTheQuery(): void
    {
        $c = self::code();
        self::assertSame(1, preg_match('/^\s*access_log\s+\S+\s+(\w+);/m', $c, $m), 'access_log mit eigenem Format');
        $format = $m[1];
        self::assertSame(1, preg_match('/log_format\s+' . $format . '\s+((?:\'[^\']*\'\s*)+);/', $c, $f), "log_format $format fehlt");
        // $request (Anfragezeile), $request_uri, $args, $query_string und $arg_* tragen die Abfrage
        self::assertSame(0, preg_match('/\$(request|request_uri|args|query_string|arg_\w+)\b(?!_)/', $f[1]),
            "log_format $format enthält die Abfrage");
        // die Pfad-Variable kommt aus einer map, die an `?` abschneidet
        self::assertSame(1, preg_match('/map \$request_uri \$(\w+) \{\s*"([^"]+)"\s+"([^"]+)";\s*default\s+\$request_uri;/', $c, $map), 'map fehlt');
        self::assertStringContainsString('$' . $map[1], $f[1]);
        $regex = '#' . substr($map[2], 1) . '#';   // nginx-Regex ohne die Tilde
        $key = 'etk_' . str_repeat('7d', 24);
        foreach (["/api/calendar.ics?token=$key" => '/api/calendar.ics?...', "/api.php/api/calendar.ics?person=p_1&token=$key" => '/api.php/api/calendar.ics?...'] as $uri => $logged) {
            self::assertSame(1, preg_match($regex, $uri, $cap), $uri);
            self::assertSame($logged, str_replace('${et_path}', $cap['et_path'], $map[3]), $uri);
        }
        self::assertSame(0, preg_match($regex, '/api/health'), 'ohne Abfrage bleibt die URI (default)');
    }

    public function testEveryLocationThatServesFilesSetsNosniff(): void
    {
        $c = self::code();
        preg_match_all('/location\s+([^{]+)\{((?:[^{}]|\{[^{}]*\})*)\}/', $c, $m, PREG_SET_ORDER);
        $files = 0;
        foreach ($m as [, $where, $body]) {
            if (!str_contains($body, 'try_files $uri')) continue;
            $files++;
            self::assertSame(1, preg_match('/add_header\s+X-Content-Type-Options\s+"nosniff"\s+always;/', $body),
                'location ' . trim($where) . ': nosniff fehlt');
        }
        self::assertGreaterThanOrEqual(3, $files, 'locations mit Dateien nicht gefunden');
    }
}
