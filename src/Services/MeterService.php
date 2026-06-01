<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;

/**
 * Manages meters per utility.
 *
 * A "meter" is a logical counter line (e.g. "Heizung Gas", "Warmwasser Gas",
 * "Wallbox Strom"). Each meter has 1..n physical devices over time —
 * device replacement (Zählertausch) is modelled as closing the old device
 * (removed_on + final_counter) and opening a new one (installed_on +
 * initial_counter).
 *
 * Consumption across a device replacement is computed as:
 *   (old.final_counter - reading_on_old_device) +
 *   (reading_on_new_device - new.initial_counter)
 *
 * This eliminates the "phantom drop" that v0.9.0 had after meter replacement.
 */
final class MeterService
{
    public function __construct(private JsonStore $store) {}

    /** @return array<int,array<string,mixed>> */
    public function list(string $utility): array
    {
        $this->assertUtility($utility);
        $meters = $this->store->read("$utility/meters.json", []);
        return is_array($meters) ? array_values($meters) : [];
    }

    public function get(string $utility, string $meterId): ?array
    {
        foreach ($this->list($utility) as $m) {
            if (($m['id'] ?? null) === $meterId) return $m;
        }
        return null;
    }

    /** Returns the default meter id (first active one, or first if none active). */
    public function defaultId(string $utility): string
    {
        $meters = $this->list($utility);
        if (empty($meters)) {
            $this->ensureDefault($utility);
            $meters = $this->list($utility);
        }
        foreach ($meters as $m) {
            if (!empty($m['active'])) return $m['id'];
        }
        return $meters[0]['id'];
    }

    public function ensureDefault(string $utility): void
    {
        $meters = $this->list($utility);
        if (!empty($meters)) return;
        $u = Utilities::get($utility);
        $meterId = 'm_' . $utility . '_default';
        $this->store->write("$utility/meters.json", [[
            'id'         => $meterId,
            'name'       => $u['default_meter_name'],
            'icon'       => $u['icon'],
            'created_at' => date('Y-m-d'),
            'active'     => true,
            'notes'      => '',
            'devices'    => [[
                'id'               => 'd_' . $utility . '_1',
                'serial'           => null,
                'installed_on'     => date('Y-m-d'),
                'initial_counter'  => 0.0,
                'removed_on'       => null,
                'final_counter'    => null,
                'reason'           => null,
            ]],
        ]]);
    }

    public function create(string $utility, array $input): array
    {
        $this->assertUtility($utility);
        $u = Utilities::get($utility);
        if (empty($u['allow_multiple_meters']) && !empty($this->list($utility))) {
            throw new \InvalidArgumentException('Mehrere Zähler für ' . $utility . ' nicht erlaubt');
        }
        $isDelivery = Utilities::isDelivery($utility);

        // Devices: entweder explizit als komplettes Array übergeben,
        // oder aus den convenience-Feldern abgeleitet.
        if (isset($input['devices']) && is_array($input['devices']) && count($input['devices']) > 0) {
            $devices = array_map(fn($d) => [
                'id'              => (string)($d['id'] ?? 'd_' . bin2hex(random_bytes(4))),
                'serial'          => $d['serial'] ?? null,
                'installed_on'    => (string)($d['installed_on'] ?? date('Y-m-d')),
                'initial_counter' => (float)($d['initial_counter'] ?? 0.0),
                'removed_on'      => $d['removed_on'] ?? null,
                'final_counter'   => isset($d['final_counter']) ? (float)$d['final_counter'] : null,
                'reason'          => $d['reason'] ?? null,
            ], $input['devices']);
        } else {
            $devices = [[
                'id'              => 'd_' . bin2hex(random_bytes(4)),
                'serial'          => $input['device_serial'] ?? null,
                'installed_on'    => $input['installed_on'] ?? date('Y-m-d'),
                'initial_counter' => (float)($input['initial_counter'] ?? 0.0),
                'removed_on'      => null,
                'final_counter'   => null,
                'reason'          => null,
            ]];
        }

        $meter = [
            'id'         => 'm_' . $utility . '_' . bin2hex(random_bytes(4)),
            'name'       => (string)($input['name'] ?? ''),
            'icon'       => (string)($input['icon'] ?? $u['icon']),
            'created_at' => date('Y-m-d'),
            'active'     => true,
            'notes'      => (string)($input['notes'] ?? ''),
            // v1.2.0 — F1006 Meter-Topologie (Default: keine Beziehung)
            'parent_meter_id' => null,
            'meter_group_id'  => null,
            'devices'    => $devices,
        ];

        // v1.2.0 — F1006: Topologie-Beziehungen optional schon beim Anlegen
        // setzen. Validierung über den gemeinsamen Pfad (Zyklen, Ketten,
        // Existenz). Bezieht den noch nicht gespeicherten Meter mit ein.
        $existing = $this->list($utility);
        $candidatePool = array_merge($existing, [$meter]);
        if (array_key_exists('parent_meter_id', $input)) {
            $meter['parent_meter_id'] = $this->normalizeRef($input['parent_meter_id']);
        }
        if (array_key_exists('meter_group_id', $input)) {
            $meter['meter_group_id'] = $this->normalizeRef($input['meter_group_id']);
        }
        $this->assertTopologyValid($utility, $meter, $candidatePool);

        // v1.3.0 — Tank-/Lager-Felder bei Delivery-Utilities (Heizöl/Pellets).
        // Pflicht: capacity > 0 und initial_stock ≥ 0 — ohne diese werden
        // Bestand und Tank-Warnung sinnlos. capacity_unit wird per Default
        // aus der Utility-Definition übernommen.
        if ($isDelivery) {
            if (!isset($input['capacity']) || (float)$input['capacity'] <= 0) {
                throw new \InvalidArgumentException(
                    'Tank-Kapazität (capacity) > 0 ist für ' . $u['label'] . ' Pflicht'
                );
            }
            if (!isset($input['initial_stock']) || (float)$input['initial_stock'] < 0) {
                throw new \InvalidArgumentException(
                    'Anfangsbestand (initial_stock) ≥ 0 ist für ' . $u['label'] . ' Pflicht'
                );
            }
            $meter['capacity']      = (float)$input['capacity'];
            $meter['capacity_unit'] = (string)($input['capacity_unit'] ?? $u['volume_unit'] ?? '');
            $meter['initial_stock'] = (float)$input['initial_stock'];
        }

        if ($meter['name'] === '') {
            throw new \InvalidArgumentException('Zählername darf nicht leer sein');
        }

        $all = $this->list($utility);
        $all[] = $meter;
        $this->store->write("$utility/meters.json", $all);
        return $meter;
    }

    public function update(string $utility, string $meterId, array $input): array
    {
        $all = $this->list($utility);
        $found = false;
        $isDelivery = Utilities::isDelivery($utility);
        foreach ($all as &$m) {
            if (($m['id'] ?? null) !== $meterId) continue;
            $found = true;
            foreach (['name', 'icon', 'notes', 'active'] as $f) {
                if (array_key_exists($f, $input)) {
                    $m[$f] = $f === 'active' ? (bool)$input[$f] : (string)$input[$f];
                }
            }
            // v1.2.0 — F1006: Topologie-Beziehungen änderbar
            if (array_key_exists('parent_meter_id', $input)) {
                $m['parent_meter_id'] = $this->normalizeRef($input['parent_meter_id']);
            }
            if (array_key_exists('meter_group_id', $input)) {
                $m['meter_group_id'] = $this->normalizeRef($input['meter_group_id']);
            }
            // v1.3.0 — Tank-Felder updatebar (nur bei Delivery-Utilities)
            if ($isDelivery) {
                if (array_key_exists('capacity', $input)) {
                    $m['capacity'] = (float)$input['capacity'];
                }
                if (array_key_exists('capacity_unit', $input)) {
                    $m['capacity_unit'] = (string)$input['capacity_unit'];
                }
                if (array_key_exists('initial_stock', $input)) {
                    $m['initial_stock'] = (float)$input['initial_stock'];
                }
            }
        }
        unset($m);
        if (!$found) throw new \InvalidArgumentException('Zähler nicht gefunden');
        // v1.2.0 — F1006: Topologie nach der Änderung validieren (Zyklen,
        // mehrstufige Ketten, Existenz von Eltern/Gruppe).
        if (array_key_exists('parent_meter_id', $input) || array_key_exists('meter_group_id', $input)) {
            $updated = null;
            foreach ($all as $m) {
                if (($m['id'] ?? null) === $meterId) { $updated = $m; break; }
            }
            if ($updated !== null) {
                $this->assertTopologyValid($utility, $updated, $all);
            }
        }
        $this->store->write("$utility/meters.json", $all);
        return $this->get($utility, $meterId);
    }

    public function delete(string $utility, string $meterId): void
    {
        $readings = $this->store->read("$utility/readings.json", []);
        foreach ($readings as $r) {
            if (($r['meter_id'] ?? null) === $meterId) {
                throw new \InvalidArgumentException('Zähler hat noch Ablesungen — bitte zuerst Ablesungen löschen oder umhängen');
            }
        }
        // v1.3.0 — auch Lieferungen blocken die Tank-Löschung
        $deliveries = $this->store->read("$utility/deliveries.json", []);
        foreach ($deliveries as $d) {
            if (($d['meter_id'] ?? null) === $meterId) {
                throw new \InvalidArgumentException('Tank/Lager hat noch Lieferungen — bitte zuerst Lieferungen löschen oder umhängen');
            }
        }
        $contracts = $this->store->read("$utility/contracts.json", []);
        foreach ($contracts as $c) {
            if (($c['meter_id'] ?? null) === $meterId) {
                throw new \InvalidArgumentException('Zähler hat noch Verträge — bitte zuerst Verträge löschen oder umhängen');
            }
        }
        // v1.2.0 — F1006: ein Elternzähler mit Subzählern kann nicht gelöscht
        // werden, ohne die Kinder zu verwaisen. Erst Subzähler auflösen.
        foreach ($this->list($utility) as $m) {
            if (($m['parent_meter_id'] ?? null) === $meterId) {
                throw new \InvalidArgumentException('Zähler ist Elternzähler von Subzählern — bitte zuerst die Subzähler-Zuordnung auflösen');
            }
        }
        $all = array_values(array_filter($this->list($utility), fn($m) => ($m['id'] ?? null) !== $meterId));
        $this->store->write("$utility/meters.json", $all);
    }

    /**
     * Replace the current (open) device of a meter with a new device.
     * Implements F2 (Zählertausch).
     *
     * Closes the previous device with final_counter & removed_on,
     * opens a new device with initial_counter & installed_on.
     *
     * @param array{date:string, old_final_counter:float, new_initial_counter:float, serial?:?string, reason?:?string} $input
     */
    public function replaceDevice(string $utility, string $meterId, array $input): array
    {
        $all = $this->list($utility);
        $found = false;
        foreach ($all as &$m) {
            if (($m['id'] ?? null) !== $meterId) continue;
            $found = true;
            $devices = $m['devices'] ?? [];
            // Find the currently-open device
            $openIdx = null;
            foreach ($devices as $i => $d) {
                if (empty($d['removed_on'])) { $openIdx = $i; break; }
            }
            if ($openIdx === null) {
                throw new \InvalidArgumentException('Kein offener Zähler vorhanden — bitte zuerst Zähler initial anlegen');
            }
            $date = $input['date'] ?? date('Y-m-d');

            // v1.6.1 — Issue #13: alter Endstand muss explizit
            // angegeben werden. Vorher wurde stillschweigend 0 gesetzt,
            // wenn das Feld fehlte — das führte zu unrealistischen
            // Bridging-Ausschlägen im Monat des Tauschs.
            if (!isset($input['old_final_counter']) || $input['old_final_counter'] === '') {
                throw new \InvalidArgumentException(
                    'Beim Zählertausch muss der Endstand des alten Geräts '
                    . '(„old_final_counter") angegeben werden.'
                );
            }
            $oldFinal = (float)$input['old_final_counter'];
            $newInit  = isset($input['new_initial_counter']) && $input['new_initial_counter'] !== ''
                        ? (float)$input['new_initial_counter']
                        : 0.0;

            $devices[$openIdx]['removed_on']    = $date;
            $devices[$openIdx]['final_counter'] = $oldFinal;
            $devices[$openIdx]['reason']        = $input['reason'] ?? null;

            $devices[] = [
                'id'              => 'd_' . bin2hex(random_bytes(4)),
                'serial'          => $input['serial'] ?? null,
                'installed_on'    => $date,
                'initial_counter' => $newInit,
                'removed_on'      => null,
                'final_counter'   => null,
                'reason'          => null,
            ];
            $m['devices'] = $devices;
        }
        unset($m);
        if (!$found) throw new \InvalidArgumentException('Zähler nicht gefunden');
        $this->store->write("$utility/meters.json", $all);
        return $this->get($utility, $meterId);
    }

    /**
     * Resolve which device was active on a given date for a given meter.
     * Returns null if no device covers the date.
     */
    public function deviceOnDate(array $meter, string $date): ?array
    {
        foreach ($meter['devices'] ?? [] as $d) {
            if ($date < ($d['installed_on'] ?? '9999-99-99')) continue;
            // v1.6.1 — Issue #13: am Tausch-Tag (removed_on) ist das
            // alte Gerät bereits ausgebaut; eine Ablesung an diesem
            // Tag gehört zum neuen Gerät.
            if (!empty($d['removed_on']) && $date >= $d['removed_on']) continue;
            return $d;
        }
        return null;
    }

    private function assertUtility(string $utility): void
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException('Unbekannte Verbrauchsart: ' . $utility);
        }
    }

    // ── v1.2.0 — F1006 Meter-Topologie ────────────────────────────────────

    /** Leerstring/leere Werte zu null normalisieren, sonst als string. */
    private function normalizeRef(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) return null;
        return (string)$value;
    }

    /**
     * Validiert die Topologie-Beziehungen eines (ggf. noch nicht persistierten)
     * Zählers gegen den übergebenen Pool aller Zähler derselben Utility.
     *
     * Regeln (Detail-Konzept F1006, 2026-06-01):
     *   - parent_meter_id ≠ eigene id (keine Selbstreferenz),
     *   - Elternzähler muss im Pool existieren,
     *   - keine mehrstufigen Subzähler-Ketten (max. 1 Ebene): der Elternzähler
     *     darf selbst keinen parent_meter_id tragen, und dieser Zähler darf
     *     nicht selbst Elternzähler eines anderen sein,
     *   - meter_group_id muss auf eine existierende Gruppe verweisen.
     *
     * @param array<int,array<string,mixed>> $pool alle Zähler der Utility
     *        (inkl. des zu prüfenden Zählers, in seinem neuen Zustand)
     */
    private function assertTopologyValid(string $utility, array $meter, array $pool): void
    {
        $id     = (string)($meter['id'] ?? '');
        $parent = $meter['parent_meter_id'] ?? null;
        $group  = $meter['meter_group_id'] ?? null;

        if ($parent !== null) {
            if ($parent === $id) {
                throw new \InvalidArgumentException('Ein Zähler kann nicht sein eigener Elternzähler sein');
            }
            $parentMeter = null;
            foreach ($pool as $m) {
                if (($m['id'] ?? null) === $parent) { $parentMeter = $m; break; }
            }
            if ($parentMeter === null) {
                throw new \InvalidArgumentException('Elternzähler nicht gefunden: ' . $parent);
            }
            // Keine mehrstufige Kette: der Elternzähler darf selbst kein Subzähler sein.
            if (($parentMeter['parent_meter_id'] ?? null) !== null) {
                throw new \InvalidArgumentException(
                    'Mehrstufige Subzähler-Ketten sind nicht erlaubt — der gewählte '
                    . 'Elternzähler ist selbst bereits ein Subzähler (max. 1 Ebene).'
                );
            }
            // ... und dieser Zähler darf selbst kein Elternzähler sein.
            foreach ($pool as $m) {
                if (($m['parent_meter_id'] ?? null) === $id) {
                    throw new \InvalidArgumentException(
                        'Mehrstufige Subzähler-Ketten sind nicht erlaubt — dieser Zähler '
                        . 'ist selbst bereits Elternzähler eines Subzählers (max. 1 Ebene).'
                    );
                }
            }
        }

        if ($group !== null) {
            if ($this->getGroup($utility, $group) === null) {
                throw new \InvalidArgumentException('Zählergruppe nicht gefunden: ' . $group);
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function listGroups(string $utility): array
    {
        $this->assertUtility($utility);
        $groups = $this->store->read("$utility/meter_groups.json", []);
        return is_array($groups) ? array_values($groups) : [];
    }

    public function getGroup(string $utility, string $groupId): ?array
    {
        foreach ($this->listGroups($utility) as $g) {
            if (($g['id'] ?? null) === $groupId) return $g;
        }
        return null;
    }

    public function createGroup(string $utility, array $input): array
    {
        $this->assertUtility($utility);
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Gruppenname darf nicht leer sein');
        }
        $group = [
            'id'         => 'g_' . $utility . '_' . bin2hex(random_bytes(4)),
            'name'       => $name,
            'created_at' => date('Y-m-d'),
        ];
        $all = $this->listGroups($utility);
        $all[] = $group;
        $this->store->write("$utility/meter_groups.json", $all);
        return $group;
    }

    public function updateGroup(string $utility, string $groupId, array $input): array
    {
        $all = $this->listGroups($utility);
        $found = false;
        foreach ($all as &$g) {
            if (($g['id'] ?? null) !== $groupId) continue;
            $found = true;
            if (array_key_exists('name', $input)) {
                $name = trim((string)$input['name']);
                if ($name === '') {
                    throw new \InvalidArgumentException('Gruppenname darf nicht leer sein');
                }
                $g['name'] = $name;
            }
        }
        unset($g);
        if (!$found) throw new \InvalidArgumentException('Zählergruppe nicht gefunden');
        $this->store->write("$utility/meter_groups.json", $all);
        return $this->getGroup($utility, $groupId);
    }

    /**
     * Löscht eine Gruppe. Mitglieder werden NICHT gelöscht, sondern aus der
     * Gruppe gelöst (meter_group_id → null), damit keine verwaisten Verweise
     * zurückbleiben.
     */
    public function deleteGroup(string $utility, string $groupId): void
    {
        if ($this->getGroup($utility, $groupId) === null) {
            throw new \InvalidArgumentException('Zählergruppe nicht gefunden');
        }
        // Mitglieder lösen
        $meters = $this->list($utility);
        $changed = false;
        foreach ($meters as &$m) {
            if (($m['meter_group_id'] ?? null) === $groupId) {
                $m['meter_group_id'] = null;
                $changed = true;
            }
        }
        unset($m);
        if ($changed) {
            $this->store->write("$utility/meters.json", $meters);
        }
        $all = array_values(array_filter(
            $this->listGroups($utility),
            fn($g) => ($g['id'] ?? null) !== $groupId
        ));
        $this->store->write("$utility/meter_groups.json", $all);
    }

    /**
     * Merge-Wizard (F1006): mehrere bestehende Zähler zu einer Gruppe
     * zusammenführen. Legt entweder eine neue Gruppe an (wenn `name` gesetzt)
     * oder nutzt eine bestehende (`group_id`), und setzt bei allen `meter_ids`
     * die `meter_group_id`.
     *
     * @param array{group_id?:string, name?:string, meter_ids:array<int,string>} $input
     * @return array{group:array<string,mixed>, members:int}
     */
    public function mergeIntoGroup(string $utility, array $input): array
    {
        $this->assertUtility($utility);
        $meterIds = $input['meter_ids'] ?? [];
        if (!is_array($meterIds) || count($meterIds) < 2) {
            throw new \InvalidArgumentException('Zum Zusammenführen werden mindestens zwei Zähler benötigt');
        }

        // Zielgruppe bestimmen oder anlegen.
        if (!empty($input['group_id'])) {
            $group = $this->getGroup($utility, (string)$input['group_id']);
            if ($group === null) {
                throw new \InvalidArgumentException('Zählergruppe nicht gefunden: ' . $input['group_id']);
            }
        } else {
            $group = $this->createGroup($utility, ['name' => $input['name'] ?? '']);
        }

        $meters = $this->list($utility);
        $known = [];
        foreach ($meters as $m) {
            if (isset($m['id'])) $known[(string)$m['id']] = true;
        }
        foreach ($meterIds as $mid) {
            if (!isset($known[(string)$mid])) {
                throw new \InvalidArgumentException('Zähler nicht gefunden: ' . $mid);
            }
        }

        $want = array_fill_keys(array_map('strval', $meterIds), true);
        $members = 0;
        foreach ($meters as &$m) {
            if (isset($want[(string)($m['id'] ?? '')])) {
                $m['meter_group_id'] = $group['id'];
                $members++;
            }
        }
        unset($m);
        $this->store->write("$utility/meters.json", $meters);

        return ['group' => $group, 'members' => $members];
    }
}
