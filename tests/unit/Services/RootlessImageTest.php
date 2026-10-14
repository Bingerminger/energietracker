<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use PHPUnit\Framework\TestCase;

/**
 * v3.5.0 (N1024) — Image ohne root als zusätzliche Variante derselben
 * Dockerfile (Ziel `rootless`). Das Standard-Image bleibt, wie es ist.
 *
 * Was am laufenden Container zu prüfen ist (Health, Schreiben, keine UID 0,
 * klare Meldung bei fremdem Datenordner), macht der CI-Job `docker`. Hier
 * steht, dass Dockerfile, Konfiguration, Workflows und Doku zueinander passen —
 * und dass die Ersetzungen, mit denen der Bau die nginx- und supervisord-
 * Konfiguration ableitet, an den Dateien im Repo greifen (sonst bräche der
 * Bau erst in der CI ab).
 */
final class RootlessImageTest extends TestCase
{
    private const UID = '10001';
    private const PORT = '8080';

    private static function file(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        self::assertFileExists($path);
        return (string)file_get_contents($path);
    }

    /** @return array<string,string> Name der Stufe → Anweisungen (ohne Kommentare, Fortsetzungen zusammengezogen) */
    private static function stages(): array
    {
        $src = preg_replace('/^\s*#.*\n/m', '', self::file('Dockerfile'));
        $src = str_replace("\\\n", ' ', (string)$src);
        preg_match_all('/^FROM (\S+) AS (\w+)\n(.*?)(?=^FROM |\z)/ms', $src, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $from, $name, $body]) $out[$name] = "FROM $from\n$body";
        return $out;
    }

    public function testOneDockerfileTwoTargetsStandardStaysTheDefault(): void
    {
        $src = self::file('Dockerfile');
        preg_match_all('/^FROM (\S+) AS (\w+)$/m', $src, $from, PREG_SET_ORDER);
        self::assertSame([['php:8.5-fpm-alpine', 'base'], ['base', 'rootless'], ['base', 'standard']],
            array_map(fn($f) => [$f[1], $f[2]], $from), 'gemeinsame Stufe base; standard zuletzt = Ziel von `docker build .`');
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/Dockerfile.rootless', 'eine Datei, keine zweite');

        $s = self::stages();
        // Standard wie bis v3.4.0
        self::assertStringNotContainsString('USER ', $s['base'] . $s['standard']);
        self::assertStringNotContainsString('ET_IMAGE_VARIANT', $s['base'] . $s['standard']);
        self::assertMatchesRegularExpression('/^EXPOSE 80$/m', $s['standard']);
        self::assertStringContainsString('chown -R www-data:www-data /data', $s['standard']);
        self::assertStringContainsString('wget -qO- http://127.0.0.1/api/health', $s['standard']);
        self::assertStringContainsString('VOLUME ["/data"]', $s['standard']);

        // Ohne root: feste UID, Gruppe 0, Port 8080, eigener Healthcheck
        $r = $s['rootless'];
        self::assertMatchesRegularExpression('/^USER ' . self::UID . ':0$/m', $r);
        self::assertMatchesRegularExpression('/^EXPOSE ' . self::PORT . '$/m', $r);
        self::assertStringNotContainsString('EXPOSE 80' . "\n", $r);
        self::assertStringContainsString('wget -qO- http://127.0.0.1:' . self::PORT . '/api/health', $r);
        self::assertMatchesRegularExpression('/^ENV ET_IMAGE_VARIANT=rootless$/m', $r);
        self::assertStringContainsString('chown ' . self::UID . ':0 /data', $r, '/data gehört der UID des Images, Gruppe 0');
        self::assertStringContainsString('chmod 775 /data', $r, 'g=u: dieselben Rechte für Gruppe 0');
        self::assertStringContainsString('VOLUME ["/data"]', $r);
        self::assertLessThan(strpos($r, 'VOLUME'), strpos($r, 'chown ' . self::UID), 'Rechte vor VOLUME setzen');
    }

    /**
     * Die Konfiguration ohne root entsteht beim Bau aus den Dateien des
     * Standard-Images (sed). Hier dieselben Ersetzungen auf die Dateien im Repo
     * — und die grep-Wachen des Baus müssen danach halten.
     */
    public function testDerivedConfigurationMatchesTheGuardsOfTheBuild(): void
    {
        $s = self::stages();
        // Quelle je Pfad im Image aus den COPY-Zeilen der Stufen base und rootless
        preg_match_all('#^COPY (docker/\S+)\s+(/\S+)$#m', $s['base'] . "\n" . $s['rootless'], $copies, PREG_SET_ORDER);
        $files = [];
        foreach ($copies as [, $src, $dst]) $files[$dst] = self::file($src);
        $original = $files;

        self::assertSame(1, preg_match('/^RUN (sed -i .*)$/m', $s['rootless'], $run), 'Ableitung per sed in der Stufe rootless');
        $cmds = array_map('trim', explode(' && ', $run[1]));
        $seds = 0; $guards = 0;
        foreach ($cmds as $cmd) {
            if (str_starts_with($cmd, 'sed -i ')) {
                preg_match_all("/-e '([^']*)'/", $cmd, $ex);
                $target = (string)substr($cmd, (int)strrpos($cmd, ' ') + 1);
                if (!isset($files[$target])) continue;   // www.conf stammt aus dem Basis-Image
                foreach ($ex[1] as $e) { $files[$target] = self::sed($e, $files[$target]); $seds++; }
            } elseif (preg_match("/^(!\\s+)?grep -q(x?) '([^']*)' (\\S+)$/", $cmd, $g)) {
                self::assertArrayHasKey($g[4], $files, "Wache prüft eine unbekannte Datei: $cmd");
                $re = '/' . ($g[2] === 'x' ? '^' : '') . self::bre($g[3]) . ($g[2] === 'x' ? '$' : '') . '/m';
                $hit = (bool)preg_match($re, $files[$g[4]]);
                self::assertSame($g[1] === '', $hit, "Wache des Baus hielte nicht: $cmd");
                $guards++;
            }
        }
        self::assertSame(6, $seds, 'drei Ersetzungen in nginx.conf, drei in supervisord.conf');
        self::assertGreaterThanOrEqual(7, $guards);

        $nginx = $files['/etc/nginx/nginx.conf'];
        self::assertSame(1, preg_match_all('/^\s*listen (\d+) default_server;$/m', $nginx, $l));
        self::assertSame([self::PORT], $l[1], 'nginx hört auf denselben Port wie EXPOSE und Healthcheck');
        self::assertMatchesRegularExpression('#^pid /tmp/[\w.-]+;$#m', $nginx);
        preg_match('#^http \{ include (/etc/nginx/[\w.-]+);$#m', $nginx, $inc);
        self::assertArrayHasKey($inc[1] ?? '', $files, 'die eingebundene Datei kopiert der Bau');
        $diff = array_diff_assoc(explode("\n", $nginx), explode("\n", $original['/etc/nginx/nginx.conf']));
        self::assertCount(3, $diff, 'nur PID, include und listen weichen ab — die Sperrregeln sind dieselben');

        preg_match_all('/^(\w+)_temp_path\s+(\S+);$/m', $files[$inc[1]], $tmp, PREG_SET_ORDER);
        self::assertSame(['client_body', 'fastcgi', 'proxy', 'scgi', 'uwsgi'], array_column($tmp, 1),
            'alle Zwischenspeicher — sonst legt nginx den eingebauten Pfad unter /var/lib/nginx an und startet nicht');
        foreach ($tmp as [, , $p]) self::assertStringStartsWith('/tmp/', $p);

        $sup = $files['/etc/supervisord.conf'];
        self::assertDoesNotMatchRegularExpression('/^user=/m', $sup, 'kein Benutzerwechsel');
        self::assertMatchesRegularExpression('#^pidfile=/tmp/#m', $sup);
        self::assertMatchesRegularExpression('#^command=nginx -e /dev/stderr #m', $sup);
        self::assertMatchesRegularExpression('/^command=php-fpm -F$/m', $sup);
    }

    /** Im Rootless-Zweig des Entrypoints keine root-Schritte; die Weiche ist die Variante. */
    public function testEntrypointHasNoRootStepsInTheRootlessBranch(): void
    {
        $sh = self::file('docker/entrypoint.sh');
        self::assertSame(1, preg_match('/^if \[ "\$\{ET_IMAGE_VARIANT:-\}" = "rootless" \]; then\n(.*?)^fi$/ms', $sh, $m),
            'Weiche über ET_IMAGE_VARIANT (gesetzt nur in der Stufe rootless)');
        $branch = $m[1];
        $cmds = [];
        foreach (explode("\n", $branch) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            foreach (preg_split('/\s*(?:;|&&|\|\||\bthen\b)\s*/', $line) ?: [] as $c) {
                if (preg_match('/^(?:if\s+)?!?\s*([a-z][\w-]*)/', trim($c), $w)) $cmds[] = $w[1];
            }
        }
        foreach (['chown', 'chmod', 'chgrp', 'mkdir', 'su-exec', 'gosu', 'su', 'setpriv', 'install'] as $root) {
            self::assertNotContains($root, $cmds, "Rootless-Zweig ruft $root auf");
        }
        self::assertMatchesRegularExpression('/^\s+exec "\$@"\n$/', substr($branch, (int)strrpos($branch, "\n", -2)),
            'der Zweig endet mit exec — der Standardteil danach läuft nie');
        self::assertStringContainsString('[ ! -w "$data" ]', $branch, 'prüft das Datenverzeichnis');
        self::assertStringContainsString('"level":"error"', $branch, 'meldet im Format des Loggers (JSON-Lines)');
        // Der Standardteil stellt weiter um (unverändert)
        self::assertStringContainsString('chown -R www-data:www-data /data', substr($sh, (int)strpos($sh, "\nfi\n")));
    }

    /** Veröffentlichung: dieselben Plattformen, eigene Tags, `latest` bleibt Standard. */
    public function testPublishBuildsBothTargetsOnTheSamePlatforms(): void
    {
        $wf = self::file('.github/workflows/docker-publish.yml');
        preg_match_all('/^\s+platforms: (\S+)$/m', $wf, $p);
        self::assertCount(2, $p[1], 'zwei Bauschritte');
        self::assertSame($p[1][0], $p[1][1], 'gleiche Plattformen');
        self::assertStringContainsString('linux/arm64', $p[1][0]);
        self::assertSame(1, substr_count($wf, 'target: rootless'));

        self::assertSame(1, preg_match('/id: meta-rootless\n(.*?)\n\n/s', $wf, $meta), 'eigene Tags für die Variante');
        self::assertStringContainsString('latest=false', $meta[1], 'kein automatisches latest für die Variante');
        preg_match_all('/^\s+type=(\S+)$/m', $meta[1], $tags);
        self::assertNotEmpty($tags[1]);
        foreach ($tags[1] as $t) {
            self::assertTrue(str_ends_with($t, 'suffix=-rootless') || $t === 'raw,value=latest-rootless', "Tag ohne Endung: $t");
        }
        self::assertContains('semver,pattern={{version}},suffix=-rootless', $tags[1], '<version>-rootless');
        self::assertContains('raw,value=latest-rootless', $tags[1]);
        self::assertStringContainsString('tags: ${{ steps.meta-rootless.outputs.tags }}', $wf);
    }

    public function testWeeklyRebuildBuildsAndScansBothVariants(): void
    {
        $wf = self::file('.github/workflows/docker-rebuild.yml');
        preg_match_all('/^\s+platforms: (\S+)$/m', $wf, $p);
        self::assertCount(2, $p[1]);
        self::assertSame($p[1][0], $p[1][1], 'gleiche Plattformen');
        self::assertSame(2, substr_count($wf, 'no-cache: true'), 'beide ohne Cache auf dem aktuellen Basis-Image');
        self::assertSame(1, preg_match('/target: rootless\n(.*?)labels:/s', $wf, $b));
        preg_match_all('#energietracker:(.+)$#m', $b[1], $t);
        self::assertSame(['${{ steps.tag.outputs.version }}-rootless', '${{ steps.tag.outputs.minor }}-rootless', 'latest-rootless'], $t[1]);
        self::assertStringContainsString("if: steps.tag.outputs.rootless == 'true'", $wf, 'Releases vor v3.5.0 haben das Ziel nicht');
        // Scan für beide (Matrix), getrennte Kategorien; Standard behält seine
        self::assertStringContainsString('energietracker:${{ needs.rebuild.outputs.version }}${{ matrix.suffix }}', $wf);
        self::assertMatchesRegularExpression('/- variant: standard\n\s+suffix: ""\n\s+category: container-image\n/', $wf);
        self::assertMatchesRegularExpression('/- variant: rootless\n\s+suffix: "-rootless"\n\s+category: container-image-rootless\n/', $wf);
        self::assertStringContainsString('category: ${{ matrix.category }}', $wf);
    }

    /** Die CI startet die Variante so, wie die Doku sie zeigt — und prüft, dass nichts als root läuft. */
    public function testCiStartsTheRootlessImageLikeTheDocsShowIt(): void
    {
        $ci = self::file('.github/workflows/ci.yml');
        self::assertStringContainsString('docker build --target rootless -t energietracker:ci-rootless .', $ci);
        self::assertSame(1, preg_match('/- name: Ohne root \(Beispiel aus der Doku\)\n(.*?)\n\n/s', $ci, $m));
        $step = $m[1];
        self::assertStringNotContainsString('--cap-add', $step, 'ohne root braucht es keine Capability');
        preg_match_all('/--tmpfs (\S+)/', $step, $ciTmpfs);
        self::assertStringContainsString('--cap-drop ALL', $step);
        self::assertStringContainsString('--read-only', $step);
        self::assertStringContainsString('--security-opt no-new-privileges:true', $step);
        self::assertMatchesRegularExpression('/-p 127\.0\.0\.1:\d+:' . self::PORT . ' energietracker:ci-rootless/', $step);
        self::assertStringContainsString('test "$(docker exec et-rootless id -u)" = "' . self::UID . '"', $step);
        self::assertStringContainsString('grep -qx 0 /tmp/uid-list.txt', $step, 'kein Prozess mit UID 0');
        self::assertStringContainsString('"wohnflaeche_m2":92', $step, 'schreibt ins Daten-Volume');
        self::assertStringContainsString('"code":"errors.storage.notWritable"', $ci, 'fremder Datenordner: klare Meldung');
        self::assertStringContainsString('--user "$(id -u):$(id -g)"', $ci, 'eigene UID (Ordner auf dem Host)');
        self::assertMatchesRegularExpression('/--user \d+:0 \\\\\n\s+-v etdata-gid0:\/data/', $ci, 'beliebige UID mit Gruppe 0, Named Volume');

        foreach (['docs/betrieb/docker.md', 'docs/en/betrieb/docker.md'] as $doc) {
            preg_match_all('/```yaml\n(.*?)```/s', self::file($doc), $blocks);
            $rootless = array_values(array_filter($blocks[1], fn($y) => str_contains($y, '-rootless')));
            self::assertCount(1, $rootless, "$doc: Compose-Beispiel ohne root fehlt");
            $y = $rootless[0];
            self::assertMatchesRegularExpression('#image: ghcr\.io/bingerminger/energietracker:X\.Y\.Z-rootless#', $y, $doc);
            self::assertMatchesRegularExpression('/- "(?:[\d.]+:)?\d+:' . self::PORT . '"/', $y, "$doc: Container-Port " . self::PORT);
            self::assertStringContainsString("cap_drop:\n      - ALL", $y, $doc);
            self::assertStringNotContainsString('cap_add', $y, $doc);
            self::assertStringContainsString('read_only: true', $y, $doc);
            self::assertStringContainsString('no-new-privileges:true', $y, $doc);
            preg_match('/tmpfs:\n((?:\s+- \S+\n)+)/', $y, $t);
            preg_match_all('/- (\S+)/', $t[1] ?? '', $docTmpfs);
            self::assertSame($ciTmpfs[1], $docTmpfs[1], "$doc: dieselben tmpfs wie die CI-Probe");
        }
    }

    /** Doku nennt Tags, Port und UID so, wie Dockerfile und Workflow sie setzen. */
    public function testDocsNameTagsPortAndUidOfTheImage(): void
    {
        foreach (['docs/betrieb/docker.md' => 'Ohne root', 'docs/en/betrieb/docker.md' => 'Without root'] as $doc => $title) {
            $md = self::file($doc);
            self::assertSame(1, preg_match('/^## ' . $title . ' \*\(v3\.5\.0\)\*$(.*?)(?=^## )/ms', $md, $sec), "$doc: Abschnitt „{$title}“");
            foreach (['X.Y.Z-rootless', 'X.Y-rootless', 'latest-rootless', 'chown -R ' . self::UID . ':0',
                      'docker build --target rootless', '/api/health', 'data_dir_writable'] as $needle) {
                self::assertStringContainsString($needle, $sec[1], "$doc: $needle");
            }
            self::assertMatchesRegularExpression('/\b' . self::UID . '\b/', $sec[1]);
            self::assertMatchesRegularExpression('/\b' . self::PORT . '\b/', $sec[1]);
        }
        foreach (['docs/betrieb/sicherheit.md' => 'docker.md#ohne-root-v350', 'docs/en/betrieb/sicherheit.md' => 'docker.md#without-root-v350'] as $doc => $link) {
            self::assertStringContainsString($link, self::file($doc), "$doc: Hinweis auf die Variante ohne root");
        }
    }

    /** Die Vorlagen für Unraid, CasaOS und Umbrel bleiben auf dem Standard-Image. */
    public function testDeployTemplatesStayOnTheStandardImage(): void
    {
        foreach (['deploy/unraid/energietracker.xml', 'deploy/casaos/docker-compose.yml', 'deploy/umbrel/docker-compose.yml', 'docker-compose.yml'] as $rel) {
            self::assertStringNotContainsString('rootless', self::file($rel), $rel);
        }
    }

    /** sed-Ausdruck (s|…|…| oder /…/d) wie busybox sed zeilenweise anwenden. */
    private static function sed(string $expr, string $text): string
    {
        if (preg_match('#^/(.*)/d$#', $expr, $d)) {
            return (string)preg_replace('/^' . ltrim(self::bre($d[1]), '^') . '.*\n/m', '', $text);
        }
        self::assertSame(1, preg_match('/^s\|([^|]*)\|([^|]*)\|$/', $expr, $s), "unbekannter sed-Ausdruck: $expr");
        self::assertStringNotContainsString('&', $s[2], 'Ersetzung ohne &');
        $re = '/' . self::bre($s[1]) . '/m';
        self::assertSame(1, preg_match_all($re, $text), "sed-Ausdruck greift nicht genau einmal: $expr");
        return (string)preg_replace($re, str_replace(['\\', '$'], ['\\\\', '\\$'], $s[2]), $text);
    }

    /** einfacher BRE-Ausdruck (nur ^ $ \. und Literale) → PCRE */
    private static function bre(string $bre): string
    {
        $out = '';
        for ($i = 0, $n = strlen($bre); $i < $n; $i++) {
            $c = $bre[$i];
            if ($c === '\\' && $i + 1 < $n) { $out .= preg_quote($bre[++$i], '/'); continue; }
            if (($c === '^' && $i === 0) || ($c === '$' && $i === $n - 1)) { $out .= $c; continue; }
            if ($c === '.') { $out .= '.'; continue; }
            if ($c === '*') { $out .= '*'; continue; }
            $out .= preg_quote($c, '/');
        }
        return $out;
    }
}
