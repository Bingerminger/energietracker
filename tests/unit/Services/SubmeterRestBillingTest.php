<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\SubmeterRest;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (F1032, GitHub #40) — Rest-Abrechnung am Elternzähler.
 *
 * Die Doku versprach seit v3.1.0 (Modul 2): „Die App zieht die Menge dort ab,
 * der Haushaltsvertrag rechnet nur den Rest.“ Umgesetzt war das nie, kein Test
 * prüfte es. Nachgemessen 2026-10-26: Elternzähler 500 kWh, Subzähler 200 kWh
 * im Monat, Verträge 20 und 40 ct ohne Grundpreis — der Elternvertrag rechnete
 * 500 kWh, die Monatssumme der Kosten war 100 € statt 140 €.
 *
 * Alle Erwartungen von Hand gerechnet.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(SubmeterRest::class)]
final class SubmeterRestBillingTest extends ServiceTestCase
{
    private string $parent;
    private string $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store->write('strom/meters.json', []);
        $this->store->write('strom/contracts.json', []);
        $this->parent = (string)$this->meters->create('strom', ['name' => 'Hausanschluss', 'installed_on' => '2024-01-01'])['id'];
        $this->sub = (string)$this->meters->create('strom', ['name' => 'Wärmepumpe', 'installed_on' => '2024-01-01',
            'parent_meter_id' => $this->parent])['id'];
    }

    /** Monatliche Stände am Ersten: je Kalendermonat genau `perMonth`, 24 Monate ab 2024-01. */
    private function monthlyReadings(array $perMeter, array $override = []): void
    {
        $all = [];
        foreach ($perMeter as $id => $perMonth) {
            $dev = $this->meters->get('strom', $id)['devices'][0]['id'];
            $counter = 1000.0;
            for ($i = 0; $i <= 24; $i++) {
                $date = date('Y-m-d', (int)strtotime("2024-01-01 +$i months"));
                $all[] = ['id' => "r_{$id}_$i", 'meter_id' => $id, 'device_id' => $dev, 'date' => $date, 'counter' => $counter,
                          'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
                $counter += $override[$id][$date] ?? $perMonth;
            }
        }
        $this->store->write('strom/readings.json', $all);
    }

    private function contract(string $meterId, float $ct, string $start = '2024-01-01', array $extra = []): array
    {
        return $this->contracts->create('strom', $extra + ['meter_id' => $meterId, 'provider' => 'Testversorger', 'start' => $start,
            'working_prices' => [['from' => $start, 'ct_per_kwh' => $ct]]]);
    }

    private function row(array $rows, string $ym): array
    {
        foreach ($rows as $r) if (($r['ym'] ?? '') === $ym) return $r;
        self::fail("Monat $ym fehlt");
    }

    /**
     * Zeile des Vertragsstatus. Seit dem Paket auswertung (v3.9.0) rechnet der Status über die
     * laufende Abrechnungsperiode (Stichtag 01.01.); `$today` wählt sie: 2025-12-31 = Periode 2025.
     */
    private function contractRow(string $meterId, string $contractId, string $today = '2025-12-31'): array
    {
        foreach ($this->consumption->contractStatus('strom', $this->meters->get('strom', $meterId), $today)['contracts'] as $c) {
            if ($c['contract_id'] === $contractId) return $c;
        }
        self::fail("Vertrag $contractId fehlt");
    }

    /**
     * Der Befund als Regressionstest: 500 und 200 kWh im Monat, 20 und 40 ct, 24 Monate.
     * Elternvertrag 300 kWh × 20 ct = 60 €, Subzähler 200 × 40 ct = 80 €, Summe 140 € bei 500 kWh.
     */
    public function testParentContractBillsOnlyTheRest(): void
    {
        $this->monthlyReadings([$this->parent => 500.0, $this->sub => 200.0]);
        $pc = $this->contract($this->parent, 20.0);
        $sc = $this->contract($this->sub, 40.0);

        $p = $this->row($this->consumption->forMeter('strom', $this->meters->get('strom', $this->parent)), '2024-06');
        self::assertEqualsWithDelta(500.0, $p['kwh'], 0.05, 'die Messung bleibt die Messung');
        self::assertEqualsWithDelta(300.0, $p['billed_kwh'], 0.05);
        self::assertEqualsWithDelta(60.0, $p['cost'], 0.01);
        self::assertEqualsWithDelta(20.0, $p['working_price_ct'], 0.001);
        self::assertSame(['measured' => 500.0, 'submeters' => [['meter_id' => $this->sub, 'name' => 'Wärmepumpe', 'kwh' => 200.0]], 'rest' => 300.0],
            $p['rest_of']);

        // Vertragsstatus in der laufenden Abrechnungsperiode 2025 (zwölf Monate)
        self::assertEqualsWithDelta(3600.0, $this->contractRow($this->parent, $pc['id'])['actual_kwh'], 0.5);
        self::assertEqualsWithDelta(720.0, $this->contractRow($this->parent, $pc['id'])['actual_cost'], 0.05);
        self::assertEqualsWithDelta(2400.0, $this->contractRow($this->sub, $sc['id'])['actual_kwh'], 0.5);
        $hint = $this->contractRow($this->parent, $pc['id'])['rest_of'];
        self::assertEqualsWithDelta(6000.0, $hint['measured'], 0.5);
        self::assertEqualsWithDelta(2400.0, $hint['submeters'][0]['kwh'], 0.5);
        self::assertEqualsWithDelta(3600.0, $hint['rest'], 0.5);

        $total = $this->row($this->consumption->forUtility('strom')['monthly_total'], '2024-06');
        self::assertEqualsWithDelta(500.0, $total['kwh'], 0.05, 'keine Doppelzählung');
        self::assertEqualsWithDelta(140.0, $total['cost'], 0.01, 'Rest × 20 ct + Subzähler × 40 ct');
    }

    /** Ohne eigenen Vertrag bleibt der Subzähler eine Aufschlüsselung: Elternvertrag brutto, 100 €. */
    public function testSubmeterWithoutContractStaysABreakdown(): void
    {
        $this->monthlyReadings([$this->parent => 500.0, $this->sub => 200.0]);
        $pc = $this->contract($this->parent, 20.0);
        $p = $this->row($this->consumption->forMeter('strom', $this->meters->get('strom', $this->parent)), '2024-06');
        self::assertEqualsWithDelta(100.0, $p['cost'], 0.01);
        self::assertArrayNotHasKey('rest_of', $p);
        self::assertArrayNotHasKey('billed_kwh', $p);
        self::assertArrayNotHasKey('rest_of', $this->contractRow($this->parent, $pc['id']));
        self::assertEqualsWithDelta(6000.0, $this->contractRow($this->parent, $pc['id'])['actual_kwh'], 0.5, 'Periode 2025 brutto');
        self::assertEqualsWithDelta(100.0, $this->row($this->consumption->forUtility('strom')['monthly_total'], '2024-06')['cost'], 0.01);
        self::assertFalse($this->consumption->isDeductingSubmeter('strom', $this->meters->get('strom', $this->sub)));
    }

    /**
     * Der Vertrag des Subzählers beginnt am 16.03.2025 — tagesgenau: vorher brutto.
     * März: Subzähler 200 kWh × 16/31 an Vertragstagen = 103,23 kWh; Rest 396,77 kWh × 20 ct = 79,35 €;
     * Subzähler 103,23 × 40 ct = 41,29 €; Summe 120,65 €. Februar: 100 €, kein Hinweis.
     */
    public function testSubmeterContractStartingMidMonthDeductsFromThatDayOn(): void
    {
        $this->monthlyReadings([$this->parent => 500.0, $this->sub => 200.0]);
        $this->contract($this->parent, 20.0);
        $this->contract($this->sub, 40.0, '2025-03-16');
        $rows = $this->consumption->forMeter('strom', $this->meters->get('strom', $this->parent));
        $feb = $this->row($rows, '2025-02');
        self::assertEqualsWithDelta(100.0, $feb['cost'], 0.01);
        self::assertArrayNotHasKey('rest_of', $feb);
        $mar = $this->row($rows, '2025-03');
        $subKwh = 200.0 * 16 / 31;
        self::assertEqualsWithDelta(500.0 - $subKwh, $mar['billed_kwh'], 0.05);
        self::assertEqualsWithDelta((500.0 - $subKwh) * 0.20, $mar['cost'], 0.01);
        self::assertEqualsWithDelta($subKwh, $mar['rest_of']['submeters'][0]['kwh'], 0.05);
        $totals = $this->consumption->forUtility('strom')['monthly_total'];
        self::assertEqualsWithDelta((500.0 - $subKwh) * 0.20 + $subKwh * 0.40, $this->row($totals, '2025-03')['cost'], 0.02);
        self::assertEqualsWithDelta(100.0, $this->row($totals, '2025-02')['cost'], 0.01);
        self::assertEqualsWithDelta(140.0, $this->row($totals, '2025-04')['cost'], 0.01);
    }

    /**
     * Ablesepreis am Subzähler (`price_cents` 30) vor seinem Vertrag: Diese Tage rechnet der
     * Elternvertrag brutto, ihr Ablesepreis zählt in der Summe nicht noch einmal. März 2025:
     * Rest 396,77 × 20 ct + Subzähler an Vertragstagen 103,23 × 40 ct = 120,65 € (nicht + 96,77 × 30 ct).
     */
    public function testUncontractedDaysOfTheSubmeterAreNotCountedTwice(): void
    {
        $this->monthlyReadings([$this->parent => 500.0, $this->sub => 200.0]);
        $all = $this->store->read('strom/readings.json', []);
        foreach ($all as &$r) if ($r['meter_id'] === $this->sub) $r['price_cents'] = 30.0;
        unset($r);
        $this->store->write('strom/readings.json', $all);
        $this->contract($this->parent, 20.0);
        $this->contract($this->sub, 40.0, '2025-03-16');
        $sub = $this->row($this->consumption->forMeter('strom', $this->meters->get('strom', $this->sub)), '2025-03');
        self::assertEqualsWithDelta(200.0 * 15 / 31 * 0.30, $sub['uncontracted_cost'], 0.01, 'Ablesepreis der Tage ohne Vertrag');
        $subKwh = 200.0 * 16 / 31;
        $totals = $this->consumption->forUtility('strom')['monthly_total'];
        self::assertEqualsWithDelta((500.0 - $subKwh) * 0.20 + $subKwh * 0.40, $this->row($totals, '2025-03')['cost'], 0.02);
        self::assertEqualsWithDelta(100.0, $this->row($totals, '2025-02')['cost'], 0.01);
    }

    /**
     * Elternzähler und Subzähler in derselben Gruppe mit einem Gruppenvertrag und Preis je Zähler
     * (20 und 40 ct): Der Elternzähler rechnet den Rest, der Subzähler seinen Teil, die Gruppe beides.
     * 300 × 20 ct + 200 × 40 ct = 140 € bei 500 kWh.
     */
    public function testParentAndSubmeterInOneGroupContract(): void
    {
        $this->monthlyReadings([$this->parent => 500.0, $this->sub => 200.0]);
        $gid = (string)$this->meters->createGroup('strom', ['name' => 'Haus'])['id'];
        foreach ([$this->parent, $this->sub] as $m) $this->meters->update('strom', $m, ['meter_group_id' => $gid]);
        $this->contracts->create('strom', ['meter_group_id' => $gid, 'provider' => 'A', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 20.0]],
            'working_prices_by_meter' => [$this->sub => [['from' => '2024-01-01', 'ct_per_kwh' => 40.0]]]]);
        $g = $this->row($this->consumption->forMeter('strom', $this->meters->groupTarget('strom', $gid)), '2024-06');
        self::assertEqualsWithDelta(500.0, $g['kwh'], 0.05);
        self::assertEqualsWithDelta(140.0, $g['cost'], 0.01);
        self::assertEqualsWithDelta(500.0, $g['billed_kwh'], 0.05, 'der Gruppenvertrag rechnet Rest und Subzähler');
        self::assertEqualsWithDelta(140.0, $this->row($this->consumption->forUtility('strom')['monthly_total'], '2024-06')['cost'], 0.01);
        $bd = $this->consumption->billBreakdown('strom', $this->meters->groupTarget('strom', $gid), '2024-06-01', '2024-07-01');
        self::assertEqualsWithDelta(140.0, $bd['totals']['total'], 0.05);
        self::assertEqualsWithDelta(500.0, $bd['totals']['kwh'], 0.1);
    }

    /**
     * Fortschreiben nach der letzten Ablesung des Subzählers höchstens 92 Tage
     * (REST_EXTRAPOLATE_DAYS): Subzähler zuletzt am 01.12.2024 abgelesen, Elternzähler
     * weiter monatlich. Januar und Februar 2025 ganz, März 2 Tage (bis 03.03.), April nichts.
     */
    public function testExtrapolationOfTheSubmeterIsCapped(): void
    {
        $this->monthlyReadings([$this->parent => 500.0, $this->sub => 200.0]);
        $all = array_values(array_filter($this->store->read('strom/readings.json', []),
            fn($r) => $r['meter_id'] !== $this->sub || $r['date'] <= '2024-12-01'));
        $this->store->write('strom/readings.json', $all);
        $this->contract($this->parent, 20.0);
        $this->contract($this->sub, 40.0);
        $rows = $this->consumption->forMeter('strom', $this->meters->get('strom', $this->parent));
        $rate = 200.0 / 30;   // letzte Tagesrate: November 2024
        self::assertEqualsWithDelta(500.0 - $rate * 31, $this->row($rows, '2025-01')['billed_kwh'], 0.1);
        self::assertEqualsWithDelta($rate * 31, $this->row($rows, '2025-01')['rest_of']['estimated_kwh'], 0.1);
        self::assertEqualsWithDelta(500.0 - $rate * 2, $this->row($rows, '2025-03')['billed_kwh'], 0.1);
        self::assertSame(29, $this->row($rows, '2025-03')['rest_of']['open_days']);
        self::assertSame(30, $this->row($rows, '2025-04')['rest_of']['open_days']);
        self::assertEqualsWithDelta(500.0, $this->row($rows, '2025-04')['billed_kwh'], 0.05);
    }

    /** Wasser bleibt, wie es ist: Dort zieht der Schmutzwasser-Abzug ab (v3.1.0), nicht der Subzähler. */
    public function testWaterIsNotRestBilled(): void
    {
        $wp = (string)$this->meters->list('wasser')[0]['id'];
        $garden = $this->meters->create('wasser', ['name' => 'Garten', 'installed_on' => '2024-01-01', 'parent_meter_id' => $wp]);
        self::assertFalse(ConsumptionService::restBillingApplies('wasser'));
        $this->contracts->create('wasser', ['meter_id' => (string)$garden['id'], 'provider' => 'W', 'start' => '2024-01-01',
            'trinkwasser' => ['working_prices' => [['from' => '2024-01-01', 'ct_per_m3' => 200.0]]]]);
        self::assertFalse($this->consumption->isDeductingSubmeter('wasser', $this->meters->get('wasser', (string)$garden['id'])));
        self::assertSame([], $this->consumption->deductingSubmeters('wasser', $this->meters->get('wasser', $wp)));
    }

    /** Endet der Vertrag des Subzählers (ohne Verlängerung), rechnet der Elternvertrag danach wieder alles. */
    public function testSubmeterContractEndingRestoresGrossBilling(): void
    {
        $this->monthlyReadings([$this->parent => 500.0, $this->sub => 200.0]);
        $this->contract($this->parent, 20.0);
        $this->contract($this->sub, 40.0, '2024-01-01', ['end' => '2024-12-31', 'auto_renews' => false]);
        $rows = $this->consumption->forMeter('strom', $this->meters->get('strom', $this->parent));
        self::assertEqualsWithDelta(60.0, $this->row($rows, '2024-12')['cost'], 0.01);
        self::assertEqualsWithDelta(100.0, $this->row($rows, '2025-01')['cost'], 0.01);
        self::assertArrayNotHasKey('rest_of', $this->row($rows, '2025-01'));
    }

    /**
     * Ablesungen an verschiedenen Tagen: Elternzähler am Ersten, Subzähler am 15. Die Tagesverteilung
     * gleicht an. Subzähler 6 kWh/Tag gleichmäßig → im Juni 2024 (30 Tage) 180 kWh; Rest 320 kWh.
     */
    public function testReadingsOnDifferentDaysAreAlignedByTheDailyDistribution(): void
    {
        $all = [];
        $pd = $this->meters->get('strom', $this->parent)['devices'][0]['id'];
        $sd = $this->meters->get('strom', $this->sub)['devices'][0]['id'];
        for ($i = 0; $i <= 12; $i++) {
            $date = date('Y-m-d', (int)strtotime("2024-01-01 +$i months"));
            $all[] = ['id' => "rp$i", 'meter_id' => $this->parent, 'device_id' => $pd, 'date' => $date, 'counter' => 500.0 * $i,
                      'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $t0 = strtotime('2024-01-15');
        for ($i = 0; $i <= 12; $i++) {
            $date = date('Y-m-d', (int)strtotime("2024-01-15 +$i months"));
            $days = (int)round((strtotime($date) - $t0) / 86400);
            $all[] = ['id' => "rs$i", 'meter_id' => $this->sub, 'device_id' => $sd, 'date' => $date, 'counter' => 6.0 * $days,
                      'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write('strom/readings.json', $all);
        $this->contract($this->parent, 20.0);
        $this->contract($this->sub, 40.0);
        $jun = $this->row($this->consumption->forMeter('strom', $this->meters->get('strom', $this->parent)), '2024-06');
        self::assertEqualsWithDelta(320.0, $jun['billed_kwh'], 0.05);
        self::assertEqualsWithDelta(64.0, $jun['cost'], 0.01);
        self::assertEqualsWithDelta(64.0 + 180 * 0.40, $this->row($this->consumption->forUtility('strom')['monthly_total'], '2024-06')['cost'], 0.02);
    }

    /**
     * Rest unter null (Subzähler im Mai 2025 600 statt 200 kWh, mehr als der Elternzähler): nicht
     * negativ abrechnen, sondern ausweisen. Elternvertrag 0 €, `short_kwh` 100; Subzähler 600 × 40 ct.
     */
    public function testNegativeRestIsNotBilledButReported(): void
    {
        $this->monthlyReadings([$this->parent => 500.0, $this->sub => 200.0], [$this->sub => ['2025-05-01' => 600.0]]);
        $this->contract($this->parent, 20.0);
        $this->contract($this->sub, 40.0);
        $may = $this->row($this->consumption->forMeter('strom', $this->meters->get('strom', $this->parent)), '2025-05');
        self::assertEqualsWithDelta(0.0, $may['cost'], 0.001);
        self::assertEqualsWithDelta(0.0, $may['billed_kwh'], 0.001);
        self::assertEqualsWithDelta(-100.0, $may['rest_of']['rest'], 0.05);
        self::assertEqualsWithDelta(100.0, $may['rest_of']['short_kwh'], 0.05);
        $total = $this->row($this->consumption->forUtility('strom')['monthly_total'], '2025-05');
        self::assertEqualsWithDelta(500.0, $total['kwh'], 0.05);
        self::assertEqualsWithDelta(240.0, $total['cost'], 0.01);
        // am Vertrag, laufende Periode 2025: 11 Monate × 300 kWh berechnet; Rest −100 im Mai ausgewiesen
        $row = $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->parent), '2025-12-31')['contracts'][0];
        self::assertEqualsWithDelta(3300.0, $row['actual_kwh'], 0.5);
        self::assertEqualsWithDelta(3200.0, $row['rest_of']['rest'], 0.5);
        self::assertEqualsWithDelta(100.0, $row['rest_of']['short_kwh'], 0.5);
    }

    /**
     * Am unordentlichen Testhaushalt (tests/fixtures/messy-household/: Strom einmal im Jahr
     * abgelesen, an wechselnden Tagen) mit einer Wärmepumpe dahinter, die unregelmäßig abgelesen
     * wird und erst ab 01.05.2024 einen eigenen Vertrag hat. Erwartung unabhängig von der App:
     * Zählerstände linear zwischen zwei Ablesungen, nach der letzten Ablesung die letzte Tagesrate
     * bis zur letzten Ablesung des Elternzählers (43 Tage, unter REST_EXTRAPOLATE_DAYS).
     */
    public function testMessyHouseholdWithIrregularSubmeterReadings(): void
    {
        $h = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/messy-household/household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings']);
        $this->store->write('temperatures.json', $h['temperatures']);
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        foreach ($h['utilities'] as $u => $pots) foreach ($pots as $pot => $rows) $this->store->write("$u/$pot.json", $rows);
        $parent = $this->meters->list('strom')[0];
        $sub = $this->meters->create('strom', ['name' => 'Wärmepumpe', 'installed_on' => '2024-03-17', 'initial_counter' => 100, 'parent_meter_id' => $parent['id']]);
        // 2,5 bis 3,3 kWh am Tag — der Elternzähler misst gut 6,7 kWh am Tag
        $subReadings = ['2024-03-17' => 100.0, '2024-07-02' => 400.0, '2024-12-20' => 950.0, '2025-06-05' => 1500.0, '2025-11-30' => 1950.0];
        $all = $this->store->read('strom/readings.json', []);
        foreach ($subReadings as $d => $c) {
            $all[] = ['id' => 'r_wp_' . $d, 'meter_id' => $sub['id'], 'device_id' => $sub['devices'][0]['id'], 'date' => $d, 'counter' => $c,
                      'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write('strom/readings.json', $all);
        $wp = $this->contract((string)$sub['id'], 25.0, '2024-05-01');

        $days = fn(string $a, string $b) => (int)(new \DateTime($a))->diff(new \DateTime($b))->days;
        $atStart = 100.0 + 300.0 * $days('2024-03-17', '2024-05-01') / $days('2024-03-17', '2024-07-02');
        $measuredSub = 1950.0 - $atStart;
        $extrapolated = 450.0 / $days('2025-06-05', '2025-11-30') * $days('2025-11-30', '2026-01-12');
        $parentMeasured = 29310.2 - 21877.0;

        // über die ganze Vertragszeit (der Vertragsstatus rechnet seit v3.9.0 je Abrechnungsperiode):
        // Vertragsmenge der Monate und der Hinweis rest_of seit Vertragsbeginn
        $rows = $this->consumption->forMeter('strom', $parent);
        $billed = array_sum(array_map(fn($r) => ConsumptionService::billedQuantity($r), $rows));
        self::assertEqualsWithDelta($parentMeasured - $measuredSub - $extrapolated, $billed, 0.5);
        $hint = $this->consumption->restBreakdown('strom', $parent, '2023-01-01', '9999-12-31');
        self::assertEqualsWithDelta($parentMeasured, $hint['measured'], 0.5);
        self::assertEqualsWithDelta($measuredSub + $extrapolated, $hint['submeters'][0]['kwh'], 0.5);
        self::assertEqualsWithDelta($extrapolated, $hint['estimated_kwh'], 0.5, 'fortgeschrieben, nicht still');
        // die Karte der Periode 2025 rechnet mit demselben Rest wie ihr Hinweis
        $card = $this->consumption->contractStatus('strom', $parent, '2025-12-31')['contracts'][0];
        self::assertEqualsWithDelta($card['rest_of']['rest'], $card['actual_kwh'], 0.5);
        $energy = array_sum(array_map(fn($r) => (float)($r['kwh_cost'] ?? 0), $rows));
        self::assertEqualsWithDelta(($parentMeasured - $measuredSub - $extrapolated) * 0.34, $energy, 0.5);
        // der Vertrag des Subzählers rechnet ab seinem Beginn (Monatsteile mit seiner ID)
        $onContract = 0.0;
        foreach ($this->consumption->forMeter('strom', $this->meters->get('strom', (string)$sub['id'])) as $r) {
            if (!empty($r['contract_parts'])) { foreach ($r['contract_parts'] as $p) if ($p['contract_id'] === $wp['id']) $onContract += (float)$p['kwh']; }
            elseif (($r['contract_id'] ?? null) === $wp['id']) $onContract += (float)$r['kwh'];
        }
        self::assertEqualsWithDelta($measuredSub, $onContract, 0.5);
    }

    /**
     * Gruppenverträge mit Preis je Zählwerk (HT/NT) auf beiden Seiten — die Kaskade aus #40:
     * Z1 HT 400 / NT 300 (Wärmepumpe 30 / 20 ct, 10 €), Z2 HT 150 / NT 100 hinter Z1 (Haushalt
     * 35 / 25 ct, 12 €). Wärmepumpe (400−150)×0,30 + (300−100)×0,20 + 10 = 125 €,
     * Haushalt 150×0,35 + 100×0,25 + 12 = 89,50 €; Summe 214,50 € bei 700 kWh.
     */
    /**
     * Nach dem Merge mit vertrag-aus-rechnung: Abschläge alle zwei Monate (`every_months`)
     * am Elternvertrag, Saldo an einem festen Stichtag. Die Rest-Abrechnung ändert Menge
     * und Kosten, nicht die Abschläge: gezahlt wie ohne Subzählervertrag, Rhythmus bleibt.
     */
    public function testRestBillingWithAdvanceRhythm(): void
    {
        $this->monthlyReadings([$this->parent => 500.0, $this->sub => 200.0]);
        $pc = $this->contract($this->parent, 20.0, '2024-01-01', ['advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 200.0, 'every_months' => 2]]]);
        $find = function () use ($pc): array {
            $st = $this->consumption->contractStatus('strom', $this->meters->get('strom', $this->parent), '2024-12-31');
            return array_values(array_filter($st['contracts'], fn($c) => $c['contract_id'] === $pc['id']))[0];
        };
        $gross = $find();
        $this->contract($this->sub, 40.0);
        $rest = $find();
        self::assertSame(2, $rest['current_advance_every_months'] ?? null, 'Rhythmus bleibt');
        self::assertGreaterThan(0.0, $gross['advance_paid']);
        self::assertEqualsWithDelta($gross['advance_paid'], $rest['advance_paid'], 0.01, 'Abschläge unabhängig vom Rest');
        self::assertEqualsWithDelta($gross['actual_kwh'] * 0.6, $rest['actual_kwh'], 0.5, '300 von 500 kWh je Monat');
        self::assertEqualsWithDelta($gross['actual_cost'] - ($gross['actual_kwh'] - $rest['actual_kwh']) * 0.20, $rest['actual_cost'], 0.05);
        self::assertEqualsWithDelta($rest['actual_kwh'], $rest['rest_of']['rest'], 0.5);
        // Saldo = Kosten − Abschläge: 2.400 € − 1.200 € = 1.200 € offen, mit Rest 1.440 € − 1.200 € = 240 €
        self::assertEqualsWithDelta(1200.0, $rest['advance_paid'], 0.01, 'sechs Abschläge zu 200 € im Jahr 2024');
        self::assertEqualsWithDelta($gross['current_balance'] - ($gross['actual_cost'] - $rest['actual_cost']), $rest['current_balance'], 0.05,
            'der Saldo um genau den Teil des Subzählers besser');
    }

    public function testGroupContractsWithPricePerRegister(): void
    {
        $this->store->write('strom/meters.json', []);
        $z1ht = (string)$this->meters->create('strom', ['name' => 'Z1 HT', 'installed_on' => '2024-01-01'])['id'];
        $z1nt = (string)$this->meters->create('strom', ['name' => 'Z1 NT', 'installed_on' => '2024-01-01'])['id'];
        $z2ht = (string)$this->meters->create('strom', ['name' => 'Z2 HT', 'installed_on' => '2024-01-01', 'parent_meter_id' => $z1ht])['id'];
        $z2nt = (string)$this->meters->create('strom', ['name' => 'Z2 NT', 'installed_on' => '2024-01-01', 'parent_meter_id' => $z1nt])['id'];
        $g1 = (string)$this->meters->createGroup('strom', ['name' => 'Z1'])['id'];
        $g2 = (string)$this->meters->createGroup('strom', ['name' => 'Z2'])['id'];
        foreach ([$z1ht, $z1nt] as $m) $this->meters->update('strom', $m, ['meter_group_id' => $g1]);
        foreach ([$z2ht, $z2nt] as $m) $this->meters->update('strom', $m, ['meter_group_id' => $g2]);
        $this->monthlyReadings([$z1ht => 400.0, $z1nt => 300.0, $z2ht => 150.0, $z2nt => 100.0]);
        $wp = $this->contracts->create('strom', ['meter_group_id' => $g1, 'provider' => 'A', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 30.0]],
            'working_prices_by_meter' => [$z1nt => [['from' => '2024-01-01', 'ct_per_kwh' => 20.0]]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 10.0]]]);
        $hh = $this->contracts->create('strom', ['meter_group_id' => $g2, 'provider' => 'B', 'start' => '2024-01-01',
            'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 35.0]],
            'working_prices_by_meter' => [$z2nt => [['from' => '2024-01-01', 'ct_per_kwh' => 25.0]]],
            'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 12.0]]]);

        $grp1 = $this->meters->groupTarget('strom', $g1);
        $r1 = $this->row($this->consumption->forMeter('strom', $grp1), '2024-06');
        self::assertEqualsWithDelta(700.0, $r1['kwh'], 0.05);
        self::assertEqualsWithDelta(450.0, $r1['billed_kwh'], 0.05);
        self::assertEqualsWithDelta(125.0, $r1['cost'], 0.01);
        self::assertEqualsWithDelta(450.0, $r1['rest_of']['rest'], 0.05);
        $r2 = $this->row($this->consumption->forMeter('strom', $this->meters->groupTarget('strom', $g2)), '2024-06');
        self::assertEqualsWithDelta(89.5, $r2['cost'], 0.01);
        // Vertragsstatus in der laufenden Abrechnungsperiode 2025 (zwölf Monate)
        $s1 = $this->consumption->contractStatus('strom', $grp1, '2025-12-31')['contracts'][0];
        self::assertSame($wp['id'], $s1['contract_id']);
        self::assertEqualsWithDelta(450.0 * 12, $s1['actual_kwh'], 1.0);
        self::assertEqualsWithDelta(125.0 * 12, $s1['actual_cost'], 0.5);
        self::assertEqualsWithDelta(250.0 * 12, $s1['rest_of']['submeters'][0]['kwh'] + $s1['rest_of']['submeters'][1]['kwh'], 1.0);
        $s2 = $this->consumption->contractStatus('strom', $this->meters->groupTarget('strom', $g2), '2025-12-31')['contracts'][0];
        self::assertSame($hh['id'], $s2['contract_id']);
        self::assertEqualsWithDelta(250.0 * 12, $s2['actual_kwh'], 1.0);
        $total = $this->row($this->consumption->forUtility('strom')['monthly_total'], '2024-06');
        self::assertEqualsWithDelta(700.0, $total['kwh'], 0.05);
        self::assertEqualsWithDelta(214.5, $total['cost'], 0.01);
    }
}
