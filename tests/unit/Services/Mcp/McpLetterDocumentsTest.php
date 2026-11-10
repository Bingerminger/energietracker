<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.12.0 (m4b) — Dokument-Werkzeuge: Steht auf der Versorgerpost der Name oder die Anschrift aus den Angaben für
 * Briefe, ersetzt die Maske sie in Zitaten, Textwerten und im Dateinamen durch „[…]“, ohne und mit
 * `mcp_share_identifiers`. Grenze: Das Original (`mcp_share_documents`) zeigt das Dokument, wie es ist. Erfunden.
 */
final class McpLetterDocumentsTest extends McpInboxTestCase
{
    private const SECRETS = ['Quellfeld', 'Zora', 'Brombeerpfad', '54321'];

    /** @return array<string,mixed> */
    private function readWithAddress(string $id): array
    {
        $pages = InboxFixture::invoicePages();
        $pages[0] = "Frau Zora Quellfeld\nBrombeerpfad 17\n54321 Musterstadt\n" . $pages[0];
        $fields = InboxFixture::invoiceAnswer();
        $fields['kind'] = ['value' => 'annual', 'quote' => "Frau Zora Quellfeld\nBrombeerpfad 17", 'page' => 1];
        $fields['supplier'] = ['value' => 'Stadtwerke Musterstadt GmbH', 'quote' => 'Stadtwerke Musterstadt GmbH', 'page' => 1];
        return $this->callTool('read_document', ['document_id' => $id, 'type' => 'invoice', 'pages' => $pages, 'fields' => $fields], $this->writer());
    }

    private static function leaks(array $out): array
    {
        $json = (string)json_encode($out, JSON_UNESCAPED_UNICODE);
        return array_values(array_filter(self::SECRETS, fn($s) => str_contains($json, $s)));
    }

    public function testQuotesFromTheMailCarryNoAddressOfTheHousehold(): void
    {
        $this->gasHousehold();
        $this->settings->set(['letter_sender_name' => 'Zora Quellfeld', 'letter_sender_address' => "Brombeerpfad 17\n54321 Musterstadt"]);
        $id = (string)$this->inbox->upload(InboxFixture::pdf('adr'), 'Rechnung Quellfeld Brombeerpfad.pdf')['id'];
        $doc = $this->readWithAddress($id);
        self::assertSame([], self::leaks($doc), 'read_document');
        foreach (['list_documents' => [], 'get_document' => ['document_id' => $id]] as $tool => $args) {
            self::assertSame([], self::leaks($this->callTool($tool, $args, $this->writer())), $tool);
        }
        $kind = array_values(array_filter($doc['fields'], fn($f) => $f['name'] === 'kind'))[0];
        self::assertStringContainsString('[…]', (string)($kind['untrusted']['quote'] ?? '[…]'), 'das Zitat bleibt, ohne die Angaben');

        // Kennnummern freigegeben: die Anschrift bleibt zurück
        $this->settings->set(['mcp_share_identifiers' => true]);
        foreach (['list_documents' => [], 'get_document' => ['document_id' => $id]] as $tool => $args) {
            self::assertSame([], self::leaks($this->callTool($tool, $args, $this->writer())), "$tool mit Schalter");
        }
        self::assertStringContainsString('K-100200', (string)json_encode($this->callTool('get_document', ['document_id' => $id], $this->writer())),
            'der Schalter wirkt weiter für Kennnummern');
    }

    /**
     * Posteingang und Auslesen (lokal wie in der Cloud, dieselbe Schnittstelle ExtractionProvider): Der Dienst bekommt
     * die Textebene des Dokuments und die Felder der Art, nie Angaben aus dem Haushalt. Die Angaben für Briefe stehen
     * hier nicht im Dokument; kämen sie beim Dienst an, hätte die App sie mitgeschickt.
     */
    public function testThePostboxSendsNoLetterDataToTheReader(): void
    {
        $this->gasHousehold();
        $this->settings->set(['letter_sender_name' => 'Zora Quellfeld', 'letter_sender_address' => "Brombeerpfad 17\n54321 Musterstadt",
            'letter_supply_address' => 'Brombeerpfad 17, 54321 Musterstadt']);
        $c = $this->contracts->list('gas')[0] ?? null;
        if ($c !== null) $this->contracts->update('gas', (string)$c['id'], ['provider_address' => "Lindenallee 3\n98765 Musterstadt"]);
        $p = InboxFixture::provider('local', ['Jahresabrechnung' => 'invoice'], ['invoice' => InboxFixture::invoiceAnswer()]);
        $proposals = new \Energietracker\Services\Inbox\Proposals($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings);
        $inbox = new \Energietracker\Services\InboxService($this->store, $this->i18n, $this->att,
            new \Energietracker\Services\Inbox\ProviderRegistry($this->settings, $p), $proposals,
            new \Energietracker\Services\Inbox\Applier($this->meters, $this->contracts, $this->readings, $this->bills, $this->reminders, $this->settings, $this->i18n));
        $id = (string)$inbox->upload(InboxFixture::pdf('post'), 'Rechnung.pdf')['id'];
        $inbox->extract($id, InboxFixture::invoicePages());
        self::assertSame([1, 1], [$p->classifyCalls, $p->extractCalls], 'erkannt und ausgelesen');
        $sent = (string)$p->lastText . json_encode($p->lastSchema, JSON_UNESCAPED_UNICODE);
        foreach ([...self::SECRETS, 'Lindenallee', '98765'] as $s) self::assertStringNotContainsString($s, $sent, "an den Dienst: $s");
    }

    public function testWithoutLetterDataNothingChanges(): void
    {
        $this->gasHousehold();
        $id = (string)$this->inbox->upload(InboxFixture::pdf('adr0'), 'Rechnung.pdf')['id'];
        $doc = $this->readWithAddress($id);
        $kind = array_values(array_filter($doc['fields'], fn($f) => $f['name'] === 'kind'))[0];
        self::assertSame('Frau Zora Quellfeld Brombeerpfad 17', $kind['untrusted']['quote'] ?? null, 'ohne Angaben für Briefe keine Maske');
    }
}
