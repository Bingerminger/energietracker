<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.12.0 — Die Belegprüfung an Zahlgrenzen (InboxEvidenceBoundaryTest) gilt auf allen
 * drei Wegen ins Dokument: Auslesen lokal, Auslesen in der Cloud, Auslesen durch die KI
 * (`read_document`). Stets dasselbe erfundene Feld „Zählerstand 0 m³“ im Text „13.580 m³“:
 * Es bekommt die Marke „zu prüfen“, und über die KI lässt sich der Stand nicht übernehmen.
 */
final class McpEvidenceBoundaryTest extends McpInboxTestCase
{
    /** Antwort wie gedruckt, nur der neue Stand ist erfunden. */
    private static function answer(): array
    {
        $a = InboxFixture::invoiceAnswer();
        $a['reading_new'] = ['value' => 0, 'quote' => 'Zählerstand neu am 31.12.2025: 0 m³', 'page' => 1];
        return $a;
    }

    private function field(string $id): array
    {
        return (array)$this->inbox->get($id)['extraction']['fields']['reading_new'];
    }

    private function assertFlagged(string $id, string $way): void
    {
        $f = $this->field($id);
        self::assertSame('check', $f['status'], "$way: Marke");
        self::assertContains('quote_not_found', $f['reasons'], "$way: Zitat nicht im Text");
    }

    /** Andere Felder der Antwort sind wie gedruckt und bleiben belegt. */
    private function assertPrintedFieldsStayOk(string $id, string $way): void
    {
        $fields = (array)$this->inbox->get($id)['extraction']['fields'];
        foreach (['reading_old', 'total_amount', 'result_amount'] as $name) {
            self::assertSame('ok', $fields[$name]['status'], "$way: $name");
        }
    }

    public function testLocalExtraction(): void
    {
        $this->inbox->providers()->register(InboxFixture::provider('local', ['Jahresabrechnung' => 'invoice'], ['invoice' => self::answer()]));
        $id = (string)$this->inbox->upload(InboxFixture::pdf('lokal'), 'lokal.pdf')['id'];
        $this->inbox->extract($id, InboxFixture::invoicePages());
        $this->assertFlagged($id, 'lokal');
        $this->assertPrintedFieldsStayOk($id, 'lokal');
    }

    public function testCloudExtraction(): void
    {
        $this->inbox->providers()->register(InboxFixture::provider('openai', ['Jahresabrechnung' => 'invoice'], ['invoice' => self::answer()]));
        // die Einstellung selbst verlangt einen eingerichteten Anbieter (CloudConfig); hier zählt nur die Prüfung der Antwort
        $this->store->write('settings.json', ['inbox_provider' => 'openai'] + (array)$this->store->read('settings.json', []));
        $id = (string)$this->inbox->upload(InboxFixture::pdf('cloud'), 'cloud.pdf')['id'];
        $this->inbox->extract($id, InboxFixture::invoicePages(), true);
        $this->assertFlagged($id, 'Cloud');
        $this->assertPrintedFieldsStayOk($id, 'Cloud');
    }

    public function testReadDocumentByTheAi(): void
    {
        $this->gasHousehold();
        $id = (string)$this->inbox->upload(InboxFixture::pdf('ki'), 'ki.pdf')['id'];
        $doc = $this->callTool('read_document', ['document_id' => $id, 'type' => 'invoice', 'pages' => InboxFixture::invoicePages(), 'fields' => self::answer()], $this->writer());
        $this->assertFlagged($id, 'KI');
        $this->assertPrintedFieldsStayOk($id, 'KI');
        $row = array_values(array_filter($doc['fields'], fn($f) => $f['name'] === 'reading_new'))[0];
        self::assertSame(['check', ['quote_not_found']], [$row['status'], array_intersect($row['reasons'], ['quote_not_found'])], 'die KI sieht die Marke');
        // eine Ablesung aus diesem Stand ist nicht vorbelegt; ausdrücklich gewählt lehnt der Server sie ab
        $reading = array_values(array_filter($doc['proposals'], fn($p) => $p['kind'] === 'reading'));
        foreach ($reading as $p) {
            if (!$p['check']) continue;
            self::assertFalse($p['checked']);
            self::assertSame('errors.mcp.document.needsReview',
                $this->callToolError('propose_document', ['document_id' => $id, 'proposals' => [$p['id']]], $this->writer())['code']);
        }
    }

    /**
     * v3.12.0 — Eine Nummer direkt hinter ihrer Bezeichnung belegt keinen Wert, auch über die KI: Der Verbrauch mit
     * dem Zitat „Vertragskonto 13216“ (erfundene Zeile, gleich groß wie der gedruckte Verbrauch) war belegt.
     */
    public function testANumberBehindItsLabelIsNoEvidenceForTheAi(): void
    {
        $this->gasHousehold();
        $pages = InboxFixture::invoicePages();
        $pages[0] .= "\nVertragskonto 13216";
        $answer = InboxFixture::invoiceAnswer();
        $answer['consumption'] = ['value' => '13216', 'quote' => 'Vertragskonto 13216', 'page' => 1];
        $id = (string)$this->inbox->upload(InboxFixture::pdf('konto'), 'konto.pdf')['id'];
        $doc = $this->callTool('read_document', ['document_id' => $id, 'type' => 'invoice', 'pages' => $pages, 'fields' => $answer], $this->writer());
        $row = array_values(array_filter($doc['fields'], fn($f) => $f['name'] === 'consumption'))[0];
        self::assertSame('check', $row['status']);
        self::assertContains('value_not_in_quote', $row['reasons']);
        // mit dem gedruckten Zitat ist derselbe Wert belegt
        $answer['consumption'] = ['value' => '13216', 'quote' => '= 13.216 kWh', 'page' => 1];
        $ok = (string)$this->inbox->upload(InboxFixture::pdf('konto2'), 'konto2.pdf')['id'];
        $doc = $this->callTool('read_document', ['document_id' => $ok, 'type' => 'invoice', 'pages' => $pages, 'fields' => $answer], $this->writer());
        self::assertSame('ok', array_values(array_filter($doc['fields'], fn($f) => $f['name'] === 'consumption'))[0]['status']);
    }

    public function testTheSameAnswerWithThePrintedValueIsFine(): void
    {
        $this->gasHousehold();
        $id = (string)$this->inbox->upload(InboxFixture::pdf('gut'), 'gut.pdf')['id'];
        $this->callTool('read_document', ['document_id' => $id, 'type' => 'invoice', 'pages' => InboxFixture::invoicePages(),
            'fields' => InboxFixture::invoiceAnswer()], $this->writer());
        self::assertSame(['ok', 13580.0], [$this->field($id)['status'], (float)$this->field($id)['value']]);
    }
}
