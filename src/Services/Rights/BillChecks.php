<?php
declare(strict_types=1);

namespace Energietracker\Services\Rights;

use Energietracker\Config\Rights;
use Energietracker\Config\Utilities;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Support\Dates;

/**
 * v3.11.0 (F1035, Paket rechnung) — die Prüfungen „Deine Rechte als Kunde“ an erfassten
 * Versorgerrechnungen (Konzept §3). Jede Funktion ist eine Prüfung im Sinne von
 * RightsService::register(): `fn(string $today, string $country): list<array>` liefert
 * Rohbefunde; Land, Form, Fenster, Ausblenden und Erledigt macht der Dienst.
 *
 *   late()       A1 bill_late                Rechnung später als sechs Wochen nach dem Zeitraum
 *   bonus()      A4 bonus_missing            Bonus laut Vertrag ohne Gutschrift
 *   estimate()   A5 estimate_despite_reading Rechnung schätzt den Endstand, eigene Ablesung daneben
 *   metering()   A6 metering_cap             Messentgelt über der Preisobergrenze des MsbG (Strom)
 *   feeNotice()  A7 fee_notice_deadline      Frist beim Gebührenbescheid (Wasser/Abwasser)
 *
 * Die Prüfungen lesen nur (Rechnungen, Verträge, Zähler, Ablesungen) und rufen weder
 * Empfehlungen noch „Zu tun“ auf (Kreis). Teure Rechnungen (billBreakdown, forMeter) laufen
 * nur für die wenigen Rechnungen, die den Auslöser schon erfüllen.
 *
 * Anlass (`date`) eines Rechnungsbefunds ist der Tag, an dem die Rechnung vorlag:
 * `received_on ?? issued_on`, ohne beides der Tag nach dem Zeitraum (wie BillService::book).
 */
final class BillChecks
{
    /** Rechnungsarten, an denen die Prüfungen ansetzen (Zwischenrechnungen bildet die App nicht ab). */
    private const BILL_KINDS = ['annual', 'final'];

    /**
     * App-Schwelle (A4): Ein Bonus mit Gutschriftstermin gehört zur Rechnung, deren Zeitraum
     * den Termin enthält, bis so viele Tage davor oder danach (Konzept §3, A4). Dasselbe
     * Fenster gilt für eine gebuchte Rückzahlung.
     */
    public const BONUS_WINDOW_DAYS = 31;

    /**
     * App-Schwelle (A4): Eine Gutschrift ohne Angabe netto/brutto zählt auch, wenn sie den
     * Bonus erst mit diesem Faktor erreicht (der Bonus kann netto ausgewiesen sein;
     * Konzept §13: „brutto oder netto“). Mit Angabe `basis: net` und `vat_pct` rechnet die
     * Prüfung genau.
     */
    public const BONUS_NET_FACTOR = 1.19;

    /** A4 — Bonusarten, die über die Rechnung kommen; `sofort` zahlt der Versorger per Überweisung. */
    private const BILLED_BONUS_TYPES = ['wechsel', 'neukunde', 'sonstige'];

    /** @var array<string,?string> Vertrag je Rechnung (eine Prüfung, ein Lauf) */
    private array $contractOf = [];

    /** Tag des laufenden Prüflaufs (A5 am Gebührenbescheid: Frist noch offen?). */
    private string $today = '';

    public function __construct(
        private BillService $bills,
        private ContractService $contracts,
        private MeterService $meters,
        private ReadingService $readings,
        private ConsumptionService $consumption,
    ) {}

    // ── A1 Rechnung zu spät ──────────────────────────────────────────────

    /**
     * A1 — Jahres- oder Schlussrechnung, die mehr als BILL_LATE_GRACE_DAYS nach dem Ende der
     * gesetzlichen Frist vorlag (§ 40c Abs. 2 EnWG: sechs Wochen nach dem Ende des Zeitraums
     * bzw. der Belieferung). Welche Arten eine Frist haben, sagt Rights::billDeadline (über
     * Countries: Strom und Gas). Ohne Rechnungs- und Zugangsdatum keine Prüfung. Eine
     * Korrektur aus dem Posteingang ändert das Datum der erfassten Fassung nicht: Die Frist
     * betrifft die erste Fassung.
     *
     * @return list<array<string,mixed>>
     */
    public function late(string $today, string $country): array
    {
        $this->contractOf = [];   // je Lauf neu: Rechnungen und Verträge können sich seit dem letzten geändert haben
        $out = [];
        foreach (Utilities::keys() as $u) {
            if (!Utilities::supportsBillCheck($u)) continue;
            foreach ($this->bills->list($u) as $b) {
                $kind = (string)($b['kind'] ?? 'annual');
                if (!in_array($kind, self::BILL_KINDS, true) || !Dates::isIsoDate($b['period_to'] ?? null)) continue;
                $at = self::presentedOn($b);
                if ($at === null) continue;
                $late = Rights::billDaysLate($country, $u, (string)$b['period_to'], $kind, $at);
                if ($late === null) continue;
                $deadline = (string)Rights::billDeadline($country, $u, (string)$b['period_to'], $kind);
                $contract = $this->contractOfBill($u, $b);
                // Namen wie im Brief „Beanstandung“ (Paket briefe, LetterService)
                $facts = self::billFacts($b) + [
                    'bill_kind' => $kind, 'bill_date' => $at, 'deadline_on' => $deadline, 'days_late' => $late,
                    'provider' => $contract !== null ? (string)($contract['provider'] ?? '') : null,
                ];
                $out[] = [
                    'severity' => 'info', 'utility' => $u, 'meter_id' => (string)$b['meter_id'],
                    'contract_id' => $contract !== null ? (string)$contract['id'] : null, 'bill_id' => (string)$b['id'],
                    'date' => $at, 'facts' => $facts,
                    'params' => ['period_from' => $facts['period_from'], 'period_to' => $facts['period_to'], 'bill_date' => $at,
                                 'deadline' => $deadline, 'days_late' => $late],
                    'rules' => Rights::applicable($country, ['de.enwg.40c.2', 'de.enwg.111a'], $u),
                    // v3.11.0 (Skeptiker N5) — ohne Zugang zählt das Rechnungsdatum: „ist vom …“ statt „lag am … vor“
                    'title_key' => 'rights.late.title', 'text_key' => Dates::isIsoDate($b['received_on'] ?? null) ? 'rights.late.text' : 'rights.late.textIssued',
                    'letter' => 'complaint',
                ];
            }
        }
        return $out;
    }

    // ── A4 Bonus nicht gutgeschrieben ────────────────────────────────────

    /**
     * A4 — Bonus laut Vertrag, für den die zugehörige Rechnung keine Gutschrift in der Höhe
     * zeigt (Konzept §3, A4). Zugehörig ist
     *   - beim Neukundenbonus (`signup_bonus_eur`) die erste Jahresrechnung des Vertrags, die
     *     mindestens zwölf Monate nach Vertragsbeginn endet, oder vorher die Schlussrechnung;
     *     gesucht wird in allen Rechnungen des Vertrags bis dahin;
     *   - bei einem terminierten Bonus (`bonuses[]`, Art Wechsel, Neukunde oder Sonstige) jede
     *     Rechnung, deren Zeitraum den Gutschriftstermin ± BONUS_WINDOW_DAYS enthält; ein
     *     Sofortbonus kommt per Überweisung und ist keine Prüfung wert.
     * Als Gutschrift zählt ein Posten der Art `credit` oder eine gebuchte Rückzahlung im Vertrag,
     * die nicht das Ergebnis einer Rechnung ist, von mindestens Bonus − BONUS_TOLERANCE_EUR
     * (netto ausgewiesen: siehe BONUS_NET_FACTOR); jede Gutschrift deckt nur einen Bonus.
     * Bezeichnungen zählen nicht (Lektion 23). Strom und Gas (§ 40c Abs. 3 EnWG). Nach der
     * regelmäßigen Verjährung (drei Jahre ab Jahresende) meldet die Prüfung nichts mehr.
     *
     * @return list<array<string,mixed>>
     */
    public function bonus(string $today, string $country): array
    {
        $this->contractOf = [];
        $out = [];
        foreach (Utilities::keys() as $u) {
            if (!Utilities::supportsBillCheck($u) || !Rights::isEnergy($country, $u)) continue;
            $bills = array_values(array_filter($this->bills->list($u), fn($b) => in_array($b['kind'] ?? 'annual', self::BILL_KINDS, true)
                && Dates::isIsoDate($b['period_from'] ?? null) && Dates::isIsoDate($b['period_to'] ?? null)));
            if ($bills === []) continue;
            usort($bills, fn($a, $b) => strcmp((string)$a['period_to'], (string)$b['period_to']));
            $resultIds = array_fill_keys(array_filter(array_map(fn($b) => (string)($b['special_payment_id'] ?? ''), $this->bills->list($u))), true);
            foreach ($this->contracts->list($u) as $c) {
                if (!empty($c['is_shadow'])) continue;
                $cid = (string)($c['id'] ?? '');
                $mine = array_values(array_filter($bills, fn($b) => ($this->contractOfBill($u, $b)['id'] ?? null) === $cid));
                if ($mine === []) continue;
                $used = [];
                foreach ($this->expectedBonuses($c, $mine) as $x) {
                    if ($this->bonusCredited($x, $c, $resultIds, $used)) continue;
                    $bill = $x['bill'];
                    $date = self::presentedOn($bill) ?? self::dayAfter((string)$bill['period_to']);
                    if ($today > ((int)substr($date, 0, 4) + 3) . '-12-31') continue;   // §§ 195, 199 BGB: verjährt
                    $portal = $x['type'] === 'sonstige';
                    $tariff = trim((string)($c['tariff_name'] ?? '')) ?: (string)($c['provider'] ?? '');
                    $facts = self::billFacts($bill) + ['bonus_eur' => $x['amount'], 'bonus_type' => $x['type'], 'credit_date' => $x['credit_date'],
                              'tariff' => $tariff, 'provider' => (string)($c['provider'] ?? ''), 'contract_start' => (string)($c['start'] ?? ''),
                              'bill_kind' => (string)($bill['kind'] ?? 'annual'), 'bill_date' => $date];
                    $out[] = [
                        'severity' => 'warning', 'utility' => $u, 'meter_id' => (string)$bill['meter_id'], 'contract_id' => $cid,
                        'bill_id' => (string)$bill['id'], 'date' => $date, 'amount_eur' => $x['amount'], 'facts' => $facts,
                        // Platzhalter `amount_eur`, nicht `bonus_eur`: Die Termbase prüft „bonus“ im Französischen auch in Platzhaltern
                        'params' => ['amount_eur' => $x['amount'], 'tariff' => $tariff, 'period_from' => $facts['period_from'], 'period_to' => $facts['period_to']],
                        'rules' => Rights::applicable($country, ['de.enwg.41.4', 'de.vz.bonus'], $u, $c['supply_kind'] ?? null),
                        'title_key' => 'rights.bonus.title', 'text_key' => $portal ? 'rights.bonus.textPortal' : 'rights.bonus.text',
                        // ein Bonus eines Vergleichsportals: zahlt nicht der Versorger, kein Brief an ihn
                        'letter' => $portal ? null : 'complaint',
                        'key' => [$x['key']],
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Erwartete Boni eines Vertrags mit der Rechnung, die sie zeigen sollte, und den
     * Rechnungen, in denen eine Gutschrift gesucht wird. Größter Bonus zuerst (eine große
     * Gutschrift deckt nicht zwei kleine, eine kleine nicht den großen).
     *
     * @param list<array<string,mixed>> $mine Rechnungen des Vertrags, nach Zeitraumende
     * @return list<array{key:string, type:string, amount:float, credit_date:?string, bill:array, search:list<array>, from:string, to:string}>
     */
    private function expectedBonuses(array $c, array $mine): array
    {
        $out = [];
        $start = (string)($c['start'] ?? '');
        $signup = $c['signup_bonus_eur'] ?? null;
        if (is_numeric($signup) && (float)$signup > 0 && Dates::isIsoDate($start)) {
            $year = self::plusDays(self::addMonths($start, 12), -1);   // zwölf Monate Belieferung
            foreach ($mine as $i => $b) {
                if ((string)$b['period_to'] < $year && ($b['kind'] ?? 'annual') !== 'final') continue;
                $out[] = ['key' => 'signup', 'type' => 'neukunde', 'amount' => round((float)$signup, 2), 'credit_date' => null, 'bill' => $b,
                          'search' => array_slice($mine, 0, $i + 1), 'from' => $start,
                          'to' => self::plusDays(self::presentedOn($b) ?? self::dayAfter((string)$b['period_to']), self::BONUS_WINDOW_DAYS)];
                break;
            }
        }
        foreach ((array)($c['bonuses'] ?? []) as $n => $x) {
            $cd = (string)($x['credit_date'] ?? '');
            $amount = (float)($x['amount_eur'] ?? 0);
            if (!Dates::isIsoDate($cd) || $amount <= 0 || !in_array($x['type'] ?? 'sofort', self::BILLED_BONUS_TYPES, true)) continue;
            $near = array_values(array_filter($mine, fn($b) => self::plusDays((string)$b['period_from'], -self::BONUS_WINDOW_DAYS) <= $cd
                && $cd <= self::plusDays((string)$b['period_to'], self::BONUS_WINDOW_DAYS)));
            if ($near === []) continue;
            $out[] = ['key' => "bonus:$cd:$n", 'type' => (string)$x['type'], 'amount' => round($amount, 2), 'credit_date' => $cd, 'bill' => $near[0],
                      'search' => $near, 'from' => self::plusDays($cd, -self::BONUS_WINDOW_DAYS), 'to' => self::plusDays($cd, self::BONUS_WINDOW_DAYS)];
        }
        usort($out, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return $out;
    }

    /**
     * Gibt es eine noch freie Gutschrift für den Bonus? Merkt sich die verbrauchte in `$used`.
     *
     * @param array<string,true> $resultIds Sonderzahlungen, die das Ergebnis einer Rechnung sind
     * @param array<string,true> $used
     */
    private function bonusCredited(array $x, array $c, array $resultIds, array &$used): bool
    {
        $need = $x['amount'] - Rights::BONUS_TOLERANCE_EUR;
        $best = null;
        foreach ($x['search'] as $b) {
            foreach ((array)($b['items'] ?? []) as $i => $it) {
                if (($it['kind'] ?? null) !== 'credit') continue;
                $id = (string)$b['id'] . '#' . $i;
                if (isset($used[$id])) continue;
                $amt = abs((float)($it['amount_eur'] ?? 0));
                $gross = ($it['basis'] ?? null) === 'net' && is_numeric($it['vat_pct'] ?? null) ? $amt * (1 + (float)$it['vat_pct'] / 100)
                    : (($it['basis'] ?? null) === null ? $amt * self::BONUS_NET_FACTOR : $amt);
                if ($gross >= $need && ($best === null || $amt < $best[1])) $best = [$id, $amt];
            }
        }
        foreach ((array)($c['special_payments'] ?? []) as $sp) {
            $id = 'sp#' . (string)($sp['id'] ?? '');
            if (isset($used[$id]) || isset($resultIds[(string)($sp['id'] ?? '')]) || !str_starts_with((string)($sp['kind'] ?? ''), 'rueckzahlung')) continue;
            $d = (string)($sp['date'] ?? '');
            if (!Dates::isIsoDate($d) || $d < $x['from'] || $d > $x['to']) continue;
            $amt = abs((float)($sp['amount_eur'] ?? 0));
            if ($amt * self::BONUS_NET_FACTOR >= $need && ($best === null || $amt < $best[1])) $best = [$id, $amt];
        }
        if ($best === null) return false;
        $used[$best[0]] = true;
        return true;
    }

    // ── A5 Schätzung trotz eigener Ablesung ──────────────────────────────

    /**
     * A5 — Die Rechnung nennt den Endstand geschätzt (`reading_estimated.end`), und neben dem
     * Ende des Zeitraums liegt eine eigene, nicht geschätzte Ablesung (höchstens
     * ESTIMATE_WINDOW_DAYS davor oder danach). Befund nur, wenn der geschätzte Stand von der
     * Fortschreibung des eigenen Stands um mehr als ESTIMATE_DEVIATION_PCT der Menge des
     * Zeitraums abweicht — sonst rechnet der Versorger nur deinen Stand auf das Ende hoch.
     *
     *   Stand der Rechnung  v3.11.0 (Skeptiker M5) der geschätzte Endstand an der Rechnung
     *                       (`estimated_end_reading` {value, on}; Formular und Posteingang); im
     *                       Bestand eine als geschätzt erfasste Ablesung am letzten Tag des Zeitraums
     *                       oder am Tag danach. Ohne beides keine Prüfung.
     *   Fortschreibung      zwischen den eigenen Ständen davor und danach geradlinig, sonst mit
     *                       dem Verbrauch je Tag des letzten bzw. nächsten eigenen Abstands;
     *                       nur Stände desselben Geräts (Zählertausch).
     *   Menge des Zeitraums v3.11.0 (Skeptiker M1) der Verbrauch laut Rechnung (Gas über den Brennwert
     *                       in m³), sonst Verbrauch je Tag dieser Fortschreibung × Tage des Zeitraums.
     *   Wirkung in Euro     Unterschied × mittlerer Arbeitspreis des Zeitraums
     *                       (billBreakdown: Verbrauchskosten ÷ Menge, bei Gas mit dem Brennwert);
     *                       Befund erst ab ESTIMATE_MIN_EUR (ohne Preise nur nach der Menge).
     * Gebührenbescheid (Wasser, `notice.public_law`): kein Satz nach AVBWasserV und kein Brief,
     * sondern der Verweis auf den Rechtsbehelf und seine Frist (Skeptiker M2).
     * Mit „gemeldet am“ (`reported_on`) an der eigenen Ablesung, nicht nach der Rechnung, die
     * deutliche Fassung (Entscheidung 9b), sonst die Frage „Hast du ihn gemeldet?“. Wasser und
     * Fernwärme mit eigenem Satz (§ 20 AVBWasserV, AVBFernwärmeV). Bei einer Gruppenrechnung
     * (Gruppenvertrag) je Zählwerk.
     *
     * @return list<array<string,mixed>>
     */
    public function estimate(string $today, string $country): array
    {
        $this->contractOf = [];
        $this->today = $today;
        $out = [];
        foreach (Utilities::keys() as $u) {
            if (!Utilities::supportsBillCheck($u) || !Utilities::isCumulative($u)) continue;
            foreach ($this->bills->list($u) as $b) {
                // v3.11.0 (Skeptiker M5) — der geschätzte Endstand an der Rechnung sagt es auch ohne Haken
                if (empty(((array)($b['reading_estimated'] ?? []))['end']) && !is_array($b['estimated_end_reading'] ?? null)) continue;
                if (!Dates::isIsoDate($b['period_from'] ?? null) || !Dates::isIsoDate($b['period_to'] ?? null)) continue;
                foreach ($this->billMeters($u, $b) as $m) {
                    $f = $this->estimateFinding($country, $u, $b, $m);
                    if ($f !== null) $out[] = $f;
                }
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function estimateFinding(string $country, string $u, array $b, array $m): ?array
    {
        if (($m['capture'] ?? 'counter') === 'period' || !empty($m['is_group'])) return null;
        $to = (string)$b['period_to'];
        $rs = array_values(array_filter($this->readings->list($u, (string)$m['id']), fn($r) => empty($r['is_future']) && empty($r['is_suspect'])
            && Dates::isIsoDate($r['date'] ?? null) && is_numeric($r['counter'] ?? null)));
        // v3.11.0 (Skeptiker M5) — der geschätzte Endstand steht an der Rechnung (`estimated_end_reading`),
        // nicht als Ablesung (die verbog den eigenen Verbrauch); Bestand: eine als geschätzt erfasste
        // Ablesung am Ende des Zeitraums oder am Tag danach, wie bis v3.11 (keine Migration nötig)
        $est = null;
        $ee = $b['estimated_end_reading'] ?? null;
        if (is_array($ee) && is_numeric($ee['value'] ?? null) && Dates::isIsoDate($ee['on'] ?? null) && empty($m['is_group'])
            && (string)($b['meter_id'] ?? '') === (string)$m['id']) {
            $est = ['date' => (string)$ee['on'], 'counter' => (float)$ee['value'], 'is_estimated' => true,
                    'device_id' => $this->meters->deviceOnDate($m, (string)$ee['on'])['id'] ?? null, 'on_bill' => true];
        }
        foreach ($est === null ? $rs : [] as $r) {
            if (!empty($r['is_estimated']) && in_array($r['date'], [$to, self::dayAfter($to)], true)) { $est = $r; break; }
        }
        if ($est === null) return null;
        $t = (string)$est['date'];
        $dev = $est['device_id'] ?? null;
        $own = array_values(array_filter($rs, fn($r) => empty($r['is_estimated']) && ($r['device_id'] ?? null) === $dev && $r['date'] !== $t));
        $before = array_values(array_filter($own, fn($r) => $r['date'] < $t));
        $after = array_values(array_filter($own, fn($r) => $r['date'] > $t));
        $a = $before !== [] ? $before[count($before) - 1] : null;
        $c = $after[0] ?? null;
        $win = fn(?array $r) => $r !== null && abs(Rights::daysBetween($t, (string)$r['date'])) <= Rights::ESTIMATE_WINDOW_DAYS;
        if (!$win($a) && !$win($c)) return null;
        // die eigene Ablesung, die der Text nennt: eine gemeldete im Fenster (vor der Rechnung), sonst die
        // nähere (gleich nah: die davor)
        $presented = self::presentedOn($b);
        $reportedIn = fn(?array $r) => $win($r) && Dates::isIsoDate($r['reported_on'] ?? null) && ($presented === null || $r['reported_on'] <= $presented);
        $near = $reportedIn($a) ? $a : ($reportedIn($c) ? $c
            : (!$win($c) || ($win($a) && Rights::daysBetween((string)$a['date'], $t) <= Rights::daysBetween($t, (string)$c['date'])) ? $a : $c));
        if ($a !== null && $c !== null) {
            [$p, $q] = [$a, $c];
        } elseif ($a !== null) {
            if (count($before) < 2) return null;
            [$p, $q] = [$before[count($before) - 2], $a];
        } else {
            if (count($after) < 2) return null;
            [$p, $q] = [$c, $after[1]];
        }
        $span = Rights::daysBetween((string)$p['date'], (string)$q['date']);
        $rate = $span > 0 ? ((float)$q['counter'] - (float)$p['counter']) / $span : -1.0;
        if ($rate < 0) return null;   // Überlauf oder Tausch dazwischen: nicht fortschreibbar
        $projected = (float)$p['counter'] + $rate * Rights::daysBetween((string)$p['date'], $t);
        $delta = (float)$est['counter'] - $projected;
        if (abs($delta) < 1e-9) return null;

        // Wirkung: mittlerer Preis je Einheit des Zählerstands im Zeitraum (dieselben Bausteine wie „Rechnung prüfen“)
        $standKey = in_array($u, ['gas', 'wasser'], true) ? 'm3' : 'kwh';
        $perUnit = null; $kwhPerM3 = null;
        try {
            $bd = $this->consumption->billBreakdown($u, $m, (string)$b['period_from'], self::dayAfter($to));
            $qty = (float)($bd['totals'][$standKey] ?? 0);
            if ($qty > 0 && is_numeric($bd['totals']['energy_cost'] ?? null) && (float)$bd['totals']['energy_cost'] > 0) $perUnit = (float)$bd['totals']['energy_cost'] / $qty;
            if ($u === 'gas' && $qty > 0 && (float)($bd['totals']['kwh'] ?? 0) > 0) $kwhPerM3 = (float)$bd['totals']['kwh'] / $qty;
        } catch (\Throwable) {
            // ohne Preise bleibt der Befund, nur ohne Euro
        }
        // v3.11.0 (Skeptiker M1) — die Menge des Zeitraums laut Rechnung (in der Einheit des Zählerstands; Gas
        // über den Brennwert der App), sonst wie bisher Verbrauch je Tag × Tage. Bis dahin immer Letzteres: mit
        // einem Sommerabstand als Grundlage wurde die Schwelle zu fein (6 m³ von rund 1.300 m³ meldeten
        // „Achtung“), mit einem Winterabstand zu grob. Die Menge der App im Zeitraum (billBreakdown) springt
        // nicht ein: Sie zählt nur die Monate mit Ablesung und wäre bei lückigen Daten zu klein.
        $invoiceQty = $b['invoice'][$u === 'wasser' ? 'volume_m3' : 'energy_kwh'] ?? null;
        $billQty = is_numeric($invoiceQty) && (float)$invoiceQty > 0
            ? ($u === 'gas' ? ($kwhPerM3 !== null ? (float)$invoiceQty / $kwhPerM3 : null) : (float)$invoiceQty) : null;
        [$periodQty, $qtyBasis] = $billQty !== null ? [$billQty, 'bill'] : [$rate * (Rights::daysBetween((string)$b['period_from'], $to) + 1), 'rate'];
        if ($periodQty <= 0 || abs($delta) <= $periodQty * Rights::ESTIMATE_DEVIATION_PCT / 100) return null;
        $deltaQty = $u === 'gas' ? ($kwhPerM3 !== null ? $delta * $kwhPerM3 : null) : $delta;
        $deltaEur = $perUnit !== null ? round($delta * $perUnit, 2) : null;
        // … und eine Wirkung von mindestens ESTIMATE_MIN_EUR (ohne Preise zählt nur die Menge)
        if ($deltaEur !== null && abs($deltaEur) < Rights::ESTIMATE_MIN_EUR - 1e-9) return null;
        $reported = Dates::isIsoDate($near['reported_on'] ?? null) ? (string)$near['reported_on'] : null;
        $strong = $reported !== null && ($presented === null || $reported <= $presented);
        $energy = Rights::isEnergy($country, $u);
        $imsys = $u === 'strom' && ($m['metering_device'] ?? null) === 'imsys';
        $contract = $this->contractOfBill($u, $b);
        $unit = (string)(Utilities::get($u)['unit'] ?? '');
        $cunit = (string)(Utilities::get($u)['consumption_unit'] ?? $unit);
        // Namen wie im Brief „Rückfrage zur Schätzung“ (Paket briefe): bill_reading, own_reading, own_reading_on, photo
        $facts = self::billFacts($b) + [
            'bill_reading' => (float)$est['counter'], 'bill_reading_on' => $t,
            'own_reading' => (float)$near['counter'], 'own_reading_on' => (string)$near['date'], 'own_reading_id' => (string)($near['id'] ?? ''),
            'own_projected' => round($projected, 1), 'reported_on' => $reported, 'reported' => $strong,
            'delta_counter' => round($delta, 1), 'delta_qty' => $deltaQty !== null ? round($deltaQty, 1) : null, 'delta_eur' => $deltaEur,
            'rate_per_day' => round($rate, 3), 'period_qty' => round($periodQty, 1), 'qty_basis' => $qtyBasis, 'unit' => $unit, 'consumption_unit' => $cunit,
            'attachment_id' => $near['attachment_id'] ?? null, 'photo' => !empty($near['attachment_id']), 'imsys' => $imsys,
            'on_bill' => !empty($est['on_bill']),
        ];
        $rules = ['de.enwg.40a.2.s1', 'de.enwg.40a.2.s3', 'de.stromgvv.11', 'de.gasgvv.11', 'de.avbwasserv.20.2', 'de.avbfernwaermev.20.2'];
        if ($imsys) $rules[] = 'de.enwg.40a.1';
        // v3.11.0 (integration) — „Berichtigung mit deinem Stand verlangen“ ist nur für die Grundversorgung
        // belegt (§ 18 GVV, Prüfbericht 28); beim Sondervertrag und unbekannter Art eine Bitte ohne Anspruch.
        // v3.11.0 (Skeptiker M3) — und nur mit gemeldetem Stand: Ohne Meldung war die Schätzung womöglich
        // zulässig (§ 40a Abs. 2 EnWG, Prüfbericht 8), ein Fehler im Sinne von § 18 GVV folgt daraus nicht
        $basic = $energy && ($contract['supply_kind'] ?? null) === 'basic';
        if ($basic && $strong) array_push($rules, 'de.stromgvv.18', 'de.gasgvv.18');
        $params = ['period_to' => $t, 'bill_counter' => (float)$est['counter'], 'own_date' => $facts['own_reading_on'], 'own_counter' => (float)$near['counter'],
                   'own_projected' => $facts['own_projected'], 'reported_on' => $reported, 'unit' => $unit, 'cunit' => $cunit,
                   'delta_qty' => $deltaQty !== null ? round(abs($deltaQty), 1) : round(abs($delta), 1),
                   'delta_eur' => $deltaEur !== null ? abs($deltaEur) : null];
        $textKey = match (true) {
            !$energy => 'rights.estimate.textHeat',
            $basic   => $strong ? 'rights.estimate.textReportedBasic' : 'rights.estimate.text',
            default  => $strong ? 'rights.estimate.textReported' : 'rights.estimate.text',
        };
        $letter = 'estimate_query';
        // v3.11.0 (Skeptiker M2) — Wasser per Gebührenbescheid (öffentlich-rechtlich): keine AVBWasserV (die gilt
        // für die privatrechtliche Versorgung, Prüfbericht 15, 35), Einwände gehen über den Rechtsbehelf (A7,
        // Prüfbericht 47); eine formlose Rückfrage wahrt dessen Frist nicht, also kein Rückfrage-Brief
        $n = (array)($b['notice'] ?? []);
        if ($u === 'wasser' && !empty($n['public_law']) && Dates::isIsoDate($n['dated_on'] ?? null)) {
            $fd = Rights::feeNoticeDeadline($country, (string)$n['dated_on'], Dates::isIsoDate($n['served_on'] ?? null) ? (string)$n['served_on'] : null);
            $rules = ['de.vwgo.70.1', 'de.vwvfg.41.2'];
            $params['deadline'] = $fd['deadline'] ?? null;
            $facts['fee_notice_deadline'] = $fd['deadline'] ?? null;
            $textKey = $fd !== null && $fd['deadline'] < $this->today ? 'rights.estimate.textNoticeExpired' : 'rights.estimate.textNotice';
            $letter = null;
        }
        return [
            'severity' => 'warning', 'utility' => $u, 'meter_id' => (string)$m['id'],
            'contract_id' => $contract !== null ? (string)$contract['id'] : null, 'bill_id' => (string)$b['id'],
            'date' => $presented ?? self::dayAfter($to), 'amount_eur' => $deltaEur !== null ? abs($deltaEur) : null, 'facts' => $facts,
            'params' => $params,
            'rules' => Rights::applicable($country, $rules, $u, $contract['supply_kind'] ?? null),
            'title_key' => $deltaEur !== null ? 'rights.estimate.titleEur' : 'rights.estimate.title',
            'text_key' => $textKey,
            'letter' => $letter,
        ];
    }

    // ── A6 Messentgelt über der Preisobergrenze (Strom) ──────────────────

    /**
     * A6 — Posten der Art `metering` an einer Stromrechnung, umgerechnet auf ein Jahr (Summe
     * brutto ÷ Tage des Zeitraums × 365; netto mit dem Steuersatz des Postens, ohne Satz keine
     * Prüfung), über der Preisobergrenze für den Einbaufall + METERING_TOLERANCE_EUR.
     * Einbaufall aus dem Zähler (`metering_device` mme/imsys, nicht bei `msb_basic: false`),
     * dem Jahresverbrauch (Mittel der letzten höchstens drei vollen Kalenderjahre bis zum Ende
     * des Zeitraums, sonst die Menge der Rechnung hochgerechnet; § 30 Abs. 4 MsbG), einer
     * Steuerung nach § 14a EnWG (Netzentgelt-Rabatt `grid_reduction` am Vertrag) und der
     * Leistung der PV-Anlage (Summe `pv_kw` der Einspeisezähler). Geprüft nur die Tage ab dem
     * Beginn der hinterlegten Tabelle (2026-01-01, Entscheidung 4a), je Tabelle anteilig.
     * Ein intelligentes Messsystem ohne bekannten Verbrauch und das „angemessene Entgelt“
     * prüft die App nicht. Tauscht der Zähler im Zeitraum, bleibt ein Mehrbetrag bis
     * METERING_ONE_OFF_EUR still (Einbau auf Wunsch, § 35 Abs. 1 Nr. 1 MsbG).
     *
     * @return list<array<string,mixed>>
     */
    public function metering(string $today, string $country): array
    {
        $u = 'strom';
        if (!Utilities::supportsBillCheck($u) || (Rights::METERING_CAPS[$country] ?? []) === []) return [];
        $this->contractOf = [];
        $out = [];
        $pvKw = null;
        foreach ($this->meters->list('pv_einspeisung') as $pm) {
            if (is_numeric($pm['pv_kw'] ?? null) && !empty($pm['active'] ?? true)) $pvKw = ($pvKw ?? 0.0) + (float)$pm['pv_kw'];
        }
        foreach ($this->bills->list($u) as $b) {
            $items = array_values(array_filter((array)($b['items'] ?? []), fn($it) => is_array($it) && ($it['kind'] ?? null) === 'metering'));
            if ($items === [] || !Dates::isIsoDate($b['period_from'] ?? null) || !Dates::isIsoDate($b['period_to'] ?? null)) continue;
            $from = (string)$b['period_from'];
            $to = (string)$b['period_to'];
            $days = Rights::daysBetween($from, $to) + 1;
            $gross = 0.0;
            foreach ($items as $it) {
                $amt = (float)($it['amount_eur'] ?? 0);
                if (($it['basis'] ?? null) === 'net') {
                    if (!is_numeric($it['vat_pct'] ?? null)) continue 2;   // netto ohne Satz: nicht vergleichbar
                    $amt *= 1 + (float)$it['vat_pct'] / 100;
                }
                $gross += $amt;
            }
            if ($gross <= 0 || $days <= 0) continue;
            $meters = $this->billMeters($u, $b);
            $dm = null;
            foreach ($meters as $m) if (in_array($m['metering_device'] ?? null, ['mme', 'imsys'], true)) { $dm = $m; break; }
            if ($dm === null || ($dm['msb_basic'] ?? null) === false) continue;
            $device = (string)$dm['metering_device'];
            $contract = $this->contractOfBill($u, $b);
            $para14a = $contract !== null && !empty($contract['grid_reduction']);
            [$kwhYear, $kwhBasis] = $device === 'imsys' ? $this->annualKwh($meters, $b) : [null, null];
            if ($device === 'imsys' && $kwhYear === null) continue;
            // Tage je Tabelle (Fassung), nur ab dem Beginn der hinterlegten Tabellen
            $starts = array_values(array_unique(array_filter(array_column(Rights::METERING_CAPS[$country], 'from'), fn($d) => $d > $from && $d <= $to)));
            sort($starts);
            $cut = [$from, ...$starts, self::dayAfter($to)];
            $capDays = 0.0; $checked = 0; $case = null;
            for ($i = 0; $i < count($cut) - 1; $i++) {
                $cap = Rights::meteringCap($country, $cut[$i], $device, $kwhYear, $pvKw, $para14a);
                if ($cap === null) continue;
                if ($cap['cap_eur'] === null) continue 2;   // angemessenes Entgelt: kein Betrag zum Vergleich
                $n = Rights::daysBetween($cut[$i], $cut[$i + 1]);
                $capDays += $cap['cap_eur'] * $n;
                $checked += $n;
                $case = $cap;
            }
            if ($checked === 0 || $case === null) continue;
            $year = round($gross / $days * 365, 2);
            $capYear = round($capDays / $checked, 2);
            if ($year <= $capYear + Rights::METERING_TOLERANCE_EUR) continue;
            // Einbau im Zeitraum: ein Einmalbetrag bis METERING_ONE_OFF_EUR ist erlaubt
            $oneOff = Rights::METERING_ONE_OFF_EUR[$country] ?? null;
            $installed = false;
            foreach ($meters as $m) foreach ((array)($m['devices'] ?? []) as $d) {
                if (($d['installed_on'] ?? '') >= $from && ($d['installed_on'] ?? '') <= $to) $installed = true;
            }
            $excessInPeriod = $gross - $capYear * $days / 365;
            if ($installed && $oneOff !== null && $excessInPeriod <= $oneOff) continue;
            $over = round($year - $capYear, 2);
            $date = self::presentedOn($b) ?? self::dayAfter($to);
            $rules = $device === 'mme'
                ? ['de.msbg.32.1', 'de.bnetza.msb_costs', 'de.msbg.35.1', 'de.enwg.40.3.4']
                : array_values(array_unique([(string)$case['rule'], 'de.msbg.30.4', 'de.bnetza.msb_costs', 'de.msbg.35.1', 'de.enwg.40.3.4']));
            // Namen wie im Brief „Beanstandung“ (Paket briefe): annual_eur, cap_eur, cap_rule, device
            $facts = self::billFacts($b) + ['days' => $days, 'checked_days' => $checked, 'billed_eur' => round($gross, 2),
                      'annual_eur' => $year, 'cap_eur' => $capYear, 'cap_rule' => (string)$case['rule'], 'over_eur' => $over,
                      'case' => (string)$case['case'], 'table_from' => (string)$case['from'],
                      'device' => $device, 'msb_basic' => $dm['msb_basic'] ?? null, 'kwh_year' => $kwhYear !== null ? round($kwhYear) : null,
                      'kwh_basis' => $kwhBasis, 'pv_kw' => $pvKw, 'para_14a' => $para14a,
                      'provider' => $contract !== null ? (string)($contract['provider'] ?? '') : null];
            $out[] = [
                'severity' => 'warning', 'utility' => $u, 'meter_id' => (string)$dm['id'],
                'contract_id' => $contract !== null ? (string)$contract['id'] : null, 'bill_id' => (string)$b['id'],
                'date' => $date, 'amount_eur' => $over, 'facts' => $facts,
                'params' => ['year_eur' => $year, 'cap_eur' => $capYear, 'over_eur' => $over, 'kwh_year' => $facts['kwh_year'],
                             'period_from' => $from, 'period_to' => $to],
                'rules' => Rights::applicable($country, $rules, $u),
                'title_key' => 'rights.metering.title', 'text_key' => $device === 'mme' ? 'rights.metering.textMme' : 'rights.metering.textImsys',
                'letter' => 'complaint',
            ];
        }
        return $out;
    }

    /**
     * A6 — Jahresverbrauch für die Fallgruppe (§ 30 Abs. 4 MsbG): Mittel der letzten höchstens
     * drei vollen Kalenderjahre (alle zwölf Monate mit Menge, je Zählwerk) bis zum Ende des
     * Zeitraums, sonst die Menge der Rechnung auf ein Jahr hochgerechnet, sonst unbekannt.
     *
     * @param list<array<string,mixed>> $meters
     * @return array{0: ?float, 1: ?string} [kWh, Grundlage years|bill]
     */
    private function annualKwh(array $meters, array $b): array
    {
        $byYear = [];
        foreach ($meters as $m) {
            foreach ($this->consumption->forMeter('strom', $m) as $row) {
                $ym = (string)($row['ym'] ?? '');
                if (strlen($ym) !== 7 || !is_numeric($row['kwh'] ?? null)) continue;
                $byYear[substr($ym, 0, 4)][(string)$m['id']][$ym] = (float)$row['kwh'];
            }
        }
        $full = [];
        foreach ($byYear as $y => $perMeter) {
            if ("$y-12-31" > (string)$b['period_to'] || count($perMeter) !== count($meters)) continue;
            foreach ($perMeter as $months) if (count($months) !== 12) continue 2;
            $full[(string)$y] = array_sum(array_map('array_sum', $perMeter));
        }
        krsort($full);
        $last = array_slice($full, 0, 3, true);
        if ($last !== []) return [array_sum($last) / count($last), 'years'];
        $kwh = $b['invoice']['energy_kwh'] ?? null;
        $days = Rights::daysBetween((string)$b['period_from'], (string)$b['period_to']) + 1;
        return is_numeric($kwh) && (float)$kwh > 0 && $days > 0 ? [(float)$kwh / $days * 365, 'bill'] : [null, null];
    }

    // ── A7 Widerspruchsfrist beim Gebührenbescheid (Wasser/Abwasser) ─────

    /**
     * A7 — Wasserrechnung, die ein öffentlich-rechtlicher Gebührenbescheid ist
     * (`notice.public_law`): Termin = Rights::feeNoticeDeadline (Bekanntgabe am vierten Tag
     * nach dem Bescheiddatum bzw. am Tag der Zustellung, dann ein Monat). Anlass ist das
     * Bescheiddatum, die Frist macht ihn zum Termin; nach Ablauf verschwindet er aus „Zu tun“.
     * Brief „Widerspruch“ nur, wenn die Rechtsbehelfsbelehrung laut Rechnung den Widerspruch
     * nennt (`remedy: objection`, Entscheidung 5b); bei Klage oder ohne Angabe kein Brief.
     *
     * @return list<array<string,mixed>>
     */
    public function feeNotice(string $today, string $country): array
    {
        $this->contractOf = [];
        $out = [];
        foreach (Utilities::keys() as $u) {
            if (!Utilities::supportsBillCheck($u)) continue;
            foreach ($this->bills->list($u) as $b) {
                $n = $b['notice'] ?? null;
                if (!is_array($n) || empty($n['public_law']) || !Dates::isIsoDate($n['dated_on'] ?? null)) continue;
                $served = Dates::isIsoDate($n['served_on'] ?? null) ? (string)$n['served_on'] : null;
                $fd = Rights::feeNoticeDeadline($country, (string)$n['dated_on'], $served);
                if ($fd === null) continue;
                $remedy = in_array($n['remedy'] ?? null, BillService::NOTICE_REMEDIES, true) ? (string)$n['remedy'] : null;
                $contract = $this->contractOfBill($u, $b);
                // Namen wie im Brief „Widerspruch“ (Paket briefe): dated_on, file_number; die Abgabe (`levy`) trägt
                // man im Dialog ein, die Rechnung kennt sie nicht
                $facts = self::billFacts($b) + ['dated_on' => (string)$n['dated_on'], 'served_on' => $served, 'known_on' => $fd['known_on'],
                          'deadline_on' => $fd['deadline'], 'remedy' => $remedy, 'file_number' => $n['file_number'] ?? null,
                          // v3.11.0 (Nachprüfung NP1, additiv) — der Monatstag vor der Verschiebung auf den nächsten Werktag
                          'nominal_end' => $fd['nominal_end'], 'deadline_shifted' => $fd['nominal_end'] !== $fd['deadline'],
                          'amount_eur' => is_numeric($b['invoice']['amount_eur'] ?? null) ? (float)$b['invoice']['amount_eur'] : null,
                          'provider' => $contract !== null ? (string)($contract['provider'] ?? '') : null];
                // v3.11.0 (Skeptiker H2) — nach dem Fristende kein „ist möglich“ und kein Widerspruch zum Ausfüllen:
                // ein eigener Text (abgelaufen; ein Jahr nur ohne oder mit unrichtiger Rechtsbehelfsbelehrung,
                // Prüfbericht 48, solange auch das noch laufen kann), kein Brief, kein Termin
                // v3.11.0 (Nachprüfung NP1) — `deadline` ist schon auf den nächsten Werktag verschoben (Samstag,
                // Sonntag, bundesweiter Feiertag; Prüfbericht 60, 61): „abgelaufen“ erst am Tag danach
                $expired = $fd['deadline'] < $today;
                $yearOpen = $expired && $today <= self::addMonths($fd['known_on'], 12);
                $facts['expired'] = $expired;
                $out[] = [
                    'severity' => $expired ? 'info' : 'warning', 'utility' => $u, 'meter_id' => (string)$b['meter_id'],
                    'contract_id' => $contract !== null ? (string)$contract['id'] : null, 'bill_id' => (string)$b['id'],
                    'date' => (string)$n['dated_on'], 'deadline' => $fd['deadline'], 'facts' => $facts,
                    'params' => ['dated_on' => (string)$n['dated_on'], 'served_on' => $served, 'deadline' => $fd['deadline']],
                    'rules' => Rights::applicable($country, $expired
                        ? ['de.vwgo.70.1', 'de.vwgo.58.2', 'de.vwvfg.41.2', 'de.ao.122.2', 'de.bgb.188', 'de.vwgo.57.2', 'de.vwvfg.31.3', 'de.ao.108.3']
                        : ['de.vwgo.70.1', 'de.vwgo.68.1', 'de.vwvfg.41.2', 'de.ao.122.2', 'de.bgb.188', 'de.vwgo.57.2', 'de.vwvfg.31.3', 'de.ao.108.3', 'de.vwgo.80'], $u),
                    'title_key' => $expired ? 'rights.feeNotice.titleExpired' : 'rights.feeNotice.title',
                    'text_key' => match (true) {
                        $yearOpen => 'rights.feeNotice.textExpired',
                        $expired  => 'rights.feeNotice.textExpiredLong',
                        default   => $served !== null ? 'rights.feeNotice.textServed' : 'rights.feeNotice.text',
                    },
                    'letter' => !$expired && $remedy === 'objection' ? 'objection' : null,
                ];
            }
        }
        return $out;
    }

    // ── gemeinsam ────────────────────────────────────────────────────────

    /**
     * Fakten jeder Rechnung, unter den Namen, die die Briefe lesen (Paket briefe, LetterService: „alle“):
     * Zeitraum, Rechnungsdatum, Zugang, Rechnungsnummer (die Rechnung führt keine: null, der Brief fragt).
     *
     * @return array{period_from:string, period_to:string, issued_on:?string, received_on:?string, invoice_number:?string}
     */
    private static function billFacts(array $b): array
    {
        $d = fn(string $f) => Dates::isIsoDate($b[$f] ?? null) ? (string)$b[$f] : null;
        return ['period_from' => (string)($b['period_from'] ?? ''), 'period_to' => (string)($b['period_to'] ?? ''),
                'issued_on' => $d('issued_on'), 'received_on' => $d('received_on'),
                'invoice_number' => is_string($b['invoice_number'] ?? null) && $b['invoice_number'] !== '' ? $b['invoice_number'] : null];
    }

    /** Tag, an dem die Rechnung vorlag: Zugang, sonst Rechnungsdatum, sonst null. */
    public static function presentedOn(array $b): ?string
    {
        foreach (['received_on', 'issued_on'] as $f) if (Dates::isIsoDate($b[$f] ?? null)) return (string)$b[$f];
        return null;
    }

    /**
     * Vertrag der Rechnung: der genannte, sonst der, der den Zeitraum abrechnet
     * (BillService::contractFor). Zuständigkeit erbt Vertrag → Zähler (Grundsatz 8).
     *
     * @return array<string,mixed>|null
     */
    private function contractOfBill(string $u, array $b): ?array
    {
        $key = $u . '|' . (string)($b['id'] ?? '');
        if (!array_key_exists($key, $this->contractOf)) {
            $c = !empty($b['contract_id']) ? $this->contracts->get($u, (string)$b['contract_id']) : null;
            $c ??= Dates::isIsoDate($b['period_from'] ?? null) && Dates::isIsoDate($b['period_to'] ?? null)
                ? $this->bills->contractFor($u, (string)$b['meter_id'], (string)$b['period_from'], (string)$b['period_to']) : null;
            $this->contractOf[$key] = $c !== null ? (string)$c['id'] : null;
            return $c;
        }
        return $this->contractOf[$key] !== null ? $this->contracts->get($u, $this->contractOf[$key]) : null;
    }

    /**
     * Zählwerke einer Rechnung: der Zähler; gilt ein Gruppenvertrag (HT/NT auf einer
     * Rechnung, wie BillService::billTarget), die Mitglieder der Gruppe.
     *
     * @return list<array<string,mixed>>
     */
    private function billMeters(string $u, array $b): array
    {
        $m = $this->meters->get($u, (string)($b['meter_id'] ?? ''));
        if ($m === null) return [];
        $gid = (string)($m['meter_group_id'] ?? '');
        if ($gid !== '') {
            $c = $this->contractOfBill($u, $b);
            if ($c !== null && ($c['meter_group_id'] ?? null) === $gid) return $this->meters->groupSumMembers($u, $gid);
        }
        return [$m];
    }

    private static function plusDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private static function dayAfter(string $date): string
    {
        return self::plusDays($date, 1);
    }

    /** Monate addieren, Tag auf das Monatsende geklemmt (Lektion 26). */
    private static function addMonths(string $date, int $months): string
    {
        $d = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
        $target = $d->modify('first day of this month')->modify("+$months months");
        return $target->setDate((int)$target->format('Y'), (int)$target->format('n'), min((int)$d->format('j'), (int)$target->format('t')))->format('Y-m-d');
    }
}
