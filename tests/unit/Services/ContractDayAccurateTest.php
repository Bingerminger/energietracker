<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\ForecastService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.9.0 (Review CALC-10, CALC-11) — Verträge wie die Rechnung: tagesgenaue
 * Preise, Weiterlaufen ohne Kündigung, Kündigungsstichtag statt Vertragsende.
 *
 * Datumsrechnung in den Tests immer vom Monatsersten aus (Lesson 26).
 */
#[CoversClass(ContractService::class)]
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ForecastService::class)]
final class ContractDayAccurateTest extends ServiceTestCase
{
    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
    }

    /** 100 kWh je Monat, Ablesung am Ersten, von … bis … (letzte Ablesung am Ersten danach). */
    private function monthlyReadings(string $fromYm, string $toYm): void
    {
        $rows = [];
        $counter = 0.0;
        for ($t = strtotime($fromYm . '-01'); date('Y-m', $t) <= $toYm; $t = strtotime('+1 month', $t)) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => $counter, 'device_id' => 'd1'];
            $counter += 100.0;
        }
        $rows[] = ['date' => date('Y-m-d', strtotime($toYm . '-01 +1 month')), 'counter' => $counter, 'device_id' => 'd1'];
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function rows(): array
    {
        return array_column($this->consumption->forMeter('strom', $this->meters->get('strom', $this->meterId)), null, 'ym');
    }

    private function contractStates(): array
    {
        return array_column($this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId))['contracts'], null, 'contract_id');
    }

    // ── Preise tagesgenau (CALC-10) ─────────────────────────────────────

    public function testPriceChangeMidMonthAppliesFromItsDay(): void
    {
        $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0], ['from' => '2024-03-15', 'ct_per_kwh' => 40.0]],
            'base_prices'    => [['from' => '2024-01-01', 'eur_per_month' => 10.0], ['from' => '2024-03-15', 'eur_per_month' => 12.0]],
        ]);
        $this->monthlyReadings('2024-01', '2024-05');
        $march = $this->rows()['2024-03'];
        self::assertEqualsWithDelta(100 * 14 / 31 * 0.30 + 100 * 17 / 31 * 0.40, $march['kwh_cost'], 0.01,
            'bis v2.8: 30,00 € — die Erhöhung griff erst im April');
        self::assertEqualsWithDelta(10.0 * 14 / 31 + 12.0 * 17 / 31, $march['base_price_eur'], 0.01);
        self::assertArrayNotHasKey('contract_parts', $march, 'ein Vertrag, kein Teilen');
        self::assertEqualsWithDelta(40.0, $this->rows()['2024-04']['working_price_ct'], 0.001);
    }

    // ── Weiterlaufen ohne Kündigung (CALC-10) ───────────────────────────

    public function testEndedContractRunsOnWithItsLastPrices(): void
    {
        $a = $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T',
            'start' => '2024-01-01', 'end' => '2024-02-29',
            'working_prices'   => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices'      => [['from' => '2024-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 50.0]],
        ]);
        $this->monthlyReadings('2024-01', '2024-04');
        $april = $this->rows()['2024-04'];
        self::assertSame($a['id'], $april['contract_id']);
        self::assertTrue($april['contract_assumed']);
        self::assertEqualsWithDelta(30.0 + 10.0, $april['cost'], 0.01, 'bis v2.8: 0 € nach dem Vertragsende');
        self::assertEqualsWithDelta(50.0, $april['advance_eur'], 0.01, 'der Abschlag läuft weiter');
        self::assertFalse($this->rows()['2024-02']['contract_assumed']);

        $s = $this->contractStates()[$a['id']];
        self::assertTrue($s['renewed']);
        self::assertTrue($s['is_current']);
        self::assertFalse($s['is_past']);
        self::assertSame('renewed', $s['notice_basis']);
        self::assertGreaterThan(date('Y-m-d'), $s['effective_end'], 'läuft bis zur nächsten Abrechnung');
    }

    /** Der Tarifwechsel sieht den weiterlaufenden Vertrag, nicht „kein Vertrag". */
    public function testTariffSwitchStartsFromTheRenewedContract(): void
    {
        $a = $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T',
            'start' => '2024-01-01', 'end' => '2024-12-31', 'notice_period_months' => 3,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
        ]);
        $this->monthlyReadings('2024-01', '2025-06');
        $forecast = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $switch = new \Energietracker\Services\TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n);
        $r = $switch->analyze('strom', $this->meterId, ['today' => '2025-07-10']);
        self::assertSame($a['id'], $r['current']['contract_id'] ?? null);
        self::assertTrue($r['current']['renewed']);
        self::assertSame('renewed', $r['current']['basis']);
        self::assertSame('2025-08-11', $r['switch_date'], 'höchstens ein Monat statt drei');
    }

    public function testCancelledContractDoesNotRunOn(): void
    {
        $a = $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T',
            'start' => '2024-01-01', 'end' => '2024-02-29', 'auto_renews' => false,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
        ]);
        self::assertFalse($a['auto_renews']);
        $this->monthlyReadings('2024-01', '2024-04');
        self::assertNull($this->rows()['2024-04']['contract_id']);
        $s = $this->contractStates()[$a['id']];
        self::assertFalse($s['renewed']);
        self::assertTrue($s['is_past']);
    }

    /** Lücke zwischen zwei Verträgen (gekündigt, Nachfolger später): keiner zahlt sie. */
    public function testGapBetweenContractsIsChargedToNeither(): void
    {
        $a = $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T',
            'start' => '2024-01-01', 'end' => '2024-03-10', 'auto_renews' => false,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
        ]);
        $b = $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'B', 'tariff_name' => 'T', 'start' => '2024-03-20',
            'working_prices' => [['from' => '2024-03-20', 'ct_per_kwh' => 40.0]],
        ]);
        $this->monthlyReadings('2024-01', '2024-04');
        $parts = array_column($this->rows()['2024-03']['contract_parts'], null, 'contract_id');
        self::assertSame(10, $parts[$a['id']]['days'], 'A endet am 10.');
        self::assertSame(12, $parts[$b['id']]['days'], 'B ab dem 20.');
        self::assertEqualsWithDelta(100 * 10 / 31 * 0.30, $parts[$a['id']]['kwh_cost'], 0.01);
        self::assertEqualsWithDelta(100 * 12 / 31 * 0.40, $parts[$b['id']]['kwh_cost'], 0.01);
    }

    // ── Kündigungsfristen (CALC-11) ─────────────────────────────────────

    public function testSwitchTimingKnowsDaysModesAndRenewal(): void
    {
        $today = '2026-09-25';
        $t = fn(array $c, bool $renewed = false) => $this->contracts->switchTiming($c, $today, $renewed);

        // Befristet, Frist in Tagen
        $r = $t(['start' => '2026-01-01', 'end' => '2026-12-31', 'notice_period_days' => 14]);
        self::assertSame('2026-12-17', $r['cancel_by']);
        self::assertSame('2027-01-01', $r['switch_date']);

        // Unbefristet, jederzeit mit zwei Wochen (Grundversorgung)
        $r = $t(['start' => '2020-01-01', 'notice_period_days' => 14, 'notice_mode' => 'any_day']);
        self::assertSame('2026-10-10', $r['switch_date'], '25.09. + 14 Tage = 09.10., Wechsel am 10.');

        // Unbefristet, zum Monatsende (bisheriges Verhalten, Standard)
        $r = $t(['start' => '2020-01-01', 'notice_period_months' => 1]);
        self::assertSame('2026-11-01', $r['switch_date']);
        self::assertSame('open_ended', $r['basis']);

        // Befristet, aber jederzeit zum Monatsende kündbar: früher als das Ende
        $r = $t(['start' => '2026-01-01', 'end' => '2027-06-30', 'notice_period_months' => 1, 'notice_mode' => 'month_end']);
        self::assertSame('2026-11-01', $r['switch_date']);
        // … und nie später als das Ende
        $r = $t(['start' => '2026-01-01', 'end' => '2026-10-15', 'notice_period_months' => 1, 'notice_mode' => 'month_end']);
        self::assertSame('2026-10-16', $r['switch_date']);

        // Verlängert: höchstens ein Monat, auch bei längerer Frist (§ 309 Nr. 9 b BGB)
        $r = $t(['start' => '2024-01-01', 'end' => '2025-12-31', 'notice_period_months' => 3], true);
        self::assertSame('renewed', $r['basis']);
        self::assertSame('2026-10-26', $r['switch_date'], '25.09. + 1 Monat = 25.10., Wechsel am 26.');
        $r = $t(['start' => '2024-01-01', 'end' => '2025-12-31', 'notice_period_days' => 14], true);
        self::assertSame('2026-10-10', $r['switch_date'], 'kürzere eigene Frist gilt');
    }

    public function testNoticeFieldsAreValidated(): void
    {
        $base = ['meter_id' => $this->meterId, 'provider' => 'A', 'start' => '2026-01-01',
                 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]]];
        $c = $this->contracts->create('strom', $base + ['notice_period_days' => '14', 'notice_mode' => 'any_day', 'auto_renews' => '1']);
        self::assertSame(14, $c['notice_period_days']);
        self::assertSame('any_day', $c['notice_mode']);
        self::assertTrue($c['auto_renews']);
        $c = $this->contracts->create('strom', $base + ['notice_period_days' => '', 'notice_mode' => '']);
        self::assertNull($c['notice_period_days'], 'leer heißt nicht gepflegt, nicht 0');
        self::assertNull($c['notice_mode']);
        self::assertNull($c['auto_renews'], 'nicht angegeben = verlängert sich (Standard)');
        $this->expectException(\InvalidArgumentException::class);
        $this->contracts->create('strom', $base + ['notice_mode' => 'irgendwann']);
    }

    public function testReminderIsDueBeforeTheCancellationDeadline(): void
    {
        $first = date('Y-m-01');
        // Ende in knapp drei Monaten, Frist ein Monat → Stichtag in knapp zwei
        $end = date('Y-m-d', strtotime($first . ' +3 months -1 day'));
        $c = $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T',
            'start' => date('Y-m-01', strtotime($first . ' -9 months')), 'end' => $end,
            'notice_period_months' => 1,
            'working_prices' => [['from' => date('Y-m-01', strtotime($first . ' -9 months')), 'ct_per_kwh' => 30.0]],
        ]);
        $s = $this->contractStates()[$c['id']];
        self::assertSame('cancel_by', $s['remind_basis']);
        $cancelBy = $s['cancel_by'];
        self::assertLessThan($end, $cancelBy);
        $days = (int)(new \DateTimeImmutable(date('Y-m-d')))->diff(new \DateTimeImmutable($cancelBy))->format('%r%a');
        self::assertSame($days, $s['days_to_cancel']);
        $stage = $days <= 1 ? 3 : ($days <= 30 ? 2 : ($days <= 90 ? 1 : 0));
        self::assertSame($stage, $s['remind_stage'], 'Stufen am Kündigungsstichtag, nicht am Ende');
        self::assertSame($stage > 0, $s['should_remind']);
        self::assertFalse($s['cancel_missed']);
    }

    /** Die Empfehlung nennt den Kündigungsstichtag, nicht das Vertragsende. */
    public function testRecommendationNamesTheCancellationDeadline(): void
    {
        $first = date('Y-m-01');
        $end = date('Y-m-d', strtotime($first . ' +2 months -1 day'));
        $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T',
            'start' => '2024-01-01', 'end' => $end, 'notice_period_days' => 14,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
        ]);
        $recos = new \Energietracker\Services\RecommendationService(
            $this->store, $this->meters, $this->consumption, $this->settings,
            new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n
        );
        $r6 = array_values(array_filter($recos->all(), fn($r) => str_starts_with((string)$r['id'], 'r6_')));
        self::assertCount(1, $r6);
        $cancelBy = date('Y-m-d', strtotime($end . ' -14 days'));
        self::assertStringContainsString($this->i18n->date($cancelBy), $r6[0]['title']);
    }

    public function testMissedDeadlineIsFlaggedNotReminded(): void
    {
        $end = date('Y-m-d', strtotime(date('Y-m-d') . ' +10 days'));
        $c = $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T',
            'start' => '2024-01-01', 'end' => $end, 'notice_period_months' => 1,
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
        ]);
        $s = $this->contractStates()[$c['id']];
        self::assertTrue($s['cancel_missed'], 'Stichtag vorbei, Vertrag läuft noch');
        self::assertFalse($s['should_remind']);
    }

    public function testUpcomingPriceIncreaseIsReported(): void
    {
        $first = date('Y-m-01');
        $next  = date('Y-m-d', strtotime($first . ' +1 month'));
        $c = $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0], ['from' => $next, 'ct_per_kwh' => 35.0]],
            'base_prices'    => [['from' => '2024-01-01', 'eur_per_month' => 10.0], ['from' => $next, 'eur_per_month' => 9.0]],
        ]);
        $pi = $this->contractStates()[$c['id']]['price_increase'];
        self::assertSame($next, $pi['from']);
        self::assertSame([30.0, 35.0], $pi['working_price_ct']);
        self::assertNull($pi['base_price_eur'], 'eine Senkung ist keine Erhöhung');
    }

    // ── Kalender und Prognose (CALC-10) ─────────────────────────────────

    public function testAdvanceOfAContractStartingMidMonthIsProrated(): void
    {
        $first = date('Y-m-01');
        $startMonth = date('Y-m-01', strtotime($first . ' -2 months'));
        $start = date('Y-m-d', strtotime($startMonth . ' +14 days'));
        $dim = (int)date('t', strtotime($startMonth));
        $c = $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T', 'start' => $start,
            'working_prices'   => [['from' => $start, 'ct_per_kwh' => 30.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 100.0]],
        ]);
        $s = $this->contractStates()[$c['id']];
        self::assertEqualsWithDelta(100.0 * ($dim - 14) / $dim + 200.0, $s['advance_paid'], 0.01,
            'erster Monat anteilig — vorher fehlte er ganz');
    }

    public function testForecastSplitsAMonthAtAPriceChange(): void
    {
        $first = date('Y-m-01');
        $this->monthlyReadings(date('Y-m', strtotime($first . ' -18 months')), date('Y-m', strtotime($first . ' -1 month')));
        $change = date('Y-m-d', strtotime($first . ' +14 days'));
        $this->contracts->create('strom', [
            'meter_id' => $this->meterId, 'provider' => 'A', 'tariff_name' => 'T',
            'start' => date('Y-m-01', strtotime($first . ' -18 months')),
            'working_prices' => [['from' => date('Y-m-01', strtotime($first . ' -18 months')), 'ct_per_kwh' => 30.0],
                                 ['from' => $change, 'ct_per_kwh' => 40.0]],
        ]);
        $f = (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))
            ->forMeter('strom', $this->meters->get('strom', $this->meterId));
        $row = $f['forecast'][0];
        self::assertSame(substr($first, 0, 7), $row['ym']);
        $dim = (int)date('t', strtotime($first));
        $vol = $row['kwh'];
        self::assertEqualsWithDelta($vol * (14 / $dim * 0.30 + ($dim - 14) / $dim * 0.40), $row['cost_estimated'], 0.05);
    }
}
