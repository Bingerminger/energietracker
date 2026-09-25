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

    private function benchmark(?ClimateNormalService $climate = null): BenchmarkService
    {
        return new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n, $climate);
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
}
