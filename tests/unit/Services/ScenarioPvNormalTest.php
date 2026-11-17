<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\PvWeatherModel;
use Energietracker\Services\ScenarioPv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v3.13.0 (Paket sonne) — Werkstatt PV: das Grundjahr auf normale Sonne. Von Hand gerechnet, ohne Daten.
 */
#[CoversClass(ScenarioPv::class)]
#[CoversClass(PvWeatherModel::class)]
final class ScenarioPvNormalTest extends TestCase
{
    public function testTheFactorIsNormalOverMeasuredPerDay(): void
    {
        // Juni 2025 mit 6,0 kWh/m² am Tag, das Normal hat 5,0 je Tag: Faktor 5/6
        $temps = [];
        for ($d = 1; $d <= 30; $d++) $temps[sprintf('2025-06-%02d', $d)] = ['rad' => 6.0];
        $f = PvWeatherModel::normalFactors(['2025-06'], $temps, fn(int $m): ?float => 5.0);
        self::assertEqualsWithDelta(5.0 / 6.0, $f['2025-06'], 1e-12);
        // drei Tage ohne Einstrahlung (unter 90 %): kein Faktor, das ganze Jahr bleibt gemessen
        unset($temps['2025-06-01'], $temps['2025-06-02'], $temps['2025-06-03'], $temps['2025-06-04']);
        self::assertNull(PvWeatherModel::normalFactors(['2025-06'], $temps, fn(int $m): ?float => 5.0));
        self::assertNull(PvWeatherModel::normalFactors(['2025-06'], [], fn(int $m): ?float => null), 'ohne Normal');
    }

    public function testASunnyMonthLosesFeedInFirstAndASharedPart(): void
    {
        // gemessen: 1.000 kWh erzeugt, 600 eingespeist, 400 selbst genutzt, 300 Netzbezug, ohne Speicher
        $b = ['gen' => 1000.0, 'feed' => 600.0, 'import' => 300.0, 'charge' => 0.0, 'discharge' => 0.0];
        $n = ScenarioPv::monthToNormal($b, 0.9);
        // 100 kWh weniger Sonne. Grenzen: ganz aus der Einspeisung (500 / 300) oder anteilig (540 / 340); Mitte
        self::assertEqualsWithDelta(900.0, $n['gen'], 1e-9);
        self::assertEqualsWithDelta(520.0, $n['feed'], 1e-9);
        self::assertEqualsWithDelta(320.0, $n['import'], 1e-9);
        self::assertEqualsWithDelta($n['gen'] - $n['feed'] + $n['import'], 400.0 + 300.0, 1e-9, 'Hausverbrauch bleibt');
    }

    public function testADullMonthGainsLikeALargerPlant(): void
    {
        $b = ['gen' => 1000.0, 'feed' => 600.0, 'import' => 300.0, 'charge' => 0.0, 'discharge' => 0.0];
        $n = ScenarioPv::monthToNormal($b, 1.1);
        // 100 kWh mehr Sonne. Grenzen: ganz ins Netz (700 / 300) oder anteilig in den Eigenverbrauch, höchstens
        // 10 % von 400 (660 / 260); Mitte
        self::assertEqualsWithDelta(1100.0, $n['gen'], 1e-9);
        self::assertEqualsWithDelta(680.0, $n['feed'], 1e-9);
        self::assertEqualsWithDelta(280.0, $n['import'], 1e-9);
        self::assertSame($b, ScenarioPv::monthToNormal($b, 1.0), 'Faktor 1: unverändert');
    }

    public function testQuarterHoursMoveSunToImportFirstAndLostSunFromFeedFirst(): void
    {
        $x = ['year' => 2025, 'gen' => [2.0, 2.0], 'feed' => [0.5, 1.5], 'import' => [0.3, 0.0], 'charge' => [0.0, 0.0],
              'discharge' => [0.0, 0.0], 'month' => [6, 12], 'day' => [0, 1]];
        $y = ScenarioPv::seriesToNormal($x, ['2025-06' => 0.5, '2025-12' => 1.25]);
        // Juni: 1,0 kWh weniger, 0,5 aus der Einspeisung, 0,5 mehr Netzbezug
        self::assertEqualsWithDelta(1.0, $y['gen'][0], 1e-12);
        self::assertEqualsWithDelta(0.0, $y['feed'][0], 1e-12);
        self::assertEqualsWithDelta(0.8, $y['import'][0], 1e-12);
        // Dezember: 0,5 kWh mehr, kein Netzbezug zu decken, alles ins Netz
        self::assertEqualsWithDelta(2.5, $y['gen'][1], 1e-12);
        self::assertEqualsWithDelta(2.0, $y['feed'][1], 1e-12);
        self::assertEqualsWithDelta(0.0, $y['import'][1], 1e-12);
    }
}
