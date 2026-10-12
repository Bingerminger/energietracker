<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;
use Energietracker\Support\LocalizedException;

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
        private ?JsonStore $store = null,   // v3.1.0 — Stapel schreiben einmal je Datei
        private ?IntervalService $intervals = null,   // v3.3.0 (F1021) — Stände mit Zeitstempel
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

        // v3.3.0 (F1021) — `timestamp` (ISO 8601 mit Zone): der Stand zu genau
        // diesem Zeitpunkt; daraus Verbrauch je Viertelstunde. Ohne `date` gilt
        // der Tag des Zeitstempels in der Zeitzone der Installation.
        $at = null;
        $tsRaw = trim((string)($input['timestamp'] ?? ''));
        if ($tsRaw !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $tsRaw)) {
                throw new \Energietracker\Support\LocalizedException('errors.ingest.timestampFormat', ['timestamp' => mb_substr($tsRaw, 0, 40)], 'ingest timestamp');
            }
            try { $at = new \DateTimeImmutable($tsRaw); } catch (\Exception) {
                throw new \Energietracker\Support\LocalizedException('errors.ingest.timestampFormat', ['timestamp' => mb_substr($tsRaw, 0, 40)], 'ingest timestamp');
            }
            if (trim((string)($input['date'] ?? '')) === '' && $this->intervals !== null) {
                $input['date'] = $at->setTimezone($this->intervals->tz())->format('Y-m-d');
            }
        }

        // Datum: optional, Default heute. Akzeptiert YYYY-MM-DD; ein voller
        // ISO-Zeitstempel (HA `now().isoformat()`) wird auf das Datum gekürzt.
        $date = trim((string)($input['date'] ?? ''));
        if ($at !== null && $date === '') $date = $at->format('Y-m-d');
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
        // v3.1.0 (H3, B2) — ein Zähler mit Verbrauch je Zeitraum nimmt keine Stände an
        if (($meter['capture'] ?? 'counter') === 'period') {
            throw new \Energietracker\Support\LocalizedException('errors.ingest.periodMeter', ['meter' => $meterRef], 'ingest to period meter');
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

        // v3.3.0 (F1021) — mit Zeitstempel: Verbrauch je Viertelstunde (nicht bei Verdacht)
        $slots = ($at !== null && !$suspect && $this->intervals !== null)
            ? $this->intervals->addCounterSample($utility, $meterId, $at->getTimestamp(), $value) : null;

        if ($existing !== null) {
            $updated = $this->readings->update($utility, (string)$existing['id'], [
                'counter'    => $value,
                'is_suspect' => $suspect,
                'source'     => 'ingest',
            ]);
            return $this->result('updated', $utility, $meterId, $date, $updated, $suspect, $previous) + ($slots !== null ? ['intervals' => $slots] : []);
        }

        $created = $this->readings->create($utility, [
            'meter_id'   => $meterId,
            'date'       => $date,
            'counter'    => $value,
            'note'       => 'Home Assistant',
            'source'     => 'ingest',
            'is_suspect' => $suspect,
        ]);
        return $this->result('created', $utility, $meterId, $date, $created, $suspect, $previous) + ($slots !== null ? ['intervals' => $slots] : []);
    }

    /** Obergrenze eines Stapels (API-34). */
    public const BULK_MAX = 500;

    /**
     * v3.1.0 (Paket H1, API-34) — Stapel: mehrere Stände in einer Anfrage,
     * etwa zum Nachliefern nach einem Ausfall von Home Assistant oder aus
     * Node-RED. Jeder Eintrag wird wie ein Einzel-Ingest behandelt (gleiche
     * Prüfungen, gleiche Antwortfelder) und bekommt seinen `index`; ein
     * fehlerhafter Eintrag hält die anderen nicht auf. Verarbeitet wird nach
     * Verbrauchsart, Zähler und Datum (so sieht die Verdachtsprüfung frühere
     * Stände desselben Stapels), geantwortet in Eingabereihenfolge. Jede Datei
     * wird einmal geschrieben (JsonStore::batch).
     *
     * @param list<mixed> $items
     * @return array{results:list<array<string,mixed>>, created:int, updated:int, failed:int}
     */
    public function ingestMany(array $items): array
    {
        if (count($items) > self::BULK_MAX) {
            throw new \InvalidArgumentException($this->i18n->t('errors.ingest.tooMany', ['max' => self::BULK_MAX, 'count' => count($items)]));
        }
        // v3.3.0 — mit Zeitstempel auch nach der Uhrzeit (die Viertelstunden brauchen die Reihenfolge)
        $key = fn($x) => is_array($x)
            ? [(string)($x['utility'] ?? ''), (string)($x['meter'] ?? $x['meter_id'] ?? ''),
               (string)($x['date'] ?? (isset($x['timestamp']) ? substr((string)$x['timestamp'], 0, 10) : '9999')),
               isset($x['timestamp']) ? (int)strtotime((string)$x['timestamp']) : 0]
            : ['', '', '', 0];
        $order = array_keys($items);
        usort($order, fn($a, $b) => [$key($items[$a]), $a] <=> [$key($items[$b]), $b]);

        $results = [];
        $work = function () use ($items, $order, &$results): void {
            foreach ($order as $i) {
                if (!is_array($items[$i])) {
                    $results[$i] = ['index' => $i, 'status' => 'error', 'code' => 'errors.ingest.bodyInvalid',
                                    'error' => $this->i18n->t('errors.ingest.bodyInvalid')];
                    continue;
                }
                try {
                    $results[$i] = ['index' => $i] + $this->ingest($items[$i]);
                } catch (\InvalidArgumentException $e) {
                    $code = $e instanceof LocalizedException ? $e->key : ($this->i18n->errorCodeFor($e->getMessage()) ?? 'errors.http.badRequest');
                    $msg = $e instanceof LocalizedException ? $this->i18n->t($e->key, $e->params) : $e->getMessage();
                    $results[$i] = ['index' => $i, 'status' => 'error', 'code' => $code, 'error' => $msg];
                }
            }
        };
        $this->store !== null ? $this->store->batch($work) : $work();
        ksort($results);
        $results = array_values($results);
        $count = fn(string $s) => count(array_filter($results, fn($r) => $r['status'] === $s));
        return ['results' => $results, 'created' => $count('created'), 'updated' => $count('updated'), 'failed' => $count('error')];
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
