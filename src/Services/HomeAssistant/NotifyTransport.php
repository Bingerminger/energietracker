<?php
declare(strict_types=1);

namespace Energietracker\Services\HomeAssistant;

use Energietracker\Services\OcrService;
use Energietracker\Support\HomeTls;
use Energietracker\Support\IpAddress;
use Energietracker\Support\Outbound;

/**
 * v3.9.0 (F1033) — eine Nachricht an Home Assistant schicken (POST mit JSON).
 *
 * Wohin, legt der Verwalter fest (NotifyConfig). Regeln für die Verbindung:
 *   - Der Name wird aufgelöst; verbunden wird mit genau der geprüften Adresse
 *     (CURLOPT_RESOLVE), Weiterleitungen werden nicht verfolgt.
 *   - Adressen im Heimnetz (OcrService::isLocalIp, dazu der Supervisor
 *     172.30.32.2) dürfen HTTP oder HTTPS sprechen. Eine öffentliche Adresse
 *     (etwa ein Fernzugang auf Home Assistant) nur HTTPS — sonst ginge der
 *     Token im Klartext durchs Internet.
 *   - Link-local (169.254.0.0/16, fe80::/10) und unspezifizierte Adressen
 *     nie: dort antworten bei gemieteten Servern die Metadatendienste.
 *     v3.12.0: dazu jeder Metadatendienst aus Support\IpAddress::METADATA.
 *   - Antwort höchstens 1 MB (Support\Outbound), Zeitgrenze TIMEOUT.
 *   - v3.10.0 (#36): HTTPS mit eigener PKI über Support\HomeTls (eigenes
 *     Stammzertifikat zusätzlich, „nicht prüfen“ nur im Heimnetz).
 *
 * Ergebnis: {ok, status, error} mit `error` aus dns, address, insecure,
 * timeout, network, http, noTransport, url und (v3.10.0) den Zertifikatsfehlern
 * aus HomeTls::CERT_ERRORS (tlsIssuer, tlsName, tlsExpired, tlsFile, tlsOther).
 *
 * v3.13.0 — Umleitung (301, 302, 303, 307, 308) auf eine andere Adresse:
 * `error` = redirect und `location` = Schema, Host und Port des Ziels, ohne
 * Pfad (darin stünde die Webhook-ID). Gefolgt wird weiter nicht: Ein neues Ziel
 * bräuchte dieselben Prüfungen wie die eingetragene Adresse (Auflösen, Sperren,
 * https außerhalb), und der Token ginge an eine Stelle, die der Verwalter nicht
 * eingetragen hat. Anlass: Home Assistant OS 18.4 antwortet auf Port 80 und
 * leitet :8123 mit 307 dorthin um (Farm); bis v3.12 hieß es nur „Status 307“.
 */
final class NotifyTransport
{
    public const TIMEOUT = 8;
    /** Umleitungen, bei denen die Testnachricht die neue Adresse nennt. */
    public const REDIRECTS = [301, 302, 303, 307, 308];

    /** @var callable(string, string, list<string>, int, ?string): array{ok:bool, http_code:?int, error_code:?string, location?:string} */
    private $send;
    /** @var callable(string): list<string> */
    private $resolver;

    public function __construct(?callable $send = null, ?callable $resolver = null)
    {
        $this->send = $send ?? self::curlPost(...);
        $this->resolver = $resolver ?? self::resolve(...);
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $headers zusätzliche Kopfzeilen (Authorization)
     * @return array{ok:bool, status:?int, error:?string, location?:string}
     */
    public function post(string $url, array $payload, array $headers = []): array
    {
        $p = parse_url($url);
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        $host = trim((string)($p['host'] ?? ''), '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) {
            return ['ok' => false, 'status' => null, 'error' => 'url'];
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        if ($ips === []) return ['ok' => false, 'status' => null, 'error' => 'dns'];
        $public = false;
        foreach ($ips as $ip) {
            if (self::forbidden($ip)) return ['ok' => false, 'status' => null, 'error' => 'address'];
            if (!OcrService::isLocalIp($ip)) $public = true;
        }
        if ($public && $scheme !== 'https') return ['ok' => false, 'status' => null, 'error' => 'insecure'];
        $json = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $r = ($this->send)($url, $json, $headers, self::TIMEOUT, $ips[0]);
        $out = ['ok' => (bool)$r['ok'], 'status' => $r['http_code'] ?? null, 'error' => $r['ok'] ? null : ($r['error_code'] ?? 'network')];
        // v3.13.0 — das Ziel einer Umleitung nur als Ursprung (redirectOrigin), nur in diesem Fall
        if ($out['error'] === 'redirect') {
            $to = is_string($r['location'] ?? null) ? self::redirectOrigin($url, $r['location']) : null;
            if ($to === null) $out['error'] = 'http';
            else $out['location'] = $to;
        }
        return $out;
    }

    /**
     * v3.13.0 — Ursprung (Schema, Host, Port) des Ziels einer Umleitung, oder
     * null, wenn es keiner ist, der sich eintragen ließe: kein http(s), kein
     * gültiger Name, Benutzerdaten in der Adresse, von https auf http oder derselbe
     * Ursprung wie die Anfrage (dann bleibt es bei `http` mit dem Status). Pfad, Abfrage und
     * Fragment fallen weg: Im Pfad des Webhooks steht die geheime ID.
     */
    public static function redirectOrigin(string $from, string $to): ?string
    {
        $p = parse_url($to);
        if (!is_array($p) || isset($p['user']) || isset($p['pass'])) return null;
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        $host = strtolower(trim((string)($p['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') return null;
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$ip && !preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)) return null;
        $origin = static function (string $s, string $h, ?int $port): string {
            $default = $s === 'https' ? 443 : 80;
            return $s . '://' . (str_contains($h, ':') ? "[$h]" : $h) . ($port !== null && $port !== $default ? ":$port" : '');
        };
        $target = $origin($scheme, $host, isset($p['port']) ? (int)$p['port'] : null);
        $f = parse_url($from);
        $fromScheme = is_array($f) ? strtolower((string)($f['scheme'] ?? '')) : '';
        // von https auf http rät die Karte nie: der Token ginge dann im Klartext
        if ($fromScheme === 'https' && $scheme === 'http') return null;
        $source = is_array($f) ? $origin($fromScheme, strtolower(trim((string)($f['host'] ?? ''), '[]')),
            isset($f['port']) ? (int)$f['port'] : null) : '';
        return $target === $source ? null : $target;
    }

    /**
     * Link-local, unspezifiziert und der Metadatendienst (IPv4 und IPv6).
     *
     * v3.12.0 — Härtung: Die Sperrliste sieht durch jede Hülle auf die IPv4
     * (Support\IpAddress: `::ffff:…` in jeder Schreibweise, `::a.b.c.d`,
     * NAT64, 6to4); das lokale NAT64-Präfix, dessen IPv4 sich nicht ablesen
     * lässt, ist gesperrt. Unspezifiziert heißt 0.0.0.0/8 („dieses Netz“).
     * Die Metadatendienste stehen in IpAddress::METADATA (auch 100.100.100.200,
     * das mitten in 100.64.0.0/10 liegt).
     */
    public static function forbidden(string $ip): bool
    {
        $a = IpAddress::parse($ip);
        if ($a['form'] === 'invalid' || $a['form'] === 'nat64_local') return true;
        if (IpAddress::isMetadata($ip)) return true;
        if ($a['ipv4'] !== null) return IpAddress::inV4Nets($a['ipv4'], [['0.0.0.0', 8], ['169.254.0.0', 16]]);
        $bin = (string)$a['bin'];
        if ($bin === inet_pton('::')) return true;
        return ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80;   // fe80::/10
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $v6 = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($v6 as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        return array_values(array_unique($ips));
    }

    /**
     * POST über curl an die geprüfte Adresse, ohne Weiterleitungen. Öffentlich
     * nur für Tests gegen einen nachgebauten Dienst (Adresse umgeleitet).
     *
     * @param list<string> $headers
     * @return array{ok:bool, http_code:?int, error_code:?string, location?:string}
     */
    public static function curlPost(string $url, string $json, array $headers, int $timeout, ?string $ip): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'http_code' => null, 'error_code' => 'noTransport'];
        $p = parse_url($url);
        $port = (int)($p['port'] ?? (($p['scheme'] ?? 'http') === 'https' ? 443 : 80));
        $host = trim((string)($p['host'] ?? ''), '[]');
        $body = '';
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_USERAGENT      => 'Energietracker (PHP)',
        ] + Outbound::curlOptions(false, false, $body, 1048576);
        if ($ip !== null && !filter_var($host, FILTER_VALIDATE_IP)) {
            $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($ip, ':') ? "[$ip]" : $ip)];
        }
        // v3.10.0 (#36) — eigenes Stammzertifikat bzw. „nicht prüfen“ (nur Heimnetz)
        $opts += HomeTls::curlOptions($ip, $host);
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($errno) {
            $error = $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout'
                : (HomeTls::certError($errno, (int)curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT)) ?? 'network');
            return ['ok' => false, 'http_code' => null, 'error_code' => $error];
        }
        // v3.13.0 — Umleitung: nicht folgen (Outbound ohne Weiterleitung), aber das Ziel weitergeben;
        // post() kürzt es auf den Ursprung
        if (in_array($code, self::REDIRECTS, true)) {
            $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            if ($location !== '') return ['ok' => false, 'http_code' => $code, 'error_code' => 'redirect', 'location' => $location];
        }
        if ($code < 200 || $code >= 300) return ['ok' => false, 'http_code' => $code, 'error_code' => 'http'];
        return ['ok' => true, 'http_code' => $code, 'error_code' => null];
    }
}
