<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ReadingCheckService;
use Energietracker\Services\ReadingService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket K1, Entscheidung 11 b, #65) — dieselbe Erwartung nach dem Wetter für den Vorschlag einer KI
 * (ReadingService::plausibilityFor mit ReadingCheckService) und für die Vorschau eines CSV-Imports (eine Sammelabfrage
 * für alle Abstände der Datei, ReadingCheckService::expectationsFor).
 *
 * Haushalt wie ReadingExpectationTest (der Fall des Melders): Gaszähler, samstags von Hand abgelesen ab 05.04.2025,
 * Sommer und ungeheizter Oktober 4,2 kWh am Tag, ab November geheizt (3,5 kWh je Gradtag, Heizgrenze 15 °C),
 * 10,5 kWh je m³, Temperatur je Kalendermonat fest (Okt 9, Nov 4, Dez 1 °C). Alle Tage fest und in der Vergangenheit.
 */
#[CoversClass(ReadingCheckService::class)]
#[CoversClass(ReadingService::class)]
final class ReadingExpectationBatchTest extends ServiceTestCase
{
    private const TEMP = [1 => 0.0, 2 => 1.0, 3 => 5.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
                          7 => 19.0, 8 => 18.0, 9 => 14.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];
    private const FACTOR = 10.5;

    private function checks(): ReadingCheckService
    {
        return new ReadingCheckService($this->store, $this->meters, $this->readings, $this->consumption, $this->settings);
    }

    /** Gaszähler des Melders mit Ständen an den Samstagen bis `$until`; Rückgabe: der Zähler. */
    private function household(string $until): array
    {
        $temps = [];
        for ($t = strtotime('2025-03-01 12:00'); date('Y-m-d', $t) <= '2026-01-31'; $t += 86400) {
            $a = self::TEMP[(int)date('n', $t)];
            $temps[date('Y-m-d', $t)] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
        }
        $this->store->write('temperatures.json', $temps);
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => self::FACTOR]],
            'hdd_base_temp' => 15.0, 'active_utilities' => ['gas']]);
        $id = $this->setMeterDevices('gas', [['id' => 'd1', 'serial' => null, 'installed_on' => '2025-04-05', 'initial_counter' => 12345.678,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = []; $c = 12345.678; $i = 0;
        for ($t = strtotime('2025-04-05 12:00'); date('Y-m-d', $t) <= $until; $t += 86400, $i++) {
            $d = date('Y-m-d', $t);
            if ($i % 7 === 0) $rows[] = ['id' => 'r_' . $d, 'date' => $d, 'counter' => round($c, 3), 'device_id' => 'd1'];
            $c += self::day($d, $i) / self::FACTOR;
        }
        $this->setReadings('gas', $id, $rows);
        return $this->meters->list('gas')[0];
    }

    private static function day(string $d, int $i): float
    {
        $heat = $d >= '2025-11-01' ? 3.5 * max(0.0, 15.0 - self::TEMP[(int)substr($d, 5, 2)]) : 0.0;
        return (4.2 + $heat) * (1 + 0.08 * sin($i * 1.7));
    }

    /** Stand nach der Formel am Tag `$date` (Abstand von der letzten Ablesung `$prevDate`, Stand `$prev`). */
    private static function counterOn(string $prevDate, float $prev, string $date): float
    {
        $i = (int)round((strtotime("$prevDate 12:00") - strtotime('2025-04-05 12:00')) / 86400);
        $c = $prev;
        for ($t = strtotime("$prevDate 12:00"); date('Y-m-d', $t) < $date; $t += 86400, $i++) $c += self::day(date('Y-m-d', $t), $i) / self::FACTOR;
        return round($c, 3);
    }

    /** Die Sammelabfrage liefert für jeden Abstand genau das, was die Einzelabfrage liefert (auch ohne Wetter). */
    public function testTheBatchGivesTheSameAsEachSingleQuery(): void
    {
        $meter = $this->household('2025-12-27');
        $dates = array_column($this->readings->list('gas', (string)$meter['id']), 'date');
        $pairs = [];
        for ($k = 1; $k < count($dates); $k++) $pairs[] = [$dates[$k - 1], $dates[$k]];
        // dazu: ein Tag über das Ende der Temperaturen hinaus, ein Abstand ohne Tage, ein Abstand vor dem Gerät
        $pairs[] = ['2026-01-24', '2026-02-07'];
        $pairs[] = ['2025-11-08', '2025-11-08'];
        $pairs[] = ['2025-03-01', '2025-03-08'];
        $checks = $this->checks();
        $batch = $checks->expectationsFor('gas', $meter, $pairs, null, '2026-01-31');
        self::assertCount(count($pairs), $batch);
        $bases = [];
        foreach ($pairs as $i => [$from, $to]) {
            self::assertSame($this->checks()->expectationFor('gas', $meter, $from, $to, null, '2026-01-31'), $batch[$i], "$from → $to");
            $bases[(string)($batch[$i]['basis'] ?? 'null')] = true;
        }
        ksort($bases);
        self::assertSame(['degree_days', 'heat_model', 'null'], array_keys($bases), 'alle Wege der Regel kommen vor');
        // andere Arten: sofort ohne Erwartung, für jeden Abstand
        $water = $checks->expectationsFor('wasser', ['id' => 'm_w'] + $meter, [['2025-11-01', '2025-11-08'], ['2025-11-08', '2025-11-15']]);
        self::assertSame(['not_weather', 'not_weather'], array_column($water, 'reason'));
    }

    /**
     * Der Vorschlag einer KI prüft nach dem Wetter, wenn er die Prüfung bekommt: erste Heizwoche nach dem Sommer ohne
     * Rückfrage (alt: Sprung), vergessenes Komma fragt, ein Zahlendreher im Sommer nennt die Erwartung. Ohne die Prüfung
     * (Parameter null) bleibt die alte Regel.
     */
    public function testTheProposalOfAnAiChecksByTheWeather(): void
    {
        $meter = $this->household('2025-11-01');
        $real = array_column($this->readings->list('gas', (string)$meter['id']), null, 'date');
        $last = (float)$real['2025-11-01']['counter'];
        $heating = self::counterOn('2025-11-01', $last, '2025-11-08');
        $old = $this->readings->plausibilityFor('gas', $meter, '2025-11-08', $heating);
        self::assertSame(['jump'], array_column($old['issues'], 'type'), 'ohne Prüfung die alte Regel (#65)');
        $new = $this->readings->plausibilityFor('gas', $meter, '2025-11-08', $heating, $this->checks(), '2025-11-08');
        self::assertSame([], $new['issues'], 'nach dem Wetter keine Rückfrage');
        $comma = $this->readings->plausibilityFor('gas', $meter, '2025-11-08', $last * 10, $this->checks(), '2025-11-08');
        self::assertSame(['magnitude'], array_column($comma['issues'], 'type'), 'Komma vergessen fragt weiter');
        // Nachtrag im Sommer (Tag nach dem 02.08.): 20 m³ an einem Tag, nach dem Wetter erwartet rund 0,4 m³ (liegt
        // auch über dem nächsten Stand)
        $typo = (float)$real['2025-08-02']['counter'] + 20.0;
        $issues = $this->readings->plausibilityFor('gas', $meter, '2025-08-03', $typo, $this->checks(), '2025-11-08')['issues'];
        self::assertSame(['jump', 'higher_than_next'], array_column($issues, 'type'));
        $jump = array_values(array_filter($issues, fn($x) => $x['type'] === 'jump'));
        self::assertSame('heat_model', $jump[0]['basis']);
        self::assertEqualsWithDelta(0.4, $jump[0]['expected'], 0.05, '4,2 kWh am Tag = 0,4 m³');
        self::assertEqualsWithDelta(20.0, $jump[0]['amount'], 1e-6);
    }

    /**
     * Steht am Tag des Vorschlags schon ein Stand (etwa aus derselben Rechnung von Hand erfasst), bestimmt er die
     * Erwartung nicht mit (Lektion 46): dieselbe Erwartung wie mit `exclude` dieses Stands.
     */
    public function testAReadingOnTheSameDayDoesNotShapeTheExpectation(): void
    {
        $meter = $this->household('2025-11-15');
        $id = (string)$meter['id'];
        $all = $this->readings->list('gas', $id);
        $cur = array_values(array_filter($all, fn($r) => $r['date'] === '2025-11-15'))[0];
        $checks = $this->checks();
        $with = $checks->expectationFor('gas', $meter, '2025-11-08', '2025-11-15', null, '2025-11-15');
        $without = $checks->expectationFor('gas', $meter, '2025-11-08', '2025-11-15', (string)$cur['id'], '2025-11-15');
        self::assertNotSame($with, $without, 'der Stand am selben Tag ändert die Erwartung (sonst prüft der Test nichts)');
        $p = $this->readings->plausibilityFor('gas', $meter, '2025-11-15', (float)$cur['counter'] + 1.0, $checks, '2025-11-15');
        self::assertSame($without, $p['expected'], 'plausibilityFor rechnet ohne den Stand am selben Tag');
        // ohne Prüfung keine Erwartung
        self::assertNull($this->readings->plausibilityFor('gas', $meter, '2025-11-15', (float)$cur['counter'] + 1.0)['expected']);
    }

    /**
     * Der Stichtag des Vorschlags (`$today`) reicht bis in die Erwartung: Stände nach diesem Tag (hier geheizte Wochen,
     * etwa später nachgetragen) bestimmen sie nicht mit. Am 08.11. kennt der Haushalt nur Sommer und ungeheizten
     * Oktober (die Höhe ist offen); mit den Wochen bis 29.11. gäbe es Vergleichsabstände mit so viel Heizwetter.
     */
    public function testTheCutOffDateOfTheProposalReachesTheExpectation(): void
    {
        $meter = $this->household('2025-11-29');
        $real = array_column($this->readings->list('gas', (string)$meter['id']), null, 'date');
        $value = (float)$real['2025-11-08']['counter'];
        $at = $this->readings->plausibilityFor('gas', $meter, '2025-11-08', $value, $this->checks(), '2025-11-08')['expected'];
        self::assertSame(['degree_days', 'colder_than_history', null], [$at['basis'], $at['reason'], $at['high']], 'Stand der Daten am 08.11.');
        $later = $this->readings->plausibilityFor('gas', $meter, '2025-11-08', $value, $this->checks(), '2025-11-29')['expected'];
        self::assertNotNull($later['high'], 'mit den Wochen danach eine Obergrenze (sonst prüft der Test nichts)');
    }

    public function testTheBatchHasALimit(): void
    {
        self::assertGreaterThanOrEqual(3700, ReadingCheckService::MAX_PAIRS, 'zehn Jahre tägliche Stände in einer Datei');
        // die Doku nennt dieselbe Grenze (DE und EN)
        $root = dirname(__DIR__, 3);
        $de = preg_replace('/\s+/', ' ', (string)file_get_contents("$root/docs/verstehen/11-zaehlerstaende.md"));
        $en = preg_replace('/\s+/', ' ', (string)file_get_contents("$root/docs/en/verstehen/11-zaehlerstaende.md"));
        self::assertStringContainsString('höchstens ' . number_format(ReadingCheckService::MAX_PAIRS, 0, ',', '.') . ' Abstände', $de);
        self::assertStringContainsString('at most ' . number_format(ReadingCheckService::MAX_PAIRS, 0, '.', ',') . ' intervals', $en);
        // die API-Referenz nennt beide Grenzen
        $apiDe = preg_replace('/\s+/', ' ', (string)file_get_contents("$root/docs/referenz/api.md"));
        $apiEn = preg_replace('/\s+/', ' ', (string)file_get_contents("$root/docs/en/referenz/api.md"));
        self::assertStringContainsString('Höchstens ' . number_format(ReadingCheckService::MAX_PAIRS, 0, ',', '.') . ' Abstände (`errors.readingCheck.tooMany`) mit zusammen höchstens '
            . number_format(ReadingCheckService::MAX_PAIR_DAYS, 0, ',', '.') . ' Tagen', $apiDe);
        self::assertStringContainsString('At most ' . number_format(ReadingCheckService::MAX_PAIRS, 0, '.', ',') . ' intervals (`errors.readingCheck.tooMany`) covering at most '
            . number_format(ReadingCheckService::MAX_PAIR_DAYS, 0, '.', ',') . ' days', $apiEn);
    }
}
