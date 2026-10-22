<?php
declare(strict_types=1);

namespace Energietracker\Services\Inbox;

use Energietracker\Http\HttpStatusException;
use Energietracker\Support\LocalizedException;
use Energietracker\Support\Outbound;

/**
 * v3.7.0 (Paket cloud) — Verbindung zu einem Cloud-Dienst fürs Auslesen im
 * Posteingang, eine Stelle für beide Anschlüsse (OpenAI-kompatibel, Claude).
 *
 * Das Gegenstück zu OcrService: Dort muss jede Adresse im Heimnetz liegen,
 * hier darf keine dort liegen. Die Regeln:
 *   - nur HTTPS, auch keine Weiterleitung (es wird keiner gefolgt);
 *   - geprüft wird nach der Namensauflösung, JEDE Adresse des Namens muss
 *     öffentlich sein (kein Heimnetz, kein Loopback, kein Link-local samt
 *     Metadatendienst, kein Tailscale-/CGNAT-Bereich, kein Multicast; IPv4 in
 *     IPv6 zählt mit der eingebetteten Adresse);
 *   - verbunden wird mit genau der geprüften Adresse (CURLOPT_RESOLVE) und ohne
 *     Proxy aus der Umgebung — ein Proxy löste den Namen selbst noch einmal auf;
 *   - die Antwort höchstens Outbound::MAX_BYTES.
 *
 * Der API-Schlüssel steht nur in den Kopfzeilen der Anfrage; weder Log noch
 * Fehlermeldung bekommen ihn (scrub()).
 */
final class CloudHttp
{
    /** Namen, die nie ins Internet zeigen (vor der Namensauflösung abgelehnt). */
    private const HOME_SUFFIXES = ['.local', '.localhost', '.lan', '.home', '.internal', '.home.arpa', '.fritz.box'];

    /**
     * Prüft die Adresse und löst den Namen auf.
     *
     * @param callable(string): list<string> $resolver
     * @return string die Adresse, mit der verbunden wird
     */
    public static function publicAddress(string $url, callable $resolver): string
    {
        $host = self::checkUrl($url);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $resolver($host);
        if ($ips === []) {
            throw new HttpStatusException(502, 'errors.inboxCloud.unreachable', ['host' => $host], [], "cloud host $host does not resolve");
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new LocalizedException('errors.inboxCloud.notPublic', ['host' => $host], "cloud host $host is not public ($ip)");
            }
        }
        return $ips[0];
    }

    /**
     * Prüfung ohne Namensauflösung (auch beim Speichern der Einstellung):
     * `https://`, ein Host ohne Zugangsdaten, kein Name aus dem Heimnetz und
     * keine Adresse dort.
     *
     * @return string der Host
     */
    public static function checkUrl(string $url): string
    {
        $p = parse_url(trim($url));
        if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https' || ($p['host'] ?? '') === ''
            || isset($p['user']) || isset($p['pass'])) {
            throw new LocalizedException('errors.inboxCloud.httpsOnly', [], 'cloud address must be https://host');
        }
        $host = strtolower(trim((string)$p['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!self::isPublicIp($host)) throw new LocalizedException('errors.inboxCloud.notPublic', ['host' => $host], "cloud host $host is not public");
            return $host;
        }
        if (self::isHomeName($host)) {
            throw new LocalizedException('errors.inboxCloud.notPublic', ['host' => $host], "cloud host $host is a home network name");
        }
        return $host;
    }

    /** Ein Name ohne Punkt (`nas`) oder mit einer Endung des Heimnetzes (`nas.local`, `fritz.box`). */
    public static function isHomeName(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === 'localhost' || !str_contains($host, '.')) return true;
        foreach (self::HOME_SUFFIXES as $s) {
            if (str_ends_with($host, $s) || $host === ltrim($s, '.')) return true;
        }
        return false;
    }

    /**
     * Öffentlich = nach RFC 6890 global erreichbar und kein Multicast. Damit
     * fällt alles heraus, was OcrService::isLocalIp() als Heimnetz gelten lässt
     * (Loopback, RFC 1918, 100.64.0.0/10, ULA) — das Gegenstück; der Test
     * CloudExtractionTest hält diese Beziehung fest.
     */
    public static function isPublicIp(string $ip): bool
    {
        $v4 = self::embeddedIpv4($ip);
        if ($v4 !== null) $ip = $v4;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) return false;
        $bin = (string)inet_pton($ip);
        // Multicast gilt in PHP als global, ist aber kein Dienst: 224.0.0.0/4, ff00::/8
        return strlen($bin) === 4 ? (ord($bin[0]) & 0xF0) !== 0xE0 : ord($bin[0]) !== 0xFF;
    }

    /** IPv4 in IPv6: ::ffff:a.b.c.d, NAT64 64:ff9b::/96, 6to4 2002::/16 — sonst null. */
    private static function embeddedIpv4(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return null;
        $b = inet_pton($ip);
        if ($b === false || strlen($b) !== 16) return null;
        if (substr($b, 0, 12) === str_repeat("\0", 10) . "\xff\xff") return (string)inet_ntop(substr($b, 12));
        if (substr($b, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) return (string)inet_ntop(substr($b, 12));
        if (substr($b, 0, 2) === "\x20\x02") return (string)inet_ntop(substr($b, 2, 4));
        return null;
    }

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $v6 = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($v6 as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        return array_values(array_unique($ips));
    }

    /**
     * POST an die geprüfte Adresse, ohne Weiterleitungen und ohne Proxy.
     * `$httpsOnly` = false nur für den Test gegen einen nachgebauten Dienst
     * auf 127.0.0.1; die Anschlüsse rufen immer mit true.
     *
     * @param list<string> $headers
     * @return array{ok:bool, status:?int, body:?string, headers:array<string,string>, error:?string}
     */
    public static function post(string $url, array $headers, string $body, int $timeout, ?string $ip, bool $httpsOnly = true): array
    {
        return self::request($url, $headers, $body, $timeout, $ip, $httpsOnly);
    }

    /**
     * v3.7.0 (Karte „Auslesen“) — GET an die geprüfte Adresse, etwa die
     * Modellliste (`/models`); dieselben Regeln wie post().
     *
     * @param list<string> $headers
     * @return array{ok:bool, status:?int, body:?string, headers:array<string,string>, error:?string}
     */
    public static function get(string $url, array $headers, int $timeout, ?string $ip, bool $httpsOnly = true): array
    {
        return self::request($url, $headers, null, $timeout, $ip, $httpsOnly);
    }

    /**
     * Basis-Adresse eines OpenAI-kompatiblen Dienstes + Pfad. Endet die Adresse
     * auf eine Versionsstufe (`/v1`, `/v1beta/openai` bei Google Gemini), kommt
     * der Pfad direkt dahinter; sonst wie bisher `/v1/<pfad>`. Eine schon
     * vollständige Adresse (`…/chat/completions`) zählt als Basis davor.
     */
    public static function apiUrl(string $endpoint, string $path): string
    {
        $base = rtrim(trim($endpoint), '/');
        if (str_ends_with($base, '/chat/completions')) $base = substr($base, 0, -strlen('/chat/completions'));
        $p = (string)parse_url($base, PHP_URL_PATH);
        return preg_match('#/v\d+[a-z0-9]*(/openai)?$#i', $p) ? "$base/$path" : "$base/v1/$path";
    }

    /**
     * @param list<string> $headers
     * @return array{ok:bool, status:?int, body:?string, headers:array<string,string>, error:?string}
     */
    private static function request(string $url, array $headers, ?string $body, int $timeout, ?string $ip, bool $httpsOnly): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'status' => null, 'body' => null, 'headers' => [], 'error' => 'noTransport'];
        $p = parse_url($url);
        $port = (int)($p['port'] ?? (strtolower((string)($p['scheme'] ?? '')) === 'https' ? 443 : 80));
        $host = trim((string)($p['host'] ?? ''), '[]');
        $resp = '';
        $respHeaders = [];
        $opts = ($body === null ? [CURLOPT_HTTPGET => true] : [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]) + [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_USERAGENT      => 'Energietracker (PHP)',
            CURLOPT_PROXY          => '',   // auch https_proxy aus der Umgebung nicht
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                if (str_starts_with($line, 'HTTP/')) $respHeaders = [];
                $kv = explode(':', $line, 2);
                if (count($kv) === 2) $respHeaders[strtolower(trim($kv[0]))] = trim($kv[1]);
                return strlen($line);
            },
        ] + Outbound::curlOptions($httpsOnly, false, $resp);
        if ($ip !== null && !filter_var($host, FILTER_VALIDATE_IP)) {
            $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($ip, ':') ? "[$ip]" : $ip)];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($errno) {
            $error = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : (Outbound::tooLarge($errno) ? 'tooLarge' : 'network');
            return ['ok' => false, 'status' => $status ?: null, 'body' => null, 'headers' => $respHeaders, 'error' => $error];
        }
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $status, 'body' => $resp, 'headers' => $respHeaders, 'error' => $ok ? null : 'http'];
    }

    /**
     * Eine gescheiterte Antwort als Ausnahme mit Katalogschlüssel. Status für
     * die eigene Antwort: 502 (der Dienst lehnt ab — nie 401, das hieße in der
     * Oberfläche „neu anmelden“), 429 bzw. 503 mit Retry-After, 504 bei
     * Zeitüberschreitung.
     *
     * @param array{status:?int, error:?string, headers?:array<string,string>, body?:?string} $resp
     */
    public static function fail(array $resp, int $timeout, string $host, string $secret = ''): never
    {
        $s = (int)($resp['status'] ?? 0);
        $error = (string)($resp['error'] ?? 'network');
        if ($error === 'timeout') throw new HttpStatusException(504, 'errors.inboxCloud.timeout', ['seconds' => $timeout], [], 'cloud request timed out');
        if ($error === 'tooLarge') throw new HttpStatusException(502, 'errors.inboxCloud.badAnswer', [], [], 'cloud answer above the size limit');
        if ($error !== 'http') throw new HttpStatusException(502, 'errors.inboxCloud.unreachable', ['host' => $host], [], "cloud request to $host: $error");
        if ($s >= 300 && $s < 400) {
            throw new HttpStatusException(502, 'errors.inboxCloud.redirect', ['host' => $host], [], "cloud answered $s, redirect not followed");
        }
        if ($s === 401 || $s === 403) throw new HttpStatusException(502, 'errors.inboxCloud.unauthorized', ['code' => $s], [], "cloud refused the key ($s)");
        if ($s === 413) throw new HttpStatusException(413, 'errors.inboxCloud.tooLarge', [], [], 'cloud: request too large');
        if ($s === 429) {
            $retry = self::retryAfter($resp['headers'] ?? []);
            throw new HttpStatusException(429, 'errors.inboxCloud.rateLimited', ['seconds' => $retry ?? 60],
                $retry !== null ? ['Retry-After' => (string)$retry] : [], 'cloud rate limit');
        }
        if ($s >= 500) {
            $retry = self::retryAfter($resp['headers'] ?? []) ?? 30;
            throw new HttpStatusException(503, 'errors.inboxCloud.overloaded', ['code' => $s], ['Retry-After' => (string)$retry], "cloud unavailable ($s)");
        }
        throw new HttpStatusException(502, 'errors.inboxCloud.rejected', ['code' => $s, 'detail' => self::errorDetail((string)($resp['body'] ?? ''), $secret)],
            [], "cloud rejected the request ($s)");
    }

    /**
     * v3.7.0 (Karte „Auslesen“) — Modellliste eines Dienstes lesen:
     * `{data: [{id, display_name?|name?}]}` (OpenAI-kompatibel, Claude,
     * OpenRouter), eine bloße Liste (so zeigt Mistral das Beispiel) oder
     * `{models: [{name}]}` (Ollama). Nur Kennungen, die als Modell gespeichert
     * werden dürfen (SettingsService: `inbox_cloud_model`); höchstens 1000, in
     * der Reihenfolge des Dienstes.
     *
     * @return list<array{id:string, label:string}>
     */
    public static function models(string $body): array
    {
        $j = json_decode($body, true);
        $list = is_array($j) && array_is_list($j) ? $j
            : (is_array($j['data'] ?? null) ? $j['data'] : (is_array($j['models'] ?? null) ? $j['models'] : null));
        if ($list === null) throw new HttpStatusException(502, 'errors.inboxCloud.badAnswer', [], [], 'model list without data');
        $out = [];
        foreach ($list as $m) {
            $id = is_array($m) ? ($m['id'] ?? $m['name'] ?? null) : null;
            if (!is_string($id) || !preg_match('#^[A-Za-z0-9._:/@+-]{1,200}$#', $id) || isset($out[$id])) continue;
            $label = $m['display_name'] ?? $m['name'] ?? '';
            $out[$id] = ['id' => $id, 'label' => is_string($label) && $label !== $id ? mb_substr(trim($label), 0, 120) : ''];
            if (count($out) >= 1000) break;
        }
        return array_values($out);
    }

    /** Sekunden aus `retry-after` (Zahl), höchstens eine Stunde. */
    private static function retryAfter(array $headers): ?int
    {
        $v = trim((string)($headers['retry-after'] ?? ''));
        return ctype_digit($v) ? min(3600, max(1, (int)$v)) : null;
    }

    /** Fehlertext des Dienstes (`error.message`), gekürzt und ohne Schlüssel. */
    public static function errorDetail(string $body, string $secret = ''): string
    {
        $j = json_decode($body, true);
        $msg = is_array($j) ? ($j['error']['message'] ?? (is_string($j['error'] ?? null) ? $j['error'] : '')) : '';
        return self::scrub(mb_substr(trim((string)$msg), 0, 300), $secret);
    }

    /**
     * Schlüssel aus einem Text entfernen — der eigene und alles, was wie ein
     * API-Schlüssel aussieht (manche Dienste wiederholen ihn in der Meldung).
     */
    public static function scrub(string $text, string $secret = ''): string
    {
        if ($secret !== '') $text = str_replace($secret, '***', $text);
        return (string)preg_replace('/\b(sk|key)-[A-Za-z0-9_*\-]{6,}/', '$1-***', $text);
    }
}
