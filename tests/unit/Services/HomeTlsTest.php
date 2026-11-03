<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AttachmentService;
use Energietracker\Services\HomeAssistant\NotifyTransport;
use Energietracker\Services\OcrService;
use Energietracker\Support\HomeTls;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.10.0 (GitHub #36) — HTTPS ins Heimnetz mit eigener PKI.
 *
 * Bis v3.9.0 prüfte der Energietracker das Zertifikat nur gegen die
 * Stammzertifikate des Systems; Home Assistant mit eigener CA scheiterte mit
 * „unable to get local issuer certificate“. Jetzt zwei Wege:
 *   1. eigenes Stammzertifikat (Einstellung `home_ca_pem` oder Datei
 *      `ET_CA_FILE`), zusätzlich zu den Systemzertifikaten, Prüfung an;
 *   2. „Zertifikat im Heimnetz nicht prüfen“, nur für Adressen im Heimnetz.
 *
 * Gegen einen echten HTTPS-Server (tests/fixtures/fake-tls.php) mit einer
 * hier erzeugten Zertifizierungsstelle; das Zertifikat nennt `ha.test` und
 * 127.0.0.1. Kein Dienst außerhalb dieses Rechners.
 */
final class HomeTlsTest extends ServiceTestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $dir = '';
    private static string $caPem = '';
    private static string $otherCaPem = '';
    private static string $keyPem = '';

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('openssl_pkey_new') || !function_exists('curl_init')) return;
        self::$dir = sys_get_temp_dir() . '/et-tls-' . bin2hex(random_bytes(4));
        mkdir(self::$dir, 0700, true);
        $cnf = self::$dir . '/openssl.cnf';
        file_put_contents($cnf, "[req]\ndistinguished_name = dn\n[dn]\n"
            . "[v3_ca]\nbasicConstraints = critical,CA:TRUE\nkeyUsage = critical,keyCertSign,cRLSign\nsubjectKeyIdentifier = hash\n"
            . "[v3_srv]\nbasicConstraints = CA:FALSE\nkeyUsage = critical,digitalSignature\nextendedKeyUsage = serverAuth\n"
            . "subjectAltName = DNS:ha.test,DNS:ocr.test,IP:127.0.0.1\nsubjectKeyIdentifier = hash\nauthorityKeyIdentifier = keyid\n");
        // RSA statt EC: openssl_csr_new mit EC-Schlüssel liefert unter PHP 8.2/8.3 mit OpenSSL 3 auf den
        // CI-Runnern false (lokal nicht nachstellbar); RSA geht überall
        $conf = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        [$ca, $caKey] = self::ca('Musterstadt Test-CA', $conf);
        [$other] = self::ca('Andere Test-CA', $conf);
        $key = openssl_pkey_new($conf);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'ha.test'], $key, $conf), $ca, $caKey, 2, $conf + ['x509_extensions' => 'v3_srv'], 2);
        openssl_x509_export($ca, self::$caPem);
        openssl_x509_export($other, self::$otherCaPem);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, self::$keyPem, null, $conf);
        file_put_contents(self::$dir . '/server.pem', $certPem . self::$keyPem);
        file_put_contents(self::$dir . '/ca.pem', self::$caPem);

        $s = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int)substr((string)strrchr((string)stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $env = array_merge(getenv(), ['FAKE_TLS_PORT' => (string)self::$port, 'FAKE_TLS_PEM' => self::$dir . '/server.pem']);
        self::$server = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/fake-tls.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, self::$dir, $env);
        for ($i = 0; $i < 100; $i++) {
            $fp = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($fp) { fclose($fp); return; }
            usleep(50_000);
        }
    }

    /** Eine selbst signierte Zertifizierungsstelle. @return array{0:\OpenSSLCertificate, 1:\OpenSSLAsymmetricKey} */
    private static function ca(string $name, array $conf): array
    {
        $key = openssl_pkey_new($conf);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => $name], $key, $conf), null, $key, 2, $conf + ['x509_extensions' => 'v3_ca'], 1);
        return [$cert, $key];
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) { proc_terminate(self::$server); proc_close(self::$server); }
        if (self::$dir !== '') exec('rm -rf ' . escapeshellarg(self::$dir));
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$port === 0) self::markTestSkipped('openssl oder curl fehlt');
    }

    protected function tearDown(): void
    {
        HomeTls::reset();
        parent::tearDown();
    }

    private function configure(string $pem = '', bool $insecure = false, ?string $file = null, ?bool $blob = null): void
    {
        HomeTls::configure(fn(): array => ['pem' => $pem, 'insecure' => $insecure], $file, $blob);
    }

    /** Home Assistant über HTTPS, der Name zeigt auf 127.0.0.1 (wie im Heimnetz). */
    private function notify(): array
    {
        return (new NotifyTransport(null, fn(string $h): array => ['127.0.0.1']))
            ->post('https://ha.test:' . self::$port . '/api/webhook/energietracker', ['title' => 'Test', 'message' => 'Hallo']);
    }

    private function ocr(): OcrService
    {
        return new OcrService($this->settings, new AttachmentService($this->store, $this->settings, $this->i18n), null, fn(string $h): array => ['127.0.0.1']);
    }

    // ── 1. eigenes Stammzertifikat ───────────────────────────────────────

    public function testWithoutTheOwnRootTheCertificateIsRefusedWithItsOwnReason(): void
    {
        self::assertSame(['ok' => false, 'status' => null, 'error' => 'tlsIssuer'], $this->notify(), 'bis v3.9.0: „network“');
        $this->configure(self::$otherCaPem);
        self::assertSame('tlsIssuer', $this->notify()['error'], 'eine fremde CA hilft nicht: die Prüfung ist an');
        try {
            $this->ocr()->listModels('https://ocr.test:' . self::$port, 'ollama');
            self::fail('Ausnahme erwartet');
        } catch (LocalizedException $e) {
            self::assertSame(['errors.homeTls.issuer', 'ocr.test'], [$e->key, $e->params['host']]);
        }
    }

    /**
     * Skeptiker N8 — Passt das Zertifikat nicht zur Adresse, hilft kein eigenes
     * Stammzertifikat: eigene Art, eigener Rat (auch wenn der Aussteller bekannt ist).
     */
    public function testANameMismatchIsNotAnUnknownIssuer(): void
    {
        $this->configure(self::$caPem);
        $wrongName = NotifyTransport::curlPost('https://anders.test:' . self::$port . '/x', '{}', [], 5, '127.0.0.1');
        self::assertSame('tlsName', $wrongName['error_code'] ?? null);
        try {
            $this->ocr()->listModels('https://anders.test:' . self::$port, 'ollama');
            self::fail('Ausnahme erwartet');
        } catch (LocalizedException $e) {
            self::assertSame(['errors.homeTls.name', 'anders.test'], [$e->key, $e->params['host']]);
        }
    }

    /** Skeptiker N8 — ET_CA_FILE gesetzt, aber nicht lesbar: der Rat nennt die Datei, nicht „Stammzertifikat eintragen“. */
    public function testAnUnreadableEtCaFileIsNamedAsTheCause(): void
    {
        $this->configure('', false, self::$dir . '/fehlt.pem');
        self::assertSame('tlsFile', $this->notify()['error']);
        file_put_contents(self::$dir . '/leer.pem', "kein Zertifikat\n");
        $this->configure('', false, self::$dir . '/leer.pem');
        self::assertSame('tlsFile', $this->notify()['error'], 'eine Datei ohne Zertifikat genauso');
    }

    /**
     * Skeptiker N8 — die Art aus curl-Fehlercode und Prüfergebnis von OpenSSL,
     * nie aus dem Meldungstext. Abgelaufen lässt sich hier nicht echt erzeugen
     * (openssl_csr_sign nimmt keine negative Laufzeit), daher die Zuordnung.
     */
    public function testTheKindComesFromCodesNotFromText(): void
    {
        $this->configure();
        foreach ([
            [77, 0, 'tlsFile'], [51, 0, 'tlsName'], [83, 0, 'tlsIssuer'],
            [60, 20, 'tlsIssuer'], [60, 2, 'tlsIssuer'], [60, 18, 'tlsIssuer'], [60, 19, 'tlsIssuer'], [60, 21, 'tlsIssuer'],
            [60, 62, 'tlsName'], [60, 64, 'tlsName'], [60, 10, 'tlsExpired'], [60, 9, 'tlsExpired'], [60, 50, 'tlsOther'],
            [7, 0, null], [28, 0, null], [6, 0, null],
        ] as [$errno, $verify, $kind]) {
            self::assertSame($kind, HomeTls::certError($errno, $verify), "curl $errno, OpenSSL $verify");
        }
        // Kette in Ordnung, Name passt nicht: curl 8 mit OpenSSL meldet 60 mit 1 (früher 0); sonst nicht sicher
        $openssl = (bool)preg_match('/^(OpenSSL|LibreSSL|BoringSSL|quictls|AWS-LC)\b/i', (string)(curl_version()['ssl_version'] ?? ''));
        self::assertSame($openssl ? ['tlsName', 'tlsName'] : ['tlsOther', 'tlsOther'], [HomeTls::certError(60, 1), HomeTls::certError(60, 0)]);
        // jede Art hat ihre Meldung, und die Oberfläche (Home Assistant) kennt dieselben
        $js = (string)file_get_contents(dirname(__DIR__, 3) . '/public/js/components/ha-notify.js');
        preg_match('/const CERT_REASONS = \{(.*?)\};/s', $js, $m);
        preg_match_all("/(\\w+): '([\\w.]+)'/", $m[1] ?? '', $pairs, PREG_SET_ORDER);
        self::assertSame(HomeTls::CERT_ERRORS, array_column($pairs, 2, 1));
        $de = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/public/locales/de.json'), true);
        foreach (HomeTls::CERT_ERRORS as $key) {
            $text = $de['errors']['homeTls'][substr($key, strlen('errors.homeTls.'))] ?? null;
            self::assertIsString($text, $key);
            self::assertStringNotContainsString('PKI', $text, "$key: kein Fachwort");
        }
        self::assertStringContainsString('hilft hier nicht', $de['errors']['homeTls']['name'], 'Namensabweichung: kein Rat zum Stammzertifikat');
        self::assertStringContainsString('ET_CA_FILE', $de['errors']['homeTls']['file']);
        // die Anleitung nennt denselben Rat je Art (DE und EN)
        foreach (['docs/anleitungen/home-assistant.md' => 'Ein eigenes Stammzertifikat hilft hier nicht.',
                  'docs/en/anleitungen/home-assistant.md' => 'Your own root certificate does not help here.'] as $rel => $nameAdvice) {
            $doc = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            self::assertStringContainsString($nameAdvice, $doc, $rel);
            self::assertMatchesRegularExpression('/^\| [^|]*\| [^|]*`ET_CA_FILE`/m', $doc, "$rel: Zeile zur unlesbaren Datei");
        }
    }

    public function testTheOwnRootFromTheSettingsIsTrustedAndCheckingStaysOn(): void
    {
        $this->configure(self::$caPem);
        self::assertSame(['ok' => true, 'status' => 200, 'error' => null], $this->notify());
        self::assertSame(['test-model'], $this->ocr()->listModels('https://ocr.test:' . self::$port, 'ollama'));
        // Prüfung an: Der Name muss passen (`anders.test` steht nicht im Zertifikat)
        $wrongName = NotifyTransport::curlPost('https://anders.test:' . self::$port . '/x', '{}', [], 5, '127.0.0.1');
        self::assertFalse($wrongName['ok']);
    }

    public function testTheOwnRootFromEtCaFile(): void
    {
        $this->configure('', false, self::$dir . '/ca.pem');
        self::assertTrue($this->notify()['ok']);
        $this->configure('', false, self::$dir . '/fehlt.pem');
        self::assertFalse($this->notify()['ok'], 'eine fehlende Datei vertraut niemandem zusätzlich');
        self::assertSame('unreadable', HomeTls::status()['ca_file']);
    }

    /** libcurl vor 7.77 kennt CURLOPT_CAINFO_BLOB nicht: dann eine eigene Datei dieser Anfrage. */
    public function testWithoutBlobSupportABundleFileDoesTheSame(): void
    {
        $this->configure(self::$caPem, false, null, false);
        self::assertTrue($this->notify()['ok']);
        $opts = HomeTls::curlOptions('127.0.0.1');
        self::assertArrayHasKey(CURLOPT_CAINFO, $opts);
        self::assertStringContainsString(trim(self::$caPem), (string)file_get_contents($opts[CURLOPT_CAINFO]));
        self::assertSame('0600', substr(sprintf('%o', fileperms($opts[CURLOPT_CAINFO])), -4), 'nur für uns lesbar');
    }

    /**
     * Zusätzlich, nicht statt: Das Bündel beginnt mit der Liste des Systems
     * (CURLOPT_CAINFO ersetzt sie sonst, und öffentliche Ziele scheiterten).
     */
    public function testTheOwnRootIsAddedToTheSystemCertificates(): void
    {
        $this->configure(self::$caPem);
        $status = HomeTls::status();
        if (!$status['system_bundle']) self::markTestSkipped('keine Liste des Systems gefunden');
        $blob = (string)(HomeTls::curlOptions('192.168.178.10')[CURLOPT_CAINFO_BLOB] ?? '');
        self::assertGreaterThan(substr_count(self::$caPem, 'BEGIN CERTIFICATE') + 10, substr_count($blob, 'BEGIN CERTIFICATE'), 'die Systemzertifikate sind dabei');
        self::assertStringEndsWith(trim(self::$caPem) . "\n", $blob);
        self::assertSame($blob, (string)(HomeTls::curlOptions('93.184.216.34')[CURLOPT_CAINFO_BLOB] ?? ''),
            'auch ein Fernzugang auf Home Assistant prüft gegen beide');
        self::assertArrayNotHasKey(CURLOPT_SSL_VERIFYPEER, HomeTls::curlOptions('192.168.178.10'), 'die Prüfung bleibt an');
        self::assertSame(['ca_file' => 'off', 'ca_file_certs' => 0, 'ca_settings_certs' => 1, 'system_bundle' => true, 'insecure_local' => false], $status);
    }

    /** Ohne Konfiguration (CLI, andere Tests) ändert sich nichts. */
    public function testUnconfiguredChangesNothing(): void
    {
        self::assertSame([], HomeTls::curlOptions('127.0.0.1'));
    }

    // ── 2. „Zertifikat im Heimnetz nicht prüfen“ ─────────────────────────

    public function testNotCheckingWorksOnlyInTheHomeNetwork(): void
    {
        $this->configure('', true);
        self::assertTrue($this->notify()['ok'], 'Heimnetz: ohne Prüfung');
        foreach (['127.0.0.1', '192.168.178.10', '10.0.0.5', '172.30.32.2', '100.64.1.2', 'fd00::5', '::1'] as $ip) {
            $o = HomeTls::curlOptions($ip);
            self::assertSame([false, 0], [$o[CURLOPT_SSL_VERIFYPEER] ?? null, $o[CURLOPT_SSL_VERIFYHOST] ?? null], $ip);
        }
        // Cloud- und öffentliche Adressen: wirkungslos, auch Link-local (Metadatendienste) und ein bloßer Name
        foreach (['93.184.216.34', '8.8.8.8', '2001:db8::1', '169.254.169.254', 'fe80::1'] as $ip) {
            self::assertSame([], HomeTls::curlOptions($ip), $ip);
        }
        self::assertSame([], HomeTls::curlOptions(null, 'homeassistant.local'), 'ein Name zählt erst mit seiner aufgelösten Adresse');
        self::assertArrayHasKey(CURLOPT_SSL_VERIFYPEER, HomeTls::curlOptions(null, '[fd00::5]'), 'IPv6 in Klammern');
    }

    /** Standard aus: ohne den Schalter bleibt die Prüfung an. */
    public function testCheckingIsOnByDefault(): void
    {
        $this->configure();
        self::assertSame('tlsIssuer', $this->notify()['error']);
        self::assertFalse((bool)$this->settings->get('home_tls_insecure'));
        self::assertSame('', $this->settings->get('home_ca_pem'));
    }

    // ── Einstellung `home_ca_pem` ────────────────────────────────────────

    public function testTheSettingKeepsOnlyReadableCertificatesAndNeverAKey(): void
    {
        $saved = $this->settings->set(['home_ca_pem' => "subject=CN = Musterstadt Test-CA\r\n" . self::$caPem . "\n" . self::$caPem])['home_ca_pem'];
        self::assertSame(HomeTls::certificates(self::$caPem)[0] . "\n", $saved, 'einheitlich, Text drumherum und Doppeltes fallen weg');
        self::assertSame("", $this->settings->set(['home_ca_pem' => "  \n"])['home_ca_pem'], 'leer = keins');
        foreach ([
            'errors.settings.caPrivateKey' => self::$caPem . self::$keyPem,
            'errors.settings.caInvalid'    => 'kein Zertifikat',
        ] as $key => $value) {
            try {
                $this->settings->set(['home_ca_pem' => $value]);
                self::fail("$key erwartet");
            } catch (LocalizedException $e) {
                self::assertSame($key, $e->key);
            }
        }
        $broken = self::$caPem . "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n";
        try {
            $this->settings->set(['home_ca_pem' => $broken]);
            self::fail('ein unlesbarer Block wird nicht still weggelassen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.settings.caInvalid', $e->key);
        }
        self::assertSame('', $this->settings->get('home_ca_pem'), 'nichts gespeichert');
        self::assertTrue($this->settings->set(['home_tls_insecure' => true])['home_tls_insecure']);
    }

    // ── Jede Verbindung ins Heimnetz, keine nach draußen ─────────────────

    /**
     * Die Anschlüsse ins Heimnetz nehmen die Optionen, die Cloud und die
     * öffentlichen Dienste nicht (dort gilt der Standard des Systems).
     */
    public function testEveryHomeNetworkConnectionUsesItAndNoPublicOne(): void
    {
        $src = dirname(__DIR__, 3) . '/src/Services/';
        foreach (['HomeAssistant/NotifyTransport.php', 'OcrService.php', 'EvccService.php'] as $f) {
            self::assertStringContainsString('HomeTls::curlOptions(', (string)file_get_contents($src . $f), $f);
            self::assertStringContainsString('HomeTls::certError($errno, (int)curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT))', (string)file_get_contents($src . $f), $f);
        }
        foreach (['Inbox/CloudHttp.php', 'WeatherService.php', 'MarketPriceService.php', 'UpdateCheckService.php'] as $f) {
            self::assertStringNotContainsString('HomeTls', (string)file_get_contents($src . $f), $f);
        }
        // das lokale Auslesen im Posteingang geht über den Transport der Texterkennung
        self::assertStringContainsString('OcrService::post(...)', (string)file_get_contents($src . 'Inbox/LocalProvider.php'));
    }
}
