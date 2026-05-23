<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Config\Utilities;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\MeterService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * F1005 — Smoke: die bestehenden Service-Pfade (Meter-Anlage, Ablesungen,
 * Bridging, Monatsaggregation) funktionieren für die neuen PV-Utilities
 * ohne Anpassung. Erwartung: pv_einspeisung und pv_erzeugung benehmen
 * sich exakt wie strom (cumulative, kWh-nativ, kein Conversion-Faktor).
 */
#[CoversClass(MeterService::class)]
#[CoversClass(ConsumptionService::class)]
#[CoversClass(Utilities::class)]
final class PvUtilityReadingPathSmokeTest extends ServiceTestCase
{
    /**
     * Migrator legt für PV-Utilities KEINEN Default-Meter an (anders als
     * Strom). meters.json existiert leer, der User muss aktiv einen Zähler
     * anlegen — vermeidet „Phantom-PV-Zähler" für Häuser ohne Anlage.
     */
    public function testPvUtilitiesHaveNoDefaultMeterAfterInitFresh(): void
    {
        foreach (['pv_einspeisung', 'pv_erzeugung'] as $key) {
            $meters = $this->meters->list($key);
            self::assertSame([], $meters,
                "$key darf nach initFresh keinen Default-Meter haben");
        }
        // Gegenprobe: strom HAT einen Default-Meter.
        self::assertNotEmpty($this->meters->list('strom'),
            'Strom hat weiterhin einen Default-Meter');
    }

    public function testPvEinspeisungReadingAggregationProducesMonthlyKwh(): void
    {
        $meter = $this->meters->create('pv_einspeisung', [
            'name'             => 'Einspeisezähler',
            'installed_on'     => '2024-01-01',
            'initial_counter'  => 0.0,
        ]);
        $this->readings->create('pv_einspeisung', [
            'meter_id' => $meter['id'], 'date' => '2024-02-01', 'counter' => 0.0,
        ]);
        $this->readings->create('pv_einspeisung', [
            'meter_id' => $meter['id'], 'date' => '2024-03-01', 'counter' => 300.0,
        ]);
        $monthly = $this->consumption->forMeter('pv_einspeisung', $meter);
        self::assertNotEmpty($monthly);
        $byYm = array_column($monthly, null, 'ym');
        self::assertArrayHasKey('2024-02', $byYm);
        self::assertEqualsWithDelta(300.0, (float)$byYm['2024-02']['kwh'], 0.5,
            'PV-Einspeisung wird wie Strom als kumulative kWh-Differenz aggregiert');
    }

    public function testUtilitiesHelpersClassifyPvCorrectly(): void
    {
        self::assertTrue(Utilities::isCumulative('pv_einspeisung'));
        self::assertTrue(Utilities::isCumulative('pv_erzeugung'));
        self::assertFalse(Utilities::isDelivery('pv_einspeisung'));
        self::assertFalse(Utilities::isHgtRelevant('pv_einspeisung'));

        self::assertSame('feed_in',    Utilities::accountingKind('pv_einspeisung'));
        self::assertSame('generation', Utilities::accountingKind('pv_erzeugung'));
        self::assertSame('consumption', Utilities::accountingKind('strom'));

        self::assertTrue(Utilities::isFeedIn('pv_einspeisung'));
        self::assertFalse(Utilities::isFeedIn('strom'));
        self::assertTrue(Utilities::isGenerationOnly('pv_erzeugung'));

        // hasAdvancePaymentContracts: F1003-Scope darf PV NICHT umfassen
        // (kein Abschlagsmodell beim Verteilnetzbetreiber).
        self::assertTrue(Utilities::hasAdvancePaymentContracts('strom'));
        self::assertFalse(Utilities::hasAdvancePaymentContracts('pv_einspeisung'));
        self::assertFalse(Utilities::hasAdvancePaymentContracts('pv_erzeugung'));

        // hasContracts: pv_erzeugung ist die einzige Utility ohne Verträge.
        self::assertTrue(Utilities::hasContracts('strom'));
        self::assertTrue(Utilities::hasContracts('pv_einspeisung'));
        self::assertFalse(Utilities::hasContracts('pv_erzeugung'));
    }
}
