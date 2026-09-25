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
    ) {}

    /** @return array<string,array{avg:float,min:float,max:float}> */
    public function all(): array
    {
        $t = $this->store->read('temperatures.json', []);
        return is_array($t) ? $t : [];
    }

    public function upsert(string $date, float $avg, float $min, float $max, string $source = 'manual'): void
    {
        $all = $this->all();
        $all[$date] = ['avg' => $avg, 'min' => $min, 'max' => $max, 'source' => $source];
        ksort($all);
        $this->store->write('temperatures.json', $all);
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
            if ($source !== null) $row['source'] = $source;
            $all[$date] = $row;
            $count++;
        }
        ksort($all);
        $this->store->write('temperatures.json', $all);
        return $count;
    }

    public function delete(string $date): void
    {
        $all = $this->all();
        unset($all[$date]);
        $this->store->write('temperatures.json', $all);
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
        foreach ($lines as $lineNo => $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Skip header
            if ($lineNo === 0 && (stripos($line, 'datum') !== false || stripos($line, 'temperatur') !== false)) {
                continue;
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
            $date = trim((string)$parts[0]);
            // Convert DD.MM.YYYY → YYYY-MM-DD
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $m)) {
                $iso = "$m[3]-$m[2]-$m[1]";
            } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date)) {
                $iso = $date;
            } else { $skipped++; continue; }
            // v2.5.3 — kalendergültig, sonst bricht der Schlüssel die HGT-Rechnung
            if (!Dates::isIsoDate($iso)) { $skipped++; continue; }
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

        // Erste Lücke im Messbestand ab $from — ab dort das Archiv holen.
        $archiveFrom = null;
        if ($from <= $end) {
            if ($reload) {
                $archiveFrom = $from;
            } else {
                for ($d = $from; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                    if (!$measured($d, $all[$d] ?? null)) { $archiveFrom = $d; break; }
                }
            }
        }

        $archiveRows = 0; $archiveError = null;
        if ($archiveFrom !== null) {
            $res = $this->weather->fetchArchive($lat, $lon, $archiveFrom, $end);
            $archiveError = $res['error'] ?? null;
            $archiveRows = $this->bulkUpsert($res['data'] ?? [], 'archive',
                fn(string $d, ?array $e): bool => $reload
                    ? !in_array($e['source'] ?? null, ['csv', 'manual'], true)
                    : !$measured($d, $e));
        }

        // Vorhersage (und die jüngsten Tage): nur wo kein Messwert steht.
        $forecast = $this->weather->fetchForecast($lat, $lon, 14, 7);
        $forecastRows = $this->bulkUpsert($forecast['data'] ?? [], 'forecast',
            fn(string $d, ?array $e): bool => !$measured($d, $e) || ($e['source'] ?? null) === 'forecast');

        $climate = $this->ensureClimateNormal($lat, $lon);

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
            'forecast_error'  => $forecast['error'] ?? null,
            'climate_normal'  => $climate,
        ];
        $this->store->write(self::META_FILE, $meta);

        return [
            'imported'        => $archiveRows + $forecastRows,
            'archive_rows'    => $archiveRows,
            'forecast_rows'   => $forecastRows,
            'archive_range'   => $archiveFrom !== null ? "$archiveFrom..$end" : null,
            'archive_error'   => $archiveError,
            'forecast_error'  => $forecast['error'] ?? null,
            'measured_until'  => $measuredUntil,
            'forecast_until'  => $forecastUntil,
            'climate_normal'  => $climate,
        ];
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
     * Holt das Klimanormal, wenn es fehlt oder veraltet ist.
     * @return array<string,mixed>|null Zusammenfassung oder null ohne Normaldienst
     */
    private function ensureClimateNormal(float $lat, float $lon): ?array
    {
        if ($this->climate === null) return null;
        if (!$this->climate->isStale(round($lat, 2), round($lon, 2))) {
            return ['status' => 'present'] + ($this->climate->summary() ?? []);
        }
        $period = ClimateNormalService::period();
        $res = $this->weather->fetchArchiveMeans($lat, $lon, $period['from'], $period['to']);
        if (($res['data'] ?? []) === []) {
            return ['status' => 'failed', 'error' => $res['error'] ?? null] + ($this->climate->summary() ?? []);
        }
        $this->climate->save(ClimateNormalService::compute($res['data'], $lat, $lon, $period));
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
