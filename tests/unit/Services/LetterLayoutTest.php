<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\LetterService;
use Energietracker\Services\Pdf\PdfWriter;
use Energietracker\Tests\Support\LetterFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.11.0 (F1036, Paket briefe) — der Brief als PDF passt in einen Fensterumschlag
 * (DIN 5008 Form B, Konzept §4): Anschriftfeld 20 mm vom linken Rand, 45 mm von oben,
 * 85 × 45 mm; Rücksendeangabe in der Vermerkzone (17,7 mm), Anschrift darunter; nichts sonst
 * im Fenster; Falzmarken bei 105 und 210 mm; nichts über den Rand, keine Überdeckung.
 * Geprüft am Box-Protokoll des PdfWriter. Am echten Umschlag prüft die Sichtprüfung.
 */
#[CoversClass(LetterService::class)]
final class LetterLayoutTest extends ServiceTestCase
{
    use LetterFixture;

    private const MM = 72 / 25.4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->letterSetUp();
    }

    private function writer(string $check, array $body = []): PdfWriter
    {
        $w = $this->letters->pdfWriter($this->letter($check, $body));
        $w->output();   // Fußzeile läuft erst hier
        return $w;
    }

    /** @return array{x0:float,y0:float,x1:float,y1:float} Fenster in Punkt */
    private static function window(float $top, float $bottom): array
    {
        $w = LetterService::WINDOW;
        return ['x0' => $w['x'] * self::MM, 'y0' => $top * self::MM, 'x1' => ($w['x'] + $w['w']) * self::MM, 'y1' => $bottom * self::MM];
    }

    private static function inside(array $b, array $r): bool
    {
        return $b['x0'] >= $r['x0'] - 0.01 && $b['x1'] <= $r['x1'] + 0.01 && $b['y0'] >= $r['y0'] - 0.01 && $b['y1'] <= $r['y1'] + 0.01;
    }

    private static function overlaps(array $a, array $b): bool
    {
        return $a['x0'] < $b['x1'] - 0.01 && $b['x0'] < $a['x1'] - 0.01 && $a['y0'] < $b['y1'] - 0.01 && $b['y0'] < $a['y1'] - 0.01;
    }

    private function assertLayout(PdfWriter $w, array $recipient, string $label): void
    {
        $win = LetterService::WINDOW;
        $note = self::window($win['y'], $win['y'] + $win['note_h']);
        $address = self::window($win['y'] + $win['note_h'], $win['y'] + $win['h']);
        $field = self::window($win['y'], $win['y'] + $win['h']);
        $left = LetterService::MARGIN_LEFT * self::MM;
        $right = $w->pageWidth() - LetterService::MARGIN_RIGHT * self::MM;
        $texts = array_values(array_filter($w->boxes(), fn($b) => $b['kind'] === 'text'));
        $inWindow = array_values(array_filter($texts, fn($b) => $b['page'] === 1 && self::overlaps($b, $field)));

        // Fenster: oben die Rücksendeangabe (eine Zeile, Vermerkzone), darunter die Anschrift
        self::assertNotEmpty($inWindow, $label);
        self::assertTrue(self::inside($inWindow[0], $note), "$label: Rücksendeangabe in der Vermerkzone");
        self::assertSame('Erika Mustermann, Musterweg 1, 12345 Musterstadt', $inWindow[0]['text'], $label);
        $lines = array_slice($inWindow, 1);
        self::assertCount(count($recipient), $lines, "$label: nur die Anschrift im Fenster");
        foreach ($lines as $i => $b) {
            self::assertTrue(self::inside($b, $address), "$label: Zeile „{$b['text']}“ außerhalb der Anschriftzone");
            // ganz oder (zu breit auch in der kleinsten Schrift) gekürzt mit „…“
            if (str_ends_with($b['text'], '…')) self::assertStringStartsWith(mb_substr($b['text'], 0, -1), $recipient[$i], $label);
            else self::assertSame($recipient[$i], $b['text'], $label);
        }
        foreach ($texts as $b) {
            // Text innerhalb der Ränder (links 25 mm, rechts 20 mm, unten 10 mm für die Seitenzahl)
            self::assertGreaterThanOrEqual($left - 0.01, $b['x0'], "$label: „{$b['text']}“ links über den Rand");
            self::assertLessThanOrEqual($right + 0.01, $b['x1'], "$label: „{$b['text']}“ rechts über den Rand");
            self::assertGreaterThan(10 * self::MM, $b['y0'], $label);
            self::assertLessThan($w->pageHeight() - 8 * self::MM, $b['y1'], $label);
        }
        for ($i = 0; $i < count($texts); $i++) {
            for ($j = $i + 1; $j < count($texts); $j++) {
                if ($texts[$i]['page'] !== $texts[$j]['page']) continue;
                self::assertFalse(self::overlaps($texts[$i], $texts[$j]), "$label: „{$texts[$i]['text']}“ überdeckt „{$texts[$j]['text']}“");
            }
        }
        // Falzmarken (105, 210 mm) und Lochmarke (148,5 mm) am linken Rand, außerhalb des Textes
        $marks = array_values(array_filter($w->boxes(), fn($b) => $b['kind'] === 'shape' && $b['page'] === 1 && $b['x1'] < $left));
        self::assertEqualsWithDelta([105.0, 148.5, 210.0], array_map(fn($b) => round(($b['y0'] + $b['y1']) / 2 / self::MM, 1), $marks), 0.05, $label);
        // Betreff unter dem Anschriftfeld
        $subject = array_values(array_filter($texts, fn($b) => $b['size'] === LetterService::BODY_SIZE))[0];
        self::assertGreaterThan(($win['y'] + $win['h']) * self::MM, $subject['y0'], "$label: Betreff unter dem Fenster");
    }

    public function testEveryLetterFitsAWindowEnvelope(): void
    {
        foreach (array_keys($this->raw) as $check) {
            $w = $this->writer($check);
            $model = $this->letter($check);
            $this->assertLayout($w, $model['recipient'] ?: ['[…]'], $check);
            self::assertSame(1, $w->pageCount(), "$check: ein Blatt");
        }
    }

    public function testSixLongAddressLinesStayInsideTheWindow(): void
    {
        $recipient = ['Stadtwerke Musterstadt Versorgungs- und Verkehrsgesellschaft', 'Abteilung Kundenservice Energie und Wasser',
            'z. Hd. Frau Mustermann-Beispielname', 'Postfach 12 34 56', str_repeat('W', 60), '12345 Musterstadt-Beispielhausen'];
        $w = $this->writer('special_termination', ['recipient' => $recipient,
            'sender' => ['Erika Mustermann', 'Musterweg 1', '12345 Musterstadt']]);
        $this->assertLayout($w, $recipient, 'sechs lange Zeilen');
        $clipped = array_values(array_filter($w->boxes(), fn($b) => $b['kind'] === 'text' && str_starts_with((string)$b['text'], 'WWW')));
        self::assertCount(1, $clipped);
        self::assertStringEndsWith('…', $clipped[0]['text'], 'auch in der kleinsten Schrift zu breit: gekürzt');
    }

    public function testALongLetterBreaksOntoASecondPageWithPageNumbers(): void
    {
        $long = trim(str_repeat('Im letzten Jahr hat sich der Haushalt verkleinert, und der Verbrauch ist deutlich gesunken. ', 21));
        $w = $this->writer('advance_high', ['extra' => ['reasons' => mb_substr($long, 0, LetterService::TEXT_MAX)]]);
        self::assertSame(2, $w->pageCount());
        $footer = array_values(array_filter($w->boxes(), fn($b) => $b['role'] === 'footer'));
        self::assertSame(['Seite 1 von 2', 'Seite 2 von 2'], array_column($footer, 'text'));
        $this->assertLayout($w, ['Gaswerk Musterstadt'], 'zwei Seiten');
    }

    public function testThePdfIsAGermanPdfWithTitle(): void
    {
        $pdf = $this->letters->pdf($this->letter('bill_late'));
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('/Lang (de)', $pdf);
        self::assertStringContainsString('Beanstandung: verspätete Rechnung', iconv('CP1252', 'UTF-8', $pdf) ?: '');
    }
}
