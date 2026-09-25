<?php
declare(strict_types=1);

namespace Energietracker\Services;

/**
 * v2.8.0 — Quelle für Tagestemperaturen. Einzige Implementierung ist
 * `WeatherService` (Open-Meteo); die Schnittstelle erlaubt Tests ohne Netz.
 */
interface WeatherSource
{
    /** @return array{data:array<string,array{avg:float,min:float,max:float}>,error:?string} */
    public function fetchArchive(float $lat, float $lon, string $start, string $end): array;

    /** @return array{data:array<string,array{avg:float,min:float,max:float}>,error:?string} */
    public function fetchForecast(float $lat, float $lon, int $forecastDays = 14, int $pastDays = 0): array;

    /** @return array{data:array<string,float>,error:?string} */
    public function fetchArchiveMeans(float $lat, float $lon, string $start, string $end): array;

    /**
     * v2.12.0 — Orte zu einem Namen oder einer Postleitzahl (Review UI-30).
     *
     * @return array{data:list<array{name:string,latitude:float,longitude:float,country:?string,admin1:?string,postcode:?string}>,error:?string}
     */
    public function geocode(string $query, string $language = 'de', int $count = 5): array;
}
