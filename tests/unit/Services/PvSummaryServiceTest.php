<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\PvSummaryService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * F1005 — Eigenverbrauch + Autarkiequote.
 *
 *   eigenverbrauch_kwh   = pv_erzeugung − pv_einspeisung
 *   eigenverbrauchsquote = eigenverbrauch_kwh / pv_erzeugung_kwh
 *   autarkiequote        = eigenverbrauch_kwh / (eigenverbrauch_kwh + bezug_kwh)
 */
#[CoversClass(PvSummaryService::class)]
final class PvSummaryServiceTest extends ServiceTestCase
{
    public function testWithoutGenerationMeterFlagIsFalseAndQuotasAreNull(): void
    {
        // Nur pv_einspeisung anlegen, kein pv_erzeugung.
        $pv = $this->meters->create('pv_einspeisung', [
            'name' => 'Einspeisezähler',
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        $this->setReadings('pv_einspeisung', $pv['id'], [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => $pv['devices'][0]['id']],
            ['date' => '2024-03-01', 'counter' => 200.0, 'device_id' => $pv['devices'][0]['id']],
        ]);

        $out = (new PvSummaryService($this->consumption))->compute();
        self::assertFalse($out['has_generation_meter']);

        $byYm = array_column($out['monthly'], null, 'ym');
        // Quote NICHT berechenbar (kein Erzeugungszähler).
        self::assertNull($byYm['2024-02']['eigenverbrauchsquote']);
    }

    public function testEigenverbrauchAndAutarkieQuoteAreComputed(): void
    {
        // Erzeugung 600 kWh, Einspeisung 200 kWh → Eigenverbrauch 400 kWh.
        $erz = $this->meters->create('pv_erzeugung', [
            'name' => 'WR', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        $eins = $this->meters->create('pv_einspeisung', [
            'name' => 'Einsp', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        // Strom-Bezug 100 kWh → Autarkie = 400 / (400+100) = 0.80.
        $stromId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $this->setReadings('pv_erzeugung', $erz['id'], [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => $erz['devices'][0]['id']],
            ['date' => '2024-03-01', 'counter' => 600.0, 'device_id' => $erz['devices'][0]['id']],
        ]);
        $this->setReadings('pv_einspeisung', $eins['id'], [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => $eins['devices'][0]['id']],
            ['date' => '2024-03-01', 'counter' => 200.0, 'device_id' => $eins['devices'][0]['id']],
        ]);
        $this->setReadings('strom', $stromId, [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => 'd_strom_1'],
            ['date' => '2024-03-01', 'counter' => 100.0, 'device_id' => 'd_strom_1'],
        ]);

        $out = (new PvSummaryService($this->consumption))->compute();
        self::assertTrue($out['has_generation_meter']);

        $byYm = array_column($out['monthly'], null, 'ym');
        $row  = $byYm['2024-02'];

        self::assertEqualsWithDelta(400.0, (float)$row['eigenverbrauch_kwh'], 0.5);
        // Eigenverbrauchsquote = 400/600 ≈ 0.667
        self::assertEqualsWithDelta(0.6667, (float)$row['eigenverbrauchsquote'], 0.001);
        // Autarkiequote = 400/(400+100) = 0.8
        self::assertEqualsWithDelta(0.8, (float)$row['autarkiequote'], 0.001);
    }

    public function testEigenverbrauchClampedToZeroIfEinspeisungExceedsErzeugung(): void
    {
        // Datenfehler-Szenario: gemeldete Einspeisung > Erzeugung.
        // Eigenverbrauch darf NIE negativ werden.
        $erz = $this->meters->create('pv_erzeugung', [
            'name' => 'WR', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        $eins = $this->meters->create('pv_einspeisung', [
            'name' => 'Einsp', 'installed_on' => '2024-01-01', 'initial_counter' => 0.0,
        ]);
        $this->setReadings('pv_erzeugung', $erz['id'], [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => $erz['devices'][0]['id']],
            ['date' => '2024-03-01', 'counter' => 100.0, 'device_id' => $erz['devices'][0]['id']],
        ]);
        $this->setReadings('pv_einspeisung', $eins['id'], [
            ['date' => '2024-02-01', 'counter' => 0.0,   'device_id' => $eins['devices'][0]['id']],
            ['date' => '2024-03-01', 'counter' => 150.0, 'device_id' => $eins['devices'][0]['id']],
        ]);

        $out  = (new PvSummaryService($this->consumption))->compute();
        $row  = array_column($out['monthly'], null, 'ym')['2024-02'];
        self::assertSame(0.0, (float)$row['eigenverbrauch_kwh'],
            'Eigenverbrauch muss bei inkonsistenten Daten auf 0 geklemmt werden');
    }
}
