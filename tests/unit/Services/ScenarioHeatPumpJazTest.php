<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ScenarioDelivery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v3.13.0 (Paket waermepumpe, Konzept §4.6 B) — Arbeitszahl beim Heizungstausch nach dem Wetter verteilt. Die
 * Jahresarbeitszahl bleibt die Eingabe (keine erfundene Höhe), nur die Monate verteilen sich: im Winter mehr Strom,
 * im Sommer weniger. Von Hand gerechnet: Heizkurve der DIN EN 14825 (Mitteltemperatur), Carnot in Kelvin.
 */
#[CoversClass(ScenarioDelivery::class)]
final class ScenarioHeatPumpJazTest extends TestCase
{
    /** Zwölf Monate ab Juli; Normal je Monat konstant (°C), Heizanteil im Winter hoch, im Sommer null. */
    private const TEMP = [7 => 18.0, 8 => 17.5, 9 => 14.0, 10 => 9.5, 11 => 5.0, 12 => 1.5, 1 => 0.5, 2 => 1.5, 3 => 4.5, 4 => 8.5, 5 => 13.0, 6 => 16.5];
    private const SHARE = [7 => 0.0, 8 => 0.0, 9 => 0.2, 10 => 0.6, 11 => 0.8, 12 => 0.9, 1 => 0.9, 2 => 0.9, 3 => 0.8, 4 => 0.6, 5 => 0.3, 6 => 0.0];

    /** @return list<array{ym:string,heat_share:float,days:int}> */
    private static function months(): array
    {
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $t = strtotime("2025-07-01 +$i months");
            $out[] = ['ym' => date('Y-m', $t), 'heat_share' => self::SHARE[(int)date('n', $t)], 'days' => (int)date('t', $t)];
        }
        return $out;
    }

    private static function dayAvg(string $d): ?float
    {
        return self::TEMP[(int)substr($d, 5, 2)];
    }

    public function testTheFlowCurveFollowsTheStandardPoints(): void
    {
        self::assertSame(55.0, ScenarioDelivery::flow(-15.0), 'unter −10 °C gehalten');
        self::assertSame(52.0, ScenarioDelivery::flow(-7.0));
        self::assertSame(42.0, ScenarioDelivery::flow(2.0));
        self::assertEqualsWithDelta(39.0, ScenarioDelivery::flow(4.5), 1e-9, 'zwischen +2 und +7 linear');
        self::assertSame(30.0, ScenarioDelivery::flow(18.0), 'über +12 °C gehalten');
        self::assertEqualsWithDelta((35.0 + 273.15) / 35.0, ScenarioDelivery::carnot(35.0, 0.0), 1e-9);
    }

    public function testTheAnnualPerformanceStaysTheEnteredOne(): void
    {
        $shape = ScenarioDelivery::copShape(self::months(), fn(string $d) => self::dayAvg($d));
        self::assertSame('carnot', $shape['source']);
        self::assertCount(12, $shape['shape']);
        // Juli (Warmwasser bei 18 °C) gegen Januar (Heizung bei 0,5 °C, Vorlauf 46,5 °C): von Hand
        $jul = ScenarioDelivery::carnot(55.0, 18.0);
        $janFlow = 42.0 + (52.0 - 42.0) * (2.0 - 0.5) / 9.0;
        $jan = 1.0 / (0.9 / ScenarioDelivery::carnot($janFlow, 0.5) + 0.1 / ScenarioDelivery::carnot(55.0, 0.5));
        self::assertEqualsWithDelta($jul, $shape['shape'][0], 1e-9);
        self::assertEqualsWithDelta($jan, $shape['shape'][6], 1e-9);

        $fuel = [300.0, 300.0, 600.0, 1200.0, 2000.0, 2600.0, 2800.0, 2400.0, 1900.0, 1100.0, 500.0, 300.0];
        $rep = ['to' => 'heat_pump', 'jaz' => 3.2, 'efficiency_old' => 0.9, 'power_ct' => 30.0];
        $flat = ScenarioDelivery::replacement($fuel, $rep, 4.8);
        $wx = ScenarioDelivery::replacement($fuel, $rep, 4.8, $shape['shape']);
        $heat = array_sum($wx['heat']);
        self::assertEqualsWithDelta($heat / 3.2, array_sum($wx['energy']), 1e-9, 'Strom im Jahr = Wärme ÷ JAZ');
        self::assertEqualsWithDelta(array_sum($flat['energy']), array_sum($wx['energy']), 1e-9);
        self::assertEqualsWithDelta(array_sum($flat['cost']), array_sum($wx['cost']), 1e-9, 'Kosten im Jahr gleich');
        self::assertEqualsWithDelta(3.2, $heat / array_sum($wx['energy']), 1e-12);
        self::assertGreaterThan($flat['energy'][6], $wx['energy'][6], 'Januar: mehr Strom');
        self::assertLessThan($flat['energy'][0], $wx['energy'][0], 'Juli: weniger Strom');
        self::assertLessThan(3.2, $wx['jaz_months'][6]);
        self::assertGreaterThan(3.2, $wx['jaz_months'][0]);
        self::assertNull($flat['jaz_months'], 'ohne Form wie bisher');
        // Pellets kennen keine Arbeitszahl: die Form wirkt nicht
        $pel = ScenarioDelivery::replacement($fuel, ['to' => 'pellets'], 4.8, $shape['shape']);
        self::assertSame(ScenarioDelivery::replacement($fuel, ['to' => 'pellets'], 4.8)['energy'], $pel['energy']);
    }

    /** Was die Anleitung (DE und EN) über die Heizkurve sagt, steht so in FLOW_CURVE (Doku-Behauptungen brauchen Tests). */
    public function testTheGuideNamesTheCurveTheCodeUses(): void
    {
        $root = dirname(__DIR__, 3);
        $curve = array_column(ScenarioDelivery::FLOW_CURVE, 1, 0);
        self::assertSame(52.0, $curve['-7']);
        self::assertSame(30.0, $curve['12']);
        self::assertSame(55.0, ScenarioDelivery::HOT_WATER_FLOW);
        foreach (['docs/anleitungen/was-waere-wenn.md', 'docs/en/anleitungen/was-waere-wenn.md'] as $f) {
            $text = (string)file_get_contents("$root/$f");
            foreach (['DIN EN 14825', '52 °C', '−7 °C', '30 °C', '+12 °C', '55 °C'] as $needle) self::assertStringContainsString($needle, $text, "$f: $needle");
        }
    }

    public function testAMeasuredCurveReplacesCarnot(): void
    {
        // gemessen: Arbeitszahl 4,5 − 0,1 × Gradtage je Tag
        $pts = [];
        foreach ([0.0, 2.0, 5.0, 8.0, 11.0, 14.0] as $x) $pts[] = [$x, 4.5 - 0.1 * $x];
        $perDay = [0.0, 0.0, 1.0, 5.5, 10.0, 13.5, 14.5, 13.5, 10.5, 6.5, 2.0, 0.0];
        $shape = ScenarioDelivery::copShape(self::months(), fn(string $d) => self::dayAvg($d), ['points' => $pts, 'hdd_per_day' => $perDay]);
        self::assertSame('measured', $shape['source']);
        self::assertEqualsWithDelta(4.5 - 0.1 * 14.5, $shape['shape'][6], 1e-9);
        // zu wenige gemessene Monate: Carnot
        $few = ScenarioDelivery::copShape(self::months(), fn(string $d) => self::dayAvg($d), ['points' => array_slice($pts, 0, 5), 'hdd_per_day' => $perDay]);
        self::assertSame('carnot', $few['source']);
        // ohne Normal keine Form (dann übers Jahr gleich wie bisher)
        self::assertNull(ScenarioDelivery::copShape(self::months(), fn(string $d) => null));
    }
}
