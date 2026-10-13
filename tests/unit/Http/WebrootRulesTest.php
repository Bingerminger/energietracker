<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * v3.1.0 — Jedes Verzeichnis und jede Datei im Webroot ist entweder ausdrücklich
 * zur Auslieferung gedacht oder gesperrt.
 *
 * Anlass: v3.0.0 brachte das Verzeichnis tools/ (Demo-Bau). Die Sperrliste in
 * .htaccess kannte es nicht — ACC und Prod lieferten tools/build-demo.mjs aus.
 * Harmlos (der Code ist öffentlich), aber genau so rutscht beim nächsten Mal etwas
 * durch, das nicht harmlos ist. Seitdem prüft dieser Test die Regeln gegen das,
 * was tatsächlich im Repo liegt — für Apache (.htaccess) und nginx (Docker).
 */
final class WebrootRulesTest extends TestCase
{
    /** Was ausgeliefert werden soll. Alles andere muss gesperrt sein. */
    private const SERVED_DIRS  = ['public'];
    private const SERVED_FILES = ['index.php', 'api.php', 'sw.js', 'manifest.webmanifest', 'LICENSE'];
    /** Lokale, ignorierte Verzeichnisse, die es im Repo nicht gibt, aber auf Platten schon. */
    private const LOCAL_DIRS = ['vendor', 'node_modules', 'graphify-out', 'dist', 'data'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array{dirs: string[], files: string[]} sichtbare Einträge im Repo-Wurzelverzeichnis */
    private static function entries(): array
    {
        $dirs = $files = [];
        foreach (scandir(self::root()) ?: [] as $e) {
            if ($e === '.' || $e === '..' || str_starts_with($e, '.')) continue;   // Punkt-Regel deckt sie ab
            is_dir(self::root() . '/' . $e) ? $dirs[] = $e : $files[] = $e;
        }
        return ['dirs' => array_values(array_unique([...$dirs, ...self::LOCAL_DIRS])), 'files' => $files];
    }

    private static function htaccess(): string
    {
        return (string)file_get_contents(self::root() . '/.htaccess');
    }

    public function testApacheBlocksEveryDirectoryExceptPublic(): void
    {
        self::assertSame(1, preg_match('/RewriteRule \^\(([a-z|_-]+)\)\(\/\|\$\) - \[R=404,L\]/', self::htaccess(), $m),
            'Verzeichnisregel in .htaccess nicht gefunden');
        $blocked = explode('|', $m[1]);
        foreach (self::entries()['dirs'] as $dir) {
            if (in_array($dir, self::SERVED_DIRS, true)) continue;
            self::assertContains($dir, $blocked, "Apache liefert $dir/ aus — in .htaccess sperren oder ausdrücklich erlauben");
        }
    }

    public function testApacheBlocksEveryFileThatIsNotServed(): void
    {
        self::assertSame(1, preg_match('/RewriteRule (\^\(composer[^\s]+\$) - \[R=404,L\]/', self::htaccess(), $m),
            'Dateiregel in .htaccess nicht gefunden');
        $rule = '#' . $m[1] . '#';
        foreach (self::entries()['files'] as $file) {
            if (in_array($file, self::SERVED_FILES, true)) continue;
            self::assertSame(1, preg_match($rule, $file), "Apache liefert $file aus — in .htaccess sperren oder ausdrücklich erlauben");
        }
    }

    /**
     * v3.4.0 (N1020) — Ohne mod_rewrite sperrt mod_alias dieselben Pfade.
     * Bis v3.3 hing der ganze Schutz an `<IfModule mod_rewrite.c>`; fehlte das
     * Modul, waren data/ und die Backups still erreichbar. Geprüft wird mit
     * den Mustern selbst (Apache nutzt PCRE wie PHP), auch im Unterverzeichnis
     * und gegen public/vendor/, das ausgeliefert werden muss.
     */
    public function testApacheFallbackWithoutModRewriteBlocksTheSamePaths(): void
    {
        $ht = self::htaccess();
        self::assertSame(1, preg_match('#<IfModule !mod_rewrite\.c>(.*?)</IfModule>\s*</IfModule>#s', $ht, $block), 'Rückfall-Block fehlt');
        preg_match_all('#RedirectMatch 404 "([^"]+)"#', $block[1], $m);
        self::assertCount(3, $m[1]);
        $blocked = fn(string $path): bool => array_filter($m[1], fn($re) => preg_match('#' . str_replace('#', '\#', $re) . '#', $path)) !== [];

        preg_match('/RewriteRule \^\(([a-z|_-]+)\)\(\/\|\$\) - \[R=404,L\]/', $ht, $dirs);
        foreach (['', '/energietracker-acc'] as $base) {
            foreach (explode('|', $dirs[1]) as $dir) {
                self::assertTrue($blocked("$base/$dir/x.json"), "$base/$dir/ ohne mod_rewrite offen");
                self::assertTrue($blocked("$base/$dir"), "$base/$dir ohne mod_rewrite offen");
            }
            foreach (['/.git/config', '/.htaccess', '/composer.json', '/README.md', '/Dockerfile', '/router.php', '/VERSION'] as $f) {
                self::assertTrue($blocked($base . $f), "$base$f ohne mod_rewrite offen");
            }
            foreach (['/', '/index.php', '/api.php/api/health', '/sw.js', '/manifest.webmanifest', '/LICENSE',
                      '/public/vendor/chart.umd.min.js', '/public/js/views/data.js', '/public/vendor/fonts/dm-sans.woff2'] as $ok) {
                self::assertFalse($blocked($base . $ok), "$base$ok wird ohne mod_rewrite gesperrt");
            }
        }
        $data = (string)file_get_contents(self::root() . '/data/.htaccess');
        self::assertMatchesRegularExpression('#<IfModule !mod_rewrite\.c>\s*<IfModule mod_alias\.c>\s*RedirectMatch 404 "\.\*"#', $data,
            'data/.htaccess: zweite Sicherung auch ohne mod_rewrite');
    }

    /**
     * v3.4.0 (N1020) — Größe des Körpers: nginx klein für alles, groß nur für
     * die Routen, die die App selbst als groß kennt (App::LARGE_BODY_ROUTES).
     * Abgeglichen an allen Routen aus bootstrap.php, unter /api/… und /api.php/api/….
     */
    public function testNginxAllowsLargeBodiesOnlyWhereTheAppDoes(): void
    {
        $conf = (string)file_get_contents(self::root() . '/docker/nginx.conf');
        self::assertMatchesRegularExpression('/^\s*client_max_body_size 8m;/m', $conf, 'global klein');
        self::assertSame(1, preg_match('#location ~ (\^/api\(\\\\\.php/api\)\?/\S+) \{\s*client_max_body_size 256m;#', $conf, $m),
            'Location für große Körper fehlt');
        $nginx = '#' . $m[1] . '#';
        $src = (string)file_get_contents(self::root() . '/src/bootstrap.php');
        preg_match_all('/\$r->(post|patch|put)\(\'([^\']+)\'/', $src, $routes);
        $large = 0;
        foreach (array_unique($routes[2]) as $route) {
            $path = preg_replace('/\{[a-zA-Z_]+\}/', 'x1', $route);
            $app = (bool)preg_match(\Energietracker\App::LARGE_BODY_ROUTES, $path);
            $large += (int)$app;
            foreach ([$path, '/api.php' . $path] as $uri) {
                self::assertSame($app, (bool)preg_match($nginx, $uri), "$uri: nginx und App uneinig über die Körpergröße");
            }
        }
        self::assertGreaterThanOrEqual(8, $large, 'Backup-Import, Belege und die Importe');
    }

    /** Im Docker-Image fehlt, was .dockerignore ausschließt; den Rest muss nginx sperren. */
    public function testNginxBlocksEveryDirectoryInTheImage(): void
    {
        $conf = (string)file_get_contents(self::root() . '/docker/nginx.conf');
        preg_match_all('#location ~ \^/\(([a-z|_-]+)\)/#', $conf, $mm);
        $blocked = explode('|', implode('|', $mm[1]));
        $ignored = array_filter(array_map('trim', file(self::root() . '/.dockerignore') ?: []),
            fn($l) => $l !== '' && !str_starts_with($l, '#') && !str_starts_with($l, '!'));
        $ignored = array_map(fn($l) => trim($l, '/'), $ignored);
        foreach (self::entries()['dirs'] as $dir) {
            if (in_array($dir, self::SERVED_DIRS, true) || in_array($dir, $ignored, true)) continue;
            self::assertContains($dir, $blocked, "nginx liefert $dir/ aus — in docker/nginx.conf sperren oder in .dockerignore ausschließen");
        }
    }
}
