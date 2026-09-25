<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Utilities;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v2.4.2 — GitHub #21: Zwei Einheiten, zwei Bedeutungen.
 *
 * Ein Gaszähler zählt Kubikmeter; kWh entsteht erst über den
 * Umrechnungsfaktor. In der SSOT heißt das `unit` (Zählerstand) gegen
 * `consumption_unit` (Verbrauch). Der Overview-Endpunkt für die zentrale
 * Zählerstand-Erfassung lieferte bis v2.4.1 nur `consumption_unit` — die
 * Maske hatte keine andere Einheit zur Wahl und beschriftete den
 * Gas-Zählerstand mit „kWh". Gespeichert und gerechnet wurde immer in m³;
 * wer dem Etikett folgte und selbst umrechnete, trug falsche Stände ein.
 *
 * Der zweite Test hält fest, dass der gespeicherte Stand roh bleibt — genau
 * das ist die Zusicherung, die in #21 gegeben wurde („Werte als m³
 * behandeln").
 */
final class ReadingOverviewUnitsTest extends ServiceTestCase
{
    public function testOverviewCarriesTheCounterUnitSeparatelyFromTheConsumptionUnit(): void
    {
        $rows = $this->readings->overview([]);
        self::assertNotEmpty($rows, 'Frischinstallation hat Standardzähler');

        $byUtility = [];
        foreach ($rows as $r) {
            $byUtility[$r['utility']] = $r;
            self::assertArrayHasKey('unit', $r, "{$r['utility']}: Zählerstand-Einheit fehlt");
            self::assertArrayHasKey('consumption_unit', $r);
        }

        // Gas: Zähler in m³, Verbrauch in kWh — genau die Verwechslung aus #21.
        self::assertSame('m³',  $byUtility['gas']['unit']);
        self::assertSame('kWh', $byUtility['gas']['consumption_unit']);

        // Strom: beide kWh — hier fiel der Fehler nie auf.
        self::assertSame('kWh', $byUtility['strom']['unit']);
        self::assertSame('kWh', $byUtility['strom']['consumption_unit']);

        // Wasser: beide m³.
        self::assertSame('m³', $byUtility['wasser']['unit']);
    }

    /**
     * v2.13.0 — Die Einrichtungs-Checkliste und der Leerzustand einer
     * Verbrauchsart fragen, ob es schon zwei Stände gibt (erst dann entsteht
     * ein Verbrauch). Gezählt werden echte Stände, keine Zukunftswerte.
     */
    public function testOverviewCountsTheRealReadingsPerMeter(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01',
            'initial_counter' => 0.0, 'removed_on' => null,
            'final_counter' => null, 'reason' => null,
        ]]);
        $row = fn() => array_values(array_filter($this->readings->overview([]), fn($r) => $r['meter_id'] === $meterId))[0];
        self::assertSame(0, $row()['reading_count']);
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-01-01', 'counter' => 100.0, 'device_id' => 'd1'],
            ['date' => '2024-02-01', 'counter' => 250.0, 'device_id' => 'd1'],
            ['date' => '2099-01-01', 'counter' => 900.0, 'device_id' => 'd1', 'is_future' => true],
        ]);
        self::assertSame(2, $row()['reading_count']);
    }

    /** Die Einheiten kommen aus der SSOT, nicht aus einer zweiten Liste. */
    public function testOverviewUnitsMatchTheUtilitiesSsot(): void
    {
        foreach ($this->readings->overview([]) as $r) {
            $u = Utilities::get($r['utility']);
            self::assertSame($u['unit'], $r['unit'], $r['utility']);
            self::assertSame($u['consumption_unit'], $r['consumption_unit'], $r['utility']);
        }
    }

    /**
     * Der Zählerstand wird roh gespeichert. Der Faktor wirkt erst beim
     * Berechnen des Verbrauchs — nicht beim Schreiben.
     */
    public function testGasCounterIsStoredAsEnteredAndConvertedOnlyForConsumption(): void
    {
        $this->settings->set(['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.0]]]);
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd_gas_1', 'serial' => null,
            'installed_on' => '2026-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('gas', $meterId, [
            ['date' => '2026-01-01', 'counter' => 100.0, 'device_id' => 'd_gas_1'],
            ['date' => '2026-02-01', 'counter' => 150.0, 'device_id' => 'd_gas_1'],
        ]);

        $stored = $this->readings->list('gas', $meterId);
        self::assertSame(150.0, (float)end($stored)['counter'], 'Stand bleibt, wie eingegeben');

        $monthly = $this->consumption->forMeter('gas', $this->meters->get('gas', $meterId));
        $jan = null;
        foreach ($monthly as $m) {
            if ($m['ym'] === '2026-01') { $jan = $m; break; }
        }
        self::assertNotNull($jan);
        self::assertEqualsWithDelta(50.0,  $jan['m3'],  0.01, '50 m³ Differenz');
        self::assertEqualsWithDelta(500.0, $jan['kwh'], 0.01, '× Faktor 10 = 500 kWh Verbrauch');
    }
}
