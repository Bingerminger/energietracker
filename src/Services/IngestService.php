<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Support\Dates;

/**
 * F1009 — Push-Ingest für externe Datenlieferanten (Home Assistant).
 *
 * Ein dedizierter, idempotenter Eingang für Zählerstände. Im Gegensatz zur
 * UI-Route `POST /api/utility/{utility}/readings` (legt immer eine neue
 * Ablesung an) macht der Ingest ein **Upsert pro (Zähler, Datum)**: ein
 * erneuter Push am selben Tag (typisch: HA-Automation um 23:55, plus manueller
 * Test) aktualisiert den vorhandenen Wert, statt Duplikate zu erzeugen.
 *
 * Zählerzuordnung: das Feld `meter` wird zuerst als `external_id` (HA-Alias)
 * aufgelöst, danach als interne Meter-ID. So funktionieren beide Schreibweisen.
 *
 * Delivery-Utilities (Heizöl/Pellets) arbeiten mit Lieferungen statt
 * Ablesungen und werden hier bewusst abgelehnt.
 */
final class IngestService
{
    public function __construct(
        private MeterService $meters,
        private ReadingService $readings,
        private I18nService $i18n,
    ) {}

    /**
     * @param array{utility?:string, meter?:string, meter_id?:string,
     *              value?:mixed, counter?:mixed, date?:string} $input
     * @return array{status:string, utility:string, meter_id:string,
     *               date:string, counter:float, reading_id:string}
     */
    public function ingest(array $input): array
    {
        $utility = (string)($input['utility'] ?? '');
        if ($utility === '' || !Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.ingest.unknownUtility', ['utility' => $utility]));
        }
        if (Utilities::isDelivery($utility)) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.ingest.deliveryNotSupported', ['utility' => $utility])
            );
        }

        // Zählerwert: `value` (HA-freundlich) oder `counter` (interne Bezeichnung).
        $rawValue = $input['value'] ?? $input['counter'] ?? null;
        if ($rawValue === null || $rawValue === '' || !is_numeric($rawValue)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.ingest.valueMissing'));
        }
        $value = (float)$rawValue;

        // Datum: optional, Default heute. Akzeptiert YYYY-MM-DD; ein voller
        // ISO-Zeitstempel (HA `now().isoformat()`) wird auf das Datum gekürzt.
        $date = trim((string)($input['date'] ?? ''));
        if ($date === '') {
            $date = date('Y-m-d');
        } else {
            if (strlen($date) > 10) $date = substr($date, 0, 10);
            // v2.5.3 — kalendergültig statt nur das Muster: `2025-13-01` wurde
            // bisher angenommen und legte danach jede Auswertung lahm.
            if (!Dates::isIsoDate($date)) {
                throw new \InvalidArgumentException($this->i18n->t('errors.ingest.dateFormat', ['date' => $date]));
            }
        }

        // Zähler auflösen: erst Alias (external_id), dann interne ID.
        $meterRef = trim((string)($input['meter'] ?? $input['meter_id'] ?? ''));
        if ($meterRef === '') {
            throw new \InvalidArgumentException($this->i18n->t('errors.ingest.meterMissing'));
        }
        $meter = $this->meters->getByExternalId($utility, $meterRef)
            ?? $this->meters->get($utility, $meterRef);
        if (!$meter) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.ingest.meterNotFound', ['meter' => $meterRef, 'utility' => $utility])
            );
        }
        $meterId = (string)$meter['id'];

        // Upsert-by-date: existiert schon eine Ablesung dieses Zählers am
        // selben Tag, wird sie aktualisiert; sonst neu angelegt.
        $existing = null;
        $readings = $this->readings->list($utility, $meterId);
        foreach ($readings as $r) {
            if (($r['date'] ?? null) === $date) { $existing = $r; break; }
        }

        // v2.6.0 — Ein Stand unter dem letzten desselben Geräts wird
        // angenommen, aber als verdächtig markiert: Die Antwort bleibt 201 bzw.
        // 200 (keine Home-Assistant-Automation bricht), die Rechnung übergeht
        // ihn und zeigt eine Warnung, bis er bestätigt oder gelöscht ist.
        // Typischer Auslöser: ein nicht verfügbarer Sensor, den eine alte
        // Vorlage mit `float(0)` zur 0 machte.
        $previous = $this->previousOnSameDevice($readings, $meter, $date);
        // Ein Überlauf des Zählwerks (99.998 → 12) mit gepflegter Stellenzahl
        // ist kein Verdacht — die Verbrauchsrechnung zählt ihn richtig.
        $suspect  = $previous !== null && $value < (float)$previous['counter']
            && ConsumptionService::rolloverAmount((float)$previous['counter'], $value, $this->meters->deviceOnDate($meter, $date)) === null;

        if ($existing !== null) {
            $updated = $this->readings->update($utility, (string)$existing['id'], [
                'counter'    => $value,
                'is_suspect' => $suspect,
                'source'     => 'ingest',
            ]);
            return $this->result('updated', $utility, $meterId, $date, $updated, $suspect, $previous);
        }

        $created = $this->readings->create($utility, [
            'meter_id'   => $meterId,
            'date'       => $date,
            'counter'    => $value,
            'note'       => 'Home Assistant',
            'source'     => 'ingest',
            'is_suspect' => $suspect,
        ]);
        return $this->result('created', $utility, $meterId, $date, $created, $suspect, $previous);
    }

    /** @return array<string,mixed> */
    private function result(string $status, string $utility, string $meterId, string $date, array $reading, bool $suspect, ?array $previous): array
    {
        $out = [
            'status'     => $status,
            'utility'    => $utility,
            'meter_id'   => $meterId,
            'date'       => $date,
            'counter'    => (float)$reading['counter'],
            'reading_id' => (string)$reading['id'],
            // v2.6.0 — additiv
            'suspect'    => $suspect,
        ];
        if ($suspect && $previous !== null) {
            $out['previous'] = ['date' => (string)$previous['date'], 'counter' => (float)$previous['counter']];
        }
        return $out;
    }

    /**
     * Letzte nicht verdächtige Ablesung VOR $date auf dem Gerät, das an
     * $date eingebaut ist. Über einen Zählertausch hinweg gibt es keinen
     * Vergleich — der neue Zähler beginnt meist bei null.
     */
    private function previousOnSameDevice(array $readings, array $meter, string $date): ?array
    {
        $device = $this->meters->deviceOnDate($meter, $date);
        $deviceId = $device['id'] ?? null;
        $prev = null;
        foreach ($readings as $r) {
            if (($r['date'] ?? '') >= $date) break;   // nach Datum sortiert
            if (!empty($r['is_suspect']) || !empty($r['is_future'])) continue;
            if ($deviceId !== null && ($r['device_id'] ?? null) !== $deviceId) continue;
            $prev = $r;
        }
        return $prev;
    }
}
