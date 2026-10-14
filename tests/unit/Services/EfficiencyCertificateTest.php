<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BenchmarkService;
use Energietracker\Services\ClimateNormalService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.10.0 — Effizienz „zwei Zahlen" (Review CALC-07).
 *
 * Bis v2.9 gab es eine Zahl: Verbrauch je m² Wohnfläche, Gas nach Brennwert,
 * ungeachtet des Wetters, Grenzen „unter" statt „bis" — und für ein halbes
 * Jahr eine Traumklasse („Daten ab 01.06. → 43 → A"). Jetzt: die
 * Hauskennzahl wie bisher, Klassen nur für ganze Jahre, und daneben eine
 * energieausweis-nahe Kennzahl (Heizwert, Gebäudenutzfläche,
 * witterungsbereinigt, Warmwasser-Zuschlag).
 */
#[CoversClass(BenchmarkService::class)]
final class EfficiencyCertificateTest extends ServiceTestCase
{
    /** Monatliche Stände 2024 ab `$fromMonth`, `$step` Einheiten je Monat. */
    private function seed(string $utility, float $step, int $fromMonth = 1, array $meterExtra = []): string
    {
        $id = $this->setMeterDevices($utility, [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2023-12-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        if ($meterExtra) {
            $all = $this->store->read("$utility/meters.json", []);
            $all[0] = array_merge($all[0], $meterExtra);
            $this->store->write("$utility/meters.json", $all);
        }
        $rows = [];
        for ($i = $fromMonth - 1; $i <= 12; $i++) {
            $rows[] = ['date' => (new \DateTimeImmutable('2024-01-01'))->modify("+$i months")->format('Y-m-d'),
                       'counter' => 1000.0 + ($i - $fromMonth + 1) * $step, 'device_id' => 'd1'];
        }
        $this->setReadings($utility, $id, $rows);
        return $id;
    }

    /**
     * v3.5.0 — der Rechentag bestimmt das Verfahren (CERT_RULES); die Tests
     * von v2.10.0 rechnen am 13.10.2026, also nach § 82 GEG bis 2026.
     */
    private function benchmark(?ClimateNormalService $climate = null, string $today = '2026-10-13'): BenchmarkService
    {
        return new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n, $climate, $today);
    }

    /** Monatliche Stände von `$from` bis `$to` (je Monatserster), `$step` je Monat. */
    private function seedRange(string $utility, float $step, string $from, string $to): void
    {
        $id = $this->setMeterDevices($utility, [[
            'id' => 'd1', 'serial' => null, 'installed_on' => $from, 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [];
        for ($d = new \DateTimeImmutable($from), $i = 0; $d->format('Y-m-d') <= $to; $d = $d->modify('+1 month'), $i++) {
            $rows[] = ['date' => $d->format('Y-m-d'), 'counter' => 1000.0 + $i * $step, 'device_id' => 'd1'];
        }
        $this->setReadings($utility, $id, $rows);
    }

    private function gasFactor(float $f): void
    {
        $this->settings->set(['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => $f]]]);
    }

    public function testAPartialYearGetsNoClass(): void
    {
        $this->gasFactor(10.0);
        $this->seed('gas', 100.0, 6);        // ab Juni: 7 Monate
        $this->settings->set(['wohnflaeche_m2' => 100]);

        $eff = $this->benchmark()->efficiency(2024);
        $gas = $eff['per_source'][0];
        self::assertFalse($gas['complete']);
        self::assertNull($gas['class'], 'bis v2.9: ein halbes Jahr ergab eine Traumklasse');
        self::assertNull($eff['combined']['class']);
        self::assertNotNull($eff['note']);
        self::assertFalse($eff['certificate']['complete']);
        self::assertNull($eff['certificate']['class']);
    }

    public function testClassBoundsIncludeTheLimit(): void
    {
        $this->gasFactor(10.0);
        $this->seed('gas', 100.0);            // 12 × 100 m³ × 10 = 12.000 kWh
        $this->settings->set(['wohnflaeche_m2' => 120]);   // genau 100 kWh/m²·a

        $eff = $this->benchmark()->efficiency(2024);
        self::assertTrue($eff['per_source'][0]['complete']);
        self::assertEqualsWithDelta(100.0, $eff['per_source'][0]['kwh_per_m2'], 0.05);
        self::assertSame('C', $eff['per_source'][0]['class'], 'GEG Anlage 10: „bis 100" ist C (bis v2.9: D)');
    }

    public function testTheCertificateFigureUsesNetCalorificValueAndUsableArea(): void
    {
        $this->gasFactor(10.0);
        $this->seed('gas', 100.0);            // 12.000 kWh (Brennwert)
        $this->settings->set(['wohnflaeche_m2' => 100, 'gebaeudetyp' => 'efh']);

        $c = $this->benchmark()->efficiency(2024)['certificate'];
        self::assertSame(1.2, $c['area_factor']);
        self::assertEqualsWithDelta(12000 * 0.906 / 120, $c['kwh_per_m2'], 0.1);
        self::assertFalse($c['weather_adjusted'], 'ohne Temperaturen und Klimanormal: wie gemessen, gekennzeichnet');

        $this->settings->set(['beheizter_keller' => true, 'warmwasser_dezentral' => true]);
        $c = $this->benchmark()->efficiency(2024)['certificate'];
        self::assertSame(1.35, $c['area_factor'], 'Einfamilienhaus mit beheiztem Keller');
        self::assertEqualsWithDelta(12000 * 0.906 / 135 + 20, $c['kwh_per_m2'], 0.1, 'plus Warmwasser-Zuschlag');

        $this->settings->set(['gebaeudetyp' => 'mfh']);
        self::assertSame(1.2, $this->benchmark()->efficiency(2024)['certificate']['area_factor'], '1,35 nur bis zwei Wohneinheiten');
    }

    public function testAHeatPumpMeterCountsAsHeatSource(): void
    {
        $this->seed('strom', 400.0, 1, ['heat_source' => true]);
        $this->settings->set(['wohnflaeche_m2' => 100]);
        $eff = $this->benchmark()->efficiency(2024);
        self::assertSame(['strom'], array_column($eff['per_source'], 'utility'), 'bis v2.9 bekam ein Wärmepumpenhaus keine Kennzahl');

        $all = $this->store->read('strom/meters.json', []);
        unset($all[0]['heat_source']);
        $this->store->write('strom/meters.json', $all);
        self::assertSame([], $this->benchmark()->efficiency(2024)['per_source'], 'normaler Haushaltsstrom zählt nicht');
    }

    public function testHeatSourceNeedsAnExplicitYes(): void
    {
        // Formulare und Skripte schicken auch Zeichenketten: "false" ist ein Nein
        // (bis zur Prüfung vor dem Release schaltete `!empty()` es ein)
        $id = $this->seed('strom', 400.0);
        foreach ([['false', false], ['0', false], ['', false], ['no', false], ['true', true], ['on', true], ['1', true]] as [$in, $want]) {
            $m = $this->meters->update('strom', $id, ['heat_source' => $in]);
            self::assertSame($want, !empty($m['heat_source']), "heat_source \"$in\"");
        }
        $m = $this->meters->update('strom', $id, ['heat_source' => false]);
        self::assertArrayNotHasKey('heat_source', $m);
    }

    public function testDeliveryAndHeatPumpYearsAreWeatherAdjustedWithTheClimateNormal(): void
    {
        // 2024 mild: 10 °C an jedem Tag (5 HGT/Tag); Normaljahr kälter: 5 °C (10 HGT/Tag)
        $temps = [];
        for ($d = '2024-01-01'; $d <= '2024-12-31'; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $temps[$d] = ['avg' => 10.0, 'min' => 7.0, 'max' => 13.0, 'source' => 'csv'];
        }
        $this->store->write('temperatures.json', $temps);
        $daily = [];
        for ($d = '2020-01-01'; $d <= '2021-12-31'; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $daily[$d] = 5.0;
        $climate = new ClimateNormalService($this->store, $this->settings);
        $climate->save(ClimateNormalService::compute($daily, 51.3, 12.4, ['from' => '2020-01-01', 'to' => '2021-12-31']));

        $this->seed('strom', 400.0, 1, ['heat_source' => true]);
        $this->settings->set(['wohnflaeche_m2' => 100]);
        $eff = $this->benchmark($climate)->efficiency(2024);
        $raw = $eff['per_source'][0]['kwh'];
        $c = $eff['certificate'];
        self::assertTrue($c['weather_adjusted']);
        // nur der Heizanteil (85 %) wird mit HGT_normal / HGT_ist = 2 umgerechnet
        self::assertEqualsWithDelta($raw * (0.15 + 0.85 * 2.0) / 120, $c['kwh_per_m2'], 0.5);
    }

    // ── v3.5.0 — GModG Art. 2 ab 01.01.2027: Verfahren nach dem Rechentag ──

    public function testTheProcedureFollowsTheDayOfCalculation(): void
    {
        self::assertSame('geg2024', BenchmarkService::certRules('2026-12-31'));
        self::assertSame('gmodg2027', BenchmarkService::certRules('2027-01-01'), 'BGBl. 2026 I Nr. 226, Art. 9 Abs. 2');
        self::assertSame('gmodg2027', BenchmarkService::nextCertRules('2026-12-31'));
        self::assertNull(BenchmarkService::nextCertRules('2027-01-01'));
    }

    /**
     * Dasselbe Bezugsjahr 2024, zwei Rechentage: bis 2026 Heizwert (Gas × 0,906)
     * und 20 kWh/m²·a Warmwasser, ab 2027 Brennwert (Gas wie gezählt) und 16.
     */
    public function testFrom2027GasCountsWithGrossCalorificValueAndHotWaterWith16(): void
    {
        $this->gasFactor(10.0);
        $this->seed('gas', 100.0);            // 12.000 kWh nach Brennwert
        $this->settings->set(['wohnflaeche_m2' => 100, 'gebaeudetyp' => 'efh']);   // AN = 120 m²

        $old = $this->benchmark(null, '2026-12-31')->efficiency(2024)['certificate'];
        $new = $this->benchmark(null, '2027-01-01')->efficiency(2024)['certificate'];
        self::assertSame(['geg2024', 'hi', 36], [$old['rules'], $old['fuel_basis'], $old['months_required']]);
        self::assertSame(['gmodg2027', 'hs', 24], [$new['rules'], $new['fuel_basis'], $new['months_required']]);
        self::assertEqualsWithDelta(12000 * 0.906 / 120, $old['kwh_per_m2'], 0.05, 'bis 2026: Heizwert');
        self::assertEqualsWithDelta(12000 / 120, $new['kwh_per_m2'], 0.05, 'ab 2027: Brennwert, ohne Umrechnung');
        self::assertSame([], $new['fuel_basis_approx'], 'Gas zählt die App schon nach Brennwert');

        $this->settings->set(['warmwasser_dezentral' => true]);
        $old = $this->benchmark(null, '2026-12-31')->efficiency(2024)['certificate'];
        $new = $this->benchmark(null, '2027-01-01')->efficiency(2024)['certificate'];
        self::assertSame([20.0, 16.0], [$old['dhw_surcharge'], $new['dhw_surcharge']]);
        self::assertSame([20.0, 16.0], [$old['dhw_rule'], $new['dhw_rule']]);
        self::assertEqualsWithDelta(12000 * 0.906 / 120 + 20, $old['kwh_per_m2'], 0.05);
        self::assertEqualsWithDelta(12000 / 120 + 16, $new['kwh_per_m2'], 0.05);

        // Fernwärme ist kein Brennstoff: in beiden Verfahren wie gemessen
        $this->store->write('gas/readings.json', []);
        $this->seed('fernwaerme', 1000.0);    // 12.000 kWh
        $this->settings->set(['warmwasser_dezentral' => false]);
        self::assertEqualsWithDelta(100.0, $this->benchmark(null, '2026-12-31')->efficiency(2024)['certificate']['kwh_per_m2'], 0.05);
        self::assertEqualsWithDelta(100.0, $this->benchmark(null, '2027-01-01')->efficiency(2024)['certificate']['kwh_per_m2'], 0.05);
    }

    /** Ausweis „jährlich über einen Zeitraum von zwei Jahren“ statt 36 Monate; months_36 behält seine Bedeutung. */
    public function testTwoYearsInsteadOf36Months(): void
    {
        $this->gasFactor(10.0);
        $this->seedRange('gas', 100.0, '2022-01-01', '2025-01-01');   // 36 Monate 2022–2024
        $this->settings->set(['wohnflaeche_m2' => 100]);

        $old = $this->benchmark(null, '2026-06-30')->efficiency(2024)['certificate'];
        $new = $this->benchmark(null, '2027-06-30')->efficiency(2024)['certificate'];
        self::assertSame([36, 36, 36], [$old['months_36'], $old['months_required'], $old['months_in_window']]);
        self::assertSame([36, 24, 24], [$new['months_36'], $new['months_required'], $new['months_in_window']],
            'Fenster 2023–2024; months_36 zählt weiter 36 Monate');

        // Nur das Bezugsjahr: dem alten Verfahren fehlen 24, dem neuen 12 Monate
        $this->store->write('gas/readings.json', []);
        $this->seed('gas', 100.0);
        $new = $this->benchmark(null, '2027-06-30')->efficiency(2024)['certificate'];
        self::assertSame([12, 24, 12], [$new['months_36'], $new['months_required'], $new['months_in_window']]);
    }

    /** Anlage 10 bleibt (BGBl. 2026 I Nr. 226, Art. 2 Nr. 54; BT-Drs. 21/6278, S. 122). */
    public function testTheClassBoundsAreTheSameUnderBothProcedures(): void
    {
        $anlage10 = ['A+' => 30, 'A' => 50, 'B' => 75, 'C' => 100, 'D' => 130, 'E' => 160, 'F' => 200, 'G' => 250];
        $defaults = (new \ReflectionClassConstant(\Energietracker\Services\SettingsService::class, 'DEFAULTS'))->getValue();
        self::assertSame($anlage10, $defaults['efficiency_class_thresholds']);

        $this->gasFactor(10.0);
        $this->seed('gas', 100.0);            // 12.000 kWh
        $this->settings->set(['wohnflaeche_m2' => 100]);   // ab 2027: genau 100 kWh/m²·a
        $old = $this->benchmark(null, '2026-12-31')->efficiency(2024);
        $new = $this->benchmark(null, '2027-01-01')->efficiency(2024);
        self::assertSame($old['thresholds'], $new['thresholds']);
        self::assertSame('C', $new['certificate']['class'], '„bis 100“ ist C, auch nach neuem Verfahren');
        self::assertSame($old['per_source'], $new['per_source'], 'die Hauskennzahl hängt nicht am Verfahren');

        $this->gasFactor(10.02);              // 100,2 → D
        self::assertSame('D', $this->benchmark(null, '2027-01-01')->efficiency(2024)['certificate']['class']);

        // Gleiche Grenzen, andere Zahl: 105 nach Brennwert (D), 95,1 nach Heizwert (C)
        $this->gasFactor(10.5);
        self::assertSame('C', $this->benchmark(null, '2026-12-31')->efficiency(2024)['certificate']['class']);
        self::assertSame('D', $this->benchmark(null, '2027-01-01')->efficiency(2024)['certificate']['class']);
    }

    /** Vor 2027 sagt die Kennzahl, was sie nach neuem Verfahren wäre; danach nicht mehr. */
    public function testBefore2027TheUpcomingProcedureIsPreviewed(): void
    {
        $this->gasFactor(10.0);
        $this->seed('gas', 100.0);
        $this->settings->set(['wohnflaeche_m2' => 100, 'warmwasser_dezentral' => true]);

        $c = $this->benchmark(null, '2026-10-13')->efficiency(2024)['certificate'];
        $up = $c['upcoming'];
        self::assertIsArray($up);
        self::assertSame(['2027-01-01', 'gmodg2027', 'hs', 16.0, 24], [$up['from'], $up['rules'], $up['fuel_basis'], $up['dhw_rule'], $up['months_required']]);
        $now2027 = $this->benchmark(null, '2027-01-01')->efficiency(2024)['certificate'];
        self::assertSame($now2027['kwh_per_m2'], $up['kwh_per_m2'], 'die Vorschau rechnet wie das Verfahren ab 2027');
        self::assertSame($now2027['class'], $up['class']);
        self::assertNull($now2027['upcoming'], 'ab 2027 gibt es keine Vorschau mehr');
    }

    /** Die Vorschau nennt deutsches Recht — ohne deutsche Skala (Österreich) gibt es sie nicht. */
    public function testThePreviewOnlyAppearsWithTheGermanScale(): void
    {
        $this->gasFactor(10.0);
        $this->seed('gas', 100.0);
        $this->settings->set(['wohnflaeche_m2' => 100, 'country' => 'AT']);

        $eff = $this->benchmark(null, '2026-10-13')->efficiency(2024);
        self::assertNull($eff['scale']);
        self::assertNull($eff['certificate']['upcoming']);
    }

    /**
     * Heizöl und Pellets zählt die App nach Heizwert. Für den Brennwert fehlt ein
     * frei zugänglicher Faktor (DIN V 18599-1): Sie bleiben beim Heizwert und sind
     * ab 2027 als Näherung gekennzeichnet — keine erfundene Zahl.
     */
    public function testHeatingOilStaysOnNetCalorificValueAsAnApproximation(): void
    {
        $deliveries = new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n);
        $tank = $this->meters->create('heizoel', ['name' => 'Tank', 'capacity' => 3000.0, 'initial_stock' => 2000.0, 'installed_on' => '2024-01-01']);
        $deliveries->create('heizoel', ['meter_id' => $tank['id'], 'date' => '2025-01-01', 'quantity' => 1500.0,
            'unit_price_cents' => 100.0, 'fill_to_full' => true]);
        $this->settings->set(['wohnflaeche_m2' => 100]);

        $old = $this->benchmark(null, '2026-12-31')->efficiency(2024)['certificate'];
        $new = $this->benchmark(null, '2027-01-01')->efficiency(2024)['certificate'];
        self::assertGreaterThan(0, $old['kwh']);
        self::assertSame($old['kwh'], $new['kwh'], 'Heizöl ohne Umrechnung in beiden Verfahren');
        self::assertSame([], $old['fuel_basis_approx'], 'bis 2026 verlangt der Ausweis den Heizwert');
        self::assertSame(['heizoel'], $new['fuel_basis_approx']);
    }

    /** Doku-Behauptungen brauchen Tests: Grundlagen §7 und API-Referenz (DE, EN) nennen jedes Verfahren mit seinen Werten. */
    public function testTheDocsNameEveryProcedureWithItsValues(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['docs/verstehen/00-overview.md', 'docs/en/verstehen/00-overview.md'] as $rel) {
            $doc = (string)file_get_contents("$root/$rel");
            foreach (BenchmarkService::CERT_RULES as $key => $r) {
                self::assertStringContainsString("($key)", $doc, "$rel: Verfahren $key fehlt");
                self::assertStringContainsString('+ ' . (int)$r['dhw'] . ' kWh/m²·a', $doc, "$rel: Warmwasser-Zuschlag von $key");
            }
        }
        foreach (['docs/referenz/api.md', 'docs/en/referenz/api.md'] as $rel) {
            $doc = (string)file_get_contents("$root/$rel");
            $months = implode(' | ', array_map(fn($r) => (string)$r['months'], array_values(BenchmarkService::CERT_RULES)));
            self::assertMatchesRegularExpression('/\| `rules` \| `' . implode('` \| `', array_keys(BenchmarkService::CERT_RULES)) . '` \|/', $doc, "$rel: Tabelle der Verfahren");
            self::assertMatchesRegularExpression('/\| `months_required` \| ' . preg_quote($months, '/') . '\b/', $doc, "$rel: verlangte Monate");
        }
    }

    /** Der Jahresbericht liefert dieselbe Zahl wie GET /api/benchmarks/efficiency — vor und nach dem Wechsel. */
    public function testTheYearlyReportShowsTheSameFigureAsTheApi(): void
    {
        $this->gasFactor(10.0);
        $this->seed('gas', 100.0);
        $this->settings->set(['wohnflaeche_m2' => 100]);

        $pages = [];
        foreach (['2026-10-13', '2027-01-01'] as $today) {
            $benchmark = $this->benchmark(null, $today);
            $deliveries = new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n);
            $recs = new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings, $benchmark, $deliveries, $this->i18n);
            $report = new \Energietracker\Services\PdfReportService($this->meters, $this->consumption, $this->settings, $benchmark, $recs, $this->i18n);
            $data = $report->yearlyData(2024);
            $api = $benchmark->efficiency(2024);
            self::assertSame($api['certificate'], $data['efficiency']['certificate'], "Rechentag $today");
            $gas = array_values(array_filter($data['model']['pages'], fn($p) => $p['utility'] === 'gas'))[0];
            self::assertSame($api['per_source'][0]['kwh_per_m2'], $gas['efficiency']['kwh_per_m2'], 'PDF: Hauskennzahl wie die API');
            $pages[] = $gas['efficiency'];
        }
        self::assertSame($pages[0], $pages[1], 'die Seite im PDF ändert sich mit dem Verfahren nicht');
    }
}
