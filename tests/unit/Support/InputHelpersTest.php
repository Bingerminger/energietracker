<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

use Energietracker\Support\Csv;
use Energietracker\Support\Dates;
use Energietracker\Support\Input;
use Energietracker\Support\LocalizedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 — die gemeinsame Eingabeprüfung (Support\Input, Dates::isInputDate) und
 * die CSV-Rundreise (Support\Csv), ohne Dienste: was eine Eingabe ist und was nicht.
 */
#[CoversClass(Input::class)]
#[CoversClass(Csv::class)]
#[CoversClass(Dates::class)]
final class InputHelpersTest extends TestCase
{
    public function testADateWithATrailingLineBreakIsNoDate(): void
    {
        // ohne Modifier D passte `$` vor dem abschließenden Zeilenumbruch
        self::assertFalse(Dates::isIsoDate("2026-11-04\n"));
        self::assertFalse(Dates::isIsoDate("2026-11-04\r\n"));
        self::assertTrue(Dates::isIsoDate('2026-11-04'));
    }

    public function testInputDatesStayBetween1900And2100(): void
    {
        foreach (['1900-01-01', '1999-12-31', '2026-11-09', '2100-12-31'] as $ok) self::assertTrue(Dates::isInputDate($ok), $ok);
        // Jahr 0001, Tippfehler 0202 für 2025, Jahr 1899 und 2101 sind formal Kalendertage, aber keine Eingabe
        foreach (['0001-01-01', '0202-05-01', '1899-12-31', '2101-01-01', '9999-12-31'] as $bad) {
            self::assertTrue(Dates::isIsoDate($bad), "$bad ist ein Kalendertag (tolerant beim Lesen)");
            self::assertFalse(Dates::isInputDate($bad), "$bad liegt außerhalb des Eingabebereichs");
        }
    }

    public function testTheRangeErrorHasItsOwnCode(): void
    {
        try {
            Input::assertDateInRange('0001-01-01');
            self::fail('Jahr 0001 hätte abgelehnt werden müssen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.common.dateOutOfRange', $e->key);
            self::assertSame(['field' => 'date', 'date' => '0001-01-01', 'min' => '1900', 'max' => '2100'], $e->params);
        }
        Input::assertDateInRange('2025-13-01');   // keine Formprüfung hier: die meldet der Aufrufer mit seinem Schlüssel
        self::assertTrue(true);
    }

    public function testDecimalReadsOneCommaAsDecimalSeparator(): void
    {
        self::assertSame(12.5, Input::decimal('12,5'));
        self::assertSame(12.5, Input::decimal(' 12.5 '));
        self::assertSame(120.5, Input::decimal('120,5'));
        self::assertSame(-3.0, Input::decimal('-3'));
        self::assertSame(7.0, Input::decimal(7));
        self::assertSame(0.25, Input::decimal(0.25));
        // mehrdeutig oder kein Wert
        foreach (['1.234,5', '1,234.5', '12,5,1', 'abc', 'viel', '', ' ', 'INF', 'NaN', '0x1A'] as $bad) {
            self::assertNull(Input::decimal($bad), var_export($bad, true));
        }
        foreach ([[1], ['a' => 1], true, false, null, INF, NAN] as $bad) self::assertNull(Input::decimal($bad));
    }

    public function testTextRejectsListsObjectsAndBooleans(): void
    {
        self::assertSame('x', Input::text(['n' => 'x'], 'n'));
        self::assertSame('12345', Input::text(['n' => 12345], 'n'), 'eine Seriennummer als Zahl ist Text');
        self::assertSame('', Input::text([], 'n'));
        self::assertNull(Input::text(['n' => null], 'n', null));
        foreach ([['x'], ['a' => 1], true] as $bad) {
            try {
                Input::text(['note' => $bad], 'note');
                self::fail('hätte abgelehnt werden müssen: ' . json_encode($bad));
            } catch (LocalizedException $e) {
                self::assertSame('errors.common.textExpected', $e->key);
                self::assertSame(['field' => 'note'], $e->params);
            }
        }
    }

    public function testBoolKnowsYesAndNoButNotAnything(): void
    {
        foreach ([true, 1, '1', 'true', 'ja', 'yes', 'on', 'TRUE'] as $y) self::assertTrue(Input::bool($y), var_export($y, true));
        foreach ([false, 0, '0', 'false', 'nein', 'no', 'off', '', null] as $n) self::assertFalse(Input::bool($n), var_export($n, true));
        foreach (['vielleicht', 'x', 2, [true], 0.5] as $bad) self::assertNull(Input::bool($bad), var_export($bad, true));
    }

    public function testRecordsKeepQuotedLineBreaksTogether(): void
    {
        $csv = "Datum;Notiz;Geschaetzt\r\n2025-02-01;\"Zeile 1\nZeile 2\";ja\n2025-03-01;\"mit \"\"Zitat\"\"\";nein\n\n2025-04-01;;\n";
        $rec = Csv::records($csv);
        self::assertSame([1, 2, 4, 5, 6], array_column($rec, 'line'), 'Zeilennummer des Datensatzbeginns');
        self::assertSame("2025-02-01;\"Zeile 1\nZeile 2\";ja", $rec[1]['text']);
        self::assertSame(['2025-02-01', "Zeile 1\nZeile 2", 'ja'], str_getcsv($rec[1]['text'], ';', '"', ''));
        self::assertSame(['2025-03-01', 'mit "Zitat"', 'nein'], str_getcsv($rec[2]['text'], ';', '"', ''));
        self::assertSame('', $rec[3]['text'], 'Leerzeile bleibt ein leerer Datensatz (der Import überspringt sie)');
    }

    public function testAQuoteInsideAFieldOrAnOpenQuoteFallsBackToLines(): void
    {
        // ein Anführungszeichen mitten im Feld öffnet nichts
        self::assertSame(['a;Rohr 5" lang;1', 'b;x;2'], array_column(Csv::records("a;Rohr 5\" lang;1\nb;x;2\n"), 'text'));
        // offen bis zum Ende (von Hand geschrieben): jede Zeile für sich, wie bisher
        self::assertSame(['a;"offen;1', 'b;x;2'], array_column(Csv::records("a;\"offen;1\nb;x;2"), 'text'));
    }

    public function testFormulaGuardAndItsReversalAreOneRule(): void
    {
        foreach (['=1+1', '+49', '-x', '@SUM', "\tx", "\r=1", ' =1+1', "'=1+1", "''=x", "'-5"] as $s) {
            self::assertTrue(Csv::formulaLike($s), var_export($s, true));
            self::assertSame($s, Csv::unguard("'" . $s), 'Rundreise: ' . var_export($s, true));
        }
        foreach (['Text', "'x", "'", '5', ''] as $s) {
            self::assertFalse(Csv::formulaLike($s), var_export($s, true));
            self::assertSame($s, Csv::unguard($s), 'unverändert: ' . var_export($s, true));
        }
    }
}
