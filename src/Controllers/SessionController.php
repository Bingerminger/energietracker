<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\AuthService;
use Energietracker\Services\I18nService;
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
 *   POST   /api/users                → {name, password, role}
 *   PATCH  /api/users/{id}           → {name?, role?, password?}
 *   DELETE /api/users/{id}
 */
final class SessionController
{
    /**
     * @param callable(): bool $authenticated Stand dieser Anfrage (App::authenticate)
     * @param (callable(): ?array)|null $currentUser Person dieser Anfrage
     * @param (callable(): string)|null $role admin | member
     */
    public function __construct(
        private AuthService $auth,
        private I18nService $i18n,
        private $authenticated,
        private $currentUser = null,
        private $role = null,
    ) {}

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
        $r = $this->auth->checkLogin((string)$req->input('name', ''), (string)$req->input('password', ''));
        $this->failOn($r['status']);
        self::cookie($this->auth->issueSession($r['user']), $this->auth->sessionTtl());
        Response::json(['authenticated' => true]);
    }

    public function logout(Request $req): never
    {
        self::cookie('', -1);
        Response::json(['authenticated' => false]);
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
        if (!in_array($scope, ['read', 'admin', 'calendar'], true)) {   // v3.1.0 — calendar: Kalender-Abo
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
        $this->failOn($this->auth->checkLogin($name, (string)$req->input('current', ''))['status']);
        $this->userCall(fn() => $this->auth->updateUser((string)$me['id'], ['password' => (string)$req->input('password', '')]));
        self::cookie($this->auth->issueSession((string)$me['id']), $this->auth->sessionTtl());
        Response::json(['changed' => true]);
    }

    public function listUsers(Request $req): never
    {
        Response::json($this->auth->users());
    }

    public function createUser(Request $req): never
    {
        $user = $this->userCall(fn() => $this->auth->createUser((string)$req->input('name', ''),
            (string)$req->input('password', ''), (string)$req->input('role', 'member')));
        Response::json($user, 201);
    }

    public function updateUser(Request $req): never
    {
        $patch = [];
        foreach (['name', 'role', 'password'] as $k) {
            if (is_array($req->body) && array_key_exists($k, $req->body)) $patch[$k] = (string)$req->body[$k];
        }
        $id = (string)$req->param('id');
        $user = $this->userCall(fn() => $this->auth->updateUser($id, $patch));
        // Neues Passwort beendet die Sitzungen der Person — die eigene bleibt
        if (isset($patch['password']) && ($this->user()['id'] ?? null) === $id) {
            self::cookie($this->auth->issueSession($id), $this->auth->sessionTtl());
        }
        Response::json($user);
    }

    public function deleteUser(Request $req): never
    {
        $id = (string)$req->param('id');
        if (($this->user()['id'] ?? null) === $id) {
            Response::error($this->i18n->t('errors.users.notSelf'), 400, null, 'errors.users.notSelf');
        }
        $this->userCall(fn() => $this->auth->deleteUser($id));
        Response::json(['deleted' => true]);
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
        $this->failOn($this->auth->checkLogin($name, $password)['status']);
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
