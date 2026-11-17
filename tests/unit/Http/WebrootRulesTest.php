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
     * v3.13.0 — Apache schreibt /api/… auf api.php um wie nginx (`location ~
     * ^/api(\.php)?(/|$)`). Bis v3.12 gab …/api/ingest mit der mitgelieferten
     * .htaccess 404 (Farm, Paket M), die Doku versprach es aber.
     *
     * Geprüft wird das Muster selbst (mod_rewrite nutzt PCRE wie PHP; in der
     * .htaccess sieht es den Pfad ohne führenden Schrägstrich, relativ zum
     * Verzeichnis), die Lage (nach den Sperren, nur im mod_rewrite-Block, also
     * ohne mod_rewrite kein 500) und dass Request danach dieselbe Route sieht,
     * auch im Unterverzeichnis und mit dem Token unter REDIRECT_HTTP_AUTHORIZATION.
     */
    public function testApacheRewritesApiToApiPhpLikeNginx(): void
    {
        $ht = self::htaccess();
        self::assertSame(1, preg_match('#<IfModule mod_rewrite\.c>(.*?)</IfModule>#s', $ht, $block), 'mod_rewrite-Block fehlt');
        self::assertSame(1, preg_match_all('#^\s*RewriteRule (\S+) (api\.php\S*) \[L\]$#m', $ht, $all), 'genau eine Regel /api/ → api.php');
        self::assertSame(1, preg_match('#^\s*RewriteRule (\S+) (api\.php\S*) \[L\]$#m', $block[1], $m), 'die Regel steht im mod_rewrite-Block');
        self::assertGreaterThan(strrpos($block[1], '[R=404,L]'), strpos($block[1], $m[0]), 'nach den Sperren');
        $apache = '#' . $m[1] . '#';
        // Ziel wie Apache es bildet ($1 aus dem Muster), als Pfad ab dem Verzeichnis
        $target = fn(string $p): string => '/' . preg_replace($apache, $m[2], ltrim($p, '/'));
        $nginx = '#^/api(\.php)?(/|$)#';
        foreach (['/api', '/api/', '/api/ingest', '/api/mcp', '/api/health', '/api/utility/strom/meters/m1/readings'] as $p) {
            self::assertSame(1, preg_match($apache, ltrim($p, '/')), "$p: Apache schreibt nicht auf api.php um");
            self::assertSame(1, preg_match($nginx, $p), "$p: nginx-Muster");
            self::assertSame('/api.php' . $p, $target($p), "$p: Ziel ist api.php mit der Route als Pfad-Suffix");
            self::assertSame(0, preg_match($apache, ltrim($target($p), '/')), "$p: das Ziel wird nicht noch einmal umgeschrieben");
        }
        // api.php selbst bleibt eine Datei (Pfad-Suffix wie bisher), alles andere geht nicht an die API
        foreach (['/api.php', '/api.php/api/ingest', '/apidocs', '/api-x', '/public/js/api.js', '/public/api/x', '/index.php'] as $p) {
            self::assertSame(0, preg_match($apache, ltrim($p, '/')), "$p: darf nicht auf api.php umgeschrieben werden");
        }

        // Größe des Körpers: Apache prüft LimitRequestBody am Ziel des Umschreibens. Die Ausnahme der
        // Doku (LocationMatch, 256 MB) muss am Ziel genau die Routen treffen, die die App groß nennt
        // (Farm v3.13.0: mit dem Ziel „api.php“ ohne Pfad gaben 9 MB an /api/backup/import 413).
        $ws = (string)file_get_contents(self::root() . '/docs/betrieb/webserver.md');
        self::assertSame(1, preg_match('#<LocationMatch "([^"]+)">\s*LimitRequestBody 268435456#', $ws, $loc), 'LocationMatch der Doku');
        $src = (string)file_get_contents(self::root() . '/src/bootstrap.php');
        preg_match_all('/\$r->(post|patch|put)\(\'([^\']+)\'/', $src, $routes);
        $large = 0;
        foreach (array_unique($routes[2]) as $route) {
            $path = preg_replace('/\{[a-zA-Z_]+\}/', 'x1', $route);
            $app = (bool)preg_match(\Energietracker\App::LARGE_BODY_ROUTES, $path);
            $large += (int)$app;
            self::assertSame($app, (bool)preg_match('#' . $loc[1] . '#', $target($path)), "$path: Apache am Ziel und App uneinig über die Körpergröße");
        }
        self::assertGreaterThanOrEqual(8, $large);

        // Was PHP nach dem Umschreiben sieht (Apache: REQUEST_URI wie gesendet, SCRIPT_NAME das Ziel)
        $saved = $_SERVER;
        try {
            foreach (['' => '/api/ingest', '/energietracker-acc' => '/api/ingest', '/energietracker' => '/api/mcp'] as $base => $route) {
                $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => "$base$route?x=1", 'SCRIPT_NAME' => "$base/api.php",
                            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer et_probe'];
                $req = new \Energietracker\Http\Request();
                self::assertSame($route, $req->path, "$base: Route nach dem Umschreiben");
                self::assertSame('et_probe', $req->bearerToken(), "$base: Token nach dem Umschreiben");
                // der bisherige Weg bleibt
                $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => "$base/api.php$route", 'SCRIPT_NAME' => "$base/api.php"];
                self::assertSame($route, (new \Energietracker\Http\Request())->path, "$base: api.php im Pfad");
            }
        } finally {
            $_SERVER = $saved;
        }
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
