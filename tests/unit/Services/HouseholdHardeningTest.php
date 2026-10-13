<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AuthService;
use Energietracker\Services\BackupService;
use Energietracker\Services\CsvExportService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\EvccService;
use Energietracker\Services\MarketPriceService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherService;
use Energietracker\Services\WeatherSource;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\LocalizedException;
use Energietracker\Support\Outbound;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * v3.4.0 (N1020) — Sicher im Haushalt, auf der Ebene der Dienste: Sperre je
 * Name und als Notbremse, Laufzeitausgleich, Client-Adresse hinter einem
 * Proxy, CSV-Formelschutz für jede Zelle, Kennungen beim Anlegen und im
 * Backup, Abgleiche erst abrufen und dann sperren, Grenzen für Verbindungen
 * nach außen, lexikalische Pfadprüfung im JsonStore.
 */
final class HouseholdHardeningTest extends ServiceTestCase
{
    private function auth(): AuthService
    {
        return new AuthService($this->store);
    }

    public function testNameLockAndGlobalBrake(): void
    {
        $auth = $this->auth();
        $auth->setPassword('geheim-123');
        $auth->createUser('Anna', 'annas-passwort', 'member');
        // Zehn Fehlversuche für „Anna" von zehn Geräten: der Name ist gesperrt …
        for ($i = 1; $i <= 10; $i++) {
            $s = $auth->checkLogin('Anna', 'falsch', "192.168.178.$i")['status'];
            self::assertSame($i < 10 ? 'wrong' : 'locked', $s, "Versuch $i");
        }
        self::assertSame('locked', $auth->checkLogin('anna', 'annas-passwort', '192.168.178.50')['status'], 'auch vom elften Gerät');
        // … die anderen Personen nicht
        self::assertSame('ok', $auth->checkLogin('', 'geheim-123', '192.168.178.50')['status']);

        // Notbremse: der 100. Fehlversuch binnen 15 Minuten (viele Geräte, viele
        // Namen) sperrt alle. 99 sind vorgegeben — jeder Versuch kostet ein bcrypt.
        $store2 = new JsonStore($this->dataDir . '/x');
        $auth2 = new AuthService($store2);
        $auth2->setPassword('geheim-123');
        $store2->write('auth.json', ['login_throttle' => ['all' => ['count' => 98, 'first_at' => time() - 60, 'locked_until' => 0]]]
            + (array)$store2->read('auth.json', []));
        self::assertSame('wrong', $auth2->checkLogin('name98', 'falsch', '10.0.0.98')['status'], 'der 99. noch nicht');
        self::assertSame('locked', $auth2->checkLogin('name99', 'falsch', '10.0.0.99')['status']);
        self::assertSame('locked', $auth2->checkLogin('', 'geheim-123', '10.0.0.100')['status'], 'Notbremse gilt für alle');
    }

    public function testUnknownNamesTakeAsLongAsKnownOnes(): void
    {
        $auth = $this->auth();
        $auth->setPassword('geheim-123');
        $auth->createUser('Anna', 'annas-passwort', 'member');
        $time = function (string $name) use ($auth): float {
            $t0 = hrtime(true);
            $auth->checkLogin($name, 'falsch', '192.168.178.' . random_int(1, 250));
            return (hrtime(true) - $t0) / 1e9;
        };
        $known = min($time('Anna'), $time('Anna'));
        $unknown = min($time('niemand'), $time('niemand'));
        self::assertGreaterThan(0.5 * $known, $unknown,
            sprintf('unbekannter Name %.1f ms, bekannter %.1f ms — die Antwortzeit verriete die Namen', $unknown * 1000, $known * 1000));
    }

    public function testClientAddressBehindTrustedProxies(): void
    {
        $prev = getenv('ET_TRUSTED_PROXIES');
        putenv('ET_TRUSTED_PROXIES=172.18.0.0/16,127.0.0.1');
        try {
            $s = ['REMOTE_ADDR' => '172.18.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 192.168.178.30, 172.18.0.2'];
            self::assertSame('192.168.178.30', AuthService::clientIp($s), 'die letzte Adresse, die kein Proxy ist');
            self::assertSame('192.168.178.7', AuthService::clientIp(['REMOTE_ADDR' => '192.168.178.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']),
                'X-Forwarded-For nur von einem vertrauenswürdigen Proxy');
            self::assertSame('172.18.0.5', AuthService::clientIp(['REMOTE_ADDR' => '172.18.0.5', 'HTTP_X_FORWARDED_FOR' => 'kein-ip']));
        } finally {
            putenv($prev === false ? 'ET_TRUSTED_PROXIES' : "ET_TRUSTED_PROXIES=$prev");
        }
    }

    public function testCsvGuardsEveryCellThatIsNotANumber(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-01-01', 'counter' => -5.5, 'device_id' => '=HYPERLINK("x")', 'note' => '@SUM(A1)'],
        ]);
        $csv = (new CsvExportService($this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n))->readings('strom');
        $row = explode(';', explode("\r\n", $csv)[1]);
        self::assertSame('"\'=HYPERLINK(""x"")"', $row[2], 'Geräte-ID: Formel entschärft');
        self::assertSame("'@SUM(A1)", $row[6], 'Notiz');
        self::assertSame('-5,5', $row[4], 'negative Zahl bleibt eine Zahl');
    }

    public function testClientChosenDeviceIdsMustBeSafe(): void
    {
        try {
            $this->meters->create('strom', ['name' => 'Garten', 'devices' => [['id' => 'd"><img src=x>', 'installed_on' => '2024-01-01']]]);
            self::fail('unsichere Geräte-ID angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.meter.idInvalid', $e->key);
        }
        $m = $this->meters->create('strom', ['name' => 'Garten', 'devices' => [['id' => 'd_garten-1', 'installed_on' => '2024-01-01']]]);
        self::assertSame('d_garten-1', $m['devices'][0]['id']);
    }

    public function testBackupImportRejectsOnlyObviouslyDangerousIds(): void
    {
        $backups = new BackupService($this->store, $this->i18n);
        $payload = fn(string $meterId, string $deviceId) => ['backup_version' => '3.0', 'utilities' => ['wasser' => [
            'meters' => [['id' => $meterId, 'name' => 'x', 'devices' => [['id' => $deviceId, 'installed_on' => '2024-01-01']]]],
            'readings' => [['id' => 'r1', 'meter_id' => $meterId, 'date' => '2024-02-01', 'counter' => 1]],
        ]]];
        $report = $backups->import($payload('Zähler Keller (alt)', 'gerät 1'), true);
        self::assertTrue($report['dry_run'], 'ungewöhnliche, aber harmlose Kennungen bleiben einspielbar');

        foreach ([['m"><img src=x>', 'd1', 'unsafe_id:id'], ['m1', "d\n1", 'unsafe_id:devices.id']] as [$meter, $device, $problem]) {
            try {
                $backups->import($payload($meter, $device), true);
                self::fail("angenommen: $problem");
            } catch (\Energietracker\Services\BackupInvalidException $e) {
                self::assertContains($problem, array_column($e->problems, 'problem'));
            }
        }
    }

    public function testSyncsFetchBeforeTheyTakeTheLock(): void
    {
        $log = [];
        $this->store->setWriteGuard(function () use (&$log): void { $log[] = 'lock'; });
        $smard = new MarketPriceService($this->store, $this->i18n, function (string $url) use (&$log): string {
            $log[] = 'fetch';
            if (str_ends_with($url, 'index_month.json')) return json_encode(['timestamps' => [1704063600000]]);
            return json_encode(['series' => [[1704063600000, 95.0]]]);
        });
        $smard->syncSmard();
        self::assertSame(['fetch', 'fetch'], array_slice($log, 0, 2), 'erst abrufen …');
        self::assertContains('lock', $log, '… dann sperren und schreiben');

        $log = [];
        $weather = new class($log) implements WeatherSource {
            public function __construct(private array &$log) {}
            public function fetchArchive(float $lat, float $lon, string $start, string $end): array
            { $this->log[] = 'fetch'; return ['data' => [$end => ['avg' => 5.0, 'min' => 1.0, 'max' => 9.0]], 'error' => null]; }
            public function fetchForecast(float $lat, float $lon, int $forecastDays = 14, int $pastDays = 0): array
            { $this->log[] = 'fetch'; return ['data' => [date('Y-m-d', strtotime('+1 day')) => ['avg' => 3.0, 'min' => 0.0, 'max' => 6.0]], 'error' => null]; }
            public function fetchArchiveMeans(float $lat, float $lon, string $start, string $end): array
            { $this->log[] = 'fetch'; return ['data' => [], 'error' => 'offline']; }
            public function geocode(string $query, string $language = 'de', int $count = 5): array { return ['data' => [], 'error' => null]; }
        };
        $climate = new \Energietracker\Services\ClimateNormalService($this->store, $this->settings);
        (new TemperatureService($this->store, $this->settings, $weather, $climate))->syncOpenMeteo(date('Y-m-d', strtotime('-10 days')));
        $firstLock = array_search('lock', $log, true);
        self::assertNotFalse($firstLock);
        self::assertNotContains('fetch', array_slice($log, (int)$firstLock), 'Wetter: kein Abruf mehr, sobald gesperrt ist');
        self::assertGreaterThanOrEqual(2, $firstLock, 'Archiv und Vorhersage vor der Sperre');
    }

    /** Welche Routen erst nach dem Abruf sperren — und dass sonst jede Schreibroute vorab sperrt. */
    public function testSyncRoutesLockLate(): void
    {
        $req = function (string $method, string $path): \Energietracker\Http\Request {
            $_SERVER['REQUEST_METHOD'] = $method;
            $_SERVER['REQUEST_URI'] = $path;
            $_SERVER['SCRIPT_NAME'] = '';
            return new \Energietracker\Http\Request();
        };
        try {
            foreach (['/api/ocr/reading', '/api/temperatures/sync-open-meteo', '/api/market-prices/sync-smard',
                      '/api/market-prices/sync-smard-intervals', '/api/utility/strom/meters/m_strom_x/sync-evcc'] as $p) {
                self::assertTrue(\Energietracker\App::locksLate($req('POST', $p)), "$p sperrt erst nach dem Abruf");
            }
            foreach ([['POST', '/api/utility/strom/readings'], ['POST', '/api/market-prices/import-csv'], ['PATCH', '/api/settings'],
                      ['GET', '/api/ocr/reading']] as [$m, $p]) {
                self::assertFalse(\Energietracker\App::locksLate($req($m, $p)), "$m $p sperrt vorab");
            }
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME']);
        }
    }

    /** Jeder Dienst mit eigener Verbindung nutzt die gemeinsamen Grenzen — öffentliche nur mit HTTPS. */
    public function testServicesUseTheOutboundLimits(): void
    {
        $src = fn(string $f) => (string)file_get_contents(dirname(__DIR__, 3) . "/src/Services/$f");
        foreach (['WeatherService.php', 'MarketPriceService.php'] as $f) {
            self::assertStringContainsString('Outbound::curlOptions(true, true, $body)', $src($f), "$f: nur HTTPS, höchstens zwei Weiterleitungen");
        }
        foreach (['OcrService.php', 'EvccService.php'] as $f) {
            self::assertStringContainsString('Outbound::curlOptions(false, false, $body)', $src($f), "$f: Heimnetz, ohne Weiterleitung");
        }
        self::assertStringNotContainsString('CURLOPT_FOLLOWLOCATION => true', $src('WeatherService.php'));
    }

    public function testOutboundConnectionsAreLimited(): void
    {
        $body = '';
        $public = Outbound::curlOptions(true, true, $body);
        self::assertSame(CURLPROTO_HTTPS, $public[CURLOPT_PROTOCOLS], 'öffentliche Dienste: nur HTTPS');
        self::assertSame(CURLPROTO_HTTPS, $public[CURLOPT_REDIR_PROTOCOLS], 'auch nach einer Weiterleitung');
        self::assertSame(2, $public[CURLOPT_MAXREDIRS]);
        $lan = Outbound::curlOptions(false, false, $body);
        self::assertFalse($lan[CURLOPT_FOLLOWLOCATION], 'im Heimnetz keine Weiterleitungen');
        self::assertSame(Outbound::MAX_BYTES, $lan[CURLOPT_MAXFILESIZE]);

        if (!function_exists('curl_init')) self::markTestSkipped('ohne curl');
        // Echte Verbindung: ein Server im Test liefert zu viel bzw. leitet weiter
        $dir = $this->dataDir . '/srv';
        mkdir($dir);
        file_put_contents("$dir/router.php", '<?php if (str_starts_with($_SERVER["REQUEST_URI"], "/redirect")) { header("Location: /big"); http_response_code(302); return; }'
            . ' header("Content-Type: application/json"); $chunk = str_repeat("x", 1048576); for ($i = 0; $i < 22; $i++) { echo $chunk; flush(); }');
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) usleep(50_000);
            $get = new \ReflectionMethod(EvccService::class, 'curlGet');
            $big = $get->invoke(null, "http://127.0.0.1:$port/big", 20, null);
            self::assertFalse($big['ok'], 'mehr als 20 MB: abgebrochen');
            $redirect = $get->invoke(null, "http://127.0.0.1:$port/redirect", 20, null);
            self::assertSame(302, $redirect['http_code'], 'Weiterleitung wird nicht verfolgt');
            self::assertFalse($redirect['ok']);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    public function testJsonStoreRejectsPathTricksLexically(): void
    {
        $outside = 'outside-' . bin2hex(random_bytes(4)) . '.json';
        try {
            foreach (["../$outside", "gas/../../$outside", '/etc/hosts', "gas/meters.json\0.txt", 'gas\\..\\x.json', './gas/meters.json', ''] as $bad) {
                try {
                    $this->store->write($bad, ['x' => 1]);
                    self::fail('angenommen: ' . json_encode($bad));
                } catch (\InvalidArgumentException) {
                    self::assertFileDoesNotExist(dirname($this->dataDir) . "/$outside");
                }
            }
        } finally {
            @unlink(dirname($this->dataDir) . "/$outside");
        }
        $this->store->write('neu/datei.json', ['ok' => true]);
        self::assertSame(['ok' => true], $this->store->read('neu/datei.json'));
    }
}
