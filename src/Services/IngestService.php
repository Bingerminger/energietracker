<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

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
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
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
        foreach ($this->readings->list($utility, $meterId) as $r) {
            if (($r['date'] ?? null) === $date) { $existing = $r; break; }
        }

        if ($existing !== null) {
            $updated = $this->readings->update($utility, (string)$existing['id'], [
                'counter' => $value,
            ]);
            return [
                'status'     => 'updated',
                'utility'    => $utility,
                'meter_id'   => $meterId,
                'date'       => $date,
                'counter'    => (float)$updated['counter'],
                'reading_id' => (string)$updated['id'],
            ];
        }

        $created = $this->readings->create($utility, [
            'meter_id' => $meterId,
            'date'     => $date,
            'counter'  => $value,
            'note'     => 'Home Assistant',
        ]);
        return [
            'status'     => 'created',
            'utility'    => $utility,
            'meter_id'   => $meterId,
            'date'       => $date,
            'counter'    => (float)$created['counter'],
            'reading_id' => (string)$created['id'],
        ];
    }
}
