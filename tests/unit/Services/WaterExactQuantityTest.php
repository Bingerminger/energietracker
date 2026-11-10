<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Wasser rechnet Kosten und Saldo aus der Menge, die die Stände ergeben, nicht aus der auf 0,1 m³
 * gerundeten Monatszeile. Bis dahin wurde die Monatsmenge vor dem Bepreisen gerundet: Bei Tagesraten, die nicht auf
 * 0,1 m³ aufgehen, verlor jeder Monat bis 0,05 m³, und die Saldo-Karte nannte einen anderen Cent als das
 * Abrechnungsjahr. Gerundet wird jetzt nur die Anzeige.
 *
 * Erfundener Haushalt: Hauptzähler mit Ständen am Monatsersten, Gartenzähler (Subzähler) als Abzug vom
 * Schmutzwasser. Trinkwasser 150 ct/m³, Schmutzwasser 250 ct/m³, kein Grundpreis, Abschlag 40 €/Monat.
 *   Hauptzähler  0 / 11,333 / 22,667 / 34,000 m³ am 01.01./01.02./01.03./01.04.2026 → 11,333 + 11,334 + 11,333 = 34,000 m³
 *   Garten       0 /  0,444 /  1,111 /  1,777 m³                                   →  0,444 +  0,667 +  0,666 =  1,777 m³
 * Von Hand:
 *   Trinkwasser  34,000 × 1,50 = 51,00 €
 *   Schmutzwasser (34,000 − 1,777) = 32,223 × 2,50 = 80,5575 €
 *   Summe 131,5575 € → 131,56 €; je Monat: Januar 17,00 (16,9995) + 27,22 (10,889 × 2,5 = 27,2225) = 44,22 €,
 *   Februar 17,00 (17,001) + 26,67 (10,667 × 2,5 = 26,6675) = 43,67 €, März 17,00 + 26,67 = 43,67 € → 131,56 €
 *   Abschläge 3 × 40 = 120,00 € → Saldo 11,56 € Nachzahlung
 * Mit gerundeten Monatsmengen (11,3 je Monat, Garten 0,4 / 0,7 / 0,7) wären es 33,9 × 1,5 + 32,1 × 2,5 = 131,10 €.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillingPeriodService::class)]
final class WaterExactQuantityTest extends ServiceTestCase
{
    private const AS_OF = '2026-04-10';

    protected function setUp(): void
    {
        parent::setUp();
        $dev = fn(string $id) => [['id' => $id, 'serial' => null, 'installed_on' => '2025-12-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $this->store->write('wasser/meters.json', [
            ['id' => 'm_haus', 'name' => 'Haus', 'icon' => '', 'created_at' => '2025-12-01', 'active' => true,
             'notes' => '', 'devices' => $dev('d_haus'), 'parent_meter_id' => null],
            ['id' => 'm_beet', 'name' => 'Beet', 'icon' => '', 'created_at' => '2025-12-01', 'active' => true,
             'notes' => '', 'devices' => $dev('d_beet'), 'parent_meter_id' => 'm_haus', 'role' => 'garden'],
        ]);
        $rows = [];
        foreach ([['2026-01-01', 0.0, 0.0], ['2026-02-01', 11.333, 0.444], ['2026-03-01', 22.667, 1.111], ['2026-04-01', 34.0, 1.777]] as $i => [$d, $h, $g]) {
            $rows[] = ['id' => "r_h$i", 'meter_id' => 'm_haus', 'device_id' => 'd_haus', 'date' => $d, 'counter' => $h,
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            $rows[] = ['id' => "r_g$i", 'meter_id' => 'm_beet', 'device_id' => 'd_beet', 'date' => $d, 'counter' => $g,
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write('wasser/readings.json', $rows);
        $this->contracts->create('wasser', [
            'meter_id' => 'm_haus', 'provider' => 'Wasserwerk Musterstadt', 'tariff_name' => 'Wasser',
            'start' => '2026-01-01', 'end' => null,
            'trinkwasser' => ['working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 150.0]]],
            'schmutzwasser' => ['basis' => 'trinkwasser_minus_abzug', 'abzug_meter_ids' => ['m_beet'],
                'working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 250.0]]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 40.0]],
        ]);
    }

    public function testMonthRowsPriceTheQuantityTheReadingsGive(): void
    {
        $rows = array_column($this->consumption->forMeter('wasser', $this->meters->get('wasser', 'm_haus')), null, 'ym');
        self::assertSame(44.22, $rows['2026-01']['cost'], 'Januar: 11,333 × 1,50 + 10,889 × 2,50');
        self::assertSame(43.67, $rows['2026-02']['cost']);
        self::assertSame(43.67, $rows['2026-03']['cost']);
        self::assertSame(11.3, $rows['2026-01']['m3'], 'die Anzeige bleibt auf 0,1 m³ gerundet');
    }

    public function testCardAndBillingYearNameTheSameCent(): void
    {
        $meter = $this->meters->get('wasser', 'm_haus');
        $row = $this->consumption->contractStatus('wasser', $meter, self::AS_OF)['contracts'][0];
        self::assertSame(34.0, (float)$row['actual_m3'], 'Menge im Vertrag: 34,000 m³, nicht 33,9');
        self::assertSame(131.56, (float)$row['actual_cost']);
        $m = $row['balance_measured'];
        self::assertSame(11.56, $m['balance'], 'Saldo bis zur letzten Ablesung: 131,56 − 120,00');

        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bp = (new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills))
            ->forTarget('wasser', $meter, false, self::AS_OF);
        $p = array_column($bp['periods'], null, 'from')['2026-01-01'];
        self::assertSame(131.56, (float)$p['cost'], 'Abrechnungsjahr: dieselben Kosten');
        self::assertSame(11.56, (float)$p['balance'], 'und derselbe Saldo wie die Karte');
    }

    /** „Rechnung prüfen“ über dieselben Stände: 131,56 € (bisher bepreiste es die auf 0,01 m³ gerundete Menge je Abschnitt). */
    public function testTheBillCheckPricesTheSameQuantity(): void
    {
        $b = $this->consumption->billBreakdown('wasser', $this->meters->get('wasser', 'm_haus'), '2026-01-01', '2026-04-01');
        self::assertSame(131.56, (float)$b['totals']['total']);
        self::assertSame(34.0, (float)$b['totals']['m3']);
        self::assertArrayNotHasKey('_m3', $b['totals'], 'die Rechenmenge bleibt intern');
        foreach ($b['rows'] as $r) self::assertArrayNotHasKey('_m3', $r);
    }

    /** Jahresbericht (Kalenderjahr aus den Monatszeilen) und „Frühere Verträge“ (Vertragsstatus): derselbe Betrag. */
    public function testReportAndPastContractsAgree(): void
    {
        $sum = 0.0;
        foreach ($this->consumption->forMeter('wasser', $this->meters->get('wasser', 'm_haus')) as $m) $sum += (float)$m['cost'];
        self::assertSame(131.56, round($sum, 2));
    }
}
