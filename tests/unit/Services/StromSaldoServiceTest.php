<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\StromSaldoService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * F1005 — Kombinierter Strom-Saldo (Bezug − PV-Einspeisung).
 *
 * Vorzeichen-Konvention:
 *   saldo_netto > 0  → Netto-Kosten (User zahlt unterm Strich)
 *   saldo_netto < 0  → Netto-Erlös  (PV bringt mehr als der Bezug kostet)
 */
#[CoversClass(StromSaldoService::class)]
final class StromSaldoServiceTest extends ServiceTestCase
{
    public function testEmptyDataReturnsEmptyAggregates(): void
    {
        $svc = new StromSaldoService($this->consumption);
        $out = $svc->compute();
        self::assertSame([], $out['monthly']);
        self::assertSame([], $out['yearly']);
    }

    public function testStromBezugWithoutPvProducesPositiveSaldo(): void
    {
        // Default-Strom-Meter mit Vertrag (30 ct/kWh), ein Monat 100 kWh Bezug.
        $stromMeterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->contracts->create('strom', [
            'meter_id' => $stromMeterId,
            'provider' => 'X', 'tariff_name' => 'Standard',
            'start' => '2024-01-01', 'end' => null,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
        ]);
        $this->setReadings('strom', $stromMeterId, [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => 'd_strom_1'],
            ['date' => '2024-03-01', 'counter' => 100.0, 'device_id' => 'd_strom_1'],
        ]);

        $out = (new StromSaldoService($this->consumption))->compute();
        $byYm = array_column($out['monthly'], null, 'ym');
        self::assertArrayHasKey('2024-02', $byYm);
        self::assertEqualsWithDelta(30.0,  (float)$byYm['2024-02']['bezug_cost'],  0.5);
        self::assertEqualsWithDelta(0.0,   (float)$byYm['2024-02']['einspeisung_revenue'], 0.01);
        self::assertEqualsWithDelta(30.0,  (float)$byYm['2024-02']['saldo_netto'], 0.5,
            'Ohne PV ist Saldo = Bezugskosten');
    }

    public function testPvEinspeisungReducesSaldoAndCanFlipNegative(): void
    {
        // Strom-Bezug: 100 kWh × 30 ct = 30 €
        $stromMeterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->contracts->create('strom', [
            'meter_id' => $stromMeterId, 'provider' => 'X', 'tariff_name' => 'Std',
            'start' => '2024-01-01', 'end' => null,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
        ]);
        $this->setReadings('strom', $stromMeterId, [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => 'd_strom_1'],
            ['date' => '2024-03-01', 'counter' => 100.0, 'device_id' => 'd_strom_1'],
        ]);

        // PV-Einspeisung: 500 kWh × 8 ct = 40 €  → Saldo = 30 − 40 = −10 € (Erlös)
        $pvMeter = $this->meters->create('pv_einspeisung', [
            'name' => 'Einspeisezähler',
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        $this->contracts->create('pv_einspeisung', [
            'meter_id' => $pvMeter['id'], 'provider' => 'Netz', 'tariff_name' => 'EEG',
            'start' => '2024-01-01', 'end' => null,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 8.0]],
        ]);
        $this->setReadings('pv_einspeisung', $pvMeter['id'], [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => $pvMeter['devices'][0]['id']],
            ['date' => '2024-03-01', 'counter' => 500.0, 'device_id' => $pvMeter['devices'][0]['id']],
        ]);

        $out = (new StromSaldoService($this->consumption))->compute();
        $byYm = array_column($out['monthly'], null, 'ym');

        self::assertEqualsWithDelta(30.0, (float)$byYm['2024-02']['bezug_cost'],          0.5);
        self::assertEqualsWithDelta(40.0, (float)$byYm['2024-02']['einspeisung_revenue'], 0.5);
        self::assertEqualsWithDelta(-10.0, (float)$byYm['2024-02']['saldo_netto'], 0.5,
            'PV-Erlös > Bezugskosten → negativer Saldo (User verdient)');

        // Jahres-Aggregat existiert.
        $byYear = array_column($out['yearly'], null, 'year');
        self::assertArrayHasKey(2024, $byYear);
        self::assertEqualsWithDelta(-10.0, (float)$byYear[2024]['saldo_netto'], 0.5);
    }
}
