<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BackupService;
use Energietracker\Services\PeriodService;
use Energietracker\Services\TenancyBudgetService;
use Energietracker\Services\TenancyService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H3, F1008/MKT-07) — Mietverhältnis: Budget als Hilfsrechnung,
 * Abrechnungen mit Belegen, Preise und Vorauszahlung übernehmen, Fristen.
 */
#[CoversClass(TenancyService::class)]
#[CoversClass(TenancyBudgetService::class)]
final class TenancyTest extends ServiceTestCase
{
    private TenancyService $ten;
    private TenancyBudgetService $budget;
    private PeriodService $periods;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ten = new TenancyService($this->store, $this->meters, $this->i18n);
        $this->budget = new TenancyBudgetService($this->ten, $this->meters, $this->consumption, $this->i18n);
        $this->periods = new PeriodService($this->store, $this->meters, $this->i18n);
    }

    private function heatMeterWithYear(int $year, float $perMonth): string
    {
        $m = (string)$this->meters->create('waerme', ['name' => 'UVI', 'capture' => 'period', 'installed_on' => "$year-01-01"])['id'];
        for ($mo = 1; $mo <= 12; $mo++) {
            $this->periods->create('waerme', ['meter_id' => $m, 'month' => sprintf('%d-%02d', $year, $mo), 'value' => $perMonth]);
        }
        return $m;
    }

    /** Spezifikation: 9.000 kWh × 0,15 € = 1.350 € gegen 12 × 120 € = 1.440 € → 90 € Guthaben. */
    public function testTheHandCalculation(): void
    {
        $m = $this->heatMeterWithYear(2025, 750);
        $t = $this->ten->create([
            'start' => '2024-01-01', 'billing_anchor' => '01-01',
            'prepayments' => [['from' => '2024-01-01', 'heating_eur_month' => 120, 'operating_eur_month' => 0]],
            'prices' => [['from' => '2024-01-01', 'heat_eur_per_kwh' => '0,15']],
            'meter_ids' => ['heat' => [$m]],
        ]);
        $b = $this->budget->budget($t['id'], '2025-12-31');
        self::assertSame('2025-01-01', $b['period_from']);
        self::assertSame('2025-12-31', $b['period_to']);
        self::assertEqualsWithDelta(1350.0, $b['expected_eur'], 0.01);
        self::assertEqualsWithDelta(1440.0, $b['prepaid_eur'], 0.01);
        self::assertEqualsWithDelta(-90.0, $b['projected_result_eur'], 0.01, 'negativ = Guthaben');
        self::assertSame('low', $b['risk']);
        self::assertSame(113.0, $b['suggested_prepayment_eur']);
        self::assertCount(12, $b['months']);
        self::assertTrue($b['months'][0]['measured']);
        self::assertSame(0, $b['months_estimated']);
        self::assertSame([], $b['assumptions']);
    }

    /** Risiko: Guthaben niedrig, Nachzahlung bis 10 % der Vorauszahlung mittel, darüber hoch. */
    public function testRiskLevels(): void
    {
        $m = $this->heatMeterWithYear(2025, 750);   // 1.350 € erwartet
        $risk = function (float $perMonth) use ($m): ?string {
            $t = $this->ten->create([
                'start' => '2024-01-01', 'billing_anchor' => '01-01',
                'prepayments' => [['from' => '2024-01-01', 'heating_eur_month' => $perMonth, 'operating_eur_month' => 0]],
                'prices' => [['from' => '2024-01-01', 'heat_eur_per_kwh' => 0.15]],
                'meter_ids' => ['heat' => [$m]],
            ]);
            return $this->budget->budget($t['id'], '2025-12-31')['risk'];
        };
        self::assertSame('low', $risk(120.0), '−90 € Guthaben');
        self::assertSame('medium', $risk(110.0), '+30 € bei 1.320 € Vorauszahlung = 2,3 %');
        self::assertSame('high', $risk(100.0), '+150 € bei 1.200 € Vorauszahlung = 12,5 %');
    }

    public function testMissingMonthsAreEstimatedAndSaid(): void
    {
        $m = $this->heatMeterWithYear(2024, 750);   // Vorjahr gemessen, 2025 noch nichts
        $t = $this->ten->create([
            'start' => '2024-01-01', 'billing_anchor' => '01-01',
            'prepayments' => [['from' => '2024-01-01', 'heating_eur_month' => 100, 'operating_eur_month' => 50]],
            'fixed_costs' => [['from' => '2024-01-01', 'label' => 'Müll', 'eur_per_year' => 240]],
            'meter_ids' => ['heat' => [$m]],
        ]);
        $b = $this->budget->budget($t['id'], '2025-06-15');
        self::assertSame(12, $b['months_estimated']);
        self::assertContains('months_estimated', $b['assumptions']);
        self::assertContains('price_missing_heat', $b['assumptions'], 'ohne Wärmepreis gesagt, nicht verschwiegen');
        // ohne Preis zählen nur die Umlagen: 240 € gegen 12 × 150 €
        self::assertEqualsWithDelta(240.0, $b['expected_eur'], 0.01);
        self::assertEqualsWithDelta(1800.0, $b['prepaid_eur'], 0.01);
        self::assertEqualsWithDelta(750.0, $b['months'][0]['heat_kwh'], 0.5, 'Vorjahresmonat als Schätzung');
    }

    public function testThePeriodFollowsTheAnchorAndTheTenancyStart(): void
    {
        self::assertSame(['2025-07-01', '2026-06-30'], TenancyBudgetService::periodAround('07-01', '2025-12-01'));
        self::assertSame(['2024-07-01', '2025-06-30'], TenancyBudgetService::periodAround('07-01', '2025-06-30'));
        $t = $this->ten->create(['start' => '2025-03-15', 'billing_anchor' => '01-01']);
        $b = $this->budget->budget($t['id'], '2025-06-01');
        self::assertSame('2025-03-15', $b['period_from'], 'erster Zeitraum beginnt mit dem Einzug');
        self::assertEqualsWithDelta(17 / 31, $b['months'][0]['share'], 0.0001);
    }

    public function testAStatementSetsPricesAndThePrepaymentAndKeepsItsPdf(): void
    {
        $t = $this->ten->create(['start' => '2024-01-01']);
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $pdf = $att->store(AttachmentServiceTest::PDF, 'statement_pdf');
        $s = $this->ten->createStatement($t['id'], [
            'period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'received_on' => '2025-05-10',
            'total_cost_eur' => 2100, 'prepaid_eur' => 1800,
            'heat' => ['consumption' => 8000, 'unit' => 'kWh', 'cost_eur' => 1200],
            'positions' => [
                ['label' => 'Warmwasser', 'category' => 'warm_water', 'amount_eur' => 300, 'consumption' => 20],
                ['label' => 'Kaltwasser', 'category' => 'cold_water', 'amount_eur' => 150, 'consumption' => 50],
                ['label' => 'Abwasser', 'category' => 'sewage', 'amount_eur' => 100],
            ],
            'new_prepayment' => ['from' => '2025-06-01', 'heating_eur_month' => 130, 'operating_eur_month' => 60],
            'attachment_ids' => [$pdf['id']],
            'apply_prices' => true, 'apply_prepayment' => true,
        ]);
        self::assertSame(300.0, $s['result_eur'], 'positiv = Nachzahlung');
        self::assertSame(['heat_eur_per_kwh' => 0.15, 'warm_water_eur_per_m3' => 15.0, 'cold_water_eur_per_m3' => 5.0],
            TenancyService::derivePrices($s));
        $t2 = $this->ten->get($t['id']);
        self::assertSame('2025-01-01', $t2['prices'][0]['from']);
        self::assertSame('statement', $t2['prices'][0]['source']);
        self::assertEquals(130, $t2['prepayments'][0]['heating_eur_month']);
        self::assertSame(['type' => 'tenancy_statement', 'id' => $s['id']], $att->get($pdf['id'])['ref']);

        // Backup → Restore bringt Abrechnung und PDF byte-gleich zurück
        $backups = new BackupService($this->store, $this->i18n);
        $backup = $backups->export();
        $this->ten->delete($t['id']);
        $att->delete($pdf['id']);
        self::assertSame([], $this->ten->list());
        $backups->import($backup);
        self::assertSame($s['id'], $this->ten->statements($t['id'])[0]['id']);
        self::assertSame(AttachmentServiceTest::PDF, file_get_contents($att->path($pdf['id'])));
    }

    public function testValidationNamesTheProblem(): void
    {
        self::assertSame('errors.tenancy.dateInvalid', $this->key(fn() => $this->ten->create(['start' => '2025-02-30'])));
        self::assertSame('errors.tenancy.endBeforeStart', $this->key(fn() => $this->ten->create(['start' => '2025-02-01', 'end' => '2025-01-01'])));
        self::assertSame('errors.tenancy.anchorInvalid', $this->key(fn() => $this->ten->create(['start' => '2025-02-01', 'billing_anchor' => '13-01'])));
        self::assertSame('errors.tenancy.meterNotFound', $this->key(fn() => $this->ten->create(['start' => '2025-02-01', 'meter_ids' => ['heat' => ['m_gas_default']]])));
        self::assertSame('errors.tenancy.amountInvalid', $this->key(fn() => $this->ten->create(['start' => '2025-02-01', 'prepayments' => [['from' => '2025-02-01', 'heating_eur_month' => -5]]])));
        $t = $this->ten->create(['start' => '2025-02-01']);
        self::assertSame('errors.tenancy.periodInvalid', $this->key(fn() => $this->ten->createStatement($t['id'], ['period_from' => '2025-12-31', 'period_to' => '2025-01-01'])));
    }

    public function testTheAgendaKnowsBothDeadlines(): void
    {
        $this->settings->set(['wohnverhaeltnis' => 'miete']);
        $t = $this->ten->create(['start' => '2020-01-01', 'billing_anchor' => '01-01', 'label' => 'Wohnung']);
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new \Energietracker\Services\ReminderService($this->store, $this->settings, $this->i18n),
            new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
                new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
                new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n),
            $this->i18n, $this->ten);
        $due = array_values(array_filter($agenda->events(400, '2026-03-01'), fn($e) => $e['kind'] === 'tenancy_statement_due'));
        self::assertCount(1, $due);
        self::assertSame('2026-12-31', $due[0]['date'], 'Zeitraum 2025 → spätestens Ende 2026');

        $this->ten->createStatement($t['id'], ['period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'received_on' => '2026-04-20',
            'total_cost_eur' => 1, 'prepaid_eur' => 1]);
        $events = $agenda->events(400, '2026-05-01');
        self::assertSame([], array_values(array_filter($events, fn($e) => $e['kind'] === 'tenancy_statement_due')));
        $obj = array_values(array_filter($events, fn($e) => $e['kind'] === 'objection_deadline'));
        self::assertSame('2027-04-20', $obj[0]['date']);

        $this->settings->set(['wohnverhaeltnis' => 'eigentum']);
        self::assertSame([], array_values(array_filter($agenda->events(400, '2026-05-01'), fn($e) => str_starts_with($e['kind'], 'tenancy') || $e['kind'] === 'objection_deadline')),
            'Eigentümer sehen nichts davon');
    }

    private function key(callable $fn): string
    {
        try {
            $fn();
        } catch (LocalizedException $e) {
            return $e->key;
        }
        self::fail('LocalizedException erwartet');
    }
}
