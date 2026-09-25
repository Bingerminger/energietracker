<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BenchmarkService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\MeterService;
use Energietracker\Services\PdfReportService;
use Energietracker\Services\RecommendationService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.9.0 (Review CALC-15, CALC-24) — Regeln für Summen und Einstellungen.
 *
 * Ein Zähler außer Betrieb zählt mit seiner
 * Historie in jeder Summe. Bis v2.8 zählten Utility-Sicht und CSV ihn mit,
 * PDF-Jahresbericht und Effizienzklasse nicht.
 */
#[CoversClass(MeterService::class)]
#[CoversClass(BenchmarkService::class)]
#[CoversClass(PdfReportService::class)]
final class TotalsAndSettingsRulesTest extends ServiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]], 'wohnflaeche_m2' => 100]);
        $device = fn(string $id) => ['id' => $id, 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
                                     'removed_on' => null, 'final_counter' => null, 'reason' => null];
        $this->store->write('gas/meters.json', [
            ['id' => 'm_gas_main', 'name' => 'Haupt', 'icon' => '', 'created_at' => '2025-01-01', 'active' => true, 'notes' => '', 'devices' => [$device('d_main')]],
            ['id' => 'm_gas_old', 'name' => 'Alt', 'icon' => '', 'created_at' => '2025-01-01', 'active' => false, 'notes' => '', 'devices' => [$device('d_old')]],
        ]);
        $this->setReadings('gas', 'm_gas_main', [
            ['meter_id' => 'm_gas_main', 'device_id' => 'd_main', 'date' => '2025-01-01', 'counter' => 0.0],
            ['meter_id' => 'm_gas_main', 'device_id' => 'd_main', 'date' => '2026-01-01', 'counter' => 1000.0],
            ['meter_id' => 'm_gas_old',  'device_id' => 'd_old',  'date' => '2025-01-01', 'counter' => 0.0],
            ['meter_id' => 'm_gas_old',  'device_id' => 'd_old',  'date' => '2025-07-01', 'counter' => 500.0],
        ]);
    }

    private function benchmark(): BenchmarkService
    {
        return new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
    }

    public function testInactiveMeterCountsEverywhere(): void
    {
        $total = array_sum(array_map(fn($m) => (float)$m['kwh'],
            array_filter($this->consumption->forUtility('gas')['monthly_total'], fn($m) => (int)$m['year'] === 2025)));
        self::assertEqualsWithDelta(1500.0, $total, 0.5, 'Utility-Summe');

        $eff = $this->benchmark()->efficiency(2025);
        self::assertEqualsWithDelta(1500.0, $eff['breakdown']['gas'] ?? 0.0, 0.5, 'Effizienz — bisher 1.000');

        $pdf = new PdfReportService($this->meters, $this->consumption, $this->settings, $this->benchmark(),
            new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings, $this->benchmark(),
                new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n),
            $this->i18n);
        $agg = (new \ReflectionMethod($pdf, 'yearAggregate'))->invoke($pdf, 'gas', 2025);
        self::assertEqualsWithDelta(1500.0, (float)$agg['kwh'], 0.5, 'PDF-Jahressumme — bisher 1.000');
    }

    /** v2.9.0 (CALC-24) — Abrechnungsstichtag als echter Kalendertag. */
    public function testBillingAnchorMustBeACalendarDay(): void
    {
        $this->settings->set(['billing_cycle_anchor_gas' => '02-29']);
        self::assertSame('02-29', $this->settings->get('billing_cycle_anchor_gas'), 'Schalttag ist erlaubt');
        foreach (['13-45', '02-30', '1-1', ''] as $bad) {
            try {
                $this->settings->set(['billing_cycle_anchor_gas' => $bad]);
                self::fail("„$bad\" wurde angenommen");
            } catch (\InvalidArgumentException) {
                self::assertSame('02-29', $this->settings->get('billing_cycle_anchor_gas'));
            }
        }
        // Ein unmöglicher Altwert (Import, Restore) rechnet wie der Jahresbeginn
        $s = $this->store->read('settings.json', []);
        $s['billing_cycle_anchor_strom'] = '13-45';
        $this->store->write('settings.json', $s);
        $anchor = (new \ReflectionMethod($this->consumption, 'nextBillingAnchor'))->invoke($this->consumption, 'strom', '2026-09-25');
        self::assertSame('2027-01-01', $anchor);
    }

    /**
     * v2.13.0 (Review FE-10) — Die Einspeisevergütung rechnet ihren Saldo bis
     * zum nächsten Stichtag; der Schlüssel fehlte in den Defaults und war
     * damit weder sichtbar noch pflegbar. Er folgt derselben Prüfung.
     */
    public function testFeedInHasItsOwnBillingAnchor(): void
    {
        self::assertSame('01-01', $this->settings->get('billing_cycle_anchor_pv_einspeisung'));
        self::assertArrayHasKey('billing_cycle_anchor_pv_einspeisung', $this->settings->all());
        $this->settings->set(['billing_cycle_anchor_pv_einspeisung' => '07-01']);
        $anchor = (new \ReflectionMethod($this->consumption, 'nextBillingAnchor'))->invoke($this->consumption, 'pv_einspeisung', '2026-09-25');
        self::assertSame('2027-07-01', $anchor);
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->set(['billing_cycle_anchor_pv_einspeisung' => '02-30']);
    }

    /** v2.9.0 (CALC-24) — Wärmezähler haben eine eigene Eichfrist (fünf Jahre). */
    public function testHeatMeterCalibrationIsAReminderCategory(): void
    {
        self::assertSame(60, \Energietracker\Services\ReminderService::CATEGORY_DEFAULT_MONTHS['waermezaehler_eichung']);
    }

    public function testHelpersSeparateTotalsFromService(): void
    {
        $old = $this->meters->get('gas', 'm_gas_old');
        self::assertTrue(MeterService::countsInTotals($old), 'außer Betrieb zählt in Summen');
        self::assertFalse(MeterService::inService($old), '… ist aber nicht in Betrieb');
        self::assertFalse(MeterService::countsInTotals(['parent_meter_id' => 'm_x']), 'Subzähler nie in Summen');
        self::assertTrue(MeterService::inService(['id' => 'x']), 'ohne Angabe in Betrieb');
    }
}
