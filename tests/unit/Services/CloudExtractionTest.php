<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Http\HttpStatusException;
use Energietracker\Services\Inbox\AnthropicProvider;
use Energietracker\Services\Inbox\CloudConfig;
use Energietracker\Services\Inbox\CloudHttp;
use Energietracker\Services\Inbox\CloudPrompt;
use Energietracker\Services\Inbox\OpenAiCompatibleProvider;
use Energietracker\Services\Inbox\ProviderRegistry;
use Energietracker\Services\Inbox\Applier;
use Energietracker\Services\Inbox\Evidence;
use Energietracker\Services\Inbox\InboxSchema;
use Energietracker\Services\Inbox\Proposals;
use Energietracker\Services\AttachmentService;
use Energietracker\Services\BillService;
use Energietracker\Services\InboxService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\OcrService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\InboxFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.7.0 (Paket cloud) — Auslesen in der Cloud: beide Anschlüsse gegen einen
 * nachgebauten Dienst (tests/fixtures/fake-cloud.php über `php -S`), samt
 * Fehlerfällen. Kein Test spricht je einen echten Cloud-Dienst an: Die
 * Namensauflösung ist ersetzt, die Anfragen gehen an 127.0.0.1.
 *
 * Fall für Fall: Opt-in (aus = keine Verbindung), Adressprüfung als
 * Gegenstück zur Texterkennung, Form der Anfrage, Belegstellen je Zeile,
 * 401, 429, 529, Ablehnung, abgeschnittene Antwort, Zeitüberschreitung,
 * Weiterleitung, Schlüssel nie in einer Meldung.
 */
final class CloudExtractionTest extends ServiceTestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';

    private CloudConfig $cfg;
    /** @var list<array{url:string, ip:?string}> */
    private array $calls = [];

    public static function setUpBeforeClass(): void
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        self::$base = "http://127.0.0.1:$port";
        self::$log = sys_get_temp_dir() . '/et-fake-cloud-' . bin2hex(random_bytes(4)) . '.json';
        // ein Prozess (ohne PHP_CLI_SERVER_WORKERS): proc_terminate beendet ihn ganz
        $env = array_merge(getenv(), ['FAKE_CLOUD_LOG' => self::$log]);
        unset($env['PHP_CLI_SERVER_WORKERS']);
        self::$server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$port", dirname(__DIR__, 2) . '/fixtures/fake-cloud.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__, 2) . '/fixtures', $env);
        for ($i = 0; $i < 100; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($fp) { fclose($fp); return; }
            usleep(50_000);
        }
        self::fail('Nachgebauter Dienst startet nicht');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) { proc_terminate(self::$server); proc_close(self::$server); }
        @unlink(self::$log);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cfg = new CloudConfig($this->settings, $this->store);
        $this->calls = [];
        @unlink(self::$log);
    }

    /** Transport des Anschlusses → nachgebauter Dienst auf 127.0.0.1 (Pfad bleibt). */
    private function transport(): callable
    {
        // v3.7.0 (Karte „Auslesen“) — ohne Körper ein GET (Modellliste), die Abfrage bleibt
        return function (string $url, array $headers, ?string $body, int $timeout, ?string $ip): array {
            $this->calls[] = ['url' => $url, 'ip' => $ip];
            $q = parse_url($url, PHP_URL_QUERY);
            $to = self::$base . parse_url($url, PHP_URL_PATH) . (is_string($q) ? "?$q" : '');
            return $body === null ? CloudHttp::get($to, $headers, $timeout, '127.0.0.1', false)
                : CloudHttp::post($to, $headers, $body, $timeout, '127.0.0.1', false);
        };
    }

    private function claude(string $key = 'sk-ant-test-ok', array $dns = ['api.anthropic.com' => ['160.79.104.10']]): AnthropicProvider
    {
        $this->cfg->update(['provider' => 'anthropic', 'enabled' => true, 'api_key' => $key]);
        return new AnthropicProvider($this->cfg, $this->i18n, $this->transport(), fn(string $h): array => $dns[$h] ?? []);
    }

    private function openai(string $key = 'sk-oa-test-ok', array $dns = ['llm.example.com' => ['8.8.8.8']]): OpenAiCompatibleProvider
    {
        $this->cfg->update(['provider' => 'openai', 'enabled' => true, 'api_key' => $key,
            'endpoint' => 'https://llm.example.com/v1', 'model' => 'test-model']);
        return new OpenAiCompatibleProvider($this->cfg, $this->i18n, $this->transport(), fn(string $h): array => $dns[$h] ?? []);
    }

    /** @return array{path:string, headers:array<string,string>, body:array} letzte Anfrage beim nachgebauten Dienst */
    private static function lastRequest(): array
    {
        $j = json_decode((string)@file_get_contents(self::$log), true);
        self::assertIsArray($j, 'der nachgebaute Dienst hat keine Anfrage bekommen');
        return ['path' => $j['path'], 'headers' => $j['headers'], 'body' => (array)json_decode((string)$j['body'], true)];
    }

    private static function pdf(int $pages = 2): string
    {
        return base64_encode("%PDF-1.4\n" . str_repeat("1 0 obj << /Type /Page >> endobj\n", $pages) . "2 0 obj << /Type /Pages >> endobj\n%%EOF");
    }

    private const SCHEMA = [
        'period_from'   => 'first day of the billing period',
        'period_to'     => ['description' => 'last day of the billing period', 'type' => 'date'],
        'total_amount'  => ['description' => 'total amount of the bill', 'type' => 'number'],
        'paid_advances' => ['description' => 'every advance payment that was paid', 'multiple' => true],
        'meter_number'  => 'meter number',
    ];

    /** Fängt die Ausnahme, prüft, dass der Schlüssel nirgends in ihr steht. */
    private function failure(callable $fn, string $secret): \Throwable
    {
        try {
            $fn();
        } catch (LocalizedException | HttpStatusException $e) {
            $all = $e->getMessage() . json_encode($e->params) . ($e instanceof HttpStatusException ? json_encode($e->headers) : '');
            self::assertStringNotContainsString($secret, $all, 'der Schlüssel steht in der Meldung');
            return $e;
        }
        self::fail('keine Ausnahme');
    }

    // ── Adressen: das Gegenstück zur Texterkennung ──────────────────────────

    public static function addresses(): array
    {
        return [
            ['8.8.8.8', true], ['160.79.104.10', true], ['2606:4700::1111', true], ['::ffff:8.8.8.8', true],
            ['192.168.178.20', false], ['10.1.2.3', false], ['172.16.5.4', false], ['127.0.0.1', false],
            ['100.64.0.1', false], ['169.254.169.254', false], ['0.0.0.0', false], ['224.0.0.1', false],
            ['192.0.2.10', false], ['::1', false], ['fe80::1', false], ['fd00::1', false], ['fd00:ec2::254', false],
            ['ff02::1', false], ['::ffff:192.168.178.20', false],
            ['64:ff9b::c0a8:b214', false],     // NAT64 auf 192.168.178.20
            ['2002:c0a8:b214::1', false],      // 6to4 auf 192.168.178.20
        ];
    }

    #[DataProvider('addresses')]
    public function testOnlyPublicAddressesCount(string $ip, bool $public): void
    {
        self::assertSame($public, CloudHttp::isPublicIp($ip), $ip);
    }

    public function testEveryAddressTheTextRecognitionAcceptsIsRejectedHere(): void
    {
        foreach (['127.0.0.1', '192.168.178.10', '10.0.0.5', '172.16.0.1', '172.31.255.1', '100.100.1.2', '::1',
                  'fd12:3456::1', '::ffff:192.168.0.2'] as $ip) {
            self::assertTrue(OcrService::isLocalIp($ip), $ip);
            self::assertFalse(CloudHttp::isPublicIp($ip), "$ip: Heimnetz für die Texterkennung, also nie für die Cloud");
        }
    }

    public function testOnlyHttpsAndNoHomeNames(): void
    {
        $key = static function (string $url): string {
            try { CloudHttp::checkUrl($url); return 'ok'; } catch (LocalizedException $e) { return $e->key; }
        };
        self::assertSame('errors.inboxCloud.httpsOnly', $key('http://llm.example.com/v1'));
        self::assertSame('errors.inboxCloud.httpsOnly', $key('https://user:secret@llm.example.com/v1'));
        self::assertSame('errors.inboxCloud.httpsOnly', $key('llm.example.com'));
        foreach (['https://nas.local/v1', 'https://fritz.box', 'https://nas', 'https://localhost:8443', 'https://router.home.arpa',
                  'https://192.168.178.20/v1', 'https://[fd00::5]/v1', 'https://100.64.1.2'] as $url) {
            self::assertSame('errors.inboxCloud.notPublic', $key($url), $url);
        }
        self::assertSame('ok', $key('https://llm.example.com/v1'));
    }

    public function testANameThatResolvesIntoTheHomeNetworkIsRejectedAtCallTime(): void
    {
        $p = $this->openai('sk-oa-test-ok', ['llm.example.com' => ['8.8.8.8', '192.168.178.20']]);
        $e = $this->failure(fn() => $p->classify('Jahresrechnung'), 'sk-oa-test-ok');
        self::assertSame('errors.inboxCloud.notPublic', $e->key);
        self::assertSame([], $this->calls, 'keine Verbindung');
        // auch ein Name, der sich nicht auflösen lässt, öffnet nichts
        $p = $this->openai('sk-oa-test-ok', []);
        self::assertSame('errors.inboxCloud.unreachable', $this->failure(fn() => $p->classify('x'), 'sk-oa-test-ok')->key);
        self::assertSame([], $this->calls);
    }

    // ── Opt-in: ohne Einschalten verlässt nichts das Haus ───────────────────

    public function testOffMeansNoConnection(): void
    {
        $claude = new AnthropicProvider($this->cfg, $this->i18n, $this->transport(), fn() => ['160.79.104.10']);
        $openai = new OpenAiCompatibleProvider($this->cfg, $this->i18n, $this->transport(), fn() => ['8.8.8.8']);
        self::assertSame('errors.inboxCloud.off', $this->failure(fn() => $claude->classify('x', self::pdf()), 'none')->key);
        self::assertSame('errors.inboxCloud.off', $this->failure(fn() => $openai->classify('x'), 'none')->key);
        // eingeschaltet für Claude: der andere Anschluss bleibt zu, auch mit eigenem Schlüssel
        $this->claude();
        $this->store->write(CloudConfig::KEY_FILE, ['anthropic' => 'sk-ant-test-ok', 'openai' => 'sk-oa-test-ok']);
        self::assertSame('errors.inboxCloud.off', $this->failure(fn() => $openai->classify('x'), 'none')->key);
        // ausgeschaltet, Schlüssel bleibt: wieder zu
        $this->cfg->update(['enabled' => false]);
        self::assertSame('errors.inboxCloud.off', $this->failure(fn() => $claude->classify('x', self::pdf()), 'none')->key);
        self::assertSame([], $this->calls);
        self::assertTrue($this->cfg->hasKey('anthropic'));
    }

    public function testSwitchingOnNeedsACompleteConnection(): void
    {
        // SettingsService meldet Grenzen als InvalidArgumentException (ohne Übersetzer: der Schlüssel)
        $key = function (array $in): string {
            try { $this->cfg->update($in); return 'ok'; }
            catch (\InvalidArgumentException $e) { return $e instanceof LocalizedException ? $e->key : $e->getMessage(); }
        };
        self::assertSame('errors.inboxCloud.noKey', $key(['provider' => 'anthropic', 'enabled' => true]));
        self::assertSame('errors.inboxCloud.noEndpoint', $key(['provider' => 'openai', 'enabled' => true, 'api_key' => 'sk-oa-test-ok', 'model' => 'm']));
        self::assertSame('errors.inboxCloud.noModel', $key(['provider' => 'openai', 'enabled' => true, 'api_key' => 'sk-oa-test-ok', 'endpoint' => 'https://llm.example.com']));
        self::assertSame('errors.inboxCloud.httpsOnly', $key(['provider' => 'openai', 'endpoint' => 'http://llm.example.com']));
        self::assertSame('errors.inboxCloud.notPublic', $key(['provider' => 'openai', 'endpoint' => 'https://192.168.178.20/v1']));
        self::assertSame('errors.inboxCloud.keyFormat', $key(['provider' => 'anthropic', 'api_key' => 'sk ant mit leerzeichen']));
        self::assertSame('errors.settings.valueInvalid', $key(['provider' => 'mistral']));
        self::assertSame('errors.settings.valueInvalid', $key(['enabled' => 'ja']));
        self::assertSame('errors.settings.valueInvalid', $key(['timeout_s' => 5]));
        self::assertSame('errors.settings.valueInvalid', $key(['provider' => 'anthropic', 'model' => 'claude opus; rm']));
        // nichts davon hat etwas geschrieben
        self::assertFalse($this->cfg->enabled());
        self::assertSame('', $this->cfg->endpoint());
        self::assertFalse($this->store->exists(CloudConfig::KEY_FILE));
        self::assertSame('local', $this->settings->get('inbox_provider'));
    }

    public function testTheSwitchSelectsTheProviderAndTheKeyStaysOnTheServer(): void
    {
        $this->claude('sk-ant-test-ok');
        self::assertSame('anthropic', $this->settings->get('inbox_provider'), 'eingeschaltet: dieser Anbieter liest');
        $file = $this->store->path(CloudConfig::KEY_FILE);
        self::assertSame('0600', substr(sprintf('%o', fileperms($file)), -4), 'nur der Webserver liest den Schlüssel');
        self::assertStringNotContainsString('sk-ant-test-ok', (string)file_get_contents($this->store->path('settings.json')));
        $status = json_encode($this->cfg->status());
        self::assertStringNotContainsString('sk-ant-test-ok', $status);
        self::assertTrue($this->cfg->status()['has_key']);

        // Schlüssel für den anderen Anbieter löschen: die Auswahl bleibt
        $this->cfg->update(['provider' => 'anthropic', 'api_key' => 'sk-ant-test-ok']);
        $this->store->write(CloudConfig::KEY_FILE, ['anthropic' => 'sk-ant-test-ok', 'openai' => 'sk-oa-test-ok']);
        $this->cfg->update(['clear_key' => 'openai']);
        self::assertSame(['anthropic' => true, 'openai' => false], $this->cfg->status()['keys']);
        self::assertTrue($this->cfg->allows('anthropic'));

        // den eigenen Schlüssel löschen geht nur mit Ausschalten
        try { $this->cfg->update(['clear_key' => true]); self::fail('ohne Schlüssel eingeschaltet'); }
        catch (LocalizedException $e) { self::assertSame('errors.inboxCloud.noKey', $e->key); }
        $this->cfg->update(['clear_key' => true, 'enabled' => false]);
        self::assertFalse($this->store->exists(CloudConfig::KEY_FILE));
        self::assertSame('local', $this->settings->get('inbox_provider'), 'ausgeschaltet: zurück auf lokal');

        // Anbieterwechsel nimmt das Modell nicht mit
        $this->cfg->update(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']);
        $this->cfg->update(['provider' => 'openai']);
        self::assertSame('', $this->cfg->model('openai'));
    }

    // ── Claude ───────────────────────────────────────────────────────────────

    public function testClaudeGetsThePdfAsADocumentWithCitations(): void
    {
        $r = $this->claude()->classify('Jahresrechnung Strom', self::pdf());
        self::assertSame(['type' => 'invoice', 'confidence' => 0.93, 'model' => 'claude-opus-5-5'], array_diff_key($r, ['seconds' => 1]));
        self::assertIsFloat($r['seconds']);
        self::assertSame([['url' => 'https://api.anthropic.com/v1/messages', 'ip' => '160.79.104.10']], $this->calls,
            'an die feste Adresse, verbunden mit der geprüften IP');

        $req = self::lastRequest();
        self::assertSame('/v1/messages', $req['path']);
        self::assertSame('sk-ant-test-ok', $req['headers']['x-api-key'] ?? null);
        self::assertSame('2023-06-01', $req['headers']['anthropic-version'] ?? null);
        self::assertStringStartsWith('application/json', $req['headers']['content-type'] ?? '');
        self::assertArrayNotHasKey('authorization', $req['headers']);
        $content = $req['body']['messages'][0]['content'];
        self::assertSame(['type' => 'base64', 'media_type' => 'application/pdf', 'data' => self::pdf()], $content[0]['source']);
        self::assertSame(['document', true, 'text'], [$content[0]['type'], $content[0]['citations']['enabled'], $content[1]['type']],
            'Dokument vor dem Text, Belegstellen eingeschaltet');
        self::assertSame(['type' => 'ephemeral'], $content[0]['cache_control'], 'zweite Anfrage liest das PDF aus dem Zwischenspeicher');
        self::assertArrayNotHasKey('temperature', $req['body'], 'die Modelle lehnen temperature ab');
        self::assertArrayNotHasKey('output_config', $req['body'], 'festes Format und Belegstellen schließen sich aus');
        self::assertSame(AnthropicProvider::MAX_TOKENS, $req['body']['max_tokens']);
        self::assertSame('default', $req['body']['fallbacks']);
        self::assertSame(AnthropicProvider::FALLBACK_BETA, $req['headers']['anthropic-beta'] ?? null);
    }

    public function testClaudeCitationsBecomeQuoteAndPagePerField(): void
    {
        $r = $this->claude()->extract('invoice', self::SCHEMA, 'Jahresrechnung', self::pdf());
        $f = $r['fields'];
        self::assertSame(['value' => '01.01.2025', 'quote' => 'Abrechnungszeitraum 01.01.2025 bis 31.12.2025', 'page' => 1], $f['period_from']);
        self::assertSame(['value' => '31.12.2025', 'quote' => 'Abrechnungszeitraum 01.01.2025 bis 31.12.2025', 'page' => 1], $f['period_to']);
        self::assertSame(['value' => '1.234,56 €', 'quote' => 'Rechnungsbetrag 1.234,56 €', 'page' => 2], $f['total_amount'],
            'von zwei Belegen gilt der, in dem der Wert steht; der Wert bleibt wörtlich');
        // Mehrfachfeld wie beim lokalen Anschluss: je Vorkommen ein Eintrag mit eigenem Zitat und eigener Seite
        self::assertSame([['value' => '85,00 €', 'quote' => 'Abschlag Januar 85,00 €', 'page' => 3],
                          ['value' => '90,00 €', 'quote' => 'Abschlag Februar 90,00 €', 'page' => 3]], $f['paid_advances']['value'],
            'Mehrfachfeld: jeder Abschlag, die Summe bildet der Server');
        self::assertSame(['value' => null, 'quote' => null, 'page' => null], $f['meter_number'], '„none“ = leer');
        self::assertSame(array_keys(self::SCHEMA), array_keys($f), 'jedes Feld des Schemas, nichts anderes');
        self::assertSame('claude-opus-5-5', $r['model']);

        $prompt = self::lastRequest()['body']['messages'][0]['content'][1]['text'];
        self::assertStringContainsString('annual or final bill', $prompt);
        self::assertStringContainsString('- paid_advances: every advance payment that was paid (may occur several times', $prompt);
        self::assertStringContainsString('<field>: <value>', $prompt);
    }

    public function testWithoutAPdfTheTextGoesAsATextDocument(): void
    {
        $this->claude()->extract('invoice', self::SCHEMA, 'Abrechnungszeitraum 01.01.2025 bis 31.12.2025');
        $doc = self::lastRequest()['body']['messages'][0]['content'][0];
        self::assertSame(['type' => 'text', 'media_type' => 'text/plain', 'data' => 'Abrechnungszeitraum 01.01.2025 bis 31.12.2025'], $doc['source']);
        self::assertTrue($doc['citations']['enabled']);
    }

    public function testClaudeFailures(): void
    {
        $cases = [
            'sk-ant-test-401'       => [502, 'errors.inboxCloud.unauthorized'],
            'sk-ant-test-429'       => [429, 'errors.inboxCloud.rateLimited'],
            'sk-ant-test-529'       => [503, 'errors.inboxCloud.overloaded'],
            'sk-ant-test-refusal'   => [422, 'errors.inboxCloud.refusal'],
            'sk-ant-test-maxtokens' => [502, 'errors.inboxCloud.truncated'],
            'sk-ant-test-redirect'  => [502, 'errors.inboxCloud.redirect'],
            // ein Schlüssel ohne bekanntes Präfix: nur der eigene Abgleich ersetzt ihn
            'tok-test-echo'         => [502, 'errors.inboxCloud.rejected'],
        ];
        foreach ($cases as $key => [$status, $code]) {
            $p = $this->claude($key);
            $e = $this->failure(fn() => $p->extract('invoice', self::SCHEMA, 'x', self::pdf()), $key);
            self::assertInstanceOf(HttpStatusException::class, $e, $key);
            self::assertSame([$status, $code], [$e->status, $e->key], $key);
            if ($key === 'sk-ant-test-429') {
                self::assertSame(17, $e->params['seconds']);
                self::assertSame(['Retry-After' => '17'], $e->headers);
            }
            if ($key === 'tok-test-echo') {
                self::assertStringContainsString('***', (string)$e->params['detail'], 'der Dienst wiederholt den Schlüssel: ersetzt');
            }
            if ($key === 'sk-ant-test-refusal') self::assertSame('Claude (Anthropic)', $e->params['name']);
        }
    }

    /**
     * v3.9.0 (Posteingang 3.7.1) — abgeschnitten am Budget: auch der PDF-Weg
     * wiederholt einmal mit dem doppelten Budget (wie LocalProvider); am
     * Kontextfenster und ohne Restzeit bleibt es beim Fehler.
     */
    public function testATruncatedPdfAnswerIsRepeatedOnceWithTheLargerBudget(): void
    {
        $r = $this->claude('sk-ant-test-maxonce')->extract('invoice', self::SCHEMA, 'x', self::pdf());
        self::assertSame('31.12.2025', $r['fields']['period_to']['value'] ?? null, 'die zweite Antwort zählt (die erste endet nach period_from)');
        self::assertCount(2, $this->calls, 'genau eine Wiederholung');
        $req = self::lastRequest();
        self::assertSame(AnthropicProvider::MAX_TOKENS_RETRY, $req['body']['max_tokens']);
        self::assertSame('base64', $req['body']['messages'][0]['content'][0]['source']['type'], 'dasselbe PDF');
        self::assertSame('default', $req['body']['fallbacks'] ?? null, 'sonst dieselbe Anfrage');

        $this->calls = [];
        $e = $this->failure(fn() => $this->claude('sk-ant-test-maxtokens')->extract('invoice', self::SCHEMA, 'x', self::pdf()), 'sk-ant-test-maxtokens');
        self::assertSame([502, 'errors.inboxCloud.truncated'], [$e->status, $e->key]);
        self::assertCount(2, $this->calls, 'auch die Wiederholung abgeschnitten: Fehler, kein dritter Versuch');

        $this->calls = [];
        $e = $this->failure(fn() => $this->claude('sk-ant-test-ctxwindow')->extract('invoice', self::SCHEMA, 'x', self::pdf()), 'sk-ant-test-ctxwindow');
        self::assertSame([502, 'errors.inboxCloud.truncated'], [$e->status, $e->key]);
        self::assertCount(1, $this->calls, 'am Kontextfenster hilft kein größeres Budget');

        $this->calls = [];
        $this->cfg->update(['timeout_s' => AnthropicProvider::RETRY_MIN_SECONDS - 1]);
        $p = $this->claude('sk-ant-test-maxonce');
        self::assertSame(AnthropicProvider::RETRY_MIN_SECONDS - 1, $this->cfg->timeout(), 'Vorbedingung: kürzer als die Mindestrestzeit');
        $e = $this->failure(fn() => $p->extract('invoice', self::SCHEMA, 'x', self::pdf()), 'sk-ant-test-maxonce');
        self::assertSame('errors.inboxCloud.truncated', $e->key);
        self::assertCount(1, $this->calls, 'ohne Restzeit keine Wiederholung');
    }

    public function testWithoutTheFallbackBetaTheRequestIsRepeatedOnceWithout(): void
    {
        $r = $this->claude('sk-ant-test-nobeta')->classify('x', self::pdf());
        self::assertSame('invoice', $r['type']);
        $req = self::lastRequest();
        self::assertArrayNotHasKey('fallbacks', $req['body']);
        self::assertArrayNotHasKey('anthropic-beta', $req['headers']);
        self::assertCount(2, $this->calls, 'genau eine Wiederholung');
    }

    public function testOtherModelsGetNoFallback(): void
    {
        $this->claude();
        $this->cfg->update(['model' => 'claude-haiku-4-5']);
        $p = new AnthropicProvider($this->cfg, $this->i18n, $this->transport(), fn() => ['160.79.104.10']);
        $p->classify('x', self::pdf());
        $req = self::lastRequest();
        self::assertSame('claude-haiku-4-5', $req['body']['model'], 'Modell wählbar');
        self::assertArrayNotHasKey('fallbacks', $req['body']);
        self::assertArrayNotHasKey('anthropic-beta', $req['headers']);
    }

    public function testPdfLimitsAreCheckedBeforeSending(): void
    {
        $p = $this->claude();
        self::assertSame('errors.inboxCloud.notPdf', $this->failure(fn() => $p->classify('x', base64_encode('kein pdf')), 'sk-ant-test-ok')->key);
        self::assertSame('errors.inboxCloud.notPdf', $this->failure(fn() => $p->classify('x', '%%%'), 'sk-ant-test-ok')->key);
        $e = $this->failure(fn() => $p->classify('x', self::pdf(AnthropicProvider::MAX_PAGES + 1)), 'sk-ant-test-ok');
        self::assertSame([413, 'errors.inboxCloud.tooLargeLimits', 32, 600], [$e->status, $e->key, $e->params['mb'], $e->params['pages']]);
        $p->classify('x', self::pdf(AnthropicProvider::MAX_PAGES));   // genau an der Grenze geht es
        self::assertSame('errors.inboxCloud.emptyDocument', $this->failure(fn() => $p->classify('  '), 'sk-ant-test-ok')->key);
        self::assertCount(1, $this->calls);
    }

    public function testTimeoutIsReported(): void
    {
        $this->cfg->update(['provider' => 'anthropic', 'enabled' => true, 'api_key' => 'sk-ant-test-ok', 'timeout_s' => 10]);
        $p = new AnthropicProvider($this->cfg, $this->i18n,
            fn() => ['ok' => false, 'status' => null, 'body' => null, 'headers' => [], 'error' => 'timeout'], fn() => ['160.79.104.10']);
        $e = $this->failure(fn() => $p->classify('x', self::pdf()), 'sk-ant-test-ok');
        self::assertSame([504, 'errors.inboxCloud.timeout', 10], [$e->status, $e->key, $e->params['seconds']]);
    }

    // ── OpenAI-kompatibel ────────────────────────────────────────────────────

    public function testOpenAiCompatibleGetsOnlyTheTextAtTemperatureZero(): void
    {
        $p = $this->openai();
        self::assertFalse($p->supportsPdf());
        $r = $p->classify('Preisänderung ab 01.03.2026', self::pdf());
        self::assertSame(['type' => 'price_change', 'confidence' => 0.88, 'model' => 'test-model'], array_diff_key($r, ['seconds' => 1]));
        self::assertSame('https://llm.example.com/v1/chat/completions', $this->calls[0]['url']);
        self::assertSame('8.8.8.8', $this->calls[0]['ip']);
        $req = self::lastRequest();
        self::assertSame('Bearer sk-oa-test-ok', $req['headers']['authorization'] ?? null);
        self::assertSame(0, $req['body']['temperature']);
        self::assertSame(['type' => 'json_object'], $req['body']['response_format']);
        self::assertSame('test-model', $req['body']['model']);
        self::assertStringContainsString('Preisänderung ab 01.03.2026', $req['body']['messages'][1]['content']);
        self::assertStringNotContainsString(self::pdf(), json_encode($req['body']), 'das PDF bleibt hier');

        $f = $p->extract('invoice', self::SCHEMA, 'Jahresrechnung')['fields'];
        self::assertSame(['value' => '01.01.2025', 'quote' => 'Abrechnungszeitraum 01.01.2025 bis 31.12.2025', 'page' => 1], $f['period_from']);
        self::assertSame(['value' => '1.234,56 €', 'quote' => 'Rechnungsbetrag 1.234,56 €', 'page' => null], $f['total_amount']);
        self::assertSame(['85,00 €', '90,00 €'], array_column($f['paid_advances']['value'], 'value'));
        self::assertNull($f['meter_number']['value']);
        self::assertNull($f['period_to']['value']);
    }

    public function testOpenAiFailures(): void
    {
        foreach ([
            'sk-oa-test-401'      => [502, 'errors.inboxCloud.unauthorized'],
            'sk-oa-test-429'      => [429, 'errors.inboxCloud.rateLimited'],
            'sk-oa-test-length'   => [502, 'errors.inboxCloud.truncated'],
            'sk-oa-test-filter'   => [422, 'errors.inboxCloud.refusal'],
            'sk-oa-test-redirect' => [502, 'errors.inboxCloud.redirect'],
        ] as $key => [$status, $code]) {
            $p = $this->openai($key);
            $e = $this->failure(fn() => $p->extract('invoice', self::SCHEMA, 'x'), $key);
            self::assertSame([$status, $code], [$e->status, $e->key], $key);
            if ($key === 'sk-oa-test-429') self::assertSame(9, $e->params['seconds']);
        }
    }

    // ── Verbindung selbst (curl gegen den nachgebauten Dienst) ──────────────

    private static function okBody(): string
    {
        return (string)json_encode(['model' => 'claude-opus-5-5', 'max_tokens' => 10, 'messages' => [['role' => 'user', 'content' => [
            ['type' => 'document', 'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => 'x'], 'citations' => ['enabled' => true]],
            ['type' => 'text', 'text' => 'Which kind of document is this?'],
        ]]]]);
    }

    private static function headers(string $key): array
    {
        return ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
    }

    public function testTheConnectionGoesToExactlyTheCheckedAddress(): void
    {
        // Ein Name, den kein DNS kennt: erreichbar nur über die vorgegebene Adresse
        $port = (int)parse_url(self::$base, PHP_URL_PORT);
        $r = CloudHttp::post("http://cloud.invalid:$port/v1/messages", self::headers('sk-ant-test-ok'), self::okBody(), 5, '127.0.0.1', false);
        self::assertTrue($r['ok'], (string)$r['error']);
        self::assertSame('cloud.invalid:' . $port, self::lastRequest()['headers']['host'] ?? null);
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $r = CloudHttp::post(self::$base . '/v1/messages', self::headers('sk-ant-test-redirect'), self::okBody(), 5, null, false);
        self::assertSame([false, 307, 'http'], [$r['ok'], $r['status'], $r['error']], 'gefolgt hätte /elsewhere mit 200 geantwortet');
    }

    public function testTimeoutEndsTheRequest(): void
    {
        $t0 = microtime(true);
        $r = CloudHttp::post(self::$base . '/v1/messages', self::headers('sk-ant-test-slow'), self::okBody(), 1, null, false);
        self::assertSame([false, 'timeout'], [$r['ok'], $r['error']]);
        self::assertLessThan(2.5, microtime(true) - $t0);
    }

    public function testTheProvidersCannotSpeakPlainHttp(): void
    {
        $r = CloudHttp::post(self::$base . '/v1/messages', self::headers('sk-ant-test-ok'), self::okBody(), 5, null);
        self::assertFalse($r['ok'], 'mit httpsOnly (Standard der Anschlüsse) kein http://');
        self::assertSame('network', $r['error']);
    }

    public function testNoProxyFromTheEnvironment(): void
    {
        // Ein Proxy löste den Namen selbst noch einmal auf — die geprüfte Adresse gälte nicht mehr
        $old = getenv('http_proxy');
        putenv('http_proxy=http://127.0.0.1:9');
        try {
            $r = CloudHttp::post(self::$base . '/v1/messages', self::headers('sk-ant-test-ok'), self::okBody(), 5, null, false);
        } finally {
            putenv($old === false ? 'http_proxy' : "http_proxy=$old");
        }
        self::assertTrue($r['ok'], 'am Proxy vorbei direkt verbunden: ' . (string)$r['error']);
    }

    public function testKeysAreScrubbedFromServiceMessages(): void
    {
        self::assertSame('Incorrect API key provided: sk-***.', CloudHttp::scrub('Incorrect API key provided: sk-proj-abc123456789.'));
        self::assertSame('bad *** here', CloudHttp::scrub('bad tok-geheim-42 here', 'tok-geheim-42'));
        self::assertSame('key x is ***', CloudHttp::errorDetail('{"error":{"message":"key x is abcdef123456"}}', 'abcdef123456'));
    }

    public function testRetryAfterAndStatusFromTheService(): void
    {
        $r = CloudHttp::post(self::$base . '/v1/messages', self::headers('sk-ant-test-429'), self::okBody(), 5, null, false);
        self::assertSame([429, '17'], [$r['status'], $r['headers']['retry-after'] ?? null]);
    }

    // ── Name, Ziel und Hinweis für den Zustimmungsdialog ────────────────────

    public function testLabelDestinationAndNotice(): void
    {
        $claude = $this->claude();
        self::assertSame(['anthropic', 'Claude (Anthropic)', 'api.anthropic.com', 'claude-opus-5-5', true],
            [$claude->id(), $claude->label(), $claude->destination(), $claude->model(), $claude->supportsPdf()]);
        self::assertStringContainsString('api.anthropic.com', $claude->notice());
        self::assertStringContainsString('Claude (Anthropic)', $claude->notice());
        $openai = $this->openai();
        self::assertSame(['openai', 'llm.example.com', 'test-model'], [$openai->id(), $openai->destination(), $openai->model()]);
        self::assertStringContainsString('llm.example.com', $openai->notice());
        self::assertNotSame($claude->notice(), $openai->notice(), 'PDF bzw. nur Text');
    }

    public function testTheRegistrySelectsByInboxProvider(): void
    {
        // die Registry des Posteingangs: lokal ist immer da, die Cloud meldet sich an
        $reg = new ProviderRegistry($this->settings, $local = InboxFixture::provider('local'));
        $reg->register($claude = new AnthropicProvider($this->cfg, $this->i18n));
        $reg->register(new OpenAiCompatibleProvider($this->cfg, $this->i18n));
        self::assertSame($local, $reg->active(), 'ohne Opt-in liest der lokale');
        $this->claude();
        self::assertSame($claude, $reg->active());
        self::assertSame(['local', 'anthropic', 'openai'], $reg->ids());
        $this->cfg->update(['enabled' => false]);
        self::assertSame($local, $reg->active(), 'ausgeschaltet: wieder lokal');
    }

    // ── Lesen der Antworten ─────────────────────────────────────────────────

    public function testUnknownTypesBecomeOtherWithoutConfidence(): void
    {
        self::assertSame(['type' => 'other', 'confidence' => null], CloudPrompt::parseClassify("type: invoice-ish\nconfidence: 0.99", CloudPrompt::DEFAULT_TYPES));
        self::assertSame(['type' => 'dunning', 'confidence' => 0.7], CloudPrompt::parseClassify('**type**: `dunning`' . "\nconfidence: 0,7", CloudPrompt::DEFAULT_TYPES));
        // Prozent wie bei der Texterkennung (7 → 0,07), außerhalb 0–100 keine Angabe
        self::assertSame(['type' => 'invoice', 'confidence' => 0.07], CloudPrompt::parseClassify('{"type": "invoice", "confidence": 7}', CloudPrompt::DEFAULT_TYPES));
        self::assertSame(['type' => 'invoice', 'confidence' => null], CloudPrompt::parseClassify('{"type": "invoice", "confidence": 120}', CloudPrompt::DEFAULT_TYPES));
        // eine Quelle für die Arten: die des Posteingangs
        self::assertSame(InboxSchema::TYPES, array_keys(CloudPrompt::DEFAULT_TYPES));
    }

    // ── Zusammenspiel mit dem Posteingang ───────────────────────────────────

    /**
     * Das Schema des Posteingangs (InboxSchema::forProvider) geht hinein, und
     * was herauskommt, nimmt die Belegprüfung so, wie sie es vom lokalen
     * Anschluss kennt: Felder mit Teilen als Liste von Objekten, je Vorkommen
     * mit eigenem Zitat und eigener Seite.
     */
    public function testBothProvidersSpeakTheInboxSchema(): void
    {
        $schema = InboxSchema::forProvider('invoice');
        $pages = ['Abrechnungszeitraum 01.01.2025 bis 31.12.2025', 'Ihr Abrechnungsergebnis. Rechnungsbetrag 1.234,56 €',
                  'Abschlag Januar 85,00 € Abschlag Februar 90,00 €'];
        $claude = $this->claude()->extract('invoice', $schema, implode("\n", $pages), self::pdf());
        $prompt = self::lastRequest()['body']['messages'][0]['content'][1]['text'];
        self::assertStringContainsString(InboxSchema::TYPE_DESC['invoice'], $prompt, 'die Art beschreibt der Posteingang');
        self::assertStringContainsString('advances_paid: amount = <value> | date = <value>', $prompt, 'Felder mit Teilen: eine Zeile je Vorkommen');
        self::assertStringContainsString('result_kind: credit (Guthaben) or amount due (Nachzahlung) (one of: credit, due)', $prompt);
        self::assertStringNotContainsString('- version', $prompt, 'nur die Felder, nicht die Hülle des Schemas');
        $openai = $this->openai()->extract('invoice', $schema, implode("\n", $pages));
        self::assertStringContainsString('"advances_paid": [{"amount": "<value>", "date": "<value>"', self::lastRequest()['body']['messages'][1]['content']);

        foreach (['Claude' => $claude, 'OpenAI-kompatibel' => $openai] as $who => $r) {
            self::assertSame(array_keys(InboxSchema::fields('invoice')), array_keys($r['fields']), "$who: jedes Feld des Schemas");
            self::assertSame([['amount' => '85,00 €', 'date' => null, 'quote' => 'Abschlag Januar 85,00 €', 'page' => 3],
                              ['amount' => '90,00 €', 'date' => null, 'quote' => 'Abschlag Februar 90,00 €', 'page' => 3]],
                $r['fields']['advances_paid']['value'], $who);
            $checked = Evidence::check('invoice', $r['fields'], $pages);
            self::assertSame(['2025-01-01', 'ok'], [$checked['period_from']['value'], $checked['period_from']['status']], $who);
            self::assertSame([1234.56, 'ok'], [$checked['total_amount']['value'], $checked['total_amount']['status']], $who);
            self::assertSame([85.0, 90.0], array_column($checked['advances_paid']['value'], 'amount'), "$who: die Summe bildet der Server");
            self::assertSame(['ok', 'ok'], array_column($checked['advances_paid']['items'], 'status'), "$who: jedes Vorkommen belegt");
            self::assertSame('empty', $checked['meter_number']['status'], $who);
        }
    }

    public function testThePostboxSeesDestinationNoticeAndWhetherTheCloudSends(): void
    {
        $inbox = $this->inboxWith($claude = new AnthropicProvider($this->cfg, $this->i18n, $this->transport(), fn() => ['160.79.104.10']));
        self::assertSame(['local', true], [$inbox->providerInfo()['id'], $inbox->providerInfo()['local']]);
        $this->claude();
        $info = $inbox->providerInfo();
        self::assertSame(['anthropic', false, true, 'Claude (Anthropic)', 'api.anthropic.com', 'claude-opus-5-5', true],
            [$info['id'], $info['local'], $info['available'], $info['label'], $info['destination'], $info['model'], $info['pdf']]);
        self::assertSame($claude->notice(), $info['notice'], 'der Hinweis für „Dieses Dokument geht an …“');
        // nach einer Sicherung von einem anderen Server: gewählt, aber ohne Schlüssel → sendet nicht
        $this->store->delete(CloudConfig::KEY_FILE);
        self::assertSame(['anthropic', false], [$inbox->providerInfo()['id'], $inbox->providerInfo()['available']]);
        // der Zwischenspeicher kennt Anbieter und Modell
        self::assertSame('anthropic|claude-opus-5-5|' . InboxSchema::VERSION, InboxService::cacheKey($claude));
    }

    private function inboxWith(AnthropicProvider $claude): InboxService
    {
        $reg = new ProviderRegistry($this->settings, InboxFixture::provider('local'));
        $reg->register($claude);
        $att = new AttachmentService($this->store, $this->settings, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $rem = new ReminderService($this->store, $this->settings, $this->i18n);
        return new InboxService($this->store, $this->i18n, $att, $reg,
            new Proposals($this->meters, $this->contracts, $this->readings, $bills, $rem, $this->settings),
            new Applier($this->meters, $this->contracts, $this->readings, $bills, $rem, $this->settings, $this->i18n));
    }

    /**
     * Die Cloud-Anschlüsse laufen über dieselbe Route wie der lokale
     * (InboxController::read → InboxService → Registry); dort hebt der
     * Controller die PHP-Zeitgrenze an. Das längste Zeitlimit eines
     * Cloud-Diensts muss mit Puffer darunter bleiben.
     */
    public function testCloudReadingStaysUnderTheTimeLimitOfTheInboxRoute(): void
    {
        $this->cfg->update(['timeout_s' => 600]);
        self::assertSame(600, $this->cfg->timeout());
        try { $this->cfg->update(['timeout_s' => 601]); self::fail('Zeitlimit über 600 s angenommen'); }
        catch (\InvalidArgumentException) { self::assertSame(600, $this->cfg->timeout()); }
        self::assertGreaterThanOrEqual($this->cfg->timeout() + 30, \Energietracker\Controllers\InboxController::READ_TIME_LIMIT_S,
            'PHP bräche sonst mitten im Warten auf den Dienst ab');
        self::assertStringContainsString('$this->inbox->extract($id, $pages, $consent, $fresh)',
            (string)file_get_contents(dirname(__DIR__, 3) . '/src/Controllers/InboxController.php'), 'Auslesen nur über den Posteingang');
    }

    /** Wer ausliest, ist eine Einstellung: Cloud nur eingeschaltet und mit Schlüssel. */
    public function testTheCloudIsOnlySelectableWhenSwitchedOnWithAKey(): void
    {
        $choose = function (string $id): string {
            try { $this->settings->set(['inbox_provider' => $id]); return 'ok'; }
            catch (\InvalidArgumentException $e) { return $e->getMessage(); }
        };
        self::assertSame('errors.inboxCloud.off', $choose('anthropic'), 'aus: nicht wählbar');
        $this->settings->set(['inbox_cloud_enabled' => true, 'inbox_cloud_provider' => 'anthropic']);
        self::assertSame('errors.inboxCloud.off', $choose('anthropic'), 'eingeschaltet, aber ohne Schlüssel: nicht wählbar');
        self::assertSame('local', $this->settings->get('inbox_provider'));
        $this->claude();
        self::assertSame('anthropic', $this->settings->get('inbox_provider'), 'Einschalten wählt den Dienst');
        self::assertSame('errors.inboxCloud.off', $choose('openai'), 'nur der eingeschaltete Dienst');
        self::assertSame('ok', $choose('local'));
        // Speichern der Karte bei eingeschalteter Cloud lässt „Lokal“ stehen
        $this->cfg->update(['timeout_s' => 90]);
        self::assertSame('local', $this->settings->get('inbox_provider'));
        self::assertSame([true, false], [$this->cfg->status()['ready'], $this->cfg->status()['active']]);
        self::assertSame('ok', $choose('anthropic'));
        self::assertTrue($this->cfg->status()['active']);
        // Ausschalten über die Einstellungen (nicht die Karte): zurück auf lokal
        $this->settings->set(['inbox_cloud_enabled' => false]);
        self::assertSame('local', $this->settings->get('inbox_provider'));
        self::assertSame('errors.settings.valueInvalid', $choose('mistral'));
    }

    public function testSchemaShapes(): void
    {
        self::assertSame([
            ['name' => 'a', 'description' => 'first', 'multiple' => false],
            ['name' => 'b', 'description' => 'second (type date, format JJJJ-MM-TT)', 'multiple' => false],
            ['name' => 'c', 'description' => '', 'multiple' => true],
        ], CloudPrompt::fields(['properties' => ['a' => ['description' => 'first'], 'b' => ['hint' => 'second', 'type' => 'date', 'format' => 'JJJJ-MM-TT'],
            'c' => ['type' => 'array'], 'bad name!' => 'x']]));
        self::assertSame(['x', 'y'], array_column(CloudPrompt::fields([['name' => 'x'], ['name' => 'y', 'label' => 'Y']]), 'name'));
    }

    // ── v3.7.0 (Karte „Auslesen“): Voreinstellungen, Modellliste, eine Wahrheit ──

    /** Die Adressen laut Doku der Anbieter; alle über den OpenAI-kompatiblen Anschluss und CloudHttp. */
    public function testPresetsFillProviderAndAddress(): void
    {
        self::assertSame(['anthropic', 'openai', 'mistral', 'openrouter', 'gemini', 'custom'], array_keys(CloudConfig::PRESETS));
        $chat = [
            'openai'     => 'https://api.openai.com/v1/chat/completions',
            'mistral'    => 'https://api.mistral.ai/v1/chat/completions',
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
        ];
        foreach ($chat as $id => $url) {
            $p = CloudConfig::PRESETS[$id];
            self::assertSame('openai', $p['provider'], $id);
            self::assertSame($p['endpoint'], CloudHttp::checkUrl($p['endpoint']) !== '' ? $p['endpoint'] : '', "$id: https und öffentlich");
            self::assertSame($url, CloudHttp::apiUrl($p['endpoint'], 'chat/completions'), $id);
            self::assertSame(substr($url, 0, -strlen('chat/completions')) . 'models', CloudHttp::apiUrl($p['endpoint'], 'models'), $id);
            $this->cfg->update(['preset' => $id]);
            self::assertSame([$id, 'openai', $p['endpoint']], [$this->cfg->preset(), $this->cfg->provider(), $this->cfg->endpoint()]);
            self::assertSame($p['label'], (new OpenAiCompatibleProvider($this->cfg, $this->i18n))->label(), 'der Dialog nennt den Dienst beim Namen');
        }
        // eine eigene Adresse bleibt „eigene Adresse“ — auch ohne /v1 (wie bisher /v1/chat/completions)
        $this->cfg->update(['preset' => 'custom', 'endpoint' => 'https://llm.example.com']);
        self::assertSame('custom', $this->cfg->preset());
        self::assertSame('https://llm.example.com/v1/chat/completions', CloudHttp::apiUrl($this->cfg->endpoint(), 'chat/completions'));
        self::assertSame('https://llm.example.com/v1/chat/completions', CloudHttp::apiUrl('https://llm.example.com/v1/chat/completions', 'chat/completions'));
        self::assertSame('OpenAI-kompatibler Dienst', (new OpenAiCompatibleProvider($this->cfg, $this->i18n))->label());
        $this->cfg->update(['preset' => 'anthropic']);
        self::assertSame(['anthropic', 'anthropic'], [$this->cfg->preset(), $this->cfg->provider()]);
        self::assertSame('errors.settings.valueInvalid', $this->failure(fn() => $this->cfg->update(['preset' => 'elsewhere']), 'none')->key);
        // keine fest eingebauten Modellnamen außer dem Claude-Standard
        self::assertSame([], array_filter(array_column(CloudConfig::PRESETS, 'model')));
        self::assertSame(['anthropic', 'openai', 'mistral', 'openrouter', 'gemini', 'custom'], array_column($this->cfg->status()['presets'], 'id'));
    }

    /** Doku-Behauptung mit Test: Die Anleitung (DE+EN) nennt jede Voreinstellung mit ihrer Adresse und „experimentell“. */
    public function testTheGuideNamesEveryPresetAddressAndThatItIsExperimental(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docs/anleitungen/posteingang.md' => 'Experimentell', 'docs/en/anleitungen/posteingang.md' => 'Experimental'] as $file => $word) {
            $doc = (string)file_get_contents("$root/$file");
            foreach (CloudConfig::PRESETS as $id => $p) {
                if ($p['endpoint'] !== null) self::assertStringContainsString('`' . $p['endpoint'] . '`', $doc, "$file: $id");
                if ($p['label'] !== null) self::assertStringContainsString($p['label'], $doc, "$file: $id");
            }
            self::assertStringContainsString("**$word.**", $doc, $file);
            self::assertStringContainsString('GET ' . AnthropicProvider::MODELS_URL, $doc, "$file: Modellliste von Claude");
        }
    }

    /** Ein Schlüssel gehört zum Host: Der von Mistral geht nie an OpenRouter, und er ist beim Zurückstellen noch da. */
    public function testKeysBelongToTheirHost(): void
    {
        $this->cfg->update(['preset' => 'mistral', 'api_key' => 'sk-mistral-test-ok']);
        self::assertTrue($this->cfg->hasKey('openai'));
        $this->cfg->update(['preset' => 'openrouter']);
        self::assertSame('', $this->cfg->key('openai'), 'nach dem Wechsel kein Schlüssel für den neuen Dienst');
        self::assertFalse($this->cfg->status()['has_key']);
        $by = array_column($this->cfg->status()['presets'], 'has_key', 'id');
        self::assertSame([true, false], [$by['mistral'], $by['openrouter']]);
        self::assertSame('errors.inboxCloud.noKey', $this->failure(fn() => $this->cfg->update(['enabled' => true, 'model' => 'm']), 'sk-mistral-test-ok')->key);
        $or = new OpenAiCompatibleProvider($this->cfg, $this->i18n, $this->transport(), fn() => ['8.8.8.8']);
        self::assertSame('errors.inboxCloud.noKey', $this->failure(fn() => $or->models(), 'sk-mistral-test-ok')->key);
        self::assertSame([], $this->calls, 'nichts an OpenRouter');
        $this->cfg->update(['preset' => 'mistral']);
        self::assertSame('sk-mistral-test-ok', $this->cfg->key('openai'));
        // Schlüssel von vorher (ohne Host) gilt für die eingetragene Adresse und bleibt bei ihr
        $this->store->write(CloudConfig::KEY_FILE, ['openai' => 'sk-legacy-test-ok']);
        $this->cfg->update(['preset' => 'custom', 'endpoint' => 'https://llm.example.com/v1']);
        self::assertSame('', $this->cfg->key('openai'), 'der alte Schlüssel gehörte zu api.mistral.ai');
        $this->cfg->update(['preset' => 'mistral']);
        self::assertSame('sk-legacy-test-ok', $this->cfg->key('openai'));
        self::assertSame(['openai@api.mistral.ai'], array_keys($this->store->read(CloudConfig::KEY_FILE, [])));
        // löschen trifft nur diesen Host
        $this->cfg->update(['preset' => 'custom', 'endpoint' => 'https://llm.example.com/v1', 'api_key' => 'sk-own-test-ok']);
        $this->cfg->update(['clear_key' => true]);
        self::assertSame(['openai@api.mistral.ai'], array_keys($this->store->read(CloudConfig::KEY_FILE, [])));
        self::assertStringNotContainsString('sk-', (string)json_encode($this->cfg->status()), 'kein Schlüssel im Stand');
    }

    public function testTheOpenAiCompatibleModelListComesFromTheService(): void
    {
        $this->cfg->update(['preset' => 'custom', 'endpoint' => 'https://llm.example.com/v1', 'api_key' => 'sk-oa-test-ok']);
        self::assertFalse($this->cfg->enabled(), 'die Liste braucht kein Einschalten, nur den Schlüssel');
        $p = new OpenAiCompatibleProvider($this->cfg, $this->i18n, $this->transport(), fn(string $h): array => ['llm.example.com' => ['8.8.8.8']][$h] ?? []);
        $models = $p->models();
        self::assertSame([['id' => 'test-model-a', 'label' => ''], ['id' => 'vendor/test-model-b:free', 'label' => 'Test Model B']], $models,
            'nur speicherbare Kennungen');
        $req = self::lastRequest();
        self::assertSame(['/v1/models', 'GET', 'Bearer sk-oa-test-ok'], [$req['path'], json_decode((string)file_get_contents(self::$log), true)['method'], $req['headers']['authorization'] ?? '']);
        self::assertSame([['url' => 'https://llm.example.com/v1/models', 'ip' => '8.8.8.8']], $this->calls, 'an die geprüfte Adresse');
        // jede Kennung der Liste lässt sich als Modell speichern
        foreach ($models as $m) { $this->cfg->update(['model' => $m['id']]); self::assertSame($m['id'], $this->cfg->model('openai')); }
        // Gemini: …/v1beta/openai/models
        $this->cfg->update(['preset' => 'gemini', 'api_key' => 'sk-gem-test-ok']);
        (new OpenAiCompatibleProvider($this->cfg, $this->i18n, $this->transport(), fn() => ['8.8.8.8']))->models();
        self::assertSame('/v1beta/openai/models', self::lastRequest()['path']);
    }

    public function testClaudeModelList(): void
    {
        $this->cfg->update(['preset' => 'anthropic', 'api_key' => 'sk-ant-test-ok']);
        $p = new AnthropicProvider($this->cfg, $this->i18n, $this->transport(), fn() => ['160.79.104.10']);
        self::assertSame([['id' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5'], ['id' => 'claude-test-small', 'label' => 'Claude Test Small']], $p->models());
        $req = self::lastRequest();
        self::assertSame(['/v1/models', 'sk-ant-test-ok', '2023-06-01'], [$req['path'], $req['headers']['x-api-key'] ?? '', $req['headers']['anthropic-version'] ?? '']);
        self::assertArrayNotHasKey('authorization', $req['headers']);
        self::assertSame('https://api.anthropic.com/v1/models?limit=1000', $this->calls[0]['url']);
        self::assertSame(AnthropicProvider::DEFAULT_MODEL, $p->model(), 'der einzige fest eingebaute Modellname');
    }

    public function testModelListFailuresKeepTheKeyAndTheRules(): void
    {
        $mk = function (string $key, array $dns = ['llm.example.com' => ['8.8.8.8']]): OpenAiCompatibleProvider {
            $this->cfg->update(['preset' => 'custom', 'endpoint' => 'https://llm.example.com/v1', 'api_key' => $key]);
            return new OpenAiCompatibleProvider($this->cfg, $this->i18n, $this->transport(), fn(string $h): array => $dns[$h] ?? []);
        };
        $e = $this->failure(fn() => $mk('sk-oa-test-401')->models(), 'sk-oa-test-401');
        self::assertSame([502, 'errors.inboxCloud.unauthorized'], [$e->status ?? null, $e->key]);
        self::assertSame('errors.inboxCloud.rejected', $this->failure(fn() => $mk('sk-oa-test-echo')->models(), 'sk-oa-test-echo')->key);
        self::assertSame('errors.inboxCloud.redirect', $this->failure(fn() => $mk('sk-oa-test-redirect')->models(), 'sk-oa-test-redirect')->key);
        self::assertSame([], $mk('sk-oa-test-empty')->models());
        $this->calls = [];
        self::assertSame('errors.inboxCloud.notPublic', $this->failure(fn() => $mk('sk-oa-test-ok', ['llm.example.com' => ['192.168.178.20']])->models(), 'sk-oa-test-ok')->key);
        self::assertSame([], $this->calls, 'Heimnetz: keine Verbindung');
        self::assertSame('errors.inboxCloud.badAnswer', $this->failure(fn() => CloudHttp::models('<html>'), 'none')->key);
    }

    /** Der Umschalter der Karte sagt ausdrücklich, ob die Cloud ausliest (`select`). */
    public function testSelectFollowsTheSwitchOfTheCard(): void
    {
        $this->cfg->update(['preset' => 'anthropic', 'enabled' => true, 'api_key' => 'sk-ant-test-ok', 'select' => true]);
        self::assertSame('anthropic', $this->settings->get('inbox_provider'));
        $this->settings->set(['inbox_provider' => 'local']);
        $this->cfg->update(['enabled' => true, 'select' => false]);
        self::assertSame('local', $this->settings->get('inbox_provider'), 'select: false lässt „Lokal“');
        $this->cfg->update(['enabled' => true, 'select' => true]);
        self::assertSame('anthropic', $this->settings->get('inbox_provider'), 'Cloud gewählt und gespeichert: sie liest aus');
        $this->cfg->update(['enabled' => false, 'select' => true]);
        self::assertSame('local', $this->settings->get('inbox_provider'), 'aus: es liest lokal, egal was gewählt war');
    }

    public function testEveryCloudPathIsMarkedExperimental(): void
    {
        self::assertTrue($this->cfg->status()['experimental']);
        $inbox = $this->inboxWith(new AnthropicProvider($this->cfg, $this->i18n, $this->transport(), fn() => ['160.79.104.10']));
        self::assertFalse($inbox->providerInfo()['experimental'], 'lokal nicht');
        $this->claude();
        self::assertTrue($inbox->providerInfo()['experimental'], 'auch Claude');
    }

    /** „Verbindung prüfen“ für den eigenen Dienst: dieselbe Heimnetz-Regel wie die Texterkennung. */
    public function testTheLocalCheckListsTheModelsOfTheOwnService(): void
    {
        $ocr = new OcrService($this->settings, new AttachmentService($this->store, $this->settings, $this->i18n));
        self::assertSame(['qwen3.8:latest', 'qwen2.5vl:7b'], $ocr->listModels(self::$base, 'ollama'));
        self::assertSame('/api/tags', self::lastRequest()['path']);
        self::assertSame(['qwen3.8'], $ocr->listModels(self::$base . '/v1', 'openai'));
        self::assertSame('/v1/models', self::lastRequest()['path']);
        @unlink(self::$log);
        // 192.0.2.0/24 ist nur für Beispiele (RFC 5737): selbst ohne Prüfung käme dort nichts an
        foreach (['http://192.0.2.10:11434', 'http://169.254.169.254'] as $url) {
            try { $ocr->listModels($url, 'ollama'); self::fail("$url angenommen"); }
            catch (LocalizedException $e) { self::assertSame('errors.ocr.notLocal', $e->key, $url); }
        }
        try { $ocr->listModels('', 'ollama'); self::fail('ohne Adresse'); } catch (LocalizedException $e) { self::assertSame('errors.ocr.off', $e->key); }
        self::assertFileDoesNotExist(self::$log, 'keine Verbindung nach außen');
    }
}
