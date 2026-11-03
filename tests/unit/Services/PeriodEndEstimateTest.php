<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.10.0 (Wirklichkeitsabgleich 2026-10-27) — Im Rückblick lag die Saldo-Schätzung einen Tag vor
 * dem Ende einer Abrechnungsperiode deutlich neben der späteren Rechnung, obwohl fast alle Tage
 * gemessen waren. Nachgebaut ohne echte Daten: erfundener Stromzähler mit genau 10 kWh am Tag,
 * Abrechnungsperiode 01.01.–31.12.2025 (Stichtag 01-01), Ablesung am 30.12.2025, heute 30.12.2025.
 * Die „spätere Rechnung“ ist von Hand gerechnet (365 Tage, Abschläge nach Plan).
 *
 * Geprüfte Hypothesen:
 *   H1 Vertragswechsel im Zeitraum beim selben Versorger — Ursache gefunden: „Erwartet bei
 *      Abrechnung“ zeigte nur den Teil des laufenden Vertrags, der Saldo des Vorvertrags fehlte
 *      ganz (hier 107 € von 174,20 €). Jetzt `settle_balance` (withSettleTotals).
 *   H2 Preisstand: Arbeitspreis, Grundpreis und Abschlag ändern sich mitten im Zeitraum — trifft.
 *   H3 Verteilung über Preisabschnitte: Ablesung nur alle zwei Monate, Preiswechsel zwischen zwei
 *      Ablesungen — trifft (bei gleichmäßigem Verbrauch; wie ein Versorger eine ungleichmäßige
 *      Menge auf Preisabschnitte verteilt, etwa nach Gradtagen, lässt sich ohne Rechnung nicht prüfen).
 */
#[CoversClass(ConsumptionService::class)]
final class PeriodEndEstimateTest extends ServiceTestCase
{
    private const TODAY = '2025-12-30';
    private string $meterId = '';

    /** 10 kWh je Tag ab 01.01.2024: Ablesungen alle $every Monate bis $last, dazu $extra. */
    private function household(string $last = '2025-12-01', array $extra = ['2025-12-30'], int $every = 1): void
    {
        foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("strom/$pot.json", []);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_a', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $at = fn(string $d): float => 10.0 * (int)round((strtotime($d . ' 12:00') - strtotime('2024-01-01 12:00')) / 86400);
        $rows = [];
        for ($t = strtotime('2024-01-01'); $t <= strtotime($last); $t = strtotime('+' . $every . ' month', $t)) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => $at(date('Y-m-d', $t)), 'device_id' => 'd_a'];
        }
        foreach ($extra as $d) $rows[] = ['date' => $d, 'counter' => $at($d), 'device_id' => 'd_a'];
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function contract(string $start, ?string $end, float $ct, float $advance, array $more = []): void
    {
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => $start, 'end' => $end,
            'working_prices' => [['from' => $start, 'ct_per_kwh' => $ct]], 'base_prices' => [['from' => $start, 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => $advance]]] + $more);
    }

    private function rows(string $today = self::TODAY): array
    {
        return $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), $today)['contracts'];
    }

    public function testOneContractMeetsTheBill(): void
    {
        // Rechnung 2025: 365 × 10 kWh × 0,10 € + 12 × 12 € − 12 × 60 € = 365 + 144 − 720 = −211 €
        $this->household();
        $this->contract('2024-01-01', null, 10.0, 60.0);
        $c = $this->rows()[0];
        self::assertEqualsWithDelta(-211.0, $c['projected_end_balance'], 0.005);
        self::assertArrayNotHasKey('settle_balance', $c, 'ein Vertrag, keine Summe');
    }

    public function testAContractChangeInThePeriodIsPartOfTheSameBill(): void
    {
        // H1 — Vertrag A 01.01.–30.06.2025 (10 ct), B ab 01.07.2025 (12 ct), derselbe Versorger, je 60 € Abschlag.
        // Rechnung 2025 über beide: 181 Tage × 1,00 € + 184 Tage × 1,20 € + 12 × 12 € − 12 × 60 €
        //   = 181 + 220,80 + 144 − 720 = −174,20 €; davon A 181 + 72 − 360 = −107 €, B 220,80 + 72 − 360 = −67,20 €
        $this->household();
        $this->contract('2024-01-01', '2025-06-30', 10.0, 60.0);
        $this->contract('2025-07-01', null, 12.0, 60.0);
        [$a, $b] = $this->rows();
        self::assertSame('2025-12-31', $a['settle_on']);
        self::assertSame('2025-12-31', $b['settle_on'], 'dieselbe Abrechnung');
        self::assertEqualsWithDelta(-107.0, $a['projected_end_balance'], 0.005);
        self::assertEqualsWithDelta(-67.2, $b['projected_end_balance'], 0.005, 'bis v3.9 die ganze Erwartung der Karte');
        self::assertEqualsWithDelta(-174.2, $b['settle_balance'], 0.005, 'die Rechnung');
        self::assertSame('refund', $b['settle_verdict']);
        self::assertCount(2, $b['settle_parts']);
        self::assertSame(['2025-01-01', '2025-06-30', -107.0], [$b['settle_parts'][0]['from'], $b['settle_parts'][0]['until'], $b['settle_parts'][0]['balance']]);
    }

    /** Lektion 99 — das Rechenbeispiel der Grundlagen (DE und EN) rechnet die App nach. */
    public function testTheDocsExampleIsWhatTheAppComputes(): void
    {
        $this->household();
        $this->contract('2024-01-01', '2025-06-30', 10.0, 60.0);
        $this->contract('2025-07-01', null, 12.0, 60.0);
        $b = $this->rows()[1];
        $want = ['de' => ['−' . number_format(-$b['settle_balance'], 2, ',', '.') . ' €', '−' . number_format(-$b['projected_end_balance'], 2, ',', '.') . ' €'],
                 'en' => ['−€' . number_format(-$b['settle_balance'], 2), '−€' . number_format(-$b['projected_end_balance'], 2)]];
        foreach (['de' => 'docs/verstehen/00-overview.md', 'en' => 'docs/en/verstehen/00-overview.md'] as $lang => $file) {
            $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            $pos = strpos($text, '<!-- doc-claim: settle-example');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            $block = substr($text, $pos, (int)strpos($text, "```\n", (int)strpos($text, '```text', $pos) + 7) - $pos);
            foreach ($want[$lang] as $v) self::assertStringContainsString($v, $block, "$file: $v");
        }
    }

    public function testTheSuggestionFollowsTheWholeBill(): void
    {
        // H1 mitten im Jahr: heute 15.07.2025, Ablesung am 15.07.; bis zur Abrechnung fünf Abschläge (Aug.–Dez.)
        // B allein: 60 € − 67,20 € ÷ 5 = 46,56 → 47 €; die Rechnung (−174,20 €): 60 € − 34,84 € = 25,16 → 25 €
        $this->household('2025-07-01', ['2025-07-15']);
        $this->contract('2024-01-01', '2025-06-30', 10.0, 60.0);
        $this->contract('2025-07-01', null, 12.0, 60.0);
        $b = $this->rows('2025-07-15')[1];
        self::assertSame(5, $b['months_left']);
        self::assertEqualsWithDelta(-67.2, $b['projected_end_balance'], 0.05);
        self::assertEqualsWithDelta(-174.2, $b['settle_balance'], 0.05);
        self::assertSame(25.0, $b['suggested_advance']);
    }

    public function testAnotherSupplierSettlesOnItsOwn(): void
    {
        // Wechsel zu einem anderen Versorger: Schlussrechnung zum Vertragsende, keine gemeinsame Summe
        $this->household();
        $this->contract('2024-01-01', '2025-06-30', 10.0, 60.0);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Anderer Versorger', 'start' => '2025-07-01',
            'working_prices' => [['from' => '2025-07-01', 'ct_per_kwh' => 12.0]], 'advance_payments' => [['from' => '2025-07-01', 'amount_eur' => 60]]]);
        [$a, $b] = $this->rows();
        self::assertSame('2025-06-30', $a['settle_on']);
        self::assertArrayNotHasKey('settle_balance', $b);
    }

    public function testOnlyRowsSettledOnTheSameDayAreSummed(): void
    {
        // reine Funktion: dieselbe Abrechnung = derselbe `settle_on` in der laufenden Periode; eine Schlussrechnung
        // (eigener Tag) und Verträge außerhalb der Periode bleiben für sich
        $bp = ['from' => '2025-01-01', 'until' => '2025-12-31'];
        $row = fn(string $id, ?array $period, string $settle, float $bal, bool $cur = false) => ['contract_id' => $id, 'provider' => 'Strom Musterstadt',
            'start' => '2025-01-01', 'end' => null, 'billing_period' => $period, 'settle_on' => $settle, 'projected_end_balance' => $bal,
            'is_current' => $cur, 'months_left' => 4, 'current_advance_amount' => 50.0, 'suggested_advance' => $cur ? 40.0 : null,
            'projection_uncertain' => null, 'projection_band' => $cur ? ['low' => -30.0, 'high' => -10.0, 'half' => 10.0] : null];
        $out = ConsumptionService::withSettleTotals([
            $row('a', $bp, '2025-03-31', -80.0), $row('b', null, '2025-12-31', -500.0),
            $row('c', $bp, '2025-12-31', -60.0), $row('d', $bp, '2025-12-31', -20.0, true),
        ]);
        self::assertArrayNotHasKey('settle_balance', $out[0], 'Schlussrechnung am eigenen Tag');
        self::assertArrayNotHasKey('settle_balance', $out[1], 'außerhalb der laufenden Periode');
        self::assertSame(-80.0, $out[3]['settle_balance']);
        self::assertSame([-90.0, -70.0], $out[3]['settle_range'], 'Band um den anderen Teil verschoben');
        self::assertSame(30.0, $out[3]['suggested_advance'], '50 € − 80 € ÷ 4 = 30 €');
        self::assertSame(['c', 'd'], array_column($out[2]['settle_parts'], 'contract_id'));
        self::assertSame('refund', $out[3]['settle_verdict']);
        // Einspeisung: dieselben Schlüssel wie `verdict` (payout/reclaim), sonst zeigte die Vertragsübersicht „Nachzahlung“
        $feed = ConsumptionService::withSettleTotals([$row('c', $bp, '2025-12-31', 60.0), $row('d', $bp, '2025-12-31', 20.0, true)], true);
        self::assertSame('payout', $feed[1]['settle_verdict']);
        $feed = ConsumptionService::withSettleTotals([$row('c', $bp, '2025-12-31', -60.0), $row('d', $bp, '2025-12-31', -20.0, true)], true);
        self::assertSame('reclaim', $feed[1]['settle_verdict']);
    }

    public function testPriceChangesInsideTheContractMeetTheBill(): void
    {
        // H2/H3 — Arbeitspreis 10 → 12 ct ab 16.07., Grundpreis 12 → 15 € ab 16.04., Abschlag 100 → 110 € ab 01.05.2025;
        // Ablesung monatlich bzw. nur alle zwei Monate (der Preiswechsel liegt zwischen zwei Ablesungen)
        // Rechnung: 196 Tage × 1,00 € + 169 Tage × 1,20 € = 398,80 €; Grundpreis 3 × 12 + 6 + 7,50 + 8 × 15 = 169,50 €;
        //   Abschläge 4 × 100 + 8 × 110 = 1.280 € → −711,70 €
        foreach ([1, 2] as $every) {
            $this->household('2025-12-01', ['2025-12-30'], $every);
            $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2024-01-01',
                'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 10.0], ['from' => '2025-07-16', 'ct_per_kwh' => 12.0]],
                'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0], ['from' => '2025-04-16', 'eur_per_month' => 15.0]],
                'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 100], ['from' => '2025-05-01', 'amount_eur' => 110]]]);
            self::assertEqualsWithDelta(-711.7, $this->rows()[0]['projected_end_balance'], 0.005, "Ablesung alle $every Monate");
        }
    }
}
