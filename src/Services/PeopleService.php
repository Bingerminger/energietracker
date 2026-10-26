<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;

/**
 * v3.6.0 — Personenverzeichnis des Haushalts.
 *
 * Datei `people.json`, Topf `people` im Backup (auth.json bleibt draußen:
 * Passwörter, Sitzungen, Schlüssel). Eine Person ist ein Zugang unter
 * Einstellungen → Zugriff (auth.json) oder ein Eintrag ohne Anmeldung — aus
 * einem Backup oder einem Beispielhaushalt. Form:
 *
 *   { last_no: int,
 *     people: [{id, no, name, role, created_at, deleted_at, deleted_by}] }
 *
 *   id          die ID des Zugangs (`u_<hex>`, zufällig) — sie steht an Terminen
 *               (`assignee_id`) und im Verlauf (`by.id`). Sie bleibt zufällig,
 *               weil Backups zwischen Installationen wandern: Eine hochgezählte
 *               ID träfe dort auf eine fremde Person mit derselben Nummer.
 *   no          fortlaufende Personennummer, nie wiederverwendet: `last_no`
 *               steigt nur — nicht zurück beim Löschen und nicht durch ein
 *               älteres Backup (mergeForImport).
 *   role        admin | member, bei Zugängen wie in auth.json
 *   deleted_at  gesetzt, wenn die Person gelöscht ist; der Eintrag bleibt
 *               (Historisierung). `deleted_by` = ID der löschenden Person.
 *
 * Bestand (bis v3.5 gab es kein Verzeichnis): Zugänge ohne Eintrag bekommen
 * beim ersten Lesen eine Nummer, in der Reihenfolge, in der sie angelegt
 * wurden. Kein Schemaschritt — fehlt die Datei, gilt ein leeres Verzeichnis.
 */
final class PeopleService
{
    public const FILE = 'people.json';
    /** Wie die Kennung in Sitzungen (AuthService::sessionUserId). */
    public const ID = '/^u_[0-9a-z]{1,32}$/';
    public const ROLES = ['admin', 'member'];
    /** Längster Name (wie die Namen im Verlauf und an Terminen). */
    private const NAME_MAX = 60;
    /** v3.6.0 — beim Umbenennen wie ein Zugang (AuthService::NAME_MAX, errors.users.nameInvalid) */
    private const NAME_ACCOUNT_MAX = 40;

    /** @var (callable(): list<array<string,mixed>>)|null gespeicherte Zugänge (AuthService::accounts) */
    private $accounts = null;

    public function __construct(private JsonStore $store) {}

    /** Zugänge aus auth.json, die ins Verzeichnis gehören (App: AuthService::accounts). */
    public function attachAccounts(callable $accounts): void
    {
        $this->accounts = $accounts;
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /**
     * Das Verzeichnis, abgeglichen mit den Zugängen (neue bekommen eine
     * Nummer, Name und Rolle folgen dem Zugang). Geschrieben wird nur bei
     * einer Änderung — unter der Schreibsperre und mit frisch gelesenem Stand.
     *
     * @return array{last_no:int, people:list<array<string,mixed>>}
     */
    public function all(): array
    {
        // je Stand des Speichers einmal (die Agenda fragt je Termin nach der Person)
        if ($this->memo !== null && $this->memoGen === $this->store->generation()) return $this->memo;
        $accounts = $this->accounts === null ? [] : ($this->accounts)();
        [$reg, $changed] = self::merge(self::normalize($this->store->read(self::FILE, [])), $accounts);
        if ($changed) {
            try {
                $this->store->lock();
                [$reg] = self::merge(self::normalize($this->store->read(self::FILE, [])), $accounts);
                $this->store->write(self::FILE, $reg);
            } catch (\Throwable) {
                // nur lesend erreichbar: gilt für diese Anfrage
            }
        }
        $this->memo = $reg;
        $this->memoGen = $this->store->generation();
        return $reg;
    }

    /** @var array{last_no:int, people:list<array<string,mixed>>}|null */
    private ?array $memo = null;
    private int $memoGen = -1;

    /**
     * Personen, die es gibt (nicht gelöscht), nach Namen sortiert.
     *
     * @return list<array{id:string, no:int, name:string, role:string}>
     */
    public function active(): array
    {
        $out = [];
        foreach ($this->all()['people'] as $p) {
            if ($p['deleted_at'] !== null || $p['name'] === '') continue;
            $out[] = ['id' => $p['id'], 'no' => $p['no'], 'name' => $p['name'], 'role' => $p['role']];
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']) ?: $a['no'] <=> $b['no']);
        return $out;
    }

    /**
     * Eine Person, auch eine gelöschte (`deleted: true`), sonst null.
     *
     * @return array{id:string, no:int, name:string, role:string, deleted:bool}|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all()['people'] as $p) {
            if ($p['id'] === $id) {
                return ['id' => $p['id'], 'no' => $p['no'], 'name' => $p['name'], 'role' => $p['role'], 'deleted' => $p['deleted_at'] !== null];
            }
        }
        return null;
    }

    /**
     * ID einer Person ohne Zugang mit diesem Namen (Groß/klein egal), die ein
     * neuer Zugang übernimmt — nach einem Umzug per Backup ist „Anna“ im
     * Verzeichnis dieselbe Anna, die sich jetzt anmeldet. Gelöschte Personen
     * übernimmt niemand.
     */
    public function adoptableId(string $name): ?string
    {
        $key = mb_strtolower(trim($name));
        if ($key === '') return null;
        $accounts = [];
        foreach ($this->accounts === null ? [] : ($this->accounts)() as $a) $accounts[(string)($a['id'] ?? '')] = true;
        foreach ($this->all()['people'] as $p) {
            if ($p['deleted_at'] === null && !isset($accounts[$p['id']]) && mb_strtolower($p['name']) === $key) return $p['id'];
        }
        return null;
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /**
     * v3.9.0 — Person ohne Zugang anlegen (Einstellungen → Haushalt & Gebäude,
     * Karte „Personen im Haushalt“): Sie steht unter „Zuständig“ zur Wahl, auch
     * ganz ohne Anmeldung. Anmelden kann sie sich nicht; ein späterer Zugang mit
     * demselben Namen übernimmt ihre ID und Nummer (adoptableId). Dieselben
     * Regeln wie beim Umbenennen: 1–40 Zeichen, eindeutig unter allen Personen.
     *
     * @return array{id:string, no:int, name:string, role:string, created_at:string}
     * @throws \InvalidArgumentException name_invalid | name_taken (Zugang) |
     *         name_taken_person (Person ohne Zugang) | role_invalid
     */
    public function add(string $name, string $role = 'member'): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > self::NAME_ACCOUNT_MAX || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new \InvalidArgumentException('name_invalid');
        }
        if (!in_array($role, self::ROLES, true)) throw new \InvalidArgumentException('role_invalid');
        $this->all();   // Bestand erst ins Verzeichnis (Zugänge mit Nummer)
        $this->store->lock();
        $reg = self::normalize($this->store->read(self::FILE, []));
        $accountIds = array_map(fn($a) => (string)($a['id'] ?? ''), $this->accounts === null ? [] : ($this->accounts)());
        $key = mb_strtolower($name);
        $ids = [];
        foreach ($reg['people'] as $p) {
            $ids[$p['id']] = true;
            if ($p['deleted_at'] === null && mb_strtolower($p['name']) === $key) {
                throw new \InvalidArgumentException(in_array($p['id'], $accountIds, true) ? 'name_taken' : 'name_taken_person');
            }
        }
        // zufällig wie ein Zugang (AuthService::newUserId), nie eine schon vergebene — auch keine gelöschte
        do { $id = 'u_' . bin2hex(random_bytes(4)); } while (isset($ids[$id]) || in_array($id, $accountIds, true));
        $p = ['id' => $id, 'no' => ++$reg['last_no'], 'name' => $name, 'role' => $role,
              'created_at' => date('c'), 'deleted_at' => null, 'deleted_by' => null];
        $reg['people'][] = $p;
        $this->store->write(self::FILE, $reg);
        return ['id' => $p['id'], 'no' => $p['no'], 'name' => $p['name'], 'role' => $p['role'], 'created_at' => $p['created_at']];
    }

    /**
     * Person als gelöscht vermerken (der Eintrag bleibt, die Nummer auch).
     * Eine Person, die noch nicht im Verzeichnis stand, kommt als gelöschte
     * hinein (sie hat eine Nummer gehabt, nur nie eine gesehen).
     */
    public function markDeleted(string $id, ?string $by, string $name = ''): void
    {
        $this->store->lock();
        $reg = self::normalize($this->store->read(self::FILE, []));
        $found = false;
        foreach ($reg['people'] as $i => $p) {
            if ($p['id'] !== $id) continue;
            $reg['people'][$i]['deleted_at'] = date('c');
            $reg['people'][$i]['deleted_by'] = $by;
            $found = true;
        }
        if (!$found) {
            if (!preg_match(self::ID, $id)) return;
            $reg['people'][] = ['id' => $id, 'no' => ++$reg['last_no'], 'name' => self::cleanName($name), 'role' => 'member',
                                'created_at' => '', 'deleted_at' => date('c'), 'deleted_by' => $by];
        }
        $this->store->write(self::FILE, $reg);
    }

    /**
     * v3.6.0 — Name und Rolle einer Person ohne Zugang ändern (Einstellungen →
     * Zugriff, nur Verwalter). Zugänge ändert AuthService::updateUser; ihr
     * Eintrag folgt beim nächsten Lesen (merge). Offene Termine zeigen danach
     * den neuen Namen (sie lesen ihn über die ID), erledigte und der Verlauf
     * behalten den Namen vom Erledigen.
     *
     * @param array{name?:mixed, role?:mixed} $patch
     * @return array{id:string, no:int, name:string, role:string, created_at:string}
     * @throws \InvalidArgumentException not_found | name_invalid | name_taken (Zugang) |
     *         name_taken_person (Person ohne Zugang) | role_invalid
     */
    public function update(string $id, array $patch): array
    {
        $this->all();   // Bestand erst ins Verzeichnis
        $this->store->lock();
        $reg = self::normalize($this->store->read(self::FILE, []));
        $accountIds = array_map(fn($a) => (string)($a['id'] ?? ''), $this->accounts === null ? [] : ($this->accounts)());
        if (in_array($id, $accountIds, true)) throw new \InvalidArgumentException('not_found');
        $i = null;
        foreach ($reg['people'] as $k => $p) if ($p['id'] === $id && $p['deleted_at'] === null) $i = $k;
        if ($i === null) throw new \InvalidArgumentException('not_found');
        if (array_key_exists('name', $patch)) {
            // dieselben Regeln wie für einen Zugang (AuthService::cleanName: 1–40 Zeichen)
            $name = is_string($patch['name']) ? trim(preg_replace('/\s+/u', ' ', $patch['name']) ?? '') : '';
            if ($name === '' || mb_strlen($name) > self::NAME_ACCOUNT_MAX || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                throw new \InvalidArgumentException('name_invalid');
            }
            // eindeutig unter allen, die es gibt — Übernahme und freie Namen gehen über den Namen;
            // die Zugänge stehen nach all() selbst im Verzeichnis (merge)
            $key = mb_strtolower($name);
            foreach ($reg['people'] as $k => $p) {
                if ($k !== $i && $p['deleted_at'] === null && mb_strtolower($p['name']) === $key) {
                    throw new \InvalidArgumentException(in_array($p['id'], $accountIds, true) ? 'name_taken' : 'name_taken_person');
                }
            }
            $reg['people'][$i]['name'] = $name;
        }
        if (array_key_exists('role', $patch)) {
            if (!in_array($patch['role'], self::ROLES, true)) throw new \InvalidArgumentException('role_invalid');
            $reg['people'][$i]['role'] = (string)$patch['role'];
        }
        $this->store->write(self::FILE, $reg);
        $p = $reg['people'][$i];
        return ['id' => $p['id'], 'no' => $p['no'], 'name' => $p['name'], 'role' => $p['role'], 'created_at' => $p['created_at']];
    }

    // ── Form ─────────────────────────────────────────────────────────────

    /**
     * Normalform eines Verzeichnisses (auch aus einem Backup oder einer alten
     * Datei): unbrauchbare Einträge fallen weg, doppelte IDs zählen einmal.
     * Ein Eintrag ohne gültige oder mit doppelter Nummer bekommt die nächste.
     *
     * @return array{last_no:int, people:list<array<string,mixed>>}
     */
    public static function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $list = is_array($raw['people'] ?? null) ? $raw['people'] : [];
        $last = is_int($raw['last_no'] ?? null) && $raw['last_no'] > 0 ? $raw['last_no'] : 0;
        $people = [];
        $ids = [];
        $nos = [];
        foreach ($list as $p) {
            if (!is_array($p) || !is_string($p['id'] ?? null) || !preg_match(self::ID, $p['id']) || isset($ids[$p['id']])) continue;
            $ids[$p['id']] = true;
            $no = is_int($p['no'] ?? null) && $p['no'] > 0 && !isset($nos[$p['no']]) ? $p['no'] : null;
            if ($no !== null) { $nos[$no] = true; $last = max($last, $no); }
            $people[] = [
                'id'         => $p['id'],
                'no'         => $no,
                'name'       => self::cleanName($p['name'] ?? ''),
                'role'       => in_array($p['role'] ?? null, self::ROLES, true) ? (string)$p['role'] : 'member',
                'created_at' => is_string($p['created_at'] ?? null) ? $p['created_at'] : '',
                'deleted_at' => is_string($p['deleted_at'] ?? null) && $p['deleted_at'] !== '' ? $p['deleted_at'] : null,
                'deleted_by' => is_string($p['deleted_by'] ?? null) && $p['deleted_by'] !== '' ? $p['deleted_by'] : null,
            ];
        }
        foreach ($people as $i => $p) {
            if ($p['no'] === null) $people[$i]['no'] = ++$last;
        }
        return ['last_no' => $last, 'people' => $people];
    }

    /**
     * Ein Verzeichnis aus einem Backup einspielen: Es ersetzt das bisherige,
     * aber der Zähler geht nicht zurück — sonst bekäme eine neue Person eine
     * Nummer, die in dieser Installation schon vergeben war.
     *
     * @return array{last_no:int, people:list<array<string,mixed>>}
     */
    public static function mergeForImport(mixed $incoming, mixed $current): array
    {
        $in = self::normalize($incoming);
        $in['last_no'] = max($in['last_no'], self::normalize($current)['last_no']);
        return $in;
    }

    /**
     * Zugänge ins Verzeichnis übernehmen: neue (nach `created_at`, dann ID)
     * mit der nächsten Nummer, bekannte mit Name und Rolle des Zugangs. Ein
     * Zugang hebt den Vermerk „gelöscht“ auf (das Verzeichnis war älter).
     *
     * @param array{last_no:int, people:list<array<string,mixed>>} $reg
     * @param list<array<string,mixed>> $accounts
     * @return array{0: array{last_no:int, people:list<array<string,mixed>>}, 1: bool}
     */
    private static function merge(array $reg, array $accounts): array
    {
        $index = [];
        foreach ($reg['people'] as $i => $p) $index[$p['id']] = $i;
        usort($accounts, fn($a, $b) => [(string)($a['created_at'] ?? ''), (string)($a['id'] ?? '')]
                                    <=> [(string)($b['created_at'] ?? ''), (string)($b['id'] ?? '')]);
        $changed = false;
        foreach ($accounts as $a) {
            $id = (string)($a['id'] ?? '');
            if (!preg_match(self::ID, $id)) continue;
            $name = self::cleanName($a['name'] ?? '');
            $role = in_array($a['role'] ?? null, self::ROLES, true) ? (string)$a['role'] : 'member';
            if (isset($index[$id])) {
                $i = $index[$id];
                $p = $reg['people'][$i];
                if ($p['name'] === $name && $p['role'] === $role && $p['deleted_at'] === null) continue;
                $reg['people'][$i]['name'] = $name;
                $reg['people'][$i]['role'] = $role;
                $reg['people'][$i]['deleted_at'] = null;
                $reg['people'][$i]['deleted_by'] = null;
            } else {
                $reg['people'][] = ['id' => $id, 'no' => ++$reg['last_no'], 'name' => $name, 'role' => $role,
                                    'created_at' => (string)($a['created_at'] ?? ''), 'deleted_at' => null, 'deleted_by' => null];
                $index[$id] = count($reg['people']) - 1;
            }
            $changed = true;
        }
        return [$reg, $changed];
    }

    private static function cleanName(mixed $name): string
    {
        if (!is_string($name)) return '';
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        return mb_substr($name, 0, self::NAME_MAX);
    }
}
