<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Countries;
use Energietracker\Services\SettingsService;
use Energietracker\Storage\Migrator;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.10.0 — CO₂-Faktoren mit Quelle (Review CALC-19) und Lektion 36.
 *
 * Bis v2.9 galt für Strom ein Wert für alle Jahre (380 g/kWh), der Gasfaktor
 * war auf den Heizwert bezogen, obwohl die App Gas nach Brennwert zählt
 * (+10 %), Pellets und Fernwärme lagen neben der BAFA-Tabelle. Die Korrektur
 * darf Bestandsinstallationen nicht still verändern: Die Migration 1.6.0
 * schreibt dort die alten Werte fest, die Einstellungen bieten den Wechsel an.
 */
#[CoversClass(SettingsService::class)]
#[CoversClass(Migrator::class)]
final class Co2FactorsTest extends ServiceTestCase
{
    public function testElectricityFollowsTheYearOfConsumption(): void
    {
        self::assertSame(379.0, $this->settings->co2Factor('co2_strom', 2023));
        self::assertSame(353.0, $this->settings->co2Factor('co2_strom', 2024));
        self::assertSame(344.0, $this->settings->co2Factor('co2_strom', 2027), 'nach dem letzten Jahr gilt dessen Wert');
        self::assertSame(380.0, $this->settings->co2Factor('co2_strom', 2010), 'davor der eine Wert');
        self::assertSame(182.0, $this->settings->co2Factor('co2_gas', 2024), 'andere Arten: ein Wert');
    }

    public function testMonthlyRowsUseTheFactorOfTheirYear(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2022-12-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $readings = [];
        for ($i = 0; $i <= 24; $i++) {
            $readings[] = ['date' => date('Y-m-d', strtotime("2023-01-01 +$i months")), 'counter' => 250.0 * $i, 'device_id' => 'd1'];
        }
        $this->setReadings('strom', $meterId, $readings);

        $rows = [];
        foreach ($this->consumption->forMeter('strom', $this->meters->get('strom', $meterId)) as $m) $rows[$m['ym']] = $m;
        self::assertEqualsWithDelta($rows['2023-06']['kwh'] * 379 / 1000, $rows['2023-06']['co2_kg'], 0.06);
        self::assertEqualsWithDelta($rows['2024-06']['kwh'] * 353 / 1000, $rows['2024-06']['co2_kg'], 0.06,
            'Bis v2.9: 380 g/kWh in jedem Jahr');
    }

    public function testGasFactorIsOnTheCalorificBasisTheAppCounts(): void
    {
        // BAFA: 201 g/kWh bezogen auf den Heizwert; Gas-kWh der App sind Brennwert-kWh
        self::assertEqualsWithDelta(201 * 0.906, (float)$this->settings->get('co2_gas'), 0.5);
        self::assertSame(36.0, (float)$this->settings->get('co2_pellets'));
        self::assertSame(280.0, (float)$this->settings->get('co2_fernwaerme'));
        self::assertSame(122.0, (float)$this->settings->get('wasser_personen_referenz'));
    }

    public function testAnUpdateKeepsTheNumbersOfAnExistingInstallation(): void
    {
        // Bestand auf 1.5.0, die CO₂-Werte nie gespeichert — bis auf einen
        $this->store->write('settings.json', ['co2_pellets' => 30.0]);
        $this->store->write('meta.json', ['schema_version' => '1.5.0', 'migrated_at' => 'x', 'log' => []]);
        $migrator = new Migrator($this->store);
        self::assertTrue($migrator->needsV160Upgrade());
        $migrator->migrate();

        $s = $this->store->read('settings.json', []);
        self::assertEquals(201.0, $s['co2_gas'], 'alter Gas-Default festgeschrieben');
        self::assertSame([], $s['co2_strom_years'], 'keine Jahreswerte — Strom rechnet weiter mit 380');
        self::assertEquals(180.0, $s['co2_fernwaerme']);
        self::assertEquals(127.0, $s['wasser_personen_referenz']);
        self::assertEquals(30.0, $s['co2_pellets'], 'ein selbst gesetzter Wert bleibt');

        $settings = new SettingsService($this->store);
        self::assertSame(380.0, $settings->co2Factor('co2_strom', 2024), 'die Zahl von gestern');
        self::assertFalse((new Migrator($this->store))->needsV160Upgrade(), 'idempotent');
    }

    public function testALaterSchemaStepNeverFreezesOldValuesOntoANewInstallation(): void
    {
        // unter 1.6.0 angelegt: settings.json ohne co2_strom_years
        self::assertSame('1.6.0', $this->store->read('meta.json', [])['schema_version']);
        self::assertFalse((new Migrator($this->store))->needsV160Upgrade());
    }

    public function testTheSettingsOfferTheCorrectedDefaultsUntilTaken(): void
    {
        $this->store->write('settings.json', []);
        $this->store->write('meta.json', ['schema_version' => '1.5.0', 'migrated_at' => 'x', 'log' => []]);
        (new Migrator($this->store))->migrate();

        $settings = new SettingsService($this->store);
        $offer = array_column($settings->defaultUpdates(), 'recommended', 'key');
        self::assertEqualsCanonicalizing(
            ['co2_gas', 'co2_strom_years', 'co2_pellets', 'co2_fernwaerme', 'wasser_personen_referenz'],
            array_keys($offer)
        );
        self::assertSame(Countries::CO2_STROM_DE_UBA, $offer['co2_strom_years']);

        // Selbst geändert: kein Angebot mehr für diesen Schlüssel
        $settings->set(['co2_gas' => 190]);
        self::assertNotContains('co2_gas', array_column($settings->defaultUpdates(), 'key'));

        // Übernommen: nichts mehr offen
        $rest = [];
        foreach ($settings->defaultUpdates() as $u) $rest[$u['key']] = $u['recommended'];
        $settings->set($rest);
        self::assertSame([], $settings->defaultUpdates());
        self::assertSame(353.0, $settings->co2Factor('co2_strom', 2024));
    }

    public function testYearValuesAreValidatedStrictly(): void
    {
        foreach ([['1800' => 300], ['2024' => -5], ['2024' => 'viel'], ['20x4' => 300], 'keine Liste'] as $bad) {
            try {
                $this->settings->set(['co2_strom_years' => $bad]);
                self::fail('angenommen: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->settings->set(['co2_strom_years' => [['year' => 2025, 'g_per_kwh' => '344,5'], ['year' => 2024, 'g_per_kwh' => 353]]]);
        // assertEquals: JSON unterscheidet 353.0 nicht von 353
        self::assertEquals([2024 => 353.0, 2025 => 344.5], $this->settings->get('co2_strom_years'), 'Liste, Dezimalkomma, sortiert');
    }

    public function testOtherCountriesDoNotInheritGermanYears(): void
    {
        self::assertSame(Countries::CO2_STROM_DE_UBA, Countries::settingsFor('DE')['co2_strom_years']);
        foreach (Countries::codes() as $c) {
            if ($c === 'DE') continue;
            self::assertSame([], Countries::settingsFor($c)['co2_strom_years'], "$c: eigener Wert, keine deutschen Jahre");
        }
        // Österreich: Profil übernommen → der eine Wert gilt für jedes Jahr
        $this->settings->set(Countries::settingsFor('AT'));
        self::assertSame(103.0, $this->settings->co2Factor('co2_strom', 2024));
    }
}
