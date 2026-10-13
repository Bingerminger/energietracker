<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.4.0 (N1019) — Hilfe auf dem Server: `GET /api/docs` und
 * `GET /api/docs/asset` liefern nur, was in der Freigabeliste steht.
 *
 * Geprüft über HTTP gegen den echten Router: Was ein Angreifer in `path`
 * schreibt (`..`, `%2e%2e`, absolute Pfade, Weiterleitungen, Dateien des
 * Wurzelverzeichnisses außerhalb der Liste), ergibt 404 — die Datei wird nie
 * aus dem Pfad zusammengesetzt. Mit Anmeldung gilt sie auch hier.
 */
final class DocsEndpointTest extends HttpServerTestCase
{
    private static function version(): string
    {
        return trim((string)file_get_contents(dirname(__DIR__, 3) . '/VERSION'));
    }

    public function testAPageComesWithLanguageMirrorAndMarkdown(): void
    {
        $this->startServer();
        $r = $this->request('GET', '/api/docs?path=README.md');
        self::assertSame(200, $r['status']);
        self::assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null);
        $d = json_decode($r['body'], true)['data'] ?? [];
        self::assertSame(['path', 'lang', 'mirror', 'root_files', 'markdown'], array_keys($d));
        self::assertSame(['README.md', 'de', 'en/README.md'], [$d['path'], $d['lang'], $d['mirror']]);
        self::assertSame((string)file_get_contents(dirname(__DIR__, 3) . '/docs/README.md'), $d['markdown']);
        self::assertContains('CHANGELOG.md', $d['root_files']);

        $en = $this->json('GET', '/api/docs?path=' . rawurlencode('en/anleitungen/anwendungsfaelle.md'));
        self::assertSame(['en', 'anleitungen/anwendungsfaelle.md'], [$en['lang'] ?? null, $en['mirror'] ?? null]);
        $root = $this->json('GET', '/api/docs?path=root/CONTRIBUTING.de.md');
        self::assertSame(['de', 'root/CONTRIBUTING.md'], [$root['lang'] ?? null, $root['mirror'] ?? null]);
        self::assertSame('de', $this->json('GET', '/api/docs?path=root/CHANGELOG.md')['lang'] ?? null);
        self::assertStringContainsString('GNU AFFERO', (string)($this->json('GET', '/api/docs?path=root/LICENSE')['markdown'] ?? ''));
    }

    public function testEverythingOutsideTheAllowlistIs404(): void
    {
        $this->startServer();
        $paths = [
            '', '../VERSION', '%2e%2e/VERSION', '..%2FVERSION', '%2e%2e%2f%2e%2e%2fsrc%2fbootstrap.php',
            'en/../../VERSION', 'README.md/../../VERSION', './README.md', '/README.md', '/etc/passwd',
            'docs/README.md', 'README.md%00', 'readme.md', 'README.md%20', 'ui/screenshots/dashboard.png',
            'root/roadmap.md', 'root/README.md', 'root/../VERSION', 'root/VERSION', 'root/src/bootstrap.php',
            'root/.htaccess', 'nope.md', 'en/nope.md', 'path[]=x',
        ];
        foreach ($paths as $p) {
            $r = $this->request('GET', '/api/docs?path=' . $p);
            self::assertSame(404, $r['status'], "path=$p");
            self::assertSame('errors.docs.notFound', json_decode($r['body'], true)['code'] ?? null, "path=$p");
        }
        self::assertSame(404, $this->request('GET', '/api/docs')['status'], 'ohne path');
        self::assertSame(404, $this->request('GET', '/api/docs?path[]=README.md')['status'], 'path als Liste');
    }

    public function testARedirectIsNotServedButNamesItsTarget(): void
    {
        $this->startServer();
        $r = $this->request('GET', '/api/docs?path=ERSTE-SCHRITTE.md');
        self::assertSame(404, $r['status']);
        $body = json_decode($r['body'], true);
        self::assertSame('einstieg/erste-schritte.md', $body['detail']['moved_to'] ?? null);
        self::assertStringNotContainsString('markdown', $r['body']);
        $en = json_decode($this->request('GET', '/api/docs?path=en/getting-started.md')['body'], true);
        self::assertSame('en/einstieg/erste-schritte.md', $en['detail']['moved_to'] ?? null);
    }

    public function testAnImageIsAPngWithItsCacheRule(): void
    {
        $this->startServer();
        $file = dirname(__DIR__, 3) . '/docs/ui/screenshots/dashboard.png';
        $r = $this->request('GET', '/api/docs/asset?path=ui/screenshots/dashboard.png&v=' . self::version());
        self::assertSame(200, $r['status']);
        self::assertSame('image/png', $r['headers']['content-type'] ?? null);
        self::assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null);
        self::assertSame('private, max-age=31536000, immutable', $r['headers']['cache-control'] ?? null);
        self::assertSame(md5_file($file), md5($r['body']));
        // ohne oder mit fremder Version: nachfragen statt festschreiben
        foreach (['', '&v=0.0.1'] as $v) {
            $o = $this->request('GET', '/api/docs/asset?path=ui/screenshots/dashboard.png' . $v);
            self::assertSame(200, $o['status']);
            self::assertSame('private, no-cache', $o['headers']['cache-control'] ?? null, "v=$v");
        }
        foreach (['README.md', 'ui/screenshots/../screenshots/dashboard.png', '../docs/ui/screenshots/dashboard.png',
                  'ui/screenshots/%2e%2e/screenshots/dashboard.png', '/ui/screenshots/dashboard.png', 'root/LICENSE'] as $p) {
            self::assertSame(404, $this->request('GET', '/api/docs/asset?path=' . $p)['status'], "asset path=$p");
        }
    }

    public function testWithLoginTheDocsNeedASessionOrKey(): void
    {
        $key = 'etk_docsdocsdocsdocsdocsdocsdocsdocsdocsdocsdocs01';
        $this->startServer(['language' => 'de'], ['ET_AUTH' => 'password']);
        file_put_contents($this->dataDir . '/auth.json', json_encode(['mode' => 'password', 'api_keys' => [[
            'id' => 'k_read', 'name' => 'read', 'scope' => 'read', 'hash' => hash('sha256', $key),
            'created_at' => date('c'), 'last_used_at' => null,
        ]]]));
        $r = $this->request('GET', '/api/docs?path=README.md');
        self::assertSame(401, $r['status']);
        self::assertSame('errors.auth.required', json_decode($r['body'], true)['code'] ?? null);
        self::assertSame(401, $this->request('GET', '/api/docs/asset?path=ui/screenshots/dashboard.png')['status']);
        self::assertSame(200, $this->request('GET', '/api/docs?path=README.md', ['Authorization: Bearer ' . $key])['status']);
        self::assertSame(200, $this->request('GET', '/api/docs/asset?path=ui/screenshots/dashboard.png',
            ['Authorization: Bearer ' . $key])['status']);
    }
}
