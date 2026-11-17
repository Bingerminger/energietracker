<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\Co2CostService;
use Energietracker\Services\ContractService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (K2d) — Leser, die einen Topf neben dem Dienst lesen, sehen den Bestand wie die Rechnung
 * (Support\FieldRules::view): die Frist der CO₂-Erstattung (Rechnungen) und die Vertragskonflikte über die
 * Verbrauchsarten hinweg (Verträge der anderen Arten). Bisher lasen beide die Datei roh: Ein Rechnungsdatum oder ein
 * Anbieter als Liste aus einer älteren Version endete in `(string)` auf eine Liste (eine Warnung, in der App 500), und
 * „false“ als Text galt als Schattentarif.
 */
#[CoversClass(Co2CostService::class)]
#[CoversClass(ContractService::class)]
final class LegacyValuesReadersTest extends ServiceTestCase
{
    public function testTheCo2ClaimDeadlineReadsBillsLikeTheCalculation(): void
    {
        $this->store->write('gas/bills.json', [
            ['id' => 'b_a', 'meter_id' => 'm_x', 'period_from' => '2023-01-01', 'period_to' => '2023-12-31', 'issued_on' => ['2024-02-12']],
            ['id' => 'b_b', 'meter_id' => 'm_x', 'period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'issued_on' => '2025-02-12'],
        ]);
        $co2 = new Co2CostService($this->store, $this->settings, $this->meters, $this->consumption, $this->contracts, $this->i18n);
        // ohne brauchbares Rechnungsdatum gilt der Tag nach dem Zeitraum (wie ohne Rechnungsdatum)
        self::assertSame('2025-01-01', $co2->claimDeadline(2023));
        self::assertSame('2026-02-12', $co2->claimDeadline(2024));
    }

    public function testContractConflictsReadTheOtherUtilitiesLikeTheCalculation(): void
    {
        $gas = $this->meters->defaultId('gas');
        $strom = $this->meters->defaultId('strom');
        $price = [['from' => '2024-01-01', 'ct_per_kwh' => 27.3]];
        // zwei Gasverträge überschneiden sich; der zweite trägt den Tarif von Strom (Kopie zählt nur mit Überschneidung)
        $this->store->write('gas/contracts.json', [
            ['id' => 'c_gas_vorher', 'meter_id' => $gas, 'provider' => 'Gasversorger Beispiel', 'start' => '2023-01-01', 'end' => '2024-12-31',
             'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 9.7]]],
            ['id' => 'c_gas', 'meter_id' => $gas, 'provider' => 'Stadtwerk Beispiel', 'tariff_name' => 'Klartarif', 'start' => '2024-06-01', 'working_prices' => [['from' => '2024-06-01', 'ct_per_kwh' => 27.3]]],
        ]);
        $this->store->write('strom/contracts.json', [
            // „false“ als Text ist kein Schattentarif: derselbe Tarif mit demselben Preis ist eine Kopie
            ['id' => 'c_strom', 'meter_id' => $strom, 'provider' => 'Stadtwerk Beispiel', 'tariff_name' => 'Klartarif', 'start' => '2024-01-01',
             'is_shadow' => 'false', 'working_prices' => $price],
            // ein Anbieter als Liste gilt als nicht eingetragen (bisher 500 beim Vergleich der Tarife)
            ['id' => 'c_alt', 'meter_id' => $strom, 'provider' => ['Stadtwerk'], 'tariff_name' => 'Klartarif', 'start' => '2023-01-01',
             'end' => '2023-12-31', 'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 30.1]]],
        ]);
        $rows = $this->contracts->conflictsFor('gas');
        $foreign = array_values(array_filter($rows, fn($r) => $r['kind'] === 'foreign_tariff' && $r['contract_id'] === 'c_gas'));
        self::assertCount(1, $foreign, json_encode($rows));
        self::assertContains('copy', $foreign[0]['signals'], json_encode($foreign));
        self::assertSame('strom', $foreign[0]['other_utility']);
    }
}
