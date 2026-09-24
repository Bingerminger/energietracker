<?php
declare(strict_types=1);

namespace Energietracker\Tests\Storage;

use Energietracker\Storage\WriteLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v2.5.3 — Keine verlorenen Updates mehr (API-04 im Review 2026-09-24).
 *
 * Die Doku behauptete „Alle Schreibvorgänge nutzen LOCK_EX, sodass parallele
 * Zugriffe sich nicht gegenseitig zerstören". Gesperrt war aber nur die
 * Temp-Datei; der Zyklus Lesen → Ändern → Schreiben lief ungeschützt, und im
 * Test gingen 36–64 % paralleler Updates verloren. Dieser Test startet echte
 * Prozesse, die denselben Zyklus unter der WriteLock fahren — genau so, wie
 * App::handle() es für jede schreibende Anfrage tut.
 */
#[CoversClass(WriteLock::class)]
final class WriteLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/et-lock-' . bin2hex(random_bytes(5));
        mkdir($tmp, 0755, true);
        $this->dir = realpath($tmp) ?: $tmp;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testAcquireAndReleaseAreIdempotent(): void
    {
        $lock = new WriteLock($this->dir);
        self::assertTrue($lock->acquire());
        self::assertTrue($lock->acquire(), 'zweites acquire im selben Prozess darf nicht blockieren');
        self::assertTrue($lock->isHeld());
        $lock->release();
        $lock->release();
        self::assertFalse($lock->isHeld());
    }

    public function testParallelReadModifyWriteLosesNothing(): void
    {
        if (!function_exists('proc_open')) self::markTestSkipped('proc_open nicht verfügbar');

        $counterFile = $this->dir . '/counter.json';
        file_put_contents($counterFile, '{"n":0}');
        $bootstrap = dirname(__DIR__, 3) . '/src/bootstrap.php';
        $worker = $this->dir . '/worker.php';
        file_put_contents($worker, <<<'PHP'
<?php
require $argv[1];
$lock = new \Energietracker\Storage\WriteLock($argv[2]);
$store = new \Energietracker\Storage\JsonStore($argv[2]);
for ($i = 0; $i < 40; $i++) {
    $lock->acquire();
    $data = $store->read('counter.json', ['n' => 0]);
    usleep(200);                       // Fenster für eine Überschneidung
    $data['n']++;
    $store->write('counter.json', $data);
    $lock->release();
}
PHP);

        $procs = [];
        $all = [];
        for ($p = 0; $p < 4; $p++) {
            $procs[] = proc_open([PHP_BINARY, $worker, $bootstrap, $this->dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $all[] = $pipes;
        }
        foreach ($procs as $i => $proc) {
            stream_get_contents($all[$i][1]);
            stream_get_contents($all[$i][2]);
            proc_close($proc);
        }

        $final = json_decode((string)file_get_contents($counterFile), true);
        self::assertSame(160, $final['n'], '4 Prozesse × 40 Erhöhungen, keine darf verloren gehen');
    }
}
