<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Countries;
use Energietracker\Services\Co2CostService;
use Energietracker\Services\Co2SplitService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\RegressionService;
use Energietracker\Services\TenancyService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.1.0 (Paket H4) — CO₂-Preis im Brennstoff (CALC-27), Aufteilung zwischen
 * Mieter und Vermieter (MKT-15), Preisszenario in der Prognose (MKT-26).
 */
#[CoversClass(Co2CostService::class)]
#[CoversClass(Co2SplitService::class)]
final class Co2CostTest extends ServiceTestCase
{
    private TenancyService $ten;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ten = new TenancyService($this->store, $this->meters, $this->i18n);
    }

    private function costs(): Co2CostService
    {
        return new Co2CostService($this->store, $this->settings, $this->meters, $this->consumption, $this->contracts, $this->i18n, $this->ten);
    }

    private function split(): Co2SplitService
    {
        return new Co2SplitService($this->costs(), $this->ten, $this->settings, $this->i18n);
    }

    /** Gas: Jahresverbrauch in kWh über m³-Stände (Faktor wie eingestellt). */
    private function gasYear(int $year, float $kwh): void
    {
        $f = (new \Energietracker\Services\ConversionFactorService($this->settings, $this->i18n))->factorOn('gas', "$year-06-01");
        $this->setReadings('gas', (string)$this->meters->defaultId('gas'), [
            ['date' => "$year-01-01", 'counter' => 1000],
            ['date' => ($year + 1) . '-01-01', 'counter' => 1000 + $kwh / $f],
        ]);
    }

    public static function stages(): array
    {
        return [[11.9, 1, 0], [11.94, 1, 0], [11.95, 2, 10], [12.0, 2, 10], [16.9, 2, 10], [17.0, 3, 20],
                [28.6, 5, 40], [51.9, 9, 80], [52.0, 10, 95], [120.0, 10, 95]];
    }

    #[DataProvider('stages')]
    public function testStageBoundariesAfterRoundingToOneDecimal(float $kg, int $stage, int $share): void
    {
        self::assertSame([$stage, $share], Co2CostService::stage($kg));
    }

    public function testShorterPeriodsScaleTheBounds(): void
    {
        // halbes Jahr: Grenze 12 → 6
        self::assertSame([2, 10], Co2CostService::stage(6.0, 182));
        self::assertSame([1, 0], Co2CostService::stage(5.9, 182));
    }

    public function testGasFormulaNetAndGross(): void
    {
        $this->gasYear(2025, 10000);
        $r = $this->costs()->forYear(2025);
        self::assertTrue($r['supported']);
        $gas = array_values(array_filter($r['rows'], fn($x) => $x['utility'] === 'gas'))[0];
        self::assertEqualsWithDelta(10000, $gas['kwh'], 1);
        self::assertEqualsWithDelta(1813.9, $gas['emissions_kg'], 0.2);
        self::assertSame(55.0, $gas['price_eur_t']);
        self::assertEqualsWithDelta(1813.9 / 1000 * 55, $gas['cost_eur_net'], 0.02);
        self::assertEqualsWithDelta(1813.9 / 1000 * 55 * 1.19, $gas['cost_eur_gross'], 0.02);
        self::assertEqualsWithDelta(1.187, $gas['ct_per_kwh'], 0.002);
        self::assertSame('computed', $gas['source']);
        self::assertFalse($r['price']['assumed']);
        self::assertSame([], array_values(array_filter($r['rows'], fn($x) => in_array($x['utility'], ['pellets', 'strom'], true))));
    }

    public function testTheBillWinsAndUnknownYearsAreAssumed(): void
    {
        $this->gasYear(2025, 10000);
        $this->store->write('gas/bills.json', [['id' => 'b1', 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'co2' => ['emissions_kg' => 1800, 'cost_eur' => 99.0]]]);
        $gas = array_values(array_filter($this->costs()->forYear(2025)['rows'], fn($x) => $x['utility'] === 'gas'))[0];
        self::assertSame('bill', $gas['source']);
        self::assertSame(1800.0, $gas['emissions_kg']);
        // v3.2.0 — die Rechnung nennt den Betrag mit Umsatzsteuer (§ 3 Abs. 3 CO2KostAufG): kein zweiter Aufschlag
        self::assertSame(99.0, $gas['cost_eur_gross']);
        self::assertEqualsWithDelta(99.0 / 1.19, $gas['cost_eur_net'], 0.01);

        $p = Co2CostService::priceForYear($this->settings, 2027);
        self::assertSame(['eur_t' => 60.0, 'assumed' => true], $p);
        $this->settings->set(['co2_price_eur_t_years' => ['2027' => '65']]);
        self::assertSame(['eur_t' => 65.0, 'assumed' => false], Co2CostService::priceForYear($this->settings, 2027));
    }

    /** v3.2.0 — 7 % Umsatzsteuer auf Gas vom 01.10.2022 bis 31.03.2024 (§ 28 UStG), danach wieder 19 %. */
    public function testTheReducedVatOnGasCountsMonthByMonth(): void
    {
        self::assertSame(0.07, Countries::co2Vat('behg', 'gas', '2023-06'));
        self::assertSame(0.07, Countries::co2Vat('behg', 'fernwaerme', '2024-03'));
        self::assertSame(0.19, Countries::co2Vat('behg', 'gas', '2024-04'));
        self::assertSame(0.19, Countries::co2Vat('behg', 'heizoel', '2023-06'), 'Heizöl war nicht ermäßigt');
        $this->gasYear(2023, 10000);
        $gas = array_values(array_filter($this->costs()->forYear(2023)['rows'], fn($x) => $x['utility'] === 'gas'))[0];
        self::assertSame(0.07, $gas['vat']);
        self::assertEqualsWithDelta($gas['cost_eur_net'] * 1.07, $gas['cost_eur_gross'], 0.02);

        // Heizwärme: der Satz des Energieträgers dahinter
        $heat = (string)$this->meters->create('waerme', ['name' => 'Heizwärme', 'installed_on' => '2023-01-01'])['id'];
        $this->setReadings('waerme', $heat, [['date' => '2023-01-01', 'counter' => 0], ['date' => '2024-01-01', 'counter' => 8000]]);
        $row = fn() => array_values(array_filter($this->costs()->forYear(2023)['rows'], fn($x) => $x['utility'] === 'waerme'))[0];
        $this->settings->set(['waerme_energietraeger' => 'gas']);
        self::assertSame(0.07, $row()['vat']);
        $this->settings->set(['waerme_energietraeger' => 'heizoel']);
        self::assertSame(0.19, $row()['vat']);
    }

    public function testCountriesWithoutSchemeSayNo(): void
    {
        $this->settings->set(['country' => 'AT']);
        $r = $this->costs()->forYear(2025);
        self::assertFalse($r['supported']);
        self::assertNotEmpty($r['note']);
        self::assertNull(Co2CostService::scenarioDeltaCtPerKwh($this->settings, 'gas', 150, 2026));
    }

    /** Recherche-Beispiel (Preis 60 €/t): 12.000 kWh Gas, 80 m² → 27,2 kg/m² → Stufe 5, 40 % → 62,17 € (Gasherd 59,06 €). */
    public function testSelfSuppliedTenantWorksOutTheLandlordShare(): void
    {
        $this->settings->set(['wohnverhaeltnis' => 'miete']);
        $t = $this->ten->create(['start' => '2024-01-01', 'wohnflaeche_m2' => 80]);
        $this->settings->set(['co2_price_eur_t_years' => ['2025' => 60]]);
        $this->gasYear(2025, 12000);
        $r = $this->split()->forYear(2025);
        self::assertSame('self_supplied', $r['case']);
        self::assertSame(27.2, $r['kg_per_m2']);
        self::assertSame(5, $r['stage']);
        self::assertSame(40, $r['landlord_share_pct']);
        self::assertEqualsWithDelta(62.17, $r['landlord_amount_eur'], 0.02);
        $this->ten->update($t['id'], ['co2_own_appliances' => true]);
        self::assertEqualsWithDelta(59.06, $this->split()->forYear(2025)['landlord_amount_eur'], 0.02);
    }

    /** H5: Frist = Zugang der Gasrechnung + 12 Monate (§ 6 Abs. 2), in der Agenda ab 30 Tagen vorher „jetzt". */
    public function testClaimDeadlineFromTheGasBillReachesTheAgenda(): void
    {
        $this->settings->set(['wohnverhaeltnis' => 'miete', 'co2_price_eur_t_years' => ['2025' => 60]]);
        $this->ten->create(['start' => '2024-01-01', 'wohnflaeche_m2' => 80]);
        $this->gasYear(2025, 12000);
        self::assertNull($this->split()->forYear(2025)['deadline'], 'ohne Rechnung keine Frist');
        $this->store->write('gas/bills.json', [['id' => 'b1', 'meter_id' => 'x', 'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'issued_on' => '2026-02-15']]);
        self::assertSame('2027-02-15', $this->split()->forYear(2025)['deadline']);

        $recs = new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n),
            new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $agenda = new \Energietracker\Services\AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, new \Energietracker\Services\ReminderService($this->store, $this->settings, $this->i18n), $recs,
            $this->i18n, $this->ten, $this->split());
        $claim = fn(string $today) => array_values(array_filter($agenda->events(90, $today), fn($e) => $e['kind'] === 'co2_claim_deadline'));
        self::assertSame([], $claim('2026-10-01'), 'außerhalb von 90 Tagen');
        $soon = $claim('2027-01-01');
        self::assertCount(1, $soon);
        self::assertSame(['2027-02-15', 'upcoming', false], [$soon[0]['date'], $soon[0]['severity'], $soon[0]['due_now']]);
        self::assertEqualsWithDelta(62.17, $soon[0]['ref']['landlord_amount_eur'], 0.02);
        self::assertTrue($claim('2027-01-20')[0]['due_now']);
        self::assertSame([], $claim('2027-02-16'), 'abgelaufen');
    }

    /** Spezifikation MKT-15: 2.000 kg bei 70 m² → 28,6 → Stufe 5, 40 %; 300 € → 120 €. */
    public function testCentralHeatingIsRecalculatedAndDeviationsNamed(): void
    {
        $this->settings->set(['wohnverhaeltnis' => 'miete']);
        $t = $this->ten->create(['start' => '2020-01-01', 'wohnflaeche_m2' => 70]);
        $this->ten->createStatement($t['id'], ['period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'total_cost_eur' => 1000, 'prepaid_eur' => 900,
            'co2' => ['emissions_kg' => 2000, 'cost_eur' => 300, 'stage' => 4, 'landlord_share_pct' => 30, 'landlord_amount_eur' => 90]]);
        $r = $this->split()->forYear(2025);
        self::assertSame('central', $r['case']);
        self::assertSame(28.6, $r['kg_per_m2']);
        self::assertSame(5, $r['stage']);
        self::assertSame(40, $r['landlord_share_pct']);
        self::assertSame(120.0, $r['landlord_amount_eur']);
        self::assertSame(['stage', 'share', 'amount'], $r['checks']);
        $pdf = $this->split()->letterPdf(2025);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('120', $pdf);
    }

    public function testOwnersSeeNoSplit(): void
    {
        self::assertFalse($this->split()->forYear(2025)['supported']);
    }

    public function testTheScenarioIsOnlyAnAddOnToTheForecast(): void
    {
        $id = (string)$this->meters->defaultId('gas');
        $rows = [];
        $c = 1000.0;
        for ($i = 0; $i <= 24; $i++) {
            $d = (new \DateTimeImmutable('first day of this month'))->modify('-' . (24 - $i) . ' months')->format('Y-m-d');
            $rows[] = ['date' => $d, 'counter' => $c];
            $c += 120;
        }
        $this->setReadings('gas', $id, $rows);
        $fc = new ForecastService($this->consumption, new RegressionService(), $this->settings, $this->contracts, $this->i18n);
        $meter = $this->meters->get('gas', $id);
        $off = $fc->forMeter('gas', $meter);
        self::assertNull($off['co2_scenario']);
        $on = $fc->forMeter('gas', $meter, ['co2_scenario_eur_t' => 150, 'co2_scenario_from' => 2021]);
        self::assertSame(array_column($off['forecast'], 'cost_estimated'), array_column($on['forecast'], 'cost_estimated'), 'Kosten unverändert');
        $f0 = $on['forecast'][0];
        $price = Co2CostService::priceForYear($this->settings, (int)$f0['year'])['eur_t'];
        $ct = (150 - $price) * 0.18139 / 10 * 1.19;
        self::assertEqualsWithDelta($f0['kwh'] * $ct / 100, $f0['co2_delta_eur'], 0.02);
        self::assertSame(150.0, $on['co2_scenario']['eur_t']);
        self::assertEqualsWithDelta(array_sum(array_column(array_slice($on['forecast'], 0, 12), 'co2_delta_eur')), $on['co2_scenario']['delta_cost_12m_eur'], 0.05);
    }
}
