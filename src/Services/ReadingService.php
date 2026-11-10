<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;
use Energietracker\Support\Dates;
use Energietracker\Support\Input;
use Energietracker\Support\LocalizedException;
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
    // v3.7.0 — `inbox`: aus einem Dokument im Posteingang; v3.12.0 (F1037) — `mcp`: von einer KI vorgeschlagen und übernommen
    private const SOURCES = ['ingest', 'csv', 'inbox', 'mcp'];

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private I18nService $i18n,
    ) {}

    /** v3.4.0 (F1024) — Verlauf: eine Ablesung erledigt „Ablesung fällig", wenn sie fällig war. */
    private ?ActivityService $activity = null;

    public function attachActivity(ActivityService $activity): void
    {
        $this->activity = $activity;
    }

    /**
     * Tag der letzten echten Ablesung eines Zählers (nicht geplant, nicht
     * verdächtig) — wie die Erfassung und die Agenda sie sehen.
     *
     * @param array<int,array<string,mixed>> $all Ablesungen der Verbrauchsart
     */
    private static function lastRealDate(array $all, string $meterId): ?string
    {
        $last = null;
        foreach ($all as $r) {
            if (($r['meter_id'] ?? null) !== $meterId || !empty($r['is_future']) || !empty($r['is_suspect'])) continue;
            $d = (string)($r['date'] ?? '');
            if ($d !== '' && ($last === null || $d > $last)) $last = $d;
        }
        return $last;
    }

    /** v3.1.0 (H3, B2) — ein Zähler mit Verbrauch je Zeitraum hat keine Stände. */
    public static function assertCounterMeter(array $meter): void
    {
        if ((($meter['capture'] ?? 'counter')) === 'period') {
            throw new \Energietracker\Support\LocalizedException('errors.reading.periodMeter', [], 'reading on period meter');
        }
    }

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

        // v3.12.0 — Typen an einer Stelle (Support\Input): eine Liste oder ein Objekt statt
        // Text ergab bisher 500 („Array to string conversion“)
        Input::assertTexts($input, ['meter_id', 'note', 'client_ref', 'attachment_id', 'reported_on', 'source']);
        $date = (string)Input::text($input, 'date');
        $this->assertDate($date);
        $counter = $this->parseCounter($input['counter']);
        $price = $this->parsePrice($input['price_cents'] ?? null);
        $estimated = $this->parseFlag($input, 'is_estimated');

        $meterId = Input::text($input, 'meter_id', null) ?? $this->meters->defaultId($utility);
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) throw new \InvalidArgumentException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
        self::assertCounterMeter($meter);

        // v3.1.0 (Paket H2, FE-32) — Kennung des Geräts für diese Erfassung: Die
        // Offline-Warteschlange sendet nach, und ein zweiter Versuch (zwei Tabs,
        // Verbindung riss nach dem Speichern ab) darf keine zweite Ablesung anlegen.
        $clientRef = null;
        if (isset($input['client_ref']) && $input['client_ref'] !== '') {
            $clientRef = (string)Input::text($input, 'client_ref');
            if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $clientRef)) {
                throw new \InvalidArgumentException($this->i18n->t('errors.reading.clientRefInvalid'));
            }
            foreach ($this->list($utility, (string)$meterId) as $existing) {
                if (($existing['client_ref'] ?? null) === $clientRef) return $existing + ['duplicate' => true];
            }
        }

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
            'price_cents'  => $price,   // v3.12.0 — „12,5“ ist 12,5 (bisher still 12), „abc“ 400 (bisher 0)
            'note'         => (string)Input::text($input, 'note'),
            'is_estimated' => $estimated,
            'is_future'    => $date > date('Y-m-d'),
        ];
        // v2.6.0 — additiv: Herkunft (für /api/health last_ingest) und
        // Verdacht (fallender Stand im Home-Assistant-Push, s. IngestService).
        // Beide nur, wenn gesetzt — bestehende Datensätze bleiben gleich.
        if (in_array($input['source'] ?? null, self::SOURCES, true)) $reading['source'] = $input['source'];
        // v3.12.0 — Merker als Wahrheitswert (parseFlag): „false“ machte einen Stand bisher verdächtig
        // bzw. zu „nur Abrechnung“ (er fiel dann aus den Monatswerten)
        if ($this->parseFlag($input, 'is_suspect')) $reading['is_suspect'] = true;
        // v3.9.0 (ablesung-pruefen) — ein Stand von der Rechnung, der die Monatswerte nicht prägen soll
        if ($this->parseFlag($input, 'is_billing_only')) $reading['is_billing_only'] = true;
        // v3.11.0 (F1035, A5) — an den Versorger gemeldet am (optional, nur wenn gesetzt)
        if (($rep = $this->reportedOn($input)) !== null) $reading['reported_on'] = $rep;
        if ($clientRef !== null) $reading['client_ref'] = $clientRef;
        // v3.1.0 (Paket H2, MKT-08) — Foto des Zählerstands als Beleg
        if (!empty($input['attachment_id'])) {
            AttachmentService::link($this->store, (string)$input['attachment_id'], 'reading_photo',
                ['type' => 'reading', 'utility' => $utility, 'id' => $reading['id']]);
            $reading['attachment_id'] = (string)$input['attachment_id'];
        }

        $all = $this->store->read("$utility/readings.json", []);
        if (!is_array($all)) $all = [];
        $prevLast = self::lastRealDate($all, (string)$meterId);   // v3.4.0 (F1024)
        $all[] = $reading;
        usort($all, fn($a, $b) => strcmp($a['date'], $b['date']));
        $this->store->write("$utility/readings.json", $all);
        if ($this->activity !== null && empty($reading['is_suspect']) && !$reading['is_future']) {
            $this->activity->readingRecorded($utility, $meter, $date, $prevLast);
        }
        return $reading;
    }

    public function update(string $utility, string $id, array $input): array
    {
        // v3.12.0 — dieselben Prüfungen wie beim Anlegen: Typen, Datum mit Jahresbereich,
        // Preis mit Dezimalkomma, „geschätzt“ als Wahrheitswert
        Input::assertTexts($input, ['date', 'meter_id', 'note', 'attachment_id', 'reported_on', 'source']);
        if (array_key_exists('date', $input)) $this->assertDate((string)Input::text($input, 'date'));
        if (array_key_exists('counter', $input)) $input['counter'] = $this->parseCounter($input['counter']);
        if (array_key_exists('price_cents', $input)) $input['price_cents'] = $this->parsePrice($input['price_cents']);
        foreach (['is_estimated', 'is_suspect', 'is_verified', 'is_billing_only'] as $flag) {
            if (array_key_exists($flag, $input)) $input[$flag] = $this->parseFlag($input, $flag);
        }
        if (array_key_exists('note', $input)) $input['note'] = (string)Input::text($input, 'note');
        // v3.1.0 (H3) — umhängen nur auf einen Zähler mit Zählerständen
        // v3.12.0 — und nur auf einen Zähler, den es in dieser Verbrauchsart gibt: Bisher hing
        // ein PATCH die Ablesung auch an einen unbekannten oder artfremden Zähler (200), danach
        // fiel sie aus allen Auswertungen. Dieselbe Prüfung wie beim Anlegen.
        if (array_key_exists('meter_id', $input)) {
            $target = $this->meters->get($utility, (string)Input::text($input, 'meter_id'));
            if ($target === null) {
                throw new \InvalidArgumentException($this->i18n->t('errors.common.meterNotFound', ['id' => (string)Input::text($input, 'meter_id')]));
            }
            self::assertCounterMeter($target);
            $input['meter_id'] = (string)$target['id'];
        }

        $all = $this->store->read("$utility/readings.json", []);
        if (!is_array($all)) $all = [];
        $found = null;
        foreach ($all as &$r) {
            if (($r['id'] ?? null) !== $id) continue;
            $before = $r;
            $counterChanged = array_key_exists('counter', $input) && (float)$input['counter'] !== (float)($r['counter'] ?? 0);
            foreach (['date', 'counter', 'price_cents', 'note', 'is_estimated', 'meter_id'] as $f) {
                if (!array_key_exists($f, $input)) continue;
                if ($f === 'counter') $r['counter'] = (float)$input['counter'];
                elseif ($f === 'price_cents') $r['price_cents'] = $input['price_cents'];   // parsePrice() oben
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
            // v3.11.0 (F1035, A5) — gemeldet am: setzen, ändern, leer entfernt
            if (array_key_exists('reported_on', $input)) {
                $rep = $this->reportedOn($input);
                if ($rep === null) unset($r['reported_on']); else $r['reported_on'] = $rep;
            }
            // v3.9.0 (ablesung-pruefen) — „Stand ist richtig“ (`is_verified`: die Prüfung fragt
            // nie wieder) und „nur für die Abrechnung“ (`is_billing_only`: Rechnung und Saldo
            // rechnen mit dem Stand, die Monatswerte überspringen ihn). Nur gespeichert, wenn
            // gesetzt. Wer Stand oder Datum ändert, hat die Bestätigung damit überholt.
            $dateChanged = array_key_exists('date', $input) && (string)$input['date'] !== (string)($before['date'] ?? '');
            foreach (['is_verified', 'is_billing_only'] as $flag) {
                if (!array_key_exists($flag, $input)) continue;
                if (!empty($input[$flag])) $r[$flag] = true; else unset($r[$flag]);
            }
            if (!array_key_exists('is_verified', $input) && ($counterChanged || $dateChanged)) unset($r['is_verified']);
            // v3.1.0 (Paket H2) — Foto setzen, ersetzen oder entfernen
            if (array_key_exists('attachment_id', $input) && ($input['attachment_id'] ?? null) !== ($r['attachment_id'] ?? null)) {
                if (!empty($r['attachment_id'])) AttachmentService::unlinkRef($this->store, 'reading', (string)$r['id']);
                if (!empty($input['attachment_id'])) {
                    AttachmentService::link($this->store, (string)$input['attachment_id'], 'reading_photo',
                        ['type' => 'reading', 'utility' => $utility, 'id' => (string)$r['id']]);
                    $r['attachment_id'] = (string)$input['attachment_id'];
                } else {
                    unset($r['attachment_id']);
                }
            }
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
        // v3.9.0 (ablesung-pruefen, Skeptiker M1) — doppelt eingetragene Stände (derselbe Zähler,
        // Tag und Stand) bekommen „Stand ist richtig“ und „nur für die Abrechnung“ gemeinsam.
        // Sonst rechnet der Zwilling in den Monatswerten weiter, und die Prüfung meldet denselben
        // Tag sofort neu.
        $flags = array_intersect_key($input, ['is_verified' => true, 'is_billing_only' => true]);
        if ($flags !== []) {
            $same = sprintf('%.6f', (float)($found['counter'] ?? 0));
            foreach ($all as &$t) {
                if (($t['id'] ?? null) === $id || ($t['meter_id'] ?? null) !== ($found['meter_id'] ?? null)
                    || ($t['date'] ?? null) !== ($found['date'] ?? null) || sprintf('%.6f', (float)($t['counter'] ?? 0)) !== $same) continue;
                foreach ($flags as $flag => $v) if (!empty($v)) $t[$flag] = true; else unset($t[$flag]);
            }
            unset($t);
        }
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
        self::assertCounterMeter($meter);

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
        $prevLast = self::lastRealDate($all, $meterId);   // v3.4.0 (F1024)
        $newest = null;
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
                'note'         => is_scalar($row['note'] ?? null) ? (string)$row['note'] : '',
                'is_estimated' => !empty($row['is_estimated']),
                'is_future'    => $date > $today,
            ];
            // v3.12.0 — Preis nur, wenn die Zeile einen trägt (CSV mit Preisspalte); sonst bleibt er
            if (isset($row['price_cents']) && is_numeric($row['price_cents'])) $fields['price_cents'] = (float)$row['price_cents'];
            if (isset($byDate[$date])) {
                $r = $all[$byDate[$date]];
                unset($r['is_suspect']);
                // v3.9.0 (ablesung-pruefen) — ein anderer Stand ist nicht mehr der bestätigte
                if ((float)($r['counter'] ?? 0) !== $counter) unset($r['is_verified']);
                $all[$byDate[$date]] = array_merge($r, $fields);
                $overwritten++;
            } else {
                $new = array_merge(['id' => date('Ymd', strtotime($date) ?: time()) . '-' . bin2hex(random_bytes(4)),
                        'meter_id' => $meterId, 'date' => $date, 'price_cents' => null], $fields);
                if (in_array($source, self::SOURCES, true)) $new['source'] = $source;
                $all[] = $new;
                $byDate[$date] = array_key_last($all);
                $imported++;
                if ($date <= $today && ($newest === null || $date > $newest)) $newest = $date;
            }
        }

        if ($imported + $overwritten > 0) {
            $all = array_values($all);
            usort($all, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
            $this->store->write("$utility/readings.json", $all);
            // v3.4.0 (F1024) — einmal je Import, mit dem neuesten neuen Stand
            if ($this->activity !== null && $newest !== null) $this->activity->readingRecorded($utility, $meter, $newest, $prevLast);
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
        // v3.1.0 — ein Foto bleibt 24 h erhalten („Rückgängig"), dann räumt es der Belege-Dienst auf
        AttachmentService::unlinkRef($this->store, 'reading', $id);
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
     *   expected_next_min:?float, typical_per_day:?float, suspect_count:int,
     *   reading_count:int
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
                if (!MeterService::inService($m)) continue;   // v2.9.0: außer Betrieb nicht in der Erfassung

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
                    // v2.13.0 — Erste-Schritte-Liste: Verbrauch entsteht erst ab zwei Ständen
                    'reading_count'     => count($real),
                    // v3.1.0 (H3, B2/B9) — Erfassungsart, letzter Zeitraum, Rolle (additiv)
                    'capture'           => (string)($m['capture'] ?? 'counter'),
                    'last_period'       => $this->lastPeriod($key, (string)$m['id']),
                    'role'              => Utilities::roleOf($key, $m),
                    // v3.9.0 (L18, additiv) — Beginn der Daten: erste echte Ablesung, sonst der
                    // Einbau des ersten Geräts (Tanks, Zeiträume); für die Jahreswahl des Berichts
                    'first_date'        => !empty($real) ? (string)($real[0]['date'] ?? '') : ((string)($m['devices'][0]['installed_on'] ?? $m['created_at'] ?? '') ?: null),
                ];
            }
        }
        return $rows;
    }

    /** v3.1.0 (H3, B2) — letzter Zeitraum eines Zählers mit Verbrauch je Zeitraum, sonst null. */
    private function lastPeriod(string $utility, string $meterId): ?array
    {
        $last = null;
        $all = $this->store->read("$utility/periods.json", []);
        foreach (is_array($all) ? $all : [] as $p) {
            if (!is_array($p) || ($p['meter_id'] ?? null) !== $meterId) continue;
            if ($last === null || (string)$p['to'] > (string)$last['to']) $last = $p;
        }
        return $last === null ? null : ['id' => (string)$last['id'], 'from' => (string)$last['from'], 'to' => (string)$last['to'],
            'value' => (float)($last['value'] ?? 0), 'value_unit' => (string)($last['value_unit'] ?? 'consumption')];
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

    /** v3.12.0 (F1037) — Tagesverbrauch über dem Dreifachen des typischen: Rückfrage (wie lib/plausibility.js JUMP_FACTOR). */
    public const JUMP_FACTOR = 3;

    /**
     * v3.12.0 (F1037) — Rückfragen zu einem neuen Stand, dieselbe Regel wie
     * `checkReading()` in public/js/lib/plausibility.js (gemeinsame Probe:
     * tests/fixtures/reading-plausibility.json). Nichts davon blockiert:
     *   lower      kleiner als der vorige Stand (Zählertausch nicht erfasst?)
     *   jump       Tagesverbrauch seit dem vorigen Stand > JUMP_FACTOR × typisch
     *   magnitude  mindestens zehnmal der vorige Stand, solange kein typischer
     *              Tagesverbrauch bekannt ist („Komma vergessen“)
     * v3.12.0 (Skeptiker M7) — dazu:
     *   higher_than_next  größer als der nächste Stand desselben Geräts: Die Verbrauchsrechnung
     *                     ließe den Stand als Ausreißer stillschweigend aus (`$next`, `$nextDeviceChanged`)
     * Ein Gerätetausch zwischen beiden Ständen erklärt Rückgang und Sprung.
     * Datum in der Zukunft und „schon ein Stand an dem Tag“ prüft der Aufrufer.
     *
     * @param array{date:string, counter:float|int}|null $prev voriger echter Stand (vor `$date`)
     * @param array{date:string, counter:float|int}|null $next nächster echter Stand (nach `$date`)
     * @return list<array{type:string, last?:float, per_day?:float, typical?:float, next?:float}>
     */
    public static function plausibilityIssues(float $value, string $date, ?array $prev, ?float $typical, bool $deviceChanged = false,
                                              ?array $next = null, bool $nextDeviceChanged = false): array
    {
        $out = [];
        if ($prev !== null && (string)$prev['date'] < $date && !$deviceChanged) {
            $last = (float)$prev['counter'];
            $days = (int)round((strtotime($date . 'T00:00:00Z') - strtotime((string)$prev['date'] . 'T00:00:00Z')) / 86400);
            if ($value < $last) $out[] = ['type' => 'lower', 'last' => $last];
            elseif ($typical !== null && $typical > 0 && $days >= 1 && ($value - $last) / $days > self::JUMP_FACTOR * $typical) {
                $out[] = ['type' => 'jump', 'per_day' => ($value - $last) / $days, 'typical' => $typical];
            } elseif (!($typical !== null && $typical > 0) && $last >= 10 && $value >= $last * 10) {
                $out[] = ['type' => 'magnitude', 'last' => $last];
            }
        }
        if ($next !== null && $date < (string)$next['date'] && !$nextDeviceChanged && $value > (float)$next['counter']) {
            $out[] = ['type' => 'higher_than_next', 'next' => (float)$next['counter']];
        }
        return $out;
    }

    /**
     * v3.12.0 (Skeptiker M7) — Rückfragen zu einem Stand gegen die echten Stände des Zählers:
     * voriger und nächster Stand, Gerätetausch dazwischen, typischer Tagesverbrauch. Für den
     * Vorschlag einer KI und noch einmal beim Übernehmen (ein Vorschlag kann lange warten).
     *
     * @param array<string,mixed> $meter
     * @return array{issues: list<array<string,mixed>>, prev: ?array<string,mixed>, next: ?array<string,mixed>, typical: ?float, device_changed: bool, real: list<array<string,mixed>>}
     */
    public function plausibilityFor(string $utility, array $meter, string $date, float $value): array
    {
        $real = array_values(array_filter($this->list($utility, (string)$meter['id']), fn($r) => empty($r['is_future']) && empty($r['is_suspect'])
            && is_numeric($r['counter'] ?? null) && Dates::isIsoDate($r['date'] ?? null)));
        $prev = null; $next = null;
        foreach ($real as $r) {
            if ($r['date'] < $date) $prev = $r;
            elseif ($r['date'] > $date && $next === null) $next = $r;
        }
        $changed = static function (string $from, string $to) use ($meter): bool {
            foreach ((array)($meter['devices'] ?? []) as $d) {
                $on = is_array($d) ? (string)($d['installed_on'] ?? '') : '';
                if ($on !== '' && $on > $from && $on <= $to) return true;
            }
            return false;
        };
        $swapped = $prev !== null && $changed((string)$prev['date'], $date);
        $swappedNext = $next !== null && $changed($date, (string)$next['date']);
        $typical = $this->typicalPerDay($real);
        return ['issues' => self::plausibilityIssues($value, $date, $prev, $typical, $swapped, $next, $swappedNext), 'prev' => $prev, 'next' => $next,
                'typical' => $typical, 'device_changed' => $swapped, 'real' => $real];
    }

    /**
     * v3.11.0 (F1035, A5) — Tag, an dem der Stand dem Versorger gemeldet wurde (`reported_on`):
     * JJJJ-MM-TT oder leer (null). Belegt die Meldung für den Hinweis „Schätzung trotz gemeldetem
     * Stand“ (Entscheidung 9b: mit Beleg die starke Formulierung).
     */
    private function reportedOn(array $input): ?string
    {
        $v = trim((string)($input['reported_on'] ?? ''));
        if ($v === '') return null;
        if (!Dates::isIsoDate($v)) throw new \InvalidArgumentException($this->i18n->t('errors.reading.reportedOnInvalid', ['date' => mb_substr($v, 0, 40)]));
        Input::assertDateInRange($v, 'reported_on');
        return $v;
    }

    /**
     * Ablesedatum: Kalendertag JJJJ-MM-TT, seit v3.12.0 auch im Jahresbereich
     * (Dates::MIN_INPUT_YEAR bis MAX_INPUT_YEAR) — eine Ablesung im Jahr 0001 legte
     * Übersicht, „Zu tun“ und Agenda lahm, und das erste Gerät wurde dauerhaft auf
     * 0001-01-01 vorverlegt.
     */
    private function assertDate(string $date): void
    {
        if (!Dates::isIsoDate($date)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.reading.dateInvalid', ['date' => $date]));
        }
        Input::assertDateInRange($date);
    }

    /**
     * v3.12.0 — höchster Zählerstand: ein Zählwerk hat höchstens zwölf Stellen
     * (MeterService, `digits`). `1e308` war bisher gültig; Verbrauch und Kosten
     * wurden unendlich, und die Antwort blieb leer.
     */
    public const MAX_COUNTER = 1e12;

    /** v3.12.0 — höchster Preis je Ablesung in ct je Verbrauchseinheit (1.000 €) */
    public const MAX_PRICE_CENTS = 100000.0;

    /**
     * Zählerstand als Zahl ≥ 0. Zahlen und Zahl-Strings mit Punkt sind gültig;
     * „12,5" nicht — mehrdeutig (Dezimal- oder Tausenderkomma) und bisher still
     * zu 12 abgeschnitten. Das Frontend wandelt Kommas vorher selbst um.
     */
    private function parseCounter(mixed $raw): float
    {
        if (is_bool($raw) || !is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0 || (float)$raw > self::MAX_COUNTER) {
            $shown = is_scalar($raw) ? (string)$raw : gettype($raw);
            throw new \InvalidArgumentException($this->i18n->t('errors.reading.counterInvalid', ['value' => $shown]));
        }
        return (float)$raw;
    }

    /**
     * v3.12.0 — Preis je Ablesung (ct je Verbrauchseinheit): leer = kein Preis
     * (null), sonst eine Zahl von 0 bis MAX_PRICE_CENTS. Anders als beim
     * Zählerstand ist ein Komma hier eindeutig der Dezimaltrenner (Support\Input::decimal):
     * „12,5“ ist 12,5 ct. Bisher wurde „12,5“ still 12, „abc“ 0, und −99 ging durch —
     * der Preis fließt in die Monatskosten.
     */
    private function parsePrice(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') return null;
        $v = Input::decimal($raw);
        if ($v === null || $v < 0 || $v > self::MAX_PRICE_CENTS) {
            $shown = is_scalar($raw) ? (string)$raw : gettype($raw);
            throw new LocalizedException('errors.reading.priceInvalid', ['value' => mb_substr($shown, 0, 40)], 'reading price_cents');
        }
        return $v;
    }

    /** v3.12.0 — Merker wie „geschätzt“ als Wahrheitswert; `(bool)"nein"` war bisher true. */
    private function parseFlag(array $input, string $key): bool
    {
        if (!array_key_exists($key, $input)) return false;
        $b = Input::bool($input[$key]);
        if ($b === null) throw new LocalizedException('errors.common.boolExpected', ['field' => $key], "field $key: bool expected");
        return $b;
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
