<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;

/**
 * Tagestemperaturen (`data/temperatures.json` als Map
 * `YYYY-MM-DD → {avg, min, max}`).
 *
 * Liefert die Lookup-Datenbasis für die HGT-Berechnung im
 * `ConsumptionService` und für die Forecast-Saisonalität.
 *
 * Quellen:
 *   - CSV-Import im Format `DD.MM.YYYY"avg"min"max` (double-quote-getrennt)
 *   - Open-Meteo-Sync via `WeatherService` (Archive + Forecast)
 *   - manuelles `POST /api/temperatures` Upsert pro Tag
 */
final class TemperatureService
{
    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
        private WeatherService $weather,
    ) {}

    /** @return array<string,array{avg:float,min:float,max:float}> */
    public function all(): array
    {
        $t = $this->store->read('temperatures.json', []);
        return is_array($t) ? $t : [];
    }

    public function upsert(string $date, float $avg, float $min, float $max): void
    {
        $all = $this->all();
        $all[$date] = ['avg' => $avg, 'min' => $min, 'max' => $max];
        ksort($all);
        $this->store->write('temperatures.json', $all);
    }

    public function bulkUpsert(array $entries): int
    {
        $all = $this->all();
        $count = 0;
        foreach ($entries as $date => $vals) {
            if (!is_string($date)) continue;
            if (!is_array($vals)) continue;
            if (!isset($vals['avg'], $vals['min'], $vals['max'])) continue;
            $all[$date] = [
                'avg' => (float)$vals['avg'],
                'min' => (float)$vals['min'],
                'max' => (float)$vals['max'],
            ];
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
    public function importCsv(string $csv): array
    {
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
            // Split on double-quote (allow ; or , as fallback)
            $parts = preg_split('/"+/', $line);
            if (count($parts) < 4) {
                $parts = preg_split('/[;,]/', $line);
            }
            if (count($parts) < 4) { $skipped++; continue; }
            $date = trim((string)$parts[0]);
            // Convert DD.MM.YYYY → YYYY-MM-DD
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $m)) {
                $iso = "$m[3]-$m[2]-$m[1]";
            } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date)) {
                $iso = $date;
            } else { $skipped++; continue; }
            $avg = $this->parseNum((string)$parts[1]);
            $min = $this->parseNum((string)$parts[2]);
            $max = $this->parseNum((string)$parts[3]);
            if ($avg === null || $min === null || $max === null) {
                $skipped++; continue;
            }
            $entries[$iso] = ['avg' => $avg, 'min' => $min, 'max' => $max];
        }
        $imported = $this->bulkUpsert($entries);
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function syncOpenMeteo(?string $start = null, ?string $end = null): array
    {
        $lat = (float)$this->settings->get('latitude', 51.3397);
        $lon = (float)$this->settings->get('longitude', 12.3731);
        $start = $start ?? $this->lastTempDate() ?? date('Y-m-d', strtotime('-30 days'));
        $end   = $end ?? date('Y-m-d', strtotime('-6 days')); // archive has ~5-day lag

        $archive = [];
        if ($start <= $end) {
            $archiveRes = $this->weather->fetchArchive($lat, $lon, $start, $end);
            $archive = $archiveRes['data'] ?? [];
        }
        $forecast = $this->weather->fetchForecast($lat, $lon, 14, 7);
        $forecastData = $forecast['data'] ?? [];

        $merged = $archive + $forecastData; // archive wins over forecast on overlap
        $imported = $this->bulkUpsert($merged);

        return [
            'imported'          => $imported,
            'archive_rows'      => count($archive),
            'forecast_rows'     => count($forecastData),
            'archive_range'     => "$start..$end",
            'archive_error'     => $archiveRes['error'] ?? null,
            'forecast_error'    => $forecast['error'] ?? null,
        ];
    }

    private function lastTempDate(): ?string
    {
        $all = $this->all();
        if (empty($all)) return null;
        $dates = array_keys($all);
        sort($dates);
        return end($dates) ?: null;
    }

    private function parseNum(string $s): ?float
    {
        $s = trim($s);
        if ($s === '') return null;
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    }
}
