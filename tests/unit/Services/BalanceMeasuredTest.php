<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.10.0 (#49, Discussion #42) — „Aktueller Saldo“ der Saldo-Karte zählt nur, was bis zur letzten
 * Ablesung gemessen ist (`balance_measured`); die Schätzung bis heute bleibt `current_balance`.
 * Kachel „Abschläge … bis …“ und Karte enden am selben Tag.
 *
 * Erfundener Haushalt: Strom, genau 10 kWh am Tag seit 01.01.2025, Ablesung am Monatsersten bis
 * 01.10.2026 und am 07.10.2026; Vertrag ab 01.01.2026: 10 ct/kWh, Grundpreis 12 €/Monat, Abschlag
 * 100 €/Monat; heute 20.10.2026. Sonderzahlungen: Nachzahlung 50 € am 15.05.2026 (vor der
 * Ablesung), Rückzahlung 30 € am 10.10.2026 (danach).
 *
 * Handrechnung gemessen [01.01.2026, 07.10.2026): 279 Tage (273 bis Ende September + 6)
 *   Arbeitspreis  279 × 10 kWh × 0,10 €            = 279,00 €
 *   Grundpreis    9 × 12 € + 12 € × 6/31           = 110,32 €
 *   Abschläge     9 × 100 € + 100 € × 6/31         = 919,35 €
 *   Sonder        − 50 € (Nachzahlung)
 *   Saldo         279,00 + 110,32 − 919,35 − 50    = −580,03 € (Guthaben)
 * Bis heute (20.10.): 13 Tage geschätzt (Oktober-Rate 10 kWh/Tag) = 13,00 €, Grundpreis
 * 9 × 12 + 12 × 19/31 = 115,35 €, Abschläge 10 × 100 = 1.000 € (laufender Monat ganz),
 * Sonder − 50 + 30 → 279 + 13 + 115,35 − 1.000 − 20 = −612,65 €.
 */
#[CoversClass(ConsumptionService::class)]
final class BalanceMeasuredTest extends ServiceTestCase
{
    private const TODAY = '2026-10-20';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_a', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        $at = fn(string $d): float => 10.0 * (int)round((strtotime($d . ' 12:00') - strtotime('2025-01-01 12:00')) / 86400);
        for ($t = strtotime('2025-01-01'); $t <= strtotime('2026-10-01'); $t = strtotime('+1 month', $t)) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => $at(date('Y-m-d', $t)), 'device_id' => 'd_a'];
        }
        $rows[] = ['date' => '2026-10-07', 'counter' => $at('2026-10-07'), 'device_id' => 'd_a'];
        $this->setReadings('strom', $this->meterId, $rows);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 10.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 100]],
            'special_payments' => [['date' => '2026-05-15', 'kind' => 'nachzahlung_ohne', 'amount_eur' => 50.0],
                                   ['date' => '2026-10-10', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 30.0]]]);
    }

    private function card(): array
    {
        return $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'][0];
    }

    public function testTheCurrentBalanceCountsOnlyWhatIsMeasured(): void
    {
        $m = $this->card()['balance_measured'];
        self::assertNotNull($m);
        self::assertSame('2026-01-01', $m['from']);
        self::assertSame('2026-10-07', $m['until'], 'endet an der letzten Ablesung');
        self::assertEqualsWithDelta(279.00, $m['energy_cost'], 0.005);
        self::assertEqualsWithDelta(110.32, $m['base'], 0.005);
        self::assertEqualsWithDelta(919.35, $m['advance'], 0.005);
        self::assertEqualsWithDelta(-50.0, $m['special_net'], 0.005, 'nur die Sonderzahlung vor der Ablesung');
        self::assertEqualsWithDelta(389.32, $m['cost'], 0.005);
        self::assertEqualsWithDelta(-580.03, $m['balance'], 0.005);
    }

    public function testTheEstimateToTodayStaysTheSecondLine(): void
    {
        $c = $this->card();
        self::assertSame('2026-10-07', $c['measured_until']);
        self::assertEqualsWithDelta(13.00, $c['estimated_cost_to_date'], 0.005);
        self::assertEqualsWithDelta(-612.65, $c['current_balance'], 0.005, 'Stand heute wie bisher (Schätzung, laufender Monat ganz)');
    }

    public function testTileAndCardEndOnTheSameDayAndAgree(): void
    {
        // Die Kachel summiert die Monatszeilen des Kalenderjahres (Kosten und Abschlag der gemessenen
        // Tage, public/js/views/utility.js); beginnt der Vertrag am 1. Januar, ist sie die Karte ohne Sonderzahlungen
        $monthly = $this->consumption->forMeter('strom', $this->meters->get('strom', $this->meterId));
        $tile = 0.0;
        foreach ($monthly as $row) {
            if ((int)$row['year'] !== 2026) continue;
            $tile += (float)($row['cost_measured'] ?? $row['cost']) - (float)($row['advance_measured_eur'] ?? $row['advance_eur']);
        }
        $m = $this->card()['balance_measured'];
        self::assertEqualsWithDelta(-530.03, $tile, 0.01, 'Handrechnung ohne Sonderzahlungen');
        self::assertEqualsWithDelta($m['balance'] - $m['special_net'], $tile, 0.01);
    }

    public function testBonusesCountInTheMonthsWithMeasuredDays(): void
    {
        // Gutschriften 50 € im März und 20 € am 20.10. (Oktober ist bis zum 07. gemessen, die Monatszeile trägt ihn),
        // 30 € im November (nach der Ablesung, noch nicht gemessen) → gemessen 70 €, wie die Kachel
        $this->store->write('strom/contracts.json', []);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 10.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 100]],
            'bonuses' => [['credit_date' => '2026-03-10', 'amount_eur' => 50.0, 'type' => 'sofort'], ['credit_date' => '2026-10-20', 'amount_eur' => 20.0, 'type' => 'sonstige'],
                          ['credit_date' => '2026-11-15', 'amount_eur' => 30.0, 'type' => 'neukunde']]]);
        $m = $this->card()['balance_measured'];
        self::assertEqualsWithDelta(70.0, $m['bonus'], 0.005);
        self::assertEqualsWithDelta(279.00 + 110.32 - 70.0 - 919.35, $m['balance'], 0.01);
        $tile = 0.0;
        foreach ($this->consumption->forMeter('strom', $this->meters->get('strom', $this->meterId)) as $row) {
            if ((int)$row['year'] === 2026) $tile += (float)($row['cost_measured'] ?? $row['cost']) - (float)($row['advance_measured_eur'] ?? $row['advance_eur']);
        }
        self::assertEqualsWithDelta($m['balance'], $tile, 0.01, 'Kachel und Karte gleich');
    }

    public function testTheElectricityBalanceTileCountsTheMeasuredDays(): void
    {
        // Skeptiker N5 — Oktober ist bis zum 07. gemessen: 6 × 10 kWh × 0,10 € + 12 € × 6/31 = 6,00 + 2,32 = 8,32 €
        // (bis dahin ein voller Monat Grundpreis: 18,00 €), wie die Kachel „Kosten“ (cost_measured)
        $s = new \Energietracker\Services\StromSaldoService($this->consumption);
        $oct = array_values(array_filter($s->compute()['monthly'], fn($r) => $r['ym'] === '2026-10'))[0];
        self::assertEqualsWithDelta(8.32, $oct['bezug_cost'], 0.005);
    }

    /** Lektion 99 — das Rechenbeispiel der Grundlagen (DE und EN) rechnet die App nach. */
    public function testTheDocsExampleIsWhatTheAppComputes(): void
    {
        $m = $this->card()['balance_measured'];
        $want = ['de' => [number_format($m['energy_cost'], 2, ',', '.') . ' €', number_format($m['base'], 2, ',', '.') . ' €',
                          number_format($m['advance'], 2, ',', '.') . ' €', '−' . number_format(-($m['balance'] - $m['special_net']), 2, ',', '.') . ' €'],
                 'en' => ['€' . number_format($m['energy_cost'], 2), '€' . number_format($m['base'], 2),
                          '€' . number_format($m['advance'], 2), '−€' . number_format(-($m['balance'] - $m['special_net']), 2)]];
        foreach (['de' => 'docs/verstehen/00-overview.md', 'en' => 'docs/en/verstehen/00-overview.md'] as $lang => $file) {
            $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            $pos = strpos($text, '<!-- doc-claim: balance-measured-example');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            $block = substr($text, $pos, (int)strpos($text, "```\n", (int)strpos($text, '```text', $pos) + 7) - $pos);
            foreach ($want[$lang] as $v) self::assertStringContainsString($v, $block, "$file: $v");
        }
    }

    public function testWithoutAReadingInThePeriodThereIsNoMeasuredBalance(): void
    {
        // Vertrag beginnt nach der letzten Ablesung: nichts gemessen, die Karte zeigt die Schätzung wie bisher
        $this->store->write('strom/contracts.json', []);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2026-10-10',
            'working_prices' => [['from' => '2026-10-10', 'ct_per_kwh' => 10.0]], 'advance_payments' => [['from' => '2026-10-10', 'amount_eur' => 100]]]);
        self::assertNull($this->card()['balance_measured']);
    }

    public function testAReadingTodayLeavesNothingToEstimate(): void
    {
        $readings = $this->store->read('strom/readings.json', []);
        $readings[] = ['id' => 'r_today', 'meter_id' => $this->meterId, 'device_id' => 'd_a', 'date' => self::TODAY, 'counter' => 10.0 * 657,
            'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        $this->store->write('strom/readings.json', $readings);
        $c = $this->card();
        self::assertSame(self::TODAY, $c['balance_measured']['until']);
        self::assertEqualsWithDelta(0.0, $c['estimated_cost_to_date'], 0.005);
        // gemessen bis heute: 292 Tage, Abschlag Oktober 19/31 (die Karte bis heute zählt ihn ganz),
        // jetzt auch die Rückzahlung vom 10.10. (− 50 + 30)
        self::assertEqualsWithDelta(292.0 + 108.0 + 12.0 * 19 / 31 - 900.0 - 100.0 * 19 / 31 - 20.0, $c['balance_measured']['balance'], 0.01);
        self::assertEqualsWithDelta(-573.94, $c['balance_measured']['balance'], 0.005);
    }
}
