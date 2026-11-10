<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Saldo-Karte im angeschnittenen Monat: Beginnt der Saldo mitten im Monat (Stichtag) und liegt die letzte
 * Ablesung im selben Monat, zählt die Menge ab dem Saldobeginn nach den gemessenen Tagen, nicht nach Kalendertagen
 * des ganzen Monats. Bis v3.11 nahm die Karte vom Monatswert (gemessen bis zur letzten Ablesung) den Anteil
 * „Tage ab Saldobeginn ÷ Tage des Monats“: mehr als gemessen, solange keine Ablesung im Folgemonat da war.
 *
 * Erfundener Haushalt: Strom 12 kWh am Tag, Stände 01.09. = 0, 01.10. = 360, 26.10.2026 = 660 (25 Tage × 12),
 * Stichtag 20.10., heute 28.10.2026. Vertrag seit 01.01.2026: 30 ct, 12 €/Monat, Abschlag 100 €/Monat. Von Hand,
 * gemessen ab 20.10. bis zur Ablesung am 26.10. (6 Tage):
 *   Menge 6 × 12 = 72 kWh → 72 × 0,30 € = 21,60 €
 *   Grundpreis 12 € × 6/31 = 2,32 €, Abschlag 100 € × 6/31 = 19,35 €
 *   Saldo gemessen 21,60 + 2,32 − 19,35 = 4,57 € (Nachzahlung)
 *   der alte Weg: 300 kWh × 12/31 = 116,13 kWh → 34,84 € Arbeitspreis
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillingPeriodService::class)]
final class BalanceStartMidMonthTest extends ServiceTestCase
{
    private const TODAY = '2026-10-28';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['billing_cycle_anchor_strom' => '10-20']);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_m', 'serial' => null, 'installed_on' => '2026-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->setReadings('strom', $this->meterId, [
            ['date' => '2026-09-01', 'counter' => 0.0, 'device_id' => 'd_m'],
            ['date' => '2026-10-01', 'counter' => 360.0, 'device_id' => 'd_m'],
            ['date' => '2026-10-26', 'counter' => 660.0, 'device_id' => 'd_m'],
        ]);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 100.0]]]);
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'][0];
    }

    /** @return array<string,mixed> */
    private function running(): array
    {
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bp = (new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills))
            ->forTarget('strom', $this->meters->get('strom', $this->meterId), false, self::TODAY);
        return array_values(array_filter($bp['periods'], fn($p) => !empty($p['running'])))[0];
    }

    public function testTheCardCountsOnlyTheMeasuredDaysOfTheStartedMonth(): void
    {
        $r = $this->row();
        self::assertSame('2026-10-20', $r['balance_from']);
        self::assertEqualsWithDelta(72.0, (float)$r['actual_kwh'], 0.05, 'von Hand: 6 Tage × 12 kWh');
        // die Monatszeile ab dem Saldobeginn: Grundpreis nach Kalendertagen 20.–31.10., 12 € × 12/31 = 4,65 €
        self::assertSame(4.65, $r['actual_base_total']);
        $m = $r['balance_measured'];
        self::assertSame('2026-10-26', $m['until']);
        self::assertSame(21.6, $m['energy_cost'], 'von Hand: 72 kWh × 0,30 €');
        self::assertSame(2.32, $m['base'], 'von Hand: 12 € × 6/31');
        self::assertSame(19.35, $m['advance'], 'von Hand: 100 € × 6/31');
        self::assertSame(4.57, $m['balance']);
    }

    /** Der Saldo-Verlauf rechnet mit derselben Menge: Sein letzter Punkt ist der erwartete Endsaldo. */
    public function testTheBalancePathEndsAtTheExpectedBalance(): void
    {
        $r = $this->row();
        $path = $r['balance_path'];
        self::assertIsArray($path);
        self::assertSame('2026-10', $path[0]['ym'], 'beginnt im angeschnittenen Monat');
        self::assertEqualsWithDelta((float)$r['projected_end_balance'], (float)end($path)['balance'], 0.02);
    }

    /**
     * Ein als geschätzt erfasster Stand am 28.10. (+24 kWh) nach der echten Ablesung am 26.10.: gemessen bleibt der Teil
     * vom 20. bis 26.10. Von Hand: 72 kWh × 0,30 € = 21,60 € (der alte Weg: 12/31 der Tage × 300/324 der Menge × 97,20 €
     * = 34,84 €).
     */
    public function testAnEstimatedReadingAfterTheLastRealOneKeepsTheMeasuredPart(): void
    {
        $this->setReadings('strom', $this->meterId, [
            ['date' => '2026-09-01', 'counter' => 0.0, 'device_id' => 'd_m'],
            ['date' => '2026-10-01', 'counter' => 360.0, 'device_id' => 'd_m'],
            ['date' => '2026-10-26', 'counter' => 660.0, 'device_id' => 'd_m'],
            ['date' => '2026-10-28', 'counter' => 684.0, 'device_id' => 'd_m', 'is_estimated' => true],
        ]);
        $m = $this->row()['balance_measured'];
        self::assertSame('2026-10-26', $m['until']);
        self::assertSame(21.6, $m['energy_cost']);
    }

    public function testCardAndBillingYearShowTheSameMeasuredPeriod(): void
    {
        $r = $this->row();
        $p = $this->running();
        self::assertSame('2026-10-20', $p['from']);
        self::assertEqualsWithDelta((float)$p['kwh'], (float)$r['actual_kwh'], 0.05, 'Menge: Karte = Abrechnungsjahr');
        self::assertEqualsWithDelta((float)$p['balance'], (float)$r['balance_measured']['balance'], 0.005, 'Saldo gemessen: Karte = Abrechnungsjahr');
    }
}
