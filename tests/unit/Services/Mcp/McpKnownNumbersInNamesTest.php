<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\IdentifierMask;

/**
 * v3.13.0 — Härtung: Ohne `mcp_share_identifiers` ersetzt die App eine Nummer, die der Haushalt kennt, auch dann,
 * wenn sie in einem Namen oder Titel an Buchstaben klebt oder mit anderen Trennern geschrieben ist. Namen und Titel
 * bekommen dazu den zweiten Schritt wie
 * Zitate und Notizen: Was danach noch wie eine Nummer aussieht, wird ersetzt, der Rest des Namens bleibt.
 * Alle Nummern erfunden.
 */
final class McpKnownNumbersInNamesTest extends McpReadTestCase
{
    private const CUSTOMER = '3141592653';
    private const MALO = '50998877665';
    private const SERIAL = '2718';

    /** @var list<string> Titel der Termine: die Kundennummer in verschiedenen Schreibweisen */
    private const TITLES = [
        'Bonus 3141/592653', "Zählerwechsel 3141\u{2014}592653", 'Rückruf Kunde3141592653', 'Lieferstelle DE50998877665',
        "Vertrag 3141\u{00A0}592\u{00A0}653", 'Notiz 3141:592653', 'Gasvertrag 3141592653 prüfen', "Abschlag 3141\u{00B7}592653",
        'Brief 3141592653ab', "Mahnung 3141\u{2013}592653", 'Schreiben 3141 592 653', 'Messstelle DE-509-988-776-65',
    ];
    /** bleiben, wie sie sind: Uhrzeit, Betrag, Tag, Jahr, Menge, die kurze Gerätenummer nur mit Doppelpunkt oder Komma */
    private const HARMLESS = ['Ablesen um 27:18 Uhr', 'Rate 27,18 €', 'Ablesung am 31.12.2026', 'Wartung 2027', 'Tank 1.500 l nachfüllen'];

    private function withNumbers(): void
    {
        $this->household();
        $c = $this->contracts->list('gas')[0];
        $this->contracts->update('gas', (string)$c['id'], ['customer_number' => self::CUSTOMER]);
        $meters = (array)$this->store->read('gas/meters.json', []);
        $meters[0]['malo_id'] = self::MALO;
        $meters[0]['devices'][0]['serial'] = self::SERIAL;
        // eine Bezeichnung der Maske direkt an der Nummer: ihr Wert ist schon ersetzt, sie kippt nicht den ganzen Namen
        $meters[0]['name'] = 'Gas 3141592653 / Vertragskonto3141592653 / Z-7654321';
        $this->store->write('gas/meters.json', $meters);
        $rows = [];
        foreach ([...self::TITLES, ...self::HARMLESS] as $i => $title) {
            $rows[] = ['id' => sprintf('rem_test%04d', $i), 'title' => $title, 'category' => 'heizung_wartung',
                'next_due' => date('Y-m-d', (int)strtotime(self::TODAY . ' +' . ($i + 1) . ' days')), 'recurrence' => 'none',
                'recurrence_months' => null, 'last_done' => null, 'notes' => '', 'active' => true];
        }
        $this->store->write('reminders.json', $rows);
    }

    /** @return array<string,string> Werkzeug → Antwort als Text */
    private function answers(): array
    {
        $ctx = $this->context('mcp', self::TODAY);
        $out = [];
        foreach ([['get_deadlines', ['days' => 60]], ['list_meters', ['include_inactive' => true]], ['get_overview', []],
                  ['get_contracts', ['utility' => 'gas', 'include_past' => true]], ['get_yearly_report', ['year' => 2026]],
                  ['get_insights', []]] as [$tool, $args]) {
            $out[$tool] = (string)json_encode($this->callTool($tool, $args, $ctx), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $out;
    }

    public function testAKnownNumberIsReplacedInEveryWriting(): void
    {
        $this->withNumbers();
        foreach ($this->answers() as $tool => $json) {
            // Orakel: alles außer Buchstaben und Ziffern weg, dann die Ziffernfolge suchen
            $flat = (string)preg_replace('/[^\p{L}\p{N}]+/u', '', $json);
            foreach ([self::CUSTOMER, self::MALO, '7654321'] as $n) self::assertStringNotContainsString($n, $flat, "$tool: $n");
        }
        $deadlines = $this->answers()['get_deadlines'];
        // die Titel bleiben lesbar, nur die Nummer ist ersetzt
        foreach (['Gasvertrag […] prüfen', 'Rückruf Kunde[…]', 'Brief […]ab', 'Notiz […]', 'Lieferstelle DE[…]'] as $t) {
            self::assertStringContainsString($t, $deadlines);
        }
        foreach (self::HARMLESS as $t) self::assertStringContainsString($t, $deadlines, 'unverändert');
        self::assertStringContainsString('"name":"Gas […] / Vertragskonto[…] / Z-[…]"', $this->answers()['list_meters'], 'der Zählername bleibt lesbar');
    }

    public function testWithTheSwitchEverythingStays(): void
    {
        $this->withNumbers();
        $this->settings->set(['mcp_share_identifiers' => true]);
        $json = $this->answers()['get_deadlines'];
        self::assertStringContainsString('Rückruf Kunde3141592653', $json);
        self::assertStringContainsString('Lieferstelle DE50998877665', $json);
    }

    /** doc-claim `mcp-identifier-mask`: Die Anleitung verspricht, was die Tests hier halten (DE und EN). */
    public function testTheDocsPromiseWhatTheseTestsHold(): void
    {
        $root = dirname(__DIR__, 4);
        $want = ['de' => ['Trennzeichen zwischen den Ziffern', 'an Buchstaben', 'Namen und Titeln', 'lesbar'],
                 'en' => ['separators between the digits', 'next to letters', 'names and titles', 'readable']];
        foreach (['de' => 'docs/anleitungen/ki-assistent.md', 'en' => 'docs/en/anleitungen/ki-assistent.md'] as $lang => $file) {
            $src = (string)file_get_contents("$root/$file");
            $at = strpos($src, '<!-- doc-claim: mcp-identifier-mask');
            self::assertNotFalse($at, "$file: Marker fehlt");
            $bullet = (string)preg_replace('/\s+/u', ' ', substr($src, (int)strrpos(substr($src, 0, $at), "\n- "), $at - (int)strrpos(substr($src, 0, $at), "\n- ")));
            foreach ($want[$lang] as $w) self::assertStringContainsString($w, $bullet, "$file: „{$w}“");
        }
    }

    /** Die Regeln der Maske an Beispielen: Rand, Trenner, Länge; Angaben für Briefe nie in einem längeren Wort. */
    public function testTheMaskRules(): void
    {
        $m = IdentifierMask::of([self::CUSTOMER, self::SERIAL, 'ABCD1234EFGH'], IdentifierMask::personal(['Ida Muster'], ['Ahornstieg 4']));
        self::assertSame('Kunde[…]', $m->known('Kunde3141592653'));
        self::assertSame('Nr. […]', $m->known("Nr. 3141\u{2013}592653"));
        self::assertSame('03141592653', $m->known('03141592653'), 'eine längere Zahl ist eine andere Nummer');
        self::assertSame('31415926530', $m->known('31415926530'));
        self::assertSame('MeLo[…]', $m->known('MeLoABCD1234EFGH'), 'lang: an Buchstaben');
        self::assertSame('27:18 Uhr, 27,18 €, Gerät […]', $m->known('27:18 Uhr, 27,18 €, Gerät 2718'), 'kurz: nur die bisherigen Trenner');
        self::assertSame('Gerät[…]x', $m->known('Gerät2718x'), 'Ziffern am Rand: auch kurz an Buchstaben');
        self::assertSame('27185', $m->known('27185'), 'nie an Ziffern');
        self::assertSame('ABCD1234EFG', $m->known('ABCD1234EFG'), 'ein Zeichen fehlt: keine Nummer des Haushalts');
        self::assertSame('Musterstadt, […]', $m->known('Musterstadt, Ida Muster'), 'Name nicht im längeren Wort');
        self::assertSame('Musterweg', $m->known('Musterweg'));
        // zweiter Schritt nur an Namen und Titeln: Unbekanntes, das wie eine Nummer aussieht
        self::assertSame('Keller Z-[…]', $m->name('Keller Z-7654321'));
        self::assertSame('Keller […]', $m->name('Keller AB123CD'), 'Buchstaben mit drei Ziffern: das ganze Stück');
        self::assertSame('Wärmepumpe WP2', $m->name('Wärmepumpe WP2'));
        self::assertSame('Ablesung am 31.12.2026 um 12:34', $m->name('Ablesung am 31.12.2026 um 12:34'));
        self::assertSame('Kundennummer […]', $m->name('Kundennummer 98-76-54-32'));
        self::assertSame('Keller Z-7654321', IdentifierMask::off()->name('Keller Z-7654321'), 'mit dem Schalter unverändert');
    }
}
