<?php
declare(strict_types=1);

namespace Energietracker\Services\HomeAssistant;

use Energietracker\Services\OcrService;
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
 *   - Antwort höchstens 1 MB (Support\Outbound), Zeitgrenze TIMEOUT.
 *
 * Ergebnis: {ok, status, error} mit `error` aus dns, address, insecure,
 * timeout, network, http, noTransport, url.
 */
final class NotifyTransport
{
    public const TIMEOUT = 8;

    /** @var callable(string, string, list<string>, int, ?string): array{ok:bool, http_code:?int, error_code:?string} */
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
     * @return array{ok:bool, status:?int, error:?string}
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
        return ['ok' => (bool)$r['ok'], 'status' => $r['http_code'] ?? null, 'error' => $r['ok'] ? null : ($r['error_code'] ?? 'network')];
    }

    /** Link-local, unspezifiziert und der Metadatendienst (IPv4 und IPv6). */
    public static function forbidden(string $ip): bool
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) $ip = $m[1];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            return $ip === '0.0.0.0' || ($n & (-1 << 16)) === (ip2long('169.254.0.0') & (-1 << 16));
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            if ($bin === false || $bin === inet_pton('::')) return true;
            if ($bin === inet_pton('fd00:ec2::254')) return true;
            return ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80;   // fe80::/10
        }
        return true;
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
     * @return array{ok:bool, http_code:?int, error_code:?string}
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
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($errno) return ['ok' => false, 'http_code' => null, 'error_code' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network'];
        if ($code < 200 || $code >= 300) return ['ok' => false, 'http_code' => $code, 'error_code' => 'http'];
        return ['ok' => true, 'http_code' => $code, 'error_code' => null];
    }
}
