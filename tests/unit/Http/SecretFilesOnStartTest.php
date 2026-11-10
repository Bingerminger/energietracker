<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Storage\JsonStore;
use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.12.0 — Härtung über HTTP: Die Anmeldung einschalten legt `auth.json` mit
 * 0600 an; eine bestehende Installation mit Dateien aus v3.11 (0644) hat sie
 * nach der nächsten Anfrage mit 0600 — ohne dass jemand etwas speichert. Die
 * App liest sie danach weiter (Anmeldung, Health).
 */
final class SecretFilesOnStartTest extends HttpServerTestCase
{
    private function mode(string $rel): string
    {
        clearstatcache();
        return substr(sprintf('%o', fileperms($this->dataDir . '/' . $rel)), -4);
    }

    public function testEnablingTheLoginWritesAuthJsonOwnerOnly(): void
    {
        $this->startServer();
        $r = $this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'Rechte-Probe-9']));
        self::assertSame(200, $r['status'], $r['body']);
        self::assertSame('0600', $this->mode('auth.json'));
        self::assertSame('0644', $this->mode('settings.json'));
    }

    public function testFilesFromAnOlderVersionAreTightenedOnTheNextRequest(): void
    {
        $this->startServer();
        foreach (JsonStore::SECRET_FILES as $f) {
            file_put_contents($this->dataDir . '/' . $f, $f === 'auth.json' ? '{"mode":"off"}' : '{"x":"y"}');
            chmod($this->dataDir . '/' . $f, 0644);
        }
        $health = $this->request('GET', '/api/health');
        self::assertSame(200, $health['status'], $health['body']);
        foreach (JsonStore::SECRET_FILES as $f) self::assertSame('0600', $this->mode($f), $f);
        // die App liest sie weiter
        self::assertSame('ok', json_decode($this->request('GET', '/api/health')['body'], true)['data']['status'] ?? null);
        self::assertSame(200, $this->request('GET', '/api/session')['status']);
    }
}
