<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp\Tools;

use Energietracker\Config\Utilities;
use Energietracker\Services\I18nService;
use Energietracker\Services\Mcp\Config;
use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\Num;
use Energietracker\Services\Mcp\Sanitize;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolGroup;
use Energietracker\Services\MeterService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\SettingsService;

/**
 * v3.12.0 (F1037, Basis) — `list_meters`: Zähler und Gruppen, damit die KI IDs
 * auflösen kann (Konzept §3.2). Referenzwerkzeug für die Fachpakete:
 *  - Eingabe als Schema mit `additionalProperties: false`, Aufzählung aus der SSOT,
 *  - Ausgabe mit `outputSchema`, Leerfelder weggelassen,
 *  - jede Zahl als Zahl-Objekt mit Herkunft (letzter Stand gemessen oder geschätzt,
 *    Tankgröße eingetragen),
 *  - eingegebener Text (Namen) nur unter `untrusted`, bereinigt,
 *  - Kennnummern (Zählernummer, Markt- und Messlokation) nur mit `mcp_share_identifiers`.
 */
final class MeterTools extends ToolGroup
{
    /** höchstens so viele Zähler je Antwort (danach `truncated: true`) */
    public const MAX_METERS = 200;

    public function tools(): array
    {
        return [new Tool('list_meters', Tool::READ, [
            'type'       => 'object',
            'properties' => [
                'utility'          => ['type' => 'string', 'enum' => Utilities::keys()],
                'include_inactive' => ['type' => 'boolean', 'default' => false],
            ],
            'additionalProperties' => false,
        ], fn(array $args, McpContext $ctx): array => $this->listMeters($args, $ctx), self::outputSchema(),
            ['readOnlyHint' => true, 'idempotentHint' => true])];
    }

    /**
     * @param array{utility?:string, include_inactive?:bool} $args
     * @return array<string,mixed>
     */
    private function listMeters(array $args, McpContext $ctx): array
    {
        $meters = $this->services->get(MeterService::class);
        $readings = $this->services->get(ReadingService::class);
        $i18n = $this->services->get(I18nService::class);
        $share = $this->services->get(Config::class)->shareIdentifiers();
        $inactive = ($args['include_inactive'] ?? false) === true;
        $utilities = isset($args['utility']) ? [$args['utility']] : $this->activeUtilities();

        $outMeters = [];
        $outGroups = [];
        $truncated = false;
        foreach ($utilities as $u) {
            $def = Utilities::get($u);
            $label = $i18n->utilityLabel($u);
            $all = $meters->list($u);
            $last = Utilities::isCumulative($u) ? self::lastReadings($readings->list($u)) : [];
            foreach ($all as $m) {
                if (!$inactive && !MeterService::inService($m)) continue;
                if (count($outMeters) >= self::MAX_METERS) { $truncated = true; break 2; }
                $id = (string)$m['id'];
                $row = [
                    'utility'          => $u,
                    'utility_label'    => $label,
                    'id'               => $id,
                    'untrusted'        => Sanitize::untrusted(['name' => $this->masked((string)($m['name'] ?? ''))]),
                    'active'           => MeterService::inService($m),
                    'role'             => Utilities::roleOf($u, $m),
                    'parent_meter_id'  => $m['parent_meter_id'] ?? null,
                    'group_id'         => $m['meter_group_id'] ?? null,
                    'external_id'      => $m['external_id'] ?? null,
                    'counts_in_totals' => MeterService::countsInTotals($m),
                    'capture'          => Utilities::isDelivery($u) ? 'delivery' : ((($m['capture'] ?? '') === 'period') ? 'period' : 'readings'),
                    'unit'             => (string)$def['unit'],
                    'consumption_unit' => (string)$def['consumption_unit'],
                ];
                if (isset($last[$id]) && $row['capture'] === 'readings') {
                    $r = $last[$id];
                    $row['last_reading'] = [
                        'date'    => (string)$r['date'],
                        'counter' => Num::of((float)$r['counter'], (string)$def['unit'], empty($r['is_estimated']) ? 'measured' : 'estimated',
                            ['as_of' => (string)$r['date']]),
                    ];
                }
                if (is_numeric($m['capacity'] ?? null) && (float)$m['capacity'] > 0) {
                    $row['capacity'] = Num::of((float)$m['capacity'], (string)($m['capacity_unit'] ?? $def['unit']), 'entered');
                }
                if ($share) {
                    $device = $meters->deviceOnDate($m, $ctx->today);
                    $row['untrusted'] = ($row['untrusted'] ?? []) + Sanitize::untrusted(['meter_number' => is_string($device['serial'] ?? null) ? $device['serial'] : null]);
                    $row['malo_id'] = $m['malo_id'] ?? null;
                    $row['melo_id'] = $m['melo_id'] ?? null;
                }
                $outMeters[] = array_filter($row, fn($v) => $v !== null && $v !== '' && $v !== []);
            }
            foreach ($meters->listGroups($u) as $g) {
                $gid = (string)($g['id'] ?? '');
                if ($gid === '') continue;
                $outGroups[] = array_filter([
                    'utility'       => $u,
                    'utility_label' => $label,
                    'id'            => $gid,
                    'untrusted'     => Sanitize::untrusted(['name' => $this->masked((string)($g['name'] ?? ''))]),
                    'members'       => array_values(array_map(fn($m) => (string)$m['id'], $meters->groupMembers($u, $gid))),
                ], fn($v) => $v !== []);
            }
        }
        return ['meters' => $outMeters, 'groups' => $outGroups] + ($truncated ? ['truncated' => true] : []);
    }

    /** @return list<string> eingeschaltete Verbrauchsarten in der Reihenfolge der SSOT */
    private function activeUtilities(): array
    {
        $a = $this->services->get(SettingsService::class)->get('active_utilities', []);
        if (!is_array($a) || $a === []) return Utilities::keys();
        return array_values(array_filter(Utilities::keys(), fn($k) => in_array($k, $a, true)));
    }

    /**
     * Letzte echte Ablesung je Zähler (nicht geplant, nicht verdächtig), wie
     * Erfassung und Übersicht sie zeigen.
     *
     * @param list<array<string,mixed>> $all nach Datum sortiert (ReadingService::list)
     * @return array<string,array<string,mixed>>
     */
    private static function lastReadings(array $all): array
    {
        $out = [];
        foreach ($all as $r) {
            if (!empty($r['is_future']) || !empty($r['is_suspect']) || !is_numeric($r['counter'] ?? null)) continue;
            $out[(string)($r['meter_id'] ?? '')] = $r;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function outputSchema(): array
    {
        $id = ['type' => 'string', 'minLength' => 1];
        $untrusted = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'additionalProperties' => false];
        return [
            'type'       => 'object',
            'properties' => [
                'meters' => ['type' => 'array', 'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'utility'          => ['type' => 'string', 'enum' => Utilities::keys()],
                        'utility_label'    => ['type' => 'string'],
                        'id'               => $id,
                        'untrusted'        => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'meter_number' => ['type' => 'string']], 'additionalProperties' => false],
                        'active'          => ['type' => 'boolean'],
                        'role'             => ['type' => 'string'],
                        'parent_meter_id'  => $id,
                        'group_id'         => $id,
                        'external_id'      => ['type' => 'string'],
                        'counts_in_totals' => ['type' => 'boolean'],
                        'capture'          => ['type' => 'string', 'enum' => ['readings', 'period', 'delivery']],
                        'unit'             => ['type' => 'string'],
                        'consumption_unit' => ['type' => 'string'],
                        'last_reading'     => ['type' => 'object', 'properties' => [
                            'date'    => ['type' => 'string', 'format' => 'date'],
                            'counter' => Num::schema(),
                        ], 'required' => ['date', 'counter'], 'additionalProperties' => false],
                        'capacity'         => Num::schema(),
                        'malo_id'          => ['type' => 'string'],
                        'melo_id'          => ['type' => 'string'],
                    ],
                    'required'             => ['utility', 'id', 'active', 'counts_in_totals', 'capture', 'unit', 'consumption_unit'],
                    'additionalProperties' => false,
                ]],
                'groups' => ['type' => 'array', 'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'utility'       => ['type' => 'string', 'enum' => Utilities::keys()],
                        'utility_label' => ['type' => 'string'],
                        'id'            => $id,
                        'untrusted'     => $untrusted,
                        'members'       => ['type' => 'array', 'items' => $id],
                    ],
                    'required'             => ['utility', 'id'],
                    'additionalProperties' => false,
                ]],
                'truncated' => ['type' => 'boolean'],
            ],
            'required'             => ['meters', 'groups'],
            'additionalProperties' => false,
        ];
    }
}
