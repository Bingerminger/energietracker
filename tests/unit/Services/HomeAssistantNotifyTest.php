<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\HomeAssistant\NotifyConfig;
use Energietracker\Services\HomeAssistant\NotifyTransport;
use Energietracker\Services\HomeAssistant\Todo;
use Energietracker\Services\HomeAssistantNotifyService;
use Energietracker\Services\InstanceService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\FakeHomeAssistant;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (F1033, GitHub #36) — Benachrichtigungen an Home Assistant: Ziel,
 * Form der Nachricht je Weg, jede Nachricht nur einmal, Zeitpunkt, Sprache,
 * Geheimnisse. Die Quellen (Agenda, Empfehlungen, Posteingang) und die Uhr
 * sind eingespeist; der Transport zeichnet auf (am Ende einmal über echtes
 * HTTP gegen das nachgebaute Home Assistant auf Port 18741).
 */
#[CoversClass(HomeAssistantNotifyService::class)]
#[CoversClass(NotifyConfig::class)]
#[CoversClass(NotifyTransport::class)]
#[CoversClass(Todo::class)]
final class HomeAssistantNotifyTest extends ServiceTestCase
{
    /** @var list<array{url:string, body:mixed, headers:list<string>, ip:?string}> */
    private array $calls = [];
    /** @var list<array{ok:bool, http_code:?int, error_code:?string}> */
    private array $answers = [];
    private array $events = [];
    private array $recs = [];
    private array $docs = [];
    private int $now;
    private ?string $supervisor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = mktime(9, 30, 0, 3, 10, 2026);
    }

    private function transport(?callable $send = null): NotifyTransport
    {
        return new NotifyTransport($send ?? function (string $url, string $json, array $headers, int $timeout, ?string $ip): array {
            $this->calls[] = ['url' => $url, 'body' => json_decode($json, true), 'headers' => $headers, 'ip' => $ip];
            return array_shift($this->answers) ?? ['ok' => true, 'http_code' => 200, 'error_code' => null];
        }, fn(string $h): array => ['supervisor' => ['172.30.32.2'], 'homeassistant.local' => ['192.168.178.10'],
            'ha.example.org' => ['93.184.216.34'], 'meta.example' => ['169.254.169.254']][$h] ?? []);
    }

    private function config(): NotifyConfig
    {
        return new NotifyConfig($this->settings, $this->store, fn(string $k) => $k === 'SUPERVISOR_TOKEN' ? ($this->supervisor ?? false) : false);
    }

    private function service(?NotifyTransport $transport = null): HomeAssistantNotifyService
    {
        return new HomeAssistantNotifyService($this->config(), $transport ?? $this->transport(), $this->store, $this->i18n,
            new InstanceService($this->store), fn(int $days, string $today): array => $this->events,
            fn(): array => $this->recs, fn(): array => $this->docs, fn(): int => $this->now);
    }

    private function today(int $offset = 0): string
    {
        return date('Y-m-d', $this->now + $offset * 86400);
    }

    private function event(string $kind, string $uid, int $dayOffset = 0, string $severity = 'due', string $title = ''): array
    {
        $date = $this->today($dayOffset);
        return ['uid' => $uid, 'kind' => $kind, 'date' => $date, 'title' => $title !== '' ? $title : "Titel $uid",
                'severity' => $severity, 'due_now' => true, 'done' => null, 'occurrence' => "$uid@$date", 'href' => '#/'];
    }

    private function webhook(HomeAssistantNotifyService $s, array $extra = []): void
    {
        $s->update(['target' => 'webhook', 'url' => 'http://homeassistant.local:8123', 'webhook_id' => 'Energietracker-Hook_42', 'enabled' => true] + $extra);
    }

    private function key(callable $fn): string
    {
        try { $fn(); } catch (LocalizedException $e) { return $e->key; }
        self::fail('LocalizedException erwartet');
    }

    // ── Ziel ──────────────────────────────────────────────────────────

    public function testOffMeansNoConnection(): void
    {
        $this->events = [$this->event('reading_due', 'reading-strom-m1')];
        $s = $this->service();
        self::assertFalse($s->config()->enabled(), 'Opt-in: aus nach dem Update');
        self::assertFalse($s->due($this->now));
        self::assertSame(['documents' => null, 'daily' => null], $s->tick());
        self::assertSame([], $this->calls);
        // ohne Ziel lässt es sich nicht einschalten
        self::assertSame('errors.haNotify.incomplete', $this->key(fn() => $s->update(['enabled' => true, 'target' => 'webhook', 'url' => 'http://homeassistant.local:8123'])));
        self::assertFalse((bool)$this->settings->get('ha_notify_enabled'), 'nichts geschrieben');
    }

    public function testInTheAppTheSupervisorNeedsNoFurtherSetup(): void
    {
        self::assertSame('errors.haNotify.incomplete', $this->key(fn() => $this->service()->update(['enabled' => true])),
            'ohne SUPERVISOR_TOKEN (Docker, NAS) geht der Weg über den Supervisor nicht');
        $this->supervisor = 'sv-token-abcdef';
        $s = $this->service();
        $s->update(['enabled' => true]);
        self::assertSame('supervisor', $s->config()->target(), 'Standardziel');
        $this->events = [$this->event('reading_due', 'reading-strom-m1', 0, 'due', 'Strom: Zählerstand ablesen (Haus)')];
        $s->tick();
        self::assertCount(1, $this->calls);
        $c = $this->calls[0];
        self::assertSame('http://supervisor/core/api/services/persistent_notification/create', $c['url']);
        self::assertSame('172.30.32.2', $c['ip'], 'verbunden wird mit der geprüften Adresse');
        self::assertContains('Authorization: Bearer sv-token-abcdef', $c['headers']);
        self::assertSame('Energietracker: Zu tun', $c['body']['title']);
        self::assertSame("Strom: Zählerstand ablesen (Haus)\nHeute fällig.", $c['body']['message']);
        self::assertMatchesRegularExpression('/^energietracker_[0-9a-f]{16}$/', $c['body']['notification_id'], 'dieselbe Meldung überschreibt sich');
        self::assertStringNotContainsString('sv-token', json_encode($s->status()), 'der Token des Supervisors bleibt im Prozess');
    }

    public function testTheWebhookGetsTheWholeMessageAndNoToken(): void
    {
        $s = $this->service();
        $this->webhook($s);
        $this->events = [$this->event('cancel_by', 'cancel_by-c1', 9, 'urgent', 'Strom: letzter Tag für die Kündigung (Testwerk)')];
        $s->tick();
        self::assertCount(1, $this->calls);
        $c = $this->calls[0];
        self::assertSame('http://homeassistant.local:8123/api/webhook/Energietracker-Hook_42', $c['url']);
        self::assertSame([], $c['headers'], 'der Webhook braucht keinen Token');
        self::assertSame('192.168.178.10', $c['ip']);
        foreach (['source', 'event', 'kind', 'key', 'title', 'message', 'date', 'severity', 'language', 'instance_id', 'sent_at'] as $k) {
            self::assertArrayHasKey($k, $c['body'], $k);
        }
        self::assertSame(['energietracker', 'deadline', 'cancel_by', 'urgent', 'de'],
            [$c['body']['source'], $c['body']['event'], $c['body']['kind'], $c['body']['severity'], $c['body']['language']]);
        self::assertSame("Strom: letzter Tag für die Kündigung (Testwerk)\nFällig am " . date('d.m.Y', $this->now + 9 * 86400) . '.', $c['body']['message']);
    }

    public function testATokenAndANotifyServiceForThePhone(): void
    {
        $s = $this->service();
        $s->update(['target' => 'token', 'url' => 'http://homeassistant.local:8123/', 'token' => 'Bearer ' . str_repeat('t', 40),
                    'service' => 'notify.mobile_app_pixel', 'enabled' => true]);
        self::assertSame('http://homeassistant.local:8123', $s->config()->url(), 'Schrägstrich am Ende weg');
        $this->events = [$this->event('reminder', 'reminder-r1', -2, 'overdue', 'Rauchmelder prüfen')];
        $s->tick();
        $c = $this->calls[0];
        self::assertSame('http://homeassistant.local:8123/api/services/notify/mobile_app_pixel', $c['url']);
        self::assertContains('Authorization: Bearer ' . str_repeat('t', 40), $c['headers'], '„Bearer“ beim Einfügen mitkopiert: nur einmal');
        self::assertSame(['title', 'message'], array_keys($c['body']), 'notify.*: title und message, keine notification_id');
        self::assertSame('Energietracker: Termin', $c['body']['title']);
        self::assertSame("Rauchmelder prüfen\nÜberfällig seit " . date('d.m.Y', $this->now - 2 * 86400) . '.', $c['body']['message']);
    }

    // ── jede Nachricht nur einmal ─────────────────────────────────────

    public function testEveryMessageOnlyOnce(): void
    {
        $s = $this->service();
        $this->webhook($s);
        $this->events = [$this->event('reading_due', 'reading-gas-m1'), $this->event('cancel_by', 'cancel_by-c1', 20)];
        $s->tick();
        self::assertCount(2, $this->calls);
        self::assertFalse($s->due($this->now + 60), 'heute schon geprüft');
        $s->tick($this->now + 60);
        self::assertCount(2, $this->calls);

        $this->now += 86400;   // nächster Tag, dieselben Vorkommen
        $this->events = [$this->event('reading_due', 'reading-gas-m1', -1), $this->event('cancel_by', 'cancel_by-c1', 19)];
        self::assertTrue($s->due());
        $s->tick();
        self::assertCount(2, $this->calls, 'dasselbe Vorkommen kommt nicht noch einmal');

        $this->now += 86400;   // die Frist wird dringend, ein neuer Termin kommt dazu
        $this->events = [$this->event('reading_due', 'reading-gas-m1', -2), $this->event('cancel_by', 'cancel_by-c1', 18, 'urgent'),
                         $this->event('reminder', 'reminder-r9')];
        $s->tick();
        self::assertCount(4, $this->calls, 'eine verschärfte Stufe und ein neuer Termin sind neue Nachrichten');
        self::assertSame(['cancel_by', 'reminder'], array_map(fn($c) => $c['body']['kind'], array_slice($this->calls, 2)));
        self::assertGreaterThanOrEqual(4, $s->status()['sent_count']);
    }

    public function testANewKindInToDoCountsWithoutASpecialCase(): void
    {
        // etwa „Heizgrenze anpassen“ (Paket heizgrenze): eine Art, die dieser Dienst nicht kennt
        $this->events = [$this->event('reading_due', 'reading-strom-m1'), $this->event('heat_limit_review', 'heat_limit_review-gas-m1', 0, 'due', 'Gas: Heizgrenze anpassen')];
        $todo = Todo::summary($this->events);
        self::assertSame(2, $todo['count']);
        self::assertContains('heat_limit_review', array_column($todo['items'], 'kind'));
        $s = $this->service();
        $this->webhook($s);
        $s->tick();
        // gleiche Stufe, gleiches Datum: nach Art sortiert
        self::assertSame(['heat_limit_review', 'reading_due'], array_map(fn($c) => $c['body']['kind'], $this->calls));
        self::assertSame('deadline', $this->calls[0]['body']['event'], 'neue Arten laufen unter „Fristen und weitere Punkte“');
        self::assertSame("Gas: Heizgrenze anpassen\nHeute fällig.", $this->calls[0]['body']['message']);
    }

    public function testTheSelectionDecidesWhatIsSent(): void
    {
        $s = $this->service();
        $this->webhook($s, ['events' => ['reminder']]);
        $this->events = [$this->event('reminder', 'reminder-r1'), $this->event('reading_due', 'reading-strom-m1')];
        $this->recs = [['id' => 'r4_x', 'severity' => 'warning', 'category' => 'anomalie', 'title' => 'Ungewöhnlich hoch', 'detail' => '…']];
        $s->tick();
        self::assertSame(['reminder'], array_map(fn($c) => $c['body']['kind'], $this->calls));
    }

    public function testWarningsAreWarningsAndUrgentOnesThatAreNotAlreadyInToDo(): void
    {
        $s = $this->service();
        $this->webhook($s, ['events' => ['warning']]);
        $this->recs = [
            ['id' => 'r1_a', 'severity' => 'info', 'category' => 'effizienz', 'title' => 'Info', 'detail' => ''],
            ['id' => 'r4_b', 'severity' => 'warning', 'category' => 'anomalie', 'title' => 'Strom im Februar 40 % über der Erwartung', 'detail' => 'Kein Wetter erklärt das.'],
            ['id' => 'r6_c', 'severity' => 'urgent', 'category' => 'vertrag', 'title' => 'Frist', 'detail' => ''],
            ['id' => 'r5_d', 'severity' => 'urgent', 'category' => 'bestand', 'title' => 'Tank', 'detail' => ''],
            ['id' => 'r2_e', 'severity' => 'urgent', 'category' => 'trend', 'title' => 'Trend', 'detail' => 'Seit drei Jahren mehr.'],
        ];
        $s->tick();
        self::assertSame(['anomalie', 'trend'], array_map(fn($c) => $c['body']['kind'], $this->calls),
            'Vertrag und Bestand stehen schon als Punkt in „Zu tun“');
        self::assertSame("Strom im Februar 40 % über der Erwartung\nKein Wetter erklärt das.", $this->calls[0]['body']['message']);
        self::assertSame('Energietracker: Warnung', $this->calls[0]['body']['title']);
    }

    // ── wann ─────────────────────────────────────────────────────────

    public function testTheDailyCheckWaitsForTheHour(): void
    {
        $s = $this->service();
        $this->webhook($s, ['hour' => 10]);
        $this->events = [$this->event('reading_due', 'reading-strom-m1')];
        self::assertFalse($s->due(), '09:30 vor 10 Uhr');
        $s->tick();
        self::assertSame([], $this->calls);
        $this->now = mktime(10, 0, 0, 3, 10, 2026);
        self::assertTrue($s->due());
        $s->tick();
        self::assertCount(1, $this->calls);
    }

    public function testAtMostFiveMessagesPerRunTheRestInOne(): void
    {
        $s = $this->service();
        $this->webhook($s);
        for ($i = 1; $i <= 8; $i++) $this->events[] = $this->event('reading_due', "reading-strom-m$i", 0, 'due', "Zähler $i");
        $r = $s->tick();
        self::assertCount(HomeAssistantNotifyService::MAX_PER_RUN, $this->calls);
        self::assertSame(['sent' => 8, 'messages' => 5], $r['daily']);
        $more = $this->calls[4]['body'];
        self::assertSame('more', $more['event']);
        self::assertStringStartsWith("Dazu 4 weitere Punkte:\n• Zähler 5\n• Zähler 6", $more['message']);
        self::assertCount(4, $more['items']);
        $this->now += 86400;
        $s->tick();
        self::assertCount(5, $this->calls, 'die zusammengefassten zählen als gesendet');
    }

    public function testAFailureIsRetriedLaterNotWithEveryRequest(): void
    {
        $s = $this->service();
        $this->webhook($s);
        $this->events = [$this->event('reading_due', 'reading-strom-m1'), $this->event('reading_due', 'reading-gas-m1')];
        $this->answers = [['ok' => false, 'http_code' => 404, 'error_code' => 'http']];
        $r = $s->tick();
        self::assertSame(['sent' => 0, 'error' => 'http'], $r['daily']);
        self::assertCount(1, $this->calls, 'nach dem ersten Fehler hört der Lauf auf');
        $last = $s->status()['last'];
        self::assertSame([false, 404, 'http'], [$last['ok'], $last['status'], $last['error']]);
        self::assertFalse($s->due($this->now + 60), 'nicht bei jeder Anfrage erneut');
        $s->tick($this->now + 60);
        self::assertCount(1, $this->calls);
        $this->now += HomeAssistantNotifyService::RETRY_S + 1;
        $s->tick();
        self::assertCount(3, $this->calls, 'später am Tag kommt alles');
    }

    // ── Sprache ──────────────────────────────────────────────────────

    public function testMessagesUseTheLanguageOfTheInstallation(): void
    {
        $this->settings->set(['language' => 'en']);
        $this->i18n->setLocale('de');   // das Gerät, dessen Anfrage die Prüfung auslöst
        $s = $this->service();
        $this->webhook($s);
        $this->events = [$this->event('reading_due', 'reading-strom-m1', 0, 'due', 'Electricity: take a meter reading (House)')];
        $s->tick();
        self::assertSame('Energietracker: to do', $this->calls[0]['body']['title']);
        self::assertSame("Electricity: take a meter reading (House)\nDue today.", $this->calls[0]['body']['message']);
        self::assertSame('en', $this->calls[0]['body']['language']);
        self::assertSame('de', $this->i18n->locale(), 'danach wieder die Sprache der Anfrage');
        $t = $s->sendTest();
        self::assertTrue($t['ok']);
        self::assertSame('Energietracker: test message', $this->calls[1]['body']['title']);
    }

    // ── Dokumente ────────────────────────────────────────────────────

    public function testNewDocumentsOnceAndABatchInOneMessage(): void
    {
        $this->docs = [['id' => 'inb_old', 'name' => 'Alt.pdf', 'created_at' => date('c', $this->now - 3600)]];
        $s = $this->service();
        $this->webhook($s, ['events' => ['document']]);
        $this->now += 5;
        $this->docs[] = ['id' => 'inb_a', 'name' => 'Jahresrechnung Gas.pdf', 'created_at' => date('c', $this->now)];
        $s->documentArrived(end($this->docs));
        self::assertTrue($s->due());
        $s->tick();
        self::assertCount(1, $this->calls);
        self::assertSame('Neues Dokument im Posteingang: Jahresrechnung Gas.pdf', $this->calls[0]['body']['message'], 'der alte Bestand meldet sich nicht');
        self::assertSame('document', $this->calls[0]['body']['event']);

        // ein Stapel: zwei weitere kurz danach
        foreach (['inb_b' => 'Abschlag.pdf', 'inb_c' => 'Preisänderung.pdf'] as $id => $name) {
            $this->now += 10;
            $this->docs[] = ['id' => $id, 'name' => $name, 'created_at' => date('c', $this->now)];
            $s->documentArrived(end($this->docs));
            $s->tick();
        }
        self::assertCount(1, $this->calls, 'innerhalb einer Minute gesammelt');
        self::assertTrue($s->due($this->now + 61), 'gesammelte Dokumente warten auf die nächste Anfrage');
        $this->now += 61;
        $s->tick();
        self::assertCount(2, $this->calls);
        self::assertSame('2 neue Dokumente im Posteingang: Abschlag.pdf, Preisänderung.pdf', $this->calls[1]['body']['message']);
        self::assertSame(['inb_b', 'inb_c'], array_column($this->calls[1]['body']['items'], 'id'));
        $this->now += 120;
        $s->tick();
        self::assertCount(2, $this->calls, 'jedes Dokument nur einmal');
        self::assertFalse($s->due());
    }

    // ── Geheimnisse ──────────────────────────────────────────────────

    public function testSecretsNeverReachTheBrowserTheSettingsOrTheBackup(): void
    {
        $s = $this->service();
        $webhook = 'Geheim-Webhook_' . bin2hex(random_bytes(6));
        $token = 'eyJ' . bin2hex(random_bytes(20));
        $s->update(['target' => 'token', 'url' => 'http://homeassistant.local:8123', 'webhook_id' => $webhook, 'token' => $token, 'enabled' => true]);
        $status = json_encode($s->status());
        self::assertStringNotContainsString($webhook, $status);
        self::assertStringNotContainsString($token, $status);
        self::assertTrue($s->status()['has_webhook']);
        self::assertTrue($s->status()['has_token']);
        self::assertStringNotContainsString($token, json_encode($this->settings->all()), 'nicht in settings.json / GET /api/settings');
        $backup = json_encode((new BackupService($this->store, $this->i18n))->export());
        self::assertStringNotContainsString($token, $backup, 'nicht im Backup');
        self::assertStringNotContainsString($webhook, $backup, 'nicht im Backup');
        self::assertStringContainsString('"ha_notify_target":"token"', $backup, 'die Einstellungen ohne Geheimnis schon');
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->store->path(NotifyConfig::SECRET_FILE))), -4));
        // ein leeres Feld lässt das gespeicherte stehen, löschen geht ausdrücklich
        $s->update(['token' => '', 'webhook_id' => '']);
        self::assertTrue($s->status()['has_token']);
        $s->update(['clear_webhook' => true]);
        self::assertFalse($s->status()['has_webhook']);
        self::assertTrue($s->status()['has_token']);
        $s->update(['enabled' => false, 'clear_token' => true]);
        self::assertFalse($this->store->exists(NotifyConfig::SECRET_FILE), 'ohne Geheimnisse keine Datei');
    }

    /**
     * Entscheidung: Das Protokoll der gesendeten Nachrichten gehört nicht ins
     * Backup. Ein Import ersetzt nur die Töpfe des Backups; das Protokoll
     * bleibt stehen, nach einer Wiederherstellung kommt nichts doppelt.
     */
    public function testTheSentLogStaysOutOfTheBackupAndSurvivesARestore(): void
    {
        $s = $this->service();
        $this->webhook($s);
        $this->events = [$this->event('reading_due', 'reading-strom-m1')];
        $s->tick();
        self::assertCount(1, $this->calls);
        $backups = new BackupService($this->store, $this->i18n);
        $export = $backups->export();
        self::assertStringNotContainsString('todo:reading-strom-m1', json_encode($export), 'kein gesendeter Schlüssel im Backup');
        $backups->import($export, false, true);
        self::assertTrue($this->store->exists(HomeAssistantNotifyService::LOG_FILE), 'das Protokoll bleibt beim Einspielen stehen');
        $this->now += 86400;
        $s->tick();
        self::assertCount(1, $this->calls, 'nach der Wiederherstellung nichts doppelt');
    }

    public function testInvalidInputIsRejectedBeforeAnythingIsWritten(): void
    {
        $s = $this->service();
        $before = $this->settings->all();
        foreach ([
            ['url' => 'http://homeassistant.local:8123/api'],          // nur die Adresse
            ['url' => 'ftp://homeassistant.local'],
            ['service' => 'notify.send_message'],                        // braucht ein Ziel-Entity
            ['service' => 'shell_command.rm'],
            ['events' => ['spam']],
            ['hour' => 24],
            ['target' => 'mqtt'],
        ] as $in) {
            self::assertSame('errors.settings.valueInvalid', $this->key(fn() => $s->update($in)), json_encode($in));
        }
        self::assertSame('errors.haNotify.webhookFormat', $this->key(fn() => $s->update(['webhook_id' => 'kurz'])));
        self::assertSame('errors.haNotify.tokenFormat', $this->key(fn() => $s->update(['token' => 'zu kurz'])));
        self::assertSame($before, $this->settings->all());
        // eine ganze Webhook-Adresse wird auf die ID gekürzt
        self::assertSame('Abc_def-123456', NotifyConfig::webhookFrom('http://homeassistant.local:8123/api/webhook/Abc_def-123456'));
        // auch über PATCH /api/settings dieselbe Prüfung
        self::assertSame('errors.settings.valueInvalid', $this->key(fn() => $this->settings->set(['ha_notify_service' => 'script.anything'])));
    }

    public function testAPublicAddressOnlyWithHttpsAndNeverTheMetadataService(): void
    {
        $t = $this->transport();
        self::assertSame('insecure', $t->post('http://ha.example.org:8123/api/webhook/x', [])['error'], 'ein Token im Klartext durchs Internet');
        self::assertTrue($t->post('https://ha.example.org/api/webhook/x', [])['ok']);
        self::assertSame('address', $t->post('http://meta.example/latest', [])['error']);
        self::assertSame('address', $t->post('http://[fe80::1]/x', [])['error']);
        self::assertSame('dns', $t->post('http://unbekannt.invalid/x', [])['error']);
        self::assertSame('url', $t->post('http://user:pw@homeassistant.local/x', [])['error']);
        self::assertTrue($t->post('http://homeassistant.local:8123/api/webhook/x', [])['ok'], 'im Heimnetz auch http');
        self::assertCount(2, $this->calls, 'abgelehnte Adressen werden nie verbunden');
    }

    // ── Zu tun ───────────────────────────────────────────────────────

    public function testToDoOrderAndNextItem(): void
    {
        $done = $this->event('reminder', 'reminder-done');
        $done['done'] = ['auto' => false];
        $later = $this->event('cancel_by', 'cancel_by-c1', 30, 'due');
        $later['due_now'] = false;
        $events = [$this->event('reading_due', 'reading-a', 0, 'due'), $this->event('cancel_by', 'cancel_by-c2', 12, 'urgent'),
                   $this->event('reminder', 'reminder-r1', -3, 'overdue'), $this->event('bill_expected', 'bill-x', -1, 'due'), $done, $later];
        $t = Todo::summary($events, 'et_0123456789abcdef');
        self::assertSame(4, $t['count'], 'Erledigtes und nicht Fälliges zählt nicht');
        self::assertSame(2, $t['overdue'], 'überfällig und dringend');
        self::assertSame(['reminder-r1', 'cancel_by-c2', 'bill-x', 'reading-a'], array_column($t['items'], 'uid'));
        self::assertSame('reminder-r1', $t['next']['uid']);
        self::assertSame(['uid', 'kind', 'title', 'date', 'severity', 'href'], array_keys($t['next']));
        self::assertNull(Todo::summary([])['next']);
        self::assertSame(0, Todo::summary([])['count']);
    }

    // ── über echtes HTTP ─────────────────────────────────────────────

    public function testOverHttpToTheFakeHomeAssistant(): void
    {
        $ha = new FakeHomeAssistant();
        try {
            // der Weg über den Supervisor, umgeleitet auf das nachgebaute Home Assistant
            $send = fn(string $url, string $json, array $headers, int $timeout, ?string $ip): array =>
                NotifyTransport::curlPost(str_replace('http://supervisor', $ha->base, $url), $json, $headers, $timeout, '127.0.0.1');
            $this->supervisor = FakeHomeAssistant::TOKEN;
            $r = $this->service($this->transport($send))->sendTest();
            self::assertSame(['ok' => true, 'status' => 200, 'error' => null, 'target' => 'supervisor'], $r);
            $req = $ha->requests()[0];
            self::assertSame('/core/api/services/persistent_notification/create', $req['path']);
            self::assertSame('Bearer ' . FakeHomeAssistant::TOKEN, $req['headers']['authorization']);
            self::assertSame('application/json', $req['headers']['content-type']);
            self::assertSame('Energietracker: Testnachricht', $req['body']['title']);
            // falscher Token: 401 kommt als Fehler an, nicht als Erfolg
            $this->supervisor = 'falsch-' . str_repeat('x', 20);
            $r = $this->service($this->transport($send))->sendTest();
            self::assertSame(['ok' => false, 'status' => 401, 'error' => 'http'], array_intersect_key($r, ['ok' => 1, 'status' => 1, 'error' => 1]));
            // unbekannte Aktion: 400
            $this->supervisor = FakeHomeAssistant::TOKEN;
            $s = $this->service($this->transport($send));
            $s->update(['service' => 'notify.unbekannt']);
            self::assertSame(400, $s->sendTest()['status']);
        } finally {
            $ha->stop();
        }
    }
}
