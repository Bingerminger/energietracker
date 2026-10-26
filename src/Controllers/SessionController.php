<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\AuthService;
use Energietracker\Services\I18nService;
use Energietracker\Services\PeopleService;
use Energietracker\Services\SettingsService;

/**
 * v2.6.0 — Anmeldung (opt-in) und API-Schlüssel.
 *
 * Routen:
 *   GET    /api/session            → Modus, angemeldet?, fest per Umgebung?
 *                                    v3.2.0: `user` (Person dieser Sitzung),
 *                                    `named_login` (Anmeldung fragt nach dem Namen)
 *   POST   /api/session            → anmelden {name?, password}; setzt das Cookie
 *   DELETE /api/session            → abmelden
 *   POST   /api/session/logout-everywhere → alle Sitzungen der Person beenden (v3.4.0)
 *   POST   /api/session/password   → Passwort setzen/ändern {current?, password}
 *                                    (setzen schaltet die Anmeldung ein)
 *   DELETE /api/session/password   → Anmeldung ausschalten {current}
 *   GET    /api/auth/keys          → API-Schlüssel (ohne Klartext)
 *   POST   /api/auth/keys          → {name, scope: read|admin}; Klartext einmalig
 *   DELETE /api/auth/keys/{id}
 *
 * v3.2.0 (F1023) — Benutzer im Haushalt:
 *   PATCH  /api/session/me           → eigene Einstellungen {ui_level?, language?}
 *   POST   /api/session/me/password  → eigenes Passwort {current, password}
 *   GET    /api/users                → Personen (nur Verwalter)
 *   POST   /api/users                → {name, password, role}; v3.9.0: {name, role?,
 *                                    has_account: false} legt eine Person ohne Zugang an
 *   PATCH  /api/users/{id}           → {name?, role?, password?}
 *   DELETE /api/users/{id}           v3.4.0 (F1024): `?anonymize_activity=1` ersetzt
 *                                    den Namen im Verlauf durch „ehemalige Person"
 *                                    (v3.5.0: auch als Zuständige an Terminen)
 *   GET    /api/people               v3.5.0 (F1028) — nur ID und Name, für jede Rolle
 *
 * v3.6.0 — Personenverzeichnis (PeopleService): `GET /api/users` trägt die
 * fortlaufende Nummer `no`; `GET /api/people` liest das Verzeichnis (auch ohne
 * Anmeldung, etwa die Personen eines Beispielhaushalts).
 *   GET    /api/users/{id}/handover  → was beim Löschen übergeht: {person, to,
 *                                    open, done} (nur Verwalter, wie alle /api/users)
 *   DELETE /api/users/{id}           offene Termine gehen an die löschende Person,
 *                                    Erledigtes behält den Namen mit „(gelöscht)“;
 *                                    Antwort additiv: handed_over, handed_over_to, kept
 * v3.7.0 — Zuständigkeit an Zählern und Verträgen geht beim Löschen mit über:
 * Vorschau additiv `meters`, `contracts`; Antwort `records_handed_over`,
 * `records_kept` (ResponsibilityService::personDeleted).
 * Personen ohne Zugang (Backup, Beispielhaushalt) stehen hinter den Zugängen in
 * `GET /api/users` (`has_account: false`, `source: "none"`); Vorschau und
 * Löschen gelten auch für sie, `PATCH /api/users/{id}` ändert Name und Rolle
 * (ein Passwort: 400 errors.users.noAccountPassword). Ohne Anmeldung gilt wie
 * überall: jede Anfrage darf, was ein Verwalter darf — nur übernimmt beim
 * Löschen niemand.
 */
final class SessionController
{
    /**
     * @param callable(): bool $authenticated Stand dieser Anfrage (App::authenticate)
     * @param (callable(): ?array)|null $currentUser Person dieser Anfrage
     * @param (callable(): string)|null $role admin | member
     */
    /**
     * @param (callable(string): void)|null $anonymizeActivity v3.4.0 (F1024) — Name einer
     *        gelöschten Person im Verlauf durch „ehemalige Person" ersetzen
     * @param (callable(string, bool, ?array, string): array)|null $personDeleted v3.5.0 (F1028) — eine
     *        Person ist gelöscht (ID, Name ersetzen?, v3.6.0: an wen, ihr Name): Termine übergeben
     * @param (callable(string): int)|null $markActivity v3.6.0 — Verlauf: Name bleibt mit „(gelöscht)“
     * @param (callable(string, string): array{open:int, done:int})|null $handoverPreview v3.6.0 —
     *        was beim Löschen übergeht (ID, Name)
     */
    public function __construct(
        private AuthService $auth,
        private I18nService $i18n,
        private $authenticated,
        private $currentUser = null,
        private $role = null,
        private $anonymizeActivity = null,
        private $personDeleted = null,
        private ?PeopleService $people = null,
        private $markActivity = null,
        private $handoverPreview = null,
    ) {}

    /**
     * v3.5.0 (F1028) — GET /api/people: Personen für die Auswahl „Zuständig“,
     * nur ID und Name (für jede angemeldete Rolle; die volle Liste mit Rollen
     * bleibt `GET /api/users` für Verwalter).
     *
     * v3.6.0 — aus dem Personenverzeichnis, ohne gelöschte Personen; auch ohne
     * Anmeldung (bis v3.5 dann leer): Die Personen eines Beispielhaushalts oder
     * aus einem Backup stehen so zur Wahl.
     */
    public function people(Request $req): never
    {
        if ($this->people === null) Response::json($this->auth->people());
        Response::json(array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], $this->people->active()));
    }

    /**
     * v3.6.0 — GET /api/users/{id}/handover: Vorschau für den Dialog „Person
     * löschen“ — wie viele offene Termine an wen übergehen und wie viele
     * erledigte Einträge den Namen mit „(gelöscht)“ behalten.
     */
    public function handover(Request $req): never
    {
        $id = (string)$req->param('id');
        [$name, $hasAccount] = $this->deletable($id);
        $counts = $this->handoverPreview === null ? ['open' => 0, 'done' => 0] : ($this->handoverPreview)($id, $name);
        Response::json([
            'person' => ['id' => $id, 'name' => $name, 'no' => $this->people?->find($id)['no'] ?? null, 'has_account' => $hasAccount],
            'to'     => $this->recipient($id),
            'open'   => (int)$counts['open'],
            'done'   => (int)$counts['done'],
            // v3.7.0 — Zähler und Verträge, für die die Person zuständig ist
            'meters'    => (int)($counts['meters'] ?? 0),
            'contracts' => (int)($counts['contracts'] ?? 0),
        ]);
    }

    /**
     * v3.6.0 — Name einer Person, die sich löschen lässt, und ob sie einen
     * Zugang hat: ein Zugang aus auth.json oder eine Person ohne Zugang aus dem
     * Verzeichnis (Backup, Beispielhaushalt), die noch nicht gelöscht ist. Sonst 404.
     *
     * @return array{0:string, 1:bool}
     */
    private function deletable(string $id): array
    {
        $u = $this->auth->userById($id);
        if ($u !== null) return [(string)($u['name'] ?? ''), true];
        $p = $this->people?->find($id);
        if ($p === null || $p['deleted']) Response::error($this->i18n->t('errors.users.notFound'), 404, null, 'errors.users.notFound');
        return [$p['name'], false];
    }

    /** Wer offene Termine übernimmt: die löschende Person (mit einem API-Schlüssel niemand). */
    private function recipient(string $deletedId): ?array
    {
        $me = $this->user();
        if ($me === null || ($me['id'] ?? '') === '' || $me['id'] === $deletedId) return null;
        return ['id' => (string)$me['id'], 'name' => (string)($me['name'] ?? '')];
    }

    private function user(): ?array
    {
        return $this->currentUser === null ? null : ($this->currentUser)();
    }

    public function status(Request $req): never
    {
        $authenticated = ($this->authenticated)();
        Response::json([
            'mode'           => $this->auth->mode(),
            'authenticated'  => $authenticated,
            'mode_fixed'     => $this->auth->modeFixedByEnv(),
            'password_fixed' => $this->auth->passwordFixedByEnv(),
            'has_password'   => $this->auth->hasPassword(),
            // v3.2.0 (F1023) — additiv
            'named_login'    => $this->auth->mode() === 'password' && $this->auth->hasNamedUsers(),
            'user'           => $authenticated ? $this->user() : null,
            'role'           => $authenticated ? ($this->role === null ? 'admin' : ($this->role)()) : null,
        ]);
    }

    public function login(Request $req): never
    {
        if ($this->auth->mode() !== 'password') {
            Response::error($this->i18n->t('errors.auth.notPasswordMode'), 400);
        }
        $r = $this->auth->checkLogin((string)$req->input('name', ''), (string)$req->input('password', ''), self::client());
        $this->failOn($r['status']);
        self::cookie($this->auth->issueSession($r['user']), $this->auth->sessionTtl());
        Response::json(['authenticated' => true]);
    }

    public function logout(Request $req): never
    {
        self::cookie('', -1);
        Response::json(['authenticated' => false]);
    }

    /**
     * v3.4.0 (N1020) — POST /api/session/logout-everywhere: alle Sitzungen der
     * angemeldeten Person enden, auf jedem Gerät (auch diesem). Abmelden allein
     * löscht nur das Cookie dieses Browsers; ein kopiertes Cookie galt weiter.
     */
    public function logoutEverywhere(Request $req): never
    {
        $me = $this->user() ?? Response::error($this->i18n->t('errors.users.noUser'), 400, null, 'errors.users.noUser');
        $this->auth->logoutEverywhere((string)$me['id']);
        self::cookie('', -1);
        Response::json(['authenticated' => false, 'logged_out_everywhere' => true]);
    }

    /** Adresse des Geräts für die Sperre nach Fehlversuchen (v3.4.0). */
    private static function client(): string
    {
        return AuthService::clientIp($_SERVER);
    }

    public function setPassword(Request $req): never
    {
        if ($this->auth->passwordFixedByEnv()) {
            Response::error($this->i18n->t('errors.auth.passwordFixed'), 409);
        }
        // Ändern verlangt das bisherige Passwort — ein gestohlenes Cookie reicht nicht.
        if ($this->auth->mode() === 'password' && $this->auth->hasPassword()) {
            $this->checkCurrent((string)$req->input('current', ''));
        }
        try {
            $this->auth->setPassword((string)$req->input('password', ''));
        } catch (\InvalidArgumentException) {
            Response::error($this->i18n->t('errors.auth.passwordTooShort', ['min' => 8]), 400);
        }
        // Wer das Passwort gerade gesetzt hat, bleibt angemeldet.
        if ($this->auth->mode() === 'password') {
            $me = $this->user();
            self::cookie($this->auth->issueSession($me['id'] ?? null), $this->auth->sessionTtl());
        }
        Response::json(['mode' => $this->auth->mode()]);
    }

    public function disable(Request $req): never
    {
        if ($this->auth->modeFixedByEnv()) {
            Response::error($this->i18n->t('errors.auth.modeFixed'), 409);
        }
        if ($this->auth->mode() === 'password') {
            $this->checkCurrent((string)$req->input('current', ''));
        }
        $this->auth->disableLogin();
        self::cookie('', -1);
        Response::json(['mode' => $this->auth->mode()]);
    }

    public function listKeys(Request $req): never
    {
        Response::json($this->auth->apiKeys());
    }

    public function createKey(Request $req): never
    {
        $scope = (string)$req->input('scope', 'read');
        // v3.1.0 — calendar: Kalender-Abo; v3.7.0 (Paket betrieb) — inbox: nur PDFs ablegen
        if (!in_array($scope, \Energietracker\Services\AuthService::API_KEY_SCOPES, true)) {
            Response::error($this->i18n->t('errors.auth.scopeInvalid'), 400);
        }
        $created = $this->auth->createApiKey((string)$req->input('name', ''), $scope);
        Response::json($created + ['hint' => $this->i18n->t('auth.tokenOnce')], 201);
    }

    public function revokeKey(Request $req): never
    {
        if (!$this->auth->revokeApiKey((string)$req->param('id'))) {
            Response::error($this->i18n->t('errors.auth.keyNotFound'), 404);
        }
        Response::json(['revoked' => true]);
    }

    // ── v3.2.0 (F1023) — Benutzer im Haushalt ────────────────────────────

    /** Eigene Einstellungen: Nutzungsstufe und Sprache (null = wie die Installation). */
    public function updateMe(Request $req): never
    {
        $me = $this->user() ?? Response::error($this->i18n->t('errors.users.noUser'), 400, null, 'errors.users.noUser');
        $prefs = [];
        foreach (['ui_level', 'language'] as $k) {
            if (!is_array($req->body) || !array_key_exists($k, $req->body)) continue;
            $v = $req->body[$k];
            $ok = $v === null || $v === ''
                || ($k === 'ui_level' && in_array($v, SettingsService::UI_LEVELS, true))
                || ($k === 'language' && is_string($v) && in_array($v, $this->i18n->supported(), true));
            if (!$ok) {
                Response::error($this->i18n->t('errors.settings.valueInvalid', ['key' => $k, 'value' => is_scalar($v) ? (string)$v : gettype($v)]),
                    400, null, 'errors.settings.valueInvalid');
            }
            $prefs[$k] = $v;
        }
        Response::json($this->auth->setPrefs((string)$me['id'], $prefs));
    }

    /** Eigenes Passwort ändern — mit dem bisherigen. */
    public function changeMyPassword(Request $req): never
    {
        $me = $this->user() ?? Response::error($this->i18n->t('errors.users.noUser'), 400, null, 'errors.users.noUser');
        if (($me['source'] ?? '') !== 'password') {
            Response::error($this->i18n->t('errors.users.proxyNoPassword'), 400, null, 'errors.users.proxyNoPassword');
        }
        $name = $me['id'] === AuthService::LEGACY_ADMIN ? '' : (string)$me['name'];
        $this->failOn($this->auth->checkLogin($name, (string)$req->input('current', ''), self::client())['status']);
        $this->userCall(fn() => $this->auth->updateUser((string)$me['id'], ['password' => (string)$req->input('password', '')]));
        self::cookie($this->auth->issueSession((string)$me['id']), $this->auth->sessionTtl());
        Response::json(['changed' => true]);
    }

    public function listUsers(Request $req): never
    {
        // v3.6.0 — mit der fortlaufenden Nummer aus dem Personenverzeichnis (additiv);
        // dahinter die Personen ohne Zugang (Backup, Beispielhaushalt): `has_account: false`
        $register = $this->people?->all()['people'] ?? [];
        $no = array_column($register, 'no', 'id');
        $users = array_map(fn($u) => $u + ['no' => $no[$u['id']] ?? null, 'has_account' => true], $this->auth->users());
        $accounts = array_flip(array_column($users, 'id'));
        foreach ($register as $p) {
            if ($p['deleted_at'] !== null || $p['name'] === '' || isset($accounts[$p['id']])) continue;
            $users[] = ['id' => $p['id'], 'name' => $p['name'], 'role' => $p['role'], 'source' => 'none', 'prefs' => (object)[],
                        'created_at' => $p['created_at'], 'no' => $p['no'], 'has_account' => false];
        }
        Response::json($users);
    }

    public function createUser(Request $req): never
    {
        // v3.9.0 — `has_account: false`: eine Person ohne Zugang, nur ins Verzeichnis
        // (Name, Rolle; kein Passwort). Sie steht unter „Zuständig“ zur Wahl, auch ohne
        // Anmeldung. Rechte wie jede Route unter /api/users (App::adminOnly).
        if (is_array($req->body) && ($req->body['has_account'] ?? null) === false && $this->people !== null) {
            $p = $this->userCall(fn() => $this->people->add((string)$req->input('name', ''), (string)$req->input('role', 'member')));
            Response::json(['id' => $p['id'], 'name' => $p['name'], 'role' => $p['role'], 'source' => 'none', 'prefs' => (object)[],
                            'created_at' => $p['created_at'], 'no' => $p['no'], 'has_account' => false], 201);
        }
        $user = $this->userCall(fn() => $this->auth->createUser((string)$req->input('name', ''),
            (string)$req->input('password', ''), (string)$req->input('role', 'member')));
        // v3.6.0 — gleich ins Personenverzeichnis (Nummer in der Antwort, additiv)
        $no = $this->people?->find((string)$user['id'])['no'] ?? null;
        Response::json($user + ['no' => $no], 201);
    }

    public function updateUser(Request $req): never
    {
        $patch = [];
        foreach (['name', 'role', 'password'] as $k) {
            if (is_array($req->body) && array_key_exists($k, $req->body)) $patch[$k] = (string)$req->body[$k];
        }
        $id = (string)$req->param('id');
        // v3.6.0 — Person ohne Zugang (Backup, Beispielhaushalt): Name und Rolle im
        // Verzeichnis; ein Passwort bekommt sie nur über einen neuen Zugang
        if ($this->people !== null && $this->auth->userById($id) === null) {
            if (isset($patch['password'])) {
                Response::error($this->i18n->t('errors.users.noAccountPassword'), 400, null, 'errors.users.noAccountPassword');
            }
            $p = $this->userCall(fn() => $this->people->update($id, $patch));
            Response::json(['id' => $p['id'], 'name' => $p['name'], 'role' => $p['role'], 'source' => 'none', 'prefs' => (object)[],
                            'created_at' => $p['created_at'], 'no' => $p['no'], 'has_account' => false]);
        }
        $user = $this->userCall(fn() => $this->auth->updateUser($id, $patch));
        // Neues Passwort beendet die Sitzungen der Person — die eigene bleibt
        if (isset($patch['password']) && ($this->user()['id'] ?? null) === $id) {
            self::cookie($this->auth->issueSession($id), $this->auth->sessionTtl());
        }
        Response::json($user);
    }

    /**
     * Person löschen — nur Verwalter (App::adminOnly, sonst 403). v3.6.0:
     * Offene Termine gehen an die löschende Person, Erledigtes behält den
     * Namen mit „(gelöscht)“, das Personenverzeichnis vermerkt die Löschung
     * (die Nummer wird nie wieder vergeben).
     */
    public function deleteUser(Request $req): never
    {
        $id = (string)$req->param('id');
        if (($this->user()['id'] ?? null) === $id) {
            Response::error($this->i18n->t('errors.users.notSelf'), 400, null, 'errors.users.notSelf');
        }
        $this->people?->all();   // Bestand: erst ins Verzeichnis, dann löschen
        [$name, $hasAccount] = $this->deletable($id);
        // eine Person ohne Zugang steht nur im Verzeichnis
        if ($hasAccount) $this->userCall(fn() => $this->auth->deleteUser($id));
        $to = $this->recipient($id);
        $this->people?->markDeleted($id, $to['id'] ?? null, $name);
        // v3.4.0 (F1024) — auf Wunsch des Verwalters: im Verlauf „ehemalige Person"
        $anon = $this->anonymizeActivity !== null && $req->queryParam('anonymize_activity') === '1';
        if ($anon) ($this->anonymizeActivity)($id);
        elseif ($this->markActivity !== null) ($this->markActivity)($id);
        // v3.5.0 (F1028) — Termine; v3.6.0: offene an die löschende Person
        $moved = $this->personDeleted !== null ? ($this->personDeleted)($id, $anon, $to, $name) : null;
        Response::json(['deleted' => true, 'activity_anonymized' => $anon,
            'handed_over' => (int)($moved['handed_over'] ?? 0), 'handed_over_to' => $to, 'kept' => (int)($moved['kept'] ?? 0),
            // v3.7.0 — Zuständigkeit an Zählern und Verträgen: übergeben bzw. mit Vermerk geblieben
            'records_handed_over' => (int)($moved['records']['handed_over'] ?? 0),
            'records_kept'        => (int)($moved['records']['kept'] ?? 0)]);
    }

    /** Fehler der Benutzerverwaltung als Meldung mit stabilem Code. */
    private function userCall(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\InvalidArgumentException $e) {
            [$key, $status, $params] = match ($e->getMessage()) {
                'name_invalid'       => ['errors.users.nameInvalid', 400, ['max' => 40]],
                'name_taken'         => ['errors.users.nameTaken', 409, []],
                'name_taken_person'  => ['errors.users.nameTakenPerson', 409, []],   // v3.6.0
                'password_too_short' => ['errors.auth.passwordTooShort', 400, ['min' => 8]],
                'role_invalid'       => ['errors.users.roleInvalid', 400, []],
                'last_admin'         => ['errors.users.lastAdmin', 409, []],
                'password_fixed'     => ['errors.auth.passwordFixed', 409, []],
                'not_found'          => ['errors.users.notFound', 404, []],
                default              => ['errors.users.nameInvalid', 400, ['max' => 40]],
            };
            Response::error($this->i18n->t($key, $params), $status, null, $key);
        }
    }

    /**
     * Das bisherige Passwort der angemeldeten Person (ohne Personen: das der
     * Installation). Bis v3.1 prüfte das immer das eine Passwort.
     */
    private function checkCurrent(string $password): void
    {
        $me = $this->user();
        $name = $me === null || $me['id'] === AuthService::LEGACY_ADMIN ? '' : (string)$me['name'];
        $this->failOn($this->auth->checkLogin($name, $password, self::client())['status']);
    }

    private function failOn(string $status): void
    {
        if ($status === 'locked') Response::error($this->i18n->t('errors.auth.locked', ['minutes' => 5]), 429);
        if ($status !== 'ok') Response::error($this->i18n->t('errors.auth.wrongPassword'), 401);
    }

    /**
     * Sitzungs-Cookie: HttpOnly, SameSite=Strict, Pfad der Installation
     * (auch in einem Unterverzeichnis wie /energietracker-acc/), Secure bei HTTPS.
     */
    public static function cookie(string $value, int $ttl): void
    {
        if (headers_sent()) return;
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        setcookie(AuthService::COOKIE, $value, [
            'expires'  => $ttl > 0 ? time() + $ttl : time() - 3600,
            'path'     => $dir . '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
