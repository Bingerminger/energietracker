<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — „Rechnung prüfen“ bei Gas teilt einen Abschnitt am Preiswechsel wie bei Strom (eigene Zeile, Grund
 * `price`). Bis v3.11 blieb es eine Zeile mit dem alten Arbeitspreis; die Kosten waren nach Tagen geteilt, und
 * Preis × Menge ging für den Leser nicht auf.
 *
 * Erfundener Haushalt: Gas 10 kWh/m³, Ablesungen 01.03.2026 = 1.000 m³ und 01.05.2026 = 1.122 m³ (2 m³ am Tag),
 * Vertrag 8,0 ct bis 31.03., 9,0 ct ab 01.04.2026, Grundpreis 10 €/Monat. Von Hand, Prüfzeitraum 01.03.–30.04.:
 *   01.03.–31.03.: 31 Tage × 2 m³ = 62 m³ = 620 kWh × 8,0 ct = 49,60 €
 *   01.04.–30.04.: 30 Tage × 2 m³ = 60 m³ = 600 kWh × 9,0 ct = 54,00 €, Stand am 01.04. 1.062 m³ (Ersatzwert)
 *   Arbeitspreis 103,60 €, Grundpreis 2 × 10 = 20,00 €, zusammen 123,60 € (die Summe wie bisher)
 */
#[CoversClass(ConsumptionService::class)]
final class GasBillPriceChangeTest extends ServiceTestCase
{
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.0]]]);
        $this->meterId = $this->setMeterDevices('gas', [['id' => 'd_g', 'serial' => null, 'installed_on' => '2026-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->setReadings('gas', $this->meterId, [
            ['date' => '2026-03-01', 'counter' => 1000.0, 'device_id' => 'd_g'],
            ['date' => '2026-05-01', 'counter' => 1122.0, 'device_id' => 'd_g'],
        ]);
        $this->contracts->create('gas', ['meter_id' => $this->meterId, 'provider' => 'Gas Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 8.0], ['from' => '2026-04-01', 'ct_per_kwh' => 9.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 10.0]]]);
    }

    /** @return array<string,mixed> */
    private function check(): array
    {
        return $this->consumption->billBreakdown('gas', $this->meters->get('gas', $this->meterId), '2026-03-01', '2026-05-01');
    }

    public function testThePriceChangeStartsItsOwnRow(): void
    {
        $rows = $this->check()['rows'];
        self::assertCount(2, $rows);
        self::assertSame(['2026-03-01', '2026-04-01'], array_column($rows, 'from'));
        self::assertSame('price', $rows[1]['reason']);
        self::assertSame(620.0, $rows[0]['kwh']);
        self::assertSame(8.0, (float)$rows[0]['ct_per_kwh']);
        self::assertSame(49.6, $rows[0]['energy_cost']);
        self::assertSame(600.0, $rows[1]['kwh']);
        self::assertSame(9.0, (float)$rows[1]['ct_per_kwh']);
        self::assertSame(54.0, $rows[1]['energy_cost']);
        self::assertSame(1062.0, $rows[1]['counter_from'], 'von Hand: 1.000 + 31 × 2');
        self::assertSame('interpolated', $rows[1]['counter_from_kind']);
    }

    public function testEveryRowAddsUpForTheReader(): void
    {
        foreach ($this->check()['rows'] as $r) {
            self::assertEqualsWithDelta($r['kwh'] * $r['ct_per_kwh'] / 100, $r['energy_cost'], 0.005, "{$r['from']}: Menge × Preis = Kosten");
            self::assertEqualsWithDelta($r['m3'] * $r['kwh_per_m3'], $r['kwh'], 0.05, "{$r['from']}: m³ × Faktor = kWh");
        }
    }

    public function testTheTotalsStayTheSame(): void
    {
        $t = $this->check()['totals'];
        self::assertSame(1220.0, $t['kwh']);
        self::assertSame(122.0, $t['m3']);
        self::assertSame(103.6, $t['energy_cost']);
        self::assertSame(20.0, $t['fixed_cost']);
        self::assertSame(123.6, $t['total']);
    }
}
