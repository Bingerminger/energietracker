<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Rights;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\RightsService;
use Energietracker\Services\Rights\ContractChecks;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.11.0 (F1035, Paket vertrag) — A2 „Abschlag deutlich zu hoch“ (Konzept §3, Entscheidung 3b).
 *
 * Handrechnung (erfundener Haushalt, Konzept §3 A2): Strom, Rechnung 2025 über 3.000 kWh in 365 Tagen,
 * 30 ct/kWh, Grundpreis 12 € im Monat → 3.000 × 0,30 + 12 × 12 = 900 + 144 = 1.044 € im Jahr ÷ 12 Raten
 * = 87 € je Rate. Die Ablesungen zeigen dieselben 3.000 kWh im Jahr (3.000/365 kWh am Tag), also hält
 * auch die Prognose 87 € für angemessen. Rate 110 € → 26 % und 23 € darüber: Befund. Rate 100 € →
 * 15 % und 13 € darüber: unter 20 %, kein Befund. Stichtag 10.06.2026, Abrechnung zum 31.12.
 */
#[CoversClass(ContractChecks::class)]
final class RightsAdvanceTest extends ServiceTestCase
{
    private const TODAY = '2026-06-10';
    private string $meterId = '';
    private RightsService $rights;
    private ContractChecks $checks;
    private BillService $bills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['billing_cycle_anchor_strom' => '01-01', 'country' => 'DE']);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_a2', 'serial' => null, 'installed_on' => '2024-12-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->readingsPerYear(3000.0, '2024-12-01');
        $this->bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $periods = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills);
        $this->checks = new ContractChecks($this->contracts, $this->meters, $this->consumption, $this->bills, $periods, $this->i18n);
        $this->rights = new RightsService($this->settings, $this->i18n);
        $this->rights->register('advance_high', fn(string $today, string $country): array => $this->checks->advanceHigh($today, $country));
    }

    /** Monatliche Ablesung ab `$from` bis zum Stichtag, gleichmäßig `$kwh` im Jahr. */
    private function readingsPerYear(float $kwh, string $from, string $until = self::TODAY): void
    {
        $rows = [];
        for ($d = $from; $d <= $until; $d = date('Y-m-d', (int)strtotime("$d +1 month"))) $rows[] = $d;
        $rows[] = $until;
        $this->setReadings('strom', $this->meterId, array_map(fn($d) => ['date' => $d, 'device_id' => 'd_a2',
            'counter' => round($kwh / 365 * (strtotime($d) - strtotime($from)) / 86400, 3)], array_values(array_unique($rows))));
    }

    private function contract(array $advance, array $over = []): array
    {
        return $this->contracts->create('strom', $over + ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt',
            'start' => '2026-01-01', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]], 'advance_payments' => [$advance]]);
    }

    private function bill(float $kwh = 3000.0): void
    {
        $this->bills->create('strom', ['meter_id' => $this->meterId, 'kind' => 'annual', 'period_from' => '2025-01-01',
            'period_to' => '2025-12-31', 'issued_on' => '2026-02-20', 'invoice' => ['energy_kwh' => $kwh, 'amount_eur' => 1044.0]]);
    }

    /** @return list<array<string,mixed>> */
    private function findings(string $today = self::TODAY): array
    {
        return $this->rights->run('advance_high', $today);
    }

    public function testRateClearlyAboveBillAndForecastGivesAFinding(): void
    {
        $this->bill();
        $c = $this->contract(['from' => '2026-01-01', 'amount_eur' => 110]);
        $status = $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'][0];
        self::assertSame(87.0, (float)$status['advance_ongoing'], 'Vorbedingung: die Prognose hält 1.044 € ÷ 12 = 87 € für angemessen');
        self::assertNull($status['projection_uncertain']);

        $f = $this->findings();
        self::assertCount(1, $f);
        $f = $f[0];
        self::assertSame(['advance_high', 'strom', $this->meterId, $c['id']], [$f['check'], $f['utility'], $f['meter_id'], $f['contract_id']]);
        self::assertSame(87.0, $f['facts']['measure_eur'], '3.000 × 0,30 + 12 × 12 = 1.044 € ÷ 12');
        self::assertSame(1044.0, $f['facts']['annual_cost_eur']);
        self::assertSame(23.0, $f['amount_eur'], '110 − 87 je Rate');
        self::assertSame(26, $f['facts']['over_pct'], '110 ÷ 87 = 1,264');
        self::assertSame(['bill', '2025-01-01', '2025-12-31', 12, '2026-07-01'],
            [$f['facts']['basis'], $f['facts']['period_from'], $f['facts']['period_to'], $f['facts']['per_year'], $f['facts']['next_due']]);
        // Fakten unter den Namen, die der Brief „Antrag auf Abschlagsanpassung“ liest (Paket briefe)
        self::assertSame([3000.0, 'kWh', 87.0, 1, 30.0, 12.0], [$f['facts']['quantity'], $f['facts']['unit'], $f['facts']['assessment_eur'],
            $f['facts']['every_months'], $f['facts']['working_price_ct'], $f['facts']['base_price_month_eur']]);
        self::assertSame('2026-02-20', $f['date'], 'Anlass: die Rechnung (später als die Abschlagszeile)');
        self::assertTrue($f['todo'], '110 Tage nach dem Anlass: im Fenster');
        self::assertNull($f['deadline']);
        self::assertSame('advance_adjustment', $f['letter']);
        // Versorgungsart unbekannt: beide Normen
        self::assertSame(['de.stromgvv.13', 'de.enwg.41b.3'], $f['rules']);
        self::assertStringContainsString('110,00 €', $f['text']);
        self::assertStringContainsString('3.000 kWh', $f['text']);
        self::assertStringContainsString('rund 87', $f['text']);
        self::assertStringContainsString('(§ 13 StromGVV, § 41b Abs. 3 EnWG)', $f['text']);
        self::assertStringContainsString('Hinweis, keine Rechtsberatung', $f['footer']);
    }

    public function testFifteenPercentIsNotEnough(): void
    {
        $this->bill();
        $this->contract(['from' => '2026-01-01', 'amount_eur' => 100]);
        self::assertSame([], $this->findings(), '100 € sind 13 € und 15 % über 87 €: unter der Schwelle von 20 %');
    }

    public function testThresholdsAtTheEdges(): void
    {
        self::assertFalse(ContractChecks::tooHigh(104.4, 87.0), 'genau 20 % darüber: nicht „mehr als“');
        self::assertTrue(ContractChecks::tooHigh(104.41, 87.0));
        self::assertTrue(ContractChecks::tooHigh(30.0, 20.0), '50 % und genau 10 €');
        self::assertFalse(ContractChecks::tooHigh(29.99, 20.0), 'unter 10 € je Rate');
        self::assertFalse(ContractChecks::tooHigh(10.0, 0.0), 'ohne Vergleichsgröße nie');
        self::assertSame([20.0, 10.0], [Rights::ADVANCE_HIGH_PCT, Rights::ADVANCE_HIGH_MIN_EUR]);
    }

    public function testTheForecastMustAgree(): void
    {
        // Der Verbrauch ist gestiegen (Wärmepumpe, E-Auto): die Ablesungen zeigen 4.000 kWh im Jahr, die
        // alte Rechnung 3.000. Gesetzlich wären 87 € angemessen, die Prognose sagt 1.200 + 144 = 1.344 ÷ 12 = 112 €.
        $this->readingsPerYear(4000.0, '2024-12-01');
        $this->bill();
        $this->contract(['from' => '2026-01-01', 'amount_eur' => 110]);
        self::assertSame([], $this->findings(), 'widerspricht die Prognose, kein Befund');
    }

    public function testEveryTwoMonthsComparesPerInstallment(): void
    {
        // 220 € alle zwei Monate ab 15.01.: nächste Rate 15.07., sechs Raten im Jahr → 1.044 ÷ 6 = 174 € je Rate
        $this->bill();
        $this->contract(['from' => '2026-01-15', 'amount_eur' => 220, 'every_months' => 2]);
        $f = $this->findings();
        self::assertCount(1, $f);
        self::assertSame([174.0, 46.0, 6, '2026-07-15', 2], [$f[0]['facts']['measure_eur'], $f[0]['amount_eur'], $f[0]['facts']['per_year'],
            $f[0]['facts']['next_due'], $f[0]['facts']['every_months']]);
    }

    public function testElevenListedDatesMeanElevenInstallments(): void
    {
        $dates = array_map(fn($m) => sprintf('2026-%02d-05', $m), range(2, 12));
        $r = ContractChecks::nextRate([['from' => '2026-02-05', 'amount_eur' => 95.0, 'due_dates' => $dates]], self::TODAY);
        self::assertSame(['due' => '2026-07-05', 'amount_eur' => 95.0, 'per_year' => 11, 'from' => '2026-02-05', 'every_months' => 1], $r,
            '1.044 € auf elf Raten = 94,91 €: kein Fehlalarm wie bei zwölf (87 €)');
        self::assertFalse(ContractChecks::tooHigh(95.0, 1044.0 / 11));
    }

    public function testPricesOnTheNextDueDateCount(): void
    {
        // Angekündigte Erhöhung zum 01.07. (nächster Fälligkeitstag): 3.000 × 0,40 + 144 = 1.344 € → 112 € je Rate
        $this->bill();
        $c = $this->contract(['from' => '2026-01-01', 'amount_eur' => 110],
            ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0], ['from' => '2026-07-01', 'ct_per_kwh' => 40.0, 'announced_on' => '2026-05-20']]]);
        self::assertSame(1044.0, $this->checks->annualCost($c, 3000.0, self::TODAY));
        self::assertSame(1344.0, $this->checks->annualCost($c, 3000.0, '2026-07-01'));
        self::assertSame([], $this->findings(), '110 € passen zu den Preisen ab Juli');

        // umgekehrt: Senkung auf 20 ct zum 01.07. → 600 + 144 = 744 € ÷ 12 = 62 €; 80 € sind 29 % und 18 € darüber
        // (mit den Preisen von heute, 87 €, wäre es kein Befund)
        $this->contracts->update('strom', $c['id'], ['advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 80]],
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0], ['from' => '2026-07-01', 'ct_per_kwh' => 20.0]]]);
        $f = $this->findings();
        self::assertCount(1, $f);
        self::assertSame([62.0, 18.0], [$f[0]['facts']['measure_eur'], $f[0]['amount_eur']]);
    }

    public function testAShorterBilledPeriodIsScaledToAYear(): void
    {
        // Rechnung 01.03.–31.12.2025 (306 Tage) über 2.000 kWh → 2.000 × 365/306 = 2.385,6 kWh × 0,30 = 715,69 €
        // + 144 € = 859,69 € ÷ 12 = 71,64 € (ungerechnet wären es 62 €)
        $this->bills->create('strom', ['meter_id' => $this->meterId, 'kind' => 'annual', 'period_from' => '2025-03-01',
            'period_to' => '2025-12-31', 'issued_on' => '2026-02-20', 'invoice' => ['energy_kwh' => 2000, 'amount_eur' => 800.0]]);
        $this->contract(['from' => '2026-01-01', 'amount_eur' => 110]);
        $f = $this->findings();
        self::assertCount(1, $f);
        self::assertSame([71.64, 72.0, 2000.0], [$f[0]['facts']['measure_eur'], $f[0]['facts']['assessment_eur'], $f[0]['facts']['quantity']],
            'Brief: Bemessung auf volle Euro aufgerundet');
    }

    public function testShortlyBeforeTheBillNoFinding(): void
    {
        $this->bill();
        $this->contract(['from' => '2026-01-01', 'amount_eur' => 110]);
        $this->readingsPerYear(3000.0, '2024-12-01', '2026-10-10');
        self::assertSame([], $this->findings('2026-10-10'), 'nur noch zwei Raten bis zur Abrechnung (L12)');
    }

    public function testOwnReadingsStandInForAMissingBill(): void
    {
        // ohne Rechnung: die abgeschlossene Abrechnungsperiode 2025 aus den eigenen Ablesungen (3.000 kWh)
        $this->contract(['from' => '2026-01-01', 'amount_eur' => 110]);
        $f = $this->findings();
        self::assertCount(1, $f);
        self::assertSame(['readings', '2025-01-01', '2025-12-31', null], [$f[0]['facts']['basis'], $f[0]['facts']['period_from'], $f[0]['facts']['period_to'], $f[0]['facts']['bill_id']]);
        self::assertEqualsWithDelta(87.0, $f[0]['facts']['measure_eur'], 0.05);
        self::assertSame('2026-01-01', $f[0]['date'], 'Anlass: Tag nach der Periode bzw. Beginn der Abschlagszeile');
    }

    public function testWithoutABilledPeriodNoFinding(): void
    {
        // erster Vertrag, Ablesungen erst seit Dezember: kein abgerechneter Zeitraum (Gesetz: vergleichbare Kunden)
        $this->readingsPerYear(3000.0, '2025-12-01');
        $this->contract(['from' => '2026-01-01', 'amount_eur' => 110]);
        self::assertSame([], $this->findings());
    }

    public function testNormsBySupplyKindAndUtility(): void
    {
        self::assertSame(['de.stromgvv.13'], ContractChecks::advanceRules('DE', 'strom', 'basic'));
        self::assertSame(['de.enwg.41b.3'], ContractChecks::advanceRules('DE', 'gas', 'special'));
        self::assertSame(['de.gasgvv.13', 'de.enwg.41b.3'], ContractChecks::advanceRules('DE', 'gas', null));
        self::assertSame(['de.avbfernwaermev.25'], ContractChecks::advanceRules('DE', 'fernwaerme', 'special'), 'Fernwärme: keine Grundversorgung');
        self::assertSame([], ContractChecks::advanceRules('DE', 'wasser', null), 'Wasser: privat- oder öffentlich-rechtlich ist offen');
        self::assertSame([], ContractChecks::advanceRules('AT', 'strom', null));

        $this->bill();
        $this->contract(['from' => '2026-01-01', 'amount_eur' => 110], ['supply_kind' => 'basic']);
        $f = $this->findings()[0];
        self::assertSame(['de.stromgvv.13'], $f['rules']);
        self::assertStringContainsString('(§ 13 StromGVV)', $f['text']);
        self::assertStringNotContainsString('41b', $f['text']);
    }

    public function testOnlyGermany(): void
    {
        $this->bill();
        $this->contract(['from' => '2026-01-01', 'amount_eur' => 110]);
        $this->settings->set(['country' => 'AT']);
        self::assertSame([], $this->findings());
    }
}
