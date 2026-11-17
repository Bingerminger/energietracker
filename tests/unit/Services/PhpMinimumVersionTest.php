<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\DiagnosticsService;
use Energietracker\Services\SettingsService;
use Energietracker\Storage\JsonStore;
use PHPUnit\Framework\TestCase;

/**
 * v3.13.0 — Jede Stelle, die die Mindestversion von PHP nennt, nennt die aus
 * composer.json (seit v3.13.0 8.4; 8.3 hing in der CI selten in einer
 * C-Funktion von PHP selbst). Bis v3.12 hingen README, INSTALL, Doku und der
 * Wegweiser in sieben Sprachen nur an der Sorgfalt beim Umstellen.
 *
 * Historische Angaben („8.3 in v3.12“) bleiben erlaubt: Geprüft werden nur die
 * Sätze, die eine Mindestversion aussprechen, und dass dort keine andere steht.
 */
final class PhpMinimumVersionTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function file(string $rel): string
    {
        return (string)file_get_contents(self::root() . '/' . $rel);
    }

    private static function minimum(): string
    {
        $composer = json_decode(self::file('composer.json'), true);
        self::assertSame(1, preg_match('/^>=(\d+\.\d+)$/', (string)($composer['require']['php'] ?? ''), $m), 'composer.json: require.php als >=X.Y');
        return $m[1];
    }

    public function testEveryStatedMinimumIsTheOneFromComposer(): void
    {
        $min = self::minimum();
        $v = '(\d+\.\d+)';
        // Datei => Muster, die je eine Mindestversion aussprechen (mindestens einmal vorhanden)
        $spots = [
            'README.md'                       => ["PHP $v or later is enough"],
            'README.de.md'                    => ["genügt PHP ab $v"],
            'INSTALL.md'                      => ["PHP ≥ $v"],
            'INSTALL.de.md'                   => ["PHP ≥ $v"],
            'CONTRIBUTING.md'                 => ["PHP $v or newer is enough"],
            'CONTRIBUTING.de.md'              => ["PHP ab $v genügt"],
            'AGENTS.md'                       => ["PHP $v or newer"],
            'docs/betrieb/webserver.md'       => ["Webserver mit PHP $v oder neuer", "- PHP $v oder neuer", "PHP-Profil ab $v"],
            'docs/en/betrieb/webserver.md'    => ["web server with PHP $v or newer", "- PHP $v or newer", "PHP profile from $v"],
            'docs/betrieb/installation.md'    => ["\| PHP \| $v \|"],
            'docs/en/betrieb/installation.md' => ["\| PHP \| $v \|"],
            'docs/einstieg/wegweiser.md'      => ["Webserver mit PHP ab $v"],
            'docs/en/einstieg/wegweiser.md'   => ["web server with PHP $v or later"],
            'docs/einstieg/funktionen.md'     => ["PHP ab $v und flache"],
            'docs/en/einstieg/funktionen.md'  => ["PHP $v or newer and plain"],
            // v3.13.0 (Paket K2e) — der Satz zur CI nennt die Mindestversion als erste geprüfte (bis v3.12 stand dort noch 8.2)
            'docs/entwicklung/tests.md'       => ["je unter PHP $v und \d+\.\d+: der Mindestversion"],
            'docs/en/entwicklung/tests.md'    => ["each run under PHP $v and \d+\.\d+: the minimum version"],
        ];
        foreach ($spots as $rel => $patterns) {
            $text = preg_replace('/\s+/', ' ', self::file($rel));
            foreach ($patterns as $p) {
                $n = preg_match_all('/' . str_replace('/', '\/', $p) . '/u', $text, $m);
                self::assertGreaterThan(0, $n, "$rel: Satz mit der Mindestversion fehlt ($p)");
                foreach ($m[1] as $found) self::assertSame($min, $found, "$rel: nennt $found als Mindestversion, composer.json $min");
            }
        }
        // Wegweiser in der App: dieselbe Zahl in allen sieben Sprachen
        foreach (['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'] as $l) {
            $cat = json_decode(self::file("public/locales/$l.json"), true);
            $text = (string)($cat['setups']['scene']['byHand']['software'] ?? '');
            self::assertSame(1, preg_match('/PHP (?:ab )?(\d+\.\d+)/', $text, $m), "$l: setups.scene.byHand.software nennt PHP");
            self::assertSame($min, $m[1], "$l: Wegweiser nennt PHP {$m[1]}");
        }
    }

    /**
     * v3.13.0 (Paket K1) — Die Diagnose-Seite nennt dieselbe Mindestversion und zeigt darunter einen Hinweis
     * (`php_supported`). Geprüft über eine eingestellte Version, nicht über die Laufzeit des Tests.
     */
    public function testTheDiagnosticsWarnsBelowTheMinimum(): void
    {
        $min = self::minimum();
        self::assertSame($min, DiagnosticsService::PHP_MINIMUM, 'Diagnose: Mindestversion wie composer.json');
        [$major, $minor] = array_map('intval', explode('.', $min));
        self::assertSame($major * 10000 + $minor * 100, DiagnosticsService::PHP_MINIMUM_ID);
        self::assertSame(PHP_VERSION_ID, DiagnosticsService::versionId(PHP_VERSION), 'dieselbe Zählung wie PHP_VERSION_ID');

        $dir = sys_get_temp_dir() . '/et-diag-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $store = new JsonStore($dir);
            $settings = new SettingsService($store);
            $run = fn(string $v) => (new DiagnosticsService($store, $settings, null, $v))->run();
            foreach (['8.3.27' => false, '8.3.99' => false, '7.4.33' => false, '8.4.0' => true, '8.4.12' => true, '8.5.1' => true, '9.0.0' => true] as $v => $ok) {
                $d = $run($v);
                self::assertSame([$v, $min, $ok], [$d['php_version'], $d['php_minimum'], $d['php_supported']], "PHP $v");
            }
        } finally {
            foreach (glob("$dir/*") ?: [] as $f) is_dir($f) ? @rmdir($f) : @unlink($f);
            @rmdir($dir);
        }
    }

    /** Die CI prüft die Mindestversion selbst und keine ältere. */
    public function testTheCiChecksTheMinimumAndNothingOlder(): void
    {
        $min = self::minimum();
        $ci = self::file('.github/workflows/ci.yml');
        preg_match_all('/^\s*php: \[([^\]]+)\]/m', $ci, $m);
        self::assertNotEmpty($m[1], 'Matrix in ci.yml');
        foreach ($m[1] as $list) {
            $versions = array_map(fn($s) => trim($s, " \""), explode(',', $list));
            self::assertContains($min, $versions, 'die Mindestversion steht in der Matrix');
            foreach ($versions as $ver) self::assertTrue(version_compare($ver, $min, '>='), "ci.yml prüft $ver, älter als $min");
        }
    }
}
