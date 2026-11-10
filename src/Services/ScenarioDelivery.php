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
     * @param list<float> $fuelKwh Brennstoff je Monat (kWh), wie im Szenario ohne Tausch
     * @param array<string,mixed> $rep Eingabe `replace` (to, Annahmen, leer = Beispielwert)
     * @return array{to:string,assumed:array<string,float>,heat:list<float>,energy:list<float>,units:?list<float>,cost:list<float>}
     */
    public static function replacement(array $fuelKwh, array $rep, float $pelletKwhPerKg): array
    {
        $to = (string)$rep['to'];
        $keys = $to === 'heat_pump' ? ['efficiency_old', 'jaz', 'power_ct'] : ['efficiency_old', 'efficiency_new', 'pellet_ct'];
        $a = [];
        foreach ($keys as $k) $a[$k] = isset($rep[$k]) && $rep[$k] !== null ? (float)$rep[$k] : self::ASSUMED[$k];
        if ($to === 'pellets') $a['kwh_per_kg'] = $pelletKwhPerKg;
        $out = ['to' => $to, 'assumed' => $a, 'heat' => [], 'energy' => [], 'units' => $to === 'pellets' ? [] : null, 'cost' => []];
        foreach ($fuelKwh as $q) {
            $heat = max(0.0, (float)$q) * $a['efficiency_old'];
            if ($to === 'heat_pump') {
                $e = $heat / $a['jaz'];
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
