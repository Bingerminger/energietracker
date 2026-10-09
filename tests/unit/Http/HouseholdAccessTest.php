<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.2.0 (F1023) — Verwalter und Mitglieder über HTTP: Anmeldung mit Namen,
 * eigene Einstellungen, und was nur Verwalter dürfen (Personen, Schlüssel,
 * Backups einspielen, Netzadressen).
 */
final class HouseholdAccessTest extends HttpServerTestCase
{
    private function cookieFrom(array $r): array
    {
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }

    private function post(string $path, array $payload, array $headers = []): array
    {
        return $this->request('POST', $path, array_merge(['Content-Type: application/json'], $headers), json_encode($payload));
    }

    public function testMembersRecordAndAdminsManageAccess(): void
    {
        $this->startServer();
        $admin = $this->cookieFrom($this->post('/api/session/password', ['password' => 'geheim-123']));
        self::assertFalse($this->json('GET', '/api/session')['named_login'], 'noch keine Personen: nur Passwort');

        $anna = $this->json('POST', '/api/users', ['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member'], $admin);
        self::assertSame('member', $anna['role']);
        self::assertTrue($this->json('GET', '/api/session')['named_login'], 'jetzt fragt die Anmeldung nach dem Namen');

        $login = $this->post('/api/session', ['name' => 'Anna', 'password' => 'annas-passwort']);
        self::assertSame(200, $login['status']);
        self::assertMatchesRegularExpression('/et_session=\d+\.u_[0-9a-f]+\.[0-9a-f]{64}/', $login['headers']['set-cookie'] ?? '');
        $member = $this->cookieFrom($login);

        $me = $this->json('GET', '/api/session', null, $member);
        self::assertSame(['Anna', 'member'], [$me['user']['name'], $me['role']]);

        // Mitglieder erfassen und ändern Haushaltsdaten …
        self::assertSame(200, $this->request('PATCH', '/api/settings', array_merge(['Content-Type: application/json'], $member), json_encode(['wohnflaeche_m2' => 80]))['status']);
        // … und ihre eigene Stufe, ohne die der Installation zu ändern
        $prefs = $this->json('PATCH', '/api/session/me', ['ui_level' => 'beginner'], $member);
        self::assertSame('beginner', $prefs['prefs']['ui_level']);
        self::assertSame('expert', $this->json('GET', '/api/settings', null, $admin)['ui_level']);

        // Nur Verwalter: Personen, Schlüssel, Backups einspielen, Netzadressen
        foreach ([
            ['GET', '/api/users', null],
            ['POST', '/api/auth/keys', ['name' => 'x', 'scope' => 'read']],
            ['POST', '/api/backup/import', ['backup_version' => '3.0']],
            ['POST', '/api/demo/import', ['force' => true]],
            ['PATCH', '/api/settings', ['ocr_endpoint' => 'http://192.168.178.20:11434']],
            ['PATCH', '/api/settings', ['evcc_endpoint' => 'http://192.168.178.30:7070']],
            ['DELETE', '/api/session/password', ['current' => 'annas-passwort']],
        ] as [$method, $path, $body]) {
            $r = $this->request($method, $path, array_merge(['Content-Type: application/json'], $member), $body === null ? null : json_encode($body));
            self::assertSame(403, $r['status'], "$method $path");
            self::assertSame('errors.auth.adminOnly', json_decode($r['body'], true)['code'] ?? null, "$method $path");
        }
        self::assertSame(200, $this->request('GET', '/api/users', $admin)['status'], 'der Verwalter darf');
        // Die Seite „Experte“ schickt alle Felder mit — unveränderte Adressen sperren das Speichern nicht
        self::assertSame(200, $this->request('PATCH', '/api/settings', array_merge(['Content-Type: application/json'], $member),
            json_encode(['ocr_endpoint' => '', 'evcc_endpoint' => '', 'anomaly_threshold' => 2.5]))['status']);

        // Eigenes Passwort ändern: mit dem bisherigen
        self::assertSame(401, $this->post('/api/session/me/password', ['current' => 'falsch', 'password' => 'neues-passwort'], $member)['status']);
        self::assertSame(200, $this->post('/api/session/me/password', ['current' => 'annas-passwort', 'password' => 'neues-passwort'], $member)['status']);
        self::assertSame(200, $this->post('/api/session', ['name' => 'anna', 'password' => 'neues-passwort'])['status']);
    }
}
