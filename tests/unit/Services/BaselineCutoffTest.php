<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AnomalyService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\MeterService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Storage\Migrator;
use Energietracker\Tests\Support\ServiceTestCase;

/**
 * F1011 — Baseline-Zäsur (GitHub #20).
 *
 * Nach einer baulichen Maßnahme ist das Gebäude thermisch ein anderes. Eine
 * Regression über beide Epochen beschreibt keine von beiden. Diese Suite hält
 * fest, dass die Zäsur in **jeder** Auswertung greift — nicht nur im Chart.
 *
 * Der Aufbau ist bewusst synthetisch und exakt: Der Verbrauch wird als
 * `Steigung × HGT + Sockel` erzeugt, der Umrechnungsfaktor auf 1,0 gesetzt.
 * Damit ist jede erwartete Zahl von Hand nachrechenbar statt „ungefähr".
 */
final class BaselineCutoffTest extends ServiceTestCase
{
    /** Heizkurve vor der Maßnahme: kWh je Gradtag. */
    private const SLOPE_BEFORE = 0.40;
    /** Heizkurve danach — ein gedämmtes Haus braucht weniger je Kältegrad. */
    private const SLOPE_AFTER  = 0.25;
    /** Wetterunabhängiger Sockel (Warmwasser, Kochen) je Monat. */
    private const BASE_LOAD    = 12.0;

    private const CUT      = '2023-01-01';
    private const FIRST_YM = '2020-01';
    private const LAST_YM  = '2026-06';

    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        // kWh == m³: hält die Handrechnung prüfbar.
        $this->settings->set(['gas_conversion_factor' => 1.0]);
        $this->seedTemperatures();
        $this->meterId = $this->seedMeter();
    }

    // ── Aufbau ────────────────────────────────────────────────────────

    /**
     * Ein Jahresgang mit festen Monatsmitteln. Bei hdd_base 15 °C ergibt sich
     * je Monat ein reproduzierbarer HGT-Wert; die Sommermonate liegen bewusst
     * über der Basis, damit sie — wie in der Wirklichkeit — aus der Regression
     * herausfallen.
     */
    private const AVG_TEMP = [
        1 => -1.0, 2 => 0.5, 3 => 4.0, 4 => 9.0, 5 => 14.0, 6 => 17.0,
        7 => 19.0, 8 => 18.5, 9 => 14.5, 10 => 9.5, 11 => 4.5, 12 => 1.0,
    ];

    private function seedTemperatures(): void
    {
        $temps = [];
        $cur = new \DateTimeImmutable(self::FIRST_YM . '-01');
        $end = new \DateTimeImmutable('2026-12-31');
        while ($cur <= $end) {
            $avg = self::AVG_TEMP[(int)$cur->format('n')];
            $temps[$cur->format('Y-m-d')] = ['avg' => $avg, 'min' => $avg - 3, 'max' => $avg + 3];
            $cur = $cur->modify('+1 day');
        }
        $this->store->write('temperatures.json', $temps);
    }

    /** HGT eines Monats — dieselbe Rechnung wie enrichWithWeather(). */
    private function hddOf(string $ym): float
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $days = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        return round($days * max(0.0, 15.0 - self::AVG_TEMP[$m]), 1);
    }

    /** Verbrauch eines Monats nach der Heizkurve der jeweiligen Epoche. */
    private function consumptionOf(string $ym): float
    {
        $slope = $ym < substr(self::CUT, 0, 7) ? self::SLOPE_BEFORE : self::SLOPE_AFTER;
        return $slope * $this->hddOf($ym) + self::BASE_LOAD;
    }

    private function seedMeter(): string
    {
        $meterId = $this->setMeterDevices('gas', [[
            'id' => 'd_gas_1', 'serial' => null,
            'installed_on' => self::FIRST_YM . '-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $rows    = [];
        $counter = 0.0;
        $cur     = new \DateTimeImmutable(self::FIRST_YM . '-01');
        $last    = new \DateTimeImmutable(self::LAST_YM . '-01');
        $rows[]  = ['date' => $cur->format('Y-m-d'), 'counter' => $counter, 'device_id' => 'd_gas_1'];
        while ($cur < $last) {
            $ym  = $cur->format('Y-m');
            $cur = $cur->modify('first day of next month');
            $counter += $this->consumptionOf($ym);
            $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => round($counter, 3), 'device_id' => 'd_gas_1'];
        }
        $this->setReadings('gas', $meterId, $rows);
        return $meterId;
    }

    /** @param array<int,array{date:string,label:string}> $events */
    private function setCut(array $events): array
    {
        $this->meters->update('gas', $this->meterId, ['baseline_events' => $events]);
        return $this->meters->get('gas', $this->meterId);
    }

    private function withCut(): array
    {
        return $this->setCut([['date' => self::CUT, 'label' => 'Dachdämmung']]);
    }

    private function monthlyFor(array $meter): array
    {
        return $this->consumption->forMeter('gas', $meter);
    }

    // ── 1. Datenmodell und Auflösung ──────────────────────────────────

    public function testFreshInstallCarriesTheBaselineFieldAndSchemaIsBumped(): void
    {
        self::assertSame('1.4.0', Migrator::SCHEMA_VERSION);
        $meters = $this->store->read('gas/meters.json', []);
        self::assertArrayHasKey('baseline_events', $meters[0], 'Zähler trägt das v1.4.0-Feld');
        self::assertSame([], $meters[0]['baseline_events'], 'Default ist „keine Zäsur"');
    }

    public function testLatestPastEventWins(): void
    {
        $meter = $this->setCut([
            ['date' => '2021-05-01', 'label' => 'Fenster'],
            ['date' => self::CUT,    'label' => 'Dachdämmung'],
        ]);
        self::assertSame(self::CUT, MeterService::activeBaselineDate($meter, '2026-06-30'));
        self::assertSame('Dachdämmung', MeterService::activeBaselineEvent($meter, '2026-06-30')['label']);
    }

    public function testFutureEventIsStoredButDoesNotTakeEffectYet(): void
    {
        $meter = $this->setCut([['date' => '2027-04-15', 'label' => 'Wärmepumpe']]);
        self::assertCount(1, $meter['baseline_events'], 'Ereignis ist gespeichert');
        self::assertNull(
            MeterService::activeBaselineDate($meter, '2026-06-30'),
            'Künftig datiert → wirkt heute nicht'
        );
        self::assertSame(
            '2027-04-15',
            MeterService::activeBaselineDate($meter, '2027-05-01'),
            'Sobald das Datum erreicht ist, greift es'
        );
    }

    public function testEventsAreSortedAndDuplicatesRejected(): void
    {
        $meter = $this->setCut([
            ['date' => '2024-03-01', 'label' => 'B'],
            ['date' => '2021-05-01', 'label' => 'A'],
        ]);
        self::assertSame(['2021-05-01', '2024-03-01'], array_column($meter['baseline_events'], 'date'));

        $this->expectException(\InvalidArgumentException::class);
        $this->setCut([
            ['date' => '2024-03-01', 'label' => 'B'],
            ['date' => '2024-03-01', 'label' => 'noch mal'],
        ]);
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->setCut([['date' => '2024-02-31', 'label' => 'gibt es nicht']]);
    }

    // ── 2. Die Markierung selbst ──────────────────────────────────────

    public function testTransitionMonthCountsAsBefore(): void
    {
        // Zäsur mitten im Monat → dieser Monat mischt beide Zustände und
        // bleibt draußen; der erste volle Monat danach zählt.
        $meter = $this->setCut([['date' => '2023-01-17', 'label' => 'mitten drin']]);
        $byYm  = $this->byYm($this->monthlyFor($meter));
        self::assertTrue($byYm['2023-01']['pre_baseline'], 'Übergangsmonat gehört zu „davor"');
        self::assertFalse($byYm['2023-02']['pre_baseline'], 'erster voller Monat gehört zu „danach"');
    }

    public function testCutOnTheFirstOfAMonthMakesThatMonthCountAsAfter(): void
    {
        $byYm = $this->byYm($this->monthlyFor($this->withCut()));
        self::assertFalse($byYm['2023-01']['pre_baseline'], 'Zäsur am Monatsersten → Monat zählt danach');
        self::assertTrue($byYm['2022-12']['pre_baseline']);
    }

    // ── 3. Die acht Verbraucher ───────────────────────────────────────

    public function testWeatherAdjustmentDropsPreBaselineMonthsFromTheModel(): void
    {
        $byYm = $this->byYm($this->monthlyFor($this->withCut()));

        // expected_hgt: nur ab der Zäsur — davor beschreibt das Modell nichts.
        self::assertNull($byYm['2021-01']['expected_hgt'], 'vor der Zäsur kein Erwartungswert');
        self::assertNotNull($byYm['2024-01']['expected_hgt'], 'danach schon');

        // delta_pct ebenso.
        self::assertNull($byYm['2021-01']['delta_pct']);
        self::assertNotNull($byYm['2024-01']['delta_pct']);

        // weather_adjusted bleibt für BEIDE Epochen — es ist gebäudeunabhängig
        // und trägt den Vorher/Nachher-Vergleich.
        self::assertNotNull($byYm['2021-01']['weather_adjusted'], 'auch davor normiert');
        self::assertNotNull($byYm['2024-01']['weather_adjusted']);
    }

    public function testExpectedValueFollowsTheNewBuildingNotTheOldMix(): void
    {
        $withoutCut = $this->byYm($this->monthlyFor($this->meters->get('gas', $this->meterId)));
        $withCut    = $this->byYm($this->monthlyFor($this->withCut()));

        $ym       = '2024-01';
        $actual   = $withCut[$ym]['kwh'];
        $mixed    = $withoutCut[$ym]['expected_hgt'];
        $clean    = $withCut[$ym]['expected_hgt'];

        self::assertGreaterThan(
            abs($clean - $actual),
            abs($mixed - $actual),
            'Ohne Zäsur liegt der Erwartungswert weiter daneben als mit'
        );
    }

    public function testRegressionInputExcludesPreBaselineMonths(): void
    {
        $meter   = $this->withCut();
        $monthly = $this->monthlyFor($meter);
        $info    = $this->consumption->baselineInfo('gas', $meter, $monthly);

        $expected = 0;
        foreach ($monthly as $m) {
            if (!empty($m['pre_baseline'])) continue;
            if (($m['hdd'] ?? 0) > 5.0 && ($m['kwh'] ?? 0) > 0) $expected++;
        }
        self::assertSame($expected, $info['points_after']);
        self::assertLessThan(count($monthly), $info['months_after'], 'nicht alle Monate zählen mit');
    }

    /**
     * Der Analyse-Chart im ConsumptionController fittet über genau diese
     * Punktauswahl. Ohne diesen Test wäre die Chart-Zeile der Befundtabelle
     * die einzige, die von keinem Test erreicht wird.
     */
    public function testChartRegressionPointsSkipThePreBaselineEpoch(): void
    {
        $meter   = $this->withCut();
        $monthly = $this->monthlyFor($meter);

        $after  = $this->consumption->regressionPoints($monthly, 'gas');
        $before = $this->consumption->regressionPoints($monthly, 'gas', true);

        self::assertGreaterThan(0, $before['n'], 'es gibt Punkte vor der Zäsur …');
        self::assertGreaterThan(0, $after['n'],  '… und danach');
        self::assertSame(
            count(array_filter($monthly, fn($m) => ($m['hdd'] ?? 0) > 0 && ($m['kwh'] ?? 0) > 0)),
            $before['n'] + $after['n'],
            'zusammen ergeben beide Epochen wieder alle verwertbaren Monate'
        );

        // Ohne Zäsur landet alles im „danach"-Topf — das ist das alte Verhalten.
        $plain = $this->consumption->regressionPoints(
            $this->monthlyFor($this->setCut([])), 'gas'
        );
        self::assertSame($before['n'] + $after['n'], $plain['n']);
    }

    /**
     * Die Heizkurve der Prognose muss die Steigung der NEUEN Epoche treffen.
     * Ohne Zäsur entsteht ein Mischwert zwischen beiden — gemessen 0,317
     * statt 0,250, und ein sichtbar schlechteres R² (0,83 statt 1,00).
     */
    public function testForecastRegressionFollowsTheNewEpochOnly(): void
    {
        $forecast = $this->forecastService();

        $cut = $forecast->forMeter('gas', $this->withCut());
        self::assertTrue($cut['valid']);
        self::assertEqualsWithDelta(self::SLOPE_AFTER, $cut['regression']['a'], 0.01);
        self::assertEqualsWithDelta(1.0, $cut['regression']['r2'], 0.01, 'saubere Epoche → perfekter Fit');

        $plain = $forecast->forMeter('gas', $this->setCut([]));
        self::assertGreaterThan(
            self::SLOPE_AFTER + 0.03,
            $plain['regression']['a'],
            'Kontrolle: ohne Zäsur ein Mischwert aus beiden Gebäudezuständen'
        );
        self::assertLessThan($cut['regression']['r2'], $plain['regression']['r2']);
    }

    /**
     * Der prognostizierte Monatswert ist eine Mischung aus Regression und
     * Saisonmittel. Beide müssen die alte Epoche auslassen — sonst zieht das
     * Saisonmittel den Wert nach oben, auch wenn die Regression stimmt.
     */
    public function testForecastMonthlyValueMatchesTheNewHeatingCurve(): void
    {
        $forecast = $this->forecastService();
        $out      = $forecast->forMeter('gas', $this->withCut());

        $jan = null;
        foreach ($out['forecast'] as $m) {
            if ((int)$m['month'] === 1) { $jan = $m; break; }
        }
        self::assertNotNull($jan, 'ein Januar liegt im Prognosefenster');

        // Handrechnung: 0,25 × 496 HGT + 12 = 136,0 kWh
        $expected = self::SLOPE_AFTER * $this->hddOf('2027-01') + self::BASE_LOAD;
        self::assertEqualsWithDelta($expected, $jan['kwh'], 2.0);

        $plain = $forecast->forMeter('gas', $this->setCut([]));
        $janPlain = null;
        foreach ($plain['forecast'] as $m) {
            if ((int)$m['month'] === 1) { $janPlain = $m; break; }
        }
        self::assertGreaterThan(
            $jan['kwh'] + 10,
            $janPlain['kwh'],
            'Kontrolle: ohne Zäsur prognostiziert der Tracker den ungedämmten Zustand weiter'
        );
    }

    private function forecastService(): ForecastService
    {
        return new ForecastService(
            $this->consumption, $this->regression, $this->settings,
            $this->contracts, $this->i18n
        );
    }

    public function testAnomalyBaselineIsTheCurrentEpoch(): void
    {
        $withCut = $this->monthlyFor($this->withCut());
        foreach ($this->anomalies->detect('gas', $withCut) as $a) {
            self::assertGreaterThanOrEqual(
                substr(self::CUT, 0, 7),
                $a['ym'],
                'Kein Monat vor der Zäsur darf als Anomalie auftauchen'
            );
        }
    }

    /**
     * Toggle-Beweis: Ein Ausreißer VOR der Zäsur muss ohne Zäsur gemeldet
     * werden (sonst prüft der Test nichts) und mit Zäsur verschwinden.
     */
    public function testRecommendationsIgnorePreBaselineMonths(): void
    {
        $this->spikeMonth('2021-01', 900.0);

        $withoutCut = $this->gasMonthsFlaggedByRecommendations();
        self::assertContains(
            '2021-01',
            $withoutCut,
            'Kontrolle: ohne Zäsur wird der Ausreißer von 2021-01 gemeldet'
        );

        $this->withCut();
        $withCut = $this->gasMonthsFlaggedByRecommendations();
        self::assertNotContains(
            '2021-01',
            $withCut,
            'Mit Zäsur darf kein Monat von vor der Maßnahme mehr auftauchen'
        );
        foreach ($withCut as $ym) {
            self::assertGreaterThanOrEqual(substr(self::CUT, 0, 7), $ym);
        }
    }

    /**
     * Der eigentliche Schaden aus GitHub #20: Nach der Sanierung liegt der
     * Vergleichsmaßstab dauerhaft zu hoch, weil er die ungedämmten Jahre
     * enthält — ein echter Mehrverbrauch danach fällt dann durch.
     *
     * Gemessen: Ein Aufschlag von 100 kWh auf 2024-03 wird **mit** Zäsur
     * gemeldet und **ohne** Zäsur übersehen. Genau diese Lücke schließt F1011.
     */
    public function testModerateExcessAfterTheWorkIsDetectedOnlyWithTheCut(): void
    {
        $this->spikeMonth('2024-03', 100.0);

        $this->setCut([]);
        self::assertNotContains(
            '2024-03',
            $this->gasMonthsFlaggedByRecommendations(),
            'Kontrolle: ohne Zäsur geht der Mehrverbrauch im Mittel der Altjahre unter'
        );

        $this->withCut();
        self::assertContains(
            '2024-03',
            $this->gasMonthsFlaggedByRecommendations(),
            'Mit Zäsur misst R1 gegen die neue Epoche und meldet ihn'
        );
    }

    /**
     * Trend (R2) und Rohausreißer (R4) tragen dieselbe Regel.
     *
     * Bei einer frischen Zäsur bleiben zu wenige Punkte übrig — dann müssen
     * beide **schweigen**, statt einen „Trend" zu melden, der in Wahrheit der
     * Umbau ist, oder einen Ausreißer, der noch zum alten Gebäude gehört.
     * Ohne Zäsur feuern beide; das ist die Kontrolle.
     */
    public function testTrendAndRawOutlierRulesAlsoRespectTheCut(): void
    {
        $this->spikeMonth('2025-07', 300.0);   // Sommer: weather_adjusted ist null → R4-Fall

        $this->setCut([]);
        $ohne = $this->gasRuleIds();
        self::assertContains('r2', $ohne, 'Kontrolle: ohne Zäsur meldet R2 einen Trend');
        self::assertContains('r4', $ohne, 'Kontrolle: ohne Zäsur meldet R4 den Ausreißer');

        // Zäsur nach dem Ausreißer: zu wenig neue Historie für beide Regeln.
        $this->setCut([['date' => '2025-10-01', 'label' => 'Wärmepumpe']]);
        $mit = $this->gasRuleIds();
        self::assertNotContains('r2', $mit, 'R2 darf keinen Trend über die Zäsur hinweg melden');
        self::assertNotContains('r4', $mit, 'R4 darf keinen Monat von vor der Zäsur melden');
    }

    /**
     * Gegenstück zum vorigen Test: Der **Rohmittelwert** von R4 muss ebenfalls
     * aus der neuen Epoche kommen. Ein Sommer-Ausreißer nach der Sanierung
     * (weather_adjusted ist dort `null`, deshalb greift R4 statt R1) wird bei
     * +160 kWh nur erkannt, wenn die ungedämmten Jahre nicht mitzählen.
     */
    public function testRawOutlierAfterTheWorkNeedsTheNewMean(): void
    {
        $this->spikeMonth('2024-07', 160.0);

        $this->setCut([]);
        self::assertNotContains(
            'r4',
            $this->gasRuleIds(),
            'Kontrolle: gegen das Mittel der Altjahre bleibt der Ausreißer unauffällig'
        );

        $this->withCut();
        self::assertContains(
            'r4',
            $this->gasRuleIds(),
            'Gegen das Mittel der neuen Epoche fällt er auf'
        );
    }

    /** @return array<int,string> Regel-Präfixe der Gas-Empfehlungen (r1, r2, …) */
    private function gasRuleIds(): array
    {
        $recos = new RecommendationService(
            $this->store,
            $this->meters,
            $this->consumption,
            $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n),
            $this->i18n
        );
        $out = [];
        foreach ($recos->all() as $r) {
            $ev = $r['evidence'] ?? [];
            if (($ev['utility'] ?? 'gas') !== 'gas') continue;
            $out[] = explode('_', (string)$r['id'])[0];
        }
        return array_values(array_unique($out));
    }

    /** @return array<int,string> Monate, auf die sich Gas-Empfehlungen beziehen */
    private function gasMonthsFlaggedByRecommendations(): array
    {
        $recos = new RecommendationService(
            $this->store,
            $this->meters,
            $this->consumption,
            $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new DeliveryService($this->store, $this->meters, $this->i18n),
            $this->i18n
        );
        $out = [];
        foreach ($recos->all() as $r) {
            $ev = $r['evidence'] ?? [];
            if (($ev['utility'] ?? '') !== 'gas') continue;
            if (isset($ev['ym'])) $out[] = (string)$ev['ym'];
        }
        return array_values(array_unique($out));
    }

    /**
     * Erhöht den Verbrauch eines einzelnen Monats. Die Zählerstände sind
     * kumulativ — der Aufschlag muss deshalb auf jede spätere Ablesung
     * ebenfalls drauf, sonst verschöbe sich der Folgemonat nach unten.
     */
    private function spikeMonth(string $ym, float $extra): void
    {
        $rows = $this->store->read('gas/readings.json', []);
        $from = (new \DateTimeImmutable($ym . '-01'))->modify('first day of next month')->format('Y-m-d');
        foreach ($rows as &$r) {
            if (($r['date'] ?? '') >= $from) $r['counter'] = (float)$r['counter'] + $extra;
        }
        unset($r);
        $this->store->write('gas/readings.json', $rows);
    }

    // ── 4. Ohne Zäsur ändert sich nichts ──────────────────────────────

    public function testEmptyEventListReproducesTheOldBehaviourExactly(): void
    {
        $meter   = $this->meters->get('gas', $this->meterId);
        $monthly = $this->monthlyFor($meter);

        foreach ($monthly as $m) {
            self::assertFalse($m['pre_baseline'], 'ohne Zäsur ist kein Monat markiert');
        }
        // Und die Kennzahlen entstehen wie bisher über die volle Historie.
        $byYm = $this->byYm($monthly);
        self::assertNotNull($byYm['2021-01']['expected_hgt']);
        self::assertNotNull($byYm['2021-01']['delta_pct']);
        self::assertNull($this->consumption->baselineComparison('gas', $meter, $monthly));
    }

    // ── 5. Untergrenzen — Hinweis statt Stille ────────────────────────

    public function testLimitsAreReportedInsteadOfSilentlyDroppingResults(): void
    {
        // Zäsur so spät, dass danach zu wenige Punkte für die Regression bleiben.
        $meter   = $this->setCut([['date' => '2026-04-01', 'label' => 'gerade erst']]);
        $monthly = $this->monthlyFor($meter);
        $info    = $this->consumption->baselineInfo('gas', $meter, $monthly);

        $byKey = array_column($info['limits'], null, 'key');
        self::assertArrayHasKey('regression', $byKey);
        self::assertFalse($byKey['regression']['ok'], 'Die Regression kann nicht rechnen …');
        self::assertSame(ConsumptionService::MIN_POINTS_REGRESSION, $byKey['regression']['need']);
        self::assertLessThan($byKey['regression']['need'], $byKey['regression']['have']);

        // Kein stiller Rückfall: expected_hgt bleibt leer, statt heimlich
        // wieder über die volle Historie gerechnet zu werden.
        $byYm = $this->byYm($monthly);
        self::assertNull($byYm['2026-05']['expected_hgt']);
    }

    public function testLimitsAreReportedEvenWithoutAnyCut(): void
    {
        // Ein frischer Zähler mit sehr kurzer Historie — ganz ohne Zäsur.
        $short = $this->meters->create('gas', ['name' => 'Neubau']);
        $this->store->write('gas/readings.json', array_merge(
            $this->store->read('gas/readings.json', []),
            [[
                'id' => 'r_short_1', 'meter_id' => $short['id'], 'date' => '2026-05-01',
                'counter' => 0.0, 'device_id' => $short['devices'][0]['id'],
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false,
            ]]
        ));
        $monthly = $this->consumption->forMeter('gas', $short);
        $info    = $this->consumption->baselineInfo('gas', $short, $monthly);

        self::assertNull($info['active_from'], 'keine Zäsur gesetzt');
        $byKey = array_column($info['limits'], null, 'key');
        self::assertFalse(
            $byKey['weather_adjustment']['ok'],
            'Auch ohne Zäsur wird die zu kurze Historie erklärt statt verschwiegen'
        );
        self::assertSame(ConsumptionService::MIN_MONTHS_WEATHER, $byKey['weather_adjustment']['need']);
        self::assertSame(AnomalyService::MIN_MONTHS, $byKey['anomalies']['need']);
    }

    // ── 6. Vorher/Nachher — gegen die Handrechnung ────────────────────

    public function testComparisonMatchesTheHandCalculatedSlopes(): void
    {
        $meter = $this->withCut();
        $cmp   = $this->consumption->baselineComparison('gas', $meter, $this->monthlyFor($meter));

        self::assertNotNull($cmp, 'beide Epochen sind dick genug besetzt');
        self::assertEqualsWithDelta(self::SLOPE_BEFORE, $cmp['before']['slope'], 0.02);
        self::assertEqualsWithDelta(self::SLOPE_AFTER,  $cmp['after']['slope'],  0.02);

        // (0,25 − 0,40) / 0,40 = −37,5 %
        $expected = (self::SLOPE_AFTER - self::SLOPE_BEFORE) / self::SLOPE_BEFORE * 100.0;
        self::assertEqualsWithDelta($expected, $cmp['delta_pct'], 1.5);
        self::assertLessThan(0, $cmp['delta_pct'], 'Dämmung senkt den Verbrauch je Gradtag');
        self::assertSame('kWh', $cmp['unit']);
    }

    public function testComparisonStaysNullWhenOneEpochIsTooThin(): void
    {
        $meter = $this->setCut([['date' => '2026-04-01', 'label' => 'zu frisch']]);
        self::assertNull(
            $this->consumption->baselineComparison('gas', $meter, $this->monthlyFor($meter)),
            'Lieber keine Zahl als eine aus vier Punkten'
        );
    }

    // ── 7. Sicherung und Migration ────────────────────────────────────

    public function testBackupRoundtripCarriesTheBaselineEvents(): void
    {
        $this->withCut();
        $backup = new \Energietracker\Services\BackupService($this->store, $this->i18n);
        $dump   = $backup->export();

        $this->meters->update('gas', $this->meterId, ['baseline_events' => []]);
        self::assertSame([], $this->meters->get('gas', $this->meterId)['baseline_events']);

        $backup->import($dump);
        $restored = $this->meters->get('gas', $this->meterId);
        self::assertSame(self::CUT, $restored['baseline_events'][0]['date']);
        self::assertSame('Dachdämmung', $restored['baseline_events'][0]['label']);
    }

    public function testMigrationIsAdditiveAndIdempotent(): void
    {
        // Feld entfernen, als käme der Bestand aus Schema 1.3.0.
        $meters = $this->store->read('gas/meters.json', []);
        unset($meters[0]['baseline_events']);
        $this->store->write('gas/meters.json', $meters);

        $migrator = new Migrator($this->store);
        self::assertTrue($migrator->needsV140Upgrade());
        $migrator->upgradeToV140();

        $after = $this->store->read('gas/meters.json', []);
        self::assertSame([], $after[0]['baseline_events']);
        self::assertFalse($migrator->needsV140Upgrade(), 'zweiter Lauf findet nichts mehr');

        $before = $this->store->read('gas/meters.json', []);
        $migrator->upgradeToV140();
        self::assertSame($before, $this->store->read('gas/meters.json', []), 'idempotent');
    }

    // ── Hilfsmittel ───────────────────────────────────────────────────

    /** @return array<string,array<string,mixed>> */
    private function byYm(array $monthly): array
    {
        $out = [];
        foreach ($monthly as $m) $out[(string)$m['ym']] = $m;
        return $out;
    }
}
