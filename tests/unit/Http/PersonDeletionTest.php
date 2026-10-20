<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.6.0 — Person löschen über HTTP: nur Verwalter (sonst 403); offene
 * Termine gehen an die löschende Person, der Dialog bekommt die Zahlen vorher
 * (`GET /api/users/{id}/handover`); erledigte Einträge behalten den Namen
 * mit „(gelöscht)“ — im Verlauf, als CSV und am erledigten Termin; die
 * Personennummer zählt nach dem Löschen weiter.
 */
final class PersonDeletionTest extends HttpServerTestCase
{
    private function cookieFrom(array $r): array
    {
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }

    private function send(string $method, string $path, ?array $payload = null, array $headers = []): array
    {
        $r = $this->request($method, $path, array_merge(['Content-Type: application/json'], $headers), $payload === null ? null : json_encode($payload));
        $r['json'] = json_decode($r['body'], true);
        return $r;
    }

    private function login(string $name, string $password): array
    {
        return $this->cookieFrom($this->send('POST', '/api/session', ['name' => $name, 'password' => $password]));
    }

    public function testOnlyAdminsDeleteAndOpenRemindersGoToTheDeleter(): void
    {
        $this->startServer();
        $admin = $this->cookieFrom($this->send('POST', '/api/session/password', ['password' => 'geheim-123']));
        $anna = $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);
        $ben = $this->json('POST', '/api/users', ['name' => 'Ben', 'password' => 'bens-passwort', 'role' => 'admin'], $admin);
        self::assertSame([2, 3], [$anna['no'], $ben['no']], 'Nummern beim Anlegen (Nr. 1: admin)');
        $member = $this->login('Anna', 'annas-passwort');
        $benCookie = $this->login('Ben', 'bens-passwort');

        // Nicht-Verwalter: 403 — beim Löschen und bei der Vorschau
        $no = $this->send('DELETE', '/api/users/' . $ben['id'], null, $member);
        self::assertSame(403, $no['status']);
        self::assertSame('errors.auth.adminOnly', $no['json']['code'] ?? null);
        self::assertSame(403, $this->send('GET', '/api/users/' . $ben['id'] . '/handover', null, $member)['status']);

        // Anna: ein offener Termin, ein erledigter (einmalig, von ihr abgehakt)
        $day = date('Y-m-d', strtotime('-1 day'));
        $open = $this->json('POST', '/api/reminders', ['title' => 'Heizung warten', 'next_due' => $day, 'recurrence' => 'yearly', 'assignee_id' => $anna['id']], $member);
        $once = $this->json('POST', '/api/reminders', ['title' => 'Zähler ablesen', 'next_due' => $day, 'assignee_id' => $anna['id']], $member);
        $done = $this->json('POST', '/api/reminders/' . $once['id'] . '/done', ['note' => 'erledigt'], $member);
        self::assertNotEmpty($done['activity_id']);

        // Vorschau für den Dialog: 1 offener an Ben (der löscht), 1 Eintrag im Verlauf
        $pre = $this->json('GET', '/api/users/' . $anna['id'] . '/handover', null, $benCookie);
        self::assertSame(['open' => 1, 'done' => 1, 'to' => ['id' => $ben['id'], 'name' => 'Ben']],
            ['open' => $pre['open'], 'done' => $pre['done'], 'to' => $pre['to']]);
        self::assertSame(['id' => $anna['id'], 'name' => 'Anna', 'no' => 2, 'has_account' => true], $pre['person']);

        $del = $this->send('DELETE', '/api/users/' . $anna['id'], null, $benCookie);
        self::assertSame(200, $del['status']);
        self::assertSame([1, ['id' => $ben['id'], 'name' => 'Ben'], 1],
            [$del['json']['data']['handed_over'], $del['json']['data']['handed_over_to'], $del['json']['data']['kept']]);
        self::assertSame(401, $this->send('GET', '/api/reminders', null, $member)['status'], 'Annas Sitzung endet');

        // offen → Ben; erledigt → Anna (gelöscht)
        $rem = array_column($this->json('GET', '/api/reminders', null, $admin), null, 'id');
        self::assertSame(['Ben', $ben['id'], false], [$rem[$open['id']]['assignee'], $rem[$open['id']]['assignee_id'], $rem[$open['id']]['assignee_deleted']]);
        $entry = $this->json('GET', '/api/activity', null, $admin)[0];
        self::assertSame(['type' => 'person', 'id' => $anna['id'], 'name' => 'Anna', 'deleted' => true], $entry['by']);
        self::assertSame(['name' => 'Anna', 'id' => $anna['id'], 'deleted' => true], $entry['assignee']);
        $csv = $this->request('GET', '/api/activity.csv', $admin);
        self::assertStringContainsString(';Anna (gelöscht);', $csv['body']);
        $en = $this->request('GET', '/api/activity.csv?format=local&lang=en', $admin);
        self::assertStringContainsString(',Anna (deleted),', $en['body']);

        // eine gelöschte Person lässt sich nicht mehr zuweisen und steht nicht zur Wahl
        $bad = $this->send('POST', '/api/reminders', ['title' => 'X', 'next_due' => $day, 'assignee_id' => $anna['id']], $admin);
        self::assertSame('errors.reminder.assigneeUnknown', $bad['json']['code'] ?? null);
        self::assertSame(['admin', 'Ben'], array_column($this->json('GET', '/api/people', null, $admin), 'name'));

        // die Nummer zählt weiter: Nr. 2 bleibt Anna
        $carla = $this->json('POST', '/api/users', ['name' => 'Carla', 'password' => 'carlas-passwort', 'role' => 'member'], $admin);
        self::assertSame(4, $carla['no']);
        $users = array_column($this->json('GET', '/api/users', null, $admin), 'no', 'name');
        self::assertSame(['admin' => 1, 'Ben' => 3, 'Carla' => 4], $users);

        // das Verzeichnis kommt mit ins Backup, samt Löschvermerk
        $backup = $this->json('GET', '/api/backup/export', null, $admin);
        $byId = array_column($backup['people']['people'], null, 'id');
        self::assertSame(4, $backup['people']['last_no']);
        self::assertNotNull($byId[$anna['id']]['deleted_at']);
        self::assertSame($ben['id'], $byId[$anna['id']]['deleted_by']);
        self::assertStringNotContainsString('password_hash', json_encode($backup['people']), 'keine Passwörter im Verzeichnis');
    }

    /**
     * Nachtrag (Entscheidung des Maintainers): Personen ohne Zugang (Backup,
     * Beispielhaushalt) stehen unter Zugriff in derselben Liste und lassen sich
     * löschen — ohne Anmeldung von jeder Anfrage (wie jede Einstellung, niemand
     * übernimmt), mit Anmeldung nur vom Verwalter (übernimmt). Ein neuer Zugang
     * mit ihrem Namen macht aus ihr einen Zugang mit derselben Nummer.
     */
    public function testPeopleWithoutAccountAreListedAndDeletable(): void
    {
        $this->startServer();
        file_put_contents($this->dataDir . '/people.json', json_encode(['last_no' => 3, 'people' => [
            ['id' => 'u_aaaa0001', 'no' => 1, 'name' => 'Lena', 'role' => 'admin', 'created_at' => '2025-01-01T09:00:00+01:00'],
            ['id' => 'u_bbbb0002', 'no' => 2, 'name' => 'Tom', 'role' => 'member', 'created_at' => '2025-01-01T09:00:00+01:00'],
            ['id' => 'u_cccc0003', 'no' => 3, 'name' => 'Mia', 'role' => 'member', 'created_at' => '2025-01-01T09:00:00+01:00'],
        ]]));
        $day = date('Y-m-d');
        $tomTask = $this->json('POST', '/api/reminders', ['title' => 'Fenster', 'next_due' => $day, 'recurrence' => 'yearly', 'assignee_id' => 'u_bbbb0002']);

        // ohne Anmeldung: Liste mit Nummer und „ohne Zugang“, löschen wie jede Einstellung, niemand übernimmt
        $users = array_column($this->json('GET', '/api/users'), null, 'id');
        self::assertSame([false, 'none', 2], [$users['u_bbbb0002']['has_account'], $users['u_bbbb0002']['source'], $users['u_bbbb0002']['no']]);
        $pre = $this->json('GET', '/api/users/u_bbbb0002/handover');
        self::assertSame([null, 1, false], [$pre['to'], $pre['open'], $pre['person']['has_account']]);
        $del = $this->send('DELETE', '/api/users/u_bbbb0002');
        self::assertSame(200, $del['status']);
        self::assertSame([0, null, 1], [$del['json']['data']['handed_over'], $del['json']['data']['handed_over_to'], $del['json']['data']['kept']]);
        $r = array_column($this->json('GET', '/api/reminders'), null, 'id')[$tomTask['id']];
        self::assertSame(['Tom', 'u_bbbb0002', true], [$r['assignee'], $r['assignee_id'], $r['assignee_deleted']]);
        self::assertSame(['Lena', 'Mia'], array_column($this->json('GET', '/api/people'), 'name'));
        self::assertSame(404, $this->send('DELETE', '/api/users/u_bbbb0002')['status'], 'schon gelöscht');
        self::assertSame(404, $this->send('GET', '/api/users/u_zzzz9999/handover')['status']);

        // mit Anmeldung: Verwalter sieht Zugänge und Personen ohne Zugang, löscht mit Übergabe
        $admin = $this->cookieFrom($this->send('POST', '/api/session/password', ['password' => 'geheim-123']));
        $member = $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);
        $list = $this->json('GET', '/api/users', null, $admin);
        self::assertSame(['admin' => true, 'Anna' => true, 'Lena' => false, 'Mia' => false], array_column($list, 'has_account', 'name'));
        self::assertGreaterThan(3, $member['no'], 'Nummern zählen hinter dem Verzeichnis weiter');
        $lenaTask = $this->json('POST', '/api/reminders', ['title' => 'Heizung', 'next_due' => $day, 'recurrence' => 'yearly', 'assignee_id' => 'u_aaaa0001'], $admin);
        $anna = $this->cookieFrom($this->send('POST', '/api/session', ['name' => 'Anna', 'password' => 'annas-passwort']));
        self::assertSame(403, $this->send('DELETE', '/api/users/u_aaaa0001', null, $anna)['status'], 'Mitglied: 403');
        $del2 = $this->send('DELETE', '/api/users/u_aaaa0001', null, $admin);
        self::assertSame([1, 'admin'], [$del2['json']['data']['handed_over'], $del2['json']['data']['handed_over_to']['name']]);
        $r2 = array_column($this->json('GET', '/api/reminders', null, $admin), null, 'id')[$lenaTask['id']];
        self::assertSame(['admin', 'u_admin'], [$r2['assignee'], $r2['assignee_id']]);

        // „Zugang anlegen“: ein Zugang mit ihrem Namen übernimmt ID und Nummer
        $mia = $this->json('POST', '/api/users', ['name' => 'mia', 'password' => 'mias-passwort', 'role' => 'member'], $admin);
        self::assertSame(['u_cccc0003', 3], [$mia['id'], $mia['no']]);
        $after = array_column($this->json('GET', '/api/users', null, $admin), null, 'id');
        self::assertTrue($after['u_cccc0003']['has_account']);
        self::assertCount(1, array_filter($after, fn($u) => strtolower($u['name']) === 'mia'), 'Mia steht einmal da');
    }

    public function testWithAnonymizingTheDoneEntriesShowFormerMember(): void
    {
        $this->startServer();
        $admin = $this->cookieFrom($this->send('POST', '/api/session/password', ['password' => 'geheim-123']));
        $anna = $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);
        $member = $this->login('Anna', 'annas-passwort');
        $r = $this->json('POST', '/api/reminders', ['title' => 'Einmal', 'next_due' => date('Y-m-d'), 'assignee_id' => $anna['id']], $member);
        $this->json('POST', '/api/reminders/' . $r['id'] . '/done', [], $member);
        self::assertSame(200, $this->send('DELETE', '/api/users/' . $anna['id'] . '?anonymize_activity=1', null, $admin)['status']);
        $entry = $this->json('GET', '/api/activity', null, $admin)[0];
        self::assertSame(['type' => 'person', 'former' => true], $entry['by']);
        self::assertSame(['former' => true], $entry['assignee']);
    }
}
