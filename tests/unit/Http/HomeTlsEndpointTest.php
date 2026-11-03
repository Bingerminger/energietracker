<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.10.0 (GitHub #36) — Eigenes Stammzertifikat über HTTP: Die App liest
 * `ET_CA_FILE` aus der Umgebung, die Diagnose nennt, wie viele eigene
 * Stammzertifikate gelten (ohne Pfad), die Einstellung lehnt einen privaten
 * Schlüssel ab, und ändern dürfen beide Einstellungen nur Verwalter.
 */
final class HomeTlsEndpointTest extends HttpServerTestCase
{
    private string $caDir = '';

    protected function setUp(): void
    {
        if (!function_exists('openssl_pkey_new')) self::markTestSkipped('openssl fehlt');
        $this->caDir = sys_get_temp_dir() . '/et-ca-' . bin2hex(random_bytes(4));
        mkdir($this->caDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->caDir !== '') exec('rm -rf ' . escapeshellarg($this->caDir));
        parent::tearDown();
    }

    /** @return array{0:string, 1:string} Zertifikat und Schlüssel einer selbst signierten Test-CA (PEM) */
    private function ca(): array
    {
        $cnf = $this->caDir . '/openssl.cnf';
        file_put_contents($cnf, "[req]\ndistinguished_name = dn\n[dn]\n[v3_ca]\nbasicConstraints = critical,CA:TRUE\nkeyUsage = critical,keyCertSign\n");
        // RSA statt EC: openssl_csr_new mit EC-Schlüssel liefert unter PHP 8.2/8.3 auf den CI-Runnern false
        $conf = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $key = openssl_pkey_new($conf);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Musterstadt Test-CA'], $key, $conf), null, $key, 2, $conf + ['x509_extensions' => 'v3_ca'], 1);
        openssl_x509_export($cert, $pem);
        openssl_pkey_export($key, $keyPem, null, $conf);
        return [$pem, $keyPem];
    }

    private function patch(array $body, array $headers = []): array
    {
        return $this->request('PATCH', '/api/settings', array_merge(['Content-Type: application/json'], $headers), json_encode($body));
    }

    public function testTheFileFromEtCaFileCountsAndTheDiagnosisNamesIt(): void
    {
        [$pem, $key] = $this->ca();
        file_put_contents($this->caDir . '/root.pem', $pem);
        $this->startServer(['language' => 'de'], ['ET_CA_FILE' => $this->caDir . '/root.pem']);
        $tls = $this->json('GET', '/api/diagnostics')['home_tls'] ?? null;
        self::assertSame(['ok', 1, 0, false], [$tls['ca_file'] ?? null, $tls['ca_file_certs'] ?? null, $tls['ca_settings_certs'] ?? null, $tls['insecure_local'] ?? null]);
        self::assertStringNotContainsString($this->caDir, json_encode($tls), 'kein Pfad in der Diagnose');

        $bad = $this->patch(['home_ca_pem' => $pem . $key]);
        self::assertSame([400, 'errors.settings.caPrivateKey'], [$bad['status'], json_decode($bad['body'], true)['code'] ?? null]);
        self::assertStringNotContainsString('PRIVATE KEY', (string)file_get_contents($this->dataDir . '/settings.json'));
        self::assertSame(200, $this->patch(['home_ca_pem' => $pem, 'home_tls_insecure' => true])['status']);
        $tls = $this->json('GET', '/api/diagnostics')['home_tls'];
        self::assertSame([1, true], [$tls['ca_settings_certs'], $tls['insecure_local']]);
    }

    public function testAMissingFileIsReportedNotIgnored(): void
    {
        $this->startServer(['language' => 'de'], ['ET_CA_FILE' => $this->caDir . '/fehlt.pem']);
        self::assertSame('unreadable', $this->json('GET', '/api/diagnostics')['home_tls']['ca_file'] ?? null);
    }

    public function testOnlyAdminsDecideWhomTheAppTrusts(): void
    {
        [$pem] = $this->ca();
        $this->startServer();
        $admin = $this->cookieFrom($this->request('POST', '/api/session/password', ['Content-Type: application/json'], json_encode(['password' => 'geheim-123'])));
        $this->request('POST', '/api/users', array_merge(['Content-Type: application/json'], $admin),
            json_encode(['name' => 'Anna', 'password' => 'annas-passwort', 'role' => 'member']));
        $member = $this->cookieFrom($this->request('POST', '/api/session', ['Content-Type: application/json'],
            json_encode(['name' => 'Anna', 'password' => 'annas-passwort'])));
        foreach ([['home_ca_pem' => $pem], ['home_tls_insecure' => true]] as $body) {
            $r = $this->patch($body, $member);
            self::assertSame([403, 'errors.auth.adminOnly'], [$r['status'], json_decode($r['body'], true)['code'] ?? null], json_encode(array_keys($body)));
        }
        self::assertSame(200, $this->patch(['home_tls_insecure' => false, 'anomaly_threshold' => 2.5], $member)['status'], 'unverändert sperrt nichts');
        self::assertSame(200, $this->patch(['home_ca_pem' => $pem], $admin)['status']);
    }

    private function cookieFrom(array $r): array
    {
        preg_match('/et_session=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        return ['Cookie: et_session=' . ($m[1] ?? '')];
    }
}
