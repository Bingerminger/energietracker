<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Energietracker\Services\ConsumptionService;

/**
 * Bridging-Pfade aus Issue #13. Treibt {@see ConsumptionService::forMeter()}
 * gegen reale JSON-Fixtures und prüft die Ergebnisse der privaten
 * consumptionBetween()-Logik über das öffentliche Aggregat.
 */
#[CoversClass(ConsumptionService::class)]
final class ConsumptionServiceBridgingTest extends ServiceTestCase
{
    /**
     * Sauberer Tausch: altes Gerät korrekt geschlossen, neues Gerät bei 0
     * gestartet, Readings beidseits sauber dem richtigen Device zugeordnet.
     * Erwartete Bridge-Summe = (final_alt − prev) + (curr − init_neu)
     *                        = (11000 − 10800) + (300 − 0) = 500 kWh.
     */
    public function testCleanBridgingSumsAcrossDeviceSwap(): void
    {
        $devices = [
            [
                'id' => 'd_old', 'serial' => null,
                'installed_on' => '2024-01-01', 'initial_counter' => 10000.0,
                'removed_on' => '2024-06-15',   'final_counter'   => 11000.0,
                'reason' => null,
            ],
            [
                'id' => 'd_new', 'serial' => null,
                'installed_on' => '2024-06-15', 'initial_counter' => 0.0,
                'removed_on' => null,           'final_counter'   => null,
                'reason' => null,
            ],
        ];
        $meterId = $this->setMeterDevices('strom', $devices);

        $this->setReadings('strom', $meterId, [
            ['date' => '2024-05-15', 'counter' => 10800.0, 'device_id' => 'd_old'],
            ['date' => '2024-07-15', 'counter' => 300.0,   'device_id' => 'd_new'],
        ]);

        $meter   = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);

        $totalKwh = array_sum(array_map(fn($m) => (float)($m['kwh'] ?? 0), $monthly));
        self::assertEqualsWithDelta(500.0, $totalKwh, 0.5,
            'Bridging-Summe muss (final_alt − prev) + (curr − init_neu) sein');
        // Verbrauch verteilt sich über mehrere Kalendermonate (kein 500-kWh-Spike in einem Monat).
        self::assertGreaterThanOrEqual(2, count($monthly),
            'Bridge-Intervall muss auf Monate verteilt werden');
    }

    /**
     * Issue #13, Off-by-one: eine Ablesung am `removed_on`-Tag wird dem
     * NEUEN Gerät zugeordnet, nicht mehr dem alten. Über
     * {@see ReadingService::create()} verifiziert (= Schreibpfad).
     */
    public function testReadingOnSwapDayBelongsToNewDevice(): void
    {
        $devices = [
            [
                'id' => 'd_old', 'serial' => null,
                'installed_on' => '2024-01-01', 'initial_counter' => 10000.0,
                'removed_on' => '2024-06-15',   'final_counter'   => 11000.0,
                'reason' => null,
            ],
            [
                'id' => 'd_new', 'serial' => null,
                'installed_on' => '2024-06-15', 'initial_counter' => 0.0,
                'removed_on' => null,           'final_counter'   => null,
                'reason' => null,
            ],
        ];
        $meterId = $this->setMeterDevices('strom', $devices);

        $reading = $this->readings->create('strom', [
            'meter_id' => $meterId,
            'date'     => '2024-06-15',
            'counter'  => 0.5,
        ]);

        self::assertSame('d_new', $reading['device_id'],
            'Am Tausch-Tag muss die Ablesung dem neuen Gerät gehören (deviceOnDate-Konvention)');
    }

    /**
     * Pfad 3 — fehlender `final_counter` am alten Gerät. Ohne sauberen
     * Endstand ist kein konsistenter Übergang berechenbar; das Tausch-
     * Intervall wird verworfen statt einen falschen Riesensprung zu
     * zeigen. Vorhergehende Same-Device-Intervalle bleiben intakt.
     */
    public function testBridgingDroppedWhenOldDeviceHasNoFinalCounter(): void
    {
        $devices = [
            [
                'id' => 'd_old', 'serial' => null,
                'installed_on' => '2024-01-01', 'initial_counter' => 10000.0,
                'removed_on' => '2024-06-15',   'final_counter'   => null,   // ⚠ nicht gepflegt
                'reason' => null,
            ],
            [
                'id' => 'd_new', 'serial' => null,
                'installed_on' => '2024-06-15', 'initial_counter' => 0.0,
                'removed_on' => null,           'final_counter'   => null,
                'reason' => null,
            ],
        ];
        $meterId = $this->setMeterDevices('strom', $devices);

        // Intervall 1 (sauber): 2024-05-01 → 2024-06-01 auf altem Gerät, 300 kWh.
        // Intervall 2 (Bridge ohne final_counter): muss verworfen werden.
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-05-01', 'counter' => 10500.0, 'device_id' => 'd_old'],
            ['date' => '2024-06-01', 'counter' => 10800.0, 'device_id' => 'd_old'],
            ['date' => '2024-07-15', 'counter' => 300.0,   'device_id' => 'd_new'],
        ]);

        $meter   = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);
        $totalKwh = array_sum(array_map(fn($m) => (float)($m['kwh'] ?? 0), $monthly));

        // Erwartet: nur das saubere Intervall (300 kWh), nichts vom Tausch.
        self::assertEqualsWithDelta(300.0, $totalKwh, 0.5,
            'Bridging ohne final_counter muss komplett verworfen werden');
        // Insbesondere darf kein einzelner Monat einen Riesensprung enthalten.
        foreach ($monthly as $m) {
            self::assertLessThan(1000.0, (float)($m['kwh'] ?? 0),
                'Keine Monatszeile darf einen Bridging-Spike enthalten');
        }
    }

    /**
     * Pfad 4 — Plausibilitäts-Cap: ein Reading auf dem alten Gerät muss
     * im Bereich [initial_old, final_old] liegen. Liegt es darunter, ist
     * die device_id offensichtlich falsch zugeordnet (typischer Issue-#13-
     * Fall) → Intervall verwerfen, nicht 200-fach übertriebenen
     * Verbrauch ausweisen.
     */
    public function testBridgingDroppedWhenPrevReadingBelowOldDeviceInitial(): void
    {
        $devices = [
            [
                'id' => 'd_old', 'serial' => null,
                'installed_on' => '2024-01-01', 'initial_counter' => 10000.0,
                'removed_on' => '2024-06-15',   'final_counter'   => 11000.0,
                'reason' => null,
            ],
            [
                'id' => 'd_new', 'serial' => null,
                'installed_on' => '2024-06-15', 'initial_counter' => 0.0,
                'removed_on' => null,           'final_counter'   => null,
                'reason' => null,
            ],
        ];
        $meterId = $this->setMeterDevices('strom', $devices);

        // prev.counter = 0.1 mit device_id=d_old ist unplausibel — der alte
        // Zähler stand bei [10000, 11000]. Bridging muss das Intervall
        // verwerfen.
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-05-15', 'counter' => 0.1,   'device_id' => 'd_old'],
            ['date' => '2024-07-15', 'counter' => 300.0, 'device_id' => 'd_new'],
        ]);

        $meter   = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);
        $totalKwh = array_sum(array_map(fn($m) => (float)($m['kwh'] ?? 0), $monthly));

        self::assertEqualsWithDelta(0.0, $totalKwh, 0.001,
            'Unplausibles prev.counter < initial_old muss das Intervall verwerfen');
    }

    /**
     * Pfad 5 — Wechsel-Monat trägt `device_swap = true`. Das erste Gerät
     * eines Zählers (initiale Inbetriebnahme) zählt nicht als Tausch;
     * spätere installed_on und alle removed_on schon.
     */
    public function testSwapMonthIsFlagged(): void
    {
        $devices = [
            [
                'id' => 'd_old', 'serial' => null,
                'installed_on' => '2024-01-01', 'initial_counter' => 10000.0,
                'removed_on' => '2024-06-15',   'final_counter'   => 11000.0,
                'reason' => null,
            ],
            [
                'id' => 'd_new', 'serial' => null,
                'installed_on' => '2024-06-15', 'initial_counter' => 0.0,
                'removed_on' => null,           'final_counter'   => null,
                'reason' => null,
            ],
        ];
        $meterId = $this->setMeterDevices('strom', $devices);

        $this->setReadings('strom', $meterId, [
            ['date' => '2024-05-01', 'counter' => 10500.0, 'device_id' => 'd_old'],
            ['date' => '2024-06-01', 'counter' => 10800.0, 'device_id' => 'd_old'],
            ['date' => '2024-07-15', 'counter' => 300.0,   'device_id' => 'd_new'],
        ]);

        $meter   = $this->meters->get('strom', $meterId);
        $monthly = $this->consumption->forMeter('strom', $meter);
        $byYm = [];
        foreach ($monthly as $m) {
            $byYm[$m['ym']] = $m;
        }

        // 2024-06 enthält das removed_on UND installed_on → muss als Tausch markiert sein.
        self::assertArrayHasKey('2024-06', $byYm);
        self::assertTrue((bool)$byYm['2024-06']['device_swap'],
            'Monat des Geräte-Tauschs muss device_swap=true tragen');

        // 2024-05 hat keinerlei Tausch → nicht markiert.
        if (isset($byYm['2024-05'])) {
            self::assertFalse((bool)$byYm['2024-05']['device_swap'],
                'Vor-Tausch-Monat darf nicht device_swap=true tragen');
        }
    }
}
