<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\BackupService;
use Energietracker\Services\DemoDataAligner;
use Energietracker\Services\PeopleService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.6.0 — Personen in den Beispieldaten (tools/build-personas.mjs). Befund
 * des Maintainers: Termine waren Namen zugewiesen, die nicht als Personen
 * angelegt waren. Jetzt hat jeder Haushalt ein Personenverzeichnis; jede
 * Zuständigkeit und jede Person im Verlauf zeigt auf eine existierende oder als
 * gelöscht markierte Person, je Haushalt verwaltet mindestens eine, und ein
 * Haushalt zeigt eine gelöschte Person mit erledigten Einträgen. Die
 * gebaute Pages-Demo prüft tests/demo.test.mjs (checkPeople).
 */
final class PersonaPeopleTest extends ServiceTestCase
{
    private const PERSONAS = ['mieterin', 'etw-fernwaerme', 'eigenheim-klassisch', 'eigenheim-modern'];
    private const TODAY = '2026-10-19';

    /** @return array<string,array{0:string}> die vier Haushalte und das Schaufenster */
    public static function households(): array
    {
        $ids = [...self::PERSONAS, 'showcase'];
        return array_combine($ids, array_map(fn($p) => [$p], $ids));
    }

    private static function load(string $id): array
    {
        $root = dirname(__DIR__, 3);
        $file = $id === 'showcase' ? '/demo-data/energietracker-demo-backup.json' : "/demo-data/personas/$id.json";
        return json_decode((string)file_get_contents($root . $file), true, 512, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('households')]
    public function testEveryAssigneeAndEveryPersonInTheHistoryIsAPersonOfTheHousehold(string $id): void
    {
        $p = self::load($id);
        $reg = $p['people'] ?? null;
        self::assertIsArray($reg, 'Personenverzeichnis fehlt');
        self::assertSame(PeopleService::normalize($reg), $reg, 'Form wie PeopleService');
        self::assertSame(range(1, count($reg['people'])), array_column($reg['people'], 'no'), 'fortlaufend');
        self::assertSame(count($reg['people']), $reg['last_no']);
        $people = array_column($reg['people'], null, 'id');
        $admins = array_filter($reg['people'], fn($x) => $x['role'] === 'admin' && $x['deleted_at'] === null);
        self::assertNotEmpty($admins, 'mindestens eine Person verwaltet');

        $assigned = 0;
        foreach ($p['reminders'] as $r) {
            if (!isset($r['assignee'])) continue;
            $assigned++;
            $person = $people[$r['assignee_id'] ?? ''] ?? null;
            self::assertNotNull($person, "Termin {$r['title']}: {$r['assignee']} ist nicht angelegt");
            self::assertSame($person['name'], $r['assignee']);
            if ($person['deleted_at'] !== null) {
                self::assertFalse($r['active'] ?? true, 'offene Termine einer gelöschten Person gehen an die löschende');
            }
        }
        self::assertGreaterThan(0, $assigned, 'Haushalt mit Zuständigkeiten');

        // v3.7.0 — Zuständig an Zählern und Verträgen: nur Personen des Verzeichnisses, keine gelöschte
        $records = ['meters' => 0, 'contracts' => 0];
        foreach ($p['utilities'] as $u => $bucket) {
            foreach (array_keys($records) as $pot) {
                foreach ($bucket[$pot] ?? [] as $x) {
                    if (!isset($x['assignee_id'])) continue;
                    $records[$pot]++;
                    $person = $people[$x['assignee_id']] ?? null;
                    self::assertNotNull($person, "$u/$pot {$x['id']}: Zuständige ist nicht angelegt");
                    self::assertNull($person['deleted_at'], "$u/$pot {$x['id']}: Zuständige ist gelöscht");
                }
            }
        }
        self::assertSame(['meters' => 1, 'contracts' => 1], $records, 'ein Zähler und ein Vertrag mit Zuständiger');
        foreach ($p['reminders'] as $r) {
            if (!isset($r['meter_id'])) continue;
            self::assertContains($r['meter_id'], array_column($p['utilities'][$r['utility']]['meters'] ?? [], 'id'), "Termin {$r['title']}: Zähler fehlt");
        }

        foreach ($p['activity'] as $e) {
            foreach ([$e['by'] ?? null, $e['undone']['by'] ?? null, $e['assignee'] ?? null] as $who) {
                if (!is_array($who) || !empty($who['former']) || (isset($who['type']) && $who['type'] !== 'person')) continue;
                $person = $people[$who['id'] ?? ''] ?? null;
                self::assertNotNull($person, "Verlauf: {$who['name']} ist nicht angelegt");
                self::assertSame($person['name'], $who['name']);
                self::assertSame($person['deleted_at'] !== null, !empty($who['deleted']), "{$who['name']}: Vermerk „gelöscht“ wie im Verzeichnis");
            }
        }
        foreach ($reg['people'] as $x) {
            if ($x['deleted_at'] === null) continue;
            self::assertSame('admin', $people[$x['deleted_by']]['role'] ?? null, 'gelöscht hat eine Person, die verwaltet');
        }
    }

    public function testOneHouseholdShowsADeletedPersonWithDoneEntries(): void
    {
        $found = [];
        foreach (array_keys(self::households()) as $id) {
            $p = self::load($id);
            foreach ($p['activity'] as $e) {
                if (!empty($e['by']['deleted']) && empty($e['undone']) && in_array($e['action'], ['done', 'auto_done'], true)) $found[$e['by']['name']] = $id;
            }
        }
        self::assertNotEmpty($found, 'keine gelöschte Person mit erledigten Einträgen');
    }

    /**
     * Doku-Behauptung (hinweise-erledigen.md § 5, datenmodell.md, DE und EN):
     * Beispielhaushalte bringen einen Verlauf mit erfundenen Personen mit, und ein
     * Beispielhaushalt laden ersetzt den vorhandenen — samt Personenverzeichnis.
     */
    public function testLoadingASampleHouseholdReplacesHistoryAndRegister(): void
    {
        $backups = new BackupService($this->store, $this->i18n);
        $backups->import(self::load('showcase'), false, true);
        $next = self::load('mieterin');
        self::assertNotEmpty($next['activity']);
        $backups->import($next, false, true);
        $activity = new ActivityService($this->store, $this->settings, $this->i18n);
        self::assertSame(array_column($next['activity'], 'id'), array_column($activity->all(), 'id'));
        self::assertSame(array_column($next['people']['people'], 'id'), array_column((new PeopleService($this->store))->all()['people'], 'id'));
    }

    /** Verzeichnisform (cp -R demo-data …) und Backup des Schaufensters führen dasselbe Verzeichnis. */
    public function testTheShowcaseDirectoryCarriesTheSameRegister(): void
    {
        $root = dirname(__DIR__, 3);
        $dir = json_decode((string)file_get_contents("$root/demo-data/people.json"), true);
        self::assertSame(self::load('showcase')['people'], $dir);
        // v3.7.0 — und dieselben Zuständigkeiten an Zählern, Verträgen und Terminen
        $bak = self::load('showcase');
        foreach (['meters', 'contracts'] as $pot) {
            foreach (array_keys($bak['utilities']) as $u) {
                $file = "$root/demo-data/$u/$pot.json";
                if (!is_file($file)) continue;
                $want = array_filter(array_column($bak['utilities'][$u][$pot], 'assignee_id', 'id'));
                $have = array_filter(array_column((array)json_decode((string)file_get_contents($file), true), 'assignee_id', 'id'));
                self::assertSame($want, $have, "$u/$pot: Verzeichnisform und Backup");
            }
        }
        $links = fn(array $list) => array_map(fn($r) => [$r['id'], $r['utility'] ?? null, $r['meter_id'] ?? null], $list);
        self::assertSame($links($bak['reminders']), $links(json_decode((string)file_get_contents("$root/demo-data/reminders.json"), true)));
    }

    /**
     * v3.7.0 — In jedem Haushalt landen Fristen bei einer Person, auch ohne
     * eigene Zuständigkeit am Termin: ein Termin am Zähler erbt die Person des
     * Zählers (Agenda), im Schaufenster liegt die erwartete Abrechnung Strom über
     * den Vertrag bei Anna.
     */
    #[DataProvider('households')]
    public function testDeadlinesInheritThePersonOfMeterOrContract(string $id): void
    {
        $p = DemoDataAligner::align(self::load($id), self::TODAY);
        (new BackupService($this->store, $this->i18n))->import($p, false, true);
        $people = new PeopleService($this->store);
        $resp = new \Energietracker\Services\ResponsibilityService($this->store);
        $resp->attachPeople(fn(string $pid): ?array => $people->find($pid));
        $rem = new ReminderService($this->store, $this->settings, $this->i18n);
        $rem->attachPeople(fn(string $pid): ?array => $people->find($pid));
        $rem->attachResponsibility($resp);
        $deliveries = new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $agenda = new \Energietracker\Services\AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $rem, $recs, $this->i18n);
        $agenda->attachResponsibility($resp);
        $periods = new \Energietracker\Services\PeriodService($this->store, $this->meters, $this->i18n);
        $bills = new \Energietracker\Services\BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $agenda->attachBillExpectations(new \Energietracker\Services\BillExpectedService($this->settings, $this->meters, $this->contracts,
            $this->readings, $periods, $bills));
        $events = $agenda->events(730, self::TODAY);
        $inherited = array_filter($events, fn($e) => in_array($e['assignee_source'], ['contract', 'meter'], true) && $e['assignee'] !== null);
        self::assertNotEmpty($inherited, 'mindestens eine Frist erbt eine Person');
        foreach ($inherited as $e) self::assertFalse($e['assignee_deleted'], "{$e['uid']}: keine gelöschte Person");
        if ($id === 'showcase') {
            $bill = array_values(array_filter($events, fn($e) => $e['kind'] === 'bill_expected' && $e['ref']['utility'] === 'strom'))[0] ?? null;
            self::assertNotNull($bill, 'Schaufenster: Abrechnung Strom erwartet');
            self::assertSame(['Anna', 'contract'], [$bill['assignee'], $bill['assignee_source']]);
        }
    }

    /** Eingespielt (ausgerichtet bis heute) und wieder gesichert: Verzeichnis und Vermerke bleiben; die App zeigt sie. */
    #[DataProvider('households')]
    public function testTheRegisterSurvivesImportAndExport(string $id): void
    {
        $p = DemoDataAligner::align(self::load($id), self::TODAY);
        $backups = new BackupService($this->store, $this->i18n);
        $backups->import($p, false, true);
        $export = $backups->export(false);
        self::assertSame($p['people'], $export['people']);

        $people = new PeopleService($this->store);
        $rem = new ReminderService($this->store, $this->settings, $this->i18n);
        $rem->attachPeople(fn(string $pid): ?array => $people->find($pid));
        foreach ($rem->listWithStatus() as $r) {
            if ($r['assignee'] === null) continue;
            self::assertNotNull($people->find((string)$r['assignee_id']));
            self::assertFalse($r['assignee_deleted'], "{$r['title']}: offen, Person nicht gelöscht");
        }
        $active = array_column($people->active(), 'name');
        foreach ($p['people']['people'] as $x) {
            self::assertSame($x['deleted_at'] === null, in_array($x['name'], $active, true), "{$x['name']}: zur Wahl nur, wenn nicht gelöscht");
        }
        $activity = new ActivityService($this->store, $this->settings, $this->i18n);
        foreach ($activity->all() as $e) {
            if (!empty($e['by']['deleted'])) {
                self::assertSame($e['by']['name'] . ' (gelöscht)', $activity->describe($e)['by'], 'CSV: Name mit „(gelöscht)“');
            }
        }
    }
}
