<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BackupService;
use Energietracker\Services\IngestService;
use Energietracker\Services\IntervalService;
use Energietracker\Services\MarketPriceService;
use Energietracker\Services\PeriodService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\SeriesImportService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.3.0 (F1021) — Werte je Viertelstunde: Ablage, Quellen, Backup.
 */
#[CoversClass(IntervalService::class)]
final class IntervalServiceTest extends ServiceTestCase
{
    private IntervalService $iv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iv = new IntervalService($this->store, $this->settings);
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Berlin')))->getTimestamp();
    }

    private function slotOf(string $local): int
    {
        return intdiv($this->at($local) - $this->iv->yearStart((int)substr($local, 0, 4)), 900);
    }

    /** Schema 1.7.0 → 1.8.0: ein leerer Index, sonst nichts. */
    public function testTheMigrationCreatesTheIndex(): void
    {
        $this->store->delete(IntervalService::INDEX);
        $this->store->write('meta.json', ['schema_version' => '1.7.0']);
        $m = new \Energietracker\Storage\Migrator($this->store);
        self::assertTrue($m->needsV180Upgrade());
        $log = $m->migrate();
        self::assertContains('v1.8.0: angelegt: intervals.json', $log);
        self::assertSame(['series' => [], 'samples' => []], $this->store->read(IntervalService::INDEX, []));
        self::assertSame('1.8.0', $this->store->read('meta.json', [])['schema_version']);
        self::assertFalse($m->needsV180Upgrade());
    }

    public function testAYearHasAPlaceForEveryQuarterHour(): void
    {
        self::assertSame(35040, $this->iv->slots(2025));
        self::assertSame(35136, $this->iv->slots(2024), 'Schaltjahr');
        $clock = $this->iv->localClock(2025);
        $perMonth = array_count_values($clock['month']);
        self::assertSame(31 * 96 - 4, $perMonth[3], 'März: eine Stunde fehlt (Sommerzeit)');
        self::assertSame(31 * 96 + 4, $perMonth[10], 'Oktober: eine Stunde doppelt');
        self::assertSame(180, $clock['minute'][$this->slotOf('2025-03-30 03:00')], 'nach der Umstellung 03:00 Ortszeit');
    }

    public function testMergedValuesLandInTheirQuarterHour(): void
    {
        $r = $this->iv->mergeEnergy('strom', 'm_x', [$this->at('2025-06-01 12:15') => 0.25, $this->at('2026-01-01 00:00') => 0.5], 'test');
        self::assertSame(['series' => 2, 'slots' => 2], $r, 'über den Jahreswechsel: zwei Reihen');
        self::assertSame(0.25, $this->iv->energy('strom', 'm_x', 2025)[$this->slotOf('2025-06-01 12:15')]);
        self::assertSame(0.5, $this->iv->energy('strom', 'm_x', 2026)[0]);
        $e = array_values(array_filter($this->iv->list(), fn($s) => $s['year'] === 2025))[0];
        self::assertSame([1, 'kWh', 'energy'], [$e['filled'], $e['unit'], $e['kind']]);
        self::assertStringStartsWith('2025-06-01T12:15', $e['from']);
    }

    /** Stände um 10:07, 10:22, 10:37: die Differenzen verteilen sich nach Minuten. */
    public function testCounterSamplesSpreadOverTheQuarterHours(): void
    {
        self::assertSame(0, $this->iv->addCounterSample('strom', 'm_x', $this->at('2025-05-05 10:07'), 100.0), 'erster Stand: nur Anfang');
        $this->iv->addCounterSample('strom', 'm_x', $this->at('2025-05-05 10:22'), 101.5);
        $this->iv->addCounterSample('strom', 'm_x', $this->at('2025-05-05 10:37'), 103.0);
        $e = $this->iv->energy('strom', 'm_x', 2025);
        self::assertEqualsWithDelta(0.8, $e[$this->slotOf('2025-05-05 10:00')], 0.0001, '1,5 kWh × 8/15');
        self::assertEqualsWithDelta(1.5, $e[$this->slotOf('2025-05-05 10:15')], 0.0001, '0,7 + 0,8 aus zwei Abständen');
        self::assertEqualsWithDelta(0.7, $e[$this->slotOf('2025-05-05 10:30')], 0.0001);

        // doppelt, rückwärts, Lücke über sechs Stunden: nichts verteilt
        self::assertSame(0, $this->iv->addCounterSample('strom', 'm_x', $this->at('2025-05-05 10:37'), 103.0));
        self::assertSame(0, $this->iv->addCounterSample('strom', 'm_x', $this->at('2025-05-05 18:00'), 110.0));
        self::assertNull($this->iv->energy('strom', 'm_x', 2025)[$this->slotOf('2025-05-05 15:00')]);
    }

    public function testOnlySeriesInTheIndexCountAndCanBeDeleted(): void
    {
        $this->iv->mergePrices([$this->at('2025-01-01 00:00') => 9.5], 'test');
        self::assertSame(9.5, $this->iv->prices(2025)[0]);
        $this->store->write(IntervalService::DIR . '/p-de-lu-2024.json', ['values' => [1.0]]);
        self::assertNull($this->iv->prices(2024), 'Datei ohne Indexeintrag (Rest eines anderen Haushalts) zählt nicht');
        $this->iv->delete(IntervalService::priceId(2025));
        self::assertNull($this->iv->prices(2025));
        $this->expectException(LocalizedException::class);
        $this->iv->delete('p-de-lu-1999');
    }

    public function testTheBackupCarriesTheSeriesAndCleansUpOthers(): void
    {
        $this->iv->mergeEnergy('strom', 'm_x', [$this->at('2025-02-02 08:00') => 0.4], 'test');
        $backups = new BackupService($this->store, $this->i18n);
        $backup = $backups->export(false);
        self::assertArrayHasKey('e-strom-m_x-2025', $backup['interval_files'], 'auch ohne Belege dabei');
        $this->iv->delete('e-strom-m_x-2025');
        $backups->import($backup);
        self::assertSame(0.4, $this->iv->energy('strom', 'm_x', 2025)[$this->slotOf('2025-02-02 08:00')]);

        // ein Backup ohne die Reihe: sie fällt weg (kein Rest des vorigen Haushalts)
        $backup['intervals'] = ['series' => [], 'samples' => []];
        $backup['interval_files'] = [];
        $backups->import($backup);
        self::assertFalse($this->store->exists(IntervalService::DIR . '/e-strom-m_x-2025.json'));
        // unbekannte Reihe im Backup: abgelehnt
        $backup['interval_files'] = ['e-fremd-2025' => ['values' => []]];
        $this->expectException(\Throwable::class);
        $backups->import($backup);
    }

    /** Portaldatei in Viertelstunden: Tageswerte wie bisher, dazu jede Viertelstunde. */
    public function testThePortalImportKeepsTheQuarterHours(): void
    {
        $meter = (string)$this->meters->defaultId('strom');
        $this->setReadings('strom', $meter, [['date' => '2025-03-01', 'counter' => 1000, 'device_id' => $this->meters->get('strom', $meter)['devices'][0]['id']]]);
        $csv = "Zeit;kWh\n";
        for ($i = 0; $i < 96; $i++) $csv .= '01.03.2025 ' . sprintf('%02d:%02d', intdiv($i, 4), ($i % 4) * 15) . ';' . ($i < 48 ? '0,1' : '0,3') . "\n";
        $svc = new SeriesImportService(new ReadingImportService($this->readings, $this->meters, $this->i18n), $this->readings, $this->meters,
            new PeriodService($this->store, $this->meters, $this->i18n), $this->settings, $this->i18n, $this->iv);
        $r = $svc->import('strom', $meter, $csv, ['skip_rows' => 1, 'date_col' => 0, 'value_col' => 1, 'value_kind' => 'consumption']);
        self::assertSame(['step' => 900, 'slots' => 96], $r['intervals']);
        $e = $this->iv->energy('strom', $meter, 2025);
        self::assertEqualsWithDelta(19.2, array_sum(array_filter($e, fn($v) => $v !== null)), 0.001, '48 × 0,1 + 48 × 0,3');
        self::assertSame(0.3, $e[$this->slotOf('2025-03-01 12:00')]);
    }

    /** Portaldatei ohne Zeitzone am Tag der Umstellung auf Winterzeit: 100 Viertelstunden, keine überschrieben. */
    public function testThePortalImportKeepsTheRepeatedHourInAutumn(): void
    {
        $meter = (string)$this->meters->defaultId('strom');
        $this->setReadings('strom', $meter, [['date' => '2025-10-26', 'counter' => 1000, 'device_id' => $this->meters->get('strom', $meter)['devices'][0]['id']]]);
        $csv = "Zeit;kWh\n";
        $row = fn(int $h, int $q, string $v) => '26.10.2025 ' . sprintf('%02d:%02d', $h, $q * 15) . ";$v\n";
        for ($h = 0; $h < 2; $h++) for ($q = 0; $q < 4; $q++) $csv .= $row($h, $q, '0,1');
        for ($q = 0; $q < 4; $q++) $csv .= $row(2, $q, '0,2');   // 02:00–02:45 Sommerzeit
        for ($q = 0; $q < 4; $q++) $csv .= $row(2, $q, '0,4');   // 02:00–02:45 Winterzeit
        for ($h = 3; $h < 24; $h++) for ($q = 0; $q < 4; $q++) $csv .= $row($h, $q, '0,1');
        $svc = new SeriesImportService(new ReadingImportService($this->readings, $this->meters, $this->i18n), $this->readings, $this->meters,
            new PeriodService($this->store, $this->meters, $this->i18n), $this->settings, $this->i18n, $this->iv);
        $r = $svc->import('strom', $meter, $csv, ['skip_rows' => 1, 'date_col' => 0, 'value_col' => 1, 'value_kind' => 'consumption']);
        self::assertSame(['step' => 900, 'slots' => 100], $r['intervals']);
        $e = $this->iv->energy('strom', $meter, 2025);
        self::assertEqualsWithDelta(11.6, array_sum(array_filter($e, fn($v) => $v !== null)), 0.001, '92 × 0,1 + 4 × 0,2 + 4 × 0,4');
        $winter = $this->slotOf('2025-10-26 02:15');   // PHP liest die Ortszeit als Winterzeit
        self::assertSame([0.2, 0.4], [$e[$winter - 4], $e[$winter]]);
    }

    /** Home Assistant mit Zeitstempel: Tagesstand wie bisher, dazu Verbrauch je Viertelstunde. */
    public function testIngestWithTimestampFillsQuarterHours(): void
    {
        $ingest = new IngestService($this->meters, $this->readings, $this->i18n, $this->store, $this->iv);
        $meter = (string)$this->meters->defaultId('strom');
        $ingest->ingest(['utility' => 'strom', 'meter' => $meter, 'value' => 500.0, 'timestamp' => '2025-07-01T10:00:00+02:00']);
        $r = $ingest->ingest(['utility' => 'strom', 'meter' => $meter, 'value' => 500.5, 'timestamp' => '2025-07-01T08:15:00Z']);
        self::assertSame('2025-07-01', $r['date'], 'Tag des Zeitstempels in der Zeitzone der Installation');
        self::assertSame(1, $r['intervals']);
        self::assertSame(0.5, $this->iv->energy('strom', $meter, 2025)[$this->slotOf('2025-07-01 10:00')]);
        try {
            $ingest->ingest(['utility' => 'strom', 'meter' => $meter, 'value' => 501, 'timestamp' => 'gestern']);
            self::fail('ungültiger Zeitstempel');
        } catch (LocalizedException $e) {
            self::assertSame('errors.ingest.timestampFormat', $e->key);
        }
    }

    /** SMARD: €/MWh je Viertelstunde → ct/kWh; Werte des Vorjahres im ersten Wochenpaket bleiben draußen. */
    public function testSmardQuarterHoursForAYear(): void
    {
        $weekStart = $this->at('2024-12-30 00:00') * 1000;
        $fetch = function (string $url) use ($weekStart): string {
            if (str_ends_with($url, 'index_quarterhour.json')) return json_encode(['timestamps' => [$weekStart, $weekStart + 7 * 86400000]]);
            if (str_contains($url, (string)$weekStart)) {
                return json_encode(['series' => [[$this->at('2024-12-31 23:45') * 1000, 50.0], [$this->at('2025-01-01 00:00') * 1000, 100.0],
                                                  [$this->at('2025-01-01 00:15') * 1000, -20.0], [$this->at('2025-01-01 00:30') * 1000, null]]]);
            }
            return json_encode(['series' => []]);
        };
        $r = (new MarketPriceService($this->store, $this->i18n, $fetch, $this->iv))->syncSmardIntervals(2025);
        self::assertSame(2, $r['slots']);
        $p = $this->iv->prices(2025);
        self::assertSame([10.0, -2.0, null], [$p[0], $p[1], $p[2]]);
        self::assertNull($this->iv->prices(2024), 'Vorjahr nicht angelegt');
    }

    /** Ohne Viertelstundenpreise oder bei einem HTTP-Fehler: eigener Code — für Viertelstunden gibt es keinen Dateiimport als Ausweg. */
    public function testSmardFailureHasItsOwnCode(): void
    {
        $codes = [];
        foreach ([fn(string $url) => json_encode(['timestamps' => []]),
                  fn(string $url) => throw new LocalizedException('errors.marketPrices.syncFailed', [], 'smard http 503')] as $fetch) {
            try { (new MarketPriceService($this->store, $this->i18n, $fetch, $this->iv))->syncSmardIntervals(2025); }
            catch (LocalizedException $e) { $codes[] = $e->key; }
        }
        self::assertSame([MarketPriceService::QH_FAILED, MarketPriceService::QH_FAILED], $codes);
        self::assertNull($this->iv->prices(2025), 'nichts gespeichert');
    }
}
