<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\App;
use Energietracker\Http\Request;
use Energietracker\Services\BackupService;
use Energietracker\Services\MeteringConceptService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (F1032, GitHub #40) — Assistent „Messkonzept“: aus dem Aufbau die
 * Zähler, Gruppen, Subzähler und Verträge; auf einem bestehenden Haushalt
 * zuordnen statt doppelt anlegen. Alle Namen und Preise erfunden.
 */
#[CoversClass(MeteringConceptService::class)]
final class MeteringConceptServiceTest extends ServiceTestCase
{
    private function svc(): MeteringConceptService
    {
        return new MeteringConceptService($this->meters, $this->contracts, $this->settings, $this->i18n, new BackupService($this->store, $this->i18n));
    }

    /** Die Kaskade aus #40, von Grund auf: HT/NT, PV, drei Verträge. */
    private static function cascadeInput(): array
    {
        return [
            'concept' => 'cascade', 'device' => 'heat_pump', 'pv' => true, 'battery' => false, 'tou' => 'all',
            'slots' => ['main' => ['name' => 'Z1', 'serial' => 'TEST-Z1'], 'house' => ['name' => 'Z2', 'serial' => 'TEST-Z2'],
                        'feed' => ['name' => 'Z1 Einspeisung', 'serial' => 'TEST-Z1'], 'gen' => ['name' => 'Z3 PV']],
            'contracts' => [
                'main'  => ['provider' => 'Versorger A', 'start' => '2024-01-01', 'ct' => 30, 'ct_nt' => 20, 'base' => 10],
                'house' => ['provider' => 'Versorger B', 'start' => '2024-01-01', 'ct' => 35, 'ct_nt' => 25, 'base' => 12],
                'feed'  => ['provider' => 'Netzbetreiber', 'start' => '2024-01-01', 'ct' => 8],
            ],
        ];
    }

    public function testCascadeFromScratchBuildsTheTopologyAndBillsTheRest(): void
    {
        $this->store->write('strom/meters.json', []);
        $this->store->write('strom/contracts.json', []);
        $before = count($this->meters->list('strom'));
        $preview = $this->svc()->plan(self::cascadeInput());
        self::assertSame([], $preview['problems']);
        self::assertSame($before, count($this->meters->list('strom')), 'die Vorschau schreibt nichts');
        $ops = array_count_values(array_column($preview['actions'], 'op'));
        self::assertSame(['activate' => 1, 'create_meter' => 6, 'create_group' => 2, 'create_contract' => 3], $ops);

        $res = $this->svc()->apply(self::cascadeInput());
        self::assertNotNull($res['snapshot'], 'vorher ein Snapshot');
        self::assertContains('pv_einspeisung', $this->settings->get('active_utilities'));
        $strom = array_column($this->meters->list('strom'), null, 'name');
        self::assertSame(['Z1 HT', 'Z1 NT', 'Z2 HT', 'Z2 NT'], array_keys($strom));
        self::assertSame(['1.8.1', '1.8.2', '1.8.1', '1.8.2'], array_column($strom, 'obis'));
        self::assertSame($strom['Z1 HT']['id'], $strom['Z2 HT']['parent_meter_id'], 'HT hinter HT');
        self::assertSame($strom['Z1 NT']['id'], $strom['Z2 NT']['parent_meter_id'], 'NT hinter NT');
        self::assertSame('heat_pump', $strom['Z1 HT']['role'] ?? null, 'vorn: der Rest ist die Wärmepumpe');
        self::assertArrayNotHasKey('role', $strom['Z2 HT'], 'Haushalt (Standardrolle)');
        self::assertSame('TEST-Z1', $strom['Z1 HT']['devices'][0]['serial']);
        self::assertSame($strom['Z1 HT']['meter_group_id'], $strom['Z1 NT']['meter_group_id']);
        self::assertSame('2.8.0', $this->meters->list('pv_einspeisung')[0]['obis'] ?? null);

        // Stände wie im Issue: der Assistent hat genau den Aufbau gebaut, der 125 € und 89,50 € rechnet
        $per = ['Z1 HT' => 400.0, 'Z1 NT' => 300.0, 'Z2 HT' => 150.0, 'Z2 NT' => 100.0];
        $all = [];
        foreach ($per as $name => $v) for ($i = 0; $i <= 12; $i++) {
            $all[] = ['id' => 'r_' . md5($name . $i), 'meter_id' => $strom[$name]['id'], 'device_id' => $strom[$name]['devices'][0]['id'],
                      'date' => date('Y-m-d', (int)strtotime("2024-01-01 +$i months")), 'counter' => 1000.0 + $v * $i,
                      'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write('strom/readings.json', $all);
        $g1 = $this->meters->groupTarget('strom', $strom['Z1 HT']['meter_group_id']);
        $g2 = $this->meters->groupTarget('strom', $strom['Z2 HT']['meter_group_id']);
        $row = fn(array $rows) => array_values(array_filter($rows, fn($r) => $r['ym'] === '2024-06'))[0];
        self::assertEqualsWithDelta(125.0, $row($this->consumption->forMeter('strom', $g1))['cost'], 0.01);
        self::assertEqualsWithDelta(89.5, $row($this->consumption->forMeter('strom', $g2))['cost'], 0.01);
    }

    /** Bestehender Haushalt: der Stromzähler mit Vertrag wird zugeordnet, nicht verdoppelt. */
    public function testExistingMeterIsAssignedNotDuplicated(): void
    {
        $main = $this->meters->list('strom')[0];
        $this->contracts->create('strom', ['meter_id' => (string)$main['id'], 'provider' => 'Bestand', 'start' => '2023-01-01',
            'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 32.0]]]);
        $in = ['concept' => 'behind', 'device' => 'heat_pump', 'pv' => false, 'tou' => 'none',
               'slots' => ['main' => ['meter_id' => (string)$main['id']], 'dev' => ['name' => 'Wärmepumpe']],
               'contracts' => ['main' => ['provider' => 'Neu', 'start' => '2025-01-01', 'ct' => 30],
                               'dev' => ['provider' => 'WP-Tarif', 'start' => '2025-01-01', 'ct' => 24]]];
        $p = $this->svc()->plan($in);
        $byOp = fn(string $op) => array_values(array_filter($p['actions'], fn($a) => $a['op'] === $op));
        self::assertCount(1, $byOp('update_meter'));
        self::assertCount(1, $byOp('create_meter'));
        self::assertSame('Bestand', $byOp('skip_contract')[0]['existing'], 'der laufende Vertrag bleibt');
        $this->svc()->apply($in);
        $strom = $this->meters->list('strom');
        self::assertCount(2, $strom, 'ein Zähler dazu, keiner doppelt');
        $wp = array_values(array_filter($strom, fn($m) => $m['name'] === 'Wärmepumpe'))[0];
        self::assertSame((string)$main['id'], $wp['parent_meter_id']);
        self::assertSame('heat_pump', $wp['role']);
        self::assertSame('1.8.0', $this->meters->get('strom', (string)$main['id'])['obis'] ?? null, 'Zählwerk ergänzt');
        self::assertCount(2, array_filter($this->contracts->list('strom'), fn($c) => empty($c['is_shadow'])), 'Bestand + Wärmepumpe');
    }

    /**
     * Skeptiker-Lauf v3.9.0 (M11, N16): Die Vorschau sagt, ab wann der Hausvertrag nur noch den Rest
     * rechnet (rückwirkend, wenn der neue Vertrag in der Vergangenheit beginnt), zeigt den laufenden
     * Vertrag und den Namen des vorhandenen Zählers; der neue Zähler ist ab Vertragsbeginn eingebaut
     * statt „heute“, mit Stand beim Einbau; ein späterer Einbau als der Vertrag wird genannt.
     */
    public function testHouseContractEffectExistingContractAndInstallDate(): void
    {
        $main = $this->meters->list('strom')[0];
        $this->contracts->create('strom', ['meter_id' => (string)$main['id'], 'provider' => 'Bestand', 'start' => '2023-01-01',
            'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 32.0]]]);
        $in = fn(array $dev, string $start) => ['concept' => 'behind', 'device' => 'heat_pump', 'tou' => 'none',
            'slots' => ['main' => ['meter_id' => (string)$main['id']], 'dev' => $dev],
            'contracts' => ['dev' => ['provider' => 'WP-Tarif', 'start' => $start, 'ct' => 24]]];
        $p = $this->svc()->plan($in(['name' => 'Wärmepumpe', 'initial_counter' => 1234.5], '2025-01-01'));
        self::assertSame((string)$main['name'], $p['slots']['main']['display_name'], 'der vorhandene Name statt „Hauszähler“');
        self::assertSame('Bestand', $p['slots']['main']['running_contract']['provider'] ?? null, 'laufender Vertrag sichtbar');
        self::assertNull($p['slots']['dev']['running_contract']);
        self::assertSame([['code' => 'rest_from', 'slot' => 'dev', 'parent' => (string)$main['name'], 'sub' => 'Wärmepumpe', 'date' => '2025-01-01', 'retro' => true]],
            $p['notices'], 'rückwirkend ab Vertragsbeginn nur noch der Rest');
        $create = array_values(array_filter($p['actions'], fn($a) => $a['op'] === 'create_meter'))[0];
        self::assertSame('2025-01-01', $create['data']['installed_on'], 'eingebaut ab Vertragsbeginn, nicht heute');
        self::assertSame(1234.5, $create['data']['initial_counter']);
        // später eingebaut als der Vertrag beginnt: Hinweis; künftiger Vertrag: nicht rückwirkend
        $late = $this->svc()->plan($in(['name' => 'Wärmepumpe', 'installed_on' => '2025-06-01'], '2025-01-01'));
        self::assertSame(['before_install'], array_values(array_diff(array_column($late['notices'], 'code'), ['rest_from'])));
        $future = date('Y-m-d', strtotime('+40 days'));
        $f = $this->svc()->plan($in(['name' => 'Wärmepumpe'], $future));
        self::assertFalse($f['notices'][0]['retro']);
        self::assertSame(date('Y-m-d'), array_values(array_filter($f['actions'], fn($a) => $a['op'] === 'create_meter'))[0]['data']['installed_on']);
        // ungültiges Einbaudatum
        $this->expectException(LocalizedException::class);
        $this->svc()->plan($in(['name' => 'Wärmepumpe', 'installed_on' => '01.06.2025'], '2025-01-01'));
    }

    public function testInvalidSetupsAreRefused(): void
    {
        try {
            $this->svc()->plan(['concept' => 'cascade', 'device' => 'heat_pump', 'tou' => 'device']);
            self::fail('HT/NT nur am Gerät in der Kaskade angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.concept.touCascade', $e->key);
        }
        try {
            $this->svc()->plan(['concept' => 'behind']);
            self::fail('ohne Gerät angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.concept.field', $e->key);
        }
        // ein vorhandener Elternzähler mit Subzählern kann nicht selbst Subzähler werden
        $a = $this->meters->list('strom')[0];
        $child = $this->meters->create('strom', ['name' => 'Kind', 'parent_meter_id' => (string)$a['id']]);
        $in = ['concept' => 'behind', 'device' => 'ev_charger', 'slots' => ['dev' => ['meter_id' => (string)$a['id']]]];
        self::assertSame('chain', $this->svc()->plan($in)['problems'][0]['code']);
        $this->expectException(LocalizedException::class);
        $this->svc()->apply($in);
        unset($child);
    }

    /** Die Vorschau nennt die Stellen für die Eingabe: Name vorbelegt, Zählwerke mit OBIS (änderbar). */
    public function testPlanListsSlotsWithDefaultNamesAndObisOverride(): void
    {
        $p = $this->svc()->plan(['concept' => 'behind', 'device' => 'heat_pump', 'pv' => true, 'tou' => 'device',
            'slots' => ['dev' => ['obis' => ['ht' => '1-0:1.8.1*255']]]]);
        self::assertSame(['main', 'dev', 'feed', 'gen'], array_keys($p['slots']));
        self::assertSame('Wärmepumpe', $p['slots']['dev']['name']);
        self::assertSame(['ht' => '1-0:1.8.1*255', 'nt' => '1.8.2'], $p['slots']['dev']['registers']);
        self::assertSame(['one' => '1.8.0'], $p['slots']['main']['registers'], 'HT/NT nur am Gerät');
        self::assertSame('main', $p['slots']['dev']['parent']);
        self::assertNull($p['slots']['gen']['contract'], 'Erzeugung ohne Vertrag');
        $created = array_values(array_filter($p['actions'], fn($a) => $a['op'] === 'create_meter' && $a['slot'] === 'dev'));
        self::assertSame(['Wärmepumpe HT', 'Wärmepumpe NT'], array_column($created, 'name'));
        self::assertSame('1-0:1.8.1*255', $created[0]['data']['obis']);
        self::assertSame('main|one', $created[1]['parent_ref'], 'beide Zählwerke hinter dem einen Zählwerk des Hauszählers');
        try {
            $this->svc()->plan(['concept' => 'single', 'slots' => ['main' => ['obis' => ['one' => '1.8']]]]);
            self::fail('ungültige Kennzahl angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.meter.obisInvalid', $e->key);
        }
    }

    public function testPreviewIsAReadOnlyPost(): void
    {
        $r = new \ReflectionClass(Request::class);
        $req = $r->newInstanceWithoutConstructor();
        foreach (['method' => 'POST', 'path' => '/api/metering-concept/preview'] as $k => $v) {
            $p = $r->getProperty($k);
            $p->setValue($req, $v);
        }
        self::assertTrue(App::readOnlyPost($req));
        self::assertTrue(App::locksLate($req));
    }
}
