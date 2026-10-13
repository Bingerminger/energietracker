<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Utilities;
use Energietracker\Services\MeterService;
use Energietracker\Services\Pdf\Chart;
use Energietracker\Services\ReportAdviceService;
use Energietracker\Tests\Support\PdfInspector;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.4.0 (F1025) — „Jahresbericht für alle“ an den vier Beispielhaushalten.
 *
 * Das Modell (ReportModelService) je Persona: Erscheinen die Sektionen, die zu
 * diesem Haushalt gehören — und nur diese? Stimmen die Summen ohne doppelt
 * gezählte Subzähler? Dazu das PDF in allen sieben Sprachen: gültige Struktur,
 * Seitenzahl im erwarteten Rahmen, keine Rohschlüssel, nichts über dem Rand,
 * keine Texte übereinander, Fließtext ab 10 pt, Lesezeichen, Sprache, Titel.
 */
final class ReportModelServiceTest extends ServiceTestCase
{
    use ReportFixture;

    private const YEAR = 2025;

    /** Seitenzahl ohne Anhang je Persona (Bericht §3: 7–13 Seiten). */
    private const PAGES = [
        'mieterin'            => [7, 11],
        'etw-fernwaerme'      => [6, 10],
        'eigenheim-klassisch' => [8, 12],
        'eigenheim-modern'    => [10, 14],
    ];

    private function model(string $persona, array $opts = []): array
    {
        $this->importPersona($persona);
        return $this->reportServices()['model']->build(self::YEAR, $opts);
    }

    /** Jahressumme der Zähler, die in Summen zählen — unabhängig vom Modell gerechnet. */
    private function rootSum(string $u, string $field): float
    {
        $sum = 0.0;
        foreach ($this->meters->list($u) as $m) {
            if (!MeterService::countsInTotals($m)) continue;
            foreach ($this->consumption->forMeter($u, $m) as $r) if ((int)$r['year'] === self::YEAR) $sum += (float)($r[$field] ?? 0);
        }
        return $sum;
    }

    public function testModernShowsPvBatteryHeatPumpWallboxAndFlexibility(): void
    {
        $m = $this->model('eigenheim-modern');
        self::assertSame([], $m['errors']);
        foreach (['pv', 'heat_pump', 'ev', 'flexibility'] as $topic) self::assertNotNull($m['topics'][$topic], $topic);
        self::assertNull($m['topics']['tenancy']);
        self::assertNotNull($m['topics']['pv']['battery'], 'Speicher');
        self::assertGreaterThan(0, $m['topics']['pv']['flow']['discharged']);
        self::assertTrue($m['topics']['ev']['meters'][0]['has_solar'], 'Sonnenanteil der Wallbox');
        self::assertGreaterThan(0, $m['topics']['flexibility']['module3_eur'], 'Modul 3');
        // Strom nach Rollen: Haushalt (mit Sonnenstrom) − Wallbox ≥ 0, Wallbox als Teil davon
        $strom = array_values(array_filter($m['pages'], fn($p) => $p['utility'] === 'strom'))[0];
        $roles = array_column($strom['roles']['rows'], 'kwh', 'role');
        self::assertSame(['household', 'heat_pump', 'ev_charger'], array_keys($roles));
        self::assertGreaterThan(0, $roles['household']);
        $bezug = 0.0;
        foreach ($this->meters->list('strom') as $meter) {
            if (Utilities::roleOf('strom', $meter) !== 'household') continue;
            foreach ($this->consumption->forMeter('strom', $meter) as $r) if ((int)$r['year'] === self::YEAR) $bezug += (float)$r['kwh'];
        }
        self::assertEqualsWithDelta($bezug + (float)$m['topics']['pv']['year_row']['eigenverbrauch_kwh'] - $roles['ev_charger'], $roles['household'], 1.0,
            'Haushalt = Bezug + selbst genutzter Sonnenstrom − Wallbox');
        // Wärmemenge der Wärmepumpe: keine eigene Seite (sie zählt nicht in Summen)
        self::assertNotContains('waerme', array_column($m['pages'], 'utility'));
        self::assertContains('pvBenefit', array_column($m['successes'], 'rule'));
        self::assertContains('evSolar', array_column($m['successes'], 'rule'));
    }

    public function testMieterinShowsTenancyAndExplainsCostsInsteadOfZero(): void
    {
        $m = $this->model('mieterin');
        self::assertNotNull($m['topics']['tenancy']);
        self::assertNotNull($m['topics']['tenancy']['co2'], 'CO₂-Aufteilung');
        foreach (['pv', 'heat_pump', 'ev'] as $topic) self::assertNull($m['topics'][$topic], $topic);
        $pages = array_column($m['pages'], null, 'utility');
        // Mangel 4 — Wasser und Heizwärme laufen über die Nebenkosten
        foreach (['wasser', 'waerme'] as $u) {
            self::assertSame('tenancy', $pages[$u]['cost']['basis'], $u);
            self::assertGreaterThan(0, $pages[$u]['cost']['value'], "$u: Betrag nach den Preisen des Mietverhältnisses");
        }
        // Mangel 7 — kein „alles im grünen Bereich“, solange die Agenda etwas hat
        self::assertNotEmpty($m['agenda']['events']);
        self::assertNotEmpty($m['tasks']);
    }

    public function testKlassischShowsTheBaselineSuccessWithEuro(): void
    {
        $m = $this->model('eigenheim-klassisch');
        $baseline = array_values(array_filter($m['successes'], fn($s) => $s['rule'] === 'baseline'));
        self::assertCount(1, $baseline, 'Zäsur-Erfolg');
        self::assertGreaterThan(0, $baseline[0]['euro']['value']);
        $wasser = array_values(array_filter($m['pages'], fn($p) => $p['utility'] === 'wasser'))[0];
        self::assertCount(1, $wasser['sub_meters'], 'Gartenzähler als „davon“, nicht mit 0 €');
        self::assertStringNotContainsString('0,00', $wasser['sub_meters'][0]['text']);
        self::assertNotNull($wasser['water'], 'Liter je Person und Tag');
        // Mangel 6 — keine Wechsel-Aufgabe für Wasser
        self::assertSame([], array_filter($m['tasks'], fn($t) => $t['rule'] === 'cancel' && $t['utility'] === 'wasser'));
        $gas = array_values(array_filter($m['pages'], fn($p) => $p['utility'] === 'gas'))[0];
        // Die Zäsur liegt im Vorjahr: Davor gibt es keine bereinigten Werte, also
        // keinen Wettervergleich — lieber keiner als ein vermischter (Lehre 33)
        self::assertNull($gas['weather']);
        self::assertNotNull($gas['efficiency']['class']);
    }

    public function testEtwShowsWeatherComparisonAndTheBill(): void
    {
        $m = $this->model('etw-fernwaerme');
        $fw = array_values(array_filter($m['pages'], fn($p) => $p['utility'] === 'fernwaerme'))[0];
        self::assertNotNull($fw['weather'], 'Kälter oder mehr verbraucht?');
        self::assertContains($fw['weather']['verdict'], ['less', 'more', 'same']);
        self::assertNotEmpty($fw['weather_rows'], 'Monatszeilen für das Schaubild der Druckansicht');
        self::assertTrue($m['headline']['tiles'][1]['key'] === 'consumption');
        self::assertContains($this->i18n->t('report.weatherSource'), $m['sources'], 'witterungsbereinigt → Quelle der Temperaturen');
        self::assertSame([], array_filter($m['tasks'], fn($t) => $t['rule'] === 'bill'), 'Versorgerrechnung passt');
    }

    /** Heizöl und Pellets (Haupt-Demo): Bestand heute und wie lange er reicht. */
    public function testTanksShowStockAndRange(): void
    {
        $m = $this->model('demo');
        $pages = array_column($m['pages'], null, 'utility');
        foreach (['heizoel', 'pellets'] as $u) {
            $tank = $pages[$u]['tank'] ?? null;
            self::assertNotNull($tank, $u);
            self::assertGreaterThan(0, $tank['stock']);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', (string)$tank['until'], "$u: Reichweite");
            self::assertGreaterThan(substr($m['today'], 0, 7), $tank['until']);
            self::assertTrue($tank['as_of_today']);
        }
    }

    /** Mangel 6 — auch die Empfehlungen der App raten bei Wasser nicht zum Wechsel. */
    public function testWaterRecommendationsDoNotSuggestSwitching(): void
    {
        $this->importPersona('eigenheim-klassisch');
        foreach ($this->reportServices()['recommendations']->all() as $r) {
            if (($r['evidence']['utility'] ?? null) !== 'wasser' || !str_starts_with((string)$r['id'], 'r6_')) continue;
            self::assertStringNotContainsString('Tarifvergleich', (string)$r['detail']);
            self::assertStringNotContainsString('wechseln', (string)$r['detail']);
        }
        self::assertFalse(Utilities::isSwitchable('wasser'));
        self::assertTrue(Utilities::isSwitchable('strom'));
        self::assertFalse(Utilities::isSwitchable('pv_erzeugung'), 'ohne Verträge nichts zu wechseln');
    }

    /** @return array<string,array{0:string}> */
    public static function personas(): array
    {
        return array_combine(array_keys(self::PAGES), array_map(fn($p) => [$p], array_keys(self::PAGES)));
    }

    /** Summen ohne Subzähler doppelt; Kosten gesamt = Summe der Arten. */
    #[DataProvider('personas')]
    public function testTotalsDoNotCountSubMetersTwice(string $persona): void
    {
        $m = $this->model($persona);
        foreach ($m['pages'] as $p) {
            $field = $p['unit'] === 'kWh' ? 'kwh' : 'm3';
            self::assertEqualsWithDelta($this->rootSum($p['utility'], $field), $p['value'], 0.05, "{$p['utility']}: Jahreswert");
        }
        if ($m['money'] !== null) {
            self::assertEqualsWithDelta(array_sum(array_column($m['money']['by_utility'], 'cost')), $m['money']['total']['cost'], 0.05);
            foreach ($m['money']['by_utility'] as $r) self::assertEqualsWithDelta($this->rootSum($r['utility'], 'cost'), $r['cost'], 0.05, $r['utility']);
            $t = $m['money']['total'];
            if ($t['prev'] !== null) {
                self::assertEqualsWithDelta($t['cost'] - $t['prev'], $t['price_effect'] + $t['volume_effect'], 0.05, 'Preis- + Mengeneffekt = Kostenänderung');
                // Mengeneffekt = (Menge jetzt − Menge Vorjahr) × Ø Preis des Vorjahres
                $volume = 0.0;
                foreach ($m['money']['by_utility'] as $r) {
                    if ($r['prev'] === null) continue;
                    $field = Utilities::get($r['utility'])['consumption_unit'] === 'kWh' ? 'kwh' : 'm3';
                    $prevValue = 0.0;
                    foreach ($this->meters->list($r['utility']) as $meter) {
                        if (!MeterService::countsInTotals($meter)) continue;
                        foreach ($this->consumption->forMeter($r['utility'], $meter) as $row) if ((int)$row['year'] === self::YEAR - 1) $prevValue += (float)($row[$field] ?? 0);
                    }
                    $volume += ($this->rootSum($r['utility'], $field) - $prevValue) * $r['prev'] / $prevValue;
                }
                self::assertEqualsWithDelta($volume, $t['volume_effect'], 0.05, 'Mengeneffekt zum Preis des Vorjahres');
            }
        }
    }

    /** Mangel 5 — Empfehlungen nur zum Berichtsjahr. */
    public function testAnomaliesOnlyFromTheReportYear(): void
    {
        $m = $this->model('eigenheim-modern');
        $anomalies = array_filter($m['tasks'], fn($t) => $t['rule'] === 'anomaly');
        self::assertNotEmpty($anomalies, 'die Persona hat einen auffälligen Dezember');
        foreach ($anomalies as $a) {
            self::assertStringContainsString((string)self::YEAR, $a['title']);
            self::assertDoesNotMatchRegularExpression('/20(23|24|26)/', $a['title']);
        }
    }

    /** Mangel 11 — Abschlagsvorschlag 0: kein „Abschlag 0 €“, sondern „kann sinken“. */
    public function testZeroAdvanceSuggestionIsNotShownAsAmount(): void
    {
        $m = $this->model('etw-fernwaerme');
        $fw = array_values(array_filter($m['money']['balances'] ?? [], fn($b) => $b['utility'] === 'fernwaerme'));
        if ($fw === [] || $fw[0]['projected_end_balance'] >= 0) self::markTestSkipped('Fernwärme ohne Guthaben am heutigen Tag');
        self::assertNull($fw[0]['suggested_advance']);
        self::assertSame($this->i18n->t('report.money.advanceLower'), $fw[0]['advance_text']);
    }

    public function testDetailAppendixShowsWeatherColumnsOnlyForHeating(): void
    {
        $m = $this->model('eigenheim-klassisch', ['detail' => true]);
        $tables = array_column($m['appendix'], null, 'utility');
        self::assertSame(7, count($tables['gas']['head']), 'Monat, Menge, Kosten, Temperatur, Gradtage, bereinigt, Abweichung');
        self::assertSame(3, count($tables['strom']['head']), 'Mangel 3: keine Temperatur bei Strom');
        self::assertSame(3, count($tables['wasser']['head']));
        foreach ($tables['gas']['rows'] as $row) self::assertNotSame('-0 %', str_replace("\u{00A0}", ' ', $row[6]), 'Mangel 2');
        self::assertArrayNotHasKey('appendix', $this->reportServices()['model']->build(self::YEAR), 'ohne detail kein Anhang');
    }

    public function testPartialReportOnlyContainsTheChosenUtilities(): void
    {
        $m = $this->model('eigenheim-klassisch', ['utilities' => ['wasser']]);
        self::assertSame(['wasser'], array_column($m['utilities'], 'utility'));
        self::assertSame(['wasser'], array_column($m['pages'], 'utility'));
        // Ereignisse anderer Arten fehlen; allgemeine Termine bleiben
        foreach ($m['agenda']['events'] as $e) self::assertFalse(str_starts_with($e['title'], 'Gas:') || str_starts_with($e['title'], 'Strom:'), $e['title']);
    }

    /** Jede Sektion einzeln abgesichert: Eine kaputte Datei kippt nicht den Bericht. */
    public function testABrokenSectionDoesNotBreakTheReport(): void
    {
        $this->importPersona('mieterin');
        file_put_contents($this->dataDir . '/tenancies.json', '{kaputt');
        $s = $this->reportServices();
        $m = $s['model']->build(self::YEAR);
        self::assertContains('tenancy', $m['errors']);
        self::assertNull($m['topics']['tenancy']);
        self::assertNotEmpty($m['pages'], 'die übrigen Sektionen stehen');
        $pdf = $s['pdf']->render($m);
        self::assertSame([], PdfInspector::structureIssues($pdf));
    }

    // ── PDF ──────────────────────────────────────────────────────────────

    /**
     * 7 Sprachen × 4 Personas: gültige Struktur, Seitenzahl, keine Rohschlüssel,
     * Box-Protokoll ohne Befund, Fließtext ab 10 pt, Lesezeichen, /Lang, Titel.
     */
    #[DataProvider('personas')]
    public function testPdfInEveryLanguage(string $persona): void
    {
        $this->importPersona($persona);
        [$min, $max] = self::PAGES[$persona];
        foreach ($this->i18n->supported() as $lang) {
            $this->i18n->setLocale($lang);
            $s = $this->reportServices();
            $pdf = $s['pdf']->build(self::YEAR);
            $log = $s['pdf']->layoutLog();
            self::assertSame([], PdfInspector::structureIssues($pdf), "$persona/$lang: Struktur");
            $pages = PdfInspector::pageCount($pdf);
            self::assertGreaterThanOrEqual($min, $pages, "$persona/$lang: Seiten");
            self::assertLessThanOrEqual($max, $pages, "$persona/$lang: Seiten");
            $all = implode(' ', PdfInspector::texts($pdf));
            self::assertDoesNotMatchRegularExpression('/\b(report|recommendations|utilityNames|explainer)\.[a-zA-Z]/', $all, "$persona/$lang: Rohschlüssel");
            self::assertDoesNotMatchRegularExpression('/(^|\s)0[,.]00\s?(€|£)|€\s?0[,.]00/u', $all, "$persona/$lang: Mangel 4 — keine 0,00");
            self::assertSame([], PdfInspector::boxIssues($log['boxes'], $log['width'], $log['height']), "$persona/$lang: Box-Protokoll");
            foreach ($log['boxes'] as $b) {
                if ($b['role'] === 'para') self::assertGreaterThanOrEqual(10.0, $b['size'], "$persona/$lang: Fließtext „{$b['text']}“");
            }
            self::assertGreaterThanOrEqual(6, count($log['bookmarks']), "$persona/$lang: Lesezeichen");
            self::assertStringContainsString("/Lang ($lang)", $pdf);
            self::assertStringContainsString('/DisplayDocTitle true', $pdf);
            self::assertStringContainsString('/Outlines', $pdf);
            self::assertMatchesRegularExpression('/\/Title <FEFF[0-9A-F]+>/', $pdf);
            self::assertStringContainsString($this->i18n->t('report.footer.page', ['page' => $pages, 'pages' => $pages]), $all, "$persona/$lang: Fußzeile");
        }
    }

    /** Grafik-Operatoren: Monatsbalken, Bögen des Energieflusses, Strichmuster, Beschneiden. */
    public function testChartsAreDrawnAsVectors(): void
    {
        $this->importPersona('eigenheim-modern');
        $this->i18n->setLocale('de');
        $pdf = $this->reportServices()['pdf']->build(self::YEAR);
        $streams = PdfInspector::pageStreams($pdf);
        $bars = max(array_map(fn($s) => substr_count($s, " re f\n"), $streams));
        self::assertGreaterThanOrEqual(24, $bars, 'eine Seite mit 12 × 2 Monatsbalken');
        $curves = max(array_map(fn($s) => substr_count($s, " c\n"), $streams));
        self::assertGreaterThanOrEqual(16, $curves, 'Energiefluss: Bögen und Kreise aus Bézier-Kurven');
        $all = implode("\n", $streams);
        self::assertStringContainsString('[4 3] 0 d', $all, 'Prognose bzw. Referenz gestrichelt');
        self::assertStringContainsString(" re W n\n", $all, 'Beschneiden (Saldo um null, Schraffur)');
        foreach ($streams as $i => $s) self::assertSame(substr_count($s, "q\n"), substr_count($s, "Q\n"), "Seite $i: q/Q paarweise");
        self::assertStringContainsString('/F3 ', $all, 'Symbole aus ZapfDingbats (Ampeln, Prioritäten)');
        // Seite 1: vier Ampeln mit Symbol plus je ein Prioritätssymbol der drei Aufgaben
        self::assertGreaterThanOrEqual(7, substr_count($streams[0], '/F3 '), 'Ampeln immer mit Symbol, nie nur Farbe');
    }

    /** Druckfarben: Farbton der Verbrauchsart, ≥ 3:1 auf Weiß; Ampeln und Vorjahr lesbar. */
    public function testPrintColorsHaveEnoughContrast(): void
    {
        foreach (Utilities::all() as $u) {
            $c = Chart::printColor((string)$u['color']);
            self::assertGreaterThanOrEqual(3.0, Chart::contrast($c, Chart::PAPER), "{$u['key']}: Druckfarbe");
            $o = Chart::hexToRgb((string)$u['color']);
            // nur abgedunkelt, nicht verfärbt: Kanäle im selben Verhältnis
            $k = max($o) > 0 ? max($c) / max($o) : 0;
            foreach ([0, 1, 2] as $i) self::assertEqualsWithDelta($o[$i] * $k, $c[$i], 2.0, "{$u['key']}: Farbton");
        }
        self::assertLessThan(3.0, Chart::contrast(Chart::hexToRgb('#22d3ee'), Chart::PAPER), 'Ausgangslage: Strom ist im Original zu hell');
        foreach (Chart::LEVELS as $name => $lv) {
            self::assertGreaterThanOrEqual(4.5, Chart::contrast($lv['rgb'], Chart::PAPER), "Ampel $name");
            self::assertArrayHasKey('symbol', $lv, 'Ampel immer mit Symbol');
        }
        self::assertGreaterThanOrEqual(3.0, Chart::contrast(Chart::PREV, Chart::PAPER), 'Vorjahr grau, aber erkennbar');
        self::assertGreaterThanOrEqual(4.5, Chart::contrast(Chart::MUTE, Chart::PAPER), 'Achsentexte');
    }

    public function testThresholdsLiveInConstants(): void
    {
        // Die Doku nennt diese Werte; ändert sich einer, muss sie mit (docs/anleitungen/jahresbericht-lesen.md)
        self::assertSame(['ok' => 0.0, 'warn' => 5.0], ReportAdviceService::LIGHT_CONSUMPTION);
        self::assertSame(['ok' => 3.0, 'warn' => 10.0], ReportAdviceService::LIGHT_COST);
        self::assertSame(['ok' => 1.0, 'warn' => 2.0], ReportAdviceService::LIGHT_BILLING);
        self::assertSame(['alert' => 14, 'warn' => 60], ReportAdviceService::LIGHT_DEADLINE_DAYS);
        $doc = (string)file_get_contents(dirname(__DIR__, 3) . '/docs/anleitungen/jahresbericht-lesen.md');
        foreach (['5 %', '3 %', '10 %', '14 Tage', '60 Tage'] as $needle) self::assertStringContainsString($needle, $doc);
    }
}
