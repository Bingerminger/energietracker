<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\MeterService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Issue #13 — Zählertausch-Disziplin.
 *  - `old_final_counter` ist Pflicht (vorher: stiller Default 0).
 *  - `deviceOnDate`-Konvention: am `removed_on`-Tag bereits das neue Gerät.
 */
#[CoversClass(MeterService::class)]
final class MeterServiceReplaceDeviceTest extends ServiceTestCase
{
    public function testReplaceDeviceRejectsMissingOldFinalCounter(): void
    {
        $meterId = $this->meters->defaultId('strom');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/old_final_counter/');

        $this->meters->replaceDevice('strom', $meterId, [
            'date'                => '2024-06-15',
            'new_initial_counter' => 0.0,
            // 'old_final_counter' fehlt absichtlich
        ]);
    }

    public function testReplaceDeviceRejectsEmptyOldFinalCounter(): void
    {
        $meterId = $this->meters->defaultId('strom');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/old_final_counter/');

        $this->meters->replaceDevice('strom', $meterId, [
            'date'                => '2024-06-15',
            'old_final_counter'   => '',   // ⚠ leer statt Zahl
            'new_initial_counter' => 0.0,
        ]);
    }

    public function testReplaceDeviceClosesOldDeviceAndOpensNewOne(): void
    {
        $meterId = $this->meters->defaultId('strom');

        $this->meters->replaceDevice('strom', $meterId, [
            'date'                => '2024-06-15',
            'old_final_counter'   => 11000.0,
            'new_initial_counter' => 0.0,
            'reason'              => 'turnusmaeßig',
        ]);

        $meter = $this->meters->get('strom', $meterId);
        $devices = $meter['devices'];
        self::assertCount(2, $devices, 'replaceDevice öffnet ein zweites Gerät');

        // Altes Gerät: removed_on + final_counter gesetzt.
        self::assertSame('2024-06-15', $devices[0]['removed_on']);
        self::assertSame(11000.0, (float)$devices[0]['final_counter']);

        // Neues Gerät: offen, initial_counter gepflegt.
        self::assertNull($devices[1]['removed_on']);
        self::assertSame(0.0, (float)$devices[1]['initial_counter']);
        self::assertSame('2024-06-15', $devices[1]['installed_on']);
    }

    /**
     * Stichtag-Konvention: am `removed_on` selbst zeigt {@see MeterService::deviceOnDate()}
     * bereits auf das NEUE Gerät. Vor diesem Tag noch auf das alte.
     */
    public function testDeviceOnDateUsesGreaterOrEqualForRemovedOn(): void
    {
        // Default-installed_on des Migrator::initFresh() ist heute — für
        // einen Tausch-Test in der Vergangenheit das Initial-Datum auf
        // 2024-01-01 zurücksetzen.
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2024-01-01', 'initial_counter' => 10000.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $this->meters->replaceDevice('strom', $meterId, [
            'date'              => '2024-06-15',
            'old_final_counter' => 11000.0,
            'new_initial_counter' => 0.0,
        ]);
        $meter = $this->meters->get('strom', $meterId);

        $devBefore = $this->meters->deviceOnDate($meter, '2024-06-14');
        $devSwap   = $this->meters->deviceOnDate($meter, '2024-06-15');
        $devAfter  = $this->meters->deviceOnDate($meter, '2024-06-16');

        self::assertNotNull($devBefore);
        self::assertSame('2024-06-15', $devBefore['removed_on'],
            'Tag vor Tausch zeigt aufs alte Gerät');
        self::assertNotNull($devSwap);
        self::assertNull($devSwap['removed_on'],
            'Tausch-Tag zeigt bereits aufs neue Gerät');
        self::assertNotNull($devAfter);
        self::assertNull($devAfter['removed_on']);
        self::assertSame($devSwap['id'], $devAfter['id']);
    }
}
