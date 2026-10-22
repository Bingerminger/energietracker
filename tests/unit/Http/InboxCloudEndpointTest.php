<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Services\Inbox\CloudConfig;
use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.7.0 (Paket cloud) — Auslesen in der Cloud über HTTP: Der API-Schlüssel
 * kommt in keiner Antwort, keinem Backup, keinem Snapshot und keinem Log vor;
 * einschalten dürfen nur Verwalter; ein eingespieltes Backup bringt keinen
 * Schlüssel mit. Kein Aufruf eines Cloud-Dienstes — hier wird nur gespeichert.
 */
final class InboxCloudEndpointTest extends HttpServerTestCase
{
    private const KEY = 'sk-ant-test-0123456789abcdef';

    private function patch(string $path, array $payload, array $headers = []): array
    {
        return $this->request('PATCH', $path, array_merge(['Content-Type: application/json'], $headers), json_encode($payload));
    }

    private function cookieFrom(array $r): array
    {
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }

    public function testTheKeyIsStoredButNeverShownBackedUpOrLogged(): void
    {
        $this->startServer(['language' => 'de'], ['ET_LOG_DEST' => 'file', 'ET_LOG_LEVEL' => 'debug']);
        $start = $this->json('GET', '/api/inbox/cloud');
        self::assertSame([false, 'anthropic', false, false], [$start['enabled'], $start['provider'], $start['has_key'], $start['ready']]);
        self::assertSame(['anthropic', 'openai'], array_column($start['providers'], 'id'));
        self::assertSame(['Claude (Anthropic)', 'api.anthropic.com', 'pdf', 'claude-opus-5-5'],
            [$start['providers'][0]['label'], $start['providers'][0]['destination'], $start['providers'][0]['sends'], $start['providers'][0]['default_model']]);
        self::assertStringContainsString('api.anthropic.com', $start['providers'][0]['notice']);
        self::assertSame('text', $start['providers'][1]['sends']);
        // v3.7.0 (Karte „Auslesen“) — Voreinstellungen samt Ziel-Host, alle Cloud-Wege experimentell
        self::assertSame(['anthropic', 'anthropic', true], [$start['preset'], $start['presets'][0]['id'], $start['experimental']]);
        self::assertSame(['api.anthropic.com', 'api.openai.com', 'api.mistral.ai', 'openrouter.ai', 'generativelanguage.googleapis.com', ''],
            array_column($start['presets'], 'host'));
        $mistral = $this->patch('/api/inbox/cloud', ['preset' => 'mistral', 'api_key' => 'sk-mistral-test-0123456789']);
        self::assertSame(200, $mistral['status'], $mistral['body']);
        self::assertStringNotContainsString('sk-mistral-test-0123456789', $mistral['body']);
        $m = json_decode($mistral['body'], true)['data'];
        self::assertSame(['mistral', 'openai', 'https://api.mistral.ai/v1', true, 'Mistral'],
            [$m['preset'], $m['provider'], $m['endpoint'], $m['has_key'], $m['providers'][1]['label']]);
        self::assertSame(200, $this->patch('/api/inbox/cloud', ['preset' => 'anthropic', 'clear_key' => 'openai'])['status']);

        // eine falsche Eingabe: abgelehnt, und auch dabei landet der Schlüssel nirgends
        $bad = $this->patch('/api/inbox/cloud', ['api_key' => 'sk-ant-test geheim 42', 'enabled' => true]);
        self::assertSame([400, 'errors.inboxCloud.keyFormat'], [$bad['status'], json_decode($bad['body'], true)['code'] ?? null]);
        self::assertStringNotContainsString('geheim', $bad['body']);
        $http = $this->patch('/api/inbox/cloud', ['provider' => 'openai', 'endpoint' => 'http://llm.example.com/v1']);
        self::assertSame([400, 'errors.inboxCloud.httpsOnly'], [$http['status'], json_decode($http['body'], true)['code'] ?? null]);
        $home = $this->patch('/api/inbox/cloud', ['provider' => 'openai', 'endpoint' => 'https://nas.local/v1']);
        self::assertSame([400, 'errors.inboxCloud.notPublic'], [$home['status'], json_decode($home['body'], true)['code'] ?? null]);
        $nokey = $this->patch('/api/inbox/cloud', ['provider' => 'anthropic', 'enabled' => true]);
        self::assertSame([400, 'errors.inboxCloud.noKey'], [$nokey['status'], json_decode($nokey['body'], true)['code'] ?? null]);

        $saved = $this->patch('/api/inbox/cloud', ['provider' => 'anthropic', 'enabled' => true, 'api_key' => self::KEY]);
        self::assertSame(200, $saved['status']);
        self::assertStringNotContainsString(self::KEY, $saved['body']);
        $state = json_decode($saved['body'], true)['data'];
        self::assertSame([true, true, true], [$state['enabled'], $state['has_key'], $state['ready']]);

        $settings = $this->request('GET', '/api/settings');
        self::assertStringNotContainsString(self::KEY, $settings['body']);
        $s = json_decode($settings['body'], true)['data'];
        self::assertSame(['anthropic', true, 'anthropic'], [$s['inbox_provider'], $s['inbox_cloud_enabled'], $s['inbox_cloud_provider']]);

        foreach (['/api/inbox/cloud', '/api/backup/export', '/api/diagnostics', '/api/health'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(200, $r['status'], $path);
            self::assertStringNotContainsString(self::KEY, $r['body'], "$path enthält den Schlüssel");
        }
        $export = json_decode($this->request('GET', '/api/backup/export')['body'], true)['data'] ?? [];
        self::assertTrue($export['settings']['inbox_cloud_enabled'] ?? null, 'die Einstellungen sind im Backup, nur der Schlüssel nicht');

        self::assertSame(200, $this->request('POST', '/api/backup/snapshot')['status']);
        $snapshots = glob($this->dataDir . '/backups/*.json') ?: [];
        self::assertNotEmpty($snapshots);
        foreach ($snapshots as $f) self::assertStringNotContainsString(self::KEY, (string)file_get_contents($f), basename($f));

        // auf dem Server liegt er, nur für den Webserver lesbar
        $keyFile = $this->dataDir . '/' . CloudConfig::KEY_FILE;
        self::assertStringContainsString(self::KEY, (string)file_get_contents($keyFile));
        self::assertSame('0600', substr(sprintf('%o', fileperms($keyFile)), -4));

        // Protokoll: geschrieben (debug: jede Anfrage), aber ohne Schlüssel
        $log = (string)@file_get_contents($this->dataDir . '/logs/app.log');
        self::assertStringContainsString('inbox', $log, 'das Protokoll wurde geschrieben');
        self::assertStringNotContainsString(self::KEY, $log);
        self::assertStringNotContainsString('geheim', $log);
    }

    public function testARestoredBackupBringsNoKey(): void
    {
        $this->startServer();
        $this->patch('/api/inbox/cloud', ['provider' => 'anthropic', 'enabled' => true, 'api_key' => self::KEY]);
        $backup = $this->request('GET', '/api/backup/export')['body'];
        // ein anderer Server: ohne die Schlüsseldatei
        unlink($this->dataDir . '/' . CloudConfig::KEY_FILE);
        $r = $this->request('POST', '/api/backup/import', ['Content-Type: application/json'], $backup);
        self::assertSame(200, $r['status'], $r['body']);
        $state = $this->json('GET', '/api/inbox/cloud');
        self::assertSame([true, false, false], [$state['enabled'], $state['has_key'], $state['ready']],
            'eingeschaltet laut Backup, aber ohne Schlüssel geht nichts hinaus');
        self::assertFileDoesNotExist($this->dataDir . '/' . CloudConfig::KEY_FILE);
    }

    public function testOnlyAdminsSwitchItOn(): void
    {
        $this->startServer();
        $admin = $this->cookieFrom($this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'geheim-123'])));
        $this->request('POST', '/api/users', array_merge(['Content-Type: application/json'], $admin),
            json_encode(['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member']));
        $member = $this->cookieFrom($this->request('POST', '/api/session', ['Content-Type: application/json'],
            json_encode(['name' => 'Anna', 'password' => 'annas-passwort'])));

        self::assertSame(200, $this->request('GET', '/api/inbox/cloud', $member)['status'], 'ansehen dürfen alle');
        foreach ([
            ['/api/inbox/cloud', ['provider' => 'anthropic', 'enabled' => true, 'api_key' => self::KEY]],
            ['/api/inbox/cloud', ['clear_key' => true]],
            ['/api/settings', ['inbox_cloud_enabled' => true]],
            ['/api/settings', ['inbox_provider' => 'anthropic']],
            ['/api/settings', ['inbox_cloud_endpoint' => 'https://llm.example.com/v1']],
            ['/api/settings', ['inbox_cloud_provider' => 'openai']],
            ['/api/settings', ['inbox_cloud_model' => 'test-model']],
        ] as [$path, $body]) {
            $r = $this->patch($path, $body, $member);
            self::assertSame([403, 'errors.auth.adminOnly'], [$r['status'], json_decode($r['body'], true)['code'] ?? null], "$path " . json_encode($body));
        }
        self::assertFileDoesNotExist($this->dataDir . '/' . CloudConfig::KEY_FILE);
        // v3.7.0 (Karte „Auslesen“) — Modellliste (mit dem Schlüssel) und Prüfung des eigenen Diensts nur für Verwalter
        foreach (['/api/inbox/cloud/models', '/api/inbox/local/check?endpoint=http%3A%2F%2F127.0.0.1%3A9&api=ollama'] as $path) {
            $r = $this->request('GET', $path, $member);
            self::assertSame([403, 'errors.auth.adminOnly'], [$r['status'], json_decode($r['body'], true)['code'] ?? null], $path);
        }
        // ohne Schlüssel fragt die Modellliste keinen Dienst
        $none = $this->request('GET', '/api/inbox/cloud/models', $admin);
        self::assertSame([400, 'errors.inboxCloud.noKey'], [$none['status'], json_decode($none['body'], true)['code'] ?? null]);
        // unveränderte Werte sperren das Speichern anderer Felder nicht
        self::assertSame(200, $this->patch('/api/settings', ['inbox_cloud_enabled' => false, 'inbox_provider' => 'local', 'anomaly_threshold' => 2.5], $member)['status']);

        $ok = $this->patch('/api/inbox/cloud', ['provider' => 'anthropic', 'enabled' => true, 'api_key' => self::KEY], $admin);
        self::assertSame(200, $ok['status'], $ok['body']);
        self::assertStringNotContainsString(self::KEY, $this->request('GET', '/api/inbox/cloud', $member)['body']);
    }
}
