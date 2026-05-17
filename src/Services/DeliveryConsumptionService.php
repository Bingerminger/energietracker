<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;

/**
 * Tagesverbrauch und Tages-Bestandsabzug für lieferungsbasierte Zähler
 * (Heizöl, Pellets).
 *
 * Wurde in v1.4.4 aus ConsumptionService extrahiert, um dessen Größe
 * zu reduzieren. ConsumptionService delegiert seine öffentlichen
 * Delivery-Methoden hierher; die internen Anreicherungs-Methoden
 * (enrichWithWeather, applyContracts, …) verbleiben in ConsumptionService,
 * da sie auch von kumulativen Utilities genutzt werden.
 *
 * Benötigte Abhängigkeiten bewusst minimal gehalten (JsonStore + Settings),
 * damit kein Zirkel mit ConsumptionService entsteht.
 */
final class DeliveryConsumptionService
{
    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    //  Tagesverbrauch (kWh)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Tagesverbrauch für einen Lieferungs-Meter (Heizöl/Pellets).
     *
     * Modell:
     *   total_kwh   = Σ (Lieferung.quantity × kwh_per_unit) im Zeitraum
     *                 (nur tatsächliche Lieferungen, geplante ausgeschlossen)
     *   total_days  = Tage zwischen Meter-Inbetriebnahme und heute
     *   baseload    = total_kwh × delivery_baseload_share        (gleichverteilt)
     *   heating     = total_kwh × (1 − delivery_baseload_share)  (HGT-gewichtet)
     *
     *   verbrauch(d) = baseload / total_days
     *                + heating × HGT(d) / Σ HGT(t..today)
     *
     * Falls für einen Tag keine Temperaturdaten vorliegen (HGT unbekannt),
     * fließt der Heating-Anteil dort als anteilig-gleichverteilt ein
     * (Fallback gegen Datenlöcher).
     *
     * Die Verteilung respektiert die Energieerhaltung — Σ verbrauch(d) über
     * alle Tage = total_kwh. Das bedeutet implizit die Annahme, dass der
     * Tankbestand am Ende des Zeitraums ungefähr dem `initial_stock`
     * entspricht; bei stark wachsendem oder fallendem Tankbestand ist die
     * Verteilung entsprechend leicht über- oder untergeschätzt. Akzeptable
     * Vereinfachung für Privatkundendaten.
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
        $u = Utilities::get($utility);
        $convSetting = (string)($u['conversion_setting'] ?? '');
        $kwhPerUnit  = $convSetting !== ''
            ? (float)$this->settings->get($convSetting, 1.0)
            : 1.0;
        $hddBase     = (float)$this->settings->get('hdd_base_temp', 15.0);
        $baseShare   = max(0.0, min(1.0, (float)$this->settings->get('delivery_baseload_share', 0.15)));

        // Lieferungen lesen — direktes Read auf die deliveries-Datei
        // (kein DeliveryService hier, um keine Zirkelabhängigkeit aufzumachen)
        $all = $this->store->read("$utility/deliveries.json", []);
        if (!is_array($all)) $all = [];
        $deliveries = array_values(array_filter(
            $all,
            fn($d) => is_array($d)
                  && ($d['meter_id'] ?? null) === ($meter['id'] ?? null)
                  && empty($d['is_planned'])
                  && !empty($d['date'])
        ));

        $startDate = $this->deliveryMeterStartDate($meter);
        $today     = date('Y-m-d');
        if ($startDate > $today) return [];

        // Gesamtenergie aus Lieferungen PLUS dem Anfangsbestand des Tanks.
        // Σ Verbrauch über die gesamte Laufzeit = (initial_stock + Σ
        // Lieferungen) × kwh_per_unit — das modelliert: alles, was beim
        // Start im Tank war plus alles, was nachgefüllt wurde, wird über
        // die Laufzeit verbraucht. Endbestand ≈ 0 als Modellannahme. Wenn
        // der reale Endbestand > 0 ist, überschätzt die Verteilung den
        // Verbrauch leicht; das wird in einer späteren Version durch eine
        // optionale Tank-Peilung („aktueller Stand laut Peilstab") feiner.
        $initialStock = (float)($meter['initial_stock'] ?? 0.0);
        $totalKwh = ($initialStock * $kwhPerUnit);
        foreach ($deliveries as $d) {
            $totalKwh += (float)($d['quantity'] ?? 0) * $kwhPerUnit;
        }
        if ($totalKwh <= 0) return [];

        // Tagesfenster
        $cursor = new \DateTime($startDate);
        $end    = new \DateTime($today);
        $dates  = [];
        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }
        $totalDays = count($dates);
        if ($totalDays === 0) return [];

        // HGT pro Tag aus temperatures.json
        $temps = $this->store->read('temperatures.json', []);
        if (!is_array($temps)) $temps = [];
        $hddPerDay = [];
        $sumHdd = 0.0;
        $daysWithTemp = 0;
        foreach ($dates as $d) {
            $t = $temps[$d] ?? null;
            $avg = (is_array($t) && isset($t['avg'])) ? (float)$t['avg'] : null;
            if ($avg !== null) {
                $hdd = max(0.0, $hddBase - $avg);
                $hddPerDay[$d] = $hdd;
                $sumHdd += $hdd;
                $daysWithTemp++;
            }
        }

        $baseloadKwh  = $totalKwh * $baseShare;
        $heatingKwh   = $totalKwh * (1.0 - $baseShare);
        $baselinePerDay = $baseloadKwh / $totalDays;

        // Wenn keine Temperaturen vorliegen oder Σ HGT = 0: alles flach
        $useHdd = $sumHdd > 0 && $daysWithTemp >= max(1, (int)($totalDays * 0.5));

        // Heating-Anteil verteilen: bei Tagen ohne Temp einen anteiligen
        // Fallback nutzen (gleichverteilt über Tage-ohne-Temp), damit Σ
        // exakt heatingKwh ergibt.
        $daysWithoutTemp = $totalDays - $daysWithTemp;
        $heatingFallbackPerDay = $useHdd && $daysWithoutTemp > 0
            ? ($heatingKwh * ($daysWithoutTemp / $totalDays)) / $daysWithoutTemp
            : ($heatingKwh / $totalDays);
        // Wenn HGT-Modell aktiv: nur der "wirkliche" Heating-Anteil aus den
        // Tagen mit Temperatur kommt aus Σ HGT; die Tage ohne Temperatur
        // bekommen den Fallback. Damit Σ exakt stimmt, korrigieren wir das:
        $heatingWeightedShare = $useHdd ? ($heatingKwh * ($daysWithTemp / $totalDays)) : $heatingKwh;

        $result = [];
        foreach ($dates as $d) {
            $hadTemp = array_key_exists($d, $hddPerDay);
            $heating = 0.0;
            if ($useHdd && $hadTemp && $sumHdd > 0) {
                $heating = $heatingWeightedShare * ($hddPerDay[$d] / $sumHdd);
            } elseif ($useHdd && !$hadTemp) {
                $heating = $heatingFallbackPerDay;
            } else {
                $heating = $heatingKwh / $totalDays;
            }
            $result[$d] = round($baselinePerDay + $heating, 6);
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Tages-Bestandsabzug (Mengeneinheiten)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * v1.4.0 — Tagesabzug für die TANK-BESTANDSKURVE (Liter bzw. kg/Tag).
     *
     * Anders als {@see dailyDeliveryConsumption()} (die für Kosten/Effizienz
     * die gesamte gelieferte Energie HGT-gewichtet auf die Laufzeit verteilt
     * und damit den Endbestand modellbedingt gegen 0 zwingt) berechnet diese
     * Methode den Abzug aus einer **kalibrierten Verbrauchsrate** und gibt
     * Mengeneinheiten (nicht kWh) zurück. Dadurch:
     *   - korrekte Einheit für stock = initial + Lieferungen − Abzug
     *   - KEIN erzwungener Endbestand 0; der Restbestand ergibt sich physisch
     *
     * Kalibrierung der Heizrate (Einheiten pro HGT):
     *   Über die „geschlossenen" Lieferintervalle (vom ersten bis zum letzten
     *   realen Liefertag) gilt im eingeschwungenen Zustand: was vor der
     *   letzten Lieferung geliefert wurde ≈ was in diesem Zeitraum verbraucht
     *   wurde (der Tank pendelt um ein ähnliches Niveau). Daraus:
     *       rate = Σ(Lieferungen außer der letzten) × (1−baseShare)
     *              / ΣHGT(erste Lieferung … letzte Lieferung)
     *   Dieselbe Rate wird auf Kopf (vor erster Lieferung) und offenen
     *   Schwanz (nach letzter Lieferung) extrapoliert — der Bestand fällt
     *   danach realistisch, ohne auf 0 normiert zu werden.
     *
     * Fallback bei < 2 Lieferungen (keine Kadenz ableitbar): Rate aus
     * (Anfangsbestand + Σ Lieferungen) über die Fenster-HGT — dann trendet
     * der Bestand mangels Information weiterhin Richtung 0, aber
     * einheitenkorrekt. Ohne Temperaturen: flacher Abzug.
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
        $hddBase   = (float)$this->settings->get('hdd_base_temp', 15.0);
        $baseShare = max(0.0, min(1.0, (float)$this->settings->get('delivery_baseload_share', 0.15)));

        $all = $this->store->read("$utility/deliveries.json", []);
        if (!is_array($all)) $all = [];
        $deliveries = array_values(array_filter(
            $all,
            fn($d) => is_array($d)
                  && ($d['meter_id'] ?? null) === ($meter['id'] ?? null)
                  && empty($d['is_planned'])
                  && !empty($d['date'])
        ));
        usort($deliveries, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));

        $startDate = $this->deliveryMeterStartDate($meter);
        $today     = date('Y-m-d');
        if ($startDate > $today) return [];

        // Tagesfenster
        $cursor = new \DateTime($startDate);
        $end    = new \DateTime($today);
        $dates  = [];
        while ($cursor <= $end) { $dates[] = $cursor->format('Y-m-d'); $cursor->modify('+1 day'); }
        $totalDays = count($dates);
        if ($totalDays === 0) return [];

        // HGT je Tag
        $temps = $this->store->read('temperatures.json', []);
        if (!is_array($temps)) $temps = [];
        $hddPerDay = [];
        $sumHddWindow = 0.0;
        foreach ($dates as $d) {
            $t = $temps[$d] ?? null;
            $avg = (is_array($t) && isset($t['avg'])) ? (float)$t['avg'] : null;
            if ($avg !== null) {
                $hdd = max(0.0, $hddBase - $avg);
                $hddPerDay[$d] = $hdd;
                $sumHddWindow += $hdd;
            }
        }

        $initialStock = (float)($meter['initial_stock'] ?? 0.0);
        $totalDelivered = 0.0;
        foreach ($deliveries as $dlv) $totalDelivered += (float)($dlv['quantity'] ?? 0);

        // Grundlast (flach) — als Mengeneinheit/Tag. Bezugsmenge:
        // Anfangsbestand + alle Lieferungen (die Grundlast existiert real
        // unabhängig vom Wetter, z. B. Warmwasser).
        $baseTotalUnits = ($initialStock + $totalDelivered) * $baseShare;
        $baselinePerDay = $totalDays > 0 ? $baseTotalUnits / $totalDays : 0.0;

        $noTemp = $sumHddWindow <= 0 || count($hddPerDay) < max(1, (int)($totalDays * 0.5));

        // ── Heizrate (Einheiten pro HGT) kalibrieren ──
        $ratePerHdd = 0.0;
        if (!$noTemp) {
            if (count($deliveries) >= 2) {
                // geschlossene Intervalle: erste … letzte Lieferung
                $firstDate = (string)$deliveries[0]['date'];
                $lastDate  = (string)$deliveries[count($deliveries) - 1]['date'];
                $sumHddClosed = 0.0;
                foreach ($hddPerDay as $d => $h) {
                    if ($d >= $firstDate && $d < $lastDate) $sumHddClosed += $h;
                }
                // im Zeitraum verbrauchte Menge ≈ alle Lieferungen außer der letzten
                $closedDelivered = 0.0;
                for ($i = 0; $i < count($deliveries) - 1; $i++) {
                    $closedDelivered += (float)($deliveries[$i]['quantity'] ?? 0);
                }
                $heatingClosed = $closedDelivered * (1.0 - $baseShare);
                if ($sumHddClosed > 0 && $heatingClosed > 0) {
                    $ratePerHdd = $heatingClosed / $sumHddClosed;
                }
            }
            if ($ratePerHdd <= 0.0) {
                // Fallback < 2 Lieferungen oder degeneriert: aus
                // (initial + Σ Lieferungen) über Fenster-HGT (trendet
                // mangels Kadenz weiter Richtung 0, aber einheitenkorrekt)
                $heatingUnits = ($initialStock + $totalDelivered) * (1.0 - $baseShare);
                $ratePerHdd = $sumHddWindow > 0 ? $heatingUnits / $sumHddWindow : 0.0;
            }
        }

        $result = [];
        foreach ($dates as $d) {
            if ($noTemp) {
                // flach: (initial + Σ Lieferungen) gleichverteilt
                $result[$d] = round(($initialStock + $totalDelivered) / $totalDays, 6);
                continue;
            }
            $hdd = $hddPerDay[$d] ?? 0.0;
            $result[$d] = round($baselinePerDay + $ratePerHdd * $hdd, 6);
        }
        return $result;
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
}
