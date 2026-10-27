<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;

/**
 * v3.9.0 (ablesung-pruefen) — Zwischenablesung prüfen.
 *
 * Ein falscher Stand zwischen zwei richtigen verschiebt Verbrauch still von einem
 * Abschnitt in den nächsten: Die Summe stimmt, Monatswerte, Abrechnungsjahr,
 * Heizmodell (und damit der Heizgrenze-Vorschlag) und Prognose nicht. Dieser Dienst
 * findet solche Stände und fragt nach. Er korrigiert nie selbst.
 *
 * Je Zähler einer Art mit Zählerständen (keine Zeiträume, keine Gruppe, in Betrieb):
 *
 *   Abstand     zwei aufeinanderfolgende Ablesungen auf demselben Gerät, in der
 *               Verbrauchseinheit (Gas mit den datierten Faktoren). Nicht dabei:
 *               geplante, verdächtige, von der Plausibilitätsprüfung übergangene und
 *               „nur für die Abrechnung“ markierte Stände, am selben Tag nur der erste,
 *               nichts vor der wirksamen Analyse-Zäsur.
 *   Erwartung   Heizarten (Gas, Fernwärme, Heizwärme, Strom der Wärmepumpe): das
 *               Heizmodell a × HGT + c × Tage mit der Heizgrenze des Zählers bzw. seiner
 *               Gruppe, gefittet über die Ablesungsabstände (zu Stücken ab
 *               `min_days_period` Tagen zusammengefasst, wie heatIntervalPoints).
 *               Strom und Wasser: die mittlere Tagesmenge der Abstände bis ein halbes
 *               Jahr davor und danach. PV und Heizarten ohne Modell: dieselben Tage in
 *               anderen Jahren (Median). Ohne Erwartung keine Prüfung.
 *   Kandidat    ein Stand oder ein Block aufeinanderfolgender Stände (höchstens
 *               MAX_BLOCK, höchstens MAX_BLOCK_DAYS Tage), bei dem der Abstand davor
 *               deutlich unter und der danach deutlich über der Erwartung liegt (oder
 *               umgekehrt), die Abstände im Block normal sind, die Summe über beide
 *               ungefähr passt (ein gemeinsamer Versatz erklärt beide) und die Abstände
 *               davor und danach nicht in dieselbe Richtung abweichen (sonst ist es ein
 *               anderes Niveau, kein falscher Stand). Ein Prüfer, der nur Nachbarpaare
 *               ansieht, erkennt einen Block nicht: Darin ist jeder Abstand normal.
 *   deutlich    alle drei: Verhältnis jenseits RATIO, Abweichung mindestens MIN_SHARE
 *               eines typischen Monats und mindestens SIGMA Streuungen des Modells.
 *   Gegenprobe  Erwartungen für den Kandidaten neu, ohne die geprüften Abstände
 *               (Lektion 46: eine Erwartung, die den geprüften Wert enthält, prüft nichts).
 *
 * Dazu doppelt eingetragene Stände (derselbe Tag, derselbe Stand) und der Hinweis auf
 * die Analyse-Zäsur: Lag der Verbrauch mindestens ein Jahr am Stück gleichbleibend weit
 * über bzw. unter dem heutigen Niveau, ohne Gegenbewegung, ist das ein anderer
 * Verbrauch (etwa früher anders geheizt), kein Ablesefehler.
 *
 * „Stand ist richtig“ (`is_verified` an der Ablesung) beendet die Frage für jeden
 * Block mit diesem Stand; „nur für die Abrechnung“ (`is_billing_only`) nimmt ihn aus
 * den Monatswerten (ConsumptionService::billingOnlyBridges) und aus dieser Prüfung.
 */
final class ReadingCheckService
{
    /**
     * Verhältnis gemessen ÷ erwartet, ab dem ein Abstand „deutlich“ abweicht: unter 3/4
     * bzw. über 4/3 (im Logarithmus symmetrisch). Ein Monat streut im Alltag um 10 %
     * (Rauschuntergrenze der Anomalien), bei schlecht passendem Heizmodell um 15 %
     * (Gütesperre); ein Drittel ist das Zwei- bis Dreifache. Gefragt wird erst, wenn ZWEI
     * Abstände in entgegengesetzter Richtung so weit abweichen und ein gemeinsamer Versatz
     * beide erklärt. Die schnelle Prüfung „unter 0,5× oder über 2×“ übersah einen
     * Zahlendreher in einem Wintermonat (900 von 1.845 kWh: 1,49× und 0,43×).
     */
    public const RATIO = 4 / 3;

    /**
     * Mindestmenge je Seite: ein Viertel eines typischen Monats (Verbrauch der letzten
     * zwölf Monate ÷ 12). Die Anomalie-Erkennung meldet einen Monat ab rund 20 % eines
     * typischen Monats; was darunter liegt, geht im Alltagsrauschen unter. Kurze Abstände
     * im Sommer (eine Woche, wenige kWh) haben oft ein großes Verhältnis und eine kleine
     * Menge: gemeldet wird nach der Menge, nicht allein nach dem Verhältnis.
     */
    public const MIN_SHARE = 0.25;

    /**
     * Abweichung mindestens so viele Streuungen: beim Heizmodell dessen Streuung je Monat,
     * auf die Länge des Abstands umgerechnet (√(Tage ÷ 30,4)); bei denselben Tagen anderer
     * Jahre die Streuung zwischen den Jahren (YEAR_SPREAD_MIN). Bei einem verrauschten
     * Haushalt fragt die Prüfung so erst bei entsprechend größeren Abweichungen. Die
     * mittlere Tagesmenge hat keine eigene Streuung; dort tragen Verhältnis und Menge.
     */
    public const SIGMA = 3.0;

    /**
     * „Die Summe über beide passt ungefähr“: Was nach dem gemeinsamen Versatz übrig
     * bleibt (|Abweichung davor + Abweichung danach|), ist höchstens halb so groß wie der
     * Versatz. Er erklärt dann mindestens drei Viertel jeder der beiden Abweichungen.
     */
    public const SUM_TOLERANCE = 0.5;

    /**
     * Ein Block hat höchstens so viele Stände, vom ersten bis zum letzten höchstens ein
     * halbes Jahr. Der Fall aus der Wirklichkeit hatte drei Stände in sieben Wochen;
     * ein gleicher Versatz über Monate ist von einem anderen Verbrauch nicht zu
     * unterscheiden (dafür gibt es den Hinweis auf die Zäsur).
     */
    public const MAX_BLOCK = 6;
    public const MAX_BLOCK_DAYS = 183;

    /** Strom und Wasser: Fenster der mittleren Tagesmenge je Seite. */
    public const LOCAL_WINDOW_DAYS = 183;

    /**
     * Dieselben Tage anderer Jahre (PV, Garten, Heizen ohne Modell): Streuung mindestens
     * ein Fünftel der Erwartung. Derselbe Monat schwankt von Jahr zu Jahr mit Sonne,
     * Wetter und Urlaub um 15 bis 30 %; mit SIGMA fragt die Prüfung dort erst ab 60 %
     * Abweichung je Seite. An den Beispielhaushalten meldete die Rauschuntergrenze von
     * 10 % sonst Monate, die nur sonniger als die Vorjahre waren.
     */
    public const YEAR_SPREAD_MIN = 0.2;

    /**
     * Hinweis auf die Analyse-Zäsur: mindestens LEVEL_MIN_DAYS Tage am Stück vor dem
     * Wechsel, jeder nennenswerte Abstand mindestens LEVEL_STEP über (bzw. unter) dem
     * heutigen Niveau (keine Gegenbewegung), zusammen mindestens LEVEL_RATIO. Das
     * heutige Niveau kommt aus der Zeit nach dem Wechsel (mindestens LEVEL_AFTER_DAYS).
     * Gibt es schon eine Zäsur bis LEVEL_NEAR_DAYS Tage daneben, entfällt der Hinweis.
     */
    public const LEVEL_RATIO = 1.5;
    public const LEVEL_STEP = 1.25;
    public const LEVEL_MIN_DAYS = 365;
    public const LEVEL_AFTER_DAYS = 182;
    public const LEVEL_NEAR_DAYS = 62;
    /**
     * Nach dem Wechsel darf höchstens ein Zehntel der Tage wieder auf dem alten Niveau
     * liegen (ein einzelner kalter Monat), und der erste Abstand danach gar nicht: Sonst
     * ist es eine Gegenbewegung, kein neues Niveau.
     */
    public const LEVEL_AFTER_BACK = 0.1;

    /** Tage eines mittleren Monats (wie ConsumptionService). */
    private const MONTH_DAYS = 365.25 / 12;

    /** @var array<string,array<string,mixed>> Ergebnis je Zähler, Datenstand und Tag */
    private array $memo = [];

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private ReadingService $readings,
        private ConsumptionService $consumption,
        private SettingsService $settings,
        private ?ConversionFactorService $factors = null,
    ) {}

    private function factors(): ConversionFactorService
    {
        return $this->factors ??= new ConversionFactorService($this->settings);
    }

    /**
     * Alle Zähler in Betrieb der aktiven Arten mit Zählerständen, nur die mit Befund.
     *
     * @return list<array{utility:string, meter:array<string,mixed>, result:array<string,mixed>}>
     */
    public function all(?string $today = null): array
    {
        $out = [];
        foreach ($this->activeUtilities() as $u) {
            if (!Utilities::isCumulative($u)) continue;
            foreach ($this->meters->list($u) as $m) {
                if (!MeterService::inService($m) || ($m['capture'] ?? 'counter') === 'period') continue;
                try {
                    $r = $this->forMeter($u, $m, $today);
                } catch (\Throwable) {
                    continue;   // ein Zähler mit kaputten Daten hält „Zu tun“ nicht an
                }
                if ($r['checks'] !== [] || $r['duplicates'] !== [] || $r['level_shift'] !== null) {
                    $out[] = ['utility' => $u, 'meter' => $m, 'result' => $r];
                }
            }
        }
        return $out;
    }

    /**
     * Befund eines Zählers.
     *
     * @return array{checks:list<array<string,mixed>>, duplicates:list<array<string,mixed>>, level_shift:?array<string,mixed>, basis:?string, unit:string, billing_only:list<string>}
     */
    public function forMeter(string $utility, array $meter, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $unit = (string)(Utilities::exists($utility) ? (Utilities::get($utility)['unit'] ?? '') : '');
        $out = ['checks' => [], 'duplicates' => [], 'level_shift' => null, 'basis' => null, 'unit' => $unit, 'billing_only' => []];
        if (!Utilities::exists($utility) || !Utilities::isCumulative($utility) || !empty($meter['is_group'])
            || ($meter['capture'] ?? 'counter') === 'period' || empty($meter['id'])) {
            return $out;
        }
        $key = $utility . '|' . md5(serialize($meter)) . '|' . $this->store->generation() . '|' . $today;
        if (isset($this->memo[$key])) return $this->memo[$key];

        $all = $this->readings->list($utility, (string)$meter['id']);
        $out['duplicates'] = $this->duplicates($utility, $meter, $all, $today);
        foreach ($all as $r) if (!empty($r['is_billing_only'])) $out['billing_only'][] = (string)($r['id'] ?? '');

        $points = $this->points($utility, $meter, $all, $today);
        $iv = $this->intervals($utility, $meter, $points);
        $ctx = $this->context($utility, $meter, $points, $iv);
        if ($ctx !== null) {
            $out['basis'] = $ctx['method'];
            $out['level_shift'] = $this->levelShift($utility, $meter, $points, $iv, $ctx);
            if ($out['level_shift'] !== null) {
                // Nur die Zeit seit dem Wechsel gilt als heute: Davor ist ein anderer
                // Verbrauch, kein Maßstab für einzelne Stände (und wird nicht geprüft)
                $ctx = $this->withMinFrom($ctx, $iv, (string)$out['level_shift']['date']);
            }
            if ($ctx !== null) $out['checks'] = $this->offsets($utility, $meter, $points, $iv, $ctx);
        }
        return $this->memo[$key] = $out;
    }

    // ── Reihe ────────────────────────────────────────────────────────────

    /**
     * Ablesungen, aus denen die Abstände der Prüfung entstehen (nach Datum).
     *
     * @param list<array<string,mixed>> $all
     * @return list<array<string,mixed>>
     */
    private function points(string $utility, array $meter, array $all, string $today): array
    {
        $skip = [];
        foreach ($this->consumption->readingWarnings($utility, $meter) as $w) {
            if (in_array($w['type'] ?? '', ['suspect', 'outlier'], true) && !empty($w['reading_id'])) $skip[(string)$w['reading_id']] = true;
        }
        $out = [];
        $last = null;
        foreach ($all as $r) {
            $d = $r['date'] ?? null;
            if (!Dates::isIsoDate($d) || $d > $today || !empty($r['is_future']) || !empty($r['is_suspect'])) continue;
            if (!empty($r['is_billing_only']) || isset($skip[(string)($r['id'] ?? '')])) continue;
            if ($d === $last) continue;   // am selben Tag: der erste (doppelte stehen in `duplicates`)
            $out[] = $r;
            $last = $d;
        }
        return $out;
    }

    /**
     * Abstände zwischen aufeinanderfolgenden Punkten. Index j: von Punkt j−1 bis j.
     *
     * @param list<array<string,mixed>> $points
     * @return array<int,array<string,mixed>>
     */
    private function intervals(string $utility, array $meter, array $points): array
    {
        $u = Utilities::get($utility);
        $toKwh = !empty($u['unit_to_kwh']);
        $devicesById = [];
        foreach ($meter['devices'] ?? [] as $d) $devicesById[(string)($d['id'] ?? '')] = $d;
        $dev = fn(array $r): ?string => isset($r['device_id']) ? (string)$r['device_id'] : ($this->meters->deviceOnDate($meter, (string)$r['date'])['id'] ?? null);
        $baseline = MeterService::activeBaselineDate($meter);
        $out = [];
        for ($j = 1; $j < count($points); $j++) {
            $a = $points[$j - 1]; $b = $points[$j];
            $from = (string)$a['date']; $to = (string)$b['date'];
            $days = self::days($from, $to);
            $raw = (float)$b['counter'] - (float)$a['counter'];
            $devA = $dev($a);
            if ($raw < 0) $raw = ConsumptionService::rolloverAmount((float)$a['counter'], (float)$b['counter'], $devicesById[(string)$devA] ?? null) ?? $raw;
            $valid = $days > 0 && $devA !== null && $devA === $dev($b) && $raw >= 0;
            $kwh = $valid ? ($toKwh ? $this->rawToKwh($utility, $from, $to, $raw) : $raw) : 0.0;
            $out[$j] = [
                'j' => $j, 'from' => $from, 'to' => $to, 'days' => $days, 'raw' => $raw, 'kwh' => $kwh,
                'valid' => $valid, 'pre' => $baseline !== null && $from < $baseline,
            ];
        }
        return $out;
    }

    /** Menge eines Abstands in der Verbrauchseinheit, tagesgenau an Faktor-Stichtagen geteilt. */
    private function rawToKwh(string $utility, string $from, string $to, float $raw): float
    {
        $days = self::days($from, $to);
        if ($days <= 0) return 0.0;
        $cuts = $utility === 'gas' ? $this->factors()->boundariesBetween($from, $to) : [];
        $bounds = array_merge([$from], $cuts, [$to]);
        $kwh = 0.0;
        for ($i = 1; $i < count($bounds); $i++) {
            $kwh += $raw / $days * self::days($bounds[$i - 1], $bounds[$i]) * $this->factors()->factorOn($utility, $bounds[$i - 1]);
        }
        return $kwh;
    }

    /** Faktor Verbrauchseinheit je Zählereinheit eines Abstands (für die Anzeige in m³). */
    private function unitFactor(string $utility, array $x): float
    {
        if (empty(Utilities::get($utility)['unit_to_kwh'])) return 1.0;
        if ((float)$x['raw'] > 0 && (float)$x['kwh'] > 0) return (float)$x['kwh'] / (float)$x['raw'];
        $f = $this->factors()->factorOn($utility, (string)$x['from']);
        return $f > 0 ? $f : 1.0;
    }

    // ── Erwartung ────────────────────────────────────────────────────────

    /**
     * Methode, Heizgrenze, Gradtage je Abstand, typischer Monat, Modell. null: keine
     * Prüfung möglich (zu wenig Daten, keine Erwartung).
     *
     * @param array<int,array<string,mixed>> $iv (wird um `hdd`, `temp_ok` ergänzt)
     * @return array<string,mixed>|null
     */
    private function context(string $utility, array $meter, array $points, array &$iv): ?array
    {
        $usable = array_filter($iv, fn($x) => $x['valid'] && !$x['pre']);
        if (count($usable) < 3) return null;
        // Ladepunkt: Geladen wird, wenn gefahren wurde. Dafür gibt es keine Erwartung.
        if (Utilities::roleOf($utility, $meter) === 'ev_charger') return null;
        $typical = $this->typicalMonth($usable);
        if ($typical <= 0) return null;
        $heat = Utilities::isHgtRelevant($utility)
            || ($utility === 'strom' && Utilities::roleOf('strom', $meter) === 'heat_pump');
        $ctx = ['typical' => $typical, 'min' => self::MIN_SHARE * $typical, 'heat' => $heat, 'min_from' => null,
                'base' => null, 'model' => null, 'chunks' => [], 'method' => null];
        if ($heat) {
            $base = $this->baseFor($utility, $meter);
            $ctx['base'] = $base;
            $this->addDegreeDays($iv, $base);
            $ctx['chunks'] = $this->chunks($iv);
            $ctx['model'] = $this->fit($ctx['chunks']);
            if ($ctx['model'] !== null) { $ctx['method'] = 'heat_model'; return $ctx; }
        }
        // Sonne, Garten, Heizen ohne Modell: Die mittlere Tagesmenge hieße im Winter „zu
        // wenig“ und im Sommer „zu viel“. Dieselben Tage anderer Jahre statt dessen.
        if ($heat || Utilities::isFeedIn($utility) || Utilities::isGenerationOnly($utility)
            || Utilities::roleOf($utility, $meter) === 'garden' || self::seasonal($usable)) {
            $ctx['method'] = 'other_years';
            return $ctx;
        }
        $ctx['method'] = 'mean_rate';
        return $ctx;
    }

    /**
     * Ausgeprägte Jahreszeiten: Die mittlere Tagesrate des schwächsten Kalendermonats
     * liegt unter der Hälfte des stärksten (Gartenwasser, Strom mit Wärmepumpe ohne
     * Rolle). Haushaltsstrom und Trinkwasser schwanken um ein Drittel und bleiben bei
     * der mittleren Tagesmenge. Mit weniger als sechs belegten Monaten: nein.
     */
    private static function seasonal(array $usable): bool
    {
        $sum = array_fill(1, 12, 0.0); $cnt = array_fill(1, 12, 0);
        foreach ($usable as $x) {
            if ($x['days'] <= 0) continue;
            $rate = $x['kwh'] / $x['days'];
            for ($t = strtotime($x['from'] . ' 12:00:00'), $e = strtotime($x['to'] . ' 00:00:00'); $t < $e; $t += 86400) {
                $mo = (int)date('n', $t);
                $sum[$mo] += $rate; $cnt[$mo]++;
            }
        }
        $rates = [];
        foreach ($sum as $mo => $s) if ($cnt[$mo] >= 20) $rates[] = $s / $cnt[$mo];
        if (count($rates) < 6) return false;
        $max = max($rates);
        return $max > 0 && min($rates) < $max / 2;
    }

    /** Nur noch Abstände ab `$date` (nach einem erkannten Wechsel des Niveaus). */
    private function withMinFrom(array $ctx, array $iv, string $date): ?array
    {
        $ctx['min_from'] = $date;
        if ($ctx['method'] === 'heat_model') {
            $ctx['model'] = $this->fit(array_filter($ctx['chunks'], fn($c) => $c['from'] >= $date));
            if ($ctx['model'] === null) return null;
        }
        return $ctx;
    }

    /** Heizgrenze: die eigene des Zählers, sonst die seiner Gruppe, sonst die Einstellung. */
    private function baseFor(string $utility, array $meter): float
    {
        if (is_numeric($meter['hdd_base_temp'] ?? null)) return (float)$meter['hdd_base_temp'];
        $gid = (string)($meter['meter_group_id'] ?? '');
        if ($gid !== '') {
            $g = $this->meters->getGroup($utility, $gid);
            if (is_numeric($g['hdd_base_temp'] ?? null)) return (float)$g['hdd_base_temp'];
        }
        return $this->consumption->hddBaseFor($meter);
    }

    /**
     * Gradtage je Abstand (`hdd`); Tage ohne Temperatur mit den Gradtagen des Normals
     * für ihren Monat. `temp_ok`: höchstens ein Zehntel der Tage ohne beides.
     */
    private function addDegreeDays(array &$iv, float $base): void
    {
        $temps = $this->store->read('temperatures.json', []);
        if (!is_array($temps)) $temps = [];
        $normals = $this->consumption->hddNormals($base);
        foreach ($iv as &$x) {
            $hdd = 0.0; $miss = 0; $n = 0;
            if ($x['valid']) {
                for ($t = strtotime($x['from'] . ' 12:00:00'), $e = strtotime($x['to'] . ' 00:00:00'); $t < $e; $t += 86400) {
                    $n++;
                    $d = date('Y-m-d', $t);
                    if (isset($temps[$d]['avg'])) { $hdd += max(0.0, $base - (float)$temps[$d]['avg']); continue; }
                    $norm = $normals[(int)date('n', $t)]['mean'] ?? null;
                    if ($norm === null) { $miss++; continue; }
                    $hdd += (float)$norm / (int)date('t', $t);
                }
            }
            $x['hdd'] = $hdd;
            $x['temp_ok'] = $x['valid'] && $n > 0 && $miss <= $n / 10;
        }
        unset($x);
    }

    /**
     * Stücke für den Fit: aufeinanderfolgende nutzbare Abstände, zusammengefasst, bis
     * sie `min_days_period` Tage erreichen (bei täglichen Ständen etwa Monate). Ein
     * Abstand, der nicht rechnet, beendet ein Stück; ein Rest unter der Mindestlänge
     * entfällt.
     *
     * @return list<array{hdd:float,days:int,kwh:float,from:string,to:string}>
     */
    private function chunks(array $iv): array
    {
        $min = max(1, (int)$this->settings->get('min_days_period', 20));
        $out = []; $cur = null;
        foreach ($iv as $x) {
            if (!$x['valid'] || $x['pre'] || empty($x['temp_ok'])) { $cur = null; continue; }
            $cur ??= ['hdd' => 0.0, 'days' => 0, 'kwh' => 0.0, 'from' => $x['from'], 'to' => $x['to']];
            $cur['hdd'] += $x['hdd']; $cur['days'] += $x['days']; $cur['kwh'] += $x['kwh']; $cur['to'] = $x['to'];
            if ($cur['days'] >= $min) { $out[] = $cur; $cur = null; }
        }
        return $out;
    }

    /**
     * Heizmodell über die Stücke (ConsumptionService::fitHeatPoints, Gewicht wie
     * heatIntervalPoints). null unter MIN_POINTS_REGRESSION Stücken oder ohne Heizsignal.
     *
     * @return array{a:float,c:float,resid_sd:float}|null
     */
    private function fit(array $chunks): ?array
    {
        $pts = [];
        foreach ($chunks as $c) if ($c['kwh'] > 0) $pts[] = [(float)$c['hdd'], (float)$c['days'], (float)$c['kwh'], self::MONTH_DAYS / $c['days']];
        if (count($pts) < ConsumptionService::MIN_POINTS_REGRESSION) return null;
        $m = ConsumptionService::fitHeatPoints($pts);
        return $m['a'] > 0 || $m['c'] > 0 ? ['a' => (float)$m['a'], 'c' => (float)$m['c'], 'resid_sd' => (float)$m['resid_sd']] : null;
    }

    /** Typischer Monat: Verbrauch der letzten zwölf Monate der Reihe ÷ 12 (kürzer: Tagesmittel × 30,4). */
    private function typicalMonth(array $usable): float
    {
        $last = max(array_map(fn($x) => $x['to'], $usable));
        $from = date('Y-m-d', (int)strtotime("$last -365 days"));
        $first = min(array_map(fn($x) => $x['from'], $usable));
        $sum = 0.0; $days = 0;
        foreach ($usable as $x) {
            if ($first <= $from && $x['to'] <= $from) continue;
            $share = $first <= $from && $x['from'] < $from ? self::days($from, $x['to']) / max(1, $x['days']) : 1.0;
            $sum += $x['kwh'] * $share;
            $days += (int)round($x['days'] * $share);
        }
        return $days > 0 ? $sum / $days * self::MONTH_DAYS : 0.0;
    }

    /**
     * Gemessen, erwartet und Streuung eines Abstands; null ohne Erwartung.
     * `$skip`: Abstände, die die Erwartung nicht mitbestimmen (geprüfter Abstand, Block).
     *
     * @param array<int,true> $skip
     * @return array{m:float,e:float,sd:float}|null
     */
    private function expect(array $iv, int $j, array $ctx, array $skip, ?array $model = null): ?array
    {
        $x = $iv[$j] ?? null;
        if ($x === null || !$this->usable($x, $ctx)) return null;
        $e = null; $sd = null;
        switch ($ctx['method']) {
            case 'heat_model':
                $m = $model ?? $ctx['model'];
                if ($m === null || empty($x['temp_ok'])) return null;
                $e = $m['a'] * $x['hdd'] + $m['c'] * $x['days'];
                $sd = $m['resid_sd'] * sqrt($x['days'] / self::MONTH_DAYS);
                break;
            case 'mean_rate':
                $wFrom = date('Y-m-d', (int)strtotime($x['from'] . ' -' . self::LOCAL_WINDOW_DAYS . ' days'));
                $wTo = date('Y-m-d', (int)strtotime($x['to'] . ' +' . self::LOCAL_WINDOW_DAYS . ' days'));
                $sum = 0.0; $days = 0;
                foreach ($iv as $k => $y) {
                    if (isset($skip[$k]) || $k === $j || !$this->usable($y, $ctx) || $y['to'] <= $wFrom || $y['from'] >= $wTo) continue;
                    $ov = self::days(max($y['from'], $wFrom), min($y['to'], $wTo));
                    $sum += $y['kwh'] * $ov / max(1, $y['days']);
                    $days += $ov;
                }
                if ($days < 60) return null;
                $e = $sum / $days * $x['days'];
                break;
            case 'other_years':
                [$e, $sd] = $this->otherYears($iv, $x, $ctx, $skip + [$j => true]) ?? [null, null];
                break;
        }
        if ($e === null || $e <= 0) return null;
        return ['m' => (float)$x['kwh'], 'e' => $e, 'sd' => (float)$sd];
    }

    private function usable(array $x, array $ctx): bool
    {
        return $x['valid'] && !$x['pre'] && ($ctx['min_from'] === null || $x['from'] >= $ctx['min_from']);
    }

    /**
     * Dieselben Tage in anderen Jahren (bis drei davor und danach): je Jahr die Summe der
     * Tagesraten der Abstände, die diese Tage abdecken (mindestens neun Zehntel), Median.
     * Dazu die Streuung zwischen den Jahren (YEAR_SPREAD_MIN als Untergrenze).
     *
     * @return array{0:float,1:float}|null [Erwartung, Streuung]
     */
    private function otherYears(array $iv, array $x, array $ctx, array $skip): ?array
    {
        $list = array_values(array_filter($iv, fn($y) => !isset($skip[$y['j']]) && $this->usable($y, $ctx)));
        if ($list === []) return null;
        $sums = [];
        foreach ([-3, -2, -1, 1, 2, 3] as $k) {
            $sum = 0.0; $cov = 0; $n = 0; $pos = 0;
            for ($t = strtotime($x['from'] . ' 12:00:00'), $e = strtotime($x['to'] . ' 00:00:00'); $t < $e; $t += 86400) {
                $n++;
                $md = date('m-d', $t);
                $y = (int)date('Y', $t) + $k;
                $d = ($md === '02-29' && !checkdate(2, 29, $y)) ? sprintf('%04d-02-28', $y) : sprintf('%04d-%s', $y, $md);
                // Abstände sind aufsteigend: die Position wandert mit
                while ($pos < count($list) && $list[$pos]['to'] <= $d) $pos++;
                if ($pos < count($list) && $list[$pos]['from'] <= $d) {
                    $sum += $list[$pos]['kwh'] / max(1, $list[$pos]['days']);
                    $cov++;
                }
            }
            if ($n > 0 && $cov >= 0.9 * $n) $sums[] = $sum * $n / $cov;
        }
        if ($sums === []) return null;
        sort($sums);
        $c = count($sums);
        $med = $c % 2 ? $sums[intdiv($c, 2)] : ($sums[$c / 2 - 1] + $sums[$c / 2]) / 2;
        $sd = $c > 1 ? sqrt(array_sum(array_map(fn($v) => ($v - $med) ** 2, $sums)) / ($c - 1)) : 0.0;
        return [$med, max($sd, self::YEAR_SPREAD_MIN * $med)];
    }

    /**
     * Weicht ein Abstand deutlich in Richtung `$sign` ab (+1 darüber, −1 darunter)?
     * Alle drei Schwellen: Verhältnis (RATIO), Menge (`$min`) und Streuung (SIGMA × `sd`).
     *
     * @param array{m:float,e:float,sd:float}|null $x gemessen, erwartet, Streuung
     */
    public static function significant(?array $x, int $sign, float $min): bool
    {
        return self::sig($x, $sign, $min);
    }

    private static function sig(?array $x, int $sign, float $min): bool
    {
        if ($x === null || $x['e'] <= 0) return false;
        $d = $x['m'] - $x['e'];
        if ($sign * $d <= 0 || abs($d) < $min || abs($d) < self::SIGMA * $x['sd']) return false;
        return $sign < 0 ? $x['m'] * self::RATIO <= $x['e'] : $x['m'] >= $x['e'] * self::RATIO;
    }

    private static function normal(?array $x, float $min): bool
    {
        return !self::sig($x, 1, $min) && !self::sig($x, -1, $min);
    }

    // ── Kandidaten ───────────────────────────────────────────────────────

    /**
     * Stände (Blöcke) mit gemeinsamem Versatz.
     *
     * @return list<array<string,mixed>>
     */
    private function offsets(string $utility, array $meter, array $points, array $iv, array $ctx): array
    {
        $min = (float)$ctx['min'];
        $scr = [];
        foreach ($iv as $j => $x) $scr[$j] = $this->expect($iv, $j, $ctx, []);
        $cands = [];
        foreach ($scr as $p => $xp) {
            foreach ([-1, 1] as $s) {
                if (!self::sig($xp, $s, $min)) continue;
                for ($q = $p + 1; $q <= $p + self::MAX_BLOCK && isset($iv[$q]); $q++) {
                    if ($scr[$q] === null) break;
                    if (self::days((string)$points[$p]['date'], (string)$points[$q - 1]['date']) > self::MAX_BLOCK_DAYS) break;
                    if (self::sig($scr[$q], -$s, $min)) { $cands[] = [$p, $q, $s]; break; }
                    if (!self::normal($scr[$q], $min)) break;
                }
            }
        }
        $found = [];
        foreach ($cands as [$p, $q, $s]) {
            $c = $this->confirm($utility, $meter, $points, $iv, $ctx, $scr, $p, $q, $s);
            if ($c !== null) $found[] = $c;
        }
        // stärkster Versatz zuerst, Blöcke überschneiden sich nicht
        usort($found, fn($a, $b) => $b['_score'] <=> $a['_score']);
        $taken = []; $out = [];
        foreach ($found as $c) {
            $range = range($c['_p'], $c['_q']);
            if (array_intersect($range, $taken) !== []) continue;
            $taken = array_merge($taken, $range);
            unset($c['_score'], $c['_p'], $c['_q']);
            $out[] = $c;
        }
        usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
        return $out;
    }

    /**
     * Einen Kandidaten bestätigen: kein bestätigter Stand im Block, Nachbarn nicht in
     * dieselbe Richtung, und mit Erwartungen ohne die geprüften Abstände weiter deutlich,
     * der Block normal und die Summe passend.
     *
     * @return array<string,mixed>|null
     */
    private function confirm(string $utility, array $meter, array $points, array $iv, array $ctx, array $scr, int $p, int $q, int $s): ?array
    {
        $min = (float)$ctx['min'];
        for ($k = $p; $k < $q; $k++) if (!empty($points[$k]['is_verified'])) return null;
        if (self::sig($scr[$p - 1] ?? null, $s, $min) || self::sig($scr[$q + 1] ?? null, -$s, $min)) return null;
        $skip = array_fill_keys(range($p, $q), true);
        $model = null;
        if ($ctx['method'] === 'heat_model') {
            $from = (string)$iv[$p]['from']; $to = (string)$iv[$q]['to'];
            $model = $this->fit(array_filter($ctx['chunks'], fn($c) => ($ctx['min_from'] === null || $c['from'] >= $ctx['min_from'])
                && ($c['to'] <= $from || $c['from'] >= $to))) ?? $ctx['model'];
        }
        $xp = $this->expect($iv, $p, $ctx, $skip, $model);
        $xq = $this->expect($iv, $q, $ctx, $skip, $model);
        if (!self::sig($xp, $s, $min) || !self::sig($xq, -$s, $min)) return null;
        for ($k = $p + 1; $k < $q; $k++) if (!self::normal($this->expect($iv, $k, $ctx, $skip, $model), $min)) return null;
        $dp = $xp['m'] - $xp['e'];
        $dq = $xq['m'] - $xq['e'];
        $delta = ($dq - $dp) / 2;   // > 0: die Stände im Block sind zu niedrig
        if (abs($dp + $dq) > self::SUM_TOLERANCE * abs($delta)) return null;

        $f = $this->unitFactor($utility, $iv[$p]);
        $side = function (array $x, array $e) use ($utility): array {
            $uf = $this->unitFactor($utility, $x);
            return ['from' => $x['from'], 'to' => $x['to'], 'days' => $x['days'],
                    'measured' => round($x['raw'], 1), 'expected' => round($e['e'] / $uf, 1),
                    'ratio' => round($e['m'] / $e['e'], 2)];
        };
        $block = array_slice($points, $p, $q - $p);
        $offsetRaw = $delta / $f;
        $first = (string)$block[0]['date'];
        return [
            'uid'         => AgendaService::uid('reading_check', $utility, (string)$meter['id'], $first),
            'kind'        => 'offset',
            'date'        => $first,
            'until'       => (string)$block[count($block) - 1]['date'],
            'count'       => count($block),
            'reading_ids' => array_map(fn($r) => (string)($r['id'] ?? ''), $block),
            'direction'   => $delta > 0 ? 'low' : 'high',
            'offset'      => round(abs($offsetRaw), 1),
            'unit'        => (string)(Utilities::get($utility)['unit'] ?? ''),
            'basis'       => $ctx['method'],
            'before'      => $side($iv[$p], $xp),
            'after'       => $side($iv[$q], $xq),
            'suggested'   => array_map(fn($r) => ['id' => (string)($r['id'] ?? ''), 'date' => (string)$r['date'],
                'counter' => (float)$r['counter'], 'suggested' => round((float)$r['counter'] + $offsetRaw, 1)], $block),
            '_score' => abs($delta) / $ctx['typical'], '_p' => $p, '_q' => $q,
        ];
    }

    // ── Zäsur ────────────────────────────────────────────────────────────

    /**
     * Gleichbleibend anderes Niveau vor einem Wechsel (s. LEVEL_*). Gesucht wird am
     * ersten Stand jedes Monats; das heutige Niveau kommt aus der Zeit danach
     * (Heizmodell bzw. mittlere Tagesmenge). Nicht bei PV (Jahreszeiten ohne Modell).
     *
     * @return array<string,mixed>|null
     */
    private function levelShift(string $utility, array $meter, array $points, array $iv, array $ctx): ?array
    {
        if (!in_array($ctx['method'], ['heat_model', 'mean_rate'], true)) return null;
        $usable = array_filter($iv, fn($x) => $this->usable($x, $ctx));
        if ($usable === []) return null;
        $first = min(array_map(fn($x) => $x['from'], $usable));
        $last = max(array_map(fn($x) => $x['to'], $usable));
        $min = (float)$ctx['min'];
        $best = null;
        for ($s = 1; $s < count($points) - 1; $s++) {
            $date = (string)$points[$s]['date'];
            if (substr($date, 0, 7) === substr((string)$points[$s - 1]['date'], 0, 7)) continue;
            if (self::days($first, $date) < self::LEVEL_MIN_DAYS || self::days($date, $last) < self::LEVEL_AFTER_DAYS) continue;
            if ($ctx['method'] === 'heat_model') {
                $model = $this->fit(array_filter($ctx['chunks'], fn($c) => $c['from'] >= $date));
                if ($model === null) continue;
                $exp = fn(array $x): ?float => empty($x['temp_ok']) ? null : $model['a'] * $x['hdd'] + $model['c'] * $x['days'];
            } else {
                $sum = 0.0; $days = 0;
                foreach ($usable as $x) if ($x['from'] >= $date) { $sum += $x['kwh']; $days += $x['days']; }
                if ($days < self::LEVEL_AFTER_DAYS) continue;
                $rate = $sum / $days;
                $exp = fn(array $x): ?float => $rate * $x['days'];
            }
            $dir = 0; $sm = 0.0; $se = 0.0; $runFrom = null;
            for ($j = $s; $j >= 1; $j--) {
                $x = $iv[$j];
                if (!$this->usable($x, $ctx)) break;
                $e = $exp($x);
                if ($e === null || $e <= 0) break;
                $m = (float)$x['kwh'];
                if (max($e, $m) >= $min) {
                    $r = $m / $e;
                    $d = $r >= self::LEVEL_STEP ? 1 : ($r <= 1 / self::LEVEL_STEP ? -1 : 0);
                    if ($d === 0 || ($dir !== 0 && $d !== $dir)) break;
                    $dir = $d;
                }
                $sm += $m; $se += $e; $runFrom = $x['from'];
            }
            if ($dir === 0 || $runFrom === null || $se <= 0) continue;
            $span = self::days($runFrom, $date);
            $ratio = $sm / $se;
            if ($span < self::LEVEL_MIN_DAYS || ($dir > 0 ? $ratio < self::LEVEL_RATIO : $ratio > 1 / self::LEVEL_RATIO)) continue;
            // Danach keine Gegenbewegung: Der Wechsel liegt hier (der erste Abstand danach
            // schon auf heutigem Niveau), und höchstens LEVEL_AFTER_BACK der Tage danach
            // kehren zum alten Niveau zurück
            $back = 0; $after = 0; $firstBack = null;
            foreach ($usable as $k => $x) {
                if ($x['from'] < $date) continue;
                $e = $exp($x);
                $after += $x['days'];
                $old = $e !== null && $e > 0 && max($e, $x['kwh']) >= $min
                    && ($dir > 0 ? $x['kwh'] >= $e * self::LEVEL_STEP : $x['kwh'] * self::LEVEL_STEP <= $e);
                if ($k === $s + 1) $firstBack = $old;
                if ($old) $back += $x['days'];
            }
            if ($firstBack === true || $back > self::LEVEL_AFTER_BACK * $after) continue;
            $score = abs(log($ratio));
            if ($best === null || $score > $best['_score'] + 1e-9) {
                $best = ['date' => $date, 'from' => $runFrom, 'dir' => $dir, 'ratio' => $ratio, 'days' => $span, '_score' => $score];
            }
        }
        if ($best === null) return null;
        foreach ((array)($meter['baseline_events'] ?? []) as $ev) {
            $d = (string)($ev['date'] ?? '');
            if (Dates::isIsoDate($d) && abs(self::days($d, $best['date'])) <= self::LEVEL_NEAR_DAYS) return null;
        }
        return [
            'uid'       => AgendaService::uid('level_shift', $utility, (string)$meter['id'], $best['date']),
            'date'      => $best['date'],
            'from'      => $best['from'],
            'direction' => $best['dir'] > 0 ? 'higher' : 'lower',
            'factor'    => round($best['ratio'], 2),
            'days'      => $best['days'],
            'months'    => (int)round($best['days'] / self::MONTH_DAYS),
            'basis'     => $ctx['method'],
        ];
    }

    // ── doppelt eingetragen ──────────────────────────────────────────────

    /**
     * Derselbe Tag mit demselben Stand mehr als einmal. Behalten wird der Eintrag mit
     * Foto, sonst der mit Notiz, sonst der erste.
     *
     * @return list<array<string,mixed>>
     */
    private function duplicates(string $utility, array $meter, array $all, string $today): array
    {
        $byDate = [];
        foreach ($all as $r) {
            $d = $r['date'] ?? null;
            if (!Dates::isIsoDate($d) || $d > $today || !empty($r['is_future']) || !empty($r['is_suspect']) || empty($r['id'])) continue;
            $byDate[$d][sprintf('%.6f', (float)($r['counter'] ?? 0))][] = $r;
        }
        $out = [];
        foreach ($byDate as $d => $groups) {
            foreach ($groups as $list) {
                if (count($list) < 2) continue;
                $keep = null;
                foreach ($list as $r) if (!empty($r['attachment_id'])) { $keep = $r; break; }
                if ($keep === null) foreach ($list as $r) if (trim((string)($r['note'] ?? '')) !== '') { $keep = $r; break; }
                $keep ??= $list[0];
                $out[] = [
                    'uid'        => AgendaService::uid('reading_duplicate', $utility, (string)$meter['id'], (string)$d),
                    'date'       => (string)$d,
                    'counter'    => (float)$keep['counter'],
                    'unit'       => (string)(Utilities::get($utility)['unit'] ?? ''),
                    'keep_id'    => (string)$keep['id'],
                    'delete_ids' => array_values(array_map(fn($r) => (string)$r['id'], array_filter($list, fn($r) => $r['id'] !== $keep['id']))),
                ];
                break;   // je Tag ein Eintrag
            }
        }
        return $out;
    }

    // ── Hilfen ───────────────────────────────────────────────────────────

    private static function days(string $a, string $b): int
    {
        return (int)round((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400);
    }

    /** @return list<string> */
    private function activeUtilities(): array
    {
        $a = $this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
        if (!is_array($a) || empty($a)) return Utilities::keys();
        return array_values(array_filter($a, fn($k) => is_string($k) && Utilities::exists($k)));
    }
}
