<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * Open-Meteo-API-Client. Holt zwei Datenquellen für eine Koordinate:
 *   1. Archive (`archive-api.open-meteo.com`): historische Tageswerte
 *      ab 2023, mit avg/min/max pro Tag.
 *   2. Forecast (`api.open-meteo.com/v1/forecast`): bis 14 Tage in die
 *      Zukunft, gleiche Aggregation.
 *
 * Benötigt `curl` als PHP-Extension (siehe DiagnosticsService).
 */
final class WeatherService
{
    private const ARCHIVE_API  = 'https://archive-api.open-meteo.com/v1/archive';
    private const FORECAST_API = 'https://api.open-meteo.com/v1/forecast';

    public function fetchArchive(float $lat, float $lon, string $start, string $end): array
    {
        $url = sprintf(
            '%s?latitude=%.4f&longitude=%.4f&start_date=%s&end_date=%s&daily=temperature_2m_mean,temperature_2m_min,temperature_2m_max&timezone=Europe%%2FBerlin',
            self::ARCHIVE_API, $lat, $lon, $start, $end
        );
        return $this->fetchAndParse($url, 60);
    }

    public function fetchForecast(float $lat, float $lon, int $forecastDays = 14, int $pastDays = 0): array
    {
        $url = sprintf(
            '%s?latitude=%.4f&longitude=%.4f&daily=temperature_2m_mean,temperature_2m_min,temperature_2m_max&forecast_days=%d&past_days=%d&timezone=Europe%%2FBerlin',
            self::FORECAST_API, $lat, $lon,
            max(0, min(16, $forecastDays)), max(0, min(92, $pastDays))
        );
        return $this->fetchAndParse($url, 30);
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
