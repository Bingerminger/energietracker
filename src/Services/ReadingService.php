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
        private I18nService $i18n,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function list(string $utility, ?string $meterId = null): array
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
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
        if (empty($input['date'])) throw new \InvalidArgumentException($this->i18n->t('errors.reading.dateMissing'));
        if (!array_key_exists('counter', $input)) throw new \InvalidArgumentException($this->i18n->t('errors.reading.counterMissing'));

        $meterId = $input['meter_id'] ?? $this->meters->defaultId($utility);
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) throw new \InvalidArgumentException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));

        $date = (string)$input['date'];
        $device = $this->meters->deviceOnDate($meter, $date);
        if (!$device) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.reading.noDevice', ['date' => $date])
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
        if (!$found) throw new \InvalidArgumentException($this->i18n->t('errors.reading.notFound'));
        usort($all, fn($a, $b) => strcmp($a['date'], $b['date']));
        $this->store->write("$utility/readings.json", $all);
        return $found;
    }

    public function delete(string $utility, string $id): void
    {
        $all = $this->store->read("$utility/readings.json", []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($r) => ($r['id'] ?? null) !== $id));
        if (count($kept) === count($all)) throw new \InvalidArgumentException($this->i18n->t('errors.reading.notFound'));
        $this->store->write("$utility/readings.json", $kept);
    }

    /**
     * v1.6.0 — Aggregat für den zentralen Zählerstand-Erfassungs-View.
     *
     * Liefert ALLE aktiven Zähler der Utilities, die mit kumulativen
     * Zählerständen arbeiten (Gas/Strom/Wasser/Fernwärme), zusammen mit
     * jeweils der letzten realen (nicht-geplanten) Ablesung. Delivery-
     * Utilities (Heizöl/Pellets) sind absichtlich ausgeschlossen — dort
     * gibt es keine Ablesungen, sondern Lieferungen.
     *
     * Designziel: ein einziger Roundtrip, damit die mobile Vor-Ort-
     * Erfassung mit minimalem API-Verkehr startet. Speichern erfolgt
     * danach pro Zeile über die bestehende POST-Route.
     *
     * @param string[] $activeUtilities Whitelist aktiver Utilities aus
     *                                   settings.json. Inaktive werden
     *                                   ohnehin in der UI nicht angezeigt.
     * @return list<array{
     *   utility:string, utility_label:string, utility_icon:string,
     *   consumption_unit:string, color:string,
     *   meter_id:string, meter_name:string, meter_icon:string,
     *   meter_notes:string, active_device_id:?string,
     *   last_reading:?array{date:string, counter:float, is_estimated:bool},
     *   expected_next_min:?float
     * }>
     */
    public function overview(array $activeUtilities): array
    {
        $rows = [];
        foreach (Utilities::all() as $utility) {
            $key = (string)($utility['key'] ?? '');
            if ($key === '') continue;
            // Nur kumulative Utilities (Zählerstand-Modell)
            if (!Utilities::isCumulative($key)) continue;
            // Nur aktivierte Utilities einbeziehen
            if (!empty($activeUtilities) && !in_array($key, $activeUtilities, true)) continue;

            $meters = $this->meters->list($key);
            foreach ($meters as $m) {
                if (!($m['active'] ?? true)) continue;

                // Letzte reale Ablesung (geplante is_future ausschließen)
                $readings = $this->list($key, (string)$m['id']);
                $real = array_values(array_filter(
                    $readings,
                    fn($r) => empty($r['is_future'])
                ));
                $last = !empty($real) ? end($real) : null;

                $rows[] = [
                    'utility'           => $key,
                    'utility_label'     => (string)($utility['label'] ?? $key),
                    'utility_icon'      => (string)($utility['icon']  ?? ''),
                    'consumption_unit'  => (string)($utility['consumption_unit'] ?? ''),
                    'color'             => (string)($utility['color'] ?? ''),
                    'meter_id'          => (string)$m['id'],
                    'meter_name'        => (string)($m['name'] ?? $m['id']),
                    'meter_icon'        => (string)($m['icon'] ?? ''),
                    'meter_notes'       => (string)($m['notes'] ?? ''),
                    'active_device_id'  => $this->activeDeviceId($m),
                    'last_reading'      => $last !== null ? [
                        'date'         => (string)($last['date'] ?? ''),
                        'counter'      => (float)($last['counter'] ?? 0),
                        'is_estimated' => (bool)($last['is_estimated'] ?? false),
                    ] : null,
                    'expected_next_min' => $last !== null ? (float)$last['counter'] : null,
                ];
            }
        }
        return $rows;
    }

    /** Aktives (nicht ausgebautes) Device eines Zählers, oder null. */
    private function activeDeviceId(array $meter): ?string
    {
        foreach ($meter['devices'] ?? [] as $d) {
            if (empty($d['removed_on'])) return (string)($d['id'] ?? '');
        }
        return null;
    }
}
