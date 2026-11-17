<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\App;
use Energietracker\Services\SettingsService;
use Energietracker\Tests\Support\HttpServerTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * v3.13.0 (K2d) — Härtung: Jeder Schlüssel des SettingsService gehört einer Rolle. Was nur Verwalter ändern, steht in
 * App::ADMIN_SETTINGS, was auch Mitglieder ändern, in App::MEMBER_SETTINGS; die Angaben für Briefe hängen am Schalter
 * `letters_admin_only`. Ein neuer Schlüssel ohne Zuordnung macht diesen Test rot, statt still für Mitglieder offen
 * zu sein. Dazu: Weitere Einstellungen der Texterkennung, des Auslesens und der Benachrichtigung an Home Assistant
 * ändern nur Verwalter; ein unveränderter Wert, den eine Seite mitschickt, bleibt für Mitglieder erlaubt.
 */
#[CoversNothing]
final class SettingsRoleMapTest extends HttpServerTestCase
{
    /** @return list<string> */
    private static function constant(string $name): array
    {
        return (new \ReflectionClassConstant(App::class, $name))->getValue();
    }

    public function testEverySettingBelongsToExactlyOneRole(): void
    {
        $admin = self::constant('ADMIN_SETTINGS');
        $member = self::constant('MEMBER_SETTINGS');
        $letters = SettingsService::LETTER_KEYS;
        self::assertSame([], array_values(array_intersect($admin, $member)), 'Schlüssel in beiden Listen');
        self::assertSame([], array_values(array_intersect($letters, [...$admin, ...$member])), 'Briefangaben hängen am Schalter, nicht an einer Liste');
        $known = (new SettingsService(new \Energietracker\Storage\JsonStore(sys_get_temp_dir() . '/et-k2d-roles-' . bin2hex(random_bytes(3)))))->knownKeys();
        $unassigned = array_values(array_diff($known, $admin, $member, $letters));
        self::assertSame([], $unassigned, 'Schlüssel ohne Rolle (in App::ADMIN_SETTINGS oder App::MEMBER_SETTINGS eintragen)');
        self::assertSame([], array_values(array_diff([...$admin, ...$member], $known)), 'Listen nennen Schlüssel, die es nicht gibt');
        foreach (['ha_notify_events', 'ha_notify_hour', 'inbox_model', 'inbox_timeout_s', 'inbox_cloud_timeout_s', 'ocr_api', 'ocr_model', 'ocr_timeout_s'] as $k) {
            self::assertContains($k, $admin, "$k nur für Verwalter");
        }
    }

    private function login(string $name, string $password): array
    {
        $r = $this->request('POST', '/api/session', ['Content-Type: application/json'], json_encode(['name' => $name, 'password' => $password]));
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }

    public function testAMemberCannotChangeTheReadingAndNotificationSettings(): void
    {
        $this->startServer();
        $r = $this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'Verwalter-Probe-7']));
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        $admin = ['Cookie: et_session=' . ($m[1] ?? '')];
        $this->json('POST', '/api/users', ['name' => 'Kim', 'password' => 'kims-passwort-4', 'role' => 'member'], $admin);
        $member = $this->login('Kim', 'kims-passwort-4');
        $h = fn(array $who) => array_merge(['Content-Type: application/json'], $who);
        $before = (array)$this->json('GET', '/api/settings', null, $admin);

        $changes = ['ha_notify_events' => ['reminder'], 'ha_notify_hour' => 5, 'inbox_model' => 'ein-anderes-modell', 'inbox_timeout_s' => 45,
                    'inbox_cloud_timeout_s' => 20, 'ocr_api' => 'openai', 'ocr_model' => 'ein-bildmodell', 'ocr_timeout_s' => 9,
                    // v3.13.0 — der Speicher für Belege gilt für den ganzen Haushalt
                    'attachments_max_mb' => 750];
        foreach ($changes as $k => $v) {
            $res = $this->request('PATCH', '/api/settings', $h($member), json_encode([$k => $v]));
            self::assertSame(403, $res['status'], "$k: {$res['body']}");
            self::assertSame('errors.auth.adminOnly', json_decode($res['body'], true)['code'] ?? null, $k);
        }
        $after = (array)$this->json('GET', '/api/settings', null, $admin);
        foreach (array_keys($changes) as $k) self::assertSame($before[$k] ?? null, $after[$k] ?? null, "$k unverändert");

        // eine Seite, die alle ihre Felder unverändert mitschickt, bleibt für Mitglieder erlaubt (auch die Liste)
        $same = array_intersect_key($before, $changes) + ['dashboard_months' => 18];
        $res = $this->request('PATCH', '/api/settings', $h($member), json_encode($same));
        self::assertSame(200, $res['status'], $res['body']);
        // der Verwalter darf
        $res = $this->request('PATCH', '/api/settings', $h($admin), json_encode($changes));
        self::assertSame(200, $res['status'], $res['body']);
    }
}
