<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ReportModelService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (#34-Nachzug) — Wasser mit Abschlägen bekommt den Saldo nach
 * Kalender wie Gas und Strom: Abschlagsvorschlag (`suggested_advance`),
 * Saldo-Verlauf (`balance_path`) und im Jahresbericht Saldo und Abschlags-Rat
 * (`has_advance`). Ohne Abschläge bleibt es beim Saldo über die Ablesemonate.
 *
 * Daten (erfunden): 10 m³ je Monat, Trinkwasser 2,50 €/m³, Schmutzwasser
 * 3,00 €/m³ (nach Trinkwasser), Grundpreis 8 €/Monat, Niederschlagswasser
 * 100 m² × 1,20 €/m²·a = 10 €/Monat, Abschlag 40 €/Monat.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ReportModelService::class)]
final class WaterCalendarBalanceTest extends ServiceTestCase
{
    use ReportFixture;

    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('wasser', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [];
        for ($m = 0; $m <= 12; $m++) $rows[] = ['date' => date('Y-m-d', (int)strtotime("2025-01-01 +$m months")), 'counter' => 10.0 * $m, 'device_id' => 'd1'];
        $this->setReadings('wasser', $this->meterId, $rows);
    }

    private function contract(?string $end, bool $advances = true, array $bonuses = []): void
    {
        $this->contracts->create('wasser', ['meter_id' => $this->meterId, 'provider' => 'Wasserwerk Beispielstadt', 'start' => '2025-01-01', 'end' => $end,
            'auto_renews' => $end === null ? null : false,   // beendet heißt hier: läuft nicht weiter
            'trinkwasser' => ['working_prices' => [['from' => '2025-01-01', 'ct_per_m3' => 250]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 8]]],
            'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => '2025-01-01', 'ct_per_m3' => 300]]],
            'niederschlagswasser' => ['rates' => [['from' => '2025-01-01', 'eur_per_m2_year' => 1.2, 'versiegelte_flaeche_m2' => 100]]],
            'advance_payments' => $advances ? [['from' => '2025-01-01', 'amount_eur' => 40]] : [], 'bonuses' => $bonuses]);
    }

    /** v3.7.0 (#35) — Wasser: die Gutschrift mit Art und Notiz an der Monatszeile, wie bei Strom und Gas. */
    public function testAWaterCreditCarriesItsTypeAndNote(): void
    {
        $this->contract('2025-12-31', true, [['credit_date' => '2025-06-10', 'amount_eur' => 30, 'type' => 'sonstige', 'label' => 'Kulanz']]);
        $rows = array_column($this->consumption->forMeter('wasser', $this->meters->get('wasser', $this->meterId)), null, 'ym');
        self::assertSame([['credit_date' => '2025-06-10', 'amount_eur' => 30.0, 'type' => 'sonstige', 'label' => 'Kulanz']], $rows['2025-06']['bonus_items']);
        self::assertSame(30.0, (float)$rows['2025-06']['bonus_eur']);
        self::assertArrayNotHasKey('bonus_items', $rows['2025-05']);
    }

    private function contractRow(): array
    {
        return $this->consumption->contractStatus('wasser', $this->meters->get('wasser', $this->meterId))['contracts'][0];
    }

    /** Ein abgeschlossenes Jahr: Saldo nach Kalender = Kosten der Monate − Abschläge, wie bisher. */
    public function testAClosedYearBalancesLikeTheMonths(): void
    {
        $this->contract('2025-12-31');
        $c = $this->contractRow();
        self::assertSame('forecast', $c['projection_method'], 'Saldo nach Kalender');
        $cost = 12 * (10 * (2.5 + 3.0)) + 12 * 8 + 12 * 10;
        self::assertEqualsWithDelta($cost, $c['cost_to_date'], 0.01);
        self::assertEqualsWithDelta($cost - 12 * 40, $c['current_balance'], 0.01);
        self::assertEqualsWithDelta($c['current_balance'], $c['projected_end_balance'], 0.01);
        self::assertEqualsWithDelta(12 * 10 * 5.5, $c['energy_cost_to_date'], 0.01, 'Trink- und Schmutzwasser je m³');
        self::assertEqualsWithDelta(12 * 18, $c['base_to_date'], 0.01, 'Grundpreis und Niederschlagswasser');
    }

    /** Der laufende Vertrag: Vorschlag für den Abschlag und Saldo-Verlauf bis zur nächsten Abrechnung. */
    public function testTheRunningContractSuggestsAnAdvanceAndDrawsThePath(): void
    {
        $this->contract(null);
        // v3.9.0 (L12) — Stichtag ein halbes Jahr voraus: genug Abschläge bis zur Abrechnung für einen Vorschlag
        $this->settings->set(['billing_cycle_anchor_wasser' => date('m-01', strtotime(date('Y-m-01') . ' +6 months'))]);
        $c = $this->contractRow();
        self::assertTrue($c['is_current']);
        self::assertNotNull($c['suggested_advance']);
        self::assertGreaterThan(40.0, (float)$c['suggested_advance'], 'Kosten über dem Abschlag: höher');
        self::assertNotEmpty($c['balance_path']);
        self::assertEqualsWithDelta((float)$c['projected_end_balance'], (float)end($c['balance_path'])['balance'], 0.02, 'Verlauf endet beim erwarteten Saldo');
        // die geschätzten Tage seit der letzten Ablesung: Trink- UND Schmutzwasser je m³
        // (10 m³ im Monat ≈ 0,33 m³ am Tag × 5,50 €), nicht nur der Trinkwasserpreis
        // v3.9.0 (V1) — geschätzt wird ab der letzten Ablesung, frühestens ab dem Beginn der Abrechnungsperiode
        $gapDays = (int)round((strtotime((string)$c['balance_as_of']) - strtotime(max((string)$c['measured_until'], (string)$c['balance_from']))) / 86400);
        self::assertGreaterThan(30, $gapDays);
        self::assertEqualsWithDelta(120 / 365 * 5.5, (float)$c['estimated_cost_to_date'] / $gapDays, 0.25);
        // Jahresbericht: Saldo und Abschlags-Rat auch für Wasser
        $m = $this->reportServices()['model']->build(2025, ['today' => date('Y-m-d')]);
        self::assertContains('wasser', array_column($m['money']['balances'] ?? [], 'utility'));
        self::assertNotEmpty(array_filter($m['tasks'], fn($t) => ($t['rule'] ?? '') === 'advance' && ($t['utility'] ?? null) === 'wasser'),
            'Abschlags-Rat für Wasser');
    }

    /** Ohne Abschläge bleibt Wasser beim Saldo über die Ablesemonate (kein Vorschlag, kein Verlauf). */
    public function testWithoutAdvancesNothingChanges(): void
    {
        $this->contract(null, false);
        $c = $this->contractRow();
        self::assertSame('flat_average', $c['projection_method']);
        self::assertArrayNotHasKey('suggested_advance', $c);
        self::assertFalse(ConsumptionService::waterWithAdvances('wasser', ['advance_payments' => []]));
        self::assertFalse(ConsumptionService::waterWithAdvances('gas', ['advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 1]]]));
    }
}
