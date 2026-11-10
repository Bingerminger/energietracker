<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.10.0 (Skeptiker-Lauf 3.10.0, H1/H3/N1) — eine Abrechnung über zwei Verträge: gemessener Saldo, Stand
 * heute und Spanne über alle Teile; die Heizgrenze-Wirkung und „Danach wären rund …“ wie die Karte.
 *
 * Erfundener Haushalt: Strom, genau 10 kWh am Tag seit 01.01.2025, Ablesung am Monatsersten bis
 * 01.01.2026 und am 05.01.2026. Vertrag A 01.01.–31.03.2026 (10 ct, Grundpreis 12 €, Abschlag 100 €),
 * Tarifwechsel beim selben Versorger: B ab 01.04.2026 (12 ct, 12 €, 100 €), ohne eigene Ablesung.
 * Heute 20.10.2026, Abrechnung 31.12.2026.
 *
 * Handrechnung gemessen (nur A, 01.01.–05.01., 4 Tage):
 *   4 × 10 kWh × 0,10 € = 4,00 €; Grundpreis 12 € × 4/31 = 1,55 €; Abschlag 100 € × 4/31 = 12,90 €
 *   Saldo am 05.01.2026 = 4,00 + 1,55 − 12,90 = −7,35 € (Guthaben) — wie die Kachel „Abschläge … bis 05.01.“
 * Stand heute: A bis zu seinem Ende 90 × 1,00 € + 36 € − 300 € = −174,00 €;
 *   B 202 Tage × 10 kWh × 0,12 € = 242,40 € + 6 × 12 € + 12 € × 19/31 = 79,35 € − 7 × 100 € = −378,25 €
 *   zusammen −552,25 €.
 */
#[CoversClass(ConsumptionService::class)]
final class SettleSumTest extends ServiceTestCase
{
    use ReportFixture;

    private const TODAY = '2026-10-20';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_a', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $at = fn(string $d): float => 10.0 * (int)round((strtotime($d . ' 12:00') - strtotime('2025-01-01 12:00')) / 86400);
        $rows = [];
        for ($t = strtotime('2025-01-01'); $t <= strtotime('2026-01-01'); $t = strtotime('+1 month', $t)) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => $at(date('Y-m-d', $t)), 'device_id' => 'd_a'];
        }
        $rows[] = ['date' => '2026-01-05', 'counter' => $at('2026-01-05'), 'device_id' => 'd_a'];
        $this->setReadings('strom', $this->meterId, $rows);
        $this->contract('2026-01-01', '2026-03-31', 10.0);
        $this->contract('2026-04-01', null, 12.0);
    }

    private function contract(string $start, ?string $end, float $ct, string $provider = 'Strom Musterstadt'): void
    {
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => $provider, 'start' => $start, 'end' => $end,
            'working_prices' => [['from' => $start, 'ct_per_kwh' => $ct]], 'base_prices' => [['from' => $start, 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 100]]]);
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $today = self::TODAY): array
    {
        return $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), $today)['contracts'];
    }

    public function testTheMeasuredBalanceSpansAllContractsOfTheBill(): void
    {
        [$a, $b] = $this->rows();
        self::assertNull($b['balance_measured'], 'B hat keinen gemessenen Tag');
        $m = $b['settle_measured'];
        self::assertSame(['2026-01-01', '2026-01-05'], [$m['from'], $m['until']]);
        self::assertEqualsWithDelta(4.00, $m['energy_cost'], 0.005);
        self::assertEqualsWithDelta(1.55, $m['base'], 0.005);
        self::assertEqualsWithDelta(12.90, $m['advance'], 0.005);
        self::assertEqualsWithDelta(-7.35, $m['balance'], 0.005);
        self::assertSame($m, $a['settle_measured'], 'beide Zeilen tragen dieselbe Summe');
    }

    public function testTodayAndTheRangeSpanAllContracts(): void
    {
        [$a, $b] = $this->rows();
        self::assertEqualsWithDelta(-174.00, $a['current_balance'], 0.005);
        self::assertEqualsWithDelta(-378.25, $b['current_balance'], 0.005);
        self::assertEqualsWithDelta(-552.25, $b['settle_today']['balance'], 0.005);
        self::assertEqualsWithDelta(1000.0, $b['settle_today']['advance'], 0.005, '300 € (A) + 700 € (B)');
        // Spanne der Summe aus beiden Teilen (bis dahin nur die des laufenden Vertrags, verschoben)
        $ra = [$a['projection_band']['low'], $a['projection_band']['high']];
        $rb = [$b['projection_band']['low'], $b['projection_band']['high']];
        self::assertEqualsWithDelta($ra[0] + $rb[0], $b['settle_range'][0], 0.011);
        self::assertEqualsWithDelta($ra[1] + $rb[1], $b['settle_range'][1], 0.011);
        self::assertGreaterThan($rb[1] - $rb[0], $b['settle_range'][1] - $b['settle_range'][0], 'breiter als die Spanne von B allein');
        // der Vorvertrag ist überwiegend geschätzt (86 von 90 Tagen): die Karte zeigt ihn mit „≈“
        $partA = $b['settle_parts'][0];
        self::assertEqualsWithDelta(86 / 90, $partA['estimated_share'], 0.002);
        self::assertSame([round($ra[0], 2), round($ra[1], 2)], $partA['range']);
    }

    public function testAnUncertainPartMakesTheSumUncertain(): void
    {
        // reine Funktion: A unsicher, B sicher → die Summe unsicher, kein einzelner Vorschlag, Spanne für den Abschlag
        $row = fn(string $id, float $bal, ?array $unc, ?array $band, bool $cur) => ['contract_id' => $id, 'provider' => 'Strom Musterstadt',
            'start' => '2026-01-01', 'end' => null, 'billing_period' => ['from' => '2026-01-01'], 'settle_on' => '2026-12-31',
            'projected_end_balance' => $bal, 'is_current' => $cur, 'months_left' => 4, 'current_advance_amount' => 100.0,
            'suggested_advance' => $cur ? 90.0 : null, 'projection_uncertain' => $unc, 'projection_band' => $band];
        $out = ConsumptionService::withSettleTotals([
            $row('a', 200.0, ['reasons' => ['poor_fit'], 'projected_range' => [100.0, 380.0]], null, false),
            $row('b', -40.0, null, ['low' => -60.0, 'high' => -20.0, 'half' => 20.0], true),
        ]);
        $b = $out[1];
        self::assertSame(160.0, $b['settle_balance']);
        self::assertSame([40.0, 360.0], $b['settle_range'], '100 − 60 bis 380 − 20');
        self::assertSame(['poor_fit'], $b['settle_uncertain']['reasons']);
        self::assertSame([40.0, 360.0], $b['settle_uncertain']['projected_range']);
        self::assertNull($b['suggested_advance']);
        self::assertSame([110.0, 190.0], $b['settle_uncertain']['suggested_range'], '100 + 40/4 bis 100 + 360/4');
    }

    public function testTheAnnualReportExpectsTheSumWithItsRange(): void
    {
        // H1 — Jahresbericht: die Summe der Abrechnung mit der Spanne aus beiden Teilen; „Wohin geht mein Geld?“
        // bekommt die Felder der Summe (der Browser nimmt sie wie die Karte, expectedBalance)
        $b = $this->rows()[1];
        $m = $this->reportServices()['model']->build(2026, ['today' => self::TODAY]);
        $bal = array_values(array_filter($m['money']['balances'] ?? [], fn($x) => $x['utility'] === 'strom'))[0] ?? null;
        self::assertNotNull($bal);
        self::assertEqualsWithDelta($b['settle_balance'], $bal['projected_end_balance'], 0.005);
        self::assertSame($b['settle_range'], [$bal['band']['low'], $bal['band']['high']]);
        self::assertStringContainsString((new \Energietracker\Services\ReportAdviceService($this->i18n))->bandSentence($bal['band']), $bal['text']);
        self::assertSame($b['settle_balance'], $bal['explainer']['settle_balance']);
        self::assertSame($b['settle_range'], $bal['explainer']['settle_range']);
    }

    public function testHeatLimitEffectSpeaksOfTheWholeBill(): void
    {
        // H1 — der Hinweis „Erwarteter Saldo zum …“ nennt dieselbe Summe wie die Karte (hier ohne Heizmodell: gleiche Grenze)
        $b = $this->rows()[1];
        // v3.12.0 — am selben Stichtag wie die Karte (bis v3.11 rechnete der Hinweis immer heute)
        $e = $this->consumption->heatLimitEffect('strom', $this->meters->get('strom', $this->meterId), 15.0, self::TODAY);
        self::assertNotNull($e);
        self::assertSame(round((float)$b['settle_balance'], 2), $e['before']['balance']);
        self::assertSame($e['before']['balance'], $e['after']['balance']);
    }

    public function testNoOngoingAdvanceOfAnEndingContractWithASuccessor(): void
    {
        // N1 — A endet zur Abrechnung, B ist eingetragen: kein „Danach wären rund … statt …“ aus den Preisen von A
        $this->store->write('strom/contracts.json', []);
        $this->contract('2026-01-01', '2026-12-31', 10.0);
        $a = $this->rows('2026-11-15')[0];
        self::assertSame('short', $a['suggestion_withheld']);
        self::assertNotNull($a['advance_ongoing'], 'ohne Folgevertrag läuft A weiter: der laufende Bedarf gilt');
        $this->contract('2027-01-01', null, 14.0, 'Anderer Versorger');
        $a = $this->rows('2026-11-15')[0];
        self::assertNull($a['advance_ongoing']);
    }
}
