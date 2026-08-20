<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\MeterService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Storage\Migrator;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * F1006 — Meter-Topologie (Schema 1.2.0).
 *
 * Deckt ab: Migration (idempotent + Feld-Defaults), Validierung
 * (Selbstreferenz, fehlender Elternzähler, keine mehrstufigen Ketten),
 * Gruppen-CRUD, Merge-Wizard, delete-Guards und die Aggregation
 * (Subzähler fließen nicht doppelt in die Utility-Gesamtsumme).
 */
#[CoversClass(MeterService::class)]
#[CoversClass(Migrator::class)]
#[CoversClass(BenchmarkService::class)]
final class MeterTopologyTest extends ServiceTestCase
{
    // ── Migration ─────────────────────────────────────────────────────────

    public function testFreshInstallHasTopologyFieldsAndGroupFile(): void
    {
        $migrator = new Migrator($this->store);
        self::assertFalse($migrator->needsV120Upgrade(), 'initFresh muss bereits 1.2.0-konform sein');

        self::assertSame('1.4.0', Migrator::SCHEMA_VERSION);
        self::assertTrue($this->store->exists('strom/meter_groups.json'));

        $meterId = $this->meters->defaultId('strom');
        $meter = $this->meters->get('strom', $meterId);
        self::assertArrayHasKey('parent_meter_id', $meter);
        self::assertArrayHasKey('meter_group_id', $meter);
        self::assertNull($meter['parent_meter_id']);
        self::assertNull($meter['meter_group_id']);
    }

    public function testUpgradeToV120IsIdempotentAndAdditive(): void
    {
        // Simuliere einen 1.1.0-Zähler ohne die neuen Felder.
        $this->store->write('strom/meters.json', [[
            'id'         => 'm_strom_legacy',
            'name'       => 'Alt',
            'icon'       => '⚡',
            'created_at' => '2024-01-01',
            'active'     => true,
            'notes'      => '',
            'devices'    => [[
                'id' => 'd_x', 'serial' => null, 'installed_on' => '2024-01-01',
                'initial_counter' => 0.0, 'removed_on' => null,
                'final_counter' => null, 'reason' => null,
            ]],
        ]]);
        // meter_groups.json entfernen, damit needsV120Upgrade greift.
        @unlink($this->store->path('strom/meter_groups.json'));

        $migrator = new Migrator($this->store);
        self::assertTrue($migrator->needsV120Upgrade());

        $migrator->upgradeToV120();

        $meter = $this->meters->get('strom', 'm_strom_legacy');
        self::assertArrayHasKey('parent_meter_id', $meter);
        self::assertArrayHasKey('meter_group_id', $meter);
        self::assertNull($meter['parent_meter_id']);
        self::assertNull($meter['meter_group_id']);
        self::assertTrue($this->store->exists('strom/meter_groups.json'));

        // Zweiter Aufruf = No-Op.
        self::assertFalse($migrator->needsV120Upgrade());
        $before = $this->store->read('strom/meters.json', []);
        $migrator->upgradeToV120();
        $after = $this->store->read('strom/meters.json', []);
        self::assertSame($before, $after, 'upgradeToV120 muss idempotent sein');
    }

    // ── Validierung ─────────────────────────────────────────────────────

    public function testMeterCannotBeItsOwnParent(): void
    {
        $meterId = $this->meters->defaultId('strom');
        $this->expectException(\InvalidArgumentException::class);
        $this->meters->update('strom', $meterId, ['parent_meter_id' => $meterId]);
    }

    public function testParentMeterMustExist(): void
    {
        $meterId = $this->meters->defaultId('strom');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Elternz/');
        $this->meters->update('strom', $meterId, ['parent_meter_id' => 'm_does_not_exist']);
    }

    public function testNoMultiLevelSubmeterChain(): void
    {
        $parent = $this->meters->defaultId('strom');
        $mid = $this->meters->create('strom', ['name' => 'Mitte'])['id'];
        $leaf = $this->meters->create('strom', ['name' => 'Blatt'])['id'];

        // Mitte → parent (1 Ebene, ok)
        $this->meters->update('strom', $mid, ['parent_meter_id' => $parent]);

        // Blatt → Mitte würde zweite Ebene erzeugen → abgelehnt.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/[Mm]ehrstufige/');
        $this->meters->update('strom', $leaf, ['parent_meter_id' => $mid]);
    }

    public function testParentThatIsAlreadyAnElternZaehlerCannotBecomeSubmeter(): void
    {
        $parent = $this->meters->defaultId('strom');
        $child  = $this->meters->create('strom', ['name' => 'Kind'])['id'];
        $other  = $this->meters->create('strom', ['name' => 'Anderer'])['id'];

        // Kind hängt unter parent — parent ist damit Elternzähler.
        $this->meters->update('strom', $child, ['parent_meter_id' => $parent]);

        // parent selbst zum Subzähler von 'other' machen → zweite Ebene → abgelehnt.
        $this->expectException(\InvalidArgumentException::class);
        $this->meters->update('strom', $parent, ['parent_meter_id' => $other]);
    }

    // ── Gruppen-CRUD ────────────────────────────────────────────────────

    public function testGroupCrud(): void
    {
        $g = $this->meters->createGroup('strom', ['name' => 'Wallboxen']);
        self::assertNotEmpty($g['id']);
        self::assertSame('Wallboxen', $g['name']);

        $g2 = $this->meters->updateGroup('strom', $g['id'], ['name' => 'Garage']);
        self::assertSame('Garage', $g2['name']);

        self::assertCount(1, $this->meters->listGroups('strom'));
        $this->meters->deleteGroup('strom', $g['id']);
        self::assertCount(0, $this->meters->listGroups('strom'));
    }

    public function testCreateGroupRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->meters->createGroup('strom', ['name' => '   ']);
    }

    public function testMeterGroupIdMustReferenceExistingGroup(): void
    {
        $meterId = $this->meters->defaultId('strom');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/[Gg]ruppe/');
        $this->meters->update('strom', $meterId, ['meter_group_id' => 'g_nope']);
    }

    public function testDeleteGroupReleasesMembers(): void
    {
        $g = $this->meters->createGroup('strom', ['name' => 'NT+HT']);
        $a = $this->meters->create('strom', ['name' => 'NT', 'meter_group_id' => $g['id']])['id'];

        self::assertSame($g['id'], $this->meters->get('strom', $a)['meter_group_id']);

        $this->meters->deleteGroup('strom', $g['id']);
        self::assertNull($this->meters->get('strom', $a)['meter_group_id'],
            'Löschen einer Gruppe löst die Mitglieder, statt sie zu blocken');
    }

    // ── Merge-Wizard ────────────────────────────────────────────────────

    public function testMergeIntoNewGroup(): void
    {
        $a = $this->meters->create('strom', ['name' => 'NT'])['id'];
        $b = $this->meters->create('strom', ['name' => 'HT'])['id'];

        $res = $this->meters->mergeIntoGroup('strom', [
            'name'      => 'Strom NT+HT',
            'meter_ids' => [$a, $b],
        ]);

        self::assertSame(2, $res['members']);
        $gid = $res['group']['id'];
        self::assertSame($gid, $this->meters->get('strom', $a)['meter_group_id']);
        self::assertSame($gid, $this->meters->get('strom', $b)['meter_group_id']);
    }

    public function testMergeRequiresAtLeastTwoMeters(): void
    {
        $a = $this->meters->create('strom', ['name' => 'NT'])['id'];
        $this->expectException(\InvalidArgumentException::class);
        $this->meters->mergeIntoGroup('strom', ['name' => 'X', 'meter_ids' => [$a]]);
    }

    // ── delete-Guard ────────────────────────────────────────────────────

    public function testCannotDeleteParentWithSubmeters(): void
    {
        $parent = $this->meters->defaultId('strom');
        $child  = $this->meters->create('strom', ['name' => 'Wärmepumpe'])['id'];
        $this->meters->update('strom', $child, ['parent_meter_id' => $parent]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Elternz/');
        $this->meters->delete('strom', $parent);
    }

    // ── Aggregation (keine Doppelzählung) ───────────────────────────────

    public function testSubmeterIsNotDoubleCountedInUtilityTotal(): void
    {
        // Eltern misst brutto inkl. Subzähler; Sub ist nur eine Aufschlüsselung.
        $this->store->write('strom/meters.json', [
            [
                'id' => 'm_eltern', 'name' => 'Haushalt', 'icon' => '⚡',
                'created_at' => '2024-01-01', 'active' => true, 'notes' => '',
                'parent_meter_id' => null, 'meter_group_id' => null,
                'devices' => [[
                    'id' => 'd_e1', 'serial' => null, 'installed_on' => '2024-01-01',
                    'initial_counter' => 1000.0, 'removed_on' => null,
                    'final_counter' => null, 'reason' => null,
                ]],
            ],
            [
                'id' => 'm_sub', 'name' => 'Wärmepumpe', 'icon' => '⚡',
                'created_at' => '2024-01-01', 'active' => true, 'notes' => '',
                'parent_meter_id' => 'm_eltern', 'meter_group_id' => null,
                'devices' => [[
                    'id' => 'd_s1', 'serial' => null, 'installed_on' => '2024-01-01',
                    'initial_counter' => 0.0, 'removed_on' => null,
                    'final_counter' => null, 'reason' => null,
                ]],
            ],
        ]);
        $this->store->write('strom/readings.json', [
            ['id' => 'r1', 'meter_id' => 'm_eltern', 'device_id' => 'd_e1', 'date' => '2024-01-31', 'counter' => 1300.0, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false],
            ['id' => 'r2', 'meter_id' => 'm_eltern', 'device_id' => 'd_e1', 'date' => '2024-02-29', 'counter' => 1600.0, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false],
            ['id' => 'r3', 'meter_id' => 'm_sub',    'device_id' => 'd_s1', 'date' => '2024-01-31', 'counter' => 100.0,  'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false],
            ['id' => 'r4', 'meter_id' => 'm_sub',    'device_id' => 'd_s1', 'date' => '2024-02-29', 'counter' => 220.0,  'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false],
        ]);

        $result = $this->consumption->forUtility('strom');

        // Beide Zähler erscheinen in der Aufschlüsselung ...
        self::assertCount(2, $result['meters']);

        // ... aber die Gesamtsumme entspricht nur dem Elternzähler.
        $elternKwh = 0.0;
        foreach ($result['meters'] as $entry) {
            if ($entry['meter']['id'] !== 'm_eltern') continue;
            foreach ($entry['monthly'] as $m) $elternKwh += (float)($m['kwh'] ?? 0);
        }
        $totalKwh = 0.0;
        foreach ($result['monthly_total'] as $m) $totalKwh += (float)($m['kwh'] ?? 0);

        self::assertGreaterThan(0.0, $elternKwh);
        self::assertEqualsWithDelta($elternKwh, $totalKwh, 0.001,
            'Subzähler darf nicht zusätzlich in die Utility-Gesamtsumme einfließen');
    }

    /**
     * v2.1.3 — Regression: dieselbe Subzähler-Ausschlussregel muss auch in der
     * Effizienzklasse (BenchmarkService) gelten. Vorher summierte
     * yearKwhForUtility über ALLE Zähler → der Subzähler blähte kWh/m²·a auf.
     * (Der Jahresbericht-PDF nutzt dasselbe Muster — yearAggregate.)
     */
    public function testSubmeterDoesNotInflateEfficiency(): void
    {
        // Heizquelle (Gas) mit Eltern (brutto inkl. Sub) + Subzähler.
        $this->store->write('gas/meters.json', [
            [
                'id' => 'm_gas_eltern', 'name' => 'Hausanschluss', 'icon' => '🔥',
                'created_at' => '2024-01-01', 'active' => true, 'notes' => '',
                'parent_meter_id' => null, 'meter_group_id' => null,
                'devices' => [[
                    'id' => 'd_ge', 'serial' => null, 'installed_on' => '2024-01-01',
                    'initial_counter' => 1000.0, 'removed_on' => null,
                    'final_counter' => null, 'reason' => null,
                ]],
            ],
            [
                'id' => 'm_gas_sub', 'name' => 'Einliegerwohnung', 'icon' => '🔥',
                'created_at' => '2024-01-01', 'active' => true, 'notes' => '',
                'parent_meter_id' => 'm_gas_eltern', 'meter_group_id' => null,
                'devices' => [[
                    'id' => 'd_gs', 'serial' => null, 'installed_on' => '2024-01-01',
                    'initial_counter' => 0.0, 'removed_on' => null,
                    'final_counter' => null, 'reason' => null,
                ]],
            ],
        ]);
        $this->store->write('gas/readings.json', [
            ['id' => 'r1', 'meter_id' => 'm_gas_eltern', 'device_id' => 'd_ge', 'date' => '2024-06-30', 'counter' => 4000.0, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false],
            ['id' => 'r2', 'meter_id' => 'm_gas_eltern', 'device_id' => 'd_ge', 'date' => '2024-12-31', 'counter' => 7000.0, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false],
            ['id' => 'r3', 'meter_id' => 'm_gas_sub',    'device_id' => 'd_gs', 'date' => '2024-06-30', 'counter' => 800.0,  'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false],
            ['id' => 'r4', 'meter_id' => 'm_gas_sub',    'device_id' => 'd_gs', 'date' => '2024-12-31', 'counter' => 1600.0, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false],
        ]);

        $bench = new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
        $eff = $bench->efficiency(2024);

        $gas = null;
        foreach ($eff['per_source'] as $s) {
            if ($s['utility'] === 'gas') { $gas = $s; break; }
        }
        self::assertNotNull($gas, 'Gas muss als Heizquelle in der Effizienz erscheinen');

        // Erwartung = NUR der Elternzähler; der Subzähler steckt bereits im
        // Eltern-Brutto und darf nicht zusätzlich gezählt werden.
        $parent = $this->meters->get('gas', 'm_gas_eltern');
        $parentKwh = 0.0;
        foreach ($this->consumption->forMeter('gas', $parent) as $m) {
            if ((int)($m['year'] ?? 0) === 2024) $parentKwh += (float)($m['kwh'] ?? 0);
        }
        self::assertGreaterThan(0.0, $parentKwh);
        self::assertEqualsWithDelta(round($parentKwh, 1), $gas['kwh'], 0.1,
            'Subzähler darf die Effizienz-kWh nicht aufblähen (F1006-Doppelzählung)');
    }

    public function testUtilityResultExposesMeterGroups(): void
    {
        $this->meters->createGroup('strom', ['name' => 'Gruppe A']);
        $result = $this->consumption->forUtility('strom');
        self::assertArrayHasKey('meter_groups', $result);
        self::assertCount(1, $result['meter_groups']);
    }
}
