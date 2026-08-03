<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\{BenchmarkService, CsvExportService, DeliveryService,
    DiagnosticsService, ForecastService, RecommendationService, ReminderService,
    TemperatureService, WeatherService};
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.2.0 — Grundabdeckung für Dienste, die bis dahin überhaupt keinen Test
 * hatten (10 von 29).
 *
 * Das sind bewusst keine tiefen Fachtests, sondern die Zusicherungen, deren
 * Fehlen im Review am meisten gekostet hätte: Lässt sich der Dienst mit der
 * echten Verdrahtung bauen? Verträgt er einen leeren Datenbestand, ohne zu
 * werfen? Hält er sein dokumentiertes Rückgabeformat ein? Genau diese Fragen
 * blieben offen, während in ForecastService ein stiller Rechenfehler saß.
 */
#[CoversClass(DeliveryService::class)]
#[CoversClass(TemperatureService::class)]
#[CoversClass(RecommendationService::class)]
#[CoversClass(CsvExportService::class)]
#[CoversClass(DiagnosticsService::class)]
#[CoversClass(BenchmarkService::class)]
final class UntestedServicesSmokeTest extends ServiceTestCase
{
    private function deliveries(): DeliveryService
    {
        return new DeliveryService($this->store, $this->meters, $this->i18n);
    }

    private function temperatures(): TemperatureService
    {
        return new TemperatureService($this->store, $this->settings, new WeatherService());
    }

    private function benchmark(): BenchmarkService
    {
        return new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
    }

    private function recommendations(): RecommendationService
    {
        return new RecommendationService(
            $this->store, $this->meters, $this->consumption, $this->settings,
            $this->benchmark(), $this->deliveries(), $this->i18n
        );
    }

    // ── Leerer Bestand darf nirgends werfen ──────────────────────────────

    public function testAllServicesSurviveAnEmptyDataDirectory(): void
    {
        self::assertSame([], $this->deliveries()->list('heizoel'));
        self::assertSame([], $this->temperatures()->all());
        self::assertIsArray($this->recommendations()->all());
        self::assertIsArray($this->benchmark()->efficiency());
        self::assertIsArray((new DiagnosticsService($this->store, $this->settings))->run());
    }

    // ── DeliveryService ──────────────────────────────────────────────────

    public function testDeliveryRoundTripAndTotalDerivation(): void
    {
        $svc = $this->deliveries();
        $meterId = $this->meters->list('heizoel')[0]['id'] ?? null;
        if ($meterId === null) {
            $meterId = $this->meters->create('heizoel', [
                'name' => 'Tank', 'capacity' => 3000.0, 'initial_stock' => 1000.0,
            ])['id'];
        }

        $d = $svc->create('heizoel', [
            'meter_id' => $meterId, 'date' => '2024-03-01',
            'quantity' => 1500.0, 'unit_price_cents' => 95.0, 'supplier' => 'Testöl',
        ]);
        self::assertNotEmpty($d['id']);
        self::assertEqualsWithDelta(1500.0, (float)$d['quantity'], 0.01);
        // Der Gesamtbetrag wird bewusst NICHT redundant gespeichert, sondern
        // dort abgeleitet, wo er gebraucht wird (Anzeige, CSV-Export).
        self::assertArrayNotHasKey('total_eur', $d);

        self::assertCount(1, $svc->list('heizoel', $meterId));
        $svc->delete('heizoel', $d['id']);
        self::assertSame([], $svc->list('heizoel', $meterId));
    }

    public function testDeliveryRejectsUnknownMeter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->deliveries()->create('heizoel', [
            'meter_id' => 'gibtsnicht', 'date' => '2024-03-01', 'quantity' => 100.0,
        ]);
    }

    // ── TemperatureService ───────────────────────────────────────────────

    public function testTemperatureCsvImportAcceptsBothDateFormats(): void
    {
        $svc = $this->temperatures();
        // Das Beispielformat der Anwendung trennt mit Anführungszeichen;
        // Semikolon und Komma sind als Rückfall erlaubt.
        $res = $svc->importCsv("15.01.2024\"4.2\"-1.0\"7.1\n2024-01-16;3.8;-2.0;6.5\n");
        self::assertSame(2, $res['imported']);
        self::assertSame(0, $res['skipped']);

        $all = $svc->all();
        self::assertArrayHasKey('2024-01-15', $all);
        self::assertArrayHasKey('2024-01-16', $all);
        self::assertEqualsWithDelta(-1.0, $all['2024-01-15']['min'], 0.001);
        self::assertEqualsWithDelta(7.1, $all['2024-01-15']['max'], 0.001);
    }

    public function testTemperatureCsvSkipsUnparsableRowsInsteadOfThrowing(): void
    {
        $res = $this->temperatures()->importCsv("Datum\"Mittel\"Min\"Max\nkaputt\n\n31.02.9999\"x\"y\"z\n");
        self::assertSame(0, $res['imported']);
        self::assertGreaterThan(0, $res['skipped']);
    }

    // ── CsvExportService ─────────────────────────────────────────────────

    public function testCsvExportProducesAHeaderRowForEveryUtility(): void
    {
        $svc = new CsvExportService(
            $this->consumption, $this->readings, $this->meters,
            $this->temperatures(), $this->deliveries(), $this->i18n
        );
        foreach (['gas', 'strom', 'wasser'] as $utility) {
            $csv = $svc->monthly($utility);
            self::assertNotSame('', trim($csv), "$utility: Export ist leer");
            self::assertStringContainsString(';', explode("\n", $csv)[0],
                "$utility: Kopfzeile fehlt oder nutzt ein anderes Trennzeichen");
        }
    }

    /**
     * Der Gesamtbetrag einer Lieferung wird im Export aus Menge × Stückpreis
     * abgeleitet, wenn er nicht mitgegeben wurde — sonst stünde die Spalte bei
     * jeder über die API angelegten Lieferung leer.
     */
    public function testDeliveryCsvDerivesTheTotalFromQuantityAndUnitPrice(): void
    {
        $meterId = $this->meters->list('heizoel')[0]['id'] ?? $this->meters->create('heizoel', [
            'name' => 'Tank', 'capacity' => 3000.0, 'initial_stock' => 1000.0,
        ])['id'];
        $this->deliveries()->create('heizoel', [
            'meter_id' => $meterId, 'date' => '2024-03-01',
            'quantity' => 1500.0, 'unit_price_cents' => 95.0,
        ]);

        $svc = new CsvExportService(
            $this->consumption, $this->readings, $this->meters,
            $this->temperatures(), $this->deliveries(), $this->i18n
        );
        $csv = $svc->deliveries('heizoel');
        // 1500 L × 95 ct = 1425 € — der Export lässt überflüssige Nachkommastellen weg.
        self::assertMatchesRegularExpression('/;1[.,]?425(?:[.,]0+)?;/', $csv,
            "Abgeleiteter Gesamtbetrag fehlt im Export:\n$csv");
    }

    // ── DiagnosticsService ───────────────────────────────────────────────

    public function testDiagnosticsReportsTheDocumentedFields(): void
    {
        $d = (new DiagnosticsService($this->store, $this->settings))->run();
        foreach (['app_version', 'schema_version', 'php_version', 'data_dir',
                  'data_dir_writable', 'utilities'] as $key) {
            self::assertArrayHasKey($key, $d, "Diagnose-Feld $key fehlt");
        }
        self::assertTrue($d['data_dir_writable'], 'Testverzeichnis muss beschreibbar sein');
        self::assertIsArray($d['utilities']);
    }

    // ── ReminderService ──────────────────────────────────────────────────

    public function testReminderValidationRejectsMissingFields(): void
    {
        $svc = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->expectException(\InvalidArgumentException::class);
        $svc->create(['next_due' => '2024-06-01']);   // ohne Titel
    }

    public function testReminderRejectsMalformedDueDate(): void
    {
        $svc = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->expectException(\InvalidArgumentException::class);
        $svc->create(['title' => 'Wartung', 'next_due' => '01.06.2024']);
    }

    // ── ForecastService ──────────────────────────────────────────────────

    public function testForecastReportsTooFewMonthsInsteadOfGuessing(): void
    {
        $forecast = new ForecastService(
            $this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n
        );
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-01-01', 'counter' => 0.0,   'device_id' => 'd1'],
            ['date' => '2024-02-01', 'counter' => 100.0, 'device_id' => 'd1'],
        ]);

        $res = $forecast->forMeter('strom', $this->meters->get('strom', $meterId));
        self::assertFalse($res['valid']);
        self::assertNotEmpty($res['reason']);
        // Der Grund ist lokalisiert, kein durchgereichter Key.
        self::assertStringNotContainsString('errors.', $res['reason']);
    }

    // ── RecommendationService ────────────────────────────────────────────

    public function testRecommendationsHaveTheDocumentedShape(): void
    {
        foreach ($this->recommendations()->all() as $r) {
            foreach (['id', 'severity', 'category', 'title', 'detail'] as $key) {
                self::assertArrayHasKey($key, $r, "Empfehlungsfeld $key fehlt");
            }
            self::assertContains($r['severity'], ['urgent', 'warning', 'info'],
                'Unbekannte Dringlichkeit: ' . $r['severity']);
        }
        self::assertTrue(true, 'Leere Empfehlungsliste ist ein gültiger Zustand');
    }
}
