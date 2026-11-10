<?php
declare(strict_types=1);

namespace Energietracker\Support;

/**
 * v3.12.0 — Härtung: eine Stelle, die eine Zieladresse zerlegt, für alle Wege,
 * die sich mit einer eingetragenen Adresse verbinden (OcrService::isLocalIp für
 * Texterkennung und evcc, NotifyTransport::forbidden für Home Assistant,
 * CloudHttp::isPublicIp für das Auslesen in der Cloud).
 *
 * Eine IPv4 steckt in IPv6 in mehreren Formen; jede gibt es in Punkt- und in
 * Hex-Schreibweise, gekürzt oder ausgeschrieben. Verglichen wird deshalb nur an
 * den 16 Bytes (inet_pton), nie am Text — PHPs FILTER_FLAG_GLOBAL_RANGE wertet
 * `::8.8.8.8` und `::808:808` verschieden.
 *
 * Formen (`form`):
 *   - ipv4        a.b.c.d
 *   - mapped      ::ffff:0:0/96 (RFC 4291) — das eigene System verbindet über IPv4
 *   - compatible  ::/96 ohne :: und ::1 (veraltet, RFC 4291 2.5.5.1)
 *   - translated  ::ffff:0:0:0/96 (veraltet, RFC 2765)
 *   - nat64       64:ff9b::/96 (RFC 6052) — ein Übersetzer im Netz verbindet
 *   - nat64_local 64:ff9b:1::/48 (RFC 8215) — Lage der IPv4 hängt am Betreiber, `ipv4` null
 *   - 6to4        2002::/16 (RFC 3056) — ein Relais im Internet verbindet
 *   - ipv6        alles andere
 *   - invalid     keine Adresse
 * Was die Wege daraus machen, entscheidet jeder selbst (Sperrliste sieht durch jede
 * Hülle, Positivlisten nehmen nur die Formen, die verlässlich zur IPv4 führen).
 */
final class IpAddress
{
    /**
     * v3.12.0 — Metadatendienste gemieteter Server: Dort holt sich eine Maschine
     * ihre Zugangsdaten. Auf allen drei Wegen gesperrt (isMetadata):
     *   - 169.254.169.254: die meisten Anbieter; liegt in Link-local und ist
     *     dort ohnehin gesperrt.
     *   - fd00:ec2::254: dieselbe Adresse in IPv6, trotz ULA.
     *   - 100.100.100.200: liegt in 100.64.0.0/10, das sonst als Heimnetz zählt
     *     (CGNAT beim Anbieter, Tailscale). Gesperrt ist **nur diese Adresse**,
     *     nicht das Netz.
     */
    public const METADATA = ['169.254.169.254', '100.100.100.200', 'fd00:ec2::254'];

    /** Ist die Adresse ein Metadatendienst, auch in einer Hülle (`::ffff:…`, NAT64, 6to4 …)? */
    public static function isMetadata(string $ip): bool
    {
        $a = self::parse($ip);
        if ($a['form'] === 'invalid') return false;
        foreach (self::METADATA as $m) {
            $bin = (string)inet_pton($m);
            if ($a['bin'] === $bin || ($a['ipv4'] !== null && inet_pton($a['ipv4']) === $bin)) return true;
        }
        return false;
    }

    /**
     * @return array{form: string, ipv4: ?string, bin: ?string} `bin` = 16 Bytes (IPv6) bzw. 4 (IPv4)
     */
    public static function parse(string $ip): array
    {
        $bin = filter_var($ip, FILTER_VALIDATE_IP) ? @inet_pton($ip) : false;
        if ($bin === false) return ['form' => 'invalid', 'ipv4' => null, 'bin' => null];
        if (strlen($bin) === 4) return ['form' => 'ipv4', 'ipv4' => (string)inet_ntop($bin), 'bin' => $bin];
        $v4 = static fn(string $four): string => (string)inet_ntop($four);
        $zero10 = str_repeat("\0", 10);
        if (substr($bin, 0, 12) === $zero10 . "\xff\xff") {
            return ['form' => 'mapped', 'ipv4' => $v4(substr($bin, 12)), 'bin' => $bin];
        }
        if (substr($bin, 0, 12) === str_repeat("\0", 12) && substr($bin, 12) !== "\0\0\0\0" && substr($bin, 12) !== "\0\0\0\1") {
            return ['form' => 'compatible', 'ipv4' => $v4(substr($bin, 12)), 'bin' => $bin];
        }
        if (substr($bin, 0, 12) === str_repeat("\0", 8) . "\xff\xff\0\0") {
            return ['form' => 'translated', 'ipv4' => $v4(substr($bin, 12)), 'bin' => $bin];
        }
        if (substr($bin, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return ['form' => 'nat64', 'ipv4' => $v4(substr($bin, 12)), 'bin' => $bin];
        }
        if (substr($bin, 0, 6) === "\x00\x64\xff\x9b\x00\x01") {
            return ['form' => 'nat64_local', 'ipv4' => null, 'bin' => $bin];
        }
        if (substr($bin, 0, 2) === "\x20\x02") {
            return ['form' => '6to4', 'ipv4' => $v4(substr($bin, 2, 4)), 'bin' => $bin];
        }
        return ['form' => 'ipv6', 'ipv4' => null, 'bin' => $bin];
    }

    /** Liegt die IPv4 (Punktschreibweise) in einem der Netze `[[netz, bits], …]`? */
    public static function inV4Nets(string $ipv4, array $nets): bool
    {
        $n = ip2long($ipv4);
        if ($n === false) return false;
        foreach ($nets as [$net, $bits]) {
            $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
            if (($n & $mask) === (ip2long($net) & $mask)) return true;
        }
        return false;
    }
}
