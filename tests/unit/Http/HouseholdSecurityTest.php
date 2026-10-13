<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.4.0 (N1020) — Sicher im Haushalt, über HTTP gegen einen echten Server:
 * Proxy-Kopfzeilen, Sperre je Gerät, überall abmelden, gleitende Sitzung,
 * Größengrenzen vor dem Lesen, Schreibsperre mit Frist, Hostliste in den
 * Einstellungen. Jede Regel war vorher eine Lücke (Sicherheitsprüfung
 * 2026-10-12); beschrieben in docs/betrieb/sicherheit.md.
 */
final class HouseholdSecurityTest extends HttpServerTestCase
{
    private function session(array $headers = []): array
    {
        return (array)$this->json('GET', '/api/session', null, $headers);
    }

    private function cookieFrom(array $r): array
    {
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }

    private function post(string $path, array $payload, array $headers = []): array
    {
        return $this->request('POST', $path, array_merge(['Content-Type: application/json'], $headers), json_encode($payload));
    }

    /** Unter Home-Assistant-Ingress zählen nur X-Remote-User-Id/-Name; Personen über die Kennung. */
    public function testIngressTrustsOnlyTheSupervisorHeaders(): void
    {
        $this->startServer(['language' => 'de'], ['ET_AUTH' => 'proxy', 'ET_TRUSTED_PROXIES' => '127.0.0.1']);
        $ingress = ['X-Ingress-Path: /api/hassio_ingress/abc'];
        $anna = $this->session(array_merge($ingress, ['X-Remote-User-Id: ha-anna', 'X-Remote-User-Name: anna']));
        self::assertSame(['anna', 'admin'], [$anna['user']['name'], $anna['role']], 'die erste Person verwaltet');

        $bobHeaders = array_merge($ingress, ['X-Remote-User-Id: ha-bob', 'X-Remote-User-Name: bob']);
        $bob = $this->session($bobHeaders);
        self::assertSame(['bob', 'member'], [$bob['user']['name'], $bob['role']]);

        // Bob setzt Remote-User selbst — HA reicht das durch, die App ignoriert es unter Ingress
        $forged = $this->session(array_merge($bobHeaders, ['Remote-User: anna', 'X-Forwarded-User: anna']));
        self::assertSame(['bob', 'member'], [$forged['user']['name'], $forged['role']], 'selbst gesetzte Kopfzeile gewinnt nicht');
        $r = $this->request('GET', '/api/users', array_merge($bobHeaders, ['Remote-User: anna']));
        self::assertSame(403, $r['status']);

        // Umbenannt in HA: dieselbe Kennung, dieselbe Person
        $renamed = $this->session(array_merge($ingress, ['X-Remote-User-Id: ha-anna', 'X-Remote-User-Name: anna-maria']));
        self::assertSame([$anna['user']['id'], 'admin'], [$renamed['user']['id'], $renamed['role']]);
    }

    /** ET_PROXY_USER_HEADER gilt allein; ohne Einstellung rät die Diagnose dazu. */
    public function testConfiguredProxyHeaderWinsAndTheLegacyModeWarns(): void
    {
        $this->startServer(['language' => 'de'], ['ET_AUTH' => 'proxy', 'ET_TRUSTED_PROXIES' => '127.0.0.1',
            'ET_PROXY_USER_HEADER' => 'X-Forwarded-User']);
        $s = $this->session(['X-Forwarded-User: carla', 'Remote-User: mallory']);
        self::assertSame('carla', $s['user']['name']);
        // Ingress-Kopfzeilen ändern an der Einstellung nichts
        $s = $this->session(['X-Ingress-Path: /x', 'X-Remote-User-Id: mallory', 'X-Forwarded-User: carla']);
        self::assertSame('carla', $s['user']['name']);
        self::assertSame(401, $this->request('GET', '/api/utilities', ['Remote-User: mallory'])['status'], 'nur die eine Kopfzeile');
        $diag = $this->json('GET', '/api/diagnostics', null, ['X-Forwarded-User: carla']);
        self::assertSame([], $diag['security']['notices']);
        self::assertSame('configured', $diag['security']['proxy_header']['mode']);
    }

    public function testLegacyProxyModeShowsAWarning(): void
    {
        $this->startServer(['language' => 'de'], ['ET_AUTH' => 'proxy', 'ET_TRUSTED_PROXIES' => '127.0.0.1']);
        $diag = $this->json('GET', '/api/diagnostics', null, ['Remote-User: dora']);
        self::assertSame('legacy', $diag['security']['proxy_header']['mode'], 'bisheriges Verhalten bleibt');
        self::assertSame(['proxy_header'], array_column($diag['security']['notices'], 'code'));
    }

    /** Fünf Fehlversuche sperren das Gerät, nicht den Haushalt (Adresse vom vertrauenswürdigen Proxy). */
    public function testTheLockIsPerDevice(): void
    {
        $this->startServer(['language' => 'de'], ['ET_TRUSTED_PROXIES' => '127.0.0.1']);
        $this->post('/api/session/password', ['password' => 'geheim-123']);
        $a = ['X-Forwarded-For: 192.168.178.20'];
        $codes = [];
        for ($i = 0; $i < 6; $i++) $codes[] = $this->post('/api/session', ['password' => 'falsch'], $a)['status'];
        self::assertSame([401, 401, 401, 401, 429, 429], $codes);
        self::assertSame(429, $this->post('/api/session', ['password' => 'geheim-123'], $a)['status'], 'dieses Gerät wartet');
        self::assertSame(200, $this->post('/api/session', ['password' => 'geheim-123'], ['X-Forwarded-For: 192.168.178.21'])['status'],
            'ein anderes Gerät meldet sich an');
    }

    /** „Überall abmelden" beendet auch die Sitzungen anderer Geräte. */
    public function testLogoutEverywhereEndsOtherSessions(): void
    {
        $this->startServer();
        $this->post('/api/session/password', ['password' => 'geheim-123']);
        $phone = $this->cookieFrom($this->post('/api/session', ['password' => 'geheim-123']));
        $laptop = $this->cookieFrom($this->post('/api/session', ['password' => 'geheim-123']));
        self::assertSame(200, $this->request('GET', '/api/utilities', $phone)['status']);

        $r = $this->request('POST', '/api/session/logout-everywhere', $laptop);
        self::assertSame(200, $r['status']);
        self::assertSame(401, $this->request('GET', '/api/utilities', $phone)['status'], 'das andere Gerät ist abgemeldet');
        self::assertSame(401, $this->request('GET', '/api/utilities', $laptop)['status'], 'dieses auch');
        self::assertSame(200, $this->post('/api/session', ['password' => 'geheim-123'])['status'], 'neu anmelden geht');
    }

    /** Eine benutzte Sitzung verlängert sich (gleitend), eine frische nicht. */
    public function testSessionsSlideForward(): void
    {
        $this->startServer();
        $this->post('/api/session/password', ['password' => 'geheim-123']);
        $fresh = $this->cookieFrom($this->post('/api/session', ['password' => 'geheim-123']));
        self::assertArrayNotHasKey('set-cookie', $this->request('GET', '/api/utilities', $fresh)['headers'], 'frisch: kein neues Cookie');

        $auth = json_decode((string)file_get_contents($this->dataDir . '/auth.json'), true);
        $exp = (string)(time() + 20 * 86400);
        $old = $exp . '.u_admin.' . hash_hmac('sha256', 'et-session|' . $exp . '|u_admin', (string)$auth['session_secret']);
        $r = $this->request('GET', '/api/utilities', ['Cookie: et_session=' . $old]);
        self::assertSame(200, $r['status']);
        self::assertMatchesRegularExpression('/et_session=(\d+)\.u_admin\./', $r['headers']['set-cookie'] ?? '', 'zehn Tage alt: neues Cookie');
        preg_match('/et_session=(\d+)\./', $r['headers']['set-cookie'], $m);
        self::assertGreaterThan(time() + 29 * 86400, (int)$m[1], 'wieder volle 30 Tage');
    }

    /** Größe vor dem Lesen: ohne Anmeldung erreichbare Routen 1 MB, sonst 8 MB, Importe 256 MB. */
    public function testBodyLimitsApplyBeforeReading(): void
    {
        $this->startServer();
        $big = str_repeat('x', 1200 * 1024);
        $r = $this->request('POST', '/api/ingest', ['Content-Type: application/json'], $big);
        self::assertSame(413, $r['status'], 'Ingest (ohne Anmeldung erreichbar) höchstens 1 MB');
        self::assertSame('errors.http.tooLarge', json_decode($r['body'], true)['code'] ?? null);
        self::assertNotSame(413, $this->request('PATCH', '/api/settings', ['Content-Type: application/json'], $big)['status'],
            'angemeldet bzw. offen: bis 8 MB');

        $nine = str_repeat('x', 9 * 1048576);
        self::assertSame(413, $this->request('PATCH', '/api/settings', ['Content-Type: application/json'], $nine)['status']);
        self::assertNotSame(413, $this->request('POST', '/api/backup/import', ['Content-Type: application/json'], $nine)['status'],
            'Backup-Import darf groß sein');

        // Mit Anmeldung: ohne Sitzung 401, bevor ein Byte gelesen wird
        $this->post('/api/session/password', ['password' => 'geheim-123']);
        self::assertSame(401, $this->request('POST', '/api/backup/import', ['Content-Type: application/json'], $nine)['status']);
    }

    /** Hält jemand die Schreibsperre, kommt nach der Frist 503 mit Retry-After; Texterkennung wartet nicht. */
    public function testTheWriteLockHasADeadline(): void
    {
        $this->startServer(['language' => 'de'], ['ET_LOCK_WAIT_S' => '1']);
        $fp = fopen($this->dataDir . '/.write.lock', 'c');
        self::assertTrue(flock($fp, LOCK_EX));
        try {
            $t0 = microtime(true);
            $r = $this->request('PATCH', '/api/settings', ['Content-Type: application/json'], json_encode(['wohnflaeche_m2' => 91]));
            self::assertSame(503, $r['status']);
            self::assertSame('5', $r['headers']['retry-after'] ?? null);
            self::assertSame('errors.http.busy', json_decode($r['body'], true)['code'] ?? null);
            self::assertLessThan(10, microtime(true) - $t0, 'nach der Frist, nicht unbegrenzt');

            // Die Texterkennung schreibt nichts und sperrt nicht vorab: ihre eigene Antwort, kein 503
            $ocr = $this->post('/api/ocr/reading', ['attachment_id' => 'att_0000000000000000']);
            self::assertNotSame(503, $ocr['status']);
            self::assertSame(400, $ocr['status']);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        self::assertSame(200, $this->request('PATCH', '/api/settings', ['Content-Type: application/json'], json_encode(['wohnflaeche_m2' => 92]))['status']);
    }

    /** Hostliste in den Einstellungen: wirkt wie ET_ALLOWED_HOSTS, sperrt aber niemanden aus. */
    public function testAllowedHostsSettingWithoutLockout(): void
    {
        $this->startServer();
        $open = $this->json('GET', '/api/diagnostics', null, ['Host: nas.local']);
        self::assertSame(['open_without_hosts'], array_column($open['security']['notices'], 'code'));
        self::assertSame('nas.local', $open['security']['notices'][0]['host'], 'Vorschlag: aktuellen Host übernehmen');

        $r = $this->request('PATCH', '/api/settings', ['Content-Type: application/json', 'Host: nas.local'],
            json_encode(['allowed_hosts' => 'energie.example.org']));
        self::assertSame(400, $r['status'], 'ohne den aktuellen Namen: abgelehnt');
        self::assertSame('errors.settings.hostNotListed', json_decode($r['body'], true)['code'] ?? null);

        $r = $this->request('PATCH', '/api/settings', ['Content-Type: application/json', 'Host: nas.local'],
            json_encode(['allowed_hosts' => 'nas.local, *.home.arpa']));
        self::assertSame(200, $r['status']);
        self::assertSame('nas.local, *.home.arpa', json_decode($r['body'], true)['data']['allowed_hosts']);

        self::assertSame(421, $this->request('GET', '/api/utilities', ['Host: rebind.attacker.example'])['status']);
        self::assertSame(200, $this->request('GET', '/api/utilities', ['Host: box.home.arpa'])['status']);
        self::assertSame(200, $this->request('GET', '/api/utilities', ['Host: 192.168.178.4'])['status'], 'IP-Adressen immer');
        self::assertSame([], $this->json('GET', '/api/diagnostics', null, ['Host: nas.local'])['security']['notices']);

        // Über eine IP-Adresse aufgerufen gilt jede Liste (man bleibt erreichbar)
        self::assertSame(200, $this->request('PATCH', '/api/settings', ['Content-Type: application/json', 'Host: 127.0.0.1'],
            json_encode(['allowed_hosts' => 'energie.example.org']))['status']);
        self::assertSame(400, $this->request('PATCH', '/api/settings', ['Content-Type: application/json', 'Host: 127.0.0.1'],
            json_encode(['allowed_hosts' => 'https://energie.example.org/x']))['status'], 'nur Namen, keine Adressen');
    }
}
