<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;

/**
 * Lieferungs-CRUD für die Lieferungs-basierten Verbrauchsarten
 * (Heizöl, Pellets — `reading_kind: 'delivery'` in Utilities.php).
 *
 * Datenmodell pro Lieferung:
 *   {
 *     id, meter_id (= Tank/Lager), date,
 *     quantity,           // in der volume_unit der Utility (L oder kg)
 *     unit_price_cents,   // ct pro Einheit
 *     total_eur,          // Rechnungsbetrag, optional (falls null → berechnet)
 *     supplier, note,
 *     is_planned          // true = geplante zukünftige Lieferung (validiert)
 *   }
 *
 * Wesentlich anders als ReadingService:
 *   - keine monoton-steigende Zählerlogik
 *   - mehrere Lieferungen am selben Tag sind erlaubt (mit Duplikat-Warnung)
 *   - is_planned-Flag, weil zukünftige Lieferungen sinnvoll sein können
 *     (z.B. „die nächste Befüllung ist bestellt für KW 47")
 */
final class DeliveryService
{
    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function list(string $utility, ?string $meterId = null): array
    {
        $this->assertDeliveryUtility($utility);
        $all = $this->store->read("$utility/deliveries.json", []);
        if (!is_array($all)) $all = [];
        if ($meterId !== null) {
            $all = array_values(array_filter($all, fn($d) => ($d['meter_id'] ?? null) === $meterId));
        }
        usort($all, fn($a, $b) => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
        return $all;
    }

    public function get(string $utility, string $id): ?array
    {
        $this->assertDeliveryUtility($utility);
        foreach ($this->list($utility) as $d) {
            if (($d['id'] ?? null) === $id) return $d;
        }
        return null;
    }

    public function create(string $utility, array $payload): array
    {
        $this->assertDeliveryUtility($utility);
        $payload = $this->normalize($utility, $payload);
        $this->validate($utility, $payload, isUpdate: false);

        $all = $this->list($utility);
        $payload['id'] = $payload['id'] ?? 'del_' . $utility . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
        $all[] = $payload;
        $this->store->write("$utility/deliveries.json", $all);
        return $payload;
    }

    public function update(string $utility, string $id, array $patch): array
    {
        $this->assertDeliveryUtility($utility);
        $all = $this->list($utility);
        $found = null;
        foreach ($all as &$d) {
            if (($d['id'] ?? null) === $id) {
                $d = array_merge($d, $this->normalize($utility, $patch));
                $this->validate($utility, $d, isUpdate: true);
                $found = $d;
                break;
            }
        }
        unset($d);
        if ($found === null) {
            throw new \RuntimeException('Lieferung nicht gefunden: ' . $id);
        }
        $this->store->write("$utility/deliveries.json", $all);
        return $found;
    }

    public function delete(string $utility, string $id): void
    {
        $this->assertDeliveryUtility($utility);
        $all = $this->list($utility);
        $kept = array_values(array_filter($all, fn($d) => ($d['id'] ?? null) !== $id));
        if (count($kept) === count($all)) {
            throw new \RuntimeException('Lieferung nicht gefunden: ' . $id);
        }
        $this->store->write("$utility/deliveries.json", $kept);
    }

    /**
     * Tank-/Lagerbestand-Verlauf als Tagesreihe.
     *
     * Berechnung:
     *   bestand(d) = initial_stock
     *              + Σ Lieferungen.quantity (Datum ≤ d, nicht-geplant)
     *              − Σ Verbrauch (Datum ≤ d)
     *
     * Der Verbrauch wird aus dem ConsumptionService bezogen, der die
     * HGT-gewichtete Tagesverteilung kennt. Liegt der Bestand unter
     * `tank_warn_pct` der Tank-Kapazität, wird das durch das Banner-System
     * im Frontend abgegriffen.
     *
     * Diese Methode liefert eine kompakte Tagesreihe; Wochen-/Monats-
     * Aggregation übernimmt das Frontend.
     *
     * @return array{
     *   meter_id: string,
     *   capacity: ?float,
     *   capacity_unit: ?string,
     *   initial_stock: float,
     *   days: array<int, array{date: string, stock: float, delivery: float, consumption: float}>
     * }
     */
    public function stockHistory(string $utility, string $meterId, ConsumptionService $consumption): array
    {
        $this->assertDeliveryUtility($utility);
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) {
            throw new \RuntimeException('Tank/Lager nicht gefunden: ' . $meterId);
        }

        $initialStock = (float)($meter['initial_stock'] ?? 0.0);
        $capacity     = isset($meter['capacity']) ? (float)$meter['capacity'] : null;
        $capacityUnit = $meter['capacity_unit'] ?? Utilities::get($utility)['volume_unit'] ?? null;

        // v1.4.0 — Tagesabzug für die Bestandskurve in MENGENEINHEITEN
        // (Liter/kg), kalibriert aus den Lieferintervallen — KEIN Zwang
        // auf Endbestand 0 (siehe ConsumptionService::dailyDeliveryStockDraw).
        $dailyCons = $consumption->dailyDeliveryStockDraw($utility, $meter);

        // Lieferungen ab Tank-Installations-Tag (active device.installed_on)
        $startDate = $this->meterStartDate($meter);
        $today     = date('Y-m-d');

        $deliveries = $this->list($utility, $meterId);
        // Map: date → sum(quantity) — geplante Lieferungen ignorieren
        $deliveryByDate = [];
        foreach ($deliveries as $d) {
            if (!empty($d['is_planned'])) continue;
            $dt = (string)($d['date'] ?? '');
            if ($dt === '') continue;
            $deliveryByDate[$dt] = ($deliveryByDate[$dt] ?? 0.0) + (float)($d['quantity'] ?? 0.0);
        }

        $days = [];
        $stock = $initialStock;
        $cursor = new \DateTime($startDate);
        $end    = new \DateTime($today);

        while ($cursor <= $end) {
            $dStr = $cursor->format('Y-m-d');
            $delivery = (float)($deliveryByDate[$dStr] ?? 0.0);
            $cons     = (float)($dailyCons[$dStr] ?? 0.0);
            $stock    = max(0.0, $stock + $delivery - $cons);
            $days[] = [
                'date'        => $dStr,
                'stock'       => round($stock, 2),
                'delivery'    => round($delivery, 2),
                'consumption' => round($cons, 4),
            ];
            $cursor->modify('+1 day');
        }

        return [
            'meter_id'      => $meterId,
            'capacity'      => $capacity,
            'capacity_unit' => $capacityUnit,
            'initial_stock' => $initialStock,
            'days'          => $days,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function assertDeliveryUtility(string $utility): void
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException('Unbekannte Verbrauchsart: ' . $utility);
        }
        if (!Utilities::isDelivery($utility)) {
            throw new \InvalidArgumentException(
                'Verbrauchsart „' . $utility . '" ist nicht lieferungs-basiert; nutze ReadingService.'
            );
        }
    }

    private function normalize(string $utility, array $payload): array
    {
        if (isset($payload['quantity'])) $payload['quantity'] = (float)$payload['quantity'];
        if (isset($payload['unit_price_cents'])) $payload['unit_price_cents'] = (float)$payload['unit_price_cents'];
        if (isset($payload['total_eur']) && $payload['total_eur'] !== null) {
            $payload['total_eur'] = (float)$payload['total_eur'];
        }
        if (isset($payload['is_planned'])) $payload['is_planned'] = (bool)$payload['is_planned'];
        if (isset($payload['supplier'])) $payload['supplier'] = (string)$payload['supplier'];
        if (isset($payload['note']))     $payload['note']     = (string)$payload['note'];
        return $payload;
    }

    private function validate(string $utility, array $d, bool $isUpdate): void
    {
        if (empty($d['date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d['date'])) {
            throw new \InvalidArgumentException('Lieferdatum (date) fehlt oder ist nicht im Format YYYY-MM-DD');
        }
        if (!isset($d['quantity']) || !is_numeric($d['quantity']) || (float)$d['quantity'] <= 0) {
            throw new \InvalidArgumentException('Liefermenge (quantity) muss > 0 sein');
        }
        if (isset($d['unit_price_cents']) && (float)$d['unit_price_cents'] < 0) {
            throw new \InvalidArgumentException('Stückpreis darf nicht negativ sein');
        }
        if (empty($d['meter_id'])) {
            throw new \InvalidArgumentException('meter_id (Tank/Lager) fehlt');
        }
        // Future delivery only allowed when explicitly marked as planned
        if (!($d['is_planned'] ?? false) && (string)$d['date'] > date('Y-m-d')) {
            throw new \InvalidArgumentException(
                'Lieferdatum liegt in der Zukunft — für geplante Lieferungen is_planned=true setzen'
            );
        }
        // Meter exists?
        $meter = $this->meters->get($utility, (string)$d['meter_id']);
        if (!$meter) {
            throw new \InvalidArgumentException('Tank/Lager (meter_id) existiert nicht: ' . $d['meter_id']);
        }
    }

    private function meterStartDate(array $meter): string
    {
        foreach ($meter['devices'] ?? [] as $dev) {
            if (empty($dev['removed_on']) && !empty($dev['installed_on'])) {
                return (string)$dev['installed_on'];
            }
        }
        // Fallback: created_at oder heute
        return (string)($meter['created_at'] ?? date('Y-m-d'));
    }
}
