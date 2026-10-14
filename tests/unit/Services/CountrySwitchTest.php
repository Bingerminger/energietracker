<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Countries;
use Energietracker\Services\SettingsService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.5.0 (N1022) — Land wechseln mit einer Regel für Erststart, Einrichtung
 * und Einstellungen (SettingsService::applyCountry).
 *
 * Festgeschrieben wird nur, was vom Default abweicht. Bis v3.4 schrieb
 * „Alle übernehmen“ jeden Profilwert fest — auch die deutschen Defaults, wenn
 * jemand nach einem englischen Erststart (Profil GB) Deutschland wählte. Eine
 * spätere Korrektur eines Defaults (neue UBA-Jahreswerte) hätte diese
 * Installation dann nie erreicht (Lektion 45).
 */
#[CoversClass(SettingsService::class)]
final class CountrySwitchTest extends ServiceTestCase
{
    /** @return array<string,mixed> */
    private function stored(): array
    {
        $s = $this->store->read('settings.json', []);
        return is_array($s) ? $s : [];
    }

    /** Schlüssel des Länderprofils (SSOT: Countries::settingsFor). */
    private static function profileKeys(): array
    {
        return array_keys(Countries::settingsFor(Countries::DEFAULT));
    }

    public function testAustriaWritesOnlyWhatDiffersFromTheDefaults(): void
    {
        $all = $this->settings->applyCountry('AT');
        self::assertSame('AT', $all['country']);
        self::assertSame('Europe/Vienna', $all['timezone']);
        self::assertSame('Wien', $all['location_name']);
        $stored = $this->stored();
        foreach (['country', 'timezone', 'co2_strom', 'co2_strom_years', 'location_name', 'latitude', 'longitude'] as $k) {
            self::assertArrayHasKey($k, $stored, "$k weicht in Österreich vom Default ab");
        }
        foreach (['currency', 'hdd_base_temp', 'gas_cv_unit'] as $k) {
            self::assertArrayNotHasKey($k, $stored, "$k ist in Österreich wie der Default und wird nicht festgeschrieben");
        }
    }

    public function testBackToGermanyLeavesNoFrozenDefaults(): void
    {
        $this->settings->applyCountry('GB');
        self::assertSame('GBP', $this->settings->get('currency'));
        $all = $this->settings->applyCountry('DE');
        self::assertSame([], array_values(array_intersect(self::profileKeys(), array_keys($this->stored()))),
            'Deutschland = Defaults: kein Profilwert bleibt festgeschrieben');
        foreach (Countries::settingsFor('DE') as $k => $v) {
            self::assertSame($v, $all[$k], "$k gilt wieder mit dem Default");
        }
    }

    public function testCountryOnlyKeepsOwnValues(): void
    {
        $this->settings->set(['location_name' => 'Musterstadt', 'latitude' => 50.1, 'longitude' => 8.6, 'hdd_base_temp' => 14.0]);
        $all = $this->settings->applyCountry('AT', false);
        self::assertSame('AT', $all['country']);
        self::assertSame('Musterstadt', $all['location_name'], 'nur das Land: eigener Standort bleibt');
        self::assertEquals(14.0, $all['hdd_base_temp']);
        self::assertSame('Europe/Berlin', $all['timezone'], 'nur das Land: Zeitzone bleibt');
        $this->settings->applyCountry('DE', false);
        self::assertArrayNotHasKey('country', $this->stored(), 'DE ist der Default und wird nicht festgeschrieben');
        self::assertSame('Musterstadt', $this->settings->get('location_name'));
    }

    public function testOtherSettingsStayUntouched(): void
    {
        $this->settings->set(['language' => 'en', 'wohnflaeche_m2' => 88, 'active_utilities' => ['strom']]);
        $this->settings->applyCountry('FR');
        $this->settings->applyCountry('DE');
        $s = $this->stored();
        self::assertSame(['en', 88, ['strom']], [$s['language'] ?? null, $s['wohnflaeche_m2'] ?? null, $s['active_utilities'] ?? null]);
    }

    public function testUnknownCountryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings->applyCountry('XX');
    }

    public function testIsDefaultIsStrict(): void
    {
        self::assertTrue(SettingsService::isDefault('country', 'DE'));
        self::assertFalse(SettingsService::isDefault('country', 'AT'));
        self::assertFalse(SettingsService::isDefault('hdd_base_temp', 15), 'int 15 ist nicht der Default 15.0 (wie beim Erststart)');
        self::assertFalse(SettingsService::isDefault('gibt_es_nicht', null));
    }
}
