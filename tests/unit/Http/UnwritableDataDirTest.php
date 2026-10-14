<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;
use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.5.0 (N1024) — Ein Datenverzeichnis, in das die App nicht schreiben darf
 * (im Image ohne root: ein Ordner, der einer anderen UID gehört).
 *
 * Bis v3.4 scheiterte beim Erststart der erste Schreibversuch, und jede
 * Anfrage endete mit 500 — auch /api/health, also ohne Hinweis auf die
 * Ursache. Jetzt versucht der Start nichts, /api/health meldet
 * `data_dir_writable: false` (503), alle anderen Routen antworten mit 503 und
 * `errors.storage.notWritable`. Sind die Rechte korrigiert, läuft die nächste
 * Anfrage normal — ohne Neustart.
 */
final class UnwritableDataDirTest extends HttpServerTestCase
{
    protected function setUp(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('als root ist jedes Verzeichnis beschreibbar');
        }
    }

    protected function tearDown(): void
    {
        if ($this->dataDir !== '' && is_dir($this->dataDir)) @chmod($this->dataDir, 0755);
        parent::tearDown();
    }

    public function testStartupTriesNothingAndHealthNamesTheCause(): void
    {
        $this->startServer();
        // Zustand eines frischen Volumes, das der App nicht gehört: leer, nicht beschreibbar
        foreach (glob($this->dataDir . '/*') ?: [] as $f) exec('rm -rf ' . escapeshellarg($f));
        chmod($this->dataDir, 0555);

        $health = $this->request('GET', '/api/health');
        self::assertSame(503, $health['status'], 'Docker-HEALTHCHECK: unhealthy');
        $data = json_decode($health['body'], true)['data'] ?? null;
        self::assertIsArray($data, 'Health antwortet mit dem Befund, nicht mit „Interner Fehler“');
        self::assertFalse($data['data_dir_writable']);
        self::assertSame('error', $data['status']);

        foreach (['/api/settings', '/api/diagnostics', '/api/utilities'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(503, $r['status'], $path);
            self::assertSame('errors.storage.notWritable', json_decode($r['body'], true)['code'] ?? null, $path);
        }
        self::assertSame([], glob($this->dataDir . '/*') ?: [], 'nichts angelegt');

        // Rechte korrigiert → die nächste Anfrage legt an und antwortet normal
        chmod($this->dataDir, 0755);
        self::assertSame(200, $this->request('GET', '/api/settings')['status']);
        self::assertFileExists($this->dataDir . '/meta.json');
        self::assertSame(200, $this->request('GET', '/api/health')['status']);
    }

    public function testMigratorReportsUnwritableOnlyWhenItWouldWrite(): void
    {
        $dir = sys_get_temp_dir() . '/et-unwritable-' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        $this->dataDir = $dir;   // räumt tearDown ab
        $store = new JsonStore($dir);

        chmod($dir, 0555);
        self::assertSame(['action' => 'unwritable'], (new Migrator($store))->runOnStartup());
        self::assertFileDoesNotExist("$dir/meta.json");

        // Daten einer neueren Version bleiben „too-new“ (schreibt ohnehin nichts)
        chmod($dir, 0755);
        file_put_contents("$dir/meta.json", json_encode(['schema_version' => '9.0.0']));
        chmod($dir, 0555);
        self::assertSame('too-new', (new Migrator($store))->runOnStartup()['action'] ?? null);
    }
}
