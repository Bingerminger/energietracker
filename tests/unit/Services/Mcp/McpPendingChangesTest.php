<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\BackupService;
use Energietracker\Services\Mcp\PendingChanges;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037, Basis) — Vorschläge der KI (Entscheidung 8): an den
 * Schlüssel gebunden, 15 Minuten gültig, einmalig, nur mit wörtlicher
 * Zusammenfassung, höchstens 50 offen; eigene Datei unter data/mcp/, nicht im
 * Backup; Sperren in fester Reihenfolge.
 */
final class McpPendingChangesTest extends McpTestCase
{
    private const SUMMARY = 'Gas, Zähler Keller: Stand 12 345,6 m³ am 02.11.2026 eintragen (geschätzt: nein).';

    private function key(string $id = 'k_test0001', string $scope = 'mcp_write'): \Energietracker\Services\Mcp\McpContext
    {
        return $this->context($scope, '2027-01-15', 'de', $id);
    }

    /** @return array<string,mixed> */
    private function propose(?string $keyId = null): array
    {
        return $this->pending->create($this->key($keyId ?? 'k_test0001'), 'reading', ['utility' => 'gas', 'counter' => 12345.6], self::SUMMARY);
    }

    private function errorKey(\Closure $fn): string
    {
        try {
            $fn();
        } catch (LocalizedException $e) {
            return $e->key;
        }
        return 'kein Fehler';
    }

    public function testCreateStoresUnderDataMcpWithTtlAndWithoutPayloadInPublicForm(): void
    {
        $p = $this->propose();
        self::assertMatchesRegularExpression(PendingChanges::ID_PATTERN, $p['id']);
        self::assertSame(['id', 'kind', 'summary', 'key_name', 'status', 'created_at', 'expires_at'], array_keys($p));
        self::assertSame(['reading', self::SUMMARY, 'open'], [$p['kind'], $p['summary'], $p['status']]);
        self::assertSame(900, strtotime($p['expires_at']) - strtotime($p['created_at']), '15 Minuten');
        self::assertFileExists($this->dataDir . '/mcp/pending.json');
        self::assertFileExists($this->dataDir . '/mcp/.pending.lock');
        self::assertSame(['utility' => 'gas', 'counter' => 12345.6], $this->pending->find($p['id'], $this->key())['payload']);
        self::assertNull($this->pending->find($p['id'], $this->key('k_fremd001')), 'fremder Schlüssel sieht ihn nicht');
        self::assertSame([$p['id']], array_column($this->pending->listOpen(), 'id'));
        self::assertArrayNotHasKey('payload', $this->pending->listOpen()[0]);

        // nicht im Backup (Entscheidung 7)
        $export = json_encode((new BackupService($this->store, $this->i18n))->export(false));
        self::assertStringNotContainsString($p['id'], (string)$export);
        self::assertStringNotContainsString('pending', (string)$export);
    }

    public function testConsumeOnceWithSameKeyAndSummary(): void
    {
        $p = $this->propose();
        $calls = 0;
        $apply = function (array $payload) use (&$calls): array { $calls++; return ['applied' => [['kind' => 'reading', 'counter' => $payload['counter']]]]; };

        self::assertSame('errors.mcp.proposal.notFound', $this->errorKey(fn() => $this->pending->consume($p['id'], $this->key('k_fremd001'), self::SUMMARY, $apply)));
        self::assertSame('errors.mcp.proposal.summaryMismatch', $this->errorKey(fn() => $this->pending->consume($p['id'], $this->key(), 'Gas: Stand 0 eintragen.', $apply)));
        self::assertSame('errors.mcp.proposal.notFound', $this->errorKey(fn() => $this->pending->consume('mp_000000000000000000000000', $this->key(), self::SUMMARY, $apply)));
        self::assertSame('errors.mcp.proposal.notFound', $this->errorKey(fn() => $this->pending->consume('../auth', $this->key(), self::SUMMARY, $apply)));
        self::assertSame(0, $calls, 'abgelehnt heißt: nichts geschrieben');

        // Leerraum zählt nicht, Wortlaut schon
        $first = $this->pending->consume($p['id'], $this->key(), '  ' . str_replace(' ', "\n ", self::SUMMARY), $apply);
        self::assertSame([false, ['applied' => [['kind' => 'reading', 'counter' => 12345.6]]]], [$first['repeated'], $first['result']]);
        $again = $this->pending->consume($p['id'], $this->key(), self::SUMMARY, $apply);
        self::assertSame([true, $first['result']], [$again['repeated'], $again['result']], 'zweites Übernehmen: gleiche Antwort');
        self::assertSame(1, $calls, 'genau einmal geschrieben');
        self::assertSame([], $this->pending->listOpen());
        self::assertNull($this->pending->find($p['id'], $this->key()));
    }

    public function testExpiryFailureCancelAndPrune(): void
    {
        $p = $this->propose();
        $this->now += 900;
        self::assertNull($this->pending->find($p['id'], $this->key()));
        self::assertSame([], $this->pending->listOpen());
        self::assertSame('errors.mcp.proposal.expired', $this->errorKey(fn() => $this->pending->consume($p['id'], $this->key(), self::SUMMARY, fn() => [])));

        $q = $this->propose();
        self::assertSame('kein Fehler', $this->errorKey(function () use ($q): void {
            try {
                $this->pending->consume($q['id'], $this->key(), self::SUMMARY, fn() => throw new \RuntimeException('Dienst lehnt ab'));
            } catch (\RuntimeException) {}
        }));
        self::assertNotNull($this->pending->find($q['id'], $this->key()), 'scheitert das Schreiben, bleibt der Vorschlag offen');

        self::assertFalse($this->pending->cancel($q['id'], $this->key('k_fremd001')));
        self::assertTrue($this->pending->cancel($q['id'], $this->key()));
        self::assertSame('errors.mcp.proposal.notFound', $this->errorKey(fn() => $this->pending->consume($q['id'], $this->key(), self::SUMMARY, fn() => [])));

        // übernommen und abgelaufen bleiben einen Tag (Meldung „abgelaufen“, gleiche Antwort), dann weg
        $r = $this->propose();
        $this->pending->consume($r['id'], $this->key(), self::SUMMARY, fn() => ['ok' => true]);
        $this->now += PendingChanges::KEEP_SECONDS;
        $this->propose();
        $ids = array_keys(json_decode((string)file_get_contents($this->dataDir . '/mcp/pending.json'), true));
        self::assertNotContains($p['id'], $ids);
        self::assertNotContains($r['id'], $ids);
        self::assertCount(1, $ids);
    }

    public function testAtMostFiftyOpenPerKey(): void
    {
        for ($i = 0; $i < PendingChanges::MAX_OPEN_PER_KEY; $i++) $this->propose();
        self::assertSame('errors.mcp.proposal.limit', $this->errorKey(fn() => $this->propose()));
        self::assertMatchesRegularExpression(PendingChanges::ID_PATTERN, $this->propose('k_ander001')['id'], 'je Schlüssel');
        $this->now += PendingChanges::TTL_SECONDS;
        self::assertMatchesRegularExpression(PendingChanges::ID_PATTERN, $this->propose()['id'], 'abgelaufene zählen nicht');
    }

    public function testClientRefMakesASecondWriteADuplicate(): void
    {
        $p = $this->propose();
        $ref = PendingChanges::clientRef($p['id']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9-]{8,64}$/', $ref);
        $in = ['meter_id' => 'm_gas_default', 'date' => date('Y-m-d'), 'counter' => 100.0, 'client_ref' => $ref, 'source' => 'mcp'];
        $first = $this->readings->create('gas', $in);
        $second = $this->readings->create('gas', $in);
        self::assertSame('mcp', $first['source'], 'Herkunft der Ablesung: mcp');
        self::assertTrue($second['duplicate'] ?? false);
        self::assertCount(1, $this->readings->list('gas', 'm_gas_default'));
    }

    public function testConsumeTakesTheGlobalLockBeforeItsOwn(): void
    {
        $lockFile = $this->dataDir . '/mcp/.pending.lock';
        $order = [];
        $store = new JsonStore($this->dataDir);
        $store->setWriteGuard(function () use (&$order, $lockFile): void {
            // die eigene Sperre darf hier noch nicht gehalten sein (sonst: gegenseitiges Warten)
            $fp = fopen($lockFile, 'c');
            $order[] = flock($fp, LOCK_EX | LOCK_NB) ? 'global, eigene frei' : 'global, eigene schon belegt';
            flock($fp, LOCK_UN);
            fclose($fp);
        });
        $pending = new PendingChanges($this->dataDir, $store, fn(): int => $this->now);
        $p = $pending->create($this->key(), 'reading', [], self::SUMMARY);
        self::assertSame([], $order, 'Anlegen holt keine globale Sperre');
        $pending->consume($p['id'], $this->key(), self::SUMMARY, function () use (&$order): array { $order[] = 'schreiben'; return []; });
        self::assertSame(['global, eigene frei', 'schreiben'], $order);
    }

    /** v3.12.0 (Skeptiker M1) — Die Übernahme in der App hält dieselbe Reihenfolge ein. */
    public function testConsumeInAppTakesTheGlobalLockBeforeItsOwn(): void
    {
        $lockFile = $this->dataDir . '/mcp/.pending.lock';
        $order = [];
        $store = new JsonStore($this->dataDir);
        $store->setWriteGuard(function () use (&$order, $lockFile): void {
            $fp = fopen($lockFile, 'c');
            $order[] = flock($fp, LOCK_EX | LOCK_NB) ? 'global, eigene frei' : 'global, eigene schon belegt';
            flock($fp, LOCK_UN);
            fclose($fp);
        });
        $pending = new PendingChanges($this->dataDir, $store, fn(): int => $this->now);
        $p = $pending->create($this->key(), 'reading', [], self::SUMMARY);
        $pending->consumeInApp($p['id'], function () use (&$order): array { $order[] = 'schreiben'; return []; });
        self::assertSame(['global, eigene frei', 'schreiben'], $order);
    }
}
