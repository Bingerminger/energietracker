<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v3.12.0 (F1040, Phase 2) — Prognose-Werkstatt für Heizöl und Pellets: reine Bausteine.
 *
 * Die Rechnung selbst steht in ScenarioService::computeDelivery(). Dort kommt der
 * Ausgangsfall aus der Prognose (ForecastService, unverändert), der Heizanteil aus dem
 * Tankbuch (DeliveryConsumptionService::tankModel(), Kalibrierung „je Heizgradtag“ und
 * „je Tag“) und die Kosten aus dem gleitenden Durchschnittspreis des Tanks. Winter,
 * Raumtemperatur und Dämmung rechnen mit derselben Formel wie beim Gas
 * (ScenarioService::quantities()). Hier stehen nur die Teile, die es beim Gas nicht gibt:
 * der Heizungstausch als zweite Rechnung und der Blick auf den Tank.
 *
 * Alle Zahlen sind Modellwerte, keine Messung. Die Annahmen des Heizungstauschs sind
 * Beispielwerte zum Ausprobieren, keine Richtwerte; die Werkstatt nennt sie.
 */
final class ScenarioDelivery
{
    /** Wohin getauscht wird. */
    public const TARGETS = ['heat_pump', 'pellets'];

    /**
     * Genannte Beispielwerte für den Heizungstausch, wenn die Eingabe fehlt.
     *   efficiency_old  Nutzungsgrad des bisherigen Kessels: aus 1 kWh Brennstoff werden 0,9 kWh Wärme
     *   efficiency_new  Nutzungsgrad eines Pelletkessels
     *   jaz             Jahresarbeitszahl der Wärmepumpe, übers Jahr gleich gerechnet
     *   power_ct        Strompreis der Wärmepumpe in ct je kWh
     *   pellet_ct       Pelletpreis in ct je kg
     */
    public const ASSUMED = [
        'efficiency_old' => 0.9,
        'efficiency_new' => 0.9,
        'jaz'            => 3.0,
        'power_ct'       => 30.0,
        'pellet_ct'      => 35.0,
    ];

    /**
     * v3.13.0 (Paket waermepumpe, Konzept §4.6 B) — Vorlauftemperatur nach der Heizkurve beim Heizungstausch: die
     * Wasseraustrittstemperaturen der Teillastpunkte A bis D für Mitteltemperatur-Anwendung (55 °C) im mittleren
     * Klima der DIN EN 14825 (−10 °C: 55 °C, −7: 52, +2: 42, +7: 36, +12: 30), dazwischen linear, außerhalb
     * gehalten. Mitteltemperatur, weil eine Öl- oder Pelletheizung meist an Heizkörpern hängt. Warmwasser mit dem
     * Auslegungswert 55 °C das ganze Jahr. Angenommen wird eine Luft/Wasser-Wärmepumpe (Außenluft als Quelle).
     */
    public const FLOW_CURVE = [[-10.0, 55.0], [-7.0, 52.0], [2.0, 42.0], [7.0, 36.0], [12.0, 30.0]];
    public const HOT_WATER_FLOW = 55.0;

    /**
     * v3.13.0 (Paket waermepumpe, Konzept §4.6 B) — die Monatsform der Arbeitszahl beim Heizungstausch: je Monat die
     * Leistungszahl nach Carnot (T_Vorlauf ÷ (T_Vorlauf − T_außen), in Kelvin) zur Vorlauftemperatur der Heizkurve
     * (FLOW_CURVE) und zur mittleren Außentemperatur des Monats im Klimanormal, für den Heizanteil; für den Rest
     * (Warmwasser) mit HOT_WATER_FLOW. Ein fester Gütegrad fiele bei der Normierung auf die eingegebene JAZ heraus
     * (replacement()): Die Form verteilt nur, die Jahreszahl bleibt die Eingabe. Misst ein Wärmezähler eine
     * Wärmepumpe im Haushalt (HeatPumpService, `by_hdd`), nimmt die Form deren gemessene Kennlinie: Arbeitszahl je
     * Monat linear über die Gradtage je Tag, aus mindestens MEASURED_MIN_POINTS Monaten.
     *
     * @param list<array{ym:string,heat_share:?float,days:int}> $months
     * @param callable(string):?float $dayAvg Tagesmittel des Normals zu einem Datum (ClimateNormalService::dayAvg)
     * @param array{points:list<array{0:float,1:float}>,hdd_per_day:list<float>}|null $measured gemessene Kennlinie:
     *        Punkte [Gradtage je Tag, Arbeitszahl] und je Prognosemonat die Gradtage je Tag des Normals
     * @return array{shape:list<float>,source:string}|null null ohne Normal (dann übers Jahr gleich)
     */
    public static function copShape(array $months, callable $dayAvg, ?array $measured = null): ?array
    {
        if ($measured !== null && count($measured['points']) >= self::MEASURED_MIN_POINTS && count($measured['hdd_per_day']) === count($months)) {
            [$a, $b] = self::line($measured['points']);
            $shape = [];
            foreach ($measured['hdd_per_day'] as $h) $shape[] = $a + $b * (float)$h;
            if (min($shape) > 0.5) return ['shape' => $shape, 'source' => 'measured'];
        }
        $shape = [];
        foreach ($months as $m) {
            $sum = 0.0; $n = 0;
            for ($t = strtotime($m['ym'] . '-01 12:00:00'); date('Y-m', $t) === $m['ym']; $t += 86400) {
                $v = $dayAvg(date('Y-m-d', $t));
                if ($v === null) continue;
                $sum += $v; $n++;
            }
            if ($n === 0) return null;
            $out = $sum / $n;
            $share = $m['heat_share'] ?? null;
            $share = $share === null ? 1.0 : max(0.0, min(1.0, (float)$share));
            // Strom je kWh Wärme: Heizanteil zur Heizkurve, der Rest zum Warmwasser; die Form ist der Kehrwert
            $perHeat = $share / self::carnot(self::flow($out), $out) + (1.0 - $share) / self::carnot(self::HOT_WATER_FLOW, $out);
            $shape[] = 1.0 / $perHeat;
        }
        return ['shape' => $shape, 'source' => 'carnot'];
    }

    /** Mindestzahl gemessener Monate (Arbeitszahl je Monat) für die gemessene Kennlinie. */
    public const MEASURED_MIN_POINTS = 6;

    /** Vorlauf nach FLOW_CURVE bei einer Außentemperatur (°C). */
    public static function flow(float $out): float
    {
        $c = self::FLOW_CURVE;
        if ($out <= $c[0][0]) return $c[0][1];
        for ($i = 1; $i < count($c); $i++) {
            if ($out <= $c[$i][0]) return $c[$i - 1][1] + ($c[$i][1] - $c[$i - 1][1]) * ($out - $c[$i - 1][0]) / ($c[$i][0] - $c[$i - 1][0]);
        }
        return $c[count($c) - 1][1];
    }

    /** Leistungszahl nach Carnot zwischen Außenluft und Vorlauf (°C, gerechnet in Kelvin); mindestens 1. */
    public static function carnot(float $flow, float $out): float
    {
        $lift = max(1.0, $flow - $out);
        return max(1.0, ($flow + 273.15) / $lift);
    }

    /** Gerade y = a + b·x nach kleinsten Quadraten (mindestens zwei verschiedene x, sonst waagerecht). @return array{0:float,1:float} */
    private static function line(array $pts): array
    {
        $n = count($pts);
        $sx = $sy = $sxx = $sxy = 0.0;
        foreach ($pts as [$x, $y]) { $sx += $x; $sy += $y; $sxx += $x * $x; $sxy += $x * $y; }
        $den = $n * $sxx - $sx * $sx;
        if ($n < 2 || abs($den) < 1e-12) return [$n > 0 ? $sy / $n : 0.0, 0.0];
        $b = ($n * $sxy - $sx * $sy) / $den;
        return [($sy - $b * $sx) / $n, $b];
    }

    /** Heizwert je Mengeneinheit (kWh je Liter bzw. kg) aus den Einstellungen, wie DeliveryConsumptionService::kwhPerUnit(). */
    public static function kwhPerUnit(SettingsService $settings, string $utility): float
    {
        $key = (string)(Utilities::get($utility)['conversion_setting'] ?? '');
        $v = $key !== '' ? (float)$settings->get($key, 1.0) : 1.0;
        return $v > 0 ? $v : 1.0;
    }

    /**
     * Heizungstausch als zweite Rechnung über dieselbe Wärme:
     *
     *   Wärme      = Brennstoff (kWh) × Nutzungsgrad alt
     *   Wärmepumpe Strom (kWh) = Wärme ÷ Jahresarbeitszahl,   Kosten = Strom × Strompreis
     *   Pellets    Brennstoff (kWh) = Wärme ÷ Nutzungsgrad neu, kg = kWh ÷ Heizwert je kg,
     *              Kosten = kg × Pelletpreis
     *
     * Je Monat dieselbe Arbeitszahl: Im Winter ist sie in Wirklichkeit kleiner, im Sommer
     * größer. Das ist eine Annahme, die Werkstatt nennt sie.
     *
     * v3.13.0 (Paket waermepumpe, Konzept §4.6 B) — mit `$copShape` (copShape(): relative Arbeitszahl je Monat) nach dem
     * Wetter verteilt: Arbeitszahl des Monats = k × Form, k so, dass die Jahresarbeitszahl (Wärme ÷ Strom über die
     * Monate) genau die eingegebene JAZ ist. Der Strom im Jahr bleibt Wärme ÷ JAZ; im Winter mehr, im Sommer weniger.
     * `jaz_months` nennt die Arbeitszahl je Monat. Ohne Form wie bisher.
     *
     * @param list<float> $fuelKwh Brennstoff je Monat (kWh), wie im Szenario ohne Tausch
     * @param array<string,mixed> $rep Eingabe `replace` (to, Annahmen, leer = Beispielwert)
     * @param list<float>|null $copShape relative Arbeitszahl je Monat (nur Wärmepumpe)
     * @return array{to:string,assumed:array<string,float>,heat:list<float>,energy:list<float>,units:?list<float>,cost:list<float>,jaz_months:?list<float>}
     */
    public static function replacement(array $fuelKwh, array $rep, float $pelletKwhPerKg, ?array $copShape = null): array
    {
        $to = (string)$rep['to'];
        $keys = $to === 'heat_pump' ? ['efficiency_old', 'jaz', 'power_ct'] : ['efficiency_old', 'efficiency_new', 'pellet_ct'];
        $a = [];
        foreach ($keys as $k) $a[$k] = isset($rep[$k]) && $rep[$k] !== null ? (float)$rep[$k] : self::ASSUMED[$k];
        if ($to === 'pellets') $a['kwh_per_kg'] = $pelletKwhPerKg;
        $out = ['to' => $to, 'assumed' => $a, 'heat' => [], 'energy' => [], 'units' => $to === 'pellets' ? [] : null, 'cost' => []];
        // v3.13.0 (§4.6 B) — Arbeitszahl je Monat aus der Form, normiert auf die JAZ (s. o.)
        $jazMonth = null;
        $values = array_values($fuelKwh);
        if ($to === 'heat_pump' && $copShape !== null && count($copShape) === count($values) && min(array_map('floatval', $copShape)) > 0) {
            $shape = array_values(array_map('floatval', $copShape));
            $heatSum = 0.0; $rel = 0.0;
            foreach ($values as $i => $q) { $h = max(0.0, (float)$q) * $a['efficiency_old']; $heatSum += $h; $rel += $h / $shape[$i]; }
            if ($heatSum > 0) {
                $k = $a['jaz'] * $rel / $heatSum;
                $jazMonth = array_map(fn($s) => $k * $s, $shape);
            }
        }
        foreach ($values as $i => $q) {
            $heat = max(0.0, (float)$q) * $a['efficiency_old'];
            if ($to === 'heat_pump') {
                $e = $heat / ($jazMonth[$i] ?? $a['jaz']);
                $cost = $e * $a['power_ct'] / 100.0;
            } else {
                $e = $heat / $a['efficiency_new'];
                $kg = $pelletKwhPerKg > 0 ? $e / $pelletKwhPerKg : 0.0;
                $out['units'][] = $kg;
                $cost = $kg * $a['pellet_ct'] / 100.0;
            }
            $out['heat'][] = $heat;
            $out['energy'][] = $e;
            $out['cost'][] = $cost;
        }
        $out['jaz_months'] = $jazMonth;
        return $out;
    }

    /**
     * Wann ist der Tank leer, wann sollte nachgefüllt werden?
     *
     * Vom Bestand heute an Tag für Tag: im Rest des laufenden Monats mit `$restPerDay`
     * (Liter bzw. kg je Tag), danach je Prognosemonat gleichmäßig mit seiner Menge ÷ Tage.
     * „Nachfüllen am“ ist der erste Tag, an dem der Bestand höchstens `$refillLevel` ist
     * (die Warnschwelle des Tanks, `tank_warn_pct`); liegt er heute schon darunter, heute.
     * „Leer am“ ist der Tag, an dessen Ende der Bestand null erreicht. Null, wenn es im
     * Horizont nicht geschieht. Lieferungen nach heute kennt die Rechnung nicht.
     *
     * @param list<array{ym:string,days:int}> $months
     * @param list<float> $units Menge je Prognosemonat (Liter bzw. kg)
     * @return array{empty_on:?string,refill_on:?string}
     */
    public static function tankOutlook(float $stock, string $today, float $restPerDay, array $months, array $units, ?float $refillLevel): array
    {
        $empty = null;
        $refill = $refillLevel !== null && $stock <= $refillLevel ? $today : null;
        if ($stock <= 0.0) return ['empty_on' => $today, 'refill_on' => $refill];
        $step = function (string $date, float $draw) use (&$stock, &$empty, &$refill, $refillLevel): bool {
            $stock -= max(0.0, $draw);
            if ($refill === null && $refillLevel !== null && $stock <= $refillLevel) $refill = $date;
            if ($stock <= 1e-9) { $empty = $date; return true; }
            return false;
        };
        $monthEnd = date('Y-m-t', (int)strtotime($today));
        for ($d = new \DateTimeImmutable($today . ' +1 day'); $d->format('Y-m-d') <= $monthEnd; $d = $d->modify('+1 day')) {
            if ($step($d->format('Y-m-d'), $restPerDay)) return ['empty_on' => $empty, 'refill_on' => $refill];
        }
        foreach ($months as $i => $m) {
            $days = max(1, (int)$m['days']);
            $perDay = (float)($units[$i] ?? 0.0) / $days;
            for ($k = 1; $k <= $days; $k++) {
                $date = sprintf('%s-%02d', $m['ym'], $k);
                if ($date <= $monthEnd) continue;
                if ($step($date, $perDay)) return ['empty_on' => $empty, 'refill_on' => $refill];
            }
        }
        return ['empty_on' => $empty, 'refill_on' => $refill];
    }
}
