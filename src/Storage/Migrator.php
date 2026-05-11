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
    public const SCHEMA_VERSION = '1.0.3';

    public function __construct(private JsonStore $store) {}

    public function isAlreadyMigrated(): bool
    {
        $meta = $this->store->read('meta.json', []);
        return ($meta['schema_version'] ?? '') === self::SCHEMA_VERSION;
    }

    public function needsMigration(): bool
    {
        if ($this->isAlreadyMigrated()) return false;
        // v0.9.0 signatures take precedence: they need the full migrate() path.
        if ($this->store->exists('gas.json')
            || $this->store->exists('strom.json')
            || $this->store->exists('contracts.json')) {
            return true;
        }
        // Otherwise we may need a 1.0.x → 1.0.3 schema bump (water contracts).
        return $this->needsWaterContractsUpgrade();
    }

    /**
     * Detect water contracts in the pre-1.0.3 shape (flat working_prices
     * with ct_per_kwh). Returns true if at least one such contract is on disk.
     */
    public function needsWaterContractsUpgrade(): bool
    {
        if (!$this->store->exists('wasser/contracts.json')) return false;
        $list = $this->store->read('wasser/contracts.json', []);
        if (!is_array($list)) return false;
        foreach ($list as $c) {
            if (!is_array($c)) continue;
            // 1.0.3 contracts have a 'trinkwasser' block; older ones don't.
            if (isset($c['trinkwasser'])) continue;
            // pre-1.0.3 contracts have a flat working_prices array
            if (isset($c['working_prices'])) return true;
        }
        return false;
    }

    public function migrate(): array
    {
        $log = [];

        // ── v0.9.0 → v1.0.0 (full migration if old files exist) ────────────
        if ($this->store->exists('gas.json')
            || $this->store->exists('strom.json')
            || $this->store->exists('contracts.json')) {
            $log = array_merge($log, $this->migrateFromV09());
        }

        // ── v1.0.x → v1.0.3 water-contract upgrade ────────────────────────
        if ($this->needsWaterContractsUpgrade()) {
            $log = array_merge($log, $this->upgradeWaterContracts());
        }

        // ── write meta marker ─────────────────────────────────────────────
        $this->store->write('meta.json', [
            'schema_version'  => self::SCHEMA_VERSION,
            'migrated_at'     => date('c'),
            'log'             => $log,
        ]);

        return $log;
    }

    /**
     * v1.0.x → v1.0.3 water-contracts upgrade.
     *
     * Converts a pre-1.0.3 water contract:
     *   { working_prices: [{from, ct_per_kwh}], base_prices, advance_payments, ... }
     * into the v1.0.3 component shape:
     *   {
     *     trinkwasser:   { working_prices: [{from, ct_per_m3}], base_prices: [...] },
     *     schmutzwasser: { basis: 'trinkwasser', working_prices: [] },
     *     niederschlagswasser: { rates: [] },
     *     advance_payments, bonuses, ...
     *   }
     *
     * The legacy contracts conflated trinkwasser + schmutzwasser into a
     * single ct_per_kwh — we move that value into trinkwasser.working_prices
     * as ct_per_m3 (the unit-rename happens silently because the legacy
     * field was already semantically ct/m³ for water; see the notes in the
     * old demo data).
     *
     * schmutzwasser and niederschlagswasser come out empty — the user has
     * to fill them in manually, since the legacy data didn't split them.
     */
    public function upgradeWaterContracts(): array
    {
        $log = [];
        if (!$this->store->exists('wasser/contracts.json')) return $log;
        $list = $this->store->read('wasser/contracts.json', []);
        if (!is_array($list)) return $log;
        $upgraded = 0;
        $skipped = 0;
        foreach ($list as &$c) {
            if (!is_array($c)) continue;
            if (isset($c['trinkwasser'])) { $skipped++; continue; }

            // Convert the legacy ct_per_kwh entries into ct_per_m3.
            $oldPrices = is_array($c['working_prices'] ?? null) ? $c['working_prices'] : [];
            $twPrices = [];
            foreach ($oldPrices as $p) {
                if (!is_array($p) || !isset($p['from'])) continue;
                $twPrices[] = [
                    'from'      => (string)$p['from'],
                    'ct_per_m3' => (float)($p['ct_per_kwh'] ?? $p['ct_per_m3'] ?? 0),
                ];
            }
            $c['trinkwasser'] = [
                'working_prices' => $twPrices,
                'base_prices'    => is_array($c['base_prices'] ?? null) ? $c['base_prices'] : [],
            ];
            $c['schmutzwasser'] = [
                'basis'                       => 'trinkwasser',
                'separater_zaehler_meter_id'  => null,
                'working_prices'              => [],
            ];
            $c['niederschlagswasser'] = [
                'rates' => [],
            ];
            // Append a hint to notes so the user knows
            $hint = '⚠ Aus v1.0.2 migriert — Schmutzwasser- und Niederschlagswasser-Tarife bitte manuell ergänzen.';
            $c['notes'] = trim(($c['notes'] ?? '') . ' ' . $hint);
            // Remove legacy flat fields
            unset($c['working_prices'], $c['base_prices']);
            $upgraded++;
        }
        unset($c);
        if ($upgraded > 0) {
            $this->store->write('wasser/contracts.json', $list);
        }
        $log[] = sprintf('wasser: %d Verträge auf v1.0.3-Komponentenmodell aufgewertet, %d bereits aktuell', $upgraded, $skipped);
        return $log;
    }

    private function migrateFromV09(): array
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
