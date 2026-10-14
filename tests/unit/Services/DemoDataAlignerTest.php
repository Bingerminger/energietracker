<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\DemoDataAligner;
use PHPUnit\Framework\TestCase;

/**
 * v3.0.0 — Demo-Daten bis heute fortschreiben (`DemoDataAligner`).
 *
 * Vorher endeten die Demo-Ablesungen am 15.03.2026 und die Termine lagen fest:
 * Monate nach dem Export zeigte „Mit Beispieldaten ausprobieren“ einen Haushalt,
 * den seit einem halben Jahr niemand abgelesen hatte. Geprüft werden
 * Eigenschaften, nicht nachgerechnete Einzelwerte: Jahresverbrauch wie im
 * Vorjahr, steigende Stände, lückenlose Temperaturen, Termine relativ zu heute.
 */
final class DemoDataAlignerTest extends TestCase
{
    private static function demo(): array
    {
        $raw = file_get_contents(dirname(__DIR__, 3) . '/demo-data/energietracker-demo-backup.json');
        return json_decode((string)$raw, true);
    }

    /** v3.5.0 — Viertelstundenreihen sind große Listen: Speicher zurückgeben (Suite im Standardlimit). */
    protected function tearDown(): void
    {
        parent::tearDown();
        gc_collect_cycles();
        gc_mem_caches();
    }

    private static function plusDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify("$days days")->format('Y-m-d');
    }

    /** @return array<int,array<string,mixed>> */
    private static function readingsOf(array $payload, string $u, string $meter): array
    {
        $rs = array_values(array_filter($payload['utilities'][$u]['readings'],
            fn($r) => $r['meter_id'] === $meter));
        usort($rs, fn($a, $b) => strcmp($a['date'], $b['date']));
        return $rs;
    }

    /** Verbrauch zwischen zwei Tagen, linear zwischen den Ständen eines Geräts. */
    private static function consumption(array $rs, string $from, string $to): float
    {
        $at = function (string $d) use ($rs): float {
            for ($i = 1; $i < count($rs); $i++) {
                if ($rs[$i]['date'] < $d) continue;
                $a = $rs[$i - 1]; $b = $rs[$i];
                $span = (new \DateTimeImmutable($a['date']))->diff(new \DateTimeImmutable($b['date']))->days;
                $part = (new \DateTimeImmutable($a['date']))->diff(new \DateTimeImmutable($d))->days;
                return $a['counter'] + ($b['counter'] - $a['counter']) * ($span ? $part / $span : 1);
            }
            return end($rs)['counter'];
        };
        return $at($to) - $at($from);
    }

    public function testNothingIsAddedOnTheExportDay(): void
    {
        $demo = self::demo();
        $out = DemoDataAligner::align($demo, DemoDataAligner::referenceDate($demo));
        self::assertSame($demo['utilities'], $out['utilities']);
        self::assertSame($demo['temperatures'], $out['temperatures']);
    }

    public function testReadingsGrowUntilTodayWithLastYearsConsumption(): void
    {
        $demo = self::demo();
        $ref = DemoDataAligner::referenceDate($demo);
        $today = self::plusDays($ref, 200);
        $out = DemoDataAligner::align($demo, $today);

        $before = self::readingsOf($demo, 'gas', 'm_gas_main');
        $after  = self::readingsOf($out, 'gas', 'm_gas_main');
        self::assertGreaterThan(count($before), count($after), 'neue Stände nach dem Export');

        $prev = null;
        foreach ($after as $r) {
            self::assertLessThanOrEqual($today, $r['date'], 'kein Stand nach heute');
            if ($prev !== null && $prev['device_id'] === $r['device_id']) {
                self::assertGreaterThanOrEqual($prev['counter'], $r['counter'], 'Stände steigen je Gerät');
            }
            $prev = $r;
        }
        $new = array_slice($after, count($before));
        $lastOld = end($before)['date'];
        foreach ($new as $r) {
            self::assertSame('d_gas_002', $r['device_id'], 'neue Stände gehören zum offenen Gerät');
            self::assertGreaterThan($lastOld, $r['date'], 'neue Stände schließen an den letzten an');
        }
        self::assertGreaterThan(self::plusDays($today, -45), end($after)['date'], 'jüngster Stand höchstens anderthalb Monate alt');
        // ohne Lücke zwischen letztem Stand und Export: Sommer genau wie im Vorjahr
        self::assertEqualsWithDelta(
            self::consumption($after, '2025-06-01', '2025-09-01'),
            self::consumption($after, '2026-06-01', '2026-09-01'), 0.05, 'Juni bis August wie im Vorjahr');
        // Verbrauch zwischen dem ersten und letzten neuen Stand = derselbe Zeitraum ein Jahr zuvor
        $first = $new[0];
        $last = end($new);
        $minusYear = fn(string $d) => DemoDataAligner::addYears($d, -1);
        $y1 = self::consumption($before, $minusYear($first['date']), $minusYear($last['date']));
        $y2 = $last['counter'] - $first['counter'];
        self::assertGreaterThan(50, $y1, 'Fenster mit nennenswertem Verbrauch');
        self::assertEqualsWithDelta($y1, $y2, 0.01, 'Verbrauch wie im Vorjahr');
    }

    public function testSeveralYearsKeepTheYearlyRhythm(): void
    {
        $demo = self::demo();
        $ref = DemoDataAligner::referenceDate($demo);
        $today = self::plusDays($ref, 800);
        $out = DemoDataAligner::align($demo, $today);

        $rs = self::readingsOf($out, 'strom', 'm_strom_main');
        $ids = array_column($out['utilities']['strom']['readings'], 'id');
        self::assertSame(count($ids), count(array_unique($ids)), 'IDs eindeutig');
        $last = end($rs)['date'];
        self::assertGreaterThan(self::plusDays($today, -60), $last, 'jüngster Stand höchstens zwei Monate alt');

        $year = fn(string $from) => self::consumption($rs, $from, self::plusDays($from, 365));
        $a = $year('2024-06-01');
        $b = $year('2026-06-01');
        $c = $year('2027-06-01');
        self::assertEqualsWithDelta($a, $b, $a * 0.2, 'Jahresverbrauch in der Größenordnung von vorher');
        self::assertEqualsWithDelta($b, $c, $b * 0.01, 'fortgeschriebene Jahre gleichen einander');
    }

    public function testTemperaturesContinueDayByDayFromLastYear(): void
    {
        $demo = self::demo();
        $ref = DemoDataAligner::referenceDate($demo);
        $out = DemoDataAligner::align($demo, self::plusDays($ref, 400));

        $days = array_keys($out['temperatures']);
        sort($days);
        for ($i = 1; $i < count($days); $i++) {
            self::assertSame(self::plusDays($days[$i - 1], 1), $days[$i], 'Lücke vor ' . $days[$i]);
        }
        $lastOld = max(array_keys($demo['temperatures']));
        self::assertSame(self::plusDays($lastOld, 400), end($days), 'Temperaturen wachsen um dieselbe Spanne');
        self::assertSame($out['temperatures']['2026-01-15'], $out['temperatures']['2027-01-15'] ?? null,
            'Januar wie im Vorjahr — Winter bleibt Winter');
    }

    public function testRemindersStayRelativeToToday(): void
    {
        $demo = self::demo();
        foreach (['2026-10-06', '2027-03-01', '2029-12-24'] as $today) {
            $out = DemoDataAligner::align($demo, $today);
            $due = array_filter($out['reminders'], fn($r) => $r['next_due'] <= $today);
            self::assertCount(1, $due, "$today: genau ein fälliger Termin");
            self::assertSame(self::plusDays($today, -7), min(array_column($out['reminders'], 'next_due')));
            foreach ($out['reminders'] as $r) {
                if ($r['last_done'] !== null) self::assertLessThanOrEqual($today, $r['last_done']);
            }
        }
        // Abstände bleiben erhalten
        $a = DemoDataAligner::align($demo, '2027-03-01')['reminders'];
        $gap = fn(array $rs) => (new \DateTimeImmutable($rs[0]['next_due']))->diff(new \DateTimeImmutable($rs[2]['next_due']))->days;
        self::assertSame($gap($demo['reminders']), $gap($a));
    }

    public function testDeliveriesRepeatOncePerYear(): void
    {
        $demo = self::demo();
        $ref = DemoDataAligner::referenceDate($demo);
        $count = fn(array $p) => count($p['utilities']['heizoel']['deliveries']);
        self::assertSame($count($demo) + 1, $count(DemoDataAligner::align($demo, self::plusDays($ref, 365))));
        self::assertSame($count($demo) + 2, $count(DemoDataAligner::align($demo, self::plusDays($ref, 730))));
    }

    /** v3.2.0 (F1018) — Monatswerte der Verbrauchsinfo laufen mit dem Vorjahr weiter, bis zum letzten ganzen Monat. */
    public function testMonthlyPeriodsContinueWithLastYearsValues(): void
    {
        $periods = [];
        foreach (['2025-01' => 900, '2025-02' => 800, '2025-03' => 600, '2026-01' => 950] as $ym => $v) {
            $periods[] = ['id' => "p_$ym", 'meter_id' => 'm_uvi', 'from' => "$ym-01", 'to' => date('Y-m-t', strtotime("$ym-01")),
                          'value' => $v, 'value_unit' => 'consumption', 'is_estimated' => false, 'source' => 'manual'];
        }
        $payload = ['exported_at' => '2026-02-01T00:00:00+01:00', 'utilities' => ['waerme' => ['meters' => [], 'periods' => $periods]]];
        $out = DemoDataAligner::align($payload, '2026-04-15')['utilities']['waerme']['periods'];
        $byFrom = array_column($out, 'value', 'from');
        self::assertSame(800, $byFrom['2026-02-01'] ?? null, 'Februar wie im Vorjahr');
        self::assertSame(600, $byFrom['2026-03-01'] ?? null, 'März wie im Vorjahr');
        self::assertArrayNotHasKey('2026-04-01', $byFrom, 'der laufende Monat ist noch nicht ganz');
        self::assertSame(count($out), count(array_unique(array_column($out, 'id'))), 'eindeutige IDs');
    }

    /** v3.2.0 (F1022) — Ladevorgänge aus evcc laufen wie Lieferungen mit dem Vorjahr weiter, ohne Wallbox-Zählerstand. */
    public function testChargingSessionsRepeatTheYearBefore(): void
    {
        $s = fn(string $d, string $time) => ['id' => 'evs_' . $d, 'meter_id' => 'm_wb', 'created' => "{$d}T$time+02:00", 'finished' => "{$d}T13:00:00+02:00",
            'date' => $d, 'loadpoint' => 'Wallbox', 'charged_kwh' => 10.0, 'solar_pct' => 60.0, 'meter_start' => 100.0, 'meter_stop' => 110.0];
        $payload = ['exported_at' => '2026-05-31T12:00:00+02:00', 'utilities' => [],
            'ev_sessions' => [$s('2025-05-20', '11:00:00'), $s('2025-07-02', '11:00:00'), $s('2026-05-20', '11:00:00')]];
        $out = DemoDataAligner::align($payload, '2026-08-01')['ev_sessions'];
        $byDate = array_column($out, null, 'date');
        self::assertArrayHasKey('2026-07-02', $byDate, 'Juli wie im Vorjahr');
        self::assertSame('2026-07-02T11:00:00+02:00', $byDate['2026-07-02']['created']);
        self::assertArrayNotHasKey('meter_stop', $byDate['2026-07-02'], 'kein Zählerstand der Wallbox in der Kopie');
        self::assertSame(10.0, $byDate['2026-07-02']['charged_kwh']);
        self::assertCount(4, $out, 'Mai 2025 lag vor dem Fenster, Mai 2026 ist echt');
        self::assertSame(count($out), count(array_unique(array_column($out, 'id'))), 'eindeutige IDs');
    }

    public function testLeapDayDoesNotOverflowIntoMarch(): void
    {
        self::assertSame('2025-02-28', DemoDataAligner::addYears('2024-02-29', 1));
        self::assertSame('2028-02-28', DemoDataAligner::addYears('2027-02-28', 1));
        self::assertSame('2023-02-28', DemoDataAligner::addYears('2024-02-29', -1));
    }

    /** Ein Zählertausch im Vorjahresfenster: Der Verbrauch über den Tausch zählt, kein Sprung. */
    public function testMeterSwapInTheSourceWindowCountsTheRealConsumption(): void
    {
        $payload = [
            'exported_at' => '2026-05-31T12:00:00+02:00',
            'utilities' => ['gas' => [
                'meters' => [[
                    'id' => 'm1', 'active' => true,
                    'devices' => [
                        ['id' => 'd1', 'installed_on' => '2020-01-01', 'removed_on' => '2025-07-01', 'initial_counter' => 0, 'final_counter' => 1000],
                        ['id' => 'd2', 'installed_on' => '2025-07-01', 'removed_on' => null, 'initial_counter' => 0, 'final_counter' => null],
                    ],
                ]],
                'readings' => [
                    ['id' => 'a', 'meter_id' => 'm1', 'device_id' => 'd1', 'date' => '2025-05-01', 'counter' => 900],
                    ['id' => 'b', 'meter_id' => 'm1', 'device_id' => 'd1', 'date' => '2025-06-01', 'counter' => 950],
                    ['id' => 'c', 'meter_id' => 'm1', 'device_id' => 'd2', 'date' => '2025-08-01', 'counter' => 40],
                    ['id' => 'd', 'meter_id' => 'm1', 'device_id' => 'd2', 'date' => '2026-05-01', 'counter' => 600],
                ],
                'contracts' => [],
            ]],
        ];
        $out = DemoDataAligner::align($payload, '2026-09-01');
        $new = array_values(array_filter($out['utilities']['gas']['readings'], fn($r) => $r['date'] > '2026-05-31'));
        self::assertSame(['2026-06-01', '2026-08-01'], array_column($new, 'date'));
        // 01.05.→01.06. wie im Vorjahr: 50; 01.06.→01.08. über den Tausch: (1000−950) + (40−0) = 90
        self::assertEqualsWithDelta(650.0, $new[0]['counter'], 0.01);
        self::assertEqualsWithDelta(740.0, $new[1]['counter'], 0.01);
        self::assertSame(['d2', 'd2'], array_column($new, 'device_id'));
    }

    // ── v3.5.0 (N1026) — Viertelstunden und Verlauf ─────────────────────

    private static function persona(string $id): array
    {
        return json_decode((string)file_get_contents(dirname(__DIR__, 3) . "/demo-data/personas/$id.json"), true);
    }

    /** Plätze [von, bis) eines Tages im Jahr (Ortszeit Berlin). */
    private static function dayRange(string $date): array
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $y0 = (new \DateTimeImmutable(substr($date, 0, 4) . '-01-01 00:00:00', $tz))->getTimestamp();
        $a = new \DateTimeImmutable("$date 00:00:00", $tz);
        return [intdiv($a->getTimestamp() - $y0, 900), intdiv($a->modify('+1 day')->getTimestamp() - $y0, 900)];
    }

    /** Tage mit Werten in einer Reihe. @return list<string> */
    private static function filledDays(array $values, int $year): array
    {
        $out = [];
        for ($d = "$year-01-01"; $d < ($year + 1) . '-01-01'; $d = self::plusDays($d, 1)) {
            [$a, $b] = self::dayRange($d);
            if (array_filter(array_slice($values, $a, $b - $a), fn($v) => $v !== null) !== []) $out[] = $d;
        }
        return $out;
    }

    public function testQuarterHoursCoverTheLastMonthsUntilYesterday(): void
    {
        $p = self::persona('eigenheim-modern');
        $out = DemoDataAligner::align($p, '2026-10-13');
        $ids = array_column($out['intervals']['series'], 'id');
        $new = array_values(array_filter($ids, fn($id) => str_ends_with($id, '-2026')));
        self::assertCount(8, $new, 'alle Reihen des Haushalts, auch der Börsenpreis');
        foreach ($new as $id) {
            $v = $out['interval_files'][$id]['values'];
            $days = self::filledDays($v, 2026);
            self::assertSame('2026-08-01', $days[0], "$id beginnt am Ersten des vorvorigen Monats");
            self::assertSame('2026-10-12', end($days), "$id endet gestern");
            self::assertCount(73, $days, "$id lückenlos");
            $entry = $out['intervals']['series'][array_search($id, $ids, true)];
            self::assertSame(73 * 96, $entry['filled']);
            self::assertSame('2026-08-01T00:00:00+02:00', $entry['from']);
            self::assertSame('2026-10-13T00:00:00+02:00', $entry['to']);
        }
        // die Reihen von 2025 bleiben, wie sie sind
        foreach ($p['interval_files'] as $id => $f) self::assertSame($f, $out['interval_files'][$id]);

        // jede Viertelstunde geht auf, jeder Tag kommt aus demselben Kalendertag ein Jahr zuvor
        $get = fn(int $y) => array_map(fn($k) => $out['interval_files'][str_replace('YYYY', (string)$y, $k)]['values'], [
            'grid' => 'e-strom-m_strom_haushalt-YYYY', 'heat_pump' => 'e-strom-m_strom_waermepumpe-YYYY',
            'wallbox' => 'e-strom-m_strom_wallbox-YYYY', 'gen' => 'e-pv_erzeugung-m_pv_erzeugung_wr-YYYY',
            'feed' => 'e-pv_einspeisung-m_pv_einspeisung-YYYY', 'charged' => 'e-pv_erzeugung-m_pv_speicher_laden-YYYY',
            'discharged' => 'e-pv_erzeugung-m_pv_speicher_entladen-YYYY', 'price' => 'p-de-lu-YYYY']);
        $q26 = $get(2026);
        $q25 = $get(2025);
        [$a] = self::dayRange('2026-08-01');
        [, $b] = self::dayRange('2026-10-12');
        self::assertSame($b - $a, PersonaUseCasesTest::assertBalancedSlots($q26, $a, $b, 2026));
        foreach (['2026-08-15', '2026-09-30', '2026-10-12'] as $d) {
            [$ta, $tb] = self::dayRange($d);
            [$sa, $sb] = self::dayRange(DemoDataAligner::addYears($d, -1));
            foreach ($q26 as $k => $v) self::assertSame(array_slice($q25[$k], $sa, $sb - $sa), array_slice($v, $ta, $tb - $ta), "$k am $d");
        }
        // heute (noch nicht vorbei) bleibt leer
        [$ta, $tb] = self::dayRange('2026-10-13');
        self::assertSame([null], array_values(array_unique(array_slice($q26['grid'], $ta, $tb - $ta), SORT_REGULAR)));
    }

    public function testQuarterHoursFollowTheClockChange(): void
    {
        $p = self::persona('eigenheim-modern');
        // Herbst 2026: 25. Oktober hat 100 Viertelstunden, die Stunde 2–3 Uhr zweimal
        $out = DemoDataAligner::align($p, '2026-11-10');
        $v = $out['interval_files']['e-pv_erzeugung-m_pv_speicher_entladen-2026']['values'];
        [$a, $b] = self::dayRange('2026-10-25');
        self::assertSame(100, $b - $a);
        self::assertNotContains(null, array_slice($v, $a, 100));
        self::assertSame(array_slice($v, $a + 8, 4), array_slice($v, $a + 12, 4), '2:00–2:45 Uhr zweimal mit denselben Werten');
        // Frühjahr 2027: 28. März hat 92 Viertelstunden; Quelle ist 2025 (2026 hat dort keine Werte)
        $out = DemoDataAligner::align($p, '2027-04-15');
        $g = $out['interval_files']['e-strom-m_strom_haushalt-2027']['values'];
        [$a, $b] = self::dayRange('2027-03-28');
        self::assertSame(92, $b - $a);
        self::assertNotContains(null, array_slice($g, $a, 92));
        [$sa] = self::dayRange('2025-03-28');
        $src = $p['interval_files']['e-strom-m_strom_haushalt-2025']['values'];
        self::assertSame(array_slice($src, $sa, 8), array_slice($g, $a, 8), '0:00–1:45 Uhr wie am Quelltag');
        self::assertSame(array_slice($src, $sa + 12, 80), array_slice($g, $a + 8, 80), 'ab 3:00 Uhr wie am Quelltag ab 3:00');
        self::assertArrayNotHasKey('e-strom-m_strom_haushalt-2026', array_flip(array_column(
            array_filter($out['intervals']['series'], fn($s) => ($s['filled'] ?? 0) > 0), 'id')), 'kein Jahr dazwischen erfunden');
    }

    public function testQuarterHoursStartOnlyAfterTheExport(): void
    {
        $p = self::persona('eigenheim-modern');
        // drei Wochen nach dem Export: nur Juni, nicht April und Mai (dazu passen die Stände nicht)
        $out = DemoDataAligner::align($p, '2026-06-21');
        $days = self::filledDays($out['interval_files']['e-strom-m_strom_haushalt-2026']['values'], 2026);
        self::assertSame('2026-06-01', $days[0]);
        self::assertSame('2026-06-20', end($days));
        // am Exporttag: nichts Neues
        $same = DemoDataAligner::align($p, '2026-05-31');
        self::assertSame($p['intervals'], $same['intervals']);
        self::assertSame($p['interval_files'], $same['interval_files']);
    }

    public function testBillingAnchorFollowsTodayOnlyWhereTheDemoAsksForIt(): void
    {
        $payload = ['exported_at' => '2026-05-31T12:00:00+02:00', 'settings' => ['billing_cycle_anchor_gas' => '07-01', 'billing_cycle_anchor_wasser' => '04-01'],
            'demo_align' => ['billing_anchor_days_ago' => ['gas' => 56, 'strom' => 30, 'kaffee' => 10, 'wasser' => 'viel']]];
        $out = DemoDataAligner::align($payload, '2026-10-13');
        self::assertArrayNotHasKey('demo_align', $out);
        // letzte Periode endet 56 bzw. 30 Tage vor heute: Stichtag = der Tag danach
        self::assertSame('08-19', $out['settings']['billing_cycle_anchor_gas']);
        self::assertSame('09-14', $out['settings']['billing_cycle_anchor_strom']);
        self::assertSame('04-01', $out['settings']['billing_cycle_anchor_wasser'], 'ungültige Angabe: unverändert');
        self::assertArrayNotHasKey('billing_cycle_anchor_kaffee', $out['settings']);
        // nie der 29. Februar (gibt es nicht in jedem Jahr)
        $leap = DemoDataAligner::align(['settings' => [], 'demo_align' => ['billing_anchor_days_ago' => ['gas' => 1]]], '2028-02-29');
        self::assertSame('03-01', $leap['settings']['billing_cycle_anchor_gas']);
        // ohne Hinweis bleibt alles, wie es ist
        self::assertSame($payload['settings'], DemoDataAligner::align(array_diff_key($payload, ['demo_align' => 1]), '2026-10-13')['settings']);
    }

    public function testHistoryOfRemindersMovesWithTheReminders(): void
    {
        foreach (['mieterin', 'eigenheim-modern'] as $id) {
            $p = self::persona($id);
            $today = '2026-10-13';
            $shift = DemoDataAligner::reminderShift($p['reminders'], $today);
            self::assertNotSame(0, $shift);
            $out = DemoDataAligner::align($p, $today);
            $rem = array_column($out['reminders'], null, 'id');
            foreach ($p['activity'] as $i => $e) {
                $o = $out['activity'][$i];
                self::assertSame($e['id'], $o['id']);
                self::assertLessThanOrEqual($today, $o['done_on']);
                if ($e['subject']['kind'] !== 'reminder') {
                    self::assertSame($e, $o, "{$e['subject']['kind']} bleibt am Datum seiner Daten");
                    continue;
                }
                self::assertSame(self::plusDays($e['done_on'], $shift), $o['done_on']);
                self::assertSame(self::plusDays(substr($e['recorded_at'], 0, 10), $shift), substr($o['recorded_at'], 0, 10));
                [, $anchor] = explode('@', $e['subject']['occurrence']);
                self::assertSame('reminder-' . $e['ref']['reminder_id'] . '@' . self::plusDays($anchor, $shift), $o['subject']['occurrence']);
                if (!empty($e['undone'])) self::assertSame(self::plusDays(substr($e['undone']['at'], 0, 10), $shift), substr($o['undone']['at'], 0, 10));
                // der letzte Haken passt weiter zum Termin
                if (empty($o['undone']) && $o['revert']['after']['next_due'] === $rem[$e['ref']['reminder_id']]['next_due']) {
                    self::assertSame($rem[$e['ref']['reminder_id']]['last_done'], $o['done_on']);
                }
            }
        }
    }
}
