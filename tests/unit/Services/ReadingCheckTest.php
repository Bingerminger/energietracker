<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Utilities;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BackupService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\HomeAssistant\Todo;
use Energietracker\Services\ReadingCheckService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (ablesung-pruefen) — Zwischenablesung prüfen. Alle Zahlen erfunden.
 *
 * Der Testhaushalt rechnet von Hand nach: Gas mit 1 kWh je m³, Temperatur je
 * Kalendermonat fest (Jan 0, Feb 1, Mär 5, Apr 9, Mai 14, Jun 17, Jul 19, Aug 18,
 * Sep 14, Okt 9, Nov 4, Dez 1 °C), Verbrauch je Tag 7 kWh Grundlast + 3,5 kWh je
 * Gradtag bei 15 °C Heizgrenze. Ein Januartag braucht so 7 + 3,5 × 15 = 59,5 kWh,
 * ein Dezembertag 56, ein Sommertag 7. Jahresverbrauch 10.808 kWh, ein typischer
 * Monat also rund 901 kWh und die Mindestmenge (ein Viertel) rund 225 kWh.
 *
 *   1  Block, Zahlendreher, Sommerrauschen, kleines Verhältnis
 *   2  Gegenbewegung fehlt: Nachbar, Summe, langer Versatz
 *   3  Hinweis auf die Analyse-Zäsur
 *   4  doppelt eingetragen
 *   5  „Stand ist richtig“, „nur für die Abrechnung“
 *   6  „Zu tun“ und /api/todo, Backup
 *   7  unordentlicher Haushalt und Beispielhaushalte ohne Fehlmeldung
 */
#[CoversClass(ReadingCheckService::class)]
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ReadingService::class)]
#[CoversClass(AgendaService::class)]
final class ReadingCheckTest extends ServiceTestCase
{
    private const TEMP = [1 => 0.0, 2 => 1.0, 3 => 5.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
                          7 => 19.0, 8 => 18.0, 9 => 14.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];
    private const MESSY = __DIR__ . '/../../fixtures/messy-household/';
    private const ROOT = __DIR__ . '/../../../';

    // ── Hilfen ──────────────────────────────────────────────────────────

    private function checks(): ReadingCheckService
    {
        return new ReadingCheckService($this->store, $this->meters, $this->readings, $this->consumption, $this->settings);
    }

    /** Tagesverbrauch des Testhaushalts (s. Klassenkommentar), Heizgrenze `$base`. */
    private static function day(string $d, float $base = 15.0): float
    {
        return 7.0 + 3.5 * max(0.0, $base - self::TEMP[(int)substr($d, 5, 2)]);
    }

    /** Erste Tage der Monate von `$from` bis einschließlich `$to` (JJJJ-MM). */
    private static function firsts(string $from, string $to): array
    {
        $out = [];
        for ($t = strtotime("$from-01"); date('Y-m', $t) <= $to; $t = strtotime(date('Y-m-01', $t) . ' +1 month')) $out[] = date('Y-m-01', $t);
        return $out;
    }

    /**
     * Gas-Haushalt nach Formel: Ablesungen an `$dates`, Stand = `$start` + Verbrauch
     * aller Tage davor, dazu `$offsets[Datum]` (der Fehler). `$scale(Datum)`
     * vervielfacht den Tagesverbrauch (anderes Niveau, Urlaub).
     */
    private function gasHousehold(array $dates, array $offsets = [], float $start = 10000.0, ?callable $scale = null, string $from = '2022-07-01', float $base = 15.0): array
    {
        sort($dates);
        $to = end($dates);
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]],
            'hdd_base_temp' => 15.0, 'active_utilities' => ['gas']]);
        $temps = [];
        for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= $to; $t += 86400) {
            $a = self::TEMP[(int)date('n', $t)];
            $temps[date('Y-m-d', $t)] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $id = $this->setMeterDevices('gas', [['id' => 'd1', 'serial' => null, 'installed_on' => $from, 'initial_counter' => $start,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $want = array_flip($dates);
        $rows = []; $c = $start;
        for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= $to; $t += 86400) {
            $d = date('Y-m-d', $t);
            if (isset($want[$d])) $rows[] = ['id' => 'r_' . $d, 'date' => $d, 'counter' => round($c + ($offsets[$d] ?? 0.0), 3), 'device_id' => 'd1'];
            $c += self::day($d, $base) * ($scale !== null ? $scale($d) : 1.0);
        }
        $this->setReadings('gas', $id, $rows);
        return $this->meters->list('gas')[0];
    }

    /** Die Ablesungen aus der Nacherzählung (15.10., 01.01., 20.02., 01.03., 25.05.), davor und danach monatlich. */
    private static function storyDates(): array
    {
        return array_merge(self::firsts('2022-10', '2024-09'), ['2024-10-15', '2025-01-01', '2025-02-20', '2025-03-01', '2025-05-25'],
            self::firsts('2025-07', '2026-06'));
    }

    private const BLOCK = ['2025-01-01' => -2500.0, '2025-02-20' => -2500.0, '2025-03-01' => -2500.0];

    private function agenda(): AgendaService
    {
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $a = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n);
        $a->attachReadingChecks($this->checks());
        return $a;
    }

    private function monthOf(array $meter, string $ym): array
    {
        foreach ($this->consumption->forMeter('gas', $meter) as $m) if ($m['ym'] === $ym) return $m;
        self::fail("Monat $ym fehlt");
    }

    // ── 1  Kandidaten ───────────────────────────────────────────────────

    /**
     * Die Nacherzählung: Die drei Stände 01.01., 20.02. und 01.03.2025 liegen um
     * 2.500 m³ zu niedrig. Von Hand, davor 15.10.–01.01. (78 Tage): 17 Oktobertage
     * × 28 + 30 × 45,5 + 31 × 56 = 3.577 kWh erwartet, 3.577 − 2.500 = 1.077
     * gemessen (0,30×). Danach 01.03.–25.05. (85 Tage): 31 × 42 + 30 × 28 + 24 × 10,5
     * = 2.394 erwartet, 4.894 gemessen (2,04×). Im Block ist jeder Abstand richtig,
     * ein Prüfer nur für Nachbarpaare fände ihn nicht. Genau ein Eintrag.
     */
    public function testABlockOfThreeLowReadingsInWinterIsOneEntry(): void
    {
        $meter = $this->gasHousehold(self::storyDates(), self::BLOCK);
        $r = $this->checks()->forMeter('gas', $meter);
        self::assertSame('heat_model', $r['basis']);
        self::assertCount(1, $r['checks'], json_encode($r['checks']));
        $c = $r['checks'][0];
        self::assertSame('reading_check-gas-' . $meter['id'] . '-2025-01-01', $c['uid']);
        self::assertSame(['r_2025-01-01', 'r_2025-02-20', 'r_2025-03-01'], $c['reading_ids']);
        self::assertSame(3, $c['count']);
        self::assertSame('2025-03-01', $c['until']);
        self::assertSame('low', $c['direction']);
        self::assertEqualsWithDelta(2500.0, $c['offset'], 0.5);
        self::assertSame('m³', $c['unit']);
        self::assertSame(['from' => '2024-10-15', 'to' => '2025-01-01', 'days' => 78], array_intersect_key($c['before'], array_flip(['from', 'to', 'days'])));
        self::assertEqualsWithDelta(1077.0, $c['before']['measured'], 0.5);
        self::assertEqualsWithDelta(3577.0, $c['before']['expected'], 0.5);
        self::assertEqualsWithDelta(4894.0, $c['after']['measured'], 0.5);
        self::assertEqualsWithDelta(2394.0, $c['after']['expected'], 0.5);
        // Korrigieren: der Stand, der passen würde (+2.500)
        self::assertEqualsWithDelta($c['suggested'][0]['counter'] + 2500, $c['suggested'][0]['suggested'], 0.5);
        self::assertNull($r['level_shift']);
    }

    /**
     * Ein Zahlendreher: Am 01.02.2025 steht 24.356 statt 23.456 (+900). Januar 31 ×
     * 59,5 = 1.844,5 erwartet, 2.744,5 gemessen (1,49×); Februar 28 × 56 = 1.568
     * erwartet, 668 gemessen (0,43×). Die schnelle Prüfung „über 2×“ hätte ihn
     * übersehen; ein Eintrag für genau diesen Stand, „vermutlich 900 m³ zu hoch“.
     */
    public function testASingleDigitSwapIsOneEntryForThatReading(): void
    {
        $dates = self::firsts('2023-01', '2026-06');
        // Stand am 01.02.2025 genau 23.456: Anfangsstand rückwärts gerechnet
        $before = 0.0;
        for ($t = strtotime('2022-07-01 12:00'); date('Y-m-d', $t) < '2025-02-01'; $t += 86400) $before += self::day(date('Y-m-d', $t));
        $meter = $this->gasHousehold($dates, ['2025-02-01' => 900.0], 23456.0 - $before);
        $rows = array_values(array_filter($this->readings->list('gas', $meter['id']), fn($r) => $r['date'] === '2025-02-01'));
        self::assertEqualsWithDelta(24356.0, $rows[0]['counter'], 0.01);

        $r = $this->checks()->forMeter('gas', $meter);
        self::assertCount(1, $r['checks'], json_encode($r['checks']));
        $c = $r['checks'][0];
        self::assertSame(['r_2025-02-01'], $c['reading_ids']);
        self::assertSame('high', $c['direction']);
        self::assertEqualsWithDelta(900.0, $c['offset'], 0.5);
        self::assertEqualsWithDelta(2744.5, $c['before']['measured'], 0.5);
        self::assertEqualsWithDelta(1844.5, $c['before']['expected'], 0.5);
        self::assertEqualsWithDelta(1.49, $c['before']['ratio'], 0.005);
        self::assertEqualsWithDelta(668.0, $c['after']['measured'], 0.5);
        self::assertEqualsWithDelta(1568.0, $c['after']['expected'], 0.5);
        self::assertEqualsWithDelta(23456.0, $c['suggested'][0]['suggested'], 0.5);
    }

    /**
     * Wöchentliche Sommerablesungen; der Stand vom 08.07.2025 liegt 30 m³ daneben
     * (abgelesen zu anderer Uhrzeit). Abstand davor 7 × 7 = 49 erwartet, 79 gemessen
     * (1,61×), danach 19 (0,39×): Das Verhältnis ist groß, die Menge mit 30 m³ weit
     * unter einem Viertel eines typischen Monats (225). Kein Eintrag.
     */
    public function testShortSummerIntervalsWithALargeRatioButSmallAmountAreNotReported(): void
    {
        $summer = [];
        for ($t = strtotime('2025-06-03 12:00'); date('Y-m-d', $t) <= '2025-08-26'; $t += 7 * 86400) $summer[] = date('Y-m-d', $t);
        $dates = array_values(array_unique(array_merge(self::firsts('2023-01', '2026-06'), $summer)));
        $meter = $this->gasHousehold($dates, ['2025-07-08' => 30.0]);
        $r = $this->checks()->forMeter('gas', $meter);
        self::assertSame([], $r['checks'], json_encode($r['checks']));
        // Ohne die Mindestmenge wäre es einer: Verhältnis und Streuung allein schlagen an
        $x = ['m' => 79.0, 'e' => 49.0, 'sd' => 0.0];
        self::assertTrue(ReadingCheckService::significant($x, 1, 0.0));
        self::assertFalse(ReadingCheckService::significant($x, 1, 225.0));
    }

    /**
     * Die Schwellen einzeln, von Hand: 1.600 statt 1.000 erwartet ist 1,6× und 600
     * über der Mindestmenge 225 — mit einer Streuung von 250 (3 × 250 = 750) aber
     * nicht deutlich. 1.300 statt 1.000 (1,3×) ist unter 4/3, 700 statt 1.000 (0,7×)
     * darunter deutlich.
     */
    public function testEachThresholdOnItsOwn(): void
    {
        self::assertFalse(ReadingCheckService::significant(['m' => 1600.0, 'e' => 1000.0, 'sd' => 250.0], 1, 225.0), 'Streuung');
        self::assertTrue(ReadingCheckService::significant(['m' => 1600.0, 'e' => 1000.0, 'sd' => 150.0], 1, 225.0));
        self::assertFalse(ReadingCheckService::significant(['m' => 1300.0, 'e' => 1000.0, 'sd' => 0.0], 1, 225.0), 'Verhältnis');
        self::assertTrue(ReadingCheckService::significant(['m' => 700.0, 'e' => 1000.0, 'sd' => 0.0], -1, 225.0));
        self::assertFalse(ReadingCheckService::significant(['m' => 700.0, 'e' => 1000.0, 'sd' => 0.0], 1, 225.0), 'Richtung');
    }

    /**
     * Ein Versatz von 600 m³ auf langen Winterabständen: davor 3.577 → 2.977 (0,83×),
     * danach 2.394 → 2.994 (1,25×). Die Menge reicht, das Verhältnis nicht (4/3):
     * Bei solchen Abständen ist das noch Wetter und Alltag. Kein Eintrag.
     */
    public function testAnOffsetSmallAgainstLongIntervalsIsNotReported(): void
    {
        $meter = $this->gasHousehold(self::storyDates(), array_map(fn() => -600.0, self::BLOCK));
        self::assertSame([], $this->checks()->forMeter('gas', $meter)['checks']);
    }

    /**
     * Die Heizgrenze der Gruppe gilt: Der Haushalt heizt unter 12 °C, eingestellt sind
     * 15 °C, die Gruppe des Zählers hat 12 °C. Von Hand mit 12 °C, davor (15.10.–01.01.)
     * 17 × 17,5 + 30 × 35 + 31 × 45,5 = 2.758 kWh, danach (01.03.–25.05.) 31 × 31,5 +
     * 30 × 17,5 + 24 × 7 = 1.669,5 kWh — genau das, was die Prüfung erwartet. Mit 15 °C
     * passte das Modell nicht und die Zahlen lägen daneben.
     */
    public function testTheHeatingLimitOfTheGroupApplies(): void
    {
        $meter = $this->gasHousehold(self::storyDates(), array_map(fn() => -1500.0, self::BLOCK), 10000.0, null, '2022-07-01', 12.0);
        $g = $this->meters->createGroup('gas', ['name' => 'Haus']);
        $this->meters->update('gas', (string)$meter['id'], ['meter_group_id' => $g['id']]);
        $this->meters->updateGroup('gas', (string)$g['id'], ['hdd_base_temp' => 12]);
        $c = $this->checks()->forMeter('gas', $this->meters->list('gas')[0])['checks'];
        self::assertCount(1, $c, json_encode($c));
        self::assertEqualsWithDelta(2758.0, $c[0]['before']['expected'], 0.5);
        self::assertEqualsWithDelta(1669.5, $c[0]['after']['expected'], 0.5);
        self::assertEqualsWithDelta(1500.0, $c[0]['offset'], 0.5);
    }

    /**
     * Strom, 10 kWh am Tag, monatlich abgelesen; am 01.04.2025 steht der Stand 250 kWh
     * zu niedrig: März 310 → 60, April 300 → 550. Die Erwartung kommt aus der mittleren
     * Tagesmenge OHNE die beiden geprüften Abstände (Lektion 46): (310 + 280 + 310 + 300
     * + 310) ÷ 151 Tage = 10 kWh, also März 310 und April 300. Mit dem April im Fenster
     * hieße es für den März 2.060 ÷ 181 × 31 ≈ 353.
     */
    public function testTheMeanRateLeavesTheCheckedIntervalsOut(): void
    {
        $id = $this->setMeterDevices('strom', [['id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        foreach (self::firsts('2025-01', '2025-08') as $d) {
            $rows[] = ['id' => 'r_' . $d, 'date' => $d, 'device_id' => 'd1',
                'counter' => 10.0 * (strtotime("$d 12:00 UTC") - strtotime('2025-01-01 12:00 UTC')) / 86400 + ($d === '2025-04-01' ? -250.0 : 0.0)];
        }
        $this->setReadings('strom', $id, $rows);
        $r = $this->checks()->forMeter('strom', $this->meters->list('strom')[0]);
        self::assertSame('mean_rate', $r['basis']);
        self::assertCount(1, $r['checks'], json_encode($r['checks']));
        $c = $r['checks'][0];
        self::assertSame(['r_2025-04-01'], $c['reading_ids']);
        self::assertSame('low', $c['direction']);
        self::assertEqualsWithDelta(310.0, $c['before']['expected'], 0.05);
        self::assertEqualsWithDelta(300.0, $c['after']['expected'], 0.05);
        self::assertEqualsWithDelta(60.0, $c['before']['measured'], 0.05);
        self::assertEqualsWithDelta(250.0, $c['offset'], 0.05);
    }

    /**
     * In der Zeitzone des Servers (Europe/Berlin statt UTC der Tests): Bis zur Korrektur
     * fand die Jahreszeiten-Prüfung den nächsten Monatsersten über strtotime in Ortszeit
     * und kam nie dort an (die Agenda lief in die 30 Sekunden). Dieselben Befunde wie in UTC.
     */
    public function testTheCheckRunsInTheServersTimeZone(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            $meter = $this->gasHousehold(self::storyDates(), self::BLOCK);
            $c = $this->checks()->forMeter('gas', $meter)['checks'];
            self::assertCount(1, $c);
            self::assertEqualsWithDelta(3577.0, $c[0]['before']['expected'], 0.5);
            $this->testTheMeanRateLeavesTheCheckedIntervalsOut();
        } finally {
            date_default_timezone_set($tz);
        }
    }

    /** Ein Zähler mit Zählerständen nach Tagesrate je Monat (`$rate(Datum)`), monatlich abgelesen. */
    private function rateMeter(string $utility, callable $rate, string $from, string $to, array $offsets = [], array $meterFields = []): array
    {
        $id = $this->setMeterDevices($utility, [['id' => 'd1', 'serial' => null, 'installed_on' => $from, 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        if ($meterFields !== []) {
            $all = $this->store->read("$utility/meters.json", []);
            $all[0] = $meterFields + $all[0];
            $this->store->write("$utility/meters.json", $all);
        }
        $want = array_flip(self::firsts(substr($from, 0, 7), substr($to, 0, 7)));
        $rows = []; $c = 0.0;
        for ($t = strtotime("$from 12:00 UTC"); gmdate('Y-m-d', $t) <= $to; $t += 86400) {
            $d = gmdate('Y-m-d', $t);
            if (isset($want[$d])) $rows[] = ['id' => 'r_' . $d, 'date' => $d, 'device_id' => 'd1', 'counter' => round($c + ($offsets[$d] ?? 0.0), 3)];
            $c += $rate($d);
        }
        $this->setReadings($utility, $id, $rows);
        return $this->meters->list($utility)[0];
    }

    /** Sonnenprofil je Monat (kWh am Tag), erfunden. */
    private const SUN = [1 => 2.0, 2 => 3.5, 3 => 6.0, 4 => 9.0, 5 => 11.0, 6 => 12.0, 7 => 12.0, 8 => 10.5, 9 => 7.5, 10 => 4.5, 11 => 2.5, 12 => 1.5];

    /**
     * PV mit zwei Jahren, im zweiten ein sonniger Juni (+45 %) und ein trüber Juli (−45 %):
     * Juni 360 → 522, Juli 372 → 204,6. Das sieht aus wie ein Stand am 01.07. um rund
     * 165 kWh zu hoch, ist aber Wetter. Mit nur einem anderen Jahr gibt es keine
     * Streuung zwischen den Jahren; die Untergrenze von 20 % (3 × 20 % = 60 % je Seite)
     * hält beide Abweichungen (45 %) still.
     */
    public function testASunnyJuneAndADullJulyAreNoWrongReading(): void
    {
        $meter = $this->rateMeter('pv_erzeugung', fn(string $d) => self::SUN[(int)substr($d, 5, 2)]
            * (str_starts_with($d, '2025-06') ? 1.45 : (str_starts_with($d, '2025-07') ? 0.55 : 1.0)), '2024-01-01', '2025-12-01');
        $r = $this->checks()->forMeter('pv_erzeugung', $meter);
        self::assertSame('other_years', $r['basis']);
        self::assertSame([], $r['checks'], json_encode($r['checks']));
    }

    /**
     * Welche Erwartung: Gartenwasser (Rolle `garden`), ein Strombezug mit ausgeprägtem
     * Winter ohne Rolle (Sommer 4, Winter 12 kWh am Tag) und einer mit milderem, aber
     * jedes Jahr wiederkehrendem Winter (Sommer 8, Winter 10: Sommer ÷ Winter = 0,8, also
     * Winter das 1,25-Fache) rechnen mit denselben Tagen anderer Jahre. Gleichmäßiger
     * Haushaltsstrom (Sommer 9, Winter 10) und einer mit nur einem stärkeren Sommer mit der
     * mittleren Tagesmenge.
     */
    public function testSeasonalMetersUseOtherYears(): void
    {
        $garden = $this->rateMeter('wasser', fn(string $d) => in_array((int)substr($d, 5, 2), [5, 6, 7, 8, 9], true) ? 0.8 : 0.02,
            '2023-01-01', '2025-12-01', [], ['role' => 'garden']);
        self::assertSame('other_years', $this->checks()->forMeter('wasser', $garden)['basis']);
        $winter = $this->rateMeter('strom', fn(string $d) => in_array((int)substr($d, 5, 2), [4, 5, 6, 7, 8, 9], true) ? 4.0 : 12.0, '2023-01-01', '2025-12-01');
        self::assertSame('other_years', $this->checks()->forMeter('strom', $winter)['basis']);
        $mild = $this->rateMeter('strom', fn(string $d) => in_array((int)substr($d, 5, 2), [4, 5, 6, 7, 8, 9], true) ? 8.0 : 10.0, '2023-01-01', '2025-12-01');
        self::assertSame('other_years', $this->checks()->forMeter('strom', $mild)['basis']);
        $flat = $this->rateMeter('strom', fn(string $d) => in_array((int)substr($d, 5, 2), [4, 5, 6, 7, 8, 9], true) ? 9.0 : 10.0, '2023-01-01', '2025-12-01');
        self::assertSame('mean_rate', $this->checks()->forMeter('strom', $flat)['basis']);
        // ein einziger Sommer mit 30 % mehr (Klimagerät im heißen Jahr) kehrt nicht wieder
        $once = $this->rateMeter('strom', fn(string $d) => $d >= '2024-04-01' && $d < '2024-10-01' ? 13.0 : 10.0, '2023-01-01', '2025-12-01');
        self::assertSame('mean_rate', $this->checks()->forMeter('strom', $once)['basis']);
    }

    /** Strom-Profil mit Sommer rund anderthalbmal so viel wie Winter (kWh am Tag je Monat), erfunden. */
    private const SUMMER = [1 => 19.0, 2 => 20.0, 3 => 22.0, 4 => 27.0, 5 => 31.0, 6 => 34.0, 7 => 37.0, 8 => 36.0, 9 => 31.0,
                            10 => 26.0, 11 => 22.0, 12 => 20.0];

    /**
     * Ein Zähler mit Ständen in unregelmäßigen Abständen (`$steps` Tage, zyklisch ab
     * `$shift`) nach Tagesrate `$rate(Datum)`, von `$from` bis höchstens `$to`.
     */
    private function irregularMeter(string $utility, callable $rate, string $from, string $to, array $steps, int $shift = 0): array
    {
        $dates = [];
        for ($t = strtotime("$from 12:00 UTC"), $i = $shift; gmdate('Y-m-d', $t) <= $to; $t += 86400 * $steps[$i++ % count($steps)]) $dates[] = gmdate('Y-m-d', $t);
        return $this->meterAt($utility, $rate, $dates);
    }

    /** Ein Zähler mit Ständen an `$dates` (aufsteigend) nach Tagesrate `$rate(Datum)`. */
    private function meterAt(string $utility, callable $rate, array $dates): array
    {
        $from = $dates[0]; $to = end($dates);
        $id = $this->setMeterDevices($utility, [['id' => 'd1', 'serial' => null, 'installed_on' => $from, 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $want = array_flip($dates);
        $rows = []; $c = 0.0;
        for ($t = strtotime("$from 12:00 UTC"); gmdate('Y-m-d', $t) <= $to; $t += 86400) {
            $d = gmdate('Y-m-d', $t);
            if (isset($want[$d])) $rows[] = ['id' => 'r_' . $d, 'date' => $d, 'device_id' => 'd1', 'counter' => round($c, 3)];
            $c += $rate($d);
        }
        $this->setReadings($utility, $id, $rows);
        return $this->meters->list($utility)[0];
    }

    private const STEPS = [41, 23, 67, 35, 52, 19, 74, 30, 46, 28, 61, 38, 25, 57, 33, 70, 21, 44];

    /**
     * Nachprüfung, Befund 1: Strom, jedes Jahr im Sommer rund anderthalbmal so viel wie im
     * Winter (Sommerhalbjahr im Mittel 32,7, Winterhalbjahr 21,5 kWh am Tag; an echten Daten
     * 31 bis 35 gegen 18 bis 24), unregelmäßig abgelesen, alle Stände richtig. Mit der
     * mittleren Tagesmenge (ein halbes Jahr davor und danach, also immer beide Jahreszeiten)
     * lag der Winter 30.12.2023 bis 06.03.2024 bei 0,69× (1.319 kWh gemessen gegen rund
     * 1.900 ohne die geprüften Abstände) und der Sommer 20.06. bis 02.09.2024 bei 1,58×
     * (2.668 gegen rund 1.690): ein „Block“ von vier richtigen Ständen im Frühsommer. Die
     * Jahreszeit kehrt in vier Jahren gleichsinnig wieder (Sommer ÷ Winter rund 1,5), also
     * dieselben Tage anderer Jahre und kein Eintrag.
     */
    public function testARecurringSeasonIsNoWrongReading(): void
    {
        $this->store->write('settings.json', ['active_utilities' => ['strom']]);
        $meter = $this->irregularMeter('strom', fn(string $d) => self::SUMMER[(int)substr($d, 5, 2)], '2022-02-01', '2026-09-30', self::STEPS, 3);
        $r = $this->checks()->forMeter('strom', $meter);
        self::assertSame('other_years', $r['basis']);
        self::assertSame([], $r['checks'], json_encode($r['checks']));
        self::assertNull($r['level_shift']);
    }

    /**
     * Eine mildere Jahreszeit (Sommer 30, Winter 22 kWh am Tag, Sommer ÷ Winter 1,36), davor
     * ein gutes Jahr Leerstand mit 8 % des heutigen Verbrauchs (vor dem Einzug): Der
     * Hinweis auf die Zäsur rechnet weiter mit der mittleren Tagesmenge und kommt (davor
     * weniger, Faktor unter 0,2 mit dem Abstand über den Einzug), geprüft wird die Zeit
     * seit dem Einzug mit anderen Jahren, ohne Eintrag. (Bei der stärkeren Jahreszeit oben
     * läge jeder Winter nach dem Einzug unter vier Fünfteln des Mittels und zählte als
     * Rückkehr zum alten Niveau: kein Hinweis, auch schon vor dem Nachauftrag.)
     */
    public function testARecurringSeasonKeepsTheHintOnTheBaseline(): void
    {
        $this->store->write('settings.json', ['active_utilities' => ['strom']]);
        $meter = $this->irregularMeter('strom', fn(string $d) => (in_array((int)substr($d, 5, 2), [4, 5, 6, 7, 8, 9], true) ? 30.0 : 22.0)
            * ($d < '2022-02-01' ? 0.08 : 1.0), '2020-12-15', '2026-09-30', self::STEPS, 3);
        $r = $this->checks()->forMeter('strom', $meter);
        self::assertSame('other_years', $r['basis']);
        self::assertNotNull($r['level_shift']);
        self::assertSame('lower', $r['level_shift']['direction']);
        self::assertSame('mean_rate', $r['level_shift']['basis']);
        self::assertLessThan(0.2, $r['level_shift']['factor'], 'mit dem Abstand über den Einzug');
        self::assertSame([], $r['checks'], json_encode($r['checks']));
    }

    /** Ein Ladepunkt wird nicht geprüft: Selbst ein Stand 300 kWh zu niedrig bei 10 kWh am Tag bleibt ohne Befund. */
    public function testAChargingPointIsNotChecked(): void
    {
        $meter = $this->rateMeter('strom', fn() => 10.0, '2024-01-01', '2025-12-01', ['2025-04-01' => -250.0], ['role' => 'ev_charger']);
        $r = $this->checks()->forMeter('strom', $meter);
        self::assertNull($r['basis']);
        self::assertSame([], $r['checks']);
        // derselbe Zähler als Haushaltsstrom: gefunden
        $meter = $this->rateMeter('strom', fn() => 10.0, '2024-01-01', '2025-12-01', ['2025-04-01' => -250.0], ['role' => null]);
        self::assertCount(1, $this->checks()->forMeter('strom', $meter)['checks']);
    }

    // ── 2  keine Gegenbewegung ──────────────────────────────────────────

    /**
     * November und Dezember 2024 halb so viel (Urlaub, Wohnung kalt), Januar 2025
     * (31 × 59,5 = 1.844,5) um 868 mehr: Dezember (1.736 → 868) und Januar gleichen
     * sich genau aus, aber der November davor weicht schon in dieselbe Richtung ab.
     * Das ist ein anderer Verbrauch über zwei Monate, kein falscher Stand am 01.01.
     */
    public function testANeighbourDeviatingTheSameWayIsNotAWrongReading(): void
    {
        $dates = self::firsts('2023-01', '2026-06');
        $scale = fn(string $d) => $d >= '2024-11-01' && $d < '2025-01-01' ? 0.5 : ($d >= '2025-01-01' && $d < '2025-02-01' ? (1844.5 + 868.0) / 1844.5 : 1.0);
        $meter = $this->gasHousehold($dates, [], 10000.0, $scale);
        self::assertSame([], $this->checks()->forMeter('gas', $meter)['checks']);
    }

    /**
     * Dezember 2024 nur 30 % (1.736 → 520,8, also −1.215,2), Januar 2025 1,35-mal
     * (1.844,5 → 2.490,1, +645,6): entgegengesetzt und beide deutlich, aber die Summe
     * passt nicht. Nach einem gemeinsamen Versatz von 930,4 blieben 569,6 übrig, mehr
     * als die Hälfte des Versatzes. Kein Eintrag.
     */
    public function testALowWithoutAMatchingHighIsNotAWrongReading(): void
    {
        $dates = self::firsts('2023-01', '2026-06');
        $scale = fn(string $d) => str_starts_with($d, '2024-12') ? 0.3 : (str_starts_with($d, '2025-01') ? 1.35 : 1.0);
        $meter = $this->gasHousehold($dates, [], 10000.0, $scale);
        self::assertSame([], $this->checks()->forMeter('gas', $meter)['checks']);
    }

    /** Sieben Monatsstände (01.01. bis 01.07.2025) um denselben Betrag zu niedrig: länger als ein Block. */
    public function testAnOffsetOverSevenReadingsIsNotOneWrongBlock(): void
    {
        $offsets = array_fill_keys(self::firsts('2025-01', '2025-07'), -1200.0);
        $meter = $this->gasHousehold(self::firsts('2023-01', '2026-06'), $offsets);
        self::assertSame([], $this->checks()->forMeter('gas', $meter)['checks']);
        // drei davon (01.01. bis 01.03.) sind ein Block
        $meter = $this->gasHousehold(self::firsts('2023-01', '2026-06'), array_fill_keys(self::firsts('2025-01', '2025-03'), -1200.0));
        self::assertCount(1, $this->checks()->forMeter('gas', $meter)['checks']);
    }

    /**
     * Vierteljährlich abgelesen, drei Stände (01.01., 01.04., 15.07.2025, 195 Tage) um
     * 2.500 zu niedrig: Davor (01.10.–01.01., 3.969 erwartet) 0,37×, danach
     * (15.07.–15.10.) ein Vielfaches. Über ein halbes Jahr ist kein Block mehr; mit dem
     * letzten Stand am 01.07. (181 Tage) wäre es einer.
     */
    public function testABlockLongerThanHalfAYearIsNotReported(): void
    {
        $tail = fn(string $last) => array_merge(self::firsts('2023-01', '2024-10'), ['2025-01-01', '2025-04-01', $last,
            date('Y-m-d', (int)strtotime("$last +92 days"))], self::firsts('2025-11', '2026-06'));
        $meter = $this->gasHousehold($tail('2025-07-15'), ['2025-01-01' => -2500.0, '2025-04-01' => -2500.0, '2025-07-15' => -2500.0]);
        self::assertSame([], $this->checks()->forMeter('gas', $meter)['checks']);
        $meter = $this->gasHousehold($tail('2025-07-01'), ['2025-01-01' => -2500.0, '2025-04-01' => -2500.0, '2025-07-01' => -2500.0]);
        $c = $this->checks()->forMeter('gas', $meter)['checks'];
        self::assertCount(1, $c);
        self::assertSame(3, $c[0]['count']);
    }

    /**
     * Nachprüfung, Befund 2: Heizgrenze des Zählers 10 °C (Tagesverbrauch 7 + 3,5 × Gradtag
     * bei 10 °C: Januar 42, Februar 38,5, März 24,5, April und Oktober 10,5, Mai bis
     * September 7, November 28, Dezember 38,5). Drei Stände werden nach und nach falscher:
     * 26.11.2024 um 849, 31.01.2025 um 1.419,6, 15.04.2025 um 2.047,8 zu niedrig. Von Hand:
     *
     *   davor  20.09.–26.11. (67 T)  11 × 7 + 31 × 10,5 + 25 × 28 = 1.102,5 erwartet,
     *                                253,5 gemessen   0,23×
     *   Block  26.11.–31.01. (66 T)  2.593,5 erwartet, 2.022,9 gemessen   0,78×
     *          31.01.–15.04. (74 T)  2.026,5 erwartet, 1.398,3 gemessen   0,69×
     *   danach 15.04.–11.07. (87 T)  16 × 10,5 + 71 × 7 = 665 erwartet,
     *                                2.712,8 gemessen   4,08×
     *
     * Die erste Stufe fragt nicht: Im Block weicht ein Abstand deutlich ab (0,69×, 628
     * unter der Erwartung, die Mindestmenge ist rund 143), und die Summe passt nicht
     * (Mangel 849 gegen Überschuss 2.047,8; nach einem gemeinsamen Versatz von 1.448 blieben
     * 1.199 übrig, mehr als die Hälfte). Die zweite Stufe fragt: davor unter der Hälfte,
     * danach über dem Doppelten, dazwischen keine Gegenbewegung. Ein Eintrag „Verbrauch vor
     * dem … und nach dem … passt nicht zusammen“, ohne vorgeschlagenen Stand.
     */
    public function testAVeryStrongCounterMovementIsAskedWithoutASuggestion(): void
    {
        $dates = array_merge(self::firsts('2023-01', '2024-09'), ['2024-09-20', '2024-11-26', '2025-01-31', '2025-04-15', '2025-07-11'],
            self::firsts('2025-08', '2026-06'));
        $offsets = ['2024-11-26' => -849.0, '2025-01-31' => -1419.6, '2025-04-15' => -2047.8];
        $meter = $this->gasHousehold($dates, $offsets, 10000.0, null, '2023-01-01', 10.0);
        $all = $this->store->read('gas/meters.json', []);
        $all[0]['hdd_base_temp'] = 10.0;
        $this->store->write('gas/meters.json', $all);
        $meter = $this->meters->list('gas')[0];
        $c = $this->checks()->forMeter('gas', $meter)['checks'];
        self::assertCount(1, $c, json_encode($c));
        self::assertSame('mismatch', $c[0]['kind']);
        self::assertSame(['r_2024-11-26', 'r_2025-01-31', 'r_2025-04-15'], $c[0]['reading_ids']);
        self::assertSame('low', $c[0]['direction']);
        self::assertNull($c[0]['offset']);
        self::assertSame([], $c[0]['suggested']);
        self::assertEqualsWithDelta(1102.5, $c[0]['before']['expected'], 0.5);
        self::assertSame(0.23, $c[0]['before']['ratio']);
        self::assertEqualsWithDelta(665.0, $c[0]['after']['expected'], 0.5);
        self::assertSame(4.08, $c[0]['after']['ratio']);
        // „Zu tun“ mit eigenem Text
        $ev = array_values(array_filter($this->agenda()->events(90), fn($e) => $e['kind'] === 'reading_check'));
        self::assertSame('calendar.event.readingMismatchBlock', $ev[0]['title_key']);
        self::assertSame('mismatch', $ev[0]['ref']['kind']);
        // nur 0,6× davor: keine sehr starke Gegenbewegung, kein Eintrag
        $meter = $this->gasHousehold($dates, ['2024-11-26' => -441.0, '2025-01-31' => -1011.6, '2025-04-15' => -1639.8], 10000.0, null, '2023-01-01', 10.0);
        $all = $this->store->read('gas/meters.json', []);
        $all[0]['hdd_base_temp'] = 10.0;
        $this->store->write('gas/meters.json', $all);
        self::assertSame([], $this->checks()->forMeter('gas', $this->meters->list('gas')[0])['checks']);
    }

    // ── 3  Analyse-Zäsur ────────────────────────────────────────────────

    /**
     * Zwei ganze Jahre (07/2022 bis 06/2024) durchgehend doppelt so viel wie heute
     * (früher anders geheizt): Hinweis auf die Analyse-Zäsur am 01.07.2024, Faktor 2,
     * und kein „Ablesung prüfen“ — auch nicht am Übergang, wo ein hoher Abstand vor
     * einem niedrigen steht.
     */
    public function testTwoYearsAtDoubleTheLevelAreALevelShiftNotAReadingCheck(): void
    {
        $meter = $this->gasHousehold(self::firsts('2022-07', '2026-06'), [], 10000.0, fn(string $d) => $d < '2024-07-01' ? 2.0 : 1.0);
        $r = $this->checks()->forMeter('gas', $meter);
        self::assertSame([], $r['checks'], json_encode($r['checks']));
        $s = $r['level_shift'];
        self::assertNotNull($s);
        self::assertSame('2024-07-01', $s['date']);
        self::assertSame('higher', $s['direction']);
        self::assertEqualsWithDelta(2.0, $s['factor'], 0.01);
        self::assertSame('2022-07-01', $s['from']);
        self::assertSame(24, $s['months']);
        self::assertSame('level_shift-gas-' . $meter['id'] . '-2024-07-01', $s['uid']);
    }

    /** Nur acht Monate doppelt: zu kurz für eine Zäsur (mindestens ein Jahr am Stück). */
    public function testEightMonthsAtDoubleAreNoLevelShift(): void
    {
        $meter = $this->gasHousehold(self::firsts('2022-07', '2026-06'), [], 10000.0, fn(string $d) => $d >= '2023-11-01' && $d < '2024-07-01' ? 2.0 : 1.0);
        self::assertNull($this->checks()->forMeter('gas', $meter)['level_shift']);
    }

    /**
     * Doppelt bis 06/2024, aber der Winter 11/2023 bis 02/2024 wie heute: Vor dem
     * 01.07.2024 liegen nur vier Monate am Stück doppelt, und nach dem 01.11.2023 kehrt
     * der Verbrauch für vier Monate zum alten Niveau zurück. Gegenbewegung, keine Zäsur.
     */
    public function testALevelWithACounterMovementIsNoLevelShift(): void
    {
        $meter = $this->gasHousehold(self::firsts('2022-07', '2026-06'), [], 10000.0,
            fn(string $d) => $d < '2024-07-01' && !($d >= '2023-11-01' && $d < '2024-03-01') ? 2.0 : 1.0);
        self::assertNull($this->checks()->forMeter('gas', $meter)['level_shift']);
    }

    /**
     * Gas mit langen Abständen früher und einem gleitenden Übergang: 01.07.2021 bis
     * 31.03.2022 (273 Tage) beim `$old1`-Fachen von heute, bis 22.02.2023 (328 Tage) beim
     * `$old2`-Fachen, bis 16.07.2023 (144 Tage) beim `$tr`-Fachen, danach heute (je Jahr
     * abwechselnd 4 % darüber und darunter), abgelesen alle zwei bis drei Monate bis `$end`.
     */
    private function transitionHousehold(float $old1, float $old2, float $tr, string $end): array
    {
        $dates = ['2021-07-01', '2022-03-31', '2023-02-22', '2023-07-16'];
        $steps = [61, 92, 58, 75, 88, 64, 70];
        for ($t = strtotime('2023-07-16 12:00 UTC'), $k = 0; ; ) {
            $t += 86400 * $steps[$k++ % count($steps)];
            if (gmdate('Y-m-d', $t) > $end) break;
            $dates[] = gmdate('Y-m-d', $t);
        }
        $scale = fn(string $d) => $d < '2022-03-31' ? $old1 : ($d < '2023-02-22' ? $old2 : ($d < '2023-07-16' ? $tr
            : ((int)substr($d, 0, 4) % 2 ? 0.96 : 1.04)));
        return $this->gasHousehold($dates, [], 10000.0, $scale, '2021-07-01');
    }

    /**
     * Nachprüfung, Befund 3: zwei lange Abstände beim 2,1- und 1,7-Fachen, ein Übergang beim
     * 1,4-Fachen, danach heute, aber nur anderthalb Jahre mit sieben Abständen. Mit dem
     * Übergang im heutigen Niveau (Wechsel am 22.02.2023) lag er selbst über dem
     * Eineinviertelfachen, eine Gegenbewegung; ohne ihn (Wechsel am 16.07.2023) fehlten dem
     * Heizmodell Stücke (sieben statt acht). Das heutige Niveau reicht jetzt ab vier Stücken:
     * Hinweis am Ende des letzten Abstands über dem Eineinviertelfachen, dem 16.07.2023.
     */
    public function testAShortTimeAfterAGradualChangeStillGivesTheHint(): void
    {
        $meter = $this->transitionHousehold(2.1, 1.7, 1.4, '2024-12-31');
        $s = $this->checks()->forMeter('gas', $meter)['level_shift'];
        self::assertNotNull($s);
        self::assertSame('2023-07-16', $s['date']);
        self::assertSame('higher', $s['direction']);
        self::assertSame('2021-07-01', $s['from']);
    }

    /**
     * Übergang zwischen beiden Niveaus, Strom mit der mittleren Tagesmenge: 01.07.2021 bis
     * 31.03.2022 (273 Tage) 20 kWh am Tag, bis 22.02.2023 (328 Tage) 12,6, bis 16.07.2023
     * (144 Tage) 12, danach heute 10 (alle 61 Tage abgelesen bis 18.05.2026, 1.037 Tage).
     * Von Hand: Mit dem Übergang im heutigen Niveau ist das (10.370 + 1.728) ÷ 1.181 = 10,24
     * am Tag, der Abstand mit 12,6 liegt dann beim 1,23-Fachen, nicht ein Viertel darüber,
     * und die Reihe bricht ab. Ohne ihn ist heute 10: Der Übergang (1,2×) liegt zwischen
     * den Niveaus und unterbrach bisher ebenfalls; davor 2,0× und 1,26×, zusammen
     * (5.460 + 4.132,8) ÷ 6.010 = 1,60. Ein einzelner Abstand dazwischen ist jetzt erlaubt:
     * Hinweis am Ende des Abstands mit 12,6, dem 22.02.2023. Zwei Abstände dazwischen (12,0
     * auch davor) sind zu viel.
     */
    public function testOneTransitionIntervalBetweenTheLevelsIsAllowed(): void
    {
        $this->store->write('settings.json', ['active_utilities' => ['strom']]);
        $dates = ['2021-07-01', '2022-03-31', '2023-02-22', '2023-07-16'];
        for ($t = strtotime('2023-07-16 12:00 UTC') + 61 * 86400; gmdate('Y-m-d', $t) <= '2026-07-03'; $t += 61 * 86400) $dates[] = gmdate('Y-m-d', $t);
        $rate = fn(float $old2) => fn(string $d) => $d < '2022-03-31' ? 20.0 : ($d < '2023-02-22' ? $old2 : ($d < '2023-07-16' ? 12.0 : 10.0));
        $s = $this->checks()->forMeter('strom', $this->meterAt('strom', $rate(12.6), $dates))['level_shift'];
        self::assertNotNull($s);
        self::assertSame('2023-02-22', $s['date']);
        self::assertSame('higher', $s['direction']);
        self::assertSame('mean_rate', $s['basis']);
        self::assertEqualsWithDelta(1.6, $s['factor'], 0.01);
        self::assertNull($this->checks()->forMeter('strom', $this->meterAt('strom', $rate(12.0), $dates))['level_shift']);
    }

    /**
     * Nachprüfung, zweiter Nachauftrag: Gas, zwei alte Jahre beim 2,16- und 1,74-Fachen
     * (273 und 328 Tage), Übergang 1,39-fach (144 Tage), danach heute, unregelmäßig
     * abgelesen bis 01.06.2026 (1.051 Tage). Im Sommer 2024 drei kurze Abstände (20, 32
     * und 51 Tage) beim 1,4-Fachen: 199,5 → 279, 224 → 314, 357 → 500 kWh, also 80, 90 und
     * 143 kWh mehr bei einer Mindestmenge von rund 225 (ein Viertel von 901). Im zweiten
     * Winter ein falscher Block: 01.01., 01.02. und 01.03.2025 um 1.500 zu niedrig, der März
     * danach 1.302 → 2.802 kWh (2,15×). Bisher zählten die drei Sommerabstände als Rückkehr
     * zum alten Niveau, weil Verbrauch oder Erwartung die Mindestmenge erreichten: 20 + 32 +
     * 51 + 31 = 134 von 1.051 Tagen, 12,7 % > 10 %, kein Hinweis. Jetzt zählt ein Stück nur,
     * wenn seine Abweichung die Mindestmenge erreicht: nur der März, 31 Tage, 2,9 %.
     * Ergebnis: Hinweis auf die Zäsur am 16.07.2023 UND „Ablesungen vom 01.01. bis 01.03.2025
     * prüfen“.
     */
    public function testSmallSummerExcessIsNoReturnToTheOldLevel(): void
    {
        $dates = ['2021-07-01', '2022-03-31', '2023-02-22', '2023-07-16', '2023-08-16', '2023-09-16', '2023-10-16', '2023-11-16',
            '2023-12-16', '2024-01-16', '2024-02-16', '2024-03-16', '2024-04-16', '2024-05-15', '2024-06-04', '2024-07-06', '2024-08-26',
            '2024-10-01', '2024-11-01', '2024-12-01', '2025-01-01', '2025-02-01', '2025-03-01', '2025-04-01', '2025-05-01', '2025-06-01',
            '2025-07-01', '2025-08-01', '2025-09-01', '2025-10-01', '2025-11-01', '2025-12-01', '2026-01-01', '2026-02-01', '2026-03-01',
            '2026-04-01', '2026-05-01', '2026-06-01'];
        $scale = fn(string $d) => $d < '2022-03-31' ? 2.16 : ($d < '2023-02-22' ? 1.74 : ($d < '2023-07-16' ? 1.39
            : ($d >= '2024-05-15' && $d < '2024-08-26' ? 1.4 : 1.0)));
        $meter = $this->gasHousehold($dates, ['2025-01-01' => -1500.0, '2025-02-01' => -1500.0, '2025-03-01' => -1500.0], 10000.0, $scale, '2021-07-01');
        $r = $this->checks()->forMeter('gas', $meter);
        self::assertNotNull($r['level_shift'], 'Hinweis auf die Zäsur fehlt');
        self::assertSame('2023-07-16', $r['level_shift']['date']);
        self::assertSame('higher', $r['level_shift']['direction']);
        self::assertCount(1, $r['checks'], json_encode($r['checks']));
        self::assertSame('offset', $r['checks'][0]['kind']);
        self::assertSame(['r_2025-01-01', 'r_2025-02-01', 'r_2025-03-01'], $r['checks'][0]['reading_ids']);
    }

    /** Steht die Zäsur schon am Wechsel, kommt kein Hinweis mehr. */
    public function testAnExistingBaselineAtTheShiftSilencesTheHint(): void
    {
        $meter = $this->gasHousehold(self::firsts('2022-07', '2026-06'), [], 10000.0, fn(string $d) => $d < '2024-07-01' ? 2.0 : 1.0);
        $meter = $this->meters->update('gas', $meter['id'], ['baseline_events' => [['date' => '2024-06-15', 'label' => 'Neue Heizung']]]);
        self::assertNull($this->checks()->forMeter('gas', $meter)['level_shift']);
    }

    // ── 4  doppelt ──────────────────────────────────────────────────────

    /** Derselbe Tag, derselbe Stand zweimal: ein Eintrag, behalten wird der mit Notiz. */
    public function testADuplicateReadingIsReported(): void
    {
        $meter = $this->gasHousehold(self::firsts('2024-01', '2026-06'));
        $all = $this->store->read('gas/readings.json', []);
        $orig = array_values(array_filter($all, fn($r) => $r['date'] === '2025-03-01'))[0];
        $all[] = ['id' => 'r_dup', 'note' => 'vom Zettel'] + $orig;
        $this->store->write('gas/readings.json', $all);
        $r = $this->checks()->forMeter('gas', $this->meters->list('gas')[0]);
        self::assertCount(1, $r['duplicates']);
        $d = $r['duplicates'][0];
        self::assertSame('2025-03-01', $d['date']);
        self::assertSame('r_dup', $d['keep_id']);
        self::assertSame(['r_2025-03-01'], $d['delete_ids']);
        self::assertSame('reading_duplicate-gas-' . $meter['id'] . '-2025-03-01', $d['uid']);
        self::assertSame([], $r['checks']);
    }

    /** Am selben Tag zwei verschiedene Stände sind nicht „doppelt“. */
    public function testSameDayDifferentCountersAreNoDuplicate(): void
    {
        $this->gasHousehold(self::firsts('2024-01', '2026-06'));
        $all = $this->store->read('gas/readings.json', []);
        $orig = array_values(array_filter($all, fn($r) => $r['date'] === '2025-03-01'))[0];
        $all[] = ['id' => 'r_other', 'counter' => $orig['counter'] + 3] + $orig;
        $this->store->write('gas/readings.json', $all);
        self::assertSame([], $this->checks()->forMeter('gas', $this->meters->list('gas')[0])['duplicates']);
    }

    // ── 5  die Knöpfe ───────────────────────────────────────────────────

    /** „Stand ist richtig“ an den drei Ständen: nie wieder gefragt. Ein neuer Stand hebt das auf. */
    public function testVerifiedReadingsAreNotAskedAgain(): void
    {
        $meter = $this->gasHousehold(self::storyDates(), self::BLOCK);
        foreach (array_keys(self::BLOCK) as $d) {
            $r = $this->readings->update('gas', 'r_' . $d, ['is_verified' => true]);
            self::assertTrue($r['is_verified']);
        }
        self::assertSame([], $this->checks()->forMeter('gas', $meter)['checks']);
        // wer den Stand ändert, hat die Bestätigung überholt
        $r = $this->readings->update('gas', 'r_2025-02-20', ['counter' => $this->readings->list('gas', $meter['id'])[0]['counter'] + 100000]);
        self::assertArrayNotHasKey('is_verified', $r);
        $r = $this->readings->update('gas', 'r_2025-01-01', ['note' => 'geprüft']);
        self::assertTrue($r['is_verified'], 'eine Notiz ändert die Bestätigung nicht');
        $r = $this->readings->update('gas', 'r_2025-01-01', ['is_verified' => false]);
        self::assertArrayNotHasKey('is_verified', $r);
    }

    /** Ein CSV-Import mit anderem Stand am selben Tag hebt die Bestätigung auf, derselbe Stand nicht. */
    public function testAnImportWithAnotherCounterCancelsTheConfirmation(): void
    {
        $meter = $this->gasHousehold(self::firsts('2025-01', '2025-06'));
        $this->readings->update('gas', 'r_2025-03-01', ['is_verified' => true]);
        $this->readings->update('gas', 'r_2025-04-01', ['is_verified' => true]);
        $byId = fn() => array_column($this->readings->list('gas', $meter['id']), null, 'id');
        $same = $byId()['r_2025-03-01']['counter'];
        $this->readings->upsertMany('gas', $meter['id'], [['date' => '2025-03-01', 'counter' => $same], ['date' => '2025-04-01', 'counter' => 99999]]);
        self::assertTrue($byId()['r_2025-03-01']['is_verified'] ?? false);
        self::assertArrayNotHasKey('is_verified', $byId()['r_2025-04-01']);
    }

    /**
     * „Nur für die Abrechnung“ am Block: Die Monatswerte überspringen die drei
     * Stände, der Abstand 15.10.2024–25.05.2025 verteilt sich nach dem Wetter. Weil
     * der Testhaushalt genau nach dem Heizmodell verbraucht, ergibt das die wahren
     * Monate: Dezember 31 × 56 = 1.736, Januar 31 × 59,5 = 1.844,5 (gleichmäßig
     * verteilt wären es 4.594,5 ÷ 50 × 31 ≈ 1.803 wie bisher zwischen 01.01. und 20.02.).
     * Rechnung prüfen rechnet weiter mit dem Stand: 15.10.2024–01.01.2025 1.077 kWh;
     * die Verträge rechnen den Dezember mit 1.077 × 31 ÷ 78 = 428,0 (`billed_kwh`).
     */
    public function testBillingOnlyBridgesTheMonthsAndTheBillCheckKeepsTheReading(): void
    {
        $meter = $this->gasHousehold(self::storyDates(), self::BLOCK);
        $before = $this->monthOf($meter, '2024-12');
        self::assertEqualsWithDelta(1077.0 * 31 / 78, $before['kwh'], 0.1);
        self::assertTrue($this->consumption->boundaryMeasured('gas', $meter, '2025-01-01'));
        foreach (array_keys(self::BLOCK) as $d) $this->readings->update('gas', 'r_' . $d, ['is_billing_only' => true]);
        $meter = $this->meters->list('gas')[0];
        // Jahresgrenze am Stand „nur für die Abrechnung“: Die Jahressumme der Monatswerte ist dort verteilt
        self::assertFalse($this->consumption->boundaryMeasured('gas', $meter, '2025-01-01'));

        $dec = $this->monthOf($meter, '2024-12');
        $jan = $this->monthOf($meter, '2025-01');
        self::assertEqualsWithDelta(1736.0, $dec['kwh'], 0.5);
        self::assertEqualsWithDelta(1844.5, $jan['kwh'], 0.5);
        self::assertTrue($dec['bridged']);
        self::assertTrue($dec['interpolated'], 'über 222 Tage verteilt, nicht gemessen');
        self::assertEqualsWithDelta(428.0, $dec['billed_kwh'], 0.1);
        self::assertArrayNotHasKey('bridged', $this->monthOf($meter, '2024-09'));
        // Summe über den überbrückten Abstand unverändert
        $sum = 0.0; $bill = 0.0;
        foreach ($this->consumption->forMeter('gas', $meter) as $m) {
            if ($m['ym'] >= '2024-10' && $m['ym'] <= '2025-05') { $sum += $m['kwh']; $bill += $m['billed_kwh'] ?? $m['kwh']; }
        }
        self::assertEqualsWithDelta($bill, $sum, 1.0);

        $b = $this->consumption->billBreakdown('gas', $meter, '2024-10-15', '2025-01-01');
        self::assertEqualsWithDelta(1077.0, $b['totals']['kwh'], 0.5);
        $last = end($b['rows']);
        self::assertSame('reading', $last['counter_to_kind']);
        $stored = array_values(array_filter($this->readings->list('gas', $meter['id']), fn($r) => $r['date'] === '2025-01-01'))[0];
        self::assertEqualsWithDelta($stored['counter'], $last['counter_to'], 0.05);

        // und die Prüfung fragt nicht mehr
        $r = $this->checks()->forMeter('gas', $meter);
        self::assertSame([], $r['checks']);
        self::assertSame(['r_2025-01-01', 'r_2025-02-20', 'r_2025-03-01'], $r['billing_only']);
    }

    /**
     * Befund des Leads an echten Daten: Drei Stände eines Winters als „geschätzt“ markiert,
     * Heizmodell und Rückblicke blieben bit-gleich — `is_estimated` überbrückt nichts. Hier
     * der Gegenbeweis für „nur für die Abrechnung“: monatlich abgelesen (jeder Monat
     * gemessen, also ein Punkt des Heizmodells), jeder Monat mit Alltagsrauschen, die drei
     * Stände 01.01., 01.02. und 01.03.2025 je um 40 % des Dezembers (1.736 × 0,4 = 694,4)
     * zu niedrig. Mit `is_estimated` bleiben die Monatswerte bit-gleich (der Dezember
     * bleibt zu niedrig; die Monate gelten nur als verteilt — liegen sie ohnehin in
     * langen Abständen wie beim Lead, ändert auch das Modell nichts). Mit
     * `is_billing_only` ändern sich beide: Dezember bis März sind über 121 Tage nach dem
     * Wetter verteilt, keine Punkte des Modells mehr, der Dezember zeigt wieder rund
     * 1.736, und das Modell liegt näher am Haushalt.
     */
    public function testBillingOnlyChangesMonthlyValuesAndTheHeatModel(): void
    {
        mt_srand(11);
        $noise = [];
        $scale = function (string $d) use (&$noise): float {
            $ym = substr($d, 0, 7);
            return $noise[$ym] ??= 1.0 + (mt_rand(-80, 80) / 1000.0);   // ±8 % je Monat
        };
        $offsets = array_fill_keys(['2025-01-01', '2025-02-01', '2025-03-01'], -0.4 * 1736.0);
        $meter = $this->gasHousehold(self::firsts('2022-07', '2026-06'), $offsets, 10000.0, $scale);
        self::assertCount(1, $this->checks()->forMeter('gas', $meter)['checks'], 'die Prüfung findet den Block');
        $state = function () {
            $m = $this->meters->list('gas')[0];
            $rows = $this->consumption->forMeter('gas', $m);
            $model = $this->consumption->heatModel('gas', $rows, $m);
            return [array_column($rows, 'kwh', 'ym'), $model];
        };
        [$plainRows, $plainModel] = $state();
        foreach (array_keys($offsets) as $d) $this->readings->update('gas', 'r_' . $d, ['is_estimated' => true]);
        [$estRows] = $state();
        self::assertSame($plainRows, $estRows, '„geschätzt“ ändert die Monatswerte nicht (der Dezember bleibt zu niedrig)');
        foreach (array_keys($offsets) as $d) $this->readings->update('gas', 'r_' . $d, ['is_estimated' => false, 'is_billing_only' => true]);
        [$billRows, $billModel] = $state();
        self::assertEqualsWithDelta(1736.0 * $scale('2024-12-15'), $plainRows['2024-12'] + 694.4, 1.0, 'vorher: Dezember um den Versatz zu niedrig');
        self::assertEqualsWithDelta(1736.0, $billRows['2024-12'], 1736.0 * 0.1, 'nachher: Dezember nach dem Wetter, rund 1.736');
        self::assertLessThan($plainRows['2025-03'] - 400, $billRows['2025-03'], 'der März trägt den Überschuss nicht mehr');
        // Fit vorher/nachher (gemessen an diesem Haushalt: n 42 → 40, a 3,485 → 3,494, Streuung 34,6 → 31,3)
        $info = json_encode([[$plainModel['a'], $plainModel['c'], $plainModel['n'], $plainModel['resid_sd']],
            [$billModel['a'], $billModel['c'], $billModel['n'], $billModel['resid_sd']]]);
        self::assertLessThan($plainModel['n'], $billModel['n'], "die verzerrten Monate sind keine Punkte mehr: $info");
        self::assertLessThan($plainModel['resid_sd'], $billModel['resid_sd'], "das Modell streut weniger: $info");
        self::assertLessThan(abs($plainModel['a'] - 3.5), abs($billModel['a'] - 3.5), "a liegt näher an den 3,5 des Haushalts: $info");
    }

    /** Ein Stand „nur für die Abrechnung“ ohne Ablesung danach bleibt in den Monatswerten. */
    public function testBillingOnlyWithoutAReadingAfterStays(): void
    {
        $meter = $this->gasHousehold(self::firsts('2025-01', '2025-08'));
        $plain = $this->consumption->forMeter('gas', $meter);
        $this->readings->update('gas', 'r_2025-08-01', ['is_billing_only' => true]);
        $after = $this->consumption->forMeter('gas', $this->meters->list('gas')[0]);
        self::assertSame(array_column($plain, 'kwh'), array_column($after, 'kwh'));
        self::assertNotContains(true, array_column($after, 'bridged'));
    }

    /**
     * Saldo und Kosten folgen dem Stand: Mit einem Vertrag (10 ct/kWh, 100 € Abschlag)
     * kostet der Dezember 2024 nach dem Stand 428,0 × 0,10 = 42,80 € Arbeitspreis,
     * obwohl der Monatswert 1.736 kWh zeigt.
     */
    public function testContractCostsKeepTheReading(): void
    {
        $meter = $this->gasHousehold(self::storyDates(), self::BLOCK);
        $this->contracts->create('gas', ['meter_id' => $meter['id'], 'provider' => 'Stadtwerke Musterstadt', 'start' => '2022-10-01',
            'working_prices' => [['from' => '2022-10-01', 'ct_per_kwh' => 10.0]], 'base_prices' => [['from' => '2022-10-01', 'eur_per_month' => 0.0]],
            'advance_payments' => [['from' => '2022-10-01', 'amount_eur' => 100.0]]]);
        $plain = $this->monthOf($meter, '2024-12');
        foreach (array_keys(self::BLOCK) as $d) $this->readings->update('gas', 'r_' . $d, ['is_billing_only' => true]);
        $dec = $this->monthOf($this->meters->list('gas')[0], '2024-12');
        self::assertEqualsWithDelta(42.80, $dec['kwh_cost'], 0.01);
        self::assertEqualsWithDelta($plain['kwh_cost'], $dec['kwh_cost'], 0.01);
        self::assertEqualsWithDelta($plain['cost'], $dec['cost'], 0.01);
        self::assertEqualsWithDelta(1736.0, $dec['kwh'], 0.5);
    }

    /**
     * Wasser, 0,3 m³ am Tag; der Stand vom 01.04.2025 ist 5 m³ zu niedrig und „nur für
     * die Abrechnung“. Monatswerte gleichmäßig über 01.03.–01.05. (Wasser hat kein
     * Heizmodell): März 31 × 0,3 = 9,3, April 9,0. Die Kosten nach dem Stand: März
     * 9,3 − 5 = 4,3 m³ × 2,00 € = 8,60 € Trinkwasser.
     */
    public function testWaterBillingOnlyKeepsTheCostsOfTheReading(): void
    {
        $id = $this->setMeterDevices('wasser', [['id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        foreach (self::firsts('2025-01', '2025-07') as $d) {
            $rows[] = ['id' => 'r_' . $d, 'date' => $d, 'device_id' => 'd1',
                'counter' => round(0.3 * (strtotime("$d 12:00 UTC") - strtotime('2025-01-01 12:00 UTC')) / 86400 + ($d === '2025-04-01' ? -5.0 : 0.0), 3)];
        }
        $this->setReadings('wasser', $id, $rows);
        $this->contracts->create('wasser', ['meter_id' => $id, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2025-01-01',
            'trinkwasser' => ['working_prices' => [['from' => '2025-01-01', 'ct_per_m3' => 200.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 0.0]]],
            'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => '2025-01-01', 'ct_per_m3' => 0.0]]]]);
        $this->readings->update('wasser', 'r_2025-04-01', ['is_billing_only' => true]);
        $mar = array_values(array_filter($this->consumption->forMeter('wasser', $this->meters->list('wasser')[0]), fn($m) => $m['ym'] === '2025-03'))[0];
        self::assertTrue($mar['bridged']);
        self::assertEqualsWithDelta(9.3, $mar['m3'], 0.01);
        self::assertEqualsWithDelta(8.60, $mar['trinkwasser']['working_cost'], 0.01);
    }

    // ── 6  „Zu tun“, /api/todo, Backup ──────────────────────────────────

    /** Der Eintrag steht in „Zu tun“ (due_now) und zählt im Sensor; nach „Stand ist richtig“ fehlt er. */
    public function testTheAgendaAndTheTodoCountTheEntryUntilItIsVerified(): void
    {
        $meter = $this->gasHousehold(self::storyDates(), self::BLOCK);
        $events = $this->agenda()->events(90);
        $ev = array_values(array_filter($events, fn($e) => $e['kind'] === 'reading_check'));
        self::assertCount(1, $ev);
        self::assertTrue($ev[0]['due_now']);
        self::assertSame('reading_check-gas-' . $meter['id'] . '-2025-01-01', $ev[0]['uid']);
        self::assertSame(['r_2025-01-01', 'r_2025-02-20', 'r_2025-03-01'], $ev[0]['ref']['reading_ids']);
        self::assertSame('calendar.event.readingCheckBlock', $ev[0]['title_key']);
        self::assertStringContainsString('01.01.2025', $ev[0]['title']);
        self::assertFalse($ev[0]['can_mark_done'], 'erledigt wird über die Knöpfe, aus den Daten');
        $todo = Todo::summary($events);
        self::assertContains($ev[0]['uid'], array_column($todo['items'], 'uid'));

        foreach (array_keys(self::BLOCK) as $d) $this->readings->update('gas', 'r_' . $d, ['is_verified' => true]);
        $events = $this->agenda()->events(90);
        self::assertSame([], array_values(array_filter($events, fn($e) => $e['kind'] === 'reading_check')));
        self::assertSame($todo['count'] - 1, Todo::summary($events)['count']);
    }

    /** Doppelt und Zäsur stehen ebenso in „Zu tun“; nur die Zäsur lässt sich abhaken. */
    public function testDuplicateAndLevelShiftAreAgendaEvents(): void
    {
        $this->gasHousehold(self::firsts('2022-07', '2026-06'), [], 10000.0, fn(string $d) => $d < '2024-07-01' ? 2.0 : 1.0);
        $all = $this->store->read('gas/readings.json', []);
        $all[] = ['id' => 'r_dup'] + array_values(array_filter($all, fn($r) => $r['date'] === '2025-03-01'))[0];
        $this->store->write('gas/readings.json', $all);
        $events = $this->agenda()->events(90);
        $kinds = array_column(array_filter($events, fn($e) => !empty($e['due_now'])), 'kind');
        self::assertContains('reading_duplicate', $kinds);
        self::assertContains('level_shift', $kinds);
        $ls = array_values(array_filter($events, fn($e) => $e['kind'] === 'level_shift'))[0];
        self::assertTrue($ls['can_mark_done']);
        self::assertSame('calendar.event.levelShiftLess', $ls['title_key']);
        self::assertSame('2024-07-01', $ls['ref']['date']);
        $dup = array_values(array_filter($events, fn($e) => $e['kind'] === 'reading_duplicate'))[0];
        self::assertFalse($dup['can_mark_done']);
        self::assertSame(['r_dup'], $dup['ref']['delete_ids']);
    }

    /** Beide Merker sind Felder der Ablesung und überstehen das Backup. */
    public function testFlagsSurviveTheBackup(): void
    {
        $meter = $this->gasHousehold(self::storyDates(), self::BLOCK);
        $this->readings->update('gas', 'r_2025-01-01', ['is_verified' => true]);
        $this->readings->update('gas', 'r_2025-02-20', ['is_billing_only' => true]);
        $before = $this->readings->list('gas', $meter['id']);
        $backups = new BackupService($this->store, $this->i18n);
        $dump = $backups->export();
        $this->store->write('gas/readings.json', []);
        $backups->import($dump);
        $after = $this->readings->list('gas', $meter['id']);
        self::assertEquals($before, $after);
        $byId = array_column($after, null, 'id');
        self::assertTrue($byId['r_2025-01-01']['is_verified']);
        self::assertTrue($byId['r_2025-02-20']['is_billing_only']);
        self::assertArrayNotHasKey('is_billing_only', $byId['r_2025-01-01'], 'nur gespeichert, wenn gesetzt');
    }

    /** Neu angelegt „nur für die Abrechnung“ (Stand von der Rechnung). */
    public function testCreateAcceptsBillingOnly(): void
    {
        $meter = $this->gasHousehold(self::firsts('2025-01', '2025-03'));
        $r = $this->readings->create('gas', ['meter_id' => $meter['id'], 'date' => '2025-03-15', 'counter' => 99999, 'is_billing_only' => true]);
        self::assertTrue($r['is_billing_only']);
        $r = $this->readings->create('gas', ['meter_id' => $meter['id'], 'date' => '2025-03-20', 'counter' => 99999]);
        self::assertArrayNotHasKey('is_billing_only', $r);
    }

    // ── 7  ohne Fehlmeldung ─────────────────────────────────────────────

    /**
     * Der unordentliche Testhaushalt (eigene Physik, Ablesungen wie im Leben) und die
     * Beispielhaushalte (vier Personas, Beispieldaten) haben keinen falschen Stand und
     * melden nichts — weder „Ablesung prüfen“ noch „doppelt“ noch eine Zäsur.
     */
    public function testTheMessyAndTheExampleHouseholdsHaveNoFindings(): void
    {
        $sources = ['messy' => self::MESSY . 'household.json'];
        foreach (glob(self::ROOT . 'demo-data/personas/*.json') ?: [] as $f) $sources[basename($f, '.json')] = $f;
        $sources['demo'] = self::ROOT . 'demo-data';
        self::assertGreaterThanOrEqual(6, count($sources));
        foreach ($sources as $name => $src) {
            $this->loadHousehold($src);
            $checks = $this->checks();
            $n = 0;
            foreach (Utilities::keys() as $u) {
                if (!Utilities::isCumulative($u)) continue;
                foreach ($this->meters->list($u) as $m) {
                    $r = $checks->forMeter($u, $m);
                    $n += $r['basis'] !== null ? 1 : 0;
                    self::assertSame([], $r['checks'], "$name $u {$m['id']}: " . json_encode($r['checks']));
                    self::assertSame([], $r['duplicates'], "$name $u {$m['id']}");
                    self::assertNull($r['level_shift'], "$name $u {$m['id']}: " . json_encode($r['level_shift']));
                }
            }
            self::assertGreaterThan(0, $n, "$name: kein Zähler geprüft");
        }
    }

    /** Haushalt laden: Fixture (settings/temperatures/utilities), Backup einer Persona oder das Verzeichnis demo-data. */
    private function loadHousehold(string $src): void
    {
        $pots = ['meters', 'readings', 'contracts', 'deliveries', 'meter_groups', 'periods', 'bills'];
        foreach (Utilities::keys() as $u) foreach ($pots as $p) $this->store->write("$u/$p.json", []);
        if (is_dir($src)) {
            $this->store->write('settings.json', json_decode((string)file_get_contents("$src/settings.json"), true));
            $this->store->write('temperatures.json', json_decode((string)file_get_contents("$src/temperatures.json"), true));
            foreach (Utilities::keys() as $u) foreach ($pots as $p) {
                if (is_file("$src/$u/$p.json")) $this->store->write("$u/$p.json", json_decode((string)file_get_contents("$src/$u/$p.json"), true));
            }
            return;
        }
        $h = json_decode((string)file_get_contents($src), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings'] ?? []);
        $this->store->write('temperatures.json', $h['temperatures'] ?? []);
        foreach ((array)($h['utilities'] ?? []) as $u => $byPot) foreach ((array)$byPot as $p => $rows) {
            if (in_array($p, $pots, true)) $this->store->write("$u/$p.json", $rows);
        }
    }
}
