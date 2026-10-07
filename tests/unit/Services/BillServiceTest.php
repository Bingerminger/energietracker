<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\MeterService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H5) — Rechnungen des Versorgers erfassen, gegen die eigene
 * Rechnung halten und buchen (B3/UI-35); Rechnungsprüfung für Strom, Wasser
 * und Fernwärme; Fernwärme-Fixkosten (CALC-31); Lieferantenwechsel (MKT-24).
 */
#[CoversClass(BillService::class)]
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ContractService::class)]
#[CoversClass(MeterService::class)]
#[CoversClass(RecommendationService::class)]
final class BillServiceTest extends ServiceTestCase
{
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    /** 250 kWh je Monat 2025, Ablesung am Monatsersten; 30 ct/kWh, 10 €/Monat, Abschlag 90 €. */
    private function seedYear(): array
    {
        $rows = [];
        for ($m = 0; $m <= 12; $m++) {
            $rows[] = ['date' => date('Y-m-d', (int)strtotime("2025-01-01 +$m months")), 'counter' => 250.0 * $m, 'device_id' => 'd1'];
        }
        $this->setReadings('strom', $this->meterId, $rows);
        return $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T', 'start' => '2025-01-01',
            'working_prices'   => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices'      => [['from' => '2025-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 90.0]],
        ]);
    }

    private function bill(array $invoice, array $extra = []): array
    {
        return $this->bills()->create('strom', $extra + [
            'meter_id' => $this->meterId, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'invoice' => $invoice,
        ]);
    }

    public function testStromBillMatchesWithinOnePercent(): void
    {
        $this->seedYear();
        // eigene Rechnung: 3000 kWh × 0,30 + 12 × 10 = 1020 €
        $b = $this->bill(['energy_kwh' => 3000, 'amount_eur' => 1021.5, 'advances_paid_eur' => 1080]);
        self::assertSame(-58.5, $b['invoice']['result_eur'], 'Ergebnis = Betrag − Abschläge, wenn die Rechnung es nicht nennt');
        $c = $this->bills()->compare('strom', $b['id']);
        self::assertEqualsWithDelta(3000, $c['ours']['kwh'], 0.01);
        self::assertEqualsWithDelta(900, $c['ours']['energy_cost'], 0.01);
        self::assertEqualsWithDelta(120, $c['ours']['fixed_cost'], 0.01);
        self::assertEqualsWithDelta(1020, $c['ours']['total'], 0.01);
        self::assertEqualsWithDelta(1080, $c['ours']['advances'], 0.01);
        self::assertEqualsWithDelta(1.5, $c['delta']['eur'], 0.001);
        self::assertSame('ok', $c['verdict']);
        self::assertSame([], $c['reasons']);
    }

    public function testDeviationAndFreeItemsAreNamed(): void
    {
        $this->seedYear();
        $b = $this->bill(['energy_kwh' => 3100, 'amount_eur' => 1100], ['items' => [['label' => 'Umlage', 'amount_eur' => 12.5, 'kind' => 'levy']]]);
        $c = $this->bills()->compare('strom', $b['id']);
        self::assertSame('check', $c['verdict'], '3,3 % mehr Menge');
        self::assertEqualsWithDelta(1032.5, $c['ours']['total'], 0.01, 'freie Posten kommen zur eigenen Summe');
        self::assertContains('items_not_modelled', $c['reasons']);
        // Menge stimmt, Betrag nicht: 30 € (2,9 %) über 1.020 € sind mehr als 2 € und mehr als 1 %
        $amount = $this->bill(['energy_kwh' => 3000, 'amount_eur' => 1050]);
        self::assertSame('check', $this->bills()->compare('strom', $amount['id'])['verdict'], 'Betrag 30 € daneben');
    }

    public function testBookingIsIdempotent(): void
    {
        $contract = $this->seedYear();
        $b = $this->bill(['amount_eur' => 1020, 'advances_paid_eur' => 970], ['issued_on' => '2026-02-10']);
        $once = $this->bills()->book('strom', $b['id']);
        $twice = $this->bills()->book('strom', $b['id']);
        self::assertSame($once['special_payment_id'], $twice['special_payment_id']);
        self::assertSame($contract['id'], $once['contract_id']);
        $payments = $this->contracts->get('strom', $contract['id'])['special_payments'];
        self::assertCount(1, $payments);
        self::assertSame(['2026-02-10', 'nachzahlung_ohne', 50.0], [$payments[0]['date'], $payments[0]['kind'], (float)$payments[0]['amount_eur']]);
    }

    public function testBookingACreditAndBookingWithoutResult(): void
    {
        $contract = $this->seedYear();
        $credit = $this->bill(['amount_eur' => 1000, 'result_eur' => -80]);
        $this->bills()->book('strom', $credit['id']);
        $p = $this->contracts->get('strom', $contract['id'])['special_payments'][0];
        self::assertSame(['2026-01-01', 'rueckzahlung_ohne', 80.0], [$p['date'], $p['kind'], (float)$p['amount_eur']], 'ohne Rechnungsdatum: Tag nach dem Zeitraum');

        $none = $this->bill(['amount_eur' => 1000]);
        try {
            $this->bills()->book('strom', $none['id']);
            self::fail('ohne Ergebnis gibt es nichts zu buchen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.bill.noResult', $e->key);
        }
    }

    public function testOnlyUtilitiesWithBillCheck(): void
    {
        foreach (['heizoel', 'pv_einspeisung'] as $u) {
            try {
                $this->bills()->list($u);
                self::fail("$u hat keine Rechnungsprüfung");
            } catch (LocalizedException $e) {
                self::assertSame('errors.billCheck.unsupportedUtility', $e->key);
            }
        }
    }

    /** Rechnungsprüfung und Monatssicht rechnen mit denselben Bausteinen. */
    public function testBillCheckAgreesWithMonthlyCosts(): void
    {
        $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0], ['from' => '2025-07-15', 'ct_per_kwh' => 34.0]],
            'base_prices'    => [['from' => '2025-01-01', 'eur_per_month' => 10.0], ['from' => '2025-07-15', 'eur_per_month' => 12.0]],
        ]);
        $rows = [];
        for ($m = 0; $m <= 12; $m++) {
            $rows[] = ['date' => date('Y-m-d', (int)strtotime("2025-01-01 +$m months")), 'counter' => 250.0 * $m, 'device_id' => 'd1'];
        }
        $this->setReadings('strom', $this->meterId, $rows);
        $meter = $this->meters->get('strom', $this->meterId);
        $monthly = 0.0;
        foreach ($this->consumption->forMeter('strom', $meter) as $m) {
            if (str_starts_with((string)$m['ym'], '2025-')) $monthly += (float)$m['kwh_cost'] + (float)$m['base_price_eur'];
        }
        $bd = $this->consumption->billBreakdown('strom', $meter, '2025-01-01', '2026-01-01');
        self::assertEqualsWithDelta($monthly, $bd['totals']['total'], 0.05);
        self::assertContains('price', array_merge(...array_map(fn($r) => explode('+', (string)($r['reason'] ?? '')), $bd['rows'])),
            'Preisstichtag teilt den Abschnitt');
    }

    public function testDistrictHeatingFixedCosts(): void
    {
        $mid = $this->setMeterDevices('fernwaerme', [[
            'id' => 'f1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $c = $this->contracts->create('fernwaerme', [
            'meter_id' => $mid, 'provider' => 'W', 'tariff_name' => 'T', 'start' => '2025-01-01',
            'working_prices'  => [['from' => '2025-01-01', 'ct_per_kwh' => 12.0]],
            'capacity_kw'     => '10',
            'capacity_prices' => [['from' => '2025-01-01', 'eur_per_kw_year' => 60.0]],
            'metering_prices' => [['from' => '2025-01-01', 'eur_per_year' => 120.0]],
            'co2_g_per_kwh'   => '180,5',
        ]);
        self::assertSame(180.5, $c['co2_g_per_kwh']);
        // 10 kW × 60 €/kW·a ÷ 12 + 120 €/a ÷ 12 = 50 + 10
        self::assertEqualsWithDelta(60.0, $this->contracts->fixedPerMonthOn($c, '2025-03-01'), 0.001);
        $this->setReadings('fernwaerme', $mid, [
            ['date' => '2025-01-01', 'counter' => 0.0, 'device_id' => 'f1'],
            ['date' => '2025-02-01', 'counter' => 1000.0, 'device_id' => 'f1'],
        ]);
        $jan = array_column($this->consumption->forMeter('fernwaerme', $this->meters->get('fernwaerme', $mid)), null, 'ym')['2025-01'];
        self::assertEqualsWithDelta(60.0, $jan['base_price_eur'], 0.01);
        self::assertEqualsWithDelta(120.0, $jan['kwh_cost'], 0.01);

        $this->expectException(\InvalidArgumentException::class);
        $this->contracts->update('fernwaerme', $c['id'], ['capacity_kw' => null]);
    }

    public function testMarketLocationIdNeedsAValidCheckDigit(): void
    {
        self::assertTrue(MeterService::isValidMalo('51234567895'));
        self::assertFalse(MeterService::isValidMalo('51234567894'));
        self::assertFalse(MeterService::isValidMalo('01234567895'), 'beginnt nicht mit 0');
        $melo = 'DE' . str_repeat('0', 30) . 'A';
        $m = $this->meters->update('strom', $this->meterId, ['malo_id' => '5123 4567 895', 'melo_id' => strtolower($melo)]);
        self::assertSame(['51234567895', $melo], [$m['malo_id'], $m['melo_id']]);
        $m = $this->meters->update('strom', $this->meterId, ['malo_id' => '']);
        self::assertArrayNotHasKey('malo_id', $m, 'leer entfernt die ID');
        try {
            $this->meters->update('strom', $this->meterId, ['malo_id' => '51234567894']);
            self::fail('falsche Prüfziffer');
        } catch (LocalizedException $e) {
            self::assertSame('errors.meter.maloInvalid', $e->key);
        }
    }

    public function testContractTermOverTwoYearsWarnsButSaves(): void
    {
        $c = $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T', 'start' => '2025-01-01', 'min_term_end' => '2027-03-31',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]],
        ]);
        self::assertSame(['term_over_24_months'], $c['warnings']);
        self::assertArrayNotHasKey('warnings', $this->contracts->get('strom', $c['id']), 'Hinweis wird nicht gespeichert');
        self::assertSame([], ContractService::warnings(['start' => '2025-01-01', 'min_term_end' => '2026-12-31']));
    }

    public function testAnnouncedPriceIncreaseRecommendsCheckingTheSpecialTerminationRight(): void
    {
        $day = fn(int $d) => date('Y-m-d', (int)strtotime("today $d days"));
        $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => $day(-200), 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $this->meterId, [
            ['date' => $day(-200), 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => $day(-10), 'counter' => 1500.0, 'device_id' => 'd1'],
        ]);
        $increase = $day(30);
        $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'Testwerk', 'start' => $day(-200), 'end' => $day(400),
            'working_prices' => [['from' => $day(-200), 'ct_per_kwh' => 30.0], ['from' => $increase, 'ct_per_kwh' => 36.0]],
        ]);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $hits = array_values(array_filter($recs->all(), fn($r) => str_starts_with($r['id'], 'r_price_increase_')));
        self::assertCount(1, $hits);
        self::assertSame($increase, $hits[0]['evidence']['from']);
        self::assertStringContainsString('Testwerk', $hits[0]['detail']);

        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n);
        $ev = array_values(array_filter($agenda->events(90), fn($e) => $e['kind'] === 'price_increase'));
        self::assertCount(1, $ev);
        self::assertTrue($ev[0]['due_now'], 'jetzt handeln, solange die Empfehlung steht');
        self::assertSame($hits[0]['id'], $ev[0]['ref']['recommendation_id']);

        $this->settings->set(['country' => 'AT']);
        self::assertSame([], array_values(array_filter($recs->all(), fn($r) => str_starts_with($r['id'], 'r_price_increase_'))),
            'Sonderkündigungsrecht nach EnWG nur in Deutschland');
    }
}
