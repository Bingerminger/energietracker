<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\DeliveryService;
use Energietracker\Services\IngestService;
use Energietracker\Services\ReminderService;
use Energietracker\Support\Dates;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.5.3 — Daten und Zahlen werden beim Schreiben geprüft (API-06, FE-04,
 * CALC-Randnotiz im Review 2026-09-24).
 *
 * Vorher: `2025-13-01` kam über Ingest oder Ablesung in die Daten und legte
 * Verbrauch, Prognose, Empfehlungen, CSV und PDF mit HTTP 500 lahm; HTML im
 * Vertragsdatum landete ungefiltert im DOM; `(float)"12,5"` wurde still 12.
 */
#[CoversClass(Dates::class)]
final class InputValidationTest extends ServiceTestCase
{
    public function testDatesAcceptOnlyRealCalendarDays(): void
    {
        self::assertTrue(Dates::isIsoDate('2024-02-29'));
        foreach (['2025-02-29', '2025-13-01', '2025-00-10', '2025-1-01', '01.02.2025', '<img src=x>', '', null, 20250101] as $bad) {
            self::assertFalse(Dates::isIsoDate($bad), var_export($bad, true) . ' ist kein Datum');
        }
    }

    public function testReadingRejectsImpossibleDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->readings->create('strom', ['date' => '2025-13-01', 'counter' => 100]);
    }

    public function testReadingRejectsCommaStringInsteadOfTruncatingIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->readings->create('strom', ['date' => '2026-01-01', 'counter' => '12,5']);
    }

    public function testReadingRejectsNegativeCounter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->readings->create('strom', ['date' => '2026-01-01', 'counter' => -1]);
    }

    public function testReadingAcceptsZeroAndNumericStrings(): void
    {
        $a = $this->readings->create('strom', ['date' => '2026-01-01', 'counter' => 0]);
        $b = $this->readings->create('strom', ['date' => '2026-01-02', 'counter' => '12.5']);
        self::assertSame(0.0, $a['counter']);
        self::assertSame(12.5, $b['counter']);
    }

    public function testReadingUpdateValidatesToo(): void
    {
        $r = $this->readings->create('strom', ['date' => '2026-01-01', 'counter' => 10]);
        $this->expectException(\InvalidArgumentException::class);
        $this->readings->update('strom', $r['id'], ['date' => '2026-02-30']);
    }

    public function testIngestRejectsImpossibleDate(): void
    {
        $ingest = new IngestService($this->meters, $this->readings, $this->i18n);
        $this->expectException(\InvalidArgumentException::class);
        $ingest->ingest(['utility' => 'strom', 'meter' => $this->meters->defaultId('strom'), 'value' => 5, 'date' => '2025-13-01']);
    }

    public function testContractRejectsMarkupAsStartDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->contracts->create('gas', [
            'provider' => 'X', 'start' => '<img src=x onerror=alert(1)>',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 9]],
        ]);
    }

    public function testContractRejectsBrokenPriceDateAndTextAmount(): void
    {
        foreach ([
            [['from' => 'x" autofocus onfocus="alert(1)', 'ct_per_kwh' => 9]],
            [['from' => '2026-01-01', 'ct_per_kwh' => 'neun']],
        ] as $prices) {
            try {
                $this->contracts->create('gas', ['provider' => 'X', 'start' => '2026-01-01', 'working_prices' => $prices]);
                self::fail('Eingabe hätte abgelehnt werden müssen: ' . json_encode($prices));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testContractRejectsBrokenBonusAndSpecialPaymentDates(): void
    {
        $base = ['provider' => 'X', 'start' => '2026-01-01', 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 9]]];
        foreach ([
            ['bonuses' => [['credit_date' => '2026-02-31', 'amount_eur' => 50]]],
            ['special_payments' => [['date' => 'gestern', 'kind' => 'nachzahlung_ohne', 'amount_eur' => 10]]],
        ] as $extra) {
            try {
                $this->contracts->create('gas', $base + $extra);
                self::fail('Eingabe hätte abgelehnt werden müssen: ' . json_encode($extra));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testValidContractStillWorks(): void
    {
        $c = $this->contracts->create('gas', [
            'provider' => 'X', 'start' => '2026-01-01', 'end' => '2026-12-31',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => '9.5']],
            'bonuses' => [['credit_date' => '2026-03-01', 'amount_eur' => 50]],
        ]);
        self::assertSame(9.5, $c['working_prices'][0]['ct_per_kwh']);
    }

    public function testDeviceSwapRejectsBrokenDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->meters->replaceDevice('strom', $this->meters->defaultId('strom'), [
            'date' => '<b>x</b>', 'old_final_counter' => 100, 'new_initial_counter' => 0,
        ]);
    }

    public function testDeviceSwapBeforeASecondDeviceIsRejected(): void
    {
        $meterId = $this->setMeterDevices('strom', [
            ['id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
             'removed_on' => '2024-06-15', 'final_counter' => 1500.0, 'reason' => null],
            ['id' => 'd2', 'serial' => null, 'installed_on' => '2024-06-15', 'initial_counter' => 0.0,
             'removed_on' => null, 'final_counter' => null, 'reason' => null],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->meters->replaceDevice('strom', $meterId, ['date' => '2024-03-01', 'old_final_counter' => 10]);
    }

    public function testDeliveryAndReminderRejectImpossibleDates(): void
    {
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $reminders  = new ReminderService($this->store, $this->settings, $this->i18n);
        foreach ([
            fn() => $deliveries->create('heizoel', ['meter_id' => $this->meters->defaultId('heizoel'), 'date' => '2025-02-30', 'quantity' => 1000]),
            fn() => $reminders->create(['title' => 'Wartung', 'next_due' => '2026-13-01']),
        ] as $call) {
            try {
                $call();
                self::fail('Eingabe hätte abgelehnt werden müssen');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * Lektion 20: Die Rechnung muss auch mit Altdaten zurechtkommen, die den
     * Schreibpfad nie gesehen haben (Restore, ältere Version). Eine Ablesung
     * mit unmöglichem Datum wird übersprungen statt die Auswertung zu brechen.
     */
    public function testConsumptionSkipsStoredReadingWithImpossibleDate(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['date' => '2024-01-01', 'counter' => 0.0,   'device_id' => 'd1'],
            ['date' => '2024-02-01', 'counter' => 310.0, 'device_id' => 'd1'],
            ['date' => '2024-02-30', 'counter' => 999.0, 'device_id' => 'd1'],
            ['date' => '2024-03-01', 'counter' => 600.0, 'device_id' => 'd1'],
        ]);
        $rows = $this->consumption->forMeter('strom', $this->meters->get('strom', $meterId));
        self::assertNotEmpty($rows);
        self::assertEqualsWithDelta(600.0, array_sum(array_column($rows, 'kwh')), 0.001);
    }
}
