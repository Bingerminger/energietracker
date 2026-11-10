<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\Inbox\Evidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 — Belegprüfung an Zahlgrenzen. Bis v3.11 galt ein Zitat als belegt, wenn es
 * irgendwo im Text stand: Das ausgedachte „0 m³“ steckt in „13.580 m³“, „80,00 €“ in
 * „180,00 €“. Die Wege über Anbieter, Cloud und KI hält McpEvidenceBoundaryTest fest.
 */
final class InboxEvidenceBoundaryTest extends TestCase
{
    private const PAGE = "Zählerstand neu am 31.12.2025: 13.580 m³\nRechnungsbetrag: 180,00 €\nVerbrauch 1.235 m³\n"
        . "Zählernummer: 1234567\nDatum: 31.12.2025\nGrundpreis 1'234,50 CHF";

    /** @return iterable<string,array{string}> */
    public static function fragments(): iterable
    {
        yield 'Ende einer Zahl mit Tausenderpunkt' => ['0 m³'];
        yield 'Tausendergruppe' => ['580 m³'];
        yield 'Anfang fehlt' => ['3.580 m³'];
        yield 'vorn abgeschnitten' => ['80,00 €'];
        yield 'nur Nachkommastellen' => ['0,00 €'];
        yield 'beginnt mit dem Trennzeichen' => [',00 €'];
        yield 'hinten abgeschnitten' => ['13.58'];
        yield 'hinten vor dem Komma' => ['Rechnungsbetrag: 180'];
        yield 'endet mit dem Trennzeichen' => ['Rechnungsbetrag: 180,'];
        yield 'Teil einer Nummer' => ['23456'];
        yield 'Teil eines Datums' => ['1.12.2025'];
        yield 'Apostroph als Tausendertrenner' => ['234,50 CHF'];
        yield 'ohne Leerraum' => ['0m³'];
    }

    #[DataProvider('fragments')]
    public function testAPieceOfALongerNumberIsNoEvidence(string $quote): void
    {
        self::assertNull(Evidence::locate($quote, [self::PAGE], 1), $quote);
    }

    /** @return iterable<string,array{string}> */
    public static function wholeNumbers(): iterable
    {
        yield 'mit Einheit' => ['13.580 m³'];
        yield 'Betrag' => ['180,00 €'];
        yield 'Zeile' => ['Zählerstand neu am 31.12.2025: 13.580 m³'];
        yield 'Teile mit Auslassung' => ['Rechnungsbetrag … 1.235 m³'];
        yield 'Satzzeichen danach' => ['Rechnungsbetrag: 180,00'];
        yield 'anders gesetzter Leerraum' => ['13.580m³'];
        yield 'mit Apostroph' => ["1'234,50 CHF"];
        yield 'Nummer ganz' => ['1234567'];
    }

    #[DataProvider('wholeNumbers')]
    public function testAWholeNumberStaysEvidence(string $quote): void
    {
        self::assertSame(1, Evidence::locate($quote, [self::PAGE], 1), $quote);
    }

    public function testNumbersNextToEachOtherStayApartEvenWithoutWhitespace(): void
    {
        // Spalten: das Leerzeichen trennt zwei Zahlen, beide Lesarten bleiben (auch ohne Leerraum gesucht)
        $table = "Tage 35 580 m³\nStand 31.12.2025 13.580m³";
        self::assertSame(1, Evidence::locate('580 m³', [$table], 1));
        self::assertSame(1, Evidence::locate('13.580 m³', [$table], 1), 'Grenze aus dem Leerraum vor der Zahl');
        // Zeilenumbruch: eine Grenze
        self::assertSame(1, Evidence::locate('13.580 m³', ["Stand 2025\n13.580\nm³"], 1));
        // gesperrte Ziffern werden erst zusammengezogen, dann geprüft
        self::assertSame(1, Evidence::locate('315,59 €', ['Betrag 3 1 5,5 9 €'], 1));
        self::assertNull(Evidence::locate('15,59 €', ['Betrag 3 1 5,5 9 €'], 1));
    }

    /**
     * v3.12.0 — Ziffern in einer Kennung (Zählernummer, Aktenzeichen) belegen keinen Wert. Bis v3.11 gewann das erste
     * passende Zahlwort, auch „0002“ aus „ABC-K-0002“: Ein als 2 gelesener Stand galt als belegt, und die deutsche
     * Lesart der freien Zahl „2.000“ (2000) griff nicht. Eine Zahl muss als eigene Zahl im Zitat stehen.
     */
    public function testDigitsInsideAnIdentifierAreNoEvidence(): void
    {
        $v = new \ReflectionMethod(Evidence::class, 'valueFor');
        $num = ['kind' => 'number'];
        $at = fn(mixed $raw, string $quote): array => $v->invoke(null, $num, $raw, $quote);
        self::assertSame([2000.0, true], $at(2, 'ABC-K-0002 15.02.2025 2.000 kWh abgelesen'), 'die freie Zahl gilt, deutsch gelesen');
        self::assertSame([2.0, false], $at(2, 'ABC-K-0002 15.02.2025 3.000 kWh abgelesen'), 'die 2 steht nur in der Kennung');
        self::assertSame([815.0, false], $at(815, 'Zähler G-815 am 02.03.2025: 4.000 m³'));
        self::assertSame([345.0, false], $at(345, 'Aktenzeichen AZ12/345'));
        self::assertSame([12.0, false], $at(12, 'Aktenzeichen AZ12/345'));
        self::assertSame([7.0, false], $at(7, 'Vertrag 4711-B, Stand 900 kWh'), 'Kennung mit Buchstaben dahinter');
        // eigene Zahlen bleiben Beleg, auch ohne Leerraum zur Einheit oder zum Wort davor
        self::assertSame([1250.0, true], $at(1250, 'Stand 1.250m³'));
        self::assertSame([80.0, true], $at(80, 'Abschlag €80,00'));
        self::assertSame([34.56, true], $at(34.56, 'Guthaben: -34,56 €'));
        // v3.12.0 — eine Nummer direkt hinter ihrer Bezeichnung ist die Nummer (testANumberRightBehindItsLabelIsNoEvidence)
        self::assertSame([12345.0, false], $at(12345, 'Zählernummer 12345'));
        self::assertSame([12345.0, true], $at(12345, 'Zählerstand 12345'));
        self::assertSame([2000.0, true], $at(2000, 'Zählerstand2.000 m³'));
        self::assertSame([1500.0, true], $at(1500, 'Betrag CHF1500'));
        self::assertNotContains(2.0, Evidence::numbersIn('ABC-K-0002'));
    }

    /**
     * v3.12.0 — Eine Nummer, die mit Leerraum hinter ihrer Bezeichnung steht, belegte jeden gleich großen Wert: ein
     * Betrag 7 aus „Kundennummer 0007“, ein Verbrauch aus „Vertragskonto 512340“. Die Bezeichnungen sind dieselben wie
     * in der Maske der KI-Schnittstelle; was danach im Zitat steht, bleibt Beleg. Werte erfunden.
     */
    public function testANumberRightBehindItsLabelIsNoEvidence(): void
    {
        $v = new \ReflectionMethod(Evidence::class, 'valueFor');
        $num = ['kind' => 'number'];
        $at = fn(mixed $raw, string $quote): array => $v->invoke(null, $num, $raw, $quote);
        self::assertSame([7.0, false], $at(7, 'Kundennummer 0007'));
        self::assertSame([512340.0, false], $at(512340, 'Vertragskonto 512340'));
        self::assertSame([512340.0, false], $at(512340, 'Vertragskonto: 512340'));
        self::assertSame([88.0, false], $at(88, 'Kd.-Nr. 88'));
        self::assertSame([4321.0, false], $at(4321, 'Kassenzeichen 4321'));
        self::assertSame([99.0, false], $at(99, 'Customer number 99'));
        // die Zahl danach bleibt ein Beleg, ebenso eine Zahl ohne Bezeichnung davor
        self::assertSame([96.5, true], $at(96.5, 'Kundennummer 0007 Betrag 96,50 €'));
        self::assertSame([7.0, true], $at(7, 'Abschlag 7,00 €'));
        self::assertSame([512340.0, true], $at(512340, '512340 kWh'));
        self::assertNotContains(7.0, Evidence::numbersIn('Kundennummer 0007'));
    }

    public function testAProtectedSpaceIsAThousandsSeparator(): void
    {
        $page = "Stand 13\u{00A0}580 m³ und 2\u{202F}345,50 €";
        self::assertNull(Evidence::locate('580 m³', [$page], 1), 'geschützte Lücke: dieselbe Zahl');
        self::assertNull(Evidence::locate('345,50 €', [$page], 1));
        self::assertSame(1, Evidence::locate("13\u{00A0}580 m³", [$page], 1));
        self::assertSame(1, Evidence::locate('13 580 m³', [$page], 1), 'vom Modell mit gewöhnlichem Leerzeichen');
        self::assertSame([[13580.0]], Evidence::numberTokens("13\u{00A0}580"), 'keine zweite Lesart');
        self::assertSame([[13580.0], [13.0], [580.0]], Evidence::numberTokens('13 580'), 'gewöhnliches Leerzeichen: beide Lesarten wie bisher');
    }

    /** Der Fund aus dem Paket schreiben: Zitat mit Bezeichnung und erfundenem Stand, zerlegt in zwei Stellen. */
    public function testTheInventedReadingIsFlagged(): void
    {
        $f = Evidence::check('invoice', ['reading_new' => ['value' => 0, 'quote' => 'Zählerstand neu am 31.12.2025: 0 m³', 'page' => 1]], [self::PAGE])['reading_new'];
        self::assertSame('check', $f['status']);
        self::assertContains('quote_not_found', $f['reasons']);
        $bare = Evidence::check('invoice', ['total_amount' => ['value' => '80,00', 'quote' => '80,00 €', 'page' => 1]], [self::PAGE])['total_amount'];
        self::assertContains('quote_not_found', $bare['reasons'], 'kurzes Zitat ohne Bezeichnung');
        // der gedruckte Wert bleibt belegt
        $ok = Evidence::check('invoice', ['reading_new' => ['value' => '13.580', 'quote' => 'Zählerstand neu am 31.12.2025: 13.580 m³', 'page' => 1]], [self::PAGE])['reading_new'];
        self::assertSame(['ok', [], 13580.0], [$ok['status'], $ok['reasons'], $ok['value']]);
    }

    public function testATruncatedIdentifierIsNotInItsQuote(): void
    {
        $f = Evidence::check('invoice', ['meter_number' => ['value' => '23456', 'quote' => 'Zählernummer: 1234567', 'page' => 1]], [self::PAGE])['meter_number'];
        self::assertContains('value_not_in_quote', $f['reasons'], 'Teilstück der Nummer');
        $whole = Evidence::check('invoice', ['meter_number' => ['value' => '1234567', 'quote' => 'Zählernummer: 1234567', 'page' => 1]], [self::PAGE])['meter_number'];
        self::assertNotContains('value_not_in_quote', $whole['reasons']);
        $spaced = Evidence::check('invoice', ['meter_number' => ['value' => '123 4567', 'quote' => 'Zählernummer: 1234567', 'page' => 1]], [self::PAGE])['meter_number'];
        self::assertNotContains('value_not_in_quote', $spaced['reasons'], 'Leerraum im Wert zählt nicht');
    }
}
