<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\CsvExportService;
use Energietracker\Services\EvChargingReportService;
use Energietracker\Services\TemperatureService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H6, MKT-14) — Ladestrom-Nachweis für den Dienstwagen
 * (BMF-Schreiben vom 11.11.2025).
 */
#[CoversClass(EvChargingReportService::class)]
final class EvChargingReportTest extends ServiceTestCase
{
    private string $house;
    private string $wallbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->house = (string)$this->meters->defaultId('strom');
        $this->wallbox = (string)$this->meters->create('strom', ['name' => 'Wallbox', 'installed_on' => '2024-12-01',
            'parent_meter_id' => $this->house, 'role' => 'ev_charger'])['id'];
        $dev = fn(string $m) => $this->meters->get('strom', $m)['devices'][0]['id'];
        $all = [];
        foreach ([[$this->house, 500.0], [$this->wallbox, 250.0]] as [$m, $perMonth]) {
            for ($i = 0; $i <= 12; $i++) {
                $all[] = ['id' => "r_{$m}_$i", 'meter_id' => $m, 'device_id' => $dev($m), 'date' => date('Y-m-d', (int)strtotime("2025-01-01 +$i months")),
                          'counter' => $perMonth * $i, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
        }
        $this->store->write('strom/readings.json', $all);
    }

    private function service(): EvChargingReportService
    {
        return new EvChargingReportService($this->meters, $this->readings, $this->consumption, $this->settings, $this->i18n);
    }

    private function contract(): void
    {
        $this->contracts->create('strom', ['meter_id' => $this->house, 'provider' => 'A', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0], ['from' => '2025-02-15', 'ct_per_kwh' => 40.0]],
            'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12.0]]]);
    }

    /** Vertragspreis des Elternzählers, Grundpreis nach kWh-Anteil; Preiswechsel mitten im Monat tagesgenau. */
    public function testContractPriceWithProRataStandingCharge(): void
    {
        $this->contract();
        $r = $this->service()->report($this->wallbox, 2025, 'contract');
        self::assertSame($this->house, $r['payer_meter_id']);
        $rows = array_column($r['rows'], null, 'ym');
        self::assertEqualsWithDelta(250, $rows['2025-01']['kwh'], 0.01);
        self::assertEqualsWithDelta(30.0, $rows['2025-01']['price_ct'], 0.001);
        self::assertEqualsWithDelta(6.0, $rows['2025-01']['base_share_eur'], 0.01, '12 € × 250/500');
        self::assertEqualsWithDelta(81.0, $rows['2025-01']['amount_eur'], 0.01);
        // Februar: 14 Tage 30 ct, 14 Tage 40 ct → 35 ct
        self::assertEqualsWithDelta(35.0, $rows['2025-02']['price_ct'], 0.001);
        self::assertEqualsWithDelta(250 * 0.35 + 6, $rows['2025-02']['amount_eur'], 0.01);
        self::assertSame(['date' => '2025-01-01', 'counter' => 0.0], $rows['2025-01']['first_reading']);
    }

    /** v3.2.0 — PV lädt hinter dem Hauszähler: Die Wallbox misst mehr als der Netzbezug, der Grundpreis zählt höchstens ganz. */
    public function testPvBehindTheMeterNeverChargesMoreThanTheWholeStandingCharge(): void
    {
        $dev = fn(string $m) => $this->meters->get('strom', $m)['devices'][0]['id'];
        $all = [];
        foreach ([[$this->house, 300.0], [$this->wallbox, 400.0]] as [$m, $perMonth]) {
            for ($i = 0; $i <= 2; $i++) {
                $all[] = ['id' => "r_{$m}_$i", 'meter_id' => $m, 'device_id' => $dev($m), 'date' => date('Y-m-d', (int)strtotime("2025-06-01 +$i months")),
                          'counter' => $perMonth * $i, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
        }
        $this->store->write('strom/readings.json', $all);
        $this->contract();
        $rows = array_column($this->service()->report($this->wallbox, 2025, 'contract')['rows'], null, 'ym');
        self::assertEqualsWithDelta(12.0, $rows['2025-06']['base_share_eur'], 0.01, 'nicht 12 € × 400/300');
    }

    /** Spezifikation: 3.000 kWh mit der Pauschale 2026 (34 ct) = 1.020 €. */
    public function testFlatRate(): void
    {
        self::assertSame(34.0, EvChargingReportService::flatRate('DE', 2026));
        self::assertNull(EvChargingReportService::flatRate('AT', 2026));
        $r = $this->service()->report($this->wallbox, 2025, 'flat', 34.0);
        self::assertSame(34.0, $r['flat_ct']);
        self::assertEqualsWithDelta(3000, $r['total']['kwh'], 0.01);
        self::assertEqualsWithDelta(1020.0, $r['total']['amount_eur'], 0.01);
        self::assertEqualsWithDelta(1200.0, $this->service()->report($this->wallbox, 2025, 'flat', 40.0)['total']['amount_eur'], 0.01, 'eigene Pauschale');
        try {
            $this->service()->report($this->wallbox, 2025, 'flat');
            self::fail('Pauschale für 2025 gibt es nicht (bis 2025 galten Monatspauschalen)');
        } catch (LocalizedException $e) {
            self::assertSame('errors.evReport.flatMissing', $e->key);
        }
    }

    public function testWithoutAPayingContractThereIsNoReport(): void
    {
        $this->expectException(LocalizedException::class);
        $this->service()->report($this->wallbox, 2025, 'contract');
    }

    public function testCsvHeaderIsFrozenAndPdfIsWritten(): void
    {
        $this->contract();
        $r = $this->service()->report($this->wallbox, 2025, 'contract');
        $csv = (new CsvExportService($this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new \Energietracker\Services\WeatherService()),
            new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n))->evCharging($r);
        $lines = explode("\r\n", substr($csv, 3));
        self::assertSame('Monat;Zaehler-ID;kWh;Preis ct/kWh;Grundpreis-Anteil;Betrag;Methode', $lines[0]);
        self::assertSame("2025-01;{$this->wallbox};250;30;6;81;contract", $lines[1]);
        self::assertStringStartsWith('%PDF', $this->service()->pdf($r));
    }
}
