<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp\Tools;

use Energietracker\Config\Utilities;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\Num;
use Energietracker\Services\Mcp\Provenance;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolGroup;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037, Paket lesen) — Verbrauch und Prognose (Konzept §3.2).
 *
 *   get_consumption  Monate, Jahre oder Abrechnungsjahre eines Zählers bzw. einer
 *                    Gruppe (ConsumptionService::forMeter, GET …/meters/{id}/consumption)
 *                    oder der ganzen Art (forUtility, GET …/consumption, ohne
 *                    Subzähler); Abrechnungsjahre aus BillingPeriodService
 *                    (GET …/billing-periods). Je Zeile `basis` (gemessen, verteilt,
 *                    geschätzt) wie die Markierung „≈“ der Oberfläche, Summe als Zahl-Objekt.
 *   get_forecast     ForecastService::forMeter (GET …/meters/{id}/forecast): alles
 *                    geschätzt, mit Band, Modell und Güte.
 */
final class ConsumptionTools extends ToolGroup
{
    use ReadSupport;

    /** höchstens so viele Zeilen (die jüngsten; danach `truncated: true`) */
    public const MAX_ROWS = 60;
    public const GRANULARITIES = ['month', 'year', 'billing_period'];
    public const DEFAULT_FORECAST_MONTHS = 12;
    public const ACCOUNTING_KINDS = ['consumption', 'feed_in', 'generation'];
    private const YM = '^\d{4}-(0[1-9]|1[0-2])$';

    public function tools(): array
    {
        $ro = ['readOnlyHint' => true, 'idempotentHint' => true];
        return [
            new Tool('get_consumption', Tool::READ, self::obj([
                'utility'       => self::utilityArg(),
                'meter'         => self::meterArg(),
                'granularity'   => ['type' => 'string', 'enum' => self::GRANULARITIES, 'default' => 'month'],
                'from'          => ['type' => 'string', 'pattern' => self::YM],
                'to'            => ['type' => 'string', 'pattern' => self::YM],
                'heat_adjusted' => ['type' => 'boolean', 'default' => false],
            ], ['utility']), function (array $a, McpContext $c): array {
                $this->textLocale = $c->locale;
                return $this->consumption($a, $c);
            }, self::consumptionSchema(), $ro),
            new Tool('get_forecast', Tool::READ, self::obj([
                'utility' => self::utilityArg(),
                'meter'   => self::meterArg(),
                'months'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => self::DEFAULT_FORECAST_MONTHS],
            ], ['utility', 'meter']), fn(array $a, McpContext $c): array => $this->forecast($a), self::forecastSchema(), $ro),
        ];
    }

    // ── get_consumption ──────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function consumption(array $args, McpContext $ctx): array
    {
        $u = (string)$args['utility'];
        $from = $args['from'] ?? null;
        $to = $args['to'] ?? null;
        if ($from !== null && $to !== null && $from > $to) {
            throw new LocalizedException('errors.mcp.rangeInvalid', ['from' => $from, 'to' => $to], 'mcp consumption');
        }
        $granularity = (string)($args['granularity'] ?? 'month');
        $service = $this->services->get(ConsumptionService::class);
        $target = isset($args['meter']) ? $this->target($u, (string)$args['meter']) : null;
        // dieselben Zeilen wie die Oberfläche: Zähler/Gruppe bzw. die Summe der Art ohne Subzähler;
        // die Herkunft aus den Zeilen der Zähler dahinter (eine Summe kennt keine geschätzte Stütze)
        $monthly = $target !== null ? $service->forMeter($u, $target) : $service->forUtility($u)['monthly_total'];
        $members = $this->membersOf($u, $target);
        $memberRows = $target !== null && empty($target['is_group']) ? $monthly : $this->memberRows($u, $members);
        // v3.13.0 — Herkunft folgt der schwächsten Eingabe: ein Monat einer Summe ist geschätzt, sobald ein Zähler dahinter
        // ihn an einer geschätzten Ablesung verteilt (bis dahin nur, wenn die Summe selbst nicht „gemessen“ hieß); sein
        // Preis ist angenommen, sobald ein Vertrag dahinter fortgeschrieben ist oder nach der Preisgarantie rechnet
        $priceOf = $this->priceBasisOf($u);
        $estimatedYm = [];
        $assumedYm = [];
        foreach ($memberRows as $r) {
            if (Provenance::month($r) === 'estimated') $estimatedYm[(string)$r['ym']] = true;
            if (($r['contract_id'] ?? null) !== null && $priceOf($r) === 'assumed') $assumedYm[(string)$r['ym']] = true;
        }
        $vf = self::valueField($u);
        $unit = self::consumptionUnit($u);
        $cur = $this->currency();
        $money = self::moneyField($u);
        // v3.12.0 (Skeptiker M4) — Monate, für die ein Vertrag oder Preis gilt; sonst keine 0 € als Kosten
        $pricedYm = [];
        // v3.13.0 — Monate, in denen ein Vertrag ohne Arbeitspreis rechnet: kein Betrag (nicht 0 €, nicht nur der
        // Grundpreis), sondern `price_missing` (ConsumptionService::markPriceMissing)
        $noPriceYm = [];
        foreach ($memberRows as $r) {
            if (($r['contract_id'] ?? null) !== null || abs((float)($r['cost'] ?? 0.0)) >= 0.005) $pricedYm[(string)$r['ym']] = true;
            if (!empty($r['price_missing'])) $noPriceYm[(string)$r['ym']] = true;
        }
        foreach ($monthly as $r) if (!empty($r['price_missing'])) $noPriceYm[(string)$r['ym']] = true;
        if ($money !== 'cost') $noPriceYm = [];   // Einspeisung und Erzeugung: kein Arbeitspreis nötig
        $isSub = $target !== null && empty($target['is_group']) && !empty($target['parent_meter_id']);
        $inRange = fn(string $ym): bool => ($from === null || $ym >= $from) && ($to === null || $ym <= $to);
        $months = array_values(array_filter($monthly, fn($r) => $inRange((string)($r['ym'] ?? ''))));
        $cost = $this->costBasisOf($u);

        if ($granularity === 'billing_period') {
            if (!Utilities::supportsBillCheck($u)) {
                throw new LocalizedException('errors.billCheck.unsupportedUtility', ['utility' => $u], 'mcp consumption billing');
            }
            $billing = $this->services->get(BillingPeriodService::class);
            $periods = ($target !== null ? $billing->forTarget($u, $target, false, $ctx->today) : $billing->forUtility($u, false, $ctx->today))['periods'];
            $periods = array_values(array_filter($periods, fn($p) => ($to === null || substr((string)$p['from'], 0, 7) <= $to)
                && ($from === null || substr((string)$p['until'], 0, 7) >= $from)));
            $rows = $this->billingRows($u, $periods, $members, $memberRows);
            $window = $rows !== [] ? [$rows[0]['from'], date('Y-m-d', (int)strtotime($rows[count($rows) - 1]['as_of'] . ' +1 day'))] : null;
        } elseif ($granularity === 'year') {
            $rows = [];
            $lastYm = $months !== [] ? (string)end($months)['ym'] : '';
            foreach (self::byYear($months) as $year => $ofYear) {
                $start = max("$year-01-01", (string)$ofYear[0]['ym'] . '-01');
                $parts = $this->windowParts($u, $members, $memberRows, $start, self::afterMonth((string)end($ofYear)['ym'])) ?: ['measured' => 1.0];
                $priced = array_filter($ofYear, fn($r) => isset($pricedYm[(string)$r['ym']])) !== [];
                $noPrice = array_filter($ofYear, fn($r) => isset($noPriceYm[(string)$r['ym']])) !== [];
                $rows[] = self::compact([
                    'period'         => (string)$year,
                    'value'          => round(array_sum(array_map(fn($r) => (float)($r[$vf] ?? 0.0), $ofYear)), 3),
                    'days'           => array_sum(array_map(fn($r) => (int)($r['days'] ?? 0), $ofYear)),
                    'months'         => count($ofYear),
                    'basis'          => Provenance::weakest(...array_keys($parts)),
                ]) + ($money !== null && $priced && $noPrice ? ['price_missing' => true] : [])
                    + ($money !== null && $priced && !$noPrice ? [$money => round(array_sum(array_map(fn($r) => (float)($r['cost'] ?? 0.0), $ofYear)), 2),
                        // v3.13.0 — Herkunft des Geldes: Menge und Preis (bis dahin hieß ein Jahr aus angenommenen Preisen gemessen)
                        'money_basis' => $this->moneyBasis($u, $members, $memberRows, $start, self::afterMonth((string)end($ofYear)['ym']))] : [])
                    + (!$priced && $money !== null ? ['no_contract' => true] + ($isSub ? ['included_in_parent' => true] : []) : [])
                    + ['measured_share' => round((float)($parts['measured'] ?? 0.0), 3)];
            }
            $window = $months !== [] ? [(string)$months[0]['ym'] . '-01', self::afterMonth((string)end($months)['ym'])] : null;
        } else {
            $heat = ($args['heat_adjusted'] ?? false) === true;
            $last = $monthly !== [] ? (string)end($monthly)['ym'] : '';
            $rows = array_map(fn(array $r): array => self::monthRow($r, $vf, $unit, $heat, isset($estimatedYm[(string)$r['ym']]), $money,
                isset($pricedYm[(string)$r['ym']]), $isSub, $last, isset($assumedYm[(string)$r['ym']]), isset($noPriceYm[(string)$r['ym']])), $months);
            $window = $months !== [] ? [(string)$months[0]['ym'] . '-01', self::afterMonth((string)end($months)['ym'])] : null;
        }

        $truncated = count($rows) > self::MAX_ROWS;
        if ($truncated) $rows = array_slice($rows, -self::MAX_ROWS);
        $total = null;
        if ($rows !== [] && $window !== null) {
            $qty = array_sum(array_column($rows, 'value'));
            $sum = $money !== null ? array_sum(array_column($rows, $money)) : 0.0;
            // v3.13.0 — fehlt in einer Zeile der Arbeitspreis, ist auch die Summe kein Kostenbetrag
            $totalNoPrice = array_filter($rows, fn($r) => !empty($r['price_missing'])) !== [];
            // v3.13.0 — Abrechnungsjahre: die Summe aus den Anteilen ihrer Zeilen (nach Menge bzw. Betrag), nie stärker als
            // die schwächste Zeile. Bis dahin aus den Rändern des ganzen Fensters: An zwei Ablesungen hieß sie gemessen,
            // auch über verteilte Zeilen, und ohne `parts`.
            $byRows = $granularity === 'billing_period';
            $total = self::compact([
                'quantity' => Provenance::num($qty, $unit, ($byRows ? self::partsOfRows($rows, 'value', 'basis')
                    : $this->windowParts($u, $members, $memberRows, $window[0], $window[1])) ?: ['measured' => 1.0]),
                (string)($money ?? 'cost') => $money !== null && !$totalNoPrice && abs($sum) > 0 ? Provenance::money($sum, $cur,
                    ($byRows ? self::partsOfRows($rows, (string)$money, 'money_basis')
                        : $this->windowParts($u, $members, $memberRows, $window[0], $window[1], 'cost', $cost, 'entered')) ?: ['entered' => 1.0]) : null,
                'price_missing' => $totalNoPrice ? true : null,
            ]);
        }
        return self::compact([
            'utility'     => $u,
            'accounting_kind' => Utilities::accountingKind($u),
            'meter'       => $target !== null ? (string)$target['id'] : null,
            'unit'        => $unit,
            'currency'    => $cur,
            'granularity' => $granularity,
            'rows'        => $rows,
            'total'       => $truncated ? null : $total,
            'truncated'   => $truncated ? true : null,
        ]) + ['rows' => []];
    }

    /**
     * v3.13.0 — Anteile je Herkunft einer Summe über Zeilen: je Zeile ihr gemessener Anteil (`measured_share`) als
     * gemessen, der Rest als ihre Herkunft (die schwächste der Zeile), gewichtet nach `$field`. So ist die Summe nie
     * stärker als ihre schwächste Zeile (eine Zeile mit mehreren schwachen Teilen zählt ganz zur schwächsten).
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,float>
     */
    private static function partsOfRows(array $rows, string $field, string $basisField): array
    {
        $acc = []; $total = 0.0;
        foreach ($rows as $r) {
            $b = $r[$basisField] ?? null;
            if (!is_string($b) || !Provenance::isBasis($b) || !is_numeric($r[$field] ?? null)) continue;
            $w = abs((float)$r[$field]);
            $ms = $basisField === 'basis' && is_numeric($r['measured_share'] ?? null) ? max(0.0, min(1.0, (float)$r['measured_share'])) : ($b === 'measured' ? 1.0 : 0.0);
            if ($b === 'measured') $ms = 1.0;
            if ($ms > 0.0) $acc['measured'] = ($acc['measured'] ?? 0.0) + $w * $ms;
            if ($ms < 1.0) $acc[$b] = ($acc[$b] ?? 0.0) + $w * (1.0 - $ms);
            $total += $w;
        }
        if ($total <= 1e-9) return $acc === [] ? [] : array_map(fn() => 1.0 / count($acc), $acc);
        return array_filter(array_map(fn(float $v) => $v / $total, $acc), fn($s) => $s > 0.0);
    }

    /**
     * Eine Monatszeile: Menge und Kosten wie die Tabelle, Herkunft wie die
     * Markierung „≈“ (in einer Summe geschätzt, wenn ein Zähler dahinter den Monat
     * an einer geschätzten Ablesung verteilt); bereinigter Wert (Heizmodell) als
     * eigene Schätzung. v3.12.0: Geld als `cost`, bei Einspeisung `revenue`, bei Erzeugung keins (H4);
     * ohne Vertrag oder Preis kein Betrag, sondern `no_contract` (M4); `bonus` erklärt negative Kosten
     * (N3); `complete: false` und `covered_until` für einen angebrochenen Monat (M3).
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function monthRow(array $r, string $vf, string $unit, bool $heat, bool $estimated = false, ?string $money = 'cost',
                                     bool $priced = true, bool $isSub = false, string $lastYm = '', bool $assumed = false, bool $noPrice = false): array
    {
        // v3.13.0 — ein Vertrag ohne Arbeitspreis: kein Kostenbetrag, sondern `price_missing` (die Menge bleibt)
        $priced = $priced && !($noPrice && $money === 'cost');
        $noPrice = $noPrice && $money === 'cost';
        // v3.13.0 — geschätzt, sobald ein Zähler dahinter den Monat an einer geschätzten Ablesung verteilt (eine Summe
        // mit gemessenem Anteil hieß bis dahin trotzdem gemessen); Geld mit der schwächeren Herkunft aus Menge und Preis
        $basis = $estimated ? 'estimated' : Provenance::month($r);
        $assumed = $assumed || !empty($r['contract_assumed']);
        $ym = (string)$r['ym'];
        $days = (int)($r['days'] ?? 0);
        $dim = preg_match('/^\d{4}-\d{2}$/', $ym) ? (int)date('t', (int)strtotime("$ym-01")) : 0;
        $partial = $days > 0 && $days < $dim;
        return self::compact([
            'period'           => $ym,
            'value'            => round((float)($r[$vf] ?? 0.0), 3),
            'cost'             => $money === 'cost' && $priced ? round((float)($r['cost'] ?? 0.0), 2) : null,
            'revenue'          => $money === 'revenue' && $priced ? round((float)($r['cost'] ?? 0.0), 2) : null,
            'no_contract'      => $money !== null && !$priced && !$noPrice ? true : null,
            'included_in_parent' => $money !== null && !$priced && !$noPrice && $isSub ? true : null,
            'price_missing'    => $noPrice ? true : null,
            'bonus'            => is_numeric($r['bonus_eur'] ?? null) && abs((float)$r['bonus_eur']) >= 0.005 ? round((float)$r['bonus_eur'], 2) : null,
            'days'             => $days,
            'complete'         => $partial ? false : null,
            'covered_until'    => $partial && $ym === $lastYm ? sprintf('%s-%02d', $ym, $days) : null,
            'basis'            => $basis,
            'measured_share'   => is_numeric($r['measured_share'] ?? null) ? round((float)$r['measured_share'], 3) : null,
            'reading_gap_days' => is_int($r['reading_gap_days'] ?? null) ? $r['reading_gap_days'] : null,
            // Preis eines fortgeschriebenen Vertrags oder nach der Preisgarantie (sonst eingetragen, ohne Feld)
            'price_basis'      => $assumed ? 'assumed' : null,
            'money_basis'      => $money !== null && $priced ? Provenance::weakest($basis, $assumed ? 'assumed' : 'entered') : null,
            'pre_baseline'     => !empty($r['pre_baseline']) ? true : null,
            'heat_adjusted'    => $heat && is_numeric($r['heat_adjusted'] ?? null)
                ? Num::of((float)$r['heat_adjusted'], $unit, 'estimated', ['derived' => true]) : null,
        ]);
    }

    /**
     * Monatszeilen je Kalenderjahr (Reihenfolge bleibt). Die Herkunft eines Jahrs
     * ist die schwächste seiner Monate, über ganze Ablesungsabstände gemessen
     * (windowParts), dazu der gemessene Anteil an der Menge.
     *
     * @param list<array<string,mixed>> $months
     * @return array<string,list<array<string,mixed>>>
     */
    private static function byYear(array $months): array
    {
        $by = [];
        foreach ($months as $m) $by[substr((string)$m['ym'], 0, 4)][] = $m;
        return $by;
    }

    // ── get_forecast ─────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function forecast(array $args): array
    {
        $u = (string)$args['utility'];
        $target = $this->target($u, (string)$args['meter']);
        $n = (int)($args['months'] ?? self::DEFAULT_FORECAST_MONTHS);
        $f = $this->services->get(ForecastService::class)->forMeter($u, $target, ['forecast_months' => $n]);
        $unit = self::consumptionUnit($u);
        $head = ['utility' => $u, 'accounting_kind' => Utilities::accountingKind($u), 'meter' => (string)$target['id'], 'unit' => $unit, 'currency' => $this->currency()];
        // v3.13.0 (Paket vertrag, #40) — additiv: ein Zählwerk einer Gruppe mit Gruppenvertrag (HT/NT); Kosten mit
        // Grundpreis und Abschlag liefert die Prognose dieser Gruppe (meter = group_id)
        $gid = (string)($target['meter_group_id'] ?? '');
        if ($gid !== '' && empty($target['is_group']) && $this->services->get(ConsumptionService::class)->contractScope($u, $target)[1] !== null) $head['priced_by_group'] = $gid;
        if (empty($f['valid'])) return $head + ['valid' => false, 'reason' => 'too_few_months'];

        $vf = self::valueField($u);
        // v3.12.0 (Skeptiker H4) — Einspeisung: Vergütung statt Kosten; Erzeugung: kein Geld
        $money = self::moneyField($u);
        $rows = [];
        $isSub = !empty($target['parent_meter_id']);
        $forecastRows = array_slice((array)$f['forecast'], 0, $n);
        // v3.13.0 — Monate, in denen der Vertrag keinen Arbeitspreis hat: kein Betrag, sondern `price_missing`
        $noPriceYm = $money === 'cost' ? $this->services->get(ConsumptionService::class)->priceMissingIn($u, $target, $forecastRows) : [];
        foreach ($forecastRows as $r) {
            // v3.12.0 — ohne Vertrag kein Betrag, wie an der Monatszeile; bis dahin `cost: 0`
            $priced = is_string($r['contract_id'] ?? null) && $r['contract_id'] !== '';
            $noPrice = $priced && isset($noPriceYm[(string)$r['ym']]);
            $rows[] = self::compact([
                'ym'    => (string)$r['ym'],
                'value' => round((float)($r[$vf] ?? 0.0), 3),
                'low'   => is_numeric($r['band_low'] ?? null) ? round((float)$r['band_low'], 3) : null,
                'high'  => is_numeric($r['band_high'] ?? null) ? round((float)$r['band_high'], 3) : null,
                'cost'  => $money === 'cost' && $priced && !$noPrice ? round((float)($r['cost_estimated'] ?? 0.0), 2) : null,
                'revenue' => $money === 'revenue' && $priced ? round((float)($r['cost_estimated'] ?? 0.0), 2) : null,
                'no_contract' => $money !== null && !$priced ? true : null,
                'included_in_parent' => $money !== null && !$priced && $isSub ? true : null,
                'price_missing' => $noPrice ? true : null,
                'basis' => 'estimated',
            ]);
        }
        $anyNoPrice = array_filter($rows, fn($r) => !empty($r['price_missing'])) !== [];
        $anyPriced = $money !== null && !$anyNoPrice && array_column($rows, $money) !== [];
        $annual = is_array($f['annual'] ?? null) ? $f['annual'] : null;
        $method = (string)($f['forecast'][0]['method'] ?? '');
        $warnings = array_values(array_unique(array_map(fn($w) => (string)($w['code'] ?? ''), (array)($f['warnings'] ?? []))));
        return $head + self::compact([
            'valid'    => true,
            'months'   => $rows,
            'total'    => self::compact([
                'quantity' => Num::of(array_sum(array_column($rows, 'value')), $unit, 'estimated', ['derived' => true]),
                (string)($money ?? 'cost') => $anyPriced ? Num::money(array_sum(array_column($rows, $money)), $this->currency(), 'estimated', ['derived' => true]) : null,
                'no_contract' => $money !== null && !$anyPriced && !$anyNoPrice ? true : null,
                'price_missing' => $anyNoPrice ? true : null,
            ]),
            // die ersten zwölf Monate mit Band (Wetter und Streuung), ohne Band mit dem Grund
            'annual'   => $annual !== null && is_numeric($annual['value'] ?? null) ? Num::of((float)$annual['value'], $unit, 'estimated',
                array_filter(['range' => is_numeric($annual['low'] ?? null) && is_numeric($annual['high'] ?? null) ? [(float)$annual['low'], (float)$annual['high']] : null,
                    'uncertain' => is_string($annual['withheld'] ?? null) ? [$annual['withheld']] : null])) : null,
            'model'    => self::compact([
                'method'           => str_starts_with($method, 'blend(') ? 'blend' : $method,
                'regression'       => is_string($f['regression']['model'] ?? null) ? $f['regression']['model'] : null,
                'heat_model_basis' => is_string($f['heat_model']['basis'] ?? null) ? $f['heat_model']['basis'] : null,
                'hdd_source'       => is_string($f['hdd_source'] ?? null) ? $f['hdd_source'] : null,
                'quality'          => is_string($f['fit_quality']['model_quality'] ?? null) ? $f['fit_quality']['model_quality'] : null,
                // Güte: Anteil der Heizkurve an den ersten zwölf Monaten und Bestimmtheit der Kurve
                'heat_curve_share' => is_numeric($f['blend_effective'] ?? null) ? Num::of((float)$f['blend_effective'], 'ratio', 'estimated') : null,
                'r2'               => is_numeric($f['regression']['r2'] ?? null) && !empty($f['regression']['valid'])
                    ? Num::of((float)$f['regression']['r2'], 'ratio', 'estimated') : null,
            ]),
            'warnings' => array_values(array_filter($warnings)),
        ]);
    }

    // ── Ausgabe-Schemas ──────────────────────────────────────────────────

    private static function consumptionSchema(): array
    {
        $num = Num::schema();
        $row = self::obj([
            'period' => self::str(), 'from' => self::date(), 'until' => self::date(), 'as_of' => self::date(),
            'value' => self::number(), 'cost' => self::number(), 'revenue' => self::number(), 'advances' => self::number(), 'balance' => self::number(),
            'reading_on' => self::date(), 'end_distributed' => self::bool(), 'balance_meaning' => self::str(Provenance::MEANINGS), 'balance_text' => self::str(),
            'no_contract' => self::bool(), 'included_in_parent' => self::bool(), 'bonus' => self::number(), 'covered_until' => self::date(),
            'balance_sign' => self::str(Provenance::SIGNS), 'days' => self::int(), 'months' => self::int(), 'basis' => self::basisSchema(),
            'measured_share' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'reading_gap_days' => self::int(),
            'price_basis' => self::str(['entered', 'assumed']), 'money_basis' => self::basisSchema(), 'pre_baseline' => self::bool(), 'heat_adjusted' => $num,
            'running' => self::bool(), 'complete' => self::bool(), 'source' => self::str(), 'price_missing' => self::bool(),
            'advance_vs_cost' => self::obj(['factor' => self::number(), 'basis' => self::basisSchema(), 'contract_id' => self::str()], ['factor', 'basis']),
        ], ['period', 'value', 'basis']);
        return self::obj([
            'utility' => self::utilityArg(), 'accounting_kind' => self::str(self::ACCOUNTING_KINDS), 'meter' => self::str(), 'unit' => self::str(), 'currency' => self::str(),
            'granularity' => self::str(self::GRANULARITIES),
            'rows' => self::arr($row, self::MAX_ROWS),
            'total' => self::obj(['quantity' => $num, 'cost' => $num, 'revenue' => $num, 'price_missing' => self::bool()], ['quantity']),
            'truncated' => self::bool(),
        ], ['utility', 'unit', 'granularity', 'rows']);
    }

    private static function forecastSchema(): array
    {
        $num = Num::schema();
        return self::obj([
            'utility' => self::utilityArg(), 'accounting_kind' => self::str(self::ACCOUNTING_KINDS), 'meter' => self::str(), 'unit' => self::str(), 'currency' => self::str(),
            'priced_by_group' => self::str(),   // v3.13.0 (Paket vertrag)
            'valid' => self::bool(), 'reason' => self::str(['too_few_months']),
            'months' => self::arr(self::obj(['ym' => self::str(), 'value' => self::number(), 'low' => self::number(), 'high' => self::number(),
                'cost' => self::number(), 'revenue' => self::number(), 'no_contract' => self::bool(), 'included_in_parent' => self::bool(),
                'price_missing' => self::bool(), 'basis' => self::str(['estimated'])], ['ym', 'value', 'basis']), 60),
            'total' => self::obj(['quantity' => $num, 'cost' => $num, 'revenue' => $num, 'no_contract' => self::bool(), 'price_missing' => self::bool()], ['quantity']),
            'annual' => $num,
            'model' => self::obj(['method' => self::str(), 'regression' => self::str(), 'heat_model_basis' => self::str(),
                'hdd_source' => self::str(), 'quality' => self::str(), 'heat_curve_share' => $num, 'r2' => $num]),
            'warnings' => self::arr(self::str()),
        ], ['utility', 'meter', 'unit', 'valid']);
    }
}
