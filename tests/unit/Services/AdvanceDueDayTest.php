<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Config\Countries;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BackupInvalidException;
use Energietracker\Services\BackupService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\InstanceService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\Rights\ContractChecks;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\SettingsService;
use Energietracker\Services\StromSaldoService;
use Energietracker\Services\SummaryService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket wechsel) — „Abschlag zählen“, eine Einstellung für alle Verträge: tagesanteilig (Standard, wie bis
 * v3.12) oder ganze Rate an jedem Fälligkeitstag im Vertrag (`advance_counting` = `due_day`, Tag `advance_due_day`,
 * am Monatsende geklemmt). Gewirkt an einer Stelle (ContractService::effectiveAdvanceSchedule); alle Tage fest.
 *
 * Von Hand (doc-claim advance-due-day-example, docs/verstehen/00-overview.md §10): Abschlag 90 € monatlich, fällig
 * am 1., Vertrag 01.01. bis 14.07.2026, Kosten 600 € (300 kWh je Monat zu 30 ct, im Juli 200 kWh bis zum 14.):
 *   tagesanteilig  6 × 90 € + 90 € × 14/31 = 580,65 €   →  Nachzahlung 19,35 €
 *   ganze Rate     7 × 90 €                 = 630,00 €   →  Guthaben    30,00 €
 */
#[CoversClass(ContractService::class)]
#[CoversClass(SettingsService::class)]
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ForecastService::class)]
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(BillService::class)]
final class AdvanceDueDayTest extends ServiceTestCase
{
    use ReportFixture;

    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_w', 'serial' => null, 'installed_on' => '2024-12-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
    }

    /** Stände am Monatsersten von $from bis $until (300 kWh je Monat), dazu ein letzter Stand $tail = [Tag, kWh seit dem Monatsersten]. */
    private function readingsMonthly(string $from, string $until, ?array $tail = null): void
    {
        $rows = []; $c = 0.0;
        for ($d = new \DateTimeImmutable($from); $d->format('Y-m-d') <= $until; $d = $d->modify('first day of next month')) {
            $rows[] = ['date' => $d->format('Y-m-d'), 'counter' => $c, 'device_id' => 'd_w'];
            $c += 300.0;
        }
        if ($tail !== null) $rows[] = ['date' => $tail[0], 'counter' => $c - 300.0 + $tail[1], 'device_id' => 'd_w'];
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function contract(array $extra = []): array
    {
        return $this->contracts->create('strom', $extra + ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt',
            'start' => '2026-01-01', 'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 90]]]);
    }

    private function wholeRate(int $day): void
    {
        $this->settings->set(['advance_counting' => 'due_day', 'advance_due_day' => $day]);
    }

    private function meter(): array
    {
        return $this->meters->get('strom', $this->meterId);
    }

    private function card(string $today, int $i = 0): array
    {
        return $this->consumption->contractStatus('strom', $this->meter(), $today)['contracts'][$i];
    }

    /** @return array<string,array<string,mixed>> Monatszeilen nach ym */
    private function rows(string $today): array
    {
        return $this->consumption->atDate($today, fn() => array_column($this->consumption->forMeter('strom', $this->meter()), null, 'ym'));
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function periods(): BillingPeriodService
    {
        return new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills());
    }

    /** Das Beispiel der Doku: Vertrag bis 14.07., gelesen am 01.08.2026. */
    private function handExample(): array
    {
        $this->readingsMonthly('2026-01-01', '2026-07-01', ['2026-07-15', 200.0]);
        return $this->contract(['end' => '2026-07-14', 'auto_renews' => false,
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 90]]]);
    }

    public function testTheHandExampleProRataAndAsWholeRates(): void
    {
        $this->handExample();
        $today = '2026-08-01';

        // tagesanteilig (Standard, nichts gespeichert)
        $c = $this->card($today);
        self::assertEqualsWithDelta(600.0, $c['cost_to_date'], 0.005, 'Kosten 600 €');
        self::assertEqualsWithDelta(580.65, $c['advance_paid'], 0.005);
        self::assertEqualsWithDelta(19.35, $c['current_balance'], 0.005, 'Nachzahlung 19,35 €');
        self::assertEqualsWithDelta(19.35, $c['projected_end_balance'], 0.005);
        $july = $this->rows($today)['2026-07'];
        self::assertSame(40.65, $july['advance_eur'], '90 € × 14/31');
        self::assertArrayNotHasKey('advance_dates', $july);
        // v3.13.0 (Paket K2a) — das Abrechnungsjahr bis zur letzten Ablesung (15.07.) zählt dieselben 14 Julitage; bis
        // dahin kürzte es den schon gekürzten Juli-Abschlag noch einmal um 14/31 (558,36 €)
        $period = $this->periods()->forTarget('strom', $this->meter(), false, $today)['periods'][0];
        self::assertEqualsWithDelta(580.65, $period['advances'], 0.005, 'Abrechnungsjahr tagesanteilig');

        // ganze Rate am 1. — nur die Einstellung ändert sich, dieselben Dienste rechnen sofort neu
        $this->wholeRate(1);
        $c = $this->card($today);
        self::assertEqualsWithDelta(630.0, $c['advance_paid'], 0.005, 'sieben Raten');
        self::assertEqualsWithDelta(-30.0, $c['current_balance'], 0.005, 'Guthaben 30,00 €');
        self::assertEqualsWithDelta(-30.0, $c['projected_end_balance'], 0.005);
        $july = $this->rows($today)['2026-07'];
        self::assertSame(90.0, $july['advance_eur'], 'die ganze Rate vom 01.07.');
        self::assertSame([['date' => '2026-07-01', 'amount_eur' => 90.0]], array_map(fn($r) => array_intersect_key($r, ['date' => 1, 'amount_eur' => 1]), $july['advance_dates']));
        $period = $this->periods()->forTarget('strom', $this->meter(), false, $today)['periods'][0];
        self::assertEqualsWithDelta(630.0, $period['advances'], 0.005, 'Abrechnungsjahr: sieben Raten');
        // Schlussrechnung bis zum letzten Vertragstag: „Rechnung prüfen“ und die Periode der Rechnung zählen dieselben Raten
        $bill = $this->bills()->create('strom', ['meter_id' => $this->meterId, 'period_from' => '2026-01-01', 'period_to' => '2026-07-14',
            'issued_on' => '2026-07-31', 'invoice' => ['energy_kwh' => 2000.0, 'amount_eur' => 600.0, 'advances_paid_eur' => 630.0]]);
        self::assertEqualsWithDelta(630.0, $this->bills()->compare('strom', $bill['id'])['ours']['advances'], 0.005, 'Rechnung prüfen: sieben Raten');
        $period = $this->periods()->forTarget('strom', $this->meter(), false, $today)['periods'][0];
        self::assertSame(['2026-01-01', '2026-07-14'], [$period['from'], $period['until']]);
        self::assertEqualsWithDelta(630.0, $period['advances'], 0.005, 'Periode der Rechnung: sieben Raten');
        // Jahresbericht: Anhang „Abrechnungsjahre“ mit denselben Raten
        $report = $this->reportServices()['model']->build(2026, ['today' => $today, 'billing' => true]);
        $appendix = array_values(array_filter((array)$report['billing'], fn($x) => $x['utility'] === 'strom'))[0] ?? null;
        self::assertNotNull($appendix);
        self::assertSame($this->i18n->number(630.0, 2), $appendix['rows'][0][5], 'Spalte Abschläge');

        // zurück auf tagesanteilig: wieder genau die alte Zahl
        $this->settings->set(['advance_counting' => 'pro_rata']);
        self::assertEqualsWithDelta(19.35, $this->card($today)['current_balance'], 0.005);
    }

    /** Der Fälligkeitstag zählt am ersten und am letzten Vertragstag, einer davor bzw. danach nicht. */
    public function testDueDaysOnTheFirstAndTheLastDayOfTheContract(): void
    {
        $this->readingsMonthly('2026-01-01', '2026-07-01', ['2026-07-15', 200.0]);
        $this->contract(['start' => '2026-01-15', 'end' => '2026-07-14', 'auto_renews' => false]);
        $jan = fn() => $this->rows('2026-08-01')['2026-01']['advance_eur'];
        $jul = fn() => $this->rows('2026-08-01')['2026-07']['advance_eur'];
        self::assertSame([49.35, 40.65], [$jan(), $jul()], 'tagesanteilig: 90 € × 17/31 und × 14/31');
        $this->wholeRate(1);
        self::assertSame([0.0, 90.0], [$jan(), $jul()], 'am 1.: der 01.01. liegt vor dem Beginn, der 01.07. zählt');
        $this->wholeRate(15);
        self::assertSame([90.0, 0.0], [$jan(), $jul()], 'am 15.: der Beginn ist ein Fälligkeitstag, der 15.07. liegt nach dem Ende');
        // Ablesung am 15.07., einen Tag nach dem Ende: die Rate an diesem Tag gehört nicht mehr zum Vertrag
        self::assertEqualsWithDelta(540.0, $this->card('2026-08-01')['balance_measured']['advance'], 0.005, 'sechs Raten 15.01. bis 15.06.');
        $this->wholeRate(14);
        self::assertSame([0.0, 90.0], [$jan(), $jul()], 'am 14.: der 14.01. liegt vor dem Beginn, der letzte Tag 14.07. zählt');
        self::assertEqualsWithDelta(540.0, $this->card('2026-08-01')['advance_paid'], 0.005, 'sechs Raten 14.02. bis 14.07.');
    }

    /**
     * Lieferantenwechsel zum 15.07. (zwei Verträge im Juli): tagesanteilig teilen sie den Juli nach Tagen, mit ganzer
     * Rate am 1. zahlt der alte Vertrag den Juli ganz und der neue beginnt mit der Rate am 01.08. Die Periode des alten
     * Vertrags endet am Wechsel (14.07.) und zählt seine Raten.
     */
    public function testASupplierSwitchOnThe15th(): void
    {
        $this->readingsMonthly('2026-01-01', '2026-09-01');
        $a = $this->contract(['end' => '2026-07-14', 'auto_renews' => false, 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 90]]]);
        $b = $this->contract(['provider' => 'Energie Musterland', 'start' => '2026-07-15', 'working_prices' => [['from' => '2026-07-15', 'ct_per_kwh' => 32.0]],
            'advance_payments' => [['from' => '2026-07-15', 'amount_eur' => 100]]]);
        $today = '2026-09-10';
        $paid = function () use ($today, $a, $b): array {
            $by = array_column($this->consumption->contractStatus('strom', $this->meter(), $today)['contracts'], 'advance_paid', 'contract_id');
            return [$by[$a['id']], $by[$b['id']]];
        };
        $july = fn() => array_column($this->rows($today)['2026-07']['contract_parts'], 'advance_eur', 'contract_id');
        $first = fn() => array_values(array_filter($this->periods()->forTarget('strom', $this->meter(), false, $today)['periods'],
            fn($p) => $p['from'] === '2026-01-01'))[0];
        // tagesanteilig: 90 € × 14/31 und 100 € × 17/31; der neue Vertrag zahlt dazu August und den laufenden September
        self::assertEquals([$a['id'] => 40.65, $b['id'] => 54.84], $july());
        self::assertEqualsWithDelta(580.65, $paid()[0], 0.005);
        self::assertEqualsWithDelta(254.84, $paid()[1], 0.005);
        self::assertSame('2026-07-14', $first()['until'], 'die Periode endet am Wechsel');
        self::assertEqualsWithDelta(580.65, $first()['advances'], 0.005);
        $this->wholeRate(1);
        self::assertEquals([$a['id'] => 90.0, $b['id'] => 0.0], $july());
        self::assertSame([630.0, 200.0], $paid(), 'alter Vertrag sieben Raten, neuer die Raten vom 01.08. und 01.09.');
        self::assertEqualsWithDelta(630.0, $first()['advances'], 0.005);
    }

    /**
     * Der Rat aus v3.12.0 („Abschlag mit Terminen eintragen oder die Sonderzahlung buchen“), nach dem Umschalten:
     * Eine Zeile mit Termin löst die monatliche ab ihrem Beginn ab (Zeilen zählen nie nebeneinander) und zählt in
     * beiden Formen gleich; eine gebuchte Abschlagszahlung über den Rest zählt mit ganzer Rate doppelt.
     */
    public function testTheAdviceOf3120AfterSwitching(): void
    {
        $this->readingsMonthly('2026-01-01', '2026-07-01', ['2026-07-15', 200.0]);
        $base = ['end' => '2026-07-14', 'auto_renews' => false, 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]]];
        // (a) Juli als Termin: die monatliche Zeile bis 30.06., ab 01.07. eine Zeile mit dem Termin 01.07.
        $a = $this->contract($base + ['advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 90],
            ['from' => '2026-07-01', 'amount_eur' => 90, 'due_dates' => ['2026-07-01']]]]);
        $card = fn() => $this->card('2026-08-01');
        self::assertEqualsWithDelta(630.0, $card()['advance_paid'], 0.005, 'tagesanteilig: sechs Monate und der Termin');
        $this->wholeRate(1);
        self::assertEqualsWithDelta(630.0, $card()['advance_paid'], 0.005, 'ganze Rate: dieselben sieben Raten, nichts doppelt');
        // (b) Rest als Abschlagszahlung gebucht (90 € − 40,65 € = 49,35 €)
        $this->settings->set(['advance_counting' => 'pro_rata']);
        $this->contracts->update('strom', $a['id'], ['advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 90]],
            'special_payments' => [['date' => '2026-07-01', 'kind' => 'abschlagszahlung', 'amount_eur' => 49.35]]]);
        self::assertEqualsWithDelta(-30.0, $card()['current_balance'], 0.005, 'tagesanteilig mit Sonderzahlung: wie die Rechnung');
        $this->wholeRate(1);
        self::assertEqualsWithDelta(-79.35, $card()['current_balance'], 0.005, 'ganze Rate mit Sonderzahlung: der Rest zählt doppelt');
    }

    /** Tag 31: in jedem Monat neu auf sein Ende geklemmt, im Schaltjahr der 29.02. */
    public function testDueDay31IsClampedPerMonth(): void
    {
        $c = $this->contract();
        $this->wholeRate(31);
        $plan = $this->contracts->effectiveAdvanceSchedule($c);
        self::assertSame(31, $plan[0]['due_day']);
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
            array_column(ContractService::installments($plan, '2026-01-01', '2026-05-01'), 'date'));
        self::assertSame(['2028-02-29', '2028-03-31'], array_column(ContractService::installments($plan, '2028-02-01', '2028-04-01'), 'date'));
        $this->readingsMonthly('2026-01-01', '2026-04-01');
        self::assertSame('2026-02-28', $this->rows('2026-04-10')['2026-02']['advance_dates'][0]['date']);
        // gespeichert wird das Kennzeichen nie
        self::assertArrayNotHasKey('due_day', $this->contracts->get('strom', $c['id'])['advance_payments'][0]);
    }

    /** Neuer Abschlag ab dem 15.: Es zählt der Betrag der Planzeile, die am Fälligkeitstag gilt. */
    public function testANewAmountFromThe15thCountsOnTheDueDaysAfterIt(): void
    {
        $this->readingsMonthly('2026-01-01', '2026-06-01');
        $this->contract(['advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 90], ['from' => '2026-03-15', 'amount_eur' => 120]]]);
        $march = fn() => [$this->rows('2026-06-10')['2026-03']['advance_eur'], $this->rows('2026-06-10')['2026-04']['advance_eur']];
        self::assertSame([90.0, 120.0], $march(), 'tagesanteilig: der Betrag am Monatsersten');
        $this->wholeRate(10);
        self::assertSame([90.0, 120.0], $march(), 'am 10.: der 10.03. liegt vor dem neuen Betrag');
        $this->wholeRate(20);
        self::assertSame([120.0, 120.0], $march(), 'am 20.: der 20.03. hat schon den neuen Betrag');
    }

    /** Eine Zeile mit Rhythmus neben einer monatlichen: Nur die monatliche bekommt den Fälligkeitstag. */
    public function testARowWithRhythmStaysAsItIs(): void
    {
        $c = $this->contract(['advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 90], ['from' => '2026-04-01', 'amount_eur' => 250, 'every_months' => 3]]]);
        $before = ContractService::installments($this->contracts->effectiveAdvanceSchedule($c), '2026-01-01', '2027-01-01');
        self::assertSame(['2026-04-01', '2026-07-01', '2026-10-01'], array_column($before, 'date'), 'tagesanteilig: nur die Raten mit Rhythmus');
        $this->wholeRate(20);
        $plan = $this->contracts->effectiveAdvanceSchedule($c);
        self::assertSame([20, null], [$plan[0]['due_day'] ?? null, $plan[1]['due_day'] ?? null]);
        self::assertSame([['2026-01-20', 90.0], ['2026-02-20', 90.0], ['2026-03-20', 90.0], ['2026-04-01', 250.0], ['2026-07-01', 250.0], ['2026-10-01', 250.0]],
            array_map(fn($r) => [$r['date'], $r['amount_eur']], ContractService::installments($plan, '2026-01-01', '2027-01-01')));
    }

    /** Eine Sonderzahlung „mit Auswirkung“ setzt einen neuen Abschlag: Auch er zählt am Fälligkeitstag ganz. */
    public function testTheAdvanceOfASpecialPaymentWithEffectGetsTheDueDay(): void
    {
        $this->readingsMonthly('2026-01-01', '2026-06-01');
        $c = $this->contract(['special_payments' => [['date' => '2026-04-05', 'kind' => 'nachzahlung_mit', 'amount_eur' => 50,
            'new_advance_eur' => 120, 'advance_from' => '2026-04-10']]]);
        self::assertSame(90.0, $this->rows('2026-06-10')['2026-04']['advance_eur'], 'tagesanteilig: der Betrag am 01.04.');
        $this->wholeRate(20);
        $plan = $this->contracts->effectiveAdvanceSchedule($c);
        self::assertSame(['2026-04-10', 20], [$plan[1]['from'], $plan[1]['due_day'] ?? null], 'die eingemischte Zeile');
        self::assertSame(120.0, $this->rows('2026-06-10')['2026-04']['advance_eur'], 'am 20.04. gilt schon der neue Abschlag');
    }

    /**
     * Laufender Monat: Mit „ganze Rate am 15.“ springt der Saldo heute am Fälligkeitstag um eine Rate; tagesanteilig
     * zählt der laufende Monat ganz (wie bis v3.12). Abrechnung am Jahresende: der erwartete Saldo bleibt gleich.
     */
    public function testTheRunningMonthBeforeAndOnTheDueDay(): void
    {
        $this->readingsMonthly('2026-01-01', '2026-06-01');
        $this->contract();
        $p14 = $this->card('2026-06-14');
        $p15 = $this->card('2026-06-15');
        self::assertSame([540.0, 540.0], [$p14['advance_paid'], $p15['advance_paid']], 'tagesanteilig: Januar bis Juni');
        $this->wholeRate(15);
        $c14 = $this->card('2026-06-14');
        $c15 = $this->card('2026-06-15');
        self::assertSame([450.0, 540.0], [$c14['advance_paid'], $c15['advance_paid']], 'ganze Rate: die Juni-Rate am 15.06.');
        // von einem Tag zum nächsten kommt ein Tag Kosten dazu; mit ganzer Rate am 15. zusätzlich die Rate
        self::assertEqualsWithDelta(90.0, $c14['current_balance'] - $p14['current_balance'], 0.01, 'am 14.06. eine Rate weniger gezahlt');
        self::assertEqualsWithDelta(0.0, $c15['current_balance'] - $p15['current_balance'], 0.01, 'am 15.06. wieder gleich');
        self::assertEqualsWithDelta($p14['projected_end_balance'], $c14['projected_end_balance'], 0.01, 'zur Abrechnung zählen dieselben zwölf Raten');
        self::assertEqualsWithDelta($p15['projected_end_balance'], $c15['projected_end_balance'], 0.01);
        self::assertSame(['2026-06-15', '2026-07-15'], array_slice($c14['next_advance_dates'], 0, 2), 'Karte: nächster Termin');
        self::assertSame(1, $c14['current_advance_every_months'], 'monatlich bleibt monatlich');
        self::assertSame([7, 6], [$c14['months_left'], $c15['months_left']], 'Raten bis zur Abrechnung, nicht Monate');
    }

    /**
     * (ii) Letzte Ablesung am Fälligkeitstag (15.06.): Eine Ablesung am Tag D ist die Grenze zu Beginn von D (Nachtrag 2).
     * „Saldo am 15.06.“, Kachel (`advance_measured_eur`) und laufende Periode zählen die Raten vor diesem Tag; die
     * Juni-Rate gehört zum nächsten Zeitraum, wie der tagesanteilige Teil (90 € × 14/30). Dieselbe Regel wie am 1.
     */
    public function testTheMeasuredBalanceCountsTheRatesBeforeTheReading(): void
    {
        $this->readingsMonthly('2026-01-01', '2026-06-01', ['2026-06-15', 140.0]);
        $this->contract();
        $three = function (): array {
            $june = $this->rows('2026-06-20')['2026-06']['advance_measured_eur'];
            $running = array_values(array_filter($this->periods()->forTarget('strom', $this->meter(), false, '2026-06-20')['periods'], fn($p) => !empty($p['running'])))[0];
            return [$this->card('2026-06-20')['balance_measured']['advance'], $june, $running['advances']];
        };
        self::assertSame('2026-06-15', $this->card('2026-06-20')['balance_measured']['until']);
        self::assertEquals([492.0, 42.0, 492.0], $three(), 'tagesanteilig: Januar bis Mai und 14 Junitage (Karte, Juni-Zeile, Periode)');
        $this->wholeRate(15);
        self::assertEquals([450.0, 0.0, 450.0], $three(), 'ganze Rate am 15.: die Rate vom Ablesetag 15.06. zählt noch nicht');
        self::assertEqualsWithDelta(540.0, $this->card('2026-06-20')['advance_paid'], 0.005, 'Saldo heute am 20.06.: die Juni-Rate zählt');
        $this->wholeRate(14);
        self::assertEquals([540.0, 90.0, 540.0], $three(), 'am 14.: die Juni-Rate liegt vor der Ablesung');
    }

    /**
     * (iv) Termin mit Rhythmus genau am Ablesetag (Standard): wie bis v3.12, die Rate dieses Tags zählt in „Saldo am
     * ‹Ablesung›“, Kachel und Periode noch nicht (dieselbe Regel wie die ganze Rate).
     */
    public function testARateWithRhythmOnTheReadingDay(): void
    {
        $this->readingsMonthly('2026-01-01', '2026-06-01', ['2026-06-15', 140.0]);
        $this->contract(['advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 180, 'due_dates' => ['2026-02-15', '2026-04-15', '2026-06-15']]]]);
        $three = function (): array {
            $tile = array_sum(array_map(fn($r) => (float)($r['advance_measured_eur'] ?? $r['advance_eur'] ?? 0), $this->rows('2026-06-20')));
            $running = array_values(array_filter($this->periods()->forTarget('strom', $this->meter(), false, '2026-06-20')['periods'], fn($p) => !empty($p['running'])))[0];
            return [$this->card('2026-06-20')['balance_measured']['advance'], $tile, $running['advances']];
        };
        self::assertEquals([360.0, 360.0, 360.0], $three(), 'drei Termine, der letzte am Ablesetag: Karte, Kachel, Periode zählen die zwei davor');
        self::assertEqualsWithDelta(540.0, $this->card('2026-06-20')['advance_paid'], 0.005, 'Saldo heute: alle drei');
    }

    /** Ablesung am 1.: dieselbe Regel wie mitten im Monat, die Rate dieses Tags zählt in „Saldo am ‹Ablesung›“ noch nicht. */
    public function testAReadingOnTheFirstLeavesThatDaysRateForLater(): void
    {
        $this->readingsMonthly('2026-01-01', '2026-07-01');
        $this->contract();
        $this->wholeRate(1);
        $c = $this->card('2026-07-20');
        self::assertSame('2026-07-01', $c['balance_measured']['until']);
        self::assertEqualsWithDelta(540.0, $c['balance_measured']['advance'], 0.005, 'Januar bis Juni, die Juli-Rate vom 01.07. noch nicht');
        self::assertEqualsWithDelta(630.0, $c['advance_paid'], 0.005, 'Saldo heute: die Juli-Rate zählt');
    }

    /**
     * Ablesung am ersten Tag der nächsten Abrechnungsperiode (Stichtag 15.06., Ablesung 15.06.): Die abgeschlossene
     * Periode endet am 14.06. mit ihrem Endstand; die Rate vom 15.06. gehört nicht mehr zu ihr.
     */
    public function testAReadingOnTheFirstDayOfTheNextPeriodKeepsItsRateThere(): void
    {
        $this->settings->set(['billing_cycle_anchor_strom' => '06-15']);
        $this->readingsMonthly('2025-06-01', '2026-06-01', ['2026-06-15', 140.0]);
        $this->contract(['start' => '2025-01-01']);
        $this->wholeRate(15);
        $closed = array_values(array_filter($this->periods()->forTarget('strom', $this->meter(), false, '2026-06-20')['periods'],
            fn($p) => $p['from'] === '2025-06-15'))[0];
        self::assertSame('2026-06-14', $closed['until']);
        self::assertEqualsWithDelta(12 * 90.0, $closed['advances'], 0.005, 'zwölf Raten 15.06.2025 bis 15.05.2026, nicht die vom 15.06.2026');
    }

    /** Werkstatt: Die Rate am Ablesetag gehört zum Rest des Monats (die Periode endet vor dem Ablesetag). */
    public function testTheWorkshopRestCountsTheReadingDayRate(): void
    {
        $running = ['as_of' => '2026-06-14', 'until' => '2026-12-31'];
        $row = ['ym' => '2026-06', 'days' => 14, 'kwh_cost' => 42.0, 'base_price_eur' => 0.0, 'advance_eur' => 90.0,
                'advance_dates' => [['date' => '2026-06-15', 'amount_eur' => 90.0]]];
        $rest = ScenarioService::restOfMonth($running, [['ym' => '2026-07', 'days' => 31]], [$row]);
        self::assertSame(16, $rest['days']);
        self::assertSame(90.0, $rest['advance'], 'die Rate vom Ablesetag 15.06. steht nicht in der Periode, darum im Rest');
        $row['advance_dates'] = [['date' => '2026-06-14', 'amount_eur' => 90.0]];
        self::assertSame(0.0, ScenarioService::restOfMonth($running, [['ym' => '2026-07', 'days' => 31]], [$row])['advance'], 'eine Rate vor dem Ablesetag steht in der Periode');
    }

    /**
     * (i) Jahresablesung am 01.01., ganze Rate 90 € am 1., Abrechnungsjahr wie das Kalenderjahr (doc-claim
     * advance-reading-day, docs/verstehen/00-overview.md §10): Das Jahr 2025 zählt zwölf Raten (1.080 €), die Rate vom
     * 01.01.2026 gehört zu 2026 („Saldo heute“ 90 €, noch kein gemessener Tag). Mit „Rate am Ablesetag zählt“ wären es
     * dreizehn. Endet der Vertrag am 31.12., zählt „Saldo am 01.01.“ der Karte dieselben zwölf.
     */
    public function testAnAnnualReadingOnTheFirstOfJanuaryCountsTwelveRates(): void
    {
        $this->readingsMonthly('2025-01-01', '2026-01-01');
        $c = $this->contract(['start' => '2025-01-01']);
        $this->wholeRate(1);
        $year2025 = fn(): array => array_values(array_filter($this->periods()->forTarget('strom', $this->meter(), false, '2026-01-01')['periods'],
            fn($p) => $p['from'] === '2025-01-01'))[0];
        self::assertSame(['2025-12-31', '2025-12-31'], [$year2025()['until'], $year2025()['as_of']]);
        self::assertEqualsWithDelta(12 * 90.0, $year2025()['advances'], 0.005, 'zwölf Raten 01.01. bis 01.12.2025');
        $card = $this->card('2026-01-01');
        self::assertSame('2026-01-01', $card['balance_from']);
        self::assertNull($card['balance_measured'], 'am 01.01. noch kein gemessener Tag im Jahr 2026');
        self::assertEqualsWithDelta(90.0, $card['advance_paid'], 0.005, 'Saldo heute 2026: die Rate vom 01.01.2026');
        // derselbe Stand, der Vertrag endet am 31.12.2025: „Saldo am 01.01.“ der Karte wie das Abrechnungsjahr
        $this->contracts->update('strom', $c['id'], ['end' => '2025-12-31', 'auto_renews' => false]);
        $ended = $this->card('2026-01-20');
        self::assertSame('2026-01-01', $ended['balance_measured']['until']);
        self::assertEqualsWithDelta(12 * 90.0, $ended['balance_measured']['advance'], 0.005, 'Karte: zwölf Raten');
        self::assertEqualsWithDelta(12 * 90.0, $year2025()['advances'], 0.005, 'Abrechnungsjahr: zwölf Raten');
        // das Beispiel der Doku (DE, EN) rechnet die App nach (Lektion 99)
        $root = dirname(__DIR__, 3);
        $de = fn(float $v) => number_format($v, 2, ',', '.') . ' €';
        $en = fn(float $v) => '€' . number_format($v, 2);
        foreach (['docs/verstehen/00-overview.md' => $de, 'docs/en/verstehen/00-overview.md' => $en] as $file => $fmt) {
            $text = (string)file_get_contents("$root/$file");
            $pos = strpos($text, '<!-- doc-claim: advance-reading-day');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            $block = substr($text, $pos, (int)strpos($text, "```\n", (int)strpos($text, '```text', $pos) + 7) - $pos);
            foreach ([12 * 90.0, $card['advance_paid']] as $v) self::assertStringContainsString($fmt($v), $block, "$file: " . $fmt($v));
        }
    }

    /**
     * (iii) Schlussablesung am letzten Vertragstag 15.07., die Rate an genau diesem Tag fällig: Wie im Abrechnungsjahr
     * (`$cap`) zählt eine Ablesung am letzten Tag des Zeitraums diesen Tag mit; Karte und Periode der Schlussrechnung
     * zählen gleich (sieben Raten 15.01. bis 15.07.). Bis Nachtrag 2 endete die Karte vor dem Ablesetag (540 €).
     */
    public function testAFinalReadingOnTheLastDayOfTheContract(): void
    {
        $this->readingsMonthly('2026-01-01', '2026-07-01', ['2026-07-15', 200.0]);
        $this->contract(['end' => '2026-07-15', 'auto_renews' => false]);
        $this->bills()->create('strom', ['meter_id' => $this->meterId, 'period_from' => '2026-01-01', 'period_to' => '2026-07-15', 'issued_on' => '2026-07-31',
            'invoice' => ['energy_kwh' => 2000.0, 'amount_eur' => 600.0, 'advances_paid_eur' => 630.0]]);
        $pair = function (): array {
            $final = array_values(array_filter($this->periods()->forTarget('strom', $this->meter(), false, '2026-08-01')['periods'],
                fn($p) => $p['from'] === '2026-01-01'))[0];
            $m = $this->card('2026-08-01')['balance_measured'];
            return [$m['until'], $final['until'], round($m['advance'], 2), round($final['advances'], 2)];
        };
        $this->wholeRate(15);
        self::assertSame(['2026-07-15', '2026-07-15', 630.0, 630.0], $pair(), 'ganze Rate am 15.: Karte und Schlussrechnung je sieben Raten');
        $this->wholeRate(1);
        self::assertSame(['2026-07-15', '2026-07-15', 630.0, 630.0], $pair(), 'ganze Rate am 1.: ebenso');
        // Standard: die Karte zählt die 15 Julitage (90 € × 15/31; bis Nachtrag 2 vierzehn, 580,65 €)
        $this->settings->set(['advance_counting' => 'pro_rata']);
        self::assertEqualsWithDelta(540.0 + 90.0 * 15 / 31, $pair()[2], 0.005, 'tagesanteilig: Januar bis Juni und 15 Julitage');
    }

    /** (v) Am Fälligkeitstag zählt „Saldo heute“ die Rate schon: Karte und Rechte-Prüfung nennen den folgenden Termin. */
    public function testOnTheDueDayTheNextDateIsTheFollowingOne(): void
    {
        $this->readingsMonthly('2026-01-01', '2026-06-01');
        $c = $this->contract();
        $this->wholeRate(15);
        self::assertSame('2026-06-15', $this->card('2026-06-14')['next_advance_dates'][0], 'am Tag davor: der Fälligkeitstag');
        self::assertSame('2026-07-15', $this->card('2026-06-15')['next_advance_dates'][0], 'am Fälligkeitstag: der folgende (bis zum Nachtrag 15.06.)');
        $plan = $this->contracts->effectiveAdvanceSchedule($c);
        self::assertSame(['2026-06-15', '2026-07-15'], [ContractChecks::nextRate($plan, '2026-06-14')['due'], ContractChecks::nextRate($plan, '2026-06-15')['due']]);
        // dieselbe Regel für eine Zeile mit Rhythmus (Standard, ⚠ am Tag eines Termins)
        $this->settings->set(['advance_counting' => 'pro_rata']);
        $this->contracts->update('strom', $c['id'], ['advance_payments' => [['from' => '2026-01-15', 'amount_eur' => 180, 'every_months' => 2]]]);
        self::assertSame('2026-07-15', $this->card('2026-05-15')['next_advance_dates'][0], 'am Termin 15.05.: der folgende (bis zum Nachtrag 15.05.)');
        self::assertSame('2026-07-15', ContractChecks::nextRate($this->contracts->effectiveAdvanceSchedule($this->contracts->get('strom', $c['id'])), '2026-05-15')['due']);
    }

    /** Der Standard ändert nichts: ohne Schlüssel und mit ausdrücklich gesetztem Standard dieselben Antworten. */
    public function testTheDefaultChangesNothing(): void
    {
        $this->readingsMonthly('2025-01-01', '2026-07-01', ['2026-07-15', 200.0]);
        $c = $this->contract(['start' => '2025-01-15', 'end' => '2026-07-14', 'auto_renews' => false,
            'advance_payments' => [['from' => '2025-01-15', 'amount_eur' => 90], ['from' => '2026-03-15', 'amount_eur' => 120]]]);
        $f = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $all = fn() => json_encode([$this->contracts->effectiveAdvanceSchedule($c), $this->card('2026-06-10'), $this->rows('2026-06-10'),
            $this->consumption->atDate('2026-06-10', fn() => $f->forMeter('strom', $this->meter())),
            $this->periods()->forTarget('strom', $this->meter(), true, '2026-06-10')]);
        $before = $all();
        self::assertStringNotContainsString('due_day', $before);
        $this->settings->set(['advance_counting' => 'pro_rata', 'advance_due_day' => 1]);
        self::assertSame($before, $all(), 'ausdrücklich tagesanteilig');
        $this->settings->set(['advance_due_day' => 15]);
        self::assertSame($before, $all(), 'der Tag wirkt nur mit „ganze Rate“');
    }

    /** Lektion 99 — das Beispiel in Doku (DE, EN) und im Hinweis der Einstellung (7 Sprachen) rechnet die App nach. */
    public function testTheExampleOfDocsAndHintIsWhatTheAppComputes(): void
    {
        $this->handExample();
        $pro = $this->card('2026-08-01');
        $july = $this->rows('2026-08-01')['2026-07']['advance_eur'];
        $this->wholeRate(1);
        $whole = $this->card('2026-08-01');
        self::assertSame([580.65, 19.35, 630.0, -30.0, 40.65], [$pro['advance_paid'], $pro['current_balance'], $whole['advance_paid'], $whole['current_balance'], $july]);
        $de = fn(float $v) => number_format($v, 2, ',', '.') . ' €';
        $en = fn(float $v) => '€' . number_format($v, 2);
        $root = dirname(__DIR__, 3);
        foreach (['docs/verstehen/00-overview.md' => $de, 'docs/en/verstehen/00-overview.md' => $en] as $file => $fmt) {
            $text = (string)file_get_contents("$root/$file");
            $pos = strpos($text, '<!-- doc-claim: advance-due-day-example');
            self::assertNotFalse($pos, "$file: Beispiel fehlt");
            $block = substr($text, $pos, (int)strpos($text, "```\n", (int)strpos($text, '```text', $pos) + 7) - $pos);
            foreach ([$pro['advance_paid'], $pro['current_balance'], $whole['advance_paid'], -$whole['current_balance']] as $v) {
                self::assertStringContainsString($fmt($v), $block, "$file: " . $fmt($v));
            }
        }
        $nl = fn(float $v) => '€ ' . number_format($v, 2, ',', '.');
        foreach (['de' => $de, 'en' => $en, 'fr' => $de, 'it' => $de, 'es' => $de, 'pt' => $de, 'nl' => $nl] as $lang => $fmt) {
            // Währung als Platzhalter wie in jedem Katalog (CountryProfileTest); mit EUR setzt t() „€“ ein
            $hint = str_replace('{cur}', '€', json_decode((string)file_get_contents("$root/public/locales/$lang.json"), true)['settings']['field']['advance_counting']['hint'] ?? '');
            foreach ([$july, $pro['current_balance'], -$whole['current_balance']] as $v) {
                self::assertStringContainsString($fmt($v), $hint, "$lang: Hinweis „Abschlag zählen“ ohne " . $fmt($v));
            }
        }
    }

    /** Lektion 99 — Standard, Auswahlwerte und Grenzen in Referenz, API, Datenmodell und Hinweis wie im Code. */
    public function testTheDocsNameValuesAndLimitsAsTheCodeDoes(): void
    {
        [$min, $max] = SettingsService::NUMBER_LIMITS['advance_due_day'];
        [$mode, $day] = [SettingsService::defaultFor('advance_counting'), SettingsService::defaultFor('advance_due_day')];
        self::assertSame(['pro_rata', 1], [$mode, $day]);
        $root = dirname(__DIR__, 3);
        $text = fn(string $file): string => (string)preg_replace('/\s+/u', ' ', (string)file_get_contents("$root/$file"));
        $row = function (string $file, string $key) use ($root): string {
            foreach (explode("\n", (string)file_get_contents("$root/$file")) as $l) if (str_starts_with($l, "| `$key` |")) return $l;
            return '';
        };
        foreach (['docs/referenz/einstellungen.md' => 'bis', 'docs/en/referenz/einstellungen.md' => 'to'] as $file => $to) {
            $c = $row($file, 'advance_counting');
            self::assertStringStartsWith("| `advance_counting` | `$mode` |", $c, $file);
            foreach (SettingsService::ADVANCE_COUNTING as $v) self::assertStringContainsString("`$v`", $c, "$file: $v");
            $d = $row($file, 'advance_due_day');
            self::assertStringStartsWith("| `advance_due_day` | $day |", $d, $file);
            self::assertStringContainsString("$min $to $max", $d, $file);
        }
        foreach (['docs/referenz/api.md' => ["ist `$mode` (Default", "ganze Zahl von $min bis $max (Default $day,"],
                  'docs/en/referenz/api.md' => ["is `$mode` (default", "whole number from $min to $max (default $day,"],
                  'docs/referenz/datenmodell.md' => ["| `advance_counting`, `advance_due_day` | `$mode`, $day |", "($min bis $max"],
                  'docs/en/referenz/datenmodell.md' => ["| `advance_counting`, `advance_due_day` | `$mode`, $day |", "($min to $max"]] as $file => $want) {
            foreach ($want as $w) self::assertStringContainsString($w, $text($file), "$file: $w");
            foreach (SettingsService::ADVANCE_COUNTING as $v) self::assertStringContainsString("`$v`", $text($file), "$file: $v");
        }
        foreach (['de' => "von $min bis $max", 'en' => "from $min to $max"] as $lang => $w) {
            $hint = json_decode((string)file_get_contents("$root/public/locales/$lang.json"), true)['settings']['field']['advance_due_day']['hint'] ?? '';
            self::assertStringContainsString($w, $hint, "$lang: Hinweis zum Fälligkeitstag");
        }
    }

    /** Schnitt des Abrechnungsjahrs mitten im Monat (Stichtag 15.06.): Eine Rate gehört ganz in eine Periode. */
    public function testABillingYearCutMidMonth(): void
    {
        $this->settings->set(['billing_cycle_anchor_strom' => '06-15']);
        $this->readingsMonthly('2025-01-01', '2026-01-01');
        $this->contract(['start' => '2025-01-01']);
        $today = '2026-01-10';
        // tagesanteilig: 90 € × 16/30 (15.–30.06.) + Juli bis Dezember + der laufende Januar ganz
        self::assertEqualsWithDelta(48.0 + 540.0 + 90.0, $this->card($today)['advance_paid'], 0.005);
        $running = fn() => array_values(array_filter($this->periods()->forTarget('strom', $this->meter(), false, $today)['periods'], fn($p) => !empty($p['running'])))[0];
        self::assertEqualsWithDelta(48.0 + 540.0, $running()['advances'], 0.005, 'Abrechnungsjahr bis zur letzten Ablesung');
        $this->wholeRate(1);
        // ganze Rate am 1.: die Juni-Rate vom 01.06.2025 gehört in die Periode davor
        self::assertEqualsWithDelta(7 * 90.0, $this->card($today)['advance_paid'], 0.005, '01.07.2025 bis 01.01.2026');
        self::assertEqualsWithDelta(540.0, $running()['advances'], 0.005);
    }

    /** Prognose der Monate nach heute: Ein Vertragsende im Prognosejahr zählt die Rate ganz oder gar nicht. */
    public function testTheForecastAfterToday(): void
    {
        $this->readingsMonthly('2025-01-01', '2026-07-01');
        $this->contract(['start' => '2025-01-01', 'end' => '2026-10-14', 'auto_renews' => false]);
        $f = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $months = fn() => array_column($this->consumption->atDate('2026-07-10', fn() => $f->forMeter('strom', $this->meter()))['forecast'], 'advance_estimated', 'ym');
        $m = $months();
        self::assertSame([90.0, 90.0, 40.65], [$m['2026-08'], $m['2026-09'], $m['2026-10']], 'tagesanteilig: Oktober 14/31');
        $this->wholeRate(1);
        $m = $months();
        self::assertSame([90.0, 90.0, 90.0], [$m['2026-08'], $m['2026-09'], $m['2026-10']], 'am 1.: auch die Oktober-Rate');
        $this->wholeRate(15);
        $m = $months();
        self::assertSame([90.0, 90.0, 0.0], [$m['2026-08'], $m['2026-09'], $m['2026-10']], 'am 15.: der 15.10. liegt nach dem Ende');
    }

    /** Gruppenvertrag (HT/NT): Der Abschlag der Gruppe zählt wie jeder andere. */
    public function testAGroupContract(): void
    {
        $dev = fn(string $id) => [['id' => "d_$id", 'serial' => null, 'installed_on' => '2025-12-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $meter = fn(string $id, string $obis) => ['id' => $id, 'name' => $id, 'icon' => '', 'created_at' => '2025-12-01', 'active' => true,
            'notes' => '', 'obis' => $obis, 'meter_group_id' => 'g_dt', 'devices' => $dev($id)];
        $this->store->write('strom/meters.json', [$meter('m_ht', '1.8.1'), $meter('m_nt', '1.8.2')]);
        $this->store->write('strom/meter_groups.json', [['id' => 'g_dt', 'name' => 'Doppeltarif', 'created_at' => '2025-12-01']]);
        $rows = [];
        foreach (['m_ht', 'm_nt'] as $id) {
            for ($i = 0; $i <= 6; $i++) $rows[] = ['id' => "r_{$id}_$i", 'meter_id' => $id, 'date' => sprintf('2026-%02d-01', $i + 1),
                'counter' => 100.0 * $i, 'device_id' => "d_$id", 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            $rows[] = ['id' => "r_{$id}_7", 'meter_id' => $id, 'date' => '2026-07-15', 'counter' => 650.0, 'device_id' => "d_$id",
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write('strom/readings.json', $rows);
        $this->store->write('strom/contracts.json', [[
            'id' => 'c_dt', 'meter_id' => null, 'meter_group_id' => 'g_dt', 'provider' => 'Musterwerk', 'tariff_name' => 'Duo',
            'start' => '2026-01-01', 'end' => '2026-07-14', 'auto_renews' => false, 'notes' => '',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'working_prices_by_meter' => ['m_ht' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'm_nt' => [['from' => '2026-01-01', 'ct_per_kwh' => 20.0]]],
            'base_prices' => [], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 60.0]],
            'bonuses' => [], 'special_payments' => [], 'is_shadow' => false,
        ]]);
        $group = $this->meters->groupTarget('strom', 'g_dt');
        $paid = fn() => $this->consumption->contractStatus('strom', $group, '2026-08-01')['contracts'][0]['advance_paid'];
        self::assertEqualsWithDelta(6 * 60.0 + 60.0 * 14 / 31, $paid(), 0.005, 'tagesanteilig 387,10 €');
        $this->wholeRate(1);
        self::assertEqualsWithDelta(7 * 60.0, $paid(), 0.005, 'ganze Rate 420,00 €');
    }

    /** Wasser (Monatszeilen und Saldo nach Kalender): dieselbe Regel. */
    public function testWater(): void
    {
        $wid = $this->meters->defaultId('wasser');
        $this->store->write('wasser/readings.json', array_map(fn($i) => ['id' => "r_w_$i", 'meter_id' => $wid,
            'date' => $i < 7 ? sprintf('2026-%02d-01', $i + 1) : '2026-07-15', 'counter' => $i < 7 ? 8.0 * $i : 52.0,
            'device_id' => (string)($this->meters->get('wasser', $wid)['devices'][0]['id'] ?? ''), 'price_cents' => null, 'note' => '',
            'is_estimated' => false, 'is_future' => false], range(0, 7)));
        $this->contracts->create('wasser', ['meter_id' => $wid, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-01-01',
            'end' => '2026-07-14', 'auto_renews' => false,
            'trinkwasser' => ['working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 200.0]], 'base_prices' => []],
            'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 200.0]]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 40]]]);
        $m = $this->meters->get('wasser', $wid);
        $july = fn() => $this->consumption->atDate('2026-08-01', fn() => array_column($this->consumption->forMeter('wasser', $m), null, 'ym')['2026-07']['advance_eur']);
        $paid = fn() => $this->consumption->contractStatus('wasser', $m, '2026-08-01')['contracts'][0]['advance_paid'];
        self::assertEqualsWithDelta(40.0 * 14 / 31, $july(), 0.005);
        self::assertEqualsWithDelta(6 * 40.0 + 40.0 * 14 / 31, $paid(), 0.005);
        $this->wholeRate(1);
        self::assertEqualsWithDelta(40.0, $july(), 0.005);
        self::assertEqualsWithDelta(7 * 40.0, $paid(), 0.005);
    }

    /** Werkstatt (Rest des laufenden Monats) und Home Assistant (/api/summary) folgen der Einstellung von selbst. */
    public function testTheWorkshopAndHomeAssistantFollow(): void
    {
        $this->readingsMonthly('2025-01-01', '2026-06-01');   // die Werkstatt braucht ein Jahr Vorgeschichte
        $this->contract(['start' => '2025-01-01']);
        $today = '2026-06-14';
        $f = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $ws = fn() => (new ScenarioService($f, $this->consumption, $this->contracts, $this->meters, $this->settings,
            new TariffSwitchService($f, $this->contracts, $this->meters, $this->i18n), $this->periods()))
            ->compute(['utility' => 'strom', 'meter_id' => $this->meterId], $today)['billing']['balance_so_far'];
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $benchmark = new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings, $benchmark, $deliveries, $this->i18n);
        $agenda = new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n);
        $summary = new SummaryService($this->settings, $this->meters, $this->readings, $this->consumption, $f, $deliveries,
            new PvSummaryService($this->consumption, $this->settings), new StromSaldoService($this->consumption), $agenda, new InstanceService($this->store), $this->i18n);
        $ha = function () use ($summary, $today): float {
            $row = array_values(array_filter($summary->build('strom', null, $today)['meters'], fn($r) => ($r['id'] ?? $r['meter_id'] ?? null) === $this->meterId))[0];
            return (float)$row['contract']['balance'];
        };
        $before = [$this->card($today)['current_balance'], $ws(), $ha()];
        self::assertEqualsWithDelta($before[0], $before[1], 0.01, 'Werkstatt = Karte');
        self::assertEqualsWithDelta($before[0], $before[2], 0.01, 'Home Assistant = Karte');
        $this->wholeRate(15);
        $after = [$this->card($today)['current_balance'], $ws(), $ha()];
        self::assertEqualsWithDelta($before[0] + 90.0, $after[0], 0.01, 'die Juni-Rate am 15. ist noch nicht gezahlt');
        self::assertEqualsWithDelta($after[0], $after[1], 0.01, 'Werkstatt zieht mit');
        self::assertEqualsWithDelta($after[0], $after[2], 0.01, 'Home Assistant zieht mit');
    }

    /** Rechte-Prüfung A2 („Abschlag zu hoch“): die nächste Rate am Fälligkeitstag, zwölf im Jahr. */
    public function testTheNextRateOfTheRightsCheck(): void
    {
        $c = $this->contract();
        self::assertSame(['2026-07-01', 12], array_values(array_intersect_key(ContractChecks::nextRate($this->contracts->effectiveAdvanceSchedule($c), '2026-06-10'), ['due' => 1, 'per_year' => 1])));
        $this->wholeRate(20);
        self::assertSame(['2026-06-20', 12], array_values(array_intersect_key(ContractChecks::nextRate($this->contracts->effectiveAdvanceSchedule($c), '2026-06-10'), ['due' => 1, 'per_year' => 1])));
    }

    public function testTheSettingIsCheckedAndNormalized(): void
    {
        self::assertNull($this->settings->advanceDueDay(), 'Standard: tagesanteilig');
        self::assertSame(['pro_rata', 1], [$this->settings->get('advance_counting'), $this->settings->get('advance_due_day')]);
        $this->settings->set(['advance_due_day' => '15']);
        self::assertNull($this->settings->advanceDueDay(), 'der Tag allein schaltet nicht um');
        self::assertSame(15, $this->settings->get('advance_due_day'));
        $this->settings->set(['advance_counting' => 'due_day']);
        self::assertSame(15, $this->settings->advanceDueDay());
        $this->settings->set(['advance_due_day' => '']);
        self::assertSame(1, $this->settings->advanceDueDay(), 'leer: wieder der 1.');
        self::assertArrayNotHasKey('advance_due_day', $this->store->read('settings.json', []));
        foreach ([['advance_counting', 'monthly'], ['advance_counting', 1], ['advance_due_day', 0], ['advance_due_day', 32], ['advance_due_day', 2.5], ['advance_due_day', 'x']] as [$k, $v]) {
            try {
                $this->settings->set([$k => $v]);
                self::fail("$k = " . var_export($v, true) . ' angenommen');
            } catch (\InvalidArgumentException) {
                self::assertSame(1, $this->settings->advanceDueDay(), 'nichts geschrieben');
            }
        }
        // von Hand geändert: geklemmt, ohne Zahl der 1.; ein unbekannter Modus zählt tagesanteilig
        $this->store->write('settings.json', ['advance_counting' => 'due_day', 'advance_due_day' => 40]);
        self::assertSame(31, $this->settings->advanceDueDay());
        $this->store->write('settings.json', ['advance_counting' => 'irgendwie', 'advance_due_day' => 5]);
        self::assertNull($this->settings->advanceDueDay());
        // Länderprofile: kein Unterschied (überall der Standard)
        foreach (Countries::codes() as $code) {
            self::assertArrayNotHasKey('advance_counting', Countries::settingsFor($code), $code);
            self::assertArrayNotHasKey('advance_due_day', Countries::settingsFor($code), $code);
        }
    }

    /** Im Backup: die Einstellung kommt so zurück, ein unmöglicher Tag ist ein Problem und wird nicht eingespielt. */
    public function testTheSettingTravelsWithTheBackup(): void
    {
        $this->wholeRate(15);
        $backups = new BackupService($this->store, $this->i18n);
        $dump = $backups->export(false);
        self::assertSame(['due_day', 15], [$dump['settings']['advance_counting'] ?? null, $dump['settings']['advance_due_day'] ?? null]);
        $this->settings->set(['advance_counting' => 'pro_rata', 'advance_due_day' => 1]);
        $backups->import($dump);
        self::assertSame(15, $this->settings->advanceDueDay());
        $bad = $dump;
        $bad['settings']['advance_due_day'] = 40;
        try {
            $backups->import($bad);
            self::fail('Tag 40 eingespielt');
        } catch (BackupInvalidException $e) {
            self::assertContains('setting:advance_due_day', array_column($e->problems, 'problem'));
        }
        self::assertSame(15, $this->settings->advanceDueDay(), 'nichts eingespielt');
    }
}
