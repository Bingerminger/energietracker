<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.1.0 (Paket H1, API-39) — Vorlagen für Unraid, CasaOS und Umbrel.
 *
 * Drei Kopien derselben Angaben (Image, Port, Datenpfad, Umgebungsvariablen)
 * neben docker-compose.yml und dem Dockerfile: Ohne Prüfung läuft eine davon
 * beim nächsten Release still auseinander.
 */
final class DeployTemplatesTest extends TestCase
{
    private const IMAGE = 'ghcr.io/bingerminger/energietracker';

    private static function file(string $rel): string
    {
        $p = dirname(__DIR__, 3) . "/$rel";
        self::assertFileExists($p);
        return (string)file_get_contents($p);
    }

    public function testImagePortAndDataPathMatchCompose(): void
    {
        $version = trim(self::file('VERSION'));
        self::assertStringContainsString('EXPOSE 80', self::file('Dockerfile'));
        $compose = self::file('docker-compose.yml');
        self::assertMatchesRegularExpression('#image: ' . preg_quote(self::IMAGE, '#') . ':\d+\.\d+\.\d+#', $compose);
        self::assertStringContainsString(':/data', $compose);

        $unraid = self::file('deploy/unraid/energietracker.xml');
        self::assertNotFalse(simplexml_load_string($unraid), 'Unraid-Vorlage ist gültiges XML');
        self::assertStringContainsString('<Repository>' . self::IMAGE . ':latest</Repository>', $unraid);
        self::assertStringContainsString('Target="80"', $unraid);
        self::assertStringContainsString('Target="/data"', $unraid);

        foreach (['deploy/casaos/docker-compose.yml', 'deploy/umbrel/docker-compose.yml'] as $rel) {
            $c = self::file($rel);
            self::assertStringContainsString('image: ' . self::IMAGE . ":$version", $c, "$rel: Image-Version = VERSION");
            self::assertMatchesRegularExpression('#(target: /data|:/data)#', $c, "$rel: Datenpfad /data");
        }
        self::assertStringContainsString("version: \"$version\"", self::file('deploy/casaos/docker-compose.yml'));
        self::assertStringContainsString("version: \"$version\"", self::file('deploy/umbrel/umbrel-app.yml'));
        self::assertStringContainsString('target: 80', self::file('deploy/casaos/docker-compose.yml'));
        self::assertStringContainsString('APP_PORT: 80', self::file('deploy/umbrel/docker-compose.yml'));
    }

    /** Jede Umgebungsvariable der Unraid-Vorlage gibt es im Code. */
    public function testUnraidVariablesExist(): void
    {
        preg_match_all('/Target="(ET_[A-Z_]+)"/', self::file('deploy/unraid/energietracker.xml'), $m);
        self::assertNotEmpty($m[1]);
        $code = self::file('src/bootstrap.php') . self::file('index.php') . self::file('docker/entrypoint.sh');
        foreach (glob(dirname(__DIR__, 3) . '/src/{Services,Logging,Http}/*.php', GLOB_BRACE) ?: [] as $f) $code .= file_get_contents($f);
        foreach ($m[1] as $var) self::assertStringContainsString($var, $code, "$var wird nirgends gelesen");
    }
}
