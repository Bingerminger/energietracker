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
        self::assertStringContainsString('image: ' . self::IMAGE . ':' . explode('.', $version)[0] . "\n", $compose, 'Compose-Beispiel auf der Hauptversion');
        self::assertStringContainsString(':/data', $compose);

        // v3.6.0 — die Hauptversion gibt es als Tag (auch ohne root), sonst zeigten die Beispiele ins Leere
        $publish = self::file('.github/workflows/docker-publish.yml');
        self::assertStringContainsString("type=semver,pattern={{major}}\n", $publish, 'Tag :3');
        self::assertStringContainsString('type=semver,pattern={{major}},suffix=-rootless', $publish, 'Tag :3-rootless');

        $unraid = self::file('deploy/unraid/energietracker.xml');
        self::assertNotFalse(simplexml_load_string($unraid), 'Unraid-Vorlage ist gültiges XML');
        self::assertStringContainsString('<Repository>' . self::IMAGE . ':' . explode('.', $version)[0] . '</Repository>', $unraid, 'Unraid auf der Hauptversion');
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

    /**
     * v3.5.0 — Format der Unraid-Vorlage (Docker-Tab von Unraid / Community
     * Applications): Pflichtfelder, Config-Attribute in der Form, die der
     * Docker-Tab selbst schreibt (Abweichungen lassen CA eine Vorlage sperren),
     * WebUI auf den Container-Port, Verweise auf Dateien, die es im Repo gibt.
     */
    public function testUnraidTemplateFollowsTheDockerManFormat(): void
    {
        $raw = self::file('deploy/unraid/energietracker.xml');
        $xml = simplexml_load_string($raw);
        self::assertNotFalse($xml);
        self::assertSame('Container', $xml->getName());
        self::assertSame('2', (string)$xml['version']);
        foreach (['Name', 'Repository', 'Registry', 'Network', 'Shell', 'Privileged', 'Support', 'Project',
                  'Overview', 'Category', 'WebUI', 'TemplateURL', 'Icon'] as $el) {
            self::assertNotSame('', trim((string)$xml->$el), "<$el> fehlt oder ist leer");
        }
        self::assertSame('bridge', (string)$xml->Network);
        self::assertSame('false', (string)$xml->Privileged);
        self::assertMatchesRegularExpression('/^(?:[A-Za-z]+:[A-Za-z]*)(?: [A-Za-z]+:[A-Za-z]*)*$/', (string)$xml->Category,
            'Kategorien wie „HomeAutomation: Productivity:“ bzw. „Tools:Utilities“');

        $ports = [];
        foreach ($xml->Config as $c) {
            $a = $c->attributes();
            foreach (['Name', 'Target', 'Default', 'Mode', 'Description', 'Type', 'Display', 'Required', 'Mask'] as $attr) {
                self::assertNotNull($a[$attr], "Config {$a['Name']}: Attribut $attr fehlt");
            }
            $type = (string)$a['Type'];
            self::assertContains($type, ['Port', 'Path', 'Variable', 'Device', 'Label'], "Config {$a['Name']}: Typ");
            self::assertContains((string)$a['Display'], ['always', 'advanced', 'always-hide', 'advanced-hide']);
            self::assertContains((string)$a['Required'], ['true', 'false']);
            self::assertContains((string)$a['Mask'], ['true', 'false']);
            self::assertSame((string)$a['Default'], (string)$c, "Config {$a['Name']}: Wert = Vorgabe");
            if ($type === 'Port') { self::assertContains((string)$a['Mode'], ['tcp', 'udp']); $ports[] = (string)$a['Target']; }
            if ($type === 'Path') self::assertContains((string)$a['Mode'], ['rw', 'ro', 'rw,slave', 'ro,slave']);
            if ($type === 'Variable') self::assertSame('', (string)$a['Mode']);
        }
        self::assertDoesNotMatchRegularExpression('/<Config [^>]*\s{2}/', $raw, 'Config-Attribute mit einfachem Abstand (Docker-Tab-Format)');
        self::assertSame(['80'], $ports, 'ein Port: der des Standard-Images');
        self::assertSame('http://[IP]:[PORT:' . $ports[0] . ']/', (string)$xml->WebUI, 'WebUI zeigt auf den Container-Port');

        // Verweise auf das Repo zeigen auf Dateien, die es gibt (Icon, Bilder, Doku, Lizenz, Vorlage selbst)
        $root = dirname(__DIR__, 3);
        $urls = [(string)$xml->Icon, (string)$xml->TemplateURL, (string)$xml->ReadMe, (string)$xml->License];
        foreach ($xml->Screenshot as $s) $urls[] = (string)$s;
        foreach ($urls as $u) {
            self::assertSame(1, preg_match('#^https://(?:raw\.githubusercontent\.com/Bingerminger/energietracker/main|github\.com/Bingerminger/energietracker/blob/main)/(.+)$#', $u, $m), "Verweis außerhalb des Repos: $u");
            self::assertFileExists("$root/{$m[1]}", $u);
        }
        self::assertStringEndsWith('.png', (string)$xml->Icon);
        self::assertSame('https://raw.githubusercontent.com/Bingerminger/energietracker/main/deploy/unraid/energietracker.xml', (string)$xml->TemplateURL);
    }

    /**
     * v3.5.0 — Jede Umgebungsvariable aus der Tabelle in docs/betrieb/docker.md
     * steht in der Unraid-Vorlage — außer den bewusst ausgelassenen. Bis v3.4
     * fehlte dort ET_PROXY_USER_HEADER, das die Doku für jeden Proxy empfiehlt.
     */
    public function testUnraidTemplateOffersEveryDocumentedVariable(): void
    {
        $skip = [
            'ET_DATA_DIR'  => 'den Datenpfad legt die Vorlage über /data fest',
            'ET_LOG_FILE'  => 'nur mit ET_LOG_DEST=file; Vorgabe liegt im Datenordner',
            'ET_DEBUG'     => 'nur kurz zur Fehlersuche, nie dauerhaft in einer Vorlage',
        ];
        preg_match_all('/^\| `(ET_[A-Z_]+)` \|/m', self::file('docs/betrieb/docker.md'), $doc);
        self::assertGreaterThanOrEqual(10, count($doc[1]));
        preg_match_all('/Target="(ET_[A-Z_]+)"/', self::file('deploy/unraid/energietracker.xml'), $tpl);
        $expected = array_values(array_diff($doc[1], array_keys($skip)));
        sort($expected);
        $actual = $tpl[1];
        sort($actual);
        self::assertSame($expected, $actual, 'Vorlage und Doku-Tabelle bieten dieselben Variablen');
        preg_match_all('/^\| `(ET_[A-Z_]+)` \|/m', self::file('docs/en/betrieb/docker.md'), $en);
        self::assertSame($doc[1], $en[1], 'EN-Tabelle wie DE');
    }

    /** Jede Umgebungsvariable der Unraid-Vorlage gibt es im Code. */
    public function testUnraidVariablesExist(): void
    {
        preg_match_all('/Target="(ET_[A-Z_]+)"/', self::file('deploy/unraid/energietracker.xml'), $m);
        self::assertNotEmpty($m[1]);
        $code = self::file('src/bootstrap.php') . self::file('index.php') . self::file('docker/entrypoint.sh');
        foreach (glob(dirname(__DIR__, 3) . '/src/{Services,Logging,Http,Storage}/*.php', GLOB_BRACE) ?: [] as $f) $code .= file_get_contents($f);
        foreach ($m[1] as $var) self::assertStringContainsString($var, $code, "$var wird nirgends gelesen");
    }
}
