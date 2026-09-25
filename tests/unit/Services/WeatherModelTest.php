<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AnomalyService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\ClimateNormalService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\RegressionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.8.0 — Paket C1 „Richtig rechnen": Wetter, Prognose, Saldo.
 *
 * Synthetische Daten mit bekannter Wahrheit: Monatsmittel der Temperatur je
 * Kalendermonat fest, Verbrauch = a × HGT + c × Tage (a = 0,3 kWh je
 * Gradtag, c = 5 kWh Grundlast je Tag — Warmwasser wie bei einer echten
 * Gasheizung), Umrechnungsfaktor 1. Jeder Test
 * baut, was er braucht; die Zahlen sind von Hand nachrechenbar.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ForecastService::class)]
#[CoversClass(AnomalyService::class)]
#[CoversClass(RegressionService::class)]
final class WeatherModelTest extends ServiceTestCase
{
    private const A = 0.3;
    private const C = 5.0;
    private const TEMP = [1 => 0.0, 2 => 1.0, 3 => 5.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
                          7 => 19.0, 8 => 18.0, 9 => 14.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store->write('settings.json', ['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 1.0]]]);
    }

    /** Tagestemperaturen von … bis (einschließlich), Monatsmittel aus TEMP. */
    private function seedTemps(string $from, string $to): void
    {
        $temps = $this->store->read('temperatures.json', []);
        for ($t = strtotime($from . ' 12:00'); date('Y-m-d', $t) <= $to; $t += 86400) {
            $avg = self::TEMP[(int)date('n', $t)];
            $temps[date('Y-m-d', $t)] = ['avg' => $avg, 'min' => $avg - 3, 'max' => $avg + 3];
        }
        $this->store->write('temperatures.json', $temps);
    }

    private function hddOf(string $ym): float
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        return (int)date('t', mktime(0, 0, 0, $m, 1, $y)) * max(0.0, 15.0 - self::TEMP[$m]);
    }

    /**
     * Monatliche Ablesungen am Ersten von $fromYm bis $toYm (letzte Ablesung
     * am Ersten nach $toYm), Verbrauch nach Modell. $extra: ym → Aufschlag.
     *
     * @return string Zähler-ID
     */
    private function seedMonthly(string $utility, string $fromYm, string $toYm, array $extra = [], ?callable $model = null): string
    {
        $meterId = $this->setMeterDevices($utility, [[
            'id' => 'd1', 'serial' => null, 'installed_on' => $fromYm . '-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $model ??= fn(string $ym) => self::A * $this->hddOf($ym) + self::C * (int)date('t', strtotime($ym . '-01'));
        $rows = [['date' => $fromYm . '-01', 'counter' => 0.0, 'device_id' => 'd1']];
        $counter = 0.0;
        for ($t = strtotime($fromYm . '-01'); date('Y-m', $t) <= $toYm; $t = strtotime('+1 month', $t)) {
            $ym = date('Y-m', $t);
            $counter += $model($ym) + ($extra[$ym] ?? 0.0);
            $rows[] = ['date' => date('Y-m-d', strtotime('+1 month', $t)), 'counter' => round($counter, 4), 'device_id' => 'd1'];
        }
        $this->setReadings($utility, $meterId, $rows);
        return $meterId;
    }

    private function monthly(string $utility): array
    {
        return $this->consumption->forMeter($utility, $this->meters->list($utility)[0]);
    }

    private function row(array $monthly, string $ym): array
    {
        foreach ($monthly as $m) if ($m['ym'] === $ym) return $m;
        self::fail("Monat $ym fehlt");
    }

    // ── Heizgradtage nur über Verbrauchstage (CALC-14) ──────────────────

    public function testDegreeDaysCountOnlyDaysWithConsumption(): void
    {
        $this->seedTemps('2025-01-01', '2025-01-31');
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('gas', $meterId, [
            ['date' => '2025-01-01', 'counter' => 0.0, 'device_id' => 'd1'],
            ['date' => '2025-01-15', 'counter' => 100.0, 'device_id' => 'd1'],
        ]);
        $jan = $this->row($this->monthly('gas'), '2025-01');
        self::assertSame(14, $jan['days']);
        self::assertEqualsWithDelta(14 * 15.0, $jan['hdd'], 0.01, '14 Tage × 15 HGT, nicht der ganze Monat (31 × 15)');
        self::assertSame(14, $jan['temp_days']);
    }

    // ── Eine Punktauswahl (CALC-14, FE-18) ──────────────────────────────

    public function testPartialMonthsAreNotRegressionPoints(): void
    {
        $this->seedTemps('2024-01-01', '2025-12-31');
        $meterId = $this->seedMonthly('gas', '2024-01', '2025-11');
        // Letzte Ablesung mitten im Dezember → Teilmonat
        $rows = $this->store->read('gas/readings.json', []);
        $rows[] = ['id' => 'r_x', 'meter_id' => $meterId, 'device_id' => 'd1', 'date' => '2025-12-10',
                   'counter' => end($rows)['counter'] + 50, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        $this->store->write('gas/readings.json', $rows);

        $monthly = $this->monthly('gas');
        self::assertFalse($this->row($monthly, '2025-12')['regression_point'], 'Teilmonat nicht im Fit');
        self::assertTrue($this->row($monthly, '2025-01')['regression_point']);
        self::assertFalse($this->row($monthly, '2025-07')['regression_point'], 'Sommer unter der HGT-Schwelle');
        $flagged = count(array_filter($monthly, fn($m) => $m['regression_point']));
        self::assertSame($flagged, $this->consumption->regressionPoints($monthly, 'gas')['n'], 'Markierung und Auswahl sind dieselbe');
    }

    /** Fehlen für einen Monat Temperaturen, ist seine HGT zu klein — er gehört nicht in die Kurve. */
    public function testMonthWithMissingTemperaturesIsNotARegressionPoint(): void
    {
        $this->seedTemps('2024-01-01', '2025-12-31');
        $temps = $this->store->read('temperatures.json', []);
        for ($d = 10; $d <= 20; $d++) unset($temps[sprintf('2025-01-%02d', $d)]);
        $this->store->write('temperatures.json', $temps);
        $this->seedMonthly('gas', '2024-01', '2025-11');
        $monthly = $this->monthly('gas');
        self::assertFalse($this->row($monthly, '2025-01')['regression_point'], '20 von 31 Tagen mit Temperatur reichen nicht');
        self::assertTrue($this->row($monthly, '2024-01')['regression_point']);
    }

    // ── Heizmodell und neue Wetterfelder (CALC-05) ──────────────────────

    public function testHeatModelRecoversSlopeAndBaseLoad(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        $this->seedMonthly('gas', '2023-01', '2025-12');
        $model = $this->consumption->heatModel('gas', $this->monthly('gas'));
        self::assertEqualsWithDelta(self::A, $model['a'], 0.001);
        self::assertEqualsWithDelta(self::C, $model['c'], 0.001);
    }

    public function testWeatherDeltaMeasuresExcessNotTheSeason(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        $this->seedMonthly('gas', '2023-01', '2025-12', ['2025-02' => 0.2 * (self::A * $this->hddOf('2025-02') + self::C * 28)]);
        $monthly = $this->monthly('gas');
        self::assertEqualsWithDelta(0.0, $this->row($monthly, '2025-01')['weather_delta_pct'], 1.0, 'Januar ist kein Mehrverbrauch');
        self::assertEqualsWithDelta(0.0, $this->row($monthly, '2025-07')['weather_delta_pct'], 1.0, 'Juli ist kein Minderverbrauch');
        self::assertEqualsWithDelta(20.0, $this->row($monthly, '2025-02')['weather_delta_pct'], 1.5, 'echter Mehrverbrauch +20 %');
        // Bereinigung: gleiches Klima jedes Jahr → Normal = Ist → keine Änderung
        $jan = $this->row($monthly, '2024-01');
        self::assertEqualsWithDelta($jan['kwh'], $jan['heat_adjusted'], 0.5);
    }

    /**
     * Ein warmer Übergangsmonat: Nur der Wettereinfluss laut Modell wird
     * umgerechnet. Die eigene Abweichung des Monats (+20 kWh, nicht wetterbedingt)
     * bleibt, wie sie ist — die Skalierung mit HGT_normal / HGT_ist machte aus
     * ihr „Heizung" und blähte sie auf.
     */
    public function testTransitionMonthKeepsItsOwnDeviation(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        $temps = $this->store->read('temperatures.json', []);
        for ($d = 1; $d <= 30; $d++) {
            $temps[sprintf('2025-09-%02d', $d)] = ['avg' => 14.6, 'min' => 11.6, 'max' => 17.6];   // 12 statt 30 HGT
        }
        $this->store->write('temperatures.json', $temps);
        $this->seedMonthly('gas', '2023-01', '2025-12', ['2025-09' => self::A * 12.0 - self::A * 30.0 + 20.0]);
        $sep = $this->row($this->monthly('gas'), '2025-09');
        self::assertEqualsWithDelta(12.0, $sep['hdd'], 0.01);
        self::assertEqualsWithDelta(24.0, $sep['hdd_normal'], 0.1, 'Normal = Mittel aus 30, 30, 12');
        // Ist = 0,3 × 12 + 5 × 30 + 20 = 173,6; bereinigt = Ist + 0,3 × (24 − 12)
        self::assertEqualsWithDelta(177.2, $sep['heat_adjusted'], 0.5);
    }

    /** Ein Urlaubs-Januar unter der Grundlast wird nicht auf die Grundlast „bereinigt". */
    public function testMonthBelowBaseLoadIsNotInflated(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        $this->seedMonthly('gas', '2023-01', '2025-12', ['2025-01' => 20.0 - (self::A * $this->hddOf('2025-01') + self::C * 31)]);
        $jan = $this->row($this->monthly('gas'), '2025-01');
        self::assertEqualsWithDelta(20.0, $jan['kwh'], 0.5);
        self::assertEqualsWithDelta(20.0, $jan['heat_adjusted'], 0.5, 'nichts zu bereinigen — nicht auf c × Tage anheben');
    }

    // ── Anomalien (CALC-06) ─────────────────────────────────────────────

    public function testCleanSummersAreNotAnomalies(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        $this->seedMonthly('gas', '2023-01', '2025-12');
        self::assertSame([], $this->anomalies->detect('gas', $this->monthly('gas')), 'saubere Daten: keine Meldung, auch nicht im Sommer');
    }

    /**
     * Sehr gleichmäßige Daten: Die robuste Streuung ist fast null, ohne
     * Untergrenze wären schon +6 % in einem Monat „6σ". Das ist Alltag.
     */
    public function testEverydayNoiseIsNotAnAnomaly(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        $this->seedMonthly('gas', '2023-01', '2025-12', ['2024-11' => 0.06 * (self::A * $this->hddOf('2024-11') + self::C * 30)]);
        self::assertSame([], $this->anomalies->detect('gas', $this->monthly('gas')));
    }

    public function testWinterExcessIsAnAnomaly(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        // +80 kWh auf rund 285 erwartete — deutlich über der Alltagsschwankung
        $this->seedMonthly('gas', '2023-01', '2025-12', ['2024-12' => 80.0]);
        $found = array_column($this->anomalies->detect('gas', $this->monthly('gas')), 'ym');
        self::assertContains('2024-12', $found);
    }

    /** exp13: +60 % im März bei einem Jahr Historie — früher unentdeckt (Monat im eigenen Mittel). */
    public function testSeasonalOutlierIsFoundWithOneYearOfHistory(): void
    {
        $this->seedMonthly('strom', '2025-01', '2025-12', [], fn(string $ym) =>
            (int)date('t', strtotime($ym . '-01')) * (in_array((int)substr($ym, 5), [1, 2, 12], true) ? 12.0 : 8.0)
            * ($ym === '2025-03' ? 1.6 : 1.0));
        $high = array_values(array_filter($this->anomalies->detect('strom', $this->monthly('strom')), fn($a) => $a['kind'] === 'high'));
        // Wintermonate sind +50 % — gegen ihre Nachbarmonate normal, gegen
        // das Gesamtmittel wären sie „Ausreißer"
        self::assertSame(['2025-03'], array_column($high, 'ym'));
    }

    // ── Trend als Vorjahresvergleich (CALC-05) ──────────────────────────

    private function ruleIds(): array
    {
        $recos = new RecommendationService(
            $this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n
        );
        return array_values(array_unique(array_map(fn($r) => explode('_', (string)$r['id'])[0], $recos->all())));
    }

    public function testNoTrendOnTrendFreeDataWhateverTheLastMonth(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        foreach (['2025-03', '2025-09', '2025-12'] as $end) {
            $this->seedMonthly('gas', '2023-01', $end);
            self::assertNotContains('r2', $this->ruleIds(), "kein Trend bei Datenende $end");
        }
    }

    /** Drei vergleichbare Monate sind kein Jahrestrend — es braucht mindestens neun. */
    public function testTrendNeedsNineComparableMonths(): void
    {
        $this->seedTemps('2024-01-01', '2025-12-31');
        $extra = [];
        foreach (['2025-10', '2025-11', '2025-12'] as $ym) {
            $extra[$ym] = 0.10 * (self::A * $this->hddOf($ym) + self::C * (int)date('t', strtotime($ym . '-01')));
        }
        $this->seedMonthly('gas', '2024-10', '2025-12', $extra);
        self::assertNotContains('r2', $this->ruleIds());
    }

    public function testRisingYearIsATrend(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        $extra = [];
        for ($t = strtotime('2025-01-01'); date('Y-m', $t) <= '2025-12'; $t = strtotime('+1 month', $t)) {
            $ym = date('Y-m', $t);
            $extra[$ym] = 0.10 * (self::A * $this->hddOf($ym) + self::C * (int)date('t', $t));
        }
        $this->seedMonthly('gas', '2023-01', '2025-12', $extra);
        self::assertContains('r2', $this->ruleIds());
    }

    // ── Prognose (CALC-03, CALC-12, CALC-13) ────────────────────────────

    private function forecast(string $utility = 'gas', array $opts = []): array
    {
        $f = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        return $f->forMeter($utility, $this->meters->list($utility)[0], $opts);
    }

    /** exp06: Historie Mai–Dezember. Der Januar kam früher mit 0 HGT (−54 % im Jahr). */
    public function testMissingWinterMonthsComeFromTheModel(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');   // Klima bekannt, Verbrauch erst ab Mai
        $this->seedMonthly('gas', '2025-05', '2025-12');
        $fc = $this->forecast();
        self::assertTrue($fc['valid']);
        $jan = array_values(array_filter($fc['forecast'], fn($m) => $m['month'] === 1))[0];
        $truth = self::A * $this->hddOf('2026-01') + self::C * 31;
        self::assertEqualsWithDelta($truth, $jan['kwh'], 0.1 * $truth, 'Januar aus der Heizkurve, nicht ~0');
        self::assertSame('regression_only', $jan['method']);
        $codes = array_column($fc['warnings'], 'code');
        self::assertContains('history_short', $codes);
        $short = array_values(array_filter($fc['warnings'], fn($w) => $w['code'] === 'history_short'))[0];
        self::assertSame(8, $short['months']);
        self::assertSame([1, 2, 3, 4], $short['missing']);
    }

    public function testClimateNormalFeedsTheForecastAndTheBand(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        $this->seedMonthly('gas', '2023-01', '2025-12');
        $means = [];
        for ($y = 1996; $y <= 2025; $y++) {
            for ($t = strtotime("$y-01-01 12:00"); date('Y', $t) === (string)$y; $t += 86400) {
                $means[date('Y-m-d', $t)] = self::TEMP[(int)date('n', $t)] + (($y % 3) - 1) * 1.5;
            }
        }
        (new ClimateNormalService($this->store, $this->settings))
            ->save(ClimateNormalService::compute($means, 51.34, 12.37, ['from' => '1996-01-01', 'to' => '2025-12-31']));

        $fc = $this->forecast();
        self::assertSame('climate_normal', $fc['hdd_source']);
        self::assertNotContains('no_climate_normal', array_column($fc['warnings'], 'code'));
        self::assertNotNull($fc['annual']);
        self::assertLessThan($fc['annual']['value'], $fc['annual']['low']);
        self::assertGreaterThan($fc['annual']['value'], $fc['annual']['high']);
        self::assertSame(80, $fc['annual']['level_pct']);
        $jan = array_values(array_filter($fc['forecast'], fn($m) => $m['month'] === 1))[0];
        self::assertLessThan($jan['kwh'], $jan['band_low']);
        self::assertGreaterThan($jan['kwh'], $jan['band_high']);
    }

    /** Ein wärmeres Jahr hat die HGT der Heizgrenze − δ — nicht HGT − δ × Tage. */
    public function testTemperatureOffsetShiftsTheBase(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        $this->seedMonthly('gas', '2023-01', '2025-12');
        $fc = $this->forecast('gas', ['temp_offset' => 2.0]);
        $may = array_values(array_filter($fc['forecast'], fn($m) => $m['month'] === 5))[0];
        // Mai 14 °C: bei 15 °C Heizgrenze 31 HGT; mit +2 K ist der Mai über der Grenze → 0
        self::assertEqualsWithDelta(0.0, $may['hdd_estimated'], 0.01);
        $jan = array_values(array_filter($fc['forecast'], fn($m) => $m['month'] === 1))[0];
        self::assertEqualsWithDelta(31 * 13.0, $jan['hdd_estimated'], 0.01, 'Januar: 31 × (13 − 0)');
    }

    public function testForecastUsesTheEffectiveAdvancePlanAndContinuesTheLastContract(): void
    {
        $this->seedTemps('2023-01-01', '2025-12-31');
        $meterId = $this->seedMonthly('gas', '2024-01', '2025-11');
        $this->store->write('gas/contracts.json', [[
            'id' => 'c1', 'meter_id' => $meterId, 'provider' => 'X', 'tariff_name' => 'T',
            'start' => '2024-01-01', 'end' => '2026-02-28',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 10.0]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 90.0]],
            'bonuses' => [],
            'special_payments' => [[
                'id' => 'sp1', 'date' => '2025-12-05', 'kind' => 'rueckzahlung_mit', 'amount_eur' => 40.0,
                'note' => '', 'new_advance_eur' => 50.0, 'advance_from' => '2026-01-01',
            ]],
        ]]);
        $fc = $this->forecast();
        $byYm = array_column($fc['forecast'], null, 'ym');
        self::assertSame(90.0, $byYm['2025-12']['advance_estimated']);
        self::assertSame(50.0, $byYm['2026-01']['advance_estimated'], 'Sonderzahlung „mit Auswirkung" ändert den Abschlag');
        self::assertFalse($byYm['2026-02']['contract_assumed']);
        self::assertTrue($byYm['2026-03']['contract_assumed'], 'nach Vertragsende läuft der Vertrag als Annahme weiter');
        self::assertSame(50.0, $byYm['2026-03']['advance_estimated'], 'Abschlag läuft weiter');
        $vol = $byYm['2026-03']['kwh'];
        self::assertEqualsWithDelta($vol * 0.10 + 12.0, $byYm['2026-03']['cost_estimated'], 0.05, 'Grundpreis läuft weiter');
    }

    // ── Saldo nach Kalender (CALC-02) ───────────────────────────────────

    /**
     * v2.8.1 — Vertrag angelegt, aber noch keine zwei Ablesungen (der übliche
     * Einstieg) oder alle Monate vor einer frischen Zäsur: Es gibt nichts
     * hochzurechnen. v2.8.0 brach hier mit HTTP 500 ab, die ganze
     * Verbrauchsansicht zeigte nur noch die Fehlermeldung.
     */
    public function testBalanceWithoutUsableMonthsDoesNotFail(): void
    {
        $first = date('Y-m-01');
        $start = date('Y-m-01', strtotime($first . ' -2 months'));
        $meter = $this->meters->list('strom')[0];
        $this->contracts->create('strom', [
            'meter_id' => $meter['id'], 'provider' => 'X', 'tariff_name' => 'T', 'start' => $start,
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => $start, 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 50.0]],
        ]);
        $c = $this->consumption->contractStatus('strom', $meter)['contracts'][0];
        self::assertSame('flat_average', $c['projection_method']);
        self::assertSame(0.0, $c['estimated_cost_to_date'], 'ohne Daten keine Schätzung');
        self::assertEqualsWithDelta($c['base_to_date'], $c['cost_to_date'], 0.01, 'bis heute nur der Grundpreis');
        self::assertSame(150.0, $c['advance_paid'], 'drei Abschläge bis heute');

        // Dasselbe mit Ablesungen, die alle vor einer Zäsur von heute liegen
        $meterId = $this->seedMonthly('gas', date('Y-m', strtotime($first . ' -14 months')), date('Y-m', strtotime($first . ' -1 month')));
        $this->meters->update('gas', $meterId, ['baseline_events' => [['date' => date('Y-m-d'), 'label' => 'Heizungstausch']]]);
        $this->contracts->create('gas', [
            'meter_id' => $meterId, 'provider' => 'X', 'tariff_name' => 'T', 'start' => $start,
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 10.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 50.0]],
        ]);
        $g = $this->consumption->contractStatus('gas', $this->meters->get('gas', $meterId))['contracts'][0];
        self::assertSame('flat_average', $g['projection_method']);
    }

    public function testBalanceCountsAdvancesByCalendarAndEstimatesTheGap(): void
    {
        // Monatsrechnung vom Monatsersten aus — '-8 months' am 31. läuft über
        $first = date('Y-m-01');
        $start = date('Y-m-01', strtotime($first . ' -8 months'));
        $lastYm = date('Y-m', strtotime($first . ' -4 months'));
        $this->seedTemps(date('Y-01-01', strtotime($first . ' -3 years')), date('Y-12-31'));
        $meterId = $this->seedMonthly('gas', date('Y-m', strtotime($first . ' -3 years')), $lastYm);
        $this->store->write('gas/contracts.json', [[
            'id' => 'c1', 'meter_id' => $meterId, 'provider' => 'X', 'tariff_name' => 'T',
            'start' => $start, 'end' => date('Y-m-d', strtotime($start . ' +1 year -1 day')),
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 10.0]],
            'base_prices' => [['from' => $start, 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 25.0]],
            'bonuses' => [], 'special_payments' => [],
        ]]);
        $meter = $this->meters->list('gas')[0];
        $c = $this->consumption->contractStatus('gas', $meter)['contracts'][0];

        self::assertSame(225.0, $c['advance_paid'], 'neun Abschläge bis heute, nicht nur die abgelesenen Monate');
        self::assertSame(date('Y-m-d'), $c['balance_as_of']);
        self::assertSame('forecast', $c['projection_method']);
        self::assertSame(date('Y-m-01', strtotime($first . ' -3 months')), $c['measured_until']);
        self::assertGreaterThan(0.0, $c['estimated_cost_to_date'], 'die Lücke seit der letzten Ablesung ist geschätzt');
        // Die Teile ergeben die Summe; Grundpreis nach Kalender (acht volle Monate plus der laufende anteilig)
        self::assertEqualsWithDelta($c['cost_to_date'], $c['energy_cost_to_date'] + $c['base_to_date'] - $c['bonus_to_date'], 0.02);
        self::assertGreaterThanOrEqual(12.0 * 8, $c['base_to_date']);
        self::assertLessThan(12.0 * 9, $c['base_to_date']);
        self::assertEqualsWithDelta($c['cost_to_date'] - $c['advance_paid'], $c['current_balance'], 0.02);
        self::assertEqualsWithDelta(
            $c['current_balance'] + $c['estimated_cost_remaining'] - $c['advance_remaining'],
            $c['projected_end_balance'], 0.02
        );
        self::assertSame(75.0, $c['advance_remaining'], 'drei Abschläge bis zum Vertragsende');
        self::assertNotNull($c['suggested_advance']);
        self::assertEqualsWithDelta(max(0.0, round(25.0 + $c['projected_end_balance'] / 3)), $c['suggested_advance'], 0.01);
    }

    // ── Knickmodell (CALC-22) ───────────────────────────────────────────

    public function testSegmentedModelIsContinuous(): void
    {
        $x = []; $y = [];
        for ($i = 0; $i <= 20; $i++) { $x[] = $i * 10.0; $y[] = 100.0 + 2.0 * max(0.0, $i * 10.0 - 50.0); }
        $reg = $this->regression->segmented($x, $y, 50.0);
        self::assertTrue($reg['valid']);
        self::assertEqualsWithDelta(2.0, $reg['heat']['a'], 1e-6);
        self::assertEqualsWithDelta(100.0, $reg['base']['b'], 1e-6);
        self::assertEqualsWithDelta(
            $this->regression->predict($reg, 49.999), $this->regression->predict($reg, 50.001), 0.01,
            'kein Sprung am Knick'
        );
    }
}
