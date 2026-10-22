<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Countries;
use Energietracker\Services\ActivityService;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\BillExpectedService;
use Energietracker\Services\BillService;
use Energietracker\Services\CalendarService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\PeriodService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\StromSaldoService;
use Energietracker\Services\SummaryService;
use Energietracker\Services\TenancyBudgetService;
use Energietracker\Services\TenancyService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.5.0 (F1029) — „Abrechnung erwartet“: Endet ein Abrechnungszeitraum
 * (Stichtag oder Vertragsende) ohne erfasste Versorgerrechnung, steht ein
 * Hinweis in der Agenda; nach der gesetzlichen Frist (Strom/Gas in DE:
 * § 40c Abs. 2 EnWG, Ende + 42 Tage) „überfällig“. Fernwärme und Wasser nie
 * überfällig, Heizöl, Pellets, Einspeisung und Schattenverträge nie erwartet.
 * Eine Rechnung erledigt ihn (tolerant am Periodenende), von Hand abhakbar,
 * Rückgängig wie üblich.
 */
#[CoversClass(BillExpectedService::class)]
final class BillExpectedTest extends ServiceTestCase
{
    private BillService $bills;
    private BillExpectedService $expected;
    private AgendaService $agenda;
    private ActivityService $activity;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = date('Y-m-d');
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $tenancies = new TenancyService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $this->agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $reminders, $recs, $this->i18n, $tenancies);
        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        // verdrahtet wie in bootstrap.php
        $this->activity = new ActivityService($this->store, $this->settings, $this->i18n);
        foreach ([$this->readings, $deliveries, $tenancies, $this->contracts, $reminders, $recs, $this->agenda, $periods, $this->bills] as $svc) {
            $svc->attachActivity($this->activity);
        }
        $this->activity->attach($reminders, $this->contracts, $recs);
        $this->expected = new BillExpectedService($this->settings, $this->meters, $this->contracts, $this->readings, $periods, $this->bills);
        $this->bills->attachExpectations($this->expected);
        $this->agenda->attachBillExpectations($this->expected);
    }

    private function day(int $offset, ?string $from = null): string
    {
        return date('Y-m-d', (int)strtotime(($from ?? $this->today) . " $offset days"));
    }

    /** Stichtag so, dass die laufende Periode vor `$daysAgo` Tagen begann (nie 29.02.). */
    private function anchorDaysAgo(string $utility, int $daysAgo): string
    {
        $d = $this->day(-$daysAgo);
        if (substr($d, 5) === '02-29') $d = $this->day(-$daysAgo - 1);
        $this->settings->set(['billing_cycle_anchor_' . $utility => substr($d, 5)]);
        return $this->day(-1, $d);   // Ende der zuletzt abgeschlossenen Periode
    }

    /** Zähler mit Ablesungen ab `$firstDay` Tagen zurück. */
    private function meter(string $utility, int $firstDay = -800): string
    {
        $id = $this->setMeterDevices($utility, [[
            'id' => 'd1', 'serial' => null, 'installed_on' => $this->day($firstDay), 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings($utility, $id, [
            ['date' => $this->day($firstDay), 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => $this->day(-5), 'counter' => 4000.0, 'device_id' => 'd1'],
        ]);
        return $id;
    }

    private function contract(string $utility, string $meterId, array $extra = []): array
    {
        $start = $extra['start'] ?? $this->day(-700);
        $base = $utility === 'wasser'
            ? ['trinkwasser' => ['working_prices' => [['from' => $start, 'ct_per_m3' => 250]], 'base_prices' => [['from' => $start, 'eur_per_month' => 8]]]]
            : ['working_prices' => [['from' => $start, 'ct_per_kwh' => 30]], 'advance_payments' => [['from' => $start, 'amount_eur' => 80]]];
        return $this->contracts->create($utility, $extra + ['meter_id' => $meterId, 'provider' => 'Werk Nord', 'start' => $start] + $base);
    }

    /** @return list<array<string,mixed>> */
    private function billEvents(): array
    {
        return array_values(array_filter($this->agenda->events(90, $this->today), fn($e) => $e['kind'] === 'bill_expected'));
    }

    public function testRunningContractExpectsABillAtTheCutoffAndIsOverdueAfterSixWeeks(): void
    {
        $m = $this->meter('strom');
        $until = $this->anchorDaysAgo('strom', 60);   // Periode endete vor 61 Tagen → Frist vor 19 Tagen verstrichen
        $c = $this->contract('strom', $m);
        $ev = $this->billEvents();
        self::assertCount(1, $ev);
        $e = $ev[0];
        $deadline = $this->day(42, $until);
        self::assertSame("bill_expected-{$c['id']}-$until", $e['uid']);
        self::assertSame('calendar.event.billOverdue', $e['title_key']);
        self::assertSame(['overdue', true, $deadline, $deadline], [$e['severity'], $e['due_now'], $e['date'], $e['ref']['deadline']]);
        self::assertTrue($e['ref']['overdue']);
        self::assertFalse($e['ref']['final']);
        [$from] = TenancyBudgetService::periodAround((string)$this->settings->get('billing_cycle_anchor_strom'), $until);
        self::assertSame([$from, $until], [$e['ref']['period_from'], $e['ref']['period_until']]);
        self::assertSame($this->day(1, $until), date('Y-m-d', (int)strtotime("$from +1 year")), 'ein ganzes Jahr bis zum Stichtag');
        self::assertStringContainsString('gesetzliche Frist', $e['title']);
        self::assertStringContainsString($this->i18n->date($until), $e['title'], 'Tage im Titel in der Schreibweise der Sprache');
        self::assertSame($until, $e['params']['until'], 'params bleiben JJJJ-MM-TT');
        self::assertTrue($e['can_mark_done']);
        self::assertStringStartsWith('#/bill-check?utility=strom&meter=' . rawurlencode($m), $e['href']);
        self::assertStringContainsString('capture=1', $e['href']);

        // vor Ablauf der sechs Wochen: erwartet, Frist in der Zukunft
        $until2 = $this->anchorDaysAgo('strom', 31);
        $e2 = $this->billEvents()[0];
        self::assertSame(['calendar.event.billExpected', 'due', $this->day(42, $until2)], [$e2['title_key'], $e2['severity'], $e2['date']]);
        self::assertFalse($e2['ref']['overdue']);
        self::assertTrue($e2['due_now'], 'erwartet steht ab dem Periodenende unter „Zu tun“');
        self::assertSame(42, Countries::billDeadlineDays('DE', 'strom'));
        self::assertSame(42, Countries::billDeadlineDays('DE', 'gas'));
    }

    public function testDistrictHeatingWaterAndOtherCountriesAreNeverOverdue(): void
    {
        $this->settings->set(['active_utilities' => ['gas', 'strom', 'wasser', 'fernwaerme']]);
        foreach (['fernwaerme', 'wasser'] as $u) {
            $until = $this->anchorDaysAgo($u, 100);
            $this->contract($u, $this->meter($u));
            self::assertNull(Countries::billDeadlineDays('DE', $u), "$u: keine gesetzliche Frist");
            $e = array_values(array_filter($this->billEvents(), fn($x) => $x['ref']['utility'] === $u))[0] ?? self::fail("$u: kein Hinweis");
            self::assertSame(['calendar.event.billExpected', 'due', $this->day(1, $until)], [$e['title_key'], $e['severity'], $e['date']], $u);
            self::assertNull($e['ref']['deadline'], $u);
            self::assertFalse($e['ref']['overdue'], $u);
            self::assertStringNotContainsString('gesetzlich', $e['title'], $u);
        }
        // Strom in Österreich: keine hinterlegte Frist → nur erwartet
        $this->settings->set(['country' => 'AT']);
        $this->anchorDaysAgo('strom', 100);
        $this->contract('strom', $this->meter('strom'));
        $s = array_values(array_filter($this->billEvents(), fn($x) => $x['ref']['utility'] === 'strom'))[0];
        self::assertSame(['calendar.event.billExpected', null], [$s['title_key'], $s['ref']['deadline']]);
    }

    public function testFuelDeliveriesFeedInAndShadowContractsAreNeverExpected(): void
    {
        $this->settings->set(['active_utilities' => ['gas', 'strom', 'wasser', 'heizoel', 'pv_einspeisung']]);
        $this->anchorDaysAgo('strom', 90);
        $this->anchorDaysAgo('heizoel', 90);
        $this->anchorDaysAgo('pv_einspeisung', 90);
        // Bestand wie aus einem Import: Tank mit Vertrag, Einspeisung mit Vertrag
        $this->store->write('heizoel/meters.json', [['id' => 'm_heizoel_t', 'name' => 'Tank', 'active' => true, 'capacity' => 3000,
            'capacity_unit' => 'L', 'initial_stock' => 1000, 'devices' => [], 'created_at' => $this->day(-800)]]);
        $this->store->write('heizoel/contracts.json', [['id' => 'c_oel', 'meter_id' => 'm_heizoel_t', 'provider' => 'Ölhandel', 'start' => $this->day(-700)]]);
        $pv = $this->meter('pv_einspeisung');
        $this->store->write('pv_einspeisung/contracts.json', [['id' => 'c_pv', 'meter_id' => $pv, 'provider' => 'Netz', 'start' => $this->day(-700),
            'working_prices' => [['from' => $this->day(-700), 'ct_per_kwh' => 8]]]]);
        $this->contract('strom', $this->meter('strom'), ['is_shadow' => true, 'shadow_label' => 'Angebot']);
        self::assertSame([], $this->billEvents());
        self::assertSame([], $this->expected->expected($this->today), 'die Regel selbst, nicht nur die Agenda');
        self::assertNull(BillExpectedService::periodOf(['id' => 'c_s', 'start' => $this->day(-700), 'is_shadow' => true], [], '01-01', $this->today));
    }

    public function testFinalBillAfterTheContractEndsUnlessTheSameSupplierContinues(): void
    {
        $m = $this->meter('strom');
        $this->settings->set(['billing_cycle_anchor_strom' => '01-01']);
        $old = $this->contract('strom', $m, ['end' => $this->day(-20), 'auto_renews' => false]);
        $this->contract('strom', $m, ['provider' => 'Werk Süd', 'start' => $this->day(-19)]);
        $final = array_values(array_filter($this->expected->expected($this->today), fn($x) => $x['contract_id'] === $old['id']));
        self::assertCount(1, $final);
        $x = $final[0];
        [$cutoff] = TenancyBudgetService::periodAround('01-01', $this->day(-20));
        self::assertSame([true, max($cutoff, $old['start']), $this->day(-20)], [$x['final'], $x['from'], $x['until']]);
        self::assertSame('calendar.event.finalBillExpected', BillExpectedService::titleKey($x));
        $e = array_values(array_filter($this->billEvents(), fn($e) => $e['ref']['contract_id'] === $old['id']))[0];
        self::assertTrue($e['ref']['final']);
        self::assertSame($this->day(22), $e['date'], 'Schlussrechnung: Ende der Belieferung + 42 Tage');

        // derselbe Versorger schließt lückenlos an: keine Schlussrechnung
        $m2 = $this->meters->create('strom', ['name' => 'Werkstatt', 'installed_on' => $this->day(-800)]);
        $this->readings->create('strom', ['meter_id' => (string)$m2['id'], 'date' => $this->day(-790), 'counter' => 0]);
        $a = $this->contract('strom', (string)$m2['id'], ['end' => $this->day(-20), 'auto_renews' => false, 'provider' => 'Werk Nord']);
        $this->contract('strom', (string)$m2['id'], ['provider' => 'werk nord ', 'start' => $this->day(-19)]);
        self::assertSame([], array_values(array_filter($this->expected->expected($this->today), fn($x) => $x['contract_id'] === $a['id'])));
    }

    public function testRenewedContractRunsOnAndFollowsTheCutoff(): void
    {
        $m = $this->meter('gas');
        $until = $this->anchorDaysAgo('gas', 100);
        // abgelaufen vor 200 Tagen, verlängert sich ohne Kündigung → läuft weiter
        $c = $this->contract('gas', $m, ['end' => $this->day(-200)]);
        $x = $this->expected->expected($this->today);
        self::assertCount(1, $x);
        self::assertSame([$c['id'], false, $until], [$x[0]['contract_id'], $x[0]['final'], $x[0]['until']]);
    }

    public function testShortStartPeriodsOldPeriodsAndPeriodsBeforeTheFirstReadingOrTheBaselineAreNotExpected(): void
    {
        // Vertrag begann 30 Tage vor dem Stichtag: kein eigener Hinweis
        $m = $this->meter('strom');
        $until = $this->anchorDaysAgo('strom', 50);
        $this->contract('strom', $m, ['start' => $this->day(-29, $until)]);
        self::assertSame([], $this->expected->expected($this->today), 'kürzer als ' . BillExpectedService::MIN_PERIOD_DAYS . ' Tage');

        // Schlussrechnung vor mehr als einem Jahr: nicht rückwirkend
        $g = $this->meter('gas');
        $this->contract('gas', $g, ['end' => $this->day(-400), 'auto_renews' => false]);
        self::assertSame([], $this->expected->expected($this->today), 'außerhalb des Fensters');

        // Wasser: erste Ablesung nach dem Periodenende
        $wUntil = $this->anchorDaysAgo('wasser', 90);
        $w = $this->meter('wasser', -60);
        $this->contract('wasser', $w);
        self::assertSame([], $this->expected->expected($this->today), 'vor der ersten Ablesung');
        $this->setReadings('wasser', $w, [['date' => $this->day(-30, $wUntil), 'counter' => 0.0, 'device_id' => 'd1'],
                                          ['date' => $this->day(-5), 'counter' => 50.0, 'device_id' => 'd1']]);
        self::assertCount(1, $this->expected->expected($this->today), 'Ablesung im Zeitraum: erwartet');
        // Analyse-Zäsur nach dem Periodenende
        $this->meters->update('wasser', $w, ['baseline_events' => [['date' => $this->day(5, $wUntil), 'label' => 'Neue Leitung']]]);
        self::assertSame([], $this->expected->expected($this->today), 'vor der Zäsur');
    }

    /**
     * Fenster: bis 120 Tage nach dem Periodenende (Frist 42 Tage plus gut elf
     * Wochen). Danach verschwindet der Hinweis — ohne Verlaufseintrag —, damit
     * Bestand ohne erfasste Rechnungen nicht für jeden Vertrag „überfällig“ sieht.
     */
    public function testTheHintEndsOneHundredTwentyDaysAfterThePeriod(): void
    {
        self::assertSame(120, BillExpectedService::WINDOW_DAYS);
        $this->contract('strom', $this->meter('strom'));
        $until = $this->anchorDaysAgo('strom', 119);   // Ende vor genau 120 Tagen: noch drin
        self::assertSame($this->day(-120), $until);
        $e = $this->billEvents();
        self::assertCount(1, $e, 'am 120. Tag nach dem Ende noch erwartet');
        self::assertSame('overdue', $e[0]['severity']);

        $this->anchorDaysAgo('strom', 120);              // Ende vor 121 Tagen: vorbei
        self::assertSame([], $this->billEvents(), 'ab dem 121. Tag kein Hinweis');
        self::assertSame([], $this->expected->expected($this->today));
        self::assertSame([], $this->activity->all(), 'verschwindet ohne Verlaufseintrag');

        // Schlussrechnung: dieselbe Grenze am Vertragsende
        $c2 = $this->contract('gas', $this->meter('gas'), ['end' => $this->day(-120), 'auto_renews' => false]);
        self::assertSame([$c2['id']], array_column($this->expected->expected($this->today), 'contract_id'));
        $this->contracts->update('gas', $c2['id'], ['end' => $this->day(-121)]);
        self::assertSame([], $this->expected->expected($this->today));
    }

    public function testARecordedBillClosesTheHintTolerantlyAndTheHistoryNamesIt(): void
    {
        $m = $this->meter('strom');
        $until = $this->anchorDaysAgo('strom', 60);
        $c = $this->contract('strom', $m);
        $uid = "bill_expected-{$c['id']}-$until";

        // 40 Tage daneben: eine andere Rechnung
        $far = $this->bills->create('strom', ['meter_id' => $m, 'period_from' => $this->day(-405, $until), 'period_to' => $this->day(-40, $until),
            'invoice' => ['amount_eur' => 900]]);
        self::assertCount(1, $this->billEvents(), 'Rechnungsende 40 Tage vor dem Periodenende zählt nicht');
        // Rechnung eines anderen Vertrags am selben Zähler zählt nicht
        $this->bills->update('strom', $far['id'], ['period_to' => $this->day(-10, $until), 'contract_id' => 'c_anderer']);
        self::assertCount(1, $this->billEvents(), 'anderer Vertrag');
        self::assertSame([], $this->activity->all());

        // Versorger las 20 Tage früher ab: deckt die Periode ab
        $bill = $this->bills->create('strom', ['meter_id' => $m, 'period_from' => $this->day(-385, $until), 'period_to' => $this->day(-20, $until),
            'issued_on' => $this->day(-30), 'invoice' => ['amount_eur' => 950]]);
        self::assertSame([], $this->billEvents(), 'mit Rechnung kein Hinweis');
        $log = $this->activity->list(['kind' => 'bill_expected']);
        self::assertCount(1, $log);
        self::assertSame(['auto_done', $uid . '@' . $until, $bill['id'], $this->day(-30)],
            [$log[0]['action'], $log[0]['subject']['occurrence'], $log[0]['ref']['bill_id'], $log[0]['done_on']]);
        self::assertSame(['activity.reason.bill', ['date' => $this->day(-20, $until)]], [$log[0]['reason']['key'], $log[0]['reason']['params']]);
        self::assertSame(['type' => 'auto'], array_diff_key($log[0]['by'], ['reason' => 1]), 'ohne Anmeldung: von selbst');

        // ändern ohne neuen Zeitraum: kein zweiter Eintrag
        $this->bills->update('strom', $bill['id'], ['note' => 'geprüft']);
        self::assertCount(1, $this->activity->list(['kind' => 'bill_expected']));

        // gelöscht: Hinweis wieder offen, Eintrag durchgestrichen
        $this->bills->delete('strom', $bill['id']);
        self::assertCount(1, $this->billEvents());
        self::assertNull($this->billEvents()[0]['done'], 'der durchgestrichene Eintrag erledigt nichts mehr');
        self::assertNotEmpty($this->activity->get($log[0]['id'])['undone']);
    }

    public function testAGroupBillRecordedAtAMemberCloses(): void
    {
        $until = $this->anchorDaysAgo('strom', 70);
        $ht = (string)$this->meters->create('strom', ['name' => 'HT', 'installed_on' => $this->day(-800)])['id'];
        $nt = (string)$this->meters->create('strom', ['name' => 'NT', 'installed_on' => $this->day(-800)])['id'];
        foreach ([$ht, $nt] as $mid) $this->readings->create('strom', ['meter_id' => $mid, 'date' => $this->day(-790), 'counter' => 1]);
        $gid = (string)$this->meters->createGroup('strom', ['name' => 'Doppeltarif'])['id'];
        foreach ([$ht, $nt] as $mid) $this->meters->update('strom', $mid, ['meter_group_id' => $gid]);
        $this->contracts->create('strom', ['meter_group_id' => $gid, 'provider' => 'Werk Nord', 'start' => $this->day(-700),
            'working_prices' => [['from' => $this->day(-700), 'ct_per_kwh' => 30]], 'advance_payments' => [['from' => $this->day(-700), 'amount_eur' => 80]]]);
        $group = array_values(array_filter($this->billEvents(), fn($e) => $e['ref']['meter_id'] === $gid));
        self::assertCount(1, $group, 'Gruppenvertrag: Hinweis für die Gruppe');
        $this->bills->create('strom', ['meter_id' => $nt, 'period_from' => $this->day(-364, $until), 'period_to' => $until, 'invoice' => ['amount_eur' => 1]]);
        self::assertSame([], array_values(array_filter($this->billEvents(), fn($e) => $e['ref']['meter_id'] === $gid)), 'Rechnung am Mitglied');
    }

    public function testTickedOffByHandWithoutABillAndUndo(): void
    {
        $until = $this->anchorDaysAgo('strom', 60);
        $c = $this->contract('strom', $this->meter('strom'));
        $ev = $this->billEvents()[0];
        $r = $this->agenda->markDone(['uid' => $ev['uid'], 'occurrence' => $ev['occurrence'], 'note' => 'liegt im Ordner']);
        self::assertSame('activity.reason.billWithout', $r['entry']['reason']['key'], 'kam ohne Erfassung');
        self::assertSame("bill_expected-{$c['id']}-$until@$until", $r['entry']['subject']['occurrence']);
        $after = $this->billEvents()[0];
        self::assertFalse($after['due_now']);
        self::assertSame('upcoming', $after['severity']);
        self::assertFalse($after['done']['auto']);
        $this->activity->undo($r['activity_id']);
        self::assertTrue($this->billEvents()[0]['due_now'], 'Rückgängig: wieder offen');
    }

    public function testCalendarAndSummaryCountTheHint(): void
    {
        $this->anchorDaysAgo('strom', 60);
        $this->contract('strom', $this->meter('strom'));
        $ics = (new CalendarService($this->agenda, $this->settings, new InstanceService($this->store), $this->i18n))->ics($this->today);
        self::assertStringContainsString('CATEGORIES:BILL_EXPECTED', $ics);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $summary = fn() => (new SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $deliveries, new PvSummaryService($this->consumption), new StromSaldoService($this->consumption),
            $this->agenda, new InstanceService($this->store)))->build(null, null, $this->today);
        $sum = $summary();
        $due = count(array_filter($this->agenda->events(365, $this->today), fn($e) => $e['due_now']));
        self::assertSame($due, $sum['agenda']['due']);
        self::assertGreaterThanOrEqual(1, $sum['agenda']['overdue'], 'überfällige Abrechnung zählt als überfällig');
        // vor Ablauf: die gesetzliche Frist ist „als Nächstes“
        $until = $this->anchorDaysAgo('strom', 31);
        self::assertSame(['bill_expected', $this->day(42, $until)], [$summary()['agenda']['next']['kind'] ?? null, $summary()['agenda']['next']['date'] ?? null]);
    }

    /**
     * v3.7.0 (wirklichkeit-2, Punkt 4) — eine als Sonderzahlung erfasste Rück- oder
     * Nachzahlung nach dem Periodenende zählt als eingegangene Rechnung; eine
     * Abschlagszahlung oder eine Zahlung lange vor dem Ende nicht.
     */
    public function testASettlingPaymentCountsAsTheBill(): void
    {
        $m = $this->meter('strom');
        $until = $this->anchorDaysAgo('strom', 60);
        $c = $this->contract('strom', $m);
        self::assertCount(1, $this->expected->open(), 'ohne Rechnung und Zahlung: erwartet');
        $pay = fn(string $kind, string $date) => $this->contracts->update('strom', (string)$c['id'], ['special_payments' => [
            ['id' => 'sp1', 'date' => $date, 'kind' => $kind, 'amount_eur' => 42.0, 'note' => ''],
        ]]);
        $pay('abschlagszahlung', $this->day(10, $until));
        self::assertCount(1, $this->expected->open(), 'eine Abschlagszahlung gleicht keine Abrechnung aus');
        $pay('rueckzahlung_ohne', $this->day(-60, $until));
        self::assertCount(1, $this->expected->open(), 'zwei Monate vor dem Periodenende: gehört nicht zu dieser Abrechnung');
        $pay('rueckzahlung_ohne', $this->day(20, $until));
        self::assertSame([], $this->expected->open(), 'Rückzahlung nach dem Periodenende: Rechnung ist da');
        self::assertSame([], $this->billEvents(), 'auch in der Agenda kein „Abrechnung erwartet“ mehr');
        $x = $this->expected->expected()[0];
        self::assertSame(['sp1', null], [$x['payment']['id'] ?? null, $x['bill_id']]);
        $pay('nachzahlung_mit', $this->day(5, $until));
        self::assertSame([], $this->expected->open(), 'ebenso eine Nachzahlung');
    }

    /**
     * v3.7.0 (wirklichkeit-2, Punkt 4) — Kurzreste: Endet ein Vertrag weniger als 14
     * Tage nach dem Stichtag, gehören die Tage zur Periode davor (#33); bei 20 Tagen
     * bleibt es eine eigene, kurze Schlussrechnung — der Versorger rechnet sie ab.
     */
    public function testAShortRestAfterTheCutoffIsItsOwnFinalBillFromFourteenDays(): void
    {
        $m = $this->meter('strom');
        $anchorEnd = $this->anchorDaysAgo('strom', 80);   // Stichtag vor 80 Tagen
        $anchor = $this->day(1, $anchorEnd);
        $c = $this->contract('strom', $m, ['end' => $this->day(19, $anchor), 'auto_renews' => false]);
        $x = array_values(array_filter($this->expected->expected(), fn($e) => $e['contract_id'] === (string)$c['id']))[0];
        self::assertSame([$anchor, $this->day(19, $anchor), true], [$x['from'], $x['until'], $x['final']], '20 Tage: eigene Schlussrechnung ab dem Stichtag');
        $this->contracts->update('strom', (string)$c['id'], ['end' => $this->day(9, $anchor)]);
        $x = array_values(array_filter($this->expected->expected(), fn($e) => $e['contract_id'] === (string)$c['id']))[0];
        self::assertLessThan($anchor, $x['from'], '10 Tage: Kurzrest, die Schlussrechnung beginnt mit der Periode davor');
    }
}
