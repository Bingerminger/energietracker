<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\EvccService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.2.0 (F1022) — Ladevorgänge aus evcc: CSV-Export in der Sprache des
 * Downloads und Abruf im Heimnetz. Transport und Namensauflösung sind
 * ersetzt; der Test geht nie ins Netz.
 */
#[CoversClass(EvccService::class)]
final class EvccServiceTest extends ServiceTestCase
{
    private string $wallbox;
    /** @var list<array{url:string, ip:?string}> */
    private array $calls = [];

    /** evcc-Export, deutsch, mit BOM und Zählerständen der Wallbox */
    private const CSV_DE = "\u{FEFF}Startzeit,Endzeit,Ladepunkt,Kennung,Fahrzeug,Kilometerstand (km),Anfangszählerstand (kWh),Endzählerstand (kWh),Energie (kWh),Ladedauer,Sonne (%),Kosten,Preis/kWh,CO₂/kWh (gCO₂eq)\n"
        . "2026-03-02 18:10:00,2026-03-02 21:40:00,Garage,,Auto,,1000.000,1012.500,12.500,3h30m0s,40.0,3.10,0.248,\n"
        . "2026-03-03 08:00:00,2026-03-03 09:00:00,Garage,,Auto,,1012.500,1018.000,5.500,1h0m0s,100.0,0.00,0.000,\n"
        . "2026-03-03 19:00:00,2026-03-03 22:00:00,Garage,,Auto,,1018.000,1030.000,12.000,3h0m0s,0.0,3.60,0.300,\n";

    /** englischer Export ohne Zählerstände (Wallbox ohne eigenen Zähler) */
    private const CSV_EN = "Created,Finished,Charging point,Identifier,Vehicle,Odometer (km),Meter start (kWh),Meter stop (kWh),Energy (kWh),Charge duration,Solar (%),Cost,Price/kWh\n"
        . "2026-04-01 10:00:00,2026-04-01 12:00:00,Carport,,Car,,,,10.0,2h0m0s,50.0,1.50,0.150\n"
        . "2026-04-05 10:00:00,2026-04-05 12:00:00,Carport,,Car,,,,6.0,2h0m0s,0.0,1.80,0.300\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallbox = (string)$this->meters->create('strom', ['name' => 'Wallbox', 'installed_on' => '2026-01-01',
            'parent_meter_id' => (string)$this->meters->defaultId('strom'), 'role' => 'ev_charger'])['id'];
    }

    private function evcc(array $answer = ['ok' => true, 'body' => '[]', 'http_code' => 200, 'error_code' => null], array $dns = []): EvccService
    {
        $transport = function (string $url, int $timeout, ?string $ip) use ($answer): array {
            $this->calls[] = ['url' => $url, 'ip' => $ip];
            return $answer;
        };
        return new EvccService($this->store, $this->meters, $this->readings,
            new ReadingImportService($this->readings, $this->meters, $this->i18n), $this->settings,
            $transport, fn(string $host): array => $dns[$host] ?? []);
    }

    private function counters(): array
    {
        $out = [];
        foreach ($this->readings->list('strom', $this->wallbox) as $r) if (empty($r['is_future'])) $out[$r['date']] = (float)$r['counter'];
        ksort($out);
        return $out;
    }

    private function key(callable $fn): string
    {
        try {
            $fn();
        } catch (LocalizedException $e) {
            return $e->key;
        }
        self::fail('LocalizedException erwartet');
    }

    /**
     * Zählerstände der Wallbox: Ein Stand am Tag D ist der Stand zu Beginn von D —
     * also der Anfangsstand am Tag des Beginns, der Endstand am Tag danach. So
     * gehört jede Ladung zu ihrem Tag (und Monat).
     */
    public function testTheGermanExportBringsSessionsAndTheChargersMeter(): void
    {
        $r = $this->evcc()->importCsv($this->wallbox, self::CSV_DE);
        self::assertSame(3, $r['sessions']);
        self::assertSame('meter', $r['counter_source']);
        self::assertEqualsWithDelta(30.0, $r['charged_kwh'], 0.001);
        self::assertEqualsWithDelta(12.5 * 0.4 + 5.5, $r['solar_kwh'], 0.001);
        self::assertSame(['Garage'], $r['loadpoints']);
        $c = $this->counters();
        self::assertEqualsWithDelta(1000.0, $c['2026-03-02'], 0.001, 'Beginn: Anfangsstand');
        self::assertEqualsWithDelta(1012.5, $c['2026-03-03'], 0.001, 'Folgetag: Endstand von Tag 1');
        self::assertEqualsWithDelta(1030.0, $c['2026-03-04'], 0.001, 'Folgetag: höchster Endstand von Tag 2');
        // Tag 2 hat 17,5 kWh: 5,5 + 12,0
        self::assertEqualsWithDelta(17.5, $c['2026-03-04'] - $c['2026-03-03'], 0.001);

        $s = $this->evcc()->sessions($this->wallbox, 2026);
        self::assertCount(3, $s);
        self::assertSame('2026-03-03T19:00:00+01:00', $s[0]['created'], 'neueste zuerst, Zeit in der Zeitzone der Installation');
        self::assertSame(3.6, $s[0]['price_eur']);
    }

    /** Ohne Zählerstände: aufsummierte Energie ab dem letzten Stand davor. */
    public function testWithoutMeterValuesTheEnergyIsAddedUpFromTheLastReading(): void
    {
        $dev = $this->meters->get('strom', $this->wallbox)['devices'][0]['id'];
        $this->readings->create('strom', ['meter_id' => $this->wallbox, 'device_id' => $dev, 'date' => '2026-03-31', 'counter' => 200]);
        $r = $this->evcc()->importCsv($this->wallbox, self::CSV_EN);
        self::assertSame('energy', $r['counter_source']);
        $c = $this->counters();
        self::assertEqualsWithDelta(210.0, $c['2026-04-02'], 0.001, 'Endstand am Tag nach der Ladung');
        self::assertEqualsWithDelta(216.0, $c['2026-04-06'], 0.001);
        self::assertArrayNotHasKey('2026-04-01', $c);
    }

    /** Zwei Ladepunkte an einem Zähler: Stände nur mit Wahl des Ladepunkts, Vorgänge immer. */
    public function testSeveralLoadpointsNeedAChoiceForTheReadings(): void
    {
        $two = self::CSV_EN . "2026-04-06 10:00:00,2026-04-06 11:00:00,Street,,Car,,,,4.0,1h0m0s,0.0,1.20,0.300\n";
        self::assertSame('errors.evcc.loadpointNeeded', $this->key(fn() => $this->evcc()->importCsv($this->wallbox, $two)));
        self::assertSame(3, $this->evcc()->importCsv($this->wallbox, $two, ['counters' => 'none'])['sessions']);
        self::assertSame(2, $this->evcc()->importCsv($this->wallbox, $two, ['loadpoint' => 'Carport', 'dry_run' => true])['readings']);
    }

    public function testRepeatingTheImportChangesNothing(): void
    {
        $this->evcc()->importCsv($this->wallbox, self::CSV_EN);
        $before = [$this->counters(), $this->evcc()->sessions()];
        $r = $this->evcc()->importCsv($this->wallbox, self::CSV_EN);
        self::assertSame(2, $r['replaces'], 'dieselben Tage — ersetzt, nicht verdoppelt');
        self::assertSame($before, [$this->counters(), $this->evcc()->sessions()]);
    }

    public function testDryRunWritesNothingAndOptionsSteerTheReadings(): void
    {
        $r = $this->evcc()->importCsv($this->wallbox, self::CSV_DE, ['dry_run' => true]);
        self::assertTrue($r['dry_run']);
        self::assertSame(3, $r['readings']);
        self::assertSame([], $this->evcc()->sessions());
        self::assertSame([], array_filter($this->counters(), fn($v, $d) => $d >= '2026-03-01', ARRAY_FILTER_USE_BOTH));

        // Zähler im Energietracker ist ein anderer als der in der Wallbox → aufsummieren
        $e = $this->evcc()->importCsv($this->wallbox, self::CSV_DE, ['dry_run' => true, 'counters' => 'energy']);
        self::assertSame('energy', $e['counter_source']);
        // nur die Vorgänge
        $n = $this->evcc()->importCsv($this->wallbox, self::CSV_DE, ['counters' => 'none']);
        self::assertSame(0, $n['readings']);
        self::assertNull($n['import']);
        self::assertCount(3, $this->evcc()->sessions($this->wallbox));
        self::assertSame('errors.evcc.countersInvalid', $this->key(fn() => $this->evcc()->importCsv($this->wallbox, self::CSV_DE, ['counters' => 'x'])));
    }

    public function testOnlyOneLoadpointWhenAsked(): void
    {
        $two = self::CSV_EN . "2026-04-06 10:00:00,2026-04-06 11:00:00,Street,,Car,,,,4.0,1h0m0s,0.0,1.20,0.300\n";
        $r = $this->evcc()->importCsv($this->wallbox, $two, ['loadpoint' => 'Street']);
        self::assertSame(1, $r['sessions']);
        self::assertSame(['Carport', 'Street'], $r['loadpoints']);
    }

    public function testMonthlySumsWithTheSolarShare(): void
    {
        $this->evcc()->importCsv($this->wallbox, self::CSV_DE);
        $m = $this->evcc()->monthly($this->wallbox, 2026)['2026-03'];
        self::assertEqualsWithDelta(30.0, $m['kwh'], 0.001);
        self::assertEqualsWithDelta(10.5, $m['solar_kwh'], 0.001);
        self::assertEqualsWithDelta(35.0, $m['solar_pct'], 0.01);
        self::assertEqualsWithDelta(6.7, $m['price_eur'], 0.001);
        self::assertSame(3, $m['sessions']);
    }

    public function testAFileWithoutStartOrEnergyIsRejected(): void
    {
        self::assertSame('errors.evcc.columns', $this->key(fn() => $this->evcc()->importCsv($this->wallbox, "Datum;Stand\n2026-01-01;5\n")));
        self::assertSame('errors.evcc.noSessions', $this->key(fn() => $this->evcc()->importCsv($this->wallbox, "Created,Energy (kWh)\nkaputt,x\n")));
        self::assertSame('errors.common.meterNotFound', $this->key(fn() => $this->evcc()->importCsv('m_gibts_nicht', self::CSV_DE)));
    }

    public function testOffMeansNoConnectionAndOnlyTheHomeNetworkCounts(): void
    {
        self::assertSame('errors.evcc.off', $this->key(fn() => $this->evcc()->sync($this->wallbox)));
        $this->settings->set(['evcc_endpoint' => 'http://8.8.8.8:7070']);
        self::assertSame('errors.evcc.notLocal', $this->key(fn() => $this->evcc()->sync($this->wallbox)));
        // ein Name, der (auch) öffentlich auflöst — DNS-Rebinding
        $this->settings->set(['evcc_endpoint' => 'http://evcc.example:7070']);
        self::assertSame('errors.evcc.notLocal',
            $this->key(fn() => $this->evcc(dns: ['evcc.example' => ['192.168.178.30', '93.184.216.34']])->sync($this->wallbox)));
        self::assertSame([], $this->calls, 'keine Verbindung');
    }

    /** Abruf über die geprüfte Adresse; ältere evcc hüllen die Liste in {result}; ein laufender Vorgang wartet. */
    public function testTheSyncReadsTheSessionsApi(): void
    {
        $this->settings->set(['evcc_endpoint' => 'http://evcc.local:7070/']);
        $body = json_encode(['result' => [
            ['created' => '2026-05-01T08:00:00+02:00', 'finished' => '2026-05-01T10:00:00+02:00', 'loadpoint' => 'Garage', 'vehicle' => 'Auto',
             'meterStart' => 2000.0, 'meterStop' => 2008.0, 'chargedEnergy' => 8.0, 'solarPercentage' => 75.0, 'price' => 0.5, 'pricePerKWh' => 0.0625],
            ['created' => '2026-05-02T08:00:00+02:00', 'finished' => '0001-01-01T00:00:00Z', 'loadpoint' => 'Garage', 'chargedEnergy' => 1.2],
        ]]);
        $evcc = $this->evcc(['ok' => true, 'body' => $body, 'http_code' => 200, 'error_code' => null], ['evcc.local' => ['192.168.178.30']]);
        $r = $evcc->sync($this->wallbox);
        self::assertSame([['url' => 'http://evcc.local:7070/api/sessions', 'ip' => '192.168.178.30']], $this->calls);
        self::assertSame(1, $r['sessions']);
        self::assertSame(1, $r['running']);
        self::assertEqualsWithDelta(2000.0, $this->counters()['2026-05-01'], 0.001);
        self::assertEqualsWithDelta(2008.0, $this->counters()['2026-05-02'], 0.001);
        self::assertSame('evcc', $evcc->sessions()[0]['source']);

        $down = $this->evcc(['ok' => false, 'body' => null, 'http_code' => null, 'error_code' => 'timeout'], ['evcc.local' => ['192.168.178.30']]);
        self::assertSame('errors.evcc.unreachable', $this->key(fn() => $down->sync($this->wallbox)));
        $odd = $this->evcc(['ok' => true, 'body' => '"nein"', 'http_code' => 200, 'error_code' => null], ['evcc.local' => ['192.168.178.30']]);
        self::assertSame('errors.evcc.badAnswer', $this->key(fn() => $odd->sync($this->wallbox)));
    }

    /** Neuer Datentopf → im Backup (BackupService::TOP_POTS) und zurück. */
    public function testTheSessionsTravelWithTheBackup(): void
    {
        $this->evcc()->importCsv($this->wallbox, self::CSV_DE);
        $backups = new BackupService($this->store, $this->i18n);
        $backup = $backups->export();
        self::assertCount(3, $backup['ev_sessions']);
        $this->store->write(EvccService::FILE, []);
        $backups->import($backup);
        self::assertCount(3, $this->evcc()->sessions());
    }

    public function testTheEndpointSettingIsValidated(): void
    {
        $this->settings->set(['evcc_endpoint' => '  http://192.168.178.30:7070  ']);
        self::assertSame('http://192.168.178.30:7070', $this->settings->get('evcc_endpoint'));
        $this->expectException(\Throwable::class);
        $this->settings->set(['evcc_endpoint' => 'ftp://evcc.local']);
    }
}
