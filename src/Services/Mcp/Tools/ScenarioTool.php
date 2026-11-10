<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp\Tools;

use Energietracker\Config\Utilities;
use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\Num;
use Energietracker\Services\Mcp\Provenance;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolGroup;
use Energietracker\Services\ScenarioService;

/**
 * v3.12.0 (F1037, Paket lesen) — `run_scenario`: „Was wäre, wenn?“ wie die
 * Prognose-Werkstatt (`POST /api/forecast/scenario`, ScenarioService::compute,
 * schreibt nichts). Eingaben wie ScenarioService::normalize (Grenzen dort; das
 * Schema hier sagt sie der KI vorab), der Zähler darf auch eine Gruppe oder ein
 * Alias sein. Alles in der Antwort ist geschätzt; Spannen aus dem Band der
 * Prognose bzw. aus der unsicheren Schätzung des Abschlags.
 */
final class ScenarioTool extends ToolGroup
{
    use ReadSupport;

    public function tools(): array
    {
        $pct = fn(float $min, float $max): array => ['type' => 'number', 'minimum' => $min, 'maximum' => $max];
        return [new Tool('run_scenario', Tool::READ, self::obj([
            'utility'          => self::utilityArg(),
            'meter'            => self::meterArg(),
            'model'            => ['type' => 'string', 'enum' => ScenarioService::MODELS, 'default' => 'standard'],
            'regression'       => ['type' => 'string', 'enum' => ScenarioService::REGRESSION_MODELS],
            'winter_pct'       => $pct(-50, 50),
            'room_temp_delta'  => $pct(-5, 5),
            'saving_pct'       => $pct(0, 80),
            'baseload_pct'     => $pct(-80, 100),
            'ev_km'            => $pct(0, 100000),
            'ev_kwh_per_100km' => $pct(5, 60),
            'jaz_from'         => $pct(1, 8),
            'jaz_to'           => $pct(1, 8),
            'budget_eur'       => $pct(0, 10000000),
            'measures'         => ['type' => 'array', 'maxItems' => ScenarioService::MAX_MEASURES, 'items' => self::obj([
                'id'       => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,40}$'],
                'kind'     => ['type' => 'string', 'pattern' => '^[a-z_]{1,30}$'],
                'label'    => ['type' => 'string', 'maxLength' => 80],
                'heat_pct' => $pct(0, 90),
                'base_pct' => $pct(0, 90),
                'cost_eur' => $pct(0, 10000000),
                'active'   => ['type' => 'boolean'],
            ], ['id'])],
            'tariff'           => self::obj([
                'kind'             => ['type' => 'string', 'enum' => ScenarioService::TARIFF_KINDS],
                'contract_id'      => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                'working_price_ct' => $pct(0, 500),
                'base_eur_month'   => $pct(0, 1000),
            ], ['kind']),
            // v3.12.0 (F1040, Phase 2) — Heizöl und Pellets: Preis ± %, Heizungstausch; PV: Anlage, Speicher, Eigenverbrauch, Vergütung
            'price_pct'        => $pct(-50, 100),
            'replace'          => self::obj([
                'to'             => ['type' => 'string', 'enum' => \Energietracker\Services\ScenarioDelivery::TARGETS],
                'efficiency_old' => $pct(0.5, 1),
                'efficiency_new' => $pct(0.5, 1),
                'jaz'            => $pct(1, 8),
                'power_ct'       => $pct(0, 200),
                'pellet_ct'      => $pct(0, 200),
                'invest_eur'     => $pct(0, 10000000),
            ], ['to']),
            'pv_size_pct'      => $pct(0, 300),
            'pv_kwp_now'       => $pct(0.1, 1000),
            'battery_kwh'      => $pct(0, 100),
            'self_use_pct'     => $pct(-50, 50),
            'feed_in_ct'       => $pct(0, 100),
        ], ['utility', 'meter']), fn(array $a, McpContext $c): array => $this->scenario($a, $c), self::outputSchema(),
            ['readOnlyHint' => true, 'idempotentHint' => true])];
    }

    /** @return array<string,mixed> */
    private function scenario(array $args, McpContext $ctx): array
    {
        $u = (string)$args['utility'];
        $target = $this->target($u, (string)$args['meter']);
        $in = $args;
        unset($in['meter']);
        $in['meter_id'] = (string)$target['id'];
        $r = $this->services->get(ScenarioService::class)->compute($in, $ctx->today);
        $unit = (string)$r['unit'];
        $cur = $this->currency();
        $head = ['utility' => $u, 'meter' => (string)$target['id'], 'unit' => $unit, 'currency' => $cur];
        if (empty($r['valid'])) {
            return $head + ['valid' => false, 'reason' => (string)($r['reason_code'] ?? 'too_few_months')];
        }
        $band = is_array($r['band'] ?? null) ? $r['band'] : null;
        $qty = fn(?float $v, ?float $lo = null, ?float $hi = null): ?array => $v === null ? null
            : Num::of($v, $unit, 'estimated', $lo !== null && $hi !== null ? ['range' => [min($lo, $hi), max($lo, $hi)]] : []);
        $eur = fn(?float $v, array $o = []): ?array => $v === null ? null : Num::money($v, $cur, 'estimated', $o);
        $t = $r['totals'];
        $side = fn(array $s, ?float $lo, ?float $hi): array => self::compact([
            'quantity' => $qty((float)$s['qty'], $lo, $hi),
            'cost'     => $eur((float)$s['cost']),
            'co2_kg'   => is_numeric($s['co2_kg'] ?? null) ? Num::of((float)$s['co2_kg'], 'kg', 'estimated', ['decimals' => 1]) : null,
        ]);
        $d = $t['delta'];
        $out = $head + [
            'valid'  => true,
            'model'  => (string)$r['model'],
            'from'   => (string)$r['from'],
            'to'     => (string)$r['to'],
            'totals' => [
                'base'     => $side($t['base'], $band['base_low'] ?? null, $band['base_high'] ?? null),
                'scenario' => $side($t['scenario'], $band['scen_low'] ?? null, $band['scen_high'] ?? null),
                'delta'    => self::compact([
                    'quantity'     => Num::of((float)$d['qty'], $unit, 'estimated', ['derived' => true]),
                    'cost'         => Num::money((float)$d['cost'], $cur, 'estimated', ['derived' => true]),
                    'co2_kg'       => is_numeric($d['co2_kg'] ?? null) ? Num::of((float)$d['co2_kg'], 'kg', 'estimated', ['derived' => true, 'decimals' => 1]) : null,
                    'quantity_pct' => is_numeric($d['qty_pct'] ?? null) ? Num::of((float)$d['qty_pct'], '%', 'estimated', ['derived' => true, 'decimals' => 1]) : null,
                    'cost_pct'     => is_numeric($d['cost_pct'] ?? null) ? Num::of((float)$d['cost_pct'], '%', 'estimated', ['derived' => true, 'decimals' => 1]) : null,
                ]),
            ],
            'months' => array_map(fn(array $m): array => [
                'ym' => (string)$m['ym'], 'base_qty' => (float)$m['base_qty'], 'scen_qty' => (float)$m['scen_qty'],
                'base_cost' => (float)$m['base_cost'], 'scen_cost' => (float)$m['scen_cost'], 'basis' => 'estimated',
            ], (array)$r['months']),
        ];

        $a = is_array($r['advance'] ?? null) ? $r['advance'] : [];
        $unc = is_array($a['uncertain'] ?? null) ? $a['uncertain'] : null;
        $reasons = $unc !== null ? array_values(array_map('strval', (array)($unc['reasons'] ?? []))) : [];
        $rangeOf = static fn(mixed $rg): ?array => is_array($rg) && is_numeric($rg[0] ?? null) && is_numeric($rg[1] ?? null)
            ? [min((float)$rg[0], (float)$rg[1]), max((float)$rg[0], (float)$rg[1])] : null;
        $withRange = function (?float $v, mixed $rg) use ($eur, $reasons): ?array {
            $range = is_array($rg) ? $rg : null;
            if ($v === null && $range === null) return null;
            return $eur($v ?? ($range[0] + $range[1]) / 2, array_filter(['range' => $range, 'uncertain' => $reasons]));
        };
        $out['advance'] = self::compact([
            'method'           => is_string($a['method'] ?? null) ? $a['method'] : null,
            'current'          => is_numeric($a['current'] ?? null) ? Num::money((float)$a['current'], $cur, 'entered') : null,
            'base_recommended' => $withRange(is_numeric($a['base_recommended'] ?? null) ? (float)$a['base_recommended'] : null, $rangeOf($unc['base_range'] ?? null)),
            'scen_recommended' => $withRange(is_numeric($a['scen_recommended'] ?? null) ? (float)$a['scen_recommended'] : null, $rangeOf($unc['scen_range'] ?? null)),
            'base_ongoing'     => is_numeric($a['base_ongoing'] ?? null) ? $eur((float)$a['base_ongoing']) : null,
            'scen_ongoing'     => is_numeric($a['scen_ongoing'] ?? null) ? $eur((float)$a['scen_ongoing']) : null,
            'months_left'      => is_int($a['months_left'] ?? null) ? $a['months_left'] : null,
            'every_months'     => is_int($a['every_months'] ?? null) && $a['every_months'] > 1 ? Num::of($a['every_months'], 'months', 'entered') : null,
            'withheld'         => is_string($a['withheld'] ?? null) ? $a['withheld'] : null,
        ]);
        $b = is_array($r['billing'] ?? null) ? $r['billing'] : null;
        if ($b !== null) {
            $sign = Provenance::signFor(Utilities::isFeedIn($u));
            $out['billing'] = self::compact([
                'until'            => is_string($b['until'] ?? null) ? $b['until'] : null,
                'base_end_balance' => is_numeric($b['base_end_balance'] ?? null) ? $eur((float)$b['base_end_balance'], ['sign' => $sign]) : null,
                'scen_end_balance' => is_numeric($b['scen_end_balance'] ?? null) ? $eur((float)$b['scen_end_balance'], ['sign' => $sign]) : null,
            ]);
        }
        $out['ranking'] = array_map(fn(array $x): array => self::compact([
            'kind' => (string)$x['kind'], 'id' => is_string($x['id'] ?? null) ? $x['id'] : null,
            'saving_eur' => (float)$x['saving_eur'], 'saving_qty' => (float)$x['saving_qty'],
            'payback_years' => is_numeric($x['payback_years'] ?? null) ? (float)$x['payback_years'] : null, 'basis' => 'estimated',
        ]), (array)($r['ranking'] ?? []));
        $o = is_array($r['optimization'] ?? null) ? $r['optimization'] : null;
        if ($o !== null) {
            $out['optimization'] = self::compact([
                'chosen'        => array_values(array_map('strval', (array)($o['chosen'] ?? []))),
                'saving'        => $eur((float)$o['saving_eur']),
                'cost'          => Num::money((float)$o['cost_eur'], $cur, 'entered'),
                'payback_years' => is_numeric($o['payback_years'] ?? null) ? Num::of((float)$o['payback_years'], 'years', 'estimated', ['derived' => true, 'decimals' => 1]) : null,
            ]);
        }
        $out['warnings'] = array_values(array_unique(array_map(fn($w) => (string)($w['code'] ?? ''), (array)($r['warnings'] ?? []))));
        if (is_array($r['profile'] ?? null)) $out['profile'] = self::profile($r['profile']);
        // v3.12.0 (F1040, Phase 2, additiv) — welche Rechnung, dazu Tank und Heizungstausch bzw. das PV-Jahr
        if (isset($r['calc'])) $out['calc'] = (string)$r['calc'];
        if (($r['calc'] ?? null) === 'delivery') $out += $this->delivery($r, $cur);
        if (($r['calc'] ?? null) === 'pv' && is_array($r['pv'] ?? null)) $out['pv'] = $this->pv($r['pv'], $cur);
        return $out;
    }

    /** @return array<string,mixed> Lieferungen: Preis je Einheit, Tank (leer am, nachfüllen am), Heizungstausch — alles Modellwerte */
    private function delivery(array $r, string $cur): array
    {
        $d = (array)($r['delivery'] ?? []);
        $unit = (string)($d['unit'] ?? '');
        // die Untereinheit der Währung (ct, Rp., p), wie ReadSupport::priceUnit()
        $minor = self::minorUnit($this->currency());
        $out = ['delivery' => self::compact([
            'price_per_unit'      => is_numeric($d['base_ct_per_unit'] ?? null) && $unit !== '' ? Num::of((float)$d['base_ct_per_unit'], "$minor/$unit", 'estimated', ['decimals' => 2]) : null,
            'scen_price_per_unit' => is_numeric($d['scen_ct_per_unit'] ?? null) && $unit !== '' ? Num::of((float)$d['scen_ct_per_unit'], "$minor/$unit", 'estimated', ['decimals' => 2]) : null,
            'base_units'          => is_numeric($d['base_units'] ?? null) && $unit !== '' ? Num::of((float)$d['base_units'], $unit, 'estimated') : null,
            'scen_units'          => is_numeric($d['scen_units'] ?? null) && $unit !== '' ? Num::of((float)$d['scen_units'], $unit, 'estimated') : null,
        ])];
        $t = is_array($r['tank'] ?? null) ? $r['tank'] : null;
        if ($t !== null) {
            $out['tank'] = self::compact([
                'stock'     => Num::of((float)$t['stock'], (string)$t['unit'], 'estimated', ['as_of' => (string)$t['as_of']]),
                'capacity'  => is_numeric($t['capacity'] ?? null) ? Num::of((float)$t['capacity'], (string)$t['unit'], 'entered') : null,
                'base'      => array_filter((array)$t['base'], fn($v) => $v !== null),
                'scenario'  => is_array($t['scenario'] ?? null) ? array_filter($t['scenario'], fn($v) => $v !== null) : null,
                'basis'     => 'estimated',
            ]);
        }
        $x = is_array($r['replacement'] ?? null) ? $r['replacement'] : null;
        if ($x !== null) {
            $out['replacement'] = self::compact([
                'to'          => (string)$x['to'],
                'assumed'     => self::assumedReplacement((array)$x['assumed'], $minor),
                'energy'      => Num::of((float)$x['energy_kwh'], 'kWh', 'estimated'),
                'cost'        => Num::money((float)$x['cost'], $cur, 'estimated'),
                'fuel_cost'   => Num::money((float)$x['fuel_cost'], $cur, 'estimated'),
                'saving'      => Num::money((float)$x['saving_eur'], $cur, 'estimated', ['derived' => true]),
                'payback_years' => is_numeric($x['payback_years'] ?? null) ? Num::of((float)$x['payback_years'], 'years', 'estimated', ['derived' => true, 'decimals' => 1]) : null,
            ]);
        }
        return $out;
    }

    /** @return array<string,mixed> PV: Jahr mit Erzeugung, Einspeisung, Bezug, Kosten, Erlös, Autarkie; Spanne ohne Viertelstunden */
    private function pv(array $p, string $cur): array
    {
        $side = function (string $k) use ($p, $cur): ?array {
            $v = $p[$k] ?? null;
            if (!is_array($v)) return null;
            $lo = $p['scenario_low'] ?? null; $hi = $p['scenario_high'] ?? null;
            $rg = fn(string $f) => $k === 'scenario' && is_array($lo) && is_array($hi) && is_numeric($lo[$f] ?? null) && is_numeric($hi[$f] ?? null)
                ? ['range' => [min((float)$lo[$f], (float)$hi[$f]), max((float)$lo[$f], (float)$hi[$f])]] : [];
            $kwh = fn(string $f) => is_numeric($v[$f] ?? null) ? Num::of((float)$v[$f], 'kWh', $k === 'base' ? 'measured' : 'estimated', $rg($f)) : null;
            // der Ausgangsfall ist das gemessene Jahr (Beträge daraus mit den Preisen der Verträge), das Szenario ein Modellwert
            $eur = fn(string $f) => is_numeric($v[$f] ?? null)
                ? Num::money((float)$v[$f], $cur, $k === 'base' ? 'measured' : 'estimated', ['derived' => true] + $rg($f)) : null;
            return self::compact([
                'generation' => $kwh('generation'), 'feed_in' => $kwh('feed_in'), 'import' => $kwh('import'), 'self_use' => $kwh('self_use'),
                'autarky' => is_numeric($v['autarky'] ?? null) ? Num::of((float)$v['autarky'] * 100, '%', 'estimated', ['decimals' => 1]
                    + ($rg('autarky') !== [] ? ['range' => array_map(fn($x) => $x * 100, $rg('autarky')['range'])] : [])) : null,
                'import_cost' => $eur('import_cost'), 'feed_revenue' => $eur('feed_revenue'), 'net_cost' => $eur('net_cost'),
            ]);
        };
        $a = (array)($p['assumed'] ?? []);
        return self::compact([
            'source' => (string)$p['source'], 'year' => is_int($p['year'] ?? null) ? $p['year'] : null,
            'battery' => Num::of((float)($p['battery_kwh'] ?? 0), 'kWh', 'entered'),
            'size_pct' => Num::of((float)($p['size_pct'] ?? 0), '%', 'entered'),
            // genannte Annahmen der Rechnung
            'assumed' => self::compact([
                'battery_efficiency' => is_numeric($a['battery_efficiency'] ?? null) ? Num::of((float)$a['battery_efficiency'] * 100, '%', 'assumed', ['decimals' => 0]) : null,
                'battery_c_rate' => is_numeric($a['battery_c_rate'] ?? null) ? Num::of((float)$a['battery_c_rate'], '1/h', 'assumed', ['decimals' => 2]) : null,
                'battery_cycles_per_day' => is_numeric($a['battery_cycles_per_day'] ?? null) ? Num::of((float)$a['battery_cycles_per_day'], 'cycles/day', 'assumed', ['decimals' => 0]) : null,
            ]),
            'base' => $side('base'), 'scenario' => $side('scenario'),
        ]);
    }

    /** v3.12.0 — Untereinheit der Währung (ct, Rp., p) aus dem Länderprofil. */
    private static function minorUnit(string $currency): string
    {
        return (string)(\Energietracker\Config\Countries::CURRENCIES[$currency]['minor'] ?? 'ct');
    }

    /** Annahmen des Heizungstauschs mit Einheit und Herkunft. @return array<string,array<string,mixed>> */
    private static function assumedReplacement(array $a, string $minor): array
    {
        $units = ['efficiency_old' => '%', 'efficiency_new' => '%', 'jaz' => 'factor', 'power_ct' => "$minor/kWh", 'pellet_ct' => "$minor/kg", 'kwh_per_kg' => 'kWh/kg'];
        $out = [];
        foreach ($a as $k => $v) {
            if (!isset($units[$k]) || !is_numeric($v)) continue;
            $out[$k] = Num::of($units[$k] === '%' ? (float)$v * 100 : (float)$v, $units[$k], 'assumed', ['decimals' => 2]);
        }
        return $out;
    }

    /**
     * v3.12.0 (F1040) — dasselbe Profil wie die Oberfläche. Die Eingaben eines Beispiels stehen
     * flach als Pfad (`measures[0].base_pct`) und Wert, jede mit Herkunft: angenommene
     * Beispielwerte, keine Richtwerte.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function profile(array $p): array
    {
        $flat = function (array $a, string $prefix = '') use (&$flat): array {
            $out = [];
            foreach ($a as $k => $v) {
                $path = is_int($k) ? "{$prefix}[$k]" : ($prefix === '' ? (string)$k : "$prefix.$k");
                if (is_array($v)) array_push($out, ...$flat($v, $path));
                elseif ($v !== null && $v !== '') $out[] = ['argument' => $path, 'value' => $v, 'basis' => 'assumed'];
            }
            return $out;
        };
        return [
            'group'    => (string)$p['group'],
            'presets'  => array_map(fn(array $x): array => [
                'id' => (string)$x['id'], 'direction' => (string)$x['direction'], 'arguments' => $flat((array)$x['apply']),
            ], (array)$p['presets']),
            'measures' => (array)$p['measures'],
            'levers'   => (array)$p['levers'],
        ];
    }

    private static function outputSchema(): array
    {
        $num = Num::schema();
        $side = self::obj(['quantity' => $num, 'cost' => $num, 'co2_kg' => $num], ['quantity', 'cost']);
        return self::obj([
            'utility' => self::utilityArg(), 'meter' => self::str(), 'unit' => self::str(), 'currency' => self::str(),
            'valid' => self::bool(), 'reason' => self::str(), 'model' => self::str(ScenarioService::MODELS),
            'from' => self::str(), 'to' => self::str(),
            'totals' => self::obj(['base' => $side, 'scenario' => $side, 'delta' => self::obj([
                'quantity' => $num, 'cost' => $num, 'co2_kg' => $num, 'quantity_pct' => $num, 'cost_pct' => $num])], ['base', 'scenario', 'delta']),
            'months' => self::arr(self::obj(['ym' => self::str(), 'base_qty' => self::number(), 'scen_qty' => self::number(),
                'base_cost' => self::number(), 'scen_cost' => self::number(), 'basis' => self::str(['estimated'])],
                ['ym', 'base_qty', 'scen_qty', 'basis'])),
            'advance' => self::obj(['method' => self::str(), 'current' => $num, 'base_recommended' => $num, 'scen_recommended' => $num,
                'base_ongoing' => $num, 'scen_ongoing' => $num, 'months_left' => self::int(), 'every_months' => $num, 'withheld' => self::str()]),
            'billing' => self::obj(['until' => self::date(), 'base_end_balance' => $num, 'scen_end_balance' => $num]),
            'ranking' => self::arr(self::obj(['kind' => self::str(), 'id' => self::str(), 'saving_eur' => self::number(),
                'saving_qty' => self::number(), 'payback_years' => self::number(), 'basis' => self::str(['estimated'])],
                ['kind', 'saving_eur', 'basis'])),
            'optimization' => self::obj(['chosen' => self::arr(self::str()), 'saving' => $num, 'cost' => $num, 'payback_years' => $num]),
            'warnings' => self::arr(self::str()),
            'profile' => self::obj([
                'group' => self::str(array_keys(\Energietracker\Services\ScenarioProfile::GROUPS)),
                'presets' => self::arr(self::obj(['id' => self::str(), 'direction' => self::str(['more', 'less']),
                    'arguments' => self::arr(self::obj(['argument' => self::str(), 'value' => ['type' => ['number', 'string', 'boolean']],
                        'basis' => self::str(['assumed'])], ['argument', 'value', 'basis']))], ['id', 'arguments'])),
                'measures' => self::arr(self::obj(['kind' => self::str(), 'levers' => self::arr(self::str(['heat', 'base']))], ['kind', 'levers'])),
                'levers' => ['type' => 'object'],
            ], ['group', 'presets', 'measures', 'levers']),
            // v3.12.0 (F1040, Phase 2, additiv)
            'calc' => self::str(['consumption', 'delivery', 'pv']),
            'delivery' => self::obj(['price_per_unit' => $num, 'scen_price_per_unit' => $num, 'base_units' => $num, 'scen_units' => $num]),
            'tank' => self::obj(['stock' => $num, 'capacity' => $num, 'base' => ['type' => 'object'], 'scenario' => ['type' => 'object'],
                'basis' => self::str(['estimated'])]),
            'replacement' => self::obj(['to' => self::str(\Energietracker\Services\ScenarioDelivery::TARGETS), 'assumed' => ['type' => 'object'],
                'energy' => $num, 'cost' => $num, 'fuel_cost' => $num, 'saving' => $num, 'payback_years' => $num]),
            'pv' => self::obj(['source' => self::str(['intervals', 'months']), 'year' => self::int(), 'battery' => $num,
                'size_pct' => $num, 'assumed' => ['type' => 'object'], 'base' => ['type' => 'object'], 'scenario' => ['type' => 'object']]),
        ], ['utility', 'meter', 'unit', 'valid']);
    }
}
