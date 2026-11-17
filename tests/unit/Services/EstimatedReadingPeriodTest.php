<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket K2a) — Ein als geschätzt erfasster Stand: Seit v3.12.0 zählt „Saldo am …“ der Karte nur bis zur letzten
 * echten Ablesung; ein geschätzter Stand gehört zu „Bis heute geschätzt“. Das Abrechnungsjahr folgt jetzt derselben Regel
 * (eine Funktion, ConsumptionService::measuredUntil): Es endet an der letzten echten Ablesung, eine Menge aus einer
 * Schätzung steht nie in `measured_kwh`. Bis dahin rechnete es bis zum geschätzten Stand und nannte dessen Menge gemessen.
 *
 * Erfunden, von Hand: Strom 8 kWh je Tag, 28 ct/kWh, Grundpreis 10 €/Monat, Abschlag 75 €/Monat ab 01.01.2026, Stichtag
 * 01.03.; echte Stände an jedem Monatsersten bis 01.08. und am 19.08., geschätzt am 28.08.; heute 03.09.2026.
 * Gemessen 01.03. bis 18.08. = 171 Tage:
 *   Menge       171 × 8 kWh = 1.368 kWh × 0,28 €   = 383,04 €
 *   Grundpreis  5 × 10 € + 10 € × 18/31           =  55,81 €
 *   Abschläge   5 × 75 € + 75 € × 18/31           = 418,55 €
 *   Saldo                                          =  20,30 €
 */
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(ConsumptionService::class)]
final class EstimatedReadingPeriodTest extends ServiceTestCase
{
    private const TODAY = '2026-09-03';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['billing_cycle_anchor_strom' => '03-01']);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_s', 'serial' => null, 'installed_on' => '2025-12-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 28.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 75]]]);
    }

    /** @param array<string,bool> $estimated Tag → geschätzt; Stand = 8 kWh je Tag seit 01.01.2026 (oder `$counter[Tag]`) */
    private function read(array $dates, array $estimated = [], array $counter = []): void
    {
        $rows = [];
        foreach ($dates as $d) {
            $days = (int)(new \DateTimeImmutable('2026-01-01'))->diff(new \DateTimeImmutable($d))->days;
            $rows[] = ['date' => $d, 'counter' => $counter[$d] ?? 8.0 * $days, 'device_id' => 'd_s', 'is_estimated' => !empty($estimated[$d])];
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    /** @return list<string> */
    private static function monthly(string $last): array
    {
        $out = [];
        for ($d = new \DateTimeImmutable('2026-01-01'); $d->format('Y-m-d') <= $last; $d = $d->modify('first day of next month')) $out[] = $d->format('Y-m-d');
        return $out;
    }

    private function periods(): BillingPeriodService
    {
        return new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption,
            new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n));
    }

    private function running(bool $utility = false): array
    {
        $all = $utility ? $this->periods()->forUtility('strom', false, self::TODAY)
            : $this->periods()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, self::TODAY);
        return array_values(array_filter($all['periods'], fn($p) => !empty($p['running'])))[0] + ['measured_until' => $all['measured_until']];
    }

    private function card(): array
    {
        return $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'][0];
    }

    public function testTheBillingYearEndsAtTheLastRealReadingLikeTheCard(): void
    {
        $this->read([...self::monthly('2026-08-01'), '2026-08-19', '2026-08-28'], ['2026-08-28' => true]);
        $p = $this->running();
        $card = $this->card();
        self::assertSame('2026-08-19', $card['measured_until']);
        self::assertSame(['2026-08-19', '2026-08-18'], [$p['measured_until'], $p['as_of']], 'bis zur letzten echten Ablesung (bis v3.12 27.08.)');
        self::assertEqualsWithDelta(1368.0, $p['measured_kwh'], 0.05, 'keine Menge aus der Schätzung');
        self::assertEqualsWithDelta(1368.0, $p['kwh'], 0.05);
        self::assertSame(20.30, $p['balance']);
        self::assertSame($card['balance_measured']['balance'], $p['balance'], 'Abrechnungsjahr = Karte');
        // die Summe der Art folgt derselben Regel
        self::assertSame('2026-08-18', $this->running(true)['as_of']);
    }

    /** Zwei Schätzungen hintereinander am Ende: dasselbe Ende. */
    public function testTwoEstimatesInARow(): void
    {
        $this->read([...self::monthly('2026-08-01'), '2026-08-19', '2026-08-25', '2026-08-31'], ['2026-08-25' => true, '2026-08-31' => true]);
        $p = $this->running();
        self::assertSame('2026-08-18', $p['as_of']);
        self::assertSame($this->card()['balance_measured']['balance'], $p['balance']);
    }

    /**
     * Eine Schätzung mitten im Zeitraum, danach wieder echte Stände: Das Ende bleibt die letzte echte Ablesung, und die
     * Menge der Periode ist die der echten Stände (ein anderer geschätzter Wert dazwischen ändert sie nicht).
     */
    public function testAnEstimateInTheMiddleDoesNotMoveTheEnd(): void
    {
        $dates = [...self::monthly('2026-08-01'), '2026-08-19'];
        $this->read($dates, ['2026-06-01' => true]);
        $a = $this->running();
        $this->read($dates, ['2026-06-01' => true], ['2026-06-01' => 8.0 * 151 + 100.0]);
        $b = $this->running();
        self::assertSame(['2026-08-18', '2026-08-18'], [$a['as_of'], $b['as_of']]);
        self::assertEqualsWithDelta(1368.0, $a['measured_kwh'], 0.05);
        self::assertEqualsWithDelta($a['measured_kwh'], $b['measured_kwh'], 0.05, 'die Schätzung verschiebt nur die Monate, nicht die Periode');
    }

    /**
     * Werkstatt ohne Vertrag in der Periode (Rückfall über das Abrechnungsjahr): Endet die Periode an der letzten echten
     * Ablesung (17.09., `as_of` 16.09.) und beginnt die Prognose erst im November (der Oktober hat einen geschätzten
     * Stand), zählt der Rest des Monats bis zum Tag vor dem ersten Prognosemonat: 17.09. bis 31.10. = 45 Tage zur
     * Tagesrate des Septembers (16 Tage, 40,00 € → 2,50 € je Tag) = 112,50 €. Sonst fiele der Oktober heraus (14 Tage, 35,00 €).
     */
    public function testTheWorkshopRestReachesTheFirstForecastMonth(): void
    {
        $running = ['as_of' => '2026-09-16', 'until' => '2026-12-31', 'balance' => 0.0];
        $history = [['ym' => '2026-09', 'days' => 16, 'kwh_cost' => 40.0, 'base_price_eur' => 0.0, 'advance_eur' => null],
                    ['ym' => '2026-10', 'days' => 4, 'kwh_cost' => 10.0, 'base_price_eur' => 0.0, 'advance_eur' => null]];
        $months = [['ym' => '2026-11', 'days' => 30, 'advance' => 0.0], ['ym' => '2026-12', 'days' => 31, 'advance' => 0.0]];
        $rest = \Energietracker\Services\ScenarioService::restOfMonth($running, $months, $history);
        self::assertSame([45, 112.5], [$rest['days'], $rest['cost']]);
        $b = \Energietracker\Services\ScenarioService::billingBalance($running, $months, [58.0, 64.0], [58.0, 64.0], $rest);
        self::assertSame(234.5, $b['base_end_balance'], '112,50 € Rest bis 31.10. + 58,00 € November + 64,00 € Dezember');
    }

    /**
     * Schätzung am Periodenende: geschätzter Stand am letzten Tag (28.02.2027) eines Zeitraums, für den es danach keinen
     * echten Stand gibt: Die Periode endet an der letzten echten Ablesung davor (01.02.2027, as_of 31.01.2027).
     */
    public function testAnEstimateOnTheLastDayOfThePeriod(): void
    {
        $dates = [];
        for ($d = new \DateTimeImmutable('2026-01-01'); $d->format('Y-m-d') <= '2027-02-01'; $d = $d->modify('first day of next month')) $dates[] = $d->format('Y-m-d');
        $this->read([...$dates, '2027-02-28'], ['2027-02-28' => true]);
        $all = $this->periods()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2027-03-05')['periods'];
        $p = array_values(array_filter($all, fn($x) => $x['from'] === '2026-03-01'))[0];
        self::assertSame('2027-01-31', $p['as_of'], 'nicht bis zum geschätzten Endstand');
        self::assertEqualsWithDelta(8.0 * 337, $p['measured_kwh'], 0.05, '01.03.2026 bis 31.01.2027');
    }
}
