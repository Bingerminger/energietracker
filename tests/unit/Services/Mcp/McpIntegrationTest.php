<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Mcp\Journal;
use Energietracker\Services\Mcp\RateLimiter;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolRegistry;
use Energietracker\Services\Mcp\Tools\WriteTools;
use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.12.0 (integration) — Was zwischen den Paketen liegt: Töpfe der Ratenbegrenzung gegen die
 * Registry, Beschriftungen aller Vorschlagsarten, Feldvertrag des Zugriffsprotokolls zwischen
 * Server und Karte, die eine Erklärseite je Nummer, der Link der Karte auf die Anleitung.
 */
final class McpIntegrationTest extends McpInboxTestCase
{
    private const LOCALES = ['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'];

    private static function file(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 4) . '/' . $rel);
    }

    /** @return array<string,mixed> */
    private static function catalog(string $locale): array
    {
        return json_decode(self::file("public/locales/$locale.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function at(array $cat, string $key): mixed
    {
        foreach (explode('.', $key) as $k) {
            if (!is_array($cat) || !array_key_exists($k, $cat)) return null;
            $cat = $cat[$k];
        }
        return $cat;
    }

    // ── Ratenbegrenzung ──────────────────────────────────────────────────

    public function testEveryWritingToolHasItsBucket(): void
    {
        $seen = [];
        foreach ((new ToolRegistry($this->mcpServices()))->all() as $tool) {
            $bucket = RateLimiter::bucketForTool($tool->name);
            $seen[$tool->name] = $bucket;
            if ($tool->scope === Tool::READ) {
                self::assertSame('call', $bucket, "{$tool->name}: lesend");
            } elseif (!in_array($tool->name, RateLimiter::WRITE_TOOLS_IN_CALL, true)) {
                self::assertContains($bucket, ['propose', 'apply'], "{$tool->name}: ein Schreibwerkzeug im Lesetopf; Topf wählen oder ausdrücklich in WRITE_TOOLS_IN_CALL");
            }
        }
        // von Hand: die fünf Schreibwerkzeuge der Doku
        $written = array_intersect_key($seen, array_flip(McpDocsTest::WRITE_TOOLS));
        ksort($written);
        self::assertSame(['apply_proposal' => 'apply', 'cancel_proposal' => 'call', 'propose_document' => 'propose', 'propose_reading' => 'propose', 'read_document' => 'propose'], $written);
        self::assertSame(['cancel_proposal'], RateLimiter::WRITE_TOOLS_IN_CALL);
    }

    public function testDiscardingStillWorksWhenTheProposalsOfTheHourAreUsedUp(): void
    {
        $this->gasHousehold();
        $limiter = new RateLimiter($this->dataDir, fn(): int => $this->now);
        $services = $this->mcpServices();
        $server = new \Energietracker\Services\Mcp\Server(new ToolRegistry($services), new \Energietracker\Services\Mcp\Prompts($services, $this->i18n),
            new \Energietracker\Services\Mcp\Resources($services, $this->i18n), $this->i18n, '9.9.9', fn(\Throwable $e) => throw $e, $limiter);
        $call = function (string $name, array $args) use ($server): array {
            $reply = $server->handle((string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]), $this->writer(), []);
            return ['status' => $reply['status'], 'result' => json_decode((string)json_encode($reply['body']), true)['result'] ?? null];
        };
        $first = $call('propose_reading', ['utility' => 'gas', 'meter' => 'm_gas_default', 'counter' => 5000, 'date' => self::TODAY]);
        self::assertSame(200, $first['status']);
        $id = $first['result']['structuredContent']['proposal_id'];
        for ($i = 1; $i < RateLimiter::LIMITS['propose'][0]; $i++) $limiter->hit('propose', 'k_test0001');
        self::assertSame(429, $call('propose_reading', ['utility' => 'gas', 'meter' => 'm_gas_default', 'counter' => 5001, 'date' => self::TODAY])['status'], 'Topf voll');
        $cancel = $call('cancel_proposal', ['proposal_id' => $id]);
        self::assertSame([200, true], [$cancel['status'], $cancel['result']['structuredContent']['cancelled']], 'verwerfen geht trotzdem');
    }

    // ── Beschriftungen ───────────────────────────────────────────────────

    public function testEveryKindOfProposalHasItsLabels(): void
    {
        $order = (new \ReflectionClass(Applier::class))->getConstant('ORDER');
        $kinds = WriteTools::KINDS;
        sort($kinds);
        sort($order);
        self::assertSame($order, $kinds, 'WriteTools::KINDS = Arten, die Inbox\Applier übernimmt');
        foreach (self::LOCALES as $l) {
            $cat = self::catalog($l);
            foreach (WriteTools::KINDS as $k) {
                self::assertIsString(self::at($cat, "mcp.summary.kind.$k"), "$l: mcp.summary.kind.$k");
            }
            foreach (WriteTools::PROPOSAL_KINDS as $k) {
                self::assertIsString(self::at($cat, "settings.mcp.kind.$k"), "$l: settings.mcp.kind.$k (Karte, offene Vorschläge)");
            }
        }
    }

    public function testTheSupplyKindIsSpelledOutInTheSummary(): void
    {
        $contract = $this->gasHousehold();
        $this->inbox->providers()->register(InboxFixture::provider('local', ['Jahresabrechnung' => 'invoice'], ['invoice' => InboxFixture::invoiceAnswer()
            + ['basic_supply' => ['value' => true, 'quote' => 'Grundversorgung', 'page' => 1]]]));
        $pages = InboxFixture::invoicePages();
        $pages[0] .= "\nGrundversorgung";
        $id = (string)$this->inbox->upload(InboxFixture::pdf('gv'), 'gv.pdf')['id'];
        $this->inbox->extract($id, $pages);
        $doc = $this->callTool('get_document', ['document_id' => $id], $this->writer());
        $row = array_values(array_filter($doc['proposals'], fn($p) => $p['kind'] === 'contract_supply_kind'));
        self::assertNotSame([], $row, 'Fall des Tests: Vorschlag zur Versorgungsart am Vertrag ' . $contract['id']);
        self::assertSame('Versorgungsart am Vertrag', $row[0]['label']);
        $out = $this->callTool('propose_document', ['document_id' => $id, 'proposals' => [$row[0]['id']]], $this->writer());
        self::assertStringContainsString('Versorgungsart am Vertrag (Grundversorgung)', $out['summary'], 'die Person sieht, was sie bestätigt');
    }

    // ── Feldvertrag des Zugriffsprotokolls ───────────────────────────────

    public function testTheCardReadsTheFieldsTheJournalWrites(): void
    {
        $card = self::file('public/js/components/mcp-card.js');
        $start = (int)strpos($card, 'data-mcp-journal');
        self::assertGreaterThan(0, $start, 'Zeile des Protokolls');
        preg_match_all('/\be\.([a-z_]+)/', substr($card, $start, 800), $m);
        $read = array_values(array_unique($m[1]));
        self::assertNotSame([], $read);
        self::assertSame([], array_values(array_diff($read, Journal::ENTRY_FIELDS)), 'die Karte liest Felder, die das Protokoll nicht schreibt');
        self::assertContains('ms', $read, 'die Dauer heißt `ms`');
        self::assertStringNotContainsString('duration_ms', $card);
        // das nachgestellte Protokoll im Render-Test hat dieselben Felder
        $render = self::file('tests/browser-render.test.mjs');
        preg_match('/const journal = Array\.from\(\{ length: 60 \}.*?\}\)\);/s', $render, $f);
        preg_match_all('/\b([a-z_]+):/', str_replace(['length:'], '', $f[0] ?? ''), $keys);
        self::assertSame([], array_values(array_diff(array_diff($keys[1], ['name', 'version']), Journal::ENTRY_FIELDS)), 'Fixture = Feldvertrag');
        self::assertSame(Journal::ENTRY_FIELDS, ['at', 'key_id', 'key_name', 'client', 'method', 'name', 'result', 'status', 'code', 'error_id', 'version', 'ms']);
        foreach (self::LOCALES as $l) {
            $results = self::at(self::catalog($l), 'settings.mcp.result');
            self::assertIsArray($results);
            $names = array_keys($results);
            sort($names);
            $want = Journal::RESULTS;
            sort($want);
            self::assertSame($want, $names, "$l: settings.mcp.result = Journal::RESULTS");
        }
    }

    // ── Doku ─────────────────────────────────────────────────────────────

    public function testEveryExplainerPageHasItsOwnNumber(): void
    {
        foreach (['docs/verstehen', 'docs/en/verstehen'] as $dir) {
            $seen = [];
            foreach ((array)glob(dirname(__DIR__, 4) . "/$dir/*.md") as $path) {
                if (!preg_match('/^(\d+)-/', basename((string)$path), $m)) continue;
                self::assertArrayNotHasKey($m[1], $seen, "$dir: zweimal die Nummer {$m[1]}: " . basename((string)$path));
                $seen[$m[1]] = basename((string)$path);
            }
            self::assertSame('17-rechte.md', $seen['17'], 'in v3.11.0 veröffentlicht: bleibt');
            self::assertSame('18-herkunft.md', $seen['18']);
        }
    }

    public function testTheCardLinksTheGuide(): void
    {
        $card = self::file('public/js/components/mcp-card.js');
        self::assertStringContainsString("docUrl('aiAssistant')", $card);
        self::assertStringContainsString("import { docUrl } from '../lib/docs.js'", $card);
    }
}
