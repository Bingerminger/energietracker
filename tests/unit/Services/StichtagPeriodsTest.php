<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 — Zähler mit Verbrauch je Zeitraum rechnen am Stichtag wie Zähler mit Ständen: mit dem Stand an diesem Tag.
 *
 * Bis v3.12 kannten Zeiträume keinen Stichtag (Bekannte Grenze v3.12.0): Ein eingetragener Zeitraum zählte immer mit,
 * auch einer, der am Stichtag noch lief oder erst danach begann. Dieselbe Karte, derselbe Jahresbericht, dasselbe
 * Mietbudget hingen damit daran, was später eingetragen wurde.
 *
 * Die Regel (ConsumptionService::knownPeriods): **Ein Zeitraum zählt ab seinem letzten Tag** (`to` ≤ Stichtag), wie
 * eine Ablesung ab ihrem Tag; ein Zeitraum, der am Stichtag noch läuft, zählt nicht. Alle Fälle an festen Tagen und
 * je Grenze beide Seiten: Monatsende, Schaltjahr, Abrechnungsjahr ab September.
 */
#[CoversClass(ConsumptionService::class)]
final class StichtagPeriodsTest extends ServiceTestCase
{
    /** Zähler mit Verbrauch je Zeitraum, Zeiträume mit festen IDs direkt in den Topf. */
    private function periodMeter(string $utility, string $from): array
    {
        $m = $this->meters->create($utility, ['name' => 'Laut Verbrauchsinfo', 'capture' => 'period', 'installed_on' => $from]);
        return $this->meters->get($utility, (string)$m['id']);
    }

    /** @param list<array{0:string,1:string,2:float}> $rows [von, bis einschließlich, Verbrauch] */
    private function setPeriods(string $utility, array $meter, array $rows): void
    {
        $out = [];
        foreach ($rows as $i => [$from, $to, $v]) {
            $out[] = ['id' => sprintf('p_test_%03d', $i + 1), 'meter_id' => $meter['id'], 'from' => $from, 'to' => $to, 'value' => $v,
                'value_unit' => 'consumption', 'is_estimated' => false, 'source' => 'manual', 'note' => ''];
        }
        $this->store->write("$utility/periods.json", $out);
    }

    /** Monatswerte Januar bis `$lastMonth` 2025: Januar 100 kWh, Februar 200 kWh, … */
    private function monthly(array $meter, string $lastMonth): void
    {
        $rows = [];
        for ($m = 1; sprintf('2025-%02d', $m) <= $lastMonth; $m++) {
            $first = sprintf('2025-%02d-01', $m);
            $rows[] = [$first, date('Y-m-t', strtotime($first)), 100.0 * $m];
        }
        $this->setPeriods('waerme', $meter, $rows);
    }

    private static function sum(array $rows): float
    {
        return round(array_sum(array_column($rows, 'kwh')), 6);
    }

    /**
     * Monatsende: Am 29.04. läuft der April noch, an seinem letzten Tag (30.04.) zählt er.
     * Handrechnung: bis März 100 + 200 + 300 = 600 kWh, mit April 1.000 kWh; ohne Stichtag (heute) alle sechs Monate.
     */
    public function testAPeriodCountsFromItsLastDay(): void
    {
        $meter = $this->periodMeter('waerme', '2025-01-01');
        $this->monthly($meter, '2025-06');
        $at = fn(string $day) => $this->consumption->forMeter('waerme', $meter, null, $day);
        self::assertSame(['2025-01', '2025-02', '2025-03'], array_column($at('2025-04-29'), 'ym'), 'am 29.04. läuft der April noch');
        self::assertSame(600.0, self::sum($at('2025-04-29')));
        self::assertSame(['2025-01', '2025-02', '2025-03', '2025-04'], array_column($at('2025-04-30'), 'ym'), 'am 30.04. ist er abgelaufen');
        self::assertSame(1000.0, self::sum($at('2025-04-30')));
        self::assertSame(2100.0, self::sum($this->consumption->forMeter('waerme', $meter)), 'heute: Januar bis Juni');
    }

    /** Am Stichtag dasselbe Ergebnis, ob spätere Zeiträume schon eingetragen sind oder nicht (bis v3.12: Mai und Juni zählten mit). */
    public function testTheMonthlyRowsAtAStichtagDoNotDependOnLaterPeriods(): void
    {
        $meter = $this->periodMeter('waerme', '2025-01-01');
        $this->monthly($meter, '2025-03');
        $then = $this->consumption->forMeter('waerme', $meter, null, '2025-04-20');
        $this->monthly($meter, '2025-06');
        self::assertSame($then, $this->consumption->forMeter('waerme', $meter, null, '2025-04-20'));
    }

    /**
     * Schaltjahr: Zeiträume vom 15. bis zum 14., 10 kWh am Tag; der vom 15.02. bis 14.03.2024 hat 29 Tage.
     *   am 29.02.2024 (Schalttag): nur 15.01.–14.02. (Januar 17 Tage = 170, Februar 14 Tage = 140), der Februarzeitraum läuft
     *   am 13.03.2024: unverändert (der Zeitraum läuft noch einen Tag)
     *   am 14.03.2024: dazu 290 kWh, davon 15 Tage im Februar (150) und 14 im März (140)
     */
    public function testALeapYearPeriodCountsFromItsLastDay(): void
    {
        $meter = $this->periodMeter('strom', '2024-01-15');
        $this->setPeriods('strom', $meter, [['2024-01-15', '2024-02-14', 310.0], ['2024-02-15', '2024-03-14', 290.0], ['2024-03-15', '2024-04-14', 310.0]]);
        $at = fn(string $day) => array_column($this->consumption->forMeter('strom', $meter, null, $day), 'kwh', 'ym');
        $leap = $at('2024-02-29');
        self::assertEqualsWithDelta(['2024-01' => 170.0, '2024-02' => 140.0], $leap, 1e-6);
        self::assertEqualsWithDelta($leap, $at('2024-03-13'), 1e-6);
        self::assertEqualsWithDelta(['2024-01' => 170.0, '2024-02' => 290.0, '2024-03' => 140.0], $at('2024-03-14'), 1e-6);
    }

    /**
     * Abrechnungsjahr ab September (Stichtag 01.09.): Gas je Zeitraum, 500 kWh je Monat ab September 2024, Vertrag 10 ct/kWh,
     * 12 €/Monat Grundpreis, 100 € Abschlag. Am 30.08.2025 läuft der August noch: gemessen sind September bis Juli,
     * 11 × 500 = 5.500 kWh; am 31.08., dem letzten Tag des Abrechnungsjahrs, alle zwölf Monate, 6.000 kWh.
     * Die Karte des 30.08. ist dieselbe, ob September und Oktober schon eingetragen sind oder nicht.
     */
    public function testTheContractCardAtTheEndOfASeptemberBillingYearIsTheCardOfThatDay(): void
    {
        $this->settings->set(['billing_cycle_anchor_gas' => '09-01']);
        $meter = $this->periodMeter('gas', '2024-09-01');
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Gas Musterstadt', 'start' => '2024-09-01',
            'working_prices' => [['from' => '2024-09-01', 'ct_per_kwh' => 10.0]], 'base_prices' => [['from' => '2024-09-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2024-09-01', 'amount_eur' => 100]]]);
        $periods = function (string $last) use ($meter): void {
            $rows = [];
            for ($t = strtotime('2024-09-01 12:00'); date('Y-m', $t) <= $last; $t = strtotime('+1 month', $t)) $rows[] = [date('Y-m-01', $t), date('Y-m-t', $t), 500.0];
            $this->setPeriods('gas', $meter, $rows);
        };
        $card = fn(string $day) => $this->consumption->contractStatus('gas', $meter, $day)['contracts'][0];
        $periods('2025-08');
        $end = $card('2025-08-30');
        self::assertSame('2025-09-01', $end['effective_end'] ?? null, 'gerechnet bis zum Stichtag 01.09. (mit 01-01: 2026-01-01)');
        self::assertEqualsWithDelta(5500.0, (float)$end['actual_kwh'], 1e-6, 'September bis Juli');
        self::assertSame(11, $end['months_actual']);
        self::assertEqualsWithDelta(6000.0, (float)$card('2025-08-31')['actual_kwh'], 1e-6, 'mit August, am letzten Tag des Abrechnungsjahrs');
        $periods('2025-10');
        $later = $card('2025-08-30');
        foreach (['actual_kwh', 'actual_cost', 'current_balance', 'projected_end_balance', 'months_actual'] as $k) {
            self::assertSame($end[$k] ?? null, $later[$k] ?? null, $k);
        }
    }

    /** Das Mietbudget am Stichtag rechnet mit den Zeiträumen, die bis dahin abgelaufen sind (Doku: api.md, Budget). */
    public function testTheTenancyBudgetAtAStichtagIgnoresPeriodsStillRunning(): void
    {
        $meter = $this->periodMeter('waerme', '2025-01-01');
        $tenancies = new \Energietracker\Services\TenancyService($this->store, $this->meters, $this->i18n);
        $budget = new \Energietracker\Services\TenancyBudgetService($tenancies, $this->meters, $this->consumption, $this->i18n);
        $t = $tenancies->create(['start' => '2025-01-01', 'billing_anchor' => '01-01',
            'prepayments' => [['from' => '2025-01-01', 'heating_eur_month' => 50, 'operating_eur_month' => 0]],
            'prices' => [['from' => '2025-01-01', 'heat_eur_per_kwh' => 0.12]], 'meter_ids' => ['heat' => [$meter['id']]]]);
        $this->monthly($meter, '2025-03');
        $then = $budget->budget($t['id'], '2025-04-20');
        $this->monthly($meter, '2025-06');
        self::assertSame($then, $budget->budget($t['id'], '2025-04-20'));
        self::assertEqualsWithDelta(600.0, array_sum(array_column(array_slice($then['months'], 0, 3), 'heat_kwh')), 1e-6);
    }

    /**
     * Die Regel hat eine Stelle (Lektion 32): Die Rechnung liest die Zeiträume nur in knownPeriods(). Bis v3.12 lasen
     * fünf Stellen den Topf je für sich (Monatswerte, Ablesungsabstände, gemessen am Rand, Zählung je Jahr zweimal).
     */
    public function testTheCalculationReadsPeriodsAtOnePlace(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Services/ConsumptionService.php');
        self::assertSame(1, substr_count($src, 'periods.json'), 'ConsumptionService liest periods.json nur in knownPeriods()');
        self::assertGreaterThanOrEqual(5, substr_count($src, '$this->knownPeriods('), 'Monatswerte, Abstände, Rand, zwei Zählungen');
    }

    /** Die Doku sagt die Regel in beiden Sprachen, gleich nach der Marke (die Tests oben rechnen sie nach). */
    public function testTheDocsStateTheRule(): void
    {
        $want = ['docs/verstehen/15-waerme.md' => 'Ein Zeitraum zählt ab seinem letzten Tag',
                 'docs/en/verstehen/15-waerme.md' => 'A period counts from its last day',
                 'docs/referenz/api.md' => 'Ein Zeitraum zählt ab seinem letzten Tag',
                 'docs/en/referenz/api.md' => 'A period counts from its last day'];
        foreach ($want as $file => $phrase) {
            $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            $marker = str_contains($file, 'api.md') ? '<!-- doc-claim: budget-as-of' : '<!-- doc-claim: period-stichtag';
            $pos = strpos($text, $marker);
            self::assertNotFalse($pos, "$file: Marke fehlt");
            self::assertStringContainsString($phrase, preg_replace('/\s+/', ' ', str_replace('**', '', substr($text, $pos, 1200))), $file);
        }
    }
}
