<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.4.0 (N1020) — Container und Lieferkette: php-fpm nur intern, PHP 8.5 im
 * Image und in der CI, gehärtetes Compose-Beispiel = CI-Probe, wöchentlicher
 * Neubau mit Scan, Fremd-Actions per Commit-SHA, Rechte der Workflows.
 * Die Prüfungen am laufenden Container macht der CI-Job `docker`; hier steht,
 * dass Dateien und Doku zueinander passen.
 */
final class ContainerHardeningTest extends TestCase
{
    private static function file(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        self::assertFileExists($path);
        return (string)file_get_contents($path);
    }

    public function testPhpFpmListensOnLoopbackOnly(): void
    {
        $docker = self::file('Dockerfile');
        self::assertSame(1, preg_match('#COPY docker/(\S+) /usr/local/etc/php-fpm\.d/(zzz-[a-z-]+\.conf)#', $docker, $m),
            'eigene Pool-Datei fehlt im Image');
        self::assertGreaterThan(0, strcmp($m[2], 'zz-docker.conf'), 'lädt nach zz-docker.conf des Basis-Images (listen = 9000)');
        $pool = self::file('docker/' . $m[1]);
        self::assertMatchesRegularExpression('/^\[www\]$/m', $pool);
        self::assertMatchesRegularExpression('/^listen = 127\.0\.0\.1:9000$/m', $pool);
        self::assertMatchesRegularExpression('/^listen\.allowed_clients = 127\.0\.0\.1$/m', $pool);
        preg_match_all('/fastcgi_pass ([^;]+);/', self::file('docker/nginx.conf'), $pass);
        self::assertNotEmpty($pass[1]);
        self::assertSame(['127.0.0.1:9000'], array_values(array_unique($pass[1])), 'nginx spricht php-fpm nur über Loopback an');
        $ci = self::file('.github/workflows/ci.yml');
        self::assertStringContainsString('Port 9000 nur intern', $ci, 'CI prüft am laufenden Container');
    }

    public function testImageAndCiRunPhp85(): void
    {
        $docker = self::file('Dockerfile');
        // v3.5.0 (N1024) — gemeinsame Stufe `base` für Standard und „ohne root“
        self::assertMatchesRegularExpression('/^FROM php:8\.5-fpm-alpine AS base$/m', $docker);
        self::assertStringContainsString('extension_loaded("Zend OPcache")', $docker, 'OPcache ist in 8.5 eingebaut: nur einschalten, wenn nicht geladen');
        $ci = self::file('.github/workflows/ci.yml');
        self::assertSame(2, substr_count($ci, 'php: ["8.2", "8.3", "8.4", "8.5"]'), 'lint-php und phpunit prüfen 8.2 bis 8.5');
    }

    /** Das gehärtete Beispiel der Doku ist genau das, was die CI startet. */
    public function testHardenedComposeExampleMatchesTheCiProbe(): void
    {
        $ci = self::file('.github/workflows/ci.yml');
        foreach (['--security-opt no-new-privileges:true', '--cap-drop ALL', '--read-only', '--tmpfs /run', '--tmpfs /tmp',
                  '--tmpfs /var/lib/nginx/tmp', '-p 127.0.0.1:8081:80'] as $flag) {
            self::assertStringContainsString($flag, $ci, "CI-Probe ohne $flag");
        }
        preg_match_all('/--cap-add ([A-Z_]+)/', $ci, $caps);
        $caps = array_values(array_unique($caps[1]));
        sort($caps);
        foreach (['docs/betrieb/docker.md', 'docs/en/betrieb/docker.md'] as $doc) {
            preg_match_all('/```yaml\n(.*?)```/s', self::file($doc), $blocks);
            // v3.5.0 — das Beispiel ohne root prüft RootlessImageTest
            $hardened = array_values(array_filter($blocks[1], fn($y) => str_contains($y, 'read_only:') && !str_contains($y, '-rootless')));
            self::assertCount(1, $hardened, "$doc: gehärtetes Compose-Beispiel fehlt");
            $yaml = $hardened[0];
            foreach (['no-new-privileges:true', 'cap_drop:', '- ALL', 'read_only: true', '- /run', '- /tmp', '- /var/lib/nginx/tmp', '"127.0.0.1:8080:80"'] as $needle) {
                self::assertStringContainsString($needle, $yaml, "$doc: $needle");
            }
            preg_match_all('/^\s+- (CHOWN|SETUID|SETGID|DAC_OVERRIDE|NET_BIND_SERVICE|[A-Z_]+)$/m', (string)strstr($yaml, 'cap_add:'), $docCaps);
            $docCaps = array_values(array_unique(array_filter($docCaps[1], fn($c) => $c !== 'ALL')));
            sort($docCaps);
            self::assertSame($caps, $docCaps, "$doc: dieselben Capabilities wie die CI-Probe");
        }
    }

    public function testWeeklyRebuildWithANonBlockingScan(): void
    {
        $wf = self::file('.github/workflows/docker-rebuild.yml');
        self::assertMatchesRegularExpression("/schedule:\n\s+- cron: '[^']+'/", $wf);
        self::assertStringContainsString("git tag --list 'v*' --sort=-version:refname", $wf, 'baut den neuesten Release-Tag');
        self::assertStringContainsString('no-cache: true', $wf, 'ohne Cache: neues Basis-Image samt Paketen');
        self::assertStringContainsString('continue-on-error: true', $wf, 'der Scan blockiert nicht');
        self::assertStringContainsString('upload-sarif', $wf);
        self::assertStringContainsString('security-events: write', $wf);
    }

    /** Fremd-Actions per Commit-SHA (mit Versionskommentar), Rechte ausdrücklich. */
    public function testThirdPartyActionsArePinnedAndPermissionsExplicit(): void
    {
        $dir = dirname(__DIR__, 3) . '/.github/workflows';
        $unpinned = [];
        foreach (glob("$dir/*.yml") ?: [] as $f) {
            $yml = (string)file_get_contents($f);
            self::assertMatchesRegularExpression('/^permissions:\n  contents: read$/m', $yml, basename($f) . ': Rechte oben ausdrücklich');
            preg_match_all('/uses: ([\w.-]+\/[\w.\/-]+)@(\S+)(.*)$/m', $yml, $m, PREG_SET_ORDER);
            foreach ($m as [, $action, $ref, $rest]) {
                if (str_starts_with($action, 'actions/') || str_starts_with($action, 'github/')) continue;   // GitHub selbst
                if (!preg_match('/^[0-9a-f]{40}$/', $ref) || !preg_match('/^\s+#\s+v?\d+\.\d+\.\d+$/', $rest)) {
                    $unpinned[] = basename($f) . ": $action@$ref$rest";
                }
            }
        }
        self::assertSame([], $unpinned, "Fremd-Actions ohne Commit-SHA und Versionskommentar:\n" . implode("\n", $unpinned));
        self::assertMatchesRegularExpression('/package-ecosystem: "composer"/', self::file('.github/dependabot.yml'));
    }
}
