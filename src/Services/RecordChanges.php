<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Storage\JsonStore;

/**
 * v3.13.0 (Paket F) — der Rückweg einer Übernahme der KI (Rückgängig im Verlauf, bis v3.12 `409`).
 *
 * Eine Übernahme (`apply_proposal`, „Übernehmen“ in der App) schreibt über die Dienste wie die Handeingabe
 * und kann viele Datensätze berühren: eine Ablesung, beim Dokument Vertrag, Preise, Rechnung, Buchung,
 * Ablesungen, Termine, Gas-Umrechnung, Zähler. Statt je Art einen eigenen Rückweg zu bauen, hält
 * capture() vor und nach der Übernahme die Töpfe fest (unter der Schreibsperre der Anfrage, also schreibt
 * niemand dazwischen), diff() macht daraus die Änderungen je Datensatz {file, id | key, before, after}.
 * Sie stehen am Verlaufseintrag (`revert: {type: records, changes}`, im Backup mit dem Verlauf).
 *
 * Zurückgenommen wird nur der **jüngste Stand**: conflicts() vergleicht jeden berührten Datensatz mit dem
 * Stand nach der Übernahme; hat ihn seitdem jemand geändert (die Person, eine zweite Übernahme, Home
 * Assistant), wird nichts zurückgestellt und der Verlauf meldet es (`409 errors.activity.undoChanged`).
 * restore() stellt dann jeden Datensatz auf den Stand davor (angelegt → gelöscht, geändert → wie vorher)
 * und schreibt jede Datei einmal (JsonStore::batch). Buchführung ohne eigenen Inhalt geht nachsichtig
 * zurück: am Dokument des Posteingangs Status, Übernommenes und Zuordnung, an einem Beleg die Verknüpfung.
 *
 * Datensätze vergleicht same() nach JSON-Form mit sortierten Schlüsseln (10 und 10.0 gleich, Reihenfolge
 * der Felder egal). Nur bekannte Töpfe (Liste wie das Backup): Ein Verlaufseintrag aus einem fremden Backup
 * kann damit nichts außerhalb der Daten schreiben.
 */
final class RecordChanges
{
    /** Töpfe, die nur nachsichtig zurückgestellt werden (Buchführung zum Datensatz, kein eigener Inhalt). */
    private const LENIENT = [InboxService::FILE, 'attachments.json'];
    /** Felder des Dokuments, die eine Übernahme setzt und die Rücknahme zurückstellt. */
    private const INBOX_FIELDS = ['status', 'applied', 'assignment'];

    /** @return list<string> die Töpfe, die eine Übernahme berühren kann */
    public static function files(): array
    {
        $out = [];
        foreach (Utilities::keys() as $u) foreach (BackupService::UTILITY_POTS as $pot) $out[] = "$u/$pot.json";
        return [...$out, 'reminders.json', 'settings.json', 'attachments.json', InboxService::FILE];
    }

    /**
     * Datei → Inhalt wie auf der Platte (fehlt sie: null). Roh, nicht dekodiert: Ein Haushalt mit langen Reihen
     * braucht dekodiert ein Vielfaches (BackupService); diff() dekodiert nur Dateien, deren Bytes sich geändert haben.
     *
     * @return array<string,?string>
     */
    public static function capture(JsonStore $store): array
    {
        $out = [];
        foreach (self::files() as $f) {
            $raw = $store->exists($f) ? @file_get_contents($store->path($f)) : false;
            $out[$f] = $raw === false ? null : $raw;
        }
        return $out;
    }

    /**
     * Einstellungen, die eine Übernahme schreibt (Applier: Gas-Umrechnung) und eine Rücknahme zurückstellen darf.
     * Eine andere Einstellung in einem Rückweg (fremdes Backup) ist ein Konflikt: Rückgängig dürfen auch Mitglieder,
     * Verwalter-Einstellungen gehören nicht dazu.
     */
    private const SETTINGS_KEYS = ['gas_conversion_factors'];

    /**
     * Änderungen seit `capture()`: liest jede Datei einzeln und dekodiert nur, was sich geändert hat (gemessen:
     * 20 Jahre tägliche Ablesungen an zehn Zählern, 19 MB Dateien, Spitze unter 128 MB; tmp-f-eingaben).
     *
     * @param array<string,?string> $before roh (capture)
     * @return list<array<string,mixed>>
     */
    public static function since(JsonStore $store, array $before): array
    {
        $out = [];
        foreach (self::files() as $f) {
            $raw = $store->exists($f) ? @file_get_contents($store->path($f)) : false;
            $now = $raw === false ? null : $raw;
            if ($now === ($before[$f] ?? null)) continue;
            foreach (self::diff([$f => $before[$f] ?? null], [$f => $now]) as $c) $out[] = $c;
        }
        return $out;
    }

    /**
     * @param array<string,?string> $before roh (capture)
     * @param array<string,?string> $after  roh (capture)
     * @return list<array<string,mixed>>
     */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        foreach (self::files() as $file) {
            if (!array_key_exists($file, $before) && !array_key_exists($file, $after)) continue;
            if (($before[$file] ?? null) === ($after[$file] ?? null)) continue;
            $b = self::decode($before[$file] ?? null);
            $a = self::decode($after[$file] ?? null);
            if (self::same($b, $a)) continue;
            if ($file === 'settings.json' && (is_array($a) || $a === null) && (is_array($b) || $b === null)) {
                foreach (array_unique([...array_keys((array)$b), ...array_keys((array)$a)]) as $k) {
                    if (!in_array((string)$k, self::SETTINGS_KEYS, true)) continue;
                    $x = is_array($b) && array_key_exists($k, $b) ? ['v' => $b[$k]] : null;
                    $y = is_array($a) && array_key_exists($k, $a) ? ['v' => $a[$k]] : null;
                    if (!self::same($x, $y)) $out[] = ['file' => $file, 'key' => (string)$k, 'before' => $x, 'after' => $y];
                }
                continue;
            }
            if (self::records($a) !== null && self::records($b) !== null) {
                $bi = self::records($b);
                $ai = self::records($a);
                foreach (array_unique([...array_keys($bi), ...array_keys($ai)]) as $id) {
                    $x = $bi[$id] ?? null;
                    $y = $ai[$id] ?? null;
                    if (self::same($x, $y)) continue;
                    // Buchführung: nur die Felder, die zurückgehen (die Auslesung eines Dokuments kann groß sein)
                    $keep = match ($file) { InboxService::FILE => self::INBOX_FIELDS, 'attachments.json' => ['ref'], default => null };
                    if ($keep !== null) {
                        $trim = fn(?array $r): ?array => $r === null ? null : array_intersect_key($r, array_flip(['id', ...$keep]));
                        [$x, $y] = [$trim($x), $trim($y)];
                        if (self::same($x, $y)) continue;
                    }
                    $out[] = ['file' => $file, 'id' => (string)$id, 'before' => $x, 'after' => $y];
                }
                continue;
            }
            $out[] = ['file' => $file, 'whole' => true, 'before' => $b, 'after' => $a];
        }
        return $out;
    }

    /**
     * Was seit der Übernahme anders ist (leer: der Stand nach der Übernahme ist noch der jüngste).
     *
     * @param list<array<string,mixed>> $changes
     * @return list<string> Datei und Datensatz, die sich geändert haben
     */
    public static function conflicts(JsonStore $store, array $changes): array
    {
        $out = [];
        $now = [];
        foreach ($changes as $c) {
            $file = (string)($c['file'] ?? '');
            if (!in_array($file, self::files(), true)) { $out[] = $file; continue; }
            if ($file === 'settings.json' && !in_array((string)($c['key'] ?? ''), self::SETTINGS_KEYS, true)) { $out[] = "$file#" . ($c['key'] ?? '*'); continue; }
            if (in_array($file, self::LENIENT, true)) continue;
            $now[$file] ??= $store->exists($file) ? $store->read($file, null) : null;
            $cur = match (true) {
                isset($c['key']) => is_array($now[$file]) && array_key_exists((string)$c['key'], $now[$file]) ? ['v' => $now[$file][(string)$c['key']]] : null,
                isset($c['id'])  => self::records($now[$file])[(string)$c['id']] ?? null,
                default          => $now[$file],
            };
            if (!self::same($cur, $c['after'] ?? null)) $out[] = $file . (isset($c['id']) ? '#' . $c['id'] : (isset($c['key']) ? '#' . $c['key'] : ''));
        }
        // Was die Rücknahme entfernt (von der Übernahme angelegte Datensätze und Einträge in ihnen, etwa das Gerät
        // eines Zählertauschs oder eine Sonderzahlung): Verweist ein Datensatz außerhalb der Übernahme darauf (eine
        // spätere Ablesung auf dem neuen Gerät, eine Rechnung am neuen Vertrag), ist die Übernahme nicht mehr der
        // jüngste Stand. Die Dienste sperren solche Löschungen selbst (errors.meter.hasBills …); restore() schreibt
        // an ihnen vorbei, also prüft es hier.
        $gone = [];
        $inSet = [];
        foreach ($changes as $c) {
            if (!isset($c['id']) || in_array((string)($c['file'] ?? ''), self::LENIENT, true)) continue;
            $inSet[(string)$c['file']][(string)$c['id']] = true;
            if (($c['before'] ?? null) === null && is_array($c['after'] ?? null)) $gone[(string)$c['id']] = true;
            $had = self::nestedIds($c['before'] ?? null);
            foreach (self::nestedIds($c['after'] ?? null) as $nid) if (!in_array($nid, $had, true)) $gone[$nid] = true;
        }
        if ($gone === []) return $out;
        // Datei für Datei (ein Haushalt mit langen Reihen hält sonst alle Töpfe dekodiert zugleich)
        unset($now);
        foreach (self::files() as $file) {
            if ($file === 'settings.json' || in_array($file, self::LENIENT, true) || !$store->exists($file)) continue;
            $data = $store->read($file, null);
            foreach (self::records($data) ?? [] as $id => $r) {
                if (!isset($inSet[$file][(string)$id]) && self::refersTo($r, $gone)) $out[] = "$file#$id";
            }
            unset($data);
        }
        return $out;
    }

    /** @return list<string> Kennungen der Einträge in Listen eines Datensatzes (Geräte, Sonderzahlungen …) */
    private static function nestedIds(mixed $record): array
    {
        $out = [];
        foreach (is_array($record) ? $record : [] as $v) {
            if (!is_array($v) || !array_is_list($v)) continue;
            foreach ($v as $e) if (is_array($e) && is_scalar($e['id'] ?? null)) $out[] = (string)$e['id'];
        }
        return $out;
    }

    /**
     * Verweist der Datensatz auf eine der Kennungen (`…_id`, Listen `…_ids`)?
     *
     * @param array<string,mixed> $record
     * @param array<string,true> $ids
     */
    private static function refersTo(array $record, array $ids): bool
    {
        foreach ($record as $k => $v) {
            if (!is_string($k)) continue;
            if (str_ends_with($k, '_id') && is_scalar($v) && isset($ids[(string)$v])) return true;
            if (str_ends_with($k, '_ids') && is_array($v)) foreach ($v as $x) if (is_scalar($x) && isset($ids[(string)$x])) return true;
        }
        return false;
    }

    private static function decode(?string $raw): mixed
    {
        if ($raw === null || trim($raw) === '') return null;
        return json_decode($raw, true);
    }

    /**
     * Jeden Datensatz auf den Stand vor der Übernahme, jede Datei einmal. Vorher conflicts() prüfen.
     *
     * @param list<array<string,mixed>> $changes
     */
    public static function restore(JsonStore $store, array $changes): void
    {
        $byFile = [];
        foreach ($changes as $c) {
            $file = (string)($c['file'] ?? '');
            if (in_array($file, self::files(), true)) $byFile[$file][] = $c;
        }
        $store->batch(function () use ($store, $byFile): void {
            foreach ($byFile as $file => $list) {
                $data = $store->exists($file) ? $store->read($file, null) : null;
                foreach ($list as $c) {
                    if (!empty($c['whole'])) { if ($file !== 'settings.json') $data = $c['before'] ?? null; continue; }
                    if (isset($c['key'])) {
                        if (!in_array((string)$c['key'], self::SETTINGS_KEYS, true)) continue;
                        $data = is_array($data) ? $data : [];
                        if (is_array($c['before'] ?? null)) $data[(string)$c['key']] = $c['before']['v'] ?? null;
                        else unset($data[(string)$c['key']]);
                        continue;
                    }
                    $data = self::restoreRecord($file, is_array($data) ? array_values($data) : [], (string)$c['id'], $c['before'] ?? null, $c['after'] ?? null);
                }
                if ($data === null) { $store->delete($file); continue; }
                if (str_ends_with($file, '/readings.json') && array_is_list($data)) {
                    usort($data, fn($a, $b) => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
                }
                $store->write($file, $data);
            }
        });
    }

    /**
     * @param list<mixed> $list
     * @return list<mixed>
     */
    private static function restoreRecord(string $file, array $list, string $id, mixed $before, mixed $after): array
    {
        $at = null;
        foreach ($list as $i => $r) if (is_array($r) && (string)($r['id'] ?? '') === $id) { $at = $i; break; }
        if ($file === InboxService::FILE) {
            // das Dokument bleibt (mit Notiz, Auslesung, Entscheidungen); zurück gehen Status, Übernommenes, Zuordnung
            if ($at === null || !is_array($before)) return $list;
            foreach (self::INBOX_FIELDS as $f) {
                if (array_key_exists($f, $before)) $list[$at][$f] = $before[$f]; else unset($list[$at][$f]);
            }
            return $list;
        }
        if ($file === 'attachments.json') {
            // nur die Verknüpfung, und nur wenn sie noch die der Übernahme ist
            if ($at === null || !is_array($before) || !is_array($after) || !self::same($list[$at]['ref'] ?? null, $after['ref'] ?? null)) return $list;
            $list[$at]['ref'] = $before['ref'] ?? null;
            return $list;
        }
        if ($before === null) {
            if ($at !== null) array_splice($list, $at, 1);
            return array_values($list);
        }
        if ($at !== null) $list[$at] = $before; else $list[] = $before;
        return $list;
    }

    /** @return array<string,array<string,mixed>>|null Datensätze nach `id`; null, wenn es keine solche Liste ist */
    private static function records(mixed $data): ?array
    {
        if ($data === null) return [];
        if (!is_array($data) || !array_is_list($data)) return null;
        $out = [];
        foreach ($data as $r) {
            if (!is_array($r) || !is_scalar($r['id'] ?? null)) return null;
            $out[(string)$r['id']] = $r;
        }
        return $out;
    }

    /** Gleicher Inhalt in JSON-Form (Schlüssel sortiert, 10 = 10.0). */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::canon($a) === self::canon($b);
    }

    private static function canon(mixed $v): string
    {
        $norm = function (mixed $x) use (&$norm): mixed {
            if (is_float($x) && floor($x) === $x && abs($x) < 1e15) return (int)$x;
            if (!is_array($x)) return $x;
            if (!array_is_list($x)) ksort($x);
            return array_map($norm, $x);
        };
        return (string)json_encode($norm($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
