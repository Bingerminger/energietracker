<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\InstanceService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 — Die Kennung der Installation ohne Schreibrecht, ohne `chmod` nachgestellt (läuft auch als root): Fehlt
 * `instance.json`, gilt eine aus dem Datenordner abgeleitete Kennung, bei jeder Anfrage dieselbe und nicht gespeichert;
 * darf wieder geschrieben werden, speichert die App genau diese. Bisher wollte der erste Bedarf die Datei schreiben, und
 * Zusammenfassung, „Zu tun“ und Kalender antworteten ohne Schreibrecht mit 503.
 */
#[CoversClass(InstanceService::class)]
final class InstanceIdWithoutWriteAccessTest extends ServiceTestCase
{
    private bool $writable = false;

    private function service(): InstanceService
    {
        return new InstanceService($this->store, fn(): bool => $this->writable);
    }

    public function testWithoutWriteAccessTheIdIsDerivedStableAndNotStored(): void
    {
        @unlink($this->dataDir . '/instance.json');
        $a = $this->service()->id();
        self::assertMatchesRegularExpression('/^et_[0-9a-f]{16}$/', $a);
        self::assertSame($a, $this->service()->id(), 'jede Anfrage dieselbe (neuer Dienst = neue Anfrage)');
        self::assertFileDoesNotExist($this->dataDir . '/instance.json', 'ohne Schreibrecht nichts geschrieben');

        $this->writable = true;
        self::assertSame($a, $this->service()->id(), 'wieder beschreibbar: dieselbe Kennung');
        self::assertSame($a, json_decode((string)file_get_contents($this->dataDir . '/instance.json'), true)['instance_id']);
    }

    public function testAStoredIdWinsAndTheFirstStartStoresARandomOne(): void
    {
        $this->writable = true;
        @unlink($this->dataDir . '/instance.json');
        $svc = $this->service();
        $svc->ensure();
        $stored = json_decode((string)file_get_contents($this->dataDir . '/instance.json'), true)['instance_id'];
        self::assertMatchesRegularExpression('/^et_[0-9a-f]{16}$/', $stored);
        self::assertSame($stored, $svc->id());
        $svc->ensure();
        self::assertSame($stored, $this->service()->id(), 'ensure() überschreibt nichts');
        // ohne Schreibrecht gilt die gespeicherte weiter
        $this->writable = false;
        self::assertSame($stored, $this->service()->id());
    }

    public function testTwoDataFoldersGetTwoIds(): void
    {
        @unlink($this->dataDir . '/instance.json');
        $other = sys_get_temp_dir() . '/et-k4b-zweiter-' . bin2hex(random_bytes(4));
        mkdir($other, 0755, true);
        try {
            $b = (new InstanceService(new \Energietracker\Storage\JsonStore(realpath($other) ?: $other), fn(): bool => false))->id();
            self::assertNotSame($this->service()->id(), $b);
        } finally {
            exec('rm -rf ' . escapeshellarg($other));
        }
    }
}
