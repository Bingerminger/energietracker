<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Utilities;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ReadingCheckService;
use Energietracker\Services\ReadingService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (#65) — Rückfrage beim Eintragen nach dem Wetter (ReadingCheckService::expectationFor,
 * ReadingService::plausibilityIssues mit `$expected`). Alle Zahlen erfunden, feste Tage.
 *
 * Der Haushalt des Melders: Gaszähler, von Hand etwa wöchentlich abgelesen (einmal neun Tage,
 * einmal fünf), neu in der App (kein Vorjahr). Kochen und Warmwasser 4,2 kWh am Tag, geheizt
 * wird erst ab dem 01.11., obwohl der Oktober schon kühl ist; dann 3,5 kWh je Gradtag dazu.
 * 10,5 kWh je m³ (nicht 1, damit eine Verwechslung von m³ und kWh auffällt), ±8 % Alltagsrauschen.
 * Temperatur je Kalendermonat fest wie in ReadingCheckTest (Okt 9, Nov 4, Dez 1 °C), Heizgrenze
 * 15 °C: Gradtage je Tag im Oktober 6, im November 11, im Dezember 14.
 *
 * Von Hand: ein Sommertag 4,2 kWh = 0,4 m³, ein Novembertag 4,2 + 3,5 × 11 = 42,7 kWh ≈ 4,07 m³,
 * gut das Zehnfache. Die alte Regel (dreimal der Median der letzten zehn Abstände, alles Sommer)
 * fragt bei 1,2 m³ am Tag nach.
 */
#[CoversClass(ReadingCheckService::class)]
#[CoversClass(ReadingService::class)]
final class ReadingExpectationTest extends ServiceTestCase
{
    private const TEMP = [1 => 0.0, 2 => 1.0, 3 => 5.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
                          7 => 19.0, 8 => 18.0, 9 => 14.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];
    private const FACTOR = 10.5;
    private const ROOT = __DIR__ . '/../../../';
    private const MESSY = __DIR__ . '/../../fixtures/messy-household/';

    private function checks(): ReadingCheckService
    {
        return new ReadingCheckService($this->store, $this->meters, $this->readings, $this->consumption, $this->settings);
    }

    /** Temperaturen je Tag nach der Monatstabelle von `$from` bis `$to`. */
    private function writeTemperatures(string $from, string $to): void
    {
        $temps = [];
        for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= $to; $t += 86400) {
            $a = self::TEMP[(int)date('n', $t)];
            $temps[date('Y-m-d', $t)] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
        }
        $this->store->write('temperatures.json', $temps);
    }

    /** Die Ablesetage des Melders: samstags ab 05.04.2025, im Juli einmal nach neun und dann nach fünf Tagen. */
    private static function weeklyDates(string $until = '2025-12-27'): array
    {
        $out = [];
        for ($t = strtotime('2025-04-05 12:00'); date('Y-m-d', $t) <= $until; $t += 7 * 86400) {
            $d = date('Y-m-d', $t);
            $out[] = $d === '2025-07-19' ? '2025-07-21' : $d;   // vergessen, zwei Tage später, dann wieder samstags
        }
        return $out;
    }

    /**
     * Gaszähler nach Formel; `$day(Datum, laufender Tag)` in kWh. Ablesungen an `$dates`, Stand in m³.
     *
     * @param callable(string,int):float $day
     * @return array<string,mixed> der Zähler
     */
    private function household(array $dates, callable $day, string $from = '2025-04-05', ?string $utility = 'gas', array $meterExtra = []): array
    {
        $to = end($dates);
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => self::FACTOR]],
            'hdd_base_temp' => 15.0, 'active_utilities' => [$utility]]);
        $id = $this->setMeterDevices($utility, [['id' => 'd1', 'serial' => null, 'installed_on' => $from, 'initial_counter' => 12345.678,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        if ($meterExtra !== []) {
            $all = $this->store->read("$utility/meters.json", []);
            $all[0] = $meterExtra + $all[0];
            $this->store->write("$utility/meters.json", $all);
        }
        $per = $utility === 'gas' ? self::FACTOR : 1.0;
        $want = array_flip($dates);
        $rows = []; $c = 12345.678; $i = 0;
        for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= $to; $t += 86400, $i++) {
            $d = date('Y-m-d', $t);
            if (isset($want[$d])) $rows[] = ['id' => 'r_' . $d, 'date' => $d, 'counter' => round($c, 3), 'device_id' => 'd1'];
            $c += $day($d, $i) / $per;
        }
        $this->setReadings($utility, $id, $rows);
        return $this->meters->list($utility)[0];
    }

    /** Ein Tag des Melders (s. Klassenkommentar). */
    private static function fievelDay(string $d, int $i): float
    {
        $heat = $d >= '2025-11-01' ? 3.5 * max(0.0, 15.0 - self::TEMP[(int)substr($d, 5, 2)]) : 0.0;
        return (4.2 + $heat) * (1 + 0.08 * sin($i * 1.7));
    }

    private function fievel(bool $temps = true): array
    {
        if ($temps) $this->writeTemperatures('2025-03-01', '2026-01-31');
        return $this->household(self::weeklyDates(), [self::class, 'fievelDay']);
    }

    /** @return array<string,array<string,mixed>> Stände nach Datum */
    private function byDate(string $utility = 'gas'): array
    {
        $out = [];
        foreach ($this->store->read("$utility/readings.json", []) as $r) $out[$r['date']] = $r;
        return $out;
    }

    /**
     * Einen Stand neu eintragen, als gäbe es nur die Vorgeschichte (Stand der Daten: sein Tag, er selbst
     * zählt nicht): Rückfragen nach der neuen Regel (mit Erwartung) und nach der alten (Median).
     *
     * @return array{new: list<string>, old: list<string>, expected: array<string,mixed>}
     */
    private function enter(array $meter, string $date, float $value, string $utility = 'gas'): array
    {
        $all = array_values(array_filter($this->store->read("$utility/readings.json", []), fn($r) => $r['date'] < $date));
        $prev = end($all) ?: null;
        $exp = $this->checks()->expectationFor($utility, $meter, (string)$prev['date'], $date, 'r_' . $date, $date);
        $typical = $this->readings->typicalPerDay($all);
        return [
            'new' => array_column(ReadingService::plausibilityIssues($value, $date, $prev, $typical, false, null, false, $exp), 'type'),
            'old' => array_column(ReadingService::plausibilityIssues($value, $date, $prev, $typical), 'type'),
            'expected' => $exp,
        ];
    }

    // ── Der Fall des Melders ─────────────────────────────────────────────

    /**
     * Erste Heizwoche (01.11. bis 08.11.) nach einem Sommer: Die alte Regel fragt nach, die neue
     * nicht. Kälter als alles bisher (11 Gradtage je Tag, der Oktober hatte 6): Wie viel Heizen kostet,
     * weiß die App noch nicht, also keine Obergrenze. Das Heizmodell der Zwischenablesungen gibt es
     * schon (aus Sommer und ungeheiztem Oktober), es kennt aber kein so kaltes Wetter und bleibt
     * draußen; es erwartete sonst die Sommermenge.
     */
    public function testTheFirstHeatingWeekAfterASummerIsNoTypo(): void
    {
        $meter = $this->fievel();
        $r = $this->byDate();
        self::assertSame('heat_model', $this->checks()->forMeter('gas', $meter, '2025-11-01')['basis'], 'ein Modell aus dem Sommer gibt es');
        $e = $this->enter($meter, '2025-11-08', (float)$r['2025-11-08']['counter']);
        self::assertSame(['jump'], $e['old'], 'die alte Regel fragt beim Heizbeginn (#65)');
        self::assertSame([], $e['new'], 'nach dem Wetter keine Rückfrage');
        self::assertSame('degree_days', $e['expected']['basis']);
        self::assertSame('colder_than_history', $e['expected']['reason']);
        self::assertNull($e['expected']['high']);
        self::assertEqualsWithDelta(11.0, $e['expected']['hdd_per_day'], 1e-9);
        self::assertSame('m³', $e['expected']['unit']);
    }

    /** Dieselbe Woche mit vergessenem Komma (eine Nachkommastelle, „124582“ statt „12458,2“): Rückfrage. */
    public function testAForgottenCommaInTheFirstHeatingWeekIsAsked(): void
    {
        $meter = $this->fievel();
        $true = (float)$this->byDate()['2025-11-08']['counter'];
        $typo = (float)str_replace('.', '', number_format($true, 1, '.', ''));
        self::assertGreaterThan(10 * 12000, $typo);
        self::assertSame(['magnitude'], $this->enter($meter, '2025-11-08', $typo)['new']);
    }

    /**
     * Die ganze Heizzeit bis Ende Dezember, Woche für Woche neu eingetragen: keine einzige Rückfrage
     * für einen richtigen Stand (die alte Regel fragt mehrmals), für jeden Stand mit vergessenem Komma
     * eine. Sobald zwei Wochen mit so viel Heizwetter vorliegen, gibt es wieder eine Obergrenze: Ein
     * Zahlendreher mit 100 m³ zu viel in der vierten Heizwoche fällt auf.
     */
    public function testTheHeatingSeasonAsksNothingButTyposStillAreAsked(): void
    {
        $meter = $this->fievel();
        $r = $this->byDate();
        $old = 0; $seen = 0;
        foreach (self::weeklyDates() as $d) {
            if ($d < '2025-11-08') continue;
            $seen++;
            $e = $this->enter($meter, $d, (float)$r[$d]['counter']);
            self::assertSame([], $e['new'], "$d: " . json_encode($e['expected']));
            $old += $e['old'] !== [] ? 1 : 0;
            $typo = $this->enter($meter, $d, (float)$r[$d]['counter'] * 10);
            self::assertNotSame([], $typo['new'], "$d: Komma vergessen");
        }
        self::assertSame(8, $seen);
        self::assertGreaterThanOrEqual(3, $old, 'die alte Regel fragte mehrmals');
        // zweite Heizwoche: eine Woche mit so viel Heizwetter reicht noch nicht für eine Obergrenze
        $two = $this->enter($meter, '2025-11-15', (float)$r['2025-11-15']['counter'])['expected'];
        self::assertSame(['degree_days', null], [$two['basis'], $two['high']]);

        $e = $this->enter($meter, '2025-11-29', (float)$r['2025-11-29']['counter'] + 100.0);
        self::assertSame(['jump'], $e['new']);
        self::assertSame('degree_days', $e['expected']['basis']);
        // drei Novemberwochen davor, Median ≈ 7 × 4,07 m³; gefragt ab dem Dreifachen
        self::assertEqualsWithDelta(28.5, $e['expected']['expected'], 2.5);
        self::assertEqualsWithDelta(3 * $e['expected']['expected'], $e['expected']['high'], 0.01);
    }

    /** Ohne Temperaturen (kein Ort, kein Normal) bleibt es bei der alten Regel: Sie fragt wie bisher. */
    public function testWithoutTemperaturesTheOldRuleStays(): void
    {
        $meter = $this->fievel(false);
        $e = $this->enter($meter, '2025-11-08', (float)$this->byDate()['2025-11-08']['counter']);
        self::assertNull($e['expected']['basis']);
        self::assertSame('no_temperatures', $e['expected']['reason']);
        self::assertSame(['jump'], $e['new']);
        self::assertSame($e['old'], $e['new']);
    }

    /**
     * Temperaturen nur auf einer Seite: Fehlen sie für die Tage des neuen Abstands (der Abgleich lief nicht,
     * kein Normal) oder für die Vergleichsabstände (Ort erst seit Kurzem eingetragen), bleibt es bei der
     * alten Regel. Die Gradtage wären sonst 0 bzw. ohne Vergleich, und die Rückfrage riete.
     */
    public function testTemperaturesOnOneSideOnlyKeepTheOldRule(): void
    {
        $meter = $this->fievel();
        $value = (float)$this->byDate()['2025-11-08']['counter'];
        $temps = $this->store->read('temperatures.json', []);
        $this->store->write('temperatures.json', array_filter($temps, fn($k) => $k < '2025-11-01' || $k >= '2025-11-08', ARRAY_FILTER_USE_KEY));
        $e = $this->enter($meter, '2025-11-08', $value);
        self::assertSame([null, 'no_temperatures'], [$e['expected']['basis'], $e['expected']['reason']], 'neuer Abstand ohne Temperaturen');
        self::assertSame($e['old'], $e['new']);
        $this->store->write('temperatures.json', array_filter($temps, fn($k) => $k >= '2025-10-26', ARRAY_FILTER_USE_KEY));
        $e = $this->enter($meter, '2025-11-08', $value);
        self::assertSame([null, 'no_temperatures'], [$e['expected']['basis'], $e['expected']['reason']], 'Vergleichsabstände ohne Temperaturen');
        self::assertSame(['jump'], $e['new']);
    }

    /**
     * Fehlt nur der letzte Tag (der Abgleich lief noch nicht), sind es bei einer Woche 1 von 7 Tagen, mehr als
     * ein Zehntel: ohne Klimanormal die alte Regel (eine benannte Grenze). Mit Ort holt der Abgleich das
     * Klimanormal und 14 Tage Vorhersage; das Normal füllt den Tag, und die erste Heizwoche fragt nicht.
     */
    public function testAMissingLastDayIsFilledByTheClimateNormal(): void
    {
        $meter = $this->fievel();
        $value = (float)$this->byDate()['2025-11-08']['counter'];
        // Stand am 08.11.: Temperaturen bis zum 06.11. (der 07.11. fehlt noch, danach gibt es nichts)
        $temps = array_filter($this->store->read('temperatures.json', []), fn($k) => $k < '2025-11-07', ARRAY_FILTER_USE_KEY);
        $this->store->write('temperatures.json', $temps);
        $e = $this->enter($meter, '2025-11-08', $value);
        self::assertSame([null, 'no_temperatures', ['jump']], [$e['expected']['basis'], $e['expected']['reason'], $e['new']], 'ohne Normal: alte Regel');
        $grid = [];
        foreach (['15.0', '15.5'] as $b) {
            $mean = [];
            for ($m = 1; $m <= 12; $m++) $mean[] = (int)date('t', mktime(12, 0, 0, $m, 1, 2025)) * max(0.0, (float)$b - self::TEMP[$m]);
            $grid[$b] = ['mean' => $mean, 'sd' => array_fill(0, 12, 10.0)];
        }
        $this->store->write('climate_normal.json', ['hdd' => $grid]);
        $e = $this->enter($meter, '2025-11-08', $value);
        self::assertSame(['degree_days', []], [$e['expected']['basis'], $e['new']], 'mit Normal: nach dem Wetter, keine Rückfrage');
        self::assertEqualsWithDelta(11.0, $e['expected']['hdd_per_day'], 1e-9);
    }

    /**
     * Mild, dann wieder kalt (Zusage in #65, „in beide Richtungen“): Nach einem Dezember im Wechsel von sehr
     * kalten (−1 °C, 16 Gradtage je Tag) und milden Wochen (5 °C) kommen elf Wochen mit 12 °C, dann wieder eine
     * Woche mit −1 °C. Die letzten zehn Abstände sind alle mild, die alte Regel fragt. Nach dem Wetter kommt der
     * Vergleich aus den beiden kalten Dezemberwochen, auch wenn sie mehr als zehn Abstände zurückliegen; das
     * Heizmodell kennt so kaltes Wetter nicht (seine Stücke über drei Wochen liegen bei rund 12 Gradtagen).
     */
    public function testAColdWeekAfterAMildSpellComparesWithTheEarlierColdWeeks(): void
    {
        $avg = static function (string $d): float {
            if ($d < '2025-11-01') return self::TEMP[(int)substr($d, 5, 2)];
            if ($d < '2025-12-01') return 4.0;
            if (($d >= '2025-12-06' && $d < '2025-12-13') || ($d >= '2025-12-20' && $d < '2025-12-27') || ($d >= '2026-03-21' && $d < '2026-03-28')) return -1.0;
            if ($d < '2026-01-03') return 5.0;
            return 12.0;
        };
        $temps = [];
        for ($t = strtotime('2025-03-01 12:00'); date('Y-m-d', $t) <= '2026-04-30'; $t += 86400) {
            $a = $avg(date('Y-m-d', $t));
            $temps[date('Y-m-d', $t)] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $day = static fn(string $d, int $i): float => (4.2 + ($d >= '2025-11-01' ? 3.5 * max(0.0, 15.0 - $avg($d)) : 0.0)) * (1 + 0.08 * sin($i * 1.7));
        $meter = $this->household(self::weeklyDates('2026-03-28'), $day);
        $e = $this->enter($meter, '2026-03-28', (float)$this->byDate()['2026-03-28']['counter']);
        self::assertSame(['jump'], $e['old'], 'die alte Regel fragt nach der milden Zeit');
        self::assertSame([], $e['new']);
        self::assertSame('degree_days', $e['expected']['basis']);
        self::assertNotNull($e['expected']['high'], 'Vergleich aus den kalten Dezemberwochen');
        // zwei kalte Wochen: 4,2 + 3,5 × 16 = 60,2 kWh am Tag, 7 Tage, 10,5 kWh je m³ ≈ 40,1 m³
        self::assertEqualsWithDelta(40.1, $e['expected']['expected'], 3.5);
    }

    /** Ein Gerätetausch zwischen beiden Ständen erklärt jeden Stand, auch mit Erwartung. */
    public function testADeviceSwapInBetweenAsksNothing(): void
    {
        $meter = $this->fievel();
        $prev = $this->byDate()['2025-11-01'];
        $exp = $this->checks()->expectationFor('gas', $meter, '2025-11-01', '2025-11-08', null, '2025-11-08');
        foreach ([3.2, 28.5, 99999.0] as $v) {
            self::assertSame([], ReadingService::plausibilityIssues($v, '2025-11-08', $prev, 0.4, true, null, false, $exp), (string)$v);
        }
    }

    /** Lektion 46: Der Stand, der bearbeitet wird, bestimmt seine Erwartung nicht mit (wie gelöscht). */
    public function testTheEditedReadingDoesNotShapeItsOwnExpectation(): void
    {
        $meter = $this->fievel();
        $all = $this->store->read('gas/readings.json', []);
        foreach ($all as &$x) if ($x['date'] === '2025-11-22') $x['counter'] += 300.0;   // der falsche Stand
        unset($x);
        $this->store->write('gas/readings.json', $all);
        $with = $this->checks()->expectationFor('gas', $meter, '2025-11-15', '2025-11-22', 'r_2025-11-22', '2025-12-27');
        $this->store->write('gas/readings.json', array_values(array_filter($all, fn($r) => $r['date'] !== '2025-11-22')));
        $without = $this->checks()->expectationFor('gas', $meter, '2025-11-15', '2025-11-22', null, '2025-12-27');
        self::assertSame($without, $with);
        self::assertNotNull($with['basis']);
    }

    // ── Mit Heizmodell ───────────────────────────────────────────────────

    /**
     * Ein Haushalt mit zweieinhalb Jahren Vorgeschichte (monatlich, 7 kWh Grundlast + 3,5 kWh je Gradtag,
     * ohne Rauschen): Für die erste Januarwoche 2026 (0 °C, 15 Gradtage je Tag) erwartet das Modell
     * 7 × (7 + 3,5 × 15) = 416,5 kWh = 39,667 m³; gefragt wird ab dem Dreifachen, 119,0 m³ (die
     * Mindestmenge, ein Viertel eines typischen Monats von rund 901 kWh, liegt darunter).
     * Doku: docs/verstehen/11-zaehlerstaende.md, `doc-claim: reading-expectation-example`.
     *
     * @return array<string,mixed>
     */
    private function modelHousehold(): array
    {
        $this->writeTemperatures('2023-07-01', '2026-01-31');
        $dates = [];
        for ($t = strtotime('2023-07-01 12:00'); date('Y-m-d', $t) <= '2026-01-01'; $t = strtotime(date('Y-m-01', $t) . ' +1 month 12:00')) $dates[] = date('Y-m-01', $t);
        $dates[] = '2026-01-08';
        return $this->household($dates, fn(string $d) => 7.0 + 3.5 * max(0.0, 15.0 - self::TEMP[(int)substr($d, 5, 2)]), '2023-07-01');
    }

    public function testAHeatModelThatKnowsWinterGivesTheExpectation(): void
    {
        $meter = $this->modelHousehold();
        $e = $this->checks()->expectationFor('gas', $meter, '2026-01-01', '2026-01-08', 'r_2026-01-08', '2026-01-08');
        self::assertSame('heat_model', $e['basis']);
        self::assertNull($e['reason']);
        self::assertEqualsWithDelta(416.5 / self::FACTOR, $e['expected'], 0.05);
        self::assertEqualsWithDelta(3 * 416.5 / self::FACTOR, $e['high'], 0.15);
        $prev = $this->byDate()['2026-01-01'];
        $last = (float)$prev['counter'];
        self::assertSame([], ReadingService::plausibilityIssues($last + 41.0, '2026-01-08', $prev, null, false, null, false, $e));
        $jump = ReadingService::plausibilityIssues($last + 120.0, '2026-01-08', $prev, null, false, null, false, $e);
        self::assertSame(['jump'], array_column($jump, 'type'));
        self::assertSame('heat_model', $jump[0]['basis']);
        self::assertEqualsWithDelta(120.0, $jump[0]['amount'], 1e-6);
        self::assertEqualsWithDelta($e['expected'], $jump[0]['expected'], 1e-9);
        // Sommerwoche: das Modell erwartet die Grundlast, 7 × 7 kWh = 4,667 m³
        $s = $this->checks()->expectationFor('gas', $meter, '2025-07-01', '2025-07-08', null, '2026-01-08');
        self::assertSame('heat_model', $s['basis']);
        self::assertEqualsWithDelta(49.0 / self::FACTOR, $s['expected'], 0.05);
    }

    /**
     * Mindestmenge: Eine Sommerwoche erwartet 7 × 7 kWh = 49 kWh; das Dreifache (147 kWh = 14,0 m³) läge
     * unter der Erwartung plus einem Viertel eines typischen Monats (rund 901 kWh ÷ 4 = 225 kWh), gefragt
     * wird also erst ab rund 274 kWh = 26,1 m³. 20 m³ in einer Sommerwoche sind kein Tippfehler, 30 m³ schon.
     */
    public function testASummerWeekIsAskedOnlyAboveTheMinimumAmount(): void
    {
        $meter = $this->modelHousehold();
        $e = $this->checks()->expectationFor('gas', $meter, '2025-07-01', '2025-07-08', null, '2026-01-08');
        self::assertSame('heat_model', $e['basis']);
        self::assertEqualsWithDelta((49.0 + 0.25 * 10808.0 / 12) / self::FACTOR, $e['high'], 0.3);
        $prev = ['date' => '2025-07-01', 'counter' => 20000.0];
        self::assertSame([], ReadingService::plausibilityIssues(20020.0, '2025-07-08', $prev, null, false, null, false, $e));
        self::assertSame(['jump'], array_column(ReadingService::plausibilityIssues(20030.0, '2025-07-08', $prev, null, false, null, false, $e), 'type'));
    }

    /**
     * Streuung: Im unordentlichen Testhaushalt passt das Heizmodell des Gaszählers schlecht (Grundlast
     * c = 0, Streuung rund 250 kWh je Monat). Der Sommer 03.06. bis 02.09.2025 (91 Tage, 45 m³) liegt beim
     * Vierfachen der Erwartung (11,2 m³), aber innerhalb von drei Streuungen: keine Rückfrage.
     */
    public function testAPoorlyFittingModelAsksOnlyBeyondItsSpread(): void
    {
        $this->loadHousehold(self::MESSY . 'household.json');
        $m = $this->meters->list('gas')[0];
        $list = $this->readings->list('gas', (string)$m['id']);
        $prev = null; $cur = null;
        foreach ($list as $r) { if ($r['date'] === '2025-09-02') $cur = $r; elseif ($r['date'] < '2025-09-02') $prev = $r; }
        $e = $this->checks()->expectationFor('gas', $m, (string)$prev['date'], '2025-09-02', (string)$cur['id'], '2025-09-02');
        self::assertSame('heat_model', $e['basis']);
        self::assertGreaterThan(3 * $e['expected'], (float)$cur['counter'] - (float)$prev['counter']);
        self::assertGreaterThan(0.0, $e['spread']);
        self::assertEqualsWithDelta($e['expected'] + ReadingCheckService::SIGMA * $e['spread'], $e['high'], 0.01);
        self::assertSame([], ReadingService::plausibilityIssues((float)$cur['counter'], '2025-09-02', $prev, null, false, null, false, $e));
    }

    /**
     * Die Beispielhaushalte und der unordentliche Testhaushalt: Jeder Stand der letzten Abstände, neu
     * eingetragen mit nur seiner Vorgeschichte, fragt nach dem Wetter nicht öfter als nach der alten
     * Regel, und kein richtiger Stand eines Heizzählers fragt, wo das Heizmodell rechnet. Mindestens
     * ein Zähler rechnet mit dem Heizmodell. Ein Stand mit dem Zehnfachen der Menge fragt.
     */
    public function testTheExampleHouseholdsAskNoMoreThanBefore(): void
    {
        $sources = ['messy' => self::MESSY . 'household.json'];
        foreach (glob(self::ROOT . 'demo-data/personas/*.json') ?: [] as $f) $sources[basename($f, '.json')] = $f;
        $sources['demo'] = self::ROOT . 'demo-data';
        $model = 0;
        foreach ($sources as $name => $src) {
            $this->loadHousehold($src);
            $checks = $this->checks();
            $askedOld = 0; $askedNew = 0;
            foreach (Utilities::keys() as $u) {
                if (!Utilities::isCumulative($u)) continue;
                foreach ($this->meters->list($u) as $m) {
                    if (!empty($m['is_group']) || ($m['capture'] ?? 'counter') === 'period') continue;
                    $list = array_values(array_filter($this->readings->list($u, (string)$m['id']), fn($r) => empty($r['is_future']) && empty($r['is_suspect'])));
                    for ($k = max(1, count($list) - 6); $k < count($list); $k++) {
                        $prev = $list[$k - 1]; $cur = $list[$k];
                        if ($prev['date'] >= $cur['date'] || ($prev['device_id'] ?? null) !== ($cur['device_id'] ?? null)) continue;
                        $exp = $checks->expectationFor($u, $m, (string)$prev['date'], (string)$cur['date'], (string)($cur['id'] ?? ''), (string)$cur['date']);
                        $typical = $this->readings->typicalPerDay(array_slice($list, 0, $k));
                        $v = (float)$cur['counter'];
                        $new = ReadingService::plausibilityIssues($v, (string)$cur['date'], $prev, $typical, false, null, false, $exp);
                        $askedNew += $new !== [] ? 1 : 0;
                        $askedOld += ReadingService::plausibilityIssues($v, (string)$cur['date'], $prev, $typical) !== [] ? 1 : 0;
                        if ($exp['basis'] === 'heat_model') {
                            $model++;
                            self::assertSame([], $new, "$name $u {$m['id']} {$cur['date']}");
                            $amount = $v - (float)$prev['counter'];
                            if ($amount > 0) {
                                $ten = ReadingService::plausibilityIssues((float)$prev['counter'] + 10 * $amount, (string)$cur['date'], $prev, $typical, false, null, false, $exp);
                                self::assertSame(['jump'], array_column($ten, 'type'), "$name $u {$m['id']} {$cur['date']}: zehnfache Menge");
                            }
                        }
                    }
                }
            }
            self::assertLessThanOrEqual($askedOld, $askedNew, "$name: nach dem Wetter öfter gefragt");
        }
        self::assertGreaterThan(0, $model, 'kein Zähler mit Heizmodell');
    }

    // ── Andere Arten ─────────────────────────────────────────────────────

    /** Strom im Haushalt: keine Erwartung nach dem Wetter (alte Regel); Strom der Wärmepumpe wie Gas. */
    public function testOnlyHeatingMetersGetAnExpectation(): void
    {
        $this->writeTemperatures('2025-03-01', '2026-01-31');
        $meter = $this->household(self::weeklyDates(), [self::class, 'fievelDay'], '2025-04-05', 'strom');
        $e = $this->checks()->expectationFor('strom', $meter, '2025-11-01', '2025-11-08', 'r_2025-11-08', '2025-11-08');
        self::assertNull($e['basis']);
        self::assertSame('not_weather', $e['reason']);
        $hp = $this->household(self::weeklyDates(), [self::class, 'fievelDay'], '2025-04-05', 'strom', ['role' => 'heat_pump']);
        $e = $this->checks()->expectationFor('strom', $hp, '2025-11-01', '2025-11-08', 'r_2025-11-08', '2025-11-08');
        self::assertSame('degree_days', $e['basis']);
        self::assertSame('kWh', $e['unit']);
    }

    /**
     * Doku (Lektion 99): Das Beispiel nach `<!-- doc-claim: reading-expectation-example -->` in
     * docs/verstehen/11-zaehlerstaende.md (DE und EN) ist, was die App rechnet, und die Schwellen in
     * Worten hängen an den Konstanten (testTheConstantsOfTheRule).
     */
    public function testTheDocumentedExampleIsWhatTheAppCalculates(): void
    {
        $meter = $this->modelHousehold();
        $e = $this->checks()->expectationFor('gas', $meter, '2026-01-01', '2026-01-08', 'r_2026-01-08', '2026-01-08');
        $docs = [
            'de' => ['docs/verstehen/11-zaehlerstaende.md', ',', ['über dem Dreifachen der Erwartung', 'ein Viertel eines typischen Monats',
                     'drei Streuungen', 'die letzten bis zu zehn Abstände', 'mindestens zwei seiner Stücke', 'höchstens einem halben Gradtag',
                     'mindestens zehnmal der letzte Stand', 'Ohne Temperaturen']],
            'en' => ['docs/en/verstehen/11-zaehlerstaende.md', '.', ['above three times the expectation', 'a quarter of a typical month',
                     'three spreads', 'the last up to ten intervals', 'at least two of its chunks', 'at most half a degree day',
                     'at least ten times the last reading', 'Without temperatures']],
        ];
        foreach ($docs as $l => [$rel, $dec, $words]) {
            $md = (string)file_get_contents(self::ROOT . $rel);
            $pos = strpos($md, '<!-- doc-claim: reading-expectation-example');
            self::assertNotFalse($pos, "$rel: Beispiel fehlt");
            self::assertSame(1, preg_match('/```text\n(.*?)```/s', substr($md, (int)$pos), $m), "$rel: Codeblock fehlt");
            self::assertStringContainsString(number_format($e['expected'], 1, $dec, '') . ' m³', $m[1], "$l: erwartet");
            self::assertStringContainsString(number_format($e['high'], 1, $dec, '') . ' m³', $m[1], "$l: Rückfrage ab");
            self::assertStringContainsString(number_format(416.5, 1, $dec, ''), $m[1], "$l: kWh");
            $flat = (string)preg_replace('/\s+/u', ' ', $md);
            foreach ($words as $w) self::assertStringContainsString($w, $flat, "$l: „{$w}“");
        }
    }

    /** Schwellen der Regel, an denen die Doku hängt. */
    public function testTheConstantsOfTheRule(): void
    {
        self::assertSame(3, ReadingService::JUMP_FACTOR);
        self::assertSame(10, ReadingCheckService::COMPARE_INTERVALS);
        self::assertSame(2, ForecastService::HEAT_SUPPORT_MIN);
        self::assertSame(0.5, ForecastService::HEAT_SUPPORT_MARGIN);
        self::assertSame(0.25, ReadingCheckService::MIN_SHARE);
        self::assertSame(3.0, ReadingCheckService::SIGMA);
    }

    /** Haushalt laden: Fixture, Backup einer Persona oder das Verzeichnis demo-data (wie ReadingCheckTest). */
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
