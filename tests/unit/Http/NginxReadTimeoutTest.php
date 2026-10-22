<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Controllers\InboxController;
use PHPUnit\Framework\TestCase;

/**
 * v3.7.0 — Erkennen und Auslesen im Posteingang warten auf das KI-Modell, ein
 * Cloud-Dienst bis 600 s. PHP hebt seine Grenze dafür auf
 * InboxController::READ_TIME_LIMIT_S; wartete nginx im Docker-Image kürzer
 * (bis dahin überall 310 s), endete ein langsames Auslesen mit `504`, während
 * PHP noch rechnete. Der Test bindet die Zeit in `docker/nginx.conf` an die
 * Konstante und die Location an die Routen, die tatsächlich auslesen — unter
 * /api/… und /api.php/api/…. Alle übrigen Routen bleiben bei 310 s.
 */
final class NginxReadTimeoutTest extends TestCase
{
    private const DEFAULT_TIMEOUT_S = 310;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<array{pattern:string, body:string}> Regex-Locations aus nginx.conf */
    private static function locations(): array
    {
        $conf = (string)file_get_contents(self::root() . '/docker/nginx.conf');
        preg_match_all('#^\s*location ~\*? (\S+) \{(.*?)^\s*\}#ms', $conf, $m, PREG_SET_ORDER);
        return array_map(fn($x) => ['pattern' => $x[1], 'body' => $x[2]], $m);
    }

    /** Die Routen, deren Methode im InboxController über read() ausliest. @return list<string> */
    private static function readRoutes(): array
    {
        $ctrl = (string)file_get_contents(self::root() . '/src/Controllers/InboxController.php');
        preg_match_all('#public function (\w+)\(Request \$req\): never\s*\{\s*\$this->read\(#', $ctrl, $mm);
        self::assertNotEmpty($mm[1], 'Methoden mit read() nicht gefunden');
        $src = (string)file_get_contents(self::root() . '/src/bootstrap.php');
        $routes = [];
        foreach ($mm[1] as $method) {
            preg_match_all('#\$r->(?:post|get|patch|put)\(\'([^\']+)\',\s*fn\(\$req\) => \$inCtrl->' . $method . '\(#', $src, $r);
            foreach ($r[1] as $route) $routes[] = $route;
        }
        return $routes;
    }

    public function testInboxReadRoutesWaitAsLongAsPhp(): void
    {
        $limit = InboxController::READ_TIME_LIMIT_S;
        $long = array_values(array_filter(self::locations(), fn($l) => preg_match('/fastcgi_read_timeout (\d+)s;/', $l['body'], $t) && (int)$t[1] === $limit));
        self::assertCount(1, $long, "genau eine Location mit fastcgi_read_timeout {$limit}s (InboxController::READ_TIME_LIMIT_S)");
        $re = '#' . $long[0]['pattern'] . '#';

        $reads = self::readRoutes();
        sort($reads);
        self::assertSame(['/api/inbox/{id}/classify', '/api/inbox/{id}/extract'], $reads, 'Erkennen und Auslesen');

        $src = (string)file_get_contents(self::root() . '/src/bootstrap.php');
        preg_match_all('/\$r->(?:get|post|put|patch|delete)\(\'([^\']+)\'/', $src, $all);
        self::assertGreaterThan(50, count($all[1]));
        foreach (array_unique($all[1]) as $route) {
            $path = preg_replace('/\{[a-zA-Z_]+\}/', 'x1', $route);
            $isRead = in_array($route, $reads, true);
            foreach ([$path, '/api.php' . $path] as $uri) {
                self::assertSame($isRead, (bool)preg_match($re, $uri), "$uri: " . ($isRead ? "wartet nicht {$limit} s" : "wartet {$limit} s, soll bei " . self::DEFAULT_TIMEOUT_S . ' s bleiben'));
            }
        }
    }

    public function testAllOtherPhpLocationsKeepTheDefault(): void
    {
        $conf = (string)file_get_contents(self::root() . '/docker/nginx.conf');
        preg_match_all('/fastcgi_read_timeout (\d+)s;/', $conf, $t);
        $values = array_map('intval', $t[1]);
        sort($values);
        self::assertSame([self::DEFAULT_TIMEOUT_S, self::DEFAULT_TIMEOUT_S, InboxController::READ_TIME_LIMIT_S], $values,
            'große Körper und API 310 s, nur das Auslesen länger');
        self::assertGreaterThan(300, self::DEFAULT_TIMEOUT_S, 'Texterkennung (ocr_timeout_s) bis 300 s');
        // Die Location für das Auslesen steht vor der allgemeinen API-Regel (nginx nimmt die erste passende)
        $patterns = array_column(self::locations(), 'pattern');
        $read = array_search('^/api(\.php/api)?/inbox/[^/]+/(classify|extract)$', $patterns, true);
        $api = array_search('^/api(\.php)?(/|$)', $patterns, true);
        self::assertIsInt($read, 'Location für das Auslesen fehlt');
        self::assertIsInt($api, 'allgemeine API-Location fehlt');
        self::assertLessThan($api, $read, 'Auslesen vor der allgemeinen API-Regel');
    }

    public function testTheOperationsDocsNameTheReadTimeout(): void
    {
        $s = (string)InboxController::READ_TIME_LIMIT_S;
        foreach (['docs/betrieb/webserver.md', 'docs/en/betrieb/webserver.md'] as $rel) {
            $doc = (string)file_get_contents(self::root() . '/' . $rel);
            self::assertStringContainsString('location ~ ^/api(\.php/api)?/inbox/[^/]+/(classify|extract)$ {', $doc, "$rel: nginx-Beispiel ohne die Location");
            self::assertStringContainsString("fastcgi_read_timeout {$s}s;", $doc, "$rel: nginx-Beispiel ohne {$s} s");
            self::assertStringContainsString("`{$s}s` ", $doc, "$rel: Tabelle ohne {$s} s");
        }
        foreach (['docs/betrieb/docker.md' => "{$s} Sekunden", 'docs/en/betrieb/docker.md' => "{$s} seconds"] as $rel => $phrase) {
            self::assertStringContainsString($phrase, (string)file_get_contents(self::root() . '/' . $rel), "$rel nennt die Wartezeit beim Auslesen nicht");
        }
    }
}
