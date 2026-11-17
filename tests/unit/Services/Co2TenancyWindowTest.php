<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillService;
use Energietracker\Services\Co2CostService;
use Energietracker\Services\Co2SplitService;
use Energietracker\Services\TenancyService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — CO₂-Aufteilung bei eigener Etagenheizung im Teiljahr: Grenzen und Ausstoß über denselben Zeitraum
 * (§ 5 Abs. 1 Satz 4 und 5, Abs. 3 Satz 4 CO2KostAufG, Wortlaut 2026-11-13: „Ist ein Abrechnungszeitraum von unter einem
 * Jahr vereinbart, so sind die Werte der Einstufungstabelle in der Anlage anteilig zu kürzen. Weichen die
 * Abrechnungszeiträume der Brennstoff- oder Wärmelieferungen von den … vereinbarten Abrechnungszeiträumen ab, sind die
 * auf den Rechnungen ausgewiesenen Brennstoffemissionen auf den vereinbarten Zeitraum umzurechnen.“). Bis v3.12 kürzte
 * die App die Grenzen auf die Miettage, nahm Ausstoß und Kosten aber aus dem ganzen Kalenderjahr, auch aus Ständen vor
 * dem Einzug oder nach dem Auszug.
 *
 * Erfundener Haushalt 2025 (kein Schaltjahr), Miete, 75 m², Gas 10 kWh/m³, Faktor 0,18139 kg/kWh, Preis 55 €/t, 19 %.
 * Gasstände (m³): 01.01. 1.000 · 01.05. 1.500 · 01.07. 1.600 · 01.11. 1.800 · 01.01.2026 2.100 (Jahr 1.100 m³).
 * Von Hand (Grenzen der Stufen × Tage ÷ 365, Wert auf eine Stelle gerundet):
 *   Einzug 01.07.: 184 Tage, 500 m³ = 5.000 kWh × 0,18139 = 906,95 kg ÷ 75 = 12,1; 22 × 184/365 = 11,09,
 *     27 × 184/365 = 13,61 → Stufe 4, 30 %. Kosten 0,90695 t × 55 € × 1,19 = 59,36 €, Vermieter 30 % = 17,81 €.
 *     Bis v3.12: 1.100 m³ → 1.995,29 kg ÷ 75 = 26,6 ≥ 52 × 184/365 = 26,21 → Stufe 10, 95 %.
 *   Auszug 30.04.: 120 Tage, 500 m³ → 12,1; 32 × 120/365 = 10,52, 37 × 120/365 = 12,16 → Stufe 6, 50 %.
 *   Einzug 01.05., Auszug 31.10.: 184 Tage, 300 m³ = 3.000 kWh → 544,17 kg ÷ 75 = 7,3; 12 × 184/365 = 6,05,
 *     17 × 184/365 = 8,57 → Stufe 2, 10 %.
 *   ganzes Jahr: 26,6 < 27 → Stufe 4, 30 % (unverändert).
 * Heizöl (150 m², 10 kWh/l, 0,2664 kg/kWh): Tank ab 01.01.2025 mit 2.000 l, Stand 01.07. 1.400 l, 01.01.2026 600 l.
 *   Einzug 01.07.: 800 l = 8.000 kWh × 0,2664 = 2.131,2 kg ÷ 150 = 14,2; 27 × 184/365 = 13,61, 32 × 184/365 = 16,13 →
 *   Stufe 5, 40 %. Bis v3.12: 1.400 l → 3.729,6 kg ÷ 150 = 24,9 ≥ 47 × 184/365 = 23,69 → Stufe 9, 80 %.
 */
#[CoversClass(Co2SplitService::class)]
#[CoversClass(Co2CostService::class)]
final class Co2TenancyWindowTest extends ServiceTestCase
{
    private TenancyService $ten;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ten = new TenancyService($this->store, $this->meters, $this->i18n);
        $this->settings->set(['wohnverhaeltnis' => 'miete', 'country' => 'DE', 'gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.0]],
            'heizoel_kwh_per_l' => 10.0]);
    }

    private function costs(): Co2CostService
    {
        return new Co2CostService($this->store, $this->settings, $this->meters, $this->consumption, $this->contracts, $this->i18n, $this->ten);
    }

    /** @return array<string,mixed> */
    private function split(): array
    {
        return (new Co2SplitService($this->costs(), $this->ten, $this->settings, $this->i18n))->forYear(2025);
    }

    private function gas(): void
    {
        $this->setReadings('gas', (string)$this->meters->defaultId('gas'), [
            ['date' => '2025-01-01', 'counter' => 1000.0], ['date' => '2025-05-01', 'counter' => 1500.0], ['date' => '2025-07-01', 'counter' => 1600.0],
            ['date' => '2025-11-01', 'counter' => 1800.0], ['date' => '2026-01-01', 'counter' => 2100.0],
        ]);
    }

    /** @return array<string, array{array<string,mixed>, int, float, int, int}> Mietverhältnis, Tage, kg/m², Stufe, Anteil */
    public static function tenancies(): array
    {
        return [
            'Einzug 01.07.'               => [['start' => '2025-07-01'], 184, 12.1, 4, 30],
            'Auszug 30.04.'               => [['start' => '2024-01-01', 'end' => '2025-04-30'], 120, 12.1, 6, 50],
            'Einzug 01.05., Auszug 31.10.' => [['start' => '2025-05-01', 'end' => '2025-10-31'], 184, 7.3, 2, 10],
            'ganzes Jahr'                 => [['start' => '2024-01-01'], 365, 26.6, 4, 30],
        ];
    }

    #[DataProvider('tenancies')]
    public function testGasEmissionsCountOnlyOnTheDaysOfTheTenancy(array $tenancy, int $days, float $kg, int $stage, int $share): void
    {
        $this->gas();
        $this->ten->create($tenancy + ['wohnflaeche_m2' => 75, 'billing_anchor' => '01-01']);
        $r = $this->split();
        self::assertSame(['self_supplied', $days, $kg, $stage, $share], [$r['case'], $r['days'], $r['kg_per_m2'], $r['stage'], $r['landlord_share_pct']]);
        // Ansicht gegen Ansicht: die Kennzahl je m² der CO₂-Kosten hat denselben Wert und dieselbe Stufe
        $perM2 = $this->costs()->forYear(2025)['per_m2'];
        self::assertSame([$kg, $stage, $share, $days], [$perM2['kg'], $perM2['stage'], $perM2['landlord_share_pct'], $perM2['days']]);
    }

    public function testTheAmountIsTheCostOfTheTenancyDays(): void
    {
        $this->gas();
        $this->ten->create(['start' => '2025-07-01', 'wohnflaeche_m2' => 75, 'billing_anchor' => '01-01']);
        $r = $this->split();
        // 906,95 kg, auf eine Stelle (im Gleitkomma 906,9499…)
        self::assertEqualsWithDelta(906.95, $r['emissions_kg'], 0.051);
        self::assertSame([59.36, 17.81], [$r['co2_cost_eur'], $r['landlord_amount_eur']]);
        // die Seite der CO₂-Kosten zeigt weiter das ganze Jahr, die Kennzahl nennt den Ausstoß des Mietzeitraums
        $all = $this->costs()->forYear(2025);
        self::assertEqualsWithDelta(1995.29, $all['total']['emissions_kg'], 0.051, '11.000 kWh × 0,18139');
        self::assertSame([$r['emissions_kg'], '2025-07-01', '2025-12-31'], [$all['per_m2']['emissions_kg'], $all['per_m2']['from'], $all['per_m2']['to']]);
    }

    /** Stände erst ab dem Einzug: dasselbe Ergebnis wie mit Ständen davor (der Zähler zählt ab Einzug). */
    public function testReadingsBeforeMovingInChangeNothing(): void
    {
        $this->setReadings('gas', (string)$this->meters->defaultId('gas'), [
            ['date' => '2025-07-01', 'counter' => 1600.0], ['date' => '2025-11-01', 'counter' => 1800.0], ['date' => '2026-01-01', 'counter' => 2100.0]]);
        $this->ten->create(['start' => '2025-07-01', 'wohnflaeche_m2' => 75, 'billing_anchor' => '01-01']);
        $only = $this->split();
        $this->gas();
        $with = $this->split();
        foreach (['days', 'emissions_kg', 'kg_per_m2', 'stage', 'landlord_share_pct', 'co2_cost_eur', 'landlord_amount_eur'] as $k) {
            self::assertSame($only[$k], $with[$k], $k);
        }
    }

    /**
     * Emissionen laut Versorgerrechnung (ganzes Jahr 2025: 2.000 kg, 130 € brutto): auf den Mietzeitraum umgerechnet nach
     * der Menge des Zählers, Einzug 01.07.: 5.000 ÷ 11.000 kWh → 909,09 kg ÷ 75 = 12,1 → Stufe 4; 130 € × 5/11 = 59,09 €.
     */
    public function testEmissionsOnTheBillAreConvertedToTheTenancy(): void
    {
        $this->gas();
        (new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n))->create('gas', [
            'meter_id' => (string)$this->meters->defaultId('gas'), 'kind' => 'annual', 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'issued_on' => '2026-01-20', 'co2' => ['emissions_kg' => 2000.0, 'cost_eur' => 130.0]]);
        $this->ten->create(['start' => '2025-07-01', 'wohnflaeche_m2' => 75, 'billing_anchor' => '01-01']);
        $r = $this->split();
        self::assertSame([909.1, 12.1, 4, 59.09, 'bill'], [$r['emissions_kg'], $r['kg_per_m2'], $r['stage'], $r['co2_cost_eur'], $r['source']]);
    }

    /** Doku (CO₂-Aufteilung → Teiljahr): dieselben Zahlen wie die App, „bisher“ mit dem Ausstoß des ganzen Jahres. */
    public function testTheDocumentedExampleHolds(): void
    {
        $this->gas();
        $this->ten->create(['start' => '2025-07-01', 'wohnflaeche_m2' => 75, 'billing_anchor' => '01-01']);
        $r = $this->split();
        self::assertSame([12.1, 4, 30], [$r['kg_per_m2'], $r['stage'], $r['landlord_share_pct']]);
        $wholeYear = round($this->costs()->forYear(2025)['total']['emissions_kg'] / 75, 1);
        self::assertSame([26.6, [10, 95]], [$wholeYear, Co2CostService::stage($wholeYear, $r['days'])]);
        $root = dirname(__DIR__, 3);
        foreach (['docs/anleitungen/co2-aufteilung.md' => ['75 m²', '1.100 m³', '500 m³', '12,1 kg je m²', 'Stufe 4, 30 %', '26,6 kg je m², Stufe 10, 95 %'],
                  'docs/en/anleitungen/co2-aufteilung.md' => ['75 m²', '1,100 m³', '500 m³', '12.1 kg per m²', 'stage 4, 30 %', '26.6 kg per m², stage 10, 95 %']] as $file => $want) {
            $text = (string)preg_replace('/\s+/u', ' ', (string)file_get_contents("$root/$file"));
            $pos = strpos($text, '<!-- doc-claim: co2-part-year-window -->');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            foreach ($want as $w) self::assertStringContainsString($w, substr($text, $pos, 1200), "$file: $w");
        }
    }

    public function testHeatingOilFollowsTheSameRule(): void
    {
        $tank = $this->meters->create('heizoel', ['name' => 'Tank', 'capacity' => 3000.0, 'initial_stock' => 2000.0, 'installed_on' => '2025-01-01']);
        $all = $this->store->read('heizoel/meters.json', []);
        foreach ($all as &$row) {
            if (($row['id'] ?? '') === $tank['id']) $row['tank_levels'] = [['date' => '2025-07-01', 'level' => 1400.0], ['date' => '2026-01-01', 'level' => 600.0]];
        }
        unset($row);
        $this->store->write('heizoel/meters.json', $all);
        $this->ten->create(['start' => '2025-07-01', 'wohnflaeche_m2' => 150, 'billing_anchor' => '01-01']);
        $r = $this->split();
        self::assertSame([184, 2131.2, 14.2, 5, 40], [$r['days'], $r['emissions_kg'], $r['kg_per_m2'], $r['stage'], $r['landlord_share_pct']]);
        self::assertSame(['heizoel'], $r['utilities']);
    }
}
