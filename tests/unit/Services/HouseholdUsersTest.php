<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AuthService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.2.0 (F1023) — Benutzer im Haushalt: Personen mit eigener Anmeldung und
 * eigenen Einstellungen; Verwalter und Mitglieder. Das Passwort aus der Zeit
 * vor v3.2 gehört dem Verwalter „admin“ und gilt weiter ohne Namen.
 */
#[CoversClass(AuthService::class)]
final class HouseholdUsersTest extends ServiceTestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new AuthService($this->store);
    }

    public function testTheOldPasswordBelongsToTheAdmin(): void
    {
        $this->auth->setPassword('geheim-123');
        $users = $this->auth->users();
        self::assertSame([['u_admin', 'admin', 'admin']], array_map(fn($u) => [$u['id'], $u['name'], $u['role']], $users));
        self::assertSame(['status' => 'ok', 'user' => 'u_admin'], $this->auth->checkLogin('', 'geheim-123'), 'ohne Namen wie bisher');
        self::assertSame('ok', $this->auth->checkLogin('Admin', 'geheim-123')['status']);
        self::assertSame('u_admin', $this->auth->sessionUserId($this->auth->issueSession()), 'altes Cookie = Verwalter');
        self::assertFalse($this->auth->hasNamedUsers(), 'die Anmeldung fragt noch nicht nach dem Namen');
    }

    public function testPeopleLogInWithTheirNameAndKeepTheirOwnSettings(): void
    {
        $this->auth->setPassword('geheim-123');
        $anna = $this->auth->createUser('Anna', 'annas-passwort', 'member');
        self::assertTrue($this->auth->hasNamedUsers());
        self::assertSame(2, count($this->auth->users()), 'der Verwalter steht jetzt fest in der Liste');

        $r = $this->auth->checkLogin('anna', 'annas-passwort');
        self::assertSame(['status' => 'ok', 'user' => $anna['id']], $r, 'Name ohne Rücksicht auf Groß/klein');
        self::assertSame('wrong', $this->auth->checkLogin('Anna', 'geheim-123')['status'], 'nur ihr eigenes Passwort');
        self::assertSame($anna['id'], $this->auth->sessionUserId($this->auth->issueSession($anna['id'])));

        $this->auth->setPrefs($anna['id'], ['ui_level' => 'beginner', 'language' => 'fr']);
        self::assertSame(['ui_level' => 'beginner', 'language' => 'fr'], (array)$this->auth->users()[1]['prefs']);
        self::assertSame([], (array)$this->auth->users()[0]['prefs'], 'die Einstellungen gehören nur ihr');
        $this->auth->setPrefs($anna['id'], ['language' => null]);
        self::assertSame(['ui_level' => 'beginner'], (array)$this->auth->users()[1]['prefs'], 'null = wie die Installation');

        try { $this->auth->createUser(' ANNA ', 'noch-eins-123', 'member'); self::fail('doppelter Name'); }
        catch (\InvalidArgumentException $e) { self::assertSame('name_taken', $e->getMessage()); }
        try { $this->auth->createUser('Ben', 'kurz', 'member'); self::fail('kurzes Passwort'); }
        catch (\InvalidArgumentException $e) { self::assertSame('password_too_short', $e->getMessage()); }
    }

    public function testTheLastAdminStays(): void
    {
        $this->auth->setPassword('geheim-123');
        foreach ([fn() => $this->auth->updateUser('u_admin', ['role' => 'member']), fn() => $this->auth->deleteUser('u_admin')] as $call) {
            try { $call(); self::fail('letzter Verwalter'); }
            catch (\InvalidArgumentException $e) { self::assertSame('last_admin', $e->getMessage()); }
        }
        $bob = $this->auth->createUser('Bob', 'bobs-passwort', 'admin');
        self::assertSame('member', $this->auth->updateUser('u_admin', ['role' => 'member'])['role'], 'mit einem zweiten Verwalter geht es');
        try { $this->auth->updateUser($bob['id'], ['role' => 'member']); self::fail('Bob ist jetzt der letzte'); }
        catch (\InvalidArgumentException $e) { self::assertSame('last_admin', $e->getMessage()); }
    }

    public function testDeletingSomeoneEndsTheirSessionAndTheOldLogin(): void
    {
        $this->auth->setPassword('geheim-123');
        $bob = $this->auth->createUser('Bob', 'bobs-passwort', 'admin');
        $anna = $this->auth->createUser('Anna', 'annas-passwort', 'member');
        $cookie = $this->auth->issueSession($anna['id']);
        $this->auth->deleteUser($anna['id']);
        self::assertNull($this->auth->sessionUserId($cookie), 'gelöscht = abgemeldet');

        $this->auth->deleteUser('u_admin');
        self::assertSame('wrong', $this->auth->checkLogin('', 'geheim-123')['status'], 'ohne den alten Verwalter keine Anmeldung ohne Namen');
        self::assertNull($this->auth->sessionUserId($this->auth->issueSession()), 'auch sein altes Cookie nicht');
        self::assertSame('ok', $this->auth->checkLogin('bob', 'bobs-passwort')['status']);
        self::assertSame([$bob['id']], array_column($this->auth->users(), 'id'));
    }

    /** Ein neues Passwort beendet die Sitzungen der Person auf allen Geräten — die der anderen nicht. */
    public function testANewPasswordEndsThatPersonsSessions(): void
    {
        $this->auth->setPassword('geheim-123');
        $legacy = $this->auth->issueSession();                    // Cookie aus der Zeit vor v3.2
        $anna = $this->auth->createUser('Anna', 'annas-passwort', 'member');
        $annaPhone = $this->auth->issueSession($anna['id']);
        $adminTablet = $this->auth->issueSession('u_admin');

        $this->auth->updateUser($anna['id'], ['password' => 'neues-passwort']);
        self::assertNull($this->auth->sessionUserId($annaPhone), 'Annas altes Gerät ist abgemeldet');
        self::assertSame($anna['id'], $this->auth->sessionUserId($this->auth->issueSession($anna['id'])), 'neu angemeldet gilt');
        self::assertSame('u_admin', $this->auth->sessionUserId($adminTablet), 'die anderen bleiben angemeldet');
        self::assertSame('u_admin', $this->auth->sessionUserId($legacy));

        $this->auth->updateUser('u_admin', ['password' => 'verwalter-neu']);
        self::assertNull($this->auth->sessionUserId($adminTablet));
        self::assertNull($this->auth->sessionUserId($legacy), 'auch das alte Cookie des Verwalters');
        self::assertSame('ok', $this->auth->checkLogin('', 'verwalter-neu')['status']);
    }

    public function testProxyPeopleAreRecognizedByName(): void
    {
        $first = $this->auth->proxyUserRecord('Anna');
        $second = $this->auth->proxyUserRecord('Ben');
        self::assertSame('admin', $first['role'], 'die erste Person verwaltet');
        self::assertSame('member', $second['role']);
        self::assertSame($first['id'], $this->auth->proxyUserRecord('anna')['id'], 'dieselbe Person beim nächsten Mal');
        self::assertFalse($this->auth->hasNamedUsers(), 'Proxy-Personen melden sich nicht mit Namen an');
    }
}
