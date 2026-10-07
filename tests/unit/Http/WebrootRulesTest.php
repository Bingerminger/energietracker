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
