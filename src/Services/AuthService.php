<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Storage\JsonStore;

/**
 * F1009 — API-Token-Verwaltung für externe Schreibzugriffe (Home Assistant).
 *
 * Designprinzip (Multiple-Choice-Entscheidung 2026-06-01):
 *  - **Opt-in**: Solange kein Token gesetzt ist, ist die API unverändert offen
 *    (LAN-Annahme, keine Breaking Change). `requiresAuth()` ist dann false.
 *  - Der Token wird **einmalig** im Klartext erzeugt und zurückgegeben; gespeichert
 *    wird nur sein **SHA-256-Hash** in einer separaten `data/auth.json` — NICHT in
 *    `settings.json`, weil `GET /api/settings` den gesamten Settings-Block
 *    ausliefert. So ist der Token nach dem Erzeugen nicht mehr auslesbar.
 *  - `verify()` nutzt `hash_equals()` (konstante Zeit) gegen Timing-Angriffe.
 *
 * Genau ein aktiver Token; `revoke()` entfernt ihn wieder (zurück in den
 * offenen Modus). `data/auth.json` wird vom Backup ausgenommen.
 *
 * v2.6.0 — Anmeldung (opt-in, Review 2026-09-24, Entscheidung „optionale
 * Anmeldung"). Modus aus `ET_AUTH` (off | password | proxy), sonst aus
 * auth.json (in den Einstellungen umschaltbar), sonst off — das heutige
 * Verhalten. Mit Anmeldung verlangt jede API-Route eine Sitzung, einen
 * API-Schlüssel oder (proxy) einen angemeldeten Benutzer vom vorgeschalteten
 * Proxy; ausgenommen sind nur der Ingest (Token) und /api/health
 * (Minimalform). Fehlerfall fail-closed: Eine unlesbare auth.json wirft
 * (JsonStore), statt „kein Token" zu bedeuten.
 */
final class AuthService
{
    private const FILE = 'auth.json';
    public const MODES = ['off', 'password', 'proxy'];
    public const COOKIE = 'et_session';
    private const SESSION_TTL = 30 * 86400;
    /** v3.4.0 (N1020) — gleitend: eine Sitzung, die älter als ein Tag ist, bekommt bei Gebrauch wieder die volle Dauer. */
    private const SESSION_RENEW_AFTER = 86400;
    /**
     * v3.4.0 (N1020) — Sperre nach Fehlversuchen je Gerät (Client-Adresse) und
     * je Name; für alle zusammen nur eine hohe Notbremse. Bis v3.3 galt eine
     * einzige Sperre für alle: Jedes Gerät im Netz sperrte mit fünf falschen
     * Versuchen den ganzen Haushalt aus, beliebig wiederholbar.
     */
    private const LIMITS = ['ip' => 5, 'name' => 10, 'all' => 100];
    private const FAILURE_WINDOW = 900;
    private const LOCK_SECONDS = 300;
    /** Höchstzahl gemerkter Zähler (viele Adressen, z. B. IPv6, lassen auth.json sonst wachsen). */
    private const THROTTLE_MAX_ENTRIES = 500;
    /**
     * Ziel des Laufzeitausgleichs, wenn es noch kein Passwort gibt: Bei einem
     * unbekannten Namen läuft password_verify trotzdem (sonst verriete die
     * Antwortzeit, welche Namen es gibt).
     */
    private const DUMMY_HASH = '$2y$12$eFRbCNPkROi8SkpUGCP1t.gyTYBpos1A6.5f.2vCsK7ZePTaaWGRm';
    private const MIN_PASSWORD = 8;

    public function __construct(private JsonStore $store) {}

    /** @return array<string,mixed> */
    private function data(): array
    {
        $data = $this->store->read(self::FILE, []);
        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed> $patch */
    private function save(array $patch): void
    {
        $data = array_merge($this->data(), $patch);
        foreach ($patch as $k => $v) if ($v === null) unset($data[$k]);
        $this->store->write(self::FILE, $data);
    }

    // ── Ingest-Token (F1009) ────────────────────────────────────────────

    /** Ist überhaupt ein Token gesetzt? Nur dann ist Auth erforderlich. */
    public function requiresAuth(): bool
    {
        return !empty($this->data()['token_hash']);
    }

    /**
     * Status für die UI (NIE der Klartext-Token):
     *   { enabled: bool, created_at: ?string, last_used_at: ?string }
     */
    public function status(): array
    {
        $data = $this->data();
        $enabled = !empty($data['token_hash']);
        return [
            'enabled'      => $enabled,
            'created_at'   => $enabled ? ($data['created_at'] ?? null) : null,
            // v2.6.0 — hilft bei der HA-Fehlersuche („kommt überhaupt etwas an?")
            'last_used_at' => $enabled ? ($data['token_last_used_at'] ?? null) : null,
        ];
    }

    /**
     * Erzeugt einen neuen Token, speichert dessen Hash und gibt den
     * **Klartext** zurück (nur dieses eine Mal sichtbar). Ein bereits
     * existierender Token wird dabei ersetzt.
     */
    public function generate(): string
    {
        $token = 'et_' . bin2hex(random_bytes(24)); // 48 Hex-Zeichen + Präfix
        $this->save([
            'token_hash'         => hash('sha256', $token),
            'created_at'         => date('c'),
            'token_last_used_at' => null,
        ]);
        return $token;
    }

    /** Entfernt den Token → Ingest ist wieder offen (ohne Anmeldung). */
    public function revoke(): void
    {
        $this->save(['token_hash' => null, 'created_at' => null, 'token_last_used_at' => null]);
    }

    /**
     * Prüft einen Klartext-Token gegen den gespeicherten Hash.
     * Ist kein Token gesetzt, gilt jede Anfrage als autorisiert (offener Modus).
     */
    public function verify(?string $token): bool
    {
        $data = $this->data();
        if (empty($data['token_hash'])) {
            return true; // offener Modus: keine Auth konfiguriert
        }
        if ($token === null || $token === '') return false;
        $ok = hash_equals((string)$data['token_hash'], hash('sha256', $token));
        if ($ok) $this->touch('token_last_used_at', $data['token_last_used_at'] ?? null);
        return $ok;
    }

    /** Zeitstempel höchstens stündlich schreiben (Ingest pusht oft täglich, manchmal minütlich). */
    private function touch(string $field, ?string $previous): void
    {
        if ($previous !== null && strtotime($previous) > time() - 3600) return;
        try { $this->save([$field => date('c')]); } catch (\Throwable) { /* Statistik, kein Grund zum Scheitern */ }
    }

    // ── Anmeldung (v2.6.0) ──────────────────────────────────────────────

    public function modeFixedByEnv(): bool
    {
        return in_array(strtolower((string)getenv('ET_AUTH')), self::MODES, true);
    }

    /** off | password | proxy */
    public function mode(): string
    {
        $env = strtolower((string)getenv('ET_AUTH'));
        if (in_array($env, self::MODES, true)) return $env;
        $stored = (string)($this->data()['mode'] ?? 'off');
        return in_array($stored, self::MODES, true) ? $stored : 'off';
    }

    public function loginEnabled(): bool
    {
        return $this->mode() !== 'off';
    }

    private function passwordHash(): ?string
    {
        $env = (string)getenv('ET_ADMIN_PASSWORD_HASH');
        if ($env !== '') return $env;
        $stored = $this->data()['password_hash'] ?? null;
        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    public function hasPassword(): bool
    {
        return $this->passwordHash() !== null;
    }

    public function passwordFixedByEnv(): bool
    {
        return (string)getenv('ET_ADMIN_PASSWORD_HASH') !== '';
    }

    /**
     * Setzt (oder ändert) das Passwort und schaltet die Anmeldung ein.
     * Ein neues Sitzungsgeheimnis beendet alle bestehenden Sitzungen.
     */
    public function setPassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new \InvalidArgumentException('password_too_short');
        }
        $patch = [
            'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
            'session_secret' => bin2hex(random_bytes(32)),
            'login_failures' => null,
            'login_throttle' => null,
        ];
        if (!$this->modeFixedByEnv()) $patch['mode'] = 'password';
        $this->save($patch);
    }

    /** Schaltet die Anmeldung aus (Passwort bleibt nicht erhalten). */
    public function disableLogin(): void
    {
        $this->save(['mode' => 'off', 'password_hash' => null, 'session_secret' => bin2hex(random_bytes(32))]);
    }

    /**
     * Passwort der Installation prüfen (ohne Namen), mit Sperre nach
     * wiederholten Fehlversuchen — derselbe Weg wie checkLogin().
     *
     * @return 'ok'|'wrong'|'locked'
     */
    public function checkPassword(string $password, string $client = ''): string
    {
        return $this->checkLogin('', $password, $client)['status'];
    }

    private function sessionSecret(): string
    {
        $secret = (string)($this->data()['session_secret'] ?? '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $this->save(['session_secret' => $secret]);
        }
        return $secret;
    }

    /**
     * Neuer Sitzungswert für das Cookie: <ablauf>.<benutzer>.<hmac>.
     * v3.2.0 (F1023) — mit der Benutzerkennung; ohne Kennung die alte Form
     * <ablauf>.<hmac>, die für den Verwalter steht.
     */
    public function issueSession(?string $userId = null): string
    {
        $exp = (string)(time() + self::SESSION_TTL);
        if ($userId === null) {
            return $exp . '.' . hash_hmac('sha256', 'et-session|' . $exp, $this->sessionSecret());
        }
        $user = $this->userById($userId);
        return $exp . '.' . $userId . '.' . hash_hmac('sha256', self::sessionPayload($exp, $userId, $user === null ? 0 : self::epochOf($user)), $this->sessionSecret());
    }

    /**
     * v3.2.0 — Was signiert wird. Die Sitzungs-Epoche einer Person steigt mit
     * jedem neuen Passwort; ältere Cookies passen dann nicht mehr. Epoche 0
     * (nie geändert) signiert wie bisher.
     */
    private static function sessionPayload(string $exp, string $userId, int $epoch): string
    {
        return 'et-session|' . $exp . '|' . $userId . ($epoch > 0 ? '|' . $epoch : '');
    }

    /** @param array<string,mixed> $user */
    private static function epochOf(array $user): int
    {
        return max(0, (int)($user['session_epoch'] ?? 0));
    }

    public function sessionTtl(): int
    {
        return self::SESSION_TTL;
    }

    public function validSession(?string $value): bool
    {
        return $this->sessionUserId($value) !== null;
    }

    /**
     * v3.2.0 (F1023) — Benutzer einer gültigen Sitzung, sonst null. Ein Cookie
     * der alten Form (bis v3.1) gehört dem Verwalter; die Sitzung eines
     * gelöschten Benutzers ist ungültig.
     */
    public function sessionUserId(?string $value): ?string
    {
        if ($value === null) return null;
        $secret = (string)($this->data()['session_secret'] ?? '');
        if ($secret === '') return null;
        if (preg_match('/^(\d{9,12})\.([0-9a-f]{64})$/', $value, $m)) {
            if ((int)$m[1] < time() || !hash_equals(hash_hmac('sha256', 'et-session|' . $m[1], $secret), $m[2])) return null;
            // Ohne Personen und ohne Passwort (z. B. Proxy-Betrieb) steht die
            // alte Form weiter für die Installation; sonst nur, solange es den
            // alten Verwalter gibt
            if ($this->storedUsers() === [] && $this->passwordHash() === null) return self::LEGACY_ADMIN;
            // … und nur, bis er sein Passwort ändert
            $admin = $this->userById(self::LEGACY_ADMIN);
            return $admin !== null && self::epochOf($admin) === 0 ? self::LEGACY_ADMIN : null;
        }
        if (!preg_match('/^(\d{9,12})\.(u_[0-9a-z]{1,32})\.([0-9a-f]{64})$/', $value, $m)) return null;
        if ((int)$m[1] < time()) return null;
        $user = $this->userById($m[2]);
        if ($user === null) return null;
        if (!hash_equals(hash_hmac('sha256', self::sessionPayload($m[1], $m[2], self::epochOf($user)), $secret), $m[3])) return null;
        return $m[2];
    }

    /**
     * v3.4.0 (N1020) — gleitende Verlängerung: Ist eine gültige Sitzung älter
     * als SESSION_RENEW_AFTER, ein neuer Wert derselben Form mit voller Dauer
     * (30 Tage ab jetzt), sonst null. Wer die App nutzt, bleibt angemeldet;
     * ein liegengebliebenes Cookie läuft 30 Tage nach dem letzten Gebrauch ab.
     */
    public function renewedSession(?string $value): ?string
    {
        if ($value === null || !preg_match('/^(\d{9,12})\./', $value, $m)) return null;
        if ((int)$m[1] - time() > self::SESSION_TTL - self::SESSION_RENEW_AFTER) return null;
        $user = $this->sessionUserId($value);
        if ($user === null) return null;
        return preg_match('/^\d{9,12}\.[0-9a-f]{64}$/', $value) ? $this->issueSession() : $this->issueSession($user);
    }

    /**
     * v3.4.0 (N1020) — „Überall abmelden": Die Sitzungs-Epoche der Person
     * steigt, alle ihre Sitzungen enden — auf allen Geräten, auch auf diesem.
     * API-Schlüssel bleiben gültig. Ohne Personen und ohne Passwort (Proxy-
     * Betrieb mit altem Cookie) gilt ein neues Sitzungsgeheimnis.
     */
    public function logoutEverywhere(string $userId): void
    {
        $users = $this->materialize();
        $i = array_search($userId, array_column($users, 'id'), true);
        if ($i === false) {
            $this->save(['session_secret' => bin2hex(random_bytes(32))]);
            return;
        }
        $users[$i]['session_epoch'] = self::epochOf($users[$i]) + 1;
        $this->save(['users' => $users]);
    }

    // ── Benutzer im Haushalt (v3.2.0, F1023) ────────────────────────────
    //
    // Mehrere Personen mit eigener Anmeldung und eigenen Einstellungen
    // (Nutzungsstufe, Sprache). Die Daten des Haushalts teilen sich alle.
    // Verwalter (`admin`) ändern den Zugriff und spielen Backups ein,
    // Mitglieder (`member`) erfassen und sehen alles. Benutzer stehen wie das
    // Passwort in auth.json — nicht im Backup, kein Schemaschritt.
    //
    // Übergang: Bis v3.1 gab es genau ein Passwort. Es gehört dem Verwalter
    // `u_admin` (Name „admin“), der ohne eigenen Hash das bisherige Passwort
    // (oder ET_ADMIN_PASSWORD_HASH) benutzt. Solange niemand Benutzer anlegt,
    // steht er nur virtuell in der Liste; die Anmeldung ohne Namen bleibt.

    public const ROLES = ['admin', 'member'];
    public const LEGACY_ADMIN = 'u_admin';
    private const NAME_MAX = 40;

    /** @return list<array<string,mixed>> gespeicherte Benutzer (mit Hash) */
    private function storedUsers(): array
    {
        return array_values(array_filter((array)($this->data()['users'] ?? []), 'is_array'));
    }

    /** @return list<array<string,mixed>> Benutzer samt virtuellem Verwalter (mit Hash) */
    private function allUsers(): array
    {
        $users = $this->storedUsers();
        if ($users === [] && $this->passwordHash() !== null) {
            $users = [['id' => self::LEGACY_ADMIN, 'name' => 'admin', 'role' => 'admin', 'source' => 'password',
                       'prefs' => [], 'created_at' => (string)($this->data()['created_at'] ?? '')]];
        }
        return $users;
    }

    /** Den virtuellen Verwalter fest eintragen, bevor die Liste geändert wird. */
    private function materialize(): array
    {
        $stored = $this->storedUsers();
        if ($stored === []) $stored = $this->allUsers();
        return $stored;
    }

    /** @param array<string,mixed> $u */
    public static function publicUser(array $u): array
    {
        return [
            'id'         => (string)$u['id'],
            'name'       => (string)($u['name'] ?? ''),
            'role'       => in_array($u['role'] ?? '', self::ROLES, true) ? (string)$u['role'] : 'member',
            'source'     => (string)($u['source'] ?? 'password'),
            'prefs'      => (object)array_filter((array)($u['prefs'] ?? []), fn($v) => $v !== null && $v !== ''),
            'created_at' => (string)($u['created_at'] ?? ''),
        ];
    }

    /** @return list<array<string,mixed>> ohne Passwort-Hash */
    public function users(): array
    {
        return array_map([self::class, 'publicUser'], $this->allUsers());
    }

    /** Gibt es angelegte Personen (dann fragt die Anmeldung nach dem Namen)? */
    public function hasNamedUsers(): bool
    {
        foreach ($this->storedUsers() as $u) {
            if (($u['source'] ?? 'password') === 'password' && ($u['id'] ?? '') !== self::LEGACY_ADMIN) return true;
        }
        return false;
    }

    /** @return array<string,mixed>|null mit Hash */
    public function userById(string $id): ?array
    {
        foreach ($this->allUsers() as $u) if (($u['id'] ?? null) === $id) return $u;
        return null;
    }

    /** @return array<string,mixed>|null mit Hash; Name ohne Rücksicht auf Groß/klein */
    private function userByName(string $name, ?string $source = null): ?array
    {
        $key = mb_strtolower(trim($name));
        foreach ($this->allUsers() as $u) {
            if (mb_strtolower((string)($u['name'] ?? '')) !== $key) continue;
            if ($source !== null && ($u['source'] ?? 'password') !== $source) continue;
            return $u;
        }
        return null;
    }

    private static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > self::NAME_MAX || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new \InvalidArgumentException('name_invalid');
        }
        return $name;
    }

    /** @param list<array<string,mixed>> $users */
    private static function adminCount(array $users): int
    {
        return count(array_filter($users, fn($u) => ($u['role'] ?? '') === 'admin'));
    }

    /**
     * Person anlegen (Anmeldung mit Passwort).
     *
     * @return array<string,mixed> öffentlich
     * @throws \InvalidArgumentException name_invalid | name_taken | password_too_short | role_invalid
     */
    public function createUser(string $name, string $password, string $role): array
    {
        $name = self::cleanName($name);
        if (!in_array($role, self::ROLES, true)) throw new \InvalidArgumentException('role_invalid');
        if (mb_strlen($password) < self::MIN_PASSWORD) throw new \InvalidArgumentException('password_too_short');
        if ($this->userByName($name) !== null) throw new \InvalidArgumentException('name_taken');
        $users = $this->materialize();
        $user = ['id' => 'u_' . bin2hex(random_bytes(4)), 'name' => $name, 'role' => $role, 'source' => 'password',
                 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'prefs' => [], 'created_at' => date('c')];
        $users[] = $user;
        $this->save(['users' => $users]);
        return self::publicUser($user);
    }

    /**
     * Name, Rolle oder Passwort ändern. Der letzte Verwalter bleibt Verwalter.
     *
     * @param array{name?:string, role?:string, password?:string} $patch
     * @return array<string,mixed> öffentlich
     */
    public function updateUser(string $id, array $patch): array
    {
        $users = $this->materialize();
        $i = array_search($id, array_column($users, 'id'), true);
        if ($i === false) throw new \InvalidArgumentException('not_found');
        if (array_key_exists('name', $patch)) {
            $name = self::cleanName((string)$patch['name']);
            $other = $this->userByName($name);
            if ($other !== null && $other['id'] !== $id) throw new \InvalidArgumentException('name_taken');
            $users[$i]['name'] = $name;
        }
        if (array_key_exists('role', $patch)) {
            $role = (string)$patch['role'];
            if (!in_array($role, self::ROLES, true)) throw new \InvalidArgumentException('role_invalid');
            if ($role !== 'admin' && ($users[$i]['role'] ?? '') === 'admin' && self::adminCount($users) <= 1) {
                throw new \InvalidArgumentException('last_admin');
            }
            $users[$i]['role'] = $role;
        }
        if (array_key_exists('password', $patch)) {
            if (mb_strlen((string)$patch['password']) < self::MIN_PASSWORD) throw new \InvalidArgumentException('password_too_short');
            if ($id === self::LEGACY_ADMIN && $this->passwordFixedByEnv()) throw new \InvalidArgumentException('password_fixed');
            if ($id === self::LEGACY_ADMIN) {
                // Der Verwalter aus der Zeit vor v3.2 benutzt das Passwort der Installation
                $this->save(['password_hash' => password_hash((string)$patch['password'], PASSWORD_DEFAULT)]);
            } else {
                $users[$i]['password_hash'] = password_hash((string)$patch['password'], PASSWORD_DEFAULT);
            }
            // Alle Sitzungen dieser Person enden (auch auf anderen Geräten)
            $users[$i]['session_epoch'] = self::epochOf($users[$i]) + 1;
        }
        $this->save(['users' => $users]);
        return self::publicUser($users[$i]);
    }

    /** Person löschen; der letzte Verwalter bleibt. */
    public function deleteUser(string $id): void
    {
        $users = $this->materialize();
        $i = array_search($id, array_column($users, 'id'), true);
        if ($i === false) throw new \InvalidArgumentException('not_found');
        if (($users[$i]['role'] ?? '') === 'admin' && self::adminCount($users) <= 1) throw new \InvalidArgumentException('last_admin');
        if ($id === self::LEGACY_ADMIN && $this->passwordFixedByEnv()) throw new \InvalidArgumentException('password_fixed');
        array_splice($users, $i, 1);
        $patch = ['users' => $users];
        // Ohne den alten Verwalter gilt auch die Anmeldung ohne Namen nicht mehr
        if ($id === self::LEGACY_ADMIN) $patch['password_hash'] = null;
        $this->save($patch);
    }

    /**
     * Eigene Einstellungen einer Person (Nutzungsstufe, Sprache); null löscht.
     * Die Werte prüft der Aufrufer (Controller kennt Stufen und Sprachen).
     *
     * @param array<string,mixed> $prefs
     * @return array<string,mixed> öffentlich
     */
    public function setPrefs(string $id, array $prefs): array
    {
        $users = $this->materialize();
        $i = array_search($id, array_column($users, 'id'), true);
        if ($i === false) throw new \InvalidArgumentException('not_found');
        $current = (array)($users[$i]['prefs'] ?? []);
        foreach ($prefs as $k => $v) {
            if ($v === null || $v === '') unset($current[$k]);
            else $current[$k] = $v;
        }
        $users[$i]['prefs'] = $current;
        $this->save(['users' => $users]);
        return self::publicUser($users[$i]);
    }

    /**
     * Anmeldung mit Name und Passwort. Ohne Namen gilt das Passwort der
     * Installation (der Verwalter aus der Zeit vor v3.2).
     *
     * v3.4.0 (N1020) — Sperre je Gerät (`$client`, s. clientIp()), je Name und
     * als Notbremse für alle; Laufzeitausgleich bei unbekanntem Namen.
     *
     * @return array{status:'ok'|'wrong'|'locked', user?:string}
     */
    public function checkLogin(string $name, string $password, string $client = ''): array
    {
        $buckets = ['ip:' . ($client !== '' ? $client : '-'), 'name:' . mb_strtolower(trim($name)), 'all'];
        $throttle = $this->throttle();
        $now = time();
        foreach ($buckets as $b) {
            if ((int)($throttle[$b]['locked_until'] ?? 0) > $now) return ['status' => 'locked'];
        }
        $user = trim($name) === '' ? $this->userById(self::LEGACY_ADMIN) : $this->userByName($name, 'password');
        $hash = $user === null ? null
            : (isset($user['password_hash']) && $user['password_hash'] !== '' ? (string)$user['password_hash']
                : ($user['id'] === self::LEGACY_ADMIN ? $this->passwordHash() : null));
        if ($hash === null) {
            // gleiche Arbeit wie bei einem bekannten Namen, Ergebnis ohne Bedeutung
            password_verify($password, $this->passwordHash() ?? self::DUMMY_HASH);
        } elseif (password_verify($password, $hash)) {
            if (isset($throttle[$buckets[0]]) || isset($throttle[$buckets[1]]) || array_key_exists('login_failures', $this->data())) {
                unset($throttle[$buckets[0]], $throttle[$buckets[1]]);
                $this->save(['login_throttle' => $throttle, 'login_failures' => null]);
            }
            return ['status' => 'ok', 'user' => (string)$user['id']];
        }
        $locked = false;
        foreach ($buckets as $b) {
            $kind = explode(':', $b, 2)[0];
            $e = (array)($throttle[$b] ?? []);
            $first = (int)($e['first_at'] ?? 0);
            $count = $first > $now - self::FAILURE_WINDOW ? (int)($e['count'] ?? 0) + 1 : 1;
            $hit = $count >= self::LIMITS[$kind];
            $throttle[$b] = ['count' => $count, 'first_at' => $count === 1 ? $now : $first,
                             'locked_until' => $hit ? $now + self::LOCK_SECONDS : 0];
            $locked = $locked || $hit;
        }
        $this->save(['login_throttle' => self::prune($throttle, $now), 'login_failures' => null]);
        return ['status' => $locked ? 'locked' : 'wrong'];
    }

    /** @return array<string,array{count:int,first_at:int,locked_until:int}> */
    private function throttle(): array
    {
        $t = $this->data()['login_throttle'] ?? [];
        return is_array($t) ? array_filter($t, 'is_array') : [];
    }

    /**
     * Abgelaufene Zähler weg, höchstens THROTTLE_MAX_ENTRIES (die jüngsten).
     *
     * @param array<string,array<string,int>> $t
     * @return array<string,array<string,int>>
     */
    private static function prune(array $t, int $now): array
    {
        $t = array_filter($t, fn($e) => (int)($e['locked_until'] ?? 0) > $now
            || (int)($e['first_at'] ?? 0) > $now - self::FAILURE_WINDOW);
        if (count($t) > self::THROTTLE_MAX_ENTRIES) {
            uasort($t, fn($a, $b) => (int)($b['first_at'] ?? 0) <=> (int)($a['first_at'] ?? 0));
            $t = array_slice($t, 0, self::THROTTLE_MAX_ENTRIES, true);
        }
        return $t;
    }

    /**
     * v3.4.0 (N1020) — Adresse des Geräts für die Sperre: REMOTE_ADDR; kommt
     * die Anfrage von einem vertrauenswürdigen Proxy (`ET_TRUSTED_PROXIES`),
     * die letzte Adresse in `X-Forwarded-For`, die nicht selbst ein solcher
     * Proxy ist. Ohne diese Regel teilten sich hinter einem Reverse-Proxy alle
     * Geräte eine Sperre.
     */
    public static function clientIp(array $server): string
    {
        $remote = (string)($server['REMOTE_ADDR'] ?? '');
        $trusted = self::trustedProxies();
        if ($trusted === [] || !self::ipInList($remote, $trusted)) return $remote;
        $chain = array_reverse(array_filter(array_map('trim', explode(',', (string)($server['HTTP_X_FORWARDED_FOR'] ?? '')))));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) break;
            if (!self::ipInList($ip, $trusted)) return $ip;
        }
        return $remote;
    }

    /** @return list<string> Adressen und Netze aus ET_TRUSTED_PROXIES */
    public static function trustedProxies(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)getenv('ET_TRUSTED_PROXIES')))));
    }

    /** Kommt die Anfrage von einem vertrauenswürdigen Proxy? */
    public static function fromTrustedProxy(array $server): bool
    {
        $trusted = self::trustedProxies();
        return $trusted !== [] && self::ipInList((string)($server['REMOTE_ADDR'] ?? ''), $trusted);
    }

    /**
     * Proxy-Modus: die Person zum gemeldeten Namen; beim ersten Mal wird sie
     * angelegt — als Verwalter, solange es keinen gibt, sonst als Mitglied.
     *
     * v3.4.0 (N1020) — mit `$externalId` (Home Assistant: X-Remote-User-Id)
     * gilt die Kennung, nicht der Name: Eine Person bleibt dieselbe, auch wenn
     * sie in Home Assistant umbenannt wird, und zwei Personen mit gleichem
     * Anzeigenamen bleiben zwei. Wer schon vor v3.4 über den Namen angelegt
     * wurde, bekommt beim ersten Mal die Kennung dazu.
     *
     * @return array<string,mixed> mit Hash
     */
    public function proxyUserRecord(string $name, ?string $externalId = null): array
    {
        $name = mb_substr(trim($name), 0, self::NAME_MAX);
        if ($externalId !== null && $externalId !== '') {
            foreach ($this->allUsers() as $u) {
                if (($u['source'] ?? '') === 'proxy' && ($u['proxy_id'] ?? null) === $externalId) return $u;
            }
            $byName = $this->userByName($name, 'proxy');
            if ($byName !== null && !isset($byName['proxy_id'])) {
                $users = $this->materialize();
                foreach ($users as $i => $u) if (($u['id'] ?? null) === $byName['id']) $users[$i]['proxy_id'] = $externalId;
                try { $this->save(['users' => $users]); } catch (\Throwable) { /* nur lesend erreichbar */ }
                return $byName + ['proxy_id' => $externalId];
            }
        } else {
            $found = $this->userByName($name, 'proxy');
            if ($found !== null) return $found;
        }
        // materialize: ein altes Passwort (Verwalter u_admin) bleibt erhalten,
        // zählt aber nicht als Verwalter des Proxy-Betriebs
        $users = $this->materialize();
        $proxyAdmins = array_filter($users, fn($u) => ($u['role'] ?? '') === 'admin' && ($u['source'] ?? '') === 'proxy');
        $user = ['id' => 'u_' . bin2hex(random_bytes(4)), 'name' => $name,
                 'role' => $proxyAdmins === [] ? 'admin' : 'member',
                 'source' => 'proxy', 'prefs' => [], 'created_at' => date('c')];
        if ($externalId !== null && $externalId !== '') $user['proxy_id'] = mb_substr($externalId, 0, 200);
        $users[] = $user;
        try { $this->save(['users' => $users]); } catch (\Throwable) { /* nur lesend erreichbar: gilt für diese Anfrage */ }
        return $user;
    }

    /**
     * Proxy-Modus: angemeldeter Benutzer — nur, wenn die Anfrage von einer
     * Adresse aus `ET_TRUSTED_PROXIES` kommt. Sonst könnte jeder den Header
     * selbst setzen. Liefert den Namen; Einzelheiten in proxyIdentity().
     */
    public function proxyUser(array $server): ?string
    {
        return $this->proxyIdentity($server)['name'] ?? null;
    }

    /** Kopfzeilen, die bis v3.3 in dieser Reihenfolge galten (erste nicht leere gewinnt). */
    public const LEGACY_PROXY_HEADERS = ['Remote-User', 'X-Forwarded-User', 'X-Remote-User', 'X-Remote-User-Name', 'X-Remote-User-Id'];

    /**
     * v3.4.0 (N1020) — Welche Kopfzeile den Benutzer meldet:
     *
     *   - `configured`: `ET_PROXY_USER_HEADER` nennt genau eine Kopfzeile, nur sie
     *     zählt. Sie geht vor, weil eine ausdrückliche Einstellung nicht von
     *     Kopfzeilen der Anfrage abhängen darf.
     *   - `ingress`: Home Assistant (erkennbar an `X-Ingress-Path` oder
     *     `X-Remote-User-Id`). Es zählen nur `X-Remote-User-Id` (stabile Kennung)
     *     und `X-Remote-User-Name` — die filtert der Supervisor aus den Anfragen
     *     der Nutzer, `Remote-User` und `X-Forwarded-User` reicht er dagegen
     *     unverändert durch.
     *   - `legacy`: das Verhalten bis v3.3 (erste nicht leere Kopfzeile der
     *     Liste). Bleibt für bestehende Installationen; die Diagnose rät dann
     *     zu `ET_PROXY_USER_HEADER`, denn je nach Proxy gewinnt eine vom Nutzer
     *     selbst gesetzte Kopfzeile.
     *
     * @return 'configured'|'ingress'|'legacy'
     */
    public static function proxyHeaderMode(array $server): string
    {
        if (self::configuredProxyHeader() !== null) return 'configured';
        if (trim((string)($server['HTTP_X_INGRESS_PATH'] ?? '')) !== ''
            || trim((string)($server['HTTP_X_REMOTE_USER_ID'] ?? '')) !== '') return 'ingress';
        return 'legacy';
    }

    /** Die Kopfzeile aus `ET_PROXY_USER_HEADER` (nur gültige Namen), sonst null. */
    public static function configuredProxyHeader(): ?string
    {
        $h = trim((string)getenv('ET_PROXY_USER_HEADER'));
        return preg_match('/^[A-Za-z0-9-]{1,64}$/', $h) ? $h : null;
    }

    private static function serverKey(string $header): string
    {
        return 'HTTP_' . strtoupper(str_replace('-', '_', $header));
    }

    /**
     * Angemeldete Person vom vertrauenswürdigen Proxy.
     *
     * @return array{name:string, id:?string, via:string}|null `id`: stabile Kennung
     *         (Home Assistant), `via`: proxyHeaderMode()
     */
    public function proxyIdentity(array $server): ?array
    {
        if (!self::fromTrustedProxy($server)) return null;
        $mode = self::proxyHeaderMode($server);
        $val = fn(string $h): string => mb_substr(trim((string)($server[self::serverKey($h)] ?? '')), 0, 200);
        if ($mode === 'configured') {
            $u = $val((string)self::configuredProxyHeader());
            return $u === '' ? null : ['name' => $u, 'id' => null, 'via' => $mode];
        }
        if ($mode === 'ingress') {
            // v3.1.0 (Ökosystem G2) — Home Assistant schickt X-Remote-User-Name
            // und X-Remote-User-Id; geführt wird die Person über die Kennung
            $id = $val('X-Remote-User-Id');
            $name = $val('X-Remote-User-Name');
            if ($id === '' && $name === '') return null;
            return ['name' => $name !== '' ? $name : $id, 'id' => $id !== '' ? $id : null, 'via' => $mode];
        }
        foreach (self::LEGACY_PROXY_HEADERS as $h) {
            $u = $val($h);
            if ($u !== '') return ['name' => $u, 'id' => null, 'via' => $mode];
        }
        return null;
    }

    /** IP gegen Liste aus Einzeladressen und CIDR-Bereichen (IPv4/IPv6). */
    public static function ipInList(string $ip, array $list): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        foreach ($list as $entry) {
            [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $netBin = @inet_pton((string)$net);
            if ($netBin === false || strlen($netBin) !== strlen($bin)) continue;
            $bits = $bits === null ? strlen($bin) * 8 : (int)$bits;
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) continue;
            $rest = $bits % 8;
            if ($rest === 0) return true;
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) return true;
        }
        return false;
    }

    // ── API-Schlüssel für Skripte (v2.6.0) ──────────────────────────────

    /** @return list<array{id:string,name:string,scope:string,created_at:string,last_used_at:?string}> */
    public function apiKeys(): array
    {
        $out = [];
        foreach ((array)($this->data()['api_keys'] ?? []) as $k) {
            $out[] = [
                'id'           => (string)$k['id'],
                'name'         => (string)($k['name'] ?? ''),
                'scope'        => (string)($k['scope'] ?? 'read'),
                'created_at'   => (string)($k['created_at'] ?? ''),
                'last_used_at' => $k['last_used_at'] ?? null,
            ];
        }
        return $out;
    }

    /** @return array{id:string, key:string} Klartext nur hier */
    public function createApiKey(string $name, string $scope): array
    {
        // v3.1.0 (MKT-09) — `calendar`: nur das Kalender-Abo, nur als ?token=
        if (!in_array($scope, ['read', 'admin', 'calendar'], true)) throw new \InvalidArgumentException('scope');
        $name = trim($name) === '' ? $scope : mb_substr(trim($name), 0, 60);
        $key  = 'etk_' . bin2hex(random_bytes(24));
        $id   = 'k_' . bin2hex(random_bytes(4));
        $keys = (array)($this->data()['api_keys'] ?? []);
        $keys[] = ['id' => $id, 'name' => $name, 'scope' => $scope, 'hash' => hash('sha256', $key),
                   'created_at' => date('c'), 'last_used_at' => null];
        $this->save(['api_keys' => array_values($keys)]);
        return ['id' => $id, 'key' => $key];
    }

    public function revokeApiKey(string $id): bool
    {
        $keys = (array)($this->data()['api_keys'] ?? []);
        $kept = array_values(array_filter($keys, fn($k) => ($k['id'] ?? null) !== $id));
        if (count($kept) === count($keys)) return false;
        $this->save(['api_keys' => $kept]);
        return true;
    }

    /** v3.4.0 (F1024) — Name eines gültigen API-Schlüssels (für den Verlauf), sonst null. */
    public function apiKeyName(?string $key): ?string
    {
        if ($key === null || !str_starts_with($key, 'etk_')) return null;
        $hash = hash('sha256', $key);
        foreach ((array)($this->data()['api_keys'] ?? []) as $k) {
            if (hash_equals((string)($k['hash'] ?? ''), $hash)) return (string)($k['name'] ?? '');
        }
        return null;
    }

    /** Scope eines gültigen API-Schlüssels, sonst null. */
    public function apiKeyScope(?string $key): ?string
    {
        if ($key === null || !str_starts_with($key, 'etk_')) return null;
        $hash = hash('sha256', $key);
        $keys = (array)($this->data()['api_keys'] ?? []);
        foreach ($keys as $i => $k) {
            if (!hash_equals((string)($k['hash'] ?? ''), $hash)) continue;
            $last = $k['last_used_at'] ?? null;
            if ($last === null || strtotime((string)$last) < time() - 3600) {
                $keys[$i]['last_used_at'] = date('c');
                try { $this->save(['api_keys' => $keys]); } catch (\Throwable) {}
            }
            return (string)($k['scope'] ?? 'read');
        }
        return null;
    }
}
