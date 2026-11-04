<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Rights;
use Energietracker\Http\HttpStatusException;
use Energietracker\Services\LetterService;
use Energietracker\Services\RightsService;
use Energietracker\Tests\Support\LetterFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.11.0 (F1036, Paket briefe) — die fünf Briefe aus festen Vorlagen (Konzept §4).
 *
 * Inhalt je Vorlage mit den Angaben, die der Empfänger zur Zuordnung braucht; Normen nur aus
 * Config\Rights (Prüfbericht §12); keine erfundenen Rechtsfolgen; fehlende Angaben als
 * sichtbare Lücke; Widerspruch nur nach Pflichtbestätigung (Entscheidung 5b); nichts
 * gespeichert (Entscheidung 10a). Befunde erfunden (tests/unit/Support/LetterFixture.php),
 * Erwartungen von Hand.
 */
#[CoversClass(LetterService::class)]
final class LetterServiceTest extends ServiceTestCase
{
    use LetterFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->letterSetUp();
    }

    /** @return array<string, array{string, list<string>}> Prüfung → Pflichtangaben im Brief (von Hand) */
    public static function letters(): array
    {
        return [
            'Sonderkündigung' => ['special_termination', [
                'Kündigung wegen Preisänderung', 'Schreiben vom 20.10.2026', 'Preisänderung zum 01.12.2026',
                'kündige ich den Vertrag nach § 41 Abs. 5 Satz 4 EnWG zum Zeitpunkt des Wirksamwerdens der Preisänderung',
                'Bitte bestätigen Sie mir die Kündigung und das Vertragsende in Textform.',
                'Kundennummer: K-4711', 'Vertragsnummer: V-0815', 'Stadtwerke Musterstadt']],
            'Abschlag' => ['advance_high', [
                'Antrag auf Anpassung des Abschlags', 'vom 01.01.2025 bis 31.12.2025 betrug 12.000 kWh',
                '(Arbeitspreis 10,50 ct/kWh, Grundpreis 12,00 € im Monat)', 'rund 98,40 € je Monat',
                'Nach § 13 GasGVV richtet sich der Abschlag nach dem Verbrauch im zuletzt abgerechneten Zeitraum.',
                'ab dem 01.12.2026 auf 99,00 € festzusetzen', 'Kundennummer: G-0042', 'Gaswerk Musterstadt']],
            'Schätzung' => ['estimate_despite_reading', [
                'Rückfrage zur Schätzung in Ihrer Rechnung vom 15.10.2026', 'zum 30.09.2026 als geschätzt ausgewiesen (45.210 kWh)',
                'am 28.09.2026 selbst abgelesen: 44.890,5 kWh und Ihnen den Stand am 29.09.2026 mitgeteilt',
                'nach § 40a Abs. 2 Satz 3 EnWG Grund und Faktoren der Schätzung', 'Zählernummer: Z-1234567',
                'Marktlokation: 51234567895', 'Anlage:', 'Foto des Zählerstands vom 28.09.2026']],
            'Rechnung zu spät' => ['bill_late', [
                'Beanstandung: verspätete Rechnung vom 01.03.2026', 'Zeitraum vom 01.01.2025 bis 31.12.2025 ist vom 01.03.2026; sie lag mir am 04.03.2026 vor',
                'Nach § 40c Abs. 2 EnWG ist die Rechnung spätestens sechs Wochen nach Ende des Abrechnungszeitraums',
                'Diese Frist endete am 11.02.2026.', 'innerhalb von vier Wochen, wie sie § 111a EnWG']],
            'Bonus' => ['bonus_missing', [
                'Beanstandung: Bonus fehlt in Ihrer Rechnung vom 20.02.2026', 'laut meinem Vertrag „Grundversorgung“ steht mir ein Bonus von 100,00 € zu',
                'Rechnungsnummer: R-2026-17', 'Bitte schreiben Sie mir den Bonus gut', '§ 111a EnWG']],
            'Messentgelt' => ['metering_cap', [
                'Beanstandung: Entgelt für den Messstellenbetrieb', 'umgerechnet 60,00 € im Jahr (brutto)',
                'Für ein intelligentes Messsystem nennt § 30 Abs. 1, 3 MsbG für den grundzuständigen Messstellenbetreiber eine Obergrenze von 30,00 € im Jahr.',
                '§ 111a EnWG']],
            'Widerspruch' => ['fee_notice_deadline', [
                'Widerspruch gegen den Bescheid vom 27.10.2026',
                'gegen den Bescheid über Abwassergebühren 2026 vom 27.10.2026 (Aktenzeichen/Kassenzeichen KZ 2026-0815) lege ich hiermit Widerspruch ein.',
                'Die Begründung reiche ich nach.', 'Bitte bestätigen Sie mir den Eingang des Widerspruchs.',
                'Aktenzeichen/Kassenzeichen: KZ 2026-0815', '______________________________']],
        ];
    }

    #[DataProvider('letters')]
    public function testEveryLetterCarriesItsRequiredParts(string $check, array $expected): void
    {
        $m = $this->letter($check);
        $text = str_replace("\u{00A0}", ' ', $m['text']);
        foreach ($expected as $part) self::assertStringContainsString($part, $text, "$check: „{$part}“ fehlt");
        // Angaben in jedem Brief (Konzept §4): Absender, Ort und Datum, Betreff, Anrede, Gruß, Name
        foreach (['Erika Mustermann', 'Musterweg 1', '12345 Musterstadt', 'Musterstadt, 03.11.2026',
                  'Sehr geehrte Damen und Herren,', 'Mit freundlichen Grüßen'] as $part) {
            self::assertStringContainsString($part, $text, "$check: „{$part}“ fehlt");
        }
        self::assertSame(RightsService::CHECKS[$check][0], $m['kind']);
        self::assertSame('Erika Mustermann', $m['signature']);
        self::assertSame($m['text'], implode("\n", $m['lines']), 'Text und Zeilen aus einem Modell');
        // nichts fehlt mehr: alle Pflichtangaben aus Befund, Vertrag und Zähler (Empfänger der Behörde fehlt)
        self::assertSame($check === 'fee_notice_deadline' || $check === 'metering_cap' ? ['recipient'] : [], $m['gaps'], $check);
        self::assertStringNotContainsString('[…]', implode("\n", $m['paragraphs']), $check);
    }

    /**
     * Keine Rechtsaussage ohne Eintrag im Prüfbericht: Die Bausteine nennen keine Norm als Text,
     * jede Norm im Brief kommt aus Config\Rights, ist nennbar (nicht U, nicht `cite: false`)
     * und steht in `norms`.
     */
    public function testNormsComeOnlyFromConfigRights(): void
    {
        $tpl = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/src/Services/Letters/de.json'), true);
        unset($tpl['_comment']);
        self::assertDoesNotMatchRegularExpression('/§|Abs\.|EnWG|GVV|VwGO|MsbG|AVB|BGB/u', json_encode($tpl, JSON_UNESCAPED_UNICODE),
            'Normen nur über {norms} aus Config\Rights');
        // nur nennbare Regeln: Status U und `cite: false` nennt kein Brief, auch wenn sie einschlägig wären
        $cite = new \ReflectionMethod(LetterService::class, 'cite');
        self::assertSame(['de.vwgo.70.1'], $cite->invoke($this->letters, ['de.vwgo.70.1', 'de.land.vorverfahren', 'de.enwg.40c.2'], 'DE', 'wasser'));
        self::assertSame([], $cite->invoke($this->letters, ['de.avbfernwaermev.24.1'], 'DE', 'fernwaerme'), 'Status U');
        foreach (self::letters() as [$check]) {
            $m = $this->letter($check);
            $named = array_column($m['norms'], 'norm');
            foreach ($m['norms'] as $n) self::assertTrue(Rights::citable('DE', $n['key']), $n['key']);
            preg_match_all('/§+ [^,.;()]*?(?:EnWG|StromGVV|GasGVV|MsbG|VwGO|AVBWasserV|AVBFernwärmeV)/u', $m['text'], $hits);
            foreach ($hits[0] as $hit) {
                self::assertNotEmpty(array_filter($named, fn($n) => str_contains($n, $hit) || str_contains($hit, $n)), "$check: „{$hit}“ nicht aus Config\\Rights");
            }
        }
    }

    /** Konzept §4 „Nicht im Brief“, Grundsatz 3: keine Urteile, Drohungen, Kürzungen oder erfundenen Folgen. */
    public function testNoInventedLegalConsequences(): void
    {
        $forbidden = '/unwirksam|rechtswidrig|nichtig|Schadensersatz|Zinsen|Verzug|Mahn|zurückhalt|zurückbehalt|kürze|kürzen|Anwalt|Klage|Gericht|'
            . 'andernfalls|ansonsten|Konsequenz|Rechtsberatung|verpflichtet|verwirkt|unverzüglich|Fristsetzung|setze Ihnen/iu';
        foreach (self::letters() as [$check]) {
            $m = $this->letter($check, ['extra' => ['reason' => null]]);
            self::assertDoesNotMatchRegularExpression($forbidden, $m['text'], $check);
        }
        $raw = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Services/Letters/de.json');
        $json = json_decode($raw, true);
        unset($json['_comment']);
        self::assertDoesNotMatchRegularExpression($forbidden, json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    /** Fehlende Angaben: sichtbare Lücke „[…]“, in `gaps` genannt, nie erfunden. */
    public function testMissingFactsLeaveVisibleGaps(): void
    {
        $this->raw['special_termination']['facts'] = ['valid_from' => '2026-12-01'];
        $this->contracts->update('strom', $this->stromContract, ['customer_number' => '', 'provider' => '']);
        $m = $this->letters->render(['finding_id' => $this->ids['special_termination']], self::TODAY)['model'];
        self::assertStringContainsString('mit Schreiben vom […] eine Preisänderung zum 01.12.2026', $m['text']);
        self::assertStringContainsString('Kundennummer: […]', $m['text']);
        self::assertSame(['sender', 'recipient', 'customer_number', 'announced_on'], $m['gaps']);
        self::assertSame('[…]', $m['signature']);
        self::assertStringNotContainsString('Zählerstand', $m['text'], 'optionaler Baustein ohne Angaben entfällt');
        self::assertStringNotContainsString('Lieferanschrift', $m['text'], 'Bezugszeichen ohne Wert und ohne Pflicht entfällt');
    }

    /** Entscheidung 5b: Widerspruch nur nach Pflichtbestätigung; kurz und fristwahrend; Schriftform. */
    public function testObjectionNeedsTheConfirmation(): void
    {
        foreach (['text', 'pdf'] as $format) {
            try {
                $this->letters->render(['finding_id' => $this->ids['fee_notice_deadline'], 'format' => $format], self::TODAY);
                self::fail("$format ohne Bestätigung");
            } catch (HttpStatusException $e) {
                self::assertSame([422, 'errors.letter.confirmRequired'], [$e->status, $e->key]);
            }
        }
        $m = $this->letter('fee_notice_deadline');
        self::assertSame(['written', true], [$m['form'], $m['signature_line']]);
        self::assertCount(3, $m['paragraphs'], 'kurz: Widerspruch, Begründung folgt, Eingangsbestätigung');

        $m = $this->letter('fee_notice_deadline', ['extra' => ['reason' => "Die Fläche ist falsch.\nSiehe Anlage.", 'suspend' => true]]);
        self::assertSame('Zur Begründung: Die Fläche ist falsch. Siehe Anlage.', $m['paragraphs'][1]);
        self::assertStringContainsString('die Vollziehung des Bescheids bis zur Entscheidung über den Widerspruch auszusetzen', $m['paragraphs'][2]);
        self::assertStringNotContainsString('reiche ich nach', $m['text']);
        self::assertSame([], $this->letter('bill_late')['signature_line'] ? ['line'] : [], 'Textform genügt: keine Unterschriftszeile');
    }

    /** Entscheidung 6a: Norm nach Sondervertrag oder Grundversorgung, ohne Angabe beide. */
    public function testSpecialTerminationNamesTheNormOfTheSupplyKind(): void
    {
        $norm = fn() => array_column($this->letter('special_termination')['norms'], 'norm');
        self::assertSame(['§ 41 Abs. 5 Satz 4 EnWG'], $norm());
        $this->contracts->update('strom', $this->stromContract, ['supply_kind' => 'basic']);
        self::assertSame(['§ 5 Abs. 3 StromGVV'], $norm());
        $this->contracts->update('strom', $this->stromContract, ['supply_kind' => '']);
        self::assertSame(['§ 41 Abs. 5 Satz 4 EnWG', '§ 5 Abs. 3 StromGVV'], $norm());
        self::assertStringContainsString('nach § 41 Abs. 5 Satz 4 EnWG bzw. § 5 Abs. 3 StromGVV zum', $this->letter('special_termination')['text']);
    }

    /** Dialog: Vorbelegung aus Vertrag und Zähler, Eingaben gehen vor, leer heißt „weglassen“. */
    public function testDefaultsAndInputs(): void
    {
        $m = $this->letter('special_termination');
        self::assertSame(['Stadtwerke Musterstadt'], $m['recipient']);
        self::assertSame(['customer_number' => 'K-4711', 'contract_number' => 'V-0815', 'meter_serial' => 'Z-1234567', 'malo_id' => '51234567895'],
            $m['defaults']['refs']);
        self::assertSame('Erika Mustermann, Musterweg 1, 12345 Musterstadt', $m['return_line']);

        $m = $this->letter('special_termination', [
            'recipient' => "Stadtwerke Musterstadt GmbH\nKundenservice\nPostfach 1\n12345 Musterstadt",
            'refs' => ['customer_number' => 'K-9', 'meter_serial' => '', 'malo_id' => ''],
            'extra' => ['reading_value' => 12345.6, 'reading_date' => '2026-11-30'],
        ]);
        self::assertSame(['Stadtwerke Musterstadt GmbH', 'Kundenservice', 'Postfach 1', '12345 Musterstadt'], $m['recipient']);
        self::assertSame(['Kundennummer: K-9', 'Vertragsnummer: V-0815'], array_map(fn($r) => $r['label'] . ': ' . $r['value'], $m['refs']));
        self::assertContains('Mein Zählerstand am 30.11.2026: 12.345,6 kWh. Bitte legen Sie ihn der Schlussrechnung zugrunde.', $m['paragraphs']);

        $m = $this->letter('advance_high', ['extra' => ['amount_eur' => 90, 'reasons' => 'Seit Juli wohnen wir zu zweit statt zu dritt.']]);
        self::assertStringContainsString("auf 90,00\u{00A0}€ festzusetzen", $m['text']);
        self::assertContains('Seit Juli wohnen wir zu zweit statt zu dritt.', $m['paragraphs']);
        self::assertSame(99.0, $this->letter('advance_high')['defaults']['extra']['amount_eur'], 'Bemessung auf volle Euro aufgerundet');

        // Foto als Anlage abwählbar
        self::assertSame([], $this->letter('estimate_despite_reading', ['extra' => ['attach_photo' => false]])['attachments']);
    }

    /** Wasser und Fernwärme: kein § 40a EnWG und keine Verbraucherbeschwerde nach § 111a EnWG. */
    public function testNoEnergyNormsOutsideElectricityAndGas(): void
    {
        $this->raw['estimate_despite_reading']['utility'] = 'wasser';
        $this->raw['estimate_despite_reading']['meter_id'] = $this->wasserMeter;
        $this->raw['estimate_despite_reading']['contract_id'] = null;
        $this->raw['estimate_despite_reading']['rules'] = ['de.avbwasserv.20.2'];
        $id = RightsService::id('estimate_despite_reading', ['wasser', $this->wasserMeter, null, null]);
        $m = $this->letters->render(['finding_id' => $id] + $this->person(), self::TODAY)['model'];
        self::assertSame([], $m['norms']);
        self::assertStringContainsString('Bitte erläutern Sie mir Grund und Grundlage der Schätzung', $m['text']);
        self::assertStringContainsString('(45.210 m³)', $m['text'], 'Einheit des Zählerstands der Art');
        self::assertDoesNotMatchRegularExpression('/§/u', $m['text']);
    }

    /** @return array<string, array{array<string,mixed>, int, string}> */
    public static function invalid(): array
    {
        return [
            'Format'          => [['format' => 'docx'], 400, 'errors.letter.formatInvalid'],
            'Befund unbekannt' => [['finding_id' => 'rights-bill_late-000000000000'], 404, 'errors.letter.findingNotFound'],
            'keine ID'        => [['finding_id' => '../etc'], 404, 'errors.letter.findingNotFound'],
            'Vorlage unbekannt' => [['kind' => 'lawsuit'], 400, 'errors.letter.kindInvalid'],
            'Vorlage nicht angeboten' => [['kind' => 'objection'], 422, 'errors.letter.notOffered'],
            'sieben Zeilen'   => [['sender' => ['1', '2', '3', '4', '5', '6', '7']], 400, 'errors.letter.fieldInvalid'],
            'Zeile zu lang'   => [['recipient' => [str_repeat('x', 61)]], 400, 'errors.letter.fieldInvalid'],
            'Absender Objekt' => [['sender' => ['a' => 'b']], 400, 'errors.letter.fieldInvalid'],
            'Ort mehrzeilig'  => [['place' => ['Musterstadt']], 400, 'errors.letter.fieldInvalid'],
            'Bezug Liste'     => [['refs' => ['K-1']], 400, 'errors.letter.fieldInvalid'],
            'Datum falsch'    => [['extra' => ['reading_date' => '30.11.2026']], 400, 'errors.letter.fieldInvalid'],
            'Stand negativ'   => [['extra' => ['reading_value' => -1]], 400, 'errors.letter.fieldInvalid'],
        ];
    }

    #[DataProvider('invalid')]
    public function testInvalidInputIsRejected(array $body, int $status, string $key): void
    {
        try {
            $this->letters->render($body + ['finding_id' => $this->ids['special_termination']], self::TODAY);
            self::fail('angenommen');
        } catch (HttpStatusException $e) {
            self::assertSame([$status, $key], [$e->status, $e->key]);
        }
    }

    /** Ein Befund ohne Brief (A4 mit Bonus im Portal, A7 mit Klage) bietet keinen an; anderes Land: kein Befund. */
    public function testNoLetterWithoutOfferOrOutsideGermany(): void
    {
        $this->raw['fee_notice_deadline']['letter'] = null;
        try {
            $this->letter('fee_notice_deadline', ['kind' => 'objection']);
            self::fail('angeboten');
        } catch (HttpStatusException $e) {
            self::assertSame(422, $e->status);
        }
        $this->settings->set(['country' => 'AT']);
        $this->expectExceptionObject(new HttpStatusException(404, 'errors.letter.findingNotFound'));
        try {
            $this->letter('bill_late');
        } catch (HttpStatusException $e) {
            self::assertSame([404, 'errors.letter.findingNotFound'], [$e->status, $e->key]);
            throw $e;
        }
    }

    /** Entscheidung 10a: Der Brief schreibt nichts — kein Absender, kein Empfänger in den Daten. */
    public function testRenderingStoresNothing(): void
    {
        $before = $this->snapshot();
        $m = $this->letter('special_termination', ['recipient' => ['Stadtwerke Musterstadt', 'Postfach 9']]);
        $this->letters->pdf($m);
        self::assertSame($before, $this->snapshot());
        self::assertStringNotContainsString('Mustermann', implode('', array_map('file_get_contents', $this->files())));
    }

    /** @return list<string> */
    private function files(): array
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS));
        $out = [];
        foreach ($it as $f) if ($f->isFile()) $out[] = $f->getPathname();
        sort($out);
        return $out;
    }

    private function snapshot(): string
    {
        return md5(implode('|', array_map(fn($f) => $f . ':' . md5_file($f), $this->files())));
    }
}
