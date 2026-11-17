<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Support\Outbound;

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
    private const GEOCODE_API  = 'https://geocoding-api.open-meteo.com/v1/search';
    /**
     * v3.13.0 (Paket sonne) — Globalstrahlung kommt als `shortwave_radiation_sum` in MJ/m² am Tag;
     * gespeichert wird `rad` in kWh/m² am Tag (1 kWh = 3,6 MJ).
     */
    public const MJ_PER_KWH = 3.6;

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
     * v3.13.0 (Paket sonne) — dazu die Einstrahlung je Tag (`rad`, kWh/m²) für die
     * Monatssummen des Normals (`rad_month`). Die Antwort wächst für 30 Jahre am
     * Standard-Standort von rund 190 auf rund 250 KB.
     *
     * @return array{data:array<string,float>,rad?:array<string,float>,error:?string}
     */
    public function fetchArchiveMeans(float $lat, float $lon, string $start, string $end): array
    {
        $resp = $this->httpGet($this->meansUrl($lat, $lon, $start, $end), 120);
        if (!$resp['ok']) return ['data' => []] + self::failure($resp);
        return self::parseMeans(json_decode((string)$resp['body'], true));
    }

    /** v3.13.0 — Adresse des Abrufs fürs Klimanormal (Tagesmittel und Einstrahlung). */
    public function meansUrl(float $lat, float $lon, string $start, string $end): string
    {
        return sprintf(
            '%s?latitude=%.2f&longitude=%.2f&start_date=%s&end_date=%s&daily=temperature_2m_mean,shortwave_radiation_sum&timezone=%s',
            self::ARCHIVE_API, $lat, $lon, $start, $end, self::timezone()
        );
    }

    /**
     * Antwort des Abrufs fürs Klimanormal → Tagesmittel und (v3.13.0, additiv) Einstrahlung je Tag.
     *
     * @return array{data:array<string,float>,rad?:array<string,float>,error:?string}
     */
    public static function parseMeans(mixed $data): array
    {
        if (!is_array($data) || !isset($data['daily']['time']) || !is_array($data['daily']['time'])) {
            return ['data' => [], 'error' => self::BAD_FORMAT, 'error_code' => 'badFormat'];
        }
        $out = []; $rad = [];
        foreach ($data['daily']['time'] as $i => $date) {
            $v = $data['daily']['temperature_2m_mean'][$i] ?? null;
            if ($v !== null) $out[(string)$date] = (float)$v;
            // v3.13.0 (Paket sonne) — Einstrahlung fürs Klimanormal (`rad_month`)
            $r = self::radiation($data['daily']['shortwave_radiation_sum'][$i] ?? null);
            if ($r !== null) $rad[(string)$date] = $r;
        }
        return $out ? ['data' => $out, 'rad' => $rad, 'error' => null] : ['data' => [], 'error' => self::NO_DAYS, 'error_code' => 'noDays'];
    }

    public function archiveUrl(float $lat, float $lon, string $start, string $end): string
    {
        return sprintf(
            '%s?latitude=%.2f&longitude=%.2f&start_date=%s&end_date=%s&daily=temperature_2m_mean,temperature_2m_min,temperature_2m_max,shortwave_radiation_sum&timezone=%s',
            self::ARCHIVE_API, $lat, $lon, $start, $end, self::timezone()
        );
    }

    public function forecastUrl(float $lat, float $lon, int $forecastDays = 14, int $pastDays = 0): string
    {
        return sprintf(
            '%s?latitude=%.2f&longitude=%.2f&daily=temperature_2m_mean,temperature_2m_min,temperature_2m_max,shortwave_radiation_sum&forecast_days=%d&past_days=%d&timezone=%s',
            self::FORECAST_API, $lat, $lon,
            max(0, min(16, $forecastDays)), max(0, min(92, $pastDays)), self::timezone()
        );
    }

    /** Zeitzone für die Tagesgrenzen, URL-kodiert (Europe%2FBerlin). */
    /**
     * v2.12.0 — Ortssuche über die Geocoding-API von Open-Meteo (Review UI-30).
     * Bis v2.11 musste man die Koordinaten selbst herausfinden. Übertragen wird
     * nur der Suchbegriff, an denselben Anbieter wie beim Wetterabgleich.
     */
    public function geocode(string $query, string $language = 'de', int $count = 5): array
    {
        $url = self::GEOCODE_API . '?name=' . rawurlencode($query)
            . '&count=' . max(1, min(10, $count))
            . '&language=' . rawurlencode($language) . '&format=json';
        $resp = $this->httpGet($url, 10);
        if (!$resp['ok']) return ['data' => []] + self::failure($resp);
        $json = json_decode((string)$resp['body'], true);
        if (!is_array($json)) return ['data' => [], 'error' => self::BAD_FORMAT, 'error_code' => 'badFormat'];
        $out = [];
        foreach (($json['results'] ?? []) as $r) {
            if (!is_array($r) || !isset($r['name'], $r['latitude'], $r['longitude'])) continue;
            $out[] = [
                'name'      => (string)$r['name'],
                'latitude'  => round((float)$r['latitude'], 4),
                'longitude' => round((float)$r['longitude'], 4),
                'country'   => isset($r['country']) ? (string)$r['country'] : null,
                'admin1'    => isset($r['admin1']) ? (string)$r['admin1'] : null,
                'postcode'  => isset($r['postcodes'][0]) ? (string)$r['postcodes'][0] : null,
            ];
        }
        return ['data' => $out, 'error' => null];
    }

    // v3.1.0 (Review I18N-14) — `error` ist der technische Text fürs Log (bis v3.0
    // landete er deutsch im Toast, auch in einer englischen Oberfläche).
    // `error_code` (badFormat, noDays, network, http, noTransport) übersetzt
    // TemperatureService über errors.weather.<code>.
    private const BAD_FORMAT = 'Unexpected response format from Open-Meteo';
    private const NO_DAYS = 'HTTP 200, but no usable days';

    /** Fehlerfelder einer gescheiterten Anfrage. */
    private static function failure(array $resp): array
    {
        return ['error' => $resp['error'], 'error_code' => $resp['error_code'] ?? 'network', 'detail' => $resp['detail'] ?? null,
                'http_code' => $resp['http_code'] ?? null];
    }

    /** Tagessumme in MJ/m² → kWh/m² (drei Nachkommastellen), null ohne verwertbaren Wert. */
    public static function radiation(mixed $mj): ?float
    {
        if ($mj === null || is_bool($mj) || !is_numeric($mj) || !is_finite((float)$mj) || (float)$mj < 0) return null;
        return round((float)$mj / self::MJ_PER_KWH, 3);
    }

    private static function timezone(): string
    {
        return rawurlencode(date_default_timezone_get());
    }

    private function fetchAndParse(string $url, int $timeout): array
    {
        $resp = $this->httpGet($url, $timeout);
        if (!$resp['ok']) {
            return ['data' => [], 'url' => $url, 'http_code' => $resp['http_code']] + self::failure($resp);
        }
        $out = self::parseDays(json_decode($resp['body'], true));
        if ($out === null) {
            return ['data' => [], 'error' => self::BAD_FORMAT, 'error_code' => 'badFormat', 'url' => $url];
        }
        return [
            'data'      => $out,
            'error'     => empty($out) ? self::NO_DAYS : null,
            'error_code' => empty($out) ? 'noDays' : null,
            'url'       => $url,
            'http_code' => $resp['http_code'],
            'rows'      => count($out),
        ];
    }

    /**
     * Tageswerte einer Archiv- oder Vorhersage-Antwort: Tag → {avg, min, max} und (v3.13.0, Paket sonne)
     * optional `rad`, die Einstrahlung in kWh/m². Ohne Einstrahlung bleibt der Tag gültig. Null bei
     * unerwartetem Format.
     *
     * @return array<string,array{avg:float,min:float,max:float,rad?:float}>|null
     */
    public static function parseDays(mixed $data): ?array
    {
        if (!is_array($data) || !isset($data['daily']['time']) || !is_array($data['daily']['time'])) return null;
        $out = [];
        foreach ($data['daily']['time'] as $i => $date) {
            $a = $data['daily']['temperature_2m_mean'][$i] ?? null;
            $mi = $data['daily']['temperature_2m_min'][$i] ?? null;
            $ma = $data['daily']['temperature_2m_max'][$i] ?? null;
            if ($a !== null && $mi !== null && $ma !== null) {
                $out[$date] = ['avg' => (float)$a, 'min' => (float)$mi, 'max' => (float)$ma];
                $r = self::radiation($data['daily']['shortwave_radiation_sum'][$i] ?? null);
                if ($r !== null) $out[$date]['rad'] = $r;
            }
        }
        return $out;
    }

    private function httpGet(string $url, int $timeout): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            // v3.4.0 (N1020) — nur HTTPS (auch nach Weiterleitung), höchstens zwei
            // Weiterleitungen, Antwort höchstens 20 MB (Support\Outbound)
            $body = '';
            curl_setopt_array($ch, [
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => 'Energietracker/1.0 (PHP)',
            ] + Outbound::curlOptions(true, true, $body));
            curl_exec($ch);
            $errno = curl_errno($ch);
            $cerr  = curl_error($ch);
            $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($errno) {
                if (Outbound::tooLarge($errno)) $cerr = 'response larger than ' . Outbound::MAX_BYTES . ' bytes';
                return ['ok' => false, 'body' => null, 'http_code' => null,
                        'error' => "cURL #$errno: " . ($cerr ?: 'unknown'), 'error_code' => 'network',
                        'detail' => $cerr ?: "cURL #$errno"];
            }
            if ($code !== 200) {
                return ['ok' => false, 'body' => $body ?: null, 'http_code' => $code,
                        'error' => "HTTP $code", 'error_code' => 'http'];
            }
            return ['ok' => true, 'body' => (string)$body, 'http_code' => 200, 'error' => null];
        }

        if (!ini_get('allow_url_fopen')) {
            return ['ok' => false, 'body' => null, 'http_code' => null,
                    'error' => 'Neither cURL nor allow_url_fopen available', 'error_code' => 'noTransport'];
        }
        // v3.4.0 (N1020) — ohne curl: keine Weiterleitungen (der Stream-Wrapper
        // kann das Protokoll danach nicht auf HTTPS beschränken), Größe begrenzt
        $ctx = stream_context_create(['http' => [
            'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
            'user_agent' => 'Energietracker/1.0 (PHP)',
        ]]);
        $r = @file_get_contents($url, false, $ctx, 0, Outbound::MAX_BYTES + 1);
        if (is_string($r) && strlen($r) > Outbound::MAX_BYTES) {
            return ['ok' => false, 'body' => null, 'http_code' => null, 'error' => 'response too large',
                    'error_code' => 'network', 'detail' => 'response larger than ' . Outbound::MAX_BYTES . ' bytes'];
        }
        if ($r === false) {
            $err = error_get_last();
            return ['ok' => false, 'body' => null, 'http_code' => null,
                    'error' => 'file_get_contents failed: ' . ($err['message'] ?? '?'), 'error_code' => 'network',
                    'detail' => (string)($err['message'] ?? '?')];
        }
        return ['ok' => true, 'body' => $r, 'http_code' => 200, 'error' => null];
    }
}
