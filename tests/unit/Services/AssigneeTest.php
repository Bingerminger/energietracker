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
    /** @var array<string,string> v3.6.0 — gelöschte Personen im Verzeichnis (ID → Name) */
    private array $gone = [];
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = date('Y-m-d');
        $this->reminderService = new ReminderService($this->store, $this->settings, $this->i18n);
        $this->reminderService->attachPeople(fn(string $id): ?array => isset($this->people[$id]) ? ['id' => $id, 'name' => $this->people[$id]]
            : (isset($this->gone[$id]) ? ['id' => $id, 'name' => $this->gone[$id], 'deleted' => true] : null));
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

    /**
     * v3.6.0 — ersetzt testDeletingAPersonDropsTheIdAndOnRequestTheName (bis
     * v3.5.0 blieb nur der Name als freier Name stehen): Offene Termine gehen
     * an die löschende Person, auch ein freier Name derselben Person; erledigte
     * behalten Name und ID mit `assignee_deleted`.
     */
    public function testDeletingAPersonHandsOpenRemindersToTheDeleterAndKeepsTheDoneOnes(): void
    {
        $open = $this->rem(['assignee_id' => 'u_anna']);
        $free = $this->rem(['assignee' => 'anna', 'title' => 'Garten']);   // freier Name derselben Person
        $once = $this->rem(['assignee_id' => 'u_anna', 'title' => 'Ablesung', 'recurrence' => 'none']);
        $other = $this->rem(['assignee_id' => 'u_ben', 'title' => 'Zähler']);
        $stranger = $this->rem(['assignee' => 'Annabell', 'title' => 'Fenster']);
        $entry = $this->activity->get((string)$this->reminderService->markDone($once['id'], $this->today)['activity_id']);
        self::assertSame(2, $this->reminderService->openCountFor('u_anna', 'Anna'), 'Vorschau: zwei offene');

        $this->gone['u_anna'] = 'Anna';
        unset($this->people['u_anna']);
        self::assertSame(['handed_over' => 2, 'kept' => 1],
            $this->reminderService->personDeleted('u_anna', false, ['id' => 'u_ben', 'name' => 'Ben'], 'Anna'));
        self::assertSame(['Ben', 'u_ben', false], self::trio($this->listed($open['id'])));
        self::assertFalse($this->listed($open['id'])['assignee_deleted']);
        self::assertSame('u_ben', $this->stored($free['id'])['assignee_id'], 'freier Name derselben Person geht mit');
        $done = $this->stored($once['id']);
        self::assertSame(['Anna', 'u_anna', true], [$done['assignee'], $done['assignee_id'], $done['assignee_deleted']], 'erledigt: Name bleibt');
        self::assertTrue($this->reminderService->withAssignee($done)['assignee_deleted']);
        self::assertSame('Annabell', $this->listed($stranger['id'])['assignee'], 'ein anderer Name bleibt');
        self::assertSame('u_ben', $this->stored($other['id'])['assignee_id']);
        // der Verlauf behält den Namen vom Erledigen mit dem Vermerk
        self::assertSame(1, $this->activity->markDeleted('u_anna'));
        self::assertSame(['name' => 'Anna', 'id' => 'u_anna', 'deleted' => true], $this->activity->get($entry['id'])['assignee']);
        // eine gelöschte Person lässt sich nicht mehr zuweisen
        self::assertSame($this->i18n->t('errors.reminder.assigneeUnknown'), $this->error(fn() => $this->rem(['assignee_id' => 'u_anna'])));
    }

    /**
     * v3.6.0 — Ohne löschende Person (API-Schlüssel) bleiben offene Termine
     * mit dem Vermerk stehen; „ehemalige Person“ ersetzt den Namen erledigter,
     * offene gehen trotzdem über. Ein Termin, dessen Person das Verzeichnis
     * als gelöscht führt, trägt den Vermerk auch ohne Feld am Termin.
     */
    public function testWithoutRecipientOrWithAnonymizingAndFromTheRegister(): void
    {
        $open = $this->rem(['assignee_id' => 'u_anna']);
        $this->gone['u_anna'] = 'Anna';
        unset($this->people['u_anna']);
        self::assertSame(['handed_over' => 0, 'kept' => 1], $this->reminderService->personDeleted('u_anna', false, null, 'Anna'));
        $l = $this->listed($open['id']);
        self::assertSame(['Anna', 'u_anna', false, true], [$l['assignee'], $l['assignee_id'], $l['assignee_former'], $l['assignee_deleted']]);
        // neu zuweisen hebt den Vermerk auf
        $this->reminderService->update($open['id'], ['assignee_id' => 'u_ben']);
        self::assertArrayNotHasKey('assignee_deleted', $this->stored($open['id']));

        // „ehemalige Person“: offene gehen über, erledigte verlieren den Namen
        $this->people['u_carl'] = 'Carl';
        $a = $this->rem(['assignee_id' => 'u_carl']);
        $done = $this->rem(['assignee_id' => 'u_carl', 'title' => 'Einmal', 'recurrence' => 'none']);
        $this->reminderService->markDone($done['id'], $this->today);
        unset($this->people['u_carl']);
        self::assertSame(['handed_over' => 1, 'kept' => 1], $this->reminderService->personDeleted('u_carl', true, ['id' => 'u_ben', 'name' => 'Ben'], 'Carl'));
        self::assertSame(['Ben', 'u_ben', false], self::trio($this->listed($a['id'])));
        self::assertSame([null, null, true], self::trio($this->reminderService->withAssignee($this->stored($done['id']))));

        // Verzeichnis: gelöscht, ohne Feld am Termin (etwa aus einem Backup)
        $this->gone['u_dora'] = 'Dora';
        $this->store->write('reminders.json', [...$this->store->read('reminders.json', []),
            ['id' => 'rem_dora0001', 'title' => 'Dach', 'category' => 'custom', 'next_due' => $this->day(5), 'recurrence' => 'yearly',
             'recurrence_months' => null, 'last_done' => null, 'notes' => '', 'active' => true, 'assignee_id' => 'u_dora']]);
        $d = $this->listed('rem_dora0001');
        self::assertSame(['Dora', 'u_dora', true], [$d['assignee'], $d['assignee_id'], $d['assignee_deleted']], 'Name aus dem Verzeichnis');
    }

    /** v3.6.0 — Der Name eines einmaligen Termins wird beim Erledigen eingefroren (umbenannt danach: bleibt). */
    public function testTheNameIsFrozenWhenTheReminderIsDone(): void
    {
        $once = $this->rem(['assignee_id' => 'u_anna', 'recurrence' => 'none']);
        $this->people['u_anna'] = 'Anna Maria';
        $this->reminderService->markDone($once['id'], $this->today);
        $this->people['u_anna'] = 'Anna Berg';
        self::assertSame('Anna Maria', $this->reminderService->withAssignee($this->stored($once['id']))['assignee']);
    }

    public function testAgendaEventsAndCalendarCarryTheAssignee(): void
    {
        $a = $this->rem(['assignee_id' => 'u_anna']);
        $f = $this->rem(['title' => 'Schornsteinfeger']);
        $this->reminderService->personDeleted('u_x', true);   // betrifft niemanden
        $this->store->write('reminders.json', array_map(fn($r) => $r['id'] === $f['id'] ? $r + ['assignee_former' => true] : $r,
            $this->store->read('reminders.json', [])));
        $plain = $this->rem(['title' => 'Eichfrist']);
        // v3.6.0 — Person gelöscht (offener Termin ohne Übernahme): Name mit „(gelöscht)“
        $g = $this->rem(['assignee_id' => 'u_ben', 'title' => 'Dachrinne']);
        $this->gone['u_ben'] = 'Ben';
        unset($this->people['u_ben']);
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
        self::assertTrue($events['reminder-' . $g['id']]['assignee_deleted']);
        self::assertFalse($ea['assignee_deleted']);
        self::assertStringContainsString("\r\nDESCRIPTION:Zuständig: Ben (gelöscht)\r\n", $block('reminder-' . $g['id']));
        // DESCRIPTION der Vorab-Erinnerung bleibt der Titel
        self::assertMatchesRegularExpression('/BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Heizung warten/', $block('reminder-' . $a['id']));
    }
}
