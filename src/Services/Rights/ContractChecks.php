<?php
declare(strict_types=1);

namespace Energietracker\Services\Rights;

use Energietracker\Config\Rights;
use Energietracker\Config\Utilities;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\I18nService;
use Energietracker\Services\MeterService;
use Energietracker\Support\Dates;

/**
 * v3.11.0 (F1035, Paket vertrag) — die Prüfungen am Vertrag (Konzept §3):
 *
 *   advance_high         (A2) Der Abschlag liegt deutlich über dem, was der Verbrauch des zuletzt
 *                        abgerechneten Zeitraums mal die Preise am nächsten Fälligkeitstag ergibt
 *                        (§ 13 StromGVV/GasGVV, § 41b Abs. 3 EnWG, § 25 AVBFernwärmeV), und die
 *                        Prognose der App sieht es genauso. Brief: Antrag auf Abschlagsanpassung.
 *   special_termination  (A3) Angekündigte Preiserhöhung bei Strom und Gas: Sonderkündigung zum Tag
 *                        der Änderung (§ 41 Abs. 5 EnWG bzw. § 5 Abs. 3 StromGVV/GasGVV) als Termin.
 *                        Brief: Sonderkündigung.
 *
 * Beide liefern Rohbefunde für RightsService (Form: umsetzung-basis.md). Sie rufen weder
 * RecommendationService noch AgendaService (Kreis). A2 rechnet den Vertragsstatus nur für Zähler,
 * deren laufender Vertrag einen Abschlag und einen Arbeitspreis hat, und die Abrechnungsperioden
 * nur, wenn die Prognose den Abschlag schon für zu hoch hält (teuer, selten).
 */
final class ContractChecks
{
    /**
     * A2 — Verbrauchsarten mit Abschlagsprüfung. Wasser nicht: Ob die Versorgung privatrechtlich
     * (§ 25 AVBWasserV) oder per Gebührensatzung läuft, weiß die App nicht (Entscheidung 5).
     */
    public const ADVANCE_UTILITIES = ['strom', 'gas', 'fernwaerme'];

    /** A2 — Normen je Verbrauchsart und Versorgungsart (gefiltert über Rights::applicable). */
    private const ADVANCE_RULES = ['de.stromgvv.13', 'de.gasgvv.13', 'de.enwg.41b.3', 'de.avbfernwaermev.25'];

    public function __construct(
        private ContractService $contracts,
        private MeterService $meters,
        private ConsumptionService $consumption,
        private BillService $bills,
        private BillingPeriodService $periods,
        private I18nService $i18n,
    ) {}

    // ── A2 Abschlag deutlich zu hoch ────────────────────────────────────────

    /** @return list<array<string,mixed>> Rohbefunde */
    public function advanceHigh(string $today, string $country): array
    {
        $out = [];
        foreach (self::ADVANCE_UTILITIES as $u) {
            if (!Utilities::exists($u)) continue;
            foreach ($this->meters->list($u) as $meter) {
                if (($meter['active'] ?? true) === false || !is_string($meter['id'] ?? null)) continue;
                $f = $this->advanceFinding($u, $meter, $today, $country);
                if ($f !== null) $out[] = $f;
            }
        }
        return $out;
    }

    /** A2 für einen Zähler (ohne Gruppenverträge: Arbeitspreise je Mitglied rechnet die Prüfung nicht). */
    private function advanceFinding(string $u, array $meter, string $today, string $country): ?array
    {
        $mine = array_values(array_filter($this->contracts->list($u, (string)$meter['id']),
            fn($c) => empty($c['is_shadow']) && empty($c['meter_group_id'])));
        // v3.11.0 (Skeptiker H1) — der Vertrag, nach dem heute abgerechnet wird, auch verlängert ohne
        // Kündigung (resolveForDate, dieselbe Regel wie coverage()); findActiveForDate kennt keine Verlängerung
        $c = $this->contracts->resolveForDate($mine, $today)['contract'] ?? null;
        if ($c === null || ($c['price_model'] ?? null) === 'dynamic' || ConsumptionService::lacksWorkingPrice($u, $c)) return null;
        $rate = self::nextRate($this->contracts->effectiveAdvanceSchedule($c), $today);
        if ($rate === null) return null;

        // zweite Sicht zuerst: die Prognose (Vertragsstatus) muss den Abschlag ebenfalls für zu hoch halten
        $row = null;
        foreach ($this->consumption->contractStatus($u, $meter, $today)['contracts'] ?? [] as $r) {
            if (($r['contract_id'] ?? null) === ($c['id'] ?? null) && !empty($r['is_current'])) $row = $r;
        }
        $ongoing = $row['advance_ongoing'] ?? null;
        if ($row === null || !is_numeric($ongoing) || ($row['projection_uncertain'] ?? null) !== null
            || (int)($row['months_left'] ?? 0) < ConsumptionService::SUGGEST_MIN_MONTHS
            || !self::tooHigh($rate['amount_eur'], (float)$ongoing)) return null;

        // gesetzliche Sicht: Verbrauch des zuletzt abgerechneten Zeitraums × Preise am nächsten Fälligkeitstag
        $basis = $this->lastBilledPeriod($u, $meter, $today);
        if ($basis === null) return null;
        $annual = $this->annualCost($c, $basis['qty'] * 365 / $basis['days'], $rate['due']);
        if ($annual === null) return null;
        $measure = $annual / $rate['per_year'];
        if (!self::tooHigh($rate['amount_eur'], $measure)) return null;

        $kind = in_array($c['supply_kind'] ?? null, Rights::SUPPLY_KINDS, true) ? (string)$c['supply_kind'] : null;
        $rules = self::advanceRules($country, $u, $kind);
        if ($rules === []) return null;
        $norm = implode(', ', array_map(fn($k) => (string)Rights::rule($country, $k)['norm'], $rules));
        $over = (int)round(($rate['amount_eur'] / $measure - 1) * 100);
        $unit = (string)(Utilities::get($u)['consumption_unit'] ?? 'kWh');
        // v3.11.0 (Skeptiker M6) — gelten am Fälligkeitstag andere Preise als heute, sagen Text und Brief
        // „mit den Preisen ab …“ statt „mit den aktuellen Preisen“ (gerechnet wird ohnehin mit denen des Tags)
        $pricesChange = false;
        foreach ([['working_prices', 'ct_per_kwh'], ['base_prices', 'eur_per_month']] as [$list, $field]) {
            $now = $this->contracts->valueOnDate($c[$list] ?? [], $field, $today);
            $then = $this->contracts->valueOnDate($c[$list] ?? [], $field, $rate['due']);
            if ($then !== null && ($now === null || abs($now - $then) > 1e-9)) $pricesChange = true;
        }
        // Namen der Fakten wie die Vorlage „Antrag auf Abschlagsanpassung“ (Paket briefe) sie liest;
        // `assessment_eur` = Bemessung je Rate auf volle Euro aufgerundet (der Betrag, den der Brief beantragt)
        $facts = [
            'provider' => (string)($c['provider'] ?? ''), 'rate_eur' => $rate['amount_eur'], 'rate_from' => $rate['from'],
            'next_due' => $rate['due'], 'every_months' => $rate['every_months'], 'per_year' => $rate['per_year'],
            'measure_eur' => round($measure, 2), 'assessment_eur' => (float)ceil(round($measure, 2)),
            'working_price_ct' => $this->contracts->valueOnDate($c['working_prices'] ?? [], 'ct_per_kwh', $rate['due']),
            'base_price_month_eur' => ($fixed = $this->contracts->fixedPerMonthOn($c, $rate['due'])) !== null ? round($fixed, 2) : null,
            'annual_cost_eur' => round($annual, 2), 'over_pct' => $over, 'ongoing_eur' => (float)$ongoing,
            'quantity' => round($basis['qty'], 1), 'unit' => $unit, 'period_from' => $basis['from'], 'period_to' => $basis['to'],
            'basis' => $basis['source'], 'bill_id' => $basis['bill_id'], 'supply_kind' => $kind,
            'contract_number' => $c['contract_number'] ?? null, 'customer_number' => $c['customer_number'] ?? null,
            'prices_change' => $pricesChange,
        ];
        return [
            'severity' => 'info', 'utility' => $u, 'meter_id' => (string)$meter['id'], 'contract_id' => (string)$c['id'],
            // Anlass: die Abschlagszeile oder der Zeitraum, an dem sich die Bemessung zuletzt änderte
            'date' => max($rate['from'], $basis['known_on']),
            'amount_eur' => round($rate['amount_eur'] - $measure, 2),
            'facts' => $facts,
            'params' => [
                'label' => $this->i18n->utilityLabel($u), 'rate_eur' => $rate['amount_eur'],
                // v3.11.0 (Nachprüfung, klein) — gilt der Abschlag erst ab einem späteren Tag (etwa aus einem
                // übernommenen Preisänderungsschreiben), sagt der Text „von … ab dem …“
                'rate' => $rate['from'] > $today
                    ? $this->i18n->t('rights.advance_high.rateFrom', ['amount' => $this->i18n->money((float)$rate['amount_eur']), 'date' => $this->i18n->date((string)$rate['from'])])
                    : $this->i18n->money((float)$rate['amount_eur']),
                'measure_eur' => round($measure),
                'ongoing_eur' => (float)$ongoing, 'over_pct' => $over, 'qty' => $this->i18n->number($basis['qty'], 0),
                'unit' => $unit, 'period_from' => $basis['from'], 'period_to' => $basis['to'], 'norm' => $norm,
                'next_due' => $rate['due'],
            ],
            'rules' => $rules,
            'title_key' => 'rights.advance_high.title', 'text_key' => $pricesChange ? 'rights.advance_high.textFrom' : 'rights.advance_high.text',
            'letter' => 'advance_adjustment',
            'key' => [$rate['from']],
        ];
    }

    /**
     * A2 — die Normen zur Bemessung des Abschlags: Grundversorgung § 13 StromGVV/GasGVV,
     * Sondervertrag § 41b Abs. 3 EnWG, unbekannt beide; Fernwärme § 25 AVBFernwärmeV.
     *
     * @return list<string>
     */
    public static function advanceRules(string $country, string $utility, ?string $supplyKind): array
    {
        if (!in_array($utility, self::ADVANCE_UTILITIES, true)) return [];
        return Rights::applicable($country, self::ADVANCE_RULES, $utility, Rights::isEnergy($country, $utility) ? $supplyKind : null);
    }

    /** A2 — Rate über der Vergleichsgröße: mehr als ADVANCE_HIGH_PCT darüber und mindestens ADVANCE_HIGH_MIN_EUR. */
    public static function tooHigh(float $rate, float $reference): bool
    {
        return $reference > 0 && $rate > $reference * (1 + Rights::ADVANCE_HIGH_PCT / 100) + 1e-9
            && $rate - $reference >= Rights::ADVANCE_HIGH_MIN_EUR - 1e-9;
    }

    /**
     * A2 — die nächste Rate ab heute: Zeile mit Rhythmus → ihr nächster Termin (ContractService::
     * installments), monatlich → der nächste Monatserste. Raten je Jahr: monatlich 12, alle N Monate
     * 12 / N, genannte Termine ihre Zahl (elf statt zwölf Raten).
     *
     * @return array{due:string, amount_eur:float, per_year:int, from:string}|null
     */
    public static function nextRate(array $plan, string $today): ?array
    {
        $row = ContractService::planRowOn($plan, $today);
        if ($row === null) return null;
        if (ContractService::hasRhythm($row)) {
            $next = ContractService::installments($plan, $today, ContractService::addMonthsClamped($today, 13))[0] ?? null;
            if ($next === null) return null;
            $row = ContractService::planRowOn($plan, $next['date']) ?? $row;
            $due = $next['date'];
            $dates = array_unique(array_map('strval', (array)($row['due_dates'] ?? [])));
            $perYear = $dates !== [] ? min(12, count($dates)) : intdiv(12, ContractService::everyMonths($row));
        } else {
            $due = ContractService::addMonthsClamped(substr($today, 0, 8) . '01', 1);
            $row = ContractService::planRowOn($plan, $due) ?? $row;
            $perYear = ContractService::hasRhythm($row) ? intdiv(12, ContractService::everyMonths($row)) : 12;
        }
        $amount = (float)$row['amount_eur'];
        return $amount > 0 && $perYear > 0 ? ['due' => $due, 'amount_eur' => $amount, 'per_year' => $perYear, 'from' => (string)$row['from'],
            'every_months' => ContractService::everyMonths($row)] : null;
    }

    /**
     * A2 — Jahreskosten der gesetzlichen Bemessung: Menge je Jahr × Arbeitspreis am Fälligkeitstag
     * + 12 × feste Kosten je Monat (Grundpreis, bei Fernwärme Leistungs- und Messpreis). Boni zieht
     * sie nicht ab (vorsichtig). Null ohne Arbeitspreis.
     */
    public function annualCost(array $c, float $qtyPerYear, string $due): ?float
    {
        $wp = $this->contracts->valueOnDate($c['working_prices'] ?? [], 'ct_per_kwh', $due);
        if ($wp === null) return null;
        return $qtyPerYear * $wp / 100 + 12 * ($this->contracts->fixedPerMonthOn($c, $due) ?? 0.0);
    }

    /**
     * A2 — der zuletzt abgerechnete Zeitraum: erste Wahl die jüngste erfasste Jahres- oder
     * Schlussrechnung mit Menge; sonst die letzte abgeschlossene Abrechnungsperiode aus eigenen
     * Ablesungen, nur ohne verteilte Monate (`gaps` = 0). `known_on`: ab wann die App ihn kennt
     * (Zugang bzw. Rechnungsdatum, sonst der Tag nach dem Zeitraum).
     *
     * @return array{qty:float, days:int, from:string, to:string, source:string, bill_id:?string, known_on:string}|null
     */
    private function lastBilledPeriod(string $u, array $meter, string $today): ?array
    {
        foreach ($this->bills->list($u, (string)$meter['id']) as $b) {
            $from = (string)($b['period_from'] ?? ''); $to = (string)($b['period_to'] ?? '');
            $qty = $b['invoice']['energy_kwh'] ?? null;
            if (!in_array($b['kind'] ?? 'annual', ['annual', 'final'], true) || !Dates::isIsoDate($from) || !Dates::isIsoDate($to)
                || $to >= $today || !is_numeric($qty) || (float)$qty <= 0) continue;
            $days = Rights::daysBetween($from, $to) + 1;
            if ($days < 1) continue;
            $known = (string)($b['received_on'] ?? $b['issued_on'] ?? '');
            return ['qty' => (float)$qty, 'days' => $days, 'from' => $from, 'to' => $to, 'source' => 'bill', 'bill_id' => (string)$b['id'],
                'known_on' => Dates::isIsoDate($known) ? $known : date('Y-m-d', (int)strtotime("$to +1 day"))];
        }
        try {
            $periods = $this->periods->forTarget($u, $meter, false, $today)['periods'] ?? [];
        } catch (\Throwable) {
            return null;
        }
        $qk = $u === 'wasser' ? 'm3' : 'kwh';
        foreach (array_reverse($periods) as $p) {
            if (empty($p['complete']) || !empty($p['running']) || (int)($p['gaps'] ?? 1) !== 0) continue;
            $days = (int)($p['days'] ?? 0);
            $qty = (float)($p[$qk] ?? 0);
            if ($days < 1 || $qty <= 0) continue;
            // gemessener Anteil der Periode (nach Menge gewichtet) mindestens MEASURED_SHARE_MIN, sonst urteilt
            // die Prüfung aus Lücken (Konzept §3 A2); eine ältere Periode springt nicht ein
            if (self::measuredShare($this->consumption->forMeter($u, $meter), (string)$p['from'], (string)$p['until'], $qk)
                < ConsumptionService::MEASURED_SHARE_MIN) return null;
            return ['qty' => $qty, 'days' => $days, 'from' => (string)$p['from'], 'to' => (string)$p['until'], 'source' => 'readings',
                'bill_id' => null, 'known_on' => date('Y-m-d', (int)strtotime((string)$p['until'] . ' +1 day'))];
        }
        return null;
    }

    /**
     * A2 — gemessener Anteil eines Zeitraums aus den Monatszeilen (`measured_share`, nach Menge gewichtet,
     * Monate von `from` bis `until` einschließlich); 0 ohne Menge.
     *
     * @param list<array<string,mixed>> $monthly ConsumptionService::forMeter()
     */
    public static function measuredShare(array $monthly, string $from, string $until, string $qk = 'kwh'): float
    {
        $sum = 0.0; $measured = 0.0;
        foreach ($monthly as $m) {
            $ym = (string)($m['ym'] ?? '');
            if ($ym < substr($from, 0, 7) || $ym > substr($until, 0, 7)) continue;
            $q = abs((float)($m[$qk] ?? 0));
            $sum += $q;
            $measured += $q * (float)($m['measured_share'] ?? 0);
        }
        return $sum > 0 ? $measured / $sum : 0.0;
    }

    // ── A3 Preiserhöhung mit Sonderkündigungsrecht und Frist ───────────────

    /** @return list<array<string,mixed>> Rohbefunde */
    public function specialTermination(string $today, string $country): array
    {
        $out = [];
        foreach (Rights::ENERGY_UTILITIES[$country] ?? [] as $u) {
            if (!Utilities::exists($u)) continue;
            $all = $this->contracts->list($u);
            foreach ($all as $c) {
                $f = $this->terminationFor($u, $c, $all, $today, $country);
                if ($f !== null) $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * A3 für einen Vertrag (Rohbefund oder null). v3.11.0 (Skeptiker H1) — ein Vertrag, der ohne
     * Kündigung weiterläuft (Phase `renewed`), zählt wie ein laufender: Eine Preisänderung nach dem
     * eingetragenen Ende gilt, solange er nach coverage() abrechnet. Bis v3.10 fiel sie weg
     * (priceIncrease bis zum Ende, Phase am Tag der Änderung nur `current`).
     *
     * @param list<array<string,mixed>> $all Verträge der Verbrauchsart
     * @return array<string,mixed>|null
     */
    public function terminationFor(string $u, array $c, array $all, string $today, string $country): ?array
    {
        // nie Schattenverträge, nie dynamische Tarife (Preise aus den Marktdaten)
        if (!empty($c['is_shadow']) || ($c['price_model'] ?? null) === 'dynamic' || !is_string($c['id'] ?? null)) return null;
        if (!in_array($this->contracts->phase($c, $all, $today), ['current', 'renewed'], true)) return null;
        $pi = $this->contracts->priceIncrease($c, $today, $u, $country, $this->contracts->coverageOf($c, $all));
        // am Tag der Änderung gilt schon ein Folgevertrag: priceIncrease() zählt nur Änderungen bis zum Ende der
        // Abdeckung (coverageOf), die Frage ist damit beantwortet (bis zur Korrektur H1 eine zweite Phasenprüfung hier)
        if ($pi === null || $pi['termination'] === null) return null;
        return $this->terminationFinding($u, $c, $pi, $country);
    }

    /**
     * v3.11.0 (Skeptiker H1) — Legt A3 den Termin der Sonderkündigung für diese Preisänderung an?
     * Für den Posteingang: Er legt seinen eigenen Termin nur an, wenn nicht (sonst stünde er doppelt
     * unter „Zu tun“). `$c` ist der Vertrag, wie er nach der Übernahme der Preise aus dem Schreiben
     * aussähe; `$from` der Tag der Änderung. Nur in Ländern mit Regeln.
     */
    public function coversPriceChange(string $u, array $c, string $from, string $today, string $country): bool
    {
        if (!Rights::supports($country) || !in_array($u, Rights::ENERGY_UTILITIES[$country] ?? [], true)) return false;
        $all = array_values(array_filter($this->contracts->list($u), fn($x) => ($x['id'] ?? null) !== ($c['id'] ?? null)));
        $all[] = $c;
        $f = $this->terminationFor($u, $c, $all, $today, $country);
        return $f !== null && ($f['facts']['valid_from'] ?? null) === $from;
    }

    /** A3 — ein Befund aus ContractService::priceIncrease(). */
    private function terminationFinding(string $u, array $c, array $pi, string $country): array
    {
        $from = (string)$pi['from'];
        $termination = (string)$pi['termination'];
        $kind = $pi['supply_kind'];
        $gvv = $u === 'gas' ? 'gasgvv' : 'stromgvv';
        $keys = match ($termination) {
            'special' => ['de.enwg.41.5.s4'],
            'basic'   => ["de.$gvv.5.3", "de.$gvv.20"],
            default   => ['de.enwg.41.5.s4', "de.$gvv.5.3", 'de.enwg.40.2.13'],
        };
        $p = ['valid_from' => $from];
        $extra = [];
        // Ankündigungsfrist (nur mit Datum des Schreibens): Sondervertrag ein Monat; Grundversorgung sechs
        // Wochen und Monatsbeginn; unbekannt nur, wenn auch die mildere Frist (ein Monat) verfehlt ist
        $ann = is_string($pi['announced_on'] ?? null) ? Rights::announcement($country, (string)$pi['announced_on'], $from) : null;
        $late = false;
        if ($ann !== null) {
            $p['announced_on'] = (string)$pi['announced_on'];
            if ($termination === 'basic') {
                if ($ann['basic']['late']) { $extra[] = ['rights.special_termination.lateBasic', $p]; $late = true; }
                if (!$ann['basic']['month_start']) { $extra[] = ['rights.special_termination.monthStart', $p]; $late = true; }
                if ($late) $keys[] = "de.$gvv.5.2";
            } elseif ($ann['special']['late']) {
                $extra[] = ['rights.special_termination.lateSpecial', $p];
                $keys[] = 'de.enwg.41.5.s1';
                $late = true;
            }
            if ($late) $extra[] = ['rights.special_termination.adviceCheck', []];
        }
        $guarantee = (string)($c['price_guarantee_until'] ?? '');
        if (Dates::isIsoDate($guarantee) && $guarantee >= $from) $extra[] = ['rights.special_termination.guarantee', ['date' => $guarantee]];
        if ($pi['deadline'] !== $pi['legal_deadline']) {
            $extra[] = ['rights.special_termination.letterDate', ['letter' => (string)$pi['deadline'], 'legal' => (string)$pi['legal_deadline']]];
        }
        $keys[] = 'de.vz.special_termination';   // Zugang vor dem Wirksamwerden, keine eigene Frist des Anbieters

        $what = [];
        $unit = (string)(Utilities::get($u)['consumption_unit'] ?? 'kWh');
        if ($pi['working_price_ct'] !== null) {
            [$o, $n] = $pi['working_price_ct'];
            $what[] = $this->i18n->t('rights.special_termination.wp', ['old' => $this->i18n->number((float)$o, 2), 'new' => $this->i18n->number((float)$n, 2), 'unit' => $unit]);
        }
        if ($pi['base_price_eur'] !== null) {
            [$o, $n] = $pi['base_price_eur'];
            $what[] = $this->i18n->t('rights.special_termination.bp', ['old' => $this->i18n->money((float)$o), 'new' => $this->i18n->money((float)$n)]);
        }
        $note = [];
        foreach (self::noteParts($pi, $u) as [$k, $params]) $note[] = $this->i18n->t($k, $params);
        foreach ($extra as [$k, $params]) $note[] = $this->i18n->t($k, array_map(fn($v) => is_string($v) && Dates::isIsoDate($v) ? $this->i18n->date($v) : $v, $params));

        $certain = $pi['announced_on'] !== null;
        return [
            'severity' => $certain ? 'warning' : 'info',
            'utility' => $u,
            'meter_id' => (string)($c['meter_id'] ?? $c['meter_group_id'] ?? '') ?: null,
            'contract_id' => (string)$c['id'],
            'date' => $from, 'deadline' => (string)$pi['deadline'],
            'facts' => [
                'provider' => (string)($c['provider'] ?? ''), 'tariff_name' => (string)($c['tariff_name'] ?? ''),
                'valid_from' => $from, 'working_price_ct' => $pi['working_price_ct'], 'base_price_eur' => $pi['base_price_eur'],
                'announced_on' => $pi['announced_on'], 'special_termination_until' => $pi['special_termination_until'],
                'legal_deadline' => $pi['legal_deadline'], 'deadline' => $pi['deadline'], 'supply_kind' => $kind,
                'termination' => $termination, 'days_before' => $ann['days_before'] ?? null, 'announced_late' => $late,
                'price_guarantee_until' => $guarantee !== '' ? $guarantee : null,
                'contract_number' => $c['contract_number'] ?? null, 'customer_number' => $c['customer_number'] ?? null,
            ],
            'params' => [
                'label' => $this->i18n->utilityLabel($u), 'provider' => (string)(($c['provider'] ?? '') !== '' ? $c['provider'] : ($c['tariff_name'] ?? '—')),
                'valid_from' => $from, 'deadline' => (string)$pi['deadline'], 'what' => implode(', ', $what), 'note' => implode(' ', $note),
            ],
            'rules' => array_values(array_unique(Rights::applicable($country, $keys, $u, $kind))),
            'title_key' => $certain ? 'rights.special_termination.title' : 'rights.special_termination.titleMaybe',
            'text_key' => 'rights.special_termination.text',
            'letter' => 'special_termination',
            'key' => [$from],
        ];
    }

    /**
     * Der Rechtshinweis zu einer Preiserhöhung (Saldo-Karte, Jahresbericht, A3): Sätze aus
     * `rights.priceNote.*` nach ContractService::priceIncrease() — Sondervertrag, Grundversorgung,
     * unbekannt (+ „nur bei einer Änderung des Versorgers“ ohne Datum des Schreibens), reine
     * Steueränderung (§ 41 Abs. 6 EnWG), Fernwärme (§ 24 AVBFernwärmeV). Leer außerhalb
     * Deutschlands und bei Wasser. Dieselbe Liste liefert `price_increase.notes` an die Oberfläche.
     *
     * @return list<array{0:string, 1:array<string,string>}>
     */
    public static function noteParts(array $pi, string $utility): array
    {
        $params = (array)($pi['note_params'] ?? ['gvv' => $utility === 'gas' ? 'GasGVV' : 'StromGVV']);
        $out = [];
        foreach ((array)($pi['notes'] ?? []) as $k) $out[] = ['rights.priceNote.' . $k, $params];
        return $out;
    }
}
