<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ForecastService;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.1.0 (Review CALC-20) — Schmutzwasser = Hauptzähler minus Abzugszähler.
 *
 * Der häufigste Fall auf der Abwasserrechnung: Ein Gartenzähler hinter dem
 * Hauptzähler misst Wasser, das versickert. Es kostet Trinkwasser, aber kein
 * Schmutzwasser. Bis v3.0 ging das nur über einen „separaten Zähler“ fürs
 * Schmutzwasser — und die Demo führte den Garten als eigenen Zähler mit
 * eigenem Trinkwasserpreis: Volumen doppelt, Trinkwasser doppelt bepreist.
 */
final class WaterDeductionMeterTest extends ServiceTestCase
{
    private function setUpMeters(): void
    {
        $dev = fn(string $id) => [['id' => $id, 'serial' => null, 'installed_on' => '2024-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $this->store->write('wasser/meters.json', [
            ['id' => 'm_main', 'name' => 'Haupt', 'icon' => '', 'created_at' => '2024-01-01', 'active' => true,
             'notes' => '', 'devices' => $dev('d_main'), 'parent_meter_id' => null],
            ['id' => 'm_garden', 'name' => 'Garten', 'icon' => '', 'created_at' => '2024-01-01', 'active' => true,
             'notes' => '', 'devices' => $dev('d_garden'), 'parent_meter_id' => 'm_main'],
        ]);
        $r = fn(string $id, string $meter, string $dev, string $date, float $c) => ['id' => $id, 'meter_id' => $meter,
            'device_id' => $dev, 'date' => $date, 'counter' => $c, 'price_cents' => null, 'note' => '',
            'is_estimated' => false, 'is_future' => false];
        $this->store->write('wasser/readings.json', [
            $r('r1', 'm_main', 'd_main', '2024-06-01', 0.0),
            $r('r2', 'm_main', 'd_main', '2024-07-01', 20.0),
            $r('r3', 'm_garden', 'd_garden', '2024-06-01', 0.0),
            $r('r4', 'm_garden', 'd_garden', '2024-07-01', 6.0),
        ]);
    }

    private function contract(array $schmutzwasser): array
    {
        return $this->contracts->create('wasser', [
            'meter_id' => 'm_main', 'provider' => 'Wasserwerk', 'tariff_name' => 'Wasser',
            'start' => '2024-01-01', 'end' => null,
            'trinkwasser' => ['working_prices' => [['from' => '2024-01-01', 'ct_per_m3' => 200.0]]],
            'schmutzwasser' => $schmutzwasser,
        ]);
    }

    public function testSewageIsMainMinusGarden(): void
    {
        $this->setUpMeters();
        $c = $this->contract(['basis' => 'trinkwasser_minus_abzug', 'abzug_meter_ids' => ['m_garden'],
            'working_prices' => [['from' => '2024-01-01', 'ct_per_m3' => 300.0]]]);
        self::assertSame(['m_garden'], $c['schmutzwasser']['abzug_meter_ids']);

        $rows = array_column($this->consumption->forMeter('wasser', $this->meters->get('wasser', 'm_main')), null, 'ym');
        $june = $rows['2024-06'];
        self::assertEqualsWithDelta(20.0, $june['trinkwasser']['m3'], 1e-6, 'Trinkwasser: alles, was der Hauptzähler misst');
        self::assertEqualsWithDelta(14.0, $june['schmutzwasser']['m3'], 1e-6, 'Schmutzwasser: ohne Garten');
        self::assertEqualsWithDelta(6.0, $june['schmutzwasser']['abzug_m3'], 1e-6);
        self::assertEqualsWithDelta(40.0 + 42.0, $june['cost'], 0.01);

        // Der Garten ist Subzähler: in der Summe nicht doppelt
        $total = array_column($this->consumption->forUtility('wasser')['monthly_total'] ?? [], null, 'ym');
        self::assertEqualsWithDelta(20.0, $total['2024-06']['m3'] ?? null, 1e-6);

        // Prognose: Schmutzwasser-Anteil des Kalendermonats aus der Historie
        self::assertEqualsWithDelta(0.7, ForecastService::sewageShareByMonth(array_values($rows))[6], 1e-4);
    }

    public function testNeverBelowZero(): void
    {
        $this->setUpMeters();
        $this->store->write('wasser/readings.json', array_map(function ($r) {
            if ($r['id'] === 'r4') $r['counter'] = 25.0;   // Garten „mehr“ als Haupt (Ablesefehler)
            return $r;
        }, $this->store->read('wasser/readings.json', [])));
        $this->contract(['basis' => 'trinkwasser_minus_abzug', 'abzug_meter_ids' => ['m_garden'],
            'working_prices' => [['from' => '2024-01-01', 'ct_per_m3' => 300.0]]]);
        $rows = array_column($this->consumption->forMeter('wasser', $this->meters->get('wasser', 'm_main')), null, 'ym');
        self::assertSame(0.0, (float)$rows['2024-06']['schmutzwasser']['m3']);
    }

    public function testDeductionNeedsMetersAndNotItself(): void
    {
        $this->setUpMeters();
        foreach ([[], ['m_main']] as $ids) {
            try {
                $this->contract(['basis' => 'trinkwasser_minus_abzug', 'abzug_meter_ids' => $ids]);
                self::fail('ohne gültigen Abzugszähler gespeichert: ' . json_encode($ids));
            } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        // andere Basis: keine Abzugszähler gespeichert
        $c = $this->contract(['basis' => 'trinkwasser', 'abzug_meter_ids' => ['m_garden']]);
        self::assertSame([], $c['schmutzwasser']['abzug_meter_ids']);
    }
}
