<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;

/**
 * Tagesverbrauch und Tankbestand für lieferungsbasierte Zähler (Heizöl,
 * Pellets).
 *
 * Wurde in v1.4.4 aus ConsumptionService extrahiert. Benötigte
 * Abhängigkeiten bewusst minimal (JsonStore + Settings), damit kein Zirkel
 * mit ConsumptionService entsteht; das Klimanormal legt der Service bei
 * Bedarf selbst an.
 *
 * v2.10.0 — Tankbuch (Review CALC-04, CALC-25): **eine** Rechnung für
 * Verbrauch, Kosten und Bestandskurve ({@see tankModel()}). Bis v2.9 gab es
 * zwei: Die Kosten verteilten Anfangsbestand plus alle Lieferungen bis heute
 * (Endbestand 0), die Bestandskurve rechnete mit einer kalibrierten Rate.
 * Folge: Eine Lieferung von heute erhöhte den Verbrauch aller Vorjahre, und
 * die Bilanz sagte „Tank leer", während die Kurve 1.466 L zeigte.
 */
final class DeliveryConsumptionService
{
    /** Mindestzahl Tage, ab der geschlossene Intervalle die Rate bestimmen. */
    private const MIN_CALIBRATION_DAYS = 14;

    private ?ClimateNormalService $climate;
    /** @var array<string,array<string,mixed>> je Anfrage einmal rechnen */
    private array $memo = [];

    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
        ?ClimateNormalService $climate = null,
    ) {
        $this->climate = $climate;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Tankbuch
    // ─────────────────────────────────────────────────────────────────────

    /**
     * v2.10.0 — Tankbuch: Verbrauch, Bestand und Kosten je Tag.
     *
     * Konvention: Der Bestand „am Tag D" meint den Stand zu Beginn des Tages,
     * nach den Lieferungen dieses Tages und vor seinem Verbrauch.
     *
     * **Stützstellen** — Tage mit bekanntem Bestand:
     *   - der Starttag mit `initial_stock` (plus Lieferungen desselben Tages),
     *   - eine Lieferung mit `fill_to_full` (danach = `capacity`),
     *   - ein Peilstand aus `tank_levels` am Zähler.
     *
     * Zwischen zwei Stützstellen i → j ist der Verbrauch **bekannt**:
     *
     *   C = Bestand_i + Σ Lieferungen (i < Tag ≤ j) − Bestand_j
     *
     * Er wird nach der Form w(Tag) = ρ + HGT(Tag) auf die Tage verteilt
     * (ρ: Grundlast in HGT-Einheiten, s. {@see shapeRho()}). Nach der letzten
     * Stützstelle rechnet die kalibrierte Rate r × w(Tag) weiter — als
     * **geschätzt** gekennzeichnet. Die Rate stammt, in dieser Reihenfolge,
     *   1. aus den geschlossenen Intervallen (ΣC / Σw, ab 14 Tagen),
     *   2. aus der Lieferkadenz: Was vor der letzten Lieferung geliefert
     *      wurde, ist zwischen erster und letzter Lieferung verbraucht,
     *   3. bei genau einer Lieferung aus der Annahme, dass der Anfangsbestand
     *      bis zu ihr verbraucht war,
     * sonst gibt es keine Rate: Ohne Lieferung oder Peilstand lässt sich kein
     * Verbrauch rechnen (Warnung `no_calibration`, Verbrauch 0).
     *
     * Fehlen Temperaturen, füllt das Klimanormal (mittleres Tagesmittel des
     * Kalendertags) die Lücke, sonst das Mittel der bekannten Tage desselben
     * Monats; liegen für weniger als die Hälfte der Tage Werte vor, wird flach
     * verteilt.
     *
     * **Kosten:** gleitender Durchschnittspreis des Tankinhalts — eine
     * Lieferung mischt sich mit ihrem Preis unter den Bestand, verbraucht wird
     * zum Durchschnitt. Der Anfangsbestand kostet `initial_stock_price_ct`
     * (ct je Einheit) oder, wenn nicht gepflegt, den Preis der ersten
     * Lieferung. Bis v2.9 war er kostenlos, und jeder Tag trug den Preis der
     * letzten Lieferung.
     *
     * @return array{
     *   days: array<string, array{draw: float, stock: float, delivery: float, estimated: bool, price_ct: ?float, cost_eur: ?float}>,
     *   anchors: array<int, array{date: string, kind: string, stock: float}>,
     *   estimated_from: ?string,
     *   calibration: array{source: string, base_per_day: float, per_hdd: float, flat: bool},
     *   warnings: array<int, array<string,mixed>>,
     *   hdd_filled_days: int
     * }
     */
    public function tankModel(string $utility, array $meter, ?string $today = null): array
    {
        if (!Utilities::isDelivery($utility)) {
            throw new \InvalidArgumentException('tankModel nur für Delivery-Utilities, nicht für ' . $utility);
        }
        $today = $today ?? date('Y-m-d');
        $memoKey = $utility . '|' . md5(serialize($meter)) . '|' . $today . '|' . $this->store->generation();
        if (isset($this->memo[$memoKey])) return $this->memo[$memoKey];

        $result = [
            'days' => [], 'anchors' => [], 'estimated_from' => null,
            'calibration' => ['source' => 'none', 'base_per_day' => 0.0, 'per_hdd' => 0.0, 'flat' => false],
            'warnings' => [], 'hdd_filled_days' => 0,
        ];
        $start = $this->deliveryMeterStartDate($meter);
        if ($start > $today) return $this->memo[$memoKey] = $result;

        $baseShare = max(0.0, min(1.0, (float)$this->settings->get('delivery_baseload_share', 0.15)));
        $hddBase   = (float)$this->settings->get('hdd_base_temp', 15.0);
        $capacity  = (float)($meter['capacity'] ?? 0.0);
        $initial   = max(0.0, (float)($meter['initial_stock'] ?? 0.0));

        // ── Lieferungen im Fenster [Start, heute] ──
        $deliveries = $this->deliveriesFor($utility, $meter, $start, $today);
        $qByDate = [];
        $fullDates = [];
        foreach ($deliveries as $d) {
            $qByDate[$d['date']] = ($qByDate[$d['date']] ?? 0.0) + (float)$d['quantity'];
            if (!empty($d['fill_to_full']) && $capacity > 0) $fullDates[$d['date']] = true;
        }

        // ── Tage und Gradtage ──
        $dates = [];
        for ($c = new \DateTimeImmutable($start), $e = new \DateTimeImmutable($today); $c <= $e; $c = $c->modify('+1 day')) {
            $dates[] = $c->format('Y-m-d');
        }
        $temps = $this->store->read('temperatures.json', []);
        if (!is_array($temps)) $temps = [];
        [$hdd, $filled, $flat] = $this->hddSeries($dates, $hddBase, $temps);
        $result['hdd_filled_days'] = $filled;
        $rho = $flat ? 0.0 : $this->shapeRho($baseShare, $hddBase, $temps);
        $flat = $flat || $baseShare >= 0.999;
        $w = [];
        foreach ($dates as $d) $w[$d] = $flat ? 1.0 : $rho + $hdd[$d];

        // ── Stützstellen ──
        $anchors = [$start => ['kind' => 'start', 'stock' => $initial + ($qByDate[$start] ?? 0.0)]];
        foreach (array_keys($fullDates) as $d) {
            $anchors[$d] = ['kind' => 'full', 'stock' => $capacity];
        }
        foreach ($this->tankLevels($meter) as $lv) {
            if ($lv['date'] < $start || $lv['date'] > $today) continue;
            $anchors[$lv['date']] = ['kind' => 'level', 'stock' => $lv['level']];
        }
        ksort($anchors);
        $anchorDates = array_keys($anchors);

        // ── Geschlossene Intervalle: bekannter Verbrauch ──
        $idx = array_flip($dates);
        $intervals = [];
        $tolerance = max(1.0, 0.01 * $capacity);
        for ($k = 0; $k + 1 < count($anchorDates); $k++) {
            $a = $anchorDates[$k];
            $b = $anchorDates[$k + 1];
            $delivered = 0.0;
            foreach ($qByDate as $d => $q) {
                if ($d > $a && $d <= $b) $delivered += $q;
            }
            $cons = $anchors[$a]['stock'] + $delivered - $anchors[$b]['stock'];
            $days = array_slice($dates, $idx[$a], $idx[$b] - $idx[$a]);
            if ($cons < -$tolerance) {
                $result['warnings'][] = [
                    'code' => 'inconsistent_level', 'from' => $a, 'to' => $b,
                    'excess' => round(-$cons, 1),
                ];
            }
            $intervals[] = ['from' => $a, 'to' => $b, 'days' => $days, 'cons' => max(0.0, $cons), 'valid' => $cons >= -$tolerance];
        }

        // ── Rate kalibrieren ──
        $rate = null;
        $source = 'none';
        $calDays = 0; $calCons = 0.0; $calW = 0.0;
        foreach ($intervals as $iv) {
            if (!$iv['valid']) continue;
            $calDays += count($iv['days']);
            $calCons += $iv['cons'];
            foreach ($iv['days'] as $d) $calW += $w[$d];
        }
        if ($calDays >= self::MIN_CALIBRATION_DAYS && $calW > 0) {
            $rate = $calCons / $calW;
            $source = 'anchors';
        } else {
            $deliveryDates = array_values(array_unique(array_column($deliveries, 'date')));
            if (count($deliveryDates) >= 2) {
                // Kadenz: alles außer der letzten Lieferung ist zwischen der
                // ersten und der letzten verbraucht (Tank pendelt um ein Niveau)
                $first = $deliveryDates[0];
                $last  = $deliveryDates[count($deliveryDates) - 1];
                $consumed = 0.0;
                foreach ($qByDate as $d => $q) if ($d < $last) $consumed += $q;
                $sumW = 0.0; $n = 0;
                foreach ($dates as $d) if ($d >= $first && $d < $last) { $sumW += $w[$d]; $n++; }
                if ($n >= self::MIN_CALIBRATION_DAYS && $sumW > 0 && $consumed > 0) {
                    $rate = $consumed / $sumW;
                    $source = 'deliveries';
                }
            }
            if ($rate === null && count($deliveryDates) === 1 && $deliveryDates[0] > $start && $initial > 0) {
                // Eine Lieferung: Der Anfangsbestand war bis zu ihr verbraucht
                $sumW = 0.0; $n = 0;
                foreach ($dates as $d) if ($d < $deliveryDates[0]) { $sumW += $w[$d]; $n++; }
                if ($n >= self::MIN_CALIBRATION_DAYS && $sumW > 0) {
                    $rate = $initial / $sumW;
                    $source = 'first_delivery';
                }
            }
        }
        if ($rate === null) {
            $result['warnings'][] = ['code' => 'no_calibration'];
        }
        if ($flat) {
            $result['warnings'][] = ['code' => 'flat_no_temperatures'];
        }
        $result['calibration'] = [
            'source'       => $source,
            'base_per_day' => round(($rate ?? 0.0) * ($flat ? 1.0 : $rho), 4),
            'per_hdd'      => round($flat ? 0.0 : ($rate ?? 0.0), 5),
            'flat'         => $flat,
        ];

        // ── Tagesverbrauch ──
        $draw = array_fill_keys($dates, 0.0);
        $estimated = array_fill_keys($dates, false);
        foreach ($intervals as $iv) {
            $sumW = 0.0;
            foreach ($iv['days'] as $d) $sumW += $w[$d];
            $n = count($iv['days']);
            foreach ($iv['days'] as $d) {
                $draw[$d] = $sumW > 0 ? $iv['cons'] * $w[$d] / $sumW : ($n > 0 ? $iv['cons'] / $n : 0.0);
            }
        }
        $lastAnchor = $anchorDates[count($anchorDates) - 1];
        foreach ($dates as $d) {
            if ($d < $lastAnchor) continue;
            $draw[$d] = $rate !== null ? $rate * $w[$d] : 0.0;
            $estimated[$d] = true;
        }
        $result['estimated_from'] = $lastAnchor;

        // ── Bestand und Kosten ──
        $avg = $this->initialPrice($meter, $deliveries);
        $stock = 0.0;              // Ende des Vortags (modelliert, ≥ 0 für die Preisführung)
        $exhaustedWarned = false;
        $byDate = [];
        foreach ($deliveries as $d) $byDate[$d['date']][] = $d;
        foreach ($dates as $i => $d) {
            // Anfangsbestand zum Preis p0 in den Tank legen
            if ($i === 0) $stock = $initial;
            foreach ($byDate[$d] ?? [] as $dl) {
                $q = (float)$dl['quantity'];
                $p = $this->deliveryPrice($dl);
                if ($p !== null) {
                    $avg = ($stock + $q) > 0 && $avg !== null
                        ? ($stock * $avg + $q * $p) / ($stock + $q)
                        : $p;
                }
                $stock += $q;
            }
            // Stützstelle: bekannter Bestand ersetzt den mitgeführten
            if (isset($anchors[$d])) $stock = $anchors[$d]['stock'];
            $end = $stock - $draw[$d];
            if ($end < -$tolerance && $estimated[$d] && !$exhaustedWarned) {
                $result['warnings'][] = ['code' => 'stock_exhausted', 'date' => $d];
                $exhaustedWarned = true;
            }
            $result['days'][$d] = [
                'draw'      => round($draw[$d], 6),
                'stock'     => round(max(0.0, $end), 2),
                'delivery'  => round($qByDate[$d] ?? 0.0, 2),
                'estimated' => $estimated[$d],
                'price_ct'  => $avg !== null ? round($avg, 4) : null,
                'cost_eur'  => $avg !== null ? $draw[$d] * $avg / 100.0 : null,
            ];
            $stock = max(0.0, $end);
        }

        foreach ($anchors as $d => $a) {
            $result['anchors'][] = ['date' => $d, 'kind' => $a['kind'], 'stock' => round($a['stock'], 2)];
        }
        return $this->memo[$memoKey] = $result;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Abgeleitete Tagesreihen (Schnittstelle seit v1.4.0 unverändert)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Tagesverbrauch in kWh (Tankbuch × Heizwert).
     *
     * @return array<string,float>  date(YYYY-MM-DD) → verbrauch_kwh
     */
    public function dailyDeliveryConsumption(string $utility, array $meter): array
    {
        if (!Utilities::isDelivery($utility)) {
            throw new \InvalidArgumentException(
                'dailyDeliveryConsumption nur für Delivery-Utilities, nicht für ' . $utility
            );
        }
        $kwhPerUnit = $this->kwhPerUnit($utility);
        $out = [];
        foreach ($this->tankModel($utility, $meter)['days'] as $d => $row) {
            $out[$d] = round($row['draw'] * $kwhPerUnit, 6);
        }
        return $out;
    }

    /**
     * Tagesabzug in Mengeneinheiten (Liter bzw. kg) — dieselbe Reihe wie der
     * Verbrauch, nur ohne Heizwert.
     *
     * @return array<string,float> date → Abzug in Mengeneinheiten/Tag
     */
    public function dailyDeliveryStockDraw(string $utility, array $meter): array
    {
        if (!Utilities::isDelivery($utility)) {
            throw new \InvalidArgumentException(
                'dailyDeliveryStockDraw nur für Delivery-Utilities, nicht für ' . $utility
            );
        }
        return array_map(fn($row) => $row['draw'], $this->tankModel($utility, $meter)['days']);
    }

    /** Heizwert je Mengeneinheit (kWh/L bzw. kWh/kg) aus den Einstellungen. */
    public function kwhPerUnit(string $utility): float
    {
        $convSetting = (string)(Utilities::get($utility)['conversion_setting'] ?? '');
        return $convSetting !== '' ? (float)$this->settings->get($convSetting, 1.0) : 1.0;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Interne Hilfsmethoden
    // ─────────────────────────────────────────────────────────────────────

    /** Start-Datum eines Delivery-Meters — installed_on des aktiven Devices. */
    public function deliveryMeterStartDate(array $meter): string
    {
        foreach ($meter['devices'] ?? [] as $dev) {
            if (empty($dev['removed_on']) && !empty($dev['installed_on'])) {
                return (string)$dev['installed_on'];
            }
        }
        return (string)($meter['created_at'] ?? date('Y-m-d'));
    }

    /**
     * Echte Lieferungen des Zählers im Fenster, nach Datum sortiert. Geplante
     * zählen nicht, künftige ebenso wenig; Lieferungen vor dem Start stecken
     * im Anfangsbestand.
     *
     * @return array<int,array<string,mixed>>
     */
    private function deliveriesFor(string $utility, array $meter, string $start, string $today): array
    {
        $all = $this->store->read("$utility/deliveries.json", []);
        if (!is_array($all)) $all = [];
        $out = array_values(array_filter(
            $all,
            fn($d) => is_array($d)
                  && ($d['meter_id'] ?? null) === ($meter['id'] ?? null)
                  && empty($d['is_planned'])
                  && !empty($d['date'])
                  && (string)$d['date'] >= $start
                  && (string)$d['date'] <= $today
                  && (float)($d['quantity'] ?? 0) > 0
        ));
        usort($out, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
        return $out;
    }

    /**
     * Peilstände des Zählers, tolerant gelesen (Backup, Altbestand).
     *
     * @return array<int,array{date:string,level:float}>
     */
    private function tankLevels(array $meter): array
    {
        $out = [];
        foreach ((array)($meter['tank_levels'] ?? []) as $lv) {
            if (!is_array($lv) || empty($lv['date']) || !is_numeric($lv['level'] ?? null)) continue;
            $out[] = ['date' => (string)$lv['date'], 'level' => max(0.0, (float)$lv['level'])];
        }
        return $out;
    }

    /** Effektiver Stückpreis einer Lieferung in ct je Einheit (Rechnungsbetrag hat Vorrang). */
    private function deliveryPrice(array $d): ?float
    {
        $q = (float)($d['quantity'] ?? 0);
        if (isset($d['total_eur']) && $d['total_eur'] !== null && is_numeric($d['total_eur']) && $q > 0) {
            return (float)$d['total_eur'] * 100.0 / $q;
        }
        if (isset($d['unit_price_cents']) && $d['unit_price_cents'] !== null && is_numeric($d['unit_price_cents'])) {
            return (float)$d['unit_price_cents'];
        }
        return null;
    }

    /** Preis des Anfangsbestands: gepflegt, sonst der der ersten Lieferung mit Preis. */
    private function initialPrice(array $meter, array $deliveries): ?float
    {
        $p = $meter['initial_stock_price_ct'] ?? null;
        if ($p !== null && $p !== '' && is_numeric($p) && (float)$p >= 0) return (float)$p;
        foreach ($deliveries as $d) {
            $price = $this->deliveryPrice($d);
            if ($price !== null) return $price;
        }
        return null;
    }

    /**
     * Gradtage je Tag. Fehlende Tage: Klimanormal, sonst Mittel der bekannten
     * Tage desselben Kalendermonats; liegen für weniger als die Hälfte der
     * Tage Werte vor, wird flach verteilt.
     *
     * @param array<int,string> $dates
     * @param array<string,mixed> $temps  temperatures.json
     * @return array{0: array<string,float>, 1: int, 2: bool}  [HGT je Tag, per Klimanormal gefüllte Tage, flach]
     */
    private function hddSeries(array $dates, float $hddBase, array $temps): array
    {
        $hdd = [];
        $known = 0; $filled = 0;
        foreach ($dates as $d) {
            $t = $temps[$d] ?? null;
            $avg = (is_array($t) && isset($t['avg']) && is_numeric($t['avg'])) ? (float)$t['avg'] : null;
            if ($avg !== null) { $hdd[$d] = max(0.0, $hddBase - $avg); $known++; continue; }
            $normal = $this->climate()->dayAvg($d);
            if ($normal !== null) { $hdd[$d] = max(0.0, $hddBase - $normal); $filled++; continue; }
            $hdd[$d] = null;
        }
        $missing = count($dates) - $known - $filled;
        if ($missing === 0) return [$hdd, $filled, false];
        if ($known + $filled < 0.5 * count($dates)) {
            return [array_fill_keys($dates, 0.0), $filled, true];
        }
        $byMonth = []; $all = [];
        foreach ($hdd as $d => $v) {
            if ($v === null) continue;
            $byMonth[(int)substr($d, 5, 2)][] = $v;
            $all[] = $v;
        }
        $mean = fn(array $xs) => array_sum($xs) / count($xs);
        foreach ($hdd as $d => $v) {
            if ($v !== null) continue;
            $m = (int)substr($d, 5, 2);
            $hdd[$d] = isset($byMonth[$m]) ? $mean($byMonth[$m]) : $mean($all);
        }
        return [$hdd, $filled, false];
    }

    /**
     * Grundlast in HGT-Einheiten: Bei einem Grundlastanteil s am Jahr gilt
     * b × 365 = s × A und r × HGT_Jahr = (1 − s) × A, also
     * ρ = b / r = s × HGT_Jahr / ((1 − s) × 365). Die Tagesform ρ + HGT(Tag)
     * ist damit unabhängig davon, ob ein Intervall im Sommer oder im Winter
     * liegt — ein Sommerintervall bekommt vor allem Grundlast, nicht 85 % auf
     * die wenigen kühlen Tage.
     */
    private function shapeRho(float $baseShare, float $hddBase, array $temps): float
    {
        if ($baseShare <= 0.0) return 0.0;
        $year = $this->annualHdd($hddBase, $temps);
        if ($year <= 0) return 1.0;
        return $baseShare * $year / ((1.0 - min(0.999, $baseShare)) * 365.25);
    }

    /**
     * Heizgradtage eines Normaljahrs am Standort: aus dem Klimanormal, sonst
     * aus der eigenen Temperaturhistorie, wenn sie alle zwölf Monate abdeckt,
     * sonst aus einem groben mitteleuropäischen Monatsmittel.
     *
     * Nie aus dem Fenster des Tanks allein: Wer im Mai anfängt, hätte sonst
     * ein „Jahr" mit 25 Gradtagen, ρ ginge gegen 0, und die im Sommer
     * kalibrierte Rate würde im Herbst zu Hunderten Litern am Tag.
     *
     * @param array<string,mixed> $temps
     */
    private function annualHdd(float $hddBase, array $temps): float
    {
        $year = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $n = $this->climate()->hddForMonth($m, $hddBase);
            if ($n === null) { $year = 0.0; break; }
            $year += (float)$n['mean'];
        }
        if ($year > 0) return $year;

        $days = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $byMonth = [];
        foreach ($temps as $d => $t) {
            if (!is_array($t) || !isset($t['avg']) || !is_numeric($t['avg'])) continue;
            $byMonth[(int)substr((string)$d, 5, 2)][] = max(0.0, $hddBase - (float)$t['avg']);
        }
        if (count($byMonth) === 12 && min(array_map('count', $byMonth)) >= 15) {
            foreach ($byMonth as $m => $vals) $year += array_sum($vals) / count($vals) * $days[$m - 1];
            return $year;
        }

        // Grobes Monatsmittel der Lufttemperatur, mitteleuropäisch (°C)
        $normal = [1, 2, 5, 9, 13, 16, 18, 18, 14, 9, 5, 2];
        foreach ($normal as $i => $t) $year += max(0.0, $hddBase - $t) * $days[$i];
        return $year;
    }

    private function climate(): ClimateNormalService
    {
        return $this->climate ??= new ClimateNormalService($this->store, $this->settings);
    }
}
