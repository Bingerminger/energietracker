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
 *
 * v3.13.0 (Paket sonne) — dazu die Einstrahlung: mittlere Monatssumme der
 * Globalstrahlung je Kalendermonat (`rad_month`, kWh/m²) samt Streuung. Sie
 * trägt die PV-Prognose nach dem Wetter (PvWeatherModel).
 */
final class ClimateNormalService
{
    public const FILE = 'climate_normal.json';
    /**
     * Raster der Heizgrenzen, für die das Normal vorberechnet wird.
     * v3.7.0 (wirklichkeit-2) — ab 6 statt 10 °C: Bei echten Daten passte das
     * Heizmodell weit unter 15 °C am besten, und darunter rechnete das Normal
     * still mit 10 °C. Ein gespeichertes Normal ohne die neuen Rasterpunkte
     * gilt als veraltet und wird beim nächsten Wetterabgleich einmal neu geholt
     * (ein Archivabruf wie beim ersten Mal, die Datei wächst um acht Spalten).
     */
    public const BASE_MIN  = 6.0;
    public const BASE_MAX  = 22.0;
    public const BASE_STEP = 0.5;
    /** Länge der Referenzperiode in Jahren. */
    public const YEARS = 30;

    public function __construct(
        private JsonStore $store,
        private SettingsService $settings,
    ) {}

    /** @var array<string,mixed>|null */
    private ?array $cached = null;
    private int $cachedAt = -1;

    /**
     * @return array<string,mixed>|null das gespeicherte Normal
     *
     * v2.10.0 — je Schreibstand des Stores einmal gelesen: Das Tankbuch
     * fragt für jeden Tag ohne Temperatur `dayAvg()`.
     */
    public function get(): ?array
    {
        $gen = $this->store->generation();
        if ($this->cachedAt === $gen) return $this->cached;
        $n = $this->store->read(self::FILE, []);
        $this->cached = is_array($n) && isset($n['hdd']) && is_array($n['hdd']) ? $n : null;
        $this->cachedAt = $gen;
        return $this->cached;
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
        if ($n === null || self::outdated($n, $lat, $lon, $currentYear)) return true;
        // v3.13.0 (Paket sonne) — ein Normal ohne Einstrahlung samt Trend (RAD_MARKER) einmal ergänzen
        // v3.13.0 (Paket waermepumpe, E2) — ebenso ohne Trend der Temperatur (TEMP_MARKER), im selben Abruf
        return !array_key_exists(self::RAD_MARKER, $n) || !array_key_exists(self::TEMP_MARKER, $n);
    }

    /**
     * v3.13.0 (Paket waermepumpe, E2) — Merker eines Normals mit dem Trend der Temperatur (`temp_trend`, auch null).
     * Fehlt er (Normal von vor E2), ergänzt der nächste Abgleich ihn einmal aus denselben dreißig Jahren; die
     * Heizgradtage bleiben unverändert (Gas bewegt sich nicht).
     */
    public const TEMP_MARKER = 'temp_trend';

    /**
     * v3.13.0 (Paket waermepumpe, E2) — Der Trend der Jahresmitteltemperatur wird auf das Jahr nach der
     * Referenzperiode fortgeschrieben, höchstens um so viele Kelvin über oder unter dem Mittel der Periode (Schutz vor
     * Ausreißern; an zehn DWD-Stationen lag er für die Zieljahre 2001 bis 2025 zwischen rund 0,4 und 1,1 K).
     */
    public const TEMP_TREND_MAX = 2.0;

    /** Mindestzahl vollständiger Jahre für einen Trend der Temperatur. */
    public const TEMP_TREND_MIN_YEARS = 10;

    /**
     * v3.13.0 (Paket waermepumpe, E2) — Trend der Temperatur aus Tagesmitteln (`YYYY-MM-DD` → °C): Jahresmittel der
     * vollständigen Jahre (alle zwölf Monate mit höchstens einem fehlenden Tag), linear über die Jahre, fortgeschrieben
     * auf das Jahr nach dem letzten, minus das Mittel der Jahre (`temp_trend`, Kelvin, begrenzt auf ± TEMP_TREND_MAX).
     * Ein um ΔT wärmeres Jahr hat die Heizgradtage der Heizgrenze − ΔT (wie `temp_offset` der Prognose). Gemessen an
     * zehn DWD-Stationen (Zieljahre 2001–2025, je die 30 Jahre davor): Das flache Mittel lag die Heizgradtage des
     * Folgejahrs zur Grenze 15 °C im Mittel 10,8 % daneben und 9,0 % zu hoch, mit dem Trend 7,4 % und 1,6 %; zur Grenze
     * 12 °C 14,1 % und + 11,6 % gegen 10,2 % und + 2,6 % (besser als die letzten 10 oder 15 Jahre, ein gedämpfter
     * Trend und der Trend der Gradtage selbst; docs/entwicklung/messung-temperaturtrend.md). Null unter TEMP_TREND_MIN_YEARS Jahren.
     *
     * Dazu `temp_trend_slope` (Kelvin je Jahr) und `temp_trend_center` (Mitte der Jahre): Damit rechnet tempTrend()
     * den Versatz für ein bestimmtes Jahr (die Prognose eines späteren Jahrs, ein Rückblick auf ein früheres), nicht
     * nur für das Jahr nach der Periode.
     *
     * @param array<string,float> $dailyMeans
     * @return array{temp_trend:?float,temp_trend_years:int,temp_trend_method:?string,temp_trend_slope:?float,temp_trend_center:?float}
     */
    public static function temperatureTrend(array $dailyMeans): array
    {
        $sum = []; $cnt = []; $mon = [];
        foreach ($dailyMeans as $date => $t) {
            if (!is_numeric($t) || !preg_match('/^(\d{4})-(\d{2})-\d{2}$/', (string)$date, $m)) continue;
            $y = (int)$m[1];
            $sum[$y] = ($sum[$y] ?? 0.0) + (float)$t;
            $cnt[$y] = ($cnt[$y] ?? 0) + 1;
            $mon[$y][(int)$m[2]] = ($mon[$y][(int)$m[2]] ?? 0) + 1;
        }
        $years = [];
        foreach ($sum as $y => $s) {
            $ok = true;
            for ($mo = 1; $mo <= 12; $mo++) if (($mon[$y][$mo] ?? 0) < (int)date('t', mktime(0, 0, 0, $mo, 1, $y)) - 1) { $ok = false; break; }
            if ($ok) $years[(int)$y] = $s / $cnt[$y];
        }
        $n = count($years);
        $none = ['temp_trend' => null, 'temp_trend_years' => $n, 'temp_trend_method' => null, 'temp_trend_slope' => null, 'temp_trend_center' => null];
        if ($n < self::TEMP_TREND_MIN_YEARS) return $none;
        $xs = array_keys($years); $ys = array_values($years);
        $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
        $num = 0.0; $den = 0.0;
        foreach ($xs as $i => $x) { $num += ($x - $mx) * ($ys[$i] - $my); $den += ($x - $mx) ** 2; }
        if ($den <= 0) return $none;
        $d = $num / $den * (max($xs) + 1 - $mx);
        return ['temp_trend' => self::limitTrend($d), 'temp_trend_years' => $n, 'temp_trend_method' => 'linear_annual',
                'temp_trend_slope' => round($num / $den, 5), 'temp_trend_center' => round($mx, 2)];
    }

    /** Versatz in Kelvin, begrenzt auf ± TEMP_TREND_MAX und auf 0,001 K gerundet. */
    private static function limitTrend(float $d): float
    {
        return round(max(-self::TEMP_TREND_MAX, min(self::TEMP_TREND_MAX, $d)), 3);
    }

    /**
     * v3.13.0 (Paket waermepumpe, E2) — Trend der Temperatur in Kelvin gegen das Mittel der Periode: für `$year` aus
     * Steigung und Mitte (Steigung × (Jahr − Mitte), begrenzt), ohne Jahr der fortgeschriebene Wert für das Jahr nach der
     * Periode (`temp_trend`). Null ohne Trend im Normal (dann das flache Mittel).
     */
    public function tempTrend(?int $year = null): ?float
    {
        $n = $this->get() ?? [];
        $v = $n['temp_trend'] ?? null;
        if (!is_numeric($v) || !is_finite((float)$v)) return null;
        // Der Trend gehört zu den Jahren seiner Periode: Ein Normal über weniger als TEMP_TREND_MIN_YEARS Jahre (etwa das
        // Wetter eines einzelnen Jahres, wie es die Rückrechnung „nachgerechnet“ einsetzt) trägt keinen
        $p = $n['period'] ?? null;
        if (is_array($p) && is_string($p['from'] ?? null) && is_string($p['to'] ?? null)
            && (int)substr($p['to'], 0, 4) - (int)substr($p['from'], 0, 4) + 1 < self::TEMP_TREND_MIN_YEARS) return null;
        $slope = $n['temp_trend_slope'] ?? null;
        $center = $n['temp_trend_center'] ?? null;
        if ($year !== null && is_numeric($slope) && is_numeric($center) && is_finite((float)$slope) && is_finite((float)$center)) {
            return self::limitTrend((float)$slope * ($year - (float)$center));
        }
        return (float)$v;
    }

    /**
     * v3.13.0 (Paket sonne, C2) — Merker eines Normals mit vollständiger Einstrahlung: Fehlt er (Normal von vor
     * v3.13.0, oder mit `rad_month`, aber ohne Trend), ergänzt der nächste Abgleich alle `rad_*`-Felder einmal.
     */
    public const RAD_MARKER = 'rad_month_trend';

    /**
     * v3.13.0 (Paket sonne, C2) — der Trend der Einstrahlung wird auf das Jahr nach der Referenzperiode
     * fortgeschrieben, höchstens um diesen Anteil (Schutz vor Ausreißern; an echten Reihen lag der Faktor zwischen
     * 1,006 und 1,061, docs/entwicklung/messung-einstrahlung.md).
     */
    public const RAD_TREND_MAX = 0.10;

    /** Mindestzahl vollständiger Jahre für einen Trend der Einstrahlung. */
    public const RAD_TREND_MIN_YEARS = 10;

    /** Ort, Raster oder Referenzperiode passen nicht mehr (alles außer der Einstrahlung). */
    private static function outdated(array $n, float $lat, float $lon, ?int $currentYear): bool
    {
        if (abs((float)($n['latitude'] ?? 999) - $lat) > 0.05) return true;
        if (abs((float)($n['longitude'] ?? 999) - $lon) > 0.05) return true;
        $year = $currentYear ?? (int)date('Y');
        if (!self::gridComplete($n)) return true;   // v3.7.0 — Raster ab 6 °C fehlt
        return (int)substr((string)($n['period']['to'] ?? '0000'), 0, 4) < $year - 1;
    }

    /**
     * v3.13.0 (Paket sonne) — fehlt dem gespeicherten Normal nur die Einstrahlung (sonst aktuell)? Dann
     * ergänzt der Abgleich nur `rad_month` und lässt die Heizgradtage, wie sie sind: Ein neuer Abruf derselben
     * dreißig Jahre könnte sie in der letzten Stelle verschieben, und Gas soll sich durch das Update nicht
     * bewegen (Lektion 36).
     */
    public function lacksOnlyRadiation(float $lat, float $lon, ?int $currentYear = null): bool
    {
        $n = $this->get();
        // v3.13.0 (E2) — ebenso, wenn nur der Trend der Temperatur fehlt (TEMP_MARKER): derselbe Abruf, Gradtage bleiben
        return $n !== null && (!array_key_exists(self::RAD_MARKER, $n) || !array_key_exists(self::TEMP_MARKER, $n))
            && !self::outdated($n, $lat, $lon, $currentYear);
    }

    /**
     * v3.13.0 (Paket sonne) — das gespeicherte Normal um die Einstrahlung ergänzt (Heizgradtage unverändert).
     *
     * v3.13.0 (Paket waermepumpe, E2) — mit `$dailyMeans` (Tagesmittel desselben Abrufs) dazu der Trend der
     * Temperatur (temperatureTrend()); die Heizgradtage bleiben auch dann unverändert. Fehlte nur der Trend und
     * bringt der Abruf keine Einstrahlung, bleibt die vorhandene Einstrahlung, wie sie ist.
     *
     * @param array<string,mixed> $normal
     * @param array<string,float> $dailyRad
     * @param array<string,float> $dailyMeans
     * @return array<string,mixed>
     */
    public static function withRadiation(array $normal, array $dailyRad, array $dailyMeans = []): array
    {
        $keepRad = $dailyRad === [] && $dailyMeans !== [] && array_key_exists(self::RAD_MARKER, $normal);
        return array_merge($normal, $keepRad ? [] : self::radiationStats($dailyRad) + ['rad_fetched_at' => date('c')],
            $dailyMeans !== [] ? self::temperatureTrend($dailyMeans) : []);
    }

    /**
     * v3.13.0 (Paket sonne) — Einstrahlung des Normals aus Tageswerten (`YYYY-MM-DD` → kWh/m²):
     *
     *   rad_month    mittlere Monatssumme je Kalendermonat (Januar bis Dezember, kWh/m²) über die Jahre, in
     *                denen der Monat (fast) vollständig ist; null ohne Einstrahlung
     *   rad_sd       Streuung dieser Monatssummen über die Jahre
     *   rad_year_sd  Streuung der Jahressumme (Jahre mit zwölf vollständigen Monaten)
     *   rad_years    Zahl dieser Jahre
     *
     * `rad_month` steht immer im Ergebnis: Ein Normal ohne den Schlüssel stammt von vor v3.13.0.
     *
     * @param array<string,float> $dailyRad
     * @return array{rad_month:?list<?float>,rad_sd:?list<?float>,rad_year_sd:?float,rad_years:int}
     */
    public static function radiationStats(array $dailyRad): array
    {
        $sums = []; $days = [];
        foreach ($dailyRad as $date => $r) {
            if (!is_numeric($r) || !preg_match('/^(\d{4})-(\d{2})-\d{2}$/', (string)$date, $m)) continue;
            $y = (int)$m[1]; $mo = (int)$m[2];
            $sums[$y][$mo] = ($sums[$y][$mo] ?? 0.0) + (float)$r;
            $days[$y][$mo] = ($days[$y][$mo] ?? 0) + 1;
        }
        $complete = [];
        foreach ($days as $y => $months) {
            foreach ($months as $mo => $n) {
                $dim = (int)date('t', mktime(0, 0, 0, $mo, 1, $y));
                // wie die Heizgradtage: höchstens ein fehlender Tag, hochgerechnet auf den ganzen Monat
                if ($n >= $dim - 1) $complete[$y][$mo] = $sums[$y][$mo] * $dim / $n;
            }
        }
        $mean = []; $sd = []; $any = false;
        for ($mo = 1; $mo <= 12; $mo++) {
            $vals = [];
            foreach ($complete as $months) if (isset($months[$mo])) $vals[] = (float)$months[$mo];
            $mean[] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
            $sd[] = count($vals) > 1 ? round(self::stddev($vals), 2) : null;
            if ($vals) $any = true;
        }
        $years = [];
        foreach ($complete as $y => $months) if (count($months) === 12) $years[(int)$y] = array_sum($months);
        $factor = self::radTrendFactor($years);
        return [
            'rad_month'   => $any ? $mean : null,
            'rad_sd'      => $any ? $sd : null,
            'rad_year_sd' => count($years) > 1 ? round(self::stddev(array_values($years)), 2) : null,
            'rad_years'   => count($years),
            // v3.13.0 (Paket sonne, C2) — dieselben Monate auf das Jahr nach der Referenzperiode fortgeschrieben
            'rad_month_trend'  => $any && $factor !== null ? array_map(fn($v) => $v === null ? null : round($v * $factor, 2), $mean) : null,
            'rad_trend_factor' => $factor !== null ? round($factor, 4) : null,
            'rad_trend_method' => $factor !== null ? 'linear_annual' : null,
        ];
    }

    /**
     * v3.13.0 (Paket sonne, C2) — Die Einstrahlung nimmt in Mitteleuropa seit den Achtzigern zu (Aufhellung). An
     * acht Großstädten in DE/AT/CH (Open-Meteo-Archiv, Zieljahre 2001 bis 2025) lag das flache 30-Jahre-Mittel die
     * Jahressumme des Folgejahrs im Mittel 4,1 % daneben, 3,4 % zu niedrig; ein linearer Trend der Jahressummen über
     * dieselben 30 Jahre, auf das Folgejahr fortgeschrieben, 3,2 % (Bias −0,5 %), besser als die letzten 10 oder 15
     * Jahre und als ein gedämpfter Trend (docs/entwicklung/messung-einstrahlung.md). Faktor = (Mittel + Steigung ×
     * (Folgejahr − mittleres Jahr)) ÷ Mittel, begrenzt auf 1 ± RAD_TREND_MAX; null unter RAD_TREND_MIN_YEARS Jahren.
     *
     * @param array<int,float> $years Jahr → Jahressumme (nur vollständige Jahre)
     */
    public static function radTrendFactor(array $years): ?float
    {
        $n = count($years);
        if ($n < self::RAD_TREND_MIN_YEARS) return null;
        $xs = array_keys($years); $ys = array_values($years);
        $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
        if ($my <= 0) return null;
        $num = 0.0; $den = 0.0;
        foreach ($xs as $i => $x) { $num += ($x - $mx) * ($ys[$i] - $my); $den += ($x - $mx) ** 2; }
        if ($den <= 0) return null;
        $f = ($my + $num / $den * (max($xs) + 1 - $mx)) / $my;
        return max(1.0 - self::RAD_TREND_MAX, min(1.0 + self::RAD_TREND_MAX, $f));
    }

    /**
     * v3.13.0 (Paket sonne) — Einstrahlung eines Kalendermonats im Normal: erwartete Monatssumme und ihre
     * Streuung über die Jahre (kWh/m²); null ohne Einstrahlung im Normal. C2: erwartet ist der fortgeschriebene
     * Trend (`rad_month_trend`), wenn das Normal ihn trägt (`trend` = true), sonst das flache Mittel.
     *
     * @return array{mean:float,sd:?float,trend:bool}|null
     */
    public function radForMonth(int $month): ?array
    {
        $n = $this->get();
        if ($n === null || $month < 1 || $month > 12) return null;
        $flat = self::monthValue($n['rad_month'] ?? null, $month);
        if ($flat === null) return null;
        $trend = self::monthValue($n['rad_month_trend'] ?? null, $month);
        return ['mean' => $trend ?? $flat, 'sd' => self::monthValue($n['rad_sd'] ?? null, $month), 'trend' => $trend !== null];
    }

    /** v3.13.0 (C2) — Trägt das Normal den fortgeschriebenen Trend der Einstrahlung für alle zwölf Monate? */
    public function hasRadiationTrend(): bool
    {
        for ($m = 1; $m <= 12; $m++) if (!($this->radForMonth($m)['trend'] ?? false)) return false;
        return true;
    }

    /** v3.13.0 — Kennt das Normal die Einstrahlung aller zwölf Monate? */
    public function hasRadiation(): bool
    {
        for ($m = 1; $m <= 12; $m++) if ($this->radForMonth($m) === null) return false;
        return true;
    }

    /**
     * v3.13.0 — mittlere Einstrahlung je Tag eines Kalendermonats im Normal (kWh/m²): die Monatssumme durch
     * die mittlere Länge des Monats (Februar 28,25 Tage, über die Schaltjahre gemittelt).
     */
    public function radPerDay(int $month): ?float
    {
        $r = $this->radForMonth($month);
        if ($r === null) return null;
        return $r['mean'] / ($month === 2 ? 28.25 : (int)date('t', mktime(0, 0, 0, $month, 1, 2001)));
    }

    /** v3.13.0 — Streuung der Jahressumme der Einstrahlung (kWh/m²), null ohne. */
    public function radYearSd(): ?float
    {
        $v = $this->get()['rad_year_sd'] ?? null;
        return is_numeric($v) ? (float)$v : null;
    }

    /**
     * v3.13.0 — Wert eines Kalendermonats aus einer Liste (Januar = Index 0, wie `hdd.*.mean`) oder einer
     * Zuordnung mit den Schlüsseln 1 bis 12.
     */
    private static function monthValue(mixed $list, int $month): ?float
    {
        if (!is_array($list)) return null;
        $v = array_is_list($list) ? ($list[$month - 1] ?? null) : ($list[$month] ?? $list[(string)$month] ?? null);
        return is_numeric($v) && is_finite((float)$v) && (float)$v >= 0 ? (float)$v : null;
    }

    /** Trägt ein gespeichertes Normal das ganze heutige Raster? */
    private static function gridComplete(array $n): bool
    {
        return isset($n['hdd'][number_format(self::BASE_MIN, 1, '.', '')], $n['hdd'][number_format(self::BASE_MAX, 1, '.', '')]);
    }

    /**
     * v3.7.0 (wirklichkeit-2) — Deckt das gespeicherte Normal diese Heizgrenze ab?
     * Sonst gibt es für sie kein Normal (die Rechnung nimmt die eigene
     * Temperaturhistorie und sagt es), statt still mit der nächsten Rastergrenze
     * zu rechnen.
     */
    public function covers(?float $base = null): bool
    {
        $n = $this->get();
        if ($n === null) return false;
        $b = $base ?? (float)$this->settings->get('hdd_base_temp', 15.0);
        if ($b < self::BASE_MIN - 1e-9 || $b > self::BASE_MAX + 1e-9) return false;
        [$lo, $hi] = $this->gridPoints($b);
        return isset($n['hdd'][$lo], $n['hdd'][$hi]);
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
     * v3.13.0 (Paket sonne) — mit `$dailyRad` (Tag → Einstrahlung in kWh/m²) dazu `rad_month` und seine
     * Streuung ({@see radiationStats()}).
     *
     * @param array<string,float> $dailyMeans
     * @param array<string,float> $dailyRad
     * @return array<string,mixed>
     */
    public static function compute(array $dailyMeans, float $lat, float $lon, array $period, array $dailyRad = []): array
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
        ] + self::radiationStats($dailyRad) + self::temperatureTrend($dailyMeans);   // v3.13.0 (E2) — Trend der Temperatur
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
        if ($n === null || $month < 1 || $month > 12 || !$this->covers($base)) return null;
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
        if ($n === null || !$this->covers($base)) return null;
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
