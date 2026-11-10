<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 (Sichtung M1, S2) — die Zeile eines beendeten Vertrags unter „Verträge & Abschläge“, von Hand nachgerechnet.
 *
 * Die Zahl ist der Saldo am Ende des Vertrags: Verbraucht minus Bezahlt plus Sonderzahlungen. Sie ist kein Kostenunterschied zum
 * Folgevertrag. Positiv heißt Nachzahlung, negativ Guthaben; die Tabelle zeigt sie aus Kundensicht („+“ Guthaben, „−“ Nachzahlung).
 *
 * Erfundener Haushalt: Strom, 10 kWh am Tag, Ablesung am Monatsersten, 30 ct/kWh, Grundpreis 12 €/Monat; heute 15.10.2026.
 * Das Jahr 2025 von Hand: 365 × 10 kWh × 0,30 € = 1.095,00 €, Grundpreis 12 × 12 € = 144,00 €, zusammen 1.239,00 €.
 */
#[CoversClass(ConsumptionService::class)]
final class SwitchBalanceByHandTest extends ServiceTestCase
{
    private const TODAY = '2026-10-15';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_s', 'serial' => null, 'installed_on' => '2025-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($d = '2025-01-01'; $d <= '2026-10-01'; $d = date('Y-m-d', (int)strtotime("$d +1 month"))) {
            $rows[] = ['date' => $d, 'device_id' => 'd_s', 'counter' => 10.0 * (int)round((strtotime("$d 12:00") - strtotime('2025-01-01 12:00')) / 86400)];
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function contract(string $provider, string $start, ?string $end, float $advance, array $extra = []): array
    {
        return $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => $provider, 'start' => $start] + ($end ? ['end' => $end] : []) + [
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => $start, 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => $advance]]] + $extra);
    }

    /** @return array<string,mixed> Zeile des Vertrags */
    private function row(string $provider): array
    {
        $rows = $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->meterId), self::TODAY)['contracts'];
        return array_column($rows, null, 'provider')[$provider];
    }

    /** Guthaben: 12 × 130 € = 1.560,00 € bezahlt, 1.239,00 − 1.560,00 = −321,00 €. */
    public function testCreditAtTheSwitch(): void
    {
        $this->contract('Alt', '2025-01-01', '2025-12-31', 130.0);
        $this->contract('Neu', '2026-01-01', null, 130.0);
        $r = $this->row('Alt');
        self::assertEqualsWithDelta(1239.00, $r['cost_to_date'], 0.01);
        self::assertEqualsWithDelta(1560.00, $r['advance_paid'], 0.01);
        self::assertSame(-321.00, $r['current_balance']);
        self::assertSame(-321.00, $r['projected_end_balance'], 'beendet: erwarteter Saldo = Saldo');
        self::assertSame('refund', $r['verdict']);
    }

    /** Nachzahlung: 12 × 90 € = 1.080,00 € bezahlt, 1.239,00 − 1.080,00 = +159,00 €. */
    public function testDueAtTheSwitch(): void
    {
        $this->contract('Alt', '2025-01-01', '2025-12-31', 90.0);
        $this->contract('Neu', '2026-01-01', null, 90.0);
        $r = $this->row('Alt');
        self::assertEqualsWithDelta(1239.00, $r['cost_to_date'], 0.01);
        self::assertEqualsWithDelta(1080.00, $r['advance_paid'], 0.01);
        self::assertSame(159.00, $r['current_balance']);
        self::assertSame('surcharge', $r['verdict']);
    }

    /**
     * Weiterlaufend: Ende 30.09.2025 eingetragen, ohne Kündigung weiter bis zum Folgevertrag am 01.01.2026. Es zählt das ganze Jahr
     * 2025, also dieselben 1.239,00 €, und zwölf Abschläge zu 110 € = 1.320,00 €: −81,00 €. Nur bis zum eingetragenen Ende wären es
     * 273 × 10 × 0,30 = 819,00 € + 108,00 € − 990,00 € = −63,00 €.
     */
    public function testAContractThatRanOnUntilItsSuccessor(): void
    {
        $this->contract('Alt', '2025-01-01', '2025-09-30', 110.0);
        $this->contract('Neu', '2026-01-01', null, 110.0);
        $r = $this->row('Alt');
        self::assertSame(-81.00, $r['current_balance']);
        self::assertEqualsWithDelta(1320.00, $r['advance_paid'], 0.01);
    }

    /** Dasselbe mit einem Folgevertrag, der teurer lief als gezahlt: 12 × 100 € = 1.200,00 €, 1.239,00 − 1.200,00 = +39,00 €, und Null bleibt Null. */
    public function testRanOnWithDue(): void
    {
        $this->contract('Alt', '2025-01-01', '2025-09-30', 100.0);
        $this->contract('Neu', '2026-01-01', null, 100.0);
        self::assertSame(39.00, $this->row('Alt')['current_balance']);
    }

    /** Die Rückzahlung des Altversorgers gleicht das Guthaben aus: −321,00 + 321,00 = 0,00 €, in der Tabelle „ausgeglichen“. */
    public function testSettledByARefundIsEven(): void
    {
        $this->contract('Alt', '2025-01-01', '2025-12-31', 130.0, ['special_payments' => [['date' => '2026-02-10', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 321.0]]]);
        $this->contract('Neu', '2026-01-01', null, 130.0);
        self::assertSame(0.0, abs($this->row('Alt')['current_balance']));
    }

    /** Eine Teilrückzahlung von 50 €: −321,00 + 50,00 = −271,00 €. Eine Nachzahlung von 100 € beim Fall +159,00 €: +59,00 €. */
    public function testPartialPaymentsMoveTheBalance(): void
    {
        $this->contract('Alt', '2025-01-01', '2025-12-31', 130.0, ['special_payments' => [['date' => '2026-02-10', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 50.0]]]);
        $this->contract('Neu', '2026-01-01', null, 130.0);
        self::assertSame(-271.00, $this->row('Alt')['current_balance']);
    }

    public function testADuePaidLater(): void
    {
        $this->contract('Alt', '2025-01-01', '2025-12-31', 90.0, ['special_payments' => [['date' => '2026-02-10', 'kind' => 'nachzahlung_ohne', 'amount_eur' => 100.0]]]);
        $this->contract('Neu', '2026-01-01', null, 90.0);
        self::assertSame(59.00, $this->row('Alt')['current_balance']);
    }

    /**
     * Wechsel im Monat: Ende 20.06.2025, Folgevertrag ab 21.06.2025. Von Hand: 171 Tage × 10 kWh × 0,30 € = 513,00 €, Grundpreis tagesgenau
     * 5 × 12 € + 20/30 × 12 € = 68,00 €, zusammen 581,00 €. Abschläge nach der dokumentierten Regel „Plan anteilig nach Tagen“
     * (docs/verstehen/00-overview.md): 5 × 100 € + 20/30 × 100 € = 566,67 €, also +14,33 € Nachzahlung.
     * Grenze: Hat der Versorger den vollen Juni-Abschlag abgebucht, wären es 600,00 € und −19,00 €; das Vorzeichen hängt daran. Der Abschlagsrhythmus
     * mit Terminen oder eine Sonderzahlung bildet das ab.
     */
    public function testASwitchInTheMiddleOfAMonth(): void
    {
        $this->contract('Alt', '2025-01-01', '2025-06-20', 100.0);
        $this->contract('Neu', '2025-06-21', null, 100.0);
        $r = $this->row('Alt');
        self::assertEqualsWithDelta(581.00, $r['cost_to_date'], 0.01);
        self::assertEqualsWithDelta(566.67, $r['advance_paid'], 0.01);
        self::assertSame(14.33, $r['current_balance']);
    }
}
