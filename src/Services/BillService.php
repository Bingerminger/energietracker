<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;
use Energietracker\Support\LocalizedException;

/**
 * v3.1.0 (Paket H5, B3/UI-35) — Versorgerrechnungen erfassen und gegen die
 * eigene Rechnung halten.
 *
 * Topf `<art>/bills.json`, ein Eintrag:
 *   {id 'b_…', meter_id, contract_id?, kind annual|final|interim, period_from,
 *    period_to (inklusive), issued_on?, invoice {energy_kwh?, volume_m3?,
 *    amount_eur, advances_paid_eur?, result_eur? (positiv = Nachzahlung)},
 *    items [{label, amount_eur, kind levy|fee|credit|other}],
 *    co2? {emissions_kg, cost_eur?, stated_factor?}, attachment_ids [],
 *    special_payment_id?, note, created_at}
 *
 * `compare()` rechnet den Zeitraum mit billBreakdown() nach (dieselben Bausteine
 * wie Monatssicht und Saldo) und nennt die Abweichung; `book()` legt das
 * Ergebnis als Sonderzahlung im Vertrag an (einmal — erneutes Buchen ändert
 * nichts). Freie Posten (Umlagen) bildet die App nicht nach; sie kommen zur
 * eigenen Summe dazu, damit der Vergleich aufgeht.
 */
final class BillService
{
    public const KINDS = ['annual', 'final', 'interim'];
    public const ITEM_KINDS = ['levy', 'fee', 'credit', 'other'];
    /** ok: |Δ Menge| ≤ 1 % und (|Δ €| ≤ 1 % oder ≤ 2 €) */
    private const OK_PCT = 1.0;
    private const OK_EUR = 2.0;

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private ContractService $contracts,
        private ConsumptionService $consumption,
        private I18nService $i18n,
    ) {}

    /** @return list<array<string,mixed>> neueste zuerst */
    public function list(string $utility, ?string $meterId = null): array
    {
        $this->assertUtility($utility);
        $all = $this->store->read("$utility/bills.json", []);
        $all = is_array($all) ? array_values(array_filter($all, 'is_array')) : [];
        if ($meterId !== null) $all = array_values(array_filter($all, fn($b) => ($b['meter_id'] ?? null) === $meterId));
        usort($all, fn($a, $b) => strcmp((string)($b['period_to'] ?? ''), (string)($a['period_to'] ?? '')));
        return $all;
    }

    /** @return array<string,mixed> */
    public function get(string $utility, string $id): array
    {
        foreach ($this->list($utility) as $b) if (($b['id'] ?? null) === $id) return $b;
        throw new NotFoundException($this->i18n->t('errors.bill.notFound'));
    }

    /** @return array<string,mixed> */
    public function create(string $utility, array $input): array
    {
        $this->assertUtility($utility);
        $meterId = (string)($input['meter_id'] ?? $this->meters->defaultId($utility));
        if (!$this->meters->get($utility, $meterId)) {
            throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
        }
        $b = ['id' => 'b_' . bin2hex(random_bytes(6)), 'meter_id' => $meterId]
            + $this->normalize($input, null) + ['attachment_ids' => [], 'created_at' => date('c')];
        $this->linkAttachments($utility, $b, [], $input['attachment_ids'] ?? []);
        $all = $this->store->read("$utility/bills.json", []);
        if (!is_array($all)) $all = [];
        $all[] = $b;
        $this->store->write("$utility/bills.json", array_values($all));
        return $b;
    }

    /** @return array<string,mixed> */
    public function update(string $utility, string $id, array $input): array
    {
        $this->assertUtility($utility);
        $all = $this->store->read("$utility/bills.json", []);
        if (!is_array($all)) $all = [];
        foreach ($all as $i => $b) {
            if (!is_array($b) || ($b['id'] ?? null) !== $id) continue;
            $next = array_merge($b, $this->normalize($input, $b));
            if (array_key_exists('attachment_ids', $input)) $this->linkAttachments($utility, $next, (array)($b['attachment_ids'] ?? []), $input['attachment_ids']);
            $all[$i] = $next;
            $this->store->write("$utility/bills.json", array_values($all));
            return $next;
        }
        throw new NotFoundException($this->i18n->t('errors.bill.notFound'));
    }

    public function delete(string $utility, string $id): void
    {
        $this->assertUtility($utility);
        $all = $this->store->read("$utility/bills.json", []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($b) => is_array($b) && ($b['id'] ?? null) !== $id));
        if (count($kept) === count($all)) throw new NotFoundException($this->i18n->t('errors.bill.notFound'));
        $this->store->write("$utility/bills.json", $kept);
        AttachmentService::unlinkRef($this->store, 'bill', $id);
    }

    /**
     * Eigene Rechnung gegen die Rechnung des Versorgers.
     *
     * @return array<string,mixed>
     */
    public function compare(string $utility, string $id): array
    {
        $b = $this->get($utility, $id);
        $meter = $this->meters->get($utility, (string)$b['meter_id'])
            ?? throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => (string)$b['meter_id']]));
        $toExcl = date('Y-m-d', (int)strtotime((string)$b['period_to'] . ' +1 day'));
        $meter = $this->billTarget($utility, $meter, (string)$b['period_from'], (string)$b['period_to']);
        $bd = $this->consumption->billBreakdown($utility, $meter, (string)$b['period_from'], $toExcl);
        $isWater = $utility === 'wasser';
        $qtyKey = $isWater ? 'm3' : 'kwh';
        $items = array_sum(array_map(fn($x) => (float)($x['amount_eur'] ?? 0), (array)($b['items'] ?? [])));
        $ours = [
            $qtyKey        => $bd['totals'][$qtyKey] ?? null,
            'energy_cost'  => $bd['totals']['energy_cost'],
            'fixed_cost'   => $bd['totals']['fixed_cost'],
            'bonus'        => $bd['totals']['bonus'],
            'items_total'  => round($items, 2),
            'total'        => round($bd['totals']['total'] + $items, 2),
            'advances'     => $this->advancesBetween($utility, $meter, (string)$b['period_from'], (string)$b['period_to']),
        ];
        $inv = (array)($b['invoice'] ?? []);
        $invQty = $isWater ? ($inv['volume_m3'] ?? null) : ($inv['energy_kwh'] ?? null);
        $delta = [
            $qtyKey          => $invQty !== null && $ours[$qtyKey] !== null ? round((float)$invQty - (float)$ours[$qtyKey], 1) : null,
            $qtyKey . '_pct' => $invQty !== null && !empty($ours[$qtyKey]) ? round(((float)$invQty - (float)$ours[$qtyKey]) / (float)$ours[$qtyKey] * 100, 2) : null,
            'eur'            => isset($inv['amount_eur']) ? round((float)$inv['amount_eur'] - $ours['total'], 2) : null,
            'eur_pct'        => isset($inv['amount_eur']) && $ours['total'] != 0 ? round(((float)$inv['amount_eur'] - $ours['total']) / $ours['total'] * 100, 2) : null,
        ];
        $qtyOk = $delta[$qtyKey . '_pct'] === null || abs($delta[$qtyKey . '_pct']) <= self::OK_PCT;
        $eurOk = $delta['eur'] === null || abs($delta['eur']) <= self::OK_EUR || ($delta['eur_pct'] !== null && abs($delta['eur_pct']) <= self::OK_PCT);
        $reasons = [];
        foreach ($bd['rows'] as $r) {
            if (in_array($r['counter_from_kind'] ?? null, ['interpolated', 'reading_estimated'], true)
                || in_array($r['counter_to_kind'] ?? null, ['interpolated', 'reading_estimated'], true)) $reasons['estimated_reading_at_boundary'] = true;
            if (str_contains((string)($r['reason'] ?? ''), 'price')) $reasons['price_change_inside'] = true;
            if (str_contains((string)($r['reason'] ?? ''), 'factor')) $reasons['factor_change_inside'] = true;
        }
        if ($items != 0) $reasons['items_not_modelled'] = true;
        if (!empty($bd['totals']['price_missing'])) $reasons['price_missing'] = true;
        if (($bd['totals']['gaps'] ?? 0) > 0) $reasons['gaps'] = true;
        return [
            'bill_id' => $b['id'], 'utility' => $utility, 'meter_id' => $b['meter_id'],
            'group_id' => !empty($meter['is_group']) ? (string)$meter['id'] : null,   // v3.1.0 (H6) — verglichen mit der Gruppe
            'period_from' => $b['period_from'], 'period_to' => $b['period_to'],
            'ours' => $ours, 'invoice' => $inv, 'delta' => $delta,
            'verdict' => ($invQty === null && !isset($inv['amount_eur'])) ? null : ($qtyOk && $eurOk ? 'ok' : 'check'),
            'reasons' => array_keys($reasons),
            'rows' => $bd['rows'],
        ];
    }

    /**
     * Ergebnis der Rechnung als Sonderzahlung im Vertrag (F1003): Nachzahlung
     * oder Rückzahlung „ohne Auswirkung" auf den Abschlag. Einmal je Rechnung.
     *
     * @return array<string,mixed> die Rechnung mit special_payment_id
     */
    public function book(string $utility, string $id): array
    {
        $b = $this->get($utility, $id);
        if (!empty($b['special_payment_id'])) return $b;
        $result = $b['invoice']['result_eur'] ?? null;
        if (!is_numeric($result) || (float)$result == 0.0) {
            throw new LocalizedException('errors.bill.noResult', [], 'bill without result');
        }
        $date = (string)($b['issued_on'] ?? '') ?: date('Y-m-d', (int)strtotime((string)$b['period_to'] . ' +1 day'));
        $contract = !empty($b['contract_id']) ? $this->contracts->get($utility, (string)$b['contract_id']) : null;
        if ($contract === null) {
            $list = $this->contracts->list($utility, (string)$b['meter_id']);
            // v3.1.0 (H6, #17) — ein Gruppenvertrag gilt auch für die Rechnung eines Mitglieds
            $gid = (string)(($this->meters->get($utility, (string)$b['meter_id']) ?? [])['meter_group_id'] ?? '');
            if ($gid !== '') $list = [...$list, ...$this->contracts->list($utility, $gid)];
            $list = array_values(array_filter($list, fn($c) => empty($c['is_shadow'])));
            usort($list, fn($x, $y) => strcmp((string)($x['start'] ?? ''), (string)($y['start'] ?? '')));
            $contract = $this->contracts->findActiveForDate($list, (string)$b['period_to']) ?? ($list ? end($list) : null);
        }
        if ($contract === null) throw new LocalizedException('errors.bill.noContract', [], 'bill without contract');
        $spId = 'sp_' . bin2hex(random_bytes(5));
        $payments = (array)($contract['special_payments'] ?? []);
        $payments[] = ['id' => $spId, 'date' => $date, 'kind' => (float)$result > 0 ? 'nachzahlung_ohne' : 'rueckzahlung_ohne',
                       'amount_eur' => abs((float)$result), 'note' => $this->i18n->t('billCheck.bookNote', ['from' => $this->i18n->date((string)$b['period_from']), 'to' => $this->i18n->date((string)$b['period_to'])])];
        $this->contracts->update($utility, (string)$contract['id'], ['special_payments' => $payments]);
        return $this->update($utility, $id, ['special_payment_id' => $spId, 'contract_id' => (string)$contract['id']]);
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** Abschläge im Zeitraum aus den Monatswerten, anteilig nach Tagen. */
    /**
     * v3.1.0 (H6, #17) — Wen rechnet der Versorger ab? Berührt ein Gruppenvertrag
     * den Zeitraum, kommt eine Rechnung für die ganze Gruppe (HT und NT auf
     * einer Rechnung), auch wenn sie an einem Mitglied erfasst ist.
     */
    private function billTarget(string $utility, array $meter, string $from, string $to): array
    {
        $gid = (string)($meter['meter_group_id'] ?? '');
        if ($gid === '') return $meter;
        foreach ($this->contracts->list($utility, $gid) as $c) {
            if (!empty($c['is_shadow']) || ($c['meter_group_id'] ?? null) !== $gid) continue;
            if ((string)($c['start'] ?? '') <= $to && $from <= ((string)($c['end'] ?? '') ?: '9999-12-31')) {
                return $this->meters->groupTarget($utility, $gid) ?? $meter;
            }
        }
        return $meter;
    }

    private function advancesBetween(string $utility, array $meter, string $from, string $to): ?float
    {
        $sum = 0.0; $any = false;
        foreach ($this->consumption->forMeter($utility, $meter) as $m) {
            if (!isset($m['advance_eur']) || $m['advance_eur'] === null) continue;
            $ms = (string)$m['ym'] . '-01';
            $me = date('Y-m-t', (int)strtotime($ms));
            $a = max($ms, $from); $b = min($me, $to);
            if ($a > $b) continue;
            $dim = (int)date('t', (int)strtotime($ms));
            $sum += (float)$m['advance_eur'] * (((int)((strtotime($b) - strtotime($a)) / 86400)) + 1) / $dim;
            $any = true;
        }
        return $any ? round($sum, 2) : null;
    }

    /** @return array<string,mixed> */
    private function normalize(array $in, ?array $old): array
    {
        $isNew = $old === null;
        $out = [];
        foreach (['period_from', 'period_to'] as $f) {
            if ($isNew || array_key_exists($f, $in)) {
                $v = (string)($in[$f] ?? '');
                if (!Dates::isIsoDate($v)) throw new LocalizedException('errors.bill.periodInvalid', ['from' => (string)($in['period_from'] ?? ''), 'to' => (string)($in['period_to'] ?? '')], 'bill date');
                $out[$f] = $v;
            }
        }
        $from = $out['period_from'] ?? $old['period_from'];
        $to = $out['period_to'] ?? $old['period_to'];
        if ($from > $to) throw new LocalizedException('errors.bill.periodInvalid', ['from' => $from, 'to' => $to], 'bill order');
        if ($isNew || array_key_exists('issued_on', $in)) {
            $v = (string)($in['issued_on'] ?? '');
            if ($v !== '' && !Dates::isIsoDate($v)) throw new LocalizedException('errors.bill.periodInvalid', ['from' => $v, 'to' => ''], 'issued_on');
            $out['issued_on'] = $v === '' ? null : $v;
        }
        if ($isNew || array_key_exists('kind', $in)) $out['kind'] = in_array($in['kind'] ?? null, self::KINDS, true) ? $in['kind'] : 'annual';
        if (array_key_exists('contract_id', $in)) $out['contract_id'] = ($in['contract_id'] ?? '') === '' ? null : (string)$in['contract_id'];
        if (array_key_exists('special_payment_id', $in)) $out['special_payment_id'] = $in['special_payment_id'] === null ? null : (string)$in['special_payment_id'];
        if ($isNew || array_key_exists('invoice', $in)) {
            $inv = is_array($in['invoice'] ?? null) ? $in['invoice'] : [];
            $o = [];
            foreach (['energy_kwh' => [0, 1e8], 'volume_m3' => [0, 1e7], 'amount_eur' => [-1e7, 1e7],
                      'advances_paid_eur' => [0, 1e7], 'result_eur' => [-1e7, 1e7]] as $f => [$lo, $hi]) {
                if (isset($inv[$f]) && $inv[$f] !== '') $o[$f] = $this->num($inv[$f], $lo, $hi);
            }
            if (!isset($o['result_eur']) && isset($o['amount_eur'], $o['advances_paid_eur'])) {
                $o['result_eur'] = round($o['amount_eur'] - $o['advances_paid_eur'], 2);
            }
            if (array_key_exists('net', $inv)) $o['net'] = !empty($inv['net']);
            $out['invoice'] = $o;
        }
        if ($isNew || array_key_exists('items', $in)) {
            $items = [];
            foreach ((array)($in['items'] ?? []) as $x) {
                if (!is_array($x) || (($x['label'] ?? '') === '' && ($x['amount_eur'] ?? '') === '')) continue;
                $items[] = [
                    'label'      => mb_substr(trim((string)($x['label'] ?? '')), 0, 120),
                    'amount_eur' => $this->num($x['amount_eur'] ?? 0, -1e6, 1e6),
                    'kind'       => in_array($x['kind'] ?? null, self::ITEM_KINDS, true) ? $x['kind'] : 'other',
                ];
            }
            $out['items'] = $items;
        }
        if ($isNew || array_key_exists('co2', $in)) {
            $c = $in['co2'] ?? null;
            $co2 = [];
            if (is_array($c)) {
                if (isset($c['emissions_kg']) && $c['emissions_kg'] !== '') $co2['emissions_kg'] = $this->num($c['emissions_kg'], 0, 1e7);
                if (isset($c['cost_eur']) && $c['cost_eur'] !== '') $co2['cost_eur'] = $this->num($c['cost_eur'], 0, 1e6);
                if (isset($c['stated_factor']) && $c['stated_factor'] !== '') $co2['stated_factor'] = $this->num($c['stated_factor'], 0, 10);
            }
            $out['co2'] = $co2 === [] ? null : $co2;
        }
        if ($isNew || array_key_exists('note', $in)) $out['note'] = mb_substr((string)($in['note'] ?? ''), 0, 2000);
        return $out;
    }

    private function linkAttachments(string $utility, array &$b, array $old, mixed $new): void
    {
        $new = array_values(array_unique(array_filter(array_map('strval', is_array($new) ? $new : []), fn($x) => $x !== '')));
        if (array_diff($old, $new) !== []) AttachmentService::unlinkRef($this->store, 'bill', (string)$b['id']);
        foreach ($new as $id) {
            AttachmentService::link($this->store, $id, null, ['type' => 'bill', 'utility' => $utility, 'id' => (string)$b['id']]);
        }
        $b['attachment_ids'] = $new;
    }

    private function num(mixed $v, float $min, float $max): float
    {
        $n = is_string($v) ? ReadingImportService::parseNum($v) : (is_int($v) || is_float($v) ? (float)$v : null);
        if ($n === null || !is_finite($n) || $n < $min || $n > $max) {
            throw new LocalizedException('errors.bill.amountInvalid', ['value' => is_scalar($v) ? (string)$v : ''], 'bill number');
        }
        return round($n, 4);
    }

    private function assertUtility(string $utility): void
    {
        if (!Utilities::supportsBillCheck($utility)) {
            throw new LocalizedException('errors.billCheck.unsupportedUtility', ['utility' => $utility], "bills for $utility");
        }
    }
}
