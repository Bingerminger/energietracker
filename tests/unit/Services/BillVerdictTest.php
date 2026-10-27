<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (treffsicher) — Buchung ohne Ausweichen und „Rechnung prüfen“ mit
 * Aussage: welcher Vertrag eine Rechnung ohne angegebenen Vertrag bekommt,
 * das Urteil „keine Preise“, Grenzstände am letzten Tag, Brennwert laut
 * Rechnung gegen die Stichtag-Liste — und dass sich an der Rechnung selbst
 * (Zeilen, Summen, Saldo) nichts ändert, nur an der Einstufung.
 */
#[CoversClass(BillService::class)]
#[CoversClass(ConsumptionService::class)]
final class BillVerdictTest extends ServiceTestCase
{
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2023-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function contract(string $provider, string $start, ?string $end = null, array $extra = []): array
    {
        return $this->contracts->create('strom', $extra + [
            'meter_id' => $this->meterId, 'provider' => $provider, 'tariff_name' => 'T', 'start' => $start, 'end' => $end,
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => $start, 'eur_per_month' => 10.0]],
        ]);
    }

    /** 250 kWh je Monat, Ablesungen an den angegebenen Tagen (Tagesrate gleichmäßig). */
    private function readingsOn(array $dates): void
    {
        $rows = [];
        foreach ($dates as $d) {
            $days = (int)(new \DateTime('2025-01-01'))->diff(new \DateTime($d))->format('%r%a');
            $rows[] = ['date' => $d, 'counter' => 10000 + round($days * 3000 / 365, 3), 'device_id' => 'd1'];
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function bill(string $from, string $to, array $invoice, array $extra = []): array
    {
        return $this->bills()->create('strom', $extra + ['meter_id' => $this->meterId, 'period_from' => $from, 'period_to' => $to, 'invoice' => $invoice]);
    }

    // ── Buchung ──────────────────────────────────────────────────────────

    public function testBookingWithoutAContractAtThePeriodIsRefusedInsteadOfTakingTheNewest(): void
    {
        $this->contract('Altversorger', '2023-01-01', '2023-12-31', ['auto_renews' => false]);
        $newest = $this->contract('Neuversorger', '2026-03-01');
        $b = $this->bill('2025-01-01', '2025-12-31', ['amount_eur' => 1000, 'result_eur' => 40]);
        try {
            $this->bills()->book('strom', $b['id']);
            self::fail('gebucht an einem Vertrag außerhalb des Zeitraums');
        } catch (LocalizedException $e) {
            self::assertSame('errors.bill.noContract', $e->key);
            self::assertSame(['from' => $this->i18n->date('2025-01-01'), 'to' => $this->i18n->date('2025-12-31')], $e->params, 'die Meldung nennt den Zeitraum');
        }
        self::assertSame([], $this->contracts->get('strom', $newest['id'])['special_payments'] ?? [], 'der jüngste Vertrag bleibt unberührt');
        self::assertNull($this->bills()->get('strom', $b['id'])['special_payment_id'] ?? null);
    }

    public function testAFinalBillOneDayPastTheContractEndGoesToTheOldContract(): void
    {
        $old = $this->contract('Altversorger', '2025-01-01', '2025-06-30', ['auto_renews' => false]);
        $new = $this->contract('Neuversorger', '2025-07-01');
        // Schlussrechnung bis einschließlich 01.07.: Am letzten Tag liefert schon der neue
        $b = $this->bill('2025-01-01', '2025-07-01', ['amount_eur' => 500, 'result_eur' => -25], ['kind' => 'final']);
        self::assertSame($old['id'], $this->bills()->contractFor('strom', $this->meterId, '2025-01-01', '2025-07-01')['id']);
        $booked = $this->bills()->book('strom', $b['id']);
        self::assertSame($old['id'], $booked['contract_id'], 'der Vertrag, der den Zeitraum überwiegend abdeckt');
        self::assertCount(1, $this->contracts->get('strom', $old['id'])['special_payments']);
        self::assertSame([], $this->contracts->get('strom', $new['id'])['special_payments'] ?? []);
    }

    public function testTheContractAtThePeriodEndWinsWhenItCoversMostOfThePeriod(): void
    {
        $a = $this->contract('Versorger', '2024-01-01', '2025-02-28');
        $b = $this->contract('Versorger', '2025-03-01');
        self::assertSame($b['id'], $this->bills()->contractFor('strom', $this->meterId, '2025-01-01', '2025-12-31')['id'], 'gilt am Ende und rechnet 10 von 12 Monaten ab');
        // ein weiterlaufender Vertrag (ohne Kündigung beendet) gilt am Periodenende wie im Saldo
        $this->contracts->delete('strom', $b['id']);
        self::assertSame($a['id'], $this->bills()->contractFor('strom', $this->meterId, '2025-01-01', '2025-12-31')['id']);
        // drei Abschnitte, keiner über die Hälfte: der am letzten Tag
        $this->contracts->update('strom', $a['id'], ['end' => '2025-04-30']);
        $this->contract('Versorger', '2025-05-01', '2025-08-31');
        $c = $this->contract('Versorger', '2025-09-01');
        self::assertSame($c['id'], $this->bills()->contractFor('strom', $this->meterId, '2025-01-01', '2025-12-31')['id']);
        // ohne Verträge: keiner
        self::assertNull($this->bills()->contractFor('strom', 'm_strom_unbekannt', '2025-01-01', '2025-12-31'));
    }

    public function testAGivenContractIsStillUsedAsItIs(): void
    {
        $this->contract('Versorger', '2025-01-01');
        $other = $this->contract('Anderer', '2020-01-01', '2020-12-31');
        $b = $this->bill('2025-01-01', '2025-12-31', ['amount_eur' => 1000, 'result_eur' => 10], ['contract_id' => $other['id']]);
        self::assertSame($other['id'], $this->bills()->book('strom', $b['id'])['contract_id'], 'ausdrücklich angegeben: wie bisher');
    }

    // ── Urteil ───────────────────────────────────────────────────────────

    public function testWithoutPricesTheVerdictIsNoPricesWithTheContractToFix(): void
    {
        $c = $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Ohne Preis', 'tariff_name' => 'T', 'start' => '2025-01-01']);
        $this->readingsOn(['2025-01-01', '2026-01-01']);
        $b = $this->bill('2025-01-01', '2025-12-31', ['energy_kwh' => 3000, 'amount_eur' => 1020]);
        $r = $this->bills()->compare('strom', $b['id']);
        self::assertSame('no_prices', $r['verdict'], 'nicht „prüfen“: ohne Preise ist nichts nachzurechnen');
        self::assertContains('price_missing', $r['reasons']);
        self::assertSame([['from' => '2025-01-01', 'to' => '2025-12-31', 'contract_id' => $c['id'], 'provider' => 'Ohne Preis']], $r['price_missing_at']);
        // weicht die Menge ab, bleibt es „prüfen“
        $off = $this->bill('2025-01-01', '2025-12-31', ['energy_kwh' => 3300, 'amount_eur' => 1020]);
        self::assertSame('check', $this->bills()->compare('strom', $off['id'])['verdict']);
        // ohne Vertrag: kein Vertrag genannt
        $this->contracts->delete('strom', $c['id']);
        $r = $this->bills()->compare('strom', $b['id']);
        self::assertSame('no_prices', $r['verdict']);
        self::assertNull($r['price_missing_at'][0]['contract_id']);
        // mit Preisen und passendem Betrag: passt
        $this->contract('Versorger', '2025-01-01');
        $r = $this->bills()->compare('strom', $b['id']);
        self::assertSame('ok', $r['verdict']);
        self::assertSame([], $r['price_missing_at']);
    }

    public function testAReadingOnTheLastDayIsABoundaryReadingNotAnEstimate(): void
    {
        $this->contract('Versorger', '2025-01-01');
        // Rechnung nennt den Stand am 31.12. — die Prüfung rechnet bis zum 01.01.
        $this->readingsOn(['2024-12-31', '2025-06-30', '2025-12-31', '2026-01-31']);
        $b = $this->bill('2025-01-01', '2025-12-31', ['energy_kwh' => 3000, 'amount_eur' => 1020]);
        $r = $this->bills()->compare('strom', $b['id']);
        self::assertSame(['start' => 'reading_adjacent', 'end' => 'reading_adjacent'], $r['boundaries']);
        self::assertNotContains('estimated_reading_at_boundary', $r['reasons']);
        self::assertNotContains('gaps', $r['reasons']);
        self::assertSame('ok', $r['verdict']);

        // ohne Ablesung danach: der eine Tag nach dem Grenzstand ist keine Lücke
        $this->readingsOn(['2024-12-31', '2025-12-31']);
        $r = $this->bills()->compare('strom', $b['id']);
        self::assertSame('reading_adjacent', $r['boundaries']['end']);
        self::assertNotContains('gaps', $r['reasons']);
        // v3.9.0 (Skeptiker M13) — auch die Prüfung selbst: Der Stand am 31.12. ist der Endstand, der eine Tag
        // danach hat keinen Verbrauch und ist keine Lücke mehr (bis v3.8: 1 Lücke, „dort fehlt der Verbrauch“)
        $bd = $this->consumption->billBreakdown('strom', $this->meters->get('strom', $this->meterId), '2025-01-01', '2026-01-01');
        self::assertSame([0, 0.0], [$bd['totals']['gaps'], (float)end($bd['rows'])['kwh']]);

        // Gegenprobe: weit weg von beiden Rändern bleibt es geschätzt
        $this->readingsOn(['2024-12-15', '2026-01-15']);
        $r = $this->bills()->compare('strom', $b['id']);
        self::assertSame(['start' => 'interpolated', 'end' => 'interpolated'], $r['boundaries']);
        self::assertContains('estimated_reading_at_boundary', $r['reasons']);
        // als geschätzt erfasst: bleibt geschätzt, auch am letzten Tag
        $this->setReadings('strom', $this->meterId, [
            ['date' => '2025-01-01', 'counter' => 10000, 'device_id' => 'd1'],
            ['date' => '2025-12-31', 'counter' => 12992, 'device_id' => 'd1', 'is_estimated' => true],
        ]);
        $r = $this->bills()->compare('strom', $b['id']);
        self::assertSame('reading_estimated', $r['boundaries']['end']);
        self::assertContains('estimated_reading_at_boundary', $r['reasons']);
    }

    public function testInnerPriceDatesAreNotBoundaries(): void
    {
        $this->contract('Versorger', '2025-01-01', null, ['working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0], ['from' => '2025-07-15', 'ct_per_kwh' => 32.0]]]);
        $this->readingsOn(['2025-01-01', '2026-01-01']);
        $b = $this->bill('2025-01-01', '2025-12-31', ['energy_kwh' => 3000]);
        $r = $this->bills()->compare('strom', $b['id']);
        $inner = array_values(array_filter($r['rows'], fn($x) => $x['from'] === '2025-07-15'));
        self::assertSame('interpolated', $inner[0]['counter_from_kind'], 'innen interpoliert wie beim Versorger');
        self::assertContains('price_change_inside', $r['reasons']);
        self::assertNotContains('estimated_reading_at_boundary', $r['reasons'], 'bis v3.6 zählte jede Zeile');
    }

    public function testOnlyTheClassificationChangesNotTheBill(): void
    {
        $this->contract('Versorger', '2025-01-01', null, ['advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 90.0]]]);
        $this->readingsOn(['2024-12-31', '2025-03-31', '2025-08-15', '2025-12-31']);
        $meter = $this->meters->get('strom', $this->meterId);
        $before = [$this->consumption->forMeter('strom', $meter), $this->consumption->contractStatus('strom', $meter),
                   $this->consumption->billBreakdown('strom', $meter, '2025-01-01', '2026-01-01')];
        $b = $this->bill('2025-01-01', '2025-12-31', ['energy_kwh' => 3000, 'amount_eur' => 1020]);
        $r = $this->bills()->compare('strom', $b['id']);
        self::assertSame($before[2]['rows'], $r['rows'], 'Zeilen wie billBreakdown()');
        self::assertSame($before[2]['totals']['total'], $r['ours']['total']);
        self::assertSame($before[2]['totals']['kwh'], $r['ours']['kwh']);
        // die Prüfung liest nur: Monatssicht und Saldo danach wie davor
        self::assertSame($before[0], $this->consumption->forMeter('strom', $meter));
        self::assertSame($before[1], $this->consumption->contractStatus('strom', $meter));
        self::assertSame(['2025-12-31' => 'reading'], $this->consumption->readingKindsOn('strom', $meter, ['2025-12-31', '2026-01-01']));
    }

    // ── Gas: Brennwert laut Rechnung ─────────────────────────────────────

    private function gasSetup(array $factors): array
    {
        $mid = $this->setMeterDevices('gas', [[
            'id' => 'g1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->settings->set(['gas_conversion_factors' => $factors]);
        $this->contracts->create('gas', ['meter_id' => $mid, 'provider' => 'Gasversorger', 'tariff_name' => 'G', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 10.0]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]]]);
        $this->setReadings('gas', $mid, [['date' => '2025-01-01', 'counter' => 1000, 'device_id' => 'g1'], ['date' => '2026-01-01', 'counter' => 2200, 'device_id' => 'g1']]);
        return ['meter' => $mid];
    }

    public function testAFactorChangeTheListKnowsLikeTheInvoiceIsNoReason(): void
    {
        $s = $this->gasSetup([['zustandszahl' => 0.95, 'brennwert' => 11.2], ['from' => '2025-07-01', 'zustandszahl' => 0.95, 'brennwert' => 11.4]]);
        $bills = $this->bills();
        $same = $bills->create('gas', ['meter_id' => $s['meter'], 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'invoice' => ['energy_kwh' => 13560],
            'factors' => [['from' => '2025-01-01', 'to' => '2025-06-30', 'zustandszahl' => 0.95, 'brennwert' => 11.2],
                          ['from' => '2025-07-01', 'to' => '2025-12-31', 'zustandszahl' => 0.95, 'brennwert' => 11.4]]]);
        $r = $bills->compare('gas', $same['id']);
        self::assertNotContains('factor_change_inside', $r['reasons'], 'die Liste kennt den Wechsel wie die Rechnung');
        self::assertNotContains('factor_mismatch', $r['reasons']);
        self::assertSame([], $r['factor_mismatches']);

        // die Rechnung teilt anders: Hinweis mit beiden Werten
        $other = $bills->create('gas', ['meter_id' => $s['meter'], 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'invoice' => ['energy_kwh' => 13560],
            'factors' => [['from' => '2025-01-01', 'zustandszahl' => 0.95, 'brennwert' => 11.2], ['from' => '2025-10-01', 'zustandszahl' => 0.95, 'brennwert' => 11.4]]]);
        $r = $bills->compare('gas', $other['id']);
        self::assertContains('factor_mismatch', $r['reasons']);
        self::assertSame(['2025-07-01'], array_column($r['factor_mismatches'], 'from'));
        self::assertSame(['zustandszahl' => 0.95, 'brennwert' => 11.2, 'kwh_per_m3' => 10.64], $r['factor_mismatches'][0]['invoice']);
        self::assertEqualsWithDelta(10.83, $r['factor_mismatches'][0]['list']['kwh_per_m3'], 1e-9);

        // ohne Angaben der Rechnung: wie bisher ein Hinweis
        $plain = $bills->create('gas', ['meter_id' => $s['meter'], 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'invoice' => ['energy_kwh' => 13560]]);
        self::assertContains('factor_change_inside', $bills->compare('gas', $plain['id'])['reasons']);
        self::assertArrayNotHasKey('factors', $plain, 'ohne Angabe kein Feld');
    }

    public function testInvoiceFactorsAreValidated(): void
    {
        $s = $this->gasSetup([['zustandszahl' => 0.95, 'brennwert' => 11.2]]);
        $this->expectException(LocalizedException::class);
        $this->bills()->create('gas', ['meter_id' => $s['meter'], 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'invoice' => [],
            'factors' => [['from' => '2025-01-01', 'zustandszahl' => 9512, 'brennwert' => 11.2]]]);
    }
}
