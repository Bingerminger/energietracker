<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BenchmarkService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\PdfReportService;
use Energietracker\Services\RecommendationService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.2.2 — PDF-Jahresbericht.
 *
 * Der Dienst hatte bis dahin keinen Test — obwohl in seiner Jahresaggregation
 * die Subzähler-Doppelzählung saß, die v2.1.3 beheben musste. Ein PDF lässt
 * sich schlecht auf Aussehen prüfen; hier geht es um das, was schiefgehen kann
 * und niemandem auffällt: dass der Bericht überhaupt entsteht, dass er ein
 * gültiges Dokument ist, dass er in jeder Sprache funktioniert und dass die
 * Summen die Subzähler nicht doppelt zählen.
 */
#[CoversClass(PdfReportService::class)]
final class PdfReportServiceTest extends ServiceTestCase
{
    private function service(): PdfReportService
    {
        $benchmark = new BenchmarkService(
            $this->consumption, $this->meters, $this->settings, $this->i18n
        );
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recommendations = new RecommendationService(
            $this->store, $this->meters, $this->consumption, $this->settings,
            $benchmark, $deliveries, $this->i18n
        );
        return new PdfReportService(
            $this->meters, $this->consumption, $this->settings,
            $benchmark, $recommendations, $this->i18n
        );
    }

    /** Zwölf Monate à 100 kWh auf einem Zähler. */
    private function seedYear(string $utility, string $meterId, string $deviceId, float $step = 100.0): void
    {
        $rows = [];
        for ($i = 0; $i <= 12; $i++) {
            $d = (new \DateTimeImmutable('2024-01-01'))->modify("+$i months");
            $rows[] = [
                'date' => $d->format('Y-m-d'),
                'counter' => 1000.0 + $i * $step,
                'device_id' => $deviceId,
            ];
        }
        $this->setReadings($utility, $meterId, $rows);
    }

    public function testReportIsAValidPdfDocument(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2023-12-01',
            'initial_counter' => 0.0, 'removed_on' => null,
            'final_counter' => null, 'reason' => null,
        ]]);
        $this->seedYear('strom', $meterId, 'd1');

        $pdf = $this->service()->build(2024);

        self::assertStringStartsWith('%PDF-', $pdf, 'Fehlender PDF-Header');
        self::assertStringContainsString('%%EOF', $pdf, 'Fehlende Endmarke');
        self::assertGreaterThan(2000, strlen($pdf), 'Das Dokument ist verdächtig klein');
        self::assertStringContainsString('/Type /Page', $pdf, 'Keine Seite im Dokument');
    }

    /** Ein leerer Bestand darf keinen Fehler werfen, sondern einen leeren Bericht liefern. */
    public function testReportSurvivesAYearWithoutAnyData(): void
    {
        $pdf = $this->service()->build(1999);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('%%EOF', $pdf);
    }

    /**
     * Liest die sichtbaren Textfragmente aus dem Dokument. Der PdfWriter
     * schreibt unkomprimiert, sodass sich die gedruckten Zahlen direkt prüfen
     * lassen — verlässlicher als ein Vergleich der Dateigröße.
     *
     * @return string[]
     */
    private function pdfTexts(string $pdf): array
    {
        preg_match_all('/\((.*?)\) Tj/s', $pdf, $m);
        return array_values(array_filter($m[1], fn($t) => trim($t) !== ''));
    }

    /**
     * v2.1.3 — F1006: Der Elternzähler trägt den Brutto-Verbrauch bereits
     * inklusive seiner Subzähler. Wer beide addiert, zählt doppelt. Genau das
     * tat `yearAggregate()` bis v2.1.3 — der Bericht wies zu hohe Summen aus,
     * ohne dass irgendetwas fehlschlug. `MeterTopologyTest` deckt dieselbe
     * Regel für Utility-Summe und Effizienzklasse ab; hier wird der
     * Bericht-Pfad selbst geprüft.
     */
    public function testSubMetersAreNotCountedTwiceInTheYearlyTotals(): void
    {
        // Elternzähler: 12 × 100 kWh ⇒ 1.200 kWh im Jahr.
        $parentId = $this->setMeterDevices('strom', [[
            'id' => 'd_parent', 'serial' => null, 'installed_on' => '2023-12-01',
            'initial_counter' => 0.0, 'removed_on' => null,
            'final_counter' => null, 'reason' => null,
        ]]);
        $this->seedYear('strom', $parentId, 'd_parent');

        self::assertContains('1.200 kWh', $this->pdfTexts($this->service()->build(2024)),
            'Ausgangslage: Der Bericht weist 1.200 kWh aus');

        // Subzähler am selben Eltern — sein Verbrauch steckt im Brutto bereits
        // drin und darf die Jahressumme nicht erhöhen.
        $all = $this->store->read('strom/meters.json', []);
        $all[] = [
            'id' => 'm_strom_sub', 'name' => 'Wärmepumpe', 'icon' => '',
            'created_at' => '2024-01-01', 'active' => true, 'notes' => '',
            'parent_meter_id' => $parentId, 'meter_group_id' => null,
            'devices' => [[
                'id' => 'd_sub', 'serial' => null, 'installed_on' => '2023-12-01',
                'initial_counter' => 0.0, 'removed_on' => null,
                'final_counter' => null, 'reason' => null,
            ]],
        ];
        $this->store->write('strom/meters.json', $all);

        $subRows = [];
        for ($i = 0; $i <= 12; $i++) {
            $d = (new \DateTimeImmutable('2024-01-01'))->modify("+$i months");
            $subRows[] = [
                'id' => sprintf('rs_%03d', $i),
                'meter_id' => 'm_strom_sub', 'device_id' => 'd_sub',
                'date' => $d->format('Y-m-d'),
                'counter' => 500.0 + $i * 40.0,   // 12 × 40 = 480 kWh
                'price_cents' => null, 'note' => '',
                'is_estimated' => false, 'is_future' => false,
            ];
        }
        $this->store->write('strom/readings.json',
            array_merge($this->store->read('strom/readings.json', []), $subRows));

        $texts = $this->pdfTexts($this->service()->build(2024));
        self::assertContains('1.200 kWh', $texts,
            'Die Jahressumme muss unverändert 1.200 kWh betragen');
        self::assertNotContains('1.680 kWh', $texts,
            'Doppelzählung: Eltern + Subzähler (1.200 + 480) dürfen nicht summiert werden');
    }

    /**
     * Der Bericht muss in jeder Sprache entstehen. Fehlt ein Katalogschlüssel,
     * druckt der Dienst den rohen Schlüssel ins PDF — sichtbar, aber ohne
     * Fehlermeldung.
     */
    public function testReportBuildsInEveryLanguageWithoutRawKeys(): void
    {
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2023-12-01',
            'initial_counter' => 0.0, 'removed_on' => null,
            'final_counter' => null, 'reason' => null,
        ]]);
        $this->seedYear('gas', $meterId, 'd1');

        foreach ($this->i18n->supported() as $lang) {
            $this->settings->set(['language' => $lang]);
            $this->i18n->setLocale($lang);

            $pdf = $this->service()->build(2024);
            self::assertStringStartsWith('%PDF-', $pdf, "Sprache $lang: kein gültiges PDF");
            self::assertStringNotContainsString('report.title', $pdf,
                "Sprache $lang: unaufgelöster Katalogschlüssel im Dokument");
            self::assertStringNotContainsString('utilityNames.', $pdf,
                "Sprache $lang: unaufgelöster Verbrauchsart-Schlüssel im Dokument");
        }
    }

    /** Abgeschaltete Verbrauchsarten gehören nicht in den Bericht. */
    public function testInactiveUtilitiesAreLeftOut(): void
    {
        // Zwei Verbrauchsarten mit Daten, damit es überhaupt etwas wegzulassen gibt.
        $gasId = $this->setMeterDevices('gas', [[
            'id' => 'dg', 'serial' => null, 'installed_on' => '2023-12-01',
            'initial_counter' => 0.0, 'removed_on' => null,
            'final_counter' => null, 'reason' => null,
        ]]);
        $this->seedYear('gas', $gasId, 'dg');

        $stromId = $this->setMeterDevices('strom', [[
            'id' => 'ds', 'serial' => null, 'installed_on' => '2023-12-01',
            'initial_counter' => 0.0, 'removed_on' => null,
            'final_counter' => null, 'reason' => null,
        ]]);
        $this->seedYear('strom', $stromId, 'ds', 250.0);   // 12 × 250 = 3.000 kWh

        $this->settings->set(['active_utilities' => ['gas', 'strom']]);
        $both = $this->pdfTexts($this->service()->build(2024));
        self::assertContains('3.000 kWh', $both, 'Strom muss enthalten sein, solange es aktiv ist');

        $this->settings->set(['active_utilities' => ['gas']]);
        $onlyGas = $this->pdfTexts($this->service()->build(2024));
        self::assertNotContains('3.000 kWh', $onlyGas,
            'Eine abgeschaltete Verbrauchsart darf nicht mehr im Bericht stehen');
    }
}
