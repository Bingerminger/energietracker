<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Services\HomeAssistant\NotifyConfig;
use Energietracker\Services\HomeAssistantNotifyService;
use Energietracker\Tests\Support\FakeHomeAssistant;
use Energietracker\Tests\Support\HttpServerTestCase;
use Energietracker\Tests\Support\InboxFixture;

/**
 * v3.9.0 (F1033, GitHub #36) — Sensor „Zu tun“ und Benachrichtigungen über
 * HTTP: die App auf einem freien Port, ein nachgebautes Home Assistant auf
 * Port 18741 (FakeHomeAssistant). Was nur hier sichtbar wird: die Arbeit nach
 * der Antwort (App::afterResponse), Geheimnisse in keiner Antwort, wer was
 * darf.
 */
final class HomeAssistantEndpointTest extends HttpServerTestCase
{
    private static ?FakeHomeAssistant $ha = null;

    public static function setUpBeforeClass(): void
    {
        self::$ha = new FakeHomeAssistant();
    }

    public static function tearDownAfterClass(): void
    {
        self::$ha?->stop();
        self::$ha = null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$ha->reset();
    }

    /** Anfragen an das nachgebaute Home Assistant, bis `$n` da sind (die Arbeit läuft nach der Antwort). */
    private function haRequests(int $n, float $seconds = 5.0): array
    {
        $until = microtime(true) + $seconds;
        do {
            $r = self::$ha->requests();
            if (count($r) >= $n) return $r;
            usleep(50_000);
        } while (microtime(true) < $until);
        return self::$ha->requests();
    }

    private function login(): array
    {
        $r = $this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'geheim-123']));
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }

    public function testToDoCountsTheDueItemsForASensor(): void
    {
        $this->startServer();
        $t = $this->json('GET', '/api/todo');
        // frische Installation: drei Zähler ohne Ablesung
        self::assertSame(3, $t['count']);
        self::assertSame(0, $t['overdue']);
        self::assertSame(1, $t['todo_version']);
        self::assertMatchesRegularExpression('/^et_[0-9a-f]{16}$/', $t['instance_id']);
        self::assertSame(['uid', 'kind', 'title', 'date', 'severity', 'href'], array_keys($t['next']));
        self::assertSame('reading_due', $t['next']['kind']);
        self::assertStringContainsString('Zählerstand ablesen', $t['next']['title'], 'Sprache der Installation (de)');
        self::assertCount(3, $t['items']);
        // ein Termin, der fällig ist, zählt mit
        $this->json('POST', '/api/reminders', ['title' => 'Rauchmelder prüfen', 'category' => 'custom', 'next_due' => date('Y-m-d', strtotime('-2 days'))]);
        $t = $this->json('GET', '/api/todo');
        self::assertSame(4, $t['count']);
        self::assertSame('Rauchmelder prüfen', $t['next']['title'], 'Überfälliges zuerst');
        self::assertSame('overdue', $t['next']['severity']);
        self::assertSame(1, $t['overdue']);
        // /api/summary additiv: der Name der Verbrauchsart (HACS-Integration benennt so die Geräte)
        $labels = array_column($this->json('GET', '/api/summary')['meters'], 'utility_label', 'utility');
        self::assertSame('Strom', $labels['strom'] ?? null);
        $en = array_column($this->json('GET', '/api/summary', null, ['X-ET-Language: en'])['meters'], 'utility_label', 'utility');
        self::assertSame('Electricity', $en['strom'] ?? null, 'in der Sprache der Anfrage');
    }

    public function testSecretsAppearInNoAnswer(): void
    {
        $this->startServer();
        $webhook = 'Hook_' . bin2hex(random_bytes(8));
        $token = 'eyJ' . bin2hex(random_bytes(24));
        $r = $this->request('PATCH', '/api/homeassistant/notify', ['Content-Type: application/json'],
            json_encode(['target' => 'token', 'url' => self::$ha->base, 'webhook_id' => $webhook, 'token' => $token]));
        self::assertSame(200, $r['status'], $r['body']);
        foreach (['/api/homeassistant/notify', '/api/settings', '/api/backup/export', '/api/diagnostics'] as $path) {
            $body = $this->request('GET', $path)['body'];
            self::assertStringNotContainsString($token, $body, $path);
            self::assertStringNotContainsString($webhook, $body, $path);
        }
        self::assertStringNotContainsString($token, $r['body'], 'auch nicht in der Antwort auf das Speichern');
        $s = $this->json('GET', '/api/homeassistant/notify');
        self::assertSame([true, true, false], [$s['has_webhook'], $s['has_token'], $s['supervisor_available']]);
        self::assertFileExists($this->dataDir . '/' . NotifyConfig::SECRET_FILE);
    }

    public function testTheTestMessageReachesHomeAssistant(): void
    {
        $this->startServer();
        $this->json('PATCH', '/api/homeassistant/notify', ['target' => 'token', 'url' => self::$ha->base, 'token' => FakeHomeAssistant::TOKEN]);
        $r = $this->json('POST', '/api/homeassistant/notify/test');
        self::assertSame(['ok' => true, 'status' => 200, 'error' => null, 'target' => 'token'], $r);
        $req = $this->haRequests(1)[0];
        self::assertSame('/api/services/persistent_notification/create', $req['path']);
        self::assertSame('Bearer ' . FakeHomeAssistant::TOKEN, $req['headers']['authorization']);
        self::assertSame('Energietracker: Testnachricht', $req['body']['title']);
        // falscher Token: das sagt die Antwort und danach der Stand
        $this->json('PATCH', '/api/homeassistant/notify', ['token' => 'eyJ' . str_repeat('0', 30)]);
        $r = $this->json('POST', '/api/homeassistant/notify/test');
        self::assertSame([false, 401, 'http'], [$r['ok'], $r['status'], $r['error']]);
        self::assertSame(401, $this->json('GET', '/api/homeassistant/notify')['last']['status']);
    }

    public function testTheDailyCheckRunsAfterTheResponseAndOnlyOnce(): void
    {
        $this->startServer();
        $this->json('PATCH', '/api/homeassistant/notify', ['target' => 'webhook', 'url' => self::$ha->base,
            'webhook_id' => 'Energietracker_Test_1', 'hour' => 0, 'enabled' => true]);
        // die Antwort auf das Speichern stößt die Prüfung an: drei fällige Ablesungen
        $reqs = $this->haRequests(3);
        self::assertCount(3, $reqs);
        self::assertSame(['/api/webhook/Energietracker_Test_1'], array_values(array_unique(array_column($reqs, 'path'))));
        self::assertSame(['reading_due'], array_values(array_unique(array_map(fn($r) => $r['body']['kind'], $reqs))));
        self::assertSame('Energietracker: Zu tun', $reqs[0]['body']['title']);
        // weitere Anfragen (etwa der Gesundheitscheck): nichts doppelt
        $this->request('GET', '/api/health');
        $this->request('GET', '/api/todo');
        usleep(300_000);
        self::assertCount(3, self::$ha->requests());
        self::assertSame(date('Y-m-d'), $this->json('GET', '/api/homeassistant/notify')['daily_on']);
    }

    public function testANewDocumentIsReportedAfterTheUpload(): void
    {
        $this->startServer();
        $this->json('PATCH', '/api/homeassistant/notify', ['target' => 'webhook', 'url' => self::$ha->base,
            'webhook_id' => 'Energietracker_Test_2', 'events' => ['document'], 'enabled' => true]);
        $up = $this->request('POST', '/api/inbox?name=Jahresrechnung%20Strom.pdf', ['Content-Type: application/pdf'], InboxFixture::pdf('ha-doc'));
        self::assertSame(201, $up['status'], $up['body']);
        $reqs = $this->haRequests(1);
        self::assertCount(1, $reqs);
        self::assertSame('document', $reqs[0]['body']['event']);
        self::assertSame('Neues Dokument im Posteingang: Jahresrechnung Strom.pdf', $reqs[0]['body']['message']);
        self::assertSame(json_decode($up['body'], true)['data']['id'], $reqs[0]['body']['items'][0]['id']);
        // der zweite gleich danach wartet auf die Sammelnachricht (DOC_DEBOUNCE_S)
        $this->request('POST', '/api/inbox?name=Abschlag.pdf', ['Content-Type: application/pdf'], InboxFixture::pdf('ha-doc-2'));
        usleep(300_000);
        self::assertCount(1, self::$ha->requests());
        self::assertTrue((bool)json_decode((string)file_get_contents($this->dataDir . '/' . HomeAssistantNotifyService::LOG_FILE), true)['docs_pending']);
    }

    public function testOnlyAdminsChangeTheDestination(): void
    {
        $this->startServer();
        $admin = $this->login();
        $read = (string)$this->json('POST', '/api/auth/keys', ['name' => 'HA', 'scope' => 'read'], $admin)['key'];
        $this->json('POST', '/api/users', ['name' => 'Mia', 'password' => 'mias-passwort', 'role' => 'member'], $admin);
        $r = $this->request('POST', '/api/session', ['Content-Type: application/json'], json_encode(['name' => 'Mia', 'password' => 'mias-passwort']));
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        $member = ['Cookie: et_session=' . ($m[1] ?? '')];
        $patch = json_encode(['target' => 'webhook', 'url' => self::$ha->base]);

        self::assertSame(200, $this->request('GET', '/api/todo', ["Authorization: Bearer $read"])['status'], 'der Sensor liest mit einem Lese-Schlüssel');
        self::assertSame(200, $this->request('GET', '/api/homeassistant/notify', ["Authorization: Bearer $read"])['status']);
        self::assertSame(403, $this->request('PATCH', '/api/homeassistant/notify', ['Content-Type: application/json', "Authorization: Bearer $read"], $patch)['status']);
        self::assertSame(403, $this->request('POST', '/api/homeassistant/notify/test', ["Authorization: Bearer $read"])['status']);
        self::assertSame(403, $this->request('PATCH', '/api/homeassistant/notify', array_merge(['Content-Type: application/json'], $member), $patch)['status']);
        self::assertSame(403, $this->request('POST', '/api/homeassistant/notify/test', $member)['status']);
        // auch nicht am Umweg über die Einstellungen vorbei
        self::assertSame(403, $this->request('PATCH', '/api/settings', array_merge(['Content-Type: application/json'], $member),
            json_encode(['ha_notify_url' => self::$ha->base]))['status']);
        self::assertSame(200, $this->request('PATCH', '/api/homeassistant/notify', array_merge(['Content-Type: application/json'], $admin), $patch)['status']);
        self::assertSame(401, $this->request('GET', '/api/todo')['status'], 'ohne Schlüssel mit eingeschalteter Anmeldung');
    }
}
