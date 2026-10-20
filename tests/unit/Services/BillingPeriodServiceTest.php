<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\CsvExportService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.6.0 (F1030, GitHub #33) — Abrechnungsjahr statt Kalenderjahr: Perioden
 * aus Rechnungen und Stichtag, Summen wie „Rechnung prüfen“, Abschläge
 * tagesanteilig, Zäsur, Gruppen und Subzähler, CSV und Bericht.
 */
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(CsvExportService::class)]
final class BillingPeriodServiceTest extends ServiceTestCase
{
    use ReportFixture;

    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function service(): BillingPeriodService
    {
        return new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills());
    }

    /** Ablesung am Monatsersten von 2024-01 bis $until, 250 kWh je Monat; Vertrag 30 ct, 10 €/Monat, Abschlag 90 €. */
    private function seed(string $until = '2026-01-01', ?string $meterId = null, float $perMonth = 250.0): void
    {
        $rows = [];
        for ($m = 0; ; $m++) {
            $d = date('Y-m-d', (int)strtotime("2024-01-01 +$m months"));
            if ($d > $until) break;
            $rows[] = ['date' => $d, 'counter' => $perMonth * $m, 'device_id' => 'd1'];
        }
        $this->setReadings('strom', $meterId ?? $this->meterId, $rows);
        $this->contracts->create('strom', [
            'meter_id' => $meterId ?? $this->meterId, 'provider' => 'A', 'tariff_name' => 'T', 'start' => '2024-01-01',
            'working_prices'   => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices'      => [['from' => '2024-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 90.0]],
        ]);
    }

    private function bill(string $from, string $to): array
    {
        return $this->bills()->create('strom', ['meter_id' => $this->meterId, 'period_from' => $from, 'period_to' => $to,
            'invoice' => ['amount_eur' => 1000.0, 'advances_paid_eur' => 1080.0]]);
    }

    /** @return list<string> „von..bis/Quelle“ je Periode */
    private static function spans(array $periods): array
    {
        return array_map(fn($p) => $p['from'] . '..' . $p['until'] . '/' . $p['source'], $periods);
    }

    public function testWithoutBillsTheAnchorCutsAndTheFirstPeriodStartsAtTheData(): void
    {
        $this->seed();
        $this->settings->set(['billing_cycle_anchor_strom' => '09-01']);
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2026-03-15');
        self::assertSame(['2024-01-01..2024-08-31/anchor', '2024-09-01..2025-08-31/anchor', '2025-09-01..2026-08-31/anchor'], self::spans($r['periods']));
        [$first, $full, $run] = $r['periods'];
        self::assertFalse($first['complete'], 'vor der ersten Ablesung keine Periode, die angebrochene ist eine Teilperiode');
        self::assertNull($first['label']);
        self::assertTrue($full['complete']);
        self::assertSame('2024/25', $full['label']);
        self::assertTrue($run['running']);
        self::assertSame('2025/26', $run['label']);
        self::assertSame('2025-12-31', $run['as_of'], 'die laufende Periode zählt bis zur letzten Ablesung');
        self::assertEqualsWithDelta(1000.0, $run['kwh'], 0.5);
        self::assertEqualsWithDelta(360.0, $run['advances'], 0.01, 'Abschläge auf derselben Grundlage wie die Menge');
    }

    // ── Randfälle (v3.6.0, Nachprüfung) ────────────────────────────────

    public function testWithoutReadingsThereAreNoPeriods(): void
    {
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), true, '2026-03-15');
        self::assertSame([], $r['periods'], 'ohne Ablesungen nichts anbieten, kein Fehler');
    }

    public function testABillBeforeTheFirstReadingCreatesNoPeriod(): void
    {
        $this->seed();
        $this->bill('2023-01-01', '2023-12-31');
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2026-03-15');
        self::assertSame('2024-01-01', $r['periods'][0]['from'] ?? null, 'die erste Periode beginnt mit den Daten');
        self::assertNotContains('2023-01-01..2023-12-31/bill', self::spans($r['periods']));
    }

    public function testABillLongerThanAYearStaysOnePeriod(): void
    {
        $this->seed();
        $this->bill('2024-01-01', '2025-02-28');   // 14 Monate, etwa nach einem Lieferantenwechsel
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2026-03-15');
        self::assertSame('2024-01-01..2025-02-28/bill', self::spans($r['periods'])[0]);
        self::assertEqualsWithDelta(14 * 250.0, $r['periods'][0]['kwh'], 0.5, 'Menge über die ganze Rechnungsperiode');
        self::assertTrue($r['periods'][0]['complete']);
    }

    public function testABillOfAnotherMeterDoesNotCutThisMeter(): void
    {
        $this->seed();
        $other = $this->meters->create('strom', ['name' => 'Zweiter Zähler', 'installed_on' => '2024-01-01']);
        $this->bills()->create('strom', ['meter_id' => $other['id'], 'period_from' => '2024-04-15', 'period_to' => '2025-03-30',
            'invoice' => ['amount_eur' => 500.0]]);
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2026-03-15');
        self::assertSame(['2024-01-01..2024-12-31/anchor', '2025-01-01..2025-12-31/anchor', '2026-01-01..2026-12-31/anchor'],
            self::spans($r['periods']), 'nur Rechnungen dieses Zählers schneiden');
    }

    public function testAnImportedBillWithInvalidDatesIsIgnored(): void
    {
        $this->seed();
        // wie aus einem fremden Backup: Ende vor Beginn, fehlendes Ende, kein Datum
        $this->store->write('strom/bills.json', [
            ['id' => 'bill_x1', 'meter_id' => $this->meterId, 'period_from' => '2025-06-01', 'period_to' => '2025-01-31'],
            ['id' => 'bill_x2', 'meter_id' => $this->meterId, 'period_from' => '2025-02-01'],
            ['id' => 'bill_x3', 'meter_id' => $this->meterId, 'period_from' => 'gestern', 'period_to' => '2025-05-31'],
        ]);
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2026-03-15');
        self::assertSame(['2024-01-01..2024-12-31/anchor', '2025-01-01..2025-12-31/anchor', '2026-01-01..2026-12-31/anchor'],
            self::spans($r['periods']), 'ungültige Rechnungen zählen nicht als Periode');
    }

    public function testAMeterReplacementInsideAPeriodIsBridged(): void
    {
        // Zählertausch am 01.04.2025: alter Zähler endet bei 3750, neuer beginnt bei 0
        $this->setMeterDevices('strom', [
            ['id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
             'removed_on' => '2025-04-01', 'final_counter' => 3750.0, 'reason' => 'replacement'],
            ['id' => 'd2', 'serial' => null, 'installed_on' => '2025-04-01', 'initial_counter' => 0.0,
             'removed_on' => null, 'final_counter' => null, 'reason' => null],
        ], $this->meterId);
        $rows = [];
        for ($m = 0; $m <= 15; $m++) $rows[] = ['date' => date('Y-m-d', (int)strtotime("2024-01-01 +$m months")), 'counter' => 250.0 * $m, 'device_id' => 'd1'];
        for ($m = 1; $m <= 9; $m++) $rows[] = ['date' => date('Y-m-d', (int)strtotime("2025-04-01 +$m months")), 'counter' => 250.0 * $m, 'device_id' => 'd2'];
        $this->setReadings('strom', $this->meterId, $rows);
        $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 10.0]],
        ]);
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2026-03-15');
        $year2025 = array_values(array_filter($r['periods'], fn($p) => $p['from'] === '2025-01-01'))[0] ?? null;
        self::assertNotNull($year2025);
        self::assertEqualsWithDelta(12 * 250.0, $year2025['kwh'], 0.5, 'Menge über den Zählertausch hinweg (3 Monate alt + 9 Monate neu)');
    }

    public function testBillsGoFirstEvenIrregularAndTheAnchorFillsTheRest(): void
    {
        $this->seed();
        $b = $this->bill('2024-04-15', '2025-03-30');
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2026-03-15');
        self::assertSame([
            '2024-01-01..2024-04-14/anchor', '2024-04-15..2025-03-30/bill', '2025-03-31..2025-12-31/anchor', '2026-01-01..2026-12-31/anchor',
        ], self::spans($r['periods']));
        $p = $r['periods'][1];
        self::assertSame($b['id'], $p['bill_id']);
        self::assertTrue($p['complete']);
        self::assertNull($p['label'], 'kein ganzes Jahr: der Browser nennt die Daten');
        self::assertEquals(1000.0, $p['invoice']['amount_eur']);
        self::assertSame('03-31', $r['suggested_anchor'], 'Stichtag passt nicht zur Rechnung: Vorschlag = Tag nach ihrem Ende');
        self::assertFalse($r['periods'][2]['complete'], 'Rest bis zum Stichtag ist eine Teilperiode');
        self::assertTrue($r['periods'][3]['running']);
        self::assertSame('2026', $r['periods'][3]['label']);
    }

    public function testGapBetweenBillsAndOverlappingBillsAndTheRunningPeriodAfterTheLastBill(): void
    {
        $this->seed();
        $this->bill('2024-01-01', '2024-12-31');
        $this->bill('2024-06-01', '2024-09-30');   // Zwischenrechnung innerhalb: die frühere gilt
        $this->bill('2025-03-01', '2026-02-28');
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2026-06-01');
        self::assertSame([
            '2024-01-01..2024-12-31/bill', '2025-01-01..2025-02-28/anchor', '2025-03-01..2026-02-28/bill', '2026-03-01..2026-12-31/anchor',
        ], self::spans($r['periods']));
        self::assertSame('2024', $r['periods'][0]['label']);
        self::assertSame('2025/26', $r['periods'][2]['label']);
        self::assertTrue($r['periods'][3]['running'], 'die laufende Periode beginnt am Tag nach der letzten Rechnung');
        self::assertNull($r['periods'][3]['label']);
    }

    public function testOverlappingBillTheEarlierWinsAlsoForTheSuggestion(): void
    {
        $spans = BillingPeriodService::billSpans([
            ['id' => 'c', 'period_from' => '2025-01-01', 'period_to' => '2025-12-31'],
            ['id' => 'b', 'period_from' => '2024-06-01', 'period_to' => '2026-05-31'],
            ['id' => 'a', 'period_from' => '2024-01-01', 'period_to' => '2024-12-31'],
            ['id' => 'x', 'period_from' => 'kaputt', 'period_to' => '2025-12-31'],
        ]);
        self::assertSame(['a', 'c'], array_column($spans, 'id'), 'überlappende Rechnung fällt raus, ungültige auch');
        self::assertNull(BillingPeriodService::suggestedAnchor($spans, '01-01'), 'der Vorschlag folgt nicht der verworfenen Rechnung');
    }

    public function testLeapDayAnchorBehavesLike28February(): void
    {
        self::assertSame('02-28', BillingPeriodService::normalizeAnchor('02-29'));
        self::assertSame('01-01', BillingPeriodService::normalizeAnchor('13-45'));
        self::assertSame(['2024-02-28', '2025-02-27'], BillingPeriodService::gridAround('02-29', '2024-02-29'));
        $p = BillingPeriodService::build([], '02-29', '2024-01-10', '2026-03-01');
        self::assertSame(['2024-01-10..2024-02-27/anchor', '2024-02-28..2025-02-27/anchor', '2025-02-28..2026-02-27/anchor', '2026-02-28..2027-02-27/anchor'], self::spans($p));
        for ($i = 1; $i < count($p); $i++) {
            self::assertSame(date('Y-m-d', (int)strtotime($p[$i - 1]['until'] . ' +1 day')), $p[$i]['from'], 'lückenlos, ohne Überlappung');
        }
    }

    public function testBaselineStartsThePeriods(): void
    {
        $this->seed();
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['baseline_events'] = [['date' => '2025-06-01', 'label' => 'Wärmepumpe']];
        $this->store->write('strom/meters.json', $all);
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2026-03-15');
        self::assertSame('2025-06-01', $r['data_from']);
        self::assertSame(['2025-06-01..2025-12-31/anchor', '2026-01-01..2026-12-31/anchor'], self::spans($r['periods']));
    }

    public function testSumsLikeTheBillCheckWithAdvancesPerDayAndPartialMonths(): void
    {
        $this->seed();
        $this->bill('2025-04-15', '2025-10-14');
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), true, '2026-03-15');
        $p = array_values(array_filter($r['periods'], fn($x) => $x['source'] === 'bill'))[0];
        // 16 Apriltage + Mai–Sep + 14 Oktobertage
        $kwh = 250 * 16 / 30 + 5 * 250 + 250 * 14 / 31;
        self::assertSame(183, $p['days']);
        self::assertEqualsWithDelta($kwh, $p['kwh'], 0.1);
        self::assertEqualsWithDelta($kwh * 0.30, $p['energy_cost'], 0.05);
        self::assertEqualsWithDelta(10 * 16 / 30 + 50 + 10 * 14 / 31, $p['fixed_cost'], 0.02);
        self::assertEqualsWithDelta(90 * 16 / 30 + 450 + 90 * 14 / 31, $p['advances'], 0.02, 'Abschläge tagesanteilig, auch bei Stichtag mitten im Monat');
        self::assertEqualsWithDelta($p['cost'] - $p['advances'], $p['balance'], 0.01);
        $bc = $this->consumption->billBreakdown('strom', $this->meters->get('strom', $this->meterId), '2025-04-15', '2025-10-15');
        self::assertEqualsWithDelta($bc['totals']['total'], $p['cost'], 0.01, 'eine Rechnung mit „Rechnung prüfen“');
        $months = $p['months'];
        self::assertCount(7, $months);
        self::assertSame(['2025-04', 16], [$months[0]['ym'], $months[0]['days']], 'erster Monat als Teilmonat');
        self::assertSame(['2025-10', 14], [$months[6]['ym'], $months[6]['days']], 'letzter Monat als Teilmonat');
        self::assertEqualsWithDelta($p['kwh'], array_sum(array_column($months, 'kwh')), 0.5);
        self::assertEqualsWithDelta($p['advances'], array_sum(array_column($months, 'advance_eur')), 0.05);

        // CO₂ der Teilmonate tagesanteilig wie die Menge (v3.6.0: bisher ohne eigenen Test)
        $co2 = [];
        foreach ($this->consumption->forMeter('strom', $this->meters->get('strom', $this->meterId)) as $row) {
            $co2[sprintf('%04d-%02d', (int)$row['year'], (int)$row['month'])] = (float)($row['co2_kg'] ?? 0);
        }
        $want = $co2['2025-04'] * 16 / 30 + $co2['2025-05'] + $co2['2025-06'] + $co2['2025-07']
            + $co2['2025-08'] + $co2['2025-09'] + $co2['2025-10'] * 14 / 31;
        self::assertGreaterThan(0.0, $want, 'das Beispiel braucht einen CO₂-Faktor');
        self::assertEqualsWithDelta($want, $p['co2_kg'], 0.2, 'CO₂ der Teilmonate tagesanteilig');
    }

    public function testSpecialPaymentsExtraAdvanceCountsRefundStandsBeside(): void
    {
        $this->seed();
        $c = $this->contracts->list('strom', $this->meterId)[0];
        $this->contracts->update('strom', (string)$c['id'], ['special_payments' => [
            ['date' => '2025-05-10', 'kind' => 'abschlagszahlung', 'amount_eur' => 50.0],
            ['date' => '2025-02-10', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 30.0],
        ]]);
        $r = $this->service()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2026-03-15');
        $p = array_values(array_filter($r['periods'], fn($x) => $x['label'] === '2025'))[0];
        self::assertSame(['extra_advances' => 50.0, 'refunds' => 30.0, 'surcharges' => 0.0], $p['special']);
        self::assertEqualsWithDelta($p['cost'] - $p['advances'] - 50.0, $p['balance'], 0.01, 'Rückzahlung gleicht eine frühere Abrechnung aus');
    }

    public function testUtilityWideLeavesOutSubmetersAndAGroupSumsItsMembers(): void
    {
        $this->seed();
        $all = $this->store->read('strom/meters.json', []);
        $sub = $all[0];
        $sub['id'] = 'm_sub'; $sub['name'] = 'Wallbox'; $sub['parent_meter_id'] = $this->meterId; $sub['meter_group_id'] = null;
        $all[0]['meter_group_id'] = 'g_x';
        $two = $all[0]; $two['id'] = 'm_two'; $two['name'] = 'Zwei';
        $this->store->write('strom/meters.json', [$all[0], $sub, $two]);
        $this->store->write('strom/meter_groups.json', [['id' => 'g_x', 'name' => 'Haus', 'created_at' => '2024-01-01']]);
        $rows = $this->store->read('strom/readings.json', []);
        foreach ($rows as $i => $row) {
            $rows[] = ['id' => "s$i", 'meter_id' => 'm_sub'] + $row;
            $rows[] = ['id' => "t$i", 'meter_id' => 'm_two'] + $row;
        }
        $this->store->write('strom/readings.json', $rows);
        $svc = $this->service();
        $year = fn(array $r) => array_values(array_filter($r['periods'], fn($x) => $x['label'] === '2025'))[0]['kwh'];
        self::assertEqualsWithDelta(6000.0, $year($svc->forUtility('strom', false, '2026-03-15')), 1.0, 'Hauptzähler + zweiter Zähler, ohne Subzähler');
        self::assertEqualsWithDelta(6000.0, $year($svc->forTarget('strom', $this->meters->target('strom', 'g_x'), false, '2026-03-15')), 1.0, 'Gruppe = Mitglieder zusammen');
        self::assertEqualsWithDelta(3000.0, $year($svc->forTarget('strom', $this->meters->get('strom', 'm_sub'), false, '2026-03-15')), 1.0, 'der Subzähler für sich');
    }

    public function testOnlyUtilitiesWithBillCheck(): void
    {
        $this->expectException(LocalizedException::class);
        $this->service()->forUtility('heizoel');
    }

    public function testCsvOneRowPerBillingPeriod(): void
    {
        $this->seed();
        $this->settings->set(['billing_cycle_anchor_strom' => '09-01']);
        $csv = new CsvExportService($this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $csv->attachBillingPeriods($this->service());
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($csv->billingPeriods('strom'), "\u{FEFF}")))));
        self::assertStringStartsWith('Von;Bis;Grundlage;Tage;', $lines[0]);
        self::assertStringStartsWith('2024-01-01;2024-08-31;Stichtag;244;', $lines[1]);
        self::assertStringStartsWith('2024-09-01;2025-08-31;Stichtag;365;3000;', $lines[2]);
        self::assertStringEndsWith(';ja', $lines[2]);
        self::assertSame('energietracker-strom-abrechnungsjahre-' . date('Y-m-d') . '.csv', $csv->filename('billing', 'strom'));
    }

    public function testReportAppendixBillingYearsEndingInTheReportYear(): void
    {
        $this->seed();
        $this->settings->set(['billing_cycle_anchor_strom' => '09-01', 'active_utilities' => ['strom']]);
        $model = $this->reportServices()['model']->build(2025, ['billing' => true, 'today' => '2026-03-15']);
        self::assertTrue($model['options']['billing']);
        $tbl = $model['billing'][0];
        self::assertSame('strom', $tbl['utility']);
        self::assertCount(1, $tbl['rows'], 'nur die Periode, die 2025 endet (Rechnungsjahr = Jahr des Periodenendes)');
        self::assertSame('2024/25', $tbl['rows'][0][0]);
        $plain = $this->reportServices()['model']->build(2025, ['today' => '2026-03-15']);
        self::assertArrayNotHasKey('billing', $plain, 'ohne Option kein Anhang');
    }
}
