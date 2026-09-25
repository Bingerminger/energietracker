<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * Open-Meteo-API-Client. Holt zwei Datenquellen für eine Koordinate:
 *   1. Archive (`archive-api.open-meteo.com`): historische Tageswerte
 *      (zurück bis 1940), mit avg/min/max pro Tag.
 *   2. Forecast (`api.open-meteo.com/v1/forecast`): bis 16 Tage in die
 *      Zukunft und einige zurück, gleiche Aggregation.
 *
 * v2.8.0 — Der Standort geht auf zwei Nachkommastellen gerundet hinaus
 * (rund 1 km; Review API-24). Vier Stellen waren praktisch die Hausadresse,
 * das Wettergitter ist ohnehin kilometergrob.
 *
 * Benötigt `curl` als PHP-Extension (siehe DiagnosticsService).
 *
 * v2.7.0 — Tagesgrenzen in der Zeitzone der Installation (Einstellung
 * `timezone`, vom Bootstrap als PHP-Standardzeitzone gesetzt). Vorher stand
 * hier fest Europe/Berlin; in Lissabon oder London verschob das die
 * Tagesmittel um eine Stunde.
 */
final class WeatherService implements WeatherSource
{
    private const ARCHIVE_API  = 'https://archive-api.open-meteo.com/v1/archive';
    private const FORECAST_API = 'https://api.open-meteo.com/v1/forecast';

    public function fetchArchive(float $lat, float $lon, string $start, string $end): array
    {
        return $this->fetchAndParse($this->archiveUrl($lat, $lon, $start, $end), 60);
    }

    public function fetchForecast(float $lat, float $lon, int $forecastDays = 14, int $pastDays = 0): array
    {
        return $this->fetchAndParse($this->forecastUrl($lat, $lon, $forecastDays, $pastDays), 30);
    }

    /**
     * v2.8.0 — nur Tagesmittel, für das Klimanormal (30 Jahre; ein Drittel der
     * Antwortgröße gegenüber avg/min/max).
     *
     * @return array{data:array<string,float>,error:?string}
     */
    public function fetchArchiveMeans(float $lat, float $lon, string $start, string $end): array
    {
        $url = sprintf(
            '%s?latitude=%.2f&longitude=%.2f&start_date=%s&end_date=%s&daily=temperature_2m_mean&timezone=%s',
            self::ARCHIVE_API, $lat, $lon, $start, $end, self::timezone()
        );
        $resp = $this->httpGet($url, 120);
        if (!$resp['ok']) return ['data' => [], 'error' => $resp['error']];
        $data = json_decode((string)$resp['body'], true);
        if (!is_array($data) || !isset($data['daily']['time'])) {
            return ['data' => [], 'error' => 'Unerwartetes Antwortformat von Open-Meteo'];
        }
        $out = [];
        foreach ($data['daily']['time'] as $i => $date) {
            $v = $data['daily']['temperature_2m_mean'][$i] ?? null;
            if ($v !== null) $out[(string)$date] = (float)$v;
        }
        return ['data' => $out, 'error' => $out ? null : 'Antwort 200 OK, aber 0 verwendbare Tage'];
    }

    public function archiveUrl(float $lat, float $lon, string $start, string $end): string
    {
        return sprintf(
            '%s?latitude=%.2f&longitude=%.2f&start_date=%s&end_date=%s&daily=temperature_2m_mean,temperature_2m_min,temperature_2m_max&timezone=%s',
            self::ARCHIVE_API, $lat, $lon, $start, $end, self::timezone()
        );
    }

    public function forecastUrl(float $lat, float $lon, int $forecastDays = 14, int $pastDays = 0): string
    {
        return sprintf(
            '%s?latitude=%.2f&longitude=%.2f&daily=temperature_2m_mean,temperature_2m_min,temperature_2m_max&forecast_days=%d&past_days=%d&timezone=%s',
            self::FORECAST_API, $lat, $lon,
            max(0, min(16, $forecastDays)), max(0, min(92, $pastDays)), self::timezone()
        );
    }

    /** Zeitzone für die Tagesgrenzen, URL-kodiert (Europe%2FBerlin). */
    private static function timezone(): string
    {
        return rawurlencode(date_default_timezone_get());
    }

    private function fetchAndParse(string $url, int $timeout): array
    {
        $resp = $this->httpGet($url, $timeout);
        if (!$resp['ok']) {
            return ['data' => [], 'error' => $resp['error'], 'url' => $url, 'http_code' => $resp['http_code']];
        }
        $data = json_decode($resp['body'], true);
        if (!is_array($data) || !isset($data['daily']['time'])) {
            return ['data' => [], 'error' => 'Unerwartetes Antwortformat von Open-Meteo', 'url' => $url];
        }
        $out = [];
        $times = $data['daily']['time'];
        foreach ($times as $i => $date) {
            $a = $data['daily']['temperature_2m_mean'][$i] ?? null;
            $mi = $data['daily']['temperature_2m_min'][$i] ?? null;
            $ma = $data['daily']['temperature_2m_max'][$i] ?? null;
            if ($a !== null && $mi !== null && $ma !== null) {
                $out[$date] = ['avg' => (float)$a, 'min' => (float)$mi, 'max' => (float)$ma];
            }
        }
        return [
            'data'      => $out,
            'error'     => empty($out) ? 'Antwort 200 OK, aber 0 verwendbare Tage' : null,
            'url'       => $url,
            'http_code' => $resp['http_code'],
            'rows'      => count($out),
        ];
    }

    private function httpGet(string $url, int $timeout): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'Energietracker/1.0 (PHP)',
            ]);
            $body  = curl_exec($ch);
            $errno = curl_errno($ch);
            $cerr  = curl_error($ch);
            $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($errno) {
                return ['ok' => false, 'body' => null, 'http_code' => null,
                        'error' => "cURL #$errno: " . ($cerr ?: 'unbekannt')];
            }
            if ($code !== 200) {
                return ['ok' => false, 'body' => $body ?: null, 'http_code' => $code,
                        'error' => "HTTP $code"];
            }
            return ['ok' => true, 'body' => (string)$body, 'http_code' => 200, 'error' => null];
        }

        if (!ini_get('allow_url_fopen')) {
            return ['ok' => false, 'body' => null, 'http_code' => null,
                    'error' => 'Neither cURL nor allow_url_fopen available'];
        }
        $ctx = stream_context_create(['http' => [
            'timeout' => $timeout, 'ignore_errors' => true,
            'user_agent' => 'Energietracker/1.0 (PHP)',
        ]]);
        $r = @file_get_contents($url, false, $ctx);
        if ($r === false) {
            $err = error_get_last();
            return ['ok' => false, 'body' => null, 'http_code' => null,
                    'error' => 'file_get_contents fehlgeschlagen: ' . ($err['message'] ?? '?')];
        }
        return ['ok' => true, 'body' => $r, 'http_code' => 200, 'error' => null];
    }
}
