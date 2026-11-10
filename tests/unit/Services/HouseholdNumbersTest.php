<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BenchmarkService;
use Energietracker\Services\SettingsService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Wohnfläche und Personen: Dezimalkomma angenommen, Unsinn abgewiesen, Grenzen.
 * Bisher nahm PATCH /api/settings jeden Wert: „120,5“ rechnete die Effizienz als 120 m² und
 * hielt die Fläche zugleich für nicht eingetragen, „viel“ wurde 0 m², −5 eine negative Fläche.
 */
#[CoversClass(SettingsService::class)]
final class HouseholdNumbersTest extends ServiceTestCase
{
    private function rejected(array $patch): bool
    {
        try {
            $this->settings->set($patch);
        } catch (\InvalidArgumentException) {
            return true;
        }
        return false;
    }

    private function efficiency(): array
    {
        return (new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n))->efficiency(2025);
    }

    public function testAnAreaWithADecimalCommaIsTheSameArea(): void
    {
        $this->settings->set(['wohnflaeche_m2' => '120,5']);
        self::assertSame(120.5, $this->settings->get('wohnflaeche_m2'));
        self::assertTrue($this->settings->isEntered('wohnflaeche_m2'));
        self::assertNotContains('wohnflaeche_m2', $this->settings->assumedKeys());
        $e = $this->efficiency();
        self::assertSame(120.5, $e['wohnflaeche_m2'], 'die Effizienz rechnet mit 120,5 m², nicht mit 120');
        self::assertFalse($e['area_assumed'], 'und hält die Fläche für eingetragen');
    }

    public function testNonsenseAndImpossibleAreasAreRejected(): void
    {
        foreach (['viel', -5, '-0,5', '1.234,5', 100001, [120], true] as $bad) {
            self::assertTrue($this->rejected(['wohnflaeche_m2' => $bad]), var_export($bad, true));
        }
        self::assertTrue($this->settings->assumedKeys() === ['wohnflaeche_m2', 'wasser_personen_anzahl'], 'nichts gespeichert');
        // Grenzen gehen, eine ganze Zahl bleibt ganz; 0 heißt wie bisher „keine Angabe“
        $this->settings->set(['wohnflaeche_m2' => '100000']);
        self::assertSame(100000, $this->settings->get('wohnflaeche_m2'));
        $this->settings->set(['wohnflaeche_m2' => 0]);
        self::assertSame(0, $this->settings->get('wohnflaeche_m2'));
        self::assertFalse($this->settings->isEntered('wohnflaeche_m2'));
    }

    public function testPersonsAreAWholeNumber(): void
    {
        $this->settings->set(['wasser_personen_anzahl' => '3']);
        self::assertSame(3, $this->settings->get('wasser_personen_anzahl'));
        foreach (['2,5', -1, 'viele', 1001] as $bad) {
            self::assertTrue($this->rejected(['wasser_personen_anzahl' => $bad]), var_export($bad, true));
        }
        self::assertSame(3, $this->settings->get('wasser_personen_anzahl'));
    }

    public function testOtherNumberSettingsTakeACommaAndRejectText(): void
    {
        $this->settings->set(['hdd_base_temp' => '14,5']);
        self::assertSame(14.5, $this->settings->get('hdd_base_temp'));
        self::assertTrue($this->rejected(['hdd_base_temp' => 'warm']));
        self::assertTrue($this->rejected(['dashboard_months' => ['12']]));
        self::assertSame(14.5, $this->settings->get('hdd_base_temp'));
    }

    /** Altbestand: ungeprüft gespeicherte Werte einer älteren Version lesen sich ehrlich. */
    public function testStoredValuesFromAnOlderVersionAreReadHonestly(): void
    {
        $this->store->write('settings.json', ['wohnflaeche_m2' => '120,5', 'wasser_personen_anzahl' => 'viel']);
        self::assertSame(120.5, $this->settings->get('wohnflaeche_m2'));
        self::assertTrue($this->settings->isEntered('wohnflaeche_m2'));
        // „viel“ ist keine Angabe: Default 2 Personen, als angenommen gemeldet
        self::assertSame(2, $this->settings->get('wasser_personen_anzahl'));
        self::assertSame(['wasser_personen_anzahl'], $this->settings->assumedKeys());
        $this->store->write('settings.json', ['wohnflaeche_m2' => -5]);
        self::assertSame(100, $this->settings->get('wohnflaeche_m2'), 'eine negative Fläche gilt als nicht eingetragen');
        self::assertTrue($this->efficiency()['area_assumed']);
    }
}
