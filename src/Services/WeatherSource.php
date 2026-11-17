<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * v2.8.0 — Quelle für Tagestemperaturen. Einzige Implementierung ist
 * `WeatherService` (Open-Meteo); die Schnittstelle erlaubt Tests ohne Netz.
 */
interface WeatherSource
{
    /**
     * v3.13.0 (Paket sonne) — je Tag optional `rad`: Einstrahlung (Globalstrahlung) in kWh/m² am Tag.
     *
     * @return array{data:array<string,array{avg:float,min:float,max:float,rad?:float}>,error:?string}
     */
    public function fetchArchive(float $lat, float $lon, string $start, string $end): array;

    /** @return array{data:array<string,array{avg:float,min:float,max:float,rad?:float}>,error:?string} */
    public function fetchForecast(float $lat, float $lon, int $forecastDays = 14, int $pastDays = 0): array;

    /**
     * v3.13.0 (Paket sonne) — optional `rad`: Tag → Einstrahlung in kWh/m² (für `rad_month` des Klimanormals).
     *
     * @return array{data:array<string,float>,rad?:array<string,float>,error:?string}
     */
    public function fetchArchiveMeans(float $lat, float $lon, string $start, string $end): array;

    /**
     * v2.12.0 — Orte zu einem Namen oder einer Postleitzahl (Review UI-30).
     *
     * @return array{data:list<array{name:string,latitude:float,longitude:float,country:?string,admin1:?string,postcode:?string}>,error:?string}
     */
    public function geocode(string $query, string $language = 'de', int $count = 5): array;
}
