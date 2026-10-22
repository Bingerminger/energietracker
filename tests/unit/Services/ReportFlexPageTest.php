<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\EvccService;
use Energietracker\Services\FlexibilityInsightService;
use Energietracker\Services\FlexibilityService;
use Energietracker\Services\IntervalService;
use Energietracker\Services\PdfReportService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\ReportModelService;
use Energietracker\Tests\Support\PdfInspector;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.6.0 — Jahresbericht: Seite „Flexibilität“ als Option (`flex`), dieselben
 * Zahlen wie die Seite in der App, im PDF in jeder Sprache ohne Befund.
 */
#[CoversClass(ReportModelService::class)]
#[CoversClass(PdfReportService::class)]
final class ReportFlexPageTest extends ServiceTestCase
{
    use ReportFixture;

    private const YEAR = 2025;
    private const TODAY = '2026-01-15';

    protected function tearDown(): void
    {
        parent::tearDown();
        gc_collect_cycles();
    }

    private function insights(): array
    {
        $iv = new IntervalService($this->store, $this->settings);
        $flex = new FlexibilityService($iv, $this->meters, $this->contracts);
        $ins = new FlexibilityInsightService($iv, $this->meters, $this->contracts, $flex);
        $ins->attachSessions(new EvccService($this->store, $this->meters, $this->readings,
            new ReadingImportService($this->readings, $this->meters, $this->i18n), $this->settings));
        return $ins->insights(self::YEAR, $flex->analyze(self::YEAR), self::TODAY);
    }

    /**
     * v3.6.0 (Paket flexplus) — die Seite sagt „inkl. USt“ (Fuß der Karte,
     * Summe, Hinweis mit dem Satz) und nennt die Grundlage der Ladefenster
     * des Autos (Steckzeiten aus evcc), in PDF und Druckansicht dieselben Sätze.
     */
    public function testThePageSaysVatAndTheBasisOfTheCarsWindows(): void
    {
        $this->importPersona('eigenheim-modern');
        $this->i18n->setLocale('de');
        $s = $this->reportServices();
        $f = $s['model']->build(self::YEAR, ['today' => self::TODAY, 'flex' => true])['flex_page'];
        $x = $this->insights();
        self::assertStringContainsString('inkl. USt', $f['footer']);
        self::assertStringContainsString('inkl. USt', $f['levers']['total']);
        $notes = implode(' ', $f['notes']);
        self::assertMatchesRegularExpression('/19\h*%\h*Umsatzsteuer/u', $notes);
        self::assertStringContainsString($this->i18n->tp('flex.lever.evSessions', $x['ev_availability']['sessions'],
            ['count' => $this->i18n->number((float)$x['ev_availability']['sessions'], 0)]), $notes);
        self::assertStringNotContainsString('ganzen Tag bereit', $notes, 'alle Ladung in einem Ladefenster');
        $ev = array_values(array_filter($f['actions'], fn($a) => str_starts_with($a['text'], 'Lade das Auto')));
        self::assertNotSame([], $ev);
        self::assertStringContainsString('Steckzeiten aus evcc', $ev[0]['tag']);
        // „Größter Hebel“ der Karte: der Satz mit dem Vertrag, wie die große Zahl
        self::assertSame($this->i18n->money(round($x['summary']['action']['eur']), 0), $f['facts'][0]['value']);
        self::assertTrue($x['summary']['action']['real']);
        $pdf = $s['pdf']->render($s['model']->build(self::YEAR, ['today' => self::TODAY, 'flex' => true]));
        // im PDF bricht der Text um: einzelne Wörter
        $all = implode(' ', PdfInspector::texts($pdf));
        self::assertStringContainsString('Steckzeiten', $all);
        self::assertStringContainsString('USt', $all);
    }

    /**
     * Nachtrag (Maintainer, 2026-10-20) — was netto bleibt, sagt es: ct-Werte
     * von Ampel und Zeitfenstern und die Nachrechnung (Thema „Flexibilität“,
     * Speicherwert) als „Börsenanteil netto, ohne USt“, neben den Hebeln inkl. USt.
     */
    public function testNetValuesSayNetWithoutVat(): void
    {
        $this->importPersona('eigenheim-modern');
        $this->i18n->setLocale('de');
        $s = $this->reportServices();
        $m = $s['model']->build(self::YEAR, ['today' => self::TODAY, 'flex' => true]);
        $f = $m['flex_page'];
        self::assertContains($this->i18n->t('flex.netNote'), $f['notes'], 'Hinweis zu den ct-Werten');
        self::assertStringContainsString('netto, ohne USt', $f['band_lead']);
        self::assertStringContainsString('netto, ohne USt', (string)$f['band_lead_pdf']);
        self::assertStringContainsString('(netto)', $f['windows']['head'][1], 'Spalte Ø Börsenpreis');
        $topic = $m['topics']['flexibility'];
        self::assertStringContainsString('netto, ohne USt', (string)$topic['battery_text'], 'Speicherwert der Nachrechnung');
        self::assertStringContainsString('netto ohne USt', $this->i18n->t('report.topic.flex.note'));
        $all = implode(' ', PdfInspector::texts($s['pdf']->render($m)));
        self::assertStringContainsString('netto', $all);
    }

    public function testThePageComesWithTheOptionAndCarriesTheServerNumbers(): void
    {
        $this->importPersona('eigenheim-modern');
        $this->i18n->setLocale('de');
        $s = $this->reportServices();
        $plain = $s['model']->build(self::YEAR, ['today' => self::TODAY]);
        self::assertArrayNotHasKey('flex_page', $plain, 'ohne Option und ohne Anhang keine Seite');
        self::assertFalse($plain['options']['flex']);

        $m = $s['model']->build(self::YEAR, ['today' => self::TODAY, 'flex' => true]);
        self::assertTrue($m['options']['flex']);
        $f = $m['flex_page'];
        self::assertNotNull($f);
        $x = $this->insights();
        self::assertSame($this->i18n->money(round($x['total_real_eur']), 0), $f['big'], 'große Zahl: mit dem Vertrag, wie er ist');
        self::assertSame($this->i18n->money(round($x['summary']['eur']), 0), $f['big'], 'dieselbe Zahl wie die Kachel der Übersicht');
        self::assertCount(count($x['levers']), $f['levers']['rows']);
        self::assertCount(count(array_filter($x['actions'], fn($a) => $a['window'] !== null)), $f['actions']);
        self::assertSame(['winter', 'summer'], array_column($f['bands'], 'season'));
        self::assertCount(24, $f['bands'][0]['hours']);
        self::assertStringContainsString('Lade das Auto', $f['actions'][0]['text']);
        self::assertStringNotContainsString('Wärmepumpe (Wärmepumpe)', json_encode($f, JSON_UNESCAPED_UNICODE), 'Zählername nur, wenn er etwas sagt');
        self::assertStringNotContainsString('☀', (string)$f['band_lead_pdf'], 'PDF: CP1252');
        // Druckansicht holt den Bericht mit Anhang — die Seite liegt bei, gezeigt nur mit flex=1
        $withDetail = $s['model']->build(self::YEAR, ['today' => self::TODAY, 'detail' => true]);
        self::assertNotNull($withDetail['flex_page'] ?? null);
        self::assertFalse($withDetail['options']['flex']);

        $pdf = $s['pdf']->render($m);
        self::assertContains('Flexibilität', array_column($s['pdf']->layoutLog()['bookmarks'], 'title'));
        self::assertSame([], PdfInspector::structureIssues($pdf));
        $s['pdf']->render($withDetail);
        self::assertNotContains('Flexibilität', array_column($s['pdf']->layoutLog()['bookmarks'], 'title'), 'PDF nur mit der Option');
    }

    /**
     * v3.7.0 (Paket nachzuege) — eigene Werte statt Beispielwerte: der Satz mit
     * den Annahmen nennt je Teil, woher der Wert kommt, die Hebel-Zeile der
     * Waschmaschine sagt „eigener Wert“ statt „Beispielwert“.
     */
    public function testOwnValuesReplaceTheSampleValuesInTheText(): void
    {
        $this->importPersona('eigenheim-modern');
        $this->i18n->setLocale('de');
        $before = $this->reportServices()['model']->build(self::YEAR, ['today' => self::TODAY, 'flex' => true])['flex_page'];
        self::assertStringNotContainsString('eigener Wert', json_encode($before, JSON_UNESCAPED_UNICODE), 'ohne Einstellung nur Beispielwerte');
        self::assertMatchesRegularExpression('/Wärmepumpe 30\h*% des Tagesverbrauchs verschiebbar \(Beispielwert\)/u', $before['notes'][0]);

        $this->settings->set(['flex_heat_pump_share_pct' => 40, 'flex_washer_cycles_per_week' => 3]);
        $f = $this->reportServices()['model']->build(self::YEAR, ['today' => self::TODAY, 'flex' => true])['flex_page'];
        $lead = $f['notes'][0];
        self::assertMatchesRegularExpression('/Wärmepumpe 40\h*% des Tagesverbrauchs verschiebbar \(eigener Wert\)/u', $lead);
        self::assertStringContainsString('Waschmaschine 0,9 kWh je Gang, 3 Gänge je Woche (eigener Wert)', $lead);
        self::assertStringContainsString('Spülmaschine 1,0 kWh je Gang, 5 Gänge je Woche (Beispielwert)', $lead, 'was nicht gesetzt ist, bleibt Beispielwert');
        $washer = array_values(array_filter($f['levers']['rows'], fn($r) => str_contains($r['label'], 'Wasch')));
        self::assertNotSame([], $washer);
        self::assertStringContainsString('(eigener Wert)', $washer[0]['label']);
        self::assertStringNotContainsString('Beispielwert', $washer[0]['label']);
    }

    /** Jede Sprache: Struktur, Box-Protokoll, Fließtext ab 10 pt, keine Rohschlüssel. */
    public function testThePdfPageInEveryLanguage(): void
    {
        $this->importPersona('eigenheim-modern');
        foreach ($this->i18n->supported() as $lang) {
            $this->i18n->setLocale($lang);
            $s = $this->reportServices();
            $pdf = $s['pdf']->render($s['model']->build(self::YEAR, ['today' => self::TODAY, 'flex' => true]));
            $log = $s['pdf']->layoutLog();
            self::assertSame([], PdfInspector::structureIssues($pdf), "$lang: Struktur");
            self::assertSame([], PdfInspector::boxIssues($log['boxes'], $log['width'], $log['height']), "$lang: Box-Protokoll");
            foreach ($log['boxes'] as $b) {
                if ($b['role'] === 'para') self::assertGreaterThanOrEqual(10.0, $b['size'], "$lang: Fließtext „{$b['text']}“");
            }
            $all = implode(' ', PdfInspector::texts($pdf));
            self::assertDoesNotMatchRegularExpression('/\b(flex|report)\.[a-zA-Z]/', $all, "$lang: Rohschlüssel");
            self::assertContains($this->i18n->t('report.section.flexPage'), array_column($log['bookmarks'], 'title'), "$lang: Lesezeichen");
        }
    }

    /** Ohne Viertelstunden und Preise entfällt die Seite, auch mit Option. */
    public function testWithoutDataThereIsNoPage(): void
    {
        $this->i18n->setLocale('de');
        $s = $this->reportServices();
        $m = $s['model']->build(self::YEAR, ['today' => self::TODAY, 'flex' => true]);
        self::assertNull($m['flex_page']);
        $s['pdf']->render($m);
        self::assertNotContains('Flexibilität', array_column($s['pdf']->layoutLog()['bookmarks'], 'title'));
    }
}
