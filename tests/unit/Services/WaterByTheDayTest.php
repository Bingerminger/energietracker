<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Wasser rechnet tagesgenau wie Strom: Jeder Tag rechnet mit dem Preis, der an ihm gilt, und ein
 * Vertragswechsel mitten im Monat teilt Menge, Grundpreis und Abschlag nach derselben Regel. Bis v3.11 galten bei
 * Wasser Vertrag und Preise des Monatsersten (eine Preisänderung zum 15. wirkte erst im Folgemonat), Grundpreis und
 * Abschlag im Saldo aber tagesgenau. „Rechnung prüfen“ setzt jetzt Abschnitte an den Preisstichtagen.
 *
 * Erfundener Haushalt: Wasser 0,4 m³ am Tag, Stände am Monatsersten 01.01.–01.11.2026.
 *   Vertrag A ab 01.01.2026: Trinkwasser 210 ct/m³, ab 15.07. 260 ct/m³, Grundpreis 8 €/Monat; Schmutzwasser
 *   (Basis Trinkwasser) 250 ct/m³; Abschlag 40 €/Monat. Im Wechselfall endet A am 14.09.
 *   Vertrag B ab 15.09.2026: Trinkwasser 230 ct/m³, 10 €/Monat; Schmutzwasser 270 ct/m³; Abschlag 50 €/Monat.
 * Von Hand:
 *   Juli (31 Tage, 12,4 m³): 14 Tage = 5,6 m³ × 2,10 € = 11,76 €, 17 Tage = 6,8 m³ × 2,60 € = 17,68 €
 *     Trinkwasser Arbeitspreis 29,44 € (bis v3.11: 12,4 × 2,10 = 26,04 €); Schmutzwasser 12,4 × 2,50 = 31,00 €
 *     Rechnung prüfen: 01.–14.07. 11,76 + 8 × 14/31 = 15,37 €, 15.–31.07. 17,68 + 8 × 17/31 = 22,07 €
 *   September (30 Tage, 12 m³): A 14 Tage = 5,6 m³, B 16 Tage = 6,4 m³
 *     A (seit 15.07. 2,60 €): 5,6 × 2,60 + 5,6 × 2,50 + 8 × 14/30 = 14,56 + 14,00 + 3,73 = 32,29 €, Abschlag 40 × 14/30 = 18,67 €
 *     B: 6,4 × 2,30 + 6,4 × 2,70 + 10 × 16/30 = 14,72 + 17,28 + 5,33 = 37,33 €, Abschlag 50 × 16/30 = 26,67 €
 *     Monat: Trinkwasser 14,56 + 14,72 + 3,73 + 5,33 = 38,35 €, Schmutzwasser 31,28 €, zusammen 69,63 €
 *   Vertrag A ganz: Jan.–Aug. 97,2 m³ + 5,6 m³ = 102,8 m³ (bis v3.11: der ganze September, 109,2 m³)
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ContractService::class)]
final class WaterByTheDayTest extends ServiceTestCase
{
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('wasser', [['id' => 'd_w', 'serial' => null, 'installed_on' => '2026-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($d = '2026-01-01'; $d <= '2026-11-01'; $d = date('Y-m-d', (int)strtotime("$d +1 month"))) {
            $rows[] = ['date' => $d, 'counter' => 0.4 * (int)round((strtotime("$d 12:00") - strtotime('2026-01-01 12:00')) / 86400), 'device_id' => 'd_w'];
        }
        $this->setReadings('wasser', $this->meterId, $rows);
    }

    private function contractA(?string $end = null): array
    {
        return $this->contracts->create('wasser', ['meter_id' => $this->meterId, 'provider' => 'Wasserwerk Musterstadt', 'start' => '2026-01-01', 'end' => $end,
            'trinkwasser' => ['working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 210], ['from' => '2026-07-15', 'ct_per_m3' => 260]],
                              'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 8]]],
            'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 250]]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 40]]]);
    }

    private function contractB(): array
    {
        return $this->contracts->create('wasser', ['meter_id' => $this->meterId, 'provider' => 'Wasser Neu', 'start' => '2026-09-15',
            'trinkwasser' => ['working_prices' => [['from' => '2026-09-15', 'ct_per_m3' => 230]], 'base_prices' => [['from' => '2026-09-15', 'eur_per_month' => 10]]],
            'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => '2026-09-15', 'ct_per_m3' => 270]]],
            'advance_payments' => [['from' => '2026-09-15', 'amount_eur' => 50]]]);
    }

    /** @return array<string,array<string,mixed>> */
    private function months(): array
    {
        return array_column($this->consumption->forMeter('wasser', $this->meters->get('wasser', $this->meterId)), null, 'ym');
    }

    public function testAPriceFromTheFifteenthCountsFromTheFifteenth(): void
    {
        $this->contractA();
        $jul = $this->months()['2026-07'];
        self::assertEqualsWithDelta(12.4, (float)$jul['m3'], 0.001);
        self::assertSame(29.44, $jul['trinkwasser']['working_cost'], 'von Hand: 5,6 × 2,10 + 6,8 × 2,60');
        self::assertSame(31.0, $jul['schmutzwasser']['total']);
        self::assertSame(68.44, $jul['cost'], 'von Hand: 29,44 + 8 + 31,00');
        self::assertSame(25.2, $this->months()['2026-06']['trinkwasser']['working_cost'], 'von Hand: Juni ganz zu 2,10 €, 30 × 0,4 = 12,0 m³');
    }

    public function testTheBillCheckStartsARowAtThePriceDate(): void
    {
        $this->contractA();
        $b = $this->consumption->billBreakdown('wasser', $this->meters->get('wasser', $this->meterId), '2026-07-01', '2026-08-01');
        self::assertSame(['2026-07-01', '2026-07-15'], array_column($b['rows'], 'from'));
        self::assertSame('price', $b['rows'][1]['reason']);
        self::assertSame([15.37, 22.07], array_column($b['rows'], 'tw_cost'), 'von Hand: 11,76 + 3,61 und 17,68 + 4,39');
        self::assertEqualsWithDelta($this->months()['2026-07']['cost'], (float)$b['totals']['total'], 0.011, 'Rechnung prüfen = Monatszeile');
    }

    public function testAContractSwitchOnTheFifteenthSplitsQuantityBaseAndAdvanceByTheSameRule(): void
    {
        $a = $this->contractA('2026-09-14');
        $b = $this->contractB();
        $sep = $this->months()['2026-09'];
        self::assertSame((string)$b['id'], $sep['contract_id'], 'die meisten Tage');
        $parts = array_column($sep['contract_parts'], null, 'contract_id');
        $pa = $parts[(string)$a['id']]; $pb = $parts[(string)$b['id']];
        self::assertSame([14, 5.6, 32.29, 18.67], [$pa['days'], $pa['m3'], $pa['cost'], $pa['advance_eur']], 'von Hand: A 14 Tage');
        self::assertSame([16, 6.4, 37.33, 26.67], [$pb['days'], $pb['m3'], $pb['cost'], $pb['advance_eur']], 'von Hand: B 16 Tage');
        self::assertSame(69.63, $sep['cost']);
        self::assertEqualsWithDelta(45.33, (float)$sep['advance_eur'], 0.005);

        $rows = array_column($this->consumption->contractStatus('wasser', $this->meters->get('wasser', $this->meterId), '2026-11-10')['contracts'], null, 'contract_id');
        self::assertEqualsWithDelta(102.8, (float)$rows[(string)$a['id']]['actual_m3'], 0.05, 'von Hand: 97,2 + 5,6');
        self::assertEqualsWithDelta(338.67, (float)$rows[(string)$a['id']]['advance_paid'], 0.01, 'von Hand: 8 × 40 + 18,67');
    }

    /** Doku-Beispiel (Wasser → Preise ab einem Tag): derselbe Juli, dieselbe Zahl. */
    public function testTheDocumentedExampleHolds(): void
    {
        $this->contractA();
        $v = (float)$this->months()['2026-07']['trinkwasser']['working_cost'];
        $root = dirname(__DIR__, 3);
        foreach (['docs/verstehen/03-wasser.md' => number_format($v, 2, ',', '.') . ' €', 'docs/en/verstehen/03-wasser.md' => '€' . number_format($v, 2, '.', ',')] as $file => $want) {
            $text = (string)file_get_contents("$root/$file");
            $pos = strpos($text, '<!-- doc-claim: water-by-the-day');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            self::assertStringContainsString($want, substr($text, $pos, 900), "$file: $want");
        }
    }

    public function testTheBillCheckOfTheYearMatchesTheMonths(): void
    {
        $this->contractA('2026-09-14');
        $this->contractB();
        $b = $this->consumption->billBreakdown('wasser', $this->meters->get('wasser', $this->meterId), '2026-01-01', '2026-11-01');
        $months = array_filter($this->months(), fn($m) => $m['ym'] >= '2026-01' && $m['ym'] <= '2026-10');
        self::assertEqualsWithDelta(array_sum(array_column($months, 'cost')), (float)$b['totals']['total'], 0.05, 'Ansicht gegen Ansicht');
        self::assertContains('2026-09-15', array_column($b['rows'], 'from'));
    }
}
