<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.6.0 (Entscheidung des Maintainers) — Personen ohne Zugang (Backup,
 * Beispielhaushalt) unter Einstellungen → Zugriff umbenennen und ihre Rolle
 * ändern: `PATCH /api/users/{id}` gilt auch für sie, nur für Verwalter (403
 * sonst, wie beim Löschen), ohne Anmeldung wie jede Einstellung. Offene Termine
 * zeigen den neuen Namen, erledigte und der Verlauf behalten den Namen vom
 * Erledigen; das Verzeichnis geht mit dem neuen Namen ins Backup.
 */
final class PersonEditTest extends HttpServerTestCase
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

    private function register(): void
    {
        file_put_contents($this->dataDir . '/people.json', json_encode(['last_no' => 3, 'people' => [
            ['id' => 'u_aaaa0001', 'no' => 1, 'name' => 'Lena', 'role' => 'admin', 'created_at' => '2025-01-01T09:00:00+01:00'],
            ['id' => 'u_bbbb0002', 'no' => 2, 'name' => 'Tom', 'role' => 'member', 'created_at' => '2025-01-01T09:00:00+01:00'],
            ['id' => 'u_cccc0003', 'no' => 3, 'name' => 'Mia', 'role' => 'member', 'created_at' => '2025-01-01T09:00:00+01:00'],
        ]]));
    }

    public function testRenamingAPersonWithoutAccountKeepsTheHistory(): void
    {
        $this->startServer();
        $this->register();
        $day = date('Y-m-d');
        $open = $this->json('POST', '/api/reminders', ['title' => 'Fenster', 'next_due' => $day, 'recurrence' => 'yearly', 'assignee_id' => 'u_bbbb0002']);
        $once = $this->json('POST', '/api/reminders', ['title' => 'Zähler ablesen', 'next_due' => $day, 'assignee_id' => 'u_bbbb0002']);
        $this->json('POST', '/api/reminders/' . $once['id'] . '/done', ['note' => 'erledigt']);

        // ohne Anmeldung wie jede Einstellung: Name und Rolle
        $r = $this->send('PATCH', '/api/users/u_bbbb0002', ['name' => '  Thomas ', 'role' => 'admin']);
        self::assertSame(200, $r['status'], $r['body']);
        self::assertSame(['id' => 'u_bbbb0002', 'name' => 'Thomas', 'role' => 'admin', 'source' => 'none', 'no' => 2, 'has_account' => false],
            array_intersect_key($r['json']['data'], array_flip(['id', 'name', 'role', 'no', 'has_account', 'source'])));
        $users = array_column($this->json('GET', '/api/users'), null, 'id');
        self::assertSame(['Thomas', 'admin', 2, false], [$users['u_bbbb0002']['name'], $users['u_bbbb0002']['role'], $users['u_bbbb0002']['no'], $users['u_bbbb0002']['has_account']]);
        self::assertSame(['Lena', 'Mia', 'Thomas'], array_column($this->json('GET', '/api/people'), 'name'), 'zur Wahl mit neuem Namen');

        // offen: neuer Name; erledigt: der Name vom Erledigen; Verlauf: Momentaufnahme
        $rem = array_column($this->json('GET', '/api/reminders'), null, 'id');
        self::assertSame(['Thomas', 'u_bbbb0002'], [$rem[$open['id']]['assignee'], $rem[$open['id']]['assignee_id']]);
        self::assertArrayNotHasKey($once['id'], $rem, 'erledigt (einmalig): nicht mehr in der Liste');
        $stored = array_column(json_decode((string)file_get_contents($this->dataDir . '/reminders.json'), true), null, 'id');
        self::assertSame([false, 'Tom'], [$stored[$once['id']]['active'], $stored[$once['id']]['assignee']], 'erledigter Termin behält den eingefrorenen Namen');
        $entry = $this->json('GET', '/api/activity')[0];
        self::assertSame(['name' => 'Tom', 'id' => 'u_bbbb0002'], $entry['assignee']);
        $agenda = array_values(array_filter($this->json('GET', '/api/agenda?days=400')['events'] ?? [], fn($e) => ($e['assignee_id'] ?? null) === 'u_bbbb0002'));
        self::assertNotEmpty($agenda);
        self::assertSame(['Thomas'], array_values(array_unique(array_column($agenda, 'assignee'))), 'Agenda/Kalender: neuer Name');

        // Fehler wie bei einem Zugang
        self::assertSame([400, 'errors.users.nameInvalid'], $this->outcome($this->send('PATCH', '/api/users/u_bbbb0002', ['name' => '   '])));
        self::assertSame([409, 'errors.users.nameTakenPerson'], $this->outcome($this->send('PATCH', '/api/users/u_bbbb0002', ['name' => 'lena'])), 'Lena hat keinen Zugang');
        self::assertSame([400, 'errors.users.roleInvalid'], $this->outcome($this->send('PATCH', '/api/users/u_bbbb0002', ['role' => 'chef'])));
        self::assertSame([400, 'errors.users.noAccountPassword'], $this->outcome($this->send('PATCH', '/api/users/u_bbbb0002', ['password' => 'geheim-123'])));
        self::assertSame([404, 'errors.users.notFound'], $this->outcome($this->send('PATCH', '/api/users/u_zzzz9999', ['name' => 'X'])));
        self::assertSame('Thomas', array_column($this->json('GET', '/api/users'), 'name', 'id')['u_bbbb0002'], 'nichts halb geändert');

        // Backup: das Verzeichnis trägt den neuen Namen; ein älteres Backup bringt den alten zurück
        $backup = $this->json('GET', '/api/backup/export');
        $byId = array_column($backup['people']['people'], null, 'id');
        self::assertSame(['Thomas', 'admin'], [$byId['u_bbbb0002']['name'], $byId['u_bbbb0002']['role']]);
        $this->send('PATCH', '/api/users/u_bbbb0002', ['name' => 'Tommy']);
        $imp = $this->send('POST', '/api/backup/import?allow_without_snapshot=1', $backup);
        self::assertSame(200, $imp['status'], $imp['body']);
        self::assertSame('Thomas', array_column($this->json('GET', '/api/users'), 'name', 'id')['u_bbbb0002'], 'Roundtrip');
    }

    public function testOnlyAdminsEditPeopleWithoutAccount(): void
    {
        $this->startServer();
        $this->register();
        $admin = $this->cookieFrom($this->send('POST', '/api/session/password', ['password' => 'geheim-123']));
        $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);
        $anna = $this->cookieFrom($this->send('POST', '/api/session', ['name' => 'Anna', 'password' => 'annas-passwort']));

        self::assertSame([403, 'errors.auth.adminOnly'], $this->outcome($this->send('PATCH', '/api/users/u_cccc0003', ['name' => 'Maria'], $anna)));
        self::assertSame('Mia', array_column($this->json('GET', '/api/users', null, $admin), 'name', 'id')['u_cccc0003']);

        self::assertSame(200, $this->send('PATCH', '/api/users/u_cccc0003', ['name' => 'Maria', 'role' => 'admin'], $admin)['status']);
        // ein Zugang darf den Namen nicht doppelt vergeben — auch nicht an eine Person ohne Zugang
        self::assertSame([409, 'errors.users.nameTaken'], $this->outcome($this->send('PATCH', '/api/users/u_cccc0003', ['name' => 'ANNA'], $admin)));
        // die Rolle wirkt, sobald der Zugang angelegt ist (Übernahme per Name)
        $maria = $this->json('POST', '/api/users', ['name' => 'Maria', 'password' => 'marias-passwort', 'role' => 'admin'], $admin);
        self::assertSame(['u_cccc0003', 3], [$maria['id'], $maria['no']]);

        // gelöscht: nicht mehr änderbar
        self::assertSame(200, $this->send('DELETE', '/api/users/u_aaaa0001', null, $admin)['status']);
        self::assertSame([404, 'errors.users.notFound'], $this->outcome($this->send('PATCH', '/api/users/u_aaaa0001', ['name' => 'Lea'], $admin)));
    }

    /**
     * Nachtrag (Entscheidung des Maintainers): Namen sind über alle Personen
     * eindeutig — Zugänge und Personen ohne Zugang, gelöschte zählen nicht.
     * Ein neuer Zugang mit dem Namen einer Person ohne Zugang übernimmt sie
     * (kein Konflikt); ein Zugang umbenannt auf ihren Namen bekommt 409.
     */
    public function testNamesAreUniqueAcrossAccountsAndPeopleWithoutAccount(): void
    {
        $this->startServer();
        $this->register();
        $admin = $this->cookieFrom($this->send('POST', '/api/session/password', ['password' => 'geheim-123']));
        $anna = $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);

        // Zugang → Name einer Person ohne Zugang: 409 mit eigener Meldung, nichts geändert
        $r = $this->send('PATCH', '/api/users/' . $anna['id'], ['name' => 'lena'], $admin);
        self::assertSame([409, 'errors.users.nameTakenPerson'], $this->outcome($r));
        self::assertStringContainsString('ohne Zugang', (string)($r['json']['error'] ?? ''));
        self::assertSame('Anna', array_column($this->json('GET', '/api/users', null, $admin), 'name', 'id')[$anna['id']]);
        // Person ohne Zugang → Name eines Zugangs: 409 (allgemein)
        self::assertSame([409, 'errors.users.nameTaken'], $this->outcome($this->send('PATCH', '/api/users/u_cccc0003', ['name' => 'ANNA'], $admin)));
        // anderer Name geht
        self::assertSame(200, $this->send('PATCH', '/api/users/' . $anna['id'], ['name' => 'Annika'], $admin)['status']);

        // Anlegen mit dem Namen einer Person ohne Zugang: Übernahme, kein Konflikt (Groß/klein egal)
        $tom = $this->send('POST', '/api/users', ['name' => 'TOM', 'password' => 'toms-passwort', 'role' => 'member'], $admin);
        self::assertSame([201, 'u_bbbb0002', 2], [$tom['status'], $tom['json']['data']['id'] ?? null, $tom['json']['data']['no'] ?? null]);
        self::assertCount(1, array_filter($this->json('GET', '/api/users', null, $admin), fn($u) => strtolower($u['name']) === 'tom'));

        // gelöschte Personen zählen nicht
        self::assertSame(200, $this->send('DELETE', '/api/users/u_aaaa0001', null, $admin)['status']);
        self::assertSame(200, $this->send('PATCH', '/api/users/' . $anna['id'], ['name' => 'Lena'], $admin)['status'], 'Lena ist gelöscht');
    }

    /** @return array{0:int, 1:?string} */
    private function outcome(array $r): array
    {
        return [$r['status'], $r['json']['code'] ?? null];
    }
}
