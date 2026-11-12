<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.13.0 — Selbsttest der Diagnose in HttpServerTestCase: Bleibt eine Antwort
 * aus, nennt die Meldung, wo der Server steckt. Nachgestellt mit einer
 * Schreibsperre, die der Test selbst hält: Der Server wartet in
 * WriteLock::acquire (bis ET_LOCK_WAIT_S), der Test gibt nach zwei Sekunden auf.
 * Anlass: seltener Status 0 in der CI unter PHP 8.3 (McpReadEndpointTest).
 */
final class HungRequestDiagnosisTest extends HttpServerTestCase
{
    public function testAMissingAnswerNamesWhereTheServerWaits(): void
    {
        if (!function_exists('posix_kill') || !defined('SIGUSR1')) {
            self::markTestSkipped('ohne pcntl und posix kein Signal an den Testserver');
        }
        $this->requestTimeout = 2;
        $this->startServer(['language' => 'de'], ['ET_LOCK_WAIT_S' => '30']);
        $lock = fopen($this->dataDir . '/.write.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        try {
            $r = $this->request('PATCH', '/api/settings', ['Content-Type: application/json'], '{"language":"de"}');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertSame(0, $r['status'], $r['body']);
        self::assertStringContainsString('[keine Antwort auf PATCH /api/settings]', $r['body']);
        self::assertStringContainsString('ET-TRACE', $r['body'], 'der Stack der hängenden Anfrage');
        self::assertStringContainsString('WriteLock->acquire', $r['body'], $r['body']);
        if (PHP_OS_FAMILY === 'Linux') {
            self::assertMatchesRegularExpression('/Zustand: S/', $r['body'], 'wartet, rechnet nicht');
            self::assertStringContainsString('.write.lock', $r['body'], 'die offene Sperrdatei');
        }
    }

    /**
     * Ein blockierendes flock() (JsonStore::read wartet auf LOCK_SH): Das Signal muss den
     * Systemaufruf unterbrechen, sonst startet der Kern ihn neu und der Stack kommt nie
     * (pcntl_signal mit restart_syscalls = false im Test-Router).
     */
    public function testABlockingFileLockStillYieldsTheStack(): void
    {
        if (!function_exists('posix_kill') || !defined('SIGUSR1')) {
            self::markTestSkipped('ohne pcntl und posix kein Signal an den Testserver');
        }
        $this->requestTimeout = 2;
        $this->startServer();
        $lock = fopen($this->dataDir . '/settings.json', 'rb');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        try {
            $r = $this->request('GET', '/api/settings');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertSame(0, $r['status'], $r['body']);
        self::assertStringContainsString('ET-TRACE', $r['body'], 'der Stack der hängenden Anfrage');
        self::assertStringContainsString('JsonStore->read', $r['body'], $r['body']);
        if (PHP_OS_FAMILY === 'Linux') {
            self::assertMatchesRegularExpression('/wartet in: \S*lock/i', $r['body'], 'wartet im Kern auf eine Dateisperre');
            self::assertStringContainsString('settings.json', $r['body'], 'die gesperrte Datei');
        }
    }
}
