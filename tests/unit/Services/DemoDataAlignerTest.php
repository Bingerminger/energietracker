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
}
