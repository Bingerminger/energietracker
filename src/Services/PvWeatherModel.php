<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * v3.13.0 (Paket sonne) — PV-Erzeugung und Einspeisung nach dem Wetter.
 *
 * Bis v3.12 folgte die PV dem Saisonprofil der gemessenen Monate: Nach einem sonnigen Jahr lag die Prognose zu
 * hoch, und zwischen zwei Ablesungen war jeder Tag gleich (Konzept S7). Jetzt:
 *
 *   Erzeugung je Monat  = k(Jahreszeit) × Einstrahlung des Monats
 *   Einspeisung je Monat = k′(Jahreszeit) × Einstrahlung des Monats (mit FEED_BASE − e × Tage), nur aus den
 *                          jüngsten FEED_RECENT_DAYS Tagen
 *
 * In der Prognose mischt sich das Modell wie bei Gas mit dem Saisonprofil: Monate mit eigener Messung zu PV_BLEND
 * aus dem Modell mit dem Normal, der Rest aus den gemessenen Jahren; Monate ohne eigene Messung ganz aus dem Modell.
 *
 * Abgestimmt am Prüfhaushalt A/V1 (tests/fixtures/heatpump-household, tools/rueckrechnung.php), Schwellen aus dem
 * Paketplan v3.13.0. Was die Schwelle nicht schafft, bleibt beim alten Weg mit Hinweis (`not_validated`): Schalter
 * GEN_ENABLED und FEED_ENABLED.
 *
 * Die Einstrahlung ist die Globalstrahlung auf die Waagerechte (Open-Meteo, `rad` in kWh/m² am Tag). Ein Süddach
 * bekommt im Winter mehr als sie, ein Ost-West-Dach im Sommer verhältnismäßig mehr; darum ein k je Jahreszeit.
 * Die Jahreszeiten folgen dem Sonnenstand (Deklination zur Monatsmitte), paarweise um die Sonnenwenden:
 * November bis Januar, Februar und Oktober, März und September, April und August, Mai bis Juli (GROUPS).
 *
 * k kommt aus den Ablesungsabständen (Summe der Einstrahlung über die Tage eines Abstands), nicht aus den
 * Monatszeilen: Zwischen zwei Ablesungen verteilt die App den Verbrauch nach Tagen, ein Monat aus einem langen
 * Abstand trüge sonst Juni-Sonne in den Dezember. Je Abstand Gewicht 30,4 ÷ Tage (die Streuung wächst mit der
 * Länge, wie beim Heizmodell). Eine Jahreszeit ohne eigene Daten bekommt das k aller Abstände: Die Anpassung
 * zieht jedes k(Jahreszeit) leicht zum gemeinsamen k hin (RIDGE), so bleibt sie auch mit wenigen langen Abständen
 * bestimmt. Ein Ausreißer (Wechselrichter wochenlang aus) fällt einmal heraus wie beim Heizmodell.
 *
 * Prognose = k × mittlere Monatssumme des Klimanormals (`rad_month`, ClimateNormalService), nie die eigene
 * Historie: Nur so trennt die Rückrechnung „nachgerechnet“ (Normal = Wetter des Prognosejahrs) den Modellfehler
 * vom Wetterglück. Mindestens MIN_DAYS Tage in verwertbaren Abständen (Konzept §3: zwei Ablesungen mindestens sechs
 * Monate auseinander); darunter, ohne Einstrahlung oder ohne Normal mit Einstrahlung bleibt das Saisonprofil, und
 * die Prognose sagt warum (`pv_no_weather`).
 */
final class PvWeatherModel
{
    /**
     * Schalter je Baustein: aus = alter Weg (Saisonprofil), dazu der Hinweis `not_validated`. Die Rückrechnung
     * übersteuert sie per Option `pv_weather` (Prognose) bzw. ConsumptionService::setPvWeather(). Stand am
     * Prüfhaushalt A/V1 (Schwellen „PV Erzeugung und Einspeisung“ des Paketplans v3.13.0):
     *   Erzeugung   aus: nachgerechnet 2,5 % (Schwelle ± 5 %), vorab 2,1 % gegen alt 3,4 % (Schwelle 0,8 × alt), aber
     *               ein Schnitt 3,6 Punkte schlechter als alt (Schwelle 3): Am ersten Schnitt fehlte dem alten Weg der
     *               Juni (`filled`, rund 40 % zu niedrig), das glich zu hohe andere Monate zufällig aus
     *   Einspeisung aus: nachgerechnet 19,9 % gegen alt 33,4 % (Schwelle ± 5 %). Das Fenster enthält Geräte, die es am
     *               Schnitt noch nicht gab (Speicher, Wallbox); kein Modell kennt sie vorher
     */
    public const GEN_ENABLED = false;
    public const FEED_ENABLED = false;

    /**
     * Saldo der Einspeisung („Erwartet bei Abrechnung“) nach der Einstrahlung: an. Am Prüfhaushalt A/V1 mittlerer
     * Fehler 28 € gegen alt 150 € (Schwelle „Erwartet bei Abrechnung“: ≤ 0,8 × alt). Der alte Weg rechnete das Mittel
     * der abgelesenen Monate ab heute hoch, die Tage seit der letzten Ablesung fehlten.
     */
    public const FEED_SALDO_ENABLED = true;

    /**
     * Anteil des Modells in Monaten mit eigener Messung (der Rest: Saisonprofil der gemessenen Jahre), wie die Mischung
     * bei Gas (Einstellung blend_max, dort 0,8). Am Prüfhaushalt A/V1 abgestimmt: Mit 0,8 lag ein Schnitt vorab
     * 3,9 Punkte schlechter als alt (Schwelle 3); mit 0,5 sind es 2,4. Die gemessenen Jahre tragen mit, was das
     * dreißigjährige Normal nicht kennt: dass die letzten Jahre sonniger waren (Aufhellung).
     */
    public const PV_BLEND = 0.5;

    /**
     * v3.13.0 (C2) — Anteil des Modells in Monaten mit eigener Messung: 1 (keine Mischung), wenn das Normal den Trend
     * der Einstrahlung trägt (an echten Reihen verschlechterte eine Mischung mit den Vorjahren den fortgeschriebenen
     * Trend: Monatsfehler 8,9 statt 8,4 %, Jahr 3,3 statt 3,2 %), und nie bei der Einspeisung (ihr Saisonprofil trägt
     * die Jahre vor Speicher und Wallbox, das Modell nur die jüngsten FEED_RECENT_DAYS); sonst PV_BLEND.
     */
    public static function blendWeight(string $utility, bool $trend): float
    {
        return Utilities::isFeedIn($utility) || $trend ? 1.0 : self::PV_BLEND;
    }

    /** Gilt der Baustein dieser Art (Erzeugung oder Einspeisung) als geprüft und angezeigt? */
    public static function enabled(string $utility): bool
    {
        return Utilities::isFeedIn($utility) ? self::FEED_ENABLED : self::GEN_ENABLED;
    }

    /** Jahreszeit je Kalendermonat nach dem Sonnenstand (0 = November bis Januar … 4 = Mai bis Juli). */
    public const GROUPS = [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 4, 7 => 4, 8 => 3, 9 => 2, 10 => 1, 11 => 0, 12 => 0];

    /** Mindestens so viele Tage in verwertbaren Abständen (rund sechs Monate, Konzept §3). */
    public const MIN_DAYS = 182;

    /** Ein Abstand zählt, wenn für so viele seiner Tage eine Einstrahlung vorliegt (der Rest aus dem Normal). */
    public const RAD_COVERAGE = 0.9;

    /** Kurze Abstände (täglicher Stand) werden zu Stücken von mindestens so vielen Tagen zusammengelegt. */
    public const MERGE_DAYS = 7;

    /** Zug jedes k(Jahreszeit) zum gemeinsamen k, relativ zum Gewicht dieser Jahreszeit (1 %). */
    public const RIDGE = 0.01;

    /** Eine Jahreszeit gilt als gemessen ab so vielen Tagen in ihren Monaten (Auskunft `groups_measured`). */
    public const GROUP_MIN_DAYS = 15;

    /** Ab so vielen Punkten trägt die Streuung ein Band (wie das Heizmodell, ConsumptionService::MIN_POINTS_REGRESSION). */
    public const BAND_MIN_POINTS = 8;

    /**
     * Einspeisung mit Eigenverbrauch je Tag (k′ × H − e × Tage); aus: k′ × H wie die Erzeugung. Am Prüfhaushalt A/V1
     * gleich gut (vorab 15,1 gegen 15,2 %); ohne e rechnen Monat und Tag (Saldo) dieselbe Formel ohne Klemme bei null.
     */
    public const FEED_BASE = false;

    /**
     * Einspeisung nur aus den jüngsten so vielen Tagen (0 = alle), wenn sie MIN_DAYS tragen: Was eingespeist wird, hängt
     * am Eigenverbrauch, und der ändert sich mit Speicher, Wallbox und Wärmepumpe. Die Erzeugung nimmt alle Abstände.
     * Am Prüfhaushalt A/V1 halbierte das den Fehler (vorab 26,0 → 15,1 %).
     */
    public const FEED_RECENT_DAYS = 365;

    /**
     * Nur die Abstände, die nach dem Tag `$since` enden, wenn sie zusammen MIN_DAYS Tage tragen; sonst alle.
     *
     * @param list<array{d:float,to:string}> $points
     * @return list<array{d:float,to:string}>
     */
    public static function recent(array $points, string $since): array
    {
        $r = array_values(array_filter($points, fn($p) => (string)$p['to'] > $since));
        return array_sum(array_column($r, 'd')) >= self::MIN_DAYS ? $r : $points;
    }

    private const MONTH_DAYS = 365.25 / 12;

    /** Gilt das Modell für diesen Zähler? Erzeugung (Rolle generation) und Einspeisung, keine Gruppe. */
    public static function applies(string $utility, array $meter): bool
    {
        if (!empty($meter['is_group'])) return false;
        if (Utilities::isFeedIn($utility)) return true;
        return Utilities::isGenerationOnly($utility) && Utilities::roleOf($utility, $meter) === 'generation';
    }

    /**
     * Punkte aus den Ablesungsabständen: je Abstand die Einstrahlung je Jahreszeit, Tage, Menge und Gewicht.
     * Ausgelassen: Abstände mit Tagen vor der Zäsur (`$excluded` = Monate YYYY-MM), mit weniger als RAD_COVERAGE
     * Tagen mit Einstrahlung, mit negativer Menge, nach `$until` (Stichtag).
     *
     * @param list<array{0:string,1:string,2:float}> $spans [von, bis (ohne), Menge]
     * @param array<string,mixed> $temps temperatures.json
     * @param callable(int):?float $normalPerDay mittlere Einstrahlung je Tag des Kalendermonats im Normal
     * @param array<string,bool> $excluded
     * @return list<array{h:array<int,float>,d:float,y:float,w:float,from:string,to:string}>
     */
    public static function points(array $spans, array $temps, callable $normalPerDay, array $excluded = [], ?string $until = null): array
    {
        $rows = [];
        foreach ($spans as [$from, $to, $y]) {
            if ($until !== null && $to > $until) continue;
            if ((float)$y < 0) continue;
            $h = array_fill(0, 5, 0.0); $dg = array_fill(0, 5, 0); $days = 0; $radDays = 0; $skip = false;
            for ($t = strtotime($from . ' 12:00:00'), $e = strtotime($to . ' 00:00:00'); $t !== false && $t < $e; $t += 86400) {
                $d = date('Y-m-d', $t);
                if (isset($excluded[substr($d, 0, 7)])) { $skip = true; break; }
                $mo = (int)date('n', $t);
                $days++;
                $r = TemperatureService::radValue(is_array($temps[$d] ?? null) ? ($temps[$d]['rad'] ?? null) : null);
                if ($r !== null) $radDays++;
                else $r = $normalPerDay($mo);
                if ($r === null) { $skip = true; break; }
                $h[self::GROUPS[$mo]] += $r;
                $dg[self::GROUPS[$mo]]++;
            }
            if ($skip || $days === 0 || $radDays < $days * self::RAD_COVERAGE) continue;
            $rows[] = ['h' => $h, 'dg' => $dg, 'd' => (float)$days, 'y' => (float)$y, 'from' => (string)$from, 'to' => (string)$to];
        }
        // kurze, lückenlos aneinanderliegende Abstände zusammenlegen (Summen sind additiv)
        $out = []; $cur = null;
        foreach ($rows as $r) {
            if ($cur !== null && $cur['d'] < self::MERGE_DAYS && $cur['to'] === $r['from']) {
                foreach ($r['h'] as $g => $v) { $cur['h'][$g] += $v; $cur['dg'][$g] += $r['dg'][$g]; }
                $cur['d'] += $r['d']; $cur['y'] += $r['y']; $cur['to'] = $r['to'];
                continue;
            }
            if ($cur !== null) $out[] = $cur;
            $cur = $r;
        }
        if ($cur !== null) $out[] = $cur;
        foreach ($out as &$p) $p['w'] = self::MONTH_DAYS / $p['d'];
        unset($p);
        return $out;
    }

    /**
     * Anpassung y = Σ k_g × H_g (− e × Tage) mit Zug zum gemeinsamen k, ein Robustheitsschritt.
     *
     * @param list<array{h:array<int,float>,d:float,y:float,w:float}> $points
     * @return array{k:array<int,float>,k_all:float,e:float,n:int,days:int,resid_sd:float,groups:list<int>,dropped:int,base:bool}|null
     */
    public static function fit(array $points, bool $withBase = false): ?array
    {
        if ($points === []) return null;
        $m = self::solve($points, $withBase);
        if ($m === null) return null;
        // ein Robustheitsschritt wie beim Heizmodell (ConsumptionService::fitHeatPoints)
        $res = array_map(fn($p) => ($p['y'] - self::fitted($m, $p)) * sqrt($p['w']), $points);
        [$center, $scale] = AnomalyService::robustScale(array_values($res));
        $dropped = 0;
        if ($scale > 0) {
            $kept = [];
            foreach ($points as $i => $p) if (abs($res[$i] - $center) <= 3.5 * $scale) $kept[] = $p;
            if (count($kept) < count($points) && count($kept) >= 2) {
                $refit = self::solve($kept, $withBase);
                if ($refit !== null) { $dropped = count($points) - count($kept); $points = $kept; $m = $refit; }
            }
        }
        $sq = 0.0;
        foreach ($points as $p) $sq += $p['w'] * ($p['y'] - self::fitted($m, $p)) ** 2;
        $par = count($m['groups']) + ($m['base'] ? 1 : 0);
        $m['resid_sd'] = count($points) > $par ? sqrt($sq / (count($points) - $par)) : 0.0;
        $m['n'] = count($points);
        $m['days'] = (int)array_sum(array_column($points, 'd'));
        $m['dropped'] = $dropped;
        return $m;
    }

    /** Menge eines Kalendermonats: k(Jahreszeit) × Einstrahlung − e × Tage, nie unter null. */
    public static function predict(array $model, int $month, float $rad, float $days): float
    {
        $k = (float)($model['k'][self::GROUPS[$month]] ?? $model['k_all'] ?? 0.0);
        return max(0.0, $k * $rad - (float)($model['e'] ?? 0.0) * $days);
    }

    /**
     * Prognosemonat mit Band: Wetter = k × Streuung der Monatssumme im Normal, Modell = Streuung der Anpassung
     * je Monat (`resid_sd` gilt für 30,4 Tage, die Varianz wächst mit den Tagen). Kein Band unter BAND_MIN_POINTS.
     *
     * Mit `$seasonal` (Menge des Saisonprofils, wenn der Kalendermonat gemessen ist) die Mischung PV_BLEND × Modell +
     * (1 − PV_BLEND) × Saisonprofil; das Band behält seine Breite.
     *
     * @param array{mean:float,sd:?float} $normal Einstrahlung des Monats im Normal
     * @return array{value:float,k:float,low:?float,high:?float,w_sd:?float,n_sd:?float,blended:bool}
     */
    public static function month(array $model, int $month, array $normal, int $days, float $z, ?float $seasonal = null, float $w = self::PV_BLEND): array
    {
        $value = self::predict($model, $month, (float)$normal['mean'], (float)$days);
        if ($seasonal !== null && $w < 1.0) $value = $w * $value + (1.0 - $w) * $seasonal;
        else $seasonal = null;
        $k = (float)($model['k'][self::GROUPS[$month]] ?? $model['k_all'] ?? 0.0);
        $out = ['value' => $value, 'k' => $k, 'low' => null, 'high' => null, 'w_sd' => null, 'n_sd' => null, 'blended' => $seasonal !== null];
        if (empty($model['band'])) return $out;
        $wSd = $normal['sd'] !== null ? $k * (float)$normal['sd'] : null;
        $nSd = (float)$model['resid_sd'] * sqrt($days / self::MONTH_DAYS);
        $half = $z * sqrt(($wSd ?? 0.0) ** 2 + $nSd ** 2);
        return ['low' => round(max(0.0, $value - $half), 1), 'high' => round($value + $half, 1), 'w_sd' => $wSd, 'n_sd' => $nSd] + $out;
    }

    /**
     * Werkstatt: Faktor je Monat, der ein gemessenes Grundjahr auf ein Jahr mit normaler Sonne bringt —
     * Einstrahlung des Normals ÷ Einstrahlung des Monats (je Tag gerechnet, Februar im Schaltjahr wie sonst).
     * Fehlende Tage (bis 1 − RAD_COVERAGE) aus dem Normal. Null, wenn das Normal keine Einstrahlung kennt oder
     * ein Monat zu wenig Tage mit Einstrahlung hat: Dann rechnet die Werkstatt das ganze Jahr wie gemessen.
     *
     * @param list<string> $yms Monate des Grundjahrs (YYYY-MM)
     * @param array<string,mixed> $temps temperatures.json
     * @param callable(int):?float $normalPerDay
     * @return array<string,float>|null Monat → Faktor
     */
    public static function normalFactors(array $yms, array $temps, callable $normalPerDay): ?array
    {
        $out = [];
        foreach ($yms as $ym) {
            if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$ym, $mm)) return null;
            $mo = (int)$mm[2];
            $perDay = $normalPerDay($mo);
            if ($perDay === null || $perDay <= 0) return null;
            $dim = (int)date('t', mktime(0, 0, 0, $mo, 1, (int)$mm[1]));
            $sum = 0.0; $have = 0;
            for ($d = 1; $d <= $dim; $d++) {
                $day = sprintf('%s-%02d', $ym, $d);
                $r = TemperatureService::radValue(is_array($temps[$day] ?? null) ? ($temps[$day]['rad'] ?? null) : null);
                if ($r !== null) $have++;
                $sum += $r ?? $perDay;
            }
            if ($have < $dim * self::RAD_COVERAGE || $sum <= 0) return null;
            $out[(string)$ym] = $perDay * $dim / $sum;
        }
        return $out;
    }

    /** Kurzbeschreibung des Modells für Antworten (additiv, nur bei PV-Zählern). */
    public static function summary(array $model): array
    {
        $k = [];
        foreach ($model['k'] as $g => $v) $k[(string)$g] = round((float)$v, 4);
        return ['basis' => 'intervals', 'k' => $k, 'k_all' => round((float)$model['k_all'], 4), 'e_per_day' => round((float)$model['e'], 3),
                'groups_measured' => $model['groups'], 'n' => $model['n'], 'days' => $model['days'], 'dropped' => $model['dropped'],
                'band' => !empty($model['band']), 'blend' => self::PV_BLEND];
    }

    // ── Intern ──────────────────────────────────────────────────────────

    private static function fitted(array $m, array $p): float
    {
        $v = 0.0;
        foreach ($p['h'] as $g => $h) $v += (float)$m['k'][$g] * $h;
        return $v - ($m['base'] ? (float)$m['e'] * $p['d'] : 0.0);
    }

    /**
     * Gewichtete kleinste Quadrate in zwei Schritten: erst ein gemeinsames k (und e), dann je Jahreszeit mit Zug
     * zum gemeinsamen k. k ≥ 0, e ≥ 0 (sonst ohne e neu).
     *
     * @return array{k:array<int,float>,k_all:float,e:float,groups:list<int>,base:bool}|null
     */
    private static function solve(array $points, bool $withBase): ?array
    {
        // 1. gemeinsames k (und e)
        $cols = fn(array $p): array => $withBase ? [array_sum($p['h']), -$p['d']] : [array_sum($p['h'])];
        $common = self::wls($points, $cols, []);
        if ($common === null) return null;
        $kAll = max(0.0, $common[0]);
        $eAll = $withBase ? $common[1] : 0.0;
        if ($withBase && $eAll < 0) return self::solve($points, false);
        // 2. je Jahreszeit, Zug zum gemeinsamen k: je Jahreszeit relativ zu ihrem eigenen Gewicht (der Winter hat
        // wenig Einstrahlung und damit wenig Gewicht, ein gemeinsamer Zug drückte ihn aufs Jahres-k); eine
        // Jahreszeit ohne Daten bekommt das gemeinsame k
        $diag = array_fill(0, 5, 0.0);
        foreach ($points as $p) foreach ($p['h'] as $g => $h) $diag[$g] += $p['w'] * $h * $h;
        $present = array_filter($diag, fn($v) => $v > 0);
        if ($present === []) return null;
        $floor = 1e-6 * array_sum($present) / count($present);
        $gcols = fn(array $p): array => $withBase ? [...array_values($p['h']), -$p['d']] : array_values($p['h']);
        $prior = [];
        for ($g = 0; $g < 5; $g++) $prior[$g] = [self::RIDGE * max($diag[$g], $floor), $kAll];
        $sol = self::wls($points, $gcols, $prior);
        if ($sol === null) return null;
        $k = [];
        for ($g = 0; $g < 5; $g++) $k[$g] = max(0.0, (float)$sol[$g]);
        $e = $withBase ? (float)$sol[5] : 0.0;
        if ($withBase && $e < 0) return self::solve($points, false);
        // Jahreszeiten mit eigenen Daten: mindestens GROUP_MIN_DAYS Tage in ihren Monaten
        $days = array_fill(0, 5, 0.0);
        foreach ($points as $p) foreach ($p['dg'] ?? [] as $g => $d) $days[$g] += $d;
        $groups = array_keys(array_filter($days, fn($d) => $d >= self::GROUP_MIN_DAYS));
        return ['k' => $k, 'k_all' => $kAll, 'e' => max(0.0, $e), 'groups' => array_values($groups), 'base' => $withBase && $e > 0];
    }

    /**
     * Gewichtete kleinste Quadrate mit optionalem Zug einzelner Koeffizienten zu einem Wert
     * (Normalgleichungen, Gauß mit Spaltenpivot). Null, wenn das System singulär ist.
     *
     * @param callable(array):list<float> $cols
     * @param array<int,array{0:float,1:float}> $prior Index → [Gewicht, Zielwert]
     * @return list<float>|null
     */
    private static function wls(array $points, callable $cols, array $prior): ?array
    {
        $n = count($cols($points[0]));
        $a = array_fill(0, $n, array_fill(0, $n, 0.0));
        $b = array_fill(0, $n, 0.0);
        foreach ($points as $p) {
            $x = $cols($p);
            for ($i = 0; $i < $n; $i++) {
                $b[$i] += $p['w'] * $x[$i] * $p['y'];
                for ($j = 0; $j < $n; $j++) $a[$i][$j] += $p['w'] * $x[$i] * $x[$j];
            }
        }
        foreach ($prior as $i => [$lam, $target]) { $a[$i][$i] += $lam; $b[$i] += $lam * $target; }
        for ($c = 0; $c < $n; $c++) {
            $piv = $c;
            for ($r = $c + 1; $r < $n; $r++) if (abs($a[$r][$c]) > abs($a[$piv][$c])) $piv = $r;
            if (abs($a[$piv][$c]) < 1e-12) return null;
            [$a[$c], $a[$piv]] = [$a[$piv], $a[$c]];
            [$b[$c], $b[$piv]] = [$b[$piv], $b[$c]];
            for ($r = 0; $r < $n; $r++) {
                if ($r === $c) continue;
                $f = $a[$r][$c] / $a[$c][$c];
                if ($f == 0.0) continue;
                for ($j = $c; $j < $n; $j++) $a[$r][$j] -= $f * $a[$c][$j];
                $b[$r] -= $f * $b[$c];
            }
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) $out[] = $b[$i] / $a[$i][$i];
        return $out;
    }
}
