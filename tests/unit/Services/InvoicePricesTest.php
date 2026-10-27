<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\Inbox\InvoicePrices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v3.9.0 (F1034) — Bestandteile eines Arbeitspreises (Gas, Strom, Fernwärme):
 * Weist die Rechnung am selben Tag genau einen Arbeitspreis und daneben
 * Energiesteuer, CO₂-Preis oder einen Aufschlag je kWh aus, ist der Preis im
 * Vertrag ihre Summe. Ohne diese Angabe bleibt jede Zeile für sich wie bis v3.8
 * (nie eine Summe aus Zeilen, die vielleicht zwei Zeitabschnitte sind).
 */
#[CoversClass(InvoicePrices::class)]
final class InvoicePricesTest extends TestCase
{
    /** @param list<array<string,mixed>> $items */
    private static function fields(array $items): array
    {
        return ['working_prices' => ['status' => 'ok', 'items' => array_map(fn($v) => ['value' => $v, 'quote' => null, 'status' => 'ok', 'reasons' => []], $items)]];
    }

    public function testEnergyTaxAndCo2BecomeOneWorkingPrice(): void
    {
        // 8,50 + 0,55 + 0,95 = 10,00 ct/kWh ab 01.01.; ab 01.07. nur der Arbeitspreis (eine Zeile)
        $g = InvoicePrices::workingGroups(self::fields([
            ['from' => '2025-01-01', 'ct' => 8.5, 'part' => 'energy', 'label' => 'Arbeitspreis'],
            ['from' => '2025-01-01', 'ct' => 0.55, 'part' => 'energy_tax', 'label' => 'Energiesteuer'],
            ['from' => '2025-01-01', 'ct' => 0.95, 'part' => 'co2', 'label' => 'CO2-Preis'],
            ['from' => '2025-07-01', 'ct' => 9.0, 'part' => 'energy', 'label' => 'Arbeitspreis'],
        ]));
        self::assertSame([['2025-01-01', 10.0, true, [0, 1, 2]], ['2025-07-01', 9.0, false, [3]]],
            array_map(fn($x) => [$x['from'], $x['value'], $x['merged'], $x['items']], $g));
        self::assertSame(['Arbeitspreis', 'Energiesteuer', 'CO2-Preis'], array_column($g[0]['parts'], 'label'), 'Aufschlüsselung im Vorschlag');
    }

    /** Ein unsicher gelesener Termin zählt nicht; dann ist der Rest kein Rhythmus mehr (nur Termine). */
    public function testAnUnsureDueDateDoesNotCount(): void
    {
        $date = fn(string $d, string $status) => ['value' => ['date' => $d], 'quote' => $d, 'status' => $status, 'reasons' => $status === 'check' ? ['quote_not_found'] : []];
        $entry = ['extraction' => ['fields' => [
            'advance_new' => ['value' => 165.0, 'quote' => '165,00', 'status' => 'ok', 'reasons' => []],
            'advance_new_dates' => ['value' => null, 'status' => 'check', 'items' => [$date('2026-05-15', 'ok'), $date('2026-07-15', 'check'), $date('2026-09-15', 'ok')]],
        ]]];
        self::assertSame(['from' => '2026-05-15', 'amount_eur' => 165.0, 'due_dates' => ['2026-05-15', '2026-09-15']], InvoicePrices::advancePlan($entry));
        $entry['extraction']['fields']['advance_new_dates']['items'][1]['status'] = 'ok';
        self::assertSame(['from' => '2026-05-15', 'amount_eur' => 165.0, 'every_months' => 2, 'due_dates' => ['2026-05-15', '2026-07-15', '2026-09-15']],
            InvoicePrices::advancePlan($entry), 'alle drei sicher: alle 2 Monate');
    }

    /**
     * Skeptiker H2 — die laut Rechnung gezahlten Abschläge als Planzeile: gleich hohe datierte Zahlungen an ihren
     * Terminen (sechs × 133,33 € alle zwei Monate = 800 €), sonst die Summe je Monat (800 € ÷ 12, auf vier Stellen).
     */
    public function testThePaidAdvancesOfTheBillBecomeAPlanRow(): void
    {
        $paid = fn(array $items, $total) => ['extraction' => ['fields' => [
            'advances_paid' => ['status' => 'ok', 'items' => array_map(fn($x) => ['value' => ['amount' => $x[0], 'date' => $x[1]], 'status' => 'ok', 'reasons' => []], $items)],
            'advances_total' => ['value' => $total, 'status' => 'ok', 'reasons' => []]]]];
        $dates = ['2025-04-15', '2025-06-15', '2025-08-15', '2025-10-15', '2025-12-15', '2026-02-15'];
        $e = $paid(array_map(fn($d) => [133.33, $d], $dates), 799.98);
        self::assertSame(['from' => '2025-04-01', 'amount_eur' => 133.33, 'every_months' => 2, 'due_dates' => $dates, 'total' => 799.98],
            InvoicePrices::paidPlan($e, '2025-04-01', '2026-03-31'));
        self::assertSame(['from' => '2025-04-01', 'amount_eur' => 66.6667, 'total' => 800.0],
            InvoicePrices::paidPlan($paid([[800.0, null]], 800.0), '2025-04-01', '2026-03-31'), 'nur die Summe: je Monat');
        self::assertSame(['from' => '2025-04-01', 'amount_eur' => 66.6667, 'total' => 800.0],
            InvoicePrices::paidPlan($paid([[400.0, '2025-06-15'], [400.0, '2025-12-15'], [0.0, '2026-02-15']], 800.0), '2025-04-01', '2026-03-31'),
            'ungleiche Beträge: je Monat');
        self::assertNull(InvoicePrices::paidPlan($paid([], null), '2025-04-01', '2026-03-31'));
    }

    /** Rhythmus tolerant in den sieben Sprachen der App; Unbekanntes wird nicht geraten. */
    public function testTheRhythmIsReadTolerantlyAndNeverGuessed(): void
    {
        $cases = [
            1 => [1, '1', 'monthly', 'Monatlich', 'jeden Monat', 'mensuel', 'tous les mois', 'mensile', 'ogni mese', 'mensual', 'cada mes', 'mensal', 'maandelijks'],
            2 => [2, 2.0, '2', 'bimonthly', 'every 2 months', 'every two months', 'every other month', 'zweimonatlich', 'alle 2 Monate', 'alle  zwei Monate',
                  'bimestriel', 'tous les deux mois', 'bimestrale', 'ogni 2 mesi', 'bimestral', 'cada dos meses', 'a cada 2 meses', 'de 2 em 2 meses',
                  'tweemaandelijks', 'om de 2 maanden'],
            3 => [3, 'quarterly', 'every 3 months', 'vierteljährlich', 'quartalsweise', 'alle drei Monate', 'trimestriel', 'tous les 3 mois', 'trimestrale',
                  'ogni tre mesi', 'trimestral', 'cada 3 meses', 'per kwartaal', 'driemaandelijks'],
        ];
        foreach ($cases as $n => $list) foreach ($list as $v) self::assertSame($n, InvoicePrices::everyOf($v), json_encode($v));
        foreach ([null, '', 'weekly', 'jährlich', 'alle 4 Monate', 'bimensuel', 'bimensal', 4, 1.5, 'zwei', ['bimonthly'], true] as $v) {
            self::assertNull(InvoicePrices::everyOf($v), json_encode($v));
        }
    }

    public function testTheRhythmOfDatesAllowsWeekendsAndMonthEnds(): void
    {
        self::assertSame(2, InvoicePrices::rhythmOfDates(['2026-10-16', '2026-12-16', '2027-02-15', '2027-04-16', '2027-06-16']), 'Wochenende');
        self::assertSame(1, InvoicePrices::rhythmOfDates(['2027-01-31', '2027-02-28', '2027-03-31']), 'Monatsletzte');
        self::assertSame(3, InvoicePrices::rhythmOfDates(['2026-01-15', '2026-04-15']), 'zwei Termine genügen');
        self::assertNull(InvoicePrices::rhythmOfDates(['2026-10-16']), 'einer sagt keinen Rhythmus');
        self::assertNull(InvoicePrices::rhythmOfDates(['2026-10-16', '2026-11-16', '2027-02-16']), 'ungleichmäßig');
        self::assertNull(InvoicePrices::rhythmOfDates(['2026-10-16', '2026-12-25']), 'mehr als drei Tage daneben');
        self::assertNull(InvoicePrices::rhythmOfDates(['2026-01-15', '2026-07-15']), 'halbjährlich: nicht 1, 2 oder 3');
    }

    public function testWithoutPartsOrWithTwoEnergyRowsNothingIsAddedUp(): void
    {
        $none = InvoicePrices::workingGroups(self::fields([
            ['from' => '2025-01-01', 'ct' => 8.5], ['from' => '2025-01-01', 'ct' => 0.55, 'part' => 'energy_tax'],
        ]));
        self::assertSame([8.5, 0.55], array_column($none, 'value'), 'ein Bestandteil ohne Angabe: keine Summe');
        $two = InvoicePrices::workingGroups(self::fields([
            ['from' => '2025-01-01', 'ct' => 8.5, 'part' => 'energy'], ['from' => '2025-01-01', 'ct' => 9.5, 'part' => 'energy'],
        ]));
        self::assertSame([false, false], array_column($two, 'merged'), 'zwei Arbeitspreise am selben Tag sind kein Preis');
        $dup = InvoicePrices::workingGroups(self::fields([
            ['from' => '2025-01-01', 'ct' => 8.5, 'part' => 'energy'], ['from' => '2025-01-01', 'ct' => 0.55, 'part' => 'energy_tax'],
            ['from' => '2025-01-01', 'ct' => 0.55, 'part' => 'energy_tax'],
        ]));
        self::assertCount(3, $dup, 'derselbe Bestandteil zweimal: nicht zusammengezählt');
    }
}
