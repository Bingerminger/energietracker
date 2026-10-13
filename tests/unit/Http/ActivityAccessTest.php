<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.4.0 (F1024) — Erledigt mit Verlauf über HTTP: wer im Verlauf steht
 * (ohne Anmeldung niemand, Person, API-Schlüssel), wer was darf (abhaken,
 * Rückgängig, Notiz: Mitglied und Verwalter; leeren und Aufbewahrung nur
 * Verwalter; Lese-Schlüssel nichts), Datenschutz beim Löschen einer Person.
 */
final class ActivityAccessTest extends HttpServerTestCase
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

    /** Fälliger Termin (gestern) und seine Agenda-UID. */
    private function dueReminder(string $title, array $auth = []): string
    {
        $rem = $this->json('POST', '/api/reminders', ['title' => $title, 'category' => 'custom',
            'next_due' => date('Y-m-d', strtotime('-1 day')), 'recurrence' => 'yearly'], $auth);
        return 'reminder-' . $rem['id'];
    }

    public function testWithoutLoginNobodyIsRecordedAndOnlyManualKindsCanBeTicked(): void
    {
        $this->startServer();
        $uid = $this->dueReminder('Rauchmelder');
        $events = $this->json('GET', '/api/agenda?days=30')['events'];
        $ev = array_values(array_filter($events, fn($e) => $e['uid'] === $uid))[0];
        self::assertTrue($ev['due_now'] && $ev['can_mark_done']);
        self::assertSame($uid . '@' . date('Y-m-d', strtotime('-1 day')), $ev['occurrence']);
        self::assertNull($ev['done']);

        $r = $this->send('POST', '/api/agenda/done', ['uid' => $uid, 'occurrence' => $ev['occurrence'], 'note' => 'erledigt']);
        self::assertSame(201, $r['status']);
        $entry = $r['json']['data']['entry'];
        self::assertNull($entry['by'], 'ohne Anmeldung: by = null');
        self::assertSame(['done', 'reminder', 'erledigt'], [$entry['action'], $entry['subject']['kind'], $entry['note']]);

        $list = $this->json('GET', '/api/activity');
        self::assertCount(1, $list);
        self::assertSame($entry['id'], $list[0]['id']);
        self::assertSame([], $this->json('GET', '/api/activity?kind=cancel_by'));
        self::assertSame(400, $this->send('GET', '/api/activity?from=gestern')['status']);

        self::assertSame('neu', $this->send('PATCH', '/api/activity/' . $entry['id'], ['note' => 'neu'])['json']['data']['note']);
        $undo = $this->send('POST', '/api/activity/' . $entry['id'] . '/undo');
        self::assertSame(200, $undo['status']);
        self::assertTrue($undo['json']['data']['reverted'], 'Termin wieder offen');
        self::assertSame(404, $this->send('POST', '/api/activity/act_000000000000/undo')['status']);

        // Ablesung fällig erledigt nur eine Ablesung → 422
        $reading = array_values(array_filter($this->json('GET', '/api/agenda?days=30')['events'], fn($e) => $e['kind'] === 'reading_due'))[0];
        self::assertFalse($reading['can_mark_done']);
        $bad = $this->send('POST', '/api/agenda/done', ['uid' => $reading['uid']]);
        self::assertSame(422, $bad['status']);
        self::assertSame('errors.activity.notManual', $bad['json']['code'] ?? null);

        // reminders/{id}/done antwortet zusätzlich mit activity_id
        $id = substr($uid, strlen('reminder-'));
        $done = $this->json('POST', "/api/reminders/$id/done", ['note' => 'per Knopf']);
        self::assertMatchesRegularExpression('/^act_[0-9a-f]{12}$/', $done['activity_id'] ?? '');
        $recs = $this->json('GET', '/api/recommendations');
        if ($recs !== []) {
            $dis = $this->json('POST', '/api/recommendations/' . rawurlencode($recs[0]['id']) . '/dismiss', []);
            self::assertMatchesRegularExpression('/^act_/', $dis['activity_id'] ?? '', 'Ausblenden: activity_id');
        }
    }

    public function testPersonsKeysAndRoles(): void
    {
        $this->startServer();
        $admin = $this->cookieFrom($this->send('POST', '/api/session/password', ['password' => 'geheim-123']));
        $anna = $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);
        $member = $this->cookieFrom($this->send('POST', '/api/session', ['name' => 'Anna', 'password' => 'annas-passwort']));

        // Mitglied: abhaken, Notiz, Rückgängig — im Verlauf mit Namen
        $uid = $this->dueReminder('Heizung warten', $admin);
        $r = $this->send('POST', '/api/agenda/done', ['uid' => $uid], $member);
        self::assertSame(201, $r['status']);
        self::assertSame(['type' => 'person', 'id' => $anna['id'], 'name' => 'Anna'], $r['json']['data']['entry']['by']);
        $id = $r['json']['data']['activity_id'];
        self::assertSame(200, $this->send('PATCH', "/api/activity/$id", ['note' => 'Filter neu'], $member)['status']);
        self::assertSame(200, $this->send('POST', "/api/activity/$id/undo", null, $member)['status']);
        self::assertSame(['type' => 'person', 'id' => $anna['id'], 'name' => 'Anna'],
            $this->json('GET', '/api/activity', null, $member)[0]['undone']['by'], 'Rückgängig: wer');

        // nur Verwalter: leeren und Aufbewahrung
        foreach ([['DELETE', '/api/activity', null], ['PATCH', '/api/settings', ['activity_keep_months' => 12]]] as [$m, $p, $b]) {
            $res = $this->send($m, $p, $b, $member);
            self::assertSame(403, $res['status'], "$m $p");
            self::assertSame('errors.auth.adminOnly', $res['json']['code'] ?? null);
        }
        self::assertSame(200, $this->send('PATCH', '/api/settings', ['activity_keep_months' => 12], $admin)['status']);
        self::assertSame(200, $this->send('PATCH', '/api/settings', ['activity_keep_months' => 12, 'dashboard_months' => 6], $member)['status'],
            'unverändert mitgeschickt sperrt nicht');

        // API-Schlüssel: Lese-Schlüssel gesperrt, Verwalter-Schlüssel steht mit Namen im Verlauf
        $read = $this->json('POST', '/api/auth/keys', ['name' => 'Anzeige', 'scope' => 'read'], $admin)['key'];
        $write = $this->json('POST', '/api/auth/keys', ['name' => 'Skript', 'scope' => 'admin'], $admin)['key'];
        $uid2 = $this->dueReminder('Schornsteinfeger', $admin);
        $ro = $this->send('POST', '/api/agenda/done', ['uid' => $uid2], ["Authorization: Bearer $read"]);
        self::assertSame(403, $ro['status']);
        self::assertSame('errors.auth.readOnlyKey', $ro['json']['code'] ?? null);
        self::assertSame(200, $this->send('GET', '/api/activity', null, ["Authorization: Bearer $read"])['status'], 'lesen darf er');
        $byKey = $this->send('POST', '/api/agenda/done', ['uid' => $uid2], ["Authorization: Bearer $write"]);
        self::assertSame(['type' => 'key', 'name' => 'Skript'], $byKey['json']['data']['entry']['by']);

        // Person löschen, Name im Verlauf ersetzen
        $del = $this->send('DELETE', '/api/users/' . $anna['id'] . '?anonymize_activity=1', null, $admin);
        self::assertTrue($del['json']['data']['activity_anonymized']);
        $all = $this->json('GET', '/api/activity', null, $admin);
        self::assertStringNotContainsString('Anna', json_encode($all));
        self::assertContains(['type' => 'person', 'former' => true], array_column($all, 'by'));

        // Verlauf leeren: Verwalter
        $clear = $this->send('DELETE', '/api/activity', null, $admin);
        self::assertSame(200, $clear['status']);
        self::assertSame(2, $clear['json']['data']['deleted']);
        self::assertSame([], $this->json('GET', '/api/activity', null, $admin));
    }
}
