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
 * v3.11.0 (GitHub #56) — ein Bonus zählt in genau einer Abrechnungsperiode, auch wenn die Periode mitten im
 * Monat beginnt. Bis dahin addierten der Saldo der laufenden Periode (`balance_measured`, Prognose) den Bonus
 * des ganzen Kalendermonats: Lag er am letzten Tag der alten Periode, stand er in der neuen noch einmal.
 *
 * Erfundener Haushalt: Strom, Stichtag 22.09., Vertrag seit 22.09.2023 (offen), 30 ct/kWh, 10 €/Monat,
 * Abschlag 80 €/Monat, 10 kWh am Tag, Ablesung am Monatsersten; heute 02.11.2026. Bonus 60 €.
 *   alte Periode 22.09.2025 bis 21.09.2026, laufende ab 22.09.2026
 *   (1) Bonus am 21.09.2026: in der alten Periode 60 €, in der laufenden 0 €; die Prognose wie ohne Bonus
 *   (2) Bonus am 22.09.2026: in der alten 0 €, in der laufenden 60 €; die Prognose 60 € günstiger
 *   (3) Abrechnungsperioden, `balance_measured` und `projected_end_balance` zählen ihn gleich
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ContractService::class)]
final class BonusPeriodBoundaryTest extends ServiceTestCase
{
    private const TODAY = '2026-11-02';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings->set(['billing_cycle_anchor_strom' => '09-22']);
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_b', 'serial' => null, 'installed_on' => '2023-09-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($d = '2023-09-01'; $d <= '2026-11-01'; $d = date('Y-m-d', (int)strtotime("$d +1 month"))) {
            $rows[] = ['date' => $d, 'device_id' => 'd_b', 'counter' => 10.0 * (int)round((strtotime("$d 12:00") - strtotime('2023-09-01 12:00')) / 86400)];
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function contract(?string $bonusOn): void
    {
        $this->store->write('strom/contracts.json', []);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2023-09-22',
            'working_prices' => [['from' => '2023-09-22', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2023-09-22', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2023-09-22', 'amount_eur' => 80.0]],
            'bonuses' => $bonusOn !== null ? [['credit_date' => $bonusOn, 'amount_eur' => 60.0, 'type' => 'sonstige']] : []]);
    }

    /** @return array{old: float, running: float, measured: float, projected: float} */
    private function bonusEverywhere(): array
    {
        $meter = $this->meters->get('strom', $this->meterId);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bp = (new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills))->forTarget('strom', $meter, false, self::TODAY);
        $by = array_column($bp['periods'], null, 'from');
        $row = $this->consumption->contractStatus('strom', $meter, self::TODAY)['contracts'][0];
        return ['old' => (float)$by['2025-09-22']['bonus'], 'running' => (float)$by['2026-09-22']['bonus'],
                'measured' => (float)$row['balance_measured']['bonus'], 'projected' => (float)$row['projected_end_balance'],
                'until' => (string)$by['2025-09-22']['until'], 'from' => (string)$row['balance_measured']['from']];
    }

    public function testABonusOnTheLastDayOfThePeriodCountsOnceInTheOldPeriod(): void
    {
        $this->contract(null);
        $none = $this->bonusEverywhere();
        self::assertSame(['2026-09-21', '2026-09-22'], [$none['until'], $none['from']], 'die Perioden wie im Fall von #56');
        $this->contract('2026-09-21');
        $x = $this->bonusEverywhere();
        self::assertSame([60.0, 0.0, 0.0], [$x['old'], $x['running'], $x['measured']], 'bis v3.10 stand er in balance_measured noch einmal');
        self::assertEqualsWithDelta($none['projected'], $x['projected'], 0.01, 'die Prognose der laufenden Periode zählt ihn nicht');
    }

    public function testABonusOnTheFirstDayCountsOnlyInTheNewPeriod(): void
    {
        $this->contract(null);
        $none = $this->bonusEverywhere();
        $this->contract('2026-09-22');
        $x = $this->bonusEverywhere();
        self::assertSame([0.0, 60.0, 60.0], [$x['old'], $x['running'], $x['measured']]);
        self::assertEqualsWithDelta($none['projected'] - 60.0, $x['projected'], 0.01, 'einmal, 60 € günstiger');
    }

    /**
     * v3.11.0 (Nachprüfung NP7) — „Verträge & Abschläge“ zeigt in der Spalte Bonus denselben Betrag, der im Saldo
     * der Zeile steckt: tagesgenau nach dem Gutschriftstag, kein Anteil des Septembers. Von Hand: die laufende Zeile
     * beginnt am 22.09.; bis dahin zählte sie 60 € × 9/30 Tage = 18,00 € (in keinem Saldo enthalten).
     *   Bonus 21.09.: Zeile 0 €; Bonus 22.09.: Zeile 60 €; der Saldo heute ändert sich um genau diesen Betrag.
     */
    public function testTheContractTableShowsTheBonusThatIsInTheBalance(): void
    {
        $meter = $this->meters->get('strom', $this->meterId);
        $row = function (?string $on) use ($meter): array {
            $this->contract($on);
            return $this->consumption->contractStatus('strom', $meter, self::TODAY)['contracts'][0];
        };
        $none = $row(null);
        $old = $row('2026-09-21');
        self::assertSame([0.0, 0.0], [(float)$old['actual_bonus_total'], (float)$old['bonus_to_date']], 'bis dahin 18,00 € (9/30 von 60 €)');
        self::assertEqualsWithDelta((float)$none['current_balance'], (float)$old['current_balance'], 0.01);
        $new = $row('2026-09-22');
        self::assertSame([60.0, 60.0], [(float)$new['actual_bonus_total'], (float)$new['bonus_to_date']]);
        // current_balance = Kosten − Abschläge (+ = Nachzahlung, die Ansicht dreht das Vorzeichen über balanceView)
        self::assertEqualsWithDelta((float)$none['current_balance'] - 60.0, (float)$new['current_balance'], 0.01, '60 € weniger zu zahlen');
        self::assertEqualsWithDelta((float)$none['actual_cost'] - 60.0, (float)$new['actual_cost'], 0.01, 'die Kosten der Zeile enthalten denselben Bonus');
    }

    public function testBonusBetweenCountsTheEffectiveDay(): void
    {
        $c = ['start' => '2025-01-15', 'end' => '2025-12-31', 'bonuses' => [
            ['credit_date' => '2026-02-10', 'amount_eur' => 20.0],   // nach dem Ende: zählt am letzten Vertragstag
            ['credit_date' => '2025-01-02', 'amount_eur' => 5.0],    // vor dem Beginn: zählt am ersten
            ['credit_date' => '2025-06-30', 'amount_eur' => 7.0]]];
        self::assertSame(20.0, ContractService::bonusBetween($c, '2025-12-01', '2026-01-01'));
        self::assertSame(5.0, ContractService::bonusBetween($c, '2025-01-15', '2025-01-16'));
        self::assertSame(0.0, ContractService::bonusBetween($c, '2025-07-01', '2025-12-01'), '[von, bis): der 30.06. gehört nicht dazu');
        self::assertSame(7.0, ContractService::bonusBetween($c, '2025-06-30', '2025-07-01'));
    }
}
