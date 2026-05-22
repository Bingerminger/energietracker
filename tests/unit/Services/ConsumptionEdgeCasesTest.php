<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ConsumptionService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * N1002 — Edge-Case-Suite für {@see ConsumptionService::forMeter()}.
 *
 * Dokumentiert das tatsächliche Verhalten in seltenen Konstellationen.
 * Kein Test verlangt eine Code-Änderung — alle Pfade waren bereits in
 * v1.6.2 korrekt; die Tests halten sie fest, bevor F1006 (Meter-Topologie)
 * den ConsumptionService substanziell anfasst.
 */
#[CoversClass(ConsumptionService::class)]
final class ConsumptionEdgeCasesTest extends ServiceTestCase
{
    /**
     * Case 1 — Zählerüberlauf ohne Device-Tausch (Reset auf 0 oder Stand
     * läuft auf 6 Stellen über, neue Ablesung ist KLEINER als die alte).
     * Ohne Bridging-Information ist die Differenz negativ — der Service
     * verwirft das Intervall, statt einen riesigen falschen Verbrauch oder
     * einen negativen Wert anzuzeigen.
     */
    public function testCounterRolloverWithoutSwapIsDiscarded(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 99000.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        // 99900 → Überlauf → 100 (5-stellig wieder bei 0)
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-03-01', 'counter' => 99900.0, 'device_id' => 'd_strom_1'],
            ['date' => '2024-04-01', 'counter' => 100.0,   'device_id' => 'd_strom_1'],
        ]);

        $meter = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);

        $totalKwh = array_sum(array_map(fn($m) => (float)($m['kwh'] ?? 0), $monthly));
        self::assertEqualsWithDelta(0.0, $totalKwh, 0.001,
            'Zählerüberlauf ohne Tausch muss verworfen werden, nicht negativ verbucht');
    }

    /**
     * Case 2 — Sehr langes Intervall (>90 Tage zwischen zwei Ablesungen).
     * Verbrauch wird linear über alle betroffenen Kalendermonate verteilt.
     * Jeder Monat trägt `days` proportional zu seiner Länge im Intervall.
     */
    public function testLongIntervalIsLinearlyDistributedAcrossMonths(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        // ~180 Tage Lücke, 1800 kWh → 10 kWh/Tag
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-01-15', 'counter' => 0.0,    'device_id' => 'd_strom_1'],
            ['date' => '2024-07-15', 'counter' => 1820.0, 'device_id' => 'd_strom_1'],
        ]);

        $meter = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);

        $months = array_column($monthly, 'ym');
        self::assertGreaterThanOrEqual(6, count($months),
            'Langes Intervall muss über alle berührten Kalendermonate verteilt werden');
        $totalKwh = array_sum(array_map(fn($m) => (float)($m['kwh'] ?? 0), $monthly));
        self::assertEqualsWithDelta(1820.0, $totalKwh, 1.0,
            'Summe der verteilten kWh muss dem Gesamtverbrauch entsprechen');
    }

    /**
     * Case 3 — Zwei Ablesungen am exakt selben Datum (z.B. versehentliche
     * doppelte Eingabe, oder Import doppelt). `days = 0` → der Service
     * überspringt das degenerierte Intervall und rechnet das nächste sauber.
     */
    public function testDuplicateReadingOnSameDateIsSkipped(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-03-01', 'counter' => 100.0, 'device_id' => 'd_strom_1', 'id' => 'r_a'],
            ['date' => '2024-03-01', 'counter' => 105.0, 'device_id' => 'd_strom_1', 'id' => 'r_b'], // ⚠ Duplikat
            ['date' => '2024-04-01', 'counter' => 200.0, 'device_id' => 'd_strom_1', 'id' => 'r_c'],
        ]);

        $meter = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);

        $totalKwh = array_sum(array_map(fn($m) => (float)($m['kwh'] ?? 0), $monthly));
        // Erwartet: das Duplikat-Intervall (a→b) wird übersprungen, b→c trägt 95.
        // Bei stabilem usort gewinnt die später eingefügte Ablesung (r_b) als
        // „prev" für das nächste Intervall — alternative Reihenfolge wäre
        // a→c (=100). Akzeptiert beide plausiblen Reihenfolgen.
        self::assertContains(
            round($totalKwh, 1),
            [95.0, 100.0],
            'Duplikat-Intervall muss übersprungen werden, Folge-Intervall korrekt gerechnet'
        );
    }

    /**
     * Case 4 — Negativer Verbrauch ohne Device-Tausch (Eingabefehler:
     * neue Ablesung kleiner als die alte). Wird verworfen, NICHT als
     * negative Zahl ins Monatsergebnis übernommen.
     */
    public function testNegativeReadingDeltaWithoutSwapIsDiscarded(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-03-01', 'counter' => 500.0, 'device_id' => 'd_strom_1'],
            ['date' => '2024-04-01', 'counter' => 480.0, 'device_id' => 'd_strom_1'], // ⚠ rückwärts
        ]);

        $meter = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);

        $totalKwh = array_sum(array_map(fn($m) => (float)($m['kwh'] ?? 0), $monthly));
        self::assertEqualsWithDelta(0.0, $totalKwh, 0.001,
            'Negativer Verbrauch ohne Tausch muss verworfen werden');
        foreach ($monthly as $m) {
            self::assertGreaterThanOrEqual(0.0, (float)($m['kwh'] ?? 0),
                'Keine einzelne Monatszeile darf einen negativen kWh-Wert tragen');
        }
    }

    /**
     * Case 5 — Schaltjahr. Ein Intervall, das den 29.02. eines Schaltjahres
     * überquert, muss diesen Tag korrekt mitzählen (Februar hat 29 Tage,
     * nicht 28). distributeToMonths rechnet via DateTime::diff, mktime/date('t').
     */
    public function testLeapYearFebruaryIsCountedWith29Days(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        // 2024 ist Schaltjahr. Feb 1 → Mär 1 = 29 Tage.
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-02-01', 'counter' => 0.0,  'device_id' => 'd_strom_1'],
            ['date' => '2024-03-01', 'counter' => 29.0, 'device_id' => 'd_strom_1'],
        ]);

        $meter = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);
        $byYm = array_column($monthly, null, 'ym');

        self::assertArrayHasKey('2024-02', $byYm);
        self::assertSame(29, (int)$byYm['2024-02']['days'],
            'Februar 2024 (Schaltjahr) muss 29 Tage tragen, nicht 28');
    }

    /**
     * Case 6 — Sommer-/Winterzeit-Umstellung. Die App rechnet nur mit
     * Datumsteilen (YYYY-MM-DD) in Europe/Berlin — DST-Wechsel am letzten
     * März- bzw. Oktober-Sonntag dürfen die Tagezählung nicht verschieben.
     * März 2024 hat 31 Tage, egal ob DST darin liegt.
     */
    public function testDstTransitionDoesNotShiftDayCount(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        // Märzwechsel auf Sommerzeit liegt 2024 am 31.03. Intervall umspannt das.
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-03-15', 'counter' => 0.0,  'device_id' => 'd_strom_1'],
            ['date' => '2024-04-15', 'counter' => 31.0, 'device_id' => 'd_strom_1'],
        ]);

        $meter = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);
        $byYm = array_column($monthly, null, 'ym');

        // März-Anteil 15.→01.04. = 17 Tage (Differenz, nicht „Anzahl Tage im Kalender"),
        // April-Anteil 01.→15. = 14 Tage. Summe 31 = Intervall-Länge.
        self::assertSame(17, (int)$byYm['2024-03']['days'],
            'März-Anteil (15.→01.04.) muss exakt 17 Tage sein trotz DST');
        self::assertSame(14, (int)$byYm['2024-04']['days'],
            'April-Anteil (01.→15.) muss exakt 14 Tage sein');
        self::assertSame(31,
            (int)$byYm['2024-03']['days'] + (int)$byYm['2024-04']['days'],
            'Tagessumme muss der Intervall-Länge entsprechen, DST verschiebt nichts'
        );
    }

    /**
     * Case 7 — Leerer Zähler / nur eine Ablesung. Ohne ≥ 2 Ablesungen
     * existiert kein Intervall — der Service gibt sauber `[]` zurück,
     * crasht nicht und meldet auch keine Monatszeile mit `kwh = 0`.
     */
    public function testEmptyOrSingleReadingMeterReturnsEmptyMonthly(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        // Variante a — gar keine Ablesungen
        $this->setReadings('strom', $meterId, []);
        $meter = $this->meters->get('strom', $meterId);
        self::assertSame([], $this->consumption->forMeter('strom', $meter),
            'Zähler ohne Ablesungen muss [] liefern');

        // Variante b — genau eine Ablesung
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-03-01', 'counter' => 100.0, 'device_id' => 'd_strom_1'],
        ]);
        self::assertSame([], $this->consumption->forMeter('strom', $meter),
            'Zähler mit nur einer Ablesung muss [] liefern (kein Intervall)');
    }
}
