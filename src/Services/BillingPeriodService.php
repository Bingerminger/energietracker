<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Support\Dates;
use Energietracker\Support\LocalizedException;

/**
 * v3.6.0 (F1030, GitHub #33) — Abrechnungsjahr statt Kalenderjahr: die eine
 * Quelle für Abrechnungsperioden. Verbrauchsart, Übersicht, Analyse,
 * Jahresbericht und CSV lesen sie hier; der Browser rechnet nichts nach.
 *
 * Perioden:
 *   1. aus den erfassten Versorgerrechnungen (`period_from`/`period_to`),
 *      genau wie auf der Rechnung — auch kürzer oder länger als ein Jahr;
 *      überlappt eine Rechnung eine frühere, gilt die frühere;
 *   2. davor, dazwischen und danach aus dem Stichtag der Art
 *      (`billing_cycle_anchor_<art>`, MM-TT, Standard 01-01): eine Lücke wird
 *      an den Stichtagen geteilt; die laufende Periode beginnt am Tag nach
 *      der letzten Rechnung bzw. am letzten Stichtag und endet am Tag vor dem
 *      nächsten Stichtag;
 *   3. erst ab den Daten: die erste Ablesung bzw. eine wirksame Analyse-Zäsur
 *      (`pre_baseline`) — davor gibt es keine Periode, die Periode mit dem
 *      Beginn ist eine Teilperiode (`complete` = false);
 *   4. v3.6.0 — ein Lieferantenwechsel ohne erfasste Rechnung
 *      (ContractService::supplierSwitchAfter) teilt die Stichtag-Periode: Sie
 *      endet am letzten Tag beim alten Versorger (`ends_at_switch`), die
 *      nächste beginnt mit dem Folgevertrag. Liegt dazwischen eine Lücke ohne
 *      Vertrag, ist sie eine eigene Teilperiode. Eine Verlängerung oder ein
 *      Folgevertrag desselben Versorgers ohne Lücke teilt nicht; Rechnungen
 *      gehen vor (eine Schlussrechnung teilt ohnehin).
 *   5. v3.7.0 (GitHub #33) — keine Kurzreste: Schneidet ein Wechsel kurz
 *      vor oder nach dem Stichtag eine Periode von weniger als
 *      SHORT_REST_DAYS Tagen ab (Stichtag 31-08, Verträge jeweils ab 01.09.:
 *      jedes Jahr eine Periode 31.08.–31.08.), geht der Rest über den Stichtag
 *      hinweg in die Nachbarperiode auf — nie über einen Wechsel oder eine
 *      Rechnung. Die Summen bleiben dieselben, sie verteilen sich nur anders.
 *
 * Der Stichtag ist der ERSTE Tag einer Abrechnungsperiode: 01-09 heißt
 * 1. September bis 31. August.
 *
 * Summen je Periode mit denselben Bausteinen wie „Rechnung prüfen“
 * (ConsumptionService::billBreakdown: Menge, Arbeits- und Grundpreis
 * tagesanteilig, Boni nach Gutschrift), Abschläge wie BillService::compare
 * tagesanteilig aus der Monatsreihe, Sonderzahlungen nach Datum. Saldo =
 * Kosten − Abschläge − zusätzliche Abschlagszahlungen (positiv = Nachzahlung,
 * wie `result_eur` der Rechnung). Rück- und Nachzahlungen gleichen eine
 * frühere Abrechnung aus; sie stehen daneben und zählen nicht in den Saldo.
 *
 * Zeiträume sind „bis einschließlich“ (`until`), wie auf der Rechnung.
 */
final class BillingPeriodService
{
    /**
     * v3.7.0 (#33) — Ein Stück unter so vielen Tagen zwischen Stichtag und
     * Lieferantenwechsel ist ein Kurzrest. Zwei Wochen: weniger als ein halber
     * Abschlagsmonat und weit unter den 31 Tagen, um die „Abrechnung erwartet“
     * ein Rechnungsende vom Periodenende abweichen lässt
     * (BillExpectedService::TOLERANCE_DAYS) — eine eigene Rechnung bekommt so
     * ein Stück nicht; es ist ein Stichtag, der ein paar Tage neben dem
     * Lieferbeginn liegt. 17 Tage (Stichtag 01-11, Wechsel am 15.10.) bleiben
     * dagegen ein eigener, kurzer erster Abschnitt.
     */
    public const SHORT_REST_DAYS = 14;

    /**
     * v3.9.0 (Skeptiker M7) — Eine Lieferzeit, die an einem Lieferantenwechsel beginnt und an
     * einem endet, ist bis zu so vielen Tagen eine Abrechnungsperiode: Der Versorger rechnet sie
     * mit der Schlussrechnung zum Vertragsende ab, der Stichtag gilt in ihr nicht. 400 Tage: ein
     * Jahresvertrag mit einem Monat Spielraum (Verlängerung um Wochen, Wechsel zum Monatsende);
     * eine längere Belieferung rechnet der Versorger im Jahresrhythmus ab.
     */
    public const SWITCH_PERIOD_MAX_DAYS = 400;

    /**
     * v3.10.0 (GitHub #36) — Trend im Abrechnungsjahr erst ab so vielen Tagen der
     * Periode: Bei wenigen Tagen entscheidet der Ablesetag über den Prozentwert.
     */
    public const SAME_SPAN_MIN_DAYS = 14;

    /**
     * v3.10.0 (GitHub #36) — Die Periode davor dient als Vergleich, wenn sie
     * höchstens so viele Tage neben „ein Jahr früher“ beginnt (Rechnungen
     * schwanken um ein paar Tage, wie BillExpectedService::TOLERANCE_DAYS).
     * Beginnt sie deutlich anders, etwa nach einem Lieferantenwechsel mitten im
     * Jahr, lägen dieselben Tage in einer anderen Jahreszeit; dann vergleicht der
     * Pfeil dieselben Kalendertage ein Jahr früher (sameSpan, `basis` = `calendar`).
     */
    public const SAME_SPAN_ALIGN_DAYS = 31;

    public function __construct(
        private SettingsService $settings,
        private MeterService $meters,
        private ReadingService $readings,
        private ConsumptionService $consumption,
        private BillService $bills,
    ) {}

    /** Stichtag der Art wie nextBillingAnchor: ungültig → 01-01, 29.02. → 28.02. */
    public function anchor(string $utility): string
    {
        return self::normalizeAnchor($this->settings->get('billing_cycle_anchor_' . $utility, '01-01'));
    }

    public static function normalizeAnchor(mixed $anchor): string
    {
        if (!SettingsService::isMonthDay($anchor)) return '01-01';
        return str_starts_with((string)$anchor, '02-') && (int)substr((string)$anchor, 3) > 28 ? '02-28' : (string)$anchor;
    }

    /**
     * Perioden eines Zählers oder einer Gruppe (die Mitglieder zusammen).
     *
     * @return array<string,mixed>
     */
    public function forTarget(string $utility, array $target, bool $withMonths = false, ?string $today = null): array
    {
        $this->assertSupported($utility);
        // v3.6.0 — Gruppe ohne Subzähler, deren Elternzähler Mitglied ist (wie ConsumptionService::computeForGroup)
        $meters = !empty($target['is_group']) ? $this->meters->groupSumMembers($utility, (string)$target['id']) : [$target];
        // v3.9.0 (F1032) — … außer sie rechnen nach dem Gruppenvertrag (dann rechnet ihr Elternzähler nur den Rest)
        if (!empty($target['is_group'])) {
            $ids = array_map(fn($m) => (string)($m['id'] ?? ''), $meters);
            foreach ($this->meters->groupMembers($utility, (string)$target['id']) as $m) {
                if (!in_array((string)($m['id'] ?? ''), $ids, true) && $this->consumption->isDeductingSubmeter($utility, $m)) $meters[] = $m;
            }
        }
        return $this->compute($utility, $meters, $withMonths, $today) + ['target_id' => (string)($target['id'] ?? '')];
    }

    /** v3.11.0 (D, #54) — nur abgeschlossene Perioden ab diesem Ende rechnen (closedPeriods), sonst null. */
    private ?string $closedSince = null;

    /**
     * v3.11.0 (D, #54) — die jüngste abgeschlossene Periode eines Ziels, wenn sie am oder nach `$since`
     * endet (sonst keine), mit denselben Summen wie forTarget() (auch `advance_vs_cost`), ohne die übrigen
     * und ohne Vergleich mit dem Vorjahr. Für die Empfehlung `r_contract_conflict`: Sie läuft mit jeder
     * Agenda-Anfrage; alle Perioden zu rechnen kostete dort bei zehn Jahren Tageswerten (tests/
     * large-data.test.mjs) rund 440 ms je Anfrage, die jüngste allein einen Bruchteil.
     *
     * @return list<array<string,mixed>>
     */
    public function closedPeriods(string $utility, array $target, string $since, ?string $today = null): array
    {
        $this->closedSince = $since;
        try {
            return $this->forTarget($utility, $target, false, $today)['periods'];
        } finally {
            $this->closedSince = null;
        }
    }

    /**
     * Perioden der ganzen Art: Zähler ohne Elternzähler (Subzähler stecken im
     * Elternzähler, F1006), ohne Zähler mit Verbrauch je Zeitraum.
     *
     * @return array<string,mixed>
     */
    public function forUtility(string $utility, bool $withMonths = false, ?string $today = null): array
    {
        $this->assertSupported($utility);
        $meters = array_values(array_filter($this->meters->list($utility),
            fn($m) => MeterService::countsInTotals($m) && ($m['capture'] ?? null) !== 'period'));
        // v3.9.0 (F1032, #40) — Subzähler mit eigenem Vertrag: ihr Geld, nicht ihre Menge; die
        // Menge der Art ist die Messung der Zähler ohne Elternzähler (`measured_kwh`)
        $money = array_values(array_filter($this->meters->list($utility),
            fn($m) => !MeterService::countsInTotals($m) && ($m['capture'] ?? null) !== 'period' && $this->consumption->isDeductingSubmeter($utility, $m)));
        return $this->compute($utility, $meters, $withMonths, $today, $money, true);
    }

    private function assertSupported(string $utility): void
    {
        if (!Utilities::supportsBillCheck($utility)) {
            throw new LocalizedException('errors.billCheck.unsupportedUtility', ['utility' => $utility], "billing periods $utility");
        }
    }

    /**
     * v3.9.0 (V1) — Die laufende Abrechnungsperiode eines Zählers oder einer
     * Gruppe (die Periode, in der heute liegt), ohne Summen: dieselben Grenzen
     * wie forTarget() (Rechnungen, Stichtag, Lieferantenwechsel, Kurzreste).
     * Der Vertragsstatus rechnet seinen Saldo über genau diese Periode — eine
     * Quelle für Saldo-Karte, Kosten & Verträge, Übersicht, Jahresbericht und
     * Werkstatt, auch ohne erfasste Rechnung. Null für Arten ohne
     * Abrechnungsjahr und ohne Ablesung.
     *
     * @return array{from:string,until:string,source:string,bill_id:?string,complete:bool,running:bool,label:?string,ends_at_switch:bool}|null
     */
    public function runningPeriodFor(string $utility, array $target, ?string $today = null): ?array
    {
        if (!Utilities::supportsBillCheck($utility) || ($target['capture'] ?? null) === 'period') return null;
        $today ??= date('Y-m-d');
        $meters = !empty($target['is_group']) ? $this->meters->groupSumMembers($utility, (string)$target['id']) : [$target];
        if ($meters === []) return null;
        $st = $this->structure($utility, $meters, $today);
        $periods = $st['periods'];
        // v3.9.0 (Skeptiker M7) — der Stichtag-Vorschlag auch an Saldo-Karte und „Kosten & Verträge“
        // (bis dahin nur unter „Abrechnungsjahr“): passt er nicht zur letzten Rechnung bzw. zu den Verträgen
        $suggested = self::suggestedAnchor($st['bills'], $st['anchor']);
        $source = $suggested !== null ? 'bill' : null;
        if ($st['bills'] === []) {
            $suggested = self::suggestedAnchorFromContracts(array_values($st['starts']), $st['switches'], $st['anchor']);
            $source = $suggested !== null ? 'contracts' : null;
        }
        $hint = ['anchor' => $st['anchor'], 'suggested_anchor' => $suggested, 'suggested_anchor_source' => $source,
                 'last_bill_until' => $st['bills'] !== [] ? (string)$st['bills'][count($st['bills']) - 1]['until'] : null];
        foreach ($periods as $i => $p) {
            if ($p['from'] <= $today && $today <= $p['until']) {
                // offen seit: Eine angeschnittene Stichtag-Periode direkt nach einer
                // Rechnung (der Stichtag passt nicht zu ihr, die App schlägt dann
                // einen anderen vor) ist noch nicht abgerechnet — die nächste Rechnung
                // umfasst sie mit. Ganze Stichtag-Perioden ohne Rechnung gelten als
                // abgerechnet.
                $prev = $periods[$i - 1] ?? null;
                $before = $periods[$i - 2] ?? null;
                $p['open_from'] = $prev !== null && $prev['source'] === 'anchor' && !$prev['complete'] && $before !== null
                    && $before['source'] === 'bill' && self::addDays($before['until'], 1) === $prev['from'] && $p['source'] === 'anchor'
                    ? $prev['from'] : $p['from'];
                return $p + $hint;
            }
        }
        return null;
    }

    /**
     * v3.9.0 (V1) — Grundlagen der Perioden (Stichtag, Rechnungen, Daten von–bis,
     * Vertragsbeginne, Wechsel) und die Perioden selbst, ohne Summen.
     *
     * @param list<array<string,mixed>> $meters
     * @return array{anchor:string,bills:list<array>,start:?string,end:?string,starts:array<string,string>,switches:list<array>,periods:list<array>}
     */
    private function structure(string $utility, array $meters, string $today): array
    {
        $anchor = $this->anchor($utility);
        $ids = array_map(fn($m) => (string)($m['id'] ?? ''), $meters);
        $bills = self::billSpans(array_values(array_filter($this->bills->list($utility),
            fn($b) => in_array((string)($b['meter_id'] ?? ''), $ids, true))));
        [$start, $end] = $this->dataSpan($utility, $meters, $today);
        // v3.6.0 — Verträge, nach denen die Zähler rechnen: Wechsel teilen die
        // Perioden, ihr Beginn ordnet die Abschläge eines geteilten Monats zu
        $scope = [];
        foreach ($meters as $m) $scope[(string)$m['id']] = $this->consumption->contractScope($utility, $m)[0];
        $starts = $scope === [] ? [] : ConsumptionService::contractStarts(array_merge(...array_values($scope)));
        $switches = self::switches($scope);
        $periods = $start === null || $meters === [] ? [] : self::build($bills, $anchor, $start, $today, $switches);
        return ['anchor' => $anchor, 'bills' => $bills, 'start' => $start, 'end' => $end, 'starts' => $starts,
                'switches' => $switches, 'periods' => $periods];
    }

    /**
     * @param list<array<string,mixed>> $meters
     * @param list<array<string,mixed>> $money    v3.9.0 (F1032) — Zähler, von denen nur das Geld zählt (Subzähler mit eigenem Vertrag)
     * @param bool $measured  v3.9.0 (F1032) — Menge = Messung (Summe der Art) statt Menge der Verträge
     * @return array<string,mixed>
     */
    private function compute(string $utility, array $meters, bool $withMonths, ?string $today, array $money = [], bool $measured = false): array
    {
        $today ??= date('Y-m-d');
        ['anchor' => $anchor, 'bills' => $bills, 'start' => $start, 'end' => $end, 'starts' => $starts,
         'switches' => $switches, 'periods' => $periods] = $this->structure($utility, $meters, $today);
        // v3.9.0 (F1032) — die Verträge der Subzähler mit eigenem Vertrag ordnen nur ihre Abschläge zu
        foreach ($money as $m) $starts += ConsumptionService::contractStarts($this->consumption->contractScope($utility, $m)[0]);
        // v3.7.0 (#33) — ohne Rechnung ein Vorschlag aus den Vertragsbeginnen
        $suggested = self::suggestedAnchor($bills, $anchor);
        $source = $suggested !== null ? 'bill' : null;
        if ($bills === []) {
            $suggested = self::suggestedAnchorFromContracts(array_values($starts), $switches, $anchor);
            $source = $suggested !== null ? 'contracts' : null;
        }
        $out = ['utility' => $utility, 'anchor' => $anchor, 'suggested_anchor' => $suggested, 'suggested_anchor_source' => $source,
                'today' => $today, 'data_from' => $start, 'measured_until' => $end,
                'unit_key' => $utility === 'wasser' ? 'm3' : 'kwh', 'periods' => []];
        if ($start === null || $meters === []) return $out;
        // v3.11.0 (D, #54) — closedPeriods(): nur abgeschlossene Perioden ab dem Stichtag
        if ($this->closedSince !== null) {
            $since = $this->closedSince;
            $periods = array_values(array_filter($periods, fn($p) => !empty($p['complete']) && (string)$p['until'] >= $since && (string)$p['until'] < $today));
            $periods = array_slice($periods, -1);   // nur die jüngste
        }

        $monthly = [];
        foreach (array_merge($meters, $money) as $m) $monthly[(string)$m['id']] = $this->consumption->forMeter($utility, $m);
        $contracts = $this->carryingContracts($utility, array_merge($meters, $money));
        $this->money = $money;
        $this->measured = $measured;
        $billById = [];
        foreach ($this->bills->list($utility) as $b) $billById[(string)($b['id'] ?? '')] = $b;
        // v3.11.0 (D, #54) — was jeder Vertrag abrechnet, aus derselben Quelle wie die Vertragsliste
        $coverage = $this->consumption->contractsService()->coverageByTarget($contracts);
        $byId = [];
        foreach ($contracts as $c) $byId[(string)($c['id'] ?? '')] = $c;

        foreach ($periods as &$p) {
            // gerechnet bis zur letzten Ablesung (der Stand gehört zum Folgetag,
            // wie die Abschnitte von bill-check) — Kosten und Abschläge auf
            // derselben Grundlage, nicht Grundpreis bis heute und Menge bis März
            // v3.9.0 (Skeptiker H2/M13) — liegt die letzte Ablesung auf dem letzten Tag der Periode, ist sie
            // deren Endstand wie auf der Rechnung: die Periode ist ganz gerechnet (der eine Tag danach hat keinen
            // Verbrauch, ConsumptionService::genericBillRows), nicht bis zum Vortag
            $cap = (string)$end === $p['until'] ? self::addDays($p['until'], 1) : (string)$end;
            $toExcl = max($p['from'], min(self::addDays($p['until'], 1), $cap));
            $p += $this->sums($utility, $meters, $monthly, $p['from'], $toExcl, $starts);
            $p['as_of'] = $toExcl > $p['from'] ? self::addDays($toExcl, -1) : null;
            $sp = self::specialPayments($contracts, $p['from'], min($p['until'], $today));
            $p['special'] = $sp;
            // v3.7.0 (wirklichkeit-2) — ein Vertrag der Periode ohne Arbeitspreis: dieselbe
            // Aussage wie der Vertragsstatus (`price_missing`), statt eines anderen Saldos
            $p['price_missing'] = false;
            foreach ($contracts as $c) {
                $cEnd = $coverage[(string)($c['id'] ?? '')]['superseded_on'] ?? (string)($c['end'] ?? '');
                if ((string)($c['start'] ?? '9999') > $p['until'] || ($cEnd !== '' && $cEnd < $p['from'])) continue;
                if (ConsumptionService::lacksWorkingPrice($utility, $c)) { $p['price_missing'] = true; break; }
            }
            $p['balance'] = round($p['cost'] - $p['advances'] - $sp['extra_advances'], 2);
            // v3.11.0 (D, #54) — „angenommen“: Abschnitte, in denen ein Vertrag nur durch Fortschreibung
            // über sein Ende rechnet, während ein anderer laut Eintrag diese Zeit beansprucht (additiv)
            $p['assumed'] = self::assumedParts($coverage, $byId, $p['from'], $p['until']);
            // Abschläge mindestens ADVANCE_COST_FACTOR × Kosten in einer abgeschlossenen Periode (additiv, sonst null)
            $p['advance_vs_cost'] = self::advanceVsCost($p, $today);
            // v3.11.0 (Skeptiker N13) — der Vertrag, der die meisten Tage der Periode abrechnet (additiv)
            if ($p['advance_vs_cost'] !== null) $p['advance_vs_cost']['contract_id'] = self::mainContract($coverage, $p['from'], $p['until']);
            if ($p['bill_id'] !== null) {
                $inv = (array)($billById[$p['bill_id']]['invoice'] ?? []);
                $p['invoice'] = array_intersect_key($inv, array_flip(['energy_kwh', 'volume_m3', 'amount_eur', 'advances_paid_eur', 'result_eur']));
            }
            if ($withMonths) $p['months'] = $this->months($utility, $meters, $monthly, $p['from'], $toExcl, $starts);
        }
        unset($p);
        // v3.10.0 (GitHub #36) — die jüngste Periode mit Daten (die der Übersicht) gegen dieselbe
        // Spanne der Periode davor; dieselben Bausteine, solange money/measured noch gelten
        $last = null;
        foreach ($periods as $i => $p) if ((int)($p['days'] ?? 0) > 0) $last = $i;
        if ($last !== null && $this->closedSince === null) {
            $periods[$last]['prev_same_span'] = $this->sameSpan($utility, $meters, $monthly, $periods[$last], $periods[$last - 1] ?? null, $starts, $start);
        }
        $this->money = [];
        $this->measured = false;
        $out['periods'] = $periods;
        return $out;
    }

    /**
     * v3.11.0 (D, #54) — Fortgeschriebene Abschnitte der Periode, die ein anderer Vertrag laut Eintrag
     * beansprucht (coverage `claimed_by`): je Abschnitt `{contract_id, name, from, until, claimed_by,
     * claimed_name, claimed_until}`, Tage in der Periode. Die Rechnung bleibt (Entscheidung 11a); die
     * Ansicht schreibt „angenommen“ daneben.
     *
     * @param array<string,array<string,mixed>> $coverage ContractService::coverageByTarget
     * @param array<string,array<string,mixed>> $byId
     * @return list<array<string,mixed>>
     */
    private static function assumedParts(array $coverage, array $byId, string $from, string $until): array
    {
        $name = fn(?array $c): string => $c === null ? '' : implode(' · ', array_filter([(string)($c['provider'] ?? ''), (string)($c['tariff_name'] ?? '')], fn($s) => $s !== ''));
        $out = [];
        foreach ($coverage as $id => $cov) {
            $claim = $cov['claimed_by'] ?? null;
            if ($claim === null || $cov['assumed_from'] === null) continue;
            // nur der beanspruchte Teil der Fortschreibung, geschnitten mit der Periode
            $a = max((string)$cov['assumed_from'], $from);
            $b = min((string)$claim['until'], $until);
            if ($a > $b) continue;
            $out[] = ['contract_id' => (string)$id, 'name' => $name($byId[(string)$id] ?? null), 'from' => $a, 'until' => $b,
                      'claimed_by' => (string)$claim['contract_id'], 'claimed_name' => $name($byId[(string)$claim['contract_id']] ?? null),
                      'claimed_until' => (string)($byId[(string)$claim['contract_id']]['end'] ?? $claim['until'])];
        }
        return $out;
    }

    /**
     * v3.11.0 (D, #54) — Datenplausibilität, kein Rechtsbefund: In einer abgeschlossenen Periode
     * (vollständig, vorbei, mit Preisen und Kosten über 0) sind die Abschläge mindestens
     * ContractService::ADVANCE_COST_FACTOR-mal so hoch wie die Kosten — so sah das Abrechnungsjahr aus,
     * wenn ein Vertrag mit fremdem Tarif Jahre über sein Ende fortgeschrieben wurde (Skeptiker M6).
     * `{factor}` (eine Nachkommastelle) oder null.
     */
    private static function advanceVsCost(array $p, string $today): ?array
    {
        if (empty($p['complete']) || !empty($p['running']) || (string)$p['until'] >= $today || !empty($p['price_missing'])) return null;
        $cost = (float)($p['cost'] ?? 0);
        $adv = (float)($p['advances'] ?? 0);
        if ($cost <= 0 || $adv < ContractService::ADVANCE_COST_FACTOR * $cost) return null;
        return ['factor' => round($adv / $cost, 1)];
    }

    /**
     * v3.11.0 (Skeptiker N13) — der Vertrag mit den meisten Tagen in [von, bis] nach coverage(); null ohne.
     *
     * @param array<string,array<string,mixed>> $coverage
     */
    private static function mainContract(array $coverage, string $from, string $until): ?string
    {
        $best = null; $bestDays = 0;
        foreach ($coverage as $id => $cov) {
            if (($cov['from'] ?? null) === null) continue;
            $a = max($from, (string)$cov['from']);
            $b = $cov['to'] === null ? $until : min($until, (string)$cov['to']);
            if ($a > $b) continue;
            $days = (int)round((strtotime($b) - strtotime($a)) / 86400) + 1;
            if ($days > $bestDays) { $bestDays = $days; $best = (string)$id; }
        }
        return $best;
    }

    /** @var list<array<string,mixed>> v3.9.0 (F1032) — Zähler der laufenden Rechnung, von denen nur das Geld zählt */
    private array $money = [];
    /** v3.9.0 (F1032) — Menge der laufenden Rechnung als Messung (Summe der Art) */
    private bool $measured = false;

    /**
     * Die Perioden ohne Summen (rein, testbar).
     *
     * v3.6.0 — `$switches` (Lieferantenwechsel, {@see switches()}) teilen die
     * Stichtag-Perioden: Am Tag nach `until` endet der alte Versorger, an
     * `next` beginnt der neue; eine Periode, die an einem Wechsel endet oder
     * mit dem Folgevertrag beginnt, ist so vollständig wie eine ganze
     * Stichtag-Periode. Eine Lücke dazwischen (kein Vertrag) ist eine
     * Teilperiode. Rechnungen teilt kein Wechsel. Nach einem Wechsel in der
     * Zukunft endet die laufende Periode am Wechsel.
     *
     * @param list<array{id:string,from:string,until:string}> $bills sortiert, überlappungsfrei (billSpans)
     * @param list<array{until:string,next:string}> $switches
     * @return list<array{from:string,until:string,source:string,bill_id:?string,complete:bool,running:bool,label:?string,ends_at_switch:bool}>
     */
    public static function build(array $bills, string $anchor, string $start, string $today, array $switches = []): array
    {
        $anchor = self::normalizeAnchor($anchor);
        // Tage, an denen eine Periode beginnt: `end` = Tag nach dem letzten
        // Liefertag beim alten Versorger, `start` = Beginn des Folgevertrags
        $cuts = [];
        foreach ($switches as $s) {
            $cuts[self::addDays((string)$s['until'], 1)]['end'] = true;
            $cuts[(string)$s['next']]['start'] = true;
        }
        ksort($cuts);
        $out = [];
        $cursor = $start;
        // Stichtag-Perioden für [a, b], an den Stichtagen und Wechseln geteilt
        $fill = function (string $a, string $b, bool $last = false) use (&$out, $anchor, $today, $cuts): void {
            for ($p = $a; $p <= $b;) {
                if ($last && $p > $today) break;   // nach einem künftigen Wechsel: noch keine Periode
                // v3.9.0 (Skeptiker M7) — Lieferzeit zwischen zwei Lieferantenwechseln bis
                // SWITCH_PERIOD_MAX_DAYS: eine Periode bis zum Vertragsende, wie die Schlussrechnung
                // des Versorgers. Bis dahin teilte ein Stichtag mitten in ihr die Lieferzeit (Stichtag
                // einen Monat vor dem Wechseltag: Stummel, „nächste Abrechnung“ einen Monat zu früh)
                if (!empty($cuts[$p]['start'])) {
                    $endCut = null;
                    foreach ($cuts as $day => $f) if ($day > $p && !empty($f['end'])) { $endCut = (string)$day; break; }
                    if ($endCut !== null && self::days($p, $endCut) <= self::SWITCH_PERIOD_MAX_DAYS && ($endCut <= self::addDays($b, 1) || $last)) {
                        $until = self::addDays($endCut, -1);
                        $out[] = ['from' => $p, 'until' => $until, 'source' => 'anchor', 'bill_id' => null,
                                  'complete' => $until < $today, 'running' => $until >= $today,
                                  'label' => self::yearLabel($p, $until), 'ends_at_switch' => true];
                        $p = $endCut;
                        continue;
                    }
                }
                [$gs, $ge] = self::gridAround($anchor, $p);
                $until = min($ge, $b);
                foreach ($cuts as $day => $_) {
                    if ($day > $p && $day <= $until) { $until = self::addDays((string)$day, -1); break; }
                }
                $next = self::addDays($until, 1);
                $atSwitch = !empty($cuts[$next]['end']);
                $whole = $p === $gs && $until === $ge;
                $out[] = ['from' => $p, 'until' => $until, 'source' => 'anchor', 'bill_id' => null,
                          'complete' => ($p === $gs || !empty($cuts[$p]['start'])) && ($until === $ge || $atSwitch) && $until < $today,
                          'running' => $until >= $today,
                          'label' => $whole ? self::yearLabel($p, $until) : null, 'ends_at_switch' => $atSwitch];
                $p = $next;
            }
        };
        foreach ($bills as $b) {
            if ($b['until'] < $cursor) continue;                 // vor den Daten bzw. überdeckt
            if ($b['from'] < $cursor && $out !== []) continue;   // überlappt die vorige Periode
            if ($b['from'] > $cursor) $fill($cursor, self::addDays($b['from'], -1));
            $out[] = ['from' => $b['from'], 'until' => $b['until'], 'source' => 'bill', 'bill_id' => $b['id'],
                      // Rechnung vor der ersten Ablesung: die Daten decken sie nicht ganz
                      'complete' => $b['from'] >= $start && $b['until'] < $today, 'running' => $b['until'] >= $today,
                      'label' => self::yearLabel($b['from'], $b['until']),
                      'ends_at_switch' => !empty($cuts[self::addDays($b['until'], 1)]['end'])];
            $cursor = self::addDays($b['until'], 1);
        }
        if ($cursor <= $today) $fill($cursor, self::gridAround($anchor, $today)[1], true);
        return self::mergeShortRests($out, $anchor, $cuts, $today);
    }

    /**
     * v3.7.0 (GitHub #33) — Kurzreste in die Nachbarperiode: Ein Stück der
     * Stichtag-Perioden unter SHORT_REST_DAYS Tagen, das auf der einen Seite
     * am Stichtag und auf der anderen an einem Wechsel (bzw. einer Lücke ohne
     * Vertrag) liegt, geht über den Stichtag hinweg in das Nachbarstück auf:
     *   [Stichtag, Wechsel]       → an die Periode davor (Ende beim alten Versorger)
     *   [Folgevertrag, Stichtag)  → an die Periode danach; ist sie noch nicht
     *                               angebrochen (heute liegt im Rest), läuft die
     *                               laufende Periode bis zum übernächsten Stichtag
     * Nie über einen Wechsel, nie mit einer Rechnung, nie am Beginn der Daten.
     * „complete“ und der Name folgen den neuen Grenzen.
     *
     * @param list<array<string,mixed>> $out
     * @param array<string,array<string,bool>> $cuts
     * @return list<array<string,mixed>>
     */
    private static function mergeShortRests(array $out, string $anchor, array $cuts, string $today): array
    {
        if ($cuts === []) return $out;
        $isCut = fn(string $d): bool => isset($cuts[$d]);
        $gridStart = fn(string $d): bool => self::gridAround($anchor, $d)[0] === $d;
        $gridEnd = fn(string $d): bool => self::gridAround($anchor, $d)[1] === $d;
        $finish = function (array $p) use ($cuts, $today, $gridStart, $gridEnd): array {
            $p['ends_at_switch'] = !empty($cuts[self::addDays($p['until'], 1)]['end']);
            $p['complete'] = ($gridStart($p['from']) || !empty($cuts[$p['from']]['start']))
                && ($gridEnd($p['until']) || $p['ends_at_switch']) && $p['until'] < $today;
            $p['running'] = $p['until'] >= $today;
            $p['label'] = self::yearLabel($p['from'], $p['until']);
            return $p;
        };
        $short = fn(array $p): bool => $p['source'] === 'anchor' && self::days($p['from'], self::addDays($p['until'], 1)) < self::SHORT_REST_DAYS;
        for ($i = 0; $i < count($out); $i++) {
            $p = $out[$i];
            if (!$short($p)) continue;
            $next = self::addDays($p['until'], 1);
            // [Stichtag, Wechsel]: an die Periode davor
            if ($gridStart($p['from']) && !$isCut($p['from']) && $isCut($next) && $i > 0
                && $out[$i - 1]['source'] === 'anchor' && self::addDays($out[$i - 1]['until'], 1) === $p['from']) {
                $out[$i - 1] = $finish(['until' => $p['until']] + $out[$i - 1]);
                array_splice($out, $i, 1);
                $i--;
                continue;
            }
            // [Folgevertrag, Stichtag): an die Periode danach
            if ($isCut($p['from']) && $gridEnd($p['until']) && !$isCut($next)) {
                if (isset($out[$i + 1]) && $out[$i + 1]['source'] === 'anchor' && $out[$i + 1]['from'] === $next) {
                    $out[$i] = $finish(['until' => $out[$i + 1]['until']] + $p);
                    array_splice($out, $i + 1, 1);
                } elseif (!isset($out[$i + 1]) && $p['until'] >= $today) {
                    // die laufende Periode: bis zum übernächsten Stichtag, höchstens bis zu einem künftigen Wechsel
                    $until = self::gridAround($anchor, $next)[1];
                    foreach ($cuts as $day => $_) {
                        if ($day > $next && $day <= $until) { $until = self::addDays((string)$day, -1); break; }
                    }
                    $out[$i] = $finish(['until' => $until] + $p);
                }
            }
        }
        return $out;
    }

    /**
     * v3.6.0 — Lieferantenwechsel der Zähler (ContractService::supplierSwitchAfter),
     * je Zähler über die Verträge, nach denen er rechnet (eigene und der
     * Gruppenvertrag); mehrere Zähler: alle Wechsel, wie ihre Rechnungen.
     *
     * @param array<string,list<array<string,mixed>>> $scope Verträge je Zähler
     * @return list<array{until:string,next:string}>
     */
    public static function switches(array $scope): array
    {
        $out = [];
        foreach ($scope as $list) {
            foreach ($list as $c) {
                $s = ContractService::supplierSwitchAfter($c, $list);
                if ($s !== null) $out[$s['until'] . '|' . $s['next']] = ['until' => $s['until'], 'next' => $s['next']];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * Rechnungen als Zeitspannen: gültige Daten, nach Beginn sortiert (bei
     * gleichem Beginn die längere zuerst), Überlappungen raus — die frühere gilt.
     *
     * @param list<array<string,mixed>> $bills
     * @return list<array{id:string,from:string,until:string}>
     */
    public static function billSpans(array $bills): array
    {
        $spans = [];
        foreach ($bills as $b) {
            $f = (string)($b['period_from'] ?? ''); $u = (string)($b['period_to'] ?? '');
            if (!Dates::isIsoDate($f) || !Dates::isIsoDate($u) || $f > $u) continue;
            $spans[] = ['id' => (string)($b['id'] ?? ''), 'from' => $f, 'until' => $u];
        }
        usort($spans, fn($a, $b) => [$a['from'], $b['until']] <=> [$b['from'], $a['until']]);
        $out = []; $last = '';
        foreach ($spans as $s) {
            if ($last !== '' && $s['from'] <= $last) continue;
            $out[] = $s; $last = $s['until'];
        }
        return $out;
    }

    /** Passt der Stichtag nicht zur letzten Rechnung, der Tag nach ihrem Ende (MM-TT) — sonst null. */
    public static function suggestedAnchor(array $bills, string $anchor): ?string
    {
        if ($bills === []) return null;
        $next = substr(self::addDays($bills[count($bills) - 1]['until'], 1), 5);
        $next = self::normalizeAnchor($next);
        return $next === self::normalizeAnchor($anchor) ? null : $next;
    }

    /**
     * v3.7.0 (GitHub #33) — Vorschlag ohne Rechnung, aus den Verträgen (MM-TT):
     *   1. der Beginn des Folgevertrags beim jüngsten Lieferantenwechsel — der
     *      jetzige Versorger rechnet ab Lieferbeginn im Jahresrhythmus;
     *   2. sonst der Tag im Jahr, an dem mindestens zwei Verträge beginnen (der
     *      häufigste; bei Gleichstand der des jüngsten Beginns) — wer jedes
     *      Jahr zum 01.09. verlängert, rechnet vom 1.9. bis 31.8. ab.
     * Null, wenn der Vorschlag der Stichtag schon ist oder nichts dafür spricht.
     *
     * @param list<string> $starts Vertragsbeginne (JJJJ-MM-TT), je Vertrag einer
     * @param list<array{until:string,next:string}> $switches aufsteigend ({@see switches()})
     */
    public static function suggestedAnchorFromContracts(array $starts, array $switches, string $anchor): ?string
    {
        $byDay = [];
        foreach ($starts as $s) {
            if (!Dates::isIsoDate($s)) continue;
            $md = self::normalizeAnchor(substr($s, 5));
            $byDay[$md] = ['n' => ($byDay[$md]['n'] ?? 0) + 1, 'last' => max($byDay[$md]['last'] ?? '', $s)];
        }
        uasort($byDay, fn($a, $b) => [$b['n'], $b['last']] <=> [$a['n'], $a['last']]);
        $best = array_key_first($byDay);
        $pick = null;
        if ($switches !== []) {
            usort($switches, fn($a, $b) => (string)$a['next'] <=> (string)$b['next']);
            $pick = self::normalizeAnchor(substr((string)end($switches)['next'], 5));
        } elseif ($best !== null && $byDay[$best]['n'] >= 2) {
            $pick = (string)$best;
        }
        return $pick === null || $pick === self::normalizeAnchor($anchor) ? null : $pick;
    }

    /** Name einer ganzen Jahresperiode: „2025“ (Kalenderjahr) oder „2025/26“; sonst null (der Browser nennt die Daten). */
    public static function yearLabel(string $from, string $until): ?string
    {
        if (self::addDays(date('Y-m-d', (int)strtotime("$from +1 year")), -1) !== $until
            && self::gridAround(substr($from, 5), $from)[1] !== $until) return null;
        $y = (int)substr($from, 0, 4);
        return substr($from, 5) === '01-01' ? (string)$y : sprintf('%d/%02d', $y, ($y + 1) % 100);
    }

    /** Periode des Stichtag-Rasters um ein Datum: [Beginn, Ende einschließlich]. */
    public static function gridAround(string $anchor, string $date): array
    {
        $anchor = self::normalizeAnchor($anchor);
        $y = (int)substr($date, 0, 4);
        $start = "$y-$anchor";
        if ($start > $date) $start = ($y - 1) . "-$anchor";
        $next = ((int)substr($start, 0, 4) + 1) . "-$anchor";
        return [$start, self::addDays($next, -1)];
    }

    /**
     * Daten von–bis: erste Ablesung, frühestens die wirksame Zäsur (die späteste
     * der Zähler); letzte Ablesung bis heute. [null, null] ohne Ablesung.
     *
     * @return array{0:?string,1:?string}
     */
    private function dataSpan(string $utility, array $meters, string $today): array
    {
        $first = null; $last = null; $baseline = null;
        foreach ($meters as $m) {
            foreach ($this->readings->list($utility, (string)$m['id']) as $r) {
                $d = (string)($r['date'] ?? '');
                if (!empty($r['is_future']) || !empty($r['is_suspect']) || !Dates::isIsoDate($d) || $d > $today) continue;
                if ($first === null || $d < $first) $first = $d;
                if ($last === null || $d > $last) $last = $d;
            }
            $b = MeterService::activeBaselineDate($m, $today);
            if ($b !== null && ($baseline === null || $b > $baseline)) $baseline = $b;
        }
        if ($first === null) return [null, null];
        return [$baseline !== null && $baseline > $first ? $baseline : $first, $last];
    }

    /**
     * Verträge, die Abschlag und Sonderzahlungen tragen (im Gruppenvertrag nur
     * das erste Mitglied, wie ConsumptionService::carriesFixed), je einmal.
     *
     * @return list<array<string,mixed>>
     */
    private function carryingContracts(string $utility, array $meters): array
    {
        if (!Utilities::hasSpecialPayments($utility)) return [];
        $out = [];
        foreach ($meters as $m) {
            [$list, $ctx] = $this->consumption->contractScope($utility, $m);
            foreach ($list as $c) {
                if (!empty($c['meter_group_id']) && $ctx !== null && !$ctx['primary']) continue;
                $out[(string)($c['id'] ?? '')] = $c;
            }
        }
        return array_values($out);
    }

    /** @return array{extra_advances:float,refunds:float,surcharges:float} Sonderzahlungen mit Datum in [from, until] */
    private static function specialPayments(array $contracts, string $from, string $until): array
    {
        $s = ['extra_advances' => 0.0, 'refunds' => 0.0, 'surcharges' => 0.0];
        foreach ($contracts as $c) {
            foreach ($c['special_payments'] ?? [] as $sp) {
                $d = (string)($sp['date'] ?? '');
                if ($d < $from || $d > $until) continue;
                $amt = abs((float)($sp['amount_eur'] ?? 0));
                $kind = (string)($sp['kind'] ?? '');
                if ($kind === 'abschlagszahlung') $s['extra_advances'] += $amt;
                elseif (str_starts_with($kind, 'rueckzahlung')) $s['refunds'] += $amt;
                elseif (str_starts_with($kind, 'nachzahlung')) $s['surcharges'] += $amt;
            }
        }
        return array_map(fn($v) => round($v, 2), $s);
    }

    /**
     * v3.10.0 (GitHub #36) — Vergleich im Abrechnungsjahr: dieselbe Spanne der
     * Periode davor, gleich viele Tage ab ihrem Beginn. Die laufende Periode zählt
     * bis zur letzten Ablesung (`as_of`), verglichen wird also mit denselben Tagen
     * ein Abrechnungsjahr früher, nicht mit dem ganzen Vorjahr. Ist die Periode bis
     * zu ihrem Ende gerechnet, steht die ganze Periode davor gegenüber.
     *
     * Fehlt dieser faire Vergleich, weil es keine Periode davor gibt, weil sie
     * nur ein Teil ist (Beginn der Daten), weil sie nicht etwa ein Jahr früher
     * beginnt (SAME_SPAN_ALIGN_DAYS, etwa nach einem Lieferantenwechsel mitten
     * im Jahr) oder weil sie kürzer ist als die Spanne, vergleicht der Pfeil
     * dieselben Kalendertage ein Jahr früher (`basis` = `calendar`, sonst
     * `period`), unabhängig von Perioden und Lieferant. Kein Vergleich (null),
     * wenn die Spanne kürzer ist als SAME_SPAN_MIN_DAYS oder die Daten erst nach
     * dem Beginn dieser Kalendertage anfangen.
     *
     * @param array<string,mixed>      $p
     * @param array<string,mixed>|null $prev
     * @param array<string,string>     $starts
     * @param string|null              $dataFrom erster Tag mit Daten (nach einer Zäsur deren Tag)
     * @return array<string,mixed>|null von, bis (einschließlich), Tage, ganz?, Grundlage, Menge, Kosten, Bonus, Preis fehlt
     */
    private function sameSpan(string $utility, array $meters, array $monthly, array $p, ?array $prev, array $starts, ?string $dataFrom): ?array
    {
        $n = (int)($p['days'] ?? 0);
        if ($p['as_of'] === null || $n < self::SAME_SPAN_MIN_DAYS) return null;
        $whole = $p['as_of'] >= $p['until'];
        $from = null; $toExcl = null; $basis = 'period';
        if ($prev !== null && !empty($prev['complete'])
            && abs(self::days(self::yearEarlier((string)$p['from']), (string)$prev['from'])) <= self::SAME_SPAN_ALIGN_DAYS) {
            $prevEnd = self::addDays((string)$prev['until'], 1);
            if ($whole || self::days((string)$prev['from'], $prevEnd) >= $n) {
                $from = (string)$prev['from'];
                $toExcl = $whole ? $prevEnd : self::addDays($from, $n);
            }
        }
        if ($from === null) {
            // v3.10.0 (Nachtrag #36) — Rückfall: dieselben Kalendertage ein Jahr früher
            $from = self::yearEarlier((string)$p['from']);
            if ($dataFrom === null || $dataFrom > $from) return null;
            $toExcl = self::addDays(self::yearEarlier((string)($whole ? $p['until'] : $p['as_of'])), 1);
            $basis = 'calendar';
        }
        $s = $this->sums($utility, $meters, $monthly, $from, $toExcl, $starts);
        $qk = $utility === 'wasser' ? 'm3' : 'kwh';
        return ['from' => $from, 'until' => self::addDays($toExcl, -1), 'whole' => $whole, 'basis' => $basis]
            + array_intersect_key($s, array_flip(['days', $qk, 'measured_kwh', 'm3', 'cost', 'bonus', 'price_missing']));
    }

    /**
     * v3.10.0 (Nachtrag #36) — Dasselbe Kalenderdatum ein Jahr früher. Den
     * 29. Februar gibt es dann nicht: Er wird zum 28. Februar (PHPs
     * `modify('-1 year')` machte daraus den 1. März, Lektion 26). Eine Spanne
     * über den 29. Februar ist ein Jahr früher deshalb einen Tag kürzer, eine
     * Spanne über den 28. Februar eines Schaltjahrs davor einen Tag länger:
     * verglichen werden dieselben Kalendertage.
     */
    public static function yearEarlier(string $date): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        $y--;
        $d = min($d, (int)date('t', mktime(12, 0, 0, $m, 1, $y)));
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /**
     * Summen für [from, toExcl): Menge und Kosten über billBreakdown je Zähler,
     * Abschläge und CO₂ tagesanteilig aus den Monatszeilen — der Abschlag
     * eines Monats mit zwei Verträgen je Vertrag nach seinen Tagen
     * (ConsumptionService::advanceBetween, wie „Rechnung prüfen“).
     *
     * @param array<string,string> $starts Vertragsbeginn je ID
     * @return array<string,mixed>
     */
    private function sums(string $utility, array $meters, array $monthly, string $from, string $toExcl, array $starts = []): array
    {
        $qk = $utility === 'wasser' ? 'm3' : 'kwh';
        $t = ['days' => max(0, self::days($from, $toExcl)), $qk => 0.0, 'energy_cost' => 0.0, 'fixed_cost' => 0.0,
              'bonus' => 0.0, 'cost' => 0.0, 'advances' => 0.0, 'co2_kg' => 0.0, 'gaps' => 0, 'price_missing' => false,
              'bonus_items' => []];
        if ($utility === 'gas') $t['m3'] = 0.0;
        // v3.9.0 (F1032, Skeptiker M9) — neben der Menge der Verträge die Messung (bei der
        // Rest-Abrechnung am Elternzähler verschieden): Verbrauch = Messung, abgerechnet = Rest
        if ($qk === 'kwh') $t['measured_kwh'] = 0.0;
        if ($toExcl <= $from) return $t;
        $moneyIds = array_map(fn($m) => (string)($m['id'] ?? ''), $this->money);
        foreach (array_merge($meters, $this->money) as $m) {
            $bt = $this->consumption->billBreakdown($utility, $m, $from, $toExcl)['totals'] ?? [];
            // v3.9.0 (F1032) — von Subzählern mit eigenem Vertrag nur das Geld; die Summe der Art
            // zählt die Messung (`measured_kwh`), ein Zähler oder eine Gruppe die Menge der Verträge
            $moneyOnly = in_array((string)($m['id'] ?? ''), $moneyIds, true);
            if (!$moneyOnly) {
                $t[$qk] += (float)(($this->measured ? ($bt['measured_kwh'] ?? null) : null) ?? $bt[$qk] ?? 0);
                if ($qk === 'kwh') $t['measured_kwh'] += (float)($bt['measured_kwh'] ?? $bt[$qk] ?? 0);
                if ($utility === 'gas') $t['m3'] += (float)($bt['m3'] ?? 0);
            }
            foreach (['energy_cost', 'fixed_cost', 'bonus'] as $k) $t[$k] += (float)($bt[$k] ?? 0);
            // v3.7.0 (#33) — die Boni einzeln für „Bonus −X € am …“ unter der Kachel
            $t['bonus_items'] = array_merge($t['bonus_items'], (array)($bt['bonus_items'] ?? []));
            $t['cost'] += (float)($bt['total'] ?? 0);
            $t['gaps'] += (int)($bt['gaps'] ?? 0);
            $t['price_missing'] = $t['price_missing'] || !empty($bt['price_missing']);
            foreach ($monthly[(string)$m['id']] ?? [] as $row) {
                $ms = (string)($row['ym'] ?? '') . '-01';
                if (!Dates::isIsoDate($ms)) continue;
                $me = date('Y-m-d', (int)strtotime("$ms +1 month"));
                $a = max($ms, $from); $b = min($me, $toExcl);
                if ($a >= $b) continue;
                $share = self::days($a, $b) / (int)date('t', (int)strtotime($ms));
                $t['advances'] += ConsumptionService::advanceBetween($row, $from, $toExcl, $starts);
                if (!$moneyOnly) $t['co2_kg'] += (float)($row['co2_kg'] ?? 0) * $share;
            }
        }
        foreach (['energy_cost', 'fixed_cost', 'bonus', 'cost', 'advances'] as $k) $t[$k] = round($t[$k], 2);
        $t[$qk] = round($t[$qk], $qk === 'm3' ? 2 : 1);
        if ($qk === 'kwh') $t['measured_kwh'] = round($t['measured_kwh'], 1);
        if ($utility === 'gas') $t['m3'] = round($t['m3'], 2);
        $t['co2_kg'] = round($t['co2_kg'], 1);
        return $t;
    }

    /**
     * Monatsreihe einer Periode: Kalendermonate, der erste und letzte als
     * Teilmonat ab bzw. bis zur Grenze. Felder wie die Monatszeilen der
     * Verbrauchsansicht (`kwh`/`m3`, `cost`, `kwh_cost`, `base_price_eur`,
     * `advance_eur`, `co2_kg`, `days`).
     *
     * @return list<array<string,mixed>>
     */
    private function months(string $utility, array $meters, array $monthly, string $from, string $toExcl, array $starts = []): array
    {
        $out = [];
        for ($p = $from; $p < $toExcl;) {
            $next = min($toExcl, date('Y-m-01', (int)strtotime(substr($p, 0, 7) . '-01 +1 month')));
            $s = $this->sums($utility, $meters, $monthly, $p, $next, $starts);
            $ym = substr($p, 0, 7);
            $row = ['ym' => $ym, 'year' => (int)substr($p, 0, 4), 'month' => (int)substr($p, 5, 2),
                    'from' => $p, 'until' => self::addDays($next, -1), 'days' => $s['days'],
                    'cost' => $s['cost'], 'kwh_cost' => $s['energy_cost'], 'base_price_eur' => $s['fixed_cost'],
                    'bonus_eur' => $s['bonus'], 'advance_eur' => $s['advances'], 'co2_kg' => $s['co2_kg']];
            if ($utility === 'wasser') $row['m3'] = $s['m3'];
            else { $row['kwh'] = $s['kwh']; $row['measured_kwh'] = $s['measured_kwh']; }
            if ($utility === 'gas') $row['m3'] = $s['m3'];
            $out[] = $row;
            $p = $next;
        }
        return $out;
    }

    private static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Tage zwischen zwei Daten (b ausschließlich), ohne Sommerzeitsprung. */
    private static function days(string $a, string $b): int
    {
        return (int)round(((new \DateTimeImmutable($b . ' 12:00'))->getTimestamp() - (new \DateTimeImmutable($a . ' 12:00'))->getTimestamp()) / 86400);
    }
}
