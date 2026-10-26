<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.9.0 (Paket oberflaeche) — Personen im Haushalt ohne Anmeldung: Die Karte
 * „Personen im Haushalt“ unter Einstellungen → Haushalt & Gebäude legt eine
 * Person ohne Zugang an (`POST /api/users` mit `has_account: false`). Bis v3.8
 * entstanden solche Personen nur aus einem Backup oder einem Beispielhaushalt,
 * „Zuständig“ und die Ablesetermine aus dem Posteingang nutzen sie aber in
 * allen Stufen. Rechte wie bisher: ohne Anmeldung darf jede Anfrage, was ein
 * Verwalter darf; mit Anmeldung nur Verwalter (App::adminOnly, /api/users).
 */
final class PersonWithoutAccountTest extends HttpServerTestCase
{
    private function send(string $method, string $path, ?array $payload = null, array $headers = []): array
    {
        $r = $this->request($method, $path, array_merge(['Content-Type: application/json'], $headers), $payload === null ? null : json_encode($payload));
        $r['json'] = json_decode($r['body'], true);
        return $r;
    }

    private function cookieFrom(array $r): array
    {
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }

    /** @return array{0:int, 1:?string} */
    private function outcome(array $r): array
    {
        return [$r['status'], $r['json']['code'] ?? null];
    }

    public function testWithoutSignInAnyoneAddsAPersonWhoIsThenResponsible(): void
    {
        $this->startServer();
        $r = $this->send('POST', '/api/users', ['name' => '  Lena  ', 'has_account' => false]);
        self::assertSame(201, $r['status'], $r['body']);
        $lena = $r['json']['data'];
        self::assertMatchesRegularExpression('/^u_[0-9a-f]{8}$/', $lena['id']);
        self::assertSame(['Lena', 'member', 'none', 1, false], [$lena['name'], $lena['role'], $lena['source'], $lena['no'], $lena['has_account']]);

        // zur Wahl unter „Zuständig“ und in der Personenliste
        self::assertSame([['id' => $lena['id'], 'name' => 'Lena']], $this->json('GET', '/api/people'));
        $users = array_column($this->json('GET', '/api/users'), null, 'id');
        self::assertFalse($users[$lena['id']]['has_account']);

        // zuständig für einen Termin: der Name kommt aus dem Verzeichnis
        $rem = $this->json('POST', '/api/reminders', ['title' => 'Zähler ablesen', 'next_due' => date('Y-m-d'), 'assignee_id' => $lena['id']]);
        self::assertSame(['Lena', $lena['id']], [$rem['assignee'] ?? null, $rem['assignee_id'] ?? null]);

        // Fehler wie beim Umbenennen
        self::assertSame([400, 'errors.users.nameInvalid'], $this->outcome($this->send('POST', '/api/users', ['name' => '   ', 'has_account' => false])));
        self::assertSame([400, 'errors.users.nameInvalid'], $this->outcome($this->send('POST', '/api/users', ['name' => str_repeat('x', 41), 'has_account' => false])));
        self::assertSame([409, 'errors.users.nameTakenPerson'], $this->outcome($this->send('POST', '/api/users', ['name' => 'LENA', 'has_account' => false])));
        self::assertSame([400, 'errors.users.roleInvalid'], $this->outcome($this->send('POST', '/api/users', ['name' => 'Ben', 'role' => 'chef', 'has_account' => false])));
        self::assertCount(1, $this->json('GET', '/api/people'), 'nichts halb angelegt');

        // Backup trägt die Person mit (Topf `people`)
        $backup = $this->json('GET', '/api/backup/export');
        self::assertSame(['Lena'], array_column($backup['people']['people'], 'name'));

        // Nummern werden nie wieder vergeben: löschen, neu anlegen → nächste Nummer
        self::assertSame(200, $this->send('DELETE', '/api/users/' . $lena['id'])['status']);
        $again = $this->send('POST', '/api/users', ['name' => 'Lena', 'has_account' => false])['json']['data'];
        self::assertSame(2, $again['no']);
        self::assertNotSame($lena['id'], $again['id'], 'eine gelöschte ID kommt nicht zurück');
    }

    public function testWithSignInOnlyAdminsAddPeopleAndALaterAccountAdoptsThem(): void
    {
        $this->startServer();
        $admin = $this->cookieFrom($this->send('POST', '/api/session/password', ['password' => 'geheim-123']));
        $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);
        $anna = $this->cookieFrom($this->send('POST', '/api/session', ['name' => 'Anna', 'password' => 'annas-passwort']));

        self::assertSame([403, 'errors.auth.adminOnly'], $this->outcome($this->send('POST', '/api/users', ['name' => 'Tim', 'has_account' => false], $anna)));
        self::assertSame(401, $this->send('POST', '/api/users', ['name' => 'Tim', 'has_account' => false])['status'], 'nicht angemeldet');

        $tim = $this->send('POST', '/api/users', ['name' => 'Tim', 'has_account' => false], $admin);
        self::assertSame(201, $tim['status'], $tim['body']);
        $tim = $tim['json']['data'];
        self::assertContains('Tim', array_column($this->json('GET', '/api/people', null, $anna), 'name'), 'Mitglieder sehen die Person unter „Zuständig“');
        // Name eines Zugangs ist vergeben
        self::assertSame([409, 'errors.users.nameTaken'], $this->outcome($this->send('POST', '/api/users', ['name' => 'anna', 'has_account' => false], $admin)));
        // ohne `has_account: false` bleibt alles wie bisher: Passwort nötig
        self::assertSame([400, 'errors.auth.passwordTooShort'], $this->outcome($this->send('POST', '/api/users', ['name' => 'Tom'], $admin)));

        // später ein Zugang mit demselben Namen: dieselbe ID und Nummer
        $acc = $this->json('POST', '/api/users', ['name' => 'Tim', 'password' => 'tims-passwort', 'role' => 'member'], $admin);
        self::assertSame([$tim['id'], $tim['no']], [$acc['id'], $acc['no']]);
        $users = array_column($this->json('GET', '/api/users', null, $admin), null, 'id');
        self::assertTrue($users[$tim['id']]['has_account']);
    }
}
