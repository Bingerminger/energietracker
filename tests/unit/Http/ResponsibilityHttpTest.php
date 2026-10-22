<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.7.0 — Zuständig an Zählern und Verträgen über HTTP: `assignee_id`
 * schreiben (nur Personen aus dem Verzeichnis, sonst 400
 * `errors.assignee.unknown`), Antworten mit Namen, Termine am Zähler erben,
 * Vorschau und Löschen einer Person mit Zählern und Verträgen, Kalender je
 * Person (`?person=`).
 */
final class ResponsibilityHttpTest extends HttpServerTestCase
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

    private function seedPeople(): void
    {
        file_put_contents($this->dataDir . '/people.json', json_encode(['last_no' => 2, 'people' => [
            ['id' => 'u_aaaa0001', 'no' => 1, 'name' => 'Lena', 'role' => 'admin', 'created_at' => '2025-01-01T09:00:00+01:00'],
            ['id' => 'u_bbbb0002', 'no' => 2, 'name' => 'Tom', 'role' => 'member', 'created_at' => '2025-01-01T09:00:00+01:00'],
        ]]));
    }

    public function testMetersAndContractsTakeAPersonAndRemindersInheritIt(): void
    {
        $this->startServer();
        $this->seedPeople();
        $m = $this->json('POST', '/api/utility/strom/meters', ['name' => 'Haus', 'assignee_id' => 'u_bbbb0002']);
        self::assertSame(['u_bbbb0002', 'Tom', false, false], [$m['assignee_id'], $m['assignee'], $m['assignee_former'], $m['assignee_deleted']]);
        $listed = array_column($this->json('GET', '/api/utility/strom/meters'), null, 'id')[$m['id']];
        self::assertSame('Tom', $listed['assignee']);
        $plain = array_values(array_filter($this->json('GET', '/api/utility/strom/meters'), fn($x) => $x['id'] !== $m['id']));
        foreach ($plain as $x) self::assertSame([null, null], [$x['assignee'], $x['assignee_id']], 'ohne Zuständigkeit: null (additiv)');

        $bad = $this->send('PATCH', '/api/utility/strom/meters/' . $m['id'], ['assignee_id' => 'u_zzzz9999']);
        self::assertSame([400, 'errors.assignee.unknown'], [$bad['status'], $bad['json']['code'] ?? null]);

        $day = date('Y-m-d', strtotime('-200 days'));
        $c = $this->json('POST', '/api/utility/strom/contracts', ['meter_id' => $m['id'], 'provider' => 'Werk', 'start' => $day,
            'working_prices' => [['from' => $day, 'ct_per_kwh' => 30]], 'assignee_id' => 'u_aaaa0001']);
        self::assertSame(['u_aaaa0001', 'Lena'], [$c['assignee_id'], $c['assignee']]);
        $cl = array_column($this->json('GET', '/api/utility/strom/contracts'), null, 'id')[$c['id']];
        self::assertSame('Lena', $cl['assignee']);
        self::assertSame('Lena', $this->json('GET', '/api/utility/strom/contracts/' . $c['id'])['assignee']);

        // Termin am Zähler: GET /api/reminders trägt die geerbte Zuständigkeit
        $r = $this->json('POST', '/api/reminders', ['title' => 'Eichfrist', 'category' => 'stromzaehler_eichung', 'next_due' => date('Y-m-d', strtotime('+10 days')),
            'utility' => 'strom', 'meter_id' => $m['id']]);
        self::assertSame(['strom', $m['id']], [$r['utility'], $r['meter_id']]);
        $rl = array_column($this->json('GET', '/api/reminders'), null, 'id')[$r['id']];
        self::assertSame([null, 'Tom', 'meter'], [$rl['assignee'], $rl['assignee_inherited']['assignee'], $rl['assignee_inherited']['assignee_source']]);
        $wrong = $this->send('POST', '/api/reminders', ['title' => 'X', 'next_due' => date('Y-m-d'), 'utility' => 'strom', 'meter_id' => 'm_weg']);
        self::assertSame([400, 'errors.reminder.meterUnknown'], [$wrong['status'], $wrong['json']['code'] ?? null]);

        // Agenda: das Ereignis des Termins nennt die Person samt Herkunft
        $ev = array_column($this->json('GET', '/api/agenda?days=30')['events'], null, 'uid')['reminder-' . $r['id']];
        self::assertSame(['Tom', 'u_bbbb0002', 'meter'], [$ev['assignee'], $ev['assignee_id'], $ev['assignee_source']]);
    }

    public function testDeletingAPersonPreviewsAndHandsOverMetersAndContracts(): void
    {
        $this->startServer();
        $this->seedPeople();
        $m = $this->json('POST', '/api/utility/gas/meters', ['name' => 'Keller', 'assignee_id' => 'u_bbbb0002']);
        $day = date('Y-m-d', strtotime('-100 days'));
        $this->json('POST', '/api/utility/gas/contracts', ['meter_id' => $m['id'], 'provider' => 'Werk', 'start' => $day,
            'working_prices' => [['from' => $day, 'ct_per_kwh' => 9]], 'assignee_id' => 'u_bbbb0002']);

        // ohne Anmeldung: niemand übernimmt, die Person bleibt mit „(gelöscht)“
        $pre = $this->json('GET', '/api/users/u_bbbb0002/handover');
        self::assertSame([null, 1, 1], [$pre['to'], $pre['meters'], $pre['contracts']]);
        $del = $this->send('DELETE', '/api/users/u_bbbb0002');
        self::assertSame([0, 2], [$del['json']['data']['records_handed_over'], $del['json']['data']['records_kept']]);
        $mm = $this->json('GET', '/api/utility/gas/meters/' . $m['id']);
        self::assertSame(['Tom', true], [$mm['assignee'], $mm['assignee_deleted']]);

        // mit Anmeldung: die löschende Person übernimmt
        $admin = $this->cookieFrom($this->send('POST', '/api/session/password', ['password' => 'geheim-123']));
        $this->json('PATCH', '/api/utility/gas/meters/' . $m['id'], ['assignee_id' => 'u_aaaa0001'], $admin);
        self::assertSame(1, $this->json('GET', '/api/users/u_aaaa0001/handover', null, $admin)['meters']);
        $del2 = $this->send('DELETE', '/api/users/u_aaaa0001', null, $admin);
        self::assertSame([1, 0, 'admin'], [$del2['json']['data']['records_handed_over'], $del2['json']['data']['records_kept'],
            $del2['json']['data']['handed_over_to']['name']]);
        self::assertSame('admin', $this->json('GET', '/api/utility/gas/meters/' . $m['id'], null, $admin)['assignee']);
    }

    public function testTheCalendarFiltersByPerson(): void
    {
        $this->startServer();
        $this->seedPeople();
        $m = $this->json('POST', '/api/utility/strom/meters', ['name' => 'Haus', 'assignee_id' => 'u_aaaa0001']);
        $a = $this->json('POST', '/api/reminders', ['title' => 'Eichfrist', 'next_due' => date('Y-m-d', strtotime('+5 days')),
            'utility' => 'strom', 'meter_id' => $m['id']]);
        $b = $this->json('POST', '/api/reminders', ['title' => 'Fenster', 'next_due' => date('Y-m-d', strtotime('+6 days')), 'assignee_id' => 'u_bbbb0002']);
        $lena = $this->request('GET', '/api/calendar.ics?person=u_aaaa0001')['body'];
        self::assertStringContainsString('reminder-' . $a['id'] . '@', $lena);
        self::assertStringNotContainsString('reminder-' . $b['id'] . '@', $lena);
        self::assertStringContainsString('Lena', $lena);
        self::assertStringContainsString('reminder-' . $b['id'] . '@', $this->request('GET', '/api/calendar.ics')['body'], 'ohne Filter: alles');
        $bad = $this->request('GET', '/api/calendar.ics?person=' . rawurlencode('<x>'));
        self::assertSame(400, $bad['status']);
    }
}
