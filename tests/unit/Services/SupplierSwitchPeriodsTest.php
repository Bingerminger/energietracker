<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillExpectedService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\PeriodService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.6.0 (F1030, Nachtrag) — Abrechnungsperioden beim Lieferantenwechsel:
 * Endet ein Vertrag und beginnt der eines anderen Versorgers, endet die
 * Periode am letzten Tag beim alten, die nächste beginnt mit dem neuen —
 * auch ohne erfasste Schlussrechnung. Erfasste Rechnungen gehen vor; eine
 * Verlängerung oder ein Folgevertrag desselben Versorgers teilt nicht.
 *
 * Daten: Strom ab 2024-01-01, 250 kWh je Monat, Ablesung am Monatsersten.
 * Alter Vertrag 30 ct, 10 €/Monat, Abschlag 90 €; neuer 40 ct, 12 €/Monat,
 * Abschlag 120 € (Namen erfunden).
 */
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(ContractService::class)]
final class SupplierSwitchPeriodsTest extends ServiceTestCase
{
    private const OLD = 'Stadtwerke Beispielstadt';
    private const NEW = 'Nordlicht Energie';
    private const TODAY = '2026-03-15';

    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [];
        for ($m = 0; $m <= 24; $m++) $rows[] = ['date' => date('Y-m-d', (int)strtotime("2024-01-01 +$m months")), 'counter' => 250.0 * $m, 'device_id' => 'd1'];
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function service(): BillingPeriodService
    {
        return new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills());
    }

    /** Alter Vertrag bis `$end`, neuer ab `$start`; `$renews` = auto_renews des alten. */
    private function contracts(?string $end, string $start, string $provider = self::NEW, ?bool $renews = false, array $target = []): void
    {
        $target = $target ?: ['meter_id' => $this->meterId];
        $this->contracts->create('strom', $target + [
            'provider' => self::OLD, 'tariff_name' => 'Basis', 'start' => '2024-01-01', 'end' => $end, 'auto_renews' => $renews,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 90.0]],
        ]);
        $this->contracts->create('strom', $target + [
            'provider' => $provider, 'tariff_name' => 'Wechsel', 'start' => $start,
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 40.0]],
            'base_prices' => [['from' => $start, 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 120.0]],
        ]);
    }

    private function periods(?array $target = null, string $today = self::TODAY): array
    {
        return $this->service()->forTarget('strom', $target ?? $this->meters->get('strom', $this->meterId), true, $today)['periods'];
    }

    /** @return list<string> „von..bis“ je Periode, `+S` am Wechsel, `/teil` für Teilperioden */
    private static function spans(array $periods): array
    {
        return array_map(fn($p) => $p['from'] . '..' . $p['until'] . (!empty($p['ends_at_switch']) ? '+S' : '')
            . ($p['complete'] || $p['running'] ? '' : '/teil'), $periods);
    }

    private static function find(array $periods, string $from): array
    {
        return array_values(array_filter($periods, fn($p) => $p['from'] === $from))[0] ?? self::fail("Periode ab $from fehlt");
    }

    public function testASwitchAtTheStartOfAMonthEndsThePeriodAtTheContractEnd(): void
    {
        $this->contracts('2025-09-30', '2025-10-01');
        $p = $this->periods();
        self::assertSame(['2024-01-01..2024-12-31', '2025-01-01..2025-09-30+S', '2025-10-01..2025-12-31', '2026-01-01..2026-12-31'], self::spans($p));
        $old = self::find($p, '2025-01-01');
        $new = self::find($p, '2025-10-01');
        self::assertTrue($old['complete'] && $new['complete'], 'beide Teile sind ganze Abrechnungen (Schluss- bzw. erste Rechnung)');
        self::assertNull($old['label'], 'kein ganzes Jahr: der Browser nennt die Daten');
        self::assertEqualsWithDelta(9 * 250.0, $old['kwh'], 0.5);
        self::assertEqualsWithDelta(9 * 250 * 0.30 + 9 * 10, $old['cost'], 0.05, 'nur zu den Preisen des alten Vertrags');
        self::assertEqualsWithDelta(9 * 90.0, $old['advances'], 0.01);
        self::assertEqualsWithDelta(3 * 250 * 0.40 + 3 * 12, $new['cost'], 0.05);
        self::assertEqualsWithDelta(3 * 120.0, $new['advances'], 0.01);
        // „Rechnung prüfen“ über denselben Zeitraum rechnet dasselbe
        $bc = $this->consumption->billBreakdown('strom', $this->meters->get('strom', $this->meterId), '2025-01-01', '2025-10-01');
        self::assertEqualsWithDelta($bc['totals']['total'], $old['cost'], 0.01);
    }

    public function testAMidMonthSwitchSplitsTheMonthAndItsAdvanceByDays(): void
    {
        $this->contracts('2025-10-14', '2025-10-15');
        $p = $this->periods();
        self::assertSame(['2024-01-01..2024-12-31', '2025-01-01..2025-10-14+S', '2025-10-15..2025-12-31', '2026-01-01..2026-12-31'], self::spans($p));
        $old = self::find($p, '2025-01-01');
        $new = self::find($p, '2025-10-15');
        // je Vertrag nur seine Tage: 14 Oktobertage alt, 17 neu
        self::assertEqualsWithDelta(9 * 90 + 90 * 14 / 31, $old['advances'], 0.01, 'kein Teil des neuen Abschlags in der Schlussperiode');
        self::assertEqualsWithDelta(120 * 17 / 31 + 2 * 120, $new['advances'], 0.01);
        self::assertEqualsWithDelta(9 * 10 + 10 * 14 / 31 + (9 * 250 + 250 * 14 / 31) * 0.30, $old['cost'], 0.05);
        self::assertSame(['2025-10', 14], [end($old['months'])['ym'], end($old['months'])['days']], 'Oktober als Teilmonat');
        self::assertEqualsWithDelta($old['advances'], array_sum(array_column($old['months'], 'advance_eur')), 0.02);
        // „Rechnung prüfen“ mit der Schlussrechnung: dieselben Abschläge (bis v3.5 das Monatsmittel beider Verträge)
        $bill = $this->bills()->create('strom', ['meter_id' => $this->meterId, 'kind' => 'final', 'period_from' => '2025-01-01', 'period_to' => '2025-10-14',
            'invoice' => ['amount_eur' => 800.0, 'advances_paid_eur' => 850.0]]);
        $ours = $this->bills()->compare('strom', (string)$bill['id'])['ours'];
        self::assertEqualsWithDelta($old['advances'], (float)$ours['advances'], 0.011);
        self::assertEqualsWithDelta($old['cost'], (float)$ours['total'], 0.011);
    }

    public function testASwitchInsideARecordedBillDoesNotCutIt(): void
    {
        $this->contracts('2025-09-30', '2025-10-01');
        $b = $this->bills()->create('strom', ['meter_id' => $this->meterId, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'invoice' => ['amount_eur' => 1000.0, 'advances_paid_eur' => 1170.0]]);
        $p = $this->periods();
        self::assertSame(['2024-01-01..2024-12-31', '2025-01-01..2025-12-31', '2026-01-01..2026-12-31'], self::spans($p), 'die Rechnung geht vor');
        self::assertSame($b['id'], self::find($p, '2025-01-01')['bill_id']);
        // eine Schlussrechnung bis zum Wechsel: Periode wie auf der Rechnung, am Wechsel
        $this->store->write('strom/bills.json', []);
        $this->bills()->create('strom', ['meter_id' => $this->meterId, 'kind' => 'final', 'period_from' => '2025-01-01', 'period_to' => '2025-09-30',
            'invoice' => ['amount_eur' => 760.0]]);
        $p = $this->periods();
        self::assertSame('bill', self::find($p, '2025-01-01')['source']);
        self::assertTrue(self::find($p, '2025-01-01')['ends_at_switch']);
        self::assertSame('anchor', self::find($p, '2025-10-01')['source']);
    }

    public function testAGapBetweenTheContracts(): void
    {
        // der alte verlängert sich (Standard): Er läuft bis zum Tag vor dem neuen
        $this->contracts('2025-09-30', '2025-11-01', self::NEW, null);
        self::assertSame(['2024-01-01..2024-12-31', '2025-01-01..2025-10-31+S', '2025-11-01..2025-12-31', '2026-01-01..2026-12-31'], self::spans($this->periods()));
        // gekündigt, ohne Verlängerung: Die Lücke ohne Vertrag ist eine eigene Teilperiode
        $this->store->write('strom/contracts.json', []);
        $this->contracts('2025-09-30', '2025-11-01', self::NEW, false);
        $p = $this->periods();
        self::assertSame(['2024-01-01..2024-12-31', '2025-01-01..2025-09-30+S', '2025-10-01..2025-10-31/teil', '2025-11-01..2025-12-31', '2026-01-01..2026-12-31'], self::spans($p));
        self::assertEqualsWithDelta(9 * 90.0, self::find($p, '2025-01-01')['advances'], 0.01);
        self::assertSame(0.0, self::find($p, '2025-10-01')['advances'], 'in der Lücke gilt kein Vertrag');
        // Lücke bis zum Stichtag: endet mit der Stichtag-Periode, bleibt aber eine Teilperiode
        $this->store->write('strom/contracts.json', []);
        $this->contracts('2025-09-30', '2026-01-01', self::NEW, false);
        self::assertSame(['2024-01-01..2024-12-31', '2025-01-01..2025-09-30+S', '2025-10-01..2025-12-31/teil', '2026-01-01..2026-12-31'], self::spans($this->periods()));
    }

    public function testARenewalOrAFollowUpOfTheSameSupplierDoesNotCut(): void
    {
        $plain = ['2024-01-01..2024-12-31', '2025-01-01..2025-12-31', '2026-01-01..2026-12-31'];
        // derselbe Versorger, anders geschrieben, lückenlos — mit und ohne Verlängerung
        foreach ([true, false, null] as $renews) {
            $this->store->write('strom/contracts.json', []);
            $this->contracts('2025-09-30', '2025-10-01', '  stadtwerke BEISPIELSTADT ', $renews);
            self::assertSame($plain, self::spans($this->periods()), 'Folgevertrag desselben Versorgers: ' . var_export($renews, true));
        }
        // verlängert sich, desselben Versorgers, mit Lücke: läuft bis zum Folgevertrag weiter
        $this->store->write('strom/contracts.json', []);
        $this->contracts('2025-09-30', '2025-11-01', self::OLD, true);
        self::assertSame($plain, self::spans($this->periods()));
        // ein weiterlaufender Vertrag ohne Folgevertrag teilt nichts
        $this->store->write('strom/contracts.json', []);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => self::OLD, 'start' => '2024-01-01', 'end' => '2025-06-30',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]]]);
        self::assertSame($plain, self::spans($this->periods()));
        // derselbe Versorger nach einer Lücke ohne Vertrag: neu abgeschlossen, also geteilt
        $this->store->write('strom/contracts.json', []);
        $this->contracts('2025-09-30', '2025-11-01', self::OLD, false);
        self::assertContains('2025-01-01..2025-09-30+S', self::spans($this->periods()));
    }

    public function testOverlappingContractsSwitchWhenTheNewOneBegins(): void
    {
        // neuer Vertrag vor dem Ende des alten nachgetragen: ab seinem Beginn gilt er (findActiveForDate)
        $this->contracts('2025-12-31', '2025-10-01');
        self::assertContains('2025-01-01..2025-09-30+S', self::spans($this->periods()));
        // alter ohne Ende
        $this->store->write('strom/contracts.json', []);
        $this->contracts(null, '2025-10-01');
        self::assertContains('2025-01-01..2025-09-30+S', self::spans($this->periods()));
    }

    public function testTheRunningPeriodEndsAtAFutureSwitch(): void
    {
        $p = BillingPeriodService::build([], '01-01', '2024-01-01', '2025-08-20', [['until' => '2025-09-30', 'next' => '2025-10-01']]);
        self::assertSame(['2024-01-01..2024-12-31', '2025-01-01..2025-09-30+S'], self::spans($p), 'nach dem Wechsel noch keine Periode');
        self::assertTrue($p[1]['running']);
        // ohne Wechsel wie bisher
        self::assertSame(['2024-01-01..2024-12-31', '2025-01-01..2025-12-31'], self::spans(BillingPeriodService::build([], '01-01', '2024-01-01', '2025-08-20')));
    }

    public function testTheAnchorOfTheNewContractStartsTheNextBillingYear(): void
    {
        // der neue Versorger rechnet ab Vertragsbeginn im Jahresrhythmus: Stichtag = sein Beginn
        $this->settings->set(['billing_cycle_anchor_strom' => '10-15']);
        $this->contracts('2025-10-14', '2025-10-15');
        $p = $this->periods();
        self::assertSame(['2024-01-01..2024-10-14/teil', '2024-10-15..2025-10-14+S', '2025-10-15..2026-10-14'], self::spans($p), 'kein Stummel am Wechsel');
        self::assertSame('2024/25', $p[1]['label']);
        self::assertSame('2025/26', $p[2]['label']);
        self::assertTrue($p[2]['running']);
        // Stichtag nach dem Wechsel im selben Monat: ein kurzer erster Abschnitt des neuen Vertrags
        $this->settings->set(['billing_cycle_anchor_strom' => '11-01']);
        self::assertContains('2025-10-15..2025-10-31', self::spans($this->periods()));
    }

    public function testAGroupContractSwitchCutsTheGroupAndItsMembers(): void
    {
        $nt = (string)$this->meters->create('strom', ['name' => 'NT', 'installed_on' => '2024-01-01'])['id'];
        $gid = (string)$this->meters->createGroup('strom', ['name' => 'Doppeltarif'])['id'];
        foreach ([$this->meterId, $nt] as $m) $this->meters->update('strom', $m, ['meter_group_id' => $gid]);
        $all = $this->store->read('strom/readings.json', []);
        $dev = $this->meters->get('strom', $nt)['devices'][0]['id'];
        for ($m = 0; $m <= 24; $m++) $all[] = ['id' => "r_nt_$m", 'meter_id' => $nt, 'device_id' => $dev, 'date' => date('Y-m-d', (int)strtotime("2024-01-01 +$m months")),
            'counter' => 100.0 * $m, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        $this->store->write('strom/readings.json', $all);
        $this->contracts('2025-06-30', '2025-07-01', self::NEW, false, ['meter_group_id' => $gid]);
        $want = ['2024-01-01..2024-12-31', '2025-01-01..2025-06-30+S', '2025-07-01..2025-12-31', '2026-01-01..2026-12-31'];
        $group = $this->meters->groupTarget('strom', $gid);
        $g = $this->periods($group);
        self::assertSame($want, self::spans($g), 'Gruppe');
        self::assertSame($want, self::spans($this->periods($this->meters->get('strom', $nt))), 'Mitglied');
        self::assertSame($want, self::spans($this->service()->forUtility('strom', false, self::TODAY)['periods']), 'ganze Art');
        self::assertEqualsWithDelta(6 * 350.0, self::find($g, '2025-01-01')['kwh'], 0.5);
        self::assertEqualsWithDelta(6 * 90.0, self::find($g, '2025-01-01')['advances'], 0.01, 'Abschlag des Gruppenvertrags einmal');
    }

    /** Die ganze Art: Der Wechsel eines Zählers schneidet ihre Perioden (wie seine Rechnungen), der andere Zähler bleibt ganz. */
    public function testTheWholeUtilityIsCutByTheSwitchOfAnyMeter(): void
    {
        $wp = (string)$this->meters->create('strom', ['name' => 'Wärmepumpe', 'installed_on' => '2024-01-01'])['id'];
        $all = $this->store->read('strom/readings.json', []);
        $dev = $this->meters->get('strom', $wp)['devices'][0]['id'];
        for ($m = 0; $m <= 24; $m++) $all[] = ['id' => "r_wp_$m", 'meter_id' => $wp, 'device_id' => $dev, 'date' => date('Y-m-d', (int)strtotime("2024-01-01 +$m months")),
            'counter' => 300.0 * $m, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        $this->store->write('strom/readings.json', $all);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => self::OLD, 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]]]);
        $this->contracts('2025-06-30', '2025-07-01', self::NEW, false, ['meter_id' => $wp]);
        self::assertContains('2025-01-01..2025-06-30+S', self::spans($this->service()->forUtility('strom', false, self::TODAY)['periods']), 'ganze Art');
        self::assertContains('2025-01-01..2025-06-30+S', self::spans($this->periods($this->meters->get('strom', $wp))), 'Wärmepumpe');
        self::assertContains('2025-01-01..2025-12-31', self::spans($this->periods()), 'der Haushaltszähler wechselt nicht');
    }

    /** „Abrechnung erwartet“ erwartet die Schlussrechnung genau für die Periode, die am Wechsel endet. */
    public function testTheExpectedFinalBillIsThePeriodThatEndsAtTheSwitch(): void
    {
        $this->contracts('2025-09-30', '2025-10-01');
        $expected = new BillExpectedService($this->settings, $this->meters, $this->contracts, $this->readings,
            new PeriodService($this->store, $this->meters, $this->i18n), $this->bills());
        $final = array_values(array_filter($expected->expected('2025-11-20'), fn($x) => $x['final']))[0] ?? self::fail('keine Schlussrechnung erwartet');
        $p = array_values(array_filter($this->periods(null, '2025-11-20'), fn($x) => !empty($x['ends_at_switch'])))[0];
        self::assertSame([$final['from'], $final['until']], [$p['from'], $p['until']]);
        // dieselbe Regel: Folgevertrag desselben Versorgers (anders geschrieben) — keine Schlussrechnung, keine Teilung
        $this->store->write('strom/contracts.json', []);
        $this->contracts('2025-09-30', '2025-10-01', 'STADTWERKE Beispielstadt', false);
        self::assertSame([], array_values(array_filter($expected->expected('2025-11-20'), fn($x) => $x['final'])), 'keine Schlussrechnung erwartet');
        self::assertSame([], array_values(array_filter($this->periods(null, '2025-11-20'), fn($x) => !empty($x['ends_at_switch']))));
    }
}
