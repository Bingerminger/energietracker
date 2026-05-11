<?php
declare(strict_types=1);

namespace Energietracker\Storage;

use Energietracker\Config\Utilities;

/**
 * One-way migration from v0.9.0 data layout to v1.0.0.
 *
 * v0.9.0 layout (flat):
 *   data/gas.json          — array of readings
 *   data/strom.json        — array of readings
 *   data/contracts.json    — {gas: [...], strom: [...]}
 *   data/temperatures.json — keep as-is
 *   data/settings.json     — keep & extend
 *
 * v1.0.0 layout (utility-orientiert):
 *   data/meta.json
 *   data/gas/meters.json     — meter chains with device history (Zählertausch)
 *   data/gas/readings.json   — readings, each carries meter_id
 *   data/gas/contracts.json  — contracts, each carries meter_id
 *   data/strom/...           — same shape
 *   data/wasser/...          — empty defaults (new utility)
 *   data/temperatures.json   — unchanged
 *   data/settings.json       — extended with wasser_* keys
 *
 * Safety: the migration writes new files only; the old files are renamed to
 * *.v0_9_0_backup so they can be inspected or restored manually.
 */
final class Migrator
{
    public const SCHEMA_VERSION = '1.0.0';

    public function __construct(private JsonStore $store) {}

    public function isAlreadyMigrated(): bool
    {
        $meta = $this->store->read('meta.json', []);
        return ($meta['schema_version'] ?? '') === self::SCHEMA_VERSION;
    }

    public function needsMigration(): bool
    {
        if ($this->isAlreadyMigrated()) return false;
        // Look for v0.9.0 signatures
        return $this->store->exists('gas.json')
            || $this->store->exists('strom.json')
            || $this->store->exists('contracts.json');
    }

    public function migrate(): array
    {
        $log = [];

        // ── readings + meters per utility ─────────────────────────────────
        foreach (['gas', 'strom'] as $utility) {
            $old = $this->store->read($utility . '.json', []);
            if (!is_array($old)) $old = [];

            $meterId = 'm_' . $utility . '_default';
            $meters = [[
                'id'         => $meterId,
                'name'       => 'Hauptzähler',
                'icon'       => Utilities::get($utility)['icon'],
                'created_at' => date('Y-m-d'),
                'active'     => true,
                'notes'      => 'Automatisch migriert aus v0.9.0',
                'devices'    => [[
                    'id'               => 'd_' . $utility . '_1',
                    'serial'           => null,
                    'installed_on'     => $this->earliestDate($old) ?? date('Y-m-d'),
                    'initial_counter'  => $this->earliestCounter($old),
                    'removed_on'       => null,
                    'final_counter'    => null,
                    'reason'           => null,
                ]],
            ]];

            $newReadings = [];
            foreach ($old as $r) {
                if (!is_array($r)) continue;
                $r['meter_id'] = $meterId;
                $r['device_id'] = 'd_' . $utility . '_1';
                $newReadings[] = $r;
            }

            $this->store->write($utility . '/meters.json', $meters);
            $this->store->write($utility . '/readings.json', $newReadings);
            $log[] = sprintf('%s: %d Ablesungen migriert, 1 Zähler angelegt', $utility, count($newReadings));

            // Backup old file
            $this->backupIfExists($utility . '.json');
        }

        // ── wasser: fresh defaults ────────────────────────────────────────
        $wasserMeterId = 'm_wasser_default';
        $this->store->write('wasser/meters.json', [[
            'id'         => $wasserMeterId,
            'name'       => 'Hauptzähler',
            'icon'       => '💧',
            'created_at' => date('Y-m-d'),
            'active'     => true,
            'notes'      => 'Standard-Wasserzähler (v1.0.0)',
            'devices'    => [[
                'id'              => 'd_wasser_1',
                'serial'          => null,
                'installed_on'    => date('Y-m-d'),
                'initial_counter' => 0.0,
                'removed_on'      => null,
                'final_counter'   => null,
                'reason'          => null,
            ]],
        ]]);
        $this->store->write('wasser/readings.json', []);
        $this->store->write('wasser/contracts.json', []);
        $log[] = 'wasser: leere Standardstruktur angelegt';

        // ── contracts ─────────────────────────────────────────────────────
        $oldContracts = $this->store->read('contracts.json', []);
        foreach (['gas', 'strom'] as $utility) {
            $list = $oldContracts[$utility] ?? [];
            $meterId = 'm_' . $utility . '_default';
            $migrated = [];
            foreach ($list as $c) {
                if (!is_array($c)) continue;
                $c['meter_id'] = $meterId;
                $migrated[] = $c;
            }
            $this->store->write($utility . '/contracts.json', $migrated);
            $log[] = sprintf('%s: %d Verträge migriert', $utility, count($migrated));
        }
        $this->backupIfExists('contracts.json');

        // ── settings: extend with wasser keys ─────────────────────────────
        $settings = $this->store->read('settings.json', []);
        $settings += [
            'co2_wasser'             => 350.0,  // g CO2 per m³ (Frisch+Abwasser kombiniert, Richtwert)
            'wasser_personen_anzahl' => 2,
            'wasser_personen_referenz' => 127.0, // L/Person/Tag
        ];
        $this->store->write('settings.json', $settings);
        $log[] = 'settings: Wasser-spezifische Defaults ergänzt';

        // ── write meta marker ─────────────────────────────────────────────
        $this->store->write('meta.json', [
            'schema_version'  => self::SCHEMA_VERSION,
            'migrated_at'     => date('c'),
            'migrated_from'   => '0.9.0',
            'log'             => $log,
        ]);

        return $log;
    }

    /** Used on a fresh install — sets schema version without legacy data. */
    public function initFresh(): void
    {
        foreach (Utilities::keys() as $key) {
            if (!$this->store->exists($key . '/meters.json')) {
                $meterId = 'm_' . $key . '_default';
                $this->store->write($key . '/meters.json', [[
                    'id' => $meterId,
                    'name' => Utilities::get($key)['default_meter_name'],
                    'icon' => Utilities::get($key)['icon'],
                    'created_at' => date('Y-m-d'),
                    'active' => true,
                    'notes' => '',
                    'devices' => [[
                        'id' => 'd_' . $key . '_1',
                        'serial' => null,
                        'installed_on' => date('Y-m-d'),
                        'initial_counter' => 0.0,
                        'removed_on' => null,
                        'final_counter' => null,
                        'reason' => null,
                    ]],
                ]]);
            }
            if (!$this->store->exists($key . '/readings.json')) {
                $this->store->write($key . '/readings.json', []);
            }
            if (!$this->store->exists($key . '/contracts.json')) {
                $this->store->write($key . '/contracts.json', []);
            }
        }
        if (!$this->store->exists('temperatures.json')) {
            $this->store->write('temperatures.json', []);
        }
        if (!$this->store->exists('settings.json')) {
            $this->store->write('settings.json', []);
        }
        $this->store->write('meta.json', [
            'schema_version' => self::SCHEMA_VERSION,
            'created_at'     => date('c'),
        ]);
    }

    private function earliestDate(array $readings): ?string
    {
        $dates = array_filter(array_map(fn($r) => is_array($r) ? ($r['date'] ?? null) : null, $readings));
        return $dates ? min($dates) : null;
    }

    private function earliestCounter(array $readings): float
    {
        $earliest = null;
        $earliestDate = null;
        foreach ($readings as $r) {
            if (!is_array($r) || !isset($r['date'], $r['counter'])) continue;
            if ($earliestDate === null || $r['date'] < $earliestDate) {
                $earliestDate = $r['date'];
                $earliest = (float)$r['counter'];
            }
        }
        return $earliest ?? 0.0;
    }

    private function backupIfExists(string $relative): void
    {
        $path = $this->store->path($relative);
        if (is_file($path)) {
            @rename($path, $path . '.v0_9_0_backup');
        }
    }
}
