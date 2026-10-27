<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Support\Dates;
use Energietracker\Support\LocalizedException;

/**
 * v3.9.0 (F1032, GitHub #40) — Assistent „Messkonzept“: aus dem Aufbau einer
 * Anlage die Zähler, Gruppen, Subzähler und Verträge.
 *
 * Gefragt wird nach dem Aufbau, nicht nach dem Namen des Messkonzepts (die
 * Namen wechseln je Netzbetreiber):
 *
 *   single        ein Zähler für alles; mit PV ein Zweirichtungszähler
 *                 (Bezug und Einspeisung), Überschusseinspeisung
 *   side_by_side  Wärmepumpe bzw. Wallbox mit eigenem Zähler neben dem
 *                 Haushaltszähler (getrennte Messung)
 *   behind        Wärmepumpe bzw. Wallbox mit eigenem Zähler hinter dem
 *                 Haushaltszähler (Subzähler; mit eigenem Vertrag rechnet der
 *                 Haushaltsvertrag nur den Rest)
 *   cascade       Kaskade: der vordere Zähler misst alles, dahinter der
 *                 Haushalt; die Wärmepumpe bzw. Wallbox ist der Rest
 *
 * dazu `pv` (Einspeisung, Erzeugung), `battery` (Ladung, Entladung) und
 * `tou` (HT/NT: none, all, device). Ein Zähler mit HT/NT sind zwei Zähler je
 * Zählwerk (1.8.1, 1.8.2) in einer Gruppe mit Gruppenvertrag und Preis je
 * Zählwerk; ein Subzähler hängt je Zählwerk am gleichnamigen Zählwerk seines
 * Elternzählers.
 *
 * Auf einem bestehenden Haushalt keine Doppelten: Jede Stelle (`slot`) kann
 * einen vorhandenen Zähler bekommen (`meter_id` bzw. `meter_ids` je
 * Zählwerk); er wird zugeordnet (Rolle, Zählwerk, Eltern, Gruppe), nicht neu
 * angelegt. Verträge entstehen nur, wo das Ziel heute keinen laufenden hat.
 *
 * `plan()` schreibt nichts (Vorschau), `apply()` legt mit denselben Schritten
 * an — vorher ein Snapshot, wie beim Einspielen von Beispieldaten.
 */
final class MeteringConceptService
{
    public const CONCEPTS = ['single', 'side_by_side', 'behind', 'cascade'];
    public const DEVICES = ['heat_pump', 'ev_charger'];
    public const TOU = ['none', 'all', 'device'];
    /** Zählwerke einer Stelle mit HT/NT bzw. ohne: Schlüssel → OBIS-Kennzahl */
    public const REGISTERS = ['single' => ['one' => '1.8.0'], 'tou' => ['ht' => '1.8.1', 'nt' => '1.8.2']];

    public function __construct(
        private MeterService $meters,
        private ContractService $contracts,
        private SettingsService $settings,
        private I18nService $i18n,
        private ?BackupService $backup = null,
    ) {}

    /**
     * Die Stellen eines Aufbaus: Verbrauchsart, Rolle, Elternstelle, Zählwerke,
     * Vertrag (Schlüssel der Vertragseingabe oder null).
     *
     * @param array{concept:string,device:?string,pv:bool,battery:bool,tou:string} $s
     * @return array<string,array{utility:string,role:?string,parent:?string,registers:array<string,string>,contract:?string,obis:?string}>
     */
    public static function slots(array $s): array
    {
        $dev = $s['device'];
        $reg = fn(bool $tou) => $tou ? self::REGISTERS['tou'] : self::REGISTERS['single'];
        $touAll = $s['tou'] === 'all';
        $touDev = $s['tou'] === 'device';
        $out = [];
        switch ($s['concept']) {
            case 'single':
                $out['main'] = ['utility' => 'strom', 'role' => 'household', 'parent' => null, 'registers' => $reg($touAll), 'contract' => 'main'];
                break;
            case 'side_by_side':
            case 'behind':
                $out['main'] = ['utility' => 'strom', 'role' => 'household', 'parent' => null, 'registers' => $reg($touAll), 'contract' => 'main'];
                $out['dev'] = ['utility' => 'strom', 'role' => $dev, 'parent' => $s['concept'] === 'behind' ? 'main' : null,
                               'registers' => $reg($touAll || $touDev), 'contract' => 'dev'];
                break;
            case 'cascade':
                // vorn der Zähler, der alles misst: sein Vertrag ist der des Geräts (der Rest)
                $out['main'] = ['utility' => 'strom', 'role' => $dev, 'parent' => null, 'registers' => $reg($touAll || $touDev), 'contract' => 'main'];
                $out['house'] = ['utility' => 'strom', 'role' => 'household', 'parent' => 'main', 'registers' => $reg($touAll), 'contract' => 'house'];
                break;
        }
        if ($s['pv']) {
            $out['feed'] = ['utility' => 'pv_einspeisung', 'role' => null, 'parent' => null, 'registers' => ['one' => '2.8.0'], 'contract' => 'feed'];
            $out['gen'] = ['utility' => 'pv_erzeugung', 'role' => null, 'parent' => null, 'registers' => ['one' => ''], 'contract' => null];
            if ($s['battery']) {
                $out['bat_in'] = ['utility' => 'pv_erzeugung', 'role' => 'battery_charge', 'parent' => null, 'registers' => ['one' => ''], 'contract' => null];
                $out['bat_out'] = ['utility' => 'pv_erzeugung', 'role' => 'battery_discharge', 'parent' => null, 'registers' => ['one' => ''], 'contract' => null];
            }
        }
        return $out;
    }

    /** Eingabe prüfen und vereinheitlichen. @return array<string,mixed> */
    public function normalize(array $in): array
    {
        $concept = (string)($in['concept'] ?? '');
        if (!in_array($concept, self::CONCEPTS, true)) throw new LocalizedException('errors.concept.field', ['field' => 'concept'], 'concept');
        $device = $in['device'] ?? null;
        $device = $device === '' ? null : $device;
        if ($device !== null && !in_array($device, self::DEVICES, true)) throw new LocalizedException('errors.concept.field', ['field' => 'device'], 'concept device');
        if ($concept !== 'single' && $device === null) throw new LocalizedException('errors.concept.field', ['field' => 'device'], 'concept needs device');
        $tou = (string)($in['tou'] ?? 'none');
        if (!in_array($tou, self::TOU, true)) throw new LocalizedException('errors.concept.field', ['field' => 'tou'], 'concept tou');
        if ($tou === 'device' && $concept === 'single') $tou = 'none';
        // ein Subzähler hängt je Zählwerk am gleichnamigen Zählwerk: HT/NT nur am Gerät geht in der
        // Kaskade nicht (vorn HT/NT, dahinter der Haushalt mit einem Zählwerk)
        if ($tou === 'device' && $concept === 'cascade') throw new LocalizedException('errors.concept.touCascade', [], 'concept tou cascade');
        $s = ['concept' => $concept, 'device' => $concept === 'single' ? null : $device, 'pv' => !empty($in['pv']),
              'battery' => !empty($in['pv']) && !empty($in['battery']), 'tou' => $tou];
        $slots = self::slots($s);
        $s['slots'] = [];
        foreach ($slots as $key => $def) {
            $raw = is_array($in['slots'][$key] ?? null) ? $in['slots'][$key] : [];
            $name = trim((string)($raw['name'] ?? ''));
            $ids = [];
            foreach (array_keys($def['registers']) as $r) {
                $id = $r === 'one' ? ($raw['meter_id'] ?? null) : ($raw['meter_ids'][$r] ?? null);
                if (is_string($id) && $id !== '') {
                    if ($this->meters->get($def['utility'], $id) === null) throw new LocalizedException('errors.concept.meterUnknown', ['id' => mb_substr($id, 0, 40)], 'concept meter');
                    $ids[$r] = $id;
                }
            }
            // vorbelegte OBIS-Kennzahl je Zählwerk, änderbar (etwa 1-0:1.8.0 oder 2.8.0 auf dem Typenschild)
            foreach (array_keys($def['registers']) as $r) {
                $v = preg_replace('/\s+/', '', (string)(is_array($raw['obis'] ?? null) ? ($raw['obis'][$r] ?? '') : '')) ?? '';
                if ($v === '') continue;
                if (MeterService::obisKey($v) === null) throw new LocalizedException('errors.meter.obisInvalid', ['value' => mb_substr($v, 0, 24)], 'concept obis');
                $def['registers'][$r] = $v;
            }
            $s['slots'][$key] = $def + ['name' => mb_substr($name, 0, 80), 'serial' => mb_substr(trim((string)($raw['serial'] ?? '')), 0, 40), 'meter_ids' => $ids];
        }
        $used = [];
        foreach ($s['slots'] as $slot) foreach ($slot['meter_ids'] as $id) {
            if (isset($used[$id])) throw new LocalizedException('errors.concept.meterTwice', ['id' => mb_substr($id, 0, 40)], 'concept meter twice');
            $used[$id] = true;
        }
        $s['contracts'] = [];
        foreach ($s['slots'] as $key => $slot) {
            $c = $slot['contract'] !== null && is_array($in['contracts'][$slot['contract']] ?? null) ? $in['contracts'][$slot['contract']] : null;
            if ($c === null) continue;
            $num = function (string $f) use ($c): ?float {
                $v = $c[$f] ?? null;
                if ($v === null || $v === '') return null;
                if (!is_numeric($v) || (float)$v < 0) throw new LocalizedException('errors.concept.field', ['field' => $f], 'concept number');
                return (float)$v;
            };
            $start = (string)($c['start'] ?? '');
            $entry = ['provider' => mb_substr(trim((string)($c['provider'] ?? '')), 0, 80), 'start' => $start,
                      'ct' => $num('ct'), 'ct_nt' => $num('ct_nt'), 'base' => $num('base'), 'advance' => $num('advance')];
            if ($entry['provider'] === '' && $entry['ct'] === null) continue;   // nichts eingetragen: später
            if (!Dates::isIsoDate($start)) throw new LocalizedException('errors.concept.field', ['field' => 'start'], 'concept start');
            if ($entry['ct'] === null) throw new LocalizedException('errors.concept.field', ['field' => 'ct'], 'concept price');
            $s['contracts'][$key] = $entry;
        }
        return $s;
    }

    /**
     * Vorschau: die Schritte, die apply() ginge. Schreibt nichts.
     *
     * @return array{concept:array<string,mixed>,actions:list<array<string,mixed>>,problems:list<array<string,mixed>>}
     */
    public function plan(array $in): array
    {
        $s = $this->normalize($in);
        [$actions, $problems] = $this->steps($s);
        // die Stellen für die Eingabe der Zähler und Verträge (Name vorbelegt, Zählwerke mit OBIS)
        $slots = [];
        foreach ($s['slots'] as $key => $slot) {
            $slots[$key] = ['utility' => $slot['utility'], 'role' => $slot['role'], 'parent' => $slot['parent'], 'registers' => $slot['registers'],
                            'contract' => $slot['contract'] !== null && Utilities::hasContracts($slot['utility']) ? $slot['contract'] : null,
                            'name' => $this->slotName($s, $key), 'default_name' => $this->slotName($s, $key, true)];
        }
        return ['concept' => array_intersect_key($s, array_flip(['concept', 'device', 'pv', 'battery', 'tou'])), 'slots' => $slots,
                'actions' => $actions, 'problems' => $problems];
    }

    /** Anlegen. @return array{concept:array<string,mixed>,actions:list<array<string,mixed>>,created:array<string,list<string>>,snapshot:?string} */
    public function apply(array $in): array
    {
        $s = $this->normalize($in);
        [$actions, $problems] = $this->steps($s);
        if ($problems !== []) {
            $p = $problems[0];
            throw new LocalizedException('errors.concept.' . $p['code'], $p['params'] ?? [], 'concept problem');
        }
        $snapshot = $this->backup?->saveSnapshot('concept_');
        $created = ['meters' => [], 'groups' => [], 'contracts' => []];
        $ids = [];        // slot|register → Zähler-ID
        $groupOf = [];    // slot → Gruppen-ID
        foreach ($actions as $a) {
            switch ($a['op']) {
                case 'activate':
                    $active = (array)$this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
                    $this->settings->set(['active_utilities' => array_values(array_unique(array_merge($active, $a['utilities'])))]);
                    break;
                case 'create_meter':
                    $m = $this->meters->create($a['utility'], $a['data'] + ($a['parent_ref'] !== null ? ['parent_meter_id' => $ids[$a['parent_ref']]] : []));
                    $ids[$a['ref']] = (string)$m['id'];
                    $created['meters'][] = (string)$m['id'];
                    break;
                case 'update_meter':
                    $this->meters->update($a['utility'], $a['meter_id'], $a['data'] + ($a['parent_ref'] !== null ? ['parent_meter_id' => $ids[$a['parent_ref']]] : []));
                    $ids[$a['ref']] = $a['meter_id'];
                    break;
                case 'create_group':
                    $g = $this->meters->createGroup($a['utility'], ['name' => $a['name']]);
                    $groupOf[$a['slot']] = (string)$g['id'];
                    $created['groups'][] = (string)$g['id'];
                    foreach ($a['members'] as $ref) $this->meters->update($a['utility'], $ids[$ref], ['meter_group_id' => (string)$g['id']]);
                    break;
                case 'reuse_group':
                    $groupOf[$a['slot']] = $a['group_id'];
                    break;
                case 'create_contract':
                    $target = isset($groupOf[$a['slot']]) ? ['meter_group_id' => $groupOf[$a['slot']]] : ['meter_id' => $ids[$a['meter_ref']]];
                    $data = $a['data'];
                    if (isset($data['working_prices_by_ref'])) {
                        $data['working_prices_by_meter'] = [];
                        foreach ($data['working_prices_by_ref'] as $ref => $list) $data['working_prices_by_meter'][$ids[$ref]] = $list;
                        unset($data['working_prices_by_ref']);
                    }
                    $c = $this->contracts->create($a['utility'], $target + $data);
                    $created['contracts'][] = (string)$c['id'];
                    break;
            }
        }
        return ['concept' => array_intersect_key($s, array_flip(['concept', 'device', 'pv', 'battery', 'tou'])), 'actions' => $actions,
                'created' => $created, 'snapshot' => $snapshot];
    }

    /**
     * Die Schritte und Hindernisse: Zähler anlegen oder zuordnen (Eltern zuerst),
     * Gruppen für HT/NT, Verträge, wo das Ziel keinen laufenden hat.
     *
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function steps(array $s): array
    {
        $actions = []; $problems = [];
        $need = array_values(array_unique(array_filter(array_map(fn($x) => $x['utility'], $s['slots']), fn($u) => $u !== 'strom')));
        $active = (array)$this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
        $missing = array_values(array_diff(array_merge(['strom'], $need), $active));
        if ($missing !== []) $actions[] = ['op' => 'activate', 'utilities' => $missing];
        $today = date('Y-m-d');
        // Eltern vor Kindern
        $order = array_keys($s['slots']);
        usort($order, fn($a, $b) => ($s['slots'][$a]['parent'] !== null) <=> ($s['slots'][$b]['parent'] !== null));
        // Namenszusatz je Zählwerk in der Sprache der Installation (HT/NT, HP/HC, F1/F23 …)
        $regName = ['ht' => $this->i18n->t('concept.regShort.ht'), 'nt' => $this->i18n->t('concept.regShort.nt'), 'one' => ''];
        foreach ($order as $key) {
            $slot = $s['slots'][$key];
            $u = $slot['utility'];
            $name = $this->slotName($s, $key);
            $multi = count($slot['registers']) > 1;
            $parent = $slot['parent'] !== null ? $s['slots'][$slot['parent']] : null;
            foreach ($slot['registers'] as $r => $obis) {
                $ref = "$key|$r";
                // Elternzählwerk: das gleichnamige, sonst das einzige
                $parentRef = null;
                if ($parent !== null) {
                    $parentRef = isset($parent['registers'][$r]) ? "{$slot['parent']}|$r" : (count($parent['registers']) === 1 ? "{$slot['parent']}|one" : null);
                    if ($parentRef === null) { $problems[] = ['code' => 'touCascade', 'slot' => $key]; continue; }
                }
                $data = $slot['role'] !== null ? ['role' => $slot['role']] : [];
                if ($obis !== '') $data['obis'] = $obis;
                $existingId = $slot['meter_ids'][$r] ?? null;
                if ($existingId !== null) {
                    $m = $this->meters->get($u, $existingId);
                    // nicht umbenennen, ein vorhandenes Zählwerk nicht überschreiben
                    if (!empty($m['obis'])) unset($data['obis']);
                    // Ketten: ein Elternzähler darf keinen Elternzähler haben, ein Subzähler keine Subzähler
                    if ($parentRef === null && !empty($m['parent_meter_id'])) $data['parent_meter_id'] = null;
                    if ($parentRef !== null && $this->hasChildren($u, $existingId)) $problems[] = ['code' => 'chain', 'slot' => $key, 'params' => ['name' => (string)$m['name']]];
                    $actions[] = ['op' => 'update_meter', 'utility' => $u, 'slot' => $key, 'register' => $r, 'ref' => $ref, 'meter_id' => $existingId,
                                  'name' => (string)$m['name'], 'parent_ref' => $parentRef, 'data' => $data];
                } else {
                    $mName = trim($name . ($multi ? ' ' . $regName[$r] : ''));
                    $data += ['name' => $mName, 'installed_on' => $today];
                    if ($slot['serial'] !== '') $data['device_serial'] = $slot['serial'];
                    $actions[] = ['op' => 'create_meter', 'utility' => $u, 'slot' => $key, 'register' => $r, 'ref' => $ref, 'name' => $mName,
                                  'parent_ref' => $parentRef, 'data' => $data];
                }
            }
            if ($multi) {
                $existing = array_values(array_filter(array_map(fn($id) => $this->meters->get($u, $id), $slot['meter_ids'])));
                $gids = array_values(array_unique(array_filter(array_map(fn($m) => (string)($m['meter_group_id'] ?? ''), $existing))));
                if (count($existing) === count($slot['registers']) && count($gids) === 1 && $gids[0] !== '') {
                    $g = $this->meters->getGroup($u, $gids[0]);
                    $actions[] = ['op' => 'reuse_group', 'utility' => $u, 'slot' => $key, 'group_id' => $gids[0], 'name' => (string)($g['name'] ?? $gids[0])];
                } else {
                    $actions[] = ['op' => 'create_group', 'utility' => $u, 'slot' => $key, 'name' => $name,
                                  'members' => array_map(fn($r) => "$key|$r", array_keys($slot['registers']))];
                }
            }
            // Vertrag
            $c = $s['contracts'][$key] ?? null;
            if ($c === null || !Utilities::hasContracts($u)) continue;
            $running = $this->runningContract($u, $slot, $multi, $actions, $key);
            if ($running !== null) {
                $actions[] = ['op' => 'skip_contract', 'utility' => $u, 'slot' => $key, 'name' => $name,
                              'existing' => trim((string)($running['provider'] ?? '') . ' ' . (string)($running['tariff_name'] ?? ''))];
                continue;
            }
            $data = ['provider' => $c['provider'], 'start' => $c['start'],
                     'working_prices' => [['from' => $c['start'], 'ct_per_kwh' => $c['ct']]]];
            if ($multi && $c['ct_nt'] !== null) $data['working_prices_by_ref'] = ["$key|nt" => [['from' => $c['start'], 'ct_per_kwh' => $c['ct_nt']]]];
            if (!Utilities::isFeedIn($u)) {
                if ($c['base'] !== null) $data['base_prices'] = [['from' => $c['start'], 'eur_per_month' => $c['base']]];
                if ($c['advance'] !== null) $data['advance_payments'] = [['from' => $c['start'], 'amount_eur' => $c['advance']]];
            }
            $actions[] = ['op' => 'create_contract', 'utility' => $u, 'slot' => $key, 'name' => $name, 'meter_ref' => "$key|" . array_key_first($slot['registers']),
                          'group' => $multi, 'data' => $data];
        }
        return [$actions, $problems];
    }

    /** Name einer Stelle: eingegeben, sonst aus dem Katalog („Wärmepumpe“, „Haushalt“ …). */
    private function slotName(array $s, string $key, bool $default = false): string
    {
        $given = $default ? '' : (string)($s['slots'][$key]['name'] ?? '');
        if ($given !== '') return $given;
        $cascadeMain = $key === 'main' && $s['concept'] === 'cascade';
        $k = 'concept.slotName.' . ($cascadeMain ? 'mainCascade' : $key) . ($key === 'dev' || $cascadeMain ? '.' . $s['device'] : '');
        return $this->i18n->t($k);
    }

    private function hasChildren(string $u, string $id): bool
    {
        foreach ($this->meters->list($u) as $m) if ((string)($m['parent_meter_id'] ?? '') === $id) return true;
        return false;
    }

    /**
     * Laufender echter Vertrag am Ziel der Stelle (vorhandener Zähler bzw. seine Gruppe), oder null.
     * Bei HT/NT zählt auch ein eigener Vertrag eines Zählwerks: ein Gruppenvertrag daneben wäre doppelt.
     */
    private function runningContract(string $u, array $slot, bool $multi, array $actions, string $key): ?array
    {
        $today = date('Y-m-d');
        $targets = array_values($slot['meter_ids']);
        foreach ($actions as $a) if ($a['op'] === 'reuse_group' && $a['slot'] === $key) $targets[] = $a['group_id'];
        foreach ($targets as $t) {
            $list = array_values(array_filter($this->contracts->list($u, $t), fn($c) => empty($c['is_shadow'])));
            $r = $this->contracts->resolveForDate($list, $today);
            if ($r !== null) return $r['contract'];
            foreach ($list as $c) if ((string)($c['start'] ?? '') > $today) return $c;   // schon vereinbart
        }
        return null;
    }
}
