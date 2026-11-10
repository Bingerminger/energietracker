<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — „Preise, die heute gelten“ im Vertragsstatus: am Tag, nicht am Monatsersten.
 * Bis v3.11 zeigte die Karte bei einem Preis ab dem 15. bis zum Monatsende den alten Preis,
 * obwohl die Rechnung selbst schon tagesgenau rechnete. Werte mit der Hand gesetzt.
 */
#[CoversClass(ConsumptionService::class)]
final class CurrentPriceOnTheDayTest extends ServiceTestCase
{
    private function meter(string $utility): string
    {
        return $this->setMeterDevices($utility, [['id' => "d_$utility", 'serial' => null, 'installed_on' => '2026-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
    }

    public function testElectricityPriceFromTheFifteenthCountsFromTheFifteenth(): void
    {
        $m = $this->meter('strom');
        $this->contracts->create('strom', ['meter_id' => $m, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0], ['from' => '2026-07-15', 'ct_per_kwh' => 40.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 10.0], ['from' => '2026-07-15', 'eur_per_month' => 12.0]]]);
        $at = fn(string $day) => $this->consumption->contractStatus('strom', $this->meters->get('strom', $m), $day)['contracts'][0];
        self::assertSame(30.0, (float)$at('2026-07-14')['current_working_price_ct'], 'am 14.07. gilt noch der alte Preis');
        self::assertSame(40.0, (float)$at('2026-07-20')['current_working_price_ct'], 'am 20.07. gilt der Preis ab dem 15.');
        self::assertSame(12.0, (float)$at('2026-07-20')['current_base_price_eur'], 'ebenso der Grundpreis');
    }

    public function testWaterPriceFromTheFifteenthCountsFromTheFifteenth(): void
    {
        $m = $this->meter('wasser');
        $this->contracts->create('wasser', ['meter_id' => $m, 'provider' => 'Wasserwerk Musterstadt', 'start' => '2026-01-01',
            'trinkwasser' => ['working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 200.0], ['from' => '2026-07-15', 'ct_per_m3' => 250.0]],
                'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 5.0]]]]);
        $c = $this->consumption->contractStatus('wasser', $this->meters->get('wasser', $m), '2026-07-20')['contracts'][0];
        self::assertSame(250.0, (float)$c['current_working_price_ct'], 'Trinkwasser am 20.07.: 250 ct ab dem 15.');
    }
}
