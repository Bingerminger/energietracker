<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\PeriodService;
use Energietracker\Services\ReadingImportService;
use Energietracker\Services\ReferenceService;
use Energietracker\Services\SeriesImportService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H8) — Zeitreihen aus Portalen mit Spaltenzuordnung (MKT-19)
 * und Einordnung mit eigenen Vergleichswerten (MKT-11).
 */
#[CoversClass(SeriesImportService::class)]
#[CoversClass(ReferenceService::class)]
final class SeriesImportAndReferenceTest extends ServiceTestCase
{
    private function series(): SeriesImportService
    {
        $periods = new PeriodService($this->store, $this->meters, $this->i18n);
        return new SeriesImportService(new ReadingImportService($this->readings, $this->meters, $this->i18n), $this->readings,
            $this->meters, $periods, $this->settings, $this->i18n);
    }

    /** Viertelstunden in Wh, Stempel = Intervallbeginn in Ortszeit, über beide Zeitumstellungen 2025. */
    private function quarterHours(string $from, string $to, float $wh, bool $endStamp = false): array
    {
        // auf UTC-Zeitstempeln laufen: „+15 minutes" auf der Ortszeit überspringt die doppelte Stunde im Herbst
        $tz = new \DateTimeZone('Europe/Berlin');
        $ts = (new \DateTimeImmutable("$from 00:00", $tz))->getTimestamp();
        $stop = (new \DateTimeImmutable("$to 00:00", $tz))->getTimestamp();
        $lines = ['Zeitstempel;Wirkenergie Bezug (Wh)'];
        $n = 0;
        for (; $ts < $stop; $ts += 900) {
            $stamp = (new \DateTimeImmutable('@' . ($endStamp ? $ts + 900 : $ts)))->setTimezone($tz);
            $lines[] = $stamp->format('d.m.Y H:i') . ';' . number_format($wh, 1, ',', '');
            $n++;
        }
        return [implode("\n", $lines), $n];
    }

    public function testQuarterHoursAcrossDstBecomeDailyCounters(): void
    {
        $id = (string)$this->meters->defaultId('strom');
        [$csv, $n] = $this->quarterHours('2025-03-29', '2025-03-31', 250.0);
        self::assertSame(96 + 92, $n, '30.03.: 23 Stunden');
        $map = ['skip_rows' => 1, 'date_col' => 0, 'value_col' => 1, 'value_kind' => 'consumption', 'unit_factor' => '0,001', 'start_counter' => '1000'];
        $dry = $this->series()->import('strom', $id, $csv, $map, true);
        self::assertSame(['2025-03-29', '2025-03-30'], [$dry['from'], $dry['to']]);
        self::assertSame([24.0, 23.0], array_column($dry['preview'], 'value'));
        self::assertEqualsWithDelta($n * 0.25, $dry['total'], 1e-6, 'Summe der Tage = Summe der Datei');
        self::assertSame([], $this->readings->list('strom', $id), 'Trockenlauf schreibt nichts');

        $this->series()->import('strom', $id, $csv, $map);
        $c = array_column($this->readings->list('strom', $id), 'counter', 'date');
        self::assertEquals(['2025-03-29' => 1000.0, '2025-03-30' => 1024.0, '2025-03-31' => 1047.0], $c);
        // erneut: überschreibt, keine Doppelten
        $this->series()->import('strom', $id, $csv, $map);
        self::assertCount(3, $this->readings->list('strom', $id));
    }

    public function testEndStampsAndCounterValues(): void
    {
        $id = (string)$this->meters->defaultId('strom');
        [$csv] = $this->quarterHours('2025-10-25', '2025-10-27', 100.0, true);
        $r = $this->series()->import('strom', $id, $csv, ['skip_rows' => 1, 'date_col' => 0, 'value_col' => 1,
            'value_kind' => 'consumption', 'unit_factor' => 0.001, 'interval_stamp' => 'end', 'start_counter' => 0], true);
        self::assertSame([9.6, 10.0], array_column($r['preview'], 'value'), '26.10.: 25 Stunden; 00:00 gehört zum Vortag');

        // Zählerstand: der letzte Wert des Tages
        $csv = "2025-01-01T08:00:00+01:00;100,5\n2025-01-01T23:45:00+01:00;101,5\n2025-01-02T12:00:00Z;104\n";
        $r = $this->series()->import('strom', $id, $csv, ['date_col' => 0, 'value_col' => 1, 'value_kind' => 'counter']);
        self::assertSame(2, $r['readings']);
        self::assertEquals(['2025-01-01' => 101.5, '2025-01-02' => 104.0], array_column($this->readings->list('strom', $id), 'counter', 'date'));
    }

    public function testConsumptionNeedsAnAnchorAndPeriodMetersGetDays(): void
    {
        $id = (string)$this->meters->defaultId('strom');
        try {
            $this->series()->import('strom', $id, "2025-05-01;3\n2025-05-02;4\n", ['date_col' => 0, 'value_col' => 1, 'value_kind' => 'consumption']);
            self::fail('ohne Anker angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.import.anchorMissing', $e->key);
        }
        $this->readings->create('strom', ['meter_id' => $id, 'date' => '2025-05-01', 'counter' => 50]);
        $this->series()->import('strom', $id, "2025-05-01;3\n2025-05-02;4\n", ['date_col' => 0, 'value_col' => 1, 'value_kind' => 'consumption']);
        self::assertEquals(['2025-05-01' => 50.0, '2025-05-02' => 53.0, '2025-05-03' => 57.0], array_column($this->readings->list('strom', $id), 'counter', 'date'));

        $pm = (string)$this->meters->create('waerme', ['name' => 'UVI', 'installed_on' => '2025-01-01', 'capture' => 'period'])['id'];
        $r = $this->series()->import('waerme', $pm, "Datum;kWh\n01.02.2025;20\n02.02.2025;22\n", ['skip_rows' => 1, 'date_col' => 0, 'value_col' => 1, 'value_kind' => 'consumption']);
        self::assertSame('periods', $r['target']);
        self::assertCount(2, (new PeriodService($this->store, $this->meters, $this->i18n))->list('waerme', $pm));
        $this->expectException(LocalizedException::class);
        $this->series()->import('strom', $id, "x", ['date_col' => 0]);
    }

    /** Akzeptanz: 35.040 Viertelstunden in unter 5 s zu 365 Tagen, Jahressumme = Dateisumme. */
    public function testAYearOfQuarterHoursIsFast(): void
    {
        $id = (string)$this->meters->defaultId('strom');
        [$csv, $n] = $this->quarterHours('2025-01-01', '2026-01-01', 125.0);
        self::assertSame(35040, $n);
        $t0 = microtime(true);
        $r = $this->series()->import('strom', $id, $csv, ['skip_rows' => 1, 'date_col' => 0, 'value_col' => 1,
            'value_kind' => 'consumption', 'unit_factor' => 0.001, 'start_counter' => 0], true);
        self::assertLessThan(5.0, microtime(true) - $t0);
        self::assertSame(365, $r['days']);
        self::assertEqualsWithDelta(4380.0, $r['total'], 1e-6);
    }

    /** MKT-11: eigene Vergleichswerte, Wärmepumpe und Wallbox ausgeklammert, nur volle Jahre, Links nur für DE. */
    public function testOwnReferenceValues(): void
    {
        $house = (string)$this->meters->defaultId('strom');
        $wp = (string)$this->meters->create('strom', ['name' => 'WP', 'installed_on' => '2024-01-01', 'role' => 'heat_pump'])['id'];
        $set = function (string $m, float $perMonth): void {
            $dev = $this->meters->get('strom', $m)['devices'][0]['id'];
            $all = array_values(array_filter((array)$this->store->read('strom/readings.json', []), fn($r) => $r['meter_id'] !== $m));
            for ($i = 0; $i <= 12; $i++) $all[] = ['id' => "r_{$m}_$i", 'meter_id' => $m, 'device_id' => $dev, 'date' => date('Y-m-d', (int)strtotime("2025-01-01 +$i months")),
                'counter' => $perMonth * $i, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            $this->store->write('strom/readings.json', $all);
        };
        $set($house, 250); $set($wp, 300);
        $svc = new ReferenceService($this->consumption, $this->meters, $this->settings);
        $r = $svc->compare(2025);
        self::assertEqualsWithDelta(3000, $r['strom']['kwh'], 1, 'ohne den Wärmepumpenstrom');
        self::assertTrue($r['strom']['special_household']);
        self::assertNull($r['strom']['delta_pct'], 'ohne eigenen Vergleichswert keine Einordnung');
        self::assertSame(ReferenceService::LINKS, $r['links']);
        $this->settings->set(['reference_strom_kwh' => 2500, 'reference_source' => '  Stromspiegel, eigene Angabe  ']);
        $r = $svc->compare(2025);
        self::assertSame(20.0, $r['strom']['delta_pct']);
        self::assertSame('Stromspiegel, eigene Angabe', $r['source']);
        self::assertNull($svc->compare(2026)['strom']['delta_pct'] ?? null, 'unvollständiges Jahr');
        $this->settings->set(['country' => 'AT']);
        self::assertSame([], $svc->compare(2025)['links']);
    }
}
