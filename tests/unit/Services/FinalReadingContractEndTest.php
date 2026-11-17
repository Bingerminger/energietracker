<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ScenarioService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket K2a) — Schlussablesung am letzten Vertragstag ohne erfasste Schlussrechnung: Das Abrechnungsjahr zählt
 * diesen Tag mit, wie die Saldo-Karte (seit Paket W) und wie die Periode einer erfassten Schlussrechnung. Eine Ablesung
 * am Tag D ist sonst die Grenze zu Beginn von D (as_of = D − 1); am letzten Tag eines Zeitraums ist sie dessen Endstand
 * (BillingPeriodService, `$cap`). Bis dahin galt das nur am letzten Tag der Periode, nicht am Vertragsende: ganze Rate am
 * 15. Abrechnungsjahr 540 € gegen Karte 630 €.
 *
 * Fall (aus der Doku, Abschnitt „Saldo bis heute“): Abschlag 90 €, Vertrag 01.01. bis 15.07.2026 ohne Folgevertrag,
 * Stände an jedem Monatsersten und am 15.07. (Schlussablesung), Stichtag 01.01., heute 01.08.2026.
 *   ganze Rate am 15.  sieben Raten 15.01. bis 15.07.     = 630,00 €
 *   tagesanteilig      6 × 90 € + 90 € × 15/31           = 583,55 €
 */
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(ScenarioService::class)]
final class FinalReadingContractEndTest extends ServiceTestCase
{
    private const TODAY = '2026-08-01';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_e', 'serial' => null, 'installed_on' => '2025-12-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
    }

    private function read(array $tail = ['2026-07-15']): void
    {
        $rows = [];
        foreach ([...array_map(fn($m) => sprintf('2026-%02d-01', $m), range(1, 7)), ...$tail] as $d) {
            $rows[] = ['date' => $d, 'counter' => 10.0 * (int)(new \DateTimeImmutable('2026-01-01'))->diff(new \DateTimeImmutable($d))->days, 'device_id' => 'd_e'];
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function contract(array $extra = []): array
    {
        return $this->contracts->create('strom', $extra + ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-01-01',
            'end' => '2026-07-15', 'auto_renews' => false, 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 9.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 90]]]);
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function year(bool $utility = false): array
    {
        $bp = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills());
        $all = $utility ? $bp->forUtility('strom', true, self::TODAY) : $bp->forTarget('strom', $this->meters->get('strom', $this->meterId), true, self::TODAY);
        return array_values(array_filter($all['periods'], fn($p) => $p['from'] === '2026-01-01'))[0];
    }

    private function card(): array
    {
        return $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'][0]['balance_measured'];
    }

    public function testWholeRateOnThe15th(): void
    {
        $this->read();
        $this->contract();
        $this->settings->set(['advance_counting' => 'due_day', 'advance_due_day' => 15]);
        $y = $this->year();
        self::assertSame(['2026-07-15', 196, 0], [$y['as_of'], $y['days'], $y['gaps']], 'der letzte Vertragstag zählt (bis v3.12 as_of 14.07.)');
        self::assertEqualsWithDelta(630.0, $y['advances'], 0.005, 'sieben Raten (bis v3.12 540 €)');
        self::assertEqualsWithDelta(630.0, $this->card()['advance'], 0.005, 'Karte');
        self::assertSame($this->card()['balance'], $y['balance'], 'Saldo Abrechnungsjahr = Karte');
    }

    public function testProRataAndTheStandingCharge(): void
    {
        $this->read();
        $this->contract();
        $y = $this->year();
        // 6 × 90 € + 90 € × 15/31 = 583,55 €; Grundpreis 6 × 9 € + 9 € × 15/31 = 58,35 €
        self::assertEqualsWithDelta(583.55, $y['advances'], 0.005);
        self::assertEqualsWithDelta(58.35, $y['fixed_cost'], 0.005);
        self::assertEqualsWithDelta(1950.0, $y['kwh'], 0.05, 'Menge 01.01. bis 14.07. (195 Tage), der Ablesetag ohne Verbrauch');
        self::assertSame($this->card()['balance'], $y['balance'], 'Abrechnungsjahr = Karte');
        // dieselbe Zahl wie die Periode einer erfassten Schlussrechnung bis 15.07.
        $this->bills()->create('strom', ['meter_id' => $this->meterId, 'period_from' => '2026-01-01', 'period_to' => '2026-07-15', 'issued_on' => '2026-07-31',
            'invoice' => ['energy_kwh' => 1950.0, 'amount_eur' => 643.35, 'advances_paid_eur' => 583.55]]);
        $b = $this->year();
        self::assertSame(['2026-07-15', '2026-07-15'], [$b['until'], $b['as_of']]);
        self::assertSame($y['balance'], $b['balance'], 'ohne und mit Schlussrechnung gleich');
    }

    /** Die Summe der Art: Endet der einzige Vertrag, gilt dieselbe Regel. */
    public function testTheWholeUtility(): void
    {
        $this->read();
        $this->contract();
        self::assertSame('2026-07-15', $this->year(true)['as_of']);
        self::assertEqualsWithDelta(583.55, $this->year(true)['advances'], 0.005);
    }

    /**
     * Die Summe der Art mit einem zweiten Zähler, dessen Vertrag weiterläuft und der am selben Tag abgelesen ist: Jeder
     * Zähler rechnet bis zu seiner eigenen Grenze, der erste mit dem Endstand am 15.07., der zweite bis zum Vortag (am 15.07.
     * hätte er sonst einen Tag ohne Verbrauch). Die Art ist ihre Summe und reicht bis zum späteren Ende.
     * v3.13.0 — bis dahin eine Grenze für die ganze Art: Sie endete für beide am 14.07., der erste Zähler verlor seinen
     * letzten Vertragstag (Grundpreis und Abschlag), den sein eigenes Abrechnungsjahr und die Schlussrechnung zählen.
     */
    public function testTheWholeUtilityIsTheSumOfItsMetersWithAnotherContractRunningOn(): void
    {
        $this->read();
        $this->contract();
        $all = $this->store->read('strom/meters.json', []);
        $all[] = ['id' => 'm_zwei', 'name' => 'Zwei', 'icon' => '', 'created_at' => '2025-12-01', 'active' => true, 'notes' => '',
            'devices' => [['id' => 'd_zwei', 'serial' => null, 'installed_on' => '2025-12-01', 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]];
        $this->store->write('strom/meters.json', $all);
        $rows = $this->store->read('strom/readings.json', []);
        foreach ($rows as $i => $r) $rows[] = ['id' => "z$i", 'meter_id' => 'm_zwei', 'device_id' => 'd_zwei'] + $r;
        $this->store->write('strom/readings.json', $rows);
        $this->contracts->create('strom', ['meter_id' => 'm_zwei', 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 50]]]);
        $bp = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills());
        $zwei = array_values(array_filter($bp->forTarget('strom', $this->meters->get('strom', 'm_zwei'), true, self::TODAY)['periods'],
            fn($p) => $p['from'] === '2026-01-01'))[0];
        $own = $this->year();
        $art = $this->year(true);
        self::assertSame('2026-07-15', $own['as_of'], 'der Zähler mit dem beendeten Vertrag allein: Endstand');
        self::assertSame('2026-07-14', $zwei['as_of'], 'der weiterlaufende Vertrag: bis zum Vortag');
        self::assertSame('2026-07-15', $art['as_of'], 'die Art bis zum späteren Ende');
        // 6 × 90 € + 90 € × 15/31 = 583,55 € und 6 × 50 € + 50 € × 14/31 = 322,58 €
        self::assertEqualsWithDelta(583.55 + 322.58, $art['advances'], 0.011, 'Abschläge = Summe der Zähler');
        self::assertEqualsWithDelta($own['balance'] + $zwei['balance'], $art['balance'], 0.011, 'Saldo = Summe der Zähler');
    }

    /** Ablesung am Tag nach dem Ende (16.07.): wie bisher bis zum letzten Vertragstag, keine Rate, kein Tag mehr. */
    public function testAReadingTheDayAfterTheEndStaysAsItWas(): void
    {
        $this->read(['2026-07-16']);
        $this->contract();
        $y = $this->year();
        self::assertSame('2026-07-15', $y['as_of']);
        self::assertEqualsWithDelta(583.55, $y['advances'], 0.005);
    }

    /** Ein Vertrag, der sich ohne Kündigung verlängert, endet nicht: Die Ablesung am eingetragenen Ende zählt bis zum Vortag. */
    public function testARenewingContractDoesNotEnd(): void
    {
        $this->read();
        $this->contract(['auto_renews' => true]);
        self::assertSame('2026-07-14', $this->year()['as_of']);
    }

    /** Werkstatt: Der Rest des Monats nach dem Vertragsende hat weder Abschlag noch Grundpreis noch Arbeitspreis. */
    public function testTheWorkshopRestAfterTheEndIsZero(): void
    {
        $running = ['as_of' => '2026-07-15', 'until' => '2026-12-31'];
        $row = ['ym' => '2026-07', 'days' => 14, 'kwh_cost' => 42.0, 'base_price_eur' => 4.35, 'advance_eur' => 43.55,
                'contract_from' => '2026-07-01', 'contract_until' => '2026-07-15'];
        $rest = ScenarioService::restOfMonth($running, [['ym' => '2026-08', 'days' => 31]], [$row]);
        self::assertSame([0.0, 0.0], [$rest['advance'], $rest['cost']]);
    }
}
