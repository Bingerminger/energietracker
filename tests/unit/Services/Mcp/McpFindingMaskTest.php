<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

/**
 * v3.12.0 — Ohne den Schalter `mcp_share_identifiers` ersetzt die App Nummern, die sie kennt, überall, wo die KI Text
 * bekommt. Titel und Text von Hinweisen und Rechte-Befunden nennen Anbieter und Zähler; trug ein Anbietername die
 * Kundennummer (so übernommen aus einem Dokument oder von Hand eingetragen), stand sie dort im Klartext, während das
 * Feld `provider` daneben maskiert war. Ebenso eine Angabe für Briefe im Zählernamen. Alle Angaben erfunden.
 */
final class McpFindingMaskTest extends McpReadTestCase
{
    /** Kundennummer, Seriennummer des Geräts, Straße des Absenders: stehen nur in Namen, die Hinweise nennen */
    private const SECRETS = ['K-0815', 'GZ-0001', 'Ahornstieg'];

    private function withNumbersInNames(): void
    {
        $this->household();
        $this->settings->set(['letter_sender_name' => 'Ida Beispiel', 'letter_sender_address' => "Ahornstieg 4\n54321 Musterstadt"]);
        $c = $this->contracts->list('gas')[0];
        // Vertragsende im Fenster der Hinweise: der Hinweis zum Vertragsende nennt den Anbieter wörtlich
        // dazu eine eingetragene Preiserhöhung: der Rechte-Befund (Sonderkündigung) nennt den Anbieter ebenso
        $this->contracts->update('gas', (string)$c['id'], ['provider' => 'Gaswerk K-0815', 'end' => '2026-08-31', 'auto_renews' => false,
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 10.0], ['from' => '2026-08-15', 'ct_per_kwh' => 12.0]]]);
        $this->meters->update('gas', 'm_gas_default', ['name' => 'Keller GZ-0001 Ahornstieg']);
    }

    /** @return list<string> Fundstellen „Werkzeug Argumente: Teil“ */
    private function leaks(): array
    {
        $ctx = $this->context('mcp', self::TODAY);
        $out = [];
        foreach ($this->callsFor() as $tool => $list) {
            foreach ($list as $args) {
                $json = (string)json_encode($this->callTool($tool, $args, $ctx), JSON_UNESCAPED_UNICODE);
                foreach (self::SECRETS as $s) if (str_contains($json, $s)) $out[] = "$tool " . json_encode($args) . ": $s";
            }
        }
        return $out;
    }

    public function testHintsAndFindingsUseTheSameMaskAsTheFields(): void
    {
        $this->withNumbersInNames();
        self::assertSame([], $this->leaks());
    }

    /** Der Hinweis bleibt lesbar: der Anbieter steht darin, nur die Nummer ist ersetzt (sonst prüfte der Test nichts). */
    public function testTheHintStillNamesTheProvider(): void
    {
        $this->withNumbersInNames();
        $json = (string)json_encode($this->callTool('get_insights', [], $this->context('mcp', self::TODAY)), JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Gaswerk […]', $json, 'ein Hinweis nennt den Anbieter');
        // der Rechte-Befund am Vertrag (get_contracts, eigener Weg über ReadSupport)
        $rights = (string)json_encode($this->callTool('get_contracts', ['utility' => 'gas'], $this->context('mcp', self::TODAY)), JSON_UNESCAPED_UNICODE);
        self::assertMatchesRegularExpression('/"note":"[^"]*Gaswerk \[…\][^"]*ändert die Preise/u', $rights, 'der Rechte-Befund nennt den Anbieter, maskiert');
        // mit dem Schalter steht die Kundennummer wieder da, die Straße des Absenders nie
        $this->settings->set(['mcp_share_identifiers' => true]);
        $json = (string)json_encode($this->callTool('get_insights', [], $this->context('mcp', self::TODAY)), JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Gaswerk K-0815', $json);
        self::assertStringNotContainsString('Ahornstieg', (string)json_encode($this->callTool('get_deadlines', ['days' => 366], $this->context('mcp', self::TODAY)), JSON_UNESCAPED_UNICODE));
    }
}
