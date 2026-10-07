<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ContractService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\MeterService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Support\LocalizedException;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.1.0 (Paket H6, B6/#17, CALC-26, MKT-13) — ein Vertrag für eine
 * Zählergruppe (HT/NT mit einem Grundpreis) und § 14a EnWG Modul 1.
 */
#[CoversClass(ContractService::class)]
#[CoversClass(ConsumptionService::class)]
#[CoversClass(MeterService::class)]
final class GroupContractTest extends ServiceTestCase
{
    private string $ht;
    private string $nt;
    private string $gid;

    protected function setUp(): void
    {
        parent::setUp();
        // zwei Zählwerke eines Doppeltarifzählers als zwei Zähler in einer Gruppe
        $this->ht = (string)$this->meters->create('strom', ['name' => 'HT', 'installed_on' => '2024-01-01'])['id'];
        $this->nt = (string)$this->meters->create('strom', ['name' => 'NT', 'installed_on' => '2024-01-01'])['id'];
        $this->gid = (string)$this->meters->createGroup('strom', ['name' => 'Doppeltarif'])['id'];
        foreach ([$this->ht, $this->nt] as $m) $this->meters->update('strom', $m, ['meter_group_id' => $this->gid]);
        // HT 2.000 kWh, NT 1.000 kWh im Jahr 2025
        $all = [];
        foreach ([[$this->ht, 2000.0], [$this->nt, 1000.0]] as [$m, $year]) {
            $dev = $this->meters->get('strom', $m)['devices'][0]['id'];
            for ($i = 0; $i <= 12; $i++) {
                $all[] = ['id' => "r_{$m}_$i", 'meter_id' => $m, 'device_id' => $dev, 'date' => date('Y-m-d', (int)strtotime("2025-01-01 +$i months")),
                          'counter' => $year / 12 * $i, 'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
        }
        $this->store->write('strom/readings.json', $all);
    }

    private function groupContract(array $extra = []): array
    {
        return $this->contracts->create('strom', $extra + [
            'meter_group_id' => $this->gid, 'provider' => 'A', 'tariff_name' => 'HT/NT', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]],
            'working_prices_by_meter' => [$this->nt => [['from' => '2025-01-01', 'ct_per_kwh' => 22.0]]],
            'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 80.0]],
        ]);
    }

    private function year(array $rows, string $field): float
    {
        return array_sum(array_map(fn($r) => (float)($r[$field] ?? 0), array_filter($rows, fn($r) => str_starts_with((string)$r['ym'], '2025-'))));
    }

    /** CALC-26: HT 2.000 × 30 ct + NT 1.000 × 22 ct + 12 × 12 € = 964 € — Grundpreis einmal. */
    public function testHtNtWithOneStandingCharge(): void
    {
        $c = $this->groupContract();
        self::assertNull($c['meter_id']);
        self::assertSame($this->gid, $c['meter_group_id']);
        $group = $this->meters->groupTarget('strom', $this->gid);
        self::assertEqualsWithDelta(964.0, $this->year($this->consumption->forMeter('strom', $group), 'cost'), 0.05);
        // je Zähler: Arbeitspreis des Mitglieds, Grundpreis und Abschlag nur beim ersten
        $ht = $this->consumption->forMeter('strom', $this->meters->get('strom', $this->ht));
        $nt = $this->consumption->forMeter('strom', $this->meters->get('strom', $this->nt));
        self::assertEqualsWithDelta(600 + 144, $this->year($ht, 'cost'), 0.05);
        self::assertEqualsWithDelta(220, $this->year($nt, 'cost'), 0.05);
        self::assertEqualsWithDelta(960, $this->year($ht, 'advance_eur'), 0.05);
        self::assertSame(0.0, $this->year($nt, 'advance_eur'));
        // die Summe der Art zählt den Grundpreis einmal
        self::assertEqualsWithDelta(964.0, $this->year($this->consumption->forUtility('strom')['monthly_total'], 'cost'), 0.05);
        // Mitglied verweist auf den Gruppenvertrag
        self::assertSame(['group_id' => $this->gid, 'contract_id' => $c['id']],
            $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->nt))['group_contract'] ?? null);
    }

    public function testStatusForecastSwitchAndBillCheckRunOnTheGroup(): void
    {
        $c = $this->groupContract();
        $group = $this->meters->target('strom', $this->gid);
        self::assertTrue($group['is_group']);
        $row = $this->consumption->contractStatus('strom', $group)['contracts'][0];
        self::assertSame($c['id'], $row['contract_id']);
        self::assertEqualsWithDelta(3000, $row['actual_kwh'], 0.5);
        // Mischpreis für Hochrechnungen: 2/3 × 30 + 1/3 × 22 = 27,33 ct
        $view = $this->consumption->contractView('strom', $group, $c);
        self::assertEqualsWithDelta(27.333, $view['working_prices'][0]['ct_per_kwh'], 0.01);
        $fc = (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))->forMeter('strom', $group);
        self::assertNotEmpty($fc['forecast'] ?? []);
        $bd = $this->consumption->billBreakdown('strom', $group, '2025-01-01', '2026-01-01');
        self::assertEqualsWithDelta(964.0, $bd['totals']['total'], 0.1, 'Mengen je Abschnitt auf 0,1 kWh gerundet');
        self::assertEqualsWithDelta(144.0, $bd['totals']['fixed_cost'], 0.05, 'feste Kosten einmal');
        self::assertSame([$this->ht, $this->nt], array_values(array_unique(array_column($bd['rows'], 'meter_id'))));
    }

    /** Die Rechnung eines Mitglieds bucht ihr Ergebnis in den Gruppenvertrag. */
    public function testABillOfAMemberIsBookedIntoTheGroupContract(): void
    {
        $c = $this->groupContract();
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $b = $bills->create('strom', ['meter_id' => $this->nt, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'invoice' => ['amount_eur' => 1000, 'result_eur' => 40]]);
        self::assertSame($c['id'], $bills->book('strom', $b['id'])['contract_id']);
        $p = $this->contracts->get('strom', $c['id'])['special_payments'] ?? [];
        self::assertSame([['nachzahlung_ohne', 40.0]], array_map(fn($x) => [$x['kind'], (float)$x['amount_eur']], $p));
    }

    /** Eine Rechnung über HT und NT wird mit der ganzen Gruppe verglichen, nicht mit einem Zählwerk. */
    public function testABillOfAMemberIsComparedWithTheWholeGroup(): void
    {
        $this->groupContract();
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $b = $bills->create('strom', ['meter_id' => $this->ht, 'period_from' => '2025-01-01', 'period_to' => '2025-12-31',
            'invoice' => ['energy_kwh' => 3000, 'amount_eur' => 964, 'advances_paid_eur' => 960]]);
        $r = $bills->compare('strom', $b['id']);
        self::assertSame($this->gid, $r['group_id']);
        self::assertEqualsWithDelta(3000, $r['ours']['kwh'], 0.5);
        self::assertEqualsWithDelta(964.0, $r['ours']['total'], 0.1);
        self::assertEqualsWithDelta(960.0, $r['ours']['advances'], 0.5, 'Abschlag einmal');
        self::assertSame('ok', $r['verdict']);
    }

    public function testMembersCannotHaveTheirOwnContractAtTheSameTime(): void
    {
        $own = $this->contracts->create('strom', ['meter_id' => $this->ht, 'provider' => 'B', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]]]);
        try {
            $this->groupContract();
            self::fail('Überlappung angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.contract.groupMemberOverlap', $e->key);
        }
        // endet der eigene Vertrag vorher, geht es
        $this->contracts->update('strom', $own['id'], ['end' => '2024-12-31']);
        $this->groupContract();
        // und umgekehrt: kein neuer eigener Vertrag im Zeitraum der Gruppe
        try {
            $this->contracts->create('strom', ['meter_id' => $this->nt, 'provider' => 'C', 'start' => '2025-06-01',
                'working_prices' => [['from' => '2025-06-01', 'ct_per_kwh' => 25.0]]]);
            self::fail('Überlappung angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.contract.groupMemberOverlap', $e->key);
        }
        // Schattenvertrag auf der Gruppe ist erlaubt
        $shadow = $this->groupContract(['is_shadow' => true, 'start' => '2025-03-01']);
        self::assertTrue($shadow['is_shadow']);
    }

    public function testTargetsAreValidated(): void
    {
        foreach ([['strom', 'g_unbekannt'], ['wasser', $this->gid]] as [$u, $g]) {
            try {
                $this->contracts->create($u, ['meter_group_id' => $g, 'start' => '2025-01-01']);
                self::fail("$u/$g angenommen");
            } catch (LocalizedException $e) {
                self::assertSame('errors.contract.targetInvalid', $e->key);
            }
        }
        $other = (string)$this->meters->create('strom', ['name' => 'Wallbox', 'installed_on' => '2024-01-01'])['id'];
        try {
            $this->groupContract(['working_prices_by_meter' => [$other => [['from' => '2025-01-01', 'ct_per_kwh' => 20.0]]]]);
            self::fail('Preis für einen Zähler außerhalb der Gruppe angenommen');
        } catch (LocalizedException $e) {
            self::assertSame('errors.contract.targetInvalid', $e->key);
        }
        $this->groupContract();
        $this->expectException(\InvalidArgumentException::class);
        $this->meters->deleteGroup('strom', $this->gid);
    }

    /** MKT-13: § 14a Modul 1 — 120 € im Jahr senken die festen Kosten um 10 € je Monat, tagesgenau ab Stichtag. */
    public function testGridReductionModuleOne(): void
    {
        $id = (string)$this->meters->defaultId('strom');
        $this->setReadings('strom', $id, [['date' => '2025-01-01', 'counter' => 0, 'device_id' => $this->meters->get('strom', $id)['devices'][0]['id']],
                                          ['date' => '2025-03-01', 'counter' => 200, 'device_id' => $this->meters->get('strom', $id)['devices'][0]['id']]]);
        $c = $this->contracts->create('strom', ['meter_id' => $id, 'provider' => 'A', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 15.0]],
            'grid_reduction' => [['from' => '2025-02-15', 'eur_per_year' => 120]]]);
        self::assertSame([['from' => '2025-02-15', 'eur_per_year' => 120.0, 'module' => 1]], $c['grid_reduction']);
        self::assertEqualsWithDelta(5.0, $this->contracts->fixedPerMonthOn($c, '2025-03-01'), 0.001);
        $rows = array_column($this->consumption->forMeter('strom', $this->meters->get('strom', $id)), null, 'ym');
        self::assertEqualsWithDelta(15.0, $rows['2025-01']['base_price_eur'], 0.01);
        self::assertArrayNotHasKey('grid_reduction_eur', $rows['2025-01']);
        // Februar: 14 Tage ohne, 14 Tage mit Reduzierung
        self::assertEqualsWithDelta(10.0 * 14 / 28, $rows['2025-02']['grid_reduction_eur'], 0.01);
        self::assertEqualsWithDelta(15.0 - 10.0 * 14 / 28, $rows['2025-02']['base_price_eur'], 0.01);
        // Gas kennt die Reduzierung nicht
        $g = $this->contracts->create('gas', ['provider' => 'G', 'start' => '2025-01-01', 'grid_reduction' => [['from' => '2025-01-01', 'eur_per_year' => 120]],
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 10.0]]]);
        self::assertArrayNotHasKey('grid_reduction', $g);
    }
}
