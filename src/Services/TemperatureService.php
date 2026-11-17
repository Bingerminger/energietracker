<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;

/**
 * Tagestemperaturen (`data/temperatures.json` als Map
 * `YYYY-MM-DD → {avg, min, max, source?}`).
 *
 * Liefert die Lookup-Datenbasis für die HGT-Berechnung im
 * `ConsumptionService` und für die Forecast-Saisonalität.
 *
 * Quellen:
 *   - CSV-Import im Format `DD.MM.YYYY"avg"min"max` (double-quote-getrennt)
 *   - Open-Meteo-Sync via `WeatherService` (Archive + Forecast)
 *   - manuelles `POST /api/temperatures` Upsert pro Tag
 *
 * v2.8.0 (Review CALC-08, DOC-08) — der Sync war für Nutzer mit Historie
 * wirkungslos:
 *   - Er begann beim letzten gespeicherten Tag, ohne Bestand bei heute − 30.
 *     Wer zwei Jahre Ablesungen nacherfasste, bekam einen Monat Temperaturen
 *     — keine Heizgradtage, keine Regression, keine Bereinigung, still.
 *   - Er speicherte 14 Tage Vorhersage als normale Tageswerte. Danach lag der
 *     „letzte Tag" in der Zukunft, und das Archiv wurde nie mehr abgerufen;
 *     Vorhersagen blieben als Messwerte stehen.
 * Jetzt trägt jeder neue Eintrag seine Quelle (`archive`, `forecast`, `csv`,
 * `manual`). Der Sync füllt jede Lücke ab der ersten Ablesung oder Lieferung
 * und ersetzt Vorhersagen, sobald das Archiv den Tag kennt. CSV- und
 * Handwerte überschreibt er nie; Einträge aus der Zeit vor v2.8.0 (ohne
 * Quelle) nur auf ausdrücklichen Wunsch (`reload`) — ein Update soll keine
 * Werte ändern, die niemand angefasst hat (Lektion 36).
 *
 * v3.13.0 (Paket sonne) — je Tag optional `rad`: die Einstrahlung
 * (Globalstrahlung, Open-Meteo `shortwave_radiation_sum`) in kWh/m² am Tag, im
 * selben Abruf wie die Temperatur (Archiv, Abgleich, Vorhersage). Tage ohne
 * `rad` bleiben gültig. Gemessene Tage ohne Einstrahlung (Bestand, CSV, Hand)
 * bekommen sie beim nächsten Archivabruf nachgetragen, ihre Temperaturen
 * bleiben; welcher Zeitraum schon danach gefragt wurde, merkt sich
 * `weather_sync.json` (`radiation_checked`), damit kein Abgleich dieselben
 * Jahre wieder holt.
 */
final class TemperatureService
{
    /** Das Archiv hinkt einige Tage hinterher; jüngere Tage liefert die Vorhersage. */
    public const ARCHIVE_LAG_DAYS = 6;
    /** Zustand des letzten Syncs (Zeitpunkt, Fehler) — Cache, kein Nutzdatum. */
    public const META_FILE = 'weather_sync.json';

    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
        private WeatherSource $weather,
        private ?ClimateNormalService $climate = null,
        private ?I18nService $i18n = null,
    ) {}

    /**
     * v3.1.0 (Review I18N-14) — Fehler des Wetterabgleichs in der Sprache der
     * Anfrage. Ohne Code (Testdoubles) oder ohne Übersetzung bleibt der Rohtext.
     */
    private function weatherError(array $res): ?string
    {
        if (($res['error'] ?? null) === null) return null;
        $code = $res['error_code'] ?? null;
        if ($code === null || $this->i18n === null) return (string)$res['error'];
        return $this->i18n->t("errors.weather.$code", [
            'detail' => (string)($res['detail'] ?? ''), 'status' => (string)($res['http_code'] ?? ''),
        ]);
    }

    /** @return array<string,array{avg:float,min:float,max:float}> */
    public function all(): array
    {
        $t = $this->store->read('temperatures.json', []);
        return is_array($t) ? $t : [];
    }

    public function upsert(string $date, float $avg, float $min, float $max, string $source = 'manual'): void
    {
        $all = $this->all();
        $row = ['avg' => $avg, 'min' => $min, 'max' => $max, 'source' => $source];
        // v3.13.0 (Paket sonne) — die gemessene Einstrahlung des Tags bleibt (eine Handeingabe ändert die Temperatur)
        $rad = self::keptRadiation($all[$date] ?? null);
        if ($rad !== null) $row['rad'] = $rad;
        $all[$date] = $row;
        ksort($all);
        $this->store->write('temperatures.json', $all);
    }

    /**
     * v3.13.0 (Paket sonne) — Einstrahlung eines Eintrags (kWh/m² am Tag), null ohne verwertbaren Wert. Gültig sind
     * endliche Zahlen von 0 bis RAD_MAX (über 12 kWh/m² am Tag kommt auf der Erde nicht an).
     */
    public static function radValue(mixed $v): ?float
    {
        if ($v === null || is_bool($v) || !is_numeric($v) || !is_finite((float)$v) || (float)$v < 0 || (float)$v > self::RAD_MAX) return null;
        return (float)$v;
    }

    /** v3.13.0 — größte gültige Einstrahlung eines Tags in kWh/m² (Schreibweg, Backup-Import). */
    public const RAD_MAX = 15.0;

    /** Einstrahlung eines bestehenden Eintrags, die ein neuer Wert ohne `rad` behält: nur gemessene, keine Vorhersage. */
    private static function keptRadiation(mixed $prev): ?float
    {
        if (!is_array($prev) || ($prev['source'] ?? null) === 'forecast') return null;
        return self::radValue($prev['rad'] ?? null);
    }

    /**
     * @param array<string,array<string,mixed>> $entries
     * @param string|null $source  Quelle für alle Einträge (`null` = unverändert lassen)
     * @param callable(string,?array):bool|null $mayWrite  darf der Tag überschrieben werden?
     */
    public function bulkUpsert(array $entries, ?string $source = null, ?callable $mayWrite = null): int
    {
        $all = $this->all();
        $count = 0;
        foreach ($entries as $date => $vals) {
            if (!is_string($date)) continue;
            if (!is_array($vals)) continue;
            if (!isset($vals['avg'], $vals['min'], $vals['max'])) continue;
            if ($mayWrite !== null && !$mayWrite($date, $all[$date] ?? null)) continue;
            $row = [
                'avg' => (float)$vals['avg'],
                'min' => (float)$vals['min'],
                'max' => (float)$vals['max'],
            ];
            // v3.13.0 (Paket sonne) — Einstrahlung, optional; bringt der neue Wert keine mit (CSV), bleibt die
            // gemessene des Tags stehen
            $rad = self::radValue($vals['rad'] ?? null) ?? self::keptRadiation($all[$date] ?? null);
            if ($rad !== null) $row['rad'] = $rad;
            if ($source !== null) $row['source'] = $source;
            $all[$date] = $row;
            $count++;
        }
        ksort($all);
        $this->store->write('temperatures.json', $all);
        return $count;
    }

    /**
     * @return bool ob es den Tag gab. v3.12.0 — ohne Wert (oder kein Datum) wird nichts geschrieben,
     *              der Aufruf meldet 404; bisher 200 `deleted: true` und die Datei neu geschrieben
     */
    public function delete(string $date): bool
    {
        $all = $this->all();
        if (!array_key_exists($date, $all)) return false;
        unset($all[$date]);
        $this->store->write('temperatures.json', $all);
        return true;
    }

    /**
     * Parse the legacy CSV format: DD.MM.YYYY"avg"min"max (double-quote-delimited).
     * Returns count of imported rows.
     */
    /**
     * v2.12.0 — Ortssuche für den Wetterstandort (Review UI-30).
     *
     * @return array{data:list<array<string,mixed>>,error:?string}
     */
    public function geocode(string $query, string $language = 'de'): array
    {
        return $this->weather->geocode(trim($query), $language);
    }

    public function importCsv(string $csv): array
    {
        // v2.6.0 — BOM und Windows-1252 wie beim Ablesungs-Import; sonst
        // scheiterte die erste Datenzeile einer Datei ohne Kopfzeile am BOM.
        [$csv] = \Energietracker\Support\Encoding::normalizeCsv($csv);
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $imported = 0; $skipped = 0; $errors = [];
        $entries = [];
        $first = true;
        foreach ($lines as $lineNo => $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Kopfzeile — v3.1.0 (Review I18N-11) in jeder Sprache: die erste Zeile,
            // die nicht mit einem Datum beginnt („Date;Moyenne;Min;Max", „Fecha;…").
            if ($first) {
                $first = false;
                $cell = (string)(preg_split('/[;,\t"]/', $line)[0] ?? '');
                if (preg_match('/\p{L}/u', $line) && Dates::parseUserDate($cell) === null) continue;
            }
            // Split on double-quote (allow ; , or tab as fallback).
            // v2.12.0 (Review UI-30) — das übliche Format ist jetzt
            // `TT.MM.JJJJ;Mittel;Min;Max`; das alte mit Anführungszeichen als
            // Trenner bleibt lesbar, Tabulator kommt dazu.
            // Ein Trenner je Zeile: Bis v2.11 trennte `[;,]` auch am
            // Dezimalkomma — aus „01.01.2024;4,2;-1,0;7,1" (Excel deutsch)
            // wurden Mittel 4, Min 2, Max −1.
            $parts = preg_split('/"+/', $line);
            if (count($parts) < 4) {
                $sep = str_contains($line, ';') ? ';' : (str_contains($line, "\t") ? "\t" : ',');
                $parts = explode($sep, $line);
            }
            if (count($parts) < 4) { $skipped++; continue; }
            // TT.MM.JJJJ, TT/MM/JJJJ, TT-MM-JJJJ (v3.1.0) oder ISO — kalendergültig
            // (v2.5.3), sonst bricht der Schlüssel die HGT-Rechnung
            $iso = Dates::parseUserDate((string)$parts[0]);
            if ($iso === null) { $skipped++; continue; }
            $avg = $this->parseNum((string)$parts[1]);
            $min = $this->parseNum((string)$parts[2]);
            $max = $this->parseNum((string)$parts[3]);
            if ($avg === null || $min === null || $max === null) {
                $skipped++; continue;
            }
            $entries[$iso] = ['avg' => $avg, 'min' => $min, 'max' => $max];
        }
        $imported = $this->bulkUpsert($entries, 'csv');
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Sync mit Open-Meteo.
     *
     * @param string|null $start  erster Tag; ohne Angabe die erste Ablesung
     *                            oder Lieferung (sonst heute − 30)
     * @param string|null $end    letzter Archivtag; ohne Angabe heute − 6
     * @param bool $reload  auch Einträge ohne Quelle (vor v2.8.0) im Zeitraum
     *                      durch Archivwerte ersetzen
     * @param bool $auto    automatischer Aufruf beim App-Start: höchstens
     *                      einmal am Tag, sonst sofort zurück
     */
    public function syncOpenMeteo(?string $start = null, ?string $end = null, bool $reload = false, bool $auto = false): array
    {
        $today = date('Y-m-d');
        $meta  = $this->meta();
        if ($auto && ($meta['last_sync_date'] ?? null) === $today) {
            return ['skipped' => true, 'reason' => 'already_today', 'last_sync_at' => $meta['last_sync_at'] ?? null];
        }

        $lat = (float)$this->settings->get('latitude', 51.3397);
        $lon = (float)$this->settings->get('longitude', 12.3731);
        $horizon = date('Y-m-d', strtotime("-" . self::ARCHIVE_LAG_DAYS . " days"));
        $end   = $end ?? $horizon;
        $from  = $start ?? $this->earliestDataDate() ?? date('Y-m-d', strtotime('-30 days'));
        if ($from < '1940-01-01') $from = '1940-01-01';

        $all = $this->all();
        $measured = fn(string $date, ?array $e): bool => self::isMeasured($date, $e, $horizon);
        // v3.13.0 (Paket sonne) — gemessene Tage ohne Einstrahlung (Bestand von vor v3.13.0, CSV, Hand) holt derselbe
        // Archivabruf nach, aber nur außerhalb des Zeitraums, für den das Archiv schon gefragt wurde: Liefert es für
        // einen Tag keine, fragt nicht jeder Abgleich wieder alle Jahre ab
        $radChecked = is_array($meta['radiation_checked'] ?? null) ? $meta['radiation_checked'] : null;
        $radAsked = fn(string $d): bool => $radChecked !== null
            && $d >= (string)($radChecked['from'] ?? '9999') && $d <= (string)($radChecked['to'] ?? '0000');
        $needsRad = fn(string $d, ?array $e): bool => is_array($e) && self::radValue($e['rad'] ?? null) === null && !$radAsked($d);

        // Erste Lücke im Messbestand ab $from — ab dort das Archiv holen.
        $archiveFrom = null;
        if ($from <= $end) {
            if ($reload) {
                $archiveFrom = $from;
            } else {
                for ($d = $from; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                    $e = is_array($all[$d] ?? null) ? $all[$d] : null;
                    if (!$measured($d, $e) || $needsRad($d, $e)) { $archiveFrom = $d; break; }
                }
            }
        }

        // v3.4.0 (N1020) — erst alles abrufen (Archiv, Vorhersage, Klimanormal),
        // dann sperren und schreiben: Bis v3.3 hielt der Abgleich die
        // Schreibsperre für die ganze Wartezeit auf Open-Meteo (bis zu 120 s).
        $archiveRows = 0; $archiveError = null; $archiveCode = null;
        $res = $archiveFrom !== null ? $this->weather->fetchArchive($lat, $lon, $archiveFrom, $end) : null;
        // Vorhersage (und die jüngsten Tage): nur wo kein Messwert steht.
        $forecast = $this->weather->fetchForecast($lat, $lon, 14, 7);
        $climateFetch = $this->fetchClimateNormal($lat, $lon);

        $this->store->lock();
        $radiationRows = 0;
        if ($res !== null) {
            $archiveError = $this->weatherError($res);
            $archiveCode = $res['error_code'] ?? null;
            $archiveRows = $this->bulkUpsert($res['data'] ?? [], 'archive',
                fn(string $d, ?array $e): bool => $reload
                    ? !in_array($e['source'] ?? null, ['csv', 'manual'], true)
                    : !$measured($d, $e));
            // v3.13.0 (Paket sonne) — Einstrahlung an gemessenen Tagen nachtragen, Temperaturen unverändert
            $radiationRows = $this->addRadiation($res['data'] ?? []);
            if (($res['data'] ?? []) !== []) $radChecked = self::radiationRange($radChecked, (string)$archiveFrom, $end, $res['data']);
        }
        $forecastRows = $this->bulkUpsert($forecast['data'] ?? [], 'forecast',
            fn(string $d, ?array $e): bool => !$measured($d, $e) || ($e['source'] ?? null) === 'forecast');

        $climate = $this->saveClimateNormal($climateFetch, $lat, $lon);

        $all = $this->all();
        $measuredUntil = null; $forecastUntil = null;
        foreach ($all as $d => $e) {
            if ($measured((string)$d, $e)) $measuredUntil = max($measuredUntil ?? '', (string)$d);
            elseif (($e['source'] ?? null) === 'forecast' || !isset($e['source'])) $forecastUntil = max($forecastUntil ?? '', (string)$d);
        }

        $meta = [
            'last_sync_at'    => date('c'),
            'last_sync_date'  => $today,
            'measured_until'  => $measuredUntil,
            'forecast_until'  => $forecastUntil,
            'archive_error'   => $archiveError,
            'archive_error_code'  => $archiveCode,
            'forecast_error'  => $this->weatherError($forecast),
            'forecast_error_code' => $forecast['error_code'] ?? null,
            'climate_normal'  => $climate,
        ];
        if ($radChecked !== null) $meta['radiation_checked'] = $radChecked;   // v3.13.0 (Paket sonne)
        $this->store->write(self::META_FILE, $meta);

        return [
            'imported'        => $archiveRows + $forecastRows,
            'archive_rows'    => $archiveRows,
            'forecast_rows'   => $forecastRows,
            'archive_range'   => $archiveFrom !== null ? "$archiveFrom..$end" : null,
            'archive_error'   => $archiveError,
            'archive_error_code'  => $archiveCode,
            'forecast_error'  => $this->weatherError($forecast),
            'forecast_error_code' => $forecast['error_code'] ?? null,
            'measured_until'  => $measuredUntil,
            'forecast_until'  => $forecastUntil,
            'climate_normal'  => $climate,
            // v3.13.0 (Paket sonne, additiv) — gemessene Tage, die ihre Einstrahlung nachgetragen bekamen
            'radiation_rows'  => $radiationRows,
        ];
    }

    /**
     * v3.13.0 (Paket sonne) — trägt die Einstrahlung an gemessenen Tagen nach, die noch keine haben (Bestand von
     * vor v3.13.0, CSV, Hand). Temperaturen und Quelle bleiben, wie sie sind (Lektion 36).
     *
     * @param array<string,mixed> $entries Archivwerte je Tag
     */
    private function addRadiation(array $entries): int
    {
        $all = $this->all();
        $n = 0;
        foreach ($entries as $date => $vals) {
            if (!is_string($date) || !is_array($vals) || !is_array($all[$date] ?? null)) continue;
            if (($all[$date]['source'] ?? null) === 'forecast' || self::radValue($all[$date]['rad'] ?? null) !== null) continue;
            $rad = self::radValue($vals['rad'] ?? null);
            if ($rad === null) continue;
            $all[$date]['rad'] = $rad;
            $n++;
        }
        if ($n > 0) $this->store->write('temperatures.json', $all);
        return $n;
    }

    /**
     * v3.13.0 (Paket sonne) — Zeitraum, für den das Archiv schon nach der Einstrahlung gefragt wurde. Er reicht bis
     * zum letzten Tag, für den es eine lieferte (ein jüngerer Tag ohne kommt beim nächsten Abgleich wieder dran);
     * liefert es gar keine, bis zum Ende des Abrufs. Ein Abruf, der an den alten Zeitraum anschließt, verlängert ihn.
     *
     * @param array{from?:string,to?:string}|null $prev
     * @param array<string,mixed> $data
     * @return array{from:string,to:string}
     */
    private static function radiationRange(?array $prev, string $from, string $end, array $data): array
    {
        $last = null;
        foreach ($data as $d => $v) {
            if (is_array($v) && self::radValue($v['rad'] ?? null) !== null && ($last === null || (string)$d > $last)) $last = (string)$d;
        }
        $to = $last ?? $end;
        if ($prev !== null && isset($prev['from'], $prev['to'])
            && $from <= date('Y-m-d', (int)strtotime((string)$prev['to'] . ' +1 day')) && $to >= (string)$prev['from']) {
            return ['from' => min($from, (string)$prev['from']), 'to' => max($to, (string)$prev['to'])];
        }
        return ['from' => $from, 'to' => $to];
    }

    /** @return array<string,mixed> Zustand des letzten Syncs */
    public function meta(): array
    {
        $m = $this->store->read(self::META_FILE, []);
        return is_array($m) ? $m : [];
    }

    /**
     * Ist der Eintrag ein Messwert (Archiv, CSV, Hand)? Einträge ohne Quelle
     * stammen aus der Zeit vor v2.8.0: jenseits des Archivhorizonts können
     * sie nur Vorhersagen sein, davor gelten sie als gemessen.
     */
    public static function isMeasured(string $date, ?array $entry, string $horizon): bool
    {
        if ($entry === null) return false;
        $source = $entry['source'] ?? null;
        if ($source === null) return $date <= $horizon;
        return $source !== 'forecast';
    }

    /** Frühestes Datum einer Ablesung oder Lieferung über alle Verbrauchsarten. */
    public function earliestDataDate(): ?string
    {
        $min = null;
        foreach (Utilities::keys() as $u) {
            foreach (['readings.json', 'deliveries.json'] as $file) {
                $rows = $this->store->read("$u/$file", []);
                if (!is_array($rows)) continue;
                foreach ($rows as $r) {
                    $d = $r['date'] ?? null;
                    if (!Dates::isIsoDate($d) || !empty($r['is_future'])) continue;
                    if ($min === null || $d < $min) $min = $d;
                }
            }
        }
        return $min;
    }

    /**
     * Holt das Klimanormal, wenn es fehlt oder veraltet ist (ohne zu schreiben).
     * @return array{res:array<string,mixed>, period:array<string,string>}|null null: vorhanden oder ohne Normaldienst
     */
    private function fetchClimateNormal(float $lat, float $lon): ?array
    {
        if ($this->climate === null || !$this->climate->isStale(round($lat, 2), round($lon, 2))) return null;
        $period = ClimateNormalService::period();
        // v3.13.0 (Paket sonne) — fehlt nur die Einstrahlung, ergänzt derselbe Abruf sie für die dreißig Jahre des
        // gespeicherten Normals; seine Heizgradtage bleiben (saveClimateNormal)
        $radOnly = $this->climate->lacksOnlyRadiation(round($lat, 2), round($lon, 2));
        $stored = $radOnly ? ($this->climate->get()['period'] ?? null) : null;
        if (is_array($stored) && is_string($stored['from'] ?? null) && is_string($stored['to'] ?? null)) {
            $period = ['from' => $stored['from'], 'to' => $stored['to']];
        }
        return ['res' => $this->weather->fetchArchiveMeans($lat, $lon, $period['from'], $period['to']), 'period' => $period, 'radiation_only' => $radOnly];
    }

    /**
     * Speichert ein abgerufenes Klimanormal.
     * @param array{res:array<string,mixed>, period:array<string,string>}|null $fetched aus fetchClimateNormal()
     * @return array<string,mixed>|null Zusammenfassung oder null ohne Normaldienst
     */
    private function saveClimateNormal(?array $fetched, float $lat, float $lon): ?array
    {
        if ($this->climate === null) return null;
        if ($fetched === null) return ['status' => 'present'] + ($this->climate->summary() ?? []);
        $res = $fetched['res'];
        if (($res['data'] ?? []) === []) {
            return ['status' => 'failed', 'error' => $this->weatherError($res), 'error_code' => $res['error_code'] ?? null] + ($this->climate->summary() ?? []);
        }
        $rad = is_array($res['rad'] ?? null) ? $res['rad'] : [];
        $stored = !empty($fetched['radiation_only']) ? $this->climate->get() : null;
        if ($stored !== null) {
            // v3.13.0 (Paket sonne) — nur die Einstrahlung ergänzen (die Heizgradtage bleiben, Lektion 36)
            // v3.13.0 (Paket waermepumpe, E2) — und den Trend der Temperatur aus denselben Tagesmitteln
            $this->climate->save(ClimateNormalService::withRadiation($stored, $rad, is_array($res['data'] ?? null) ? $res['data'] : []));
            return ['status' => 'fetched', 'radiation_only' => true] + ($this->climate->summary() ?? []);
        }
        $this->climate->save(ClimateNormalService::compute($res['data'], $lat, $lon, $fetched['period'], $rad));
        return ['status' => 'fetched'] + ($this->climate->summary() ?? []);
    }

    private function parseNum(string $s): ?float
    {
        $s = trim($s);
        if ($s === '') return null;
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    }
}
