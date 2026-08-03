<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;

/**
 * Migration of legacy v0.9.0 backup files into the v1.0.3 data model.
 *
 * Why a separate service:
 *   The v0.9.0 backup format (version "2.1") does not know meters, devices,
 *   or the three-utility layout. It stores readings as flat arrays under
 *   "gas"/"strom", contracts in a {gas: [], strom: []} sub-object, and a
 *   compact reading shape with `comment` + `is_notable`. The v1.0.3 model
 *   requires `meter_id`, `device_id`, `note`, optional `is_estimated`,
 *   plus the new "wasser" utility.
 *
 * Strategy:
 *   - One synthetic default meter per utility (id = m_<utility>_main)
 *   - One synthetic default device per meter (id = d_<utility>_001), open-ended
 *   - Readings get meter_id + device_id of that default device
 *   - Field rename: comment → note
 *   - is_notable kept as a hint in the report but not in the new model
 *   - is_future preserved
 *   - is_estimated defaults to false (v0.9.0 had no such field)
 *   - Contracts gain meter_id = m_<utility>_main
 *   - Temperatures: format identical, copied verbatim
 *   - Settings: v0.9.0 keys are a subset of v1.0.3 keys, merged onto defaults
 *
 * The migration is split into two phases:
 *   1. preview()  — pure transform, no writes. Returns the translated
 *                   payload plus a report (counts, warnings, candidate
 *                   device-replacement readings).
 *   2. apply()    — writes to storage. Two modes:
 *                     'replace' = wipe target utility before write
 *                     'merge'   = append, skip on ID collision
 */
final class MigrationService
{
    /** Supported v0.9.0 backup format versions (the field is called "version" there). */
    public const SUPPORTED_LEGACY_VERSIONS = ['1.0', '2.0', '2.1'];

    public function __construct(
        private JsonStore $store,
        private BackupService $backup,
        private I18nService $i18n,
    ) {}

    /**
     * Validate a v0.9.0 backup and translate it into the v1.0.3 shape.
     * No data is written. Returns:
     *   {
     *     ok: bool,
     *     legacy_version: string,
     *     translated: { meta, settings, temperatures, utilities: { gas, strom, wasser } },
     *     report: {
     *       readings: { gas: N, strom: N, wasser: 0 },
     *       contracts: { gas: N, strom: N, wasser: 0 },
     *       temperatures: N,
     *       settings: N,
     *       warnings: [string],
     *       device_replacement_candidates: [
     *         { utility, reading_id, date, counter, comment }
     *       ],
     *     }
     *   }
     */
    public function preview(array $backup): array
    {
        $legacyVer = (string)($backup['version'] ?? '');
        if ($legacyVer === '') {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.migration.noVersionField')
            );
        }
        if (!in_array($legacyVer, self::SUPPORTED_LEGACY_VERSIONS, true)) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.migration.versionUnrecognized', [
                    'ver'       => $legacyVer,
                    'supported' => implode(', ', self::SUPPORTED_LEGACY_VERSIONS),
                ])
            );
        }

        $warnings = [];
        $candidates = [];

        // ── 1. Meta ────────────────────────────────────────────────
        $meta = [
            'schema_version' => '1.0.3',
            'created_at'     => date('c'),
            'migrated_from'  => "v0.9.0 backup (format $legacyVer)",
        ];

        // ── 2. Settings ────────────────────────────────────────────
        $legacySettings = is_array($backup['settings'] ?? null) ? $backup['settings'] : [];
        unset($legacySettings['version']); // legacy meta field
        $settings = $this->mergeSettings($legacySettings);
        if (count($legacySettings) === 0) {
            $warnings[] = 'Keine Settings im Backup gefunden — v1.0.3-Standardwerte werden verwendet.';
        }

        // ── 3. Temperatures ────────────────────────────────────────
        $temps = is_array($backup['temperatures'] ?? null) ? $backup['temperatures'] : [];

        // ── 4. Per-utility translation ─────────────────────────────
        $utilities = [
            'gas'    => $this->translateUtility('gas',    $backup, $warnings, $candidates),
            'strom'  => $this->translateUtility('strom',  $backup, $warnings, $candidates),
            'wasser' => $this->emptyUtility('wasser'),
        ];
        $warnings[] = 'v0.9.0 kennt kein Wasser — der Wasser-Utility-Bereich wird leer angelegt.';

        return [
            'ok'             => true,
            'legacy_version' => $legacyVer,
            'translated'     => [
                'meta'         => $meta,
                'settings'     => $settings,
                'temperatures' => $temps,
                'utilities'    => $utilities,
            ],
            'report' => [
                'readings'     => [
                    'gas'    => count($utilities['gas']['readings']),
                    'strom'  => count($utilities['strom']['readings']),
                    'wasser' => 0,
                ],
                'contracts'    => [
                    'gas'    => count($utilities['gas']['contracts']),
                    'strom'  => count($utilities['strom']['contracts']),
                    'wasser' => 0,
                ],
                'temperatures' => count($temps),
                'settings'     => count($settings),
                'warnings'     => $warnings,
                'device_replacement_candidates' => $candidates,
            ],
        ];
    }

    /**
     * Apply a previously-translated payload to storage.
     *
     * @param array  $translated The "translated" sub-object from preview() result
     * @param string $mode       'replace' (wipe target first) or 'merge' (append, skip duplicates)
     * @return array Stats: { mode, snapshot, written: { utility => {meters,readings,contracts} } }
     */
    public function apply(array $translated, string $mode): array
    {
        if (!in_array($mode, ['replace', 'merge'], true)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.migration.invalidMode', ['mode' => $mode]));
        }

        // Always snapshot the current state first, regardless of mode, so
        // the user has a rollback even if they pick replace and regret it.
        $snapshot = $this->backup->saveSnapshot();
        $written = [];

        if ($mode === 'replace') {
            $this->store->write('meta.json',         $translated['meta']         ?? []);
            $this->store->write('settings.json',     $translated['settings']     ?? []);
            $this->store->write('temperatures.json', $translated['temperatures'] ?? []);
            foreach (['gas', 'strom', 'wasser'] as $u) {
                $bucket = $translated['utilities'][$u] ?? $this->emptyUtility($u);
                $this->store->write("$u/meters.json",    $bucket['meters']    ?? []);
                $this->store->write("$u/readings.json", $bucket['readings']  ?? []);
                $this->store->write("$u/contracts.json", $bucket['contracts'] ?? []);
                $written[$u] = [
                    'meters'    => count($bucket['meters']    ?? []),
                    'readings'  => count($bucket['readings']  ?? []),
                    'contracts' => count($bucket['contracts'] ?? []),
                ];
            }
        } else {
            // merge mode
            $written = $this->mergeApply($translated);
        }

        return [
            'mode'     => $mode,
            'snapshot' => $snapshot,
            'written'  => $written,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //   Internal helpers
    // ──────────────────────────────────────────────────────────────

    private function translateUtility(string $u, array $backup, array &$warnings, array &$candidates): array
    {
        $rawReadings  = is_array($backup[$u] ?? null) ? $backup[$u] : [];
        $rawContracts = is_array(($backup['contracts'] ?? [])[$u] ?? null)
                        ? $backup['contracts'][$u] : [];

        $meterId  = "m_{$u}_main";
        $deviceId = "d_{$u}_001";
        $unit     = Utilities::get($u)['unit'] ?? 'kWh';

        // ── Default meter + device ────────────────────────────────
        $earliestDate = null;
        foreach ($rawReadings as $r) {
            $d = (string)($r['date'] ?? '');
            if ($d && ($earliestDate === null || $d < $earliestDate)) $earliestDate = $d;
        }
        $installedOn = $earliestDate ?: date('Y-m-d');

        $meters = [[
            'id'         => $meterId,
            'name'       => $this->i18n->defaultMeterName($u),
            'icon'       => Utilities::get($u)['icon'] ?? '🔢',
            'created_at' => date('c'),
            'active'     => true,
            'notes'      => $this->i18n->t('migration.meterNote'),
            'devices'    => [[
                'id'              => $deviceId,
                'serial'          => '',
                'installed_on'    => $installedOn,
                'initial_counter' => 0.0,
                'removed_on'      => null,
                'final_counter'   => null,
                'reason'          => '',
            ]],
        ]];

        // ── Readings ──────────────────────────────────────────────
        $readings = [];
        foreach ($rawReadings as $r) {
            if (!is_array($r) || !isset($r['date'], $r['counter'])) continue;
            $id     = (string)($r['id'] ?? $this->generateId('r'));
            $note   = (string)($r['comment'] ?? '');
            $reading = [
                'id'           => $id,
                'meter_id'     => $meterId,
                'device_id'    => $deviceId,
                'date'         => (string)$r['date'],
                'counter'      => (float)$r['counter'],
                'price_cents'  => $r['price_cents'] ?? null,
                'note'         => $note,
                'is_estimated' => false,
                'is_future'    => (bool)($r['is_future'] ?? false),
            ];
            $readings[] = $reading;

            // Heuristik: nur explizite Zählerwechsel-Hinweise im Kommentar
            // gelten als Kandidat. is_notable alleine bedeutet in v0.9.0
            // einfach "merkwürdige Ablesung" und wird stattdessen mit
            // einem ⭐-Präfix in der note erhalten bleiben.
            $isNotable = (bool)($r['is_notable'] ?? false);
            if ($isNotable && $note !== '' && strpos($note, '⭐') === false) {
                // Mark notable readings non-destructively in the note field
                $reading['note'] = '⭐ ' . $note;
                $readings[count($readings) - 1]['note'] = $reading['note'];
            } elseif ($isNotable && $note === '') {
                $readings[count($readings) - 1]['note'] = '⭐';
            }

            $hasReplacementKeyword = preg_match(
                "/(z\xc3\xa4hlerwechsel|zaehlerwechsel|tausch|austausch|neuer\\s*z\xc3\xa4hler)/iu",
                $note
            );
            if ($hasReplacementKeyword) {
                $candidates[] = [
                    'utility'    => $u,
                    'reading_id' => $id,
                    'date'       => $reading['date'],
                    'counter'    => $reading['counter'],
                    'comment'    => $note,
                    'reason'     => 'Schlüsselwort im Kommentar erkannt',
                ];
            }
        }
        usort($readings, fn($a, $b) => strcmp($a['date'], $b['date']));

        // ── Contracts ─────────────────────────────────────────────
        $contracts = [];
        foreach ($rawContracts as $c) {
            if (!is_array($c)) continue;
            $contracts[] = [
                'id'               => (string)($c['id'] ?? $this->generateId('c')),
                'meter_id'         => $meterId,
                'provider'         => (string)($c['provider']    ?? ''),
                'tariff_name'      => (string)($c['tariff_name'] ?? ''),
                'start'            => $c['start'] ?? null,
                'end'              => $c['end']   ?? null,
                'notes'            => (string)($c['notes'] ?? ''),
                'working_prices'   => $c['working_prices']   ?? [],
                'base_prices'      => $c['base_prices']      ?? [],
                'advance_payments' => $c['advance_payments'] ?? [],
                'bonuses'          => $c['bonuses']          ?? [],
            ];
        }
        usort($contracts, fn($a, $b) => strcmp((string)$a['start'], (string)$b['start']));

        return [
            'meters'    => $meters,
            'readings'  => $readings,
            'contracts' => $contracts,
        ];
    }

    private function emptyUtility(string $u): array
    {
        $meterId  = "m_{$u}_main";
        $deviceId = "d_{$u}_001";
        return [
            'meters'    => [[
                'id'         => $meterId,
                'name'       => $this->i18n->defaultMeterName($u),
                'icon'       => Utilities::get($u)['icon'] ?? '🔢',
                'created_at' => date('c'),
                'active'     => true,
                'notes'      => $this->i18n->t('migration.meterNoteWater'),
                'devices'    => [[
                    'id'              => $deviceId,
                    'serial'          => '',
                    'installed_on'    => date('Y-m-d'),
                    'initial_counter' => 0.0,
                    'removed_on'      => null,
                    'final_counter'   => null,
                    'reason'          => '',
                ]],
            ]],
            'readings'  => [],
            'contracts' => [],
        ];
    }

    /** Merge legacy settings onto v1.0.3 defaults. */
    private function mergeSettings(array $legacy): array
    {
        // Read current effective settings (= defaults + any user overrides
        // already on disk). This preserves new v1.0.3-only keys.
        $current = $this->store->read('settings.json', []);
        $defaults = [
            'gas_conversion_factor' => 11.5,
            'hdd_base_temp'         => 15,
            'co2_gas'               => 201,
            'co2_strom'             => 380,
            'co2_wasser'            => 350,
            'min_days_period'       => 20,
            'min_hdd_regression'    => 5,
            'blend_max'             => 0.8,
            'forecast_months'       => 12,
            'min_temp_days_forecast'=> 20,
            'forecast_model'        => 'linear',
            'dashboard_months'      => 12,
            'alert_days_since_reading' => 45,
            'anomaly_threshold'     => 2,
            'location_name'         => 'Leipzig Zentrum',
            'latitude'              => 51.3397,
            'longitude'             => 12.3731,
            'weather_auto_fill'     => true,
            'wasser_personen_anzahl'    => 2,
            'wasser_personen_referenz'  => 127,
        ];
        return array_merge($defaults, $current, $legacy);
    }

    private function mergeApply(array $translated): array
    {
        $written = [];

        // Temperatures: union (legacy doesn't overwrite existing days)
        $tempsExisting = $this->store->read('temperatures.json', []);
        $tempsNew = is_array($translated['temperatures'] ?? null) ? $translated['temperatures'] : [];
        $merged = $tempsExisting;
        $tempsAdded = 0;
        foreach ($tempsNew as $date => $vals) {
            if (!isset($merged[$date])) { $merged[$date] = $vals; $tempsAdded++; }
        }
        if ($tempsAdded > 0) $this->store->write('temperatures.json', $merged);

        // Settings: only fill missing keys
        $sExisting = $this->store->read('settings.json', []);
        $sNew = is_array($translated['settings'] ?? null) ? $translated['settings'] : [];
        $sMerged = array_merge($sNew, $sExisting); // existing wins
        $this->store->write('settings.json', $sMerged);

        // Utilities: append meters/readings/contracts where IDs don't collide.
        foreach (['gas', 'strom', 'wasser'] as $u) {
            $bucket = $translated['utilities'][$u] ?? $this->emptyUtility($u);
            $mIn  = $bucket['meters']    ?? [];
            $rIn  = $bucket['readings']  ?? [];
            $cIn  = $bucket['contracts'] ?? [];

            $mEx = $this->store->read("$u/meters.json", []);
            $rEx = $this->store->read("$u/readings.json", []);
            $cEx = $this->store->read("$u/contracts.json", []);

            $mAdd = $this->mergeById($mEx, $mIn);
            $rAdd = $this->mergeById($rEx, $rIn);
            $cAdd = $this->mergeById($cEx, $cIn);

            if ($mAdd) $this->store->write("$u/meters.json",    array_merge($mEx, $mAdd));
            if ($rAdd) $this->store->write("$u/readings.json",  array_merge($rEx, $rAdd));
            if ($cAdd) $this->store->write("$u/contracts.json", array_merge($cEx, $cAdd));

            $written[$u] = [
                'meters'    => count($mAdd),
                'readings'  => count($rAdd),
                'contracts' => count($cAdd),
                'skipped'   => [
                    'meters'    => count($mIn) - count($mAdd),
                    'readings'  => count($rIn) - count($rAdd),
                    'contracts' => count($cIn) - count($cAdd),
                ],
            ];
        }

        return $written;
    }

    private function mergeById(array $existing, array $incoming): array
    {
        $haveIds = [];
        foreach ($existing as $e) {
            if (isset($e['id'])) $haveIds[$e['id']] = true;
        }
        $out = [];
        foreach ($incoming as $i) {
            $id = $i['id'] ?? null;
            if ($id !== null && isset($haveIds[$id])) continue;
            $out[] = $i;
        }
        return $out;
    }

    private function generateId(string $prefix): string
    {
        return $prefix . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
