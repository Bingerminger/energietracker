<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * v3.13.0 (Paket waermepumpe) — Wärmepumpe nach dem Wetter: Schalter, Mindestmaß und Konstanten an einer Stelle.
 *
 * Welcher Zähler dem Wetter folgt, sagt UtilityWeather. Gerechnet wird mit demselben Weg wie beim Gas
 * (Konzept §4.1 A): Heizmodell a × HGT + c × Tage (auch über Ablesungsabstände), gemischt mit dem Saisonprofil
 * nach R², Klimanormal statt Wetterglück, Band, Gütesperre, Heizgrenze je Zähler. Es gibt kein zweites Modell;
 * ConsumptionService::heatPumpWeather() legt nur fest, auf welchen Monatszeilen es läuft (weatherSeries():
 * die Menge, die der Vertrag rechnet) und ob es tragen darf.
 *
 * Schalter je Baustein (wie PvWeatherModel, Paket sonne): Ein Baustein, der seine Schwelle am Prüfhaushalt
 * nicht schafft (tests/fixtures/heatpump-household/schwellen.json), geht nicht in die Anzeige; dann rechnet
 * die App wie bis v3.12 und sagt es (`heat_pump_no_weather`, Grund `not_validated`). Die Rückrechnung schaltet
 * je Instanz über ConsumptionService::setHeatPumpWeather().
 *
 * Torregel (strenge Lesart; mit den Konstanten dieser Datei eingefroren, bevor V2, V3 oder Haushalt B angesehen
 * wurden; abgestimmt nur an A/V1; siehe umsetzung-e-waermepumpe.md):
 *   FORECAST  an, wenn an A/V1, A/V2, A/V3 und B/V1 im Urteilsfenster (Schnitte ab 2024-07) jede Zeile der
 *             Wärmepumpe in schwellen.json erfüllt ist: nachgerechnet Jahresfehler ± 3 % und WAPE, vorab Jahresfehler
 *             ≤ 0,8 × alt, nach kaltem und mildem Winter ≤ 0,6 × alt, kein Schnitt mehr als 3 Punkte schlechter,
 *             größter Monatsfehler ≤ 40 % (B: feste Schwellen, relative als „nicht schlechter als alt“).
 *   BAND      an, wenn FORECAST an ist und beide Zeilen des Bands (Monate 65–90 %, Jahr im kalten und im milden
 *             Jahr) überall dort erfüllt sind, wo die Rückrechnung ein Band misst. Sonst zeigt die Prognose kein Band.
 *   SALDO     an, wenn beide Zeilen von „Erwartet bei Abrechnung“ (mittlerer Fehler ≤ 0,8 × alt, Vorzeichen ≥ 90 %)
 *             an A/V1, A/V2, A/V3 und B/V1 erfüllt sind (B: nicht schlechter als alt).
 *   Eine Zeile gilt nur als erfüllt, wenn die Rückrechnung „ja“ sagt: Bleibt ein Lauf unter dem Mindestmaß und
 *   rechnet wie alt, ist eine relative Schwelle (≤ 0,8 × alt) dort nicht erfüllt. Ohne Wert („offen“, etwa kein Fall
 *   mit Ergebnis über 5 % der Jahreskosten) zählt die Zeile nicht.
 *   Stand A/V1 beim Einfrieren: Prognose nicht erfüllt (nachgerechnet 9,3 % statt ± 3 %, WAPE 14,7 %, nach mildem
 *   Winter 26,6 > 23,4 %, größter Monatsfehler vorab 113 %), Band (Jahr) nicht erfüllt, Saldo erfüllt (25,72 € ≤
 *   31,66 €, Vorzeichen 100 %). Darum Prognose und Band aus; der Saldo hing an V2, V3 und B.
 *   Urteil an V2, V3 und B (nach dem Einfrieren, am Modell danach nichts geändert): Saldo A/V2 138,28 € wie alt
 *   (unter dem Mindestmaß, Vorzeichen 62,5 %), A/V3 31,99 € gegen 26,35 € alt (schlechter), B/V1 40,26 € gegen
 *   39,02 € (schlechter). Darum auch der Saldo aus. Alle Zahlen in umsetzung-e-waermepumpe.md.
 */
final class HeatPumpWeather
{
    /** Prognose (Monatsmengen, Jahr) nach dem Wetter: aus, schafft die Schwellen am Prüfhaushalt nicht (s. o.). */
    public const FORECAST_ENABLED = false;
    /** Band der Prognose (Monate und Jahr) aus dem Heizmodell: aus (nur mit der Prognose, Jahr nicht erfüllt). */
    public const BAND_ENABLED = false;
    /** „Erwartet bei Abrechnung“ (Saldo-Karte, Vertragsstatus, Werkstatt-Ausgangsfall) nach dem Wetter: aus (s. o.). */
    public const SALDO_ENABLED = false;

    /**
     * Mindestmaß „darunter Heiz- und Sommermonate“ (Konzept §3): Unter den Punkten des Heizmodells mindestens
     * einer mit so viel Heizbedarf wie ein halber kältester Normalmonat (Gradtage je Tag ≥ HEATING_SHARE × die
     * des kältesten Monats im Klimanormal zur Heizgrenze des Zählers) und einer fast ohne (≤ SUMMER_SHARE ×).
     * Bei 15 °C und rund 15 Gradtagen je Tag im Januar: ein Monat ab etwa 7,5 (Dezember bis März, kalte
     * November) und einer bis etwa 1,5 (Juni bis August). Ohne beide ist a oder c geraten: Ein Modell nur
     * aus Herbst und Winter kennt die Grundlast nicht (Gütesperre ohne Sommer), eins aus dem Sommer die Heizung
     * nicht. Festgelegt aus der Definition, nicht am Prüfhaushalt abgestimmt.
     */
    public const HEATING_SHARE = 0.5;
    public const SUMMER_SHARE = 0.1;

    /**
     * Heizgrenze ohne eigene am Zähler: die des Rasters (ConsumptionService::HEAT_LIMIT_GRID, 6 bis 18 °C), mit der
     * das Heizmodell über die gemessenen Monate am wenigsten streut (Gütesperre, AnomalyService::quality) und die
     * Grundlast-Prüfung besteht; eine eigene Heizgrenze am Zähler (oder an der Gruppe) gilt immer. Beim Gas bleibt
     * es beim Vorschlag (die Heizgrenze 15 °C ist dort die Norm der Gradtagzahl); für den Strom einer Wärmepumpe
     * gibt es keine solche Vorgabe, und 15 °C liegt für Häuser mit Wärmepumpe oft zu hoch (Konzept §4.1, Risiko).
     * In der Kaskade mit PV hinter dem Haushalt deckt die Sonne im Frühjahr und Herbst einen Teil der Wärmepumpe;
     * an A/V1 bestand das Modell mit 15 °C die Gütesperre nie (Streuung 0,21 bis 0,25, Grundlast null).
     */
    public const AUTO_HEAT_LIMIT = true;

    /**
     * Die Prognose rechnet das Heizmodell mit der Kalibrierung am jüngsten Niveau, wie die Saldo-Karte
     * (ConsumptionService::heatPumpCalibration(), volumeEstimator(): Faktor für Grundlast und Heizanteil aus den
     * letzten bis zu sechs gemessenen Monaten des letzten Jahres, 0,5 bis 1,5, nur bei bestandener Gütesperre).
     * So stehen am selben Tag in Prognose, Saldo und Werkstatt dieselben Monatsmengen aus demselben Modell; ein
     * neuer Speicher, eine neue Wallbox oder eine neu eingestellte Heizkurve verschieben das Niveau, bevor sie ein
     * ganzes Jahr prägen. Kein Datum eines Geräts geht ein.
     */
    public const CALIBRATE_FORECAST = true;

    /**
     * v3.13.0 (E2) — Das Normal der Wärmepumpe rechnet mit dem Trend der Temperatur: Heizgradtage der Heizgrenze − ΔT,
     * ΔT = Trend der Jahresmitteltemperatur über die dreißig Jahre des Klimanormals, fortgeschrieben auf das Jahr des
     * Prognosemonats bzw. des geschätzten Tages (ClimateNormalService::temperatureTrend(), tempTrend($jahr)). Entschieden an zehn DWD-Stationen (Zieljahre 2001–2025): Das flache
     * Mittel lag 9 bis 12 % zu kalt, mit dem Trend 2 bis 3 % (docs/entwicklung/messung-temperaturtrend.md). Ohne Trend im Normal das flache Mittel. Gas
     * und alle anderen Arten rechnen weiter mit dem flachen Mittel.
     */
    public const NORMAL_TREND = true;

    /** Gründe, warum die Wärmepumpe beim alten Weg bleibt (`heat_pump_no_weather.reason`). */
    public const REASONS = ['not_validated', 'no_normal', 'few_measured', 'no_heating', 'no_summer'];

    /**
     * Reicht das Heizmodell für eine Prognose nach dem Wetter? Null, wenn ja, sonst der Grund.
     *
     * @param array<string,mixed>|null $model  ConsumptionService::heatModel() (mit `hdd_per_day`)
     * @param array<int,array{mean:float,source:string}> $normals ConsumptionService::hddNormals() zur Heizgrenze
     */
    public static function minimumReason(?array $model, array $normals): ?string
    {
        $fromNormal = array_filter($normals, fn($n) => ($n['source'] ?? '') === 'climate_normal');
        if (count($fromNormal) < 12) return 'no_normal';
        if ($model === null) return 'few_measured';
        $coldest = 0.0;
        foreach ($fromNormal as $mo => $n) $coldest = max($coldest, (float)$n['mean'] / (int)date('t', mktime(0, 0, 0, (int)$mo, 1, 2001)));
        $perDay = array_map('floatval', (array)($model['hdd_per_day'] ?? []));
        if ($perDay === [] || $coldest <= 0.0) return 'few_measured';
        if (max($perDay) < self::HEATING_SHARE * $coldest) return 'no_heating';
        if (min($perDay) > self::SUMMER_SHARE * $coldest) return 'no_summer';
        return null;
    }
}
