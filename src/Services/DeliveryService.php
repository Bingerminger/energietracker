<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;
use Energietracker\Support\Dates;
use Energietracker\Support\Input;
use Energietracker\Support\LocalizedException;

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

    /** v3.4.0 (F1024) — Verlauf: eine Lieferung bei niedrigem Bestand erledigt „Lieferung planen". */
    private ?ActivityService $activity = null;

    public function attachActivity(ActivityService $activity): void
    {
        $this->activity = $activity;
    }

    /**
     * v3.4.0 (F1024) — Tag der letzten erfolgten (nicht geplanten) Lieferung,
     * der Anker des Hinweises „Lieferung planen" (Agenda, R5, Verlauf).
     *
     * @param array<int,array<string,mixed>> $deliveries eines Tanks
     */
    public static function lastDelivered(array $deliveries, ?string $today = null): ?string
    {
        $today ??= date('Y-m-d');
        $last = null;
        foreach ($deliveries as $d) {
            $date = (string)($d['date'] ?? '');
            if (!empty($d['is_planned']) || $date === '' || $date > $today) continue;
            if ($last === null || $date > $last) $last = $date;
        }
        return $last;
    }

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
        // v3.4.0 (F1024) — Stand vor der Lieferung: niedriger Bestand, letzte Lieferung
        $meterId = (string)($payload['meter_id'] ?? '');
        $wasLow = $this->activity !== null && empty($payload['is_planned']) && $this->activity->tankWasLow($utility, $meterId);
        $prevLatest = self::lastDelivered(array_filter($all, fn($d) => ($d['meter_id'] ?? null) === $meterId));
        $all[] = $payload;
        $this->store->write("$utility/deliveries.json", $all);
        if ($wasLow && ($meter = $this->meters->get($utility, $meterId)) !== null) {
            $this->activity->deliveryRecorded($utility, $meter, $payload, $prevLatest, true);
        }
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
    public function stockHistory(string $utility, string $meterId, ConsumptionService $consumption, ?string $today = null): array
    {
        $this->assertDeliveryUtility($utility);
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) {
            throw new NotFoundException($this->i18n->t('errors.delivery.tankNotFound', ['id' => $meterId]));
        }

        $model = $consumption->tankModel($utility, $meter, $today);   // v3.12.0 — bis zum Stichtag, sonst heute
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

    /** v3.12.0 — Obergrenzen einer Lieferung: Menge (L bzw. kg), Preis je Einheit (wie an der Ablesung), Summe. */
    public const LIMITS = ['quantity' => 1000000, 'unit_price_cents' => 100000, 'total_eur' => 10000000];

    private function normalize(string $utility, array $payload): array
    {
        // v3.12.0 — Typen und Dezimalkomma an einer Stelle (Support\Input): eine Liste statt Text ergab
        // 500, „1250,5“ L wurden still 1250, „abc“ ct still 0
        Input::assertTexts($payload, ['meter_id', 'date', 'supplier', 'note']);
        foreach (['quantity', 'unit_price_cents', 'total_eur'] as $f) {
            if (!isset($payload[$f])) continue;
            if ($payload[$f] === '') { $payload[$f] = 0.0; continue; }   // wie bisher (float)''
            $n = Input::decimal($payload[$f]);
            if ($n === null) {
                throw new LocalizedException('errors.common.numberExpected', ['field' => $f, 'value' => is_scalar($payload[$f]) ? mb_substr((string)$payload[$f], 0, 40) : gettype($payload[$f])], "delivery $f");
            }
            // v3.12.0 — Obergrenzen: 10³⁰⁸ Liter legten bisher die Ansichten der Art lahm
            if ($n > self::LIMITS[$f]) {
                throw new LocalizedException('errors.common.valueInvalid', ['field' => $f, 'value' => (string)$payload[$f]], "delivery $f too large");
            }
            $payload[$f] = $n;
        }
        if (isset($payload['is_planned'])) {
            // v3.12.0 — „false“ als Text war bisher wahr (geplant)
            $b = Input::bool($payload['is_planned']);
            if ($b === null) throw new LocalizedException('errors.common.boolExpected', ['field' => 'is_planned'], 'delivery is_planned');
            $payload['is_planned'] = $b;
        }
        // v2.10.0 — Tankbuch: „bis voll getankt" macht die Lieferung zur
        // Stützstelle (Bestand danach = Kapazität). "false" als Text ist falsch.
        if (array_key_exists('fill_to_full', $payload)) {
            // v3.12.0 — eine Regel für Wahrheitswerte (Support\Input::bool); Unlesbares ist ein Fehler
            $payload['fill_to_full'] = Input::bool($payload['fill_to_full'])
                ?? throw new LocalizedException('errors.common.boolExpected', ['field' => 'fill_to_full'], 'delivery fill_to_full');
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
        Input::assertDateInRange((string)$d['date']);   // v3.12.0 — 1900 bis 2100
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
