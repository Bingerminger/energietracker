<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * v3.13.0 (Paket speicher-auto) — Speicher und Wallbox, die mitten im Verlauf dazukommen (Konzept §4.3, §4.4).
 *
 * Die Prognose eines Zählers folgt dem Saisonprofil seiner gemessenen Monate. Kommt ein Speicher oder eine
 * Wallbox dazu, beschreiben die Monate davor einen anderen Haushalt: Bezug und Einspeisung liegen nach dem
 * Speicher zu hoch, der Zähler vor der Wallbox zu niedrig, bis genug neue Monate da sind (beim Median aus drei
 * Jahren bis zu zwei Jahre lang). Gerechnet wird auf einer Kopie der Monatszeilen nur für die Prognose
 * (ConsumptionService::deviceArrival); Monatszeilen, Auswertung, Jahresbericht und `historical` bleiben gemessen.
 *
 *   Speicher (§4.3 B)  Monate vor dem Einbau: Bezug − Entladung, Einspeisung − Ladung desselben Kalendermonats
 *                      (gemessen am Speicherzähler, Mittel je Tag über die Jahre). Für Kalendermonate, die der
 *                      Speicher noch nicht gemessen hat, die Tagesrate aller gemessenen Monate (Annahme: ein
 *                      Speicher lädt und entlädt etwa einmal am Tag, im Sommer begrenzt durch den Bedarf in der
 *                      Nacht, im Winter durch den Überschuss). Nie unter null. Erst ab MIN_MONTHS gemessenen
 *                      Monaten des Zählers dieser Seite (Ladung für die Einspeisung, Entladung für den Bezug); sonst
 *                      Hinweis mit Vorschlag einer Zäsur (§4.3 A). Hinweise nur HINT_YEARS Jahre ab dem Einbau.
 *   Wallbox (§4.4 A)   Monate des Elternzählers vor dem Einbau bekommen den Netzbezug der Wallbox desselben
 *                      Kalendermonats dazu. Das ist „Rest (Eltern − Wallbox) + Prognose der Wallbox“ für
 *                      Monate, in denen es die Wallbox noch nicht gab: Rest = Eltern. Netzbezug = was die Wallbox
 *                      misst, ohne den Sonnenanteil der Ladevorgänge aus evcc (`ev_sessions.json`, `solar_pct`);
 *                      ohne PV die ganze Menge, mit PV und ohne Ladevorgänge keine Bereinigung (Hinweis).
 *   Wallbox (§4.4 B)   Kalendermonate ohne eigene gemessene Monate der Wallbox: aus den Ladevorgängen dieses
 *                      Kalendermonats (evcc, wenn der Zähler dort nur verteilt ist), sonst aus der Annahme
 *                      EV_PER_KELVIN unter EV_TEMP_REF (Heizgradtage des Klimanormals) statt des Mittels aller
 *                      Monate (`filled`). Den Sonnenanteil eines solchen Monats schätzt §4.4 A aus der Erzeugung
 *                      desselben Monats (Anteil der Sonne am Laden je kWh Erzeugung in den gemessenen Monaten).
 *
 * Welche Zähler: Einspeisung jeder Zähler der PV-Einspeisung; Bezug die Stromzähler am Netzanschluss (ohne
 * Elternzähler, ohne eigene Geräterolle oder mit Subzählern, etwa vorn in der Kaskade) und der Zähler, hinter dem
 * die PV sitzt (`cascade_meter_id`, mit seinen Zählwerken). HT/NT: Die Entladung teilt sich auf die Zählwerke nach
 * ihrem Anteil am Bezug des Monats; die Wallbox zählt an dem Zählwerk, an dem sie hängt. Elternzähler der Wallbox:
 * ihr Elternzähler und, in der Kaskade mit einer Wärmepumpe vorn, auch der Haushaltszähler dahinter (dieselbe
 * Lesart wie der Rest der Wärmepumpe seit Paket waermepumpe: Rest = vorn − Haushalt, die Wallbox steckt im Haushalt;
 * ein Subzähler darf keine Subzähler haben, darum hängt sie am vorderen Zähler). Nichts geschieht bei einer wirksamen
 * Zäsur ab dem Einbau (die Monate davor zählen dann ohnehin nicht) und, solange die Wärmepumpe nach dem Wetter rechnet,
 * auf deren Zeilen (der Rest der Kaskade enthält weder Speicher noch Wallbox).
 *
 * Schalter je Baustein (wie HeatPumpWeather, PvWeatherModel). Torregel, festgelegt am 2026-11-12 vor dem ersten
 * abgestimmten Lauf und vor jedem Blick auf A/V2, A/V3 (abgestimmt nur an A/V1; Rückrechnung
 * tools/rueckrechnung.php --block battery,wallbox --baseline grundlinie.json, Grenzen aus schwellen.json):
 *   BATTERY_IMPORT  an, wenn „Speicher neu: Bezug im Jahr nach dem Einbau ≤ 0,6 × alt“ (Schnitte battery_year,
 *                   2023-10 bis 2024-07, vorab) an A/V1, A/V2 und A/V3 erfüllt ist.
 *   BATTERY_FEED    an, wenn „Speicher neu: Einspeisung im Jahr nach dem Einbau ≤ 0,6 × alt“ dort erfüllt ist.
 *   EV_PARENT       an, wenn „Wallbox neu: Elternzähler im Jahr nach dem Einbau ≤ 0,6 × alt“ (Schnitte
 *                   wallbox_year, 2024-10 bis 2025-06, vorab) an A/V1, A/V2 und A/V3 erfüllt ist und die
 *                   Winter-WAPE der Wallbox dabei höchstens 30 % bleibt.
 *   EV_MONTHS       an, wenn „Wallbox neu: Wintermonate der Wallbox WAPE ≤ 30 %“ an A/V1, A/V2 und A/V3 erfüllt
 *                   ist und an keinem dieser Läufe schlechter als alt.
 *   „alt“ ist die Grundlinie von Paket A (grundlinie.json), wie in der Rückrechnung. Liefert ein Lauf für eine Zeile
 *   weder alt noch neu einen Wert (der Baustein rechnet dort gar nicht), zählt er für diese Zeile nicht; hat alt einen
 *   Wert und neu keinen, ist sie nicht erfüllt. Dazu als Wache: Kein anderer Baustein der Rückrechnung (Wärmepumpe,
 *   PV, Einspeisung, Saldo, HT/NT) wird an A/V1 bis A/V3 mit dem Schalter an um mehr als 1 Punkt bzw. 5 € schlechter
 *   als die Basis des Pakets (e8d77d90); die HT/NT-Schwellen halten.
 *   Stand A/V1 beim Einfrieren (vorab, alt = Grundlinie): Einspeisung 38,57 → 15,62 % (Grenze 23,14, erfüllt);
 *   Bezug 22,41 → 35,14 % (Grenze 13,45, nicht erfüllt: Die Bereinigung senkt alle Schnitte, die späten lagen aber
 *   schon zu tief, weil im Fenster die Wallbox ab 06/2024 und der kalte Winter liegen, die am Schnitt niemand kennt;
 *   vorn in der Kaskade ersetzt die Entladung den Bezug auch nicht ganz, der Speicher nimmt der Wärmepumpe Sonne);
 *   Elternzähler 8,89 → 27,0 % mit Speicher-Bereinigung am Haushalt bzw. 36,7 % ohne (Grenze 5,33, nicht erfüllt:
 *   Der Winter der Wallbox liegt über der Annahme, und ohne Bereinigung des Speichers liegt der Haushalt vor 07/2023 zu
 *   hoch); Winter-WAPE der Wallbox 21,36 → 19,47 % (erfüllt). Darum BATTERY_IMPORT und EV_PARENT aus; BATTERY_FEED
 *   und EV_MONTHS hängen an A/V2 und A/V3. Die Wache an A/V1 hält (nur Einspeisung und Wallbox-Zähler bewegen sich).
 *   Urteil an A/V2 und A/V3 (nach dem Einfrieren, am Modell danach nichts geändert): Einspeisung 40,90 → 19,30 %
 *   (Grenze 24,54) und 35,95 → 15,06 % (21,57), erfüllt; Winter-WAPE der Wallbox A/V2 ohne Wert (alt und neu: die
 *   Wallbox hat dort zu wenige Ablesungen für eine Prognose), A/V3 22,09 → 20,68 %, erfüllt und nicht schlechter.
 *   Wache: an A/V1, A/V2, A/V3 und B/V1 bewegen sich nur Einspeisung und Wallbox-Zähler. Darum BATTERY_FEED und
 *   EV_MONTHS an. Alle Zahlen in umsetzung-j-speicher-auto.md.
 */
final class DeviceArrival
{
    /** Speicher: Bezug der Monate vor dem Einbau um die Entladung bereinigen. */
    public const BATTERY_IMPORT_ENABLED = false;
    /** Speicher: Einspeisung der Monate vor dem Einbau um die Ladung bereinigen. */
    public const BATTERY_FEED_ENABLED = true;
    /** Wallbox: Elternzähler aus Bausteinen (Monate vor dem Einbau + Netzbezug der Wallbox). */
    public const EV_PARENT_ENABLED = false;
    /** Wallbox: Kalendermonate ohne Vorgeschichte aus Ladevorgängen bzw. der Annahme statt des Mittels. */
    public const EV_MONTHS_ENABLED = true;

    /** Mindestens so viele gemessene Monate des Geräts nach dem Einbau (Konzept §3: „mindestens 3 Monate“). */
    public const MIN_MONTHS = 3;

    /**
     * So viele Jahre nach dem Einbau zeigt die Prognose die Hinweise (Konzept S8: beim Median aus drei Jahren prägen die
     * Monate davor die Prognose bis zu zwei Jahre lang). Danach überwiegen die neuen Monate; eine Bereinigung wirkt
     * weiter, der Hinweis bliebe sonst für immer stehen (Lektion 93: ein neuer Hinweis braucht ein Fenster).
     */
    public const HINT_YEARS = 2;

    /** Zeigt die Prognose am Stichtag $asOf noch den Hinweis zu einem Gerät, das am $since dazukam? */
    public static function hintShown(string $since, string $asOf): bool
    {
        return $asOf < date('Y-m-d', strtotime("$since +" . self::HINT_YEARS . ' years'));
    }

    /**
     * Annahme für Monate ohne Vorgeschichte der Wallbox (§4.4 B): Unter EV_TEMP_REF braucht ein E-Auto je Kelvin
     * EV_PER_KELVIN mehr Strom. Quelle: Lee, Song, Lim, Park (2024), „Energy consumption evaluation of passenger
     * electric vehicle based on ambient temperature under real-world driving conditions“, Energy Conversion and
     * Management 306, 118289 (doi:10.1016/j.enconman.2024.118289): bei −15 °C 35,4 % mehr Energie als bei 24 °C,
     * am sparsamsten zwischen 20 und 30 °C; 35,4 % über 35 Kelvin (−15 bis 20 °C) sind rund 1 % je Kelvin.
     * Die Fahrleistung gilt als gleich (Urlaub, Pendeln kennt die App nicht).
     */
    public const EV_TEMP_REF = 20.0;
    public const EV_PER_KELVIN = 0.01;

    /** Anteil eines Monats vor dem Tag $since (1 = ganz davor, 0 = ab dem Ersten schon mit Gerät). */
    public static function preShare(string $ym, string $since): float
    {
        $sinceYm = substr($since, 0, 7);
        if ($ym < $sinceYm) return 1.0;
        if ($ym > $sinceYm) return 0.0;
        $dim = (int)date('t', strtotime("$ym-01"));
        return max(0.0, min(1.0, ((int)substr($since, 8, 2) - 1) / $dim));
    }

    /**
     * Tagesraten je Kalendermonat aus Monatszeilen mit mindestens $minDays Tagen.
     *
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):?float $value Menge einer Zeile (null = Zeile zählt nicht)
     * @return array{by_month: array<int,list<float>>, kwh: float, days: int, n: int}
     */
    public static function rates(array $rows, int $minDays, callable $value): array
    {
        $by = []; $kwh = 0.0; $days = 0; $n = 0;
        foreach ($rows as $r) {
            $d = (int)($r['days'] ?? 0);
            if ($d < $minDays) continue;
            $v = $value($r);
            if ($v === null) continue;
            $by[(int)$r['month']][] = $v / $d;
            $kwh += $v; $days += $d; $n++;
        }
        return ['by_month' => $by, 'kwh' => $kwh, 'days' => $days, 'n' => $n];
    }

    /** Mittlere Tagesrate des Kalendermonats, sonst null. */
    public static function monthRate(array $rates, int $month): ?float
    {
        $l = $rates['by_month'][$month] ?? [];
        return $l !== [] ? array_sum($l) / count($l) : null;
    }

    /** Mittlere Tagesrate aller gemessenen Monate, sonst null. */
    public static function flatRate(array $rates): ?float
    {
        return $rates['days'] > 0 ? $rates['kwh'] / $rates['days'] : null;
    }

    /**
     * Ladung oder Entladung, die einen Monat vor dem Einbau bereinigt: Tagesrate desselben Kalendermonats (sonst
     * aller gemessenen Monate) × Tage der Zeile × Anteil vor dem Einbau.
     */
    public static function batteryAmount(array $rates, array $row, string $since): float
    {
        $share = self::preShare((string)$row['ym'], $since);
        if ($share <= 0.0) return 0.0;
        $rate = self::monthRate($rates, (int)$row['month']) ?? self::flatRate($rates);
        return $rate !== null ? $rate * (int)($row['days'] ?? 0) * $share : 0.0;
    }

    /** Faktor der Annahme (§4.4 B) für einen Monat mit $hdd Gradtagen unter EV_TEMP_REF und $days Tagen. */
    public static function evFactor(?float $hdd, int $days): float
    {
        return $hdd !== null && $days > 0 ? 1.0 + self::EV_PER_KELVIN * $hdd / $days : 1.0;
    }
}
