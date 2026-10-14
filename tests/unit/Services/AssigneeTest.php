<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\CalendarService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\TenancyService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.5.0 (F1028) — Zuständig an Terminen. Feldvertrag (fest, die
 * Beispieldaten nutzen ihn): `assignee` (Anzeigename, getrimmt, bis 60
 * Zeichen, leer = niemand), `assignee_id` (Person aus auth.json, nur aus der
 * Liste gewählt). Kein Schemaschritt; fehlende Felder = niemand. Anzeige an der
 * Agenda und im Kalender (DESCRIPTION), Momentaufnahme im Verlauf,
 * „ehemalige Person“ beim Löschen.
 */
#[CoversClass(ReminderService::class)]
final class AssigneeTest extends ServiceTestCase
{
    private ReminderService $reminderService;
    private AgendaService $agenda;
    private ActivityService $activity;
    /** @var array<string,string> Personen (ID → Name), wie auth.json */
    private array $people = ['u_anna' => 'Anna', 'u_ben' => 'Ben'];
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = date('Y-m-d');
        $this->reminderService = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->reminderService->attachPeople(fn(string $id): ?array => isset($this->people[$id]) ? ['id' => $id, 'name' => $this->people[$id]] : null);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $this->agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $this->reminderService, $recs, $this->i18n, new TenancyService($this->store, $this->meters, $this->i18n));
        $this->activity = new ActivityService($this->store, $this->settings, $this->i18n);
        $this->reminderService->attachActivity($this->activity);
        $this->agenda->attachActivity($this->activity);
        $this->activity->attach($this->reminderService);
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', (int)strtotime("{$this->today} $offset days"));
    }

    private function rem(array $extra = []): array
    {
        return $this->reminderService->create($extra + ['title' => 'Heizung warten', 'category' => 'heizung_wartung',
            'next_due' => $this->day(-1), 'recurrence' => 'yearly']);
    }

    /** gespeicherter Datensatz (ohne Aufbereitung) */
    private function stored(string $id): array
    {
        foreach ($this->store->read('reminders.json', []) as $r) if ($r['id'] === $id) return $r;
        self::fail("Termin $id fehlt");
    }

    private function listed(string $id): array
    {
        foreach ($this->reminderService->listWithStatus() as $r) if ($r['id'] === $id) return $r;
        self::fail("Termin $id fehlt in der Liste");
    }

    /** @return array{0:mixed,1:mixed,2:mixed} assignee, assignee_id, assignee_former */
    private static function trio(array $r): array
    {
        return [$r['assignee'], $r['assignee_id'], $r['assignee_former']];
    }

    private function error(callable $fn): string
    {
        try { $fn(); } catch (\InvalidArgumentException $e) { return $e->getMessage(); }
        self::fail('keine Ablehnung');
    }

    public function testFieldContract(): void
    {
        // ohne Angabe: niemand — gespeichert ohne Felder, geliefert mit allen dreien
        $none = $this->rem();
        self::assertArrayNotHasKey('assignee', $this->stored($none['id']));
        self::assertArrayNotHasKey('assignee_id', $this->stored($none['id']));
        self::assertSame([null, null, false], [$none['assignee'], $none['assignee_id'], $none['assignee_former']]);
        self::assertSame([null, null, false], self::trio($this->listed($none['id'])));

        // freier Name: getrimmt, ohne ID
        $free = $this->rem(['assignee' => '  Oma Gerda  ']);
        self::assertSame('Oma Gerda', $this->stored($free['id'])['assignee']);
        self::assertArrayNotHasKey('assignee_id', $this->stored($free['id']));
        self::assertSame(['Oma Gerda', null], [$free['assignee'], $free['assignee_id']]);

        // Person aus der Liste: es gilt ihr Name
        $p = $this->rem(['assignee' => 'irgendwas', 'assignee_id' => 'u_anna']);
        self::assertSame(['Anna', 'u_anna'], [$this->stored($p['id'])['assignee'], $this->stored($p['id'])['assignee_id']]);

        // Grenzen: 60 Zeichen gehen, 61 nicht; Steuerzeichen, Nicht-Text, unbekannte Person nicht
        self::assertSame(str_repeat('x', 60), $this->rem(['assignee' => str_repeat('x', 60)])['assignee']);
        self::assertSame(60, ReminderService::ASSIGNEE_MAX);
        $tooLong = $this->i18n->t('errors.reminder.assigneeInvalid', ['max' => 60]);
        self::assertSame($tooLong, $this->error(fn() => $this->rem(['assignee' => str_repeat('x', 61)])));
        self::assertSame($tooLong, $this->error(fn() => $this->rem(['assignee' => "An\nna"])));
        self::assertSame($tooLong, $this->error(fn() => $this->rem(['assignee' => ['Anna']])));
        self::assertSame($this->i18n->t('errors.reminder.assigneeUnknown'), $this->error(fn() => $this->rem(['assignee_id' => 'u_gibtsnicht'])));
        self::assertSame($this->i18n->t('errors.reminder.assigneeUnknown'), $this->error(fn() => $this->reminderService->update($none['id'], ['assignee_id' => 42])));

        // Ändern: ohne die Felder unverändert; freier Name ersetzt die Person; leer = niemand
        $this->reminderService->update($p['id'], ['notes' => 'Filter']);
        self::assertSame('u_anna', $this->stored($p['id'])['assignee_id'], 'andere Felder ändern die Zuständigkeit nicht');
        $this->reminderService->update($p['id'], ['assignee' => 'Ben']);
        self::assertSame('Ben', $this->stored($p['id'])['assignee']);
        self::assertArrayNotHasKey('assignee_id', $this->stored($p['id']), 'freier Name: keine Person');
        $cleared = $this->reminderService->update($p['id'], ['assignee' => '', 'assignee_id' => null]);
        self::assertArrayNotHasKey('assignee', $this->stored($p['id']));
        self::assertNull($cleared['assignee']);
    }

    public function testRenamedPersonShowsTheCurrentName(): void
    {
        $r = $this->rem(['assignee_id' => 'u_anna']);
        $this->people['u_anna'] = 'Anna Maria';
        self::assertSame('Anna Maria', $this->listed($r['id'])['assignee']);
        // Beispieldaten setzen nur einen Namen: gilt wie eingegeben
        $this->store->write('reminders.json', [...$this->store->read('reminders.json', []),
            ['id' => 'rem_demo0001', 'title' => 'Rauchmelder', 'category' => 'custom', 'next_due' => $this->day(10), 'recurrence' => 'yearly',
             'recurrence_months' => null, 'last_done' => null, 'notes' => '', 'active' => true, 'assignee' => 'Lena']]);
        self::assertSame(['Lena', null, false], self::trio($this->listed('rem_demo0001')));
    }

    public function testRecurringReminderKeepsTheAssigneeAndTheHistoryTakesASnapshot(): void
    {
        $r = $this->rem(['assignee_id' => 'u_anna']);
        $done = $this->reminderService->markDone($r['id'], $this->today);
        self::assertTrue($done['active']);
        self::assertSame(['Anna', 'u_anna'], [$done['assignee'], $done['assignee_id']], 'wiederkehrend: bleibt zuständig');
        self::assertSame('u_anna', $this->stored($r['id'])['assignee_id']);
        $entry = $this->activity->get((string)$done['activity_id']);
        self::assertSame(['name' => 'Anna', 'id' => 'u_anna'], $entry['assignee'], 'Momentaufnahme im Verlauf');
        $this->people['u_anna'] = 'Anna Maria';
        self::assertSame('Anna', $this->activity->get((string)$done['activity_id'])['assignee']['name'], 'bleibt die Momentaufnahme');
        // ohne Zuständigkeit: kein Feld im Verlauf
        $plain = $this->reminderService->markDone($this->rem()['id'], $this->today);
        self::assertArrayNotHasKey('assignee', $this->activity->get((string)$plain['activity_id']));
    }

    public function testDeletingAPersonDropsTheIdAndOnRequestTheName(): void
    {
        $a = $this->rem(['assignee_id' => 'u_anna']);
        $b = $this->rem(['assignee_id' => 'u_anna', 'title' => 'Schornsteinfeger']);
        $free = $this->rem(['assignee' => 'Anna', 'title' => 'Garten']);   // freier Name, keine Person
        $other = $this->rem(['assignee_id' => 'u_ben', 'title' => 'Zähler']);
        $entry = $this->activity->get((string)$this->reminderService->markDone($a['id'], $this->today)['activity_id']);

        // ohne „Name ersetzen“: ID weg, Name bleibt
        unset($this->people['u_anna']);
        self::assertSame(2, $this->reminderService->personDeleted('u_anna', false));
        self::assertArrayNotHasKey('assignee_id', $this->stored($a['id']));
        self::assertSame('Anna', $this->listed($a['id'])['assignee']);

        // mit „ehemalige Person“ (wie im Verlauf)
        $this->reminderService->update($b['id'], ['assignee_id' => 'u_ben']);
        $this->people['u_anna'] = 'Anna';
        $this->reminderService->update($a['id'], ['assignee_id' => 'u_anna']);
        unset($this->people['u_anna']);
        self::assertSame(1, $this->reminderService->personDeleted('u_anna', true));
        self::assertSame([null, null, true], self::trio($this->listed($a['id'])));
        self::assertArrayNotHasKey('assignee', $this->stored($a['id']));
        self::assertSame('Anna', $this->listed($free['id'])['assignee'], 'ein freier Name gehört zu keiner Person');
        self::assertSame('u_ben', $this->stored($other['id'])['assignee_id']);
        self::assertSame(1, $this->activity->anonymize('u_anna'));
        self::assertSame(['former' => true], $this->activity->get($entry['id'])['assignee'], 'Verlauf: ehemalige Person');
        // neu zuweisen löscht „ehemalige Person“
        $this->reminderService->update($a['id'], ['assignee' => 'Chris']);
        self::assertArrayNotHasKey('assignee_former', $this->stored($a['id']));
    }

    public function testAgendaEventsAndCalendarCarryTheAssignee(): void
    {
        $a = $this->rem(['assignee_id' => 'u_anna']);
        $f = $this->rem(['title' => 'Schornsteinfeger']);
        $this->reminderService->personDeleted('u_x', true);   // betrifft niemanden
        $this->store->write('reminders.json', array_map(fn($r) => $r['id'] === $f['id'] ? $r + ['assignee_former' => true] : $r,
            $this->store->read('reminders.json', [])));
        $plain = $this->rem(['title' => 'Eichfrist']);
        $events = [];
        foreach ($this->agenda->events(30, $this->today) as $e) $events[$e['uid']] = $e;
        $ea = $events['reminder-' . $a['id']];
        self::assertSame(['Anna', 'u_anna', false], [$ea['assignee'], $ea['assignee_id'], $ea['assignee_former']]);
        self::assertSame([null, null, true], [$events['reminder-' . $f['id']]['assignee'], $events['reminder-' . $f['id']]['assignee_id'],
            $events['reminder-' . $f['id']]['assignee_former']]);
        foreach ($events as $e) {
            self::assertArrayHasKey('assignee', $e, $e['kind'] . ': Feld an jedem Ereignis');
            if ($e['kind'] !== 'reminder') self::assertSame([null, null, false], [$e['assignee'], $e['assignee_id'], $e['assignee_former']]);
        }

        $ics = (new CalendarService($this->agenda, $this->settings, new InstanceService($this->store), $this->i18n))->ics($this->today);
        $block = fn(string $uid) => (preg_match('/BEGIN:VEVENT\r\nUID:' . preg_quote($uid, '/') . '@.*?END:VEVENT/s', $ics, $m) ? $m[0] : '');
        self::assertStringContainsString("\r\nDESCRIPTION:Zuständig: Anna\r\n", $block('reminder-' . $a['id']));
        self::assertStringContainsString("\r\nDESCRIPTION:Zuständig: ehemalige Person\r\n", $block('reminder-' . $f['id']));
        self::assertStringNotContainsString('Zuständig', $block('reminder-' . $plain['id']), 'ohne Zuständigkeit keine Zeile');
        // DESCRIPTION der Vorab-Erinnerung bleibt der Titel
        self::assertMatchesRegularExpression('/BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Heizung warten/', $block('reminder-' . $a['id']));
    }
}
