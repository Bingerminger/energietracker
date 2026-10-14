<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Support\Dates;

/**
 * v3.5.0 (F1027) — Tagesfilm: der Energiefluss eines Tages in Viertelstunden.
 *
 * Zwei Ansichten aus denselben Viertelstundenreihen (IntervalService):
 *
 *   day()         ein bestimmter Tag, je Viertelstunde (92, 96 oder 100 je
 *                 nach Zeitumstellung — Uhrzeit aus localClock())
 *   typicalDay()  der typische Tag eines Monats: je Uhrzeit (Ortszeit) das
 *                 Mittel über alle Viertelstunden des Monats mit Daten
 *   days()        welche Tage und Monate sich zeigen lassen, Vorbelegung
 *
 * Welche Zähler zählen — wie im Energiefluss von Monat und Jahr
 * (PvSummaryService):
 *
 *   grid        Netzbezug: Stromzähler, die in Summen zählen
 *               (MeterService::countsInTotals — Subzähler nach F1006 nicht)
 *   feed        Einspeisung: Zähler der PV-Einspeisung, die in Summen zählen
 *   gen         Erzeugung: Zähler der PV-Erzeugung, die in Summen zählen
 *               (ohne Speicherzähler)
 *   charged     Speicher geladen: Rolle battery_charge
 *   discharged  Speicher entladen: Rolle battery_discharge
 *
 * Eine Viertelstunde ist vollständig, wenn jeder Zähler, der an dem Tag in
 * Betrieb ist (inService, Gerät eingebaut), einen Wert hat. Dann rechnet
 * PvSummaryService::flows() den Fluss — dieselbe Formel und dieselbe Autarkie
 * wie Monat und Jahr. Sonst ist sie eine Lücke: Werte null, kein Fluss — nie
 * eine stille 0. Eine Art ohne Zähler ist 0 (es gibt nichts zu messen); gibt
 * es Erzeugung ohne Einspeisezähler oder umgekehrt, fehlt die andere Hälfte
 * (Lücke), weil sich die Erzeugung sonst nicht aufteilen lässt.
 *
 * Ohne Viertelstunden des Netzbezugs gibt es keinen Tagesfilm: days() bietet
 * nur Tage an, an denen der Netzbezug mindestens eine Viertelstunde hat.
 */
final class EnergyFlowDayService
{
    /** Arten im Fluss, in der Reihenfolge der Antwort. */
    public const KINDS = ['grid', 'gen', 'feed', 'charged', 'discharged'];

    /** Verbrauchsart je Art. */
    private const UTILITY = [
        'grid' => 'strom', 'feed' => 'pv_einspeisung', 'gen' => 'pv_erzeugung',
        'charged' => 'pv_erzeugung', 'discharged' => 'pv_erzeugung',
    ];

    /** Kleinste und größte zulässige Jahreszahl der Abfrage. */
    public const YEAR_MIN = 2000;
    public const YEAR_MAX = 2100;

    /** @var array<int,array<string,mixed>> geladene Reihen je Jahr (je Anfrage) */
    private array $years = [];
    /** @var array<int,list<int>> Minute des Tages je Platz, je Jahr */
    private array $clocks = [];

    public function __construct(private IntervalService $intervals, private MeterService $meters) {}

    /** Kalendertag JJJJ-MM-TT im zulässigen Jahresbereich? */
    public static function validDate(string $date): bool
    {
        return Dates::isIsoDate($date) && self::yearOk((int)substr($date, 0, 4));
    }

    /** Monat JJJJ-MM im zulässigen Jahresbereich? */
    public static function validMonth(string $month): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 && self::yearOk((int)substr($month, 0, 4));
    }

    private static function yearOk(int $year): bool
    {
        return $year >= self::YEAR_MIN && $year <= self::YEAR_MAX;
    }

    // ── Öffentlich ──────────────────────────────────────────────────────

    /**
     * Tage und Monate mit Viertelstunden des Netzbezugs.
     *
     * @param string|null $today  JJJJ-MM-TT (Ortszeit); Tage ab heute fehlen
     *                            in der Liste — sie sind noch nicht vorbei
     * @return array<string,mixed>
     */
    public function days(?string $today = null): array
    {
        $today ??= (new \DateTimeImmutable('now', $this->intervals->tz()))->format('Y-m-d');
        $byKind = $this->metersByKind();
        $gridIds = array_map(fn($m) => (string)$m['id'], $byKind['grid']);
        $years = [];
        foreach ($this->intervals->list() as $e) {
            if (($e['kind'] ?? '') !== 'energy' || ($e['utility'] ?? '') !== 'strom') continue;
            if (!in_array((string)($e['meter_id'] ?? ''), $gridIds, true) || (int)($e['filled'] ?? 0) <= 0) continue;
            $years[(int)($e['year'] ?? 0)] = true;
        }
        ksort($years);
        $days = []; $complete = [];
        $unknown = $this->unknownKinds($byKind);
        foreach (array_keys($years) as $year) {
            $Y = $this->year($year, $byKind);
            $tz = $this->intervals->tz();
            for ($d = new \DateTimeImmutable("$year-01-01 00:00:00", $tz); (int)$d->format('Y') === $year; $d = $d->modify('+1 day')) {
                $date = $d->format('Y-m-d');
                if ($date >= $today) break;
                [$i0, $i1] = $this->range($date);
                $grid = false;
                for ($i = $i0; $i < $i1 && !$grid; $i++) {
                    foreach ($Y['series']['grid'] ?? [] as $vals) if (($vals[$i] ?? null) !== null) { $grid = true; break; }
                }
                if (!$grid) continue;
                $days[] = $date;
                $needed = $this->needed($byKind, $date);
                $all = true;
                for ($i = $i0; $i < $i1 && $all; $i++) {
                    if (in_array(null, $this->values($Y, $needed, $unknown, $i), true)) $all = false;
                }
                if ($all) $complete[] = $date;
            }
        }
        $months = array_values(array_unique(array_map(fn($d) => substr($d, 0, 7), $days)));
        return [
            'available'     => $days !== [],
            'days'          => $days,
            'complete_days' => $complete,
            'months'        => $months,
            // Vorbelegung: letzter vollständiger Tag (sonst der letzte mit Daten), jüngster Monat
            'default_day'   => $complete !== [] ? end($complete) : ($days !== [] ? end($days) : null),
            'default_month' => $months !== [] ? end($months) : null,
        ];
    }

    /**
     * Ein Tag in Viertelstunden (Ortszeit der Installation).
     *
     * @param string $date JJJJ-MM-TT
     * @return array<string,mixed>
     */
    public function day(string $date): array
    {
        $year = (int)substr($date, 0, 4);
        $byKind = $this->metersByKind();
        $Y = $this->year($year, $byKind);
        $minute = $this->clock($year);
        $needed = $this->needed($byKind, $date);
        $unknown = $this->unknownKinds($byKind);
        [$i0, $i1] = $this->range($date);
        $slots = []; $seen = [];
        for ($i = $i0; $i < $i1; $i++) {
            $t = self::hhmm($minute[$i] ?? 0);
            $row = $this->slotRow($t, $this->values($Y, $needed, $unknown, $i), $Y['prices'][$i] ?? null);
            // Herbst: dieselbe Uhrzeit ein zweites Mal (nach der Umstellung)
            if (isset($seen[$t])) $row['repeat'] = true;
            $seen[$t] = true;
            $slots[] = $row;
        }
        return [
            'mode' => 'day', 'date' => $date, 'month' => substr($date, 0, 7),
            'timezone' => $this->intervals->tz()->getName(), 'step_minutes' => IntervalService::STEP / 60,
            'slots' => $slots,
            'totals' => $this->totals($slots),
            'completeness' => $this->completeness($slots),
            'kinds' => $this->kinds($byKind, $Y),
            'missing' => $this->missing($byKind, $Y, $unknown, [$date]),
        ];
    }

    /**
     * Typischer Tag eines Monats: je Uhrzeit (Ortszeit, 96 Viertelstunden)
     * das Mittel über alle vollständigen Viertelstunden des Monats. Der Tag
     * der Zeitumstellung zählt nach Uhrzeit: Im März fehlt ihm 2:00–2:45, im
     * Oktober trägt er diese Uhrzeiten zweimal bei. `n` nennt die Zahl der
     * Viertelstunden im Mittel; der Börsenpreis mittelt über alle
     * Viertelstunden mit Preis.
     *
     * @param string $month JJJJ-MM
     * @return array<string,mixed>
     */
    public function typicalDay(string $month): array
    {
        $year = (int)substr($month, 0, 4);
        $byKind = $this->metersByKind();
        $Y = $this->year($year, $byKind);
        $minute = $this->clock($year);
        $unknown = $this->unknownKinds($byKind);
        $acc = []; $days = 0; $dates = [];
        $last = (int)(new \DateTimeImmutable("$month-01"))->format('t');
        for ($d = 1; $d <= $last; $d++) {
            $date = sprintf('%s-%02d', $month, $d);
            $dates[] = $date;
            $needed = $this->needed($byKind, $date);
            [$i0, $i1] = $this->range($date);
            $any = false;
            for ($i = $i0; $i < $i1; $i++) {
                $min = $minute[$i] ?? 0;
                $p = $Y['prices'][$i] ?? null;
                if ($p !== null) {
                    $acc[$min]['p'] = ($acc[$min]['p'] ?? 0.0) + $p;
                    $acc[$min]['pn'] = ($acc[$min]['pn'] ?? 0) + 1;
                }
                $v = $this->values($Y, $needed, $unknown, $i);
                if (in_array(null, $v, true)) continue;
                $any = true;
                foreach (self::KINDS as $k) $acc[$min][$k] = ($acc[$min][$k] ?? 0.0) + $v[$k];
                $acc[$min]['n'] = ($acc[$min]['n'] ?? 0) + 1;
            }
            if ($any) $days++;
        }
        $slots = [];
        for ($min = 0; $min < 1440; $min += IntervalService::STEP / 60) {
            $a = $acc[$min] ?? [];
            $n = (int)($a['n'] ?? 0);
            $v = [];
            foreach (self::KINDS as $k) $v[$k] = $n > 0 ? $a[$k] / $n : null;
            $pn = (int)($a['pn'] ?? 0);
            $slots[] = $this->slotRow(self::hhmm($min), $v, $pn > 0 ? $a['p'] / $pn : null) + ['n' => $n];
        }
        return [
            'mode' => 'typical', 'month' => $month, 'days' => $days,
            'timezone' => $this->intervals->tz()->getName(), 'step_minutes' => IntervalService::STEP / 60,
            'slots' => $slots,
            'totals' => $this->totals($slots),
            'completeness' => $this->completeness($slots),
            'kinds' => $this->kinds($byKind, $Y),
            'missing' => $this->missing($byKind, $Y, $unknown, $dates),
        ];
    }

    // ── Zähler ──────────────────────────────────────────────────────────

    /** @return array<string,list<array<string,mixed>>> Art → Zähler */
    private function metersByKind(): array
    {
        $out = array_fill_keys(self::KINDS, []);
        foreach ($this->meters->list('strom') as $m) {
            if (MeterService::countsInTotals($m)) $out['grid'][] = $m;
        }
        foreach ($this->meters->list('pv_einspeisung') as $m) {
            if (MeterService::countsInTotals($m)) $out['feed'][] = $m;
        }
        foreach ($this->meters->list('pv_erzeugung') as $m) {
            $role = Utilities::roleOf('pv_erzeugung', $m);
            // Speicherzähler zählen wie im Monatsfluss auch als Subzähler
            if ($role === 'battery_charge') $out['charged'][] = $m;
            elseif ($role === 'battery_discharge') $out['discharged'][] = $m;
            elseif (MeterService::countsInTotals($m)) $out['gen'][] = $m;
        }
        return $out;
    }

    /**
     * Welche Zähler an einem Tag Werte liefern müssen: in Betrieb und mit
     * einem eingebauten Gerät (Stichtag wie überall: am Tag des Ausbaus gilt
     * schon das neue Gerät).
     *
     * @return array<string,array<string,bool>>
     */
    private function needed(array $byKind, string $date): array
    {
        $out = [];
        foreach ($byKind as $kind => $list) {
            foreach ($list as $m) {
                $out[$kind][(string)$m['id']] = MeterService::inService($m)
                    && (empty($m['devices']) || $this->meters->deviceOnDate($m, $date) !== null);
            }
        }
        return $out;
    }

    /**
     * Erzeugung ohne Einspeisezähler (oder umgekehrt): Die andere Hälfte
     * fehlt, die Erzeugung lässt sich nicht aufteilen.
     *
     * @return array<string,bool>
     */
    private function unknownKinds(array $byKind): array
    {
        $u = [];
        if ($byKind['gen'] !== [] && $byKind['feed'] === []) $u['feed'] = true;
        if ($byKind['feed'] !== [] && $byKind['gen'] === []) $u['gen'] = true;
        return $u;
    }

    // ── Reihen ──────────────────────────────────────────────────────────

    /** @return array{series: array<string,array<string,?list<?float>>>, prices: ?list<?float>} */
    private function year(int $year, array $byKind): array
    {
        if (isset($this->years[$year])) return $this->years[$year];
        $series = [];
        foreach ($byKind as $kind => $list) {
            foreach ($list as $m) {
                $series[$kind][(string)$m['id']] = $this->intervals->energy(self::UTILITY[$kind], (string)$m['id'], $year);
            }
        }
        return $this->years[$year] = ['series' => $series, 'prices' => $this->intervals->prices($year)];
    }

    /** @return list<int> Minute des Tages (Ortszeit) je Platz des Jahres */
    private function clock(int $year): array
    {
        return $this->clocks[$year] ??= $this->intervals->localClock($year)['minute'];
    }

    /**
     * Plätze eines Tages im Jahr: [von, bis). Mitternacht bis Mitternacht in
     * der Zeitzone der Installation — 92, 96 oder 100 Viertelstunden.
     *
     * @return array{0:int,1:int}
     */
    private function range(string $date): array
    {
        $tz = $this->intervals->tz();
        $start = $this->intervals->yearStart((int)substr($date, 0, 4));
        $a = new \DateTimeImmutable("$date 00:00:00", $tz);
        $b = $a->modify('+1 day');
        return [intdiv($a->getTimestamp() - $start, IntervalService::STEP), intdiv($b->getTimestamp() - $start, IntervalService::STEP)];
    }

    /**
     * Werte einer Viertelstunde je Art (kWh). Null, wenn ein Zähler fehlt,
     * der an dem Tag Werte liefern müsste; Zähler außer Betrieb tragen bei,
     * was sie haben.
     *
     * @return array<string,?float>
     */
    private function values(array $Y, array $needed, array $unknown, int $i): array
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            if (!empty($unknown[$kind])) { $out[$kind] = null; continue; }
            $sum = 0.0;
            foreach ($Y['series'][$kind] ?? [] as $id => $vals) {
                $v = $vals[$i] ?? null;
                if ($v === null) {
                    if ($needed[$kind][$id] ?? false) { $sum = null; break; }
                    continue;
                }
                $sum += $v;
            }
            $out[$kind] = $sum;
        }
        return $out;
    }

    // ── Antwort ─────────────────────────────────────────────────────────

    /** Eine Viertelstunde: Mengen in kWh, Fluss nur, wenn alle Arten da sind. */
    private function slotRow(string $t, array $v, ?float $price): array
    {
        $complete = !in_array(null, $v, true);
        $flow = $complete
            ? PvSummaryService::flows((float)$v['gen'], (float)$v['feed'], (float)$v['grid'], (float)$v['charged'], (float)$v['discharged'], 4)
            : null;
        $row = ['t' => $t];
        foreach (self::KINDS as $k) $row[$k] = $v[$k] !== null ? round($v[$k], 4) : null;
        $row['house'] = $flow['house'] ?? null;
        $row['price'] = $price !== null ? round($price, 3) : null;
        $row['flow'] = $flow;
        return $row;
    }

    /**
     * Summen über die vollständigen Viertelstunden: Mengen je Art und der
     * Fluss des Tages aus diesen Summen — gerechnet wie ein Monat
     * (PvSummaryService::flows). So bleibt Netzladung Netzstrom, auch wenn der
     * Speicher erst Stunden später ins Haus entlädt; eine Summe der
     * Viertelstundenflüsse verlöre das. Der Hausverbrauch ist in beiden
     * Rechnungen derselbe (Bilanz).
     *
     * @param list<array<string,mixed>> $slots
     * @return array<string,mixed>
     */
    private function totals(array $slots): array
    {
        $sum = array_fill_keys(self::KINDS, 0.0);
        $n = 0;
        foreach ($slots as $s) {
            if ($s['flow'] === null) continue;
            $n++;
            foreach (self::KINDS as $k) $sum[$k] += (float)$s[$k];
        }
        $out = array_map(fn($v) => round($v, 3), $sum);
        $out['flow'] = $n > 0 ? PvSummaryService::flows($sum['gen'], $sum['feed'], $sum['grid'], $sum['charged'], $sum['discharged'], 3) : null;
        $out['house'] = $out['flow']['house'] ?? null;
        $out['autarky'] = $out['flow']['autarky'] ?? null;
        return $out;
    }

    /** @return array{slots:int, complete:int, price:int} */
    private function completeness(array $slots): array
    {
        return [
            'slots'    => count($slots),
            'complete' => count(array_filter($slots, fn($s) => $s['flow'] !== null)),
            'price'    => count(array_filter($slots, fn($s) => $s['price'] !== null)),
        ];
    }

    /** Welche Arten es im Haushalt gibt (für die Knoten des Bildes). */
    private function kinds(array $byKind, array $Y): array
    {
        return [
            'gen'     => $byKind['gen'] !== [],
            'feed'    => $byKind['feed'] !== [],
            'battery' => $byKind['charged'] !== [] || $byKind['discharged'] !== [],
            'price'   => $Y['prices'] !== null,
        ];
    }

    /**
     * Zähler ohne Viertelstundenreihe im Jahr, die an einem der Tage Werte
     * liefern müssten — und die fehlende Hälfte der PV (Zähler-ID null).
     *
     * @param list<string> $dates
     * @return list<array{kind:string, utility:string, meter_id:?string, name:?string}>
     */
    private function missing(array $byKind, array $Y, array $unknown, array $dates): array
    {
        $out = [];
        foreach ($byKind as $kind => $list) {
            foreach ($list as $m) {
                $id = (string)$m['id'];
                if (($Y['series'][$kind][$id] ?? null) !== null) continue;
                $need = false;
                foreach ($dates as $date) if ($this->needed([$kind => [$m]], $date)[$kind][$id]) { $need = true; break; }
                if ($need) $out[] = ['kind' => $kind, 'utility' => self::UTILITY[$kind], 'meter_id' => $id, 'name' => (string)($m['name'] ?? $id)];
            }
        }
        foreach (array_keys($unknown) as $kind) {
            $out[] = ['kind' => $kind, 'utility' => self::UTILITY[$kind], 'meter_id' => null, 'name' => null];
        }
        return $out;
    }

    private static function hhmm(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
