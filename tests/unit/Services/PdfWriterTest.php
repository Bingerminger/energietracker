<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\Pdf\Metrics;
use Energietracker\Services\Pdf\PdfWriter;
use Energietracker\Tests\Support\PdfInspector;
use PHPUnit\Framework\TestCase;

/**
 * v3.4.0 (F1025) — der erweiterte PdfWriter: Struktur, Schrift, Grafik,
 * Barrierefreiheit (ohne getaggtes PDF) und das Box-Protokoll.
 */
final class PdfWriterTest extends TestCase
{
    public function testStructureWithFooterBookmarksInfoAndLanguage(): void
    {
        $w = new PdfWriter();
        $w->setInfo(['Title' => 'Jahresbericht 2025 – Haus', 'Producer' => 'Energietracker']);
        $w->setLang('de');
        $w->setFooter(fn(PdfWriter $p, int $page, int $count) => $p->text(48, 820, "Seite $page von $count", 8.5));
        foreach (['Eins', 'Zwei', 'Drei'] as $i => $title) {
            $w->addPage();
            $w->bookmark($title, 0, 48);
            if ($i === 1) $w->bookmark('Unterpunkt', 1, 200);
            $w->text(48, 80, $title, 12);
        }
        $pdf = $w->output();
        self::assertSame([], PdfInspector::structureIssues($pdf));
        self::assertSame(3, PdfInspector::pageCount($pdf));
        $texts = PdfInspector::texts($pdf);
        self::assertContains('Seite 3 von 3', $texts, 'Fußzeile kennt die Seitenzahl');
        self::assertStringContainsString('/Lang (de)', $pdf);
        self::assertStringContainsString('/ViewerPreferences << /DisplayDocTitle true >>', $pdf);
        self::assertStringContainsString('/PageMode /UseOutlines', $pdf);
        self::assertMatchesRegularExpression('/\/Type \/Outlines \/First \d+ 0 R \/Last \d+ 0 R \/Count 3/', $pdf, 'drei Lesezeichen oben');
        self::assertMatchesRegularExpression('/\/Count -1/', $pdf, 'ein Unterpunkt, zugeklappt');
        // Titel als UTF-16BE: der Gedankenstrich bleibt
        $title = '<FEFF' . strtoupper(bin2hex((string)iconv('UTF-8', 'UTF-16BE', 'Jahresbericht 2025 – Haus'))) . '>';
        self::assertStringContainsString("/Title $title", $pdf);
        self::assertMatchesRegularExpression('/\/CreationDate \(D:\d{14}[+-]\d{2}\'\d{2}\'\)/', $pdf);
        self::assertStringContainsString('/Info 3 0 R', $pdf);
    }

    public function testInvalidLanguageTagIsLeftOut(): void
    {
        $w = new PdfWriter();
        $w->setLang('de) /Evil (x');
        $w->addPage();
        self::assertStringNotContainsString('/Lang', $w->output());
    }

    public function testRealWidthsCenterAndRightAlign(): void
    {
        $w = new PdfWriter();
        self::assertEqualsWithDelta(Metrics::width('WWW', 10), 3 * 9.44, 0.001);
        self::assertEqualsWithDelta(3 * 2.22, $w->textWidth('iii', 10), 0.001, 'schmale Buchstaben sind schmal');
        self::assertGreaterThan($w->textWidth('Abc', 10), $w->textWidth('Abc', 10, true), 'fett ist breiter');
        $w->addPage();
        $w->textRight(500, 100, 'rechts', 10);
        $w->textCenter(300, 120, 'mitte', 10);
        $b = $w->boxes();
        self::assertEqualsWithDelta(500.0, $b[0]['x1'], 0.01);
        self::assertEqualsWithDelta(300.0, ($b[1]['x0'] + $b[1]['x1']) / 2, 0.01);
    }

    public function testWrapUsesWidthsBreaksLongWordsAndHonoursNewlines(): void
    {
        $w = new PdfWriter();
        $lines = $w->wrap('Ein Satz, der umbrechen muss, weil er länger ist als die Spalte.', 10, 120);
        self::assertGreaterThan(1, count($lines));
        foreach ($lines as $l) self::assertLessThanOrEqual(120.0, $w->textWidth($l, 10));
        $long = $w->wrap('Donaudampfschifffahrtsgesellschaftskapitän', 10, 60);
        self::assertGreaterThan(1, count($long), 'ein zu langes Wort wird hart getrennt');
        foreach ($long as $l) self::assertLessThanOrEqual(60.0, $w->textWidth($l, 10));
        self::assertSame('Donaudampfschifffahrtsgesellschaftskapitän', implode('', $long), 'nichts geht verloren');
        self::assertSame(['eins', 'zwei'], $w->wrap("eins\nzwei", 10, 500));
    }

    public function testGraphicsOperators(): void
    {
        $w = new PdfWriter();
        $w->addPage();
        $w->circle(100, 100, 10, [255, 0, 0]);
        $w->line(50, 200, 150, 200, 1.0, [0, 0, 0], [4, 3]);
        $w->save();
        $w->clipRect(50, 300, 100, 50);
        $w->restore();
        $w->hatch(50, 400, 40, 20, [255, 255, 255]);
        $w->path([['M', 10, 10], ['L', 20, 20], ['L', 10, 20], ['Z']], [0, 0, 255], null, 0.5, true);
        $w->roundRect(60, 500, 100, 30, 6, [200, 200, 200], [0, 0, 0]);
        $w->symbol(60, 600, 'check', 12, [0, 128, 0]);
        $s = PdfInspector::pageStreams($w->output())[0];
        self::assertGreaterThanOrEqual(8, substr_count($s, " c\n"), 'Kreis und runde Ecken aus Bézier-Vierteln');
        self::assertStringContainsString('[4 3] 0 d', $s, 'Strichmuster');
        self::assertStringContainsString("re W n\n", $s, 'Beschneiden');
        self::assertSame(substr_count($s, "q\n"), substr_count($s, "Q\n"), 'q/Q paarweise');
        self::assertStringContainsString("f*\n", $s, 'even-odd');
        self::assertStringContainsString('/F3 12 Tf', $s, 'Symbol aus ZapfDingbats');
        self::assertStringContainsString('(4) Tj', $s, '✔ = Code 52');
    }

    public function testBoxLogAndRollback(): void
    {
        $w = new PdfWriter();
        $w->addPage();
        $w->text(48, 100, 'oben', 10);
        $cp = $w->checkpoint();
        $w->text(48, 120, 'probe', 10);
        $w->rect(48, 130, 10, 10, [0, 0, 0]);
        self::assertCount(3, $w->boxes());
        $w->rollback($cp);
        self::assertCount(1, $w->boxes(), 'Probezeichnung zurückgenommen');
        $w->paragraph(48, 140, 'Fließtext', 10.5, 200);
        $b = $w->boxes()[1];
        self::assertSame('para', $b['role']);
        self::assertEqualsWithDelta(140 - Metrics::ASCENT * 10.5 / 1000, $b['y0'], 0.001, 'Oberkante = Grundlinie − Oberlänge');
        self::assertSame(1, $b['page']);
        $pdf = $w->output();
        self::assertStringNotContainsString('probe', $pdf);
        self::assertSame([], PdfInspector::boxIssues($w->boxes(), $w->pageWidth(), $w->pageHeight()));
        // Überdeckung und Rand werden gefunden
        $w2 = new PdfWriter();
        $w2->addPage();
        $w2->text(48, 100, 'eins', 10);
        $w2->text(50, 102, 'zwei', 10);
        $w2->text(560, 300, 'zu weit rechts', 10);
        self::assertCount(2, PdfInspector::boxIssues($w2->boxes(), $w2->pageWidth(), $w2->pageHeight()));
    }

    public function testTypographicCharactersSurvive(): void
    {
        $back = fn(string $s) => iconv('CP1252', 'UTF-8', PdfWriter::toCp1252($s));
        self::assertSame('„Gas“ – 12 € …', $back('„Gas“ – 12 € …'), 'CP1252 kennt diese Zeichen');
        self::assertSame('ca. ~ 5', $back('ca. ≈ 5'));
    }
}
