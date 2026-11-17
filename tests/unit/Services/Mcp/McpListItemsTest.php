<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.13.0 (Paket F) — Entscheidungen je Listeneintrag (Inbox\ItemEdits) sieht auch die KI: `get_document` nennt
 * bestätigte und korrigierte Einträge (`confirmed`, `origin: corrected`) und lässt verworfene weg; ein Vorschlag
 * aus einem bestätigten Eintrag ist ohne Prüfmarke und damit über `propose_document` wählbar.
 */
final class McpListItemsTest extends McpInboxTestCase
{
    private function document(): string
    {
        $this->gasHousehold();
        $this->readings->create('gas', ['meter_id' => 'm_gas_default', 'date' => '2024-12-31', 'counter' => 12345]);
        $id = (string)$this->inbox->upload(InboxFixture::pdf('mcp-liste'), 'Jahresabrechnung.pdf')['id'];
        $this->callTool('read_document', ['document_id' => $id, 'type' => 'invoice', 'pages' => InboxFixture::invoicePages(),
            'fields' => InboxFixture::invoiceAnswer()], $this->writer());
        return $id;
    }

    /** @return array<string,mixed>|null */
    private static function field(array $doc, string $name): ?array
    {
        foreach ($doc['fields'] as $f) if ($f['name'] === $name) return $f;
        return null;
    }

    public function testTheAiSeesTheDecisionsOfThePerson(): void
    {
        $id = $this->document();
        $doc = $this->callTool('get_document', ['document_id' => $id], $this->writer());
        self::assertSame('check', self::field($doc, 'working_prices')['items'][0]['status']);
        $price = array_values(array_filter($doc['proposals'], fn($p) => $p['kind'] === 'contract_price'))[0];
        $err = $this->callToolError('propose_document', ['document_id' => $id, 'proposals' => [$price['id']]], $this->writer());
        self::assertSame('errors.mcp.document.needsReview', $err['code'], 'markiert: nur in der App');

        // in der App: Preis bestätigt, Gas-Umrechnung verworfen, ein Abschlag korrigiert, der andere verworfen
        $this->inbox->update($id, ['item_edits' => [
            'working_prices' => ['0' => ['action' => 'confirm']],
            'gas_factors'    => ['0' => ['action' => 'discard']],
            'advances_paid'  => ['0' => ['action' => 'correct', 'value' => ['amount' => 845]], '1' => ['action' => 'discard']],
        ]]);
        $doc = $this->callTool('get_document', ['document_id' => $id], $this->writer());
        $wp = self::field($doc, 'working_prices')['items'][0];
        self::assertSame(['ok', true], [$wp['status'], $wp['confirmed']]);
        self::assertNull(self::field($doc, 'gas_factors'), 'ganz verworfen: das Feld fehlt');
        self::assertCount(1, self::field($doc, 'advances_paid')['items'], 'verworfen: nicht für die KI');
        $adv = self::field($doc, 'advances_paid')['items'][0];
        self::assertSame('corrected', $adv['origin']);
        self::assertSame(845.0, (float)array_column($adv['values'], 'value', 'name')['amount']);

        $price = array_values(array_filter($doc['proposals'], fn($p) => $p['kind'] === 'contract_price'))[0];
        self::assertFalse($price['check']);
        $prop = $this->callTool('propose_document', ['document_id' => $id, 'proposals' => [$price['id']]], $this->writer());
        self::assertSame([$price['id']], array_column($prop['items'], 'id'), 'bestätigt: wählbar');
        self::assertSame([], array_values(array_filter($doc['proposals'], fn($p) => $p['kind'] === 'gas_factor')), 'kein Vorschlag aus Verworfenem');
    }
}
