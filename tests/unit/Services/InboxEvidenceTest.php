<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\Inbox\Evidence;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Services\Inbox\TextPrep;
use Energietracker\Tests\Support\InboxFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.7.0 (Posteingang) — Schema, deutsche Zahlen, Belegprüfung, Textvorbereitung.
 */
#[CoversClass(Evidence::class)]
#[CoversClass(InboxSchema::class)]
#[CoversClass(TextPrep::class)]
final class InboxEvidenceTest extends TestCase
{
    public function testEveryTypeHasItsSchemaAndTheGroupsFromTheProbe(): void
    {
        self::assertSame(['dunning', 'withdrawal', 'other'], InboxSchema::RECOGNIZE_ONLY);
        self::assertSame(['meter_change', 'reading_request', 'advance_change'], InboxSchema::REVIEW_ALL);
        foreach (InboxSchema::TYPES as $t) {
            self::assertArrayHasKey($t, InboxSchema::TYPE_DESC, $t);
            $fields = InboxSchema::fields($t);
            if (in_array($t, InboxSchema::RECOGNIZE_ONLY, true)) { self::assertSame([], $fields, $t); continue; }
            self::assertNotSame([], $fields, $t);
            self::assertNotSame([], array_filter($fields, fn($f) => !empty($f['core'])), "$t: Kernfelder");
            foreach ($fields as $name => $f) {
                // v3.7.0 (treffsicher, Nachbesserung 4) — `bool` für die Ablese-Art, nur als Hilfsfeld
                self::assertContains($f['kind'], ['date', 'number', 'money', 'price_ct', 'text', 'enum', 'list', 'bool'], "$t.$name");
                if ($f['kind'] === 'bool') self::assertTrue(!empty($f['aux']), "$t.$name: bool nur als Hilfsfeld");
                self::assertNotSame('', $f['desc'], "$t.$name");
                if ($f['kind'] === 'enum') self::assertNotEmpty($f['options'], "$t.$name");
                if ($f['kind'] === 'list') self::assertNotEmpty($f['items'], "$t.$name");
            }
        }
        // Kernfelder der Rechnung laut Probe: Zeitraum, Verbrauch, Stand neu mit Datum, Gesamtbetrag, Ergebnis, neuer Abschlag
        $core = array_keys(array_filter(InboxSchema::fields('invoice'), fn($f) => !empty($f['core'])));
        foreach (['period_from', 'period_to', 'consumption', 'reading_new', 'reading_new_date', 'total_amount', 'result_kind', 'result_amount', 'advance_new'] as $f) {
            self::assertContains($f, $core);
        }
        // Preise aus Rechnungen nur als Vorschlag mit Prüfung
        self::assertTrue(!empty(InboxSchema::fields('invoice')['working_prices']['review']));
        self::assertTrue(!empty(InboxSchema::fields('invoice')['base_price']['review']));
        $s = InboxSchema::forProvider('invoice');
        self::assertSame(InboxSchema::VERSION, $s['version']);
        self::assertArrayNotHasKey('hints', $s['fields']['kind'], 'Stichwörter nur für die Prüfung');
    }

    public static function germanNumbers(): array
    {
        return [
            ['12.345', [12345.0, 12.345]],
            ['1.234,56', [1234.56]],
            ['12,5', [12.5]],
            ['0,2345', [0.2345]],
            ['1.234.567', [1234567.0]],
            ['1 234,5', [1234.5]],
            ['12,500', [12.5, 12500.0]],
            ['-42,70', [-42.7]],
            ['31.12.2025', []],
        ];
    }

    #[DataProvider('germanNumbers')]
    public function testGermanNumbersAreReadWithTheThousandsDotFirst(string $token, array $expected): void
    {
        self::assertSame($expected, Evidence::readings($token), $token);
    }

    public function testAThousandsDotMisreadByTheModelIsCorrectedAgainstTheQuote(): void
    {
        $pages = ['Zählerstand neu am 31.12.2025: 13.580 m³'];
        $raw = ['reading_new' => ['value' => 13.58, 'quote' => 'Zählerstand neu am 31.12.2025: 13.580 m³', 'page' => 1]];
        $f = Evidence::check('invoice', $raw, $pages)['reading_new'];
        self::assertSame(13580.0, $f['value'], 'JSON-Zahl 13.58 ist nicht im Zitat, 13.580 als Tausender schon');
        // 13,58 mit drei Nachkommastellen geschrieben → Tausendergruppe
        $raw['reading_new']['value'] = 13.580;
        self::assertSame(13580.0, Evidence::check('invoice', $raw, $pages)['reading_new']['value']);
        $raw['reading_new']['value'] = '13.580';
        $g = Evidence::check('invoice', $raw, $pages)['reading_new'];
        self::assertSame(13580.0, $g['value']);
        self::assertSame('ok', $g['status']);
    }

    public function testTheQuoteMustStandInTheTextAfterNormalisation(): void
    {
        $pages = ["Unser Abrech-\nnungszeitraum: 01.01.2025\u{00A0}bis   31.12.2025\nVERBRAUCH 13.216 kWh"];
        self::assertSame(1, Evidence::locate('Abrechnungszeitraum: 01.01.2025 bis 31.12.2025', $pages));
        self::assertSame(1, Evidence::locate('verbrauch 13.216 kwh', $pages), 'Groß/klein');
        self::assertSame(1, Evidence::locate('Verbrauch 13.216kWh', $pages), 'Leerraum');
        self::assertSame(1, Evidence::locate('Abschlag 80,00 €', ["Ab\u{00AD}schlag 80,00\u{202F}€"]), 'weiches Trennzeichen, schmales Leerzeichen');
        self::assertSame(1, Evidence::locate('„Grundversorgung“ – Erdgas', ['"Grundversorgung" - Erdgas']), 'Anführungszeichen und Striche');
        self::assertNull(Evidence::locate('Verbrauch 14.216 kWh', $pages), 'erfunden');
        self::assertNull(Evidence::locate('kWh', $pages), 'zu kurz, stünde überall');
        self::assertSame(2, Evidence::locate('Summe 40,00 €', ['Seite eins', 'Summe 40,00 €'], 1), 'falsche Seite genannt: gefunden auf der richtigen');
    }

    // ── v3.7.0 (treffsicher, Nachbesserung 2) — Zitat aus mehreren Stellen, Umlautkodierung ──

    /** Eine erfundene Rechnung mit Tabellen: Bezeichnung und Wert in getrennten Spalten. */
    private static function tablePages(): array
    {
        return ["Stadtwerke Musterstadt GmbH\nJahresabrechnung Erdgas 2025\n"
            . "Zählernummer        Marktlokation        Vertragskonto\nTEST-4711-01        51234567895          VK-778899\n"
            . "Abschlag 1. Halbjahr        145,00 €\nIhr neuer Abschlag        gültig ab\n145,00 €        01.02.2026",
            "Seite zwei\nZählernummer siehe Seite eins"];
    }

    public function testAQuoteWithAnEllipsisCountsWhenEveryPartStandsOnOnePage(): void
    {
        $pages = self::tablePages();
        self::assertSame(1, Evidence::locate('Zählernummer … TEST-4711-01', $pages));
        self::assertSame(1, Evidence::locate('Zählernummer ... TEST-4711-01', $pages), 'drei Punkte wie „…“');
        self::assertNull(Evidence::locate('Zählernummer … TEST-4711-99', $pages), 'ein Teil steht nicht da');
        self::assertNull(Evidence::locate('Seite zwei … TEST-4711-01', $pages), 'Teile auf zwei Seiten');
        self::assertNull(Evidence::locate('01.02.2026 … Seite zwei', $pages), 'Teile auf zwei Seiten, in Reihenfolge und nah');
        $f = fn(array $raw) => Evidence::check('invoice', $raw + InboxFixture::invoiceAnswer(), $pages);
        $r = $f(['meter_number' => ['value' => InboxFixture::METER, 'quote' => 'Zählernummer … TEST-4711-01', 'page' => 1]]);
        self::assertSame(['ok', []], [$r['meter_number']['status'], $r['meter_number']['reasons']]);
        // der Wert muss in einem Teil stehen, nicht über die Lücke hinweg
        $r = $f(['advance_new' => ['value' => '145,00', 'quote' => 'Abschlag 1 … 45,00 €', 'page' => 1]]);
        self::assertContains('value_not_in_quote', $r['advance_new']['reasons'], '„1 … 45,00“ ist nicht 145,00');
        $r = $f(['advance_new' => ['value' => '145,00', 'quote' => 'Abschlag 1...45,00 €', 'page' => 1]]);
        self::assertContains('value_not_in_quote', $r['advance_new']['reasons'], '„1...45,00“ (drei Punkte) ist auch nicht 145,00');
    }

    public function testAQuoteWithoutEllipsisMaySplitInTwoOnlyStrictly(): void
    {
        $pages = self::tablePages();
        $f = fn(string $field, string $value, string $quote) => Evidence::check('invoice', [$field => ['value' => $value, 'quote' => $quote, 'page' => 1]]
            + InboxFixture::invoiceAnswer(), $pages)[$field];
        // Spaltenkopf und Zeile, der Wert im zweiten Teil: gefunden
        self::assertSame('ok', $f('meter_number', InboxFixture::METER, 'Zählernummer TEST-4711-01')['status']);
        self::assertSame('ok', $f('market_location', InboxFixture::MALO, 'Marktlokation 51234567895')['status']);
        self::assertSame('ok', $f('advance_new', '145,00', 'Ihr neuer Abschlag 145,00 €')['status']);
        // streng: der Wert zuerst, der zweite Teil vor dem ersten, ein erfundener Teil, zu kurz
        self::assertContains('quote_not_found', $f('meter_number', InboxFixture::METER, 'TEST-4711-01 Zählernummer')['reasons'], 'Wert im ersten Teil');
        self::assertContains('quote_not_found', $f('advance_new_from', '01.02.2026', 'gültig ab Ihr neuer Abschlag 01.02.2026')['reasons'], 'erfundene Reihenfolge');
        self::assertContains('quote_not_found', $f('meter_number', 'TEST-4711-99', 'Zählernummer TEST-4711-99')['reasons'], 'Wert steht nicht da');
        self::assertContains('quote_not_found', $f('contract_number', 'VK-778899', 'Kundenkonto VK-778899')['reasons'], 'erster Teil steht nicht da');
        // die beiden Teile auf verschiedenen Seiten: nicht gefunden
        self::assertNull(Evidence::locateSplit('Seite zwei TEST-4711-01', $pages, 1, fn(string $p) => str_contains($p, 'test-4711-01')));
        self::assertSame([1, 'test-4711-01'], Evidence::locateSplit('Zählernummer TEST-4711-01', $pages, 1, fn(string $p) => str_contains($p, 'test-4711-01')));
    }

    public function testATwoPartQuoteNeedsTheValueOnlyInTheSecondPartAndCloseBy(): void
    {
        // Listeneintrag (Arbeitspreis): Spaltenkopf und Zeile, alle Werte im zweiten Teil
        $prices = ["Preise\nArbeitspreis        gültig ab        ct/kWh\nGas Basis        01.01.2025        11,90"];
        $item = fn(string $quote) => Evidence::check('invoice', ['working_prices' => ['value' => [['from' => '01.01.2025', 'ct' => '11,90', 'quote' => $quote, 'page' => 1]]]]
            + InboxFixture::invoiceAnswer(), $prices)['working_prices']['items'][0]['reasons'];
        $r = $item('Arbeitspreis gültig ab ct/kWh 01.01.2025 11,90');
        self::assertSame([[], []], [array_intersect(['quote_not_found'], $r), array_intersect(['value_not_in_quote'], $r)], implode(',', $r));
        self::assertContains('quote_not_found', $item('Arbeitspreis gültig ab ct/kWh 11,90'), 'nur einer der Werte im zweiten Teil');
        $has = fn(string $p) => str_contains($p, 'test-4711-01');
        // der Wert schon im ersten Teil: kein Zerfallen (sonst trüge eine zweite Fundstelle jeden Wert)
        $twice = ["Zähler TEST-4711-01 im Keller, abgelesen am 31.12.2025; Stand TEST-4711-01 laut Gerät"];
        self::assertNull(Evidence::locateSplit('Zähler TEST-4711-01 Stand TEST-4711-01', $twice, 1, $has), 'Wert im ersten Teil');
        // der zweite Teil weit weg vom ersten (andere Tabelle): nicht zusammengesetzt — Nachbesserung 5: nach Zeilen
        $far = ["Zählernummer siehe Rückseite\n" . str_repeat("Hinweise zur Abrechnung und zu Ihrem Vertrag.\n", 3) . "TEST-4711-01"];
        self::assertNull(Evidence::locateSplit('Zählernummer TEST-4711-01', $far, 1, $has), 'drei Zeilen dazwischen');
        $two = ["Zählernummer siehe Rückseite\n" . str_repeat("Hinweise zur Abrechnung und zu Ihrem Vertrag.\n", 1) . "TEST-4711-01"];
        self::assertSame([1, 'test-4711-01'], Evidence::locateSplit('Zählernummer TEST-4711-01', $two, 1, $has), 'zwei Zeilen tiefer: noch nah');
        // eine breite Tabellenzeile: in derselben Zeile zählt der Abstand nicht
        $wide = ["Zählernummer " . str_repeat('Spalte ', 40) . "TEST-4711-01"];
        self::assertSame([1, 'test-4711-01'], Evidence::locateSplit('Zählernummer TEST-4711-01', $wide, 1, $has), 'dieselbe Zeile, über 160 Zeichen');
        // erste Fundstelle weit weg, eine spätere nah: gefunden
        $near = ["Zählernummer siehe unten\n" . str_repeat("Hinweise zur Abrechnung und zu Ihrem Vertrag.\n", 4) . "Zählernummer        Marktlokation\nTEST-4711-01        51234567895"];
        self::assertSame([1, 'test-4711-01'], Evidence::locateSplit('Zählernummer TEST-4711-01', $near, 1, $has));
    }

    public function testUmlautsAsTwoCharactersAreNormalised(): void
    {
        $pages = ["ZÃ¤hlerstand alt am 31.12.2024: 12.345 mÂ³\nGrundgebÃ¼hr 12,50 â‚¬\nAbschla\u{0308}ge Januar bis Juni"];
        self::assertSame(1, Evidence::locate('Zählerstand alt am 31.12.2024: 12.345 m³', $pages), 'als Windows-1252 gelesenes UTF-8');
        self::assertSame(1, Evidence::locate('Grundgebühr 12,50 €', $pages));
        self::assertSame(1, Evidence::locate('Abschläge Januar bis Juni', $pages), 'Buchstabe + Trema');
        self::assertSame(Evidence::normalizeText('Zählerstand'), Evidence::normalizeText("ZÃ¤hlerstand"));
        $r = Evidence::check('invoice', InboxFixture::invoiceAnswer(), [str_replace(['ä', 'ü', '³', '€'], ['Ã¤', 'Ã¼', 'Â³', 'â‚¬'], InboxFixture::invoicePages()[0]),
            ...array_slice(InboxFixture::invoicePages(), 1)]);
        self::assertSame('ok', $r['reading_old']['status'], 'Zitat mit „ä“ und „m³“ gegen die verdrehte Textebene');
    }

    // ── v3.7.0 (treffsicher, Nachbesserung 2) — Netto und Steuersatz ─────

    public function testTheVatRateIsReadNeverGuessed(): void
    {
        self::assertSame(['vat_pct' => 19.0, 'net' => true], Evidence::taxOf('Arbeitspreis netto 10,00 ct/kWh zzgl. 19 % USt'));
        self::assertSame(['vat_pct' => 19.0, 'net' => true], Evidence::taxOf('Arbeitspreis 10,00 ct/kWh … USt 19%', 'net', '19'), 'Modell: netto, Satz im Zitat');
        self::assertSame(['vat_pct' => 7.0, 'net' => false], Evidence::taxOf('11,77 ct/kWh brutto inkl. 7 % USt'));
        self::assertSame(['vat_pct' => 19.0, 'net' => false], Evidence::taxOf('Arbeitspreis 11,90 ct/kWh 19,00 %', 'gross'));
        self::assertNull(Evidence::taxOf('Arbeitspreis netto 10,00 ct/kWh'), 'ohne Satz im Zitat: nicht vermutet');
        self::assertNull(Evidence::taxOf('Arbeitspreis 10,00 ct/kWh', 'net', '19'), 'der Satz des Modells steht nicht im Zitat');
        self::assertNull(Evidence::taxOf('Arbeitspreis 10,00 ct/kWh zzgl. 19 % USt', 'net', '7'), 'anderer Satz als im Zitat');
        self::assertNull(Evidence::taxOf('Arbeitspreis 10,00 ct/kWh 19 %'), 'weder netto noch brutto ausgewiesen');
        self::assertNull(Evidence::taxOf('netto 10,00 ct, brutto 11,90 ct, USt 19 %'), 'beides gedruckt: wie bisher');
        self::assertNull(Evidence::taxOf('Arbeitspreis 11,90 ct/kWh inkl. 19 % USt', 'net', 19), 'Modell sagt netto, Zitat brutto');
        self::assertNull(Evidence::taxOf('netto zzgl. 7 % bzw. 19 % USt'), 'zwei Sätze, keiner genannt');
        self::assertSame(11.9, Evidence::grossOf(10.0, ['vat_pct' => 19.0, 'net' => true]));
        self::assertSame(10.0, Evidence::grossOf(10.0, ['vat_pct' => 19.0, 'net' => false]), 'brutto bleibt');
        self::assertSame(10.0, Evidence::grossOf(10.0, null));
        self::assertEqualsWithDelta(11.0, Evidence::netOf(11.77, ['vat_pct' => 7.0, 'net' => false]), 0.0001);
    }

    // ── v3.7.0 (treffsicher, Nachbesserung 3) ────────────────────────────

    public function testAnEllipsisQuoteKeepsItsPartsInOrderAndCloseTogether(): void
    {
        // erfunden: Bezeichnung in Zeile A, eine fremde Kennung (Zählpunkt) weit darunter
        $pages = ["Ihre Verbrauchsstelle\nMarktlokation        Zählernummer        Tarif\n51234567895        TEST-4711-01        Gas Basis\n"
            . str_repeat("Hinweise zu Ihrer Abrechnung und zum Messstellenbetrieb.\n", 10)
            . "Zählpunkt: DE0001234567890000000000000123456\nWeitere Verbrauchsstelle: 41373559241"];
        $f = fn(string $value, string $quote) => Evidence::check('invoice', ['market_location' => ['value' => $value, 'quote' => $quote, 'page' => 1]]
            + InboxFixture::invoiceAnswer(), $pages)['market_location'];
        $wrong = $f('DE0001234567890000000000000123456', 'Marktlokation … DE0001234567890000000000000123456');
        self::assertSame('check', $wrong['status']);
        self::assertContains('quote_not_found', $wrong['reasons'], 'Kennung weit unter der Bezeichnung: nicht zusammengesetzt');
        self::assertContains('invalid_format', $wrong['reasons'], 'ein Zählpunkt ist keine Marktlokations-ID');
        $far = $f('41373559241', 'Marktlokation … 41373559241');
        self::assertSame(['check', ['quote_not_found']], [$far['status'], $far['reasons']], 'gültige Form, aber weit weg: die Abstandsgrenze greift');
        // Bezeichnung im Spaltenkopf, Wert in der Zeile darunter: gefunden
        self::assertSame(['ok', []], [$f(InboxFixture::MALO, 'Marktlokation … 51234567895')['status'], $f(InboxFixture::MALO, 'Marktlokation … 51234567895')['reasons']]);
        self::assertSame(1, Evidence::locate('Marktlokation … 51234567895', $pages));
        self::assertNull(Evidence::locate('51234567895 … Marktlokation', $pages), 'Reihenfolge des Zitats');
        self::assertNull(Evidence::locate('Marktlokation … 41373559241', $pages));
    }

    public function testTheMarketLocationIdIsCheckedByTheBdewRule(): void
    {
        // BDEW-Anwendungshilfe „Identifikatoren in der Marktkommunikation“ 1.2, Kap. 8.1: 4137355924 → Prüfziffer 1
        $pages = ["Marktlokation: 41373559241\nMarktlokation alt: 41373559242\nMaLo: 01373559241\nMarktlokation: 4137 3559 241\nMaLo-ID 4137355924"];
        $f = fn(string $value, string $quote) => Evidence::check('contract_confirmation', ['market_location' => ['value' => $value, 'quote' => $quote, 'page' => 1]]
            + InboxFixture::confirmationAnswer(), $pages)['market_location'];
        self::assertSame(['ok', []], [$f('41373559241', 'Marktlokation: 41373559241')['status'], $f('41373559241', 'Marktlokation: 41373559241')['reasons']]);
        self::assertSame('ok', $f('4137 3559 241', 'Marktlokation: 4137 3559 241')['status'], 'Leerzeichen zählen nicht');
        self::assertSame(['invalid_format'], $f('41373559242', 'Marktlokation alt: 41373559242')['reasons'], 'falsche Prüfziffer');
        self::assertSame(['invalid_format'], $f('01373559241', 'MaLo: 01373559241')['reasons'], 'erste Stelle 0');
        self::assertSame(['invalid_format'], $f('4137355924', 'MaLo-ID 4137355924')['reasons'], '10 Stellen');
    }

    /** Erfundene Preistabelle: Spalten Preis netto und „USt %“, der Satz je Zeile ohne Prozentzeichen. */
    private const TAX_TABLE = "Position        Zeitraum        Menge        Preis netto        USt %        Betrag netto\n"
        . "Arbeitspreis        01.01.2025 - 31.12.2025        13.216 kWh        10,00 ct/kWh        19,00        1.321,60 €\n"
        . "Grundpreis        12 Monate        10,50 €/Monat        19,00        126,00 €";
    private const ROW_WP = 'Arbeitspreis 01.01.2025 - 31.12.2025 13.216 kWh 10,00 ct/kWh 19,00 1.321,60 €';
    private const ROW_BP = 'Grundpreis 12 Monate 10,50 €/Monat 19,00 126,00 €';

    /** @return array{0: array, 1: array} Arbeitspreis-Eintrag und Grundpreis nach der Prüfung */
    private static function priced(string $page, string $wq = self::ROW_WP, string $wp = '10,00', string $bq = self::ROW_BP, string $bp = '10,50'): array
    {
        $r = Evidence::check('invoice', ['working_prices' => ['value' => [['from' => '01.01.2025', 'ct' => $wp, 'basis' => 'net', 'quote' => $wq, 'page' => 1]]],
            'base_price' => ['value' => $bp, 'quote' => $bq, 'page' => 1], 'base_price_basis' => ['value' => 'net', 'quote' => null, 'page' => null]]
            + InboxFixture::invoiceAnswer(), [$page]);
        return [$r['working_prices']['items'][0], $r['base_price'], $r];
    }

    public function testARateWithoutPercentSignCountsOnlyInATaxColumn(): void
    {
        [$wp, $bp, $all] = self::priced(self::TAX_TABLE);
        self::assertSame(['vat_pct' => 19.0, 'from' => 'column'], $wp['vat_read'] ?? null);
        self::assertSame(['vat_pct' => 19.0, 'from' => 'column'], $bp['vat_read'] ?? null);
        self::assertSame(['vat_pct' => 19.0, 'net' => true], Evidence::itemTax($wp));
        self::assertSame(['vat_pct' => 19.0, 'net' => true], Evidence::fieldTax($all, 'base_price'));
        self::assertContains('net_price', $wp['reasons']);
        // ohne Spaltenkopf mit „%“ und USt/MwSt/Umsatzsteuer: nicht
        [$wp] = self::priced(str_replace('USt %', 'Steuer', self::TAX_TABLE));
        self::assertArrayNotHasKey('vat_read', $wp, 'kein Kopf einer Steuerspalte');
        self::assertNull(Evidence::itemTax($wp));
        // kein üblicher Satz (0, 5, 7, 16, 19)
        [$wp] = self::priced(str_replace('19,00', '18,00', self::TAX_TABLE), str_replace('19,00', '18,00', self::ROW_WP));
        self::assertArrayNotHasKey('vat_read', $wp, '18 ist kein Satz');
        // der Kopf unter der Zeile (eine andere Tabelle)
        $rows = explode("\n", self::TAX_TABLE);
        [$wp] = self::priced($rows[1] . "\n" . $rows[2] . "\n" . $rows[0]);
        self::assertArrayNotHasKey('vat_read', $wp, 'Kopf nach der Zeile');
        // zwei mögliche Sätze in der Zeile: geraten wäre es
        $two = str_replace('Arbeitspreis        01.01', 'Arbeitspreis Stufe 7        01.01', self::TAX_TABLE);
        [$wp] = self::priced($two, str_replace('Arbeitspreis 01.01', 'Arbeitspreis Stufe 7 01.01', self::ROW_WP));
        self::assertArrayNotHasKey('vat_read', $wp, '7 und 19 in einer Zeile');
        // der Preis selbst ist kein Satz
        [, $bp] = self::priced(self::TAX_TABLE . "\nGrundpreis Sondertarif 19,00", self::ROW_WP, '10,00', 'Grundpreis Sondertarif 19,00', '19,00');
        self::assertArrayNotHasKey('vat_read', $bp, 'nur der Preis 19,00 in der Zeile');
        // ein Satz im Zitat geht vor; einer, den das Modell anders nennt, gilt nicht
        self::assertSame(['vat_pct' => 7.0, 'net' => true], Evidence::taxOf('Arbeitspreis 10,00 ct/kWh 7 %', 'net', null, 19.0));
        self::assertNull(Evidence::taxOf('Arbeitspreis 10,00 ct/kWh 19,00', 'net', '7', 19.0));
        self::assertNull(Evidence::taxOf('Arbeitspreis 10,00 ct/kWh 19,00', null, null, 19.0), 'netto muss ausgewiesen sein');
    }

    public function testARateFromAVatLineCountsOnlyWhenTheBillShowsOneRate(): void
    {
        $prices = "Arbeitspreis        01.01.2025 - 31.12.2025        13.216 kWh        10,00 ct/kWh        1.321,60 €\n"
            . "Grundpreis        12 Monate        10,50 €/Monat        126,00 €\nSumme netto        1.447,60 €\n";
        $wq = 'Arbeitspreis 01.01.2025 - 31.12.2025 13.216 kWh 10,00 ct/kWh 1.321,60 €';
        $bq = 'Grundpreis 12 Monate 10,50 €/Monat 126,00 €';
        [$wp, $bp] = self::priced($prices . "Umsatzsteuer 19 % auf 1.447,60 €        275,04 €\nRechnungsbetrag brutto        1.722,64 €", $wq, '10,00', $bq);
        self::assertSame([['vat_pct' => 19.0, 'from' => 'line'], ['vat_pct' => 19.0, 'from' => 'line']], [$wp['vat_read'] ?? null, $bp['vat_read'] ?? null]);
        [$wp] = self::priced($prices . "zzgl. 19 % MwSt.        275,04 €", $wq, '10,00', $bq);
        self::assertSame(19.0, $wp['vat_read']['vat_pct'] ?? null, '„zzgl. 19 % MwSt.“');
        // zwei Sätze im selben Zeitraum: welche Zeile welchen hat, wäre geraten
        [$wp, $bp] = self::priced($prices . "Umsatzsteuer 7 % auf 500,00 €        35,00 €\nUmsatzsteuer 19 % auf 947,60 €        180,04 €", $wq, '10,00', $bq);
        self::assertSame([false, false], [isset($wp['vat_read']), isset($bp['vat_read'])]);
        self::assertNull(Evidence::itemTax($wp));
        // „August“ ist kein Steuerwort, eine Steuernummer kein Satz
        [$wp] = self::priced($prices . "Abschlag August 19 %\nUSt-IdNr. DE123456789", $wq, '10,00', $bq);
        self::assertArrayNotHasKey('vat_read', $wp);
    }

    // ── v3.7.0 (treffsicher, Nachbesserung 4) ────────────────────────────

    /** @return array Arbeitspreis-Eintrag nach der Prüfung (Modell: netto, Satz $vat; Zitat ohne Satz) */
    private static function rowItem(string $page, mixed $vat = '19', mixed $basis = 'net'): array
    {
        $q = 'Arbeitspreis ab 01.01.2025 13.216 kWh 10,00 ct/kWh';
        return Evidence::check('invoice', ['working_prices' => ['value' => [['from' => '01.01.2025', 'ct' => '10,00', 'basis' => $basis, 'vat_pct' => $vat, 'quote' => $q, 'page' => 1]]]]
            + InboxFixture::invoiceAnswer(), [$page])['working_prices']['items'][0];
    }

    public function testARateInTheSameTextLineAsTheQuoteCountsOneLineBelowNot(): void
    {
        // erfunden: das Zitat endet direkt vor „19%“
        $it = self::rowItem("Ihre Preise\nArbeitspreis ab 01.01.2025        13.216 kWh        10,00 ct/kWh        19%        1.321,60 €");
        self::assertSame(['vat_pct' => 19.0, 'from' => 'row'], $it['vat_read'] ?? null);
        self::assertSame(['vat_pct' => 19.0, 'net' => true], Evidence::itemTax($it));
        // eine Zeile tiefer: nicht
        $it = self::rowItem("Ihre Preise\nArbeitspreis ab 01.01.2025        13.216 kWh        10,00 ct/kWh\n19%        1.321,60 €");
        self::assertArrayNotHasKey('vat_read', $it, 'der Satz steht in der Zeile darunter');
        self::assertNull(Evidence::itemTax($it));
        // nur mit netto/brutto und Satz vom Modell, und genau dieser Satz als einziger in der Zeile
        self::assertArrayNotHasKey('vat_read', self::rowItem("Arbeitspreis ab 01.01.2025 13.216 kWh 10,00 ct/kWh 19% 1.321,60 €", null), 'ohne Satz vom Modell');
        self::assertArrayNotHasKey('vat_read', self::rowItem("Arbeitspreis ab 01.01.2025 13.216 kWh 10,00 ct/kWh 19% 1.321,60 €", '19', null), 'ohne netto/brutto vom Modell');
        self::assertArrayNotHasKey('vat_read', self::rowItem("Arbeitspreis ab 01.01.2025 13.216 kWh 10,00 ct/kWh 19% 1.321,60 €", '7'), 'anderer Satz als in der Zeile');
        self::assertArrayNotHasKey('vat_read', self::rowItem("Arbeitspreis ab 01.01.2025 13.216 kWh 10,00 ct/kWh 7% 19% 1.321,60 €"), 'zwei Sätze in der Zeile');
    }

    /** Rechnung mit einem Endstand; `$page` ist die Textebene, `$quote` die Belegstelle des Stands. */
    private static function estimate(string $page, string $quote, mixed $flag = true, ?string $flagQuote = null): array
    {
        return Evidence::check('invoice', ['reading_new' => ['value' => '13.580', 'quote' => $quote, 'page' => 1],
            'reading_new_date' => ['value' => '31.12.2025', 'quote' => $quote, 'page' => 1],
            'reading_new_estimated' => ['value' => $flag, 'quote' => $flagQuote, 'page' => 1]] + InboxFixture::invoiceAnswer(), [$page]);
    }

    public function testAnEstimatedReadingIsReadFromAWordOrACodeTheBillExplains(): void
    {
        // erfunden: Ablesecode in der Zeile, Legende auf dem Dokument
        $line = 'Zählerstand neu am 31.12.2025: 13.580 m³ S';
        $r = self::estimate("$line\nAblesung: A = abgelesen, S = geschätzt, K = Kundenablesung", $line);
        self::assertSame([true, 'code', 'ok'], [$r['reading_new_estimated']['value'], $r['reading_new_estimated']['estimated_by'] ?? null, $r['reading_new_estimated']['status']]);
        // derselbe Code ohne Legende: nicht geschätzt, auch wenn das Modell es meint
        $r = self::estimate($line, $line);
        self::assertSame([false, null], [$r['reading_new_estimated']['value'], $r['reading_new_estimated']['estimated_by'] ?? null]);
        // ein Code, den die Legende anders erklärt
        $r = self::estimate("Zählerstand neu am 31.12.2025: 13.580 m³ A\nA = abgelesen, S = geschätzt", 'Zählerstand neu am 31.12.2025: 13.580 m³ A');
        self::assertFalse($r['reading_new_estimated']['value'], 'A = abgelesen');
        // das Wort im Satz: geschätzt, auch wenn das Modell es nicht meldet
        $s = 'Der Zählerstand neu am 31.12.2025 von 13.580 m³ wurde rechnerisch ermittelt.';
        $r = self::estimate($s, $s, null);
        self::assertSame([true, 'word'], [$r['reading_new_estimated']['value'], $r['reading_new_estimated']['estimated_by'] ?? null]);
        $s2 = 'Zählerstand neu am 31.12.2025: 13.580 m³ (Schätzung)';
        self::assertTrue(self::estimate($s2, $s2, false)['reading_new_estimated']['value'], '„Schätzung“');
        // die Legende allein als Belegstelle belegt nichts; „nicht geschätzt“ ist nicht geschätzt
        $r = self::estimate("Zählerstand neu am 31.12.2025: 13.580 m³\nS = geschätzt", 'Zählerstand neu am 31.12.2025: 13.580 m³', true, 'S = geschätzt');
        self::assertFalse($r['reading_new_estimated']['value'], 'nur die Legende zitiert');
        $s3 = 'Zählerstand neu am 31.12.2025: 13.580 m³ (abgelesen, nicht geschätzt)';
        self::assertFalse(self::estimate($s3, $s3)['reading_new_estimated']['value']);
        // je Eintrag der Stände je Zähler
        $pages = ["Zähler TEST-4711-01        12.345 m³ am 31.12.2024 A        13.580 m³ am 31.12.2025 R\nAblesecodes: A = abgelesen, R = rechnerisch ermittelt"];
        $q = 'Zähler TEST-4711-01 12.345 m³ am 31.12.2024 A 13.580 m³ am 31.12.2025 R';
        $item = fn(array $p) => Evidence::check('invoice', ['meter_readings' => ['value' => [['meter_number' => 'TEST-4711-01', 'reading_old' => '12.345', 'reading_old_date' => '31.12.2024',
            'reading_new' => '13.580', 'reading_new_date' => '31.12.2025', 'estimated' => false, 'quote' => $q, 'page' => 1]]]] + InboxFixture::invoiceAnswer(), $p)['meter_readings'];
        $mr = $item($pages);
        self::assertSame([true, 'code', 'ok'], [$mr['items'][0]['value']['estimated'], $mr['items'][0]['estimated_by'] ?? null, $mr['items'][0]['status']]);
        self::assertTrue($mr['value'][0]['estimated']);
        self::assertFalse($item([str_replace("\nAblesecodes: A = abgelesen, R = rechnerisch ermittelt", '', $pages[0])])['items'][0]['value']['estimated'], 'ohne Legende');
    }

    public function testAnEstimateIsAlsoReadFromAFootnoteACodeGroupAndAReadingColumn(): void
    {
        $q = 'Zählerstand neu am 31.12.2025: 13.580 m³';
        // erfunden: „hochgerechnet“ als Wort
        $s = "$q (hochgerechnet)";
        self::assertSame([true, 'word'], [self::estimate($s, $s, null)['reading_new_estimated']['value'], self::estimate($s, $s, null)['reading_new_estimated']['estimated_by'] ?? null]);
        // Fußnote hinter dem Stand (außerhalb des Zitats), erklärt unten auf der Seite
        $r = self::estimate("$q 5)\nVerbrauch 13.216 kWh\n5) hochgerechnet auf den Stichtag", $q, false);
        self::assertSame([true, 'mark', 'ok'], [$r['reading_new_estimated']['value'], $r['reading_new_estimated']['estimated_by'] ?? null, $r['reading_new_estimated']['status']]);
        self::assertFalse(self::estimate("$q 5)\nVerbrauch 13.216 kWh\n5) Stichtag laut Vertrag", $q)['reading_new_estimated']['value'], 'eine Fußnote, die anderes erklärt');
        self::assertFalse(self::estimate("$q 4)\nVerbrauch 13.216 kWh\n5) hochgerechnet", $q)['reading_new_estimated']['value'], 'eine andere Fußnote');
        // Codegruppe: ein Code der Gruppe ist auf dem Dokument als Schätzung erklärt
        $legend = "\nAblesegrund: 10 = Turnusablesung, 12 = geschätzt, 26 = Zählerwechsel";
        $r = self::estimate("$q 10/12/26$legend", $q, false);
        self::assertSame([true, 'code'], [$r['reading_new_estimated']['value'], $r['reading_new_estimated']['estimated_by'] ?? null]);
        self::assertFalse(self::estimate("$q 10/26$legend", $q)['reading_new_estimated']['value'], 'Gruppe ohne den Code der Schätzung');
        self::assertFalse(self::estimate("$q 10/12/26", $q)['reading_new_estimated']['value'], 'Gruppe ohne Erklärung');
        // Spalte „Ableseart“: eine Zahl darunter zählt, wenn das Dokument sie erklärt
        $table = "Ablesedatum        Zählerstand        Ableseart\n31.12.2025        13.580 m³        2\nAbleseart: 1 = abgelesen, 2 = geschätzt";
        $r = self::estimate($table, '31.12.2025 13.580 m³', false);
        self::assertSame([true, 'code'], [$r['reading_new_estimated']['value'], $r['reading_new_estimated']['estimated_by'] ?? null]);
        self::assertFalse(self::estimate(str_replace('2 = geschätzt', '2 = Kundenablesung', $table), '31.12.2025 13.580 m³')['reading_new_estimated']['value'], 'anders erklärt');
        self::assertFalse(self::estimate("31.12.2025        13.580 m³        2\n2 = geschätzt", '31.12.2025 13.580 m³')['reading_new_estimated']['value'],
            'eine Zahl allein ohne Spalte „Ableseart“: kein Code');
        // Legende unter dem Spaltenkopf ohne Zeichen dazwischen („S Schätzung“)
        $r = self::estimate("Ablesedatum        Zählerstand        Ableseart\n31.12.2025        13.580 m³        S\nAbleseart\nA Ablesung\nS Schätzung", '31.12.2025 13.580 m³', false);
        self::assertSame([true, 'code'], [$r['reading_new_estimated']['value'], $r['reading_new_estimated']['estimated_by'] ?? null]);
    }

    public function testEntriesOfOneMeterAreMergedOnlyWhenTheyFollowEachOther(): void
    {
        $row = fn(string $n, string $d1, string $c1, string $d2, string $c2, string $tail = '') => ['meter_number' => $n, 'reading_old' => $c1, 'reading_old_date' => $d1,
            'reading_new' => $c2, 'reading_new_date' => $d2, 'quote' => "$n $d1 $c1 m³ $d2 $c2 m³$tail", 'page' => 1];
        $check = fn(array $rows, string $page) => Evidence::check('invoice', ['meter_readings' => ['value' => $rows]] + InboxFixture::invoiceAnswer(), [$page])['meter_readings'];
        // erfunden: zwei Zeiträume, der zweite geschätzt (Code erklärt) — geschätzt ist nur, was der Teil sagt
        $page = "TEST-4711-01 31.12.2024 12.345 m³ 30.06.2025 12.900 m³\nTEST-4711-01 30.06.2025 12.900 m³ 31.12.2025 13.580 m³ S\nS = geschätzt";
        $mr = $check([$row('TEST-4711-01', '31.12.2024', '12.345', '30.06.2025', '12.900'), $row('TEST-4711-01', '30.06.2025', '12.900', '31.12.2025', '13.580', ' S')], $page);
        self::assertCount(1, $mr['items']);
        self::assertSame([12345.0, '2024-12-31', 13580.0, '2025-12-31', true], [$mr['value'][0]['reading_old'], $mr['value'][0]['reading_old_date'],
            $mr['value'][0]['reading_new'], $mr['value'][0]['reading_new_date'], $mr['value'][0]['estimated']]);
        self::assertSame([[false, true], [['date' => '2025-06-30', 'counter' => 12900.0, 'estimated' => true]]],
            [$mr['items'][0]['estimated_ends'], $mr['items'][0]['between']], 'Ende des ersten = Anfang des zweiten: der geschätzte Teil zählt');
        // fällt der Stand mit dem Tag: zusammengefasst, aber markiert
        $page2 = "TEST-4711-01 31.12.2024 12.345 m³ 30.06.2025 12.900 m³\nTEST-4711-01 30.06.2025 12.900 m³ 31.12.2025 12.800 m³";
        $mr = $check([$row('TEST-4711-01', '31.12.2024', '12.345', '30.06.2025', '12.900'), $row('TEST-4711-01', '30.06.2025', '12.900', '31.12.2025', '12.800')], $page2);
        self::assertSame(['check', ['inconsistent'], 'check'], [$mr['items'][0]['status'], $mr['items'][0]['reasons'], $mr['status']]);
        // Zweitarifzähler: zwei Stände am selben Tag — zwei Zählwerke, nicht zusammengefasst
        $page3 = "TEST-4711-01 31.12.2024 8.000 m³ 31.12.2025 9.000 m³ HT\nTEST-4711-01 31.12.2024 4.000 m³ 31.12.2025 4.500 m³ NT";
        $mr = $check([$row('TEST-4711-01', '31.12.2024', '8.000', '31.12.2025', '9.000', ' HT'), $row('TEST-4711-01', '31.12.2024', '4.000', '31.12.2025', '4.500', ' NT')], $page3);
        self::assertCount(2, $mr['items']);
        self::assertArrayNotHasKey('merged', $mr['items'][0]);
    }

    public function testAMeterNumberBehindAStarIsMarkedAndTheStarIsNoPartOfTheId(): void
    {
        // erfunden: Sternchen-Kürzel vor der Nummer — was gehört zur Nummer?
        $pages = [str_replace('Zählernummer: ' . InboxFixture::METER, 'Zählernummer: *EMH ' . InboxFixture::METER, InboxFixture::invoicePages()[0])];
        $a = InboxFixture::invoiceAnswer();
        $a['meter_number'] = ['value' => '*EMH ' . InboxFixture::METER, 'quote' => 'Zählernummer: *EMH ' . InboxFixture::METER, 'page' => 1];
        $f = Evidence::check('invoice', $a, $pages);
        self::assertSame('check', $f['meter_number']['status']);
        self::assertContains('invalid_format', $f['meter_number']['reasons']);
        $a['meter_number']['value'] = InboxFixture::METER;   // ohne Kürzel, Zitat mit Sternchen: trotzdem prüfen
        self::assertContains('invalid_format', Evidence::check('invoice', $a, $pages)['meter_number']['reasons']);
        self::assertSame('ok', Evidence::check('invoice', InboxFixture::invoiceAnswer(), InboxFixture::invoicePages())['meter_number']['status'], 'ohne Sternchen wie bisher');
        self::assertTrue(\Energietracker\Services\Inbox\Proposals::sameId('*' . InboxFixture::METER, InboxFixture::METER), 'beim Abgleich zählt das Sternchen nicht');
    }

    public function testAnInconsistentBillMarksEveryAmountInvolved(): void
    {
        $pages = InboxFixture::invoicePages();
        $raw = InboxFixture::invoiceAnswer();
        $pages[0] = str_replace('1.722,70', '1.448,49', $pages[0]);
        $raw['total_amount'] = ['value' => '1.448,49', 'quote' => 'Rechnungsbetrag brutto: 1.448,49 €', 'page' => 1];
        $f = Evidence::check('invoice', $raw, $pages);
        foreach (['result_amount', 'total_amount', 'advances_total'] as $n) self::assertContains('inconsistent', $f[$n]['reasons'], "$n: geht nicht auf");
        // ohne Summe der Abschläge: jeder Abschlag
        unset($raw['advances_total']);
        $f = Evidence::check('invoice', $raw, $pages);
        self::assertSame(array_fill(0, count($f['advances_paid']['items']), 'check'), array_column($f['advances_paid']['items'], 'status'));
        // geht es auf, bleibt alles, wie es war
        self::assertNotContains('inconsistent', Evidence::check('invoice', InboxFixture::invoiceAnswer(), InboxFixture::invoicePages())['total_amount']['reasons']);
    }

    public function testDateAndTitleAreOnlyMandatoryWhereAProposalNeedsThem(): void
    {
        // Typen, die alles prüfen lassen (Ableseaufforderung, Zählerwechsel): Datum und Titel, belegt, sind keine Marke
        $page = "Ablesekarte\nMusterstadt, den 02.01.2026\nBitte melden Sie Ihren Zählerstand bis zum 15.01.2026.\nZählernummer TEST-4711-01\nWechsel am 10.01.2026";
        $q = fn(string $v, string $quote) => ['value' => $v, 'quote' => $quote, 'page' => 1];
        $head = ['document_date' => $q('02.01.2026', 'Musterstadt, den 02.01.2026'), 'document_title' => $q('Ablesekarte', 'Ablesekarte')];
        foreach (['reading_request' => ['requested_by' => $q('15.01.2026', 'bis zum 15.01.2026')], 'meter_change' => ['change_date' => $q('10.01.2026', 'Wechsel am 10.01.2026')]] as $t => $core) {
            self::assertContains($t, InboxSchema::REVIEW_ALL);
            $f = Evidence::check($t, $head + $core, [$page]);
            foreach (['document_date', 'document_title'] as $n) self::assertSame(['ok', []], [$f[$n]['status'], $f[$n]['reasons']], "$t: $n");
            $name = array_key_first($core);
            self::assertSame(['check', ['mandatory']], [$f[$name]['status'], $f[$name]['reasons']], "$t: $name bleibt pflichtgeprüft");
        }
    }

    public function testUmlautsFromMacRomanBytesReadAsWindows1252AreNormalised(): void
    {
        // die Gegenrichtung von „√§“: Mac-Roman-Bytes als Windows-1252 gelesen
        self::assertSame(Evidence::normalizeText('Zählerstand Grundgebühr Größe'), Evidence::normalizeText('ZŠhlerstand GrundgebŸhr Gršße'));
        self::assertSame(1, Evidence::locate('Zählerstand alt am 31.12.2024', ['ZŠhlerstand alt am 31.12.2024: 12.345']));
    }

    public function testDocumentDateTitleAndReadingsPerMeterAreCheckedLikeOtherFields(): void
    {
        foreach (InboxSchema::TYPES as $t) {
            $f = InboxSchema::fields($t);
            if (!InboxSchema::extracts($t)) { self::assertSame([], $f); continue; }
            self::assertSame(['date', 'text'], [$f['document_date']['kind'] ?? null, $f['document_title']['kind'] ?? null], "$t: Datum und Titel");
            self::assertSame($t === 'invoice', isset($f['meter_readings']), "$t: Stände je Zähler nur an der Rechnung");
        }
        self::assertSame(['meter_number', 'reading_old', 'reading_old_date', 'reading_new', 'reading_new_date', 'estimated'], array_keys(InboxSchema::fields('invoice')['meter_readings']['items']));
        self::assertSame(4, InboxSchema::forProvider('invoice')['version'], 'Nachbesserung 5: Beschreibungen geändert');
        // erfundene Rechnungskorrektur: Titel nur im Seitenkopf, Briefdatum
        $pages = ["Rechnungskorrektur · Seite 1 von 2\nStadtwerke Musterstadt GmbH · Musterstadt, den 20.03.2026\nAbrechnungszeitraum: 01.01.2025 bis 31.12.2025\n"
            . "Zähler TEST-4711-01        Anfangsstand 12.345 m³ am 31.12.2024        Endstand 12.910 m³ am 14.05.2025\n"
            . "Zähler TEST-9999-02        Anfangsstand 0,5 m³ am 14.05.2025        Endstand 702,3 m³ am 31.12.2025"];
        $f = fn(string $n, mixed $v, ?string $q) => ['value' => $v, 'quote' => $q, 'page' => 1];
        $r = Evidence::check('invoice', ['document_title' => $f('', 'Rechnungskorrektur', 'Rechnungskorrektur · Seite 1 von 2'),
            'document_date' => $f('', '20.03.2026', 'Musterstadt, den 20.03.2026'),
            'meter_readings' => ['value' => [
                ['meter_number' => 'TEST-4711-01', 'reading_old' => '12.345', 'reading_old_date' => '31.12.2024', 'reading_new' => '12.910', 'reading_new_date' => '14.05.2025',
                 'quote' => 'Zähler TEST-4711-01 Anfangsstand 12.345 m³ am 31.12.2024 Endstand 12.910 m³ am 14.05.2025', 'page' => 1],
                ['meter_number' => 'TEST-9999-02', 'reading_old' => '0,5', 'reading_old_date' => '14.05.2025', 'reading_new' => '702,3', 'reading_new_date' => '30.12.2025',
                 'quote' => 'Zähler TEST-9999-02 Anfangsstand 0,5 m³ am 14.05.2025 Endstand 702,3 m³ am 31.12.2025', 'page' => 1],
            ]]] + InboxFixture::invoiceAnswer(), $pages);
        self::assertSame(['ok', 'Rechnungskorrektur'], [$r['document_title']['status'], $r['document_title']['value']]);
        self::assertSame(['ok', '2026-03-20'], [$r['document_date']['status'], $r['document_date']['value']]);
        self::assertSame(['ok', 'TEST-4711-01', 12910.0, '2025-05-14'], [$r['meter_readings']['items'][0]['status'], $r['meter_readings']['items'][0]['value']['meter_number'],
            $r['meter_readings']['items'][0]['value']['reading_new'], $r['meter_readings']['items'][0]['value']['reading_new_date']]);
        self::assertSame('check', $r['meter_readings']['items'][1]['status'], 'ein Datum, das nicht im Zitat steht (30.12. statt 31.12.)');
        self::assertContains('value_not_in_quote', $r['meter_readings']['items'][1]['reasons']);
        $r = Evidence::check('invoice', ['document_date' => $f('', '21.03.2026', 'Musterstadt, den 20.03.2026')] + InboxFixture::invoiceAnswer(), $pages);
        self::assertContains('value_not_in_quote', $r['document_date']['reasons'], 'das Datum steht im Zitat');
        // fehlen sie, ist das keine Marke: kein Vorschlag braucht sie
        $r = Evidence::check('invoice', InboxFixture::invoiceAnswer(), InboxFixture::invoicePages());
        self::assertSame([['empty', ['missing']], ['empty', ['missing']], ['empty', ['missing']]],
            [[$r['document_date']['status'], $r['document_date']['reasons']], [$r['document_title']['status'], $r['document_title']['reasons']],
             [$r['meter_readings']['status'], $r['meter_readings']['reasons']]]);
    }

    public function testUmlautsReadAsMacRomanAreNormalised(): void
    {
        $pages = ["Z√§hlerstand alt am 31.12.2024: 12.345 m¬≥\nGrundgeb√ºhr 12,50 ‚Ç¨\n‚ÄûStra√üe‚Äú"];
        self::assertSame(1, Evidence::locate('Zählerstand alt am 31.12.2024: 12.345 m³', $pages));
        self::assertSame(1, Evidence::locate('Grundgebühr 12,50 €', $pages));
        self::assertSame(Evidence::normalizeText('„Straße“'), Evidence::normalizeText('‚ÄûStra√üe‚Äú'));
    }

    public function testFieldsAreFlaggedLikeInTheProbe(): void
    {
        $pages = InboxFixture::invoicePages();
        $raw = InboxFixture::invoiceAnswer();
        $raw['total_amount'] = ['value' => '1.722,70', 'quote' => 'Rechnungsbetrag netto: 1.722,70 €', 'page' => 1];   // Zitat erfunden
        $raw['consumption'] = ['value' => '13.261', 'quote' => '= 13.216 kWh', 'page' => 1];                         // Wert nicht im Zitat
        $raw['reading_old'] = ['value' => '12.345', 'quote' => null, 'page' => null];                                 // ohne Beleg
        unset($raw['advance_new']);                                                                                   // Kernfeld leer
        unset($raw['customer_number']);                                                                               // optional leer
        $f = Evidence::check('invoice', $raw, $pages);
        self::assertSame(['quote_not_found'], $f['total_amount']['reasons']);
        self::assertSame(['value_not_in_quote'], $f['consumption']['reasons']);
        self::assertSame(['no_quote'], $f['reading_old']['reasons']);
        self::assertSame('check', $f['advance_new']['status']);
        self::assertSame(['empty'], $f['advance_new']['reasons']);
        self::assertSame('empty', $f['customer_number']['status'], 'leere Nebenfelder sind keine Marke');
        self::assertSame('ok', $f['period_from']['status']);
        self::assertSame('2025-01-01', $f['period_from']['value']);
        self::assertSame('ok', $f['result_kind']['status'], 'Stichwort „Nachzahlung“ im Zitat');
        self::assertContains('price_from_invoice', $f['working_prices']['reasons'], 'Preise aus Rechnungen: Pflichtprüfung');
        self::assertSame('check', $f['working_prices']['items'][0]['status']);
        self::assertSame('ok', $f['gas_factors']['status']);
        self::assertSame(['from' => '2025-01-01', 'to' => '2025-12-31', 'z' => 0.9512, 'hs' => 11.25], $f['gas_factors']['value'][0]);
    }

    public function testEnumsNeedTheirKeywordInTheQuote(): void
    {
        $pages = ['Ihr Guthaben: 42,70 €'];
        $raw = ['result_kind' => ['value' => 'due', 'quote' => 'Ihr Guthaben: 42,70 €', 'page' => 1]];
        self::assertSame(['value_not_in_quote'], Evidence::check('invoice', $raw, $pages)['result_kind']['reasons']);
        $raw['result_kind']['value'] = 'credit';
        self::assertSame('ok', Evidence::check('invoice', $raw, $pages)['result_kind']['status']);
        $raw['result_kind']['value'] = 'irgendwas';
        self::assertSame(['unreadable'], Evidence::check('invoice', $raw, $pages)['result_kind']['reasons']);
    }

    public function testTheServerSumsTheAdvancesAndChecksTheResult(): void
    {
        $pages = InboxFixture::invoicePages();
        $f = Evidence::check('invoice', InboxFixture::invoiceAnswer(), $pages);
        self::assertSame(1680.0, $f['advances_paid']['sum']);
        self::assertSame('ok', $f['advances_total']['status']);
        self::assertSame('ok', $f['result_amount']['status'], '1.722,70 − 1.680,00 = 42,70');

        $raw = InboxFixture::invoiceAnswer();
        $raw['advances_total'] = ['value' => '1.860,00', 'quote' => 'Summe der Abschläge: 1.680,00 €', 'page' => 2];
        $g = Evidence::check('invoice', $raw, $pages);
        self::assertContains('sum_mismatch', $g['advances_total']['reasons']);
        $raw = InboxFixture::invoiceAnswer();
        $pages[0] = str_replace('1.722,70', '1.448,49', $pages[0]);   // netto statt brutto erwischt
        $raw['total_amount'] = ['value' => '1.448,49', 'quote' => 'Rechnungsbetrag brutto: 1.448,49 €', 'page' => 1];
        self::assertContains('inconsistent', Evidence::check('invoice', $raw, $pages)['result_amount']['reasons']);
    }

    public function testExpectedDatesAreKeptButFlagged(): void
    {
        $pages = ['Ihr Lieferbeginn ist voraussichtlich der 01.03.2026.'];
        $raw = ['supply_start' => ['value' => '01.03.2026', 'quote' => 'Ihr Lieferbeginn ist voraussichtlich der 01.03.2026.', 'page' => 1]];
        $f = Evidence::check('supply_start', $raw, $pages)['supply_start'];
        self::assertSame('2026-03-01', $f['value']);
        self::assertSame(['estimated'], $f['reasons']);
    }

    public function testSmallTypesAreAlwaysFlagged(): void
    {
        $pages = ['Zählerwechsel am 15.05.2026. Alter Zähler TEST-1 Endstand 4.711,5, neuer Zähler TEST-2 Anfangsstand 0,0'];
        $q = $pages[0];
        $raw = ['change_date' => ['value' => '15.05.2026', 'quote' => 'Zählerwechsel am 15.05.2026', 'page' => 1],
                'old_reading' => ['value' => '4.711,5', 'quote' => 'Endstand 4.711,5', 'page' => 1],
                'new_meter_number' => ['value' => 'TEST-2', 'quote' => $q, 'page' => 1]];
        $f = Evidence::check('meter_change', $raw, $pages);
        foreach (['change_date', 'old_reading', 'new_meter_number'] as $n) self::assertSame(['mandatory'], $f[$n]['reasons'], $n);
        self::assertSame(4711.5, $f['old_reading']['value']);
        self::assertSame(3 + 2, Evidence::flagCount($f), 'drei markiert, zwei leere Kernfelder');
    }

    public function testDatesInEveryGermanSpelling(): void
    {
        self::assertSame(['2025-12-31'], Evidence::datesIn('31.12.2025'));
        self::assertSame(['2025-12-31'], Evidence::datesIn('31.12.25'));
        self::assertSame(['2026-03-01'], Evidence::datesIn('1. März 2026'));
        self::assertSame(['2026-03-01'], Evidence::datesIn('2026-03-01'));
        self::assertSame([], Evidence::datesIn('31.02.2026'), 'kein Kalendertag');
    }

    public function testTermsPagesAreDroppedAndTheTextIsCut(): void
    {
        $prep = TextPrep::prepare(InboxFixture::invoicePages());
        self::assertSame([1, 2], $prep['kept']);
        self::assertSame([3], $prep['dropped'], 'AGB-Seite fällt weg');
        self::assertStringStartsWith('[Seite 1]', $prep['text']);
        self::assertStringContainsString('[Seite 2]', $prep['text']);
        self::assertFalse($prep['truncated']);
        self::assertFalse(TextPrep::isTermsPage('Allgemeine Geschäftsbedingungen', 0), 'nie die erste Seite');
        $long = TextPrep::prepare([str_repeat('Verbrauch 12 kWh. ', 300), str_repeat('Abschlag 10 €. ', 300)], 3000);
        self::assertTrue($long['truncated']);
        self::assertLessThanOrEqual(3000, $long['chars']);
        self::assertFalse(TextPrep::hasText(['', '  ', '12 34']), 'Scan ohne Textebene');
        self::assertTrue(TextPrep::hasText(InboxFixture::invoicePages()));
    }
}
