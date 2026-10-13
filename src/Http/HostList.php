<?php
declare(strict_types=1);

namespace Energietracker\Http;

/**
 * v3.4.0 (N1020) — Hostliste gegen DNS-Rebinding, eine Stelle für die
 * Umgebung (`ET_ALLOWED_HOSTS`) und die Einstellung `allowed_hosts`.
 *
 * Beide sind Kommalisten (auch Leerzeichen trennen) aus Hostnamen und
 * Platzhaltern der Form `*.example.org`. Gilt eine der beiden, beantwortet die
 * App nur noch bekannte Namen; IP-Adressen und `localhost` sind immer erlaubt
 * — so sperrt sich niemand aus, der die App über ihre Adresse aufruft.
 */
final class HostList
{
    /** Ein Eintrag: Hostname oder `*.domain`, ohne Schema, Port und Pfad. */
    public const ENTRY = '/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/';

    /** @return list<string> kleingeschrieben, ohne leere Einträge */
    public static function parse(string $list): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower(trim($list))) ?: [] as $h) {
            if ($h !== '') $out[] = $h;
        }
        return array_values(array_unique($out));
    }

    /** Host ohne Port, IPv6 ohne Klammern; leer bei unlesbarem Wert. */
    public static function hostOf(string $hostHeader): string
    {
        $host = strtolower(trim($hostHeader));
        if (preg_match('/^\[(.+)\](:\d+)?$/', $host, $m)) return $m[1];            // [IPv6]:port
        if (substr_count($host, ':') === 1) return explode(':', $host)[0];          // name:port
        return $host;
    }

    /** IP-Adresse oder localhost — gilt immer. */
    public static function isAddress(string $host): bool
    {
        return $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /** @param list<string> $list leer = alle Hosts erlaubt */
    public static function allows(string $hostHeader, array $list): bool
    {
        if ($list === []) return true;
        $host = self::hostOf($hostHeader);
        if ($host === '') return false;
        if (self::isAddress($host)) return true;
        foreach ($list as $allowed) {
            if ($allowed === $host) return true;
            if (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1))) return true;
        }
        return false;
    }

    /** Die Liste aus der Umgebung (`ET_ALLOWED_HOSTS`). @return list<string> */
    public static function fromEnv(): array
    {
        return self::parse((string)getenv('ET_ALLOWED_HOSTS'));
    }
}
