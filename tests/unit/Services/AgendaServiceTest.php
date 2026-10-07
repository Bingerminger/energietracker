<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\CalendarService;
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
 * v3.1.0 (Paket H1) — Agenda (B5), Kalender-Abo (MKT-09) und Kennzahlen für
 * Home Assistant (API-33) aus einer Quelle.
 */
final class AgendaServiceTest extends ServiceTestCase
{
    private ReminderService $reminderService;
    private RecommendationService $recs;
    private AgendaService $agenda;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = date('Y-m-d');
        $this->reminderService = new ReminderService($this->store, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $this->recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $this->agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $this->reminderService, $this->recs, $this->i18n);
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', (int)strtotime("{$this->today} $offset days"));
    }

    /** Strom: Ablesung vor 100 Tagen, Vertrag mit Frist in 10 Tagen und Preisgarantie. */
    private function seed(): string
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => $this->day(-400), 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['date' => $this->day(-400), 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => $this->day(-100), 'counter' => 2500.0, 'device_id' => 'd1'],
        ]);
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'Testwerk', 'start' => $this->day(-300), 'end' => $this->day(40),
            'notice_period_months' => 1, 'price_guarantee_until' => $this->day(60),
            'working_prices'   => [['from' => $this->day(-300), 'ct_per_kwh' => 30]],
            'advance_payments' => [['from' => $this->day(-300), 'amount_eur' => 80]],
        ]);
        $this->reminderService->create(['title' => 'Rauchmelder prüfen', 'category' => 'custom', 'next_due' => $this->day(-3)]);
        $this->reminderService->create(['title' => 'Eichfrist Wasser', 'category' => 'custom', 'next_due' => $this->day(200)]);
        return $meterId;
    }

    public function testEventsFromRemindersReadingsAndContracts(): void
    {
        $meterId = $this->seed();
        $events = $this->agenda->events(90, $this->today);
        $kinds = array_count_values(array_column($events, 'kind'));
        self::assertSame(1, $kinds['reminder'] ?? 0, 'Termin in 200 Tagen liegt außerhalb von 90 Tagen');
        $byUid = array_column($events, null, 'uid');

        $rem = array_values(array_filter($events, fn($e) => $e['kind'] === 'reminder'))[0];
        self::assertSame(['overdue', true], [$rem['severity'], $rem['due_now']]);
        self::assertSame('Rauchmelder prüfen', $rem['title']);

        $reading = $byUid["reading-strom-$meterId"] ?? null;
        self::assertNotNull($reading, 'Ablesung vor 100 Tagen ist fällig');
        self::assertTrue($reading['due_now']);
        self::assertSame(100, $reading['ref']['days_since_reading']);

        $cancel = array_values(array_filter($events, fn($e) => $e['kind'] === 'cancel_by'));
        self::assertCount(1, $cancel);
        self::assertTrue($cancel[0]['due_now'], 'wie Empfehlung R6: Frist in den Erinnerungsstufen');
        self::assertSame('urgent', $cancel[0]['severity'], '≤ 14 Tage');
        self::assertStringContainsString('Testwerk', $cancel[0]['title']);
        self::assertArrayHasKey('recommendation_id', $cancel[0]['ref']);

        self::assertArrayHasKey('price_guarantee_end', array_flip(array_column($events, 'kind')));
        $dates = array_column($events, 'date');
        $sorted = $dates; sort($sorted);
        self::assertSame($sorted, $dates, 'nach Datum sortiert');
    }

    public function testDismissedContractReminderIsNotDueNow(): void
    {
        $this->seed();
        $cancel = array_values(array_filter($this->agenda->events(90, $this->today), fn($e) => $e['kind'] === 'cancel_by'))[0];
        $this->recs->dismiss($cancel['ref']['recommendation_id']);
        $again = array_values(array_filter($this->agenda->events(90, $this->today), fn($e) => $e['kind'] === 'cancel_by'))[0];
        self::assertFalse($again['due_now'], 'ausgeblendet bleibt ausgeblendet — wie bis v3.0 im Dashboard');
        self::assertSame($cancel['uid'], $again['uid'], 'im Kalender bleibt die Frist');
    }

    public function testCalendarIsValidIcsWithStableUids(): void
    {
        $this->seed();
        $instance = new InstanceService($this->store);
        $cal = new CalendarService($this->agenda, $this->settings, $instance, $this->i18n);
        $ics = $cal->ics($this->today);

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertSame(0, preg_match("/(?<!\r)\n/", $ics), 'nur CRLF');
        foreach (explode("\r\n", rtrim($ics)) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), "Zeile über 75 Oktette: $line");
        }
        self::assertMatchesRegularExpression('/DTSTART;VALUE=DATE:\d{8}\r\n/', $ics);
        self::assertStringContainsString("TRIGGER:-P14D", $ics, 'Vorwarnung aus reminder_warn_days_before');
        self::assertStringContainsString('REFRESH-INTERVAL;VALUE=DURATION:PT12H', $ics);
        preg_match_all('/^UID:(.+)$/m', $ics, $m);
        $uid = array_values(array_filter(array_map('trim', $m[1]), fn($u) => str_starts_with($u, 'reminder-')))[0] ?? null;
        self::assertMatchesRegularExpression('/^reminder-[\w-]+@et_[0-9a-f]{16}$/', (string)$uid);

        // erledigt → dasselbe Ereignis (gleiche UID) am neuen Datum
        $rem = array_values(array_filter($this->reminderService->list(), fn($r) => $r['title'] === 'Rauchmelder prüfen'))[0];
        $this->reminderService->update($rem['id'], ['recurrence' => 'yearly']);
        $this->reminderService->markDone($rem['id'], $this->today);
        $after = $cal->ics($this->today);
        self::assertStringContainsString("UID:$uid", $after);
        self::assertSame($instance->id(), $instance->id(), 'Kennung bleibt');
    }

    public function testIcsEscapingAndFolding(): void
    {
        self::assertSame('a\\;b\\,c\\\\d\\ne', CalendarService::text("a;b,c\\d\ne"));
        $long = 'SUMMARY:' . str_repeat('Kündigungsfrist ', 12);
        foreach (explode("\r\n", CalendarService::fold($long)) as $i => $part) {
            self::assertLessThanOrEqual(75, strlen($part));
            self::assertTrue(mb_check_encoding($part, 'UTF-8'), 'kein Zeichen zerteilt');
            if ($i > 0) self::assertStringStartsWith(' ', $part);
        }
    }

    public function testSummaryKeysAreFrozenAndAlwaysPresent(): void
    {
        $summary = fn() => new SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n),
            new PvSummaryService($this->consumption), new StromSaldoService($this->consumption), $this->agenda,
            new InstanceService($this->store));
        $empty = $summary()->build(null, null, $this->today);
        $meterId = $this->seed();
        $full = $summary()->build(null, null, $this->today);

        $top = ['summary_version', 'instance_id', 'generated_at', 'app_version', 'currency', 'meters', 'pv', 'agenda', 'warnings'];
        self::assertSame($top, array_keys($empty), 'Klasse A: Schlüssel eingefroren');
        self::assertSame($top, array_keys($full));
        self::assertSame(['autarky_pct_ytd', 'self_consumption_pct_ytd', 'strom_saldo_ytd'], array_keys($full['pv']));
        self::assertSame(['due', 'overdue', 'next'], array_keys($full['agenda']));
        $meterKeys = ['key', 'utility', 'meter_id', 'external_id', 'name', 'unit', 'consumption_unit', 'role', 'is_sub_meter',
            'last_reading_date', 'last_counter', 'days_since_reading', 'reading_due', 'month_to_date', 'year_to_date',
            'contract', 'forecast_12m', 'tank', 'capture'];
        foreach (array_merge($empty['meters'], $full['meters']) as $m) self::assertSame($meterKeys, array_keys($m), $m['key']);

        $strom = array_values(array_filter($full['meters'], fn($m) => $m['meter_id'] === $meterId))[0];
        self::assertSame(100, $strom['days_since_reading']);
        self::assertTrue($strom['reading_due']);
        self::assertSame('Testwerk', $strom['contract']['provider']);
        self::assertContains($strom['contract']['verdict'], ['refund', 'surcharge', 'balanced', null]);
        self::assertGreaterThanOrEqual(1, $full['agenda']['due']);

        $gas = array_values(array_filter($full['meters'], fn($m) => $m['utility'] === 'gas'))[0] ?? null;
        if ($gas !== null) self::assertNull($gas['contract'], 'ohne Vertrag: null');

        // Filter über Alias (external_id)
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['external_id'] = 'strom_haus';
        $this->store->write('strom/meters.json', $all);
        $one = $summary()->build(null, 'strom_haus', $this->today);
        self::assertCount(1, $one['meters']);
        self::assertSame('strom_haus', $one['meters'][0]['external_id']);
    }
}
