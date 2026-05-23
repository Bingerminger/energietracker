<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ContractService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * F1005 — pv_einspeisung-Verträge sind vereinfacht (nur ct/kWh-
 * Einspeisevergütung). pv_erzeugung-Verträge werden hart abgelehnt.
 */
#[CoversClass(ContractService::class)]
final class ContractServiceFeedInTariffTest extends ServiceTestCase
{
    public function testFeedInContractKeepsOnlyWorkingPrices(): void
    {
        $meter = $this->meters->create('pv_einspeisung', [
            'name' => 'Einspeisezähler',
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);

        $contract = $this->contracts->create('pv_einspeisung', [
            'meter_id'    => $meter['id'],
            'provider'    => 'Netz-BW',
            'tariff_name' => 'EEG 2024',
            'start'       => '2024-01-01',
            'end'         => null,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 8.2]],
            // Felder, die das Frontend für PV NICHT anbietet — Backend muss
            // sie ignorieren, falls jemand sie per API trotzdem mitschickt.
            'base_prices'      => [['from' => '2024-01-01', 'eur_per_month' => 99.0]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 50.0]],
            'special_payments' => [['date' => '2024-06-01', 'kind' => 'refund', 'amount_eur' => 30.0]],
        ]);

        self::assertSame([], $contract['base_prices'],
            'pv_einspeisung darf keinen Grundpreis tragen');
        self::assertSame([], $contract['advance_payments'],
            'pv_einspeisung hat kein Abschlagsmodell');
        self::assertSame([], $contract['special_payments'],
            'pv_einspeisung hat keine Sonderzahlungen');
        self::assertCount(1, $contract['working_prices']);
        self::assertSame(8.2, (float)$contract['working_prices'][0]['ct_per_kwh']);
    }

    public function testGenerationOnlyUtilityRejectsContractCreation(): void
    {
        // Auch wenn der User einen pv_erzeugung-Meter anlegt, darf KEIN
        // Vertrag dort entstehen — pv_erzeugung ist reine Statistik.
        $this->meters->create('pv_erzeugung', [
            'name' => 'WR Süddach',
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/keine Verträge/');

        $this->contracts->create('pv_erzeugung', [
            'provider'    => 'irgendwer',
            'tariff_name' => 'sinnlos',
            'start'       => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 1.0]],
        ]);
    }
}
