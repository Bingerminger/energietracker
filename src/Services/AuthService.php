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
    private const MAX_FAILURES = 5;
    private const FAILURE_WINDOW = 900;
    private const LOCK_SECONDS = 300;
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
     * Passwort prüfen, mit Sperre nach wiederholten Fehlversuchen.
     *
     * @return 'ok'|'wrong'|'locked'
     */
    public function checkPassword(string $password): string
    {
        $data = $this->data();
        $fail = (array)($data['login_failures'] ?? []);
        if (($fail['locked_until'] ?? 0) > time()) return 'locked';
        $hash = $this->passwordHash();
        if ($hash !== null && password_verify($password, $hash)) {
            if ($fail !== []) $this->save(['login_failures' => null]);
            return 'ok';
        }
        $first = (int)($fail['first_at'] ?? 0);
        $count = $first > time() - self::FAILURE_WINDOW ? (int)($fail['count'] ?? 0) + 1 : 1;
        $this->save(['login_failures' => [
            'count'        => $count,
            'first_at'     => $count === 1 ? time() : $first,
            'locked_until' => $count >= self::MAX_FAILURES ? time() + self::LOCK_SECONDS : 0,
        ]]);
        return $count >= self::MAX_FAILURES ? 'locked' : 'wrong';
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

    /** Neuer Sitzungswert für das Cookie: <ablauf>.<hmac> */
    public function issueSession(): string
    {
        $exp = (string)(time() + self::SESSION_TTL);
        return $exp . '.' . hash_hmac('sha256', 'et-session|' . $exp, $this->sessionSecret());
    }

    public function sessionTtl(): int
    {
        return self::SESSION_TTL;
    }

    public function validSession(?string $value): bool
    {
        if ($value === null || !preg_match('/^(\d{9,12})\.([0-9a-f]{64})$/', $value, $m)) return false;
        if ((int)$m[1] < time()) return false;
        $secret = (string)($this->data()['session_secret'] ?? '');
        if ($secret === '') return false;
        return hash_equals(hash_hmac('sha256', 'et-session|' . $m[1], $secret), $m[2]);
    }

    /**
     * Proxy-Modus: angemeldeter Benutzer aus `Remote-User`/`X-Forwarded-User`
     * — nur, wenn die Anfrage von einer Adresse aus `ET_TRUSTED_PROXIES` kommt.
     * Sonst könnte jeder den Header selbst setzen.
     */
    public function proxyUser(array $server): ?string
    {
        $trusted = array_filter(array_map('trim', explode(',', (string)getenv('ET_TRUSTED_PROXIES'))));
        $remote = (string)($server['REMOTE_ADDR'] ?? '');
        if ($trusted === [] || !self::ipInList($remote, $trusted)) return null;
        foreach (['HTTP_REMOTE_USER', 'HTTP_X_FORWARDED_USER', 'HTTP_X_REMOTE_USER'] as $h) {
            $u = trim((string)($server[$h] ?? ''));
            if ($u !== '') return $u;
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
        if (!in_array($scope, ['read', 'admin'], true)) throw new \InvalidArgumentException('scope');
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
