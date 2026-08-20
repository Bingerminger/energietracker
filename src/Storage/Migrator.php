<?php
declare(strict_types=1);

namespace Energietracker\Storage;

use Energietracker\Config\Utilities;

/**
 * Migration zwischen den Daten-Schemaversionen.
 *
 * Schema-Geschichte:
 *   v0.9.0  — flache `data/gas.json`, `data/strom.json`, `data/contracts.json`
 *   v1.0.0  — utility-orientiert: `data/<utility>/{meters,readings,contracts}.json`
 *   v1.0.3  — Wasser-Verträge als 3-Komponentenmodell (Trink-, Schmutz-, Niederschlagswasser)
 *   v1.1.0  — drei neue Verbrauchsarten: Fernwärme (kumulativ) sowie Heizöl
 *             und Pellets (lieferungs-basiert mit `deliveries.json` statt
 *             `readings.json`); zentrale `data/reminders.json` für Termine.
 *   v1.2.0  — Meter-Topologie (F1006): jeder Zähler bekommt `parent_meter_id`
 *             (Subzähler/Reihenschaltung) und `meter_group_id`
 *             (Gruppen-Mitgliedschaft), beide Default null. Pro Utility eine
 *             neue `meter_groups.json` mit den Gruppen-Stammdaten; die
 *             Mitgliedschaft selbst bleibt single-source am Zähler.
 *
 * Die Migration ist additiv und idempotent: das wiederholte Aufrufen ist
 * unschädlich, bestehende Dateien werden nicht überschrieben.
 *
 * v0.9.0-Layout-Notiz für Historiker:
 *   data/gas.json          — array of readings
 *   data/strom.json        — array of readings
 *   data/contracts.json    — {gas: [...], strom: [...]}
 *   data/temperatures.json — keep as-is
 *   data/settings.json     — keep & extend
 *
 * Safety: die Migration schreibt neue Dateien nur, wenn sie noch nicht
 * existieren; eine v0.9.0 → v1.0.0-Migration benennt die alten Dateien
 * zu *.v0_9_0_backup um, damit sie inspizierbar/restorierbar bleiben.
 */
final class Migrator
{
    public const SCHEMA_VERSION = '1.4.0';

    // v2.2.0 — der I18nService ist optional: der Migrator wird im Bootstrap
    // sehr früh und in Tests ohne Container konstruiert. Fehlt er, greifen
    // die deutschen Default-Namen aus der Utilities-SSOT.
    public function __construct(
        private JsonStore $store,
        private ?\Energietracker\Services\I18nService $i18n = null,
    ) {}

    public function isAlreadyMigrated(): bool
    {
        $meta = $this->store->read('meta.json', []);
        return ($meta['schema_version'] ?? '') === self::SCHEMA_VERSION;
    }

    /**
     * Ist das Datenverzeichnis komplett **leer/unberührt** (echter Erststart)?
     *
     * Wahr, wenn weder ein `meta.json`, noch eine v0.9.0-Altdatei
     * (`gas/strom/contracts.json`), noch irgendeine `<utility>/meters.json`
     * existiert. In diesem Fall soll `initFresh()` laufen (legt die
     * Standard-Zähler für Gas/Strom/Wasser an) — NICHT `migrate()`, das ohne
     * Altdaten keine Default-Zähler erzeugt und so einen leeren Tracker
     * hinterließe (z. B. im frisch gestarteten Docker-Container).
     */
    public function isPristine(): bool
    {
        if ($this->store->exists('meta.json')) return false;
        if ($this->store->exists('gas.json')
            || $this->store->exists('strom.json')
            || $this->store->exists('contracts.json')) {
            return false;
        }
        foreach (Utilities::keys() as $key) {
            if ($this->store->exists($key . '/meters.json')) return false;
        }
        return true;
    }

    /**
     * Die Migrationsstufen als **eine** Liste: Prüfmethode → Ausführmethode.
     *
     * `needsMigration()` und `migrate()` lesen beide von hier. Vorher standen
     * die Stufen in beiden Methoden getrennt, und bei v1.4.0 (F1011) wurde nur
     * `migrate()` erweitert — `needsMigration()` endete weiter bei v1.3.0.
     * Folge auf einer Bestandsinstallation: `needsMigration()` meldete false,
     * `migrate()` lief nie, und der Bootstrap fiel in den Zweig
     * `!isAlreadyMigrated() → initFresh()`, der `meta.json` mit der neuen
     * Version überschrieb, ohne die Zähler anzufassen. Danach galt die
     * Installation als migriert, obwohl sie es nicht war.
     *
     * Neue Stufe = ein Eintrag hier, sonst nichts.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private const UPGRADE_STEPS = [
        ['needsWaterContractsUpgrade', 'upgradeWaterContracts'],
        ['needsV110Upgrade',           'upgradeToV110'],
        ['needsV120Upgrade',           'upgradeToV120'],
        ['needsV130Upgrade',           'upgradeToV130'],
        ['needsV140Upgrade',           'upgradeToV140'],
    ];

    /** Nur zum Prüfen von außen (Test hält die Liste vollständig). */
    public static function upgradeSteps(): array
    {
        return self::UPGRADE_STEPS;
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
        foreach (self::UPGRADE_STEPS as [$check, $_]) {
            if ($this->{$check}()) return true;
        }
        return false;
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

    /**
     * v1.0.3 → v1.1.0 — fehlen die Verzeichnisse der neuen Utilities oder
     * die zentrale `reminders.json`? Idempotent: wenn alles schon da ist,
     * gibt das false zurück und der Aufruf von `upgradeToV110()` ist ein No-Op.
     */
    public function needsV110Upgrade(): bool
    {
        foreach (['fernwaerme', 'heizoel', 'pellets'] as $u) {
            if (!$this->store->exists($u . '/meters.json')) return true;
            if (!$this->store->exists($u . '/contracts.json')) return true;
        }
        if (!$this->store->exists('heizoel/deliveries.json')) return true;
        if (!$this->store->exists('pellets/deliveries.json')) return true;
        if (!$this->store->exists('fernwaerme/readings.json')) return true;
        if (!$this->store->exists('reminders.json')) return true;
        return false;
    }

    /**
     * v1.1.0 → v1.2.0 — Meter-Topologie (F1006). Fehlt für eine Utility die
     * `meter_groups.json` oder trägt ein Zähler noch nicht die beiden neuen
     * Felder `parent_meter_id` / `meter_group_id`? Idempotent: ist alles da,
     * gibt das false zurück und `upgradeToV120()` ist ein No-Op.
     */
    public function needsV120Upgrade(): bool
    {
        foreach (Utilities::keys() as $key) {
            if (!$this->store->exists($key . '/meter_groups.json')) return true;
            $meters = $this->store->read($key . '/meters.json', []);
            if (!is_array($meters)) continue;
            foreach ($meters as $m) {
                if (!is_array($m)) continue;
                if (!array_key_exists('parent_meter_id', $m)) return true;
                if (!array_key_exists('meter_group_id', $m)) return true;
            }
        }
        return false;
    }

    /**
     * v1.2.0 → v1.3.0 — Zähler-Alias `external_id` (F1009 — HA-Ingest).
     * Trägt ein Zähler das Feld `external_id` noch nicht? Idempotent.
     */
    public function needsV130Upgrade(): bool
    {
        foreach (Utilities::keys() as $key) {
            $meters = $this->store->read($key . '/meters.json', []);
            if (!is_array($meters)) continue;
            foreach ($meters as $m) {
                if (!is_array($m)) continue;
                if (!array_key_exists('external_id', $m)) return true;
            }
        }
        return false;
    }

    /**
     * v1.3.0 → v1.4.0 — Baseline-Zäsuren `baseline_events` (F1011).
     * Trägt ein Zähler das Feld noch nicht? Idempotent.
     */
    public function needsV140Upgrade(): bool
    {
        foreach (Utilities::keys() as $key) {
            $meters = $this->store->read($key . '/meters.json', []);
            if (!is_array($meters)) continue;
            foreach ($meters as $m) {
                if (!is_array($m)) continue;
                if (!array_key_exists('baseline_events', $m)) return true;
            }
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

        // ── Stufen der Reihe nach, aus der gemeinsamen Liste ──────────────
        //    1.0.x → 1.0.3 Wasserverträge · → 1.1.0 neue Utilities +
        //    reminders.json · → 1.2.0 Meter-Topologie (F1006) · → 1.3.0
        //    Zähler-Alias (F1009) · → 1.4.0 Analyse-Zäsuren (F1011)
        foreach (self::UPGRADE_STEPS as [$check, $apply]) {
            if ($this->{$check}()) {
                $log = array_merge($log, $this->{$apply}());
            }
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

    /**
     * v1.0.3 → v1.1.0 — neue Verbrauchsarten und zentrale Termin-Datei.
     *
     * Legt für jede neue Utility (Fernwärme, Heizöl, Pellets) die
     * Standard-Dateistruktur an, sofern sie noch nicht existiert:
     *
     *   data/fernwaerme/meters.json     — leer
     *   data/fernwaerme/readings.json   — leer (cumulative)
     *   data/fernwaerme/contracts.json  — leer
     *   data/heizoel/meters.json        — leer
     *   data/heizoel/deliveries.json    — leer (delivery)
     *   data/heizoel/contracts.json     — leer
     *   data/pellets/...                — analog Heizöl
     *
     * Zusätzlich: data/reminders.json (zentrale Termin-Datei, leeres Array).
     *
     * Idempotent — bereits existierende Dateien werden nicht angetastet.
     *
     * Keine Default-Meter werden angelegt: die Auswahl „Tank vorhanden?"
     * trifft der User aktiv, sonst hätten alle Installationen einen leeren
     * Heizöltank-Eintrag, den sie nicht haben.
     */
    public function upgradeToV110(): array
    {
        $log = [];

        // Cumulative utility — Fernwärme
        $this->ensureFile('fernwaerme/meters.json',    []);
        $this->ensureFile('fernwaerme/readings.json',  []);
        $this->ensureFile('fernwaerme/contracts.json', []);

        // Delivery utilities — Heizöl, Pellets
        foreach (['heizoel', 'pellets'] as $u) {
            $this->ensureFile($u . '/meters.json',     []);
            $this->ensureFile($u . '/deliveries.json', []);
            $this->ensureFile($u . '/contracts.json',  []);
        }

        // Zentrale Termin-Datei
        $this->ensureFile('reminders.json', []);

        $log[] = 'v1.1.0: Verzeichnisstruktur für Fernwärme/Heizöl/Pellets angelegt';
        $log[] = 'v1.1.0: data/reminders.json initialisiert';
        return $log;
    }

    /**
     * v1.1.0 → v1.2.0 — Meter-Topologie (F1006).
     *
     * Rein additiv und idempotent:
     *   - legt je Utility eine leere `meter_groups.json` an (Gruppen-Stammdaten),
     *   - ergänzt an jedem Zähler die beiden neuen Felder `parent_meter_id`
     *     und `meter_group_id` mit Default `null`, falls sie fehlen.
     *
     * Bestehende Werte werden nicht angetastet; die Mitgliedschaft bleibt
     * single-source am Zähler (in `meter_groups.json` stehen nur die
     * Gruppen-Stammdaten, keine Mitgliederlisten).
     */
    public function upgradeToV120(): array
    {
        $log = [];
        $patchedMeters = 0;
        $createdGroupFiles = 0;

        foreach (Utilities::keys() as $key) {
            if (!$this->store->exists($key . '/meter_groups.json')) {
                $this->store->write($key . '/meter_groups.json', []);
                $createdGroupFiles++;
            }

            $meters = $this->store->read($key . '/meters.json', []);
            if (!is_array($meters)) continue;
            $changed = false;
            foreach ($meters as &$m) {
                if (!is_array($m)) continue;
                if (!array_key_exists('parent_meter_id', $m)) {
                    $m['parent_meter_id'] = null;
                    $changed = true;
                    $patchedMeters++;
                }
                if (!array_key_exists('meter_group_id', $m)) {
                    $m['meter_group_id'] = null;
                    $changed = true;
                }
            }
            unset($m);
            if ($changed) {
                $this->store->write($key . '/meters.json', $meters);
            }
        }

        $log[] = sprintf(
            'v1.2.0: Meter-Topologie initialisiert (%d meter_groups.json angelegt, %d Zähler um parent_meter_id/meter_group_id ergänzt)',
            $createdGroupFiles,
            $patchedMeters
        );
        return $log;
    }

    /**
     * v1.2.0 → v1.3.0 — Zähler-Alias `external_id` (F1009 — HA-Ingest).
     *
     * Rein additiv und idempotent: ergänzt an jedem Zähler das Feld
     * `external_id` mit Default `null`, falls es fehlt. Bestehende Werte
     * bleiben unangetastet.
     */
    public function upgradeToV130(): array
    {
        $log = [];
        $patched = 0;

        foreach (Utilities::keys() as $key) {
            $meters = $this->store->read($key . '/meters.json', []);
            if (!is_array($meters)) continue;
            $changed = false;
            foreach ($meters as &$m) {
                if (!is_array($m)) continue;
                if (!array_key_exists('external_id', $m)) {
                    $m['external_id'] = null;
                    $changed = true;
                    $patched++;
                }
            }
            unset($m);
            if ($changed) {
                $this->store->write($key . '/meters.json', $meters);
            }
        }

        $log[] = sprintf('v1.3.0: %d Zähler um external_id (HA-Alias) ergänzt', $patched);
        return $log;
    }

    /**
     * v1.3.0 → v1.4.0 — Baseline-Zäsuren `baseline_events` (F1011).
     *
     * Rein additiv und idempotent: ergänzt an jedem Zähler das Feld
     * `baseline_events` mit Default `[]`, falls es fehlt. Ein leeres Array
     * bedeutet „keine Zäsur" — alle Auswertungen rechnen dann exakt wie vor
     * v2.4.0. Bestehende Werte bleiben unangetastet.
     */
    public function upgradeToV140(): array
    {
        $log = [];
        $patched = 0;

        foreach (Utilities::keys() as $key) {
            $meters = $this->store->read($key . '/meters.json', []);
            if (!is_array($meters)) continue;
            $changed = false;
            foreach ($meters as &$m) {
                if (!is_array($m)) continue;
                if (!array_key_exists('baseline_events', $m)) {
                    $m['baseline_events'] = [];
                    $changed = true;
                    $patched++;
                }
            }
            unset($m);
            if ($changed) {
                $this->store->write($key . '/meters.json', $meters);
            }
        }

        $log[] = sprintf('v1.4.0: %d Zähler um baseline_events (Analyse-Zäsur) ergänzt', $patched);
        return $log;
    }

    /** Hilfsmethode: legt eine Datei nur an, wenn sie noch nicht existiert. */
    private function ensureFile(string $relative, mixed $default): void
    {
        if (!$this->store->exists($relative)) {
            $this->store->write($relative, $default);
        }
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
            $u = Utilities::get($key);
            $isDelivery = ($u['reading_kind'] ?? 'cumulative') === 'delivery';
            // v1.7.0 — F1005: PV-Utilities (Einspeisung/Erzeugung) bekommen
            // wie Delivery-Utilities KEINEN Default-Meter. PV ist optional;
            // wer keine Anlage hat, soll keine „Phantom-Zähler" angeboten
            // bekommen.
            $isOptional = $isDelivery || Utilities::accountingKind($key) !== 'consumption';

            if (!$this->store->exists($key . '/meters.json')) {
                if ($isOptional) {
                    $this->store->write($key . '/meters.json', []);
                } else {
                    $meterId = 'm_' . $key . '_default';
                    $this->store->write($key . '/meters.json', [[
                        'id' => $meterId,
                        'name' => $this->i18n?->defaultMeterName($key) ?? $u['default_meter_name'],
                        'icon' => $u['icon'],
                        'created_at' => date('Y-m-d'),
                        'active' => true,
                        'notes' => '',
                        // v1.2.0 — F1006 Meter-Topologie (Default: keine Beziehung)
                        'parent_meter_id' => null,
                        'meter_group_id'  => null,
                        // v1.3.0 — F1009 HA-Alias (Default: keiner)
                        'external_id'     => null,
                        // v1.4.0 — F1011 Analyse-Zäsur (Default: keine)
                        'baseline_events' => [],
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
            }

            // Cumulative → readings.json. Delivery → deliveries.json.
            if ($isDelivery) {
                if (!$this->store->exists($key . '/deliveries.json')) {
                    $this->store->write($key . '/deliveries.json', []);
                }
            } else {
                if (!$this->store->exists($key . '/readings.json')) {
                    $this->store->write($key . '/readings.json', []);
                }
            }
            // pv_erzeugung hat keine Verträge — contracts.json wird trotzdem
            // angelegt (leer), damit Lese-Code nicht null-prüfen muss.
            if (!$this->store->exists($key . '/contracts.json')) {
                $this->store->write($key . '/contracts.json', []);
            }
            // v1.2.0 — F1006: Gruppen-Stammdaten je Utility (leer).
            if (!$this->store->exists($key . '/meter_groups.json')) {
                $this->store->write($key . '/meter_groups.json', []);
            }
        }
        if (!$this->store->exists('temperatures.json')) {
            $this->store->write('temperatures.json', []);
        }
        if (!$this->store->exists('settings.json')) {
            $this->store->write('settings.json', []);
        }
        if (!$this->store->exists('reminders.json')) {
            $this->store->write('reminders.json', []);
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
