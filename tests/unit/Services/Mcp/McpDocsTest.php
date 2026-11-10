<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\App;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\Mcp\Config;
use Energietracker\Services\Mcp\Num;
use Energietracker\Services\Mcp\PendingChanges;
use Energietracker\Services\Mcp\Prompts;
use Energietracker\Services\Mcp\Provenance;
use Energietracker\Services\Mcp\RateLimiter;
use Energietracker\Services\Mcp\Resources;
use Energietracker\Services\Mcp\Sanitize;
use Energietracker\Services\Mcp\Server;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolRegistry;

/**
 * v3.12.0 (F1037, Paket doku-vorlagen) — Was die Doku der KI-Schnittstelle
 * zusagt, hält dieser Test (Lektion 31, 99): Werkzeug-, Prompt- und
 * Ressourcenlisten in API-Referenz und Anleitung gleich Registry, Prompts und
 * Ressourcen; Grenzen in Worten gleich den Konstanten (Gültigkeit eines
 * Vorschlags, offene Vorschläge, Körper, Original-PDF, Fremdtext, Ratengrenzen,
 * Tage für „Was steht an?“, ET_MCP); Herkunft und Vorzeichen in der
 * Erklärseite und in den Server-Anweisungen gleich dem Vokabular.
 *
 * Abschnitte mit Listen stehen zwischen `<!-- mcp-tools -->` … `<!-- /mcp-tools -->`
 * (ebenso `mcp-prompts`, `mcp-resources`).
 */
final class McpDocsTest extends McpTestCase
{
    /** Die Werkzeuge von v3.12.0 (Konzept §3.2, §3.3) in der Reihenfolge der Doku. */
    public const TOOLS = [
        'get_overview', 'list_meters', 'get_deadlines', 'get_contracts', 'get_consumption', 'get_forecast',
        'run_scenario', 'list_readings', 'list_bills', 'check_bill', 'get_insights', 'get_yearly_report',
        'list_documents', 'get_document', 'get_document_schema',
        'read_document', 'propose_reading', 'propose_document', 'apply_proposal', 'cancel_proposal',
    ];

    /** Bereich `mcp_write`; alle anderen `mcp`. */
    public const WRITE_TOOLS = ['read_document', 'propose_reading', 'propose_document', 'apply_proposal', 'cancel_proposal'];

    /**
     * Werkzeuge der Fachpakete „lesen“ und „schreiben“, die beim parallelen Bau
     * auf der Basis noch fehlen. BEIM ZUSAMMENFÜHREN LEEREN: Danach muss die
     * Registry genau TOOLS sein; spätestens mit VERSION 3.12.0 hält der Test das fest.
     */
    public const NOT_YET_MERGED = [];

    /** Seiten mit den Listen der Schnittstelle (DE und EN). */
    private const LIST_PAGES = ['docs/referenz/api.md', 'docs/en/referenz/api.md',
        'docs/anleitungen/ki-assistent.md', 'docs/en/anleitungen/ki-assistent.md'];

    private static function file(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 4) . '/' . $rel);
    }

    private static function flat(string $rel): string
    {
        return (string)preg_replace('/\s+/u', ' ', self::file($rel));
    }

    private static function section(string $rel, string $name): string
    {
        $ok = preg_match('/<!-- ' . $name . ' -->(.*?)<!-- \/' . $name . ' -->/s', self::file($rel), $m);
        self::assertSame(1, $ok, "$rel: Abschnitt $name fehlt");
        return $m[1];
    }

    /** @return list<string> Werkzeugnamen in Backticks eines Abschnitts (ohne Bereiche) */
    private static function toolNames(string $text): array
    {
        preg_match_all('/`([a-z]+_[a-z_]+)`/', $text, $m);
        return array_values(array_unique(array_diff($m[1], ['mcp_write'])));
    }

    /** @return list<string> */
    private static function sorted(array $a): array
    {
        $a = array_values($a);
        sort($a);
        return $a;
    }

    /** @return list<string> */
    private function registered(): array
    {
        return array_map(fn(Tool $t) => $t->name, (new ToolRegistry($this->mcpServices()))->all());
    }

    public function testRegistryAndDocumentedToolsAgree(): void
    {
        $registered = $this->registered();
        self::assertSame([], array_values(array_diff($registered, self::TOOLS)), 'Werkzeug ohne Doku: in TOOLS, api.md und ki-assistent.md (DE+EN) eintragen');
        $missing = array_values(array_diff(self::TOOLS, $registered));
        self::assertSame([], array_values(array_diff($missing, self::NOT_YET_MERGED)), 'dokumentiert, aber nicht registriert');
        if (version_compare(trim(self::file('VERSION')), '3.12.0', '>=')) {
            self::assertSame([], self::NOT_YET_MERGED, 'NOT_YET_MERGED vor dem Release leeren');
        }
        foreach ((new ToolRegistry($this->mcpServices()))->all() as $tool) {
            self::assertSame(in_array($tool->name, self::WRITE_TOOLS, true) ? Tool::WRITE : Tool::READ, $tool->scope, $tool->name);
        }
    }

    public function testDocsListExactlyTheToolsWithTheirScope(): void
    {
        foreach (self::LIST_PAGES as $rel) {
            $section = self::section($rel, 'mcp-tools');
            self::assertSame(self::sorted(self::TOOLS), self::sorted(self::toolNames($section)), "$rel: Werkzeugliste");
        }
        foreach (['docs/referenz/api.md', 'docs/en/referenz/api.md'] as $rel) {
            preg_match_all('/^\| `([a-z_]+)` \| `(mcp|mcp_write)` \|/m', self::section($rel, 'mcp-tools'), $rows, PREG_SET_ORDER);
            self::assertCount(count(self::TOOLS), $rows, "$rel: eine Zeile je Werkzeug");
            foreach ($rows as [, $name, $scope]) {
                self::assertSame(in_array($name, self::WRITE_TOOLS, true) ? 'mcp_write' : 'mcp', $scope, "$rel: Bereich von $name");
            }
            self::assertSame(self::TOOLS, array_column($rows, 1), "$rel: Reihenfolge wie TOOLS");
        }
    }

    public function testDocsListThePromptsAndResources(): void
    {
        $this->settings->set(['mcp_share_documents' => true]);
        $res = $this->rpc('resources/list')['body']['result']['resources'];
        $tpl = $this->rpc('resources/templates/list')['body']['result']['resourceTemplates'];
        $uris = [...array_column($res, 'uri'), ...array_column($tpl, 'uriTemplate')];
        self::assertCount(4, $uris, 'vier Ressourcen laut Konzept');
        foreach (self::LIST_PAGES as $rel) {
            preg_match_all('/^\| `([a-z_]+)` \|/m', self::section($rel, 'mcp-prompts'), $p);
            self::assertSame(array_keys(Prompts::PROMPTS), $p[1], "$rel: Prompts");
            foreach (Prompts::PROMPTS as $name => $args) {
                preg_match('/^\| `' . $name . '` \|(.*)$/m', self::section($rel, 'mcp-prompts'), $row);
                foreach (array_keys($args) as $arg) self::assertStringContainsString("`$arg`", $row[1], "$rel: $name.$arg");
            }
            preg_match_all('/^\| `(energietracker:\/\/[^`]+)` \|/m', self::section($rel, 'mcp-resources'), $r);
            self::assertSame($uris, $r[1], "$rel: Ressourcen");
        }
        // die Anleitung nennt die Ressource der Erklärseite, und es gibt sie
        self::assertStringContainsString('energietracker://hilfe/herkunft', self::file('docs/verstehen/18-herkunft.md'));
    }

    /**
     * Grenzen in Worten an den Konstanten. Je Behauptung: Seite → Sätze (DE bzw. EN).
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function claims(): array
    {
        $min = intdiv(PendingChanges::TTL_SECONDS, 60);
        $hours = intdiv(PendingChanges::TTL_APP_SECONDS, 3600);
        $open = PendingChanges::MAX_OPEN_PER_KEY;
        $body = intdiv(App::BODY_PUBLIC, 1048576);
        $pdf = intdiv(Resources::DOCUMENT_MAX_BYTES, 1048576);
        [$n, $q, $no] = [Sanitize::NAME_MAX, Sanitize::QUOTE_MAX, Sanitize::NOTE_MAX];
        [$lo, $hi, $def] = [Prompts::DEADLINES_MIN_DAYS, Prompts::DEADLINES_MAX_DAYS, Prompts::DEADLINES_DAYS];
        $gap = ConsumptionService::MEASURED_GAP_MAX_DAYS;
        $off = Config::ENV_OFF;
        return [
            'Vorschlag gilt 15 Minuten' => [
                'docs/anleitungen/ki-assistent.md' => ["Mit dem Schalter gilt ein Vorschlag **$hours Stunden**", "Ohne ihn gilt ein Vorschlag **$min Minuten**"],
                'docs/en/anleitungen/ki-assistent.md' => ["With the switch, a proposal is valid for **$hours hours**", "Without it, a proposal is valid for **$min minutes**"],
                'docs/referenz/api.md' => ["Ein Vorschlag gilt $min Minuten, einmal", "gilt dann $hours Stunden"],
                'docs/en/referenz/api.md' => ["A proposal is valid for $min minutes, once", "is then valid for $hours hours"],
                'docs/betrieb/sicherheit.md' => ["binnen $min Minuten, einmal"],
                'docs/en/betrieb/sicherheit.md' => ["within $min minutes, once"],
            ],
            'höchstens 50 offene Vorschläge' => [
                'docs/anleitungen/ki-assistent.md' => ["Höchstens **$open** offene Vorschläge je Schlüssel"],
                'docs/en/anleitungen/ki-assistent.md' => ["At most **$open** open proposals per key"],
                'docs/referenz/api.md' => ["höchstens $open offene je Schlüssel"],
                'docs/en/referenz/api.md' => ["at most $open open per key"],
            ],
            'Anfrage höchstens 1 MB, Original höchstens 5 MB' => [
                'docs/anleitungen/ki-assistent.md' => ["**Anfrage höchstens $body MB.** Ein Original geht bis $pdf MB", "Anfrage über $body MB", "bis $pdf MB |"],
                'docs/en/anleitungen/ki-assistent.md' => ["**Request at most $body MB.** An original goes to the AI up to $pdf MB", "Request over $body MB", "up to $pdf MB |"],
                'docs/referenz/api.md' => ["höchstens $pdf MB |", "über $pdf MB mit"],
                'docs/en/referenz/api.md' => ["at most $pdf MB |", "over $pdf MB with"],
            ],
            'Fremdtext gekürzt' => [
                'docs/anleitungen/ki-assistent.md' => ["Namen bis $n, Zitate bis $q, Notizen bis $no Zeichen"],
                'docs/en/anleitungen/ki-assistent.md' => ["names up to $n, quotes up to $q, notes up to $no characters"],
            ],
            'Was steht an: 1 bis 366, sonst 90' => [
                'docs/anleitungen/ki-assistent.md' => ["`days` ($lo bis $hi, sonst $def)"],
                'docs/en/anleitungen/ki-assistent.md' => ["`days` ($lo to $hi, otherwise $def)"],
                'docs/referenz/api.md' => ["`days` ($lo bis $hi)"],
                'docs/en/referenz/api.md' => ["`days` ($lo to $hi)"],
            ],
            'ET_MCP sperrt mit off, 0, false, no' => [
                'docs/betrieb/docker.md' => ["`{$off[0]}` (auch `{$off[1]}`, `{$off[2]}`, `{$off[3]}`)"],
                'docs/en/betrieb/docker.md' => ["`{$off[0]}` (also `{$off[1]}`, `{$off[2]}`, `{$off[3]}`)"],
            ],
            'gemessen: Abstände bis 45 Tage, mindestens zur Hälfte' => [
                'docs/verstehen/18-herkunft.md' => ["mindestens zur Hälfte aus Ablesungsabständen bis $gap Tage", "weiter als $gap Tage auseinanderliegen"],
                'docs/en/verstehen/18-herkunft.md' => ["at least half from reading intervals of up to $gap days", "more than $gap days apart"],
            ],
            'Zustände unter data/mcp/, nicht im Backup' => [
                'docs/referenz/datenmodell.md' => ['├── mcp/ # KI-Schnittstelle (v3.12.0): offene Vorschläge (' . PendingChanges::FILE . ')', 'nicht im Backup'],
                'docs/en/referenz/datenmodell.md' => ['├── mcp/ # AI interface (v3.12.0): open proposals (' . PendingChanges::FILE . ')', 'not in the backup'],
            ],
            'Rundung wie die Oberfläche' => [
                'docs/verstehen/18-herkunft.md' => ['Beträge auf ' . Num::MONEY_DECIMALS . ', Mengen auf ' . Num::QUANTITY_DECIMALS . ' Nachkommastellen'],
                'docs/en/verstehen/18-herkunft.md' => ['amounts to ' . Num::MONEY_DECIMALS . ', quantities to ' . Num::QUANTITY_DECIMALS . ' decimal places'],
            ],
        ];
    }

    public function testDocumentedLimitsFollowTheConstants(): void
    {
        self::assertSame(0.5, ConsumptionService::MEASURED_SHARE_MIN, '„mindestens zur Hälfte“ in 18-herkunft.md');
        foreach (self::claims() as $claim => $pages) {
            foreach ($pages as $rel => $sentences) {
                $text = self::flat($rel);
                foreach ($sentences as $s) self::assertStringContainsString($s, $text, "$claim: $rel");
            }
        }
    }

    /**
     * Ratengrenzen (Konzept §2.6): Jede Zahl der Anleitung kommt aus den Konstanten des
     * RateLimiter. v3.12.0 (integration) — bis zum Zusammenführen reichte, dass die Zahl
     * irgendwo unter den Konstanten stand.
     */
    public function testDocumentedRateLimits(): void
    {
        $limits = ['all' => RateLimiter::LIMITS['all'][0], 'read' => RateLimiter::LIMITS['call'][0], 'propose' => RateLimiter::LIMITS['propose'][0],
            'apply' => RateLimiter::LIMITS['apply'][0], 'wrong' => RateLimiter::KEY_FAILURES, 'window' => intdiv(RateLimiter::FAILURE_WINDOW, 60), 'block' => intdiv(RateLimiter::LOCK_SECONDS, 60)];
        self::assertSame([60, 60, 3600, 3600, 3600], array_column(array_values(RateLimiter::LIMITS), 1), 'Fenster: Minute, Minute, Stunde, Stunde, Stunde wie in den Sätzen');
        self::assertStringContainsString('Posteingang mit dem KI-Schlüssel: ' . RateLimiter::LIMITS['inbox'][0] . ' je Stunde', self::flat('docs/anleitungen/ki-assistent.md'));
        self::assertStringContainsString('inbox with the AI key: ' . RateLimiter::LIMITS['inbox'][0] . ' per hour', self::flat('docs/en/anleitungen/ki-assistent.md'));
        $de = self::flat('docs/anleitungen/ki-assistent.md');
        $en = self::flat('docs/en/anleitungen/ki-assistent.md');
        self::assertStringContainsString("{$limits['all']} Anfragen je Minute, davon {$limits['read']} Aufrufe lesender Werkzeuge; {$limits['propose']} Vorschläge und Auslesungen je Stunde und {$limits['apply']} Übernahmen je Stunde. Nach {$limits['wrong']} falschen Schlüsseln in {$limits['window']} Minuten sperrt der Server die Adresse für {$limits['block']} Minuten", $de);
        self::assertStringContainsString("{$limits['all']} requests per minute, of which {$limits['read']} calls of reading tools; {$limits['propose']} proposals and readings of documents per hour and {$limits['apply']} applies per hour. After {$limits['wrong']} wrong keys in {$limits['window']} minutes the server blocks the address for {$limits['block']} minutes", $en);
        // `cancel_proposal` zählt im Lesetopf (RateLimiter::WRITE_TOOLS_IN_CALL): die Doku sagt es
        self::assertSame(['cancel_proposal'], RateLimiter::WRITE_TOOLS_IN_CALL);
        self::assertStringContainsString('`cancel_proposal` zählt wie ein lesendes Werkzeug', $de);
        self::assertStringContainsString('`cancel_proposal` counts like a reading tool', $en);
        self::assertStringContainsString('`cancel_proposal` zählt im Topf der lesenden Werkzeuge', self::flat('docs/referenz/api.md'));
        self::assertStringContainsString('`cancel_proposal` counts in the bucket of the reading tools', self::flat('docs/en/referenz/api.md'));
    }

    public function testInstructionsCarryThePromisesAndStayShort(): void
    {
        $de = $this->i18n->t('mcp.instructions', [], 'de');
        $en = $this->i18n->t('mcp.instructions', [], 'en');
        foreach (['de' => $de, 'en' => $en] as $l => $text) {
            // v3.12.0 (Skeptiker M1, H4, M3 bis M5) — Konzept §3.7 sagte 1500; dazu kamen Bestätigung und Klartext am Saldo
            self::assertLessThanOrEqual(1800, mb_strlen($text), "$l: Server-Anweisungen höchstens 1800 Zeichen");
            foreach ([...Provenance::SIGNS, 'untrusted', 'count', 'propose_*', 'apply_proposal', 'get_overview', 'list_documents', 'awaiting_confirmation',
                      'meaning', 'revenue', 'no_contract', 'no_price', Resources::HELP . 'herkunft'] as $part) {
                self::assertStringContainsString($part, $text, "$l: $part");
            }
            preg_match_all('/\b(?:get|list|check|run|read|propose|apply|cancel)_[a-z_]+\b/', $text, $m);
            self::assertSame([], array_values(array_diff($m[0], self::TOOLS)), "$l: unbekanntes Werkzeug");
        }
        self::assertStringContainsString(implode(', ', array_slice(Provenance::BASES, 0, 4)) . ' or ' . Provenance::BASES[4], $en);
        self::assertStringContainsString('gemessen, eingetragen, verteilt, angenommen oder geschätzt', $de);
        self::assertStringContainsString('keine Rechtsberatung', $de);
        self::assertStringContainsString('not legal advice', $en);
        // so kommen sie beim Client an
        $init = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 't', 'version' => '1']])['body']['result'];
        self::assertSame($de, $init['instructions']);
        self::assertSame($en, $this->rpc('server/discover', [], $this->context(locale: 'en'), true)['body']['result']['instructions']);
    }

    /** Die Erklärseite führt die Herkünfte in der Reihenfolge des Codes und beide Vorzeichen. */
    public function testProvenancePageFollowsTheVocabulary(): void
    {
        foreach (['docs/verstehen/18-herkunft.md', 'docs/en/verstehen/18-herkunft.md'] as $rel) {
            preg_match_all('/^\| `(' . implode('|', Provenance::BASES) . ')` \|/m', self::file($rel), $b);
            self::assertSame(Provenance::BASES, $b[1], "$rel: Herkünfte von stark nach schwach");
            preg_match_all('/^\| `(positive_is_[a-z_]+)` \|/m', self::file($rel), $s);
            self::assertSame(Provenance::SIGNS, $s[1], "$rel: Vorzeichen");
        }
        self::assertSame(Provenance::BASES[4], Provenance::weakest(...Provenance::BASES), 'schwächste = letzte');
    }

    /** Jedes Programm aus der Tabelle der Anleitung steht in TRADEMARKS.md (beschreibende Nennung). */
    public function testEveryNamedClientIsInTheTrademarkNotice(): void
    {
        $tm = self::file('TRADEMARKS.md');
        foreach (['docs/anleitungen/ki-assistent.md', 'docs/en/anleitungen/ki-assistent.md'] as $rel) {
            $md = self::file($rel);
            $start = (int)strpos($md, '| Programm |') ?: (int)strpos($md, '| Program |');
            $table = substr($md, $start, (int)strpos($md, "\n\n", $start) - $start);
            preg_match_all('/^\| ([A-Z][A-Za-z ]+?)(?: \(| ab | \d|\s*\|)/m', $table, $m);
            $names = array_values(array_diff($m[1], ['Programm', 'Program']));
            self::assertCount(5, $names, "$rel: fünf Programme");
            foreach ($names as $n) self::assertStringContainsString("**$n**", $tm, "TRADEMARKS.md: $n ($rel)");
        }
        foreach (['Ollama', 'Caddy', 'HACS'] as $n) self::assertStringContainsString("**$n**", $tm);
    }

    /** In-App-Hilfe: die Anleitung hängt in docs.js und in der Hilfe-Seite. */
    public function testInAppHelpLinksTheGuide(): void
    {
        $docs = self::file('public/js/lib/docs.js');
        self::assertStringContainsString("aiAssistant:   ['docs/anleitungen/ki-assistent.md',    'docs/en/anleitungen/ki-assistent.md']", $docs);
        self::assertStringContainsString("provenance:    ['docs/verstehen/18-herkunft.md',       'docs/en/verstehen/18-herkunft.md']", $docs);
        self::assertStringContainsString("doc('aiAssistant', 'help.docs.aiAssistant')", self::file('public/js/views/help.js'));
        // die Ressource zeigt in der Anleitung auf die Seite, die Resources ausliefert
        self::assertSame('verstehen/18-herkunft.md', Resources::PAGES['herkunft']);
        self::assertSame(Server::LOCALES, ['de', 'en'], 'Doku: Texte an die KI auf Deutsch und Englisch');
    }
}
