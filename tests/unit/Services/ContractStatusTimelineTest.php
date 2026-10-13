<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.4.0 (F1026) — Felder für den Zeitstrahl „Vertrag auf einen Blick“.
 *
 * `contract-status` trägt additiv Mindestlaufzeit, Preisgarantie und ob sich
 * der Vertrag ohne Kündigung verlängert (wirksamer Wert: fehlt = ja). Die
 * Bedeutung von `cancel_by`/`switch_date` bleibt unverändert; das Bild
 * unterscheidet selbst (verlängert, unbefristet, verpasst).
 */
#[CoversClass(ConsumptionService::class)]
final class ContractStatusTimelineTest extends ServiceTestCase
{
    private function rowFor(array $contract): array
    {
        $meter = $this->meters->create('strom', ['name' => 'Haushalt', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0]);
        $this->contracts->create('strom', $contract + [
            'meter_id' => $meter['id'], 'provider' => 'Werk', 'tariff_name' => 'Basis',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices'    => [['from' => '2024-01-01', 'eur_per_month' => 10.0]],
        ]);
        return $this->consumption->contractStatus('strom', $meter)['contracts'][0];
    }

    public function testMinimumTermPriceGuaranteeAndRenewalAreReported(): void
    {
        $start = date('Y-m-d', strtotime('-3 months'));
        $c = $this->rowFor([
            'start' => $start, 'end' => null,
            'min_term_end' => date('Y-m-d', strtotime('+9 months')),
            'price_guarantee_until' => date('Y-m-d', strtotime('+6 months')),
            'notice_period_months' => 1,
        ]);
        self::assertSame(date('Y-m-d', strtotime('+9 months')), $c['min_term_end']);
        self::assertSame(date('Y-m-d', strtotime('+6 months')), $c['price_guarantee_until']);
        self::assertTrue($c['auto_renews'], 'ohne Angabe verlängert sich ein Vertrag (gesetzlicher Normalfall)');
        self::assertSame('min_term', $c['notice_basis'], 'Bedeutung von switchTiming unverändert');
    }

    public function testAContractThatDoesNotRenewSaysSo(): void
    {
        $c = $this->rowFor([
            'start' => date('Y-m-d', strtotime('-6 months')), 'end' => date('Y-m-d', strtotime('+6 months')),
            'auto_renews' => false,
        ]);
        self::assertFalse($c['auto_renews']);
        self::assertNull($c['min_term_end']);
        self::assertNull($c['price_guarantee_until']);
    }
}
