<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v2.2.0 — Release-Paperwork maschinell prüfen.
 *
 * Mehrere Versionsangaben leben außerhalb der Datei VERSION und wurden bisher
 * von Hand nachgezogen. Das ging schief: `docker-compose.yml` pinnte über
 * sieben Releases hinweg noch `1.9.3` — wer die Anwendung aus dem Repository
 * startete, bekam eine Version vor dem gesamten v2.x-Bündel. Und die
 * Cache-Version im Service Worker entscheidet darüber, ob Nutzer nach einem
 * Update überhaupt die neuen Dateien sehen.
 *
 * Diese Prüfungen kosten nichts und fangen genau die Handgriffe ab, die man
 * am Releasetag übersieht.
 */
final class ReleaseConsistencyTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function version(): string
    {
        return trim((string)file_get_contents(self::root() . '/VERSION'));
    }

    public function testVersionFileIsSemver(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/', self::version(),
            'VERSION muss eine reine SemVer-Nummer enthalten'
        );
    }

    /** Der Service-Worker-Cache muss beim Release mitwandern. */
    public function testServiceWorkerCacheVersionMatchesRelease(): void
    {
        $sw = (string)file_get_contents(self::root() . '/sw.js');
        self::assertSame(
            1, preg_match("/const VERSION = 'v([^']+)'/", $sw, $m),
            'sw.js muss eine VERSION-Konstante tragen'
        );
        self::assertSame(
            self::version(), $m[1],
            'sw.js: Cache-Version weicht von VERSION ab — nach dem Update ' .
            'behalten Browser die alten Dateien'
        );
    }

    /** Das gepinnte Image im Compose-File zeigt auf das aktuelle Release. */
    public function testDockerComposePinsCurrentRelease(): void
    {
        $yml = (string)file_get_contents(self::root() . '/docker-compose.yml');
        self::assertSame(
            1, preg_match('#image:\s*ghcr\.io/bingerminger/energietracker:(\S+)#', $yml, $m),
            'docker-compose.yml muss ein gepinntes Image tragen'
        );
        self::assertSame(
            self::version(), $m[1],
            'docker-compose.yml pinnt eine andere Version als VERSION'
        );
    }

    /** Der CHANGELOG führt das aktuelle Release. */
    public function testChangelogHasAnEntryForTheCurrentVersion(): void
    {
        $log = (string)file_get_contents(self::root() . '/CHANGELOG.md');
        self::assertStringContainsString(
            '## [' . self::version() . ']', $log,
            'CHANGELOG.md hat keinen Abschnitt für die aktuelle Version'
        );
    }

    /** Die Versionsstempel der Einstiegsdokumente folgen dem Release. */
    public function testReadmeAndInstallCarryTheCurrentVersion(): void
    {
        foreach (['README.md', 'README.de.md', 'INSTALL.md', 'INSTALL.de.md'] as $file) {
            $txt = (string)file_get_contents(self::root() . '/' . $file);
            self::assertStringContainsString(
                self::version(), $txt,
                "$file nennt die aktuelle Version nicht"
            );
        }
    }

    /**
     * Kein Aufruf an fremde Server im ausgelieferten Frontend. Schriften und
     * Chart.js liegen seit v2.2.0 unter public/vendor/; ein versehentlich
     * wieder eingefügter CDN-Verweis würde die Anwendung offline zerlegen und
     * die IP der Nutzer an Dritte geben.
     */
    public function testFrontendHasNoExternalResourceReferences(): void
    {
        $files = ['index.php', 'sw.js', 'manifest.webmanifest'];
        foreach (glob(self::root() . '/public/js/**/*.js') ?: [] as $f) {
            $files[] = substr($f, strlen(self::root()) + 1);
        }
        foreach (glob(self::root() . '/public/css/*.css') ?: [] as $f) {
            $files[] = substr($f, strlen(self::root()) + 1);
        }

        $offenders = [];
        foreach ($files as $rel) {
            $path = self::root() . '/' . $rel;
            if (!is_file($path)) continue;
            foreach (file($path) ?: [] as $n => $line) {
                if (!preg_match('#(?:src|href)\s*=\s*["\']https?://#i', $line)
                    && !preg_match('#url\(\s*["\']?https?://#i', $line)) {
                    continue;
                }
                // Der Projekt-Link in der Fußzeile ist ein Verweis, keine
                // geladene Ressource.
                if (str_contains($line, 'github.com/Bingerminger')) continue;
                $offenders[] = $rel . ':' . ($n + 1) . ' → ' . trim($line);
            }
        }
        self::assertSame([], $offenders,
            "Externe Ressourcen im Frontend:\n" . implode("\n", $offenders));
    }
}
