<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\PendingChanges;
use Energietracker\Services\Mcp\Prompts;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolRegistry;

/**
 * v3.12.0 (Skeptiker M1, Mehrwert, Entscheidung „geprüft nur Open WebUI“) — Was die Anleitung und die Karte behaupten, hält der
 * Code: Werkzeuge gegen die Registry, Prompts gegen die Prompts, Bezeichnungen gegen den Katalog der jeweiligen Sprache, die
 * Sätze der Beispiele gegen das, was die App wirklich baut, Open WebUI zuerst und jede andere Vorlage gekennzeichnet.
 */
final class McpValueDocsTest extends McpTestCase
{
    private const GUIDES = ['de' => 'docs/anleitungen/ki-assistent.md', 'en' => 'docs/en/anleitungen/ki-assistent.md'];
    private const VALUE_HEADS = ['de' => '## Was bringt dir das?', 'en' => '## What does it do for you?'];
    private const CONFIRM_HEADS = ['de' => '## Wer bestätigt eine Änderung?', 'en' => '## Who confirms a change?'];

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function file(string $rel): string
    {
        return (string)file_get_contents(self::root() . "/$rel");
    }

    private static function flat(string $s): string
    {
        return (string)preg_replace('/\s+/u', ' ', $s);
    }

    /** Abschnitt ab einer Überschrift bis zur nächsten gleich hohen */
    private static function section(string $md, string $head): string
    {
        $at = strpos($md, "\n$head\n");
        self::assertNotFalse($at, "Abschnitt $head fehlt");
        $end = strpos($md, "\n## ", $at + 5);
        return substr($md, (int)$at, ($end === false ? strlen($md) : $end) - (int)$at);
    }

    /** @return list<string> */
    private function toolNames(): array
    {
        return array_map(fn(Tool $t) => $t->name, (new ToolRegistry($this->mcpServices()))->all());
    }

    private function cat(string $l, string $key): string
    {
        $v = $this->i18n->t($key, [], $l);
        self::assertNotSame($key, $v, "$l: $key fehlt im Katalog");
        return $v;
    }

    public function testTheValueSectionComesFirstAndNamesOnlyRealToolsAndPrompts(): void
    {
        foreach (self::GUIDES as $l => $rel) {
            $md = self::file($rel);
            $value = self::section($md, self::VALUE_HEADS[$l]);
            self::assertLessThan(strpos($md, $l === 'de' ? "\n## Was du brauchst\n" : "\n## What you need\n"), strpos($md, self::VALUE_HEADS[$l]), "$rel: vor der Einrichtung");
            self::assertLessThan(strpos($md, self::CONFIRM_HEADS[$l]), strpos($md, self::VALUE_HEADS[$l]), "$rel: vor „Wer bestätigt“");
            preg_match_all('/`([a-z]+_[a-z_]+)`/', $value, $m);
            $names = array_values(array_unique($m[1]));
            self::assertNotSame([], $names);
            $known = [...$this->toolNames(), ...array_keys(Prompts::PROMPTS)];
            self::assertSame([], array_values(array_diff($names, $known)), "$rel: unbekannte Namen");
            // die Beispiele aus dem Auftrag
            foreach (['get_document', 'check_bill', 'get_overview', 'get_consumption', 'get_contracts', 'run_scenario', 'get_deadlines', 'monthly_report', 'propose_reading'] as $n) {
                self::assertStringContainsString("`$n`", $value, "$rel: $n");
            }
            self::assertStringContainsString($l === 'de' ? 'Alle Antworten sind erfunden' : 'All answers are invented', self::flat($value));
            self::assertStringContainsString($l === 'de' ? 'der Energietracker allein kann das nicht' : 'Energietracker alone cannot do this', self::flat($value));
            self::assertStringContainsString($l === 'de' ? 'keinen Nachteil' : 'you lose nothing', self::flat($value));
        }
    }

    public function testTheGuideDoesNotClaimToHaveTestedOtherPrograms(): void
    {
        foreach (self::GUIDES as $l => $rel) {
            $md = self::flat(self::file($rel));
            self::assertStringContainsString($l === 'de' ? 'Geprüft mit Open WebUI' : 'Checked with Open WebUI', $md);
            self::assertStringContainsString('Claude Code, VS Code, Cursor, LM Studio', $md);
            self::assertStringContainsString($l === 'de' ? 'Cloud-Modelle sind **experimentell**' : 'cloud models are **experimental**', $md);
            self::assertStringContainsString('github.com/Bingerminger/energietracker/discussions', $md);
            self::assertStringContainsString('github.com/Bingerminger/energietracker/issues', $md);
            self::assertStringContainsString('<!-- doc-claim: mcp-tested-with -->', self::file($rel));
            self::assertStringContainsString('host.docker.internal', $md);
            // die Vorlagen stehen in dieser Reihenfolge, die anderen unter einer Überschrift mit „experimentell“
            $raw = self::file($rel);
            $other = strpos($raw, $l === 'de' ? "\n## Andere Programme (experimentell)\n" : "\n## Other programs (experimental)\n");
            self::assertNotFalse($other, $rel);
            self::assertLessThan($other, strpos($raw, "\n### Open WebUI\n"), "$rel: Open WebUI zuerst");
            self::assertGreaterThan($other, strpos($raw, "\n### Claude Code\n"), "$rel: Claude Code danach");
            // keine Zusage, dass Programme die Bitte beachten
            self::assertStringContainsString($l === 'de' ? 'ist nicht geprüft' : 'has not been checked', self::flat(self::section($raw, self::CONFIRM_HEADS[$l])));
            self::assertStringNotContainsString($l === 'de' ? 'Claude Code fragt bei jeder Übernahme nach' : 'Claude Code asks for every apply', $md);
        }
    }

    public function testTheWalkthroughUsesTheLabelsOfTheCatalog(): void
    {
        foreach (self::GUIDES as $l => $rel) {
            $s = self::flat(self::section(self::file($rel), self::CONFIRM_HEADS[$l]));
            // v3.12.0 (M2) — Vorschläge stehen unter Zu tun, Filter KI-Vorschläge
            $path = '**' . $this->cat($l, 'nav.todo') . '**, ' . ($l === 'de' ? 'Filter' : 'filter') . ' **' . $this->cat($l, 'todo.filter.ki') . '**';
            self::assertStringContainsString($path, $s, "$rel: Weg in der App");
            foreach (['settings.mcp.apply', 'settings.mcp.applyTitle', 'settings.mcp.applyInApp', 'settings.mcp.reject'] as $k) {
                self::assertStringContainsString('**' . $this->cat($l, $k) . '**', $s, "$rel: $k");
            }
            // der Satz des Beispiels wie der Katalog ihn baut
            $sentence = $this->i18n->t('mcp.summary.reading', ['where' => $this->cat($l, 'utilityNames.gas'), 'counter' => $l === 'de' ? '13.200' : '13,200',
                'unit' => 'm³', 'date' => $l === 'de' ? '04.11.2026' : '04/11/2026', 'estimated' => $this->cat($l, 'mcp.summary.no')], $l);
            self::assertStringContainsString($sentence, $s, "$rel: Beispielsatz");
            foreach (['propose_reading', 'apply_proposal', 'read_document', 'propose_document', 'awaiting_confirmation', 'status: applied', 'repeated: true', 'message'] as $word) {
                self::assertStringContainsString($word, $s, "$rel: $word");
            }
            // Wer bestätigt: der Verlauf nennt beide (Katalog ohne die Platzhalter)
            self::assertStringContainsString(explode('„', explode('“', $this->cat('de', 'activity.by.mcpConfirmed'))[0])[0], self::flat(self::file(self::GUIDES['de'])));
        }
    }

    public function testTheBillExampleIsTheSentenceTheAppBuilds(): void
    {
        // Beträge wie InboxFixture::invoiceAnswer; der Text entsteht aus denselben Katalogschlüsseln wie itemText()
        foreach (['de' => ['Ablesung', 'Rechnung', 'Ergebnis der Rechnung buchen'], 'en' => ['Meter reading', 'Bill', 'Book the bill’s result']] as $l => $labels) {
            self::assertSame($labels, [$this->cat($l, 'mcp.summary.kind.reading'), $this->cat($l, 'mcp.summary.kind.bill'), $this->cat($l, 'mcp.summary.kind.bill_book')]);
            $s = self::flat(self::section(self::file(self::GUIDES[$l]), self::CONFIRM_HEADS[$l]));
            $money = $l === 'de' ? ['1.722,70 €', '1.680,00 €', '42,70 €'] : ['€1,722.70', '€1,680.00', '€42.70'];
            self::assertStringContainsString($this->i18n->t('mcp.summary.part.amount', ['amount' => $money[0]], $l), $s);
            self::assertStringContainsString($this->i18n->t('mcp.summary.part.advances', ['amount' => $money[1]], $l), $s);
            self::assertStringContainsString($this->i18n->t('mcp.summary.part.due', ['amount' => $money[2]], $l), $s);
            self::assertStringContainsString($labels[2] . ' (', $s);
        }
    }

    /** Text aus dem Katalog genau dieser Sprache, ohne den Rückfall von t(). */
    private static function own(string $l, string $key): string
    {
        $node = json_decode(self::file("public/locales/$l.json"), true);
        foreach (explode('.', $key) as $part) $node = is_array($node) ? ($node[$part] ?? null) : null;
        self::assertIsString($node, "$l: $key fehlt im Katalog");
        return $node;
    }

    public function testTheCardAndItsExamplesExistInEveryLanguage(): void
    {
        $card = self::file('public/js/components/mcp-card.js');
        preg_match("/MCP_VALUE_EXAMPLES = \[([^\]]+)\]/", $card, $m);
        preg_match_all("/'([a-z]+)'/", $m[1], $keys);
        self::assertGreaterThanOrEqual(4, count($keys[1]));
        self::assertLessThanOrEqual(6, count($keys[1]));
        foreach (['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'] as $l) {
            foreach ($keys[1] as $k) {
                self::own($l, "settings.mcp.value.q.$k");
                self::own($l, "settings.mcp.value.a.$k");
            }
            foreach (['title', 'lead', 'invented', 'more'] as $k) self::own($l, "settings.mcp.value.$k");
            foreach (['experimental', 'recommended', 'clientsHint', 'feedback'] as $k) self::own($l, "settings.mcp.$k");
        }
        self::assertStringContainsString("docUrl('aiAssistant', 'mehrwert')", $card);
        foreach (self::GUIDES as $rel) self::assertStringContainsString('<a id="mehrwert"></a>', self::file($rel), "$rel: Sprungmarke");
        // „Neu in vX.Y“ führt dorthin
        self::assertStringContainsString('?mcp=value', self::file('public/js/lib/demo-mode.js'));
        self::assertStringContainsString('mcp=value', $card);
        // nur Texte aus dem Katalog, nichts gerechnet: keine Aufrufe von api.* im Baustein
        $start = strpos($card, 'function renderValue()');
        $body = substr($card, (int)$start, (int)strpos($card, 'function renderStatusCard') - (int)$start);
        self::assertStringNotContainsString('api.', $body);
    }

    public function testEveryTextThatPromisedConfirmationIsHonest(): void
    {
        foreach (['de', 'en', 'fr', 'it', 'es', 'pt', 'nl'] as $l) {
            $apply = $this->cat($l, 'mcp.tools.apply_proposal.description');
            self::assertStringContainsString('awaiting_confirmation', $apply, $l);
            self::assertStringContainsString($this->cat($l, 'settings.mcp.applyInApp'), $apply, "$l: Name des Schalters");
            self::assertStringContainsString('awaiting_confirmation', $this->cat($l, 'mcp.instructions'), $l);
            self::assertStringContainsString('awaiting_confirmation', $this->cat($l, 'mcp.prompts.rules'), $l);
            self::assertStringContainsString($this->cat($l, 'settings.mcp.apply'), $this->cat($l, 'settings.mcp.applyInAppHint'), $l);
            self::assertStringContainsString('reclaim', $this->cat($l, 'mcp.instructions'), "$l: Nachprüfung C3, jede Bedeutung des Saldos");
        }
        // kein absoluter Satz „Geändert wird nur, was du bestätigst“ mehr ohne die Bedingung
        self::assertStringNotContainsString('Geändert wird nur, was du bestätigst', $this->cat('de', 'settings.mcp.intro'));
        self::assertSame(86400, PendingChanges::TTL_APP_SECONDS);
    }
}
