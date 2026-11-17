<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\UtilityWeather;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v3.13.0 (Paket waermepumpe) — welcher Zähler dem Wetter folgt (UtilityWeather), ein Test je Fall der
 * Schnittstelle in pakete.md: Art mit Heizbezug, Strom mit Rolle Wärmepumpe, Rest der Kaskade, Hauptstromzähler bei
 * Heizung mit Strom (E4), Gruppe nur, wenn alle Mitglieder es sind. Entschieden wird an Feldern, nie am Namen.
 */
#[CoversClass(UtilityWeather::class)]
final class UtilityWeatherTest extends TestCase
{
    private const POWER = ['waerme_energietraeger' => 'strom'];

    private static function m(string $id, array $extra = []): array
    {
        return $extra + ['id' => $id, 'name' => $id, 'active' => true, 'parent_meter_id' => null, 'meter_group_id' => null];
    }

    public function testUtilitiesWithHeatingFollowTheWeatherAsBefore(): void
    {
        foreach (['gas', 'fernwaerme', 'waerme', 'heizoel', 'pellets'] as $u) {
            self::assertSame(UtilityWeather::KIND_UTILITY, UtilityWeather::kind(self::m('x'), [], $u), $u);
        }
        foreach (['wasser', 'pv_erzeugung', 'pv_einspeisung'] as $u) self::assertFalse(UtilityWeather::isWeatherDriven(self::m('x'), self::POWER, $u), $u);
        self::assertNull(UtilityWeather::kind(self::m('x'), [], 'gibt_es_nicht'));
    }

    public function testPowerWithTheHeatPumpRoleFollowsTheWeather(): void
    {
        $wp = self::m('wp', ['role' => 'heat_pump']);
        self::assertSame(UtilityWeather::KIND_HEAT_PUMP, UtilityWeather::kind($wp, [], 'strom', [self::m('haus'), $wp]));
        // das ältere Feld heat_source zählt wie die Rolle (Utilities::roleOf)
        self::assertSame(UtilityWeather::KIND_HEAT_PUMP, UtilityWeather::kind(self::m('alt', ['heat_source' => true]), [], 'strom'));
        // hinter dem Haushalt (behind): die Wärmepumpe ist ein Subzähler, folgt dem Wetter; der Haushalt nicht
        $behind = self::m('wp', ['role' => 'heat_pump', 'parent_meter_id' => 'haus']);
        self::assertSame(UtilityWeather::KIND_HEAT_PUMP, UtilityWeather::kind($behind, [], 'strom', [self::m('haus'), $behind]));
        self::assertNull(UtilityWeather::kind(self::m('haus'), [], 'strom', [self::m('haus'), $behind]));
    }

    public function testTheRestOfACascadeWhoseFrontMeterIsTheHeatPump(): void
    {
        $z1 = self::m('z1', ['role' => 'heat_pump']);
        $z2 = self::m('z2', ['parent_meter_id' => 'z1']);   // Haushalt (Standardrolle) hinter Z1
        $wallbox = self::m('wb', ['role' => 'ev_charger', 'parent_meter_id' => 'z1']);
        $all = [$z1, $z2, $wallbox];
        self::assertSame(UtilityWeather::KIND_CASCADE, UtilityWeather::kind($z1, [], 'strom', $all));
        self::assertNull(UtilityWeather::kind($z2, self::POWER, 'strom', $all), 'der Haushalt dahinter nicht');
        self::assertNull(UtilityWeather::kind($wallbox, self::POWER, 'strom', $all));
        // nur eine Wallbox dahinter ist keine Kaskade mit Haushalt
        self::assertSame(UtilityWeather::KIND_HEAT_PUMP, UtilityWeather::kind($z1, [], 'strom', [$z1, $wallbox]));
    }

    public function testE4TheMainMeterWhenTheHouseHeatsWithPowerAndHasNoHeatPumpMeter(): void
    {
        $main = self::m('main');
        self::assertSame(UtilityWeather::KIND_HEATING, UtilityWeather::kind($main, self::POWER, 'strom', [$main]));
        self::assertNull(UtilityWeather::kind($main, ['waerme_energietraeger' => 'gas'], 'strom', [$main]), 'Heizung mit Gas');
        self::assertNull(UtilityWeather::kind($main, [], 'strom', [$main]), 'Heizungsart nicht angegeben');
        // eigener Wärmepumpenzähler: dann misst er die Wärmepumpe, der Hauptzähler nicht
        $wp = self::m('wp', ['role' => 'heat_pump']);
        self::assertNull(UtilityWeather::kind($main, self::POWER, 'strom', [$main, $wp]));
        // ein stillgelegter Wärmepumpenzähler zählt nicht
        self::assertSame(UtilityWeather::KIND_HEATING, UtilityWeather::kind($main, self::POWER, 'strom', [$main, ['active' => false] + $wp]));
        // eine Wallbox neben dem Haushalt und ein Subzähler ändern nichts
        $wb = self::m('wb', ['role' => 'ev_charger']);
        $sub = self::m('sub', ['parent_meter_id' => 'main']);
        self::assertSame(UtilityWeather::KIND_HEATING, UtilityWeather::kind($main, self::POWER, 'strom', [$main, $wb, $sub]));
        self::assertNull(UtilityWeather::kind($sub, self::POWER, 'strom', [$main, $wb, $sub]), 'ein Subzähler ist nicht der Hauptzähler');
    }

    public function testE4DoesNotGuessBetweenSeveralMainMeters(): void
    {
        $main = self::m('main');
        $flat = self::m('einlieger');
        self::assertNull(UtilityWeather::kind($main, self::POWER, 'strom', [$main, $flat]), 'zwei Zähler ohne Elternzähler');
        // ein stillgelegter zweiter Zähler (Gartenhaus) stört nicht, ein stillgelegter Hauptzähler folgt nicht
        self::assertSame(UtilityWeather::KIND_HEATING, UtilityWeather::kind($main, self::POWER, 'strom', [$main, ['active' => false] + $flat]));
        self::assertNull(UtilityWeather::kind(['active' => false] + $main, self::POWER, 'strom', [['active' => false] + $main]));
    }

    public function testE4WithATwoRateMainMeterCountsTheGroupAsOne(): void
    {
        $ht = self::m('ht', ['meter_group_id' => 'g']);
        $nt = self::m('nt', ['meter_group_id' => 'g']);
        $all = [$ht, $nt];
        self::assertSame(UtilityWeather::KIND_HEATING, UtilityWeather::kind($ht, self::POWER, 'strom', $all));
        self::assertSame(UtilityWeather::KIND_HEATING, UtilityWeather::kind(['id' => 'g', 'is_group' => true, 'members' => ['ht', 'nt']], self::POWER, 'strom', $all));
    }

    public function testAGroupFollowsTheWeatherOnlyWhenAllMembersDo(): void
    {
        $ht = self::m('wp_ht', ['role' => 'heat_pump', 'meter_group_id' => 'g']);
        $nt = self::m('wp_nt', ['role' => 'heat_pump', 'meter_group_id' => 'g']);
        $group = ['id' => 'g', 'is_group' => true];
        self::assertSame(UtilityWeather::KIND_HEAT_PUMP, UtilityWeather::kind($group, [], 'strom', [$ht, $nt]), 'HT/NT einer Wärmepumpe');
        $house = self::m('haus', ['meter_group_id' => 'g']);
        self::assertNull(UtilityWeather::kind($group, [], 'strom', [$ht, $house]), 'Wärmepumpe und Haushalt in einer Gruppe');
        self::assertNull(UtilityWeather::kind($group, [], 'strom', []), 'Gruppe ohne Mitglieder');
        // verschieden, aber alle nach dem Wetter: Rest der Kaskade und eigener Zähler
        $z2 = self::m('z2', ['parent_meter_id' => 'wp_ht']);
        self::assertSame(UtilityWeather::KIND_GROUP, UtilityWeather::kind($group, [], 'strom', [$ht, $nt, $z2]));
    }

    public function testTheNameDecidesNothing(): void
    {
        $named = self::m('wp', ['name' => 'Wärmepumpe', 'notes' => 'heat pump']);
        self::assertNull(UtilityWeather::kind($named, [], 'strom', [$named, self::m('haus')]));
        self::assertFalse(UtilityWeather::isWeatherDriven($named, [], 'strom', [$named, self::m('haus')]));
        self::assertTrue(UtilityWeather::isWeatherDriven(self::m('wp', ['role' => 'heat_pump']), []));
    }
}
