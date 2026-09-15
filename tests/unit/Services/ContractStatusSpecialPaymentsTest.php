<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ConsumptionService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.5.1 — Sonderzahlungen in der Tabelle „Verträge & Abschläge".
 *
 * Der Vertragsstatus trägt je Vertrag die Einzelposten (`special_payments`)
 * und das Netto aus Kundensicht (`special_payment_net`: erhalten positiv,
 * gezahlt negativ). Die Liste gibt es nur für Verbrauchsarten mit
 * Abschlagsverträgen — ihr Fehlen ist für die UI das Signal, die Spalte
 * nicht zu zeigen.
 */
#[CoversClass(ConsumptionService::class)]
final class ContractStatusSpecialPaymentsTest extends ServiceTestCase
{
    private function seedGasContract(array $specialPayments): array
    {
        $meter = $this->meters->create('gas', [
            'name' => 'Hauptzähler', 'installed_on' => '2024-01-01', 'initial_counter' => 1000.0,
        ]);
        $devId = $meter['devices'][0]['id'];
        $this->contracts->create('gas', [
            'meter_id' => $meter['id'], 'provider' => 'Werk', 'tariff_name' => 'Basis',
            'start' => '2024-01-01', 'end' => '2024-12-31',
            'working_prices'   => [['from' => '2024-01-01', 'ct_per_kwh' => 10.0]],
            'base_prices'      => [['from' => '2024-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 100.0]],
            'special_payments' => $specialPayments,
        ]);
        $readings = [];
        for ($m = 1; $m <= 13; $m++) {
            $date = $m <= 12 ? sprintf('2024-%02d-01', $m) : '2025-01-01';
            $readings[] = ['date' => $date, 'counter' => 1000.0 + ($m - 1) * 50.0, 'device_id' => $devId];
        }
        $this->setReadings('gas', $meter['id'], $readings);
        return $meter;
    }

    public function testStatusCarriesTheIndividualSpecialPaymentsAndTheNet(): void
    {
        $meter = $this->seedGasContract([
            ['date' => '2024-03-15', 'kind' => 'rueckzahlung_ohne', 'amount_eur' => 120.0, 'note' => 'Jahresabrechnung'],
            ['date' => '2024-09-01', 'kind' => 'nachzahlung_ohne',  'amount_eur' => 30.0],
            ['date' => '2024-11-01', 'kind' => 'abschlagszahlung',  'amount_eur' => 25.0],
        ]);
        $c = $this->consumption->contractStatus('gas', $meter)['contracts'][0];

        self::assertArrayHasKey('special_payments', $c);
        self::assertCount(3, $c['special_payments']);
        self::assertSame(['date', 'kind', 'amount_eur', 'note'], array_keys($c['special_payments'][0]),
            'Einzelposten tragen genau die vier Felder für den Tooltip');
        self::assertSame('2024-03-15', $c['special_payments'][0]['date'], 'nach Datum sortiert');
        self::assertSame('rueckzahlung_ohne', $c['special_payments'][0]['kind']);
        self::assertSame('Jahresabrechnung', $c['special_payments'][0]['note']);
        self::assertEqualsWithDelta(120.0, $c['special_payments'][0]['amount_eur'], 1e-9);

        // Netto aus Kundensicht: 120 erhalten − 30 − 25 gezahlt = +65
        self::assertEqualsWithDelta(65.0, $c['special_payment_net'], 1e-9);
        self::assertSame(3, $c['special_payments_count']);
    }

    public function testAmountsAreAlwaysPositiveInTheList(): void
    {
        // Die Richtung steckt in `kind`; ein negativ erfasster Betrag darf die
        // Tooltip-Zeile nicht mit doppeltem Vorzeichen versehen.
        $meter = $this->seedGasContract([
            ['date' => '2024-06-01', 'kind' => 'nachzahlung_ohne', 'amount_eur' => -40.0],
        ]);
        $c = $this->consumption->contractStatus('gas', $meter)['contracts'][0];
        self::assertEqualsWithDelta(40.0, $c['special_payments'][0]['amount_eur'], 1e-9);
        self::assertEqualsWithDelta(-40.0, $c['special_payment_net'], 1e-9, 'gezahlt zählt negativ');
    }

    public function testContractWithoutSpecialPaymentsHasAnEmptyList(): void
    {
        $meter = $this->seedGasContract([]);
        $c = $this->consumption->contractStatus('gas', $meter)['contracts'][0];
        self::assertSame([], $c['special_payments']);
        self::assertEqualsWithDelta(0.0, $c['special_payment_net'], 1e-9);
    }

    public function testWaterStatusHasNoSpecialPaymentsField(): void
    {
        // Wasser kennt keine Sonderzahlungen (Drei-Komponenten-Tarif) — das
        // Feld fehlt, die Spalte entfällt.
        $meter = $this->meters->create('wasser', [
            'name' => 'Wasserzähler', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        $this->contracts->create('wasser', [
            'meter_id' => $meter['id'], 'provider' => 'Stadt', 'tariff_name' => 'Wasser',
            'start' => '2024-01-01', 'end' => null,
        ]);
        $c = $this->consumption->contractStatus('wasser', $meter)['contracts'][0];
        self::assertArrayNotHasKey('special_payments', $c);
    }
}
