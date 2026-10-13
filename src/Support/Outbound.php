<?php
declare(strict_types=1);

namespace Energietracker\Support;

/**
 * v3.4.0 (N1020) — Grenzen für Verbindungen nach außen, eine Stelle für alle
 * Dienste, die selbst `curl_init` aufrufen (Wetter, SMARD, Texterkennung,
 * evcc; OutboundHostsTest führt die Liste).
 *
 *   - Protokolle: öffentliche Dienste nur HTTPS, auch nach einer Weiterleitung;
 *     Dienste im Heimnetz HTTP oder HTTPS.
 *   - Weiterleitungen: höchstens MAX_REDIRECTS (öffentliche Dienste); im
 *     Heimnetz keine, denn verbunden wird mit genau der geprüften Adresse.
 *   - Antwortgröße: höchstens MAX_BYTES; größere Antworten bricht der
 *     Schreibrückruf ab, statt sie in den Speicher zu laden.
 */
final class Outbound
{
    public const MAX_BYTES = 20 * 1048576;
    public const MAX_REDIRECTS = 2;

    /**
     * curl-Optionen samt Schreibrückruf. Der Körper landet in `$body`;
     * `curl_exec` liefert dann nur true/false.
     *
     * @return array<int,mixed>
     */
    public static function curlOptions(bool $httpsOnly, bool $follow, string &$body, int $maxBytes = self::MAX_BYTES): array
    {
        $proto = $httpsOnly ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS);
        $body = '';
        return [
            CURLOPT_PROTOCOLS      => $proto,
            CURLOPT_REDIR_PROTOCOLS => $proto,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS      => $follow ? self::MAX_REDIRECTS : 0,
            CURLOPT_MAXFILESIZE    => $maxBytes,   // bei angekündigter Länge schon vor dem ersten Byte
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) return 0;   // Abbruch: CURLE_WRITE_ERROR
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
    }

    /** Scheiterte der Abruf an der Größengrenze? */
    public static function tooLarge(int $errno): bool
    {
        return $errno === CURLE_WRITE_ERROR || $errno === CURLE_FILESIZE_EXCEEDED;
    }
}
