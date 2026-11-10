<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Die Prognose rechnet Wasser tagesgenau wie Strom: Ein Preis ab dem 15. eines künftigen Monats gilt ab
 * diesem Tag, ein Vertragswechsel am 15. teilt den Monat. Bis dahin galten in der Prognose (und damit in der
 * Prognose-Werkstatt und für die KI) Vertrag und Preis des Monatsersten für den ganzen Monat, während die
 * vergangenen Monate und die Saldo-Projektion schon nach Tagen rechneten.
 *
 * Erfundener Haushalt: Wasserzähler, 0,3 m³ am Tag, Stände am Monatsersten 01.01. bis 01.11.2026.
 *   Vertrag A ab 01.01.2026: Trinkwasser 210 ct/m³, ab 15.03.2027 280 ct/m³; Schmutzwasser 190 ct/m³ (Basis
 *   Trinkwasser); Grundpreis 6 €/Monat; Abschlag 40 €.
 *   Vertrag B ab 15.05.2027 (löst A ab): Trinkwasser 300 ct/m³, Schmutzwasser 200 ct/m³, Grundpreis 9 €/Monat,
 *   Abschlag 50 €.
 * Von Hand (Monatsmenge der Prognose 31 × 0,3 = 9,3 m³):
 *   März 2027: Trinkwasser 0,3 × (14 × 2,10 + 17 × 2,80) = 0,3 × 77,0 = 23,10 €, Schmutzwasser 9,3 × 1,90 = 17,67 €,
 *              Grundpreis 6,00 € → 46,77 € (mit dem Preis des Monatsersten: 19,53 + 17,67 + 6,00 = 43,20 €)
 *              je m³: 77,0 / 31 / 1 + 1,90 = 2,483871 + 1,90 = 4,383871 €; fest 6,00 €
 *   Mai 2027:  A 14 Tage: 4,2 m³ × (2,80 + 1,90) = 19,74 €, Grundpreis 6 × 14/31 = 2,709677 €
 *              B 17 Tage: 5,1 m³ × (3,00 + 2,00) = 25,50 €, Grundpreis 9 × 17/31 = 4,935484 €
 *              → 52,885161 € = 52,89 € (bisher A für den ganzen Monat: 9,3 × 4,70 + 6 = 49,71 €)
 *              Abschlag 40 × 14/31 + 50 × 17/31 = 18,064516 + 27,419355 = 45,48 €; die Zeile gehört B (17 Tage)
 */
#[CoversClass(ForecastService::class)]
#[CoversClass(ScenarioService::class)]
final class WaterForecastByDayTest extends ServiceTestCase
{
    private string $meterId = '';
    private string $contractB = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('wasser', [['id' => 'd_w', 'serial' => null, 'installed_on' => '2025-12-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($d = '2026-01-01'; $d <= '2026-11-01'; $d = date('Y-m-d', (int)strtotime("$d +1 month"))) {
            $rows[] = ['date' => $d, 'device_id' => 'd_w', 'counter' => round(0.3 * (int)round((strtotime("$d 12:00") - strtotime('2026-01-01 12:00')) / 86400), 3)];
        }
        $this->setReadings('wasser', $this->meterId, $rows);
        $this->contracts->create('wasser', [
            'meter_id' => $this->meterId, 'provider' => 'Wasserwerk Musterstadt', 'tariff_name' => 'A', 'start' => '2026-01-01', 'end' => null,
            'trinkwasser' => ['working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 210.0], ['from' => '2027-03-15', 'ct_per_m3' => 280.0]],
                              'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 6.0]]],
            'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 190.0]]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 40.0]],
        ]);
        $this->contractB = (string)$this->contracts->create('wasser', [
            'meter_id' => $this->meterId, 'provider' => 'Wasserwerk Musterstadt', 'tariff_name' => 'B', 'start' => '2027-05-15', 'end' => null,
            'trinkwasser' => ['working_prices' => [['from' => '2027-05-15', 'ct_per_m3' => 300.0]],
                              'base_prices' => [['from' => '2027-05-15', 'eur_per_month' => 9.0]]],
            'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => '2027-05-15', 'ct_per_m3' => 200.0]]],
            'advance_payments' => [['from' => '2027-05-15', 'amount_eur' => 50.0]],
        ])['id'];
    }

    private function forecast(): ForecastService
    {
        return new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
    }

    /** @return array<string,array<string,mixed>> */
    private function rows(): array
    {
        $fc = $this->forecast()->forMeter('wasser', $this->meters->get('wasser', $this->meterId), ['forecast_months' => 12]);
        return array_column($fc['forecast'], null, 'ym');
    }

    public function testAPriceFromTheFifteenthCountsFromThatDay(): void
    {
        $r = $this->rows()['2027-03'];
        self::assertSame(9.3, $r['m3'], 'Voraussetzung: 31 × 0,3 m³');
        self::assertSame(46.77, $r['cost_estimated']);
        self::assertEqualsWithDelta(4.383871, $r['unit_eur'], 1e-6, 'je m³: Trinkwasser tagesgewichtet plus Schmutzwasser');
        self::assertEqualsWithDelta(6.0, $r['fixed_eur'], 1e-6);
        self::assertEqualsWithDelta(248.3871, $r['working_price_ct'], 1e-4, 'Trinkwasserpreis des Monats nach Tagen');
    }

    public function testAContractChangeOnTheFifteenthSplitsTheMonth(): void
    {
        $r = $this->rows()['2027-05'];
        self::assertSame(52.89, $r['cost_estimated']);
        self::assertSame(45.48, $r['advance_estimated']);
        self::assertSame($this->contractB, $r['contract_id'], 'die Zeile gehört dem Vertrag mit den meisten Tagen');
    }

    /**
     * Ein Bonus nach dem eingetragenen Ende eines weiterlaufenden Wasservertrags zählt in der Prognose an seinem Tag,
     * wie im Saldo: Vertrag A endet am 31.12.2026 (ohne Kündigung läuft er weiter), Bonus 25 € am 10.02.2027.
     * Februar 2027 ohne Bonus minus 25,00 € = mit Bonus.
     */
    public function testABonusAfterTheEnteredEndOfARunningOnContractCounts(): void
    {
        $a = array_values(array_filter($this->contracts->list('wasser'), fn($c) => ($c['tariff_name'] ?? '') === 'A'))[0];
        $this->contracts->update('wasser', (string)$a['id'], ['end' => '2026-12-31']);
        $without = $this->rows()['2027-02']['cost_estimated'];
        $this->contracts->update('wasser', (string)$a['id'], ['bonuses' => [['credit_date' => '2027-02-10', 'amount_eur' => 25, 'type' => 'sofort']]]);
        self::assertEqualsWithDelta($without - 25.0, $this->rows()['2027-02']['cost_estimated'], 0.005);
    }

    /** Das Beispiel der Doku (Verstehen › Wasser) ist dieser März: 46,77 € statt 43,20 €. */
    public function testTheDocumentedExampleHolds(): void
    {
        self::assertSame(46.77, $this->rows()['2027-03']['cost_estimated']);
        $root = dirname(__DIR__, 3);
        foreach (['docs/verstehen/03-wasser.md' => ['9,3 m³', '46,77 €', '43,20 €'], 'docs/en/verstehen/03-wasser.md' => ['9.3 m³', '€46.77', '€43.20']] as $file => $want) {
            $text = (string)file_get_contents("$root/$file");
            $pos = strpos($text, '<!-- doc-claim: water-forecast-by-the-day');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            $block = substr($text, $pos, 600);
            foreach ($want as $v) self::assertStringContainsString($v, $block, "$file: $v");
        }
    }

    /** Die Prognose-Werkstatt rechnet ihren Ausgangsfall mit denselben Bausteinen (fester Teil, Preis je m³). */
    public function testTheWorkshopUsesTheSameMonthCost(): void
    {
        $forecast = $this->forecast();
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $periods = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills);
        $sc = new ScenarioService($forecast, $this->consumption, $this->contracts, $this->meters, $this->settings,
            new TariffSwitchService($forecast, $this->contracts, $this->meters, $this->i18n), $periods);
        $months = array_column($sc->compute(['utility' => 'wasser', 'meter_id' => $this->meterId], '2026-11-05')['months'] ?? [], null, 'ym');
        self::assertArrayHasKey('2027-03', $months);
        $q = (float)$months['2027-03']['base_qty'];
        self::assertEqualsWithDelta(round(6.0 + 4.383871 * $q, 2), $months['2027-03']['base_cost'], 0.011);
    }
}
