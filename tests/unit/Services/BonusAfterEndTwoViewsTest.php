<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Ein Bonus, der nach dem Vertragsende gutgeschrieben wird, gehört in beiden Ansichten zu seinem Vertrag:
 * Saldo-Karte und Abrechnungsjahr. Ansicht gegen Ansicht, mit Handrechnung (behoben seit v3.12.0, Paket
 * Nachprüfung; hier die Probe über beide Perioden und den Wechsel zu einem anderen Versorger).
 *
 * Erfundener Haushalt: Strom 8 kWh am Tag, Stände am Monatsersten 01.07.2025–01.10.2026, Stichtag 01.07., heute
 * 15.10.2026. Vertrag A 01.07.2025–30.06.2026: 28 ct, 10 €/Monat, Abschlag 80 €, Bonus 60 € am 10.08.2026.
 * Vertrag B (anderer Versorger) ab 01.07.2026: dieselben Preise. Von Hand:
 *   Periode A: 365 × 8 = 2.920 kWh × 0,28 € = 817,60 €, Grundpreis 120 €, Bonus 60 €, Abschläge 960 € → −82,40 €
 *   Periode B bis 01.10.: 92 × 8 = 736 kWh × 0,28 € = 206,08 €, Grundpreis 30 €, Abschläge 240 € → −3,92 €
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillingPeriodService::class)]
final class BonusAfterEndTwoViewsTest extends ServiceTestCase
{
    private const TODAY = '2026-10-15';
    private string $meterId = '';
    private string $a = '';
    private string $b = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['billing_cycle_anchor_strom' => '07-01']);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_x', 'serial' => null, 'installed_on' => '2025-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($d = '2025-07-01'; $d <= '2026-10-01'; $d = date('Y-m-d', (int)strtotime("$d +1 month"))) {
            $rows[] = ['date' => $d, 'device_id' => 'd_x', 'counter' => 8.0 * (int)round((strtotime("$d 12:00") - strtotime('2025-07-01 12:00')) / 86400)];
        }
        $this->setReadings('strom', $this->meterId, $rows);
        $prices = ['working_prices' => [['from' => '2025-07-01', 'ct_per_kwh' => 28.0]], 'base_prices' => [['from' => '2025-07-01', 'eur_per_month' => 10.0]],
                   'advance_payments' => [['from' => '2025-07-01', 'amount_eur' => 80.0]]];
        $this->a = (string)$this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Altversorger Musterstadt', 'start' => '2025-07-01',
            'end' => '2026-06-30', 'bonuses' => [['credit_date' => '2026-08-10', 'amount_eur' => 60.0, 'type' => 'wechsel']]] + $prices)['id'];
        $this->b = (string)$this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Neuversorger Musterstadt', 'start' => '2026-07-01']
            + $prices)['id'];
    }

    public function testCardAndBillingYearPutTheBonusWithItsContract(): void
    {
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $periods = array_column((new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills))
            ->forTarget('strom', $this->meters->get('strom', $this->meterId), false, self::TODAY)['periods'], null, 'from');
        $status = array_column($this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'], null, 'contract_id');

        self::assertSame(60.0, (float)$periods['2025-07-01']['bonus'], 'der Bonus im Jahr seines Vertrags');
        self::assertSame(-82.4, (float)$periods['2025-07-01']['balance'], 'von Hand: 817,60 + 120 − 60 − 960');
        self::assertSame(0.0, (float)$periods['2026-07-01']['bonus']);
        self::assertSame(-3.92, (float)$periods['2026-07-01']['balance'], 'von Hand: 206,08 + 30 − 240');

        self::assertSame(60.0, (float)$status[$this->a]['actual_bonus_total']);
        self::assertSame(-82.4, (float)$status[$this->a]['current_balance'], 'Karte A = Abrechnungsjahr A');
        self::assertSame(-3.92, (float)$status[$this->b]['balance_measured']['balance'], 'Karte B = Abrechnungsjahr B');
    }
}
