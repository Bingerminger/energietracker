<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\StromSaldoService;
use Energietracker\Services\SummaryService;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.7.0 (GitHub #33, Nachzug) — `GET /api/summary` nennt neben `period_end`
 * den letzten Tag der Periode. `period_end` bleibt für Home Assistant, wie es
 * war: bei offenen Verträgen der Stichtag, also der erste Tag der nächsten
 * Periode; `period_last_day` ist der Tag davor. Mit gepflegtem Ende sind
 * beide das Ende selbst.
 */
final class SummaryPeriodLastDayTest extends ServiceTestCase
{
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = date('Y-m-d');
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', (int)strtotime("{$this->today} $offset days"));
    }

    /** Erster Tag der nächsten Periode bei Stichtag MM-TT (wie ConsumptionService::nextBillingAnchor). */
    private function nextAnchor(string $md): string
    {
        $y = (int)substr($this->today, 0, 4);
        $c = sprintf('%04d-%s', $y, $md);
        return $c < $this->today ? sprintf('%04d-%s', $y + 1, $md) : $c;
    }

    private function seedStrom(array $contract): string
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => $this->day(-500), 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['date' => $this->day(-500), 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => $this->day(-20), 'counter' => 4000.0, 'device_id' => 'd1'],
        ]);
        $this->contracts->create('strom', $contract + [
            'meter_id' => $meterId, 'provider' => 'Testwerk',
            'working_prices'   => [['from' => $contract['start'], 'ct_per_kwh' => 30]],
            'advance_payments' => [['from' => $contract['start'], 'amount_eur' => 80]],
        ]);
        return $meterId;
    }

    /** @return array<string,mixed> Vertragsblock des Strom-Zählers aus /api/summary */
    private function summaryContract(string $meterId): array
    {
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $reminders, $recs, $this->i18n);
        $summary = new SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $deliveries, new PvSummaryService($this->consumption), new StromSaldoService($this->consumption), $agenda,
            new InstanceService($this->store));
        $row = array_values(array_filter($summary->build('strom', null, $this->today)['meters'], fn($m) => $m['meter_id'] === $meterId))[0];
        self::assertIsArray($row['contract'], 'laufender Vertrag erwartet');
        return $row['contract'];
    }

    public function testOpenContractEndsTheDayBeforeTheSettlementDate(): void
    {
        $this->settings->set(['billing_cycle_anchor_strom' => '09-01']);
        $meterId = $this->seedStrom(['start' => $this->day(-400)]);
        $c = $this->summaryContract($meterId);
        $anchor = $this->nextAnchor('09-01');
        self::assertSame($anchor, $c['period_end'], 'period_end bleibt der Stichtag (erster Tag der nächsten Periode)');
        self::assertSame(date('Y-m-d', (int)strtotime("$anchor -1 day")), $c['period_last_day'], '01-09 heißt: bis 31.08.');
        self::assertStringEndsWith('-08-31', (string)$c['period_last_day']);
        $keys = array_keys($c);
        self::assertSame(array_search('period_end', $keys, true) + 1, array_search('period_last_day', $keys, true), 'neben period_end');
    }

    public function testContractWithEndKeepsTheEndInBothFields(): void
    {
        $end = $this->day(40);
        $meterId = $this->seedStrom(['start' => $this->day(-300), 'end' => $end, 'notice_period_months' => 1]);
        $c = $this->summaryContract($meterId);
        self::assertSame([$end, $end], [$c['period_end'], $c['period_last_day']], 'gepflegtes Ende: einschließlich, in beiden');
    }

    public function testRenewedContractEndsTheDayBeforeTheSettlementDate(): void
    {
        $this->settings->set(['billing_cycle_anchor_strom' => '03-01']);
        $meterId = $this->seedStrom(['start' => $this->day(-400), 'end' => $this->day(-30)]);
        $c = $this->summaryContract($meterId);
        $anchor = $this->nextAnchor('03-01');
        self::assertSame($anchor, $c['period_end'], 'weiterlaufend: bis zur nächsten Abrechnung');
        self::assertSame(date('Y-m-d', (int)strtotime("$anchor -1 day")), $c['period_last_day'], 'Ende in der Vergangenheit zählt nicht');
    }

    public function testRuleOfTheHelper(): void
    {
        // Einspeisung: das frühere von Ende und Stichtag — der Stichtag zählt ausschließlich
        self::assertSame('2026-12-31', ConsumptionService::periodLastDay(['effective_end' => '2027-01-01', 'end' => '2043-05-31']));
        self::assertSame('2027-05-31', ConsumptionService::periodLastDay(['effective_end' => '2027-05-31', 'end' => '2027-05-31']));
        // Schaltjahr und Monatsgrenze
        self::assertSame('2028-02-29', ConsumptionService::periodLastDay(['effective_end' => '2028-03-01', 'end' => null]));
        self::assertNull(ConsumptionService::periodLastDay(['effective_end' => null]));
    }

    public function testTheApiReferenceExplainsBothFields(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docs/referenz/api.md' => 'erste Tag der nächsten Periode', 'docs/en/referenz/api.md' => 'first day of the next period'] as $rel => $phrase) {
            $doc = (string)file_get_contents("$root/$rel");
            $section = substr($doc, (int)strpos($doc, '### `GET /api/summary`'));
            $section = substr($section, 0, (int)strpos($section, "\n### ", 5));
            self::assertStringContainsString('"period_last_day"', $section, "$rel: Beispiel ohne period_last_day");
            self::assertStringContainsString('`period_last_day`', $section, "$rel: Tabelle ohne period_last_day");
            self::assertStringContainsString($phrase, $section, "$rel: erklärt period_end nicht");
            self::assertStringContainsString('2027-08-31', $section, "$rel: Beispiel Stichtag 01-09 fehlt");
        }
    }
}
