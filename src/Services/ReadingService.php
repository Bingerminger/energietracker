<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;

/**
 * Ablesungs-CRUD pro Utility.
 *
 * Bei Create/Update wird device_id automatisch aus dem aktiven Device des
 * Meters abgeleitet (das letzte Device ohne `removed_on`). Validiert
 * `date` als ISO-YYYY-MM-DD und `counter` als Zahl ≥ 0.
 */
final class ReadingService
{
    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function list(string $utility, ?string $meterId = null): array
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException('Unbekannte Verbrauchsart: ' . $utility);
        }
        $all = $this->store->read("$utility/readings.json", []);
        if (!is_array($all)) $all = [];
        if ($meterId !== null) {
            $all = array_values(array_filter($all, fn($r) => ($r['meter_id'] ?? null) === $meterId));
        }
        usort($all, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
        return $all;
    }

    /**
     * Create a reading. meter_id is auto-resolved to default if not given.
     * device_id is auto-resolved from the meter's device history by date.
     */
    public function create(string $utility, array $input): array
    {
        if (empty($input['date'])) throw new \InvalidArgumentException('Datum fehlt');
        if (!array_key_exists('counter', $input)) throw new \InvalidArgumentException('Zählerstand fehlt');

        $meterId = $input['meter_id'] ?? $this->meters->defaultId($utility);
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) throw new \InvalidArgumentException('Zähler nicht gefunden: ' . $meterId);

        $date = (string)$input['date'];
        $device = $this->meters->deviceOnDate($meter, $date);
        if (!$device) {
            throw new \InvalidArgumentException(
                'Für das Datum ' . $date . ' ist kein Gerät dieses Zählers aktiv'
            );
        }

        $reading = [
            'id'           => date('Ymd', strtotime($date) ?: time()) . '-' . bin2hex(random_bytes(4)),
            'meter_id'     => $meterId,
            'device_id'    => $device['id'],
            'date'         => $date,
            'counter'      => (float)$input['counter'],
            'price_cents'  => isset($input['price_cents']) && $input['price_cents'] !== ''
                              ? (float)$input['price_cents'] : null,
            'note'         => isset($input['note']) ? (string)$input['note'] : '',
            'is_estimated' => !empty($input['is_estimated']),
            'is_future'    => $date > date('Y-m-d'),
        ];

        $all = $this->store->read("$utility/readings.json", []);
        if (!is_array($all)) $all = [];
        $all[] = $reading;
        usort($all, fn($a, $b) => strcmp($a['date'], $b['date']));
        $this->store->write("$utility/readings.json", $all);
        return $reading;
    }

    public function update(string $utility, string $id, array $input): array
    {
        $all = $this->store->read("$utility/readings.json", []);
        if (!is_array($all)) $all = [];
        $found = null;
        foreach ($all as &$r) {
            if (($r['id'] ?? null) !== $id) continue;
            foreach (['date', 'counter', 'price_cents', 'note', 'is_estimated', 'meter_id'] as $f) {
                if (!array_key_exists($f, $input)) continue;
                if ($f === 'counter') $r['counter'] = (float)$input['counter'];
                elseif ($f === 'price_cents') $r['price_cents'] = $input['price_cents'] !== '' ? (float)$input['price_cents'] : null;
                elseif ($f === 'is_estimated') $r['is_estimated'] = (bool)$input['is_estimated'];
                else $r[$f] = $input[$f];
            }
            // Recompute device_id from date
            $meter = $this->meters->get($utility, $r['meter_id']);
            if ($meter) {
                $d = $this->meters->deviceOnDate($meter, $r['date']);
                $r['device_id'] = $d['id'] ?? $r['device_id'];
            }
            $r['is_future'] = ($r['date'] ?? '') > date('Y-m-d');
            $found = $r;
            break;
        }
        unset($r);
        if (!$found) throw new \InvalidArgumentException('Ablesung nicht gefunden');
        usort($all, fn($a, $b) => strcmp($a['date'], $b['date']));
        $this->store->write("$utility/readings.json", $all);
        return $found;
    }

    public function delete(string $utility, string $id): void
    {
        $all = $this->store->read("$utility/readings.json", []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($r) => ($r['id'] ?? null) !== $id));
        if (count($kept) === count($all)) throw new \InvalidArgumentException('Ablesung nicht gefunden');
        $this->store->write("$utility/readings.json", $kept);
    }
}
