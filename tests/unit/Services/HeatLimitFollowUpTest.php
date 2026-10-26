<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\AnomalyService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (GitHub #38, Nachlauf) — der Heizgrenze-Vorschlag dort, wo seine Folgen
 * sichtbar sind, und ein ruhigeres Raster. Keine Zahl stammt aus echten Daten:
 * Raster von Hand in der Form des Befunds beim Maintainer (schmales Tal, bei dem der
 * Fit viele Monate verwirft), erfundene Haushalte mit Rauschen und der unordentliche
 * Testhaushalt (eigene, konvexe Physik, Heizgrenze 12,5 °C).
 *
 *   1  Wahl aus dem Raster (rein): Glättung, Fit-Punkte, warme Monate, Plateau
 *   2  verrauschte Reihe und unordentlicher Haushalt
 *   3  Grundlast-Prüfung monoton (öffentliche Zusage in #38)
 *   4  eigene Grenze für Tanks und Zählergruppen
 *   5  Übergangsmonate als Muster
 *   6  „Zu tun“: Empfehlung, Agenda, „Nicht jetzt“, Wirkung auf Saldo und Abschlag
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(AnomalyService::class)]
#[CoversClass(RecommendationService::class)]
#[CoversClass(AgendaService::class)]
final class HeatLimitFollowUpTest extends ServiceTestCase
{
    private const MESSY = __DIR__ . '/../../fixtures/messy-household/';
    private const TEMP = [1 => 0.0, 2 => 1.0, 3 => 5.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
                          7 => 19.0, 8 => 18.0, 9 => 14.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];

    // ── Hilfen ──────────────────────────────────────────────────────────

    private function loadMessy(): void
    {
        $h = json_decode((string)file_get_contents(self::MESSY . 'household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings']);
        $this->store->write('temperatures.json', $h['temperatures']);
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        foreach ($h['utilities'] as $u => $pots) foreach ($pots as $pot => $rows) $this->store->write("$u/$pot.json", $rows);
    }

    /** Rasterpunkt in der Form von heatLimitSuggestion()['grid']. */
    private static function g(float $base, float $ratio, ?float $summer = 1.0, bool $fit = true, bool $warm = true, bool $baseLoad = true): array
    {
        return ['base' => $base, 'ratio' => $ratio, 'base_load_ok' => $baseLoad, 'summer_ratio' => $summer,
                'summer_ok' => $summer !== null && $summer <= ConsumptionService::HEAT_LIMIT_SUMMER_MAX, 'fit_ok' => $fit, 'warm_ok' => $warm];
    }

    /**
     * Erfundener Gas-Haushalt mit Rauschen: Tagestemperatur mit trägem Zufall, Warmwasser
     * 7 kWh am Tag, Heizung unter 13 °C mit Teilbeheizung (konvex), jeder Monat ± 8 %,
     * abgelesen um den Monatsersten. Fester Seed (mt_rand ist seit PHP 7.1 je Seed gleich).
     */
    private function noisyHousehold(int $seed, string $from = '2023-10-01', string $to = '2025-10-31'): array
    {
        mt_srand($seed);
        $g = fn() => sqrt(-2 * log(max(mt_rand() / mt_getrandmax(), 1e-12))) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
        $temps = []; $ar = 0.0;
        for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= $to; $t += 86400) {
            $ar = 0.8 * $ar + 0.6 * 2.8 * $g();
            $avg = 9.8 - 9.2 * cos(2 * M_PI * ((int)date('z', $t) + 1 - 18) / 365.25) + $ar;
            $temps[date('Y-m-d', $t)] = ['avg' => round($avg, 1), 'min' => round($avg - 4, 1), 'max' => round($avg + 4, 1)];
        }
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]], 'hdd_base_temp' => 15.0]);
        $this->store->write('temperatures.json', $temps);
        $daily = []; $prev = null;
        foreach ($temps as $d => $tt) {
            $te = $prev === null ? $tt['avg'] : 0.6 * $tt['avg'] + 0.4 * $prev; $prev = $tt['avg'];
            $share = min(1.0, 0.35 + 0.65 * max(0.0, 13.0 - $te) / 9.0);
            $need = $te >= 13.0 ? 0.0 : max(0.0, 3.6 * (20.5 - $te) * $share - 12.0);
            $daily[$d] = 7.0 * (1 + 0.15 * cos(2 * M_PI * ((int)date('z', strtotime($d)) - 20) / 365.25)) + $need * (1 + 0.004 * max(0, 5 - $te) * 5);
        }
        $noise = [];
        foreach (array_keys($daily) as $d) $noise[substr($d, 0, 7)] ??= 1 + 0.08 * $g();
        $id = $this->setMeterDevices('gas', [['id' => 'd1', 'serial' => null, 'installed_on' => $from, 'initial_counter' => 1000.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = []; $c = 1000.0; $i = 0;
        foreach ($daily as $d => $v) {
            if ((int)substr($d, 8, 2) === 1 + (($i * 7 + (int)substr($d, 5, 2) * 3) % 5)) { $rows[] = ['date' => $d, 'counter' => round($c, 1), 'device_id' => 'd1']; $i++; }
            $c += $v * $noise[substr($d, 0, 7)];
        }
        $this->setReadings('gas', $id, $rows);
        return $this->meters->list('gas')[0];
    }

    /** Haushalt nach Formel (Ablesung an jedem Monatsersten), `$perDay(Datum, Temperatur)`. */
    private function formulaHousehold(callable $perDay, string $from, string $to, float $swing = 3.0): array
    {
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]]]);
        $tempOf = fn(int $t) => self::TEMP[(int)date('n', $t)] + $swing * (sin($t / 86400 * 2 * M_PI / 7.3) + 0.5 * sin($t / 86400 * 2 * M_PI / 3.1));
        $temps = [];
        for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= $to; $t += 86400) {
            $a = round($tempOf($t), 2);
            $temps[date('Y-m-d', $t)] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $id = $this->setMeterDevices('gas', [['id' => 'd1', 'serial' => null, 'installed_on' => $from, 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = []; $c = 0.0;
        for ($t = strtotime("$from 12:00"); date('Y-m-d', $t) <= $to; $t += 86400) {
            $d = date('Y-m-d', $t);
            if (substr($d, 8, 2) === '01') $rows[] = ['date' => $d, 'counter' => round($c, 3), 'device_id' => 'd1'];
            $c += $perDay($d, $temps[$d]['avg']);
        }
        $this->setReadings('gas', $id, $rows);
        return $this->meters->list('gas')[0];
    }

    private function recs(): RecommendationService
    {
        return new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
    }

    private function agenda(RecommendationService $recs): AgendaService
    {
        return new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n);
    }

    // ── 1  Wahl aus dem Raster ──────────────────────────────────────────

    /**
     * Die Form des Befunds beim Maintainer (Zahlen erfunden): Bei 9 °C ein schmales Tal
     * (4,9 %), weil der Fit dort viele Monate verwirft; die Nachbarn 12,6 % und 8,7 %. Von
     * Hand: geglättet (Mittel bis 1 °C daneben) 8,5 → 0,0950, 9,5 → 0,0812, 10 → 0,0832,
     * 10,5 → 0,09175, 11 → 0,09733. Ohne 9 °C (Fit) und unter 8,5 °C (Sommer überschätzt)
     * ist 9,5 °C am besten; das Plateau (bis 0,0812 × 1,15 = 0,0934) reicht lückenlos bis
     * 10,5 °C, und aus 15 °C kommend liegt 10,5 °C am nächsten.
     */
    public function testANarrowValleyWhereTheFitDropsMonthsIsNotTheSuggestion(): void
    {
        $grid = [self::g(7.0, 0.120, 1.30), self::g(7.5, 0.125, 1.25), self::g(8.0, 0.126, 1.15), self::g(8.5, 0.100, 1.05),
                 self::g(9.0, 0.049, 0.94, false), self::g(9.5, 0.075, 0.90), self::g(10.0, 0.087, 0.85),
                 self::g(10.5, 0.095, 0.80), self::g(11.0, 0.110, 0.70), self::g(15.0, 0.330, 0.04, true, true, false)];
        $smooth = ConsumptionService::smoothGrid($grid);
        self::assertEqualsWithDelta(0.0950, $smooth[3], 1e-9);
        self::assertEqualsWithDelta(0.0812, $smooth[5], 1e-9);
        self::assertEqualsWithDelta(0.0832, $smooth[6], 1e-9);
        self::assertEqualsWithDelta(0.09175, $smooth[7], 1e-9);
        self::assertEqualsWithDelta(0.292 / 3, $smooth[8], 1e-9);
        self::assertEqualsWithDelta(0.330, $smooth[9], 1e-9, '15 °C ohne Nachbarn bis 1 °C');
        $p = ConsumptionService::pickHeatLimit($grid, 15.0, 0.33);
        self::assertSame([10.5, true, [9.5, 10.5]], [$p['best'], $p['suggest'], $p['range']]);
        self::assertSame([0.095, 0.092], [$p['best_ratio'], $p['best_smoothed']], 'die Streuung der Gütesperre und die geglättete');
        // bis v3.7.1 der tiefste einzelne Punkt (Bedingungen von damals: Grundlast und Sommer)
        $old = array_filter($grid, fn($x) => $x['base_load_ok'] && $x['summer_ok']);
        usort($old, fn($a, $b) => $a['ratio'] <=> $b['ratio']);
        self::assertSame(9.0, $old[0]['base'], 'das Tal, das der Fit sich schönrechnet');
        // ohne die Fit-Regel wäre 9,0 °C wählbar und gewönne geglättet nicht (0,0874 gegen 0,0812)
        $grid[4]['fit_ok'] = true;
        self::assertSame(10.5, ConsumptionService::pickHeatLimit($grid, 15.0, 0.33)['best']);
    }

    /** Das Plateau enthält die heutige Grenze: kein Vorschlag, die Spanne steht trotzdem da. */
    public function testThePlateauKeepsTheCurrentLimitWhenItFitsAsWell(): void
    {
        $grid = [self::g(11.0, 0.060), self::g(11.5, 0.055), self::g(12.0, 0.058), self::g(12.5, 0.064), self::g(13.0, 0.090)];
        // geglättet: 11 → 0,0577, 11,5 → 0,05925, 12 → 0,0654, 12,5 → 0,0670, 13 → 0,0707 (je bis 1 °C daneben)
        $p = ConsumptionService::pickHeatLimit($grid, 12.0, 0.058);
        self::assertSame([12.0, false], [$p['best'], $p['suggest']], 'die heutige liegt im Plateau (0,0654 ≤ 0,0577 × 1,15 = 0,0664)');
        self::assertSame([11.0, 12.0], $p['range']);
        $p = ConsumptionService::pickHeatLimit($grid, 15.0, 0.20);
        self::assertSame([12.0, true], [$p['best'], $p['suggest']], 'aus 15 °C: das Ende des Plateaus, das am wenigsten ändert');
    }

    /** Die heutige Grenze ist nie der Vorschlag, auch wenn sie geglättet am besten ist (ein Ausreißer im Raster). */
    public function testTheCurrentLimitIsNeverSuggested(): void
    {
        // geglättet: 14 → 0,1; 14,5 → 0,0875; 15 → 0,08; 15,5 → 0,0875; 16 → 0,1 (von Hand)
        $grid = [self::g(14.0, 0.05), self::g(14.5, 0.05), self::g(15.0, 0.20), self::g(15.5, 0.05), self::g(16.0, 0.05)];
        $p = ConsumptionService::pickHeatLimit($grid, 15.0, 0.20);
        self::assertEqualsWithDelta(0.08, $p['best_smoothed'], 1e-9);
        self::assertSame([15.0, false, [14.5, 15.5]], [$p['best'], $p['suggest'], $p['range']], 'geglättet passt die heutige: kein Vorschlag');
    }

    /** Warme Monate von Hand: Mai und September 20 % unter der Erwartung, Juni bis August genau. */
    public function testTheWarmMonthsAreMayToSeptember(): void
    {
        $rows = [];
        foreach ([2024, 2025] as $y) {
            for ($mo = 1; $mo <= 12; $mo++) {
                $f = in_array($mo, [5, 9], true) ? 0.8 : 1.0 + 0.002 * ($mo % 3);
                $rows[] = ['ym' => sprintf('%d-%02d', $y, $mo), 'year' => $y, 'month' => $mo, 'days' => 30, 'kwh' => 1000.0 * $f,
                    'expected_heat' => 1000.0, 'expected_base' => 300.0, 'hdd' => 100.0, 'hdd_top' => 50.0, 'temp_days' => 30];
            }
        }
        $q = $this->anomalies->quality('gas', $rows);
        // Juni 1,000, Juli 1,002, August 1,004 (Monat mod 3), Mai und September 0,8
        $act = 2 * (800 + 1000.0 + 1002.0 + 1004.0 + 800);
        self::assertSame(10, $q['warm']['months']);
        self::assertEqualsWithDelta(round(10000 / $act, 3), $q['warm']['ratio'], 1e-9, 'Erwartung ÷ Verbrauch Mai bis September');
    }

    /** Die warmen Monate Mai bis September darf der Vorschlag nicht überschätzen. */
    public function testTheWarmMonthsMustNotBeOverestimated(): void
    {
        $grid = [self::g(9.0, 0.04, 1.05, true, false), self::g(9.5, 0.04, 1.02, true, false), self::g(12.0, 0.07), self::g(15.0, 0.20, 0.3, true, true, false)];
        $p = ConsumptionService::pickHeatLimit($grid, 15.0, 0.20);
        self::assertSame(12.0, $p['best'], 'die Grenzen mit überschätzten Übergangsmonaten scheiden aus');
    }

    /** Höchstens jeder zehnte Monat ein echter Ausreißer, mindestens einer. */
    public function testTheFitMayDropAtMostOneMonthInTen(): void
    {
        self::assertSame([true, true, false, true, true, false],
            [ConsumptionService::fitKeepsMonths(22, 2), ConsumptionService::fitKeepsMonths(22, 1), ConsumptionService::fitKeepsMonths(22, 3),
             ConsumptionService::fitKeepsMonths(5, 1), ConsumptionService::fitKeepsMonths(12, 1), ConsumptionService::fitKeepsMonths(12, 2)]);
    }

    // ── 2  verrauschte Reihe, unordentlicher Haushalt, fast rauschfreie Reihe ──

    /**
     * Zwei Jahre Monatswerte mit Rauschen: Der tiefste Punkt des Rasters (12 °C) entsteht,
     * weil der Fit dort drei Monate mehr als 10 % eines typischen Monats daneben verwirft.
     * Er ist nicht der Vorschlag; der Vorschlag passt zu allen Monaten und zum Sommer.
     */
    public function testANoisySeriesDoesNotSuggestItsNarrowValley(): void
    {
        $meter = $this->noisyHousehold(1);
        $h = $this->consumption->heatLimitSuggestion('gas', $meter);
        $by = [];
        foreach ($h['grid'] as $row) $by[sprintf('%.1f', $row['base'])] = $row;
        $rawMin = array_reduce($h['grid'], fn($c, $x) => $c === null || $x['ratio'] < $c['ratio'] ? $x : $c);
        self::assertSame(12.0, $rawMin['base'], 'das Tal');
        self::assertFalse($rawMin['fit_ok'], 'dort verwirft der Fit zu viele Monate');
        self::assertGreaterThan(2, $rawMin['fit_dropped']);
        self::assertLessThan($by['11.5']['ratio'], $rawMin['ratio']);
        self::assertTrue($h['suggest']);
        self::assertNotSame(12.0, $h['best']);
        $best = $by[sprintf('%.1f', $h['best'])];
        self::assertTrue($best['fit_ok'] && $best['summer_ok'] && $best['warm_ok'] && $best['base_load_ok']);
        self::assertGreaterThanOrEqual($h['range'][0], $h['best']);
        self::assertLessThanOrEqual($h['range'][1], $h['best']);
        self::assertEqualsWithDelta($best['smoothed'], $h['best_smoothed'], 1e-9);
        self::assertSame($best['ratio'], $h['best_ratio']);
    }

    /**
     * Fast rauschfreie Daten (± 2 %, Heizung genau unter 12 °C): Bei der richtigen Grenze
     * verwirft der Fit Monate, die nur 2 % danebenliegen (3,5 × eine winzige Streuung). Das
     * ist kein Fehler des Modells; die richtige Grenze bleibt wählbar.
     */
    public function testNearlyNoiseFreeMonthsAreNoMisfit(): void
    {
        $noise = fn(string $d) => 1.0 + 0.01 * ((((int)substr($d, 0, 4) * 12 + (int)substr($d, 5, 2)) * 7) % 5 - 2);
        $meter = $this->formulaHousehold(fn($d, $temp) => (6.5 + 7.5 * max(0.0, 12.0 - $temp)) * $noise($d), '2023-01-01', '2025-12-31');
        $h = $this->consumption->heatLimitSuggestion('gas', $meter);
        $at12 = array_values(array_filter($h['grid'], fn($x) => abs($x['base'] - 12.0) < 1e-9))[0];
        self::assertTrue($at12['fit_ok']);
        self::assertSame(0, $at12['fit_dropped'], 'verworfen ja, aber innerhalb des Alltagsrauschens');
        self::assertSame(12.0, $h['best']);
    }

    /** Unordentlicher Testhaushalt (Heizgrenze 12,5 °C mit Teilbeheizung): Vorschlag im Plateau, Sommer und warme Monate getroffen. */
    public function testTheMessyHouseholdSuggestsWithinThePlateau(): void
    {
        $this->loadMessy();
        $meter = $this->meters->list('gas')[0];
        $h = $this->consumption->heatLimitSuggestion('gas', $meter);
        self::assertTrue($h['suggest']);
        self::assertGreaterThanOrEqual(10.0, $h['range'][0]);
        self::assertLessThanOrEqual(12.5, $h['range'][1]);
        self::assertSame($h['range'][1], $h['best'], 'aus 15 °C kommend: das obere Ende des Plateaus');
        $best = array_values(array_filter($h['grid'], fn($x) => abs($x['base'] - $h['best']) < 1e-9))[0];
        self::assertLessThanOrEqual(ConsumptionService::HEAT_LIMIT_SUMMER_MAX, $best['warm_ratio']);
        self::assertLessThanOrEqual(ConsumptionService::HEAT_LIMIT_SUMMER_MAX, $best['summer_ratio']);
        // vorher und nachher: heute schlecht, mit dem Vorschlag gut, Grundlast näher an der Messung
        self::assertSame(['poor', 'good'], [$h['before']['model_quality'], $h['after']['model_quality']]);
        $m = $h['before']['measured_per_day'];
        self::assertLessThan(abs($h['before']['base_per_day'] - $m), abs($h['after']['base_per_day'] - $m));
    }

    // ── 3  Grundlast-Prüfung monoton ────────────────────────────────────

    /**
     * #38 (öffentliche Zusage, nachgemessen): Bis v3.7.1 maß die Untergrenze der
     * Grundlast-Prüfung die ganze Erwartung der Sommermonate. Bei hohen Grenzen fällt c auf
     * null, die kühlen Sommertage bekommen Heizgradtage, und a × HGT hob die Erwartung
     * wieder über die Hälfte: Die Prüfung schlug bis 17 °C an und bei 18 °C nicht mehr.
     * Jetzt misst die Untergrenze c allein. Die bestandenen Grenzen sind ein Stück.
     */
    public function testTheBaseLoadCheckIsMonotone(): void
    {
        $this->loadMessy();
        $meter = $this->meters->list('gas')[0];
        $h = $this->consumption->heatLimitSuggestion('gas', $meter);
        $ok = array_map(fn($x) => $x['base_load_ok'], $h['grid']);
        $runs = 0;
        foreach ($ok as $i => $v) if ($v && ($i === 0 || !$ok[$i - 1])) $runs++;
        self::assertSame(1, $runs, 'ein zusammenhängendes Stück: ' . json_encode(array_map(fn($x) => [$x['base'], $x['base_load_ok']], $h['grid'])));
        $at18 = end($h['grid']);
        $q = $this->anomalies->quality('gas', $this->consumption->forMeter('gas', $meter, 18.0));
        self::assertFalse($at18['base_load_ok'], '18 °C: Grundlast null');
        self::assertSame(0.0, (float)$q['base_load']['base_ratio']);
        self::assertGreaterThanOrEqual(AnomalyService::BASE_LOAD_MIN, $q['base_load']['ratio'], 'die ganze Erwartung läge über der Hälfte (bis v3.7.1 bestanden)');
        self::assertContains('base_load', $q['reasons']);
    }

    // ── 4  eigene Grenze für Tanks und Zählergruppen ────────────────────

    /** Heizöl: eigene Grenze am Tank, verteilt wird mit ihren Gradtagen; leer entfernt sie. */
    public function testATankHasItsOwnHeatingLimit(): void
    {
        $temps = [];
        for ($t = strtotime('2024-01-01 12:00'); date('Y-m-d', $t) <= '2025-12-31'; $t += 86400) {
            $a = self::TEMP[(int)date('n', $t)];
            $temps[date('Y-m-d', $t)] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $tank = $this->meters->create('heizoel', ['name' => 'Tank', 'capacity' => 3000, 'initial_stock' => 2000, 'installed_on' => '2024-01-01', 'hdd_base_temp' => 12]);
        self::assertSame(12.0, $tank['hdd_base_temp'], 'beim Anlegen');
        $rows = $this->consumption->forMeter('heizoel', $tank);
        $may = array_values(array_filter($rows, fn($r) => $r['ym'] === '2024-05'))[0];
        self::assertSame(0.0, (float)$may['hdd'], 'Mai (14 °C) hat bei 12 °C keine Gradtage');
        $apr = array_values(array_filter($rows, fn($r) => $r['ym'] === '2024-04'))[0];
        self::assertEqualsWithDelta(30 * (12.0 - 9.0), (float)$apr['hdd'], 0.01, 'April: 30 Tage × (12 − 9)');
        // verteilt wird nach den Gradtagen der Grenze des Tanks: mit 12 °C bekommt der Mai (14 °C) keinen Heizanteil
        $this->store->write('heizoel/deliveries.json', [
            ['id' => 'del_1', 'meter_id' => $tank['id'], 'date' => '2024-09-01', 'quantity' => 1500.0, 'unit_price_cents' => 100.0,
             'total_eur' => null, 'supplier' => '', 'note' => '', 'is_planned' => false, 'fill_to_full' => true],
            ['id' => 'del_2', 'meter_id' => $tank['id'], 'date' => '2025-09-01', 'quantity' => 1800.0, 'unit_price_cents' => 100.0,
             'total_eur' => null, 'supplier' => '', 'note' => '', 'is_planned' => false, 'fill_to_full' => true],
        ]);
        $mayDraw = function (?float $own) use ($tank): float {
            $t = $tank;
            if ($own === null) unset($t['hdd_base_temp']); else $t['hdd_base_temp'] = $own;
            $model = $this->deliveryConsumption->tankModel('heizoel', $t, '2025-12-31');
            return array_sum(array_map(fn($d) => $d['draw'], array_filter($model['days'], fn($k) => str_starts_with((string)$k, '2025-05'), ARRAY_FILTER_USE_KEY)));
        };
        self::assertGreaterThan(0.0, $mayDraw(12.0), 'Grundlast im Mai');
        self::assertLessThan($mayDraw(null) * 0.9, $mayDraw(12.0), 'Mai mit eigener Grenze 12 °C deutlich weniger als mit dem Standard 15 °C');
        $this->meters->update('heizoel', (string)$tank['id'], ['hdd_base_temp' => '']);
        self::assertArrayNotHasKey('hdd_base_temp', $this->meters->get('heizoel', (string)$tank['id']));
    }

    /**
     * Zählergruppe: eigene Grenze an der Gruppe; die Mitglieder rechnen für die Summe mit
     * ihr. Die Erwartung der Summe kommt aus dem Heizmodell der Summe (bis v3.8 stand dort
     * die Erwartung des ersten Mitglieds).
     */
    public function testAGroupHasItsOwnLimitAndTheExpectationOfItsSum(): void
    {
        $a = $this->formulaHousehold(fn($d, $temp) => 4.0 + 1.0 * max(0.0, 15.0 - $temp), '2024-01-01', '2025-12-31');
        $b = $this->meters->create('gas', ['name' => 'Einliegerwohnung', 'installed_on' => '2024-01-01']);
        $rows = []; $c = 0.0;
        $temps = $this->store->read('temperatures.json', []);
        foreach ($temps as $d => $tt) {
            if (substr($d, 8, 2) === '01') $rows[] = ['id' => 'rb_' . $d, 'meter_id' => $b['id'], 'device_id' => $b['devices'][0]['id'], 'date' => $d, 'counter' => round($c, 3),
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            $c += 2.0 + 3.0 * max(0.0, 15.0 - $tt['avg']);
        }
        $this->store->write('gas/readings.json', array_merge($this->store->read('gas/readings.json', []), $rows));
        $g = $this->meters->createGroup('gas', ['name' => 'Haus']);
        $this->meters->update('gas', (string)$a['id'], ['meter_group_id' => $g['id']]);
        $this->meters->update('gas', (string)$b['id'], ['meter_group_id' => $g['id']]);
        $this->meters->updateGroup('gas', (string)$g['id'], ['hdd_base_temp' => 13]);
        $target = $this->meters->groupTarget('gas', (string)$g['id']);
        self::assertSame(13.0, $target['hdd_base_temp']);
        self::assertSame(13.0, $this->consumption->hddBaseFor($target));
        $sum = $this->consumption->forMeter('gas', $target);
        $jan = array_values(array_filter($sum, fn($r) => $r['ym'] === '2025-01'))[0];
        $want = 0.0;
        foreach ($temps as $d => $tt) if (str_starts_with($d, '2025-01')) $want += max(0.0, 13.0 - $tt['avg']);
        self::assertEqualsWithDelta($want, (float)$jan['hdd'], 0.2, 'Gradtage der Gruppe zu 13 °C');
        $model = $this->consumption->heatModel('gas', $sum);
        self::assertEqualsWithDelta($model['a'] * (float)$jan['hdd'] + $model['c'] * (int)$jan['days'], (float)$jan['expected_heat'], 0.1, 'Erwartung der Summe');
        $first = array_values(array_filter($this->consumption->forMeter('gas', $this->meters->get('gas', (string)$a['id']), 13.0), fn($r) => $r['ym'] === '2025-01'))[0];
        self::assertGreaterThan((float)$first['expected_heat'] * 1.5, (float)$jan['expected_heat'], 'nicht die des ersten Mitglieds');
        // Mitglieder auf ihrer eigenen Seite unverändert mit dem Standard
        self::assertSame(15.0, $this->consumption->hddBaseFor($this->meters->get('gas', (string)$a['id'])));
        $this->meters->updateGroup('gas', (string)$g['id'], ['hdd_base_temp' => null]);
        self::assertArrayNotHasKey('hdd_base_temp', $this->meters->groupTarget('gas', (string)$g['id']));
        try { $this->meters->updateGroup('gas', (string)$g['id'], ['hdd_base_temp' => 30]); self::fail('30 °C angenommen'); }
        catch (\Energietracker\Support\LocalizedException $e) { self::assertSame('errors.meter.valueInvalid', $e->key); }
    }

    // ── 5  Übergangsmonate als Muster ───────────────────────────────────

    /**
     * Drei Jahre, der April jedes Jahr 40 % unter dem Modell, ein einzelner Februar
     * 60 % darüber: Der April ist ein Muster (alle drei Jahre gemeldet, jedes Jahr auf
     * derselben Seite), der Februar ein Ereignis. Ein Oktober jedes Jahr 50 % darüber
     * macht keine Empfehlung R1 mehr, ein einzelner hoher Monat schon.
     */
    public function testRecurringMonthsAreAPatternNotAnEvent(): void
    {
        $y0 = (int)date('Y') - 3;
        $from = "$y0-01-01"; $to = date('Y-m-d', strtotime('first day of this month -1 day'));
        $odd = fn(string $d) => match (true) {
            substr($d, 5, 2) === '04' => 0.6,
            substr($d, 5, 2) === '10' => 1.5,
            substr($d, 0, 7) === ($y0 + 2) . '-02' => 1.6,
            default => 1.0 + 0.01 * ((((int)substr($d, 0, 4) * 12 + (int)substr($d, 5, 2)) * 7) % 5 - 2),
        };
        $meter = $this->formulaHousehold(fn($d, $temp) => (5.0 + 3.0 * max(0.0, 15.0 - $temp)) * $odd($d), $from, $to);
        $rows = $this->consumption->forMeter('gas', $meter);
        $found = $this->anomalies->detect('gas', $rows);
        $byYm = array_column($found, null, 'ym');
        $aprils = array_values(array_filter($found, fn($a) => substr($a['ym'], 5, 2) === '04'));
        self::assertGreaterThanOrEqual(AnomalyService::PATTERN_MIN_YEARS, count($aprils));
        foreach ($aprils as $a) {
            self::assertSame('low', $a['kind']);
            self::assertTrue($a['pattern'], $a['ym']);
            self::assertSame(array_map(fn($x) => substr($x['ym'], 0, 4), $aprils), $a['pattern_years']);
        }
        $feb = $byYm[($y0 + 2) . '-02'] ?? null;
        self::assertNotNull($feb, 'der einzelne Februar wird gemeldet');
        self::assertSame(['high', false, null], [$feb['kind'], $feb['pattern'], $feb['pattern_years']]);
        // R1 (Mehrverbrauch bei gegebenem Wetter): der einzelne Februar ja, die Oktober nicht
        $r1 = array_values(array_filter($this->recs()->all(true), fn($r) => str_starts_with($r['id'], 'r1_')));
        $yms = array_map(fn($r) => $r['evidence']['ym'], $r1);
        foreach ($yms as $ym) self::assertNotSame('10', substr($ym, 5, 2), "Oktober $ym: Muster, keine Empfehlung");
        if (RecommendationService::isRecent(($y0 + 2) . '-02')) self::assertContains(($y0 + 2) . '-02', $yms);
        // die Mustermonate bleiben in der Liste (die Analyse fasst sie zusammen): genau die April- und Oktober-Meldungen
        $want = array_column(array_filter($found, fn($a) => in_array(substr($a['ym'], 5, 2), ['04', '10'], true)), 'ym');
        self::assertGreaterThanOrEqual(count($aprils) + AnomalyService::PATTERN_MIN_YEARS, count($want));
        self::assertSame($want, array_keys(AnomalyService::patternMonths($found)));
    }

    /**
     * Von Hand gebaute Monatszeilen (Erwartung je Monat 1000 kWh, Alltag ± 1 %): Der April
     * liegt in allen drei Jahren 40 % darunter (Muster), der März in zwei Jahren 40 % darüber,
     * im dritten aber 10 % darunter (kein Muster: nicht jedes Jahr auf derselben Seite).
     */
    public function testAPatternNeedsTheSameSideInEveryYear(): void
    {
        $rows = [];
        foreach ([2023, 2024, 2025] as $y) {
            for ($mo = 1; $mo <= 12; $mo++) {
                $f = 1.0 + 0.01 * ((($y * 12 + $mo) * 7) % 3 - 1);
                if ($mo === 4) $f = 0.6;
                if ($mo === 3) $f = $y === 2025 ? 0.9 : 1.4;
                $rows[] = ['ym' => sprintf('%d-%02d', $y, $mo), 'year' => $y, 'month' => $mo, 'days' => 30, 'kwh' => 1000.0 * $f,
                    'expected_heat' => 1000.0, 'hdd' => 100.0, 'temp_days' => 30];
            }
        }
        $found = array_column($this->anomalies->detect('gas', $rows), null, 'ym');
        foreach (['2023-04', '2024-04', '2025-04'] as $ym) {
            self::assertSame(['low', true, ['2023', '2024', '2025']], [$found[$ym]['kind'], $found[$ym]['pattern'], $found[$ym]['pattern_years']], $ym);
        }
        foreach (['2023-03', '2024-03'] as $ym) {
            self::assertSame(['high', false, null], [$found[$ym]['kind'], $found[$ym]['pattern'], $found[$ym]['pattern_years']], "$ym: der März 2025 liegt darunter");
        }
    }

    /**
     * v3.7.1 (#38, Gegenprobe der gebauten Regel) — gemessen ist der Median der Tagesraten
     * der Sommermonate: Ein einzelner Juli mit dem Vierfachen (defekte Therme) verschiebt die
     * gemessene Grundlast nicht. Von Hand: Grundlast 6,5 kWh am Tag ± 2 %; das Mittel der neun
     * Sommermonate läge bei (8 × 6,5 + 26) ÷ 9 ≈ 8,7.
     */
    public function testTheMeasuredSummerIsTheMedianOfTheDailyRates(): void
    {
        $noise = fn(string $d) => 1.0 + 0.01 * ((((int)substr($d, 0, 4) * 12 + (int)substr($d, 5, 2)) * 7) % 5 - 2);
        $meter = $this->formulaHousehold(fn($d, $temp) => (6.5 + 7.5 * max(0.0, 12.0 - $temp)) * $noise($d) * (substr($d, 0, 7) === '2024-07' ? 4.0 : 1.0),
            '2023-01-01', '2025-12-31');
        $q = $this->anomalies->quality('gas', $this->consumption->forMeter('gas', $meter, 12.0));
        self::assertSame('window', $q['base_load']['reference']);
        self::assertContains('2024-07', $q['base_load']['reference_months']);
        self::assertEqualsWithDelta(6.5, $q['base_load']['measured_per_day'], 0.15, 'Median, nicht Mittel');
    }

    /** Das Raster der Gradtage je Monat ist intern: Es steht nicht in der Antwort. */
    public function testTheHddGridDoesNotLeakIntoTheMonthlyRows(): void
    {
        $this->loadMessy();
        $rows = $this->consumption->forMeter('gas', $this->meters->list('gas')[0]);
        self::assertNotSame([], $rows);
        foreach ($rows as $r) self::assertArrayNotHasKey('_hdd_grid', $r);
        foreach ($this->consumption->forMeter('strom', $this->meters->list('strom')[0]) as $r) self::assertArrayNotHasKey('_hdd_grid', $r);
    }

    /** Die Doku nennt die Regeln, die gelten (Glättung, Plateau, Ausreißer, warme Monate, Muster, Untergrenze mit c). */
    public function testTheDocsNameTheRulesThatApply(): void
    {
        $root = dirname(__DIR__, 3) . '/docs/';
        $months = fn(array $m, string $lang) => (new \IntlDateFormatter($lang, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, 'MMMM'))->format(gmmktime(0, 0, 0, $m[0], 1, 2024));
        $warm = AnomalyService::WARM_MONTHS;
        foreach (['verstehen/00-overview.md' => ['de', 'bis %s °C neben', 'höchstens %d %% über der besten', 'jeden zehnten Monat', '%s bis %s', 'mindestens zwei Jahren', 'c × Tage'],
                  'en/verstehen/00-overview.md' => ['en', 'up to %s °C either side', 'at most %d %% above the best', 'one month in ten', '%s to %s', 'at least two years', 'c × days']] as $file => [$lang, $smooth, $plateau, $ten, $range, $years, $cDays]) {
            $flat = (string)preg_replace('/\s+/', ' ', (string)file_get_contents($root . $file));
            self::assertStringContainsString(sprintf($smooth, (int)ConsumptionService::HEAT_LIMIT_SMOOTH_K), $flat, "$file: Glättung");
            self::assertStringContainsString(sprintf($plateau, (int)round(ConsumptionService::HEAT_LIMIT_PLATEAU * 100)), $flat, "$file: Plateau");
            self::assertSame(0.10, ConsumptionService::HEAT_LIMIT_MAX_DROPPED);
            self::assertStringContainsString($ten, $flat, "$file: höchstens jeder zehnte Monat");
            $first = $months([$warm[0]], $lang); $last = $months([end($warm)], $lang);
            self::assertStringContainsString(sprintf($range, ucfirst($first), $last), $flat, "$file: warme Monate");
            self::assertSame(2, AnomalyService::PATTERN_MIN_YEARS);
            self::assertStringContainsString($years, $flat, "$file: Muster ab zwei Jahren");
            self::assertStringContainsString($cDays, $flat, "$file: Untergrenze mit der Grundlast c");
        }
    }

    // ── 6  „Zu tun“ ─────────────────────────────────────────────────────

    /**
     * Unordentlicher Haushalt: Die Empfehlung „Heizgrenze anpassen“ (Kategorie modell)
     * steht in „Zu tun“ mit Vorher/Nachher und der Wirkung auf Saldo und Abschlag. „Nicht
     * jetzt“ blendet diesen Datenstand aus; ein neu gemessener Monat bringt sie zurück.
     * Nach dem Übernehmen ist sie weg.
     */
    public function testTheToDoItemNotNowAndApply(): void
    {
        $this->loadMessy();
        $meter = $this->meters->list('gas')[0];
        $recs = $this->recs();
        $agenda = $this->agenda($recs);
        $rec = array_values(array_filter($recs->all(), fn($r) => $r['category'] === 'modell'))[0] ?? null;
        self::assertNotNull($rec);
        self::assertStringStartsWith('r_heat_limit_', $rec['id']);
        $h = $this->consumption->heatLimitSuggestion('gas', $meter);
        self::assertSame([$h['best'], $h['range'], 'gas', $meter['id']], [$rec['evidence']['best'], $rec['evidence']['range'], $rec['evidence']['utility'], $rec['evidence']['meter_id']]);
        self::assertStringContainsString($this->i18n->number($h['best'], 1) . ' °C', $rec['title']);
        // Wirkung auf den laufenden Vertrag: heute unsicher (Heizmodell), mit dem Vorschlag nicht mehr wegen der Passung
        $e = $rec['evidence']['effect'];
        self::assertSame('c_gas_messy', $e['contract_id']);
        self::assertContains('poor_fit', $e['before']['uncertain']);
        self::assertNotContains('poor_fit', $e['after']['uncertain']);
        self::assertNull($e['before']['advance'], 'heute kein Abschlagsvorschlag');
        $ev = array_values(array_filter($agenda->events(), fn($x) => $x['kind'] === 'heat_limit'));
        self::assertCount(1, $ev);
        self::assertTrue($ev[0]['due_now']);
        self::assertFalse($ev[0]['can_mark_done'], 'eigene Knöpfe statt Haken');
        self::assertSame([$rec['id'], $h['best'], '#/utility/gas?meter=m_gas_messy'], [$ev[0]['ref']['recommendation_id'], $ev[0]['ref']['best'], $ev[0]['href']]);
        // stabile Kennung je Zähler und Grenze (Benachrichtigungen einmal je Vorschlag)
        self::assertSame('heat_limit-gas-m_gas_messy-' . sprintf('%.1f', $h['best']), $ev[0]['uid']);
        // derselbe Eintrag im Sensor „Zu tun“ für Home Assistant (GET /api/todo)
        $todo = \Energietracker\Services\HomeAssistant\Todo::summary($agenda->events(), 'test');
        self::assertContains($ev[0]['uid'], array_column(array_filter($todo['items'], fn($i) => $i['kind'] === 'heat_limit'), 'uid'));
        self::assertSame($e, $ev[0]['ref']['effect']);

        // „Nicht jetzt“
        $recs->dismiss($rec['id'], date('Y-m-d', strtotime('+1 year')));
        self::assertSame([], array_values(array_filter($this->agenda($this->recs())->events(), fn($x) => $x['kind'] === 'heat_limit')));
        // ein neuer gemessener Monat: neuer Datenstand, neue ID, wieder da
        $readings = $this->store->read('gas/readings.json', []);
        $last = end($readings);
        $readings[] = ['id' => 'r_gas_messy_new', 'meter_id' => 'm_gas_messy', 'device_id' => 'd_gas_new', 'date' => '2026-02-04',
            'counter' => round((float)$last['counter'] + 250.0, 1), 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        $this->store->write('gas/readings.json', $readings);
        $again = array_values(array_filter($this->recs()->all(), fn($r) => $r['category'] === 'modell'));
        self::assertCount(1, $again);
        self::assertNotSame($rec['id'], $again[0]['id']);
        $ev2 = array_values(array_filter($this->agenda($this->recs())->events(), fn($x) => $x['kind'] === 'heat_limit'));
        self::assertSame('heat_limit-gas-m_gas_messy-' . sprintf('%.1f', $again[0]['evidence']['best']), $ev2[0]['uid'], 'Kennung ohne Datenstand, nur Zähler und Grenze');
        self::assertNotSame($ev[0]['occurrence'], $ev2[0]['occurrence'], 'das Vorkommen trägt den Datenstand');

        // übernommen: kein Vorschlag mehr, keine Empfehlung
        $this->meters->update('gas', (string)$meter['id'], ['hdd_base_temp' => $again[0]['evidence']['best']]);
        self::assertSame([], array_values(array_filter($this->recs()->all(), fn($r) => $r['category'] === 'modell')));
    }
}
