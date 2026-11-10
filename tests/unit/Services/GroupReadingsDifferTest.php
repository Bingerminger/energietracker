<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\ReportAdviceService;
use Energietracker\Services\StromSaldoService;
use Energietracker\Services\SummaryService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * v3.12.0 (Nachprüfung A3) — Saldo einer Gruppe, deren Mitglieder verschieden weit abgelesen sind.
 *
 * Bis v3.11 zwei Fehler, die sich teils aufhoben: Die Schätzung begann am frühesten Stand, die Monatszeilen enthielten
 * das spätere Mitglied schon bis zu seinem Stand (doppelt), und die Kalibrierung las die Monate ohne das früher
 * abgelesene Mitglied als gesunkenen Verbrauch (Faktor bis 0,50). Fehler bis +269 € und −278 €.
 *
 * Fall, von Hand (erfunden): A 10 kWh/Tag, B 5 kWh/Tag, 30 ct, 12 €/Monat, Abschlag 100 €/Monat, Beginn 01.01.2026,
 * Abrechnung 31.12., heute 05.11.2026. Der Verbrauch ist je Mitglied konstant, die Wahrheit also bekannt:
 * - bis heute 308 Tage × 15 kWh × 0,30 € = 1.386,00 €, Grundpreis 10 × 12 € + 12 € × 4/30 = 121,60 €, Abschläge 11 × 100 € = 1.100,00 €: +407,60 €
 * - zum 31.12. 365 Tage × 15 kWh × 0,30 € = 1.642,50 € + 144,00 € − 1.200,00 € = +586,50 €
 * - laufend angemessen (1.642,50 € + 144,00 €) ÷ 12 = 148,88 €, gerundet 149 €
 */
final class GroupReadingsDifferTest extends ServiceTestCase
{
    private const TODAY = '2026-11-05';

    private string $a;
    private string $b;
    private string $gid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['billing_cycle_anchor_strom' => '01-01']);
        $this->a = (string)$this->meters->create('strom', ['name' => 'A', 'installed_on' => '2024-01-01'])['id'];
        $this->b = (string)$this->meters->create('strom', ['name' => 'B', 'installed_on' => '2024-01-01'])['id'];
        $this->gid = (string)$this->meters->createGroup('strom', ['name' => 'A+B'])['id'];
        foreach ([$this->a, $this->b] as $m) $this->meters->update('strom', $m, ['meter_group_id' => $this->gid]);
    }

    /** Monatsstände ab 01.01.2024 mit konstantem Verbrauch, dazu der letzte Stand. */
    private function read(string $lastA, string $lastB, float $rateA = 10.0, float $rateB = 5.0): void
    {
        $all = [];
        foreach ([[$this->a, $rateA, $lastA], [$this->b, $rateB, $lastB]] as [$m, $rate, $last]) {
            $dev = $this->meters->get('strom', $m)['devices'][0]['id'];
            $dates = [];
            for ($i = 0; ($d = date('Y-m-d', (int)strtotime("2024-01-01 +$i months"))) <= $last; $i++) $dates[] = $d;
            if (!in_array($last, $dates, true)) $dates[] = $last;
            foreach ($dates as $i => $d) {
                $days = (int)round((strtotime($d) - strtotime('2024-01-01')) / 86400);
                $all[] = ['id' => "r_{$m}_$i", 'meter_id' => $m, 'device_id' => $dev, 'date' => $d, 'counter' => $days * $rate,
                          'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
        }
        $this->store->write('strom/readings.json', $all);
    }

    private function groupStatus(string $lastA, string $lastB): array
    {
        $this->read($lastA, $lastB);
        $this->contracts->create('strom', [
            'meter_group_id' => $this->gid, 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 100.0]],
        ]);
        $group = $this->meters->groupTarget('strom', $this->gid);
        return array_values(array_filter($this->consumption->contractStatus('strom', $group, self::TODAY)['contracts'], fn($c) => !empty($c['is_current'])))[0];
    }

    private function summary(): array
    {
        $reminders = new ReminderService($this->store, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $reminders, $recs, $this->i18n);
        return (new SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $deliveries, new PvSummaryService($this->consumption), new StromSaldoService($this->consumption), $agenda,
            new InstanceService($this->store)))->build('strom', null, self::TODAY);
    }

    /**
     * Letzte Stände [A, B] und der Saldo nur über Gemessenes (bleibt, wie er ist; bis zum frühesten Stand):
     * 30.09.: 272 Tage × 15 × 0,30 = 1.224,00 + Grundpreis 8 × 12 + 12 × 29/30 = 107,60 − Abschläge 8 × 100 + 100 × 29/30 = 896,67 → 434,93
     * 30.04.: 119 Tage × 15 × 0,30 =   535,50 + Grundpreis 3 × 12 + 12 × 29/30 =  47,60 − Abschläge 3 × 100 + 100 × 29/30 = 396,67 → 186,43
     *
     * @return array<string,array{0:string,1:string,2:string,3:float}>
     */
    public static function spreads(): array
    {
        return [
            'alle am selben Tag'   => ['2026-10-31', '2026-10-31', '2026-10-31', 486.34],
            'A einen Monat später' => ['2026-10-31', '2026-09-30', '2026-09-30', 434.93],
            'B einen Monat später' => ['2026-09-30', '2026-10-31', '2026-09-30', 434.93],
            'A sechs Monate später' => ['2026-10-31', '2026-04-30', '2026-04-30', 186.43],
            'B sechs Monate später' => ['2026-04-30', '2026-10-31', '2026-04-30', 186.43],
        ];
    }

    #[DataProvider('spreads')]
    public function testTheBalanceIsTheTrueOneHoweverFarTheMembersAreRead(string $lastA, string $lastB, string $until, float $measured): void
    {
        $c = $this->groupStatus($lastA, $lastB);
        self::assertSame(407.60, $c['current_balance'], 'Saldo bis heute');
        self::assertSame(586.50, $c['projected_end_balance'], 'erwartet zum 31.12.');
        self::assertEqualsWithDelta(1386.00, $c['energy_cost_to_date'], 0.01, 'Arbeitspreis bis heute einmal gezählt');
        self::assertEqualsWithDelta(121.60, $c['base_to_date'], 0.01);
        self::assertEqualsWithDelta(1100.00, $c['advance_paid'], 0.01);
        self::assertSame(1.0, $c['projection_factor'], 'kein gesunkener Verbrauch aus Monaten ohne Mitglied');
        self::assertSame($until, $c['measured_until']);
        self::assertSame($measured, $c['balance_measured']['balance'], 'der gemessene Saldo bleibt, wie er ist');
        // der letzte Punkt des Saldo-Verlaufs ist der erwartete Endsaldo, keine Stufe im Jahr
        $path = array_column($c['balance_path'], 'balance', 'ym');
        self::assertEqualsWithDelta(586.50, $path['2026-12'], 0.01, 'Saldo-Verlauf endet am erwarteten Saldo');
    }

    /** Bis 62 Tage auseinander: keine Unsicherheit, und das Band der Schätzung enthält die Wahrheit. */
    public function testASmallSpreadStaysCertainAndTheBandContainsTheTrueEnd(): void
    {
        $c = $this->groupStatus('2026-10-31', '2026-09-30');
        self::assertNull($c['projection_uncertain']);
        self::assertLessThan(586.50, $c['projection_band']['low']);
        self::assertGreaterThan(586.50, $c['projection_band']['high']);
    }

    /**
     * Sechs Monate auseinander: unsicher, Punktwert stimmt, Spanne von Hand. Geschätzt ab 30.04. 189 Tage × 15 × 0,30 = 850,50 €;
     * schon gemessen hat das spätere Mitglied bis 31.10. 184 Tage: A 184 × 10 × 0,30 = 552,00 €, B 184 × 5 × 0,30 = 276,00 €.
     * Ohne jeden Stand bleiben 298,50 € bzw. 574,50 €, davon ± 50 %: ± 149,25 € bzw. ± 287,25 € um 586,50 €.
     *
     * @param array{0:float,1:float} $range
     */
    #[TestWith(['2026-10-31', '2026-04-30', [437.25, 735.75]])]
    #[TestWith(['2026-04-30', '2026-10-31', [299.25, 873.75]])]
    public function testALongSpreadIsMarkedUncertainWithAHonestRange(string $lastA, string $lastB, array $range): void
    {
        $c = $this->groupStatus($lastA, $lastB);
        $unc = $c['projection_uncertain'];
        self::assertSame(['group_readings_differ'], $unc['reasons']);
        self::assertSame(['earliest' => '2026-04-30', 'latest' => '2026-10-31'], $unc['group_readings']);
        self::assertEqualsWithDelta($range[0], $unc['projected_range'][0], 0.02);
        self::assertEqualsWithDelta($range[1], $unc['projected_range'][1], 0.02);
        self::assertNull($c['suggested_advance'], 'kein einzelner Vorschlag aus einer unsicheren Schätzung');
        // der Satz nennt beide Tage, der Grund landet nicht beim Heizmodell
        $why = (new ReportAdviceService($this->i18n))->uncertainWhy($unc);
        self::assertStringContainsString('30.04.2026', $why);
        self::assertStringContainsString('31.10.2026', $why);
    }

    /** Bei gleichem Stand ändert sich an der Unsicherheit nichts (kein neuer Grund). */
    public function testTheSameDayCarriesNoGroupReason(): void
    {
        self::assertNull($this->groupStatus('2026-10-31', '2026-10-31')['projection_uncertain']);
    }

    /** Der laufend angemessene Abschlag kommt aus allen Mitgliedern: 149 €, nicht 141 € (A einen Monat später, bis v3.11). */
    public function testTheAdviceOnTheAdvanceCountsAllMembers(): void
    {
        self::assertSame(149.0, $this->groupStatus('2026-10-31', '2026-09-30')['advance_ongoing']);
    }

    /** Übersicht und Home Assistant lesen denselben Saldo an jedem Mitglied (`group_contract.contract`). */
    public function testTheSummaryCarriesTheSameBalance(): void
    {
        $this->groupStatus('2026-10-31', '2026-09-30');
        $rows = array_column($this->summary()['meters'], null, 'meter_id');
        foreach ([$this->a, $this->b] as $id) {
            $g = $rows[$id]['group_contract']['contract'];
            self::assertSame(407.60, $g['balance']);
            self::assertSame(586.50, $g['projected_end_balance']);
            self::assertSame(434.93, $g['measured_balance']);
        }
    }

    /**
     * Ein Vertrag, der vor dem frühesten Stand endet, hat nichts zu schätzen: Seine Zeilen zählen ganz, auch im Monat, in dem der
     * früheste Stand liegt. Von Hand: 01.01.–15.09. = 258 Tage × 15 × 0,30 = 1.161,00 € + Grundpreis 8 × 12 + 12 × 15/30 = 102,00 €
     * − Abschläge 8 × 100 + 100 × 15/30 = 850,00 € = +413,00 €.
     */
    public function testAContractThatEndedBeforeTheEarliestReadingKeepsItsRows(): void
    {
        $this->read('2026-10-31', '2026-09-30');
        $this->contracts->create('strom', [
            'meter_group_id' => $this->gid, 'provider' => 'Alt', 'start' => '2026-01-01', 'end' => '2026-09-15', 'auto_renews' => false,
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 100.0]],
        ]);
        $this->contracts->create('strom', [
            'meter_group_id' => $this->gid, 'provider' => 'Neu', 'start' => '2026-09-16',
            'working_prices' => [['from' => '2026-09-16', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2026-09-16', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2026-09-16', 'amount_eur' => 100.0]],
        ]);
        $group = $this->meters->groupTarget('strom', $this->gid);
        $rows = array_column($this->consumption->contractStatus('strom', $group, self::TODAY)['contracts'], null, 'provider');
        self::assertEqualsWithDelta(413.00, $rows['Alt']['current_balance'], 0.01);
    }

    /** Gleich große Mitglieder (kein großer Teil, der die Summe trägt): dieselbe Wahrheit. */
    public function testEqualMembersGiveTheSameTruth(): void
    {
        $this->read('2026-09-30', '2026-10-31', 8.0, 8.0);
        $this->contracts->create('strom', [
            'meter_group_id' => $this->gid, 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 100.0]],
        ]);
        $group = $this->meters->groupTarget('strom', $this->gid);
        $c = array_values(array_filter($this->consumption->contractStatus('strom', $group, self::TODAY)['contracts'], fn($c) => !empty($c['is_current'])))[0];
        // 308 × 16 × 0,30 = 1.478,40 + 121,60 − 1.100,00 = 500,00; 365 × 16 × 0,30 = 1.752,00 + 144,00 − 1.200,00 = 696,00
        self::assertSame(500.00, $c['current_balance']);
        self::assertSame(696.00, $c['projected_end_balance']);
    }
}
