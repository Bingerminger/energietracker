<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * v3.1.0 (Review I18N-29) — Sprache pro Gerät, über HTTP gegen einen echten
 * PHP-Server.
 *
 * Die Oberfläche merkt sich die Sprache jetzt je Gerät und schickt sie als
 * `X-ET-Language`. Der Server folgt ihr bei Bezeichnungen und Meldungen. Ohne
 * Kopfzeile — Downloads, Home Assistant, Skripte — gilt weiter die
 * Standardsprache der Installation (Einstellung `language`), auch wenn der
 * Browser eine andere Accept-Language schickt.
 */
final class DeviceLanguageTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    private string $base = '';
    private string $dataDir = '';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->dataDir = sys_get_temp_dir() . '/et-lang-' . bin2hex(random_bytes(4));
        mkdir($this->dataDir, 0755, true);
        // Standardsprache der Installation: Deutsch
        file_put_contents($this->dataDir . '/settings.json', json_encode(['language' => 'de']));
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $this->base = "http://127.0.0.1:$port";
        $env = array_merge(getenv(), ['ET_DATA_DIR' => $this->dataDir, 'ET_LOG_DEST' => 'null']);
        unset($env['ET_AUTH'], $env['ET_ADMIN_PASSWORD_HASH'], $env['ET_ALLOWED_HOSTS'], $env['ET_DEBUG']);
        $this->proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", 'router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, $root, $env
        );
        for ($i = 0; $i < 100; $i++) {
            if ($this->labels([]) !== null) return;
            usleep(50_000);
        }
        self::fail('Testserver startet nicht');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) { proc_terminate($this->proc); proc_close($this->proc); }
        if ($this->dataDir !== '') exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    /** @return array<string,string>|null Verbrauchsart → Bezeichnung */
    private function labels(array $headers): ?array
    {
        $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'ignore_errors' => true]]);
        $raw = @file_get_contents($this->base . '/api/utilities', false, $ctx);
        if ($raw === false) return null;
        $data = json_decode($raw, true)['data'] ?? null;
        return is_array($data) ? array_column($data, 'label', 'key') : null;
    }

    public function testTheDeviceLanguageWins(): void
    {
        self::assertSame('Electricity', $this->labels(['X-ET-Language: en'])['strom']);
        self::assertSame('Électricité', $this->labels(['X-ET-Language: fr-FR', 'Accept-Language: de'])['strom']);
    }

    /** Antworten nennen den Sprachkopf in `Vary` — sonst mischt ein Cache die Sprachen. */
    public function testResponsesVaryByDeviceLanguage(): void
    {
        foreach (['/api/utilities', '/api/does-not-exist'] as $path) {
            @file_get_contents($this->base . $path, false, stream_context_create(['http' => ['ignore_errors' => true]]));
            $vary = '';
            foreach ($http_response_header ?? [] as $h) if (stripos($h, 'vary:') === 0) $vary = $h;
            self::assertStringContainsString('X-ET-Language', $vary, $path);
        }
    }

    public function testWithoutHeaderTheInstallationLanguageStays(): void
    {
        // Browser auf Englisch, aber kein X-ET-Language (Download, Skript): Standardsprache
        self::assertSame('Strom', $this->labels(['Accept-Language: en-GB,en;q=0.9'])['strom']);
    }

    public function testAnUnknownDeviceLanguageFallsBackToTheInstallation(): void
    {
        self::assertSame('Strom', $this->labels(['X-ET-Language: xx'])['strom']);
    }

    /** v3.1.0 (I18N-30) — Manifest je Sprache; Pfade relativ zur Manifest-Adresse, Kennung unverändert. */
    public function testTheManifestSpeaksTheRequestedLanguage(): void
    {
        $raw = (string)file_get_contents($this->base . '/api/manifest?lang=en');
        $type = '';
        foreach ($http_response_header ?? [] as $h) if (stripos($h, 'content-type:') === 0) $type = $h;
        $m = json_decode($raw, true);
        self::assertStringContainsString('application/manifest+json', $type);
        self::assertSame('en', $m['lang']);
        self::assertStringStartsWith('Consumption, costs and contracts', $m['description']);
        self::assertSame('Record meter readings', $m['shortcuts'][0]['name']);
        self::assertSame(['./', '../../', '../../'], [$m['id'], $m['start_url'], $m['scope']],
            'Kennung wie bisher (sonst erscheint die installierte App doppelt), Pfade relativ zu api.php/api/');
        self::assertSame('../../public/img/icon-light-192.png', $m['icons'][0]['src']);
        $de = json_decode((string)file_get_contents($this->base . '/api/manifest'), true);
        self::assertSame('de', $de['lang'], 'ohne lang: Standardsprache der Installation');
    }
}
