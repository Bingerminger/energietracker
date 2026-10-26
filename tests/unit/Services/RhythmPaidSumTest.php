<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Paket auswertung, Nachtrag zu V1) — Abschlag alle zwei Monate: Saldo-Karte,
 * Werkstatt, Abrechnungsjahr (auch im Jahresbericht), „Rechnung prüfen“ und die
 * gemessenen Tage zählen dieselben Raten, jede ganz an ihrem Tag.
 *
 * Handrechnung: Vertrag ab 01.01.2026, 160 € alle zwei Monate ab 15.01.2026, also am
 * 15.01., 15.03., 15.05., 15.07., 15.09. und 15.11. Stichtag 10.09.2026 (zwischen dem
 * 15.07. und dem 15.09.): gezahlt 4 × 160 = 640 €. Bis zum Nachtrag verteilten
 * Abrechnungsjahr und „Rechnung prüfen“ die September-Rate über die Tage ihres Monats:
 * 640 + 160 × 10/30 = 693,33 €.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(BillService::class)]
#[CoversClass(ScenarioService::class)]
final class RhythmPaidSumTest extends ServiceTestCase
{
    use ReportFixture;

    private const TODAY = '2026-09-10';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_r', 'serial' => null, 'installed_on' => '2025-12-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->settings->set(['billing_cycle_anchor_strom' => '01-01']);
        // 90 kWh je Monat, abgelesen am Monatsersten, zuletzt am Stichtag (10.09.: 30 kWh)
        $rows = [];
        for ($i = 0; $i <= 9; $i++) $rows[] = ['date' => date('Y-m-d', (int)strtotime("2025-12-01 +$i months")), 'counter' => 90.0 * $i, 'device_id' => 'd_r'];
        $rows[] = ['date' => self::TODAY, 'counter' => 90.0 * 9 + 30.0, 'device_id' => 'd_r'];
        $this->setReadings('strom', $this->meterId, $rows);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2026-01-15', 'amount_eur' => 160, 'every_months' => 2]]]);
    }

    private function meter(): array
    {
        return $this->meters->get('strom', $this->meterId);
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function periods(): BillingPeriodService
    {
        return new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills());
    }

    public function testCardWorkshopBillingYearAndReportCountTheSameInstallments(): void
    {
        $card = $this->consumption->contractStatus('strom', $this->meter(), self::TODAY)['contracts'][0];
        self::assertEqualsWithDelta(640.0, $card['advance_paid'], 0.01, 'Saldo-Karte: vier Raten bis zum Stichtag');

        // Abrechnungsjahr 2026, Stand Stichtag (dieselbe Summe steht im Anhang des Jahresberichts)
        $running = array_values(array_filter($this->periods()->forTarget('strom', $this->meter(), false, self::TODAY)['periods'], fn($p) => !empty($p['running'])))[0];
        self::assertEqualsWithDelta(640.0, $running['advances'], 0.01, 'Abrechnungsjahr: nicht 693,33 (September-Rate anteilig)');

        // gemessene Tage: der September bis zum 10. trägt die Rate vom 15. noch nicht
        $by = array_column($this->consumption->forMeter('strom', $this->meter()), null, 'ym');
        self::assertSame(160.0, (float)$by['2026-09']['advance_eur'], 'fällig im September');
        self::assertSame(0.0, (float)$by['2026-09']['advance_measured_eur']);
        self::assertSame([['date' => '2026-09-15', 'amount_eur' => 160.0]], array_map(fn($r) => array_intersect_key($r, ['date' => 1, 'amount_eur' => 1]), $by['2026-09']['advance_dates']));
        $measured = array_sum(array_map(fn($m) => (float)($m['advance_measured_eur'] ?? 0), array_filter($by, fn($m) => str_starts_with($m['ym'], '2026-'))));
        self::assertEqualsWithDelta(640.0, $measured, 0.01, 'Kachel „Abschläge bis 10.09.“');

        // Werkstatt: Saldo bis heute aus der Karte (Kosten minus dieselben Abschläge)
        $f = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $ws = (new ScenarioService($f, $this->consumption, $this->contracts, $this->meters, $this->settings,
            new TariffSwitchService($f, $this->contracts, $this->meters, $this->i18n), $this->periods()))
            ->compute(['utility' => 'strom', 'meter_id' => $this->meterId], self::TODAY);
        self::assertEqualsWithDelta($card['current_balance'], $ws['billing']['balance_so_far'], 0.01);
        self::assertEqualsWithDelta($card['cost_to_date'] - 640.0, $ws['billing']['balance_so_far'], 0.02);

        // Jahresbericht: der Saldo der Karte und im Anhang „Abrechnungsjahre“ dieselben 640 €
        $report = $this->reportServices()['model']->build(2026, ['today' => self::TODAY, 'billing' => true]);
        $bal = array_values(array_filter((array)($report['money']['balances'] ?? []), fn($b) => $b['utility'] === 'strom'))[0] ?? null;
        self::assertNotNull($bal);
        self::assertEqualsWithDelta($card['projected_end_balance'], $bal['projected_end_balance'], 0.01);
        $appendix = array_values(array_filter((array)$report['billing'], fn($x) => $x['utility'] === 'strom'))[0] ?? null;
        self::assertNotNull($appendix);
        self::assertSame($this->i18n->number(640.0, 2), $appendix['rows'][0][5], 'Spalte Abschläge der laufenden Periode');
    }

    public function testTheBillCheckCountsTheInstallmentsOfItsPeriod(): void
    {
        $bills = $this->bills();
        $b = $bills->create('strom', ['meter_id' => $this->meterId, 'period_from' => '2026-01-01', 'period_to' => self::TODAY, 'issued_on' => '2026-09-20',
            'invoice' => ['energy_kwh' => 750.0, 'amount_eur' => 400.0, 'advances_paid_eur' => 640.0]]);
        // 15.01., 15.03., 15.05., 15.07. — die Rate vom 15.09. liegt nach dem Zeitraum (bis v3.8: 693,33)
        self::assertEqualsWithDelta(640.0, $bills->compare('strom', $b['id'])['ours']['advances'], 0.01);
    }

    public function testAMonthlyAdvanceStaysProRata(): void
    {
        // monatlich 80 €: September bis zum 10. = 80 × 10/30 = 26,67 (wie bisher)
        $c = $this->contracts->list('strom', $this->meterId)[0];
        $this->contracts->update('strom', $c['id'], ['advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 80]]]);
        $by = array_column($this->consumption->forMeter('strom', $this->meter()), null, 'ym');
        self::assertArrayNotHasKey('advance_dates', $by['2026-09']);
        self::assertEqualsWithDelta(80.0 * 9 / 30, ConsumptionService::advanceBetween($by['2026-09'], '2026-09-01', '2026-09-10', []), 0.01);
    }
}
