<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Config\Utilities;
use Energietracker\Support\Dates;
use Energietracker\Http\NotFoundException;

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
    public function __construct(private JsonStore $store, private I18nService $i18n) {}

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
            // v2.2.0 — Default-Zählername in der eingestellten Sprache; eine
            // englische Frischinstallation begrüßte den Nutzer sonst mit
            // „Hauptzähler". Der Name wird einmal geschrieben und gehört ab
            // dann dem Nutzer.
            'name'       => $this->i18n->defaultMeterName($utility),
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
            throw new \InvalidArgumentException($this->i18n->t('errors.meter.multipleMeters', ['utility' => $utility]));
        }
        $isDelivery = Utilities::isDelivery($utility);

        // v2.6.0 — docs/API.md beschrieb bis v2.5.3 ein Objekt `device`
        // {serial, installed_on, initial_counter}, das der Code nie las: Wer
        // danach integrierte, bekam still Seriennummer null, Einbau heute und
        // Anfangsstand 0 (Review API-18). Jetzt als Alias der Einzelfelder.
        if (isset($input['device']) && is_array($input['device'])) {
            $input += array_filter([
                'device_serial'   => $input['device']['serial'] ?? null,
                'installed_on'    => $input['device']['installed_on'] ?? null,
                'initial_counter' => $input['device']['initial_counter'] ?? null,
                'digits'          => $input['device']['digits'] ?? null,
            ], fn($v) => $v !== null);
        }

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
            ] + $this->digitsField($d['digits'] ?? null), $input['devices']);
        } else {
            $installedOn = (string)($input['installed_on'] ?? '');
            if ($installedOn === '') $installedOn = date('Y-m-d');
            $this->assertDate($installedOn);
            $devices = [[
                'id'              => 'd_' . bin2hex(random_bytes(4)),
                'serial'          => $input['device_serial'] ?? null,
                'installed_on'    => $installedOn,
                // Leer heißt hier 0: Der Anfangsstand zählt nur für die
                // Tausch-Überbrückung; den Verbrauch rechnet die erste Ablesung.
                'initial_counter' => $this->parseCounter(($input['initial_counter'] ?? '') === '' ? 0.0 : $input['initial_counter']),
                'removed_on'      => null,
                'final_counter'   => null,
                'reason'          => null,
            ] + $this->digitsField($input['digits'] ?? null)];
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
            // v1.3.0 — F1009 HA-Alias (Default: keiner)
            'external_id'     => null,
            // v1.4.0 — F1011 Analyse-Zäsur (Default: keine; leeres Array
            // bedeutet „rechne über die volle Historie", also wie vor v2.4.0)
            'baseline_events' => [],
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
        // v1.3.0 — F1009: external_id (HA-Alias) optional schon beim Anlegen.
        if (array_key_exists('external_id', $input)) {
            $meter['external_id'] = $this->normalizeExternalId($input['external_id'], $utility, $meter['id']);
        }
        // v1.4.0 — F1011: Zäsuren optional schon beim Anlegen.
        if (array_key_exists('baseline_events', $input)) {
            $meter['baseline_events'] = $this->normalizeBaselineEvents($input['baseline_events']);
        }
        $this->assertTopologyValid($utility, $meter, $candidatePool);

        // v1.3.0 — Tank-/Lager-Felder bei Delivery-Utilities (Heizöl/Pellets).
        // Pflicht: capacity > 0 und initial_stock ≥ 0 — ohne diese werden
        // Bestand und Tank-Warnung sinnlos. capacity_unit wird per Default
        // aus der Utility-Definition übernommen.
        if ($isDelivery) {
            if (!isset($input['capacity']) || (float)$input['capacity'] <= 0) {
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.meter.capacityRequired', ['label' => $u['label']])
                );
            }
            if (!isset($input['initial_stock']) || (float)$input['initial_stock'] < 0) {
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.meter.initialStockRequired', ['label' => $u['label']])
                );
            }
            $meter['capacity']      = (float)$input['capacity'];
            $meter['capacity_unit'] = (string)($input['capacity_unit'] ?? $u['volume_unit'] ?? '');
            $meter['initial_stock'] = (float)$input['initial_stock'];
        }

        if ($meter['name'] === '') {
            throw new \InvalidArgumentException($this->i18n->t('errors.meter.nameEmpty'));
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
            // v1.3.0 — F1009: external_id (HA-Alias) änderbar; Eindeutigkeit
            // pro Utility wird in normalizeExternalId geprüft (eigene id wird
            // dabei ausgenommen).
            if (array_key_exists('external_id', $input)) {
                $m['external_id'] = $this->normalizeExternalId($input['external_id'], $utility, $meterId);
            }
            // v1.4.0 — F1011: Zäsuren änderbar (anlegen, bearbeiten, löschen —
            // die Liste wird immer als Ganzes geschrieben).
            if (array_key_exists('baseline_events', $input)) {
                $m['baseline_events'] = $this->normalizeBaselineEvents($input['baseline_events']);
            }
            // v2.6.0 — Stellenzahl des Zählwerks am eingebauten Gerät (für
            // die Überlauf-Erkennung der Verbrauchsrechnung). Leer entfernt sie.
            if (array_key_exists('digits', $input) && !$isDelivery) {
                foreach ($m['devices'] as &$dev) {
                    if (!empty($dev['removed_on'])) continue;
                    unset($dev['digits']);
                    $dev += $this->digitsField($input['digits']);
                }
                unset($dev);
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
        if (!$found) throw new NotFoundException($this->i18n->t('errors.meter.notFound'));
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
                throw new \InvalidArgumentException($this->i18n->t('errors.meter.hasReadings'));
            }
        }
        // v1.3.0 — auch Lieferungen blocken die Tank-Löschung
        $deliveries = $this->store->read("$utility/deliveries.json", []);
        foreach ($deliveries as $d) {
            if (($d['meter_id'] ?? null) === $meterId) {
                throw new \InvalidArgumentException($this->i18n->t('errors.meter.hasDeliveries'));
            }
        }
        $contracts = $this->store->read("$utility/contracts.json", []);
        foreach ($contracts as $c) {
            if (($c['meter_id'] ?? null) === $meterId) {
                throw new \InvalidArgumentException($this->i18n->t('errors.meter.hasContracts'));
            }
        }
        // v1.2.0 — F1006: ein Elternzähler mit Subzählern kann nicht gelöscht
        // werden, ohne die Kinder zu verwaisen. Erst Subzähler auflösen.
        foreach ($this->list($utility) as $m) {
            if (($m['parent_meter_id'] ?? null) === $meterId) {
                throw new \InvalidArgumentException($this->i18n->t('errors.meter.isParent'));
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
        // v2.6.0 — die in docs/API.md bis v2.5.3 beschriebenen Namen
        // (removed_on, final_counter, new_device{…}) als Aliase; bisher
        // endeten sie in „old_final_counter fehlt" (Review API-18).
        $new = is_array($input['new_device'] ?? null) ? $input['new_device'] : [];
        $input += array_filter([
            'date'                => $input['removed_on'] ?? ($new['installed_on'] ?? null),
            'old_final_counter'   => $input['final_counter'] ?? null,
            'new_initial_counter' => $new['initial_counter'] ?? null,
            'serial'              => $new['serial'] ?? null,
            'digits'              => $new['digits'] ?? null,
        ], fn($v) => $v !== null);

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
                throw new \InvalidArgumentException($this->i18n->t('errors.meter.noOpenDevice'));
            }
            $date = (string)($input['date'] ?? '');
            if ($date === '') $date = date('Y-m-d');
            // v2.5.3 — kalendergültig und nicht vor dem Einbau des offenen
            // Geräts. Ein beliebiger String landete bisher als removed_on/
            // installed_on in der Gerätekette und von dort ungefiltert im DOM.
            $this->assertDate($date);
            $openSince = (string)($devices[$openIdx]['installed_on'] ?? '');
            if ($openSince !== '' && $date < $openSince) {
                // Beim ERSTEN Gerät ist das Einbaudatum oft nur der Tag, an dem
                // der Zähler im Energietracker angelegt wurde (Standardzähler
                // beim Erststart). Dann das Einbaudatum vorverlegen. Ein
                // späteres Gerät beginnt an einem echten Tauschtag — ein Tausch
                // davor ist ein Eingabefehler.
                if ($this->isFirstDevice($devices, $openIdx)) {
                    $devices[$openIdx]['installed_on'] = $date;
                } else {
                    throw new \InvalidArgumentException(
                        $this->i18n->t('errors.meter.swapBeforeInstall', ['date' => $date, 'installed' => $openSince])
                    );
                }
            }

            // v1.6.1 — Issue #13: alter Endstand muss explizit
            // angegeben werden. Vorher wurde stillschweigend 0 gesetzt,
            // wenn das Feld fehlte — das führte zu unrealistischen
            // Bridging-Ausschlägen im Monat des Tauschs.
            if (!isset($input['old_final_counter']) || $input['old_final_counter'] === '') {
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.meter.oldFinalRequired')
                );
            }
            $oldFinal = $this->parseCounter($input['old_final_counter']);
            $newInit  = isset($input['new_initial_counter']) && $input['new_initial_counter'] !== ''
                        ? $this->parseCounter($input['new_initial_counter'])
                        : 0.0;

            $devices[$openIdx]['removed_on']    = $date;
            $devices[$openIdx]['final_counter'] = $oldFinal;
            $devices[$openIdx]['reason']        = $input['reason'] ?? null;

            // v2.6.0 — Stellenzahl: angegeben, sonst die des alten Geräts
            $newDigits = array_key_exists('digits', $input) ? $input['digits'] : ($devices[$openIdx]['digits'] ?? null);
            $devices[] = [
                'id'              => 'd_' . bin2hex(random_bytes(4)),
                'serial'          => $input['serial'] ?? null,
                'installed_on'    => $date,
                'initial_counter' => $newInit,
                'removed_on'      => null,
                'final_counter'   => null,
                'reason'          => null,
            ] + $this->digitsField($newDigits);
            $m['devices'] = $devices;
        }
        unset($m);
        if (!$found) throw new NotFoundException($this->i18n->t('errors.meter.notFound'));
        $this->store->write("$utility/meters.json", $all);
        return $this->get($utility, $meterId);
    }

    /**
     * Resolve which device was active on a given date for a given meter.
     * Returns null if no device covers the date.
     */
    /**
     * v2.5.3 — Verlegt den Einbau des ERSTEN Geräts eines Zählers auf `$date`
     * vor, wenn `$date` davor liegt.
     *
     * Beim Erststart legt der Energietracker Standardzähler an, deren Gerät
     * „heute" eingebaut ist. Jede ältere Ablesung — auch aus dem CSV-Import —
     * scheiterte deshalb mit „kein Gerät aktiv": Wer mit seiner Historie
     * einstieg, kam nicht hinein. Das erste Gerät hat keinen Vorgänger, sein
     * Einbaudatum ist bestenfalls „spätestens seit"; eine ältere Ablesung
     * beweist, dass es früher schon da war. Spätere Geräte beginnen an einem
     * echten Tauschtag und bleiben unberührt.
     *
     * @return array|null das angepasste erste Gerät, oder null, wenn nichts zu tun war
     */
    public function backdateFirstDevice(string $utility, string $meterId, string $date): ?array
    {
        if (!Dates::isIsoDate($date)) return null;
        $all = $this->list($utility);
        foreach ($all as &$m) {
            if (($m['id'] ?? null) !== $meterId) continue;
            $devices = $m['devices'] ?? [];
            if ($devices === []) return null;
            $firstIdx = null;
            foreach ($devices as $i => $d) {
                if ($this->isFirstDevice($devices, $i)) { $firstIdx = $i; break; }
            }
            if ($firstIdx === null) return null;
            $since = (string)($devices[$firstIdx]['installed_on'] ?? '');
            if ($since !== '' && $date >= $since) return null;
            $devices[$firstIdx]['installed_on'] = $date;
            $m['devices'] = $devices;
            unset($m);
            $this->store->write("$utility/meters.json", $all);
            return $devices[$firstIdx];
        }
        unset($m);
        return null;
    }

    /** Hat das Gerät keinen Vorgänger in der Kette (frühester Einbau)? */
    private function isFirstDevice(array $devices, int $idx): bool
    {
        $since = (string)($devices[$idx]['installed_on'] ?? '');
        foreach ($devices as $i => $d) {
            if ($i === $idx) continue;
            if ((string)($d['installed_on'] ?? '') < $since) return false;
            // gleicher Einbautag: das frühere im Array gilt als erstes
            if ((string)($d['installed_on'] ?? '') === $since && $i < $idx) return false;
        }
        return true;
    }

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
            throw new \InvalidArgumentException($this->i18n->t('errors.meter.unknownUtility', ['utility' => $utility]));
        }
    }

    // ── v1.2.0 — F1006 Meter-Topologie ────────────────────────────────────

    /** Leerstring/leere Werte zu null normalisieren, sonst als string. */
    private function normalizeRef(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) return null;
        return (string)$value;
    }

    // ── v1.3.0 — F1009 HA-Alias (external_id) ─────────────────────────────

    /**
     * Findet einen Zähler einer Utility über seine `external_id` (HA-Alias).
     * Leerer/null Alias matcht nie.
     */
    public function getByExternalId(string $utility, string $externalId): ?array
    {
        if ($externalId === '') return null;
        foreach ($this->list($utility) as $m) {
            if (($m['external_id'] ?? null) === $externalId) return $m;
        }
        return null;
    }

    /**
     * Normalisiert und validiert eine `external_id`:
     *  - leer/null → null (Alias entfernt),
     *  - erlaubt sind Buchstaben/Ziffern/`_`/`-`/`.` (HA-/URL-freundlich),
     *  - muss innerhalb der Utility eindeutig sein (eigene id ausgenommen).
     */
    private function normalizeExternalId(mixed $value, string $utility, string $selfId): ?string
    {
        if ($value === null || $value === '' || $value === false) return null;
        $alias = trim((string)$value);
        if ($alias === '') return null;
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $alias)) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.meter.invalidAlias', ['alias' => $alias])
            );
        }
        foreach ($this->list($utility) as $m) {
            if (($m['id'] ?? null) === $selfId) continue;
            if (($m['external_id'] ?? null) === $alias) {
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.meter.aliasTaken', ['alias' => $alias, 'utility' => $utility])
                );
            }
        }
        return $alias;
    }

    // ── v1.4.0 — F1011 Analyse-Zäsur (baseline_events) ────────────────────

    /** Höchstlänge der Bezeichnung einer Zäsur. */
    private const BASELINE_LABEL_MAX = 80;

    /**
     * Normalisiert und validiert die Zäsur-Liste eines Zählers:
     *  - null/leer → `[]` (keine Zäsur, Verhalten wie vor v2.4.0),
     *  - `date` muss ein gültiges ISO-Datum sein (`YYYY-MM-DD`),
     *  - je Zähler darf ein Datum nur einmal vorkommen,
     *  - `label` ist optional, wird getrimmt und gekappt,
     *  - die Liste wird aufsteigend nach Datum sortiert gespeichert.
     *
     * Künftig datierte Ereignisse sind ausdrücklich erlaubt: Wer den
     * Heizungstausch plant, darf ihn vormerken — {@see activeBaselineEvent()}
     * lässt ihn erst wirken, wenn sein Datum erreicht ist.
     */
    private function normalizeBaselineEvents(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) return [];
        if (!is_array($value)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.meter.invalidBaselineList'));
        }

        $out  = [];
        $seen = [];
        foreach ($value as $raw) {
            if (!is_array($raw)) {
                throw new \InvalidArgumentException($this->i18n->t('errors.meter.invalidBaselineList'));
            }
            $date = trim((string)($raw['date'] ?? ''));
            if (!self::isIsoDate($date)) {
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.meter.invalidBaselineDate', ['date' => $date])
                );
            }
            if (isset($seen[$date])) {
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.meter.duplicateBaselineDate', ['date' => $date])
                );
            }
            $seen[$date] = true;

            $label = trim((string)($raw['label'] ?? ''));
            if (mb_strlen($label) > self::BASELINE_LABEL_MAX) {
                $label = mb_substr($label, 0, self::BASELINE_LABEL_MAX);
            }
            $out[] = ['date' => $date, 'label' => $label];
        }

        usort($out, fn(array $a, array $b) => strcmp($a['date'], $b['date']));
        return $out;
    }

    /** Echtes Kalenderdatum im Format YYYY-MM-DD? (seit v2.5.3 eine Stelle: Support\Dates) */
    private static function isIsoDate(string $d): bool
    {
        return Dates::isIsoDate($d);
    }

    private function assertDate(string $date): void
    {
        if (!Dates::isIsoDate($date)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.dateInvalid', ['date' => $date]));
        }
    }

    /**
     * v2.6.0 — optionales Gerätefeld `digits` (Stellen des Zählwerks vor dem
     * Komma, 3–12). Damit erkennt die Verbrauchsrechnung einen Überlauf
     * (99.998 → 12) statt einen fallenden Stand. Leer → Feld entfällt.
     *
     * @return array{digits?: int}
     */
    private function digitsField(mixed $raw): array
    {
        if ($raw === null || $raw === '') return [];
        if (is_bool($raw) || !is_numeric($raw) || (int)$raw != $raw || (int)$raw < 3 || (int)$raw > 12) {
            throw new \InvalidArgumentException($this->i18n->t('errors.meter.digitsInvalid', ['value' => is_scalar($raw) ? (string)$raw : gettype($raw)]));
        }
        return ['digits' => (int)$raw];
    }

    /** Zählerstand als Zahl ≥ 0 (gleiche Regel wie ReadingService). */
    private function parseCounter(mixed $raw): float
    {
        if (is_bool($raw) || !is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0) {
            $shown = is_scalar($raw) ? (string)$raw : gettype($raw);
            throw new \InvalidArgumentException($this->i18n->t('errors.reading.counterInvalid', ['value' => $shown]));
        }
        return (float)$raw;
    }

    /**
     * v2.9.0 (Review CALC-15) — Was „inaktiv" heißt, steht hier und nur hier.
     *
     * Ein inaktiver Zähler ist **außer Betrieb**: Seine Historie bleibt wahr
     * und zählt in jeder Summe (Verbrauchsart, Dashboard, CSV, PDF, Effizienz),
     * aber er bekommt keine neuen Ablesungen, erscheint nicht in der Erfassung
     * und löst keine Warnungen oder Empfehlungen aus. Bis v2.8 zählten Utility-
     * Sicht und CSV ihn mit, PDF und Effizienz nicht — derselbe Wasserzähler
     * ergab 138,8 m³ in der einen und 126,7 m³ in der anderen Sicht.
     *
     * `countsInTotals()`: gehört ein Zähler in Summen? Subzähler nie — sie sind
     * im Elternzähler schon enthalten (F1006). `inService()`: ist er in Betrieb?
     */
    public static function countsInTotals(array $meter): bool
    {
        return ($meter['parent_meter_id'] ?? null) === null;
    }

    /** v2.9.0 — in Betrieb (Erfassung, Warnungen, Empfehlungen); s. countsInTotals() */
    public static function inService(array $meter): bool
    {
        return ($meter['active'] ?? true) !== false;
    }

    /**
     * Die **wirksame** Zäsur eines Zählers: das späteste Ereignis, dessen
     * Datum nicht in der Zukunft liegt. Künftig datierte Ereignisse sind
     * gespeichert, wirken aber noch nicht.
     *
     * Bewusst `static` und ohne Speicherzugriff — reine Logik auf dem
     * übergebenen Zähler-Array. So können ConsumptionService, ForecastService
     * und die Controller sie nutzen, ohne eine Abhängigkeit auf den
     * MeterService aufzubauen.
     *
     * @return array{date:string,label:string}|null
     */
    public static function activeBaselineEvent(array $meter, ?string $today = null): ?array
    {
        $today  = $today ?? date('Y-m-d');
        $events = $meter['baseline_events'] ?? [];
        if (!is_array($events)) return null;

        $best = null;
        foreach ($events as $e) {
            if (!is_array($e)) continue;
            $d = (string)($e['date'] ?? '');
            if ($d === '' || $d > $today) continue;
            if ($best === null || $d > (string)$best['date']) {
                $best = ['date' => $d, 'label' => (string)($e['label'] ?? '')];
            }
        }
        return $best;
    }

    /** Datum der wirksamen Zäsur, oder `null` wenn keine greift. */
    public static function activeBaselineDate(array $meter, ?string $today = null): ?string
    {
        $e = self::activeBaselineEvent($meter, $today);
        return $e === null ? null : $e['date'];
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
                throw new \InvalidArgumentException($this->i18n->t('errors.meter.selfParent'));
            }
            $parentMeter = null;
            foreach ($pool as $m) {
                if (($m['id'] ?? null) === $parent) { $parentMeter = $m; break; }
            }
            if ($parentMeter === null) {
                throw new \InvalidArgumentException($this->i18n->t('errors.meter.parentNotFound', ['parent' => $parent]));
            }
            // Keine mehrstufige Kette: der Elternzähler darf selbst kein Subzähler sein.
            if (($parentMeter['parent_meter_id'] ?? null) !== null) {
                throw new \InvalidArgumentException(
                    $this->i18n->t('errors.meter.chainParentIsSub')
                );
            }
            // ... und dieser Zähler darf selbst kein Elternzähler sein.
            foreach ($pool as $m) {
                if (($m['parent_meter_id'] ?? null) === $id) {
                    throw new \InvalidArgumentException(
                        $this->i18n->t('errors.meter.chainSelfIsParent')
                    );
                }
            }
        }

        if ($group !== null) {
            if ($this->getGroup($utility, $group) === null) {
                throw new \InvalidArgumentException($this->i18n->t('errors.meter.groupNotFoundId', ['group' => $group]));
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
            throw new \InvalidArgumentException($this->i18n->t('errors.meter.groupNameEmpty'));
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
                    throw new \InvalidArgumentException($this->i18n->t('errors.meter.groupNameEmpty'));
                }
                $g['name'] = $name;
            }
        }
        unset($g);
        if (!$found) throw new NotFoundException($this->i18n->t('errors.meter.groupNotFound'));
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
            throw new NotFoundException($this->i18n->t('errors.meter.groupNotFound'));
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
            throw new \InvalidArgumentException($this->i18n->t('errors.meter.mergeNeedTwo'));
        }

        // Zielgruppe bestimmen oder anlegen.
        if (!empty($input['group_id'])) {
            $group = $this->getGroup($utility, (string)$input['group_id']);
            if ($group === null) {
                throw new \InvalidArgumentException($this->i18n->t('errors.meter.groupNotFoundId', ['group' => $input['group_id']]));
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
                throw new \InvalidArgumentException($this->i18n->t('errors.meter.notFoundId', ['id' => $mid]));
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
