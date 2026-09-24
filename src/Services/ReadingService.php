<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;
use Energietracker\Support\Dates;
use Energietracker\Http\NotFoundException;

/**
 * Ablesungs-CRUD pro Utility.
 *
 * Bei Create/Update wird device_id automatisch aus dem aktiven Device des
 * Meters abgeleitet (das letzte Device ohne `removed_on`). Validiert
 * `date` als kalendergültiges JJJJ-MM-TT und `counter` als Zahl ≥ 0.
 *
 * v2.5.3 — Die Zusage stand hier schon länger, geprüft wurde nichts:
 * `(float)"12,5"` wurde still zu 12, ein Datum `2025-13-01` legte danach jede
 * Auswertung mit HTTP 500 lahm. Jetzt lehnen beide Pfade (create, update)
 * ungültige Werte mit 400 ab.
 */
final class ReadingService
{
    /** v2.6.0 — erlaubte Werte für das optionale Feld `source` */
    private const SOURCES = ['ingest', 'csv'];

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

        $date = (string)$input['date'];
        $this->assertDate($date);
        $counter = $this->parseCounter($input['counter']);

        $meterId = $input['meter_id'] ?? $this->meters->defaultId($utility);
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) throw new \InvalidArgumentException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));

        $device = $this->meters->deviceOnDate($meter, $date);
        if (!$device && $this->meters->backdateFirstDevice($utility, (string)$meterId, $date) !== null) {
            // v2.5.3 — Ablesung vor dem Einbau des ersten Geräts: Einbau
            // vorverlegen (s. MeterService::backdateFirstDevice), sonst kam
            // niemand mit seiner Historie in eine Neuinstallation.
            $meter  = $this->meters->get($utility, (string)$meterId) ?? $meter;
            $device = $this->meters->deviceOnDate($meter, $date);
        }
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
            'counter'      => $counter,
            'price_cents'  => isset($input['price_cents']) && $input['price_cents'] !== ''
                              ? (float)$input['price_cents'] : null,
            'note'         => isset($input['note']) ? (string)$input['note'] : '',
            'is_estimated' => !empty($input['is_estimated']),
            'is_future'    => $date > date('Y-m-d'),
        ];
        // v2.6.0 — additiv: Herkunft (für /api/health last_ingest) und
        // Verdacht (fallender Stand im Home-Assistant-Push, s. IngestService).
        // Beide nur, wenn gesetzt — bestehende Datensätze bleiben gleich.
        if (in_array($input['source'] ?? null, self::SOURCES, true)) $reading['source'] = $input['source'];
        if (!empty($input['is_suspect'])) $reading['is_suspect'] = true;

        $all = $this->store->read("$utility/readings.json", []);
        if (!is_array($all)) $all = [];
        $all[] = $reading;
        usort($all, fn($a, $b) => strcmp($a['date'], $b['date']));
        $this->store->write("$utility/readings.json", $all);
        return $reading;
    }

    public function update(string $utility, string $id, array $input): array
    {
        if (array_key_exists('date', $input)) $this->assertDate((string)$input['date']);
        if (array_key_exists('counter', $input)) $input['counter'] = $this->parseCounter($input['counter']);

        $all = $this->store->read("$utility/readings.json", []);
        if (!is_array($all)) $all = [];
        $found = null;
        foreach ($all as &$r) {
            if (($r['id'] ?? null) !== $id) continue;
            $counterChanged = array_key_exists('counter', $input) && (float)$input['counter'] !== (float)($r['counter'] ?? 0);
            foreach (['date', 'counter', 'price_cents', 'note', 'is_estimated', 'meter_id'] as $f) {
                if (!array_key_exists($f, $input)) continue;
                if ($f === 'counter') $r['counter'] = (float)$input['counter'];
                elseif ($f === 'price_cents') $r['price_cents'] = $input['price_cents'] !== '' ? (float)$input['price_cents'] : null;
                elseif ($f === 'is_estimated') $r['is_estimated'] = (bool)$input['is_estimated'];
                else $r[$f] = $input[$f];
            }
            // v2.6.0 — Verdacht: ausdrücklich gesetzt oder bestätigt; wer den
            // Stand selbst korrigiert, hat ihn damit ebenfalls geklärt.
            if (array_key_exists('is_suspect', $input)) {
                if (!empty($input['is_suspect'])) $r['is_suspect'] = true; else unset($r['is_suspect']);
            } elseif ($counterChanged) {
                unset($r['is_suspect']);
            }
            if (in_array($input['source'] ?? null, self::SOURCES, true)) $r['source'] = $input['source'];
            // Recompute device_id from date
            $meter = $this->meters->get($utility, $r['meter_id']);
            if ($meter) {
                $d = $this->meters->deviceOnDate($meter, $r['date']);
                if (!$d && $this->meters->backdateFirstDevice($utility, (string)$r['meter_id'], (string)$r['date']) !== null) {
                    $meter = $this->meters->get($utility, $r['meter_id']) ?? $meter;
                    $d = $this->meters->deviceOnDate($meter, $r['date']);
                }
                $r['device_id'] = $d['id'] ?? $r['device_id'];
            }
            $r['is_future'] = ($r['date'] ?? '') > date('Y-m-d');
            $found = $r;
            break;
        }
        unset($r);
        if (!$found) throw new NotFoundException($this->i18n->t('errors.reading.notFound'));
        usort($all, fn($a, $b) => strcmp($a['date'], $b['date']));
        $this->store->write("$utility/readings.json", $all);
        return $found;
    }

    /**
     * v2.6.0 — Viele Ablesungen eines Zählers in EINEM Schreibvorgang
     * (CSV-Import). Bisher las, sortierte und schrieb jede Zeile die ganze
     * readings.json: 2.000 Zeilen dauerten 4 s, ein Mehrjahres-Import brach an
     * max_execution_time mittendrin ab. Gleiche Regeln wie create()/update():
     * gleiches Datum → überschreiben, Datum und Stand geprüft, Gerät je Datum.
     *
     * @param list<array<string,mixed>> $rows je Zeile date, counter, note?,
     *        is_estimated?, line? (Zeilennummer für die Meldung)
     * @return array{imported:int,overwritten:int,skipped:int,errors:list<string>}
     */
    public function upsertMany(string $utility, string $meterId, array $rows, ?string $source = 'csv'): array
    {
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));

        // Erstes Gerät ggf. einmal vorverlegen (s. create()).
        $dates = array_filter(array_map(fn($r) => (string)($r['date'] ?? ''), $rows), [Dates::class, 'isIsoDate']);
        if ($dates !== []) {
            $earliest = min($dates);
            if (!$this->meters->deviceOnDate($meter, $earliest)
                && $this->meters->backdateFirstDevice($utility, $meterId, $earliest) !== null) {
                $meter = $this->meters->get($utility, $meterId) ?? $meter;
            }
        }

        $all = $this->store->read("$utility/readings.json", []);
        if (!is_array($all)) $all = [];
        $byDate = [];
        foreach ($all as $idx => $r) {
            if (($r['meter_id'] ?? null) === $meterId && isset($r['date'])) $byDate[$r['date']] = $idx;
        }

        $imported = $overwritten = $skipped = 0;
        $errors = [];
        $today = date('Y-m-d');
        foreach ($rows as $i => $row) {
            $line = (int)($row['line'] ?? $i + 1);
            try {
                $date = (string)($row['date'] ?? '');
                $this->assertDate($date);
                $counter = $this->parseCounter($row['counter'] ?? null);
                $device = $this->meters->deviceOnDate($meter, $date);
                if (!$device) {
                    throw new \InvalidArgumentException($this->i18n->t('errors.reading.noDevice', ['date' => $date]));
                }
            } catch (\InvalidArgumentException $e) {
                $skipped++;
                $errors[] = $this->i18n->t('errors.import.rowError', ['line' => $line, 'message' => $e->getMessage()]);
                continue;
            }
            $fields = [
                'device_id'    => $device['id'],
                'counter'      => $counter,
                'note'         => (string)($row['note'] ?? ''),
                'is_estimated' => !empty($row['is_estimated']),
                'is_future'    => $date > $today,
            ];
            if (isset($byDate[$date])) {
                $r = $all[$byDate[$date]];
                unset($r['is_suspect']);
                $all[$byDate[$date]] = array_merge($r, $fields);
                $overwritten++;
            } else {
                $new = ['id' => date('Ymd', strtotime($date) ?: time()) . '-' . bin2hex(random_bytes(4)),
                        'meter_id' => $meterId, 'date' => $date, 'price_cents' => null] + $fields;
                if (in_array($source, self::SOURCES, true)) $new['source'] = $source;
                $all[] = $new;
                $byDate[$date] = array_key_last($all);
                $imported++;
            }
        }

        if ($imported + $overwritten > 0) {
            $all = array_values($all);
            usort($all, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
            $this->store->write("$utility/readings.json", $all);
        }
        return ['imported' => $imported, 'overwritten' => $overwritten, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function delete(string $utility, string $id): void
    {
        $all = $this->store->read("$utility/readings.json", []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($r) => ($r['id'] ?? null) !== $id));
        if (count($kept) === count($all)) throw new NotFoundException($this->i18n->t('errors.reading.notFound'));
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
     *   last_reading:?array{date:string, counter:float, is_estimated:bool, id:string, device_id:?string},
     *   expected_next_min:?float, typical_per_day:?float, suspect_count:int
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
                // v2.6.0 — verdächtige Stände ebenfalls: Ein Home-Assistant-
                // Push mit 0 wäre sonst der „letzte Stand" der Erfassung.
                $readings = $this->list($key, (string)$m['id']);
                $real = array_values(array_filter(
                    $readings,
                    fn($r) => empty($r['is_future']) && empty($r['is_suspect'])
                ));
                $last = !empty($real) ? end($real) : null;
                $suspects = count(array_filter($readings, fn($r) => !empty($r['is_suspect'])));

                $rows[] = [
                    'utility'           => $key,
                    // v2.2.0 — lokalisiert; vorher stand hier das deutsche
                    // SSOT-Label und erschien so in der Zählerstand-Erfassung,
                    // der Haupteingabemaske, auch bei anderer Sprache.
                    'utility_label'     => $this->i18n->utilityLabel($key),
                    'utility_icon'      => (string)($utility['icon']  ?? ''),
                    // v2.4.2 — GitHub #21: Der ZÄHLERSTAND steht in `unit`
                    // (Gas: m³), der VERBRAUCH in `consumption_unit` (Gas:
                    // kWh). Die Erfassungsmaske bekam bisher nur Letzteres
                    // und beschriftete damit das Zählerstand-Feld — Gas
                    // stand als „kWh" da, gespeichert und gerechnet wurde
                    // aber immer in m³.
                    'unit'              => (string)($utility['unit'] ?? ''),
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
                        // v2.6.0 — additiv: „schon ein Stand heute → ersetzen"
                        // und „anderes Gerät → kein Rückgang"
                        'id'           => (string)($last['id'] ?? ''),
                        'device_id'    => $last['device_id'] ?? null,
                    ] : null,
                    'expected_next_min' => $last !== null ? (float)$last['counter'] : null,
                    // v2.6.0 — für die Rückfrage bei ungewöhnlichem Sprung
                    'typical_per_day'   => $this->typicalPerDay($real),
                    'suspect_count'     => $suspects,
                ];
            }
        }
        return $rows;
    }

    /**
     * v2.6.0 — typischer Tagesverbrauch aus den letzten Intervallen desselben
     * Geräts: Median, also robust gegen einen einzelnen Ausreißer. Grundlage
     * der Rückfrage „Das wären 400 kWh/Tag, üblich sind 8" in der Erfassung.
     * Null, solange weniger als zwei Intervalle vorliegen.
     *
     * @param array<int,array<string,mixed>> $readings nach Datum sortiert
     */
    public function typicalPerDay(array $readings): ?float
    {
        $rates = [];
        for ($i = count($readings) - 1; $i > 0 && count($rates) < 10; $i--) {
            $a = $readings[$i - 1];
            $b = $readings[$i];
            if (($a['device_id'] ?? null) !== ($b['device_id'] ?? null)) continue;
            if (!Dates::isIsoDate($a['date'] ?? null) || !Dates::isIsoDate($b['date'] ?? null)) continue;
            $days = (int)round((strtotime($b['date']) - strtotime($a['date'])) / 86400);
            $diff = (float)($b['counter'] ?? 0) - (float)($a['counter'] ?? 0);
            if ($days >= 1 && $diff >= 0) $rates[] = $diff / $days;
        }
        if (count($rates) < 2) return null;
        sort($rates);
        $n = count($rates);
        $median = $n % 2 ? $rates[intdiv($n, 2)] : ($rates[$n / 2 - 1] + $rates[$n / 2]) / 2;
        return round($median, 4);
    }

    private function assertDate(string $date): void
    {
        if (!Dates::isIsoDate($date)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reading.dateInvalid', ['date' => $date]));
        }
    }

    /**
     * Zählerstand als Zahl ≥ 0. Zahlen und Zahl-Strings mit Punkt sind gültig;
     * „12,5" nicht — mehrdeutig (Dezimal- oder Tausenderkomma) und bisher still
     * zu 12 abgeschnitten. Das Frontend wandelt Kommas vorher selbst um.
     */
    private function parseCounter(mixed $raw): float
    {
        if (is_bool($raw) || !is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0) {
            $shown = is_scalar($raw) ? (string)$raw : gettype($raw);
            throw new \InvalidArgumentException($this->i18n->t('errors.reading.counterInvalid', ['value' => $shown]));
        }
        return (float)$raw;
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
