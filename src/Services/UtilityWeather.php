<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v3.13.0 (Paket waermepumpe) — Welcher Zähler folgt dem Wetter? Eine Stelle für die ganze App.
 *
 * Bis v3.12 hing das Wetter an der Verbrauchsart (`hgt_relevant`): Gas, Fernwärme, Heizwärme, Heizöl und
 * Pellets bekamen Heizmodell, Klimanormal und Band, Strom nie, auch nicht der Strom einer Wärmepumpe. Jetzt
 * entscheidet der Zähler (Konzept §4.1 A, Entscheidung E4 vom 2026-11-12):
 *
 *   utility        Art mit Heizbezug (`hgt_relevant`), wie bisher
 *   heat_pump      Strom mit der Rolle `heat_pump` (eigener Zähler neben oder hinter dem Haushalt)
 *   cascade_rest   Z1 einer Kaskade mit der Rolle `heat_pump` und dem Haushalt als Subzähler dahinter: Die
 *                  Wärmepumpe ist der Rest, das Modell steht auf der Menge, die der Vertrag von Z1 rechnet
 *                  (`billed_kwh`), nicht auf Z1 gesamt
 *   heating_power  Hauptstromzähler bei Heizung mit Strom (`waerme_energietraeger = strom`), wenn es keinen
 *                  eigenen Wärmepumpenzähler gibt (E4: automatisch über die Heizungsart)
 *   group          Gruppe, deren Mitglieder alle dem Wetter folgen, aber auf verschiedene Weise (sonst die Art
 *                  der Mitglieder, etwa HT/NT einer Wärmepumpe: heat_pump)
 *
 * Der Hauptstromzähler (E4) ist der eine aktive Zähler ohne Elternzähler mit der Rolle Haushalt; zwei
 * Zählwerke einer Gruppe (HT/NT) zählen als einer. Gibt es mehrere (Einliegerwohnung, Gartenhaus), ist nicht
 * klar, welcher die Heizung misst: dann keiner (nicht raten). Ein stillgelegter Wärmepumpenzähler zählt nicht.
 *
 * Die Entscheidung liest nur Felder (Rolle, Elternzähler, Gruppe, Einstellung), nie einen Namen oder einen
 * Text (keine Logik am Meldungstext). Was die App mit einem Zähler nach dem Wetter rechnet und ob der Baustein freigeschaltet
 * ist, steht in ConsumptionService::weatherKind() und ::heatPumpWeather().
 *
 * Schnittstelle (pakete.md „Schnittstellen“, für Paket I): `isWeatherDriven($meter, $settings, $utility,
 * $meters)`. Gegenüber dem Entwurf `isWeatherDriven(array $meter, array $settings)` kommen die Verbrauchsart
 * und die Zählerliste dazu: Ein Zählerdatensatz trägt seine Art nicht, und Kaskade, E4 und Gruppe hängen an
 * den anderen Zählern. Ohne Zählerliste (leer) entscheiden nur Art und Rolle.
 */
final class UtilityWeather
{
    public const KIND_UTILITY = 'utility';
    public const KIND_HEAT_PUMP = 'heat_pump';
    public const KIND_CASCADE = 'cascade_rest';
    public const KIND_HEATING = 'heating_power';
    public const KIND_GROUP = 'group';

    /**
     * Folgt dieser Zähler (oder diese Gruppe) dem Wetter?
     *
     * @param array<string,mixed> $meter     Zähler oder Gruppe (MeterService::target(), `is_group`)
     * @param array<string,mixed> $settings  Einstellungen (SettingsService::all()), gelesen: `waerme_energietraeger`
     * @param string $utility                Verbrauchsart des Zählers
     * @param list<array<string,mixed>> $meters alle Zähler dieser Art (MeterService::list()), auch stillgelegte
     */
    public static function isWeatherDriven(array $meter, array $settings, string $utility = 'strom', array $meters = []): bool
    {
        return self::kind($meter, $settings, $utility, $meters) !== null;
    }

    /**
     * Wie folgt er dem Wetter (eine der KIND_*-Konstanten), null: gar nicht.
     *
     * @param list<array<string,mixed>> $meters
     */
    public static function kind(array $meter, array $settings, string $utility, array $meters = []): ?string
    {
        if (!Utilities::exists($utility)) return null;
        if (Utilities::isHgtRelevant($utility)) return self::KIND_UTILITY;
        if ($utility !== 'strom') return null;
        if (!empty($meter['is_group'])) {
            $gid = (string)($meter['id'] ?? '');
            $members = array_values(array_filter($meters, fn($m) => $gid !== '' && (string)($m['meter_group_id'] ?? '') === $gid));
            if ($members === []) return null;
            $kinds = [];
            foreach ($members as $m) {
                $k = self::kind($m, $settings, $utility, $meters);
                if ($k === null) return null;
                $kinds[$k] = true;
            }
            return count($kinds) === 1 ? (string)array_key_first($kinds) : self::KIND_GROUP;
        }
        $id = (string)($meter['id'] ?? '');
        $role = Utilities::roleOf('strom', $meter);
        if ($role === 'heat_pump') {
            foreach ($meters as $m) {
                if ($id !== '' && (string)($m['parent_meter_id'] ?? '') === $id && Utilities::roleOf('strom', $m) === 'household') {
                    return self::KIND_CASCADE;
                }
            }
            return self::KIND_HEAT_PUMP;
        }
        return self::isMainOfPowerHeating($meter, $settings, $meters) ? self::KIND_HEATING : null;
    }

    /**
     * E4 — der Hauptstromzähler eines Hauses, das mit Strom heizt und keinen eigenen Wärmepumpenzähler hat.
     *
     * @param list<array<string,mixed>> $meters
     */
    private static function isMainOfPowerHeating(array $meter, array $settings, array $meters): bool
    {
        if (($settings['waerme_energietraeger'] ?? null) !== 'strom') return false;
        if (Utilities::roleOf('strom', $meter) !== 'household' || !empty($meter['parent_meter_id']) || ($meter['active'] ?? true) === false) return false;
        $units = [];
        foreach ($meters as $m) {
            if (($m['active'] ?? true) === false) continue;
            if (Utilities::roleOf('strom', $m) === 'heat_pump') return false;   // eigener Wärmepumpenzähler
            if (Utilities::roleOf('strom', $m) !== 'household' || !empty($m['parent_meter_id'])) continue;
            $gid = (string)($m['meter_group_id'] ?? '');
            $units[$gid !== '' ? "g:$gid" : 'm:' . (string)($m['id'] ?? '')] = true;
        }
        if (count($units) !== 1) return false;
        $gid = (string)($meter['meter_group_id'] ?? '');
        return isset($units[$gid !== '' ? "g:$gid" : 'm:' . (string)($meter['id'] ?? '')]);
    }
}
