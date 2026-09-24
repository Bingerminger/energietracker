<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * v2.7.0 — Erststart mit Länderprofil (I18N-01, N1014), über HTTP gegen
 * einen echten PHP-Server mit leerem Datenverzeichnis.
 *
 * Bis v2.6.0 begann jede neue Installation deutsch: Sprache, Zählernamen,
 * Euro, Leipzig. Jetzt entscheidet beim allerersten Aufruf die
 * Accept-Language-Kopfzeile. Geschrieben werden nur Werte, die vom Default
 * abweichen — sonst griffe eine spätere Korrektur eines Defaults bei neuen
 * Installationen nicht mehr. Danach ändert die Kopfzeile nichts mehr.
 */
final class FirstStartProfileTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    private string $base = '';
    private string $dataDir = '';

    private function start(string $acceptLanguage): void
    {
        $root = dirname(__DIR__, 3);
        $this->dataDir = sys_get_temp_dir() . '/et-first-' . bin2hex(random_bytes(4));
        mkdir($this->dataDir, 0755, true);
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
        // Schon die Bereitschaftsprobe ist der erste Aufruf — sie trägt die Kopfzeile.
        for ($i = 0; $i < 100; $i++) {
            if ($this->get('/api/health', $acceptLanguage) !== null) return;
            usleep(50_000);
        }
        self::fail('Testserver startet nicht');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) { proc_terminate($this->proc); proc_close($this->proc); }
        if ($this->dataDir !== '') exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    private function get(string $path, string $acceptLanguage): mixed
    {
        $ctx = stream_context_create(['http' => [
            'header' => "Accept-Language: $acceptLanguage", 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($this->base . $path, false, $ctx);
        if ($raw === false) return null;
        $json = json_decode($raw, true);
        return is_array($json) && array_key_exists('data', $json) ? $json['data'] : $json;
    }

    public function testFrenchBrowserGetsTheFrenchProfile(): void
    {
        $this->start('fr-FR,fr;q=0.9,en;q=0.5');

        // Ein späterer Aufruf mit anderer Sprache ändert nichts mehr.
        $s = $this->get('/api/settings', 'de-DE,de;q=0.9');
        self::assertSame('fr', $s['language']);
        self::assertSame('FR', $s['country']);
        self::assertSame('EUR', $s['currency']);
        self::assertSame('Europe/Paris', $s['timezone']);
        self::assertEquals(18.0, $s['hdd_base_temp'], 'Heizgrenze nach französischer DJU-Konvention');
        self::assertSame('Paris', $s['location_name']);

        $stored = json_decode((string)file_get_contents($this->dataDir . '/settings.json'), true);
        foreach (['language', 'country', 'timezone', 'hdd_base_temp', 'co2_strom', 'location_name'] as $k) {
            self::assertArrayHasKey($k, $stored, "$k weicht vom Default ab und gehört in settings.json");
        }
        foreach (['currency', 'gas_cv_unit'] as $k) {
            self::assertArrayNotHasKey($k, $stored, "$k entspricht dem Default und darf nicht festgeschrieben werden");
        }

        $meters = $this->get('/api/utility/gas/meters', 'de-DE');
        self::assertSame('Compteur principal', $meters[0]['name'] ?? null, 'Standardzähler in der Sprache des Erststarts');

        $countries = $this->get('/api/countries', 'fr-FR');
        self::assertSame(['DE', 'AT', 'CH', 'FR', 'IT', 'ES', 'PT', 'NL', 'GB'], array_column($countries, 'code'));
    }

    public function testBritishBrowserGetsPoundsAndMegajoules(): void
    {
        $this->start('en-GB,en;q=0.9');
        $s = $this->get('/api/settings', 'en-GB');
        self::assertSame('en', $s['language']);
        self::assertSame('GB', $s['country']);
        self::assertSame('GBP', $s['currency']);
        self::assertSame('mj', $s['gas_cv_unit']);
        self::assertSame('Europe/London', $s['timezone']);
    }

    public function testGermanBrowserWritesNothing(): void
    {
        $this->start('de-DE,de;q=0.9');
        $s = $this->get('/api/settings', 'de-DE');
        self::assertSame('de', $s['language']);
        self::assertSame('DE', $s['country']);
        $stored = is_file($this->dataDir . '/settings.json')
            ? json_decode((string)file_get_contents($this->dataDir . '/settings.json'), true) : [];
        self::assertSame([], array_intersect(array_keys($stored ?: []), ['language', 'country', 'currency', 'timezone']),
            'Beim deutschen Erststart entspricht alles dem Default — nichts wird festgeschrieben');
    }
}
