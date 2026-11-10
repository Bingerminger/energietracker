<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\PendingChanges;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037, Paket oberflaeche) — Vorschläge in der App ablehnen
 * (`DELETE /api/mcp/proposals/{id}`): jeder offene Vorschlag, gleich von welchem
 * Schlüssel; danach lässt er sich nie übernehmen, die KI erfährt „abgelehnt“
 * statt „unbekannt“, und die Nutzdaten sind weg.
 */
final class McpProposalRejectTest extends McpTestCase
{
    private const SUMMARY = 'Gas, Zähler Keller: Stand 12 345,6 m³ am 02.11.2026 eintragen (geschätzt: nein).';

    private function key(string $id = 'k_test0001'): McpContext
    {
        return $this->context('mcp_write', '2027-01-15', 'de', $id);
    }

    private function propose(string $keyId = 'k_test0001'): array
    {
        return $this->pending->create($this->key($keyId), 'reading', ['utility' => 'gas', 'counter' => 12345.6], self::SUMMARY);
    }

    public function testRejectedProposalLeavesTheListAndCanNeverBeApplied(): void
    {
        $mine = $this->propose();
        $other = $this->propose('k_fremd001');

        self::assertTrue($this->pending->reject($other['id']), 'die App lehnt auch Vorschläge eines anderen Schlüssels ab');
        self::assertSame([$mine['id']], array_column($this->pending->listOpen(), 'id'));
        self::assertNull($this->pending->find($other['id'], $this->key('k_fremd001')));

        $calls = 0;
        try {
            $this->pending->consume($other['id'], $this->key('k_fremd001'), self::SUMMARY, function () use (&$calls): array { $calls++; return []; });
            self::fail('ein abgelehnter Vorschlag darf nicht übernommen werden');
        } catch (LocalizedException $e) {
            self::assertSame('errors.mcp.proposal.rejected', $e->key);
            self::assertSame($other['id'], $e->params['id']);
        }
        self::assertSame(0, $calls, 'nichts geschrieben');

        // Nutzdaten sind weg, die Zeile bleibt für die Meldung stehen
        $stored = json_decode((string)file_get_contents($this->dataDir . '/mcp/pending.json'), true);
        self::assertSame('rejected', $stored[$other['id']]['status']);
        self::assertArrayNotHasKey('payload', $stored[$other['id']]);
        self::assertFalse($this->pending->cancel($other['id'], $this->key('k_fremd001')), 'verwerfen geht nur, solange offen');

        // ein fremder Schlüssel erfährt weiter nichts („unbekannt“)
        try {
            $this->pending->consume($other['id'], $this->key(), self::SUMMARY, fn(): array => []);
            self::fail('fremder Schlüssel');
        } catch (LocalizedException $e) {
            self::assertSame('errors.mcp.proposal.notFound', $e->key);
        }
    }

    public function testOnlyOpenValidProposalsCanBeRejected(): void
    {
        $p = $this->propose();
        self::assertFalse($this->pending->reject('mp_000000000000000000000000'), 'unbekannt');
        self::assertFalse($this->pending->reject('../pending'), 'Form der Kennung');
        self::assertTrue($this->pending->reject($p['id']));
        self::assertFalse($this->pending->reject($p['id']), 'schon abgelehnt');

        $applied = $this->propose();
        $this->pending->consume($applied['id'], $this->key(), self::SUMMARY, fn(): array => ['applied' => []]);
        self::assertFalse($this->pending->reject($applied['id']), 'schon übernommen');

        $old = $this->propose();
        $this->now += PendingChanges::TTL_SECONDS;
        self::assertFalse($this->pending->reject($old['id']), 'abgelaufen');

        // abgelehnte fallen wie abgelaufene einen Tag nach Ablauf weg
        $this->now += PendingChanges::KEEP_SECONDS;
        $this->propose();
        $stored = json_decode((string)file_get_contents($this->dataDir . '/mcp/pending.json'), true);
        self::assertArrayNotHasKey($p['id'], $stored);
    }
}
