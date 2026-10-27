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
 *               `min_days_period` Tagen zusammengefasst, Gewicht wie heatIntervalPoints).
 *               Strom und Wasser: die mittlere Tagesmenge der Abstände bis ein halbes
 *               Jahr davor und danach. PV, Garten, ausgeprägte oder jedes Jahr
 *               wiederkehrende Jahreszeiten (SEASON_RATIO) und Heizarten ohne Modell:
 *               dieselben Tage in anderen Jahren (Median). Ladepunkte nicht (geladen
 *               wird, wenn gefahren wurde). Ohne Erwartung keine Prüfung.
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
 *   2. Stufe    sehr starke Gegenbewegung (STRONG_RATIO: davor höchstens die Hälfte,
 *               danach mindestens das Doppelte oder umgekehrt) ohne passende Summe, im
 *               Block weiter in dieselbe Richtung erlaubt: `kind` mismatch, ohne Vorschlag.
 *
 * Dazu doppelt eingetragene Stände (derselbe Tag, derselbe Stand) und der Hinweis auf
 * die Analyse-Zäsur: Lag der Verbrauch mindestens ein Jahr am Stück gleichbleibend weit
 * über bzw. unter dem heutigen Niveau, ohne Gegenbewegung, ist das ein anderer
 * Verbrauch (etwa früher anders geheizt), kein Ablesefehler.
 *
 * „Stand ist richtig“ (`is_verified` an der Ablesung) beendet die Frage für jeden
 * Block mit diesem Stand; „nur für die Abrechnung“ (`is_billing_only`) nimmt ihn aus
 * den Monatswerten (ConsumptionService::billingOnlyBridges) und aus dieser Prüfung.
 *
 * Laufzeit: Die Agenda fragt bei jedem Aufruf alle Zähler. Bei zehn Jahren täglicher
 * Stände (tools/large-household.mjs) heißt das je Zähler rund 3.650 Abstände; die
 * mittlere Tagesmenge kommt deshalb aus Präfixsummen, andere Jahre über eine binäre
 * Suche, der Hinweis auf die Zäsur aus den Stücken, und Abstände, die nach Menge gar
 * nicht deutlich sein können, rechnen keine Erwartung.
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
     * Wechsel, jedes nennenswerte Stück mindestens LEVEL_STEP über (bzw. unter) dem
     * heutigen Niveau (keine Gegenbewegung), zusammen mindestens LEVEL_RATIO. Das
     * heutige Niveau kommt aus der Zeit nach dem Wechsel (mindestens LEVEL_AFTER_DAYS).
     * Mit einer wirksamen Zäsur zählt nur die Zeit danach.
     */
    public const LEVEL_RATIO = 1.5;
    public const LEVEL_STEP = 1.25;
    public const LEVEL_MIN_DAYS = 365;
    public const LEVEL_AFTER_DAYS = 182;
    /**
     * Nach dem Wechsel darf höchstens ein Zehntel der Tage wieder auf dem alten Niveau
     * liegen (ein einzelner kalter Monat), und das erste Stück danach gar nicht: Sonst
     * ist es eine Gegenbewegung, kein neues Niveau.
     */
    public const LEVEL_AFTER_BACK = 0.1;

    /**
     * Heutiges Niveau bei Heizarten: Heizmodell über mindestens so viele Stücke nach dem
     * Wechsel (statt der acht der Monatsregression). Nach einem Wechsel liegen oft nur
     * anderthalb Jahre mit Abständen von zwei, drei Monaten vor; mit acht Stücken fiel der
     * Wechsel dann ganz aus, und der Übergangsabstand stand im heutigen Niveau.
     */
    public const LEVEL_AFTER_POINTS = 4;

    /**
     * Zweite Stufe: sehr starke Gegenbewegung. Liegt der Abstand davor höchstens bei der
     * Hälfte und der danach mindestens beim Doppelten der Erwartung (oder umgekehrt), je
     * über der Mindestmenge und den Streuungen, fragt die App auch dann, wenn kein
     * gemeinsamer Versatz beide erklärt, ohne einen Stand vorzuschlagen. Im Block dürfen
     * die Abstände dabei in dieselbe Richtung weitergehen (die Stände werden nach und nach
     * falscher). Fall aus der Nachprüfung: 0,23× davor, 0,78× und 0,69× im Block, 3,67×
     * danach, der Überschuss danach doppelt so groß wie der Mangel davor.
     */
    public const STRONG_RATIO = 2.0;

    /**
     * Wiederkehrende Jahreszeit bei Strom und Wasser: Liegt die Tagesrate eines
     * Sommerhalbjahrs (April bis September) in mindestens SEASON_YEARS Jahren gleichsinnig
     * mindestens SEASON_RATIO über (bzw. unter) der der Winter daneben, in mehr Jahren als
     * umgekehrt, kommt die Erwartung aus denselben Tagen anderer Jahre statt aus der
     * mittleren Tagesmenge. Gezählt werden nur Abstände, die überwiegend
     * (SEASON_HALF_SHARE) in einer Jahreshälfte liegen. Fall aus der Nachprüfung: Strom im
     * Sommer rund 1,4-mal so viel wie im Winter, drei Jahre in Folge; die mittlere Tagesmenge
     * hieß einen Winterabstand „zu wenig“ und den Sommer danach „zu viel“ und meldete vier
     * richtige Stände. Ein Jahresgang in der Tagesmenge (Sinus) half nicht: Strom neben PV
     * springt im Herbst und Frühjahr, statt sanft zu schwingen.
     */
    public const SEASON_RATIO = 1.25;
    public const SEASON_YEARS = 2;
    private const SEASON_HALF_SHARE = 2 / 3;
    /** Tage, ab denen ein Halbjahr mit seiner Rate zählt. */
    private const SEASON_HALF_DAYS = 60;

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
        $ctx = $this->context($utility, $meter, $iv);
        if ($ctx !== null) {
            $out['basis'] = $ctx['method'];
            $out['level_shift'] = $this->levelShift($utility, $meter, $points, $ctx);
            if ($out['level_shift'] !== null) {
                // Nur die Zeit seit dem Wechsel gilt als heute: Davor ist ein anderer
                // Verbrauch, kein Maßstab für einzelne Stände (und wird nicht geprüft)
                $ctx = $this->withMinFrom($ctx, $iv, (string)$out['level_shift']['date']);
            }
            if ($ctx !== null) $out['checks'] = $this->offsets($utility, $meter, $points, $iv, $ctx);
        }
        // Skeptiker H4: Eine Entscheidung „nur für die Abrechnung“ bringt keine neuen Befunde
        // hervor. Ohne die markierten Stände wird das Heizmodell genauer (die verzerrten
        // Abstände fallen heraus), und dann hießen Abweichungen „deutlich“, die es vorher nicht
        // waren, obwohl niemand an diesen Ständen etwas geändert hat. Gemeldet wird deshalb nur,
        // was es auch mit den markierten Ständen als gewöhnlichen Ständen gäbe.
        if ($out['checks'] !== [] && $out['billing_only'] !== []) {
            $known = $this->undecidedChecks($utility, $meter, $all, $today, $out['level_shift']['date'] ?? null);
            $out['checks'] = array_values(array_filter($out['checks'], fn($c) => isset($known[implode('|', $c['reading_ids'])])));
        }
        return $this->memo[$key] = $out;
    }

    /**
     * Befunde, als wären die Stände „nur für die Abrechnung“ gewöhnliche Stände (der Stand vor
     * der Entscheidung), als Menge der Schlüssel `reading_ids`.
     *
     * @param list<array<string,mixed>> $all
     * @return array<string,true>
     */
    private function undecidedChecks(string $utility, array $meter, array $all, string $today, ?string $minFrom): array
    {
        $points = $this->points($utility, $meter, $all, $today, true);
        $iv = $this->intervals($utility, $meter, $points);
        $ctx = $this->context($utility, $meter, $iv);
        if ($ctx !== null && $minFrom !== null) $ctx = $this->withMinFrom($ctx, $iv, $minFrom);
        if ($ctx === null) return [];
        $out = [];
        foreach ($this->offsets($utility, $meter, $points, $iv, $ctx) as $c) $out[implode('|', $c['reading_ids'])] = true;
        return $out;
    }

    // ── Reihe ────────────────────────────────────────────────────────────

    /**
     * Ablesungen, aus denen die Abstände der Prüfung entstehen (nach Datum).
     *
     * @param list<array<string,mixed>> $all
     * @return list<array<string,mixed>>
     */
    private function points(string $utility, array $meter, array $all, string $today, bool $withBillingOnly = false): array
    {
        // verdächtig und eingeklemmte Ausreißer: wie die Rechnung (ohne sie ganz zu rechnen)
        $skip = $this->consumption->skippedReadingIds($utility, $meter, $all);
        $out = [];
        $last = null;
        foreach ($all as $r) {
            $d = $r['date'] ?? null;
            if (!Dates::isIsoDate($d) || $d > $today || !empty($r['is_future']) || !empty($r['is_suspect'])) continue;
            if ((!$withBillingOnly && !empty($r['is_billing_only'])) || isset($skip[(string)($r['id'] ?? '')])) continue;
            if ($d === $last) continue;   // am selben Tag: der erste (doppelte stehen in `duplicates`)
            $out[] = $r;
            $last = $d;
        }
        return $out;
    }

    /**
     * Abstände zwischen aufeinanderfolgenden Punkten. Index j: von Punkt j−1 bis j.
     * `d0`, `d1`: Tagesnummern von Beginn und Ende (für Suche und Fenster).
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
        $n = count($points);
        for ($j = 1; $j < $n; $j++) {
            $a = $points[$j - 1]; $b = $points[$j];
            $from = (string)$a['date']; $to = (string)$b['date'];
            $d0 = self::dayNo($from); $d1 = self::dayNo($to);
            $days = $d1 - $d0;
            $raw = (float)$b['counter'] - (float)$a['counter'];
            $devA = $dev($a);
            if ($raw < 0) $raw = ConsumptionService::rolloverAmount((float)$a['counter'], (float)$b['counter'], $devicesById[(string)$devA] ?? null) ?? $raw;
            $valid = $days > 0 && $devA !== null && $devA === $dev($b) && $raw >= 0;
            $kwh = $valid ? ($toKwh ? $this->rawToKwh($utility, $from, $to, $raw) : $raw) : 0.0;
            $out[$j] = [
                'j' => $j, 'from' => $from, 'to' => $to, 'd0' => $d0, 'd1' => $d1, 'days' => $days, 'raw' => $raw, 'kwh' => $kwh,
                'valid' => $valid, 'pre' => $baseline !== null && $from < $baseline, 'hdd' => 0.0, 'temp_ok' => false,
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
        if ($cuts === []) return $raw * $this->factors()->factorOn($utility, $from);
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
     * Methode, Heizgrenze, Gradtage je Abstand, typischer Monat, Modell, Stücke und die
     * Hilfslisten der Methoden. null: keine Prüfung möglich.
     *
     * @param array<int,array<string,mixed>> $iv (wird um `hdd`, `temp_ok` ergänzt)
     * @return array<string,mixed>|null
     */
    private function context(string $utility, array $meter, array &$iv): ?array
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
                'base' => null, 'model' => null, 'chunks' => [], 'method' => null, 'level_method' => null];
        if ($heat) {
            $ctx['base'] = $this->baseFor($utility, $meter);
            $this->addDegreeDays($iv, $ctx['base']);
        }
        $ctx['chunks'] = $this->chunks($iv, $heat);
        if ($heat) {
            $ctx['model'] = $this->fit($ctx['chunks']);
            if ($ctx['model'] !== null) $ctx['method'] = 'heat_model';
        }
        // Sonne, Garten, Heizen ohne Modell: Die mittlere Tagesmenge hieße im Winter „zu
        // wenig“ und im Sommer „zu viel“. Dieselben Tage anderer Jahre statt dessen.
        $ctx['method'] ??= $heat || Utilities::isFeedIn($utility) || Utilities::isGenerationOnly($utility)
            || Utilities::roleOf($utility, $meter) === 'garden' || self::seasonal($usable) ? 'other_years' : 'mean_rate';
        // Eine mildere Jahreszeit, die jedes Jahr wiederkehrt: ebenfalls andere Jahre. Der
        // Hinweis auf die Zäsur rechnet weiter mit der mittleren Tagesmenge (über ein ganzes
        // Jahr gleicht sich die Jahreszeit aus, ein Jahr am Stück auf anderem Niveau nicht)
        if ($ctx['method'] === 'mean_rate' && self::recurringSeason($usable)) {
            $ctx['method'] = 'other_years';
            $ctx['level_method'] = 'mean_rate';
        }
        return $this->withLists($ctx, $iv);
    }

    /**
     * Wiederkehrende Jahreszeit (SEASON_RATIO, SEASON_YEARS): je Sommerhalbjahr die
     * Tagesrate gegen die der Winter davor und danach (Mittel der vorhandenen), aus den
     * Abständen, die überwiegend in einer Jahreshälfte liegen. Mindestens SEASON_YEARS Jahre
     * gleichsinnig jenseits SEASON_RATIO und mehr in diese Richtung als in die andere. Jahre
     * ohne Jahreszeit (etwa Leerstand vor dem Einzug) zählen nicht dagegen; ein falscher
     * Stand verschiebt höchstens ein Jahr.
     */
    private static function recurringSeason(array $usable): bool
    {
        $half = [];   // 'S2024' Sommer 2024, 'W2024' Winter ab Oktober 2024
        foreach ($usable as $x) {
            if ($x['days'] <= 0) continue;
            $summer = 0;
            for ($d = $x['d0']; $d < $x['d1'];) {
                $t = $d * 86400 + 43200;
                $mo = (int)gmdate('n', $t);
                $y = (int)gmdate('Y', $t);
                $next = min($x['d1'], max($d + 1, self::dayNo(sprintf('%04d-%02d-01', $mo === 12 ? $y + 1 : $y, $mo === 12 ? 1 : $mo + 1))));
                if ($mo >= 4 && $mo <= 9) $summer += $next - $d;
                $d = $next;
            }
            $mid = intdiv($x['d0'] + $x['d1'], 2) * 86400 + 43200;
            $y = (int)gmdate('Y', $mid);
            if ($summer >= self::SEASON_HALF_SHARE * $x['days']) $key = 'S' . $y;
            elseif ($x['days'] - $summer >= self::SEASON_HALF_SHARE * $x['days']) $key = 'W' . ((int)gmdate('n', $mid) >= 10 ? $y : $y - 1);
            else continue;
            $half[$key] ??= [0.0, 0];
            $half[$key][0] += (float)$x['kwh'];
            $half[$key][1] += (int)$x['days'];
        }
        $rate = fn(string $k): ?float => isset($half[$k]) && $half[$k][1] >= self::SEASON_HALF_DAYS && $half[$k][0] > 0 ? $half[$k][0] / $half[$k][1] : null;
        $ratios = [];
        foreach (array_keys($half) as $k) {
            if ($k[0] !== 'S' || ($s = $rate($k)) === null) continue;
            $y = (int)substr($k, 1);
            $w = array_values(array_filter([$rate('W' . ($y - 1)), $rate('W' . $y)], fn($v) => $v !== null));
            if ($w !== []) $ratios[] = $s / (array_sum($w) / count($w));
        }
        $up = count(array_filter($ratios, fn($r) => $r >= self::SEASON_RATIO));
        $down = count(array_filter($ratios, fn($r) => $r * self::SEASON_RATIO <= 1));
        return ($up >= self::SEASON_YEARS && $up > $down) || ($down >= self::SEASON_YEARS && $down > $up);
    }

    /**
     * Die nutzbaren Abstände in Reihenfolge (`list`, mit `pos` je j) und ihre
     * Präfixsummen (`pk`, `pd`) für die mittlere Tagesmenge; dazu die größte Tagesrate
     * eines Stücks (Vorfilter: was nach Menge nicht deutlich sein kann).
     */
    private function withLists(array $ctx, array $iv): array
    {
        $list = []; $pos = []; $pk = [0.0]; $pd = [0];
        foreach ($iv as $j => $x) {
            if (!$this->usable($x, $ctx)) continue;
            $pos[$j] = count($list);
            $list[] = $x;
            $pk[] = end($pk) + (float)$x['kwh'];
            $pd[] = end($pd) + (int)$x['days'];
        }
        $maxRate = 0.0;
        foreach ($ctx['chunks'] as $c) if ($c['days'] > 0) $maxRate = max($maxRate, $c['kwh'] / $c['days']);
        foreach ($list as $x) if ($x['days'] >= 7) $maxRate = max($maxRate, $x['kwh'] / $x['days']);
        return ['list' => $list, 'pos' => $pos, 'pk' => $pk, 'pd' => $pd, 'max_rate' => $maxRate] + $ctx;
    }

    /** Nur noch Abstände ab `$date` (nach einem erkannten Wechsel des Niveaus). */
    private function withMinFrom(array $ctx, array $iv, string $date): ?array
    {
        $ctx['min_from'] = $date;
        if ($ctx['method'] === 'heat_model') {
            $ctx['model'] = $this->fit(array_filter($ctx['chunks'], fn($c) => $c['from'] >= $date));
            if ($ctx['model'] === null) return null;
        }
        return $this->withLists($ctx, $iv);
    }

    /**
     * Ausgeprägte Jahreszeiten: Die Tagesrate des Sommerhalbjahrs (April bis September)
     * und die des Winterhalbjahrs (Oktober bis März) liegen um mehr als das Doppelte
     * auseinander (Gartenwasser, Strom mit Wärmepumpe ohne Rolle, Bezug neben PV). Je
     * Halbjahr der Median der Kalendermonate, damit ein falscher Stand (ein Monat zu
     * niedrig, der nächste zu hoch) nicht selbst als Jahreszeit gilt. Eine mildere
     * Jahreszeit (ab einem Viertel) zählt, wenn sie jedes Jahr wiederkehrt
     * (recurringSeason). Mit weniger als zwei belegten Monaten je Halbjahr: nein.
     */
    private static function seasonal(array $usable): bool
    {
        $sum = array_fill(1, 12, 0.0); $cnt = array_fill(1, 12, 0);
        foreach ($usable as $x) {
            if ($x['days'] <= 0) continue;
            $rate = $x['kwh'] / $x['days'];
            // je Kalendermonat die Tage dieses Abstands (ohne Schleife über jeden Tag)
            for ($d = $x['d0']; $d < $x['d1'];) {
                $t = $d * 86400 + 43200;
                $mo = (int)gmdate('n', $t);
                $y = (int)gmdate('Y', $t);
                // Monatserster danach rein rechnerisch (strtotime rechnet in der Zeitzone des Servers)
                $next = min($x['d1'], max($d + 1, self::dayNo(sprintf('%04d-%02d-01', $mo === 12 ? $y + 1 : $y, $mo === 12 ? 1 : $mo + 1))));
                $sum[$mo] += $rate * ($next - $d);
                $cnt[$mo] += $next - $d;
                $d = $next;
            }
        }
        $half = [[], []];   // [Sommer, Winter]
        foreach ($sum as $mo => $s) if ($cnt[$mo] >= 20) $half[$mo >= 4 && $mo <= 9 ? 0 : 1][] = $s / $cnt[$mo];
        if (count($half[0]) < 2 || count($half[1]) < 2) return false;
        $med = function (array $v): float {
            sort($v);
            $c = count($v);
            return $c % 2 ? $v[intdiv($c, 2)] : ($v[$c / 2 - 1] + $v[$c / 2]) / 2;
        };
        [$s, $w] = [$med($half[0]), $med($half[1])];
        return max($s, $w) > 0 && min($s, $w) < max($s, $w) / 2;
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
            if ($x['valid'] && !$x['pre']) {
                for ($d = $x['d0']; $d < $x['d1']; $d++) {
                    $n++;
                    $t = $d * 86400 + 43200;
                    $k = gmdate('Y-m-d', $t);
                    if (isset($temps[$k]['avg'])) { $hdd += max(0.0, $base - (float)$temps[$k]['avg']); continue; }
                    $norm = $normals[(int)gmdate('n', $t)]['mean'] ?? null;
                    if ($norm === null) { $miss++; continue; }
                    $hdd += (float)$norm / (int)gmdate('t', $t);
                }
            }
            $x['hdd'] = $hdd;
            $x['temp_ok'] = $n > 0 && $miss <= $n / 10;
        }
        unset($x);
    }

    /**
     * Stücke: aufeinanderfolgende nutzbare Abstände, zusammengefasst, bis sie
     * `min_days_period` Tage erreichen (bei täglichen Ständen etwa Monate). Ein Abstand,
     * der nicht rechnet (bei Heizarten auch: ohne Temperaturen), beendet ein Stück; ein
     * Rest unter der Mindestlänge entfällt. Grundlage des Fits und des Hinweises auf
     * die Zäsur.
     *
     * @return list<array{hdd:float,days:int,kwh:float,from:string,to:string}>
     */
    private function chunks(array $iv, bool $heat): array
    {
        $min = max(1, (int)$this->settings->get('min_days_period', 20));
        $out = []; $cur = null;
        foreach ($iv as $x) {
            if (!$x['valid'] || $x['pre'] || ($heat && empty($x['temp_ok']))) { $cur = null; continue; }
            $cur ??= ['hdd' => 0.0, 'days' => 0, 'kwh' => 0.0, 'from' => $x['from'], 'to' => $x['to']];
            $cur['hdd'] += $x['hdd']; $cur['days'] += $x['days']; $cur['kwh'] += $x['kwh']; $cur['to'] = $x['to'];
            if ($cur['days'] >= $min) { $out[] = $cur; $cur = null; }
        }
        return $out;
    }

    /**
     * Heizmodell über die Stücke (ConsumptionService::fitHeatPoints, Gewicht wie
     * heatIntervalPoints). null unter `$minPoints` Stücken (sonst MIN_POINTS_REGRESSION) oder ohne Signal.
     *
     * @return array{a:float,c:float,resid_sd:float}|null
     */
    private function fit(array $chunks, int $minPoints = ConsumptionService::MIN_POINTS_REGRESSION): ?array
    {
        $pts = [];
        foreach ($chunks as $c) if ($c['kwh'] > 0) $pts[] = [(float)$c['hdd'], (float)$c['days'], (float)$c['kwh'], self::MONTH_DAYS / $c['days']];
        if (count($pts) < $minPoints) return null;
        $m = ConsumptionService::fitHeatPoints($pts);
        return $m['a'] > 0 || $m['c'] > 0 ? ['a' => (float)$m['a'], 'c' => (float)$m['c'], 'resid_sd' => (float)$m['resid_sd']] : null;
    }

    /** Typischer Monat: Verbrauch der letzten zwölf Monate der Reihe ÷ 12 (kürzer: Tagesmittel × 30,4). */
    private function typicalMonth(array $usable): float
    {
        $last = max(array_map(fn($x) => $x['d1'], $usable));
        $from = $last - 365;
        $first = min(array_map(fn($x) => $x['d0'], $usable));
        $sum = 0.0; $days = 0;
        foreach ($usable as $x) {
            if ($first <= $from && $x['d1'] <= $from) continue;
            $share = $first <= $from && $x['d0'] < $from ? ($x['d1'] - $from) / max(1, $x['days']) : 1.0;
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
        $e = null; $sd = 0.0;
        switch ($ctx['method']) {
            case 'heat_model':
                $m = $model ?? $ctx['model'];
                if ($m === null || empty($x['temp_ok'])) return null;
                $e = $m['a'] * $x['hdd'] + $m['c'] * $x['days'];
                $sd = $m['resid_sd'] * sqrt($x['days'] / self::MONTH_DAYS);
                break;
            case 'mean_rate':
                $e = $this->meanRate($x, $ctx, $skip + [$j => true]);
                if ($e !== null) $e *= $x['days'];
                break;
            case 'other_years':
                [$e, $sd] = $this->otherYears($x, $ctx, $skip + [$j => true]) ?? [null, 0.0];
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
     * Mittlere Tagesmenge der nutzbaren Abstände im Fenster LOCAL_WINDOW_DAYS vor und
     * nach dem Abstand, ohne `$skip` (Präfixsummen, Ränder anteilig). null unter 60 Tagen.
     */
    private function meanRate(array $x, array $ctx, array $skip): ?float
    {
        $list = $ctx['list'];
        $n = count($list);
        if ($n === 0) return null;
        $w0 = $x['d0'] - self::LOCAL_WINDOW_DAYS; $w1 = $x['d1'] + self::LOCAL_WINDOW_DAYS;
        $a = self::firstEndingAfter($list, $w0);
        $b = self::lastStartingBefore($list, $w1);
        if ($a >= $n || $b < $a) return null;
        $sum = $ctx['pk'][$b + 1] - $ctx['pk'][$a];
        $days = $ctx['pd'][$b + 1] - $ctx['pd'][$a];
        $cut = function (array $y, bool $all = false) use ($w0, $w1, &$sum, &$days): void {
            // vom vollen Beitrag auf den Teil im Fenster (oder ganz heraus)
            $ov = $all ? 0 : max(0, min($y['d1'], $w1) - max($y['d0'], $w0));
            $sum -= $y['kwh'] * ($y['days'] - $ov) / max(1, $y['days']);
            $days -= $y['days'] - $ov;
        };
        $cut($list[$a]);
        if ($b !== $a) $cut($list[$b]);
        foreach ($skip as $j => $_) {
            $k = $ctx['pos'][$j] ?? null;
            if ($k === null || $k < $a || $k > $b) continue;
            // schon am Rand gekürzt: den Rest im Fenster herausnehmen
            $y = $list[$k];
            $ov = max(0, min($y['d1'], $w1) - max($y['d0'], $w0));
            $sum -= $y['kwh'] * $ov / max(1, $y['days']);
            $days -= $ov;
        }
        return $days >= 60 ? $sum / $days : null;
    }

    /** Index des ersten Abstands, der nach Tag `$d` endet (`d1 > $d`). */
    private static function firstEndingAfter(array $list, int $d): int
    {
        $lo = 0; $hi = count($list);
        while ($lo < $hi) { $mid = intdiv($lo + $hi, 2); if ($list[$mid]['d1'] > $d) $hi = $mid; else $lo = $mid + 1; }
        return $lo;
    }

    /** Index des letzten Abstands, der vor Tag `$d` beginnt (`d0 < $d`), −1 ohne. */
    private static function lastStartingBefore(array $list, int $d): int
    {
        $lo = 0; $hi = count($list);
        while ($lo < $hi) { $mid = intdiv($lo + $hi, 2); if ($list[$mid]['d0'] < $d) $lo = $mid + 1; else $hi = $mid; }
        return $lo - 1;
    }

    /**
     * Dieselben Tage in anderen Jahren (bis drei davor und danach): je Jahr die Summe der
     * Tagesraten der Abstände, die diese Tage abdecken (mindestens neun Zehntel), Median.
     * Dazu die Streuung zwischen den Jahren (YEAR_SPREAD_MIN als Untergrenze).
     *
     * @return array{0:float,1:float}|null [Erwartung, Streuung]
     */
    private function otherYears(array $x, array $ctx, array $skip): ?array
    {
        $list = $ctx['list'];
        if ($list === []) return null;
        $sums = [];
        $md0 = gmdate('m-d', $x['d0'] * 86400 + 43200);
        $y0 = (int)gmdate('Y', $x['d0'] * 86400 + 43200);
        foreach ([-3, -2, -1, 1, 2, 3] as $k) {
            $y = $y0 + $k;
            $start = self::dayNo(($md0 === '02-29' && !checkdate(2, 29, $y)) ? sprintf('%04d-02-28', $y) : sprintf('%04d-%s', $y, $md0));
            $sum = 0.0; $cov = 0;
            $pos = self::firstEndingAfter($list, $start);
            for ($d = $start, $end = $start + $x['days']; $d < $end && $pos < count($list);) {
                $z = $list[$pos];
                if ($z['d1'] <= $d) { $pos++; continue; }
                if ($z['d0'] > $d) { $d = min($end, $z['d0']); continue; }   // Lücke ohne Daten
                $upto = min($end, $z['d1']);
                if (!isset($skip[$z['j']])) {
                    $sum += $z['kwh'] / max(1, $z['days']) * ($upto - $d);
                    $cov += $upto - $d;
                }
                $d = $upto;
            }
            if ($cov >= 0.9 * $x['days']) $sums[] = $sum * $x['days'] / $cov;
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

    /** Deutlich und dazu jenseits STRONG_RATIO (zweite Stufe). */
    private static function strong(?array $x, int $sign, float $min): bool
    {
        if (!self::sig($x, $sign, $min)) return false;
        return $sign < 0 ? $x['m'] * self::STRONG_RATIO <= $x['e'] : $x['m'] >= $x['e'] * self::STRONG_RATIO;
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
        foreach ($iv as $j => $x) {
            // Vorfilter: Gemessen und höchstens Erwartbares (größte Tagesrate × 1,5) beide
            // unter der Mindestmenge — dann kann der Abstand nicht deutlich sein (normal)
            if ($this->usable($x, $ctx) && max((float)$x['kwh'], 1.5 * $ctx['max_rate'] * $x['days']) < $min) {
                $scr[$j] = ['m' => (float)$x['kwh'], 'e' => max((float)$x['kwh'], 1e-9), 'sd' => 0.0];
                continue;
            }
            $scr[$j] = $this->expect($iv, $j, $ctx, []);
        }
        $cands = [];
        foreach ($scr as $p => $xp) {
            foreach ([-1, 1] as $s) {
                if (!self::sig($xp, $s, $min)) continue;
                // erste Stufe: gemeinsamer Versatz, im Block jeder Abstand normal
                for ($q = $p + 1; $q <= $p + self::MAX_BLOCK && isset($iv[$q]); $q++) {
                    if ($scr[$q] === null) break;
                    if (self::days((string)$points[$p]['date'], (string)$points[$q - 1]['date']) > self::MAX_BLOCK_DAYS) break;
                    if (self::sig($scr[$q], -$s, $min)) { $cands[] = [$p, $q, $s, false]; break; }
                    if (!self::normal($scr[$q], $min)) break;
                }
                // zweite Stufe: sehr starke Gegenbewegung, im Block weiter in dieselbe Richtung erlaubt
                if (!self::strong($xp, $s, $min)) continue;
                for ($q = $p + 1; $q <= $p + self::MAX_BLOCK && isset($iv[$q]); $q++) {
                    if ($scr[$q] === null) break;
                    if (self::days((string)$points[$p]['date'], (string)$points[$q - 1]['date']) > self::MAX_BLOCK_DAYS) break;
                    if (self::strong($scr[$q], -$s, $min)) { $cands[] = [$p, $q, $s, true]; break; }
                    if (self::sig($scr[$q], -$s, $min)) break;
                }
            }
        }
        $found = [];
        foreach ($cands as [$p, $q, $s, $strong]) {
            $c = $this->confirm($utility, $meter, $points, $iv, $ctx, $scr, $p, $q, $s, $strong);
            if ($c !== null) $found[] = $c;
        }
        // erste Stufe vor der zweiten, darin der stärkste Versatz zuerst; Blöcke überschneiden sich nicht
        usort($found, fn($a, $b) => [$a['_level'], $b['_score']] <=> [$b['_level'], $a['_score']]);
        $taken = []; $out = [];
        foreach ($found as $c) {
            $range = range($c['_p'], $c['_q']);
            if (array_intersect($range, $taken) !== []) continue;
            $taken = array_merge($taken, $range);
            unset($c['_score'], $c['_p'], $c['_q'], $c['_level']);
            $out[] = $c;
        }
        usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
        return $out;
    }

    /**
     * Einen Kandidaten bestätigen: kein bestätigter Stand im Block, Nachbarn nicht in
     * dieselbe Richtung, und mit Erwartungen ohne die geprüften Abstände weiter deutlich,
     * der Block normal und die Summe passend. Zweite Stufe (`$strong`): davor und danach
     * jenseits STRONG_RATIO, im Block keine Gegenbewegung, ohne Summe und ohne Vorschlag
     * (`kind` mismatch).
     *
     * @return array<string,mixed>|null
     */
    private function confirm(string $utility, array $meter, array $points, array $iv, array $ctx, array $scr, int $p, int $q, int $s, bool $strong = false): ?array
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
        $far = $strong ? self::strong(...) : self::sig(...);
        if (!$far($xp, $s, $min) || !$far($xq, -$s, $min)) return null;
        for ($k = $p + 1; $k < $q; $k++) {
            $xk = $this->expect($iv, $k, $ctx, $skip, $model);
            // erste Stufe: jeder Abstand im Block normal; zweite: keiner schon zurück
            if ($strong ? self::sig($xk, -$s, $min) : !self::normal($xk, $min)) return null;
        }
        $dp = $xp['m'] - $xp['e'];
        $dq = $xq['m'] - $xq['e'];
        $delta = ($dq - $dp) / 2;   // > 0: die Stände im Block sind zu niedrig
        if (!$strong && abs($dp + $dq) > self::SUM_TOLERANCE * abs($delta)) return null;

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
            'kind'        => $strong ? 'mismatch' : 'offset',
            'date'        => $first,
            'until'       => (string)$block[count($block) - 1]['date'],
            'count'       => count($block),
            'reading_ids' => array_map(fn($r) => (string)($r['id'] ?? ''), $block),
            'direction'   => $s < 0 ? 'low' : 'high',
            // zweite Stufe: kein gemeinsamer Versatz, also kein Vorschlag
            'offset'      => $strong ? null : round(abs($offsetRaw), 1),
            'unit'        => (string)(Utilities::get($utility)['unit'] ?? ''),
            'basis'       => $ctx['method'],
            'before'      => $side($iv[$p], $xp),
            'after'       => $side($iv[$q], $xq),
            'suggested'   => $strong ? [] : array_map(fn($r) => ['id' => (string)($r['id'] ?? ''), 'date' => (string)$r['date'],
                'counter' => (float)$r['counter'], 'suggested' => round((float)$r['counter'] + $offsetRaw, 1)], $block),
            // Skeptiker H4: Notizen an den Ständen vom Beginn des Abstands davor bis zum Ende des
            // Abstands danach („Heizung erst ab heute an“), damit die Frage im Zusammenhang steht
            'notes'       => array_values(array_map(fn($r) => ['date' => (string)$r['date'], 'note' => trim((string)$r['note'])],
                array_filter(array_slice($points, $p - 1, $q - $p + 2), fn($r) => trim((string)($r['note'] ?? '')) !== ''))),
            '_score' => abs($delta) / $ctx['typical'], '_p' => $p, '_q' => $q, '_level' => $strong ? 2 : 1,
        ];
    }

    // ── Zäsur ────────────────────────────────────────────────────────────

    /**
     * Gleichbleibend anderes Niveau vor einem Wechsel (s. LEVEL_*), über die Stücke
     * (etwa Monate). Wechsel kommen an jedem Stückbeginn in Frage; das heutige Niveau
     * kommt aus den Stücken danach (Heizmodell ab LEVEL_AFTER_POINTS Stücken bzw. mittlere
     * Tagesmenge). Ein gleitender Übergang ist erlaubt: Direkt vor der Zeit danach darf ein
     * einzelnes Stück zwischen altem und heutigem Niveau liegen; es zählt weder zum alten
     * Niveau noch zum heutigen. Der Tag des Wechsels ist dann das Ende des letzten Stücks
     * auf altem Niveau. Nicht bei PV, Garten und Jahreszeiten über das Doppelte (dort fragt
     * die App mit denselben Tagen anderer Jahre); bei einer milderen wiederkehrenden
     * Jahreszeit mit der mittleren Tagesmenge (`level_method`).
     *
     * @return array<string,mixed>|null
     */
    private function levelShift(string $utility, array $meter, array $points, array $ctx): ?array
    {
        $method = $ctx['level_method'] ?? $ctx['method'];
        if (!in_array($method, ['heat_model', 'mean_rate'], true)) return null;
        $ch = array_values($ctx['chunks']);
        $n = count($ch);
        if ($n < 3) return null;
        $first = $ch[0]['from'];
        $last = $ch[$n - 1]['to'];
        $min = (float)$ctx['min'];
        $best = null;
        for ($s = 1; $s < $n; $s++) {
            $start = $ch[$s]['from'];
            if (self::days($first, $start) < self::LEVEL_MIN_DAYS || self::days($start, $last) < self::LEVEL_AFTER_DAYS) continue;
            $after = array_slice($ch, $s);
            if ($method === 'heat_model') {
                $model = $this->fit($after, self::LEVEL_AFTER_POINTS);
                if ($model === null) continue;
                $exp = fn(array $c): float => $model['a'] * $c['hdd'] + $model['c'] * $c['days'];
            } else {
                $sum = 0.0; $days = 0;
                foreach ($after as $c) { $sum += $c['kwh']; $days += $c['days']; }
                if ($days < self::LEVEL_AFTER_DAYS) continue;
                $rate = $sum / $days;
                $exp = fn(array $c): float => $rate * $c['days'];
            }
            // davor: am Stück, jedes nennenswerte Stück auf derselben Seite; direkt vor
            // der Zeit danach darf ein Übergang stehen (ein Stück dazwischen)
            $dir = 0; $sm = 0.0; $se = 0.0; $runFrom = null; $date = null;
            for ($k = $s - 1; $k >= 0; $k--) {
                $c = $ch[$k];
                if ($c['to'] !== ($ch[$k + 1]['from'] ?? $c['to'])) break;   // Lücke in den Daten
                $e = $exp($c);
                if ($e <= 0) break;
                $m = (float)$c['kwh'];
                // nennenswert: Verbrauch oder Erwartung erreichen die Mindestmenge. Nicht die
                // Abweichung von heute: Ein Stück auf heutigem Niveau mitten in der alten Zeit
                // weicht von heute nicht ab, widerspricht aber dem alten Niveau (Gegenbewegung)
                if (max($e, $m) >= $min) {
                    $r = $m / $e;
                    $d = $r >= self::LEVEL_STEP ? 1 : ($r <= 1 / self::LEVEL_STEP ? -1 : 0);
                    if ($d === 0 && $k === $s - 1) continue;   // Übergang
                    if ($d === 0 || ($dir !== 0 && $d !== $dir)) break;
                    $dir = $d;
                }
                $sm += $m; $se += $e; $runFrom = $c['from'];
                $date ??= $c['to'];
            }
            if ($dir === 0 || $runFrom === null || $date === null || $se <= 0) continue;
            $span = self::days($runFrom, $date);
            $ratio = $sm / $se;
            if ($span < self::LEVEL_MIN_DAYS || ($dir > 0 ? $ratio < self::LEVEL_RATIO : $ratio > 1 / self::LEVEL_RATIO)) continue;
            // danach keine Gegenbewegung: Das erste Stück danach liegt schon auf heutigem
            // Niveau (sonst liegt der Wechsel später), und höchstens LEVEL_AFTER_BACK der
            // Tage danach kehren zum alten Niveau zurück. Zurück heißt: jenseits LEVEL_STEP in
            // Richtung des alten Niveaus, und das Stück selbst oder eines, das am Stück in
            // dieselbe Richtung daneben liegt, weicht um mindestens die Mindestmenge von heute
            // ab. Ein kurzer Sommerabstand mit 40 % mehr, aber 100 kWh, ist allein keine
            // Rückkehr (Nachprüfung: drei solche Stücke hoben den Anteil von 7,1 auf 14,6 %);
            // die kleinen Monate am Ende einer echten Rückkehr zählen mit.
            $back = 0; $total = 0; $firstBack = false;
            $run = []; $runBig = false;
            $flush = function () use (&$run, &$runBig, &$back): void {
                if ($runBig) $back += array_sum($run);
                $run = []; $runBig = false;
            };
            foreach ($after as $i => $c) {
                $e = $exp($c);
                $total += $c['days'];
                $toward = $e > 0 && ($dir > 0 ? $c['kwh'] >= $e * self::LEVEL_STEP : $c['kwh'] * self::LEVEL_STEP <= $e);
                if ($i === 0) $firstBack = $toward && max($e, $c['kwh']) >= $min;
                if (!$toward || ($i > 0 && $after[$i - 1]['to'] !== $c['from'])) $flush();
                if (!$toward) continue;
                $run[] = $c['days'];
                $runBig = $runBig || abs($c['kwh'] - $e) >= $min;
            }
            $flush();
            if ($firstBack || $back > self::LEVEL_AFTER_BACK * $total) continue;
            $score = abs(log($ratio));
            if ($best === null || $score > $best['_score'] + 1e-9) {
                $best = ['date' => $date, 'from' => $runFrom, 'dir' => $dir, 'ratio' => $ratio, 'days' => $span, '_score' => $score];
            }
        }
        if ($best === null) return null;
        return [
            'uid'       => AgendaService::uid('level_shift', $utility, (string)$meter['id'], $best['date']),
            'date'      => $best['date'],
            'from'      => $best['from'],
            'direction' => $best['dir'] > 0 ? 'higher' : 'lower',
            'factor'    => round($best['ratio'], 2),
            'days'      => $best['days'],
            'months'    => (int)round($best['days'] / self::MONTH_DAYS),
            'basis'     => $method,
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

    /** Tagesnummer eines ISO-Datums (UTC, ohne Sommerzeit). */
    private static function dayNo(string $date): int
    {
        return intdiv((int)strtotime($date . ' 12:00:00 UTC'), 86400);
    }

    private static function days(string $a, string $b): int
    {
        return self::dayNo($b) - self::dayNo($a);
    }

    /** @return list<string> */
    private function activeUtilities(): array
    {
        $a = $this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
        if (!is_array($a) || empty($a)) return Utilities::keys();
        return array_values(array_filter($a, fn($k) => is_string($k) && Utilities::exists($k)));
    }
}
