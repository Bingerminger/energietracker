<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Skeptiker-Lauf 3.9.0, H1) — Abschlag alle zwei Monate: Der laufend angemessene
 * Abschlag gilt je Rate wie der Plan. Bis dahin stand ein Monatsbetrag neben dem Betrag je
 * Termin („rund 76 € statt 165,00 € im Monat“, der Plan war 165 € alle 2 Monate).
 *
 * Handrechnung (erfundener Haushalt): Strom 3 kWh am Tag, 30 ct/kWh, Grundpreis 10 € im Monat,
 * Abschlag 165 € alle zwei Monate ab 15.01.2026. Stichtag 10.09.2026: bis zur Abrechnung am
 * 31.12.2026 noch zwei Raten (15.09., 15.11.), also kein Nachholbetrag (L12), dafür der laufende
 * Bedarf: 2027 = 365 × 3 × 0,30 + 12 × 10 = 448,50 € ÷ 6 Raten = 74,75 → 75 € je Rate
 * (je Monat wären es 37 €).
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ScenarioService::class)]
final class RhythmAdviceTest extends ServiceTestCase
{
    private const TODAY = '2026-09-10';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_h1', 'serial' => null, 'installed_on' => '2025-12-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->settings->set(['billing_cycle_anchor_strom' => '01-01']);
        // 3 kWh am Tag, abgelesen am Monatsersten und am Stichtag
        $rows = [];
        foreach (array_merge(array_map(fn($i) => date('Y-m-d', (int)strtotime("2025-12-01 +$i months")), range(0, 9)), [self::TODAY]) as $d) {
            $rows[] = ['date' => $d, 'counter' => 3.0 * (int)round((strtotime($d) - strtotime('2025-12-01')) / 86400), 'device_id' => 'd_h1'];
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function contract(array $advance): void
    {
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [$advance]]);
    }

    private function card(): array
    {
        return $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'][0];
    }

    public function testTheOngoingAdvanceIsPerInstallmentOfThePlan(): void
    {
        $this->contract(['from' => '2026-01-15', 'amount_eur' => 165, 'every_months' => 2]);
        $c = $this->card();
        self::assertSame(2, $c['months_left'], 'zwei Raten bis zur Abrechnung');
        self::assertSame('short', $c['suggestion_withheld']);
        self::assertSame(2, $c['current_advance_every_months']);
        self::assertSame(75.0, $c['advance_ongoing'], '448,50 € ÷ 6 Raten (bis dahin 37 € je Monat neben 165 € je Rate)');

        // Werkstatt: dieselben Beträge je Rate, mit dem Rhythmus
        $f = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $ws = (new ScenarioService($f, $this->consumption, $this->contracts, $this->meters, $this->settings,
            new TariffSwitchService($f, $this->contracts, $this->meters, $this->i18n),
            new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills)))
            ->compute(['utility' => 'strom', 'meter_id' => $this->meterId], self::TODAY);
        self::assertSame(2, $ws['advance']['every_months']);
        self::assertSame([165.0, 75.0, 75.0], [(float)$ws['advance']['current'], (float)$ws['advance']['base_ongoing'], (float)$ws['advance']['scen_ongoing']]);
    }

    public function testAMonthlyPlanKeepsTheMonthlyAmount(): void
    {
        $this->contract(['from' => '2026-01-01', 'amount_eur' => 80]);
        $c = $this->card();
        // monatlich: drei Abschläge bis zur Abrechnung (Oktober bis Dezember), 448,50 € ÷ 12 = 37,38 → 37 €
        self::assertSame(3, $c['months_left']);
        self::assertSame(37.0, $c['advance_ongoing']);
        self::assertArrayNotHasKey('current_advance_every_months', $c);
    }
}
