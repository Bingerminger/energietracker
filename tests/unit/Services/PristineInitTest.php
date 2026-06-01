<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Storage\JsonStore;
use Energietracker\Storage\Migrator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v1.9.1 — Frisch-Install-Erkennung.
 *
 * Ein komplett leeres Datenverzeichnis (echter Erststart, z. B. frischer
 * Docker-Container) muss per `initFresh()` mit Standard-Zählern bestückt
 * werden, NICHT per `migrate()` (das ohne Altdaten einen leeren Tracker
 * hinterließe). `isPristine()` unterscheidet die Fälle.
 *
 * Eigener Temp-Store statt ServiceTestCase, weil hier gerade der
 * uninitialisierte Zustand getestet wird.
 */
#[CoversClass(Migrator::class)]
final class PristineInitTest extends TestCase
{
    private string $dir;
    private JsonStore $store;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/et-pristine-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0755, true);
        $this->dir = realpath($tmp) ?: $tmp;
        $this->store = new JsonStore($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['gas', 'strom', 'wasser', 'fernwaerme', 'heizoel', 'pellets', 'pv_einspeisung', 'pv_erzeugung'] as $u) {
            @array_map('unlink', glob("$this->dir/$u/*") ?: []);
            @rmdir("$this->dir/$u");
        }
        @array_map('unlink', glob("$this->dir/*") ?: []);
        @rmdir($this->dir);
    }

    public function testEmptyDirIsPristine(): void
    {
        $m = new Migrator($this->store);
        self::assertTrue($m->isPristine());
    }

    public function testDirWithMetaIsNotPristine(): void
    {
        $this->store->write('meta.json', ['schema_version' => '1.0.0']);
        self::assertFalse((new Migrator($this->store))->isPristine());
    }

    public function testDirWithV09FileIsNotPristine(): void
    {
        $this->store->write('gas.json', []);
        self::assertFalse((new Migrator($this->store))->isPristine());
    }

    public function testDirWithUtilityMetersIsNotPristine(): void
    {
        $this->store->write('strom/meters.json', []);
        self::assertFalse((new Migrator($this->store))->isPristine());
    }

    public function testInitFreshCreatesDefaultMetersAndIsNoLongerPristine(): void
    {
        $m = new Migrator($this->store);
        self::assertTrue($m->isPristine());
        $m->initFresh();

        self::assertFalse($m->isPristine());
        self::assertTrue($m->isAlreadyMigrated());
        // Gas/Strom/Wasser sind Verbrauchs-Utilities → je ein Default-Zähler.
        foreach (['gas', 'strom', 'wasser'] as $u) {
            $meters = $this->store->read("$u/meters.json", []);
            self::assertCount(1, $meters, "$u sollte genau einen Default-Zähler haben");
            self::assertArrayHasKey('external_id', $meters[0], 'Default-Meter trägt das v1.3.0-Feld');
        }
    }
}
