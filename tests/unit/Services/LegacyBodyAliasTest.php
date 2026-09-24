<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\MeterService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.6.0 — früher dokumentierte Request-Körper (Review API-18).
 *
 * docs/API.md beschrieb bis v2.5.3 für das Anlegen eines Zählers ein Objekt
 * `device` und für den Zählertausch `removed_on`/`final_counter`/`new_device`.
 * Der Code las beides nie: Das Anlegen ignorierte die Felder still, der
 * Tausch endete in „old_final_counter fehlt". Wer nach der Doku integriert
 * hat, soll nicht bestraft werden — die Namen gelten jetzt als Aliase.
 */
#[CoversClass(MeterService::class)]
final class LegacyBodyAliasTest extends ServiceTestCase
{
    public function testCreateAcceptsDocumentedDeviceObject(): void
    {
        $m = $this->meters->create('wasser', [
            'name'   => 'Garten',
            'device' => ['serial' => 'WZ-1', 'installed_on' => '2021-04-15', 'initial_counter' => 12.5],
        ]);
        $d = $m['devices'][0];
        self::assertSame('WZ-1', $d['serial']);
        self::assertSame('2021-04-15', $d['installed_on']);
        self::assertSame(12.5, $d['initial_counter']);
    }

    public function testCurrentFieldNamesWinOverTheAlias(): void
    {
        $m = $this->meters->create('wasser', [
            'name'          => 'Garten',
            'device_serial' => 'NEU',
            'device'        => ['serial' => 'ALT'],
        ]);
        self::assertSame('NEU', $m['devices'][0]['serial']);
    }

    public function testReplaceDeviceAcceptsDocumentedLegacyBody(): void
    {
        $meterId = $this->meters->defaultId('strom');
        $this->meters->replaceDevice('strom', $meterId, [
            'removed_on'    => '2024-08-22',
            'final_counter' => 18432.5,
            'reason'        => 'Eichfrist',
            'new_device'    => ['serial' => 'S-NEU', 'installed_on' => '2024-08-22', 'initial_counter' => 3.0],
        ]);
        $devices = $this->meters->get('strom', $meterId)['devices'];
        self::assertCount(2, $devices);
        self::assertSame('2024-08-22', $devices[0]['removed_on']);
        self::assertSame(18432.5, $devices[0]['final_counter']);
        self::assertSame('S-NEU', $devices[1]['serial']);
        self::assertEqualsWithDelta(3.0, (float)$devices[1]['initial_counter'], 1e-9);
    }

    public function testLegacyBodyWithoutFinalCounterIsStillRejected(): void
    {
        $meterId = $this->meters->defaultId('strom');
        $this->expectException(\InvalidArgumentException::class);
        $this->meters->replaceDevice('strom', $meterId, [
            'removed_on' => '2024-08-22',
            'new_device' => ['initial_counter' => 0.0],
        ]);
    }
}
