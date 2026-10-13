<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Http\HttpStatusException;
use Energietracker\Services\ActivityService;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\CalendarService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\StromSaldoService;
use Energietracker\Services\SummaryService;
use Energietracker\Services\TenancyService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.4.0 (F1024) — Erledigt mit Verlauf.
 *
 * Teil A (die Wahrheit kommt aus den Daten): Ein lückenloser Folgevertrag
 * erledigt Kündigungsstichtag, Vertragsende, Preisgarantie und Preiserhöhung
 * des Vorgängers; mit Lücke bleibt der Hinweis. Weiterlaufende und
 * unbefristete Verträge haben keinen wandernden Stichtag mehr.
 *
 * Teil B (Verlauf): Abhaken und Rückgängig ändern `due_now`, ein geändertes
 * Vertragsende öffnet den Hinweis wieder, Ablesung/Lieferung/Abrechnung tragen
 * sich selbst ein (Ablesung nur, wenn sie fällig war), Backup-Rundreise,
 * Aufbewahrung, Kalender.
 */
#[CoversClass(ActivityService::class)]
#[CoversClass(AgendaService::class)]
final class ActivityServiceTest extends ServiceTestCase
{
    private ReminderService $reminderService;
    private RecommendationService $recs;
    private DeliveryService $deliveries;
    private TenancyService $tenancies;
    private AgendaService $agenda;
    private ActivityService $activity;
    /** Wer gerade handelt (Closure wie App::actor). */
    private ?array $who = null;
    private bool $tankLow = false;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = date('Y-m-d');
        $this->reminderService = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $this->tenancies = new TenancyService($this->store, $this->meters, $this->i18n);
        $this->recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $this->deliveries, $this->i18n);
        $this->agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $this->reminderService, $this->recs, $this->i18n, $this->tenancies);
        // verdrahtet wie in bootstrap.php
        $this->activity = new ActivityService($this->store, $this->settings, $this->i18n, fn(): ?array => $this->who);
        foreach ([$this->readings, $this->deliveries, $this->tenancies, $this->contracts, $this->reminderService,
                  $this->recs, $this->agenda] as $svc) $svc->attachActivity($this->activity);
        $this->activity->attach($this->reminderService, $this->contracts, $this->recs);
        $this->activity->setTankProbe(fn(string $u, string $m): bool => $this->tankLow);
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', (int)strtotime("{$this->today} $offset days"));
    }

    /** Strom-Zähler mit Ablesungen; Vertrag A bis +40 Tage, Frist 1 Monat (Stichtag ≈ +9 Tage). */
    private function seedStrom(array $extra = []): array
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => $this->day(-400), 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['date' => $this->day(-400), 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => $this->day(-10), 'counter' => 3500.0, 'device_id' => 'd1'],
        ]);
        $a = $this->contracts->create('strom', $extra + [
            'meter_id' => $meterId, 'provider' => 'Altwerk', 'start' => $this->day(-300), 'end' => $this->day(40),
            'notice_period_months' => 1, 'price_guarantee_until' => $this->day(20),
            'working_prices'   => [['from' => $this->day(-300), 'ct_per_kwh' => 30], ['from' => $this->day(25), 'ct_per_kwh' => 35]],
            'advance_payments' => [['from' => $this->day(-300), 'amount_eur' => 80]],
        ]);
        return [$meterId, $a];
    }

    private function successor(string $meterId, string $start, array $extra = []): array
    {
        return $this->contracts->create('strom', $extra + [
            'meter_id' => $meterId, 'provider' => 'Neuwerk', 'start' => $start, 'end' => date('Y-m-d', (int)strtotime("$start +2 years -1 day")),
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 28]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 75]],
        ]);
    }

    /** @return array<string,array<string,mixed>> Ereignisse nach Art (das erste je Art) */
    private function byKind(): array
    {
        $out = [];
        foreach ($this->agenda->events(365, $this->today) as $e) $out[$e['kind']] ??= $e;
        return $out;
    }

    private function statusOf(string $meterId, string $cid): array
    {
        foreach ($this->consumption->contractStatus('strom', $this->meters->get('strom', $meterId))['contracts'] as $c) {
            if ($c['contract_id'] === $cid) return $c;
        }
        self::fail("Vertrag $cid fehlt im Status");
    }

    private function r6(): array
    {
        return array_values(array_filter($this->recs->all(), fn($r) => str_starts_with($r['id'], 'r6_')));
    }

    private function summary(): SummaryService
    {
        return new SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $this->deliveries, new PvSummaryService($this->consumption), new StromSaldoService($this->consumption),
            $this->agenda, new InstanceService($this->store));
    }

    // ── Teil A: Folgevertrag ─────────────────────────────────────────────

    public function testGaplessSuccessorClosesTheHintsOfThePredecessor(): void
    {
        [$meterId, $a] = $this->seedStrom();
        $before = $this->byKind();
        self::assertTrue($before['cancel_by']['due_now'], 'vorher: Kündigung fällig');
        self::assertCount(1, $this->r6());
        $sumBefore = $this->summary()->build(null, null, $this->today);
        $dueBefore = $sumBefore['agenda']['due'];
        $stromContract = fn(array $s) => array_values(array_filter($s['meters'], fn($m) => $m['meter_id'] === $meterId))[0]['contract'];
        self::assertSame($before['cancel_by']['date'], $stromContract($sumBefore)['cancel_by']);

        $b = $this->successor($meterId, $this->day(41));   // Beginn = Ende + 1
        $st = $this->statusOf($meterId, $a['id']);
        self::assertSame(['contract_id' => $b['id'], 'provider' => 'Neuwerk', 'start' => $this->day(41), 'gap_days' => 0], $st['successor']);
        self::assertFalse($st['should_remind']);
        self::assertSame(0, $st['remind_stage']);
        self::assertSame([], $this->r6(), 'R6 entfällt');

        $after = $this->byKind();
        foreach (['cancel_by', 'term_end', 'price_guarantee_end', 'price_increase'] as $kind) {
            $e = $after[$kind] ?? self::fail("$kind fehlt");
            self::assertFalse($e['due_now'], $kind);
            self::assertTrue($e['done']['auto'] ?? false, "$kind: automatisch erledigt");
            self::assertSame('activity.reason.successor', $e['done']['reason']['key'], $kind);
            self::assertSame(['provider' => 'Neuwerk', 'date' => $this->day(41)], $e['done']['reason']['params'], $kind);
            self::assertFalse($e['can_mark_done'], $kind);
            self::assertSame($before[$kind]['uid'], $e['uid'], "$kind: UID bleibt");
        }
        $sumAfter = $this->summary()->build(null, null, $this->today);
        self::assertLessThan($dueBefore, $sumAfter['agenda']['due'], 'summary.due sinkt');
        self::assertNotSame('cancel_by', $sumAfter['agenda']['next']['kind'] ?? null, 'Erledigtes ist nicht „als Nächstes"');
        self::assertNull($stromContract($sumAfter)['cancel_by'], 'Summary: kein Stichtag mehr');

        // der Verlauf hält fest, wann und von wem (hier: ohne Anmeldung → von selbst)
        $log = $this->activity->list(['kind' => 'contract']);
        self::assertCount(1, $log);
        self::assertSame(['auto_done', $a['id'], $b['id']], [$log[0]['action'], $log[0]['ref']['contract_id'], $log[0]['ref']['successor_id']]);
        self::assertSame('auto', $log[0]['by']['type']);
        self::assertSame('activity.reason.successor', $log[0]['by']['reason']['key']);
        self::assertSame($log[0]['id'], $after['cancel_by']['done']['activity_id'] ?? null, 'Agenda nennt den Eintrag');

        // Folgevertrag gelöscht: Hinweis wieder offen, Eintrag durchgestrichen
        $this->contracts->delete('strom', $b['id']);
        self::assertTrue($this->byKind()['cancel_by']['due_now']);
        self::assertNotEmpty($this->activity->get($log[0]['id'])['undone']);
    }

    public function testSuccessorWithAGapKeepsTheHintAndNamesTheGap(): void
    {
        [$meterId, $a] = $this->seedStrom();
        $this->successor($meterId, $this->day(42));   // Ende + 2: ein Tag ohne Vertrag
        $st = $this->statusOf($meterId, $a['id']);
        self::assertSame(1, $st['successor']['gap_days']);
        self::assertTrue($st['should_remind']);
        $r6 = $this->r6();
        self::assertCount(1, $r6);
        self::assertStringContainsString('Neuwerk', $r6[0]['detail'], 'der Text nennt den Folgevertrag …');
        self::assertStringContainsString('1 Tag ohne Vertrag', $r6[0]['detail'], '… und die Lücke');
        $cancel = $this->byKind()['cancel_by'];
        self::assertTrue($cancel['due_now']);
        self::assertNull($cancel['done']);
        self::assertSame(1, $cancel['ref']['successor']['gap_days']);
        self::assertSame([], $this->activity->all(), 'mit Lücke kein automatischer Eintrag');
    }

    public function testAShadowContractIsNoSuccessor(): void
    {
        [$meterId, $a] = $this->seedStrom();
        $shadow = $this->successor($meterId, $this->day(41), ['is_shadow' => true, 'shadow_label' => 'Angebot']);
        self::assertNull($this->statusOf($meterId, $a['id'])['successor']);
        self::assertTrue($this->byKind()['cancel_by']['due_now']);
        self::assertSame([], $this->activity->all());
        // die Regel selbst, nicht nur der Filter davor
        self::assertNull(ContractService::successorOf($a, [$a, $shadow]), 'ein Schattenvertrag folgt nie');
        $real = ['id' => 'c_x', 'start' => $this->day(41), 'provider' => '', 'tariff_name' => 'Ökotarif'];
        self::assertSame('Ökotarif', ContractService::successorOf($a, [$a, $shadow, $real])['provider'], 'ersatzweise der Tarifname');
        $inside = ['id' => 'c_in', 'start' => $this->day(5), 'end' => $this->day(30)];
        self::assertNull(ContractService::successorOf($a, [$a, $inside]), 'ein Vertrag, der vorher endet, folgt nicht');
    }

    public function testGroupContractWithSuccessor(): void
    {
        $ht = (string)$this->meters->create('strom', ['name' => 'HT', 'installed_on' => $this->day(-400)])['id'];
        $nt = (string)$this->meters->create('strom', ['name' => 'NT', 'installed_on' => $this->day(-400)])['id'];
        $gid = (string)$this->meters->createGroup('strom', ['name' => 'Doppeltarif'])['id'];
        foreach ([$ht, $nt] as $m) $this->meters->update('strom', $m, ['meter_group_id' => $gid]);
        $group = fn(string $start, string $end, string $provider, array $x = []) => $this->contracts->create('strom', $x + [
            'meter_group_id' => $gid, 'provider' => $provider, 'start' => $start, 'end' => $end,
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => $start, 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 80.0]],
        ]);
        $a = $group($this->day(-300), $this->day(40), 'Altwerk', ['notice_period_months' => 1]);
        $target = $this->meters->groupTarget('strom', $gid);
        $cancel = fn() => array_values(array_filter($this->agenda->events(365, $this->today), fn($e) => $e['kind'] === 'cancel_by'))[0] ?? null;
        self::assertTrue($cancel()['due_now'], 'Gruppenvertrag: Kündigung fällig');

        $b = $group($this->day(41), $this->day(800), 'Neuwerk');
        $st = $this->consumption->contractStatus('strom', $target)['contracts'];
        $row = array_values(array_filter($st, fn($c) => $c['contract_id'] === $a['id']))[0];
        self::assertSame($b['id'], $row['successor']['contract_id']);
        self::assertSame(0, $row['successor']['gap_days']);
        self::assertFalse($cancel()['due_now']);
        self::assertTrue($cancel()['done']['auto']);
        self::assertSame($gid, $this->activity->list(['kind' => 'contract'])[0]['ref']['meter_group_id'] ?? null);
    }

    public function testRenewedContractWithSuccessorAndNoWanderingCancelBy(): void
    {
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'g1', 'serial' => null, 'installed_on' => $this->day(-700), 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('gas', $meterId, [
            ['date' => $this->day(-700), 'counter' => 0.0, 'device_id' => 'g1'],
            ['date' => $this->day(-10), 'counter' => 2000.0, 'device_id' => 'g1'],
        ]);
        // abgelaufen vor 60 Tagen, ohne Kündigung → läuft weiter
        $a = $this->contracts->create('gas', ['meter_id' => $meterId, 'provider' => 'Fixgas', 'start' => $this->day(-700),
            'end' => $this->day(-60), 'notice_period_months' => 1,
            'working_prices' => [['from' => $this->day(-700), 'ct_per_kwh' => 10]]]);
        // unbefristet mit Frist auf einem zweiten Zähler: jederzeit kündbar
        $open = $this->meters->create('gas', ['name' => 'Werkstatt', 'installed_on' => $this->day(-700)]);
        $this->contracts->create('gas', ['meter_id' => $open['id'], 'provider' => 'Grundversorger', 'start' => $this->day(-500),
            'notice_period_days' => 14, 'working_prices' => [['from' => $this->day(-500), 'ct_per_kwh' => 12]]]);

        $gasEvents = fn() => array_values(array_filter($this->agenda->events(365, $this->today),
            fn($e) => ($e['ref']['utility'] ?? null) === 'gas' && in_array($e['kind'], ['cancel_by', 'term_end'], true)));
        $row = array_values(array_filter($this->consumption->contractStatus('gas', $this->meters->get('gas', $meterId))['contracts'],
            fn($c) => $c['contract_id'] === $a['id']))[0];
        self::assertTrue($row['renewed']);
        self::assertSame($this->today, $row['cancel_by'], 'die Wechselplanung bleibt: kündbar ab heute (switchTiming unverändert)');
        self::assertSame([], $gasEvents(), 'kein datiertes cancel_by für weiterlaufende und unbefristete Verträge');
        $ics = (new CalendarService($this->agenda, $this->settings, new InstanceService($this->store), $this->i18n))->ics($this->today);
        self::assertStringNotContainsString('CATEGORIES:CANCEL_BY', $ics, 'kein täglich wanderndes Kalenderereignis');
        foreach ($this->summary()->build('gas', null, $this->today)['meters'] as $sm) {
            self::assertNull($sm['contract']['cancel_by'] ?? null, "Summary {$sm['meter_id']}: kein wandernder Stichtag");
        }

        $b = $this->contracts->create('gas', ['meter_id' => $meterId, 'provider' => 'Neugas', 'start' => $this->day(30),
            'working_prices' => [['from' => $this->day(30), 'ct_per_kwh' => 9]]]);
        $row = array_values(array_filter($this->consumption->contractStatus('gas', $this->meters->get('gas', $meterId))['contracts'],
            fn($c) => $c['contract_id'] === $a['id']))[0];
        self::assertSame(['contract_id' => $b['id'], 'provider' => 'Neugas', 'start' => $this->day(30), 'gap_days' => 0], $row['successor'],
            'ein weiterlaufender Vertrag läuft bis zum Folgevertrag');
        $log = $this->activity->list(['ref' => $a['id']]);
        self::assertCount(1, $log, 'Folgevertrag eines weiterlaufenden Vertrags: Eintrag im Verlauf');
        self::assertSame('contract-' . $a['id'] . '@renewed', $log[0]['subject']['occurrence']);
    }

    public function testWaterContractWithSuccessor(): void
    {
        $meterId = $this->setMeterDevices('wasser', [[
            'id' => 'w1', 'serial' => null, 'installed_on' => $this->day(-400), 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('wasser', $meterId, [
            ['date' => $this->day(-400), 'counter' => 0.0, 'device_id' => 'w1'],
            ['date' => $this->day(-10), 'counter' => 120.0, 'device_id' => 'w1'],
        ]);
        $water = fn(string $start, string $end) => $this->contracts->create('wasser', ['meter_id' => $meterId, 'provider' => 'Stadtwerke',
            'start' => $start, 'end' => $end,
            'trinkwasser' => ['working_prices' => [['from' => $start, 'ct_per_m3' => 250]], 'base_prices' => [['from' => $start, 'eur_per_month' => 8]]]]);
        $a = $water($this->day(-300), $this->day(20));
        $termEnd = fn() => array_values(array_filter($this->agenda->events(365, $this->today), fn($e) => $e['kind'] === 'term_end'
            && ($e['ref']['utility'] ?? null) === 'wasser'))[0] ?? null;
        self::assertTrue($termEnd()['due_now'], 'Wasser: Vertragsende fällig');
        $b = $water($this->day(21), $this->day(385));
        self::assertFalse($termEnd()['due_now']);
        self::assertTrue($termEnd()['done']['auto']);
        self::assertSame($b['id'], $termEnd()['done']['successor_id']);
        self::assertSame([], array_values(array_filter($this->recs->all(), fn($r) => str_starts_with($r['id'], 'r6_'))));
        self::assertSame($a['id'], $this->activity->list(['kind' => 'contract'])[0]['ref']['contract_id']);
    }

    // ── Teil B: Abhaken, Rückgängig, Vorkommen ───────────────────────────

    public function testMarkDoneAndUndoToggleDueNowAndTheRecommendation(): void
    {
        [$meterId, $a] = $this->seedStrom();
        $cancel = $this->byKind()['cancel_by'];
        self::assertTrue($cancel['can_mark_done']);
        self::assertSame('cancel_by-' . $a['id'] . '@' . $this->day(40), $cancel['occurrence'], 'Vorkommen: Vertragsende');

        $res = $this->agenda->markDone(['uid' => $cancel['uid'], 'occurrence' => $cancel['occurrence'], 'note' => 'per Brief']);
        self::assertSame('done', $res['entry']['action']);
        self::assertNull($res['entry']['by'], 'ohne Anmeldung: niemand');
        self::assertSame('per Brief', $res['entry']['note']);
        self::assertSame('calendar.event.cancelBy', $res['entry']['title_key']);
        $now = $this->byKind()['cancel_by'];
        self::assertFalse($now['due_now']);
        self::assertSame(['auto' => false, 'on' => $this->today, 'activity_id' => $res['activity_id'], 'note' => 'per Brief'], $now['done']);
        self::assertSame([], $this->r6(), 'abgehakt: auch die Empfehlung ruht');

        // zweiter Klick: kein zweiter Eintrag
        $again = $this->agenda->markDone(['uid' => $cancel['uid']]);
        self::assertTrue($again['duplicate']);
        self::assertCount(1, $this->activity->all());

        $undone = $this->activity->undo($res['activity_id']);
        self::assertArrayHasKey('at', $undone['undone']);
        self::assertTrue($this->byKind()['cancel_by']['due_now'], 'Rückgängig: wieder fällig');
        self::assertCount(1, $this->r6());
        self::assertCount(1, $this->activity->all(), 'Rückgängig streicht durch, löscht nicht');
    }

    public function testAChangedContractEndReopensTheHint(): void
    {
        [$meterId, $a] = $this->seedStrom();
        $this->agenda->markDone(['uid' => 'cancel_by-' . $a['id']]);
        self::assertFalse($this->byKind()['cancel_by']['due_now']);
        $this->contracts->update('strom', $a['id'], ['end' => $this->day(50)]);
        $e = $this->byKind()['cancel_by'];
        self::assertTrue($e['due_now'], 'neues Vertragsende = neues Vorkommen');
        self::assertSame('cancel_by-' . $a['id'] . '@' . $this->day(50), $e['occurrence']);
    }

    public function testCancelledSetsAutoRenewsFalseAndUndoRestoresIt(): void
    {
        [$meterId, $a] = $this->seedStrom();
        self::assertNull($a['auto_renews']);
        $res = $this->agenda->markDone(['uid' => 'cancel_by-' . $a['id'], 'cancelled' => true, 'done_on' => $this->day(-1)]);
        self::assertFalse($this->contracts->get('strom', $a['id'])['auto_renews'], 'gekündigt: verlängert sich nicht');
        self::assertSame($this->day(-1), $res['entry']['done_on'], 'rückdatiert');
        self::assertTrue($res['entry']['ref']['cancelled']);
        self::assertTrue($this->activity->undo($res['activity_id'])['reverted']);
        self::assertNull($this->contracts->get('strom', $a['id'])['auto_renews']);
    }

    public function testOnlyManualKindsCanBeMarkedDone(): void
    {
        [$meterId] = $this->seedStrom();
        $this->setReadings('strom', $meterId, [
            ['date' => $this->day(-400), 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => $this->day(-100), 'counter' => 2500.0, 'device_id' => 'd1'],
        ]);
        $reading = $this->byKind()['reading_due'];
        self::assertTrue($reading['due_now']);
        self::assertFalse($reading['can_mark_done']);
        try {
            $this->agenda->markDone(['uid' => $reading['uid']]);
            self::fail('reading_due darf nicht abhakbar sein');
        } catch (HttpStatusException $e) {
            self::assertSame([422, 'errors.activity.notManual'], [$e->status, $e->key]);
        }
        foreach ([['uid' => 'gibt-es-nicht'], ['uid' => 'cancel_by-x']] as $in) {
            try { $this->agenda->markDone($in); self::fail('unbekannt'); } catch (HttpStatusException $e) { self::assertSame(404, $e->status); }
        }
        $cancel = $this->byKind()['cancel_by'];
        foreach ([['done_on' => $this->day(1)], ['done_on' => '2026-02-30'], ['note' => str_repeat('x', 501)], ['occurrence' => 'alt@2020-01-01']] as $bad) {
            try {
                $this->agenda->markDone(['uid' => $cancel['uid']] + $bad);
                self::fail('ungültig: ' . json_encode($bad));
            } catch (HttpStatusException $e) {
                self::assertContains($e->status, [400, 409], json_encode($bad));
            }
        }
        self::assertSame([], $this->activity->all(), 'nichts geschrieben');
    }

    public function testRemindersDoneTwiceMakeAHistory(): void
    {
        $r = $this->reminderService->create(['title' => 'Heizung warten', 'category' => 'heizung_wartung',
            'next_due' => $this->day(-3), 'recurrence' => 'yearly']);
        $ev = array_values(array_filter($this->agenda->events(90, $this->today), fn($e) => $e['kind'] === 'reminder'))[0];
        self::assertTrue($ev['can_mark_done']);
        $first = $this->agenda->markDone(['uid' => $ev['uid'], 'done_on' => $this->day(-2)]);
        self::assertSame('reminder-' . $r['id'] . '@' . $this->day(-3), $first['entry']['subject']['occurrence']);
        $this->who = ['type' => 'person', 'id' => 'u_anna', 'name' => 'Anna'];
        $second = $this->reminderService->markDone($r['id'], $this->today, 'Filter gewechselt');
        self::assertArrayHasKey('activity_id', $second);

        $hist = $this->activity->list(['ref' => $r['id']]);
        self::assertCount(2, $hist, 'zweimal erledigt = zwei Einträge');
        self::assertSame([$this->today, $this->day(-2)], array_column($hist, 'done_on'), 'neueste zuerst');
        self::assertSame('Anna', $hist[0]['by']['name']);
        $listed = array_values(array_filter($this->reminderService->listWithStatus(), fn($x) => $x['id'] === $r['id']))[0];
        self::assertSame(['type' => 'person', 'id' => 'u_anna', 'name' => 'Anna'], $listed['last_done_by'], 'zuletzt erledigt von');

        // Rückgängig des zweiten stellt den Termin auf den Stand davor
        $nextBefore = $this->reminderService->list()[0]['next_due'];
        self::assertTrue($this->activity->undo($second['activity_id'])['reverted']);
        $now = $this->reminderService->list()[0];
        self::assertNotSame($nextBefore, $now['next_due']);
        self::assertSame($this->day(-2), $now['last_done']);
        self::assertNull($this->reminderService->listWithStatus()[0]['last_done_by'] ?? null, 'der erste Eintrag war ohne Anmeldung');
    }

    // ── Automatische Einträge ────────────────────────────────────────────

    public function testAReadingIsLoggedOnlyWhenItWasDue(): void
    {
        [$meterId] = $this->seedStrom();   // letzte Ablesung vor 10 Tagen
        $this->readings->create('strom', ['meter_id' => $meterId, 'date' => $this->day(-1), 'counter' => 3550]);
        self::assertSame([], $this->activity->all(), 'nicht fällig: kein Eintrag (sonst flutet der Ingest)');

        $this->setReadings('strom', $meterId, [
            ['date' => $this->day(-400), 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => $this->day(-100), 'counter' => 2500.0, 'device_id' => 'd1'],
        ]);
        $this->activity->actAs('home_assistant');
        $this->readings->create('strom', ['meter_id' => $meterId, 'date' => $this->today, 'counter' => 3600, 'source' => 'ingest']);
        $log = $this->activity->all();
        self::assertCount(1, $log);
        self::assertSame(['auto_done', 'reading_due'], [$log[0]['action'], $log[0]['subject']['kind']]);
        self::assertSame(['type' => 'integration', 'name' => 'home_assistant'], $log[0]['by']);
        self::assertSame("reading-strom-$meterId@" . $this->day(-100), $log[0]['subject']['occurrence']);
        self::assertSame('activity.reason.reading', $log[0]['reason']['key']);
        // nachgetragen (älter als die letzte): kein Eintrag
        $this->readings->create('strom', ['meter_id' => $meterId, 'date' => $this->day(-50), 'counter' => 3000]);
        self::assertCount(1, $this->activity->all());
    }

    public function testADeliveryIsLoggedWhenTheTankWasLow(): void
    {
        $tank = $this->meters->create('heizoel', ['name' => 'Keller', 'installed_on' => $this->day(-400), 'capacity' => 3000, 'initial_stock' => 300]);
        $this->settings->set(['active_utilities' => ['gas', 'strom', 'wasser', 'heizoel']]);
        $this->tankLow = false;
        $this->deliveries->create('heizoel', ['meter_id' => $tank['id'], 'date' => $this->day(-30), 'quantity' => 100]);
        self::assertSame([], $this->activity->all(), 'Bestand nicht niedrig: kein Eintrag');
        $this->tankLow = true;
        $this->deliveries->create('heizoel', ['meter_id' => $tank['id'], 'date' => $this->day(-90), 'quantity' => 100]);
        self::assertSame([], $this->activity->all(), 'ältere Lieferung nachgetragen: kein Eintrag');
        $this->deliveries->create('heizoel', ['meter_id' => $tank['id'], 'date' => $this->today, 'quantity' => 2000, 'is_planned' => false]);
        $log = $this->activity->all();
        self::assertCount(1, $log);
        self::assertSame('tank_reorder-heizoel-' . $tank['id'] . '@' . $this->day(-30), $log[0]['subject']['occurrence']);
        self::assertSame('activity.reason.delivery', $log[0]['reason']['key']);
    }

    public function testAStatementIsLoggedWhenItCoversTheOpenPeriod(): void
    {
        $this->settings->set(['wohnverhaeltnis' => 'miete']);
        $y = (int)substr($this->today, 0, 4);
        $t = $this->tenancies->create(['start' => ($y - 2) . '-01-01', 'billing_anchor' => '01-01', 'label' => 'Wohnung']);
        $open = ($y - 1) . '-12-31';
        self::assertSame($open, AgendaService::openStatementPeriod($t, [], $this->today));
        $this->tenancies->createStatement($t['id'], ['period_from' => ($y - 3) . '-01-01', 'period_to' => ($y - 3) . '-12-31']);
        self::assertSame([], $this->activity->all(), 'alter Zeitraum: Hinweis bleibt offen');
        $this->tenancies->createStatement($t['id'], ['period_from' => ($y - 1) . '-01-01', 'period_to' => $open, 'received_on' => $this->today]);
        $log = $this->activity->all();
        self::assertCount(1, $log);
        self::assertSame("tenancy_statement_due-{$t['id']}-$open@$open", $log[0]['subject']['occurrence']);
        self::assertNull(AgendaService::openStatementPeriod($t, $this->tenancies->statements($t['id']), $this->today));
    }

    public function testObjectionDeadlineCanBeTickedOff(): void
    {
        $this->settings->set(['wohnverhaeltnis' => 'miete']);
        $y = (int)substr($this->today, 0, 4);
        $t = $this->tenancies->create(['start' => ($y - 3) . '-01-01', 'billing_anchor' => '01-01', 'label' => 'Wohnung']);
        // Zugang vor gut elf Monaten: Einwandfrist in rund 25 Tagen
        $received = date('Y-m-d', (int)strtotime("{$this->today} -1 year +25 days"));
        $this->tenancies->createStatement($t['id'], ['period_from' => ($y - 2) . '-01-01', 'period_to' => ($y - 2) . '-12-31', 'received_on' => $received]);
        $ev = $this->byKind()['objection_deadline'] ?? self::fail('Einwandfrist fehlt');
        self::assertTrue($ev['due_now'] && $ev['can_mark_done']);
        $this->agenda->markDone(['uid' => $ev['uid'], 'note' => 'Belege angefordert']);
        $after = $this->byKind()['objection_deadline'];
        self::assertFalse($after['due_now']);
        self::assertSame('Belege angefordert', $after['done']['note']);
    }

    // ── Empfehlungen ausblenden ──────────────────────────────────────────

    public function testDismissingR6HoldsAtLeastUntilTheDeadlineAndIsLogged(): void
    {
        $this->settings->set(['contract_remind_days_1' => 120]);
        [$meterId, $a] = $this->seedStrom(['end' => $this->day(90)]);   // Stichtag ≈ +59 Tage
        $r6 = $this->r6()[0];
        $res = $this->recs->dismiss($r6['id']);
        self::assertSame($this->statusOf($meterId, $a['id'])['cancel_by'], $res['until'], 'nicht vor dem Stichtag zurück');
        self::assertGreaterThan($this->day(30), $res['until']);
        $entry = $this->activity->get((string)$res['activity_id']);
        self::assertSame(['dismissed', 'recommendation', $r6['title']], [$entry['action'], $entry['subject']['kind'], $entry['title']]);
        self::assertSame([], $this->r6());
        // Rückgängig im Verlauf blendet wieder ein
        self::assertTrue($this->activity->undo($entry['id'])['reverted']);
        self::assertCount(1, $this->r6());
        // Wieder einblenden über die Empfehlung streicht den Eintrag
        $again = $this->recs->dismiss($r6['id']);
        $this->recs->restore($r6['id']);
        self::assertNotEmpty($this->activity->get((string)$again['activity_id'])['undone']);
    }

    // ── Kalender, Backup, Aufbewahrung, Datenschutz ──────────────────────

    public function testCalendarKeepsTheUidDropsTheAlarmAndMarksDone(): void
    {
        [, $a] = $this->seedStrom();
        $cal = new CalendarService($this->agenda, $this->settings, new InstanceService($this->store), $this->i18n);
        $block = function (string $ics, string $uid): string {
            preg_match('/BEGIN:VEVENT\r\nUID:' . preg_quote($uid, '/') . '@.*?END:VEVENT/s', $ics, $m);
            return $m[0] ?? '';
        };
        $uid = 'cancel_by-' . $a['id'];
        $before = $block($cal->ics($this->today), $uid);
        self::assertStringContainsString('BEGIN:VALARM', $before);
        $this->agenda->markDone(['uid' => $uid]);
        $after = $block($cal->ics($this->today), $uid);
        self::assertNotSame('', $after, 'gleiche UID, Ereignis bleibt');
        self::assertStringNotContainsString('BEGIN:VALARM', $after, 'erledigt: keine Erinnerung');
        self::assertMatchesRegularExpression('/SUMMARY:✓ /u', $after);
    }

    public function testBackupRoundTripOldBackupCheckAndPruning(): void
    {
        $backups = new BackupService($this->store, $this->i18n);
        $this->who = ['type' => 'person', 'id' => 'u_1', 'name' => 'Anna'];
        $r = $this->reminderService->create(['title' => 'Rauchmelder', 'category' => 'custom', 'next_due' => $this->day(-1), 'recurrence' => 'yearly']);
        $this->reminderService->markDone($r['id']);
        $export = $backups->export(false);
        self::assertCount(1, $export['activity'], 'Topf im Backup');
        self::assertSame('Anna', $export['activity'][0]['by']['name'], 'Namen als Momentaufnahme');

        $this->store->write('activity.json', []);
        $backups->import($export, false, true);
        self::assertSame($export['activity'], $this->activity->all(), 'Rundreise');

        // altes Backup ohne Topf: Verlauf bleibt
        $old = $export;
        unset($old['activity']);
        $report = $backups->import($old, true);
        self::assertContains('activity', $report['untouched']);
        $backups->import($old, false, true);
        self::assertCount(1, $this->activity->all());

        // Prüfung vor dem Schreiben
        $bad = $export;
        $bad['activity'][] = ['id' => 'act_000000000001', 'action' => 'vergessen', 'subject' => ['kind' => 'x', 'uid' => 'x', 'occurrence' => 'x@y'], 'done_on' => $this->today];
        $bad['activity'][] = ['id' => 'act_000000000002', 'action' => 'done', 'subject' => 'kaputt', 'done_on' => '2026-13-01'];
        try {
            $backups->import($bad, true);
            self::fail('ungültiger Verlauf wurde angenommen');
        } catch (BackupInvalidException $e) {
            $problems = array_column($e->problems, 'problem');
            foreach (['action', 'date:done_on'] as $p) self::assertContains($p, $problems);
        }
        self::assertCount(1, $this->activity->all(), 'nichts eingespielt');

        // Aufbewahrung: beim Schreiben fällt heraus, was länger her ist; 0 = unbegrenzt
        $all = $this->activity->all();
        $all[] = ['id' => 'act_0000000000aa', 'action' => 'done', 'subject' => ['kind' => 'reminder', 'uid' => 'r', 'occurrence' => 'r@x'],
                  'ref' => [], 'done_on' => $this->day(-800), 'recorded_at' => date('c'), 'by' => null, 'note' => ''];
        $this->store->write('activity.json', $all);
        $this->settings->set(['activity_keep_months' => 0]);
        $this->reminderService->markDone($r['id']);
        self::assertNotNull($this->activity->get('act_0000000000aa'), '0 = unbegrenzt');
        $this->settings->set(['activity_keep_months' => 24]);
        $this->reminderService->markDone($r['id']);
        self::assertNull($this->activity->get('act_0000000000aa'), 'älter als 24 Monate: aufgeräumt');
        self::assertCount(3, $this->activity->all());
    }

    /**
     * Gründe und Fehlercodes stehen als Schlüssel in Arrays und Ausnahmen —
     * die Literalprüfung des Katalogs (nur t()) sieht sie nicht.
     */
    public function testReasonAndErrorKeysExistInEveryLanguage(): void
    {
        $root = dirname(__DIR__, 3);
        $src = '';
        foreach (['ActivityService', 'AgendaService', 'ReminderService', 'RecommendationService'] as $f) {
            $src .= (string)file_get_contents("$root/src/Services/$f.php");
        }
        $src .= (string)file_get_contents("$root/src/Controllers/ActivityController.php");
        preg_match_all("/'((?:activity\.reason|errors\.activity)\.\w+)'/", $src, $m);
        $keys = array_values(array_unique($m[1]));
        self::assertGreaterThanOrEqual(10, count($keys), implode(', ', $keys));
        foreach (json_decode((string)file_get_contents("$root/public/locales/languages.json"), true) as $lang => $_) {
            foreach ($keys as $k) self::assertNotSame($k, $this->i18n->t($k, [], $lang), "$lang: $k fehlt");
        }
    }

    public function testClearAndAnonymize(): void
    {
        $r = $this->reminderService->create(['title' => 'Eichung', 'category' => 'custom', 'next_due' => $this->day(-1), 'recurrence' => 'yearly']);
        $this->who = ['type' => 'person', 'id' => 'u_anna', 'name' => 'Anna'];
        $a = $this->reminderService->markDone($r['id'], $this->day(-5));
        $this->activity->undo($a['activity_id']);
        $this->reminderService->markDone($r['id'], $this->day(-1));
        self::assertSame(2, $this->activity->anonymize('u_anna'));
        foreach ($this->activity->all() as $e) {
            self::assertSame(['type' => 'person', 'former' => true], $e['by']);
            if (!empty($e['undone'])) self::assertSame(['type' => 'person', 'former' => true], $e['undone']['by']);
        }
        self::assertStringNotContainsString('Anna', (string)json_encode($this->activity->all()));
        self::assertSame(1, $this->activity->clear($this->day(-2)), 'nur vor dem Stichtag');
        self::assertSame(1, $this->activity->clear(null));
        self::assertSame([], $this->activity->all());
    }
}
