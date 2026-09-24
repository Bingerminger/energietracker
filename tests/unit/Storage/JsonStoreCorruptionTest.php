<?php
declare(strict_types=1);

namespace Energietracker\Tests\Storage;

use Energietracker\Storage\JsonStore;
use Energietracker\Storage\StorageCorruptedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v2.5.3 — „fehlt" und „kaputt" sind zwei Fälle (API-03 im Review 2026-09-24).
 *
 * Bis v2.5.2 las JsonStore eine abgeschnittene Datei still als leer; der
 * nächste Schreibzugriff (typisch: der tägliche HA-Push) ersetzte 39
 * Ablesungen durch eine einzige. Jetzt bleibt die Datei stehen, eine Kopie
 * liegt daneben, und jeder Zugriff scheitert sichtbar.
 */
#[CoversClass(JsonStore::class)]
final class JsonStoreCorruptionTest extends TestCase
{
    private string $dir;
    private JsonStore $store;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/et-store-' . bin2hex(random_bytes(5));
        mkdir($tmp, 0755, true);
        $this->dir = realpath($tmp) ?: $tmp;
        $this->store = new JsonStore($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testMissingFileGivesTheDefault(): void
    {
        self::assertSame(['d'], $this->store->read('fehlt.json', ['d']));
    }

    public function testEmptyFileGivesTheDefault(): void
    {
        file_put_contents($this->dir . '/leer.json', "  \n");
        self::assertSame([], $this->store->read('leer.json', []));
    }

    public function testTruncatedFileThrowsAndIsNeitherOverwrittenNorLost(): void
    {
        $this->store->write('readings.json', [['date' => '2026-01-01', 'counter' => 1.0], ['date' => '2026-02-01', 'counter' => 2.0]]);
        $full = (string)file_get_contents($this->dir . '/readings.json');
        $cut = substr($full, 0, (int)(strlen($full) / 2));
        file_put_contents($this->dir . '/readings.json', $cut);

        try {
            $this->store->read('readings.json', []);
            self::fail('Eine abgeschnittene Datei darf nicht als leer gelten');
        } catch (StorageCorruptedException $e) {
            self::assertStringContainsString('readings.json', $e->getMessage());
        }

        // Datei unverändert, Kopie daneben — genau einmal, auch bei wiederholtem Lesen
        self::assertSame($cut, file_get_contents($this->dir . '/readings.json'));
        try { $this->store->read('readings.json', []); } catch (StorageCorruptedException) {}
        $copies = glob($this->dir . '/readings.json.corrupt-*') ?: [];
        self::assertCount(1, $copies);
        self::assertSame($cut, file_get_contents($copies[0]));
    }

    public function testCorruptionIsReportedAs503(): void
    {
        $ref = new \ReflectionMethod(\Energietracker\Http\ErrorHandler::class, 'statusFor');
        self::assertSame(503, $ref->invoke(null, new StorageCorruptedException('x')));
    }
}
