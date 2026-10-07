<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Http\NotFoundException;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;
use Energietracker\Support\LocalizedException;

/**
 * v3.1.0 (Paket H3, F1008/MKT-07) — Mietverhältnis und Nebenkostenabrechnungen.
 *
 * Für Mieter, die Heizung und Wasser über die Nebenkosten zahlen. Kein
 * Abrechnungsprogramm für Vermieter: Die App hält fest, was der Vermieter
 * berechnet, und rechnet daraus eine Hilfsrechnung (TenancyBudgetService).
 *
 * `tenancies.json` (Liste):
 *   {id: 't_<hex>', start, end?, label, landlord?, wohnflaeche_m2?,
 *    billing_anchor: 'MM-TT',
 *    prepayments: [{from, heating_eur_month, operating_eur_month}],
 *    prices: [{from, heat_eur_per_kwh?, warm_water_eur_per_m3?, cold_water_eur_per_m3?,
 *              source: statement|estimate, statement_id?}],
 *    fixed_costs: [{from, label, eur_per_year}],
 *    meter_ids: {heat: [waerme-Zähler], warm_water: [wasser], cold_water: [wasser]},
 *    notes}
 *
 * `tenancy_statements.json` (Liste):
 *   {id: 's_<hex>', tenancy_id, period_from, period_to, received_on?,
 *    total_cost_eur, prepaid_eur, result_eur (positiv = Nachzahlung),
 *    positions: [{label, category, amount_eur, consumption?, unit?}],
 *    heat?: {consumption, unit, cost_eur?}, co2?: {...}, new_prepayment?: {...},
 *    attachment_ids: [], booked, note, created_at}
 */
final class TenancyService
{
    public const CATEGORIES = ['heating', 'warm_water', 'cold_water', 'sewage', 'operating', 'other'];
    public const PRICE_FIELDS = ['heat_eur_per_kwh', 'warm_water_eur_per_m3', 'cold_water_eur_per_m3'];
    public const METER_ROLES = ['heat' => 'waerme', 'warm_water' => 'wasser', 'cold_water' => 'wasser'];

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private I18nService $i18n,
    ) {}

    // ── Mietverhältnisse ─────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $all = $this->store->read('tenancies.json', []);
        $all = is_array($all) ? array_values(array_filter($all, 'is_array')) : [];
        usort($all, fn($a, $b) => strcmp((string)($b['start'] ?? ''), (string)($a['start'] ?? '')));
        return $all;
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        foreach ($this->list() as $t) if (($t['id'] ?? null) === $id) return $t;
        return null;
    }

    /** @return array<string,mixed> */
    public function create(array $input): array
    {
        $t = ['id' => 't_' . bin2hex(random_bytes(6))] + $this->normalizeTenancy($input, null);
        $all = $this->store->read('tenancies.json', []);
        if (!is_array($all)) $all = [];
        $all[] = $t;
        $this->store->write('tenancies.json', array_values($all));
        return $t;
    }

    /** @return array<string,mixed> */
    public function update(string $id, array $input): array
    {
        $all = $this->store->read('tenancies.json', []);
        if (!is_array($all)) $all = [];
        foreach ($all as $i => $t) {
            if (!is_array($t) || ($t['id'] ?? null) !== $id) continue;
            $all[$i] = array_merge($t, $this->normalizeTenancy($input, $t));
            $this->store->write('tenancies.json', array_values($all));
            return $all[$i];
        }
        throw new NotFoundException($this->i18n->t('errors.tenancy.notFound'));
    }

    /** Löscht das Mietverhältnis samt seinen Abrechnungen (Belege werden frei). */
    public function delete(string $id): void
    {
        $all = $this->store->read('tenancies.json', []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($t) => is_array($t) && ($t['id'] ?? null) !== $id));
        if (count($kept) === count($all)) throw new NotFoundException($this->i18n->t('errors.tenancy.notFound'));
        foreach ($this->statements($id) as $s) AttachmentService::unlinkRef($this->store, 'tenancy_statement', (string)$s['id']);
        $st = $this->store->read('tenancy_statements.json', []);
        $this->store->write('tenancy_statements.json', array_values(array_filter(is_array($st) ? $st : [], fn($s) => is_array($s) && ($s['tenancy_id'] ?? null) !== $id)));
        $this->store->write('tenancies.json', $kept);
    }

    // ── Abrechnungen ─────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> neueste zuerst */
    public function statements(?string $tenancyId = null): array
    {
        $all = $this->store->read('tenancy_statements.json', []);
        $all = is_array($all) ? array_values(array_filter($all, 'is_array')) : [];
        if ($tenancyId !== null) $all = array_values(array_filter($all, fn($s) => ($s['tenancy_id'] ?? null) === $tenancyId));
        usort($all, fn($a, $b) => strcmp((string)($b['period_to'] ?? ''), (string)($a['period_to'] ?? '')));
        return $all;
    }

    /** @return list<array<string,mixed>> — 404, wenn es das Mietverhältnis nicht gibt */
    public function statementsOf(string $tenancyId): array
    {
        if ($this->get($tenancyId) === null) throw new NotFoundException($this->i18n->t('errors.tenancy.notFound'));
        return $this->statements($tenancyId);
    }

    /**
     * Legt eine Abrechnung an. `apply_prices: true` übernimmt die daraus
     * abgeleiteten Preise ins Mietverhältnis (gültig ab dem Tag nach dem
     * Zeitraum), `apply_prepayment: true` die neue Vorauszahlung.
     *
     * @return array<string,mixed>
     */
    public function createStatement(string $tenancyId, array $input): array
    {
        $t = $this->get($tenancyId) ?? throw new NotFoundException($this->i18n->t('errors.tenancy.notFound'));
        $s = ['id' => 's_' . bin2hex(random_bytes(6)), 'tenancy_id' => $tenancyId]
            + $this->normalizeStatement($input, null) + ['created_at' => date('c')];
        $this->linkAttachments($s, [], $input['attachment_ids'] ?? []);
        $all = $this->store->read('tenancy_statements.json', []);
        if (!is_array($all)) $all = [];
        $all[] = $s;
        $this->store->write('tenancy_statements.json', array_values($all));
        $this->applyToTenancy($t, $s, $input);
        return $s;
    }

    /** @return array<string,mixed> */
    public function updateStatement(string $tenancyId, string $sid, array $input): array
    {
        $t = $this->get($tenancyId) ?? throw new NotFoundException($this->i18n->t('errors.tenancy.notFound'));
        $all = $this->store->read('tenancy_statements.json', []);
        if (!is_array($all)) $all = [];
        foreach ($all as $i => $s) {
            if (!is_array($s) || ($s['id'] ?? null) !== $sid || ($s['tenancy_id'] ?? null) !== $tenancyId) continue;
            $next = array_merge($s, $this->normalizeStatement($input, $s));
            if (array_key_exists('attachment_ids', $input)) $this->linkAttachments($next, (array)($s['attachment_ids'] ?? []), $input['attachment_ids']);
            $all[$i] = $next;
            $this->store->write('tenancy_statements.json', array_values($all));
            $this->applyToTenancy($t, $next, $input);
            return $next;
        }
        throw new NotFoundException($this->i18n->t('errors.tenancy.statementNotFound'));
    }

    public function deleteStatement(string $tenancyId, string $sid): void
    {
        $all = $this->store->read('tenancy_statements.json', []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($s) => is_array($s) && !(($s['id'] ?? null) === $sid && ($s['tenancy_id'] ?? null) === $tenancyId)));
        if (count($kept) === count($all)) throw new NotFoundException($this->i18n->t('errors.tenancy.statementNotFound'));
        $this->store->write('tenancy_statements.json', $kept);
        AttachmentService::unlinkRef($this->store, 'tenancy_statement', $sid);
    }

    /**
     * Preise, die eine Abrechnung nahelegt: Kosten der Kategorie (samt
     * Grundkosten) geteilt durch den Verbrauch. Kaltwasser schließt Abwasser
     * ein (beides je m³ Frischwasser). Fehlt ein Verbrauch, fehlt der Preis.
     *
     * @return array<string,float>
     */
    public static function derivePrices(array $s): array
    {
        $out = [];
        $sum = function (array $cats, bool $consumption) use ($s): float {
            $t = 0.0;
            foreach ((array)($s['positions'] ?? []) as $p) {
                if (!in_array($p['category'] ?? '', $cats, true)) continue;
                $t += $consumption ? (float)($p['consumption'] ?? 0) : (float)($p['amount_eur'] ?? 0);
            }
            return $t;
        };
        $heatKwh = (float)($s['heat']['consumption'] ?? 0) * (strtolower((string)($s['heat']['unit'] ?? 'kWh')) === 'mwh' ? 1000 : 1);
        $heatCost = isset($s['heat']['cost_eur']) ? (float)$s['heat']['cost_eur'] : $sum(['heating'], false);
        if ($heatKwh > 0 && $heatCost > 0) $out['heat_eur_per_kwh'] = round($heatCost / $heatKwh, 4);
        $wwM3 = $sum(['warm_water'], true);
        if ($wwM3 > 0) $out['warm_water_eur_per_m3'] = round($sum(['warm_water'], false) / $wwM3, 4);
        $cwM3 = $sum(['cold_water'], true);
        if ($cwM3 > 0) $out['cold_water_eur_per_m3'] = round($sum(['cold_water', 'sewage'], false) / $cwM3, 4);
        return $out;
    }

    /** Gültiger Eintrag einer datierten Liste (`from` ≤ Datum, der späteste). */
    public static function validAt(array $list, string $date): ?array
    {
        $best = null;
        foreach ($list as $e) {
            if (!is_array($e) || (string)($e['from'] ?? '') > $date) continue;
            if ($best === null || (string)$e['from'] >= (string)$best['from']) $best = $e;
        }
        return $best;
    }

    /** Preis eines Feldes am Datum (der späteste Eintrag, der das Feld hat). */
    public static function priceAt(array $tenancy, string $field, string $date): ?float
    {
        $best = null;
        foreach ((array)($tenancy['prices'] ?? []) as $e) {
            if (!is_array($e) || !isset($e[$field]) || (string)($e['from'] ?? '') > $date) continue;
            if ($best === null || (string)$e['from'] >= (string)$best['from']) $best = $e;
        }
        return $best === null ? null : (float)$best[$field];
    }

    /** Pauschale Umlagen im Jahr am Datum: je Bezeichnung der jüngste Eintrag. */
    public static function fixedCostsAt(array $tenancy, string $date): float
    {
        $byLabel = [];
        foreach ((array)($tenancy['fixed_costs'] ?? []) as $e) {
            if (!is_array($e) || (string)($e['from'] ?? '') > $date) continue;
            $k = (string)($e['label'] ?? '');
            if (!isset($byLabel[$k]) || (string)$e['from'] >= (string)$byLabel[$k]['from']) $byLabel[$k] = $e;
        }
        return array_sum(array_map(fn($e) => (float)($e['eur_per_year'] ?? 0), $byLabel));
    }

    // ── intern ───────────────────────────────────────────────────────────

    private function applyToTenancy(array $t, array $s, array $input): void
    {
        $patch = [];
        if (!empty($input['apply_prices'])) {
            $prices = self::derivePrices($s);
            if ($prices !== []) {
                $from = date('Y-m-d', (int)strtotime((string)$s['period_to'] . ' +1 day'));
                $list = array_values(array_filter((array)($t['prices'] ?? []), fn($p) => ($p['statement_id'] ?? null) !== $s['id']));
                $list[] = ['from' => $from] + $prices + ['source' => 'statement', 'statement_id' => $s['id']];
                $patch['prices'] = $list;
            }
        }
        if (!empty($input['apply_prepayment']) && is_array($s['new_prepayment'] ?? null)) {
            $np = $s['new_prepayment'];
            $list = array_values(array_filter((array)($t['prepayments'] ?? []), fn($p) => ($p['from'] ?? null) !== $np['from']));
            $list[] = $np;
            $patch['prepayments'] = $list;
        }
        if ($patch !== []) $this->update((string)$t['id'], $patch);
    }

    private function linkAttachments(array &$s, array $old, mixed $new): void
    {
        $new = array_values(array_unique(array_filter(array_map('strval', is_array($new) ? $new : []), fn($x) => $x !== '')));
        foreach (array_diff($old, $new) as $gone) {
            $all = $this->store->read(AttachmentService::INDEX, []);
            foreach (is_array($all) ? $all : [] as $i => $a) {
                if (($a['id'] ?? null) === $gone) { $all[$i]['ref'] = null; $all[$i]['unlinked_at'] = date('c'); }
            }
            $this->store->write(AttachmentService::INDEX, array_values($all));
        }
        foreach ($new as $id) {
            AttachmentService::link($this->store, $id, null, ['type' => 'tenancy_statement', 'id' => (string)$s['id']]);
        }
        $s['attachment_ids'] = $new;
    }

    /** @return array<string,mixed> */
    private function normalizeTenancy(array $in, ?array $old): array
    {
        $isNew = $old === null;
        $out = [];
        if ($isNew || array_key_exists('start', $in)) $out['start'] = $this->date($in['start'] ?? null, 'errors.tenancy.dateInvalid');
        if (array_key_exists('end', $in)) $out['end'] = ($in['end'] ?? '') === '' ? null : $this->date($in['end'], 'errors.tenancy.dateInvalid');
        $start = $out['start'] ?? $old['start'];
        $end = array_key_exists('end', $out) ? $out['end'] : ($old['end'] ?? null);
        if ($end !== null && $end < $start) throw new LocalizedException('errors.tenancy.endBeforeStart', [], 'tenancy end < start');
        foreach (['label' => 120, 'landlord' => 120, 'notes' => 2000] as $f => $max) {
            if ($isNew || array_key_exists($f, $in)) $out[$f] = mb_substr(trim((string)($in[$f] ?? '')), 0, $max);
        }
        if ($isNew || array_key_exists('wohnflaeche_m2', $in)) {
            $v = $in['wohnflaeche_m2'] ?? null;
            $out['wohnflaeche_m2'] = $v === null || $v === '' ? null : $this->num($v, 1, 10000);
        }
        if ($isNew || array_key_exists('billing_anchor', $in)) {
            $a = (string)($in['billing_anchor'] ?? '01-01');
            if ($a === '') $a = '01-01';
            if (!SettingsService::isMonthDay($a)) throw new LocalizedException('errors.tenancy.anchorInvalid', ['value' => $a], 'anchor');
            $out['billing_anchor'] = $a;
        }
        if ($isNew || array_key_exists('prepayments', $in)) {
            $out['prepayments'] = $this->datedList($in['prepayments'] ?? [], fn(array $e) => [
                'heating_eur_month'   => $this->num($e['heating_eur_month'] ?? 0, 0, 100000),
                'operating_eur_month' => $this->num($e['operating_eur_month'] ?? 0, 0, 100000),
            ]);
        }
        if ($isNew || array_key_exists('prices', $in)) {
            $out['prices'] = $this->datedList($in['prices'] ?? [], function (array $e): array {
                $p = [];
                foreach (self::PRICE_FIELDS as $f) {
                    if (isset($e[$f]) && $e[$f] !== '') $p[$f] = $this->num($e[$f], 0, 1000);
                }
                $p['source'] = in_array($e['source'] ?? null, ['statement', 'estimate'], true) ? $e['source'] : 'estimate';
                if (!empty($e['statement_id'])) $p['statement_id'] = (string)$e['statement_id'];
                return $p;
            });
        }
        if ($isNew || array_key_exists('fixed_costs', $in)) {
            $out['fixed_costs'] = $this->datedList($in['fixed_costs'] ?? [], fn(array $e) => [
                'label'        => mb_substr(trim((string)($e['label'] ?? '')), 0, 120),
                'eur_per_year' => $this->num($e['eur_per_year'] ?? 0, 0, 1000000),
            ]);
        }
        // v3.1.0 (H4, MKT-15) — Kürzungen der CO₂-Erstattung: eigene Geräte (Gasherd, § 6 Abs. 3)
        // und öffentlich-rechtliche Vorgaben (§ 9: eine → halbiert, beide → keine Aufteilung)
        if ($isNew || array_key_exists('co2_own_appliances', $in)) $out['co2_own_appliances'] = !empty($in['co2_own_appliances']);
        if ($isNew || array_key_exists('co2_restriction', $in)) {
            $r = (string)($in['co2_restriction'] ?? 'none');
            $out['co2_restriction'] = in_array($r, ['none', 'one', 'both'], true) ? $r : 'none';
        }
        if ($isNew || array_key_exists('meter_ids', $in)) {
            $ids = [];
            foreach (self::METER_ROLES as $role => $utility) {
                $ids[$role] = [];
                foreach ((array)($in['meter_ids'][$role] ?? []) as $mid) {
                    $mid = (string)$mid;
                    if ($mid === '') continue;
                    if (!$this->meters->get($utility, $mid)) {
                        throw new LocalizedException('errors.tenancy.meterNotFound', ['id' => $mid], 'tenancy meter');
                    }
                    if (!in_array($mid, $ids[$role], true)) $ids[$role][] = $mid;
                }
            }
            $out['meter_ids'] = $ids;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function normalizeStatement(array $in, ?array $old): array
    {
        $isNew = $old === null;
        $out = [];
        foreach (['period_from', 'period_to'] as $f) {
            if ($isNew || array_key_exists($f, $in)) $out[$f] = $this->date($in[$f] ?? null, 'errors.tenancy.dateInvalid');
        }
        $from = $out['period_from'] ?? $old['period_from'];
        $to = $out['period_to'] ?? $old['period_to'];
        if ($from > $to) throw new LocalizedException('errors.tenancy.periodInvalid', ['from' => $from, 'to' => $to], 'statement period');
        if (array_key_exists('received_on', $in) || $isNew) {
            $out['received_on'] = ($in['received_on'] ?? '') === '' || !isset($in['received_on']) ? null : $this->date($in['received_on'], 'errors.tenancy.dateInvalid');
        }
        foreach (['total_cost_eur', 'prepaid_eur'] as $f) {
            if ($isNew || array_key_exists($f, $in)) $out[$f] = $this->num($in[$f] ?? 0, 0, 1000000);
        }
        $total = $out['total_cost_eur'] ?? (float)$old['total_cost_eur'];
        $prepaid = $out['prepaid_eur'] ?? (float)$old['prepaid_eur'];
        if (array_key_exists('result_eur', $in) && $in['result_eur'] !== null && $in['result_eur'] !== '') {
            $out['result_eur'] = $this->num($in['result_eur'], -1000000, 1000000);
        } elseif ($isNew || isset($out['total_cost_eur']) || isset($out['prepaid_eur'])) {
            $out['result_eur'] = round($total - $prepaid, 2);
        }
        if ($isNew || array_key_exists('positions', $in)) {
            $pos = [];
            foreach ((array)($in['positions'] ?? []) as $p) {
                if (!is_array($p)) throw new LocalizedException('errors.tenancy.listInvalid', ['field' => 'positions'], 'positions');
                $cat = (string)($p['category'] ?? 'other');
                if (!in_array($cat, self::CATEGORIES, true)) $cat = 'other';
                $e = [
                    'label'      => mb_substr(trim((string)($p['label'] ?? '')), 0, 120),
                    'category'   => $cat,
                    'amount_eur' => $this->num($p['amount_eur'] ?? 0, -1000000, 1000000),
                ];
                if (isset($p['consumption']) && $p['consumption'] !== '') $e['consumption'] = $this->num($p['consumption'], 0, 10000000);
                if (isset($p['unit']) && $p['unit'] !== '') $e['unit'] = mb_substr((string)$p['unit'], 0, 12);
                $pos[] = $e;
            }
            $out['positions'] = $pos;
        }
        if ($isNew || array_key_exists('heat', $in)) {
            $h = $in['heat'] ?? null;
            $out['heat'] = is_array($h) && ($h['consumption'] ?? '') !== '' ? array_filter([
                'consumption' => $this->num($h['consumption'], 0, 10000000),
                'unit'        => in_array($h['unit'] ?? 'kWh', ['kWh', 'MWh'], true) ? ($h['unit'] ?? 'kWh') : 'kWh',
                'cost_eur'    => isset($h['cost_eur']) && $h['cost_eur'] !== '' ? $this->num($h['cost_eur'], 0, 1000000) : null,
            ], fn($v) => $v !== null) : null;
        }
        if ($isNew || array_key_exists('new_prepayment', $in)) {
            $np = $in['new_prepayment'] ?? null;
            $out['new_prepayment'] = is_array($np) && ($np['from'] ?? '') !== '' ? [
                'from'                => $this->date($np['from'], 'errors.tenancy.dateInvalid'),
                'heating_eur_month'   => $this->num($np['heating_eur_month'] ?? 0, 0, 100000),
                'operating_eur_month' => $this->num($np['operating_eur_month'] ?? 0, 0, 100000),
            ] : null;
        }
        // v3.1.0 (H4, MKT-15) — CO₂-Angaben der Heizkostenabrechnung (§ 7 CO2KostAufG)
        if ($isNew || array_key_exists('co2', $in)) {
            $c = $in['co2'] ?? null;
            $co2 = [];
            if (is_array($c)) {
                foreach (['emissions_kg' => [0, 1e7], 'cost_eur' => [0, 1e6], 'landlord_amount_eur' => [0, 1e6]] as $f => [$lo, $hi]) {
                    if (isset($c[$f]) && $c[$f] !== '') $co2[$f] = $this->num($c[$f], $lo, $hi);
                }
                if (isset($c['stage']) && $c['stage'] !== '') $co2['stage'] = (int)$this->num($c['stage'], 1, 10);
                if (isset($c['landlord_share_pct']) && $c['landlord_share_pct'] !== '') $co2['landlord_share_pct'] = (int)$this->num($c['landlord_share_pct'], 0, 100);
            }
            $out['co2'] = $co2 === [] ? null : $co2;
        }
        if ($isNew || array_key_exists('booked', $in)) $out['booked'] = !empty($in['booked']);
        if ($isNew || array_key_exists('note', $in)) $out['note'] = mb_substr((string)($in['note'] ?? ''), 0, 2000);
        if ($isNew) $out['attachment_ids'] = [];
        return $out;
    }

    /**
     * Datierte Liste prüfen und nach `from` sortieren.
     *
     * @param callable(array): array $row
     * @return list<array<string,mixed>>
     */
    private function datedList(mixed $list, callable $row): array
    {
        if (!is_array($list)) throw new LocalizedException('errors.tenancy.listInvalid', ['field' => ''], 'dated list');
        $out = [];
        foreach ($list as $e) {
            if (!is_array($e)) throw new LocalizedException('errors.tenancy.listInvalid', ['field' => ''], 'dated list entry');
            $out[] = ['from' => $this->date($e['from'] ?? null, 'errors.tenancy.dateInvalid')] + $row($e);
        }
        usort($out, fn($a, $b) => strcmp($a['from'], $b['from']));
        return $out;
    }

    private function date(mixed $v, string $key): string
    {
        $s = is_string($v) ? trim($v) : '';
        if (!Dates::isIsoDate($s)) throw new LocalizedException($key, ['date' => $s], 'tenancy date');
        return $s;
    }

    private function num(mixed $v, float $min, float $max): float
    {
        $n = is_string($v) ? ReadingImportService::parseNum($v) : (is_int($v) || is_float($v) ? (float)$v : null);
        if ($n === null || !is_finite($n) || $n < $min || $n > $max) {
            throw new LocalizedException('errors.tenancy.amountInvalid', ['value' => is_scalar($v) ? (string)$v : ''], 'tenancy number');
        }
        return round($n, 4);
    }
}
