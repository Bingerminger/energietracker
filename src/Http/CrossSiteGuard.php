<?php
declare(strict_types=1);

namespace Energietracker\Http;

/**
 * v2.5.3 — Schreibende Anfragen fremder Webseiten abweisen (CSRF).
 *
 * `Request` liest den Rumpf als JSON, egal mit welchem Content-Type er kommt.
 * Ein `fetch(…, {method:'POST', mode:'no-cors'})` oder ein HTML-Formular einer
 * beliebigen Webseite braucht dafür keinen Preflight: Wer im Heimnetz eine
 * präparierte Seite öffnete, konnte so Demo-Daten über den Bestand spielen,
 * ein Backup einspielen oder das HA-Token rotieren.
 *
 * Die Prüfung stützt sich auf `Sec-Fetch-Site`, das alle aktuellen Browser
 * setzen und das keine Webseite fälschen kann. Nur `same-origin` (die eigene
 * Oberfläche, auch als PWA oder hinter HA-Ingress) und `none` (direkt
 * eingegeben) dürfen schreiben. Ältere Browser ohne den Header werden am
 * `Origin` gemessen. Home Assistant, curl, Node-RED und Skripte senden keinen
 * der beiden Header — sie bleiben unberührt.
 *
 * Bewusst NICHT gewählt: eine Content-Type-Pflicht. Sie bräche Skripte, die
 * `curl -d '{…}'` ohne `-H 'Content-Type: application/json'` aufrufen.
 */
final class CrossSiteGuard
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @param array<string,mixed> $server typischerweise $_SERVER */
    public static function isForeignWrite(string $method, array $server): bool
    {
        if (!in_array(strtoupper($method), self::WRITE_METHODS, true)) return false;

        $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
        if (is_string($site) && $site !== '') {
            return !in_array(strtolower(trim($site)), ['same-origin', 'none'], true);
        }

        $origin = $server['HTTP_ORIGIN'] ?? null;
        if (!is_string($origin) || $origin === '') return false;   // kein Browser-Kontext
        if (strtolower(trim($origin)) === 'null') return true;     // Sandbox-iframe, data:-URL

        $originHost = self::hostOf($origin);
        if ($originHost === null) return true;

        foreach (self::ownHosts($server) as $own) {
            if (strcasecmp($own, $originHost) === 0) return false;
        }
        return true;
    }

    /** „host" bzw. „host:port" aus einer Origin-URL, ohne Standardport. */
    private static function hostOf(string $origin): ?string
    {
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') return null;
        $port = parse_url($origin, PHP_URL_PORT);
        return $port !== null ? "$host:$port" : $host;
    }

    /**
     * Die Namen, unter denen diese Instanz angesprochen wird: `Host` und —
     * hinter einem Reverse Proxy — der erste Eintrag von `X-Forwarded-Host`.
     * Eine fremde Seite kann keinen dieser Header in einer Anfrage ohne
     * Preflight setzen.
     *
     * @return list<string>
     */
    private static function ownHosts(array $server): array
    {
        $hosts = [];
        foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST'] as $key) {
            $v = $server[$key] ?? null;
            if (!is_string($v) || trim($v) === '') continue;
            $first = trim(explode(',', $v)[0]);
            if ($first !== '') {
                $hosts[] = $first;
                // „nas:80" und „nas" meinen dasselbe
                $hosts[] = preg_replace('/:(80|443)$/', '', $first);
            }
        }
        return array_values(array_unique($hosts));
    }
}
