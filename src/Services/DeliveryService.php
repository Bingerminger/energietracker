<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;
use Energietracker\Support\Dates;

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
        private I18nService $i18n,
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
            throw new NotFoundException($this->i18n->t('errors.delivery.notFound', ['id' => $id]));
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
            throw new NotFoundException($this->i18n->t('errors.delivery.notFound', ['id' => $id]));
        }
        $this->store->write("$utility/deliveries.json", $kept);
    }

    /**
     * Tank-/Lagerbestand-Verlauf als Tagesreihe.
     *
     * v2.10.0 — aus dem Tankbuch ({@see DeliveryConsumptionService::tankModel()}):
     * dieselbe Rechnung wie Verbrauch und Kosten. `stock` ist der Bestand am
     * Ende des Tages. Zwischen Stützstellen (Anfangsbestand, Lieferung „bis
     * voll", Peilstand) ist der Verlauf gerechnet, ab `estimated_from`
     * geschätzt (`estimated` je Tag).
     *
     * @return array{
     *   meter_id: string,
     *   capacity: ?float,
     *   capacity_unit: ?string,
     *   initial_stock: float,
     *   days: array<int, array{date: string, stock: float, delivery: float, consumption: float, estimated: bool}>,
     *   anchors: array<int, array{date: string, kind: string, stock: float}>,
     *   estimated_from: ?string,
     *   calibration: string,
     *   warnings: array<int, array<string,mixed>>
     * }
     */
    public function stockHistory(string $utility, string $meterId, ConsumptionService $consumption): array
    {
        $this->assertDeliveryUtility($utility);
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) {
            throw new NotFoundException($this->i18n->t('errors.delivery.tankNotFound', ['id' => $meterId]));
        }

        $model = $consumption->tankModel($utility, $meter);
        $days = [];
        foreach ($model['days'] as $date => $row) {
            $days[] = [
                'date'        => $date,
                'stock'       => $row['stock'],
                'delivery'    => $row['delivery'],
                'consumption' => round($row['draw'], 4),
                'estimated'   => $row['estimated'],
            ];
        }

        return [
            'meter_id'       => $meterId,
            'capacity'       => isset($meter['capacity']) ? (float)$meter['capacity'] : null,
            'capacity_unit'  => $meter['capacity_unit'] ?? Utilities::get($utility)['volume_unit'] ?? null,
            'initial_stock'  => (float)($meter['initial_stock'] ?? 0.0),
            'days'           => $days,
            'anchors'        => $model['anchors'],
            'estimated_from' => $model['estimated_from'],
            'calibration'    => $model['calibration']['source'],
            'warnings'       => $model['warnings'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    private function assertDeliveryUtility(string $utility): void
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
        }
        if (!Utilities::isDelivery($utility)) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.delivery.notDeliveryBased', ['utility' => $utility])
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
        // v2.10.0 — Tankbuch: „bis voll getankt" macht die Lieferung zur
        // Stützstelle (Bestand danach = Kapazität). "false" als Text ist falsch.
        if (array_key_exists('fill_to_full', $payload)) {
            $v = $payload['fill_to_full'];
            $payload['fill_to_full'] = $v === true || $v === 1 || (is_string($v) && in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true));
        }
        if (isset($payload['supplier'])) $payload['supplier'] = (string)$payload['supplier'];
        if (isset($payload['note']))     $payload['note']     = (string)$payload['note'];
        return $payload;
    }

    private function validate(string $utility, array $d, bool $isUpdate): void
    {
        if (empty($d['date']) || !Dates::isIsoDate((string)$d['date'])) {   // v2.5.3: kalendergültig
            throw new \InvalidArgumentException($this->i18n->t('errors.delivery.dateMissing'));
        }
        if (!isset($d['quantity']) || !is_numeric($d['quantity']) || (float)$d['quantity'] <= 0) {
            throw new \InvalidArgumentException($this->i18n->t('errors.delivery.quantityPositive'));
        }
        if (isset($d['unit_price_cents']) && (float)$d['unit_price_cents'] < 0) {
            throw new \InvalidArgumentException($this->i18n->t('errors.delivery.priceNegative'));
        }
        if (empty($d['meter_id'])) {
            throw new \InvalidArgumentException($this->i18n->t('errors.delivery.meterIdMissing'));
        }
        // Future delivery only allowed when explicitly marked as planned
        if (!($d['is_planned'] ?? false) && (string)$d['date'] > date('Y-m-d')) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.delivery.dateFuture')
            );
        }
        // Meter exists?
        $meter = $this->meters->get($utility, (string)$d['meter_id']);
        if (!$meter) {
            throw new \InvalidArgumentException($this->i18n->t('errors.delivery.tankNotExist', ['id' => $d['meter_id']]));
        }
        // v2.10.0 — „bis voll": Mehr als in den Tank passt, kann nicht geliefert sein
        $capacity = (float)($meter['capacity'] ?? 0);
        if (!empty($d['fill_to_full']) && $capacity > 0 && (float)$d['quantity'] > $capacity * 1.02) {
            throw new \InvalidArgumentException($this->i18n->t('errors.delivery.fullAboveCapacity', [
                'quantity' => (string)$d['quantity'], 'capacity' => (string)$capacity,
            ]));
        }
    }
}
