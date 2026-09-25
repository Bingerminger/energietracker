<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ContractService;
use Energietracker\Services\ConsumptionService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * N1002 — Case 8: Vertragswechsel mitten im Monat.
 *
 * Bis v2.8 galt der Vertrag vom Monatsersten für den ganzen Monat; dieser Test
 * schrieb das als bewusste Vereinfachung fest. v2.9.0 (Review CALC-10) rechnet
 * tagesgenau wie die Rechnung: Der Monat wird an Vertrags- und Preisstichtagen
 * geteilt, die Zeile gehört dem Vertrag mit den meisten Tagen, die Teile
 * stehen in `contract_parts`.
 */
#[CoversClass(ContractService::class)]
#[CoversClass(ConsumptionService::class)]
final class ContractEdgeCasesTest extends ServiceTestCase
{
    public function testContractSwitchMidMonthSplitsTheMonthByDay(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        // Vertrag A läuft bis 14.03., Vertrag B ab 15.03. — Wechsel mitten im März.
        $a = $this->contracts->create('strom', [
            'meter_id'    => $meterId,
            'provider'    => 'Anbieter A',
            'tariff_name' => 'Alt-Tarif',
            'start'       => '2024-01-01',
            'end'         => '2024-03-14',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices'    => [['from' => '2024-01-01', 'eur_per_month' => 10.0]],
        ]);
        $b = $this->contracts->create('strom', [
            'meter_id'    => $meterId,
            'provider'    => 'Anbieter B',
            'tariff_name' => 'Neu-Tarif',
            'start'       => '2024-03-15',
            'end'         => null,
            'working_prices' => [['from' => '2024-03-15', 'ct_per_kwh' => 40.0]],
            'base_prices'    => [['from' => '2024-03-15', 'eur_per_month' => 15.0]],
        ]);

        $this->setReadings('strom', $meterId, [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => 'd_strom_1'],
            ['date' => '2024-03-01', 'counter' => 100.0, 'device_id' => 'd_strom_1'],
            ['date' => '2024-04-01', 'counter' => 200.0, 'device_id' => 'd_strom_1'],
            ['date' => '2024-05-01', 'counter' => 300.0, 'device_id' => 'd_strom_1'],
        ]);

        $meter   = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);
        $byYm    = array_column($monthly, null, 'ym');

        // März 2024: 100 kWh über 31 Tage. A liefert 14 Tage (01.–14.), B 17.
        $march = $byYm['2024-03'];
        self::assertSame($b['id'], $march['contract_id'], 'die Zeile gehört dem Vertrag mit den meisten Tagen');
        $parts = array_column($march['contract_parts'] ?? [], null, 'contract_id');
        self::assertCount(2, $parts);
        self::assertSame(14, $parts[$a['id']]['days']);
        self::assertSame(17, $parts[$b['id']]['days']);
        self::assertEqualsWithDelta(100 * 14 / 31, $parts[$a['id']]['kwh'], 0.1);
        self::assertEqualsWithDelta(100 * 14 / 31 * 0.30, $parts[$a['id']]['kwh_cost'], 0.01, 'A-Tage zum A-Preis');
        self::assertEqualsWithDelta(100 * 17 / 31 * 0.40, $parts[$b['id']]['kwh_cost'], 0.01, 'B-Tage zum B-Preis');
        self::assertEqualsWithDelta(10.0 * 14 / 31, $parts[$a['id']]['base_price_eur'], 0.01, 'Grundpreis tagesanteilig');
        self::assertEqualsWithDelta(15.0 * 17 / 31, $parts[$b['id']]['base_price_eur'], 0.01);
        $expected = 100 * 14 / 31 * 0.30 + 100 * 17 / 31 * 0.40 + 10.0 * 14 / 31 + 15.0 * 17 / 31;
        self::assertEqualsWithDelta($expected, $march['cost'], 0.02, 'bis v2.8: 40,00 € (ganzer Monat zu A)');

        // April 2024: ganz B; Februar 2024: ganz A — ohne Teile.
        self::assertSame($b['id'], $byYm['2024-04']['contract_id']);
        self::assertSame(40.0, (float)$byYm['2024-04']['working_price_ct']);
        self::assertArrayNotHasKey('contract_parts', $byYm['2024-04']);
        self::assertSame($a['id'], $byYm['2024-02']['contract_id']);

        // Der Vertragsstatus summiert die Teile je Vertrag
        $status = array_column($this->consumption->contractStatus('strom', $meter)['contracts'], null, 'contract_id');
        self::assertEqualsWithDelta(100 + 100 * 14 / 31, $status[$a['id']]['actual_kwh'], 0.2);
        self::assertEqualsWithDelta(100 * 17 / 31 + 100, $status[$b['id']]['actual_kwh'], 0.2);
    }

    /**
     * Komplement: Wenn der Wechsel exakt auf den 01. fällt, geht der Monat
     * an den NEUEN Vertrag (`start <= date` greift).
     */
    public function testContractSwitchOnFirstOfMonthGoesToNewContract(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $this->contracts->create('strom', [
            'meter_id'    => $meterId,
            'provider'    => 'A', 'tariff_name' => 'Alt',
            'start'       => '2024-01-01', 'end' => '2024-02-29',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
        ]);
        $b = $this->contracts->create('strom', [
            'meter_id'    => $meterId,
            'provider'    => 'B', 'tariff_name' => 'Neu',
            'start'       => '2024-03-01', 'end' => null,
            'working_prices' => [['from' => '2024-03-01', 'ct_per_kwh' => 40.0]],
        ]);

        $this->setReadings('strom', $meterId, [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => 'd_strom_1'],
            ['date' => '2024-03-01', 'counter' => 100.0, 'device_id' => 'd_strom_1'],
            ['date' => '2024-04-01', 'counter' => 200.0, 'device_id' => 'd_strom_1'],
        ]);

        $meter   = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);
        $byYm    = array_column($monthly, null, 'ym');

        self::assertSame($b['id'], $byYm['2024-03']['contract_id'],
            'Wechsel am 01. → März läuft bereits mit dem neuen Vertrag B');
    }
}
