<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\BillExpectedService;
use Energietracker\Services\BillService;
use Energietracker\Services\CalendarService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\PeriodService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\ResponsibilityService;
use Energietracker\Services\TenancyService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 — Zuständigkeit an Zählern und Verträgen: Fristen landen bei einer
 * Person. Feldvertrag `assignee_id` (Person aus dem Verzeichnis, additiv,
 * fehlt = niemand), Erbregel eigene am Termin → Vertrag → Zähler → niemand
 * (Agenda, Kalender, Verlauf), Übergabe beim Löschen einer Person wie bei
 * offenen Terminen, Backup.
 */
#[CoversClass(ResponsibilityService::class)]
final class ResponsibilityTest extends ServiceTestCase
{
    private ReminderService $reminderService;
    private ResponsibilityService $resp;
    private AgendaService $agenda;
    private ActivityService $activity;
    private BillService $bills;
    /** @var array<string,string> Personen (ID → Name) */
    private array $people = ['u_anna' => 'Anna', 'u_ben' => 'Ben', 'u_carl' => 'Carl'];
    /** @var array<string,string> gelöschte Personen im Verzeichnis */
    private array $gone = [];
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = date('Y-m-d');
        $lookup = fn(string $id): ?array => isset($this->people[$id]) ? ['id' => $id, 'name' => $this->people[$id]]
            : (isset($this->gone[$id]) ? ['id' => $id, 'name' => $this->gone[$id], 'deleted' => true] : null);
        $this->reminderService = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->reminderService->attachPeople($lookup);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $tenancies = new TenancyService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $this->agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $this->reminderService, $recs, $this->i18n, $tenancies);
        $this->activity = new ActivityService($this->store, $this->settings, $this->i18n);
        foreach ([$this->readings, $deliveries, $tenancies, $this->contracts, $this->reminderService, $recs, $this->agenda] as $svc) {
            $svc->attachActivity($this->activity);
        }
        $this->activity->attach($this->reminderService, $this->contracts, $recs);
        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $expected = new BillExpectedService($this->settings, $this->meters, $this->contracts, $this->readings, $periods, $this->bills);
        $this->agenda->attachBillExpectations($expected);
        // verdrahtet wie in bootstrap.php
        $this->resp = new ResponsibilityService($this->store);
        $this->resp->attachPeople($lookup);
        foreach ([$this->meters, $this->contracts, $this->reminderService, $this->agenda] as $svc) $svc->attachResponsibility($this->resp);
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', (int)strtotime("{$this->today} $offset days"));
    }

    private function unknown(callable $fn): string
    {
        try { $fn(); } catch (LocalizedException $e) { return $e->key; }
        catch (\InvalidArgumentException $e) { return $e->getMessage(); }
        self::fail('keine Ablehnung');
    }

    /** gespeicherter Zähler bzw. Vertrag (ohne Aufbereitung) */
    private function stored(string $file, string $id): array
    {
        foreach ($this->store->read($file, []) as $x) if (($x['id'] ?? null) === $id) return $x;
        self::fail("$id fehlt in $file");
    }

    /** Strom: Zähler (Ablesung vor 100 Tagen) und Vertrag mit Kündigungsfrist in 10 Tagen. */
    private function seed(array $meterExtra = [], array $contractExtra = []): array
    {
        $m = $this->meters->create('strom', $meterExtra + ['name' => 'Haus', 'installed_on' => $this->day(-400)]);
        $dev = $m['devices'][0]['id'];
        $this->setReadings('strom', $m['id'], [
            ['date' => $this->day(-400), 'counter' => 0.0, 'device_id' => $dev],
            ['date' => $this->day(-100), 'counter' => 2500.0, 'device_id' => $dev],
        ]);
        $c = $this->contracts->create('strom', $contractExtra + [
            'meter_id' => $m['id'], 'provider' => 'Testwerk', 'start' => $this->day(-300), 'end' => $this->day(40),
            'notice_period_months' => 1, 'price_guarantee_until' => $this->day(60),
            'working_prices'   => [['from' => $this->day(-300), 'ct_per_kwh' => 30]],
            'advance_payments' => [['from' => $this->day(-300), 'amount_eur' => 80]],
        ]);
        return [$m, $c];
    }

    /** @return array<string,array<string,mixed>> Ereignisse je UID */
    private function events(): array
    {
        return array_column($this->agenda->events(90, $this->today), null, 'uid');
    }

    /** @return array{0:mixed,1:mixed,2:mixed} Name, ID, Herkunft */
    private static function who(array $e): array
    {
        return [$e['assignee'], $e['assignee_id'], $e['assignee_source']];
    }

    public function testMeterAndContractCarryAPersonFromTheRegister(): void
    {
        // ohne Angabe: kein Feld (Bestand bleibt byte-gleich)
        $plain = $this->meters->create('gas', ['name' => 'Gas']);
        self::assertArrayNotHasKey('assignee_id', $this->stored('gas/meters.json', $plain['id']));

        $m = $this->meters->create('gas', ['name' => 'Keller', 'assignee_id' => 'u_anna']);
        self::assertSame('u_anna', $this->stored('gas/meters.json', $m['id'])['assignee_id']);
        self::assertSame(['assignee_id' => 'u_anna', 'assignee' => 'Anna', 'assignee_former' => false, 'assignee_deleted' => false],
            $this->resp->view($this->meters->get('gas', $m['id'])));

        // andere Felder ändern lässt sie stehen; null bzw. leer = niemand
        $this->meters->update('gas', $m['id'], ['notes' => 'Flur']);
        self::assertSame('u_anna', $this->stored('gas/meters.json', $m['id'])['assignee_id']);
        $this->meters->update('gas', $m['id'], ['assignee_id' => 'u_ben']);
        self::assertSame('u_ben', $this->stored('gas/meters.json', $m['id'])['assignee_id']);
        $this->meters->update('gas', $m['id'], ['assignee_id' => '']);
        self::assertArrayNotHasKey('assignee_id', $this->stored('gas/meters.json', $m['id']));

        // nur Personen, die es gibt — kein freier Name, keine gelöschte, keine erfundene
        self::assertSame('errors.assignee.unknown', $this->unknown(fn() => $this->meters->update('gas', $m['id'], ['assignee_id' => 'Anna'])));
        self::assertSame('errors.assignee.unknown', $this->unknown(fn() => $this->meters->create('gas', ['name' => 'X', 'assignee_id' => 'u_niemand'])));
        self::assertSame('errors.assignee.unknown', $this->unknown(fn() => $this->meters->update('gas', $m['id'], ['assignee_id' => ['u_anna']])));
        $this->gone['u_dora'] = 'Dora';
        self::assertSame('errors.assignee.unknown', $this->unknown(fn() => $this->meters->update('gas', $m['id'], ['assignee_id' => 'u_dora'])));

        // Vertrag (Standard und Wasser): dasselbe Feld
        [, $c] = $this->seed([], ['assignee_id' => 'u_carl']);
        self::assertSame('u_carl', $this->stored('strom/contracts.json', $c['id'])['assignee_id']);
        $this->contracts->update('strom', $c['id'], ['notes' => 'online']);
        self::assertSame('u_carl', $this->stored('strom/contracts.json', $c['id'])['assignee_id'], 'andere Felder ändern die Zuständigkeit nicht');
        self::assertSame('errors.assignee.unknown', $this->unknown(fn() => $this->contracts->update('strom', $c['id'], ['assignee_id' => 'u_niemand'])));
        $w = $this->contracts->create('wasser', ['meter_id' => $this->meters->defaultId('wasser'), 'provider' => 'Wasserwerk', 'start' => $this->day(-100),
            'trinkwasser' => ['working_prices' => [['from' => $this->day(-100), 'ct_per_m3' => 250]]], 'assignee_id' => 'u_ben']);
        self::assertSame('u_ben', $this->stored('wasser/contracts.json', $w['id'])['assignee_id']);
        $this->contracts->update('strom', $c['id'], ['assignee_id' => null]);
        self::assertArrayNotHasKey('assignee_id', $this->stored('strom/contracts.json', $c['id']));

        // eine inzwischen gelöschte Person bleibt beim unveränderten Speichern stehen
        $this->contracts->update('strom', $c['id'], ['assignee_id' => 'u_anna']);
        unset($this->people['u_anna']);
        $this->gone['u_anna'] = 'Anna';
        $this->contracts->update('strom', $c['id'], ['assignee_id' => 'u_anna', 'notes' => 'unverändert']);
        $v = $this->resp->view($this->stored('strom/contracts.json', $c['id']));
        self::assertSame(['Anna', 'u_anna', true], [$v['assignee'], $v['assignee_id'], $v['assignee_deleted']], 'Name aus dem Verzeichnis, Vermerk „gelöscht“');
        // eine ID, die das Verzeichnis nicht kennt (fremdes Backup), zeigt niemanden
        self::assertSame([null, null, false], array_values(array_intersect_key($this->resp->view(['assignee_id' => 'u_fremd']),
            array_flip(['assignee', 'assignee_id', 'assignee_deleted']))));
    }

    public function testDeadlinesInheritOwnBeforeContractBeforeMeter(): void
    {
        [$m, $c] = $this->seed(['assignee_id' => 'u_ben'], ['assignee_id' => 'u_anna']);
        $e = $this->events();
        self::assertSame(['Anna', 'u_anna', 'contract'], self::who($e['cancel_by-' . $c['id']]), 'Kündigungsfrist: Vertrag');
        self::assertSame(['Anna', 'u_anna', 'contract'], self::who($e['term_end-' . $c['id']]), 'Vertragsende (Stichtag der Erinnerung): Vertrag');
        self::assertSame(['Anna', 'u_anna', 'contract'], self::who($e['price_guarantee_end-' . $c['id']]));
        self::assertSame(['Ben', 'u_ben', 'meter'], self::who($e["reading-strom-{$m['id']}"]), 'Ablesung: Zähler');

        // ohne Zuständige am Vertrag: die des Zählers
        $this->contracts->update('strom', $c['id'], ['assignee_id' => null]);
        self::assertSame(['Ben', 'u_ben', 'meter'], self::who($this->events()['cancel_by-' . $c['id']]));
        // ohne beide: niemand
        $this->meters->update('strom', $m['id'], ['assignee_id' => null]);
        $none = $this->events()['cancel_by-' . $c['id']];
        self::assertSame([null, null, null], self::who($none));
        self::assertFalse($none['assignee_deleted']);

        // Termin am Zähler (Eichfrist): ohne eigene die des Zählers; eine eigene geht vor
        $this->meters->update('strom', $m['id'], ['assignee_id' => 'u_ben']);
        $eich = $this->reminderService->create(['title' => 'Eichfrist Stromzähler', 'category' => 'stromzaehler_eichung',
            'next_due' => $this->day(20), 'recurrence' => 'none', 'utility' => 'strom', 'meter_id' => $m['id']]);
        $own = $this->reminderService->create(['title' => 'Zählerwechsel', 'category' => 'custom', 'next_due' => $this->day(25),
            'utility' => 'strom', 'meter_id' => $m['id'], 'assignee_id' => 'u_carl']);
        $loose = $this->reminderService->create(['title' => 'Rauchmelder', 'category' => 'custom', 'next_due' => $this->day(30)]);
        $e = $this->events();
        self::assertSame(['Ben', 'u_ben', 'meter'], self::who($e['reminder-' . $eich['id']]), 'Eichfrist: Zähler');
        self::assertSame(['strom', $m['id']], [$e['reminder-' . $eich['id']]['ref']['utility'], $e['reminder-' . $eich['id']]['ref']['meter_id']]);
        self::assertSame(['Carl', 'u_carl', 'own'], self::who($e['reminder-' . $own['id']]), 'eigene Zuständigkeit geht vor');
        self::assertSame([null, null, null], self::who($e['reminder-' . $loose['id']]), 'ohne Zähler und ohne eigene: niemand');
        // eine gelöschte eigene Zuständigkeit bleibt stehen (erbt nicht), bis jemand neu zuweist
        unset($this->people['u_carl']);
        $this->gone['u_carl'] = 'Carl';
        $g = $this->events()['reminder-' . $own['id']];
        self::assertSame(['Carl', 'own', true], [$g['assignee'], $g['assignee_source'], $g['assignee_deleted']]);
        // jedes Ereignis trägt das Feld (additiv)
        foreach ($e as $x) self::assertArrayHasKey('assignee_source', $x, $x['kind']);
    }

    public function testALowTankGoesToTheTanksPerson(): void
    {
        $tank = $this->meters->create('heizoel', ['name' => 'Keller', 'installed_on' => $this->day(-400), 'capacity' => 3000,
            'initial_stock' => 2500, 'assignee_id' => 'u_carl']);
        $this->store->write('heizoel/deliveries.json', [
            ['id' => 'del_1', 'meter_id' => $tank['id'], 'date' => $this->day(-380), 'quantity' => 500, 'price_cents' => 100.0, 'is_planned' => false],
        ]);
        $this->store->write('heizoel/meters.json', array_map(fn($m) => $m + ['tank_levels' => [['date' => $this->day(-10), 'level' => 150]]],
            $this->store->read('heizoel/meters.json', [])));
        $this->settings->set(['active_utilities' => ['strom', 'heizoel']]);
        $tankEvents = array_values(array_filter($this->agenda->events(90, $this->today), fn($e) => $e['kind'] === 'tank_reorder'));
        self::assertNotEmpty($tankEvents, 'Bestand niedrig');
        self::assertSame(['Carl', 'u_carl', 'meter'], self::who($tankEvents[0]));
    }

    public function testBillExpectedTakesTheContractsPersonThenTheMeters(): void
    {
        // Strom: Periode endete vor 31 Tagen, keine Rechnung erfasst
        $d = $this->day(-30);
        if (substr($d, 5) === '02-29') $d = $this->day(-31);
        $this->settings->set(['billing_cycle_anchor_strom' => substr($d, 5)]);
        $m = $this->meters->create('strom', ['name' => 'Haus', 'installed_on' => $this->day(-800), 'assignee_id' => 'u_ben']);
        $dev = $m['devices'][0]['id'];
        $this->setReadings('strom', $m['id'], [
            ['date' => $this->day(-800), 'counter' => 0.0, 'device_id' => $dev],
            ['date' => $this->day(-5), 'counter' => 4000.0, 'device_id' => $dev],
        ]);
        $c = $this->contracts->create('strom', ['meter_id' => $m['id'], 'provider' => 'Werk Nord', 'start' => $this->day(-700),
            'working_prices' => [['from' => $this->day(-700), 'ct_per_kwh' => 30]], 'advance_payments' => [['from' => $this->day(-700), 'amount_eur' => 80]]]);
        $bill = fn() => array_values(array_filter($this->agenda->events(90, $this->today), fn($e) => $e['kind'] === 'bill_expected'))[0] ?? null;
        self::assertNotNull($bill(), 'Abrechnung erwartet');
        self::assertSame(['Ben', 'u_ben', 'meter'], self::who($bill()));
        $this->contracts->update('strom', $c['id'], ['assignee_id' => 'u_anna']);
        self::assertSame(['Anna', 'u_anna', 'contract'], self::who($bill()));
    }

    public function testRemindersOnAMeterShowAndRecordTheInheritedPerson(): void
    {
        [$m] = $this->seed(['assignee_id' => 'u_ben']);
        $r = $this->reminderService->create(['title' => 'Eichfrist', 'category' => 'stromzaehler_eichung', 'next_due' => $this->day(-1),
            'recurrence' => 'yearly', 'utility' => 'strom', 'meter_id' => $m['id']]);
        self::assertArrayNotHasKey('assignee', $this->stored('reminders.json', $r['id']), 'der Termin selbst bleibt ohne Zuständigkeit');
        $listed = array_column($this->reminderService->listWithStatus(), null, 'id')[$r['id']];
        self::assertSame([null, ['Ben', 'u_ben', 'meter']], [$listed['assignee'],
            [$listed['assignee_inherited']['assignee'], $listed['assignee_inherited']['assignee_id'], $listed['assignee_inherited']['assignee_source']]]);
        // mit eigener Zuständigkeit: nichts geerbt
        $this->reminderService->update($r['id'], ['assignee' => 'Oma Gerda']);
        self::assertNull(array_column($this->reminderService->listWithStatus(), null, 'id')[$r['id']]['assignee_inherited']);
        $this->reminderService->update($r['id'], ['assignee' => '', 'assignee_id' => null]);

        // Erledigen: der Verlauf nennt die geerbte Person als Momentaufnahme
        $done = $this->reminderService->markDone($r['id'], $this->today);
        self::assertSame(['name' => 'Ben', 'id' => 'u_ben'], $this->activity->get((string)$done['activity_id'])['assignee']);

        // Zähler prüfen: unbekannt, falsche Art, ohne Art → 400; leer = ohne Zähler
        $msg = $this->i18n->t('errors.reminder.meterUnknown');
        self::assertSame($msg, $this->unknown(fn() => $this->reminderService->update($r['id'], ['utility' => 'strom', 'meter_id' => 'm_gibtsnicht'])));
        self::assertSame($msg, $this->unknown(fn() => $this->reminderService->update($r['id'], ['utility' => 'gas', 'meter_id' => $m['id']])));
        self::assertSame($msg, $this->unknown(fn() => $this->reminderService->create(['title' => 'X', 'next_due' => $this->today, 'meter_id' => $m['id']])));
        self::assertSame($msg, $this->unknown(fn() => $this->reminderService->update($r['id'], ['utility' => 'erdbeeren', 'meter_id' => $m['id']])));
        $this->reminderService->update($r['id'], ['meter_id' => null]);
        $s = $this->stored('reminders.json', $r['id']);
        self::assertArrayNotHasKey('meter_id', $s);
        self::assertArrayNotHasKey('utility', $s);
        self::assertNull(array_column($this->reminderService->listWithStatus(), null, 'id')[$r['id']]['assignee_inherited']);
        // ein gelöschter Zähler: der Termin erbt nichts mehr
        $this->store->write('reminders.json', [...$this->store->read('reminders.json', []),
            ['id' => 'rem_weg00001', 'title' => 'Alt', 'category' => 'custom', 'next_due' => $this->day(3), 'recurrence' => 'none',
             'recurrence_months' => null, 'last_done' => null, 'notes' => '', 'active' => true, 'utility' => 'strom', 'meter_id' => 'm_strom_weg']]);
        self::assertSame([null, null, null], self::who($this->events()['reminder-rem_weg00001']));
    }

    public function testMarkingADeadlineDoneRecordsWhoWasResponsible(): void
    {
        [, $c] = $this->seed([], ['assignee_id' => 'u_anna']);
        $ev = $this->events()['cancel_by-' . $c['id']];
        $r = $this->agenda->markDone(['uid' => $ev['uid'], 'occurrence' => $ev['occurrence'], 'note' => 'gekündigt']);
        self::assertSame(['name' => 'Anna', 'id' => 'u_anna'], $r['entry']['assignee'], 'Momentaufnahme der geerbten Zuständigkeit');
        // ohne Zuständige: kein Feld
        $this->contracts->update('strom', $c['id'], ['assignee_id' => null]);
        $pg = $this->events()['price_guarantee_end-' . $c['id']];
        self::assertSame([null, null, null], self::who($pg));
    }

    public function testDeletingAPersonHandsMetersAndContractsToTheDeleter(): void
    {
        [$m, $c] = $this->seed(['assignee_id' => 'u_anna'], ['assignee_id' => 'u_anna']);
        $other = $this->meters->create('gas', ['name' => 'Gas', 'assignee_id' => 'u_ben']);
        self::assertSame(['meters' => 1, 'contracts' => 1], $this->resp->countFor('u_anna'), 'Vorschau für den Dialog');

        unset($this->people['u_anna']);
        $this->gone['u_anna'] = 'Anna';
        self::assertSame(['handed_over' => 2, 'kept' => 0], $this->resp->personDeleted('u_anna', false, ['id' => 'u_carl', 'name' => 'Carl']));
        self::assertSame('u_carl', $this->stored('strom/meters.json', $m['id'])['assignee_id']);
        self::assertSame('u_carl', $this->stored('strom/contracts.json', $c['id'])['assignee_id']);
        self::assertSame('u_ben', $this->stored('gas/meters.json', $other['id'])['assignee_id'], 'andere Personen bleiben');
        self::assertSame(['Carl', 'contract'], [$this->events()['cancel_by-' . $c['id']]['assignee'], $this->events()['cancel_by-' . $c['id']]['assignee_source']]);

        // ohne löschende Person (Anmeldung aus): die ID bleibt, angezeigt als „Name (gelöscht)“
        unset($this->people['u_ben']);
        $this->gone['u_ben'] = 'Ben';
        self::assertSame(['handed_over' => 0, 'kept' => 1], $this->resp->personDeleted('u_ben', false, null));
        self::assertSame('u_ben', $this->stored('gas/meters.json', $other['id'])['assignee_id']);
        $v = $this->resp->view($this->meters->get('gas', $other['id']));
        self::assertSame(['Ben', true], [$v['assignee'], $v['assignee_deleted']]);

        // „ehemalige Person“ ohne Übernahme: die ID weicht dem Vermerk
        unset($this->people['u_carl']);
        $this->gone['u_carl'] = 'Carl';
        self::assertSame(['handed_over' => 0, 'kept' => 2], $this->resp->personDeleted('u_carl', true, null));
        $s = $this->stored('strom/contracts.json', $c['id']);
        self::assertArrayNotHasKey('assignee_id', $s);
        self::assertTrue($s['assignee_former']);
        $e = $this->events()['cancel_by-' . $c['id']];
        self::assertSame([null, true, 'contract'], [$e['assignee'], $e['assignee_former'], $e['assignee_source']], 'zählt als niemand, erbt nicht weiter');
        // neu zuweisen hebt den Vermerk auf
        $this->people['u_dora'] = 'Dora';
        $this->contracts->update('strom', $c['id'], ['assignee_id' => 'u_dora']);
        self::assertArrayNotHasKey('assignee_former', $this->stored('strom/contracts.json', $c['id']));
        self::assertSame('u_dora', $this->stored('strom/contracts.json', $c['id'])['assignee_id']);
        // die löschende Person selbst übernimmt nichts von sich
        self::assertSame(['handed_over' => 0, 'kept' => 0], $this->resp->personDeleted('u_gibtsnicht', false, ['id' => 'u_gibtsnicht', 'name' => 'X']));
    }

    public function testCalendarPerPersonAndNamesTheSource(): void
    {
        [$m, $c] = $this->seed(['assignee_id' => 'u_ben'], ['assignee_id' => 'u_anna']);
        $free = $this->reminderService->create(['title' => 'Garten', 'category' => 'custom', 'next_due' => $this->day(5), 'assignee' => 'anna']);
        $cal = new CalendarService($this->agenda, $this->settings, new InstanceService($this->store), $this->i18n);
        $cal->attachResponsibility($this->resp);
        $block = fn(string $ics, string $uid) => (preg_match('/BEGIN:VEVENT\r\nUID:' . preg_quote($uid, '/') . '@.*?END:VEVENT/s', $ics, $mm) ? $mm[0] : '');

        $all = $cal->ics($this->today);
        self::assertStringContainsString("\r\nDESCRIPTION:Zuständig: Anna (über den Vertrag)\r\n", $block($all, 'cancel_by-' . $c['id']));
        self::assertStringContainsString("\r\nDESCRIPTION:Zuständig: Ben (über den Zähler)\r\n", $block($all, "reading-strom-{$m['id']}"));
        self::assertStringContainsString("\r\nDESCRIPTION:Zuständig: anna\r\n", $block($all, 'reminder-' . $free['id']));

        $anna = $cal->ics($this->today, 'u_anna');
        self::assertStringContainsString('X-WR-CALNAME:Energietracker — Fristen und Termine: Anna', $anna);
        self::assertNotSame('', $block($anna, 'cancel_by-' . $c['id']), 'geerbt vom Vertrag');
        self::assertNotSame('', $block($anna, 'reminder-' . $free['id']), 'freier Name derselben Person');
        self::assertSame('', $block($anna, "reading-strom-{$m['id']}"), 'die Ablesung liegt bei Ben');
        $ben = $cal->ics($this->today, 'u_ben');
        self::assertNotSame('', $block($ben, "reading-strom-{$m['id']}"));
        self::assertSame('', $block($ben, 'cancel_by-' . $c['id']));
        self::assertStringNotContainsString('BEGIN:VEVENT', $cal->ics($this->today, 'u_niemand'), 'unbekannte Person: leerer Kalender');
        // eine gelöschte Person hat keinen eigenen Kalender mehr (ihre offenen Termine liegen bei niemandem)
        $carl = $this->reminderService->create(['title' => 'Dach', 'category' => 'custom', 'next_due' => $this->day(7), 'assignee_id' => 'u_carl']);
        self::assertNotSame('', $block($cal->ics($this->today, 'u_carl'), 'reminder-' . $carl['id']));
        unset($this->people['u_carl']);
        $this->gone['u_carl'] = 'Carl';
        self::assertSame('', $block($cal->ics($this->today, 'u_carl'), 'reminder-' . $carl['id']), 'gelöscht: nicht mehr ihre');
    }

    public function testFieldsSurviveTheBackupAndTheImportChecksThem(): void
    {
        [$m, $c] = $this->seed(['assignee_id' => 'u_ben'], ['assignee_id' => 'u_anna']);
        $this->reminderService->create(['title' => 'Eichfrist', 'category' => 'stromzaehler_eichung', 'next_due' => $this->day(9),
            'utility' => 'strom', 'meter_id' => $m['id']]);
        $this->store->write('gas/contracts.json', [['id' => 'c_alt', 'meter_id' => $this->meters->defaultId('gas'), 'start' => '2024-01-01',
            'assignee_former' => true]]);
        $backups = new BackupService($this->store, $this->i18n);
        $dump = $backups->export(false);
        foreach (['strom/meters.json', 'strom/contracts.json', 'reminders.json', 'gas/contracts.json'] as $f) $this->store->write($f, []);
        $backups->import($dump, false, true);
        self::assertSame('u_ben', $this->stored('strom/meters.json', $m['id'])['assignee_id']);
        self::assertSame('u_anna', $this->stored('strom/contracts.json', $c['id'])['assignee_id']);
        self::assertTrue($this->stored('gas/contracts.json', 'c_alt')['assignee_former']);
        $rem = $this->store->read('reminders.json', [])[0];
        self::assertSame(['strom', $m['id']], [$rem['utility'], $rem['meter_id']]);
        self::assertSame(['Ben', 'meter'], [$this->events()['reminder-' . $rem['id']]['assignee'], $this->events()['reminder-' . $rem['id']]['assignee_source']]);

        // der Import prüft die Form: eine Personen-ID oder nichts, der Vermerk ein Wahrheitswert
        $bad = $dump;
        $bad['utilities']['strom']['meters'][0]['assignee_id'] = 'Anna';
        $bad['utilities']['strom']['contracts'][0]['assignee_former'] = 'ja';
        try {
            $backups->import($bad, true);
            self::fail('ungültige Zuständigkeit angenommen');
        } catch (\Energietracker\Services\BackupInvalidException $e) {
            $problems = array_map(fn($p) => $p['pot'] . ':' . $p['problem'], $e->problems);
            self::assertContains('strom/meters:assignee_id', $problems);
            self::assertContains('strom/contracts:assignee_former', $problems);
        }
    }
}
