<?php
declare(strict_types=1);

namespace Energietracker\Controllers;

use Energietracker\Http\Request;
use Energietracker\Http\Response;
use Energietracker\Services\AuthService;
use Energietracker\Services\I18nService;

/**
 * v2.6.0 — Anmeldung (opt-in) und API-Schlüssel.
 *
 * Routen:
 *   GET    /api/session            → Modus, angemeldet?, fest per Umgebung?
 *   POST   /api/session            → anmelden {password}; setzt das Cookie
 *   DELETE /api/session            → abmelden
 *   POST   /api/session/password   → Passwort setzen/ändern {current?, password}
 *                                    (setzen schaltet die Anmeldung ein)
 *   DELETE /api/session/password   → Anmeldung ausschalten {current}
 *   GET    /api/auth/keys          → API-Schlüssel (ohne Klartext)
 *   POST   /api/auth/keys          → {name, scope: read|admin}; Klartext einmalig
 *   DELETE /api/auth/keys/{id}
 */
final class SessionController
{
    /** @param callable(): bool $authenticated Stand dieser Anfrage (App::authenticate) */
    public function __construct(
        private AuthService $auth,
        private I18nService $i18n,
        private $authenticated,
    ) {}

    public function status(Request $req): never
    {
        Response::json([
            'mode'           => $this->auth->mode(),
            'authenticated'  => ($this->authenticated)(),
            'mode_fixed'     => $this->auth->modeFixedByEnv(),
            'password_fixed' => $this->auth->passwordFixedByEnv(),
            'has_password'   => $this->auth->hasPassword(),
        ]);
    }

    public function login(Request $req): never
    {
        if ($this->auth->mode() !== 'password') {
            Response::error($this->i18n->t('errors.auth.notPasswordMode'), 400);
        }
        $this->checkOrFail((string)$req->input('password', ''));
        self::cookie($this->auth->issueSession(), $this->auth->sessionTtl());
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
            $this->checkOrFail((string)$req->input('current', ''));
        }
        try {
            $this->auth->setPassword((string)$req->input('password', ''));
        } catch (\InvalidArgumentException) {
            Response::error($this->i18n->t('errors.auth.passwordTooShort', ['min' => 8]), 400);
        }
        // Wer das Passwort gerade gesetzt hat, bleibt angemeldet.
        if ($this->auth->mode() === 'password') {
            self::cookie($this->auth->issueSession(), $this->auth->sessionTtl());
        }
        Response::json(['mode' => $this->auth->mode()]);
    }

    public function disable(Request $req): never
    {
        if ($this->auth->modeFixedByEnv()) {
            Response::error($this->i18n->t('errors.auth.modeFixed'), 409);
        }
        if ($this->auth->mode() === 'password') {
            $this->checkOrFail((string)$req->input('current', ''));
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
        if (!in_array($scope, ['read', 'admin'], true)) {
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

    private function checkOrFail(string $password): void
    {
        $r = $this->auth->checkPassword($password);
        if ($r === 'locked') Response::error($this->i18n->t('errors.auth.locked', ['minutes' => 5]), 429);
        if ($r !== 'ok') Response::error($this->i18n->t('errors.auth.wrongPassword'), 401);
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
