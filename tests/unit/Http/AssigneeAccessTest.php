<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.5.0 (F1028) — Zuständig über HTTP: Die Personenliste für die Auswahl
 * (`GET /api/people`, nur ID und Name) darf jede angemeldete Rolle lesen, die
 * volle Liste (`/api/users`) bleibt Verwaltern; ohne Anmeldung ist sie leer.
 * Termine nehmen `assignee`/`assignee_id` an (REST additiv); eine gelöschte
 * Person verliert ihre Termine bzw. wird „ehemalige Person“.
 * Dazu der Verlauf als CSV (`GET /api/activity.csv`).
 */
final class AssigneeAccessTest extends HttpServerTestCase
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

    public function testPeopleListRolesAndReminderAssignment(): void
    {
        $this->startServer();
        self::assertSame([], $this->json('GET', '/api/people'), 'ohne Anmeldung: keine Personen');
        $free = $this->json('POST', '/api/reminders', ['title' => 'Rauchmelder', 'next_due' => date('Y-m-d'), 'assignee' => ' Lena ']);
        self::assertSame(['Lena', null, false], [$free['assignee'], $free['assignee_id'], $free['assignee_former']]);

        $admin = $this->cookieFrom($this->send('POST', '/api/session/password', ['password' => 'geheim-123']));
        $anna = $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);
        $this->json('POST', '/api/users', ['name' => 'Ben', 'password' => 'bens-passwort', 'role' => 'member'], $admin);
        $member = $this->cookieFrom($this->send('POST', '/api/session', ['name' => 'Anna', 'password' => 'annas-passwort']));

        self::assertSame(401, $this->send('GET', '/api/people')['status'], 'nicht angemeldet');
        $people = $this->json('GET', '/api/people', null, $member);
        self::assertSame(['admin', 'Anna', 'Ben'], array_column($people, 'name'), 'Mitglied liest Namen (sortiert, Groß/klein egal)');
        foreach ($people as $p) self::assertSame(['id', 'name'], array_keys($p), 'nur ID und Name');
        self::assertSame(403, $this->send('GET', '/api/users', null, $member)['status'], 'die volle Liste bleibt Verwaltern');
        $read = $this->json('POST', '/api/auth/keys', ['name' => 'Anzeige', 'scope' => 'read'], $admin)['key'];
        self::assertSame(200, $this->send('GET', '/api/people', null, ["Authorization: Bearer $read"])['status']);

        // Mitglied weist zu: Person aus der Liste, unbekannte Person abgelehnt
        $r = $this->json('POST', '/api/reminders', ['title' => 'Heizung warten', 'next_due' => date('Y-m-d'), 'recurrence' => 'yearly',
            'assignee' => 'egal', 'assignee_id' => $anna['id']], $member);
        self::assertSame(['Anna', $anna['id']], [$r['assignee'], $r['assignee_id']]);
        $bad = $this->send('POST', '/api/reminders', ['title' => 'X', 'next_due' => date('Y-m-d'), 'assignee_id' => 'u_00000000'], $member);
        self::assertSame(400, $bad['status']);
        self::assertSame('errors.reminder.assigneeUnknown', $bad['json']['code'] ?? null);
        $listed = array_values(array_filter($this->json('GET', '/api/reminders', null, $member), fn($x) => $x['id'] === $r['id']))[0];
        self::assertSame($anna['id'], $listed['assignee_id']);
        $ev = array_values(array_filter($this->json('GET', '/api/agenda?days=30', null, $member)['events'], fn($e) => $e['uid'] === 'reminder-' . $r['id']))[0];
        self::assertSame(['Anna', $anna['id']], [$ev['assignee'], $ev['assignee_id']], 'Agenda-Ereignis trägt die Zuständigkeit');

        // Person löschen mit „ehemalige Person“
        $del = $this->send('DELETE', '/api/users/' . $anna['id'] . '?anonymize_activity=1', null, $admin);
        self::assertSame(200, $del['status']);
        $after = array_values(array_filter($this->json('GET', '/api/reminders', null, $admin), fn($x) => $x['id'] === $r['id']))[0];
        self::assertSame([null, null, true], [$after['assignee'], $after['assignee_id'], $after['assignee_former']]);
        self::assertSame(['admin', 'Ben'], array_column($this->json('GET', '/api/people', null, $admin), 'name'));
    }

    public function testDeletingAPersonWithoutAnonymizingKeepsTheName(): void
    {
        $this->startServer();
        $admin = $this->cookieFrom($this->send('POST', '/api/session/password', ['password' => 'geheim-123']));
        $ben = $this->json('POST', '/api/users', ['name' => 'Ben', 'password' => 'bens-passwort', 'role' => 'member'], $admin);
        $r = $this->json('POST', '/api/reminders', ['title' => 'Zähler', 'next_due' => date('Y-m-d'), 'assignee_id' => $ben['id']], $admin);
        self::assertSame(200, $this->send('DELETE', '/api/users/' . $ben['id'], null, $admin)['status']);
        $after = array_values(array_filter($this->json('GET', '/api/reminders', null, $admin), fn($x) => $x['id'] === $r['id']))[0];
        self::assertSame(['Ben', null, false], [$after['assignee'], $after['assignee_id'], $after['assignee_former']]);

        // Anmeldung ausgeschaltet: Personen gibt es weiter in auth.json, zur Auswahl stehen sie nicht
        $this->json('POST', '/api/users', ['name' => 'Carla', 'password' => 'carlas-passwort', 'role' => 'member'], $admin);
        self::assertContains('Carla', array_column($this->json('GET', '/api/people', null, $admin), 'name'));
        self::assertSame(200, $this->send('DELETE', '/api/session/password', ['current' => 'geheim-123'], $admin)['status']);
        self::assertSame([], $this->json('GET', '/api/people'), 'ohne Anmeldung keine Auswahl aus Personen');
    }

    public function testHistoryAsCsv(): void
    {
        $this->startServer();
        $r = $this->json('POST', '/api/reminders', ['title' => '=SUMME(A1)', 'next_due' => date('Y-m-d', strtotime('-1 day')), 'recurrence' => 'yearly']);
        $this->json('POST', '/api/reminders/' . $r['id'] . '/done', ['note' => '@Notiz']);
        $r2 = $this->json('POST', '/api/reminders', ['title' => 'Zweiter', 'next_due' => date('Y-m-d', strtotime('-1 day'))]);
        $done2 = $this->json('POST', '/api/reminders/' . $r2['id'] . '/done', []);
        $this->json('POST', '/api/activity/' . $done2['activity_id'] . '/undo', []);

        $csv = $this->request('GET', '/api/activity.csv');
        self::assertSame(200, $csv['status']);
        self::assertStringStartsWith('text/csv', $csv['headers']['content-type'] ?? '');
        self::assertMatchesRegularExpression('/attachment; filename="energietracker-verlauf-\d{4}-\d{2}-\d{2}\.csv"/', $csv['headers']['content-disposition'] ?? '');
        $lines = explode("\r\n", trim(substr($csv['body'], 3)));
        self::assertSame('Datum;Aktion;Titel;Wer;Grund;Notiz', $lines[0]);
        self::assertCount(2, $lines, 'Durchgestrichenes steht nicht in der Datei');
        self::assertStringContainsString(";done;'=SUMME(A1);;;'@Notiz", $lines[1], 'Titel und Notiz gegen Formeln geschützt');

        $local = $this->request('GET', '/api/activity.csv?format=local&lang=en');
        self::assertStringStartsWith('Date,Action,Title,By,Reason,Note', substr($local['body'], 3));
        self::assertStringContainsString(',done,', $local['body']);
        self::assertSame(400, $this->request('GET', '/api/activity.csv?format=xls')['status']);
        // Filter wie die Liste: andere Art → nur der Kopf
        $none = $this->request('GET', '/api/activity.csv?kind=cancel_by');
        self::assertSame(['Datum;Aktion;Titel;Wer;Grund;Notiz'], explode("\r\n", trim(substr($none['body'], 3))));
        self::assertSame(400, $this->request('GET', '/api/activity.csv?from=gestern')['status']);
    }
}
