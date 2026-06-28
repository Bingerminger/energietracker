<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ContractService;
use Energietracker\Services\ConsumptionService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * N1002 — Case 9: Wasser-Vertrag mit fehlenden Komponenten.
 *
 * Nicht jeder Haushalt hat alle drei Wasser-Komponenten — bei manchen
 * Versorgern entfällt Schmutzwasser oder Niederschlagswasser komplett
 * (z.B. eigene Klärgrube, keine versiegelte Fläche). Der Service muss
 * dann die fehlenden Komponenten als 0 verrechnen und keine Crashes
 * produzieren.
 */
#[CoversClass(ContractService::class)]
#[CoversClass(ConsumptionService::class)]
final class WaterContractEdgeCasesTest extends ServiceTestCase
{
    public function testWaterContractWithoutSchmutzwasserOrNiederschlagswasserComputesOnlyTrinkwasser(): void
    {
        $meterId = $this->setMeterDevices('wasser', [[
            'id' => 'd_wasser_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        // Vertrag NUR mit Trinkwasser — sw und nw bleiben leer (Default-Basis
        // 'trinkwasser' wird gesetzt, hat aber kein working_price → swTotal=0;
        // rates leer → nwTotal=0).
        $this->contracts->create('wasser', [
            'meter_id'    => $meterId,
            'provider'    => 'Stadtwerke',
            'tariff_name' => 'Nur-Trinkwasser',
            'start'       => '2024-01-01',
            'end'         => null,
            'trinkwasser' => [
                'working_prices' => [['from' => '2024-01-01', 'ct_per_m3' => 200.0]],
                'base_prices'    => [['from' => '2024-01-01', 'eur_per_month' => 5.0]],
            ],
            // schmutzwasser / niederschlagswasser bewusst NICHT übergeben.
        ]);

        $this->setReadings('wasser', $meterId, [
            ['date' => '2024-02-01', 'counter' => 0.0,  'device_id' => 'd_wasser_1'],
            ['date' => '2024-03-01', 'counter' => 10.0, 'device_id' => 'd_wasser_1'],
        ]);

        $meter   = $this->meters->get('wasser', $meterId);
        $monthly = $this->consumption->forMeter('wasser', $meter);
        self::assertNotEmpty($monthly, 'Wasser-Aggregation muss trotz fehlender Komponenten produzieren');

        $byYm = array_column($monthly, null, 'ym');
        self::assertArrayHasKey('2024-02', $byYm);
        $row = $byYm['2024-02'];

        // Kosten: 10 m³ × 200 ct/m³ = 20,00 € Arbeitspreis + 5,00 € Grundpreis = 25,00 €.
        // sw und nw tragen 0 bei.
        self::assertNotNull($row['trinkwasser']);
        self::assertEqualsWithDelta(25.0, (float)$row['cost'], 0.5,
            'Gesamtkosten = nur Trinkwasser, ohne Schmutz-/Niederschlagsanteil');
        self::assertSame(0.0, (float)$row['schmutzwasser']['total'],
            'Schmutzwasser-Anteil muss 0 sein, wenn keine ct/m³ definiert sind');
        self::assertSame(0.0, (float)$row['niederschlagswasser']['total'],
            'Niederschlagswasser-Anteil muss 0 sein, wenn keine Rates definiert sind');
        self::assertNull($row['niederschlagswasser']['eur_per_m2_year'],
            'NW-Rate ist null bei fehlenden Daten — Frontend zeigt „—"');
    }

    /**
     * v2.1.4 — Regression: basis='separater_zaehler' OHNE Meter-Referenz darf
     * das Schmutzwasser NICHT auf dem Trinkwasser-Volumen abrechnen (silent
     * wrong cost). Der Speicherpfad (ContractService) erzwingt eine Referenz;
     * un­validierte Daten (Backup-Import/Legacy) können sie aber missen — daher
     * schreiben wir das contracts.json hier direkt.
     */
    public function testSeparaterZaehlerWithoutMeterReferenceBillsZeroNotTrinkwasserVolume(): void
    {
        $meterId = $this->setMeterDevices('wasser', [[
            'id' => 'd_wasser_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $this->store->write('wasser/contracts.json', [[
            'id'          => 'c_water_broken',
            'meter_id'    => $meterId,
            'provider'    => 'Stadtwerke',
            'tariff_name' => 'Separater-Zaehler-ohne-Referenz',
            'start'       => '2024-01-01',
            'end'         => null,
            'trinkwasser' => [
                'working_prices' => [['from' => '2024-01-01', 'ct_per_m3' => 200.0]],
                'base_prices'    => [],
            ],
            'schmutzwasser' => [
                'basis'                      => 'separater_zaehler',
                'separater_zaehler_meter_id' => null,   // ← fehlende Referenz
                'working_prices'             => [['from' => '2024-01-01', 'ct_per_m3' => 150.0]],
            ],
            'niederschlagswasser' => ['rates' => []],
            'advance_payments'    => [],
            'bonuses'             => [],
        ]]);

        $this->setReadings('wasser', $meterId, [
            ['date' => '2024-02-01', 'counter' => 0.0,  'device_id' => 'd_wasser_1'],
            ['date' => '2024-03-01', 'counter' => 10.0, 'device_id' => 'd_wasser_1'],
        ]);

        $meter   = $this->meters->get('wasser', $meterId);
        $monthly = $this->consumption->forMeter('wasser', $meter);
        $byYm    = array_column($monthly, null, 'ym');
        self::assertArrayHasKey('2024-02', $byYm);
        $row = $byYm['2024-02'];

        self::assertGreaterThan(0.0, (float)$row['trinkwasser']['m3'],
            'Trinkwasser-Volumen sollte > 0 sein');
        self::assertSame(0.0, (float)$row['schmutzwasser']['m3'],
            'Ohne Meter-Referenz darf das Schmutzwasser-Volumen NICHT aufs Trinkwasser kippen');
        self::assertSame(0.0, (float)$row['schmutzwasser']['total'],
            'Schmutzwasser-Kosten müssen 0 sein (keine gültige Mengenbasis)');
    }
}
