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
 * Stichtag-Konvention: {@see ConsumptionService::applyStandardContracts()}
 * fragt mit dem 1. des Monats `findActiveForDate($contracts, $ym . '-01')`
 * den aktiven Vertrag ab. Folge: ein Monat wird IMMER vollständig dem am
 * Monatsersten gültigen Vertrag zugeordnet — keine tagsgenaue Aufteilung.
 * Das ist eine bewusste Vereinfachung (vgl. functional/03-vertraege.md);
 * dieser Test schreibt sie fest, bevor F1006 das Modell anfasst.
 */
#[CoversClass(ContractService::class)]
#[CoversClass(ConsumptionService::class)]
final class ContractEdgeCasesTest extends ServiceTestCase
{
    public function testContractSwitchMidMonthAttributesEntireMonthToContractActiveOnFirst(): void
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

        // März 2024: Wechsel ist am 15.03., am 01.03. galt noch Vertrag A.
        // → März-Verbrauch wird vollständig Vertrag A zugeschlagen.
        self::assertSame($a['id'], $byYm['2024-03']['contract_id'],
            'März-Monat muss dem am 01.03. aktiven Vertrag A zugeschlagen werden');
        self::assertSame(30.0, (float)$byYm['2024-03']['working_price_ct'],
            'Arbeitspreis im März stammt aus Vertrag A');

        // April 2024: Vertrag A endete 14.03., Vertrag B aktiv ab 15.03.
        // → April läuft komplett mit B.
        self::assertSame($b['id'], $byYm['2024-04']['contract_id'],
            'April-Monat läuft mit dem Folgevertrag B');
        self::assertSame(40.0, (float)$byYm['2024-04']['working_price_ct'],
            'Arbeitspreis im April stammt aus Vertrag B');

        // Februar 2024: ungebrochen Vertrag A.
        self::assertSame($a['id'], $byYm['2024-02']['contract_id']);
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
