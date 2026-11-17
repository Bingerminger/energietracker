<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 (K2d) — Wechselt der Besitzer des Datenordners (etwa das Image ohne root auf einem bestehenden Volume, ohne
 * `chown`), antworten Schreibwege mit 503 und der angekündigten Meldung `errors.storage.notWritable`, nicht mit 500
 * „Interner Fehler“. Lesen bleibt 200, /api/health 503 wie bisher. Bisher setzte nur ein Start mit Migration die
 * Sperre (UnwritableDataDirTest, leeres Volume); ein schon eingerichteter Ordner kam nie dorthin.
 *
 * Nachgestellt mit einem schreibgeschützten Ordner (`chmod a-w`, wie ein fremder Besitzer). Als root greift der
 * Schreibschutz nicht; dann wird der Test übersprungen.
 */
#[CoversNothing]
final class DataDirOwnerChangeTest extends HttpServerTestCase
{
    /** @var list<string> */
    private array $locked = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->locked) as $d) @chmod($d, 0755);
        parent::tearDown();
    }

    public function testWritesAnswer503WithTheAnnouncedMessageAndReadsStay200(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) self::markTestSkipped('als root greift der Schreibschutz nicht');
        $this->startServer();
        $gas = (string)$this->json('GET', '/api/utility/gas/meters')[0]['id'];
        self::assertSame(200, $this->request('GET', '/api/summary')['status']);
        // der Ordner gehört jetzt „jemand anderem“: nicht mehr beschreibbar
        $dirs = [$this->dataDir];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
            if ($f->isDir()) $dirs[] = (string)$f->getPathname();
        }
        foreach ($dirs as $d) { chmod($d, 0555); $this->locked[] = $d; }
        clearstatcache();

        $h = ['Content-Type: application/json', "Origin: {$this->base}", 'X-ET-Language: de'];
        foreach ([['PATCH', '/api/settings', '{"dashboard_months":30}'],
                  ['POST', '/api/utility/gas/readings', json_encode(['meter_id' => $gas, 'date' => '2024-02-02', 'counter' => 12])],
                  ['POST', '/api/utility/strom/meters', '{"name":"Neu","installed_on":"2024-01-01"}'],
                  ['POST', '/api/backup/snapshot', '{}']] as [$m, $p, $body]) {
            $r = $this->request($m, $p, $h, $body);
            $j = json_decode($r['body'], true);
            self::assertSame(503, $r['status'], "$m $p: {$r['body']}");
            self::assertSame('errors.storage.notWritable', $j['code'] ?? null, "$m $p");
        }
        foreach (['/api/settings', '/api/summary', '/api/utility/gas/readings'] as $p) {
            self::assertSame(200, $this->request('GET', $p, ['X-ET-Language: de'])['status'], "lesen: $p");
        }
        // eine Vorschau, die nichts schreibt, geht weiter
        self::assertSame(200, $this->request('POST', '/api/metering-concept/preview', $h, '{"concept":"single","tou":"none"}')['status']);
        // Wege, die erst spät sperren (Texterkennung), und die Anmeldung prüfen nicht vorab: Sie antworten selbst
        foreach ([['POST', '/api/ocr/reading', '{}'], ['POST', '/api/session', '{"username":"jemand","password":"falsch"}']] as [$m, $p, $body]) {
            $r = $this->request($m, $p, $h, $body);
            self::assertNotSame(503, $r['status'], "$m $p: {$r['body']}");
            self::assertLessThan(500, $r['status'], "$m $p: {$r['body']}");
        }
        self::assertSame(503, $this->request('GET', '/api/health')['status']);
    }

    /** Auch ein Weg, der erst unterwegs scheitert (ein Unterordner fremd), endet in 503 mit Meldung, nicht in 500. */
    public function testAFailedWriteDeepInsideIs503(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) self::markTestSkipped('als root greift der Schreibschutz nicht');
        $dir = sys_get_temp_dir() . '/et-k2d-ro-' . bin2hex(random_bytes(4));
        mkdir("$dir/gas", 0755, true);
        $store = new \Energietracker\Storage\JsonStore($dir);
        chmod("$dir/gas", 0555);
        $this->locked[] = "$dir/gas";
        try {
            $store->write('gas/readings.json', []);
            self::fail('geschrieben trotz Schreibschutz');
        } catch (\Energietracker\Storage\StorageNotWritableException $e) {
            $d = \Energietracker\Http\ErrorHandler::describe($e, 'err_00000000');
            self::assertSame(503, $d['status']);
            self::assertSame('errors.storage.notWritable', $d['payload']['code'] ?? null);
        } finally {
            @chmod("$dir/gas", 0755);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
