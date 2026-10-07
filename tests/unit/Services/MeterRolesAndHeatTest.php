<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Utilities;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\MeterService;
use Energietracker\Storage\Migrator;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H3) — Zählerrollen (B9), Heizwärme (B8), Warmwasser-Wärme
 * nach HeizkostenV § 9 (CALC-28), Schemaschritt 1.7.0.
 */
#[CoversClass(MeterService::class)]
#[CoversClass(Utilities::class)]
final class MeterRolesAndHeatTest extends ServiceTestCase
{
    public function testDhwHeatFollowsTheFormula(): void
    {
        self::assertSame(125.0, ConsumptionService::dhwKwh(1.0, 60));
        self::assertSame(337.5, ConsumptionService::dhwKwh(3.0, 55), 'Akzeptanz: 3 m³ bei 55 °C');
        self::assertSame(0.0, ConsumptionService::dhwKwh(0.0, 60));
        self::assertSame(0.0, ConsumptionService::dhwKwh(2.0, 10));
    }

    public function testOnlyAWarmWaterMeterGetsDhwAndNoOtherValueChanges(): void
    {
        $id = (string)$this->meters->defaultId('wasser');
        $this->setReadings('wasser', $id, [['date' => '2025-01-01', 'counter' => 100], ['date' => '2025-02-01', 'counter' => 103.1]]);
        $before = $this->consumption->forMeter('wasser', $this->meters->get('wasser', $id));
        self::assertArrayNotHasKey('dhw_kwh', $before[0], 'ohne Rolle warm: nichts Neues');

        $this->meters->update('wasser', $id, ['role' => 'warm']);
        $this->settings->set(['warmwasser_temp_c' => 55]);
        $after = $this->consumption->forMeter('wasser', $this->meters->get('wasser', $id));
        self::assertEqualsWithDelta(3.1, $after[0]['m3'], 0.01);
        self::assertSame(ConsumptionService::dhwKwh($after[0]['m3'], 55), $after[0]['dhw_kwh']);
        self::assertSame($before[0]['m3'], $after[0]['m3'], 'm³ unverändert');
        self::assertSame($before[0]['cost'] ?? null, $after[0]['cost'] ?? null);
    }

    public function testRolesAreValidatedAndHeatSourceIsTheHeatPumpAlias(): void
    {
        $id = (string)$this->meters->defaultId('strom');
        $m = $this->meters->update('strom', $id, ['heat_source' => true]);
        self::assertSame('heat_pump', $m['role']);
        self::assertTrue($m['heat_source']);
        $m = $this->meters->update('strom', $id, ['role' => 'household']);
        self::assertArrayNotHasKey('role', $m, 'Standardrolle wird nicht gespeichert');
        self::assertArrayNotHasKey('heat_source', $m);
        $m = $this->meters->update('strom', $id, ['role' => 'heat_pump']);
        self::assertTrue($m['heat_source'], 'ältere Versionen sehen die Wärmepumpe weiter');
        // ein alter Datensatz mit nur heat_source zählt als heat_pump
        self::assertSame('heat_pump', Utilities::roleOf('strom', ['heat_source' => true]));
        self::assertSame('cold', Utilities::roleOf('wasser', []));
        self::assertNull(Utilities::roleOf('gas', ['role' => 'x']));

        foreach ([['strom', 'garden'], ['gas', 'warm']] as [$u, $role]) {
            try {
                $this->meters->update($u, (string)$this->meters->defaultId($u), ['role' => $role]);
                self::fail("$u/$role angenommen");
            } catch (LocalizedException $e) {
                self::assertSame('errors.meter.roleInvalid', $e->key);
            }
        }
    }

    public function testHeatWaermeHasNoContractsAndTakesItsCo2FromTheHeatSource(): void
    {
        self::assertFalse(Utilities::hasContracts('waerme'));
        self::assertTrue(Utilities::isHgtRelevant('waerme'));
        self::assertNull(Utilities::co2Setting('waerme', null));
        self::assertSame('co2_fernwaerme', Utilities::co2Setting('waerme', 'fernwaerme'));
        self::assertNull(Utilities::co2Setting('waerme', 'waerme'));

        $m = (string)$this->meters->create('waerme', ['name' => 'WMZ', 'installed_on' => '2020-01-01'])['id'];
        $this->setReadings('waerme', $m, [['date' => '2025-01-01', 'counter' => 0], ['date' => '2025-02-01', 'counter' => 1000]]);
        $row = $this->consumption->forMeter('waerme', $this->meters->get('waerme', $m))[0];
        self::assertSame(0.0, $row['co2_kg'], 'ohne Angabe 0');
        $this->settings->set(['waerme_energietraeger' => 'fernwaerme']);
        $row = $this->consumption->forMeter('waerme', $this->meters->get('waerme', $m))[0];
        self::assertEqualsWithDelta(1000 * (float)$this->settings->get('co2_fernwaerme') / 1000, $row['co2_kg'], 0.2);
        $this->settings->set(['waerme_energietraeger' => 'none']);
        self::assertNull($this->settings->get('waerme_energietraeger'), '„keine Angabe" ist null');
    }

    /** Wärmeabgabe der Wärmepumpe und Batterie zählen nicht in die Summe der Art. */
    public function testMeasuringRolesStayOutOfTheTotals(): void
    {
        $use = (string)$this->meters->create('waerme', ['name' => 'WMZ', 'installed_on' => '2020-01-01'])['id'];
        $out = (string)$this->meters->create('waerme', ['name' => 'WP', 'installed_on' => '2020-01-01', 'role' => 'heat_pump_output'])['id'];
        $this->setReadings('waerme', $use, [['date' => '2025-01-01', 'counter' => 0], ['date' => '2025-02-01', 'counter' => 1000]]);
        $all = $this->store->read('waerme/readings.json', []);
        foreach ([['date' => '2025-01-01', 'counter' => 0], ['date' => '2025-02-01', 'counter' => 1100]] as $i => $r) {
            $all[] = $r + ['id' => "r_wp$i", 'meter_id' => $out, 'is_estimated' => false, 'is_future' => false, 'note' => '', 'price_cents' => null];
        }
        $this->store->write('waerme/readings.json', $all);
        $jan = array_column($this->consumption->forUtility('waerme')['monthly_total'], null, 'ym')['2025-01'];
        self::assertEqualsWithDelta(1000.0, $jan['kwh'], 0.01, 'nur der Verbrauchszähler');
        self::assertFalse(MeterService::countsInTotals(['role' => 'battery_charge']));
        self::assertTrue(MeterService::countsInTotals(['role' => 'heat_pump']), 'Heizstrom ist Verbrauch');
    }

    public function testPeriodsAndBillsKeepTheirMeterAndReadingsCannotMoveToAPeriodMeter(): void
    {
        $pm = (string)$this->meters->create('waerme', ['name' => 'UVI', 'installed_on' => '2020-01-01', 'capture' => 'period'])['id'];
        (new \Energietracker\Services\PeriodService($this->store, $this->meters, $this->i18n))
            ->create('waerme', ['meter_id' => $pm, 'month' => '2025-01', 'value' => 500]);
        try {
            $this->meters->delete('waerme', $pm);
            self::fail('Zähler mit Zeiträumen gelöscht');
        } catch (\InvalidArgumentException $e) {
            self::assertSame($this->i18n->t('errors.meter.hasPeriods'), $e->getMessage());
        }
        $cm = (string)$this->meters->create('waerme', ['name' => 'WMZ', 'installed_on' => '2020-01-01'])['id'];
        $r = $this->readings->create('waerme', ['meter_id' => $cm, 'date' => '2025-01-01', 'counter' => 5]);
        try {
            $this->readings->update('waerme', $r['id'], ['meter_id' => $pm]);
            self::fail('Ablesung auf Zeitraum-Zähler umgehängt');
        } catch (LocalizedException $e) {
            self::assertSame('errors.reading.periodMeter', $e->key);
        }
    }

    public function testTheSchemaStepCreatesTheNewPotsAndChangesNothingElse(): void
    {
        // Datenverzeichnis wie unter 1.6.0: ohne die neuen Töpfe
        foreach (Migrator::V170_TOP_POTS as $f) $this->store->delete($f);
        foreach (Utilities::keys() as $k) $this->store->delete("$k/periods.json");
        $this->store->delete('waerme/meters.json');
        $this->store->write('meta.json', ['schema_version' => '1.6.0']);
        $gasMeters = $this->store->read('gas/meters.json', []);

        $mig = new Migrator($this->store);
        self::assertTrue($mig->needsV170Upgrade());
        self::assertTrue($mig->needsMigration());
        $mig->migrate();
        self::assertSame('1.7.0', $this->store->read('meta.json', [])['schema_version']);
        foreach (Migrator::V170_TOP_POTS as $f) self::assertSame([], $this->store->read($f, null), $f);
        self::assertSame([], $this->store->read('waerme/meters.json', null), 'neue Verbrauchsart: leere Töpfe');
        self::assertSame([], $this->store->read('gas/periods.json', null));
        self::assertEquals($gasMeters, $this->store->read('gas/meters.json', []), 'vorhandene Daten unverändert');
        self::assertFalse($mig->needsV170Upgrade(), 'idempotent');
    }
}
