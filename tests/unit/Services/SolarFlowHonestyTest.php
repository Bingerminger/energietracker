<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\PvSummaryService;
use Energietracker\Services\ReportModelService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Paket auswertung, Befunde V4 und V5 des Skeptiker-Laufs) — der
 * Sonnenstrom sagt, was gemessen ist.
 *
 * V4: Die gemessene Einspeisung wurde im Energiefluss an der (verteilten)
 * Erzeugung gekappt: Im Juni 196 statt 172 kWh, im Jahr 32 kWh weniger, als
 * die Kachel daneben zeigte. Ein Monat, den die Erzeugung nur bis zum 15.
 * erfasste, stand mit „0 % selbst genutzt“ im Bild.
 *
 * V5: Der Jahresbericht verglich ein Teiljahr mit einem ganzen Jahr, mischte
 * acht Monate Erzeugung mit zwölf Monaten Einspeisung und nannte einen
 * Eigenverbrauch aus verteilter Erzeugung „gemessen“.
 *
 * Alle Erwartungen von Hand gerechnet (Kommentare). Haushalt: Erzeugung
 * 10 kWh je Tag aus zwei Ablesungen (verteilt), Einspeisung und Netzbezug
 * monatlich am Ersten abgelesen.
 */
#[CoversClass(PvSummaryService::class)]
#[CoversClass(ReportModelService::class)]
final class SolarFlowHonestyTest extends ServiceTestCase
{
    use ReportFixture;

    /** Einspeisung je Monat 2024 (kWh): Juni und Juli über der verteilten Erzeugung (300 bzw. 310). */
    private const FEED_2024 = [50, 80, 150, 220, 280, 320, 330, 260, 180, 110, 60, 40];

    /** @param list<float> $perMonth Menge je Monat ab $from (Ablesung jeweils am Ersten) */
    private function monthlyReadings(string $utility, string $meterId, string $deviceId, string $from, array $perMonth): void
    {
        $rows = [['date' => $from, 'counter' => 0.0, 'device_id' => $deviceId]];
        $c = 0.0; $d = new \DateTimeImmutable($from);
        foreach ($perMonth as $v) {
            $c += $v; $d = $d->modify('+1 month');
            $rows[] = ['date' => $d->format('Y-m-d'), 'counter' => $c, 'device_id' => $deviceId];
        }
        $this->setReadings($utility, $meterId, $rows);
    }

    /** Erzeugung aus zwei Ablesungen (10 kWh/Tag), Einspeisung und Bezug monatlich. */
    private function household(string $genUntil = '2025-01-01'): void
    {
        $gen = $this->meters->create('pv_erzeugung', ['name' => 'Wechselrichter', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0]);
        $days = (int)(new \DateTimeImmutable('2024-01-01'))->diff(new \DateTimeImmutable($genUntil))->days;
        $this->setReadings('pv_erzeugung', $gen['id'], [
            ['date' => '2024-01-01', 'counter' => 0.0, 'device_id' => $gen['devices'][0]['id']],
            ['date' => $genUntil, 'counter' => 10.0 * $days, 'device_id' => $gen['devices'][0]['id']],
        ]);
        $feed = $this->meters->create('pv_einspeisung', ['name' => 'Einspeisezähler', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0]);
        $this->monthlyReadings('pv_einspeisung', $feed['id'], $feed['devices'][0]['id'], '2024-01-01', array_map('floatval', self::FEED_2024));
        $strom = $this->setMeterDevices('strom', [['id' => 'd_strom_1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->monthlyReadings('strom', $strom, 'd_strom_1', '2024-01-01', array_fill(0, 12, 200.0));
        $this->settings->set(['active_utilities' => ['strom', 'pv_einspeisung', 'pv_erzeugung']]);
    }

    private function pv(): array
    {
        return (new PvSummaryService($this->consumption, $this->settings))->compute();
    }

    // ── V4: Einspeisung nie kappen ───────────────────────────────────────

    public function testTheMeasuredFeedInIsNeverCappedAtTheGeneration(): void
    {
        $this->household();
        $pv = $this->pv();
        $by = array_column($pv['monthly'], null, 'ym');
        // Juni: Erzeugung 30 × 10 = 300 kWh (verteilt), Einspeisung 320 kWh (gemessen)
        self::assertEqualsWithDelta(300.0, $by['2024-06']['erzeugung_kwh'], 0.05);
        self::assertSame(320.0, $by['2024-06']['flow']['pv_grid'], 'gemessene Einspeisung, nicht 300');
        self::assertSame(0.0, $by['2024-06']['flow']['pv_house']);
        self::assertTrue($by['2024-06']['feed_above_generation']);
        self::assertTrue($by['2024-07']['feed_above_generation'], 'Juli: 330 > 310');
        self::assertFalse($by['2024-08']['feed_above_generation'], 'August: 260 < 310');
        // Jahr: Einspeisung im Bild = Summe der Monate = 2.080 kWh (bis v3.7: 2.040)
        $y = array_column($pv['yearly'], null, 'year')[2024];
        self::assertSame(2080.0, $y['einspeisung_kwh']);
        self::assertSame(2080.0, $y['flow']['pv_grid'], 'das Bild zeigt dieselbe Einspeisung wie die Kachel');
        // selbst genutzt: 260+210+160+80+30+0+0+50+120+200+240+270 = 1.620 kWh
        self::assertSame(1620.0, $y['flow']['pv_house']);
        self::assertSame(1620.0, $y['eigenverbrauch_kwh']);
        // Eigenverbrauchsquote gegen die Sonne des Bildes: 1.620 / (1.620 + 2.080) = 0,4378
        self::assertEqualsWithDelta(0.4378, $y['eigenverbrauchsquote'], 0.0001);
        // Autarkie: 1 − 2.400 / (1.620 + 2.400) = 0,4030
        self::assertEqualsWithDelta(0.4030, $y['autarkiequote'], 0.0001);
        self::assertSame(['2024-06', '2024-07'], $y['flow_period']['feed_above_generation']);
        self::assertSame(12, $y['flow_period']['interpolated_months']);
        self::assertSame(['erzeugung_kwh'], $y['flow_period']['interpolated_series']);
        // die verteilte Erzeugung der zwölf Monate: 366 × 10 = 3.660 kWh
        self::assertEqualsWithDelta(3660.0, $y['flow_period']['generation_kwh'], 0.5);
    }

    public function testTheFormulaKeepsAContradictoryFeedIn(): void
    {
        $f = PvSummaryService::flows(100.0, 150.0, 50.0);
        self::assertSame(150.0, $f['pv_grid']);
        self::assertSame(0.0, $f['pv_house']);
        self::assertSame(50.0, $f['house']);
    }

    public function testAMonthOnlyPartlyCoveredByOneMeterHasNoSelfConsumption(): void
    {
        // Erzeugung bis 16.08. abgelesen: August mit 15 Tagen, Einspeisung den ganzen Monat
        $this->household('2024-08-16');
        $by = array_column($this->pv()['monthly'], null, 'ym');
        self::assertEqualsWithDelta(150.0, $by['2024-08']['erzeugung_kwh'], 0.05, '15 Tage × 10 kWh');
        self::assertSame(['erzeugung_kwh'], $by['2024-08']['partial_series']);
        self::assertFalse($by['2024-08']['covered'], 'kein „0 % selbst genutzt“ aus einem halben Monat Sonne');
        self::assertNull($by['2024-08']['flow']);
        self::assertNull($by['2024-08']['autarkiequote']);
        self::assertTrue($by['2024-07']['covered']);
        $y = array_column($this->pv()['yearly'], null, 'year')[2024];
        self::assertSame(['from' => '2024-01', 'to' => '2024-07', 'months' => 7], array_intersect_key($y['flow_period'], ['from' => 1, 'to' => 1, 'months' => 1]));
        self::assertSame(['2024-08'], $y['flow_period']['partial']);
    }

    public function testAMeterStartingOnTheThirdStillCovers(): void
    {
        // 29 von 31 Tagen = 94 % ≥ 90 %: zählt; 27 von 31 = 87 %: zählt nicht
        self::assertSame([], PvSummaryService::partialSeries(['erzeugung_kwh' => 31, 'einspeisung_kwh' => 29, 'bezug_kwh' => 31]));
        self::assertSame(['einspeisung_kwh'], PvSummaryService::partialSeries(['erzeugung_kwh' => 31, 'einspeisung_kwh' => 27, 'bezug_kwh' => 31]));
    }

    // ── V5: Jahresbericht ────────────────────────────────────────────────

    private function report(int $year): array
    {
        return $this->reportServices()['model']->build($year, ['today' => '2026-10-26']);
    }

    private function messy(): void
    {
        $h = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/messy-household/household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings']);
        $this->store->write('temperatures.json', $h['temperatures']);
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        foreach ($h['utilities'] as $u => $pots) foreach ($pots as $pot => $rows) $this->store->write("$u/$pot.json", $rows);
    }

    public function testTheReportBlockUsesOnePeriodAndComparesNoPartYear(): void
    {
        $this->messy();
        $m = $this->report(2025);
        $pv = $m['topics']['pv'];
        // Erzeugung erst bis 15.08.2025 abgelesen: Energiefluss über Januar bis Juli
        $f = $pv['flow'];
        self::assertEqualsWithDelta($f['gen'], $f['direct'] + $f['feed'], 0.1, 'selbst genutzt + eingespeist = Sonne, ein Zeitraum');
        $year = array_column($this->pv()['yearly'], null, 'year')[2025];
        self::assertSame(7, $year['flow_period']['months']);
        self::assertEqualsWithDelta($year['flow']['grid_house'], $f['grid'], 0.1, 'Netzbezug aus denselben sieben Monaten wie das Bild');
        self::assertLessThan($year['bezug_kwh'] - 100, $f['grid'], 'nicht die zwölf Monate');
        // kein Vergleich mit dem ganzen Jahr 2024, eine Notiz sagt warum
        self::assertSame($this->i18n->t('report.tile.noPrevious', ['prev' => 2024]), $pv['tiles'][0]['sub']);
        self::assertContains($this->i18n->t('report.topic.pv.noCompare', ['prev' => 2024]), $pv['notes']);
        $tile = array_values(array_filter($m['headline']['tiles'], fn($x) => $x['key'] === 'pv'))[0] ?? null;
        self::assertNotNull($tile, 'der Haushalt hat einen Strompreis, also einen Nutzen');
        self::assertNull($tile['pct'], 'Kachel Nutzen ohne Prozent gegen ein ganzes Jahr');
        self::assertStringStartsWith('≈ ', $tile['value_text']);
        // geschätzt, widersprüchlich und der Zeitraum stehen da
        self::assertStringStartsWith('≈ ', $pv['tiles'][0]['text']);
        self::assertTrue($pv['estimated']);
        self::assertCount(4, $pv['notes'], 'Zeitraum, Schätzung, Widerspruch, kein Vergleich');
        // „(gemessen)“ nur aus gemessenen Monaten
        $benefit = array_values(array_filter($m['successes'], fn($x) => $x['rule'] === 'pvBenefit'))[0] ?? null;
        self::assertNotNull($benefit);
        self::assertSame('estimated', $benefit['euro']['basis']);
        self::assertSame($this->i18n->t('report.euro.basis.estimated'), $benefit['euro']['basis_text']);
    }

    public function testTwoWholeMeasuredYearsAreCompared(): void
    {
        // Erzeugung monatlich abgelesen: 2023 je Monat 300, 2024 je Monat 330 (+10 %)
        $gen = $this->meters->create('pv_erzeugung', ['name' => 'WR', 'installed_on' => '2023-01-01', 'initial_counter' => 0.0]);
        $this->monthlyReadings('pv_erzeugung', $gen['id'], $gen['devices'][0]['id'], '2023-01-01', array_merge(array_fill(0, 12, 300.0), array_fill(0, 12, 330.0)));
        $feed = $this->meters->create('pv_einspeisung', ['name' => 'E', 'installed_on' => '2023-01-01', 'initial_counter' => 0.0]);
        $this->monthlyReadings('pv_einspeisung', $feed['id'], $feed['devices'][0]['id'], '2023-01-01', array_fill(0, 24, 100.0));
        $strom = $this->setMeterDevices('strom', [['id' => 'd_s', 'serial' => null, 'installed_on' => '2023-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->monthlyReadings('strom', $strom, 'd_s', '2023-01-01', array_fill(0, 24, 200.0));
        $this->settings->set(['active_utilities' => ['strom', 'pv_einspeisung', 'pv_erzeugung']]);
        $pv = $this->report(2024)['topics']['pv'];
        // 12 × 330 = 3.960 gegen 12 × 300 = 3.600: +10 %
        self::assertSame('3.960 kWh', $pv['tiles'][0]['text'], 'gemessen, ohne ≈');
        self::assertStringContainsString('2023', $pv['tiles'][0]['sub']);
        self::assertNotSame($this->i18n->t('report.tile.noPrevious', ['prev' => 2023]), $pv['tiles'][0]['sub']);
        self::assertSame([], $pv['notes']);
        self::assertFalse($pv['estimated']);
    }
}
