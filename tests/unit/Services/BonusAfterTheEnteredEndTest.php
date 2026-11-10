<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 (Skeptiker H5) — ein Bonus nach dem eingetragenen Ende zählt an seinem Tag, solange der Vertrag läuft. Bis
 * v3.11 wurde er zurückdatiert: Er stand im Monat des Endes, aber in keinem Saldo, und im Abrechnungsjahr danach.
 *
 * Erfundener Haushalt: Strom, Stichtag 01.07., 10 kWh am Tag, Ablesung am Monatsersten; heute 15.10.2026. Vertrag
 * 01.07.2025 bis 30.06.2026, ohne Kündigung weiterlaufend, 30 ct/kWh, 12 €/Monat, Abschlag 110 €. Bonus 120 € am 15.07.2026.
 * Von Hand, ab 01.07.2026 bis zur Ablesung am 01.10.2026:
 *   Menge (31 + 31 + 30) × 10 = 920 kWh → 276,00 €, Grundpreis 3 × 12 = 36,00 €, Abschläge 3 × 110 = 330,00 €
 *   ohne Bonus: 276 + 36 − 330 = −18,00 €; mit Bonus: −138,00 €
 *   Juli: 310 kWh × 0,30 € + 12 € − 120 € = −15,00 €; Juni: 300 kWh × 0,30 € + 12 € = 102,00 €, kein Bonus
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ContractService::class)]
final class BonusAfterTheEnteredEndTest extends ServiceTestCase
{
    private const TODAY = '2026-10-15';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['billing_cycle_anchor_strom' => '07-01']);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_b', 'serial' => null, 'installed_on' => '2025-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($d = '2025-07-01'; $d <= '2026-10-01'; $d = date('Y-m-d', (int)strtotime("$d +1 month"))) {
            $rows[] = ['date' => $d, 'device_id' => 'd_b', 'counter' => 10.0 * (int)round((strtotime("$d 12:00") - strtotime('2025-07-01 12:00')) / 86400)];
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function contract(string $bonusOn, array $extra = []): array
    {
        return $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2025-07-01', 'end' => '2026-06-30',
            'working_prices' => [['from' => '2025-07-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-07-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2025-07-01', 'amount_eur' => 110.0]],
            'bonuses' => [['credit_date' => $bonusOn, 'amount_eur' => 120.0, 'type' => 'neukunde']]] + $extra);
    }

    /** @return array<string,mixed> Abrechnungsjahre nach Beginn */
    private function periods(): array
    {
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bp = (new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills))
            ->forTarget('strom', $this->meters->get('strom', $this->meterId), false, self::TODAY);
        return array_column($bp['periods'], null, 'from');
    }

    /** @return array<string,mixed> Monatszeilen nach Monat */
    private function months(): array
    {
        return array_column($this->consumption->forMeter('strom', $this->meters->get('strom', $this->meterId)), null, 'ym');
    }

    private function row(): array
    {
        return $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'][0];
    }

    /**
     * v3.12.0 (Nachprüfung B1) — Ende 30.06.2026, ohne Kündigung weiter bis zum Folgevertrag 01.09.2026. Die Zeile rechnet bis dorthin, von Hand:
     * 01.07.2025 bis 31.08.2026 = 427 Tage × 10 kWh × 0,30 € = 1.281,00 €, Grundpreis 14 × 12 € = 168,00 €, Abschläge 14 × 110 € = 1.540,00 €.
     * Ohne Bonus 1.281 + 168 − 1.540 = −91,00 €, mit Bonus 120 € −211,00 €: die Summe der Abrechnungsjahre. Bis dahin +105,00 € (Arbeitspreis bis
     * 31.08., Grundpreis und Abschläge bis 30.06.).
     */
    public function testAContractThatRanOnUntilItsSuccessorCountsAllOfIt(): void
    {
        $follow = fn(string $provider) => $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => $provider, 'start' => '2026-09-01',
            'working_prices' => [['from' => '2026-09-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2026-09-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2026-09-01', 'amount_eur' => 110.0]]]);
        $this->contract('2026-10-10');
        $follow('Folgeversorger');
        $rows = array_column($this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'], null, 'provider');
        $old = $rows['Strom Musterstadt'];
        self::assertSame(-211.00, $old['current_balance'], 'die Zeile rechnet bis zum Folgevertrag');
        self::assertSame(120.0, $old['bonus_to_date'], 'der Bonus steht im Saldo');
        self::assertEqualsWithDelta(168.0, $old['base_to_date'], 0.01);
        self::assertEqualsWithDelta(1540.0, $old['advance_paid'], 0.01);
        // das erste Abrechnungsjahr von Hand: 365 × 10 × 0,30 = 1.095 + 144 − 1.320 = −81,00 €; der Rest −130,00 € liegt im zweiten (2 Monate −10 €, Bonus −120 €)
        self::assertSame(-81.00, $this->periods()['2025-07-01']['balance']);
    }

    /**
     * Derselbe Fall beim selben Versorger, Stichtag 01.01.: Die Periode läuft durch, die Zeile zählt ihren Teil darin, und der endet auch dort
     * erst am 31.08. Von Hand 01.01.–31.08.2026: 243 Tage × 10 × 0,30 = 729,00 € + 8 × 12 € = 96,00 € − Bonus 120,00 € − 8 × 110 € = 880,00 € = −175,00 €.
     */
    public function testARanOnContractInTheRunningPeriodCountsUpToItsSuccessor(): void
    {
        $this->settings->set(['billing_cycle_anchor_strom' => '01-01']);
        $this->contract('2026-10-10');
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2026-09-01',
            'working_prices' => [['from' => '2026-09-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2026-09-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2026-09-01', 'amount_eur' => 110.0]]]);
        $rows = array_column($this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'], null, 'start');
        self::assertSame('2026-01-01', $rows['2025-07-01']['balance_from']);
        self::assertSame(-175.00, $rows['2025-07-01']['current_balance']);
    }

    /** Ohne Bonus derselbe Fall: −91,00 €, und ein wirklich beendeter Vertrag bleibt beim eingetragenen Ende. */
    public function testWithoutBonusAndWithARealEnd(): void
    {
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Alt', 'start' => '2025-07-01', 'end' => '2026-06-30',
            'working_prices' => [['from' => '2025-07-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-07-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2025-07-01', 'amount_eur' => 110.0]]]);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Neu', 'start' => '2026-09-01',
            'working_prices' => [['from' => '2026-09-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2026-09-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2026-09-01', 'amount_eur' => 110.0]]]);
        $rows = array_column($this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'], null, 'provider');
        self::assertSame(-91.00, $rows['Alt']['current_balance']);
    }

    /**
     * v3.12.0 (Nachprüfung C6) — derselbe Stand, derselbe Cent. Von Hand bis zur Ablesung 30.10.2026 (121 Tage): 363,00 € + Grundpreis
     * 3 × 12 € + 12 € × 29/31 = 47,23 € − Bonus 120,00 € − Abschläge 3 × 110 € + 110 € × 29/31 = 432,90 € = −142,67 €. Die Karte sagte −142,68 €.
     */
    public function testCardAndBillingYearAgreeToTheCent(): void
    {
        $rows = [];
        foreach ([...array_map(fn(int $i) => date('Y-m-d', (int)strtotime("2025-07-01 +$i months")), range(0, 15)), '2026-10-30'] as $d) {
            $rows[] = ['date' => $d, 'device_id' => 'd_b', 'counter' => 10.0 * (int)round((strtotime("$d 12:00") - strtotime('2025-07-01 12:00')) / 86400)];
        }
        $this->setReadings('strom', $this->meterId, $rows);
        $this->contract('2026-07-15');
        $meter = $this->meters->get('strom', $this->meterId);
        $m = $this->consumption->contractStatus('strom', $meter, '2026-11-05')['contracts'][0]['balance_measured'];
        self::assertSame(-142.67, $m['balance']);
        self::assertSame(round($m['cost'] - $m['advance'] + $m['special_net'], 2), $m['balance'], 'die Teile ergeben die Summe');
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bp = (new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills))->forTarget('strom', $meter, false, '2026-11-05');
        self::assertSame(-142.67, array_column($bp['periods'], null, 'from')['2026-07-01']['balance']);
    }

    /** @return array<string,array<string,mixed>> Prognose nach Monat */
    private function forecastByMonth(): array
    {
        $fc = (new \Energietracker\Services\ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))
            ->forMeter('strom', $this->meters->get('strom', $this->meterId), ['forecast_months' => 12]);
        return array_column($fc['forecast'], null, 'ym');
    }

    /**
     * v3.12.0 (Nachprüfung B4) — Prognose und Saldo nach derselben Regel. Eingetragenes Ende 31.12.2026, läuft weiter, Bonus 120 € am 15.01.2027.
     * Von Hand: Dezember 31 × 10 kWh × 0,30 € + 12 € = 105,00 € (kein Bonus; bis dahin −15,00 €); Januar 105,00 € − 120,00 € = −15,00 €.
     * Der erwartete Saldo zum Stichtag enthält ihn auch nicht: kein Unterschied von 120 € mehr.
     */
    public function testTheForecastCountsTheBonusOnItsDayLikeTheBalance(): void
    {
        $this->settings->set(['billing_cycle_anchor_strom' => '01-01']);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2025-07-01', 'end' => '2026-12-31',
            'working_prices' => [['from' => '2025-07-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-07-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2025-07-01', 'amount_eur' => 110.0]],
            'bonuses' => [['credit_date' => '2027-01-15', 'amount_eur' => 120.0, 'type' => 'neukunde']]]);
        $m = $this->forecastByMonth();
        self::assertEqualsWithDelta(105.0, $m['2026-12']['cost_estimated'], 0.5, 'Dezember ohne Bonus');
        self::assertEqualsWithDelta(-15.0, $m['2027-01']['cost_estimated'], 0.5, 'Januar mit Bonus');
    }

    /** Verlängert (Ende 30.06.2026), Bonus am 15.02.2027: Februar 28 × 10 × 0,30 + 12 − 120 = −24,00 € (bis dahin 96,00 €: `assumed` schloss ihn aus). */
    public function testTheForecastCountsABonusInTheRenewedPartToo(): void
    {
        $this->contract('2027-02-15');
        self::assertEqualsWithDelta(-24.0, $this->forecastByMonth()['2027-02']['cost_estimated'], 0.5);
    }

    /**
     * Tarifvergleich: Verlängert, Bonus 120 € erst am 15.02.2027, gerechnet wird bis 2026. Er steckt nicht im Betrag und steht auch nicht
     * in `bonus_items`. Bis dahin nannte die Liste ihn als „steckt im Betrag“, zurückdatiert auf das eingetragene Ende.
     */
    public function testTheTariffComparisonListsOnlyWhatIsInItsAmount(): void
    {
        $this->contract('2027-02-15');
        $svc = new \Energietracker\Services\TariffComparisonService($this->consumption, $this->contracts, $this->meters, $this->i18n);
        $rows = $svc->compare('strom', $this->meterId, 2026)['rows'] ?? [];
        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            self::assertSame([], $row['bonus_items'] ?? [], 'kein Bonus in der Liste');
            self::assertSame(0.0, (float)($row['bonus_eur'] ?? 0), 'und keiner im Betrag');
        }
    }

    public function testARenewedContractCountsTheBonusOnItsDayEverywhere(): void
    {
        $this->contract('2026-07-15');
        $row = $this->row();
        self::assertTrue($row['renewed']);
        // Saldo-Karte
        self::assertSame(120.0, (float)$row['bonus_to_date']);
        self::assertSame(120.0, (float)$row['balance_measured']['bonus']);
        self::assertSame(-138.0, (float)$row['balance_measured']['balance'], 'von Hand: 276 + 36 − 120 − 330');
        // Abrechnungsjahre
        $p = $this->periods();
        self::assertSame(120.0, (float)$p['2026-07-01']['bonus']);
        self::assertSame(0.0, (float)$p['2025-07-01']['bonus'], 'nicht zurückdatiert');
        // Monate
        $m = $this->months();
        self::assertSame(120.0, (float)$m['2026-07']['bonus_eur']);
        self::assertEqualsWithDelta(-15.0, (float)$m['2026-07']['cost'], 0.01, 'Juli von Hand');
        self::assertSame(0.0, (float)$m['2026-06']['bonus_eur'], 'Juni ohne Bonus');
        self::assertEqualsWithDelta(102.0, (float)$m['2026-06']['cost'], 0.01, 'Juni von Hand');
    }

    public function testWithoutABonusTheSameHouseholdGivesTheDifference(): void
    {
        $this->contract('2020-01-01');   // vor dem Beginn: zählt am ersten Tag, also in der alten Periode
        $row = $this->row();
        self::assertSame(-18.0, (float)$row['balance_measured']['balance'], 'von Hand: 276 + 36 − 330');
        self::assertSame(120.0, (float)$this->periods()['2025-07-01']['bonus']);
    }

    public function testAGaplessSuccessorKeepsTheBonusWithItsContract(): void
    {
        // v2.5.3 bleibt: Der Vertrag ist vorbei, der Bonus kommt mit der Schlussrechnung und zählt am letzten Tag
        $this->contract('2026-07-15');
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2026-07-01',
            'working_prices' => [['from' => '2026-07-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2026-07-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2026-07-01', 'amount_eur' => 110.0]]]);
        $m = $this->months();
        self::assertSame(120.0, (float)$m['2026-06']['bonus_eur']);
        self::assertSame(0.0, (float)$m['2026-07']['bonus_eur']);
        $p = $this->periods();
        self::assertSame(120.0, (float)$p['2025-07-01']['bonus'], 'dieselbe Regel im Abrechnungsjahr (bis v3.11 am echten Tag: im nächsten)');
        self::assertSame(0.0, (float)$p['2026-07-01']['bonus']);
    }

    public function testNotRenewingKeepsTheBonusOnTheLastDay(): void
    {
        $this->contract('2026-07-15', ['auto_renews' => false]);
        self::assertSame(120.0, (float)$this->months()['2026-06']['bonus_eur']);
    }

    public function testAFutureSuccessorLeavesTheBonusInTheGapAndABonusAfterItGoesBack(): void
    {
        // Folgevertrag erst ab 01.03.2027: bis dahin läuft der alte, ein Bonus danach zählt an seinem letzten Tag
        $c = $this->contract('2026-08-15');
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2027-03-01',
            'working_prices' => [['from' => '2027-03-01', 'ct_per_kwh' => 30.0]]]);
        self::assertSame(120.0, (float)$this->months()['2026-08']['bonus_eur']);
        $list = $this->contracts->withRunsUntil($this->contracts->list('strom', $this->meterId));
        $old = array_values(array_filter($list, fn($x) => $x['id'] === $c['id']))[0];
        self::assertSame('2027-02-28', $old['runs_until']);
        self::assertSame('2026-08-15', ContractService::bonusDay($old, '2026-08-15'));
        self::assertSame('2027-02-28', ContractService::bonusDay($old, '2027-04-01'));
        self::assertSame('2025-07-01', ContractService::bonusDay($old, '2025-01-01'));
    }

    public function testBonusOnTheLastDayOfThePeriodStaysInThatPeriod(): void
    {
        // #56 fällt nicht zurück: am 30.06. in der alten Periode, nicht noch einmal in der neuen
        $this->contract('2026-06-30');
        $p = $this->periods();
        self::assertSame([120.0, 0.0], [(float)$p['2025-07-01']['bonus'], (float)$p['2026-07-01']['bonus']]);
        self::assertSame(0.0, (float)$this->row()['bonus_to_date']);
    }
}
