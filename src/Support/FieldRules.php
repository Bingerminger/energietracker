<?php
declare(strict_types=1);

namespace Energietracker\Support;

use Energietracker\Services\ContractService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ReadingService;

/**
 * v3.13.0 — Eine Prüfung je Regel für die gespeicherten Felder der Töpfe, Vorbild Support\TextLimits.
 *
 * Bis v3.12 prüfte jeder Eingang selbst: Die Schreibwege wiesen einen Wert ab, den der Backup-Import einspielte
 * (`is_shadow: "false"` als Text nahm den laufenden Vertrag aus dem Saldo, `initial_stock: 1e300` legte Übersicht
 * und Jahresbericht lahm), und was eine ältere Version gespeichert hatte (Stand im Jahr 0150, Vertragsende als
 * Liste, Zeitraum ab 0003), brach nach dem Update die Lesewege mit 500 ab. Jetzt gilt je Feld EINE Regel, aus
 * EINER Funktion je Art:
 *   - Wahrheitswert: Input::bool (ja/nein, true/false, 1/0, on/off; leer = nein),
 *   - Tag: Dates::isIsoDate, im Eingabebereich Dates::inInputRange (1900 bis 2100),
 *   - Zählerstand: counter() (Zahl ohne Komma, 0 bis ReadingService::MAX_COUNTER),
 *   - Zahl mit Grenzen: number() (Input::decimal, Komma als Dezimaltrenner, Grenzen aus der Tabelle),
 *   - Text, Liste, Objekt: die Form (wie Input::text, Input::objectExpected).
 *
 * Wer sie benutzt:
 *   - die Schreibwege über die Funktionen der Arten (counter(), number(), Input::flag …) bzw. assert(),
 *   - der Backup-Import über problems() (Problemart `art:feld`, KINDS) und normalize() (Wahrheitswerte als
 *     true/false, Zahlen mit Komma als Zahl),
 *   - der Bestand beim Start über usable() (DataIssues): Ein Datensatz, dessen Pflichtfeld die Regel bricht,
 *     fällt aus der Rechnung; ein anderes Feld gilt als nicht eingetragen (null). Die Datei bleibt, wie
 *     sie ist; /api/health und die Diagnose nennen den Fund.
 *
 * Leer (null oder '') heißt bei jedem Feld „nicht eingetragen“; ob ein Feld eingetragen sein muss, sagt REQUIRED.
 */
final class FieldRules
{
    /** Größter Tank bzw. größtes Lager in Litern oder Kilogramm (wie die Menge einer Lieferung). */
    public const TANK_MAX = DeliveryService::LIMITS['quantity'];
    /** Höchster Preis je Einheit in ct (wie an der Ablesung und an der Lieferung). */
    public const PRICE_MAX = ReadingService::MAX_PRICE_CENTS;

    /**
     * Topf (Schlüssel wie im Backup) → Feld → Regel. „liste.feld“: das Feld in jedem Eintrag einer Liste am
     * Datensatz. Regel: 'bool' | 'date' | 'counter' | 'text' | 'list' | 'object' | ['number', min, max] |
     * ['int', min, max] (ganze Zahl).
     */
    public const FIELDS = [
        'readings'     => [
            'meter_id' => 'text', 'date' => 'date', 'counter' => 'counter', 'device_id' => 'text',
            'price_cents' => ['number', 0, self::PRICE_MAX], 'note' => 'text', 'reported_on' => 'date',
            'client_ref' => 'text', 'attachment_id' => 'text', 'source' => 'text',
            'is_estimated' => 'bool', 'is_suspect' => 'bool', 'is_billing_only' => 'bool', 'is_verified' => 'bool', 'is_future' => 'bool',
        ],
        'meters'       => [
            'name' => 'text', 'notes' => 'text', 'icon' => 'text', 'active' => 'bool', 'heat_source' => 'bool', 'plug_in' => 'bool',
            'capacity' => ['number', 0, self::TANK_MAX], 'initial_stock' => ['number', 0, self::TANK_MAX],
            'initial_stock_price_ct' => ['number', 0, self::PRICE_MAX],
            'devices' => 'list', 'devices.installed_on' => 'date', 'devices.removed_on' => 'date',
            'devices.initial_counter' => 'counter', 'devices.final_counter' => 'counter', 'devices.digits' => ['int', 3, 12],
            'baseline_events' => 'list', 'baseline_events.date' => 'date', 'baseline_events.label' => 'text',
            'tank_levels' => 'list', 'tank_levels.date' => 'date', 'tank_levels.level' => ['number', 0, self::TANK_MAX], 'tank_levels.note' => 'text',
            'investment_eur' => ['number', 0, 10000000], 'battery_capacity_kwh' => ['number', 0, 10000],
        ],
        'meter_groups' => ['name' => 'text'],
        'contracts'    => [
            'meter_id' => 'text', 'meter_group_id' => 'text', 'provider' => 'text', 'tariff_name' => 'text', 'notes' => 'text', 'shadow_label' => 'text',
            'start' => 'date', 'end' => 'date', 'min_term_end' => 'date', 'price_guarantee_until' => 'date',
            'is_shadow' => 'bool', 'auto_renews' => 'bool',
            'notice_period_months' => ['int', 0, 24], 'notice_period_days' => ['int', 0, 730],
            'signup_bonus_eur' => ['number', 0, ContractService::AMOUNT_MAX],
            'working_prices' => 'list', 'working_prices.from' => 'date', 'working_prices.ct_per_kwh' => ['number', 0, ContractService::AMOUNT_MAX],
            'base_prices' => 'list', 'base_prices.from' => 'date', 'base_prices.eur_per_month' => ['number', 0, ContractService::AMOUNT_MAX],
            'advance_payments' => 'list', 'advance_payments.from' => 'date', 'advance_payments.amount_eur' => ['number', 0, ContractService::AMOUNT_MAX],
            'bonuses' => 'list', 'bonuses.date' => 'date', 'bonuses.amount_eur' => ['number', -ContractService::AMOUNT_MAX, ContractService::AMOUNT_MAX],
            'special_payments' => 'list', 'special_payments.date' => 'date',
            'special_payments.amount_eur' => ['number', -ContractService::AMOUNT_MAX, ContractService::AMOUNT_MAX],
            'trinkwasser' => 'object', 'schmutzwasser' => 'object', 'niederschlagswasser' => 'object', 'grid_tou' => 'object', 'grid_reduction' => 'list',
        ],
        'deliveries'   => [
            'meter_id' => 'text', 'date' => 'date', 'supplier' => 'text', 'note' => 'text',
            'quantity' => ['number', 0, DeliveryService::LIMITS['quantity']],
            'unit_price_cents' => ['number', 0, DeliveryService::LIMITS['unit_price_cents']],
            'total_eur' => ['number', 0, DeliveryService::LIMITS['total_eur']],
            'is_planned' => 'bool', 'fill_to_full' => 'bool',
        ],
        'periods'      => [
            'meter_id' => 'text', 'from' => 'date', 'to' => 'date', 'value' => ['number', 0, ReadingService::MAX_COUNTER],
            'is_estimated' => 'bool', 'note' => 'text', 'reference' => 'object',
        ],
        'bills'        => [
            'meter_id' => 'text', 'contract_id' => 'text', 'period_from' => 'date', 'period_to' => 'date', 'issued_on' => 'date',
            'received_on' => 'date', 'end_reading_on' => 'date', 'note' => 'text', 'invoice' => 'object', 'co2' => 'object',
            'items' => 'list', 'items.label' => 'text', 'items.amount_eur' => ['number', -1000000, 1000000],
            'factors' => 'list', 'factors.from' => 'date', 'factors.to' => 'date',
        ],
        'reminders'    => [
            'title' => 'text', 'notes' => 'text', 'next_due' => 'date', 'last_done' => 'date', 'active' => 'bool',
            'recurrence_months' => ['int', 0, 1200],
        ],
        'tenancies'    => [
            'start' => 'date', 'end' => 'date', 'label' => 'text', 'landlord' => 'text', 'notes' => 'text', 'co2_own_appliances' => 'bool',
            'prepayments' => 'list', 'prepayments.from' => 'date', 'prices' => 'list', 'prices.from' => 'date',
            'fixed_costs' => 'list', 'fixed_costs.from' => 'date', 'fixed_costs.label' => 'text', 'fixed_costs.eur_per_year' => ['number', 0, 1000000],
            'meter_ids' => 'object',
        ],
    ];

    /**
     * Pflichtfelder: Bricht eines die Regel (oder fehlt es), ist der ganze Datensatz unbrauchbar und fällt im Bestand
     * aus der Rechnung. Ohne Tag keine Ablesung, ohne Beginn kein Vertrag.
     */
    public const REQUIRED = [
        'readings'     => ['meter_id', 'date', 'counter'],
        'meters'       => ['devices'],
        'contracts'    => ['start'],
        'deliveries'   => ['date', 'quantity'],
        'periods'      => ['meter_id', 'from', 'to', 'value'],
        'bills'        => ['period_from', 'period_to'],   // ohne Zähler zählt sie noch für die CO₂-Kosten der Verbrauchsart
        'tenancies'    => ['start'],
    ];

    /** Problemarten, die problems() melden kann (BackupProblemCatalogTest: Text in 7 Sprachen). */
    public const KINDS = ['bool', 'date', 'date_range', 'counter', 'number', 'text', 'list', 'object'];

    // ── die Regeln je Art ────────────────────────────────────────────────

    /**
     * Zählerstand: endliche Zahl von 0 bis ReadingService::MAX_COUNTER. Ein Komma gilt nicht als Dezimaltrenner
     * (mehrdeutig, Tausenderkomma; seit v2.5.3 dokumentiert), ein Text mit Punkt schon. v3.13.0 — bewusst so gelassen,
     * an jedem Weg (Ablesung, Anfangsstand, Zählertausch, Ingest, Backup): Lieber ein lautes 400 als ein Stand, der still
     * um den Faktor 1000 danebenliegt („3,205“ für 3205). Preise und Beträge lesen das Komma (number()).
     */
    public static function counter(mixed $v): ?float
    {
        if (is_bool($v) || !is_numeric($v)) return null;
        $f = (float)$v;
        return is_finite($f) && $f >= 0 && $f <= ReadingService::MAX_COUNTER ? $f : null;
    }

    /** Zahl mit Grenzen: Input::decimal (Komma als Dezimaltrenner), dann min ≤ x ≤ max. */
    public static function number(mixed $v, float $min, float $max): ?float
    {
        $f = Input::decimal($v);
        return $f !== null && $f >= $min && $f <= $max ? $f : null;
    }

    /**
     * Art des Verstoßes eines Werts gegen eine Regel, sonst null. Leer (null, '') ist nie ein Verstoß.
     *
     * @param string|array{0:string,1:float|int,2:float|int} $rule
     */
    public static function problem(string|array $rule, mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        [$kind, $min, $max] = is_array($rule) ? [$rule[0], (float)$rule[1], (float)$rule[2]] : [$rule, 0.0, 0.0];
        return match ($kind) {
            'bool'    => Input::bool($v) === null ? 'bool' : null,
            'date'    => !is_string($v) || !Dates::isIsoDate($v) ? 'date' : (Dates::inInputRange($v) ? null : 'date_range'),
            'counter' => self::counter($v) === null ? 'counter' : null,
            'number'  => self::number($v, $min, $max) === null ? 'number' : null,
            'int'     => (($n = self::number($v, $min, $max)) === null || floor($n) != $n) ? 'number' : null,
            'text'    => is_string($v) || is_int($v) || (is_float($v) && is_finite($v)) ? null : 'text',
            'list'    => is_array($v) && array_is_list($v) ? null : 'list',
            'object'  => is_array($v) && ($v === [] || !array_is_list($v)) ? null : 'object',
            default   => throw new \LogicException("field rule: $kind"),
        };
    }

    /**
     * Verstöße eines Datensatzes als `art:feld` (Feld einer Liste als `liste.feld`), dazu fehlende Pflichtfelder als
     * `missing:feld`. Je Feld einer Liste ein Fund, auch wenn mehrere Einträge ihn tragen.
     *
     * @param array<array-key,mixed> $row
     * @return list<string>
     */
    public static function problems(array $row, string $pot): array
    {
        $out = [];
        foreach (self::REQUIRED[$pot] ?? [] as $f) {
            if (!array_key_exists($f, $row) || $row[$f] === null || $row[$f] === '') $out[] = "missing:$f";
        }
        foreach (self::FIELDS[$pot] ?? [] as $field => $rule) {
            if (!str_contains($field, '.')) {
                if (($k = self::problem($rule, $row[$field] ?? null)) !== null) $out[] = "$k:$field";
                continue;
            }
            [$list, $sub] = explode('.', $field, 2);
            if (!is_array($row[$list] ?? null) || !array_is_list($row[$list])) continue;   // die Liste selbst meldet 'list'
            // alle Einträge ansehen: ein Eintrag, der kein Objekt ist, verdeckt keinen unbrauchbaren Tag dahinter
            $found = false;
            foreach ($row[$list] as $e) {
                if (!is_array($e)) { $out[] = "list:$list"; continue; }
                if (!$found && ($k = self::problem($rule, $e[$sub] ?? null)) !== null) { $out[] = "$k:$field"; $found = true; }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * v3.13.0 — Felder eines Datensatzes, die ins Leere verweisen (gemeldet als `reference:feld`); eine Regel für den Backup-Import (Zähler und
     * Gruppen nach dem Einspielen) und den Bestand (DataIssues: Zähler und Gruppen in den Dateien). Die Schreibwege
     * prüfen dasselbe mit eigenen Meldungen (`errors.meter.parentNotFound`, `…groupNotFoundId`,
     * `errors.common.meterNotFound`).
     *   - Ablesung, Lieferung, Zeitraum: `meter_id` auf einen Zähler der Verbrauchsart,
     *   - Rechnung: `meter_id`, wenn eingetragen (ohne Zähler zählt sie für die CO₂-Kosten der Art, REQUIRED),
     *   - Vertrag: an einer Gruppe `meter_group_id` auf eine Gruppe, sonst `meter_id` auf einen Zähler,
     *   - Zähler: `parent_meter_id` und `meter_group_id`, wenn eingetragen.
     * Bis v3.12 prüfte der Import nur `meter_id`; ein Elternzähler oder eine Gruppe, die es nicht gibt, ging durch, und
     * der Bestand meldete keinen Verweis.
     *
     * @param array<array-key,mixed> $row
     * @param array{meters: array<string,true>, groups: array<string,true>} $refs Kennungen der Zähler und Gruppen
     * @return list<string>
     */
    public static function references(string $pot, array $row, array $refs): array
    {
        $set = static fn(mixed $v): bool => is_string($v) && $v !== '';
        $known = static fn(mixed $v, string $kind): bool => (is_string($v) || is_int($v)) && isset($refs[$kind][(string)$v]);
        $mid = $row['meter_id'] ?? null;
        $out = [];
        switch ($pot) {
            case 'readings': case 'deliveries': case 'periods':
                if (!$known($mid, 'meters')) $out[] = 'meter_id';
                break;
            case 'bills':
                if (($mid !== null && $mid !== '') && !$known($mid, 'meters')) $out[] = 'meter_id';
                break;
            case 'contracts':
                $group = $row['meter_group_id'] ?? null;
                if ($set($group)) { if (!$known($group, 'groups')) $out[] = 'meter_group_id'; }
                elseif (!$known($mid, 'meters')) $out[] = 'meter_id';
                break;
            case 'meters':
                foreach (['parent_meter_id' => 'meters', 'meter_group_id' => 'groups'] as $f => $kind) {
                    $v = $row[$f] ?? null;
                    if ($v !== null && $v !== '' && !$known($v, $kind)) $out[] = $f;
                }
                break;
        }
        return $out;
    }

    /**
     * Kennungen der Datensätze einer Liste (Zähler, Gruppen) als Menge, für references().
     *
     * @param mixed $rows
     * @return array<string,true>
     */
    public static function ids(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (is_array($r) && (is_string($r['id'] ?? null) || is_int($r['id'] ?? null))) $out[(string)$r['id']] = true;
        }
        return $out;
    }

    /**
     * Wie gespeichert werden soll (Backup-Import, Bestand): Wahrheitswerte als true/false, Zahlen in Textform als
     * Zahl (`"12,5"` → 12.5). Nur Felder der Tabelle, die ihre Regel erfüllen; alles andere bleibt, wie es ist.
     *
     * @param array<array-key,mixed> $row
     * @return array<array-key,mixed>
     */
    public static function normalize(array $row, string $pot): array
    {
        // nur zuweisen, wenn sich die Form ändert: Jede Zuweisung trennte sonst die Kopie vom gelesenen Datensatz, und die
        // Sicht des Bestands hielte jede Ablesung doppelt im Speicher
        foreach (self::FIELDS[$pot] ?? [] as $field => $rule) {
            if (!str_contains($field, '.')) {
                if (array_key_exists($field, $row) && ($c = self::canonical($rule, $row[$field])) !== $row[$field]) $row[$field] = $c;
                continue;
            }
            [$list, $sub] = explode('.', $field, 2);
            if (!is_array($row[$list] ?? null) || !array_is_list($row[$list])) continue;
            foreach ($row[$list] as $i => $e) {
                if (is_array($e) && array_key_exists($sub, $e) && ($c = self::canonical($rule, $e[$sub])) !== $e[$sub]) $row[$list][$i][$sub] = $c;
            }
        }
        return $row;
    }

    /** @param string|array{0:string,1:float|int,2:float|int} $rule */
    private static function canonical(string|array $rule, mixed $v): mixed
    {
        $kind = is_array($rule) ? $rule[0] : $rule;
        // nur Wahrheitswerte und Zahlen in Textform haben eine andere Form; alles andere bleibt (schnell, ohne Prüfung)
        if ($kind === 'bool') return is_bool($v) || $v === null || $v === '' ? $v : (Input::bool($v) ?? $v);
        if (!is_string($v) || $v === '' || !in_array($kind, ['number', 'int', 'counter'], true) || self::problem($rule, $v) !== null) return $v;
        return $kind === 'counter' ? (float)$v : Input::decimal($v);
    }

    /**
     * Sicht der Rechnung auf einen gespeicherten Datensatz (Bestand): null, wenn ein Pflichtfeld die Regel bricht
     * oder fehlt; sonst gilt ein Feld, das seine Regel bricht, als nicht eingetragen (null; in einer Liste fällt ein
     * Eintrag ohne brauchbaren Tag weg, ein Gerät bleibt immer), mit vereinheitlichten Wahrheitswerten und Zahlen.
     * Ein Datensatz ohne Fund kommt unverändert zurück (dieselben
     * Bytes, die Rechnung ändert sich nicht).
     *
     * @param array<array-key,mixed> $row
     * @param list<string>|null $problems schon ermittelte Funde (problems())
     * @return array<array-key,mixed>|null
     */
    public static function usable(array $row, string $pot, ?array $problems = null): ?array
    {
        $problems ??= self::problems($row, $pot);
        if ($problems === []) return $row;
        $required = self::REQUIRED[$pot] ?? [];
        foreach ($problems as $p) {
            [$kind, $field] = explode(':', $p, 2) + [1 => ''];
            // eine Liste mit Einträgen, die keine Objekte sind: nur diese Einträge fallen weg (unten), nicht die Liste
            if ($kind === 'list' && is_array($row[$field] ?? null) && array_is_list($row[$field])) continue;
            $top = explode('.', $field, 2)[0];
            if ($kind === 'missing' || in_array($top, $required, true) && !str_contains($field, '.')) return null;
            // „nicht eingetragen“ als null, nicht als fehlender Schlüssel: Leser greifen auch direkt zu ($dev['final_counter'])
            if (!str_contains($field, '.')) { $row[$field] = null; continue; }
            [$list, $sub] = explode('.', $field, 2);
            $rule = self::FIELDS[$pot][$field];
            // Ein Eintrag ohne brauchbaren Tag (Peilstand, Preis ab, Abschnitt) fällt weg; sonst gilt nur das Feld im
            // Eintrag als nicht eingetragen. Geräte bleiben immer (an ihnen hängen die Ablesungen).
            $dropEntry = (is_array($rule) ? $rule[0] : $rule) === 'date' && $list !== 'devices';
            $entries = [];
            foreach ((array)($row[$list] ?? []) as $e) {
                if (!is_array($e) || self::problem($rule, $e[$sub] ?? null) === null) { $entries[] = $e; continue; }
                if ($dropEntry) continue;
                $e[$sub] = null;
                $entries[] = $e;
            }
            $row[$list] = $entries;
        }
        // eine Liste mit kaputtem Eintrag (kein Objekt): nur die Objekte bleiben
        foreach ($problems as $p) {
            if (!str_starts_with($p, 'list:')) continue;
            $list = substr($p, 5);
            if (is_array($row[$list] ?? null) && array_is_list($row[$list])) $row[$list] = array_values(array_filter($row[$list], 'is_array'));
        }
        return self::normalize($row, $pot);
    }

    /** @var array<string, array{0:string, 1:array{0:list<array<array-key,mixed>>, 1:list<array<string,mixed>>}}> Pfad → [Schlüssel, Sicht] */
    private static array $views = [];

    /**
     * Bestand: die Sicht der Rechnung auf eine Datei eines Topfs — [brauchbare Datensätze in der Reihenfolge der Datei,
     * Funde]. Je Fund `index`, `id`, `problems` (`art:feld`) und `excluded` (der ganze Datensatz fällt aus der Rechnung).
     * Gemerkt je Datei und Stand (Schreibzähler des Stores, Größe und Zeit der Datei): Eine Anfrage liest Ablesungen
     * oft dutzendfach, die Prüfung läuft einmal. Ohne Fund sind die Datensätze dieselben wie in der Datei.
     *
     * @return array{0: list<array<array-key,mixed>>, 1: list<array{index:int, id:?string, problems:list<string>, excluded:bool}>, 2: array<array-key,mixed>}
     */
    public static function view(\Energietracker\Storage\JsonStore $store, string $file, string $pot): array
    {
        $path = $store->path($file);
        clearstatcache(true, $path);
        $st = @stat($path);
        $key = spl_object_id($store) . '|' . $store->generation() . '|' . ($st === false ? '-' : $st['size'] . '|' . $st['mtime']);
        if (isset(self::$views[$path]) && self::$views[$path][0] === $key) return self::$views[$path][1];
        $rows = $store->read($file, []);
        $kept = [];
        $issues = [];
        foreach (is_array($rows) ? $rows : [] as $i => $row) {
            if (!is_array($row)) {
                $issues[] = ['index' => (int)$i, 'id' => null, 'problems' => ['not_an_object'], 'excluded' => true];
                continue;
            }
            $problems = self::problems($row, $pot);
            // ohne Fund in der einen Form: „false“ als Text ist false (die Leser fragen `empty()`)
            if ($problems === []) { $kept[] = self::normalize($row, $pot); continue; }
            $usable = self::usable($row, $pot, $problems);
            $id = $row['id'] ?? null;
            $issues[] = ['index' => (int)$i, 'id' => is_scalar($id) ? (string)$id : null, 'problems' => $problems, 'excluded' => $usable === null];
            if ($usable !== null) $kept[] = $usable;
        }
        return (self::$views[$path] = [$key, [$kept, $issues, is_array($rows) ? $rows : []]])[1];
    }

    /**
     * Die gespeicherten Datensätze derselben Datei, wie sie dastehen (für Schreibwege, die lesen und wieder schreiben).
     * Dasselbe gelesene Array wie die Sicht: Die Datensätze liegen nur einmal im Speicher (eine Datei mit
     * zehntausenden Ablesungen läge sonst während eines Schreibvorgangs doppelt im Speicher).
     *
     * @return array<array-key,mixed>
     */
    public static function stored(\Energietracker\Storage\JsonStore $store, string $file, string $pot): array
    {
        return self::view($store, $file, $pot)[2];
    }

    /**
     * Schreibwege: die Felder der Tabelle in einer Eingabe prüfen (nur die mitgeschickten), wie TextLimits::assert.
     * Ein Eintrag einer Liste, der wortgleich gespeichert ist (`$old`), gilt als Bestand.
     *
     * @param array<array-key,mixed> $in
     * @param array<array-key,mixed> $old
     * @throws LocalizedException 400 mit stabilem Code je Art
     */
    public static function assert(array $in, string $pot, array $old = []): void
    {
        foreach (self::FIELDS[$pot] ?? [] as $field => $rule) {
            if (!str_contains($field, '.')) {
                if (array_key_exists($field, $in) && ($k = self::problem($rule, $in[$field])) !== null) throw self::error($k, $field, $in[$field], $rule);
                continue;
            }
            [$list, $sub] = explode('.', $field, 2);
            if (!is_array($in[$list] ?? null)) continue;
            $known = array_map(fn($e) => json_encode($e), is_array($old[$list] ?? null) ? $old[$list] : []);
            foreach ($in[$list] as $e) {
                if (!is_array($e) || !array_key_exists($sub, $e) || ($k = self::problem($rule, $e[$sub])) === null) continue;
                if (in_array(json_encode($e), $known, true)) continue;   // Bestand, unverändert
                throw self::error($k, $field, $e[$sub], $rule);
            }
        }
    }

    /**
     * 400 mit stabilem Code je Art (dieselben Codes wie Support\Input).
     *
     * @param string|array{0:string,1:float|int,2:float|int} $rule
     */
    public static function error(string $kind, string $field, mixed $v, string|array $rule): LocalizedException
    {
        $shown = is_scalar($v) ? mb_substr((string)$v, 0, 40) : gettype($v);
        return match ($kind) {
            'bool'       => new LocalizedException('errors.common.boolExpected', ['field' => $field], "field $field: bool expected"),
            'date'       => new LocalizedException('errors.common.dateInvalid', ['date' => $shown], "field $field: date expected"),
            'date_range' => new LocalizedException('errors.common.dateOutOfRange', ['field' => $field, 'date' => $shown,
                                'min' => (string)Dates::MIN_INPUT_YEAR, 'max' => (string)Dates::MAX_INPUT_YEAR], "field $field: date out of range"),
            'text'       => Input::textExpected($field),
            'list'       => new LocalizedException('errors.common.listExpected', ['field' => $field], "field $field: list expected"),
            'object'     => Input::objectExpected($field),
            default      => new LocalizedException('errors.common.numberOutOfRange', ['field' => $field, 'value' => $shown,
                                'min' => self::fmt(is_array($rule) ? (float)$rule[1] : 0.0),
                                'max' => self::fmt(is_array($rule) ? (float)$rule[2] : ReadingService::MAX_COUNTER)], "field $field: number out of range"),
        };
    }

    private static function fmt(float $n): string
    {
        return $n == floor($n) && abs($n) < 1e15 ? number_format($n, 0, '', '') : (string)$n;
    }
}
