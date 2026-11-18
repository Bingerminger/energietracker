<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * v3.14.0 — Ports der Testserver (TestPorts): ohne Bereich vom System, im parallelen Lauf nur aus dem eigenen
 * Bereich des Prozesses; und kein Test holt sich den Port mehr selbst.
 */
final class TestPortsTest extends TestCase
{
    private string|false $base;
    private string|false $span;

    protected function setUp(): void
    {
        $this->base = getenv('ET_TEST_PORT_BASE');
        $this->span = getenv('ET_TEST_PORT_SPAN');
    }

    protected function tearDown(): void
    {
        putenv($this->base === false ? 'ET_TEST_PORT_BASE' : "ET_TEST_PORT_BASE={$this->base}");
        putenv($this->span === false ? 'ET_TEST_PORT_SPAN' : "ET_TEST_PORT_SPAN={$this->span}");
    }

    public function testWithoutARangeTheSystemPicksAFreePort(): void
    {
        putenv('ET_TEST_PORT_BASE');
        $port = TestPorts::free();
        self::assertGreaterThan(0, $port);
        $s = @stream_socket_server("tcp://127.0.0.1:$port");
        self::assertNotFalse($s, 'der Port ist danach frei');
        fclose($s);
    }

    public function testWithARangeOnlyPortsOfThatRangeAndEachInTurn(): void
    {
        // Probeports oberhalb der Blöcke von tools/phpunit-parallel.php (20000–31999), unter 32768
        $base = 32100 + random_int(0, 40) * 4;
        putenv("ET_TEST_PORT_BASE=$base");
        putenv('ET_TEST_PORT_SPAN=4');
        $ports = [TestPorts::free(), TestPorts::free(), TestPorts::free()];
        foreach ($ports as $p) self::assertTrue($p >= $base && $p < $base + 4, "Port $p liegt im Bereich ab $base");
        self::assertCount(3, array_unique($ports), 'der Reihe nach, nicht derselbe Port');
    }

    public function testBusyPortsOfTheRangeAreSkipped(): void
    {
        $base = 32300 + random_int(0, 40) * 3;
        putenv("ET_TEST_PORT_BASE=$base");
        putenv('ET_TEST_PORT_SPAN=3');
        $busy = [@stream_socket_server("tcp://127.0.0.1:$base"), @stream_socket_server('tcp://127.0.0.1:' . ($base + 2))];
        try {
            self::assertSame($base + 1, TestPorts::free(), 'der einzige freie Port des Bereichs');
            self::assertSame($base + 1, TestPorts::free(), 'auch beim nächsten Mal, wo immer die Reihe gerade steht');
        } finally {
            foreach ($busy as $s) if ($s !== false) fclose($s);
        }
    }

    public function testAFullRangeIsAnErrorNotAForeignPort(): void
    {
        $base = 32500 + random_int(0, 40) * 2;
        putenv("ET_TEST_PORT_BASE=$base");
        putenv('ET_TEST_PORT_SPAN=2');
        $busy = [@stream_socket_server("tcp://127.0.0.1:$base"), @stream_socket_server('tcp://127.0.0.1:' . ($base + 1))];
        try {
            $this->expectException(\RuntimeException::class);
            TestPorts::free();
        } finally {
            foreach ($busy as $s) if ($s !== false) fclose($s);
        }
    }

    /** Ein Test, der sich den Port selbst beim System holt, brächte den Wettlauf im parallelen Lauf zurück. */
    public function testNoTestFetchesItsOwnPort(): void
    {
        $own = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php' || $f->getFilename() === 'TestPorts.php' || $f->getFilename() === 'TestPortsTest.php') continue;
            if (str_contains((string)file_get_contents($f->getPathname()), "'tcp://127.0.0.1:0'")) $own[] = $f->getFilename();
        }
        self::assertSame([], $own, 'Ports nur über TestPorts::free()');
    }
}
