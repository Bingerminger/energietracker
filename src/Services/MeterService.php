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

        $meter = [
            'id'         => 'm_' . $utility . '_' . bin2hex(random_bytes(4)),
            'name'       => (string)($input['name'] ?? ''),
            'icon'       => (string)($input['icon'] ?? $u['icon']),
            'created_at' => date('Y-m-d'),
            'active'     => true,
            'notes'      => (string)($input['notes'] ?? ''),
            'devices'    => [[
                'id'              => 'd_' . bin2hex(random_bytes(4)),
                'serial'          => $input['device_serial'] ?? null,
                'installed_on'    => $input['installed_on'] ?? date('Y-m-d'),
                'initial_counter' => (float)($input['initial_counter'] ?? 0.0),
                'removed_on'      => null,
                'final_counter'   => null,
                'reason'          => null,
            ]],
        ];
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
        foreach ($all as &$m) {
            if (($m['id'] ?? null) !== $meterId) continue;
            $found = true;
            foreach (['name', 'icon', 'notes', 'active'] as $f) {
                if (array_key_exists($f, $input)) {
                    $m[$f] = $f === 'active' ? (bool)$input[$f] : (string)$input[$f];
                }
            }
        }
        unset($m);
        if (!$found) throw new \InvalidArgumentException('Zähler nicht gefunden');
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
        $contracts = $this->store->read("$utility/contracts.json", []);
        foreach ($contracts as $c) {
            if (($c['meter_id'] ?? null) === $meterId) {
                throw new \InvalidArgumentException('Zähler hat noch Verträge — bitte zuerst Verträge löschen oder umhängen');
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
            $devices[$openIdx]['removed_on']    = $date;
            $devices[$openIdx]['final_counter'] = (float)($input['old_final_counter'] ?? 0);
            $devices[$openIdx]['reason']        = $input['reason'] ?? null;

            $devices[] = [
                'id'              => 'd_' . bin2hex(random_bytes(4)),
                'serial'          => $input['serial'] ?? null,
                'installed_on'    => $date,
                'initial_counter' => (float)($input['new_initial_counter'] ?? 0),
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
            if (!empty($d['removed_on']) && $date > $d['removed_on']) continue;
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
}
