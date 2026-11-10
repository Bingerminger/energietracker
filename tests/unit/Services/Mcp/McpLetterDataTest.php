<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\ToolRegistry;
use Energietracker\Services\Mcp\Tools;

/**
 * v3.12.0 (m4b) — Die Angaben für Briefe bekommt die KI nie: Absender (Name, Anschrift) und Lieferanschrift aus dem
 * Haushalt, die Anschrift des Anbieters am Vertrag. Jedes lesende Werkzeug läuft gegen einen Haushalt, der sie trägt,
 * dazu in Notizen (Ablesung, Rechnung) und im Namen eines Zählers; keine Antwort enthält sie, auch nicht mit dem
 * Schalter `mcp_share_identifiers` (er gibt Kennnummern frei, keine Anschriften). Prompts und Ressourcen ebenso.
 * Die Dokument-Werkzeuge prüft McpLetterDocumentsTest. Alle Angaben erfunden.
 */
final class McpLetterDataTest extends McpReadTestCase
{
    /** Teile, die in keiner Antwort stehen dürfen (nur in den Angaben für Briefe vorhanden) */
    private const SECRETS = ['Quellfeld', 'Zora', 'Brombeerpfad', '54321', 'Lindenallee', '98765'];
    private const GROUPS = [Tools\MeterTools::class, Tools\ReadTools::class, Tools\ContractTools::class,
        Tools\ConsumptionTools::class, Tools\ScenarioTool::class, Tools\BillTools::class];

    private function withLetterData(): void
    {
        $this->household();
        $this->settings->set([
            'letter_sender_name' => 'Zora Quellfeld',
            'letter_sender_address' => "Brombeerpfad 17\n54321 Musterstadt",
            'letter_supply_address' => 'Brombeerpfad 17, 54321 Musterstadt',
        ]);
        $c = $this->contracts->list('gas')[0];
        $this->contracts->update('gas', (string)$c['id'], ['provider_address' => "Lindenallee 3\n98765 Musterstadt"]);
        // dieselben Angaben in Texten, die Werkzeuge zeigen: Notiz einer Ablesung, einer Rechnung, Name eines Zählers
        $readings = $this->readings->list('gas', 'm_gas_default');
        $this->readings->update('gas', (string)$readings[0]['id'], ['note' => 'Schlüssel bei Zora Quellfeld']);
        $bills = (array)$this->store->read('gas/bills.json', []);
        $bills[0]['note'] = 'Für Quellfeld, Zora, Brombeerpfad 17';
        $this->store->write('gas/bills.json', $bills);
        $this->meters->update('gas', 'm_gas_default', ['name' => 'Keller Brombeerpfad 17']);
    }

    /** @return list<string> Fundstellen „Werkzeug Argumente: Teil“ */
    private function leaks(string $label): array
    {
        $ctx = $this->context('mcp', self::TODAY);
        $out = [];
        $calls = $this->callsFor() + ['list_meters' => [['include_inactive' => true]]];
        foreach ($calls as $tool => $list) {
            foreach ($list as $args) {
                $json = (string)json_encode($this->callTool($tool, $args, $ctx), JSON_UNESCAPED_UNICODE);
                foreach (self::SECRETS as $s) if (str_contains($json, $s)) $out[] = "$label $tool " . json_encode($args) . ": $s";
            }
        }
        foreach (['prompts/list', 'resources/list', 'tools/list'] as $method) {
            $json = (string)json_encode($this->rpc($method, [], $ctx)['body'], JSON_UNESCAPED_UNICODE);
            foreach (self::SECRETS as $s) if (str_contains($json, $s)) $out[] = "$label $method: $s";
        }
        return $out;
    }

    public function testNoToolHandsTheLetterDataToTheAi(): void
    {
        $this->withLetterData();
        self::assertSame([], $this->leaks('ohne Schalter'));
    }

    public function testTheIdentifierSwitchDoesNotReleaseThem(): void
    {
        $this->withLetterData();
        $this->settings->set(['mcp_share_identifiers' => true]);
        self::assertSame([], $this->leaks('mit Schalter'));
        // Kennnummern gibt der Schalter frei, die Anschrift des Anbieters nicht
        $c = $this->callTool('get_contracts', ['utility' => 'gas'], $this->context('mcp', self::TODAY))['targets'][0]['contracts'][0];
        self::assertSame(['V-4711', 'K-0815'], [$c['untrusted']['contract_number'], $c['untrusted']['customer_number']]);
        self::assertArrayNotHasKey('provider_address', $c['untrusted']);
        self::assertArrayNotHasKey('provider_address', $c);
    }

    /** Die Notiz bleibt lesbar, nur die Angaben fallen heraus (Maske statt Weglassen). */
    public function testTextsKeepTheirSenseWithoutTheData(): void
    {
        $this->withLetterData();
        $ctx = $this->context('mcp', self::TODAY);
        $rows = $this->callTool('list_readings', ['utility' => 'gas', 'meter' => 'm_gas_default', 'limit' => 100], $ctx)['readings'];
        self::assertSame('Schlüssel bei […]', $rows[0]['untrusted']['note']);
        $meters = $this->callTool('list_meters', [], $ctx);
        self::assertStringContainsString('Keller […]', (string)json_encode($meters, JSON_UNESCAPED_UNICODE));
        // ohne Angaben für Briefe bleibt alles wie vorher
        $this->settings->set(['letter_sender_name' => '', 'letter_sender_address' => '', 'letter_supply_address' => '']);
        $rows = $this->callTool('list_readings', ['utility' => 'gas', 'meter' => 'm_gas_default', 'limit' => 100], $ctx)['readings'];
        self::assertSame('Schlüssel bei Zora Quellfeld', $rows[0]['untrusted']['note']);
    }

    /**
     * doc-claim `letter-data-private`: Die Anleitung zum KI-Assistenten verspricht, was diese Tests halten (alle drei
     * Angaben, auch mit dem Schalter, die Grenze); die Karte im Haushalt sagt dasselbe in jeder Sprache.
     */
    public function testTheDocsPromiseWhatTheseTestsHold(): void
    {
        $root = dirname(__DIR__, 4);
        $want = ['de' => ['Absenders', 'Lieferanschrift', 'Anschrift des Anbieters', 'auch', 'Schalter für Kennnummern', 'Grenze'],
                 'en' => ['sender’s name and address', 'supply address', 'supplier’s address', 'also with the switch', 'Limit']];
        foreach (['de' => 'docs/anleitungen/ki-assistent.md', 'en' => 'docs/en/anleitungen/ki-assistent.md'] as $lang => $file) {
            $src = (string)file_get_contents("$root/$file");
            $at = strpos($src, '<!-- doc-claim: letter-data-private');
            self::assertNotFalse($at, "$file: Marker fehlt");
            $bullet = (string)preg_replace('/\s+/u', ' ', substr($src, (int)strrpos(substr($src, 0, $at), "\n- "), $at - (int)strrpos(substr($src, 0, $at), "\n- ")));
            foreach ($want[$lang] as $w) self::assertStringContainsString($w, $bullet, "$file: „{$w}“");
        }
        foreach (['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'] as $lang) {
            $cat = json_decode((string)file_get_contents("$root/public/locales/$lang.json"), true, 512, JSON_THROW_ON_ERROR);
            self::assertMatchesRegularExpression('/KI|AI|IA/u', (string)$cat['settings']['group']['letters']['hint'], "$lang: die Karte nennt die KI");
        }
    }

    /** Jedes lesende Werkzeug läuft hier (ein neues muss in callsFor, sonst rot). */
    public function testEveryReadingToolIsCovered(): void
    {
        $names = array_map(fn($t) => $t->name, (new ToolRegistry($this->mcpServices(), self::GROUPS))->all());
        $called = [...array_keys($this->callsFor()), 'list_meters'];
        sort($names);
        sort($called);
        self::assertSame($names, $called);
        $all = array_map(fn($t) => $t->name, (new ToolRegistry($this->mcpServices()))->all());
        $docs = ['list_documents', 'get_document', 'get_document_schema', 'read_document', 'propose_reading', 'propose_document', 'apply_proposal', 'cancel_proposal'];
        self::assertSame([], array_values(array_diff($all, $names, $docs)), 'ein Werkzeug ohne Prüfung der Angaben für Briefe');
    }
}
