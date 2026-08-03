<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\TariffComparisonService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.2.0 — Tarifvergleich.
 *
 * Kernfrage jedes Tests: Bezieht sich JEDE Kennzahl einer Zeile auf genau die
 * Monate, die dieser Vertrag abdeckt? Die v1.3.0-Fassung meldete den
 * Gesamtverbrauch des Zeitraums neben den Kosten eines Teilzeitraums — ein
 * Halbjahrestarif wirkte dadurch doppelt so günstig, wie er ist.
 */
#[CoversClass(TariffComparisonService::class)]
final class TariffComparisonServiceTest extends ServiceTestCase
{
    private TariffComparisonService $tariffs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tariffs = new TariffComparisonService(
            $this->consumption, $this->contracts, $this->meters, $this->i18n
        );
    }

    /**
     * Ein volles Jahr, exakt 100 kWh pro Monat, damit die Erwartungswerte
     * ohne Rundungsdiskussion nachrechenbar sind.
     */
    private function seedYear(int $year = 2024): string
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => ($year - 1) . '-12-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $rows = [];
        for ($i = 0; $i <= 12; $i++) {
            $d = (new \DateTimeImmutable("$year-01-01"))->modify("+$i months");
            $rows[] = [
                'date'      => $d->format('Y-m-d'),
                'counter'   => 1000.0 + $i * 100.0,
                'device_id' => 'd_strom_1',
            ];
        }
        $this->setReadings('strom', $meterId, $rows);
        return $meterId;
    }

    /**
     * Der Kernfall: echter Ganzjahresvertrag (20 ct) gegen einen
     * Schattenvertrag, der erst zur Jahresmitte beginnt (30 ct).
     *
     * Vor v2.2.0 bekam der Schattenvertrag den vollen Jahresverbrauch
     * zugeschrieben und wies dadurch eine Ersparnis aus, obwohl er teurer ist.
     */
    public function testHalfYearShadowReportsOnlyItsOwnMonths(): void
    {
        $meterId = $this->seedYear();

        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'Stadtwerke', 'tariff_name' => 'Basis',
            'start' => '2024-01-01', 'end' => '2024-12-31',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 20.0]],
        ]);
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'tariff_name' => 'Halbjahr', 'shadow_label' => 'Halbjahr',
            'is_shadow' => true, 'start' => '2024-07-01',
            'working_prices' => [['from' => '2024-07-01', 'ct_per_kwh' => 30.0]],
        ]);

        $res = $this->tariffs->compare('strom', $meterId, 2024);
        self::assertTrue($res['supported']);
        $byLabel = array_column($res['rows'], null, 'label');

        $real = $byLabel['Stadtwerke Basis'];
        self::assertTrue($real['covers_full_period'], 'Der echte Vertrag deckt das ganze Jahr ab');
        self::assertEqualsWithDelta(20.0, $real['unit_cost_ct'], 0.01,
            'Ohne Grundpreis ist der Einheitspreis der Arbeitspreis');

        $shadow = $byLabel['Halbjahr'];
        self::assertFalse($shadow['covers_full_period'], 'Der Schattenvertrag deckt nur einen Teil ab');
        self::assertLessThan($real['months_covered'], $shadow['months_covered'],
            'Der Schattenvertrag darf nicht so viele Monate zählen wie der echte');
        self::assertLessThan($real['consumption'], $shadow['consumption'],
            'ENTSCHEIDEND: der Teilzeitraum-Vertrag darf nicht den vollen Jahresverbrauch melden');
        self::assertEqualsWithDelta(30.0, $shadow['unit_cost_ct'], 0.01,
            'Der Einheitspreis macht den teureren Tarif unabhängig von der Laufzeit sichtbar');
        self::assertGreaterThan($real['unit_cost_ct'], $shadow['unit_cost_ct'],
            'Ein teurerer Tarif muss auch als teurer erscheinen');
    }

    /**
     * `vs_real_eur` muss gegen die real abgerechneten Kosten DERSELBEN Monate
     * gehen. Vorher wurde gegen die Summe des ganzen Zeitraums verglichen, was
     * jedem Teilzeitraum-Vertrag eine erfundene Ersparnis bescherte.
     */
    public function testVsRealComparesTheSameMonthsOnly(): void
    {
        $meterId = $this->seedYear();

        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'Stadtwerke', 'tariff_name' => 'Basis',
            'start' => '2024-01-01', 'end' => '2024-12-31',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 20.0]],
        ]);
        // Gleicher Preis wie der echte Vertrag → die Differenz MUSS 0 sein,
        // egal wie kurz die Laufzeit ist.
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'tariff_name' => 'Gleicher Preis', 'shadow_label' => 'Gleicher Preis',
            'is_shadow' => true, 'start' => '2024-10-01',
            'working_prices' => [['from' => '2024-10-01', 'ct_per_kwh' => 20.0]],
        ]);

        $res = $this->tariffs->compare('strom', $meterId, 2024);
        $byLabel = array_column($res['rows'], null, 'label');
        $shadow = $byLabel['Gleicher Preis'];

        self::assertNotNull($shadow['vs_real_eur']);
        self::assertEqualsWithDelta(0.0, $shadow['vs_real_eur'], 0.05,
            'Gleicher Tarif über dieselben Monate ⇒ keine Differenz');
        self::assertEqualsWithDelta(0.0, $shadow['vs_real_pct'], 0.5,
            'Auch prozentual muss die Differenz verschwinden');
    }

    /** Der echte Vertrag ist die Referenz und darf sich nicht selbst schlagen. */
    public function testRealContractHasNoDifferenceAgainstItself(): void
    {
        $meterId = $this->seedYear();
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'Stadtwerke', 'tariff_name' => 'Basis',
            'start' => '2024-01-01', 'end' => '2024-12-31',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 20.0]],
            'base_prices'    => [['from' => '2024-01-01', 'eur_per_month' => 10.0]],
        ]);

        $res = $this->tariffs->compare('strom', $meterId, 2024);
        self::assertCount(1, $res['rows']);
        self::assertEqualsWithDelta(0.0, $res['rows'][0]['vs_real_eur'], 0.05);
    }

    /** Die Hochrechnung skaliert den Teilzeitraum auf die volle Periode. */
    public function testProjectionScalesPartialPeriodToFullPeriod(): void
    {
        $meterId = $this->seedYear();
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'Stadtwerke', 'tariff_name' => 'Basis',
            'start' => '2024-01-01', 'end' => '2024-12-31',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 20.0]],
        ]);
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'tariff_name' => 'Halbjahr', 'shadow_label' => 'Halbjahr',
            'is_shadow' => true, 'start' => '2024-07-01',
            'working_prices' => [['from' => '2024-07-01', 'ct_per_kwh' => 20.0]],
        ]);

        $res = $this->tariffs->compare('strom', $meterId, 2024);
        $byLabel = array_column($res['rows'], null, 'label');
        $shadow = $byLabel['Halbjahr'];
        $real   = $byLabel['Stadtwerke Basis'];

        $ratio = $res['period']['months'] / $shadow['months_covered'];
        self::assertEqualsWithDelta(
            $shadow['total_eur'] * $ratio, $shadow['projected_full_eur'], 0.05,
            'Hochrechnung = Ist-Kosten × (Periodenmonate / abgedeckte Monate)'
        );
        // Gleicher Preis, gleicher Verbrauch pro Monat ⇒ die Hochrechnung des
        // Halbjahrestarifs muss die Ganzjahreskosten treffen.
        self::assertEqualsWithDelta($real['total_eur'], $shadow['projected_full_eur'], 1.0);
    }

    /** Verträge außerhalb des gewählten Zeitraums erzeugen keine Leerzeilen. */
    public function testContractOutsideThePeriodProducesNoRow(): void
    {
        $meterId = $this->seedYear();
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'Stadtwerke', 'tariff_name' => 'Basis',
            'start' => '2024-01-01', 'end' => '2024-12-31',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 20.0]],
        ]);
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'Alt', 'tariff_name' => 'Vorjahr',
            'start' => '2020-01-01', 'end' => '2020-12-31',
            'working_prices' => [['from' => '2020-01-01', 'ct_per_kwh' => 15.0]],
        ]);

        $res = $this->tariffs->compare('strom', $meterId, 2024);
        $labels = array_column($res['rows'], 'label');
        self::assertNotContains('Alt Vorjahr', $labels,
            'Ein Vertrag ohne einen einzigen Monat im Zeitraum gehört nicht in die Tabelle');
    }

    /** Boni und Grundpreis fließen in den Einheitspreis ein. */
    public function testUnitCostIncludesBasePriceAndBonus(): void
    {
        $meterId = $this->seedYear();
        // 1200 kWh/Jahr × 20 ct = 240 €, + 12 × 10 € Grundpreis = 360 €,
        // − 60 € Bonus = 300 € ⇒ 25 ct/kWh.
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'Stadtwerke', 'tariff_name' => 'MitGP',
            'start' => '2024-01-01', 'end' => '2024-12-31',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 20.0]],
            'base_prices'    => [['from' => '2024-01-01', 'eur_per_month' => 10.0]],
            'bonuses'        => [['credit_date' => '2024-03-15', 'amount_eur' => 60.0, 'type' => 'neukunde']],
        ]);

        $res = $this->tariffs->compare('strom', $meterId, 2024);
        $row = $res['rows'][0];
        self::assertEqualsWithDelta(300.0, $row['total_eur'], 2.0,
            'Vollkosten = Arbeitspreis + Grundpreis − Bonus');
        self::assertEqualsWithDelta(25.0, $row['unit_cost_ct'], 0.5,
            'Der Einheitspreis trägt Grundpreis und Bonus mit');
    }

    /** Die Einheit kommt aus der Utilities-SSOT, nicht aus einem Hardcode. */
    public function testUnitComesFromTheUtilitiesSsot(): void
    {
        $meterId = $this->seedYear();
        $res = $this->tariffs->compare('strom', $meterId, 2024);
        self::assertSame('kWh', $res['unit']);
    }

    /** Wasser und lieferbasierte Arten melden sauber „nicht unterstützt". */
    public function testWaterAndDeliveryAreReportedAsUnsupported(): void
    {
        $waterMeter = $this->meters->list('wasser')[0]['id'] ?? null;
        self::assertNotNull($waterMeter);
        $res = $this->tariffs->compare('wasser', $waterMeter);
        self::assertFalse($res['supported']);
        self::assertNotEmpty($res['note']);
        self::assertSame([], $res['rows']);
    }
}
