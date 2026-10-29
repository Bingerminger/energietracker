<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Storage\Migrator;
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

    /**
     * Das Compose-Beispiel zeigt auf die Hauptversion des aktuellen Release
     * (v3.6.0: `:3` statt einer festen Version — bleibt aktuell ohne Sprung
     * auf eine neue Hauptversion).
     */
    public function testDockerComposePinsCurrentRelease(): void
    {
        $yml = (string)file_get_contents(self::root() . '/docker-compose.yml');
        self::assertSame(
            1, preg_match('#image:\s*ghcr\.io/bingerminger/energietracker:(\S+)#', $yml, $m),
            'docker-compose.yml muss ein gepinntes Image tragen'
        );
        self::assertSame(
            explode('.', self::version())[0], $m[1],
            'docker-compose.yml zeigt nicht auf die Hauptversion von VERSION'
        );
    }

    /**
     * 2026-10-29 — Die Doku nannte das aktuelle Schema an zwei Stellen veraltet (1.6.0 und 1.7.0),
     * weil niemand die Angabe beim Schemaschritt mitzog. Jede solche Angabe unter docs/ (DE + EN)
     * muss Migrator::SCHEMA_VERSION nennen.
     */
    public function testDocsNameTheCurrentSchemaVersion(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/docs'));
        $found = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }
            preg_match_all('/(?:Schema-Stand:|Schema level:|aktuelle Schema \(|current schema \()\s*\*\*(\d+\.\d+\.\d+)\*\*/',
                (string)file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $v) {
                $found[] = substr($file->getPathname(), strlen(self::root()) + 1) . ': ' . $v;
            }
        }
        self::assertGreaterThanOrEqual(6, count($found), 'Schema-Angaben nicht mehr gefunden — Muster anpassen');
        foreach ($found as $hit) {
            self::assertStringEndsWith(': ' . Migrator::SCHEMA_VERSION, $hit, "veraltete Schema-Angabe in $hit");
        }
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

    /**
     * v3.4.0 — Release-Notes sind zweisprachig: Ab 3.4.0 hat jede Version
     * neben dem deutschen auch einen englischen Abschnitt in CHANGELOG.en.md,
     * und zwar für dieselben Versionen.
     */
    public function testEnglishChangelogFollowsTheGermanOneFrom340(): void
    {
        $de = (string)file_get_contents(self::root() . '/CHANGELOG.md');
        $en = (string)file_get_contents(self::root() . '/CHANGELOG.en.md');
        preg_match_all('/^## \[(\d+\.\d+\.\d+)\]/m', $de, $mDe);
        preg_match_all('/^## \[(\d+\.\d+\.\d+)\]/m', $en, $mEn);
        $from340 = array_values(array_filter($mDe[1], fn($v) => version_compare($v, '3.4.0', '>=')));
        self::assertContains(self::version(), $mEn[1], 'CHANGELOG.en.md hat keinen Abschnitt für die aktuelle Version');
        self::assertSame($from340, $mEn[1], 'CHANGELOG.en.md führt ab 3.4.0 nicht dieselben Versionen wie CHANGELOG.md');
    }

    /** Die Versionsstempel der Einstiegsdokumente folgen dem Release. */
    public function testReadmeAndInstallCarryTheCurrentVersion(): void
    {
        // v2.13.0 (Review DOC-29) — auch der Kopf des Doku-Kompendiums
        foreach (['README.md', 'README.de.md', 'INSTALL.md', 'INSTALL.de.md', 'docs/README.md'] as $file) {
            $txt = (string)file_get_contents(self::root() . '/' . $file);
            self::assertStringContainsString(
                self::version(), $txt,
                "$file nennt die aktuelle Version nicht"
            );
        }
    }

    /**
     * v2.3.5 — Die Zahlen-Badges im README müssen stimmen.
     *
     * Ein Badge, der „Tests 194" behauptet, während es 210 sind, ist schlimmer
     * als keiner: Er sieht nach geprüfter Information aus. Dasselbe gilt für
     * die Zahl der Sprachen und der Verbrauchsarten — beides wächst, beides
     * steht an mehreren Stellen.
     *
     * Gezählt wird aus der Wahrheit: Testmethoden aus tests/unit/, Kataloge
     * aus public/locales/, Verbrauchsarten aus der Utilities-SSOT.
     */
    public function testReadmeBadgeNumbersMatchReality(): void
    {
        $root = self::root();

        // Testmethoden: alle `public function testXxx()` unter tests/unit/
        $tests = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/tests/unit', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            $tests += preg_match_all(
                '/^\s*public function test\w+\s*\(/m',
                (string)file_get_contents($f->getPathname())
            );
        }

        $locales = count(array_filter(
            glob($root . '/public/locales/*.json') ?: [],
            fn($p) => basename($p) !== 'languages.json'
        ));

        $utilities = count(\Energietracker\Config\Utilities::all());

        $erwartet = [
            'Tests'          => $tests,
            'Sprachen/langs' => $locales,
            'Verbrauchsarten'=> $utilities,
        ];
        self::assertGreaterThan(0, $tests, 'keine Testmethoden gefunden — Zählung kaputt');

        foreach (['README.md', 'README.de.md'] as $file) {
            $txt = (string)file_get_contents($root . '/' . $file);

            self::assertMatchesRegularExpression(
                '/badge\/Tests-' . $tests . '-/', $txt,
                "$file: Tests-Badge weicht ab (tatsächlich {$tests})"
            );
            self::assertMatchesRegularExpression(
                '/badge\/(languages|Sprachen)-' . $locales . '-/', $txt,
                "$file: Sprachen-Badge weicht ab (tatsächlich {$locales})"
            );
            self::assertMatchesRegularExpression(
                '/badge\/(utilities|Verbrauchsarten)-' . $utilities . '-/', $txt,
                "$file: Verbrauchsarten-Badge weicht ab (tatsächlich {$utilities})"
            );
        }
    }

    /**
     * Die CI-Badges müssen auf Workflows zeigen, die es gibt — sonst zeigt
     * GitHub dauerhaft „no status".
     */
    public function testCiBadgesPointAtExistingWorkflows(): void
    {
        $root = self::root();
        foreach (['README.md', 'README.de.md'] as $file) {
            $txt = (string)file_get_contents($root . '/' . $file);
            preg_match_all('#actions/workflows/([\w.-]+)/badge\.svg#', $txt, $m);
            self::assertNotEmpty($m[1], "$file: kein CI-Badge gefunden");
            foreach (array_unique($m[1]) as $wf) {
                self::assertFileExists(
                    $root . '/.github/workflows/' . $wf,
                    "$file verweist auf einen Workflow, den es nicht gibt: $wf"
                );
            }
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
        // v2.13.0 (Review DOC-29) — rekursiv: PHP-glob kennt kein `**`, das
        // Muster traf nur die Unterordner, nicht app.js, api.js, router.js
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root() . '/public/js', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'js') continue;
            $files[] = substr($f->getPathname(), strlen(self::root()) + 1);
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
                // v3.4.0 (N1019) — keine pauschale Ausnahme mehr für GitHub:
                // Welche Links dorthin erlaubt sind, prüft
                // testFrontendLinksToGithubOnlyForIssuesProjectAndSource.
                // v2.13.0 — ein Link (<a href>) lädt nichts; geprüft bleiben
                // src, url() und <link href> (etwa die Quellenangabe Open-Meteo)
                $withoutLinks = preg_replace('#<a\s[^>]*href\s*=\s*["\']https?://[^"\']*["\']#i', '', $line);
                if (!preg_match('#(?:src|href)\s*=\s*["\']https?://#i', (string)$withoutLinks)
                    && !preg_match('#url\(\s*["\']?https?://#i', (string)$withoutLinks)) {
                    continue;
                }
                $offenders[] = $rel . ':' . ($n + 1) . ' → ' . trim($line);
            }
        }
        self::assertSame([], $offenders,
            "Externe Ressourcen im Frontend:\n" . implode("\n", $offenders));
    }

    /**
     * v3.4.0 (N1019) — Die Doku kommt vom eigenen Server; nach GitHub führen
     * aus dem Frontend nur noch die Fehlermeldung (`/issues`), die
     * Projektseite (Repo-Wurzel, in der Demo auch ihre deutsche Fassung
     * README.de.md als Installationsweg) und der Quellcode der Version
     * (`/tree/v<version>`, AGPL § 13). Bis v3.3 zeigten 13 Stellen auf
     * `blob/main/docs/…` — die Doku einer anderen Version, ohne Internet gar
     * keine.
     *
     * Geprüft wird der Quelltext: jedes Literal der Repo-Adresse und jede
     * Fortsetzung einer Konstante, die sie hält (`${REPO_URL}…`, `${REPO}…`,
     * `${repo}…`).
     */
    public function testFrontendLinksToGithubOnlyForIssuesProjectAndSource(): void
    {
        $files = ['index.php', 'sw.js'];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root() . '/public/js', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'js') $files[] = substr($f->getPathname(), strlen(self::root()) + 1);
        }
        // erlaubte Fortsetzungen hinter der Repo-Adresse — v3.6.0: dazu die
        // Release-Notes einer Version („Was ist neu“, Hinweis auf neue Versionen)
        $allowed = '#^(?:$|/issues(?:$|/)|/tree/v|/releases/tag/v|\#[a-z-]+$|/blob/main/README\.de\.md\#[a-z-]+$)#';
        $offenders = [];
        foreach ($files as $rel) {
            foreach (file(self::root() . '/' . $rel) ?: [] as $n => $line) {
                $suffixes = [];
                if (preg_match_all('#github\.com/Bingerminger/energietracker([^\s\'"`<>)]*)#', $line, $m)) $suffixes = $m[1];
                if (preg_match_all('#\$\{(?:REPO_URL|REPO|repo)\}([^\s\'"`<>)]*)#', $line, $m)) $suffixes = array_merge($suffixes, $m[1]);
                foreach ($suffixes as $s) {
                    if (!preg_match($allowed, $s) || str_contains($s, 'docs')) $offenders[] = "$rel:" . ($n + 1) . " → …energietracker$s";
                }
                if (preg_match('#/blob/[^\s\'"`]*docs/#', $line)) $offenders[] = "$rel:" . ($n + 1) . ' → blob/…/docs';
            }
        }
        self::assertSame([], $offenders, "GitHub-Links außerhalb der Freigabe (Issues, Projekt, Quellcode der Version):\n" . implode("\n", $offenders));
    }

    /**
     * v2.6.0 — Jede registrierte Route steht in der API-Referenz (DE und EN),
     * und die dort genannte Routenzahl stimmt. Review API-18: Die Referenz
     * behauptete „68 Routen, v1.9.2", während 70 existierten; docs/API.md
     * kannte 37. Ein neuer Endpunkt ohne Doku fällt jetzt hier auf.
     */
    public function testEveryRouteIsDocumentedInTheApiReference(): void
    {
        $src = (string)file_get_contents(self::root() . '/src/bootstrap.php');
        preg_match_all('/\$r->(get|post|put|patch|delete)\(\'([^\']+)\'/', $src, $m, PREG_SET_ORDER);
        $routes = array_map(fn($x) => strtoupper($x[1]) . ' ' . str_replace('{utility}', '{u}', $x[2]), $m);
        self::assertGreaterThan(50, count($routes), 'Routen aus bootstrap.php nicht gefunden');

        foreach (['docs/referenz/api.md', 'docs/en/referenz/api.md'] as $rel) {
            $doc = (string)file_get_contents(self::root() . '/' . $rel);
            $documented = [];
            foreach (preg_split('/\R/', $doc) ?: [] as $line) {
                if (preg_match('/^\|\s*\**(GET|POST|PUT|PATCH|DELETE)\**\s*\|\s*\**`([^`]+)`/', $line, $mm)) {
                    $documented[] = $mm[1] . ' ' . $mm[2];
                }
            }
            self::assertSame([], array_values(array_diff($routes, $documented)), "$rel: nicht dokumentierte Routen");
            self::assertSame([], array_values(array_diff($documented, $routes)), "$rel: dokumentierte Routen, die es nicht gibt");
            self::assertMatchesRegularExpression('/\*\*' . count($routes) . ' (Routen|routes)\*\*/', $doc,
                "$rel: Die genannte Routenzahl muss " . count($routes) . ' lauten');
        }
    }

    /**
     * v2.15.0 (Review FE-27) — Die Version von Chart.js steht im Kopf der
     * mitgelieferten Datei; die Architektur-Doku (DE und EN) nennt dieselbe.
     * Bis v2.14 lag 4.4.1 von Dezember 2023 dort, und keine Doku sagte es.
     */
    public function testVendoredChartJsVersionIsDocumented(): void
    {
        $head = (string)file_get_contents(self::root() . '/public/vendor/chart.umd.min.js', false, null, 0, 200);
        self::assertSame(1, preg_match('/Chart\.js v(\d+\.\d+\.\d+)/', $head, $m),
            'public/vendor/chart.umd.min.js trägt keinen Versionskopf');
        foreach (['docs/entwicklung/architektur.md', 'docs/en/entwicklung/architektur.md'] as $rel) {
            self::assertStringContainsString('Chart.js ' . $m[1], (string)file_get_contents(self::root() . '/' . $rel),
                "$rel nennt nicht die mitgelieferte Chart.js-Version {$m[1]}");
        }
    }
}
