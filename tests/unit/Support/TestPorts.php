<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

/**
 * v3.14.0 — Freie Ports für Testserver, an EINER Stelle.
 *
 * Ohne `ET_TEST_PORT_BASE` wie bis v3.13.0: Das System vergibt einen freien Port (Port 0), der Test gibt ihn
 * frei und startet seinen Server darauf. Das genügt, solange nur ein PHPUnit-Prozess läuft.
 *
 * Mit `ET_TEST_PORT_BASE` (setzt tools/phpunit-parallel.php je Prozess): nur Ports aus dem eigenen Bereich
 * BASE … BASE+SPAN−1 (`ET_TEST_PORT_SPAN`, Standard 400), der Reihe nach und nur, wenn gerade frei. Die
 * Bereiche der Prozesse überschneiden sich nicht. Sonst könnte zwischen „Port freigeben“ und „Server
 * startet“ ein anderer Prozess denselben Port bekommen, und ein Test spräche mit dem Server eines anderen.
 *
 * `TestPortsTest` hält fest, dass kein Test den Port mehr selbst beim System holt.
 */
final class TestPorts
{
    private static int $next = 0;

    public static function free(): int
    {
        $base = (int)getenv('ET_TEST_PORT_BASE');
        if ($base <= 0) {
            $s = stream_socket_server('tcp://127.0.0.1:0');
            if ($s === false) throw new \RuntimeException('kein freier Port');
            $port = (int)substr((string)strrchr((string)stream_socket_get_name($s, false), ':'), 1);
            fclose($s);
            return $port;
        }
        $span = max(1, (int)(getenv('ET_TEST_PORT_SPAN') ?: 400));
        for ($i = 0; $i < $span; $i++) {
            $port = $base + (self::$next++ % $span);
            $s = @stream_socket_server("tcp://127.0.0.1:$port");
            if ($s !== false) {
                fclose($s);
                return $port;
            }
        }
        throw new \RuntimeException(sprintf('kein freier Port zwischen %d und %d', $base, $base + $span - 1));
    }
}
