<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ContractService;
use Energietracker\Services\TariffComparisonService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.5.3 — CALC-09 im Review 2026-09-24: Ein Bonus, der nach dem
 * Vertragsende gutgeschrieben wird (Wechselbonus mit der Schlussrechnung),
 * fiel aus Saldo und Tarifvergleich heraus. Im Gutschriftsmonat galt schon
 * der Folgevertrag, und dessen Boni wurden gesucht.
 */
#[CoversClass(ContractService::class)]
final class BonusAttributionTest extends ServiceTestCase
{
    private function seed(): string
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [];
        for ($m = 1; $m <= 15; $m++) {
            $d = (new \DateTimeImmutable('2024-01-01'))->modify('+' . ($m - 1) . ' months')->format('Y-m-d');
            $rows[] = ['date' => $d, 'counter' => ($m - 1) * 250.0, 'device_id' => 'd1'];
        }
        $this->setReadings('strom', $meterId, $rows);

        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'Alt', 'start' => '2024-01-01', 'end' => '2024-12-31',
            'working_prices'   => [['from' => '2024-01-01', 'ct_per_kwh' => 30]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 80]],
            'bonuses'          => [['credit_date' => '2025-02-10', 'amount_eur' => 120, 'type' => 'wechsel']],
        ]);
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'Neu', 'start' => '2025-01-01',
            'working_prices'   => [['from' => '2025-01-01', 'ct_per_kwh' => 28]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 75]],
        ]);
        return $meterId;
    }

    public function testBonusCreditedAfterContractEndCountsForItsOwnContract(): void
    {
        $meterId = $this->seed();
        $status = $this->consumption->contractStatus('strom', $this->meters->get('strom', $meterId))['contracts'];
        $old = array_values(array_filter($status, fn($s) => $s['provider'] === 'Alt'))[0];
        $new = array_values(array_filter($status, fn($s) => $s['provider'] === 'Neu'))[0];

        self::assertEqualsWithDelta(120.0, $old['actual_bonus_total'], 0.001, 'Bonus gehört zum alten Vertrag');
        self::assertEqualsWithDelta(0.0, $new['actual_bonus_total'], 0.001, 'nicht zum Folgevertrag');
    }

    public function testTariffComparisonIncludesTheBonus(): void
    {
        $meterId = $this->seed();
        $tc = new TariffComparisonService($this->consumption, $this->contracts, $this->meters, $this->i18n);
        $res = $tc->compare('strom', $meterId, 2024);
        $old = array_values(array_filter($res['rows'], fn($r) => $r['provider'] === 'Alt'))[0];
        // 12 Monate × 250 kWh × 0,30 € = 900 € − 120 € Bonus
        self::assertEqualsWithDelta(780.0, $old['total_eur'], 0.5);
    }
}
