<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;

/**
 * v2.8.0 — Klimanormal am Standort (Review CALC-30, CALC-03, CALC-13).
 *
 * Dreißig Jahre Tagesmittel aus dem Open-Meteo-Archiv, einmal geholt und als
 * Kennzahlen gespeichert (`data/climate_normal.json`): die mittleren
 * Heizgradtage je Kalendermonat, ihre Streuung über die Jahre und die
 * Streuung der Jahressumme — für ein Raster von Heizgrenzen (10 bis 22 °C in
 * halben Grad), weil die Heizgradtage nicht aus Monatsmitteln folgen
 * (max(0, Grenze − T) ist nicht linear). Dazu das mittlere Tagesmittel je
 * Kalendertag.
 *
 * Wozu: Die Prognose brauchte für einen Monat ohne eigene Historie bisher
 * „0 Heizgradtage" — wer im Mai anfing, bekam für Januar den Sommerwert
 * (−54 % im Jahr). Die Wetterbereinigung verglich mit dem Mittel der eigenen,
 * oft nur zwölf Monate. Und ein Unsicherheitsband braucht die Streuung der
 * Winter, nicht die eines einzelnen Jahres.
 *
 * Die Rohdaten werden nicht gespeichert — nur, was die Rechnung braucht.
 */
final class ClimateNormalService
{
    public const FILE = 'climate_normal.json';
    /** Raster der Heizgrenzen, für die das Normal vorberechnet wird. */
    public const BASE_MIN  = 10.0;
    public const BASE_MAX  = 22.0;
    public const BASE_STEP = 0.5;
    /** Länge der Referenzperiode in Jahren. */
    public const YEARS = 30;

    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
    ) {}

    /** @return array<string,mixed>|null das gespeicherte Normal */
    public function get(): ?array
    {
        $n = $this->store->read(self::FILE, []);
        return is_array($n) && isset($n['hdd']) && is_array($n['hdd']) ? $n : null;
    }

    public function save(array $normal): void
    {
        $this->store->write(self::FILE, $normal);
    }

    /**
     * Braucht es ein neues Normal? Ja, wenn keins da ist, der Standort sich
     * um mehr als rund 5 km verschoben hat oder die Referenzperiode nicht mehr
     * an das Vorjahr heranreicht.
     */
    public function isStale(float $lat, float $lon, ?int $currentYear = null): bool
    {
        $n = $this->get();
        if ($n === null) return true;
        if (abs((float)($n['latitude'] ?? 999) - $lat) > 0.05) return true;
        if (abs((float)($n['longitude'] ?? 999) - $lon) > 0.05) return true;
        $year = $currentYear ?? (int)date('Y');
        return (int)substr((string)($n['period']['to'] ?? '0000'), 0, 4) < $year - 1;
    }

    /** Referenzperiode: die letzten dreißig vollen Kalenderjahre. */
    public static function period(?int $currentYear = null): array
    {
        $year = $currentYear ?? (int)date('Y');
        return ['from' => sprintf('%04d-01-01', $year - self::YEARS), 'to' => sprintf('%04d-12-31', $year - 1)];
    }

    /**
     * Kennzahlen aus Tagesmitteln (`YYYY-MM-DD` → °C).
     *
     * @param array<string,float> $dailyMeans
     * @return array<string,mixed>
     */
    public static function compute(array $dailyMeans, float $lat, float $lon, array $period): array
    {
        ksort($dailyMeans);
        $bases = [];
        for ($b = self::BASE_MIN; $b <= self::BASE_MAX + 1e-9; $b += self::BASE_STEP) {
            $bases[] = round($b, 1);
        }
        // Summen je Jahr und Monat für jede Grenze
        $sums = [];            // [base][year][month] = HGT
        $days = [];            // [year][month] = Tage mit Wert
        $doySum = array_fill(1, 366, 0.0);
        $doyCnt = array_fill(1, 366, 0);
        foreach ($dailyMeans as $date => $t) {
            $y = (int)substr((string)$date, 0, 4);
            $m = (int)substr((string)$date, 5, 2);
            $t = (float)$t;
            $days[$y][$m] = ($days[$y][$m] ?? 0) + 1;
            $doy = (int)date('z', (int)strtotime((string)$date)) + 1;
            // In Nicht-Schaltjahren ab März um einen Tag schieben, damit
            // Tag 60 immer der 29. Februar ist und der 1. März Tag 61.
            if ($doy >= 60 && !self::isLeap($y)) $doy++;
            $doySum[$doy] += $t;
            $doyCnt[$doy]++;
            foreach ($bases as $b) {
                $sums[(string)$b][$y][$m] = ($sums[(string)$b][$y][$m] ?? 0.0) + max(0.0, $b - $t);
            }
        }

        // Nur Monate mit (fast) vollständigen Daten zählen
        $complete = [];
        foreach ($days as $y => $months) {
            foreach ($months as $m => $n) {
                $dim = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
                if ($n >= $dim - 1) $complete[$y][$m] = true;
            }
        }

        $hdd = [];
        foreach ($bases as $b) {
            $key = number_format($b, 1, '.', '');
            $mean = []; $sd = [];
            for ($m = 1; $m <= 12; $m++) {
                $vals = [];
                foreach ($sums[(string)$b] ?? [] as $y => $months) {
                    if (!empty($complete[$y][$m])) $vals[] = (float)$months[$m];
                }
                $mean[] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
                $sd[]   = count($vals) > 1 ? round(self::stddev($vals), 2) : null;
            }
            $years = [];
            foreach ($sums[(string)$b] ?? [] as $y => $months) {
                if (count(array_filter(range(1, 12), fn($m) => !empty($complete[$y][$m]))) === 12) {
                    $years[] = array_sum($months);
                }
            }
            $hdd[$key] = [
                'mean'    => $mean,
                'sd'      => $sd,
                'year_sd' => count($years) > 1 ? round(self::stddev($years), 2) : null,
                'years'   => count($years),
            ];
        }

        $doyAvg = [];
        for ($d = 1; $d <= 366; $d++) {
            $doyAvg[] = $doyCnt[$d] > 0 ? round($doySum[$d] / $doyCnt[$d], 2) : null;
        }
        // Der 29. Februar fehlt in drei von vier Jahren — notfalls Mittel der Nachbartage
        if ($doyAvg[59] === null && $doyAvg[58] !== null && $doyAvg[60] !== null) {
            $doyAvg[59] = round(($doyAvg[58] + $doyAvg[60]) / 2, 2);
        }

        return [
            'version'    => 1,
            'latitude'   => round($lat, 2),
            'longitude'  => round($lon, 2),
            'period'     => $period,
            'fetched_at' => date('c'),
            'days'       => count($dailyMeans),
            'doy_avg'    => $doyAvg,
            'hdd'        => $hdd,
        ];
    }

    /**
     * Normale Heizgradtage eines Kalendermonats für die eingestellte
     * Heizgrenze (linear zwischen den Rasterpunkten), samt Streuung.
     *
     * @return array{mean:float,sd:?float}|null
     */
    public function hddForMonth(int $month, ?float $base = null): ?array
    {
        $n = $this->get();
        if ($n === null || $month < 1 || $month > 12) return null;
        [$lo, $hi, $w] = $this->gridPoints($base);
        $a = $n['hdd'][$lo] ?? null;
        $b = $n['hdd'][$hi] ?? null;
        if ($a === null || $b === null) return null;
        $ma = $a['mean'][$month - 1] ?? null;
        $mb = $b['mean'][$month - 1] ?? null;
        if ($ma === null || $mb === null) return null;
        $sa = $a['sd'][$month - 1] ?? null;
        $sb = $b['sd'][$month - 1] ?? null;
        return [
            'mean' => (1 - $w) * (float)$ma + $w * (float)$mb,
            'sd'   => ($sa !== null && $sb !== null) ? (1 - $w) * (float)$sa + $w * (float)$sb : null,
        ];
    }

    /** Streuung der Jahressumme der Heizgradtage (für das Jahresband). */
    public function yearSd(?float $base = null): ?float
    {
        $n = $this->get();
        if ($n === null) return null;
        [$lo, $hi, $w] = $this->gridPoints($base);
        $a = $n['hdd'][$lo]['year_sd'] ?? null;
        $b = $n['hdd'][$hi]['year_sd'] ?? null;
        return ($a !== null && $b !== null) ? (1 - $w) * (float)$a + $w * (float)$b : null;
    }

    /** Mittleres Tagesmittel eines Kalendertags (für fehlende Tage). */
    public function dayAvg(string $date): ?float
    {
        $n = $this->get();
        if ($n === null) return null;
        $ts = strtotime($date);
        if ($ts === false) return null;
        $y = (int)date('Y', $ts);
        $doy = (int)date('z', $ts) + 1;
        if ($doy >= 60 && !self::isLeap($y)) $doy++;
        $v = $n['doy_avg'][$doy - 1] ?? null;
        return $v === null ? null : (float)$v;
    }

    /** Kurzbeschreibung für Antworten und Oberfläche. */
    public function summary(): ?array
    {
        $n = $this->get();
        if ($n === null) return null;
        return [
            'period'     => $n['period'] ?? null,
            'latitude'   => $n['latitude'] ?? null,
            'longitude'  => $n['longitude'] ?? null,
            'fetched_at' => $n['fetched_at'] ?? null,
        ];
    }

    /** @return array{0:string,1:string,2:float} benachbarte Rasterschlüssel und Gewicht */
    private function gridPoints(?float $base): array
    {
        $b = $base ?? (float)$this->settings->get('hdd_base_temp', 15.0);
        $b = max(self::BASE_MIN, min(self::BASE_MAX, $b));
        $lo = floor($b / self::BASE_STEP) * self::BASE_STEP;
        $hi = min(self::BASE_MAX, $lo + self::BASE_STEP);
        $w  = $hi > $lo ? ($b - $lo) / ($hi - $lo) : 0.0;
        return [number_format($lo, 1, '.', ''), number_format($hi, 1, '.', ''), $w];
    }

    private static function isLeap(int $y): bool
    {
        return ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0;
    }

    /** @param float[] $vals */
    private static function stddev(array $vals): float
    {
        $n = count($vals);
        if ($n < 2) return 0.0;
        $mean = array_sum($vals) / $n;
        return sqrt(array_sum(array_map(fn($v) => ($v - $mean) ** 2, $vals)) / ($n - 1));
    }
}
