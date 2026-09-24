<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\CsvExportService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.5.3 — CALC-16 im Review 2026-09-24: Der Monatsexport las Abschlag,
 * Monatssaldo, kumulierten Saldo und CO₂ aus der Utility-Summe, dort fehlten
 * die Felder — die vier Spalten blieben in jeder Zeile leer.
 */
#[CoversClass(CsvExportService::class)]
final class CsvMonthlyExportTest extends ServiceTestCase
{
    public function testAdvanceBalanceAndCo2ColumnsAreFilled(): void
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->setReadings('strom', $meterId, [
            ['date' => '2025-01-01', 'counter' => 0.0,   'device_id' => 'd1'],
            ['date' => '2025-02-01', 'counter' => 310.0, 'device_id' => 'd1'],
            ['date' => '2025-03-01', 'counter' => 590.0, 'device_id' => 'd1'],
        ]);
        $this->contracts->create('strom', [
            'meter_id' => $meterId, 'provider' => 'X', 'start' => '2025-01-01',
            'working_prices'   => [['from' => '2025-01-01', 'ct_per_kwh' => 30]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 80]],
        ]);

        $csv = new CsvExportService(
            $this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n
        );
        $lines = array_values(array_filter(explode("\n", ltrim($csv->monthly('strom'), "\u{FEFF}"))));
        $jan = str_getcsv($lines[1], ';', '"', '');

        self::assertSame('2025-01', $jan[0]);
        self::assertSame('80', $jan[4], 'Abschlag');
        self::assertSame('13', $jan[5], 'Monatssaldo: 310 kWh × 0,30 € − 80 €');
        self::assertSame('13', $jan[6], 'Saldo kumuliert');
        self::assertNotSame('', $jan[9], 'CO₂');
    }
}
