<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ActivityService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DemoDataAligner;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.5.0 (N1026) — Beispieldaten für die neuen Anwendungsfälle.
 *
 * Jeder Beispielhaushalt (und das Schaufenster) zeigt: Verlauf „Erledigt“ mit
 * allen Aktionsarten und erfundenen Namen, Termine mit Zuständigkeit (F1028),
 * erfasste Versorgerrechnungen, die zur eigenen Rechnung passen, eine kürzlich
 * beendete Abrechnungsperiode ohne Rechnung bei Strom oder Gas (F1029),
 * lückenlose Folgeverträge und einen weiterlaufenden oder unbefristeten
 * Vertrag. Der Haushalt mit Sonnenstrom hat Viertelstunden, die je
 * Viertelstunde aufgehen und zwischen zwei Ablesungen genau die Differenz der
 * Stände ergeben (Tagesfilm F1027).
 */
final class PersonaUseCasesTest extends ServiceTestCase
{
    private const PERSONAS = ['mieterin', 'etw-fernwaerme', 'eigenheim-klassisch', 'eigenheim-modern'];

    /** Tag der Veröffentlichung von v3.5.0 — „heute“ der ausgerichteten Demo in diesen Tests. */
    private const TODAY = '2026-10-13';

    /**
     * Je Haushalt die Periode ohne Rechnung (F1029): Art, Vertrag und entweder
     * ein festes Periodenende (zur Veröffentlichung kürzlich beendet) oder
     * `days` — der Stichtag liegt relativ zu heute (`demo_align`), die Periode
     * endet immer so viele Tage vor heute. Strom oder Gas, weil nur dort die
     * Frist (sechs Wochen) „überfällig“ kennt.
     */
    private const OPEN_PERIOD = [
        'mieterin'            => ['strom', 'c_strom_online_2025', 'until' => '2026-09-30'],
        'etw-fernwaerme'      => ['strom', 'c_strom_flex', 'days' => 30],
        'eigenheim-klassisch' => ['gas', 'c_gas_fix', 'days' => 56],
        'eigenheim-modern'    => ['strom', 'c_strom_waermepumpe', 'until' => '2026-09-30'],
        'showcase'            => ['strom', 'c_strom_004', 'days' => 21],
    ];

    /** Fenster von F1029: Perioden, die länger her sind, erwartet die App nicht mehr. */
    private const BILL_WINDOW_DAYS = 120;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** Viertelstundenreihen sind große Listen: freigegebenen Speicher zurückgeben (Suite im Standardlimit). */
    protected function tearDown(): void
    {
        parent::tearDown();
        gc_collect_cycles();
        gc_mem_caches();
    }

    /** @return array<string,array{0:string}> */
    public static function personas(): array
    {
        return array_combine(self::PERSONAS, array_map(fn($p) => [$p], self::PERSONAS));
    }

    /** @return array<string,array{0:string}> die vier Haushalte und das Schaufenster */
    public static function households(): array
    {
        return self::personas() + ['showcase' => ['showcase']];
    }

    private static function load(string $id): array
    {
        $file = $id === 'showcase' ? '/demo-data/energietracker-demo-backup.json' : "/demo-data/personas/$id.json";
        return json_decode((string)file_get_contents(self::root() . $file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function import(array $payload): void
    {
        (new BackupService($this->store, $this->i18n))->import($payload);
        $m = new \Energietracker\Storage\Migrator($this->store);
        if ($m->needsMigration()) $m->migrate();
    }

    private static function days(string $a, string $b): int
    {
        return (int)(new \DateTimeImmutable($a))->diff(new \DateTimeImmutable($b))->format('%r%a');
    }

    // ── Verlauf „Erledigt“ ──────────────────────────────────────────────

    #[DataProvider('households')]
    public function testHistoryShowsEveryKindOfAction(string $id): void
    {
        $p = self::load($id);
        $log = $p['activity'];
        $actions = array_count_values(array_column($log, 'action'));
        // `updated` (v3.5.0, geänderte Nebenkostenabrechnung) gibt es nur, wo es Abrechnungen gibt
        $withStatements = !empty($p['tenancy_statements']);
        foreach (ActivityService::ACTIONS as $a) {
            if ($a === 'updated' && !$withStatements) continue;
            self::assertGreaterThan(0, $actions[$a] ?? 0, "Aktion $a");
        }
        self::assertNotEmpty(array_filter($log, fn($e) => !empty($e['undone'])), 'ein zurückgenommener Eintrag');

        $byName = [];
        foreach ($log as $e) {
            self::assertMatchesRegularExpression(ActivityService::ID, $e['id']);
            self::assertStringContainsString('@', $e['subject']['occurrence']);
            // von Hand: eine erfundene Person mit ID wie aus auth.json und Namen
            if ($e['action'] === 'done' || $e['action'] === 'dismissed') {
                self::assertSame('person', $e['by']['type']);
                self::assertMatchesRegularExpression('/^u_[0-9a-f]{8}$/', $e['by']['id']);
                $byName[$e['by']['name']] = $e['by']['id'];
            }
            // automatisch: immer mit Grund, in jeder Sprache neu darstellbar
            if ($e['action'] === 'auto_done') {
                self::assertStringStartsWith('activity.reason.', $e['reason']['key']);
                self::assertStringStartsWith('calendar.event.', (string)$e['title_key']);
            }
            if ($e['title_key'] === null) self::assertNotSame('', $e['title'], 'ohne Schlüssel ein Titel');
        }
        self::assertGreaterThanOrEqual(1, count($byName), 'mindestens eine Person');
        self::assertSame(count($byName), count(array_unique($byName)), 'je Name eine ID');

        $reasons = array_unique(array_filter(array_map(fn($e) => $e['reason']['key'] ?? null, array_filter($log, fn($e) => $e['action'] === 'auto_done'))));
        self::assertContains('activity.reason.successor', $reasons, 'Folgevertrag');
        self::assertContains('activity.reason.bill', $reasons, 'Rechnung erfasst');
        if (in_array($id, ['mieterin', 'eigenheim-klassisch'], true)) self::assertContains('activity.reason.reading', $reasons, 'Ablesung');
        if ($id === 'mieterin') self::assertContains('activity.reason.statement', $reasons, 'Nebenkostenabrechnung');
    }

    #[DataProvider('households')]
    public function testHistoryPointsToRecordsThatExist(string $id): void
    {
        $p = self::load($id);
        $reminders = array_column($p['reminders'], null, 'id');
        $bills = [];
        $contracts = [];
        foreach ($p['utilities'] as $b) {
            foreach ($b['bills'] ?? [] as $x) $bills[$x['id']] = $x;
            foreach ($b['contracts'] ?? [] as $c) $contracts[$c['id']] = $c;
        }
        $latest = [];
        foreach ($p['activity'] as $e) {
            $ref = $e['ref'];
            if (isset($ref['bill_id'])) {
                self::assertArrayHasKey($ref['bill_id'], $bills);
                self::assertSame("bill_expected-{$ref['contract_id']}-{$bills[$ref['bill_id']]['period_to']}", $e['subject']['uid']);
            }
            if (isset($ref['contract_id'])) self::assertArrayHasKey($ref['contract_id'], $contracts);
            if (isset($ref['successor_id'])) {
                self::assertArrayHasKey($ref['successor_id'], $contracts);
                $pred = $contracts[$ref['contract_id']];
                $peers = array_values(array_filter($contracts, fn($c) => $c['meter_id'] === $pred['meter_id']));
                $succ = ContractService::successorOf($pred, $peers, $e['subject']['occurrence'] === "contract-{$pred['id']}@renewed");
                self::assertSame($ref['successor_id'], $succ['contract_id'] ?? null);
                self::assertSame(0, $succ['gap_days'], 'lückenlos');
            }
            if (!empty($ref['cancelled'])) self::assertFalse($contracts[$ref['contract_id']]['auto_renews'], 'gekündigt: verlängert sich nicht');
            if (($e['subject']['kind'] ?? '') === 'reminder') {
                self::assertArrayHasKey($ref['reminder_id'], $reminders);
                if (empty($e['undone']) && ($latest[$ref['reminder_id']] ?? '') < $e['done_on']) $latest[$ref['reminder_id']] = $e['done_on'];
            }
        }
        // der jüngste Haken an einem Termin ist sein „zuletzt erledigt“
        foreach ($latest as $rid => $on) self::assertSame($reminders[$rid]['last_done'], $on, "Termin $rid");
    }

    // ── Termine mit Zuständigkeit (F1028) ───────────────────────────────

    #[DataProvider('households')]
    public function testRemindersNameWhoTakesCareOfThem(string $id): void
    {
        $p = self::load($id);
        $with = array_filter($p['reminders'], fn($r) => isset($r['assignee']));
        self::assertGreaterThanOrEqual(1, count($with));
        self::assertLessThanOrEqual(3, count($with));
        foreach ($p['reminders'] as $r) {
            self::assertArrayNotHasKey('assignee_id', $r, 'die Demo hat keine Personen');
            if (isset($r['assignee'])) {
                self::assertIsString($r['assignee']);
                self::assertNotSame('', trim($r['assignee']));
                self::assertLessThanOrEqual(60, mb_strlen($r['assignee']));
            }
        }
    }

    // ── Versorgerrechnungen und „Abrechnung erwartet“ (F1029) ───────────

    #[DataProvider('households')]
    public function testBillsMatchTheOwnCalculation(string $id): void
    {
        $p = self::load($id);
        $this->import($p);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $n = 0;
        foreach ($p['utilities'] as $u => $b) {
            foreach ($b['bills'] ?? [] as $x) {
                $n++;
                $cmp = $bills->compare($u, $x['id']);
                self::assertSame('ok', $cmp['verdict'], "$u {$x['period_from']}…{$x['period_to']}: " . json_encode($cmp['delta']));
                if (!empty($x['special_payment_id'])) {
                    $c = $this->contracts->get($u, (string)$x['contract_id']);
                    self::assertContains($x['special_payment_id'], array_column($c['special_payments'] ?? [], 'id'), 'gebucht');
                }
            }
        }
        self::assertGreaterThanOrEqual(1, $n, 'mindestens eine erfasste Rechnung');
    }

    /**
     * Die Periode ohne Rechnung: beendet vor „heute“, höchstens 120 Tage her
     * (Fenster von F1029), Ende = Vertragsende oder Tag vor dem Stichtag der
     * Art; keine Rechnung mit Ende in ±31 Tagen, aber eine abgerechnete Periode
     * davor. Mit relativem Stichtag gilt das zu jedem „heute“.
     */
    #[DataProvider('households')]
    public function testARecentlyEndedPeriodWaitsForItsBill(string $id): void
    {
        $open = self::OPEN_PERIOD[$id];
        [$u, $cid] = $open;
        self::assertContains($u, ['strom', 'gas'], 'Frist und „überfällig“ gibt es für Strom und Gas');
        $p = self::load($id);
        $todays = isset($open['days']) ? [self::TODAY, '2027-02-10', '2027-08-01', '2028-05-20'] : [self::TODAY];
        foreach ($todays as $today) {
            $a = DemoDataAligner::align($p, $today);
            self::assertArrayNotHasKey('demo_align', $a, 'Hinweis für die Ausrichtung wird nicht eingespielt');
            $c = array_column($a['utilities'][$u]['contracts'], null, 'id')[$cid] ?? self::fail("Vertrag $cid fehlt");
            $anchor = $a['settings']["billing_cycle_anchor_$u"] ?? '01-01';
            $until = $open['until'] ?? date('Y-m-d', strtotime("$today -{$open['days']} days"));
            self::assertLessThan($today, $until, $today);
            self::assertLessThanOrEqual(self::BILL_WINDOW_DAYS, self::days($until, $today), "$today: im Fenster");
            self::assertTrue($until === $c['end'] || substr(date('Y-m-d', strtotime("$until +1 day")), 5) === $anchor, "$today: Ende oder Stichtag");
            // beendet (verlängert sich nicht, Ende = Periodenende) oder läuft seit mindestens 60 Tagen
            if (($c['auto_renews'] ?? null) === false) self::assertSame($c['end'], $until, 'Schlussrechnung zum Vertragsende');
            else self::assertGreaterThanOrEqual(60, self::days($c['start'], $until), 'läuft lange genug');
            // überfällig nach sechs Wochen (Strom und Gas)
            if (($open['days'] ?? 0) >= 42) self::assertGreaterThanOrEqual(42, self::days($until, $today));
            // keine Rechnung in der Nähe (F1029: ±31 Tage), aber abgerechnete Perioden davor
            $bills = $a['utilities'][$u]['bills'];
            foreach ($bills as $b) self::assertGreaterThan(31, abs(self::days($b['period_to'], $until)), "$today: keine Rechnung für die offene Periode");
            self::assertNotEmpty(array_filter($bills, fn($b) => $b['period_to'] < $until), 'eine Periode davor ist abgerechnet');
            // Rechnungen schreibt die Ausrichtung nicht fort
            self::assertSame($p['utilities'][$u]['bills'], $bills);
        }
    }

    #[DataProvider('personas')]
    public function testContractsShowFollowUpAndRunningOn(string $id): void
    {
        $p = self::load($id);
        $successors = 0; $runsOn = 0;
        foreach ($p['utilities'] as $b) {
            $real = array_values(array_filter($b['contracts'], fn($c) => empty($c['is_shadow'])));
            foreach ($real as $c) {
                $renewed = !empty($c['end']) && $c['end'] < self::TODAY && ContractService::renews($c);
                $s = ContractService::successorOf($c, $real, $renewed);
                if ($s !== null && $s['gap_days'] === 0) $successors++;
                // weiterlaufend (Ende vorbei, kein Folgevertrag) oder unbefristet mit Mindestlaufzeit/Preisgarantie
                if (($renewed && $s === null) || (empty($c['end']) && (!empty($c['min_term_end']) || !empty($c['price_guarantee_until'])))) $runsOn++;
            }
        }
        self::assertGreaterThanOrEqual(1, $successors, 'lückenloser Folgevertrag');
        self::assertGreaterThanOrEqual(1, $runsOn, 'weiterlaufender oder unbefristeter Vertrag mit Zeitstrahl');
    }

    // ── Viertelstunden (Tagesfilm F1027) ────────────────────────────────

    /** Reihen des Haushalts mit Sonnenstrom: Art → Werte. */
    private static function quarterHours(array $p, int $year): array
    {
        $f = $p['interval_files'];
        $get = fn(string $id) => $f[$id]['values'] ?? self::fail("Reihe $id fehlt");
        return [
            'grid'      => $get("e-strom-m_strom_haushalt-$year"),
            'heat_pump' => $get("e-strom-m_strom_waermepumpe-$year"),
            'wallbox'   => $get("e-strom-m_strom_wallbox-$year"),
            'gen'       => $get("e-pv_erzeugung-m_pv_erzeugung_wr-$year"),
            'feed'      => $get("e-pv_einspeisung-m_pv_einspeisung-$year"),
            'charged'   => $get("e-pv_erzeugung-m_pv_speicher_laden-$year"),
            'discharged'=> $get("e-pv_erzeugung-m_pv_speicher_entladen-$year"),
            'price'     => $get("p-de-lu-$year"),
        ];
    }

    /**
     * Jede Viertelstunde für sich: alle Zähler mit Wert, Erzeugung deckt
     * Einspeisung und Laden (kein Netzladen), der Speicher lädt und entlädt nie
     * gleichzeitig, der Hausverbrauch umfasst die Wallbox, nachts keine Sonne.
     *
     * Plätze [$from, $to)
     * @return int geprüfte Viertelstunden
     */
    public static function assertBalancedSlots(array $q, int $from, int $to, int $year): int
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $start = (new \DateTimeImmutable("$year-01-01 00:00:00", $tz))->getTimestamp();
        for ($i = $from; $i < $to; $i++) {
            foreach ($q as $k => $v) self::assertNotNull($v[$i] ?? null, "$k fehlt in Viertelstunde $i");
            $self = $q['gen'][$i] - $q['feed'][$i] - $q['charged'][$i];
            self::assertGreaterThanOrEqual(-0.001, $self, "Eigenverbrauch ≥ 0 ($i)");
            self::assertFalse($q['charged'][$i] > 0.0001 && $q['discharged'][$i] > 0.0001, "Laden und Entladen zugleich ($i)");
            $house = $q['grid'][$i] + $self + $q['discharged'][$i];
            self::assertGreaterThanOrEqual($q['wallbox'][$i] - 0.001, $house, "Haus ≥ Wallbox ($i)");
            $hour = (int)(new \DateTimeImmutable('@' . ($start + $i * 900)))->setTimezone($tz)->format('G');
            if ($hour >= 23 || $hour < 3) self::assertSame(0.0, (float)$q['gen'][$i], "nachts keine Erzeugung ($i)");
        }
        return $to - $from;
    }

    public function testQuarterHoursOfTheSolarHouseholdBalanceInEverySlot(): void
    {
        $p = self::load('eigenheim-modern');
        $q = self::quarterHours($p, 2025);
        self::assertCount(35040, $q['gen']);
        self::assertSame(35040, self::assertBalancedSlots($q, 0, 35040, 2025));
        // Sommer: mittags viel Erzeugung, Speicher lädt vormittags und entlädt abends
        self::assertGreaterThan(1.0, max(array_slice($q['gen'], 172 * 96, 96)), 'Juni-Mittag über 4 kW');
        self::assertGreaterThan(0.0, array_sum($q['feed']), 'Einspeisung');
    }

    public function testQuarterHoursAddUpToTheReadings(): void
    {
        $p = self::load('eigenheim-modern');
        $q = self::quarterHours($p, 2025);
        $tz = new \DateTimeZone('Europe/Berlin');
        $start = (new \DateTimeImmutable('2025-01-01 00:00:00', $tz))->getTimestamp();
        $idx = fn(string $d) => intdiv((new \DateTimeImmutable("$d 00:00:00", $tz))->getTimestamp() - $start, 900);
        $meters = ['strom' => ['m_strom_haushalt' => 'grid', 'm_strom_waermepumpe' => 'heat_pump', 'm_strom_wallbox' => 'wallbox'],
                   'pv_erzeugung' => ['m_pv_erzeugung_wr' => 'gen', 'm_pv_speicher_laden' => 'charged', 'm_pv_speicher_entladen' => 'discharged'],
                   'pv_einspeisung' => ['m_pv_einspeisung' => 'feed']];
        $pairs = 0;
        foreach ($meters as $u => $list) {
            foreach ($list as $mid => $k) {
                $rs = array_values(array_filter($p['utilities'][$u]['readings'], fn($r) => $r['meter_id'] === $mid && $r['date'] >= '2025-01-01' && $r['date'] <= '2026-01-01'));
                usort($rs, fn($a, $b) => strcmp($a['date'], $b['date']));
                for ($j = 1; $j < count($rs); $j++) {
                    $sum = array_sum(array_slice($q[$k], $idx($rs[$j - 1]['date']), $idx($rs[$j]['date']) - $idx($rs[$j - 1]['date'])));
                    self::assertEqualsWithDelta($rs[$j]['counter'] - $rs[$j - 1]['counter'], $sum, 0.15, "$mid {$rs[$j - 1]['date']}…{$rs[$j]['date']}");
                    $pairs++;
                }
            }
        }
        self::assertGreaterThan(70, $pairs);
    }

    /** Nur der Haushalt mit Sonnenstrom hat Viertelstunden; die anderen bleiben ohne. */
    #[DataProvider('households')]
    public function testOnlyTheSolarHouseholdBringsQuarterHours(string $id): void
    {
        $p = self::load($id);
        $solar = $p['utilities']['pv_erzeugung']['meters'] ?? [];
        if ($id === 'eigenheim-modern') {
            $ids = array_column($p['intervals']['series'], 'id');
            $files = array_keys($p['interval_files']);
            sort($ids);
            sort($files);
            self::assertSame($ids, $files, 'Index und Reihen deckungsgleich');
            self::assertCount(8, $ids, 'Bezug, Wärmepumpe, Wallbox, Erzeugung, Einspeisung, Laden, Entladen, Börsenpreis');
        } else {
            self::assertSame([], $p['intervals']['series'] ?? [], $solar === [] ? 'ohne PV keine Viertelstunden' : 'Schaufenster: bewusst ohne Viertelstunden');
        }
    }

    /**
     * Das Schaufenster gibt es zweimal — als Backup und als Verzeichnis
     * (`cp -R demo-data …`). Beide führen dieselben neuen Töpfe.
     */
    public function testShowcaseDirectoryFormMatchesTheBackup(): void
    {
        $p = self::load('showcase');
        $dir = fn(string $rel) => json_decode((string)file_get_contents(self::root() . "/demo-data/$rel"), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($p['activity'], $dir('activity.json'));
        self::assertSame($p['reminders'], $dir('reminders.json'));
        foreach ($p['utilities'] as $u => $b) {
            if (!empty($b['bills'])) self::assertSame($b['bills'], $dir("$u/bills.json"), "Rechnungen $u");
        }
        // die Wasserabrechnung 2025 fehlt bewusst („Abrechnung erwartet“)
        self::assertSame([], $p['utilities']['wasser']['bills'] ?? []);
    }

    /**
     * Doku-Behauptung (docs/einstieg/einrichtung.md, „Neu in v3.5.0“, DE und
     * EN): Personen im Verlauf, Zuständige und Tage seit dem Periodenende
     * stimmen mit den Daten.
     */
    public function testHandbookTableMatchesTheData(): void
    {
        $rows = ['de' => ['docs/einstieg/einrichtung.md', '### Neu in v3.5.0'], 'en' => ['docs/en/einstieg/einrichtung.md', '### New in v3.5.0']];
        $order = ['mieterin', 'etw-fernwaerme', 'eigenheim-klassisch', 'eigenheim-modern', 'showcase'];
        foreach ($rows as $lang => [$file, $heading]) {
            $text = (string)file_get_contents(self::root() . "/$file");
            $part = substr($text, (int)strpos($text, $heading));
            preg_match_all('/^\| (?!---|Beispielhaushalt|Sample household)([^|]+)\|([^|]+)\|([^|]+)\|([^|]+)\|$/m', $part, $m, PREG_SET_ORDER);
            self::assertCount(5, array_slice($m, 0, 5), "$lang: fünf Zeilen");
            foreach ($order as $k => $id) {
                [, , $people, $assignees, $expected] = array_map('trim', $m[$k]);
                $p = self::load($id);
                $names = [];
                foreach ($p['activity'] as $e) {
                    if (($e['by']['type'] ?? null) === 'person') $names[] = $e['by']['name'];
                    if (($e['undone']['by']['type'] ?? null) === 'person') $names[] = $e['undone']['by']['name'];
                }
                $list = fn(string $s) => array_map('trim', explode(',', $s));
                self::assertEqualsCanonicalizing(array_values(array_unique($names)), $list($people), "$lang $id: Personen");
                $inCharge = array_values(array_unique(array_filter(array_column($p['reminders'], 'assignee'))));
                self::assertEqualsCanonicalizing($inCharge, $list($assignees), "$lang $id: Zuständige");
                $days = $p['demo_align']['billing_anchor_days_ago'] ?? [];
                if (preg_match('/(\d+) (Tagen|days)/', $expected, $d)) {
                    self::assertSame([self::OPEN_PERIOD[$id][0] => (int)$d[1]], $days, "$lang $id: Tage seit dem Periodenende");
                    self::assertSame((int)$d[1], self::OPEN_PERIOD[$id]['days']);
                } else {
                    self::assertSame([], $days, "$lang $id: feste Periode");
                }
            }
        }
    }

    // ── Ausgerichtet und eingespielt ────────────────────────────────────

    #[DataProvider('households')]
    public function testAlignedHouseholdImportsWithEverythingInPlace(string $id): void
    {
        $p = self::load($id);
        $aligned = DemoDataAligner::align($p, self::TODAY);
        $dry = (new BackupService($this->store, $this->i18n))->import($aligned, true);
        self::assertSame([], $dry['problems']);
        $this->import($aligned);

        // Verlauf und Termine wie eingespielt; „zuletzt erledigt von“ kommt aus dem Verlauf
        $activity = new ActivityService($this->store, $this->settings, $this->i18n);
        self::assertCount(count($aligned['activity']), $activity->all());
        foreach ($activity->all() as $e) self::assertLessThanOrEqual(self::TODAY, $e['done_on']);
        $rem = new ReminderService($this->store, $this->settings, $this->i18n);
        $rem->attachActivity($activity);
        $named = 0;
        foreach ($rem->listWithStatus() as $r) {
            if (($r['last_done_by']['type'] ?? null) === 'person') $named++;
        }
        self::assertGreaterThanOrEqual(1, $named, 'zuletzt erledigt von einer Person');
        $want = array_column($aligned['reminders'], 'assignee', 'id');
        $got = array_column($rem->list(true), 'assignee', 'id');
        ksort($want);
        ksort($got);
        self::assertSame($want, $got, 'Zuständigkeit bleibt');
    }

    /** BackupService führt Verlauf, Termine mit Zuständigkeit, Rechnungen und Viertelstunden durch Export und Import. */
    #[DataProvider('households')]
    public function testBackupRoundtripKeepsTheNewPots(string $id): void
    {
        // die Datei, wie sie ausgeliefert wird (ausgerichtet: testAlignedHouseholdImportsWithEverythingInPlace)
        $p = self::load($id);
        $this->import($p);
        $export = (new BackupService($this->store, $this->i18n))->export(false);
        self::assertEquals($p['activity'], $export['activity']);
        self::assertEquals($p['reminders'], $export['reminders']);
        foreach ($p['utilities'] as $u => $b) {
            self::assertEquals($b['bills'] ?? [], $export['utilities'][$u]['bills'], "Rechnungen $u");
        }
        // Viertelstunden je Reihe (eine Reihe hat 35.000 Werte — Vergleich ohne Kopien)
        $want = array_keys($p['interval_files'] ?? []);
        $got = array_keys($export['interval_files']);
        sort($want);
        sort($got);
        self::assertSame($want, $got, 'alle Viertelstundenreihen');
        foreach ($p['interval_files'] ?? [] as $fid => $f) {
            self::assertTrue($f['values'] === $export['interval_files'][$fid]['values'], "Viertelstunden $fid");
        }
        unset($p);
        gc_collect_cycles();

        // und zurück in eine leere Installation: gleich
        $this->import($export);
        $again = (new BackupService($this->store, $this->i18n))->export(false);
        self::assertEquals($export['activity'], $again['activity']);
        foreach ($export['interval_files'] as $fid => $f) {
            self::assertTrue($f['values'] === $again['interval_files'][$fid]['values'], "Viertelstunden $fid zweimal");
        }
    }
}
