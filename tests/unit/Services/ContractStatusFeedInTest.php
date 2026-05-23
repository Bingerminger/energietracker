<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ConsumptionService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * P-PV-01 — contractStatus für PV-Einspeisung (accounting_kind = feed_in).
 *
 * Der „Saldo" ist ein Vergütungs-Erlös, keine Kosten:
 *   - positiver Saldo → verdict „Auszahlung" (nicht „Nachzahlung")
 *   - Projektion bis zur nächsten Jahresabrechnung, NICHT bis Vertragsende
 *     (EEG-Verträge laufen 20 Jahre — sonst „erwartet +10.000 €"-Artefakt).
 */
#[CoversClass(ConsumptionService::class)]
final class ContractStatusFeedInTest extends ServiceTestCase
{
    private function seedFeedInMeterWithReadings(): array
    {
        $meter = $this->meters->create('pv_einspeisung', [
            'name' => 'Einspeisezähler',
            'installed_on' => '2023-01-01', 'initial_counter' => 0.0,
        ]);
        $devId = $meter['devices'][0]['id'];
        // EEG-Vertrag über 20 Jahre (Ende weit in der Zukunft).
        $this->contracts->create('pv_einspeisung', [
            'meter_id' => $meter['id'], 'provider' => 'Netz', 'tariff_name' => 'EEG',
            'start' => '2023-01-01', 'end' => '2043-12-31',
            'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 8.2]],
        ]);
        // Zwei Jahre Einspeisung, ~6000 kWh/Jahr.
        $readings = [];
        $counter = 0.0;
        $months = [];
        for ($y = 2023; $y <= 2024; $y++) {
            for ($m = 1; $m <= 12; $m++) {
                $months[] = sprintf('%04d-%02d-01', $y, $m);
            }
        }
        $months[] = '2025-01-01';
        foreach ($months as $i => $date) {
            $readings[] = ['date' => $date, 'counter' => round($counter, 1), 'device_id' => $devId];
            $counter += 500.0; // 500 kWh/Monat
        }
        $this->setReadings('pv_einspeisung', $meter['id'], $readings);
        return $meter;
    }

    public function testFeedInVerdictIsAuszahlungNotNachzahlung(): void
    {
        $meter = $this->seedFeedInMeterWithReadings();
        $status = $this->consumption->contractStatus('pv_einspeisung', $meter);
        self::assertNotEmpty($status['contracts']);
        $c = $status['contracts'][0];

        self::assertGreaterThan(0, $c['current_balance'],
            'feed_in-Saldo ist ein Vergütungsanspruch (positiv)');
        self::assertNotSame('Nachzahlung', $c['verdict'],
            'feed_in darf NIE als Nachzahlung klassifiziert werden');
        self::assertSame('Auszahlung', $c['verdict'],
            'positiver feed_in-Saldo → Auszahlung des Netzbetreibers');
    }

    public function testFeedInProjectionStaysWithinNextBillingCycleNotContractEnd(): void
    {
        $meter = $this->seedFeedInMeterWithReadings();
        $status = $this->consumption->contractStatus('pv_einspeisung', $meter);
        $c = $status['contracts'][0];

        // effective_end darf nicht das Vertragsende 2043 sein, sondern die
        // nächste Jahresabrechnung (≈ aktuelles/Folgejahr).
        self::assertLessThan('2030-01-01', $c['effective_end'],
            'feed_in-Projektion muss auf die nächste Abrechnung begrenzt sein, nicht bis EEG-Vertragsende 2043');

        // Erwarteter Saldo bleibt in einer plausiblen Größenordnung
        // (nicht die über 20 Jahre hochgerechnete Gesamtvergütung).
        // 500 kWh/Monat × 8,2 ct ≈ 41 €/Monat; bis zur nächsten Abrechnung
        // höchstens ~12 Monate → projected deutlich unter 2000 €.
        self::assertLessThan(2000.0, (float)$c['projected_end_balance'],
            'Projektion darf nicht die 20-Jahres-Gesamtvergütung sein');
    }
}
