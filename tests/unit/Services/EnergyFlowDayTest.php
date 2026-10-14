<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\EnergyFlowDayService;
use Energietracker\Services\IntervalService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.5.0 (F1027) — Tagesfilm: Energiefluss je Viertelstunde, von Hand
 * nachgerechnet. Haushalt erfunden (Zeitzone Europe/Berlin, Jahr 2025).
 */
#[CoversClass(EnergyFlowDayService::class)]
final class EnergyFlowDayTest extends ServiceTestCase
{
    private IntervalService $iv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iv = new IntervalService($this->store, $this->settings);
        // ohne den Standardzähler der Erstinstallation: jeder Test legt seine Zähler an
        $this->store->write('strom/meters.json', []);
    }

    private function svc(): EnergyFlowDayService
    {
        return new EnergyFlowDayService($this->iv, $this->meters);
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Berlin')))->getTimestamp();
    }

    private function meter(string $utility, string $name, array $extra = []): string
    {
        return (string)$this->meters->create($utility, ['name' => $name, 'installed_on' => '2024-01-01'] + $extra)['id'];
    }

    /**
     * Werte eines Tages: jede Viertelstunde `$fill`, dazu einzelne Uhrzeiten.
     *
     * @param array<string,float|null> $at  'HH:MM' → kWh (null = Lücke)
     * @return array<int,float>
     */
    private function dayValues(string $date, float $fill, array $at = []): array
    {
        $out = [];
        for ($t = $this->at("$date 00:00"); $t < $this->at("$date 00:00 +1 day"); $t += 900) $out[$t] = $fill;
        foreach ($at as $hhmm => $v) {
            $ts = $this->at("$date $hhmm");
            if ($v === null) unset($out[$ts]); else $out[$ts] = $v;
        }
        return $out;
    }

    /** Haushalt mit Netz, Einspeisung, Erzeugung und Speicher. @return array<string,string> */
    private function pvHousehold(): array
    {
        return [
            'grid' => $this->meter('strom', 'Haus'),
            'feed' => $this->meter('pv_einspeisung', 'Einspeisung'),
            'gen' => $this->meter('pv_erzeugung', 'Wechselrichter'),
            'charged' => $this->meter('pv_erzeugung', 'Speicher laden', ['role' => 'battery_charge']),
            'discharged' => $this->meter('pv_erzeugung', 'Speicher entladen', ['role' => 'battery_discharge']),
        ];
    }

    private const UTIL = ['grid' => 'strom', 'feed' => 'pv_einspeisung', 'gen' => 'pv_erzeugung', 'charged' => 'pv_erzeugung', 'discharged' => 'pv_erzeugung'];

    /** @param array<string,array<int,float>> $values Art → Werte */
    private function feed(array $ids, array $values): void
    {
        foreach ($values as $kind => $v) $this->iv->mergeEnergy(self::UTIL[$kind], $ids[$kind], $v, 'test');
    }

    private function slot(array $r, string $t, bool $repeat = false): array
    {
        foreach ($r['slots'] as $s) if ($s['t'] === $t && (!empty($s['repeat']) === $repeat)) return $s;
        self::fail("Viertelstunde $t fehlt");
    }

    /**
     * Mittag: 2,0 kWh Erzeugung, 0,8 eingespeist, 0,5 in den Speicher, 0,1
     * Bezug → Sonne→Haus 0,7, Haus 0,8, Netzstrom im Haus 0,1.
     * Abend: 0,3 Bezug, 0,4 aus dem Speicher → Haus 0,7, Netzstrom 0,3.
     * Tag: Haus 1,5, Netzstrom 0,4 → Autarkie 1 − 0,4/1,5.
     */
    public function testOneDayWithPvBatteryAndPrice(): void
    {
        $ids = $this->pvHousehold();
        $d = '2025-06-14';
        $this->feed($ids, [
            'gen' => $this->dayValues($d, 0.0, ['12:00' => 2.0]),
            'feed' => $this->dayValues($d, 0.0, ['12:00' => 0.8]),
            'grid' => $this->dayValues($d, 0.0, ['12:00' => 0.1, '20:00' => 0.3]),
            'charged' => $this->dayValues($d, 0.0, ['12:00' => 0.5]),
            'discharged' => $this->dayValues($d, 0.0, ['20:00' => 0.4]),
        ]);
        $prices = $this->dayValues($d, 10.0, ['12:00' => -1.5]);
        $this->iv->mergePrices($prices, 'test');

        $r = $this->svc()->day($d);
        self::assertSame('day', $r['mode']);
        self::assertCount(96, $r['slots']);
        self::assertSame(['00:00', '23:45'], [$r['slots'][0]['t'], $r['slots'][95]['t']]);
        self::assertSame(['slots' => 96, 'complete' => 96, 'price' => 96], $r['completeness']);
        self::assertSame(['gen' => true, 'feed' => true, 'battery' => true, 'price' => true], $r['kinds']);
        self::assertSame([], $r['missing']);

        $noon = $this->slot($r, '12:00');
        self::assertSame([2.0, 0.8, 0.1, 0.5, 0.0], [$noon['gen'], $noon['feed'], $noon['grid'], $noon['charged'], $noon['discharged']]);
        self::assertEqualsWithDelta(0.7, $noon['flow']['pv_house'], 1e-9);
        self::assertEqualsWithDelta(0.5, $noon['flow']['pv_battery'], 1e-9);
        self::assertEqualsWithDelta(0.8, $noon['house'], 1e-9);
        self::assertEqualsWithDelta(0.1, $noon['flow']['grid_in_house'], 1e-9);
        self::assertSame(-1.5, $noon['price']);
        $eve = $this->slot($r, '20:00');
        self::assertEqualsWithDelta(0.7, $eve['house'], 1e-9);
        self::assertEqualsWithDelta(0.4, $eve['flow']['battery_house'], 1e-9);
        self::assertSame(10.0, $eve['price']);

        $tot = $r['totals'];
        self::assertEqualsWithDelta(1.5, $tot['house'], 1e-9);
        self::assertEqualsWithDelta(0.4, $tot['grid'], 1e-9);
        self::assertEqualsWithDelta(0.4, $tot['flow']['grid_in_house'], 1e-9);
        self::assertEqualsWithDelta(round(1 - 0.4 / 1.5, 4), $tot['autarky'], 1e-9);
    }

    /** Viertelstundenwerte unter 0,1 kWh bleiben erhalten (Rundung auf 4 Stellen, nicht auf 1). */
    public function testSmallQuarterHourValuesAreNotRoundedAway(): void
    {
        $ids = $this->pvHousehold();
        $d = '2025-06-14';
        $this->feed($ids, [
            'gen' => $this->dayValues($d, 0.0, ['09:00' => 0.06]),
            'feed' => $this->dayValues($d, 0.0),
            'grid' => $this->dayValues($d, 0.04),
            'charged' => $this->dayValues($d, 0.0),
            'discharged' => $this->dayValues($d, 0.0),
        ]);
        $s = $this->slot($this->svc()->day($d), '09:00');
        self::assertEqualsWithDelta(0.06, $s['flow']['pv_house'], 1e-9);
        self::assertEqualsWithDelta(0.1, $s['house'], 1e-9);
        self::assertEqualsWithDelta(0.04, $s['flow']['grid_house'], 1e-9);
    }

    /** Zeitumstellung März: 92 Viertelstunden, 2:00–2:45 gibt es nicht. */
    public function testSpringForwardDayHas92QuarterHours(): void
    {
        $g = $this->meter('strom', 'Haus');
        $d = '2025-03-30';
        $this->iv->mergeEnergy('strom', $g, $this->dayValues($d, 0.1, ['03:00' => 0.9]), 'test');
        $r = $this->svc()->day($d);
        $times = array_column($r['slots'], 't');
        self::assertCount(92, $times);
        self::assertNotContains('02:00', $times);
        $i = array_search('01:45', $times, true);
        self::assertSame('03:00', $times[$i + 1]);
        self::assertSame(0.9, $r['slots'][$i + 1]['grid'], 'der Wert um 3:00 liegt auf 3:00');
        self::assertSame(92, $r['completeness']['complete']);
    }

    /** Zeitumstellung Oktober: 100 Viertelstunden, 2:00–2:45 zweimal, das zweite Mal markiert. */
    public function testFallBackDayHas100QuarterHoursAndMarksTheRepeat(): void
    {
        $g = $this->meter('strom', 'Haus');
        $d = '2025-10-26';
        $v = $this->dayValues($d, 0.1);
        $v[(new \DateTimeImmutable('2025-10-26 00:00:00 UTC'))->getTimestamp()] = 1.0;   // 2:00 Sommerzeit
        $v[(new \DateTimeImmutable('2025-10-26 01:00:00 UTC'))->getTimestamp()] = 2.0;   // 2:00 Winterzeit
        $this->iv->mergeEnergy('strom', $g, $v, 'test');
        $r = $this->svc()->day($d);
        $times = array_column($r['slots'], 't');
        self::assertCount(100, $times);
        self::assertSame(2, count(array_keys($times, '02:00', true)));
        self::assertSame(1.0, $this->slot($r, '02:00')['grid']);
        self::assertSame(2.0, $this->slot($r, '02:00', true)['grid']);
        self::assertSame(4, count(array_filter($r['slots'], fn($s) => !empty($s['repeat']))));
        self::assertSame('23:45', end($times));
    }

    /** Subzähler (F1006) zählen nicht zum Netzbezug — und ohne Reihe sind sie keine Lücke. */
    public function testSubMetersAreExcluded(): void
    {
        $main = $this->meter('strom', 'Haus');
        $sub = $this->meter('strom', 'Wallbox', ['parent_meter_id' => $main, 'role' => 'ev_charger']);
        $sub2 = $this->meter('strom', 'Sauna', ['parent_meter_id' => $main]);   // ohne Reihe
        $d = '2025-06-14';
        $this->iv->mergeEnergy('strom', $main, $this->dayValues($d, 0.5), 'test');
        $this->iv->mergeEnergy('strom', $sub, $this->dayValues($d, 0.2), 'test');
        $r = $this->svc()->day($d);
        self::assertSame(0.5, $r['slots'][40]['grid']);
        self::assertEqualsWithDelta(48.0, $r['totals']['grid'], 1e-9);
        self::assertSame(96, $r['completeness']['complete']);
        self::assertSame([], $r['missing']);
        self::assertNotEmpty($sub2);
    }

    /** Lücke: null statt 0, kein Fluss, nicht in den Summen; ein Zähler ohne Reihe wird genannt. */
    public function testGapsAreNullAndNamed(): void
    {
        $g = $this->meter('strom', 'Haus');
        $d = '2025-06-14';
        $this->iv->mergeEnergy('strom', $g, $this->dayValues($d, 0.5, ['10:00' => null, '10:15' => null]), 'test');
        $r = $this->svc()->day($d);
        $gap = $this->slot($r, '10:00');
        self::assertNull($gap['grid']);
        self::assertNull($gap['flow']);
        self::assertNull($gap['house']);
        self::assertSame(94, $r['completeness']['complete']);
        self::assertEqualsWithDelta(47.0, $r['totals']['grid'], 1e-9);

        // zweiter Hauptzähler ohne Viertelstunden: jede Viertelstunde fehlt, er wird genannt
        $wp = $this->meter('strom', 'Wärmepumpe', ['role' => 'heat_pump']);
        $r = $this->svc()->day($d);
        self::assertSame(0, $r['completeness']['complete']);
        self::assertNull($this->slot($r, '12:00')['grid']);
        self::assertSame([['kind' => 'grid', 'utility' => 'strom', 'meter_id' => $wp, 'name' => 'Wärmepumpe']], $r['missing']);
    }

    /** Zähler außer Betrieb oder erst später eingebaut müssen nichts liefern. */
    public function testMetersNotInServiceOnTheDayAreNotRequired(): void
    {
        $g = $this->meter('strom', 'Haus');
        $old = $this->meter('strom', 'Alt');
        $this->meters->update('strom', $old, ['active' => false]);
        $this->meters->create('strom', ['name' => 'Neu', 'installed_on' => '2025-09-01']);
        $d = '2025-06-14';
        $this->iv->mergeEnergy('strom', $g, $this->dayValues($d, 0.5), 'test');
        $r = $this->svc()->day($d);
        self::assertSame(96, $r['completeness']['complete']);
        self::assertSame([], $r['missing']);
        // am 1.9. ist der neue Zähler eingebaut und fehlt
        $this->iv->mergeEnergy('strom', $g, $this->dayValues('2025-09-01', 0.5), 'test');
        self::assertSame(0, $this->svc()->day('2025-09-01')['completeness']['complete']);
    }

    /** Ohne PV: Erzeugung und Einspeisung 0, das Haus ist der Bezug, Autarkie 0. */
    public function testWithoutPv(): void
    {
        $g = $this->meter('strom', 'Haus');
        $d = '2025-01-14';
        $this->iv->mergeEnergy('strom', $g, $this->dayValues($d, 0.25), 'test');
        $r = $this->svc()->day($d);
        $s = $r['slots'][50];
        self::assertSame([0.0, 0.0, 0.25], [$s['gen'], $s['feed'], $s['grid']]);
        self::assertSame(0.25, $s['house']);
        self::assertSame(['gen' => false, 'feed' => false, 'battery' => false, 'price' => false], $r['kinds']);
        self::assertEqualsWithDelta(24.0, $r['totals']['house'], 1e-9);
        self::assertSame(0.0, $r['totals']['autarky']);
    }

    /** Erzeugung ohne Einspeisezähler: die Aufteilung fehlt — Lücke, kein angenommenes 0. */
    public function testGenerationWithoutFeedInMeterIsAGap(): void
    {
        $g = $this->meter('strom', 'Haus');
        $gen = $this->meter('pv_erzeugung', 'Balkon');
        $d = '2025-06-14';
        $this->iv->mergeEnergy('strom', $g, $this->dayValues($d, 0.2), 'test');
        $this->iv->mergeEnergy('pv_erzeugung', $gen, $this->dayValues($d, 0.1), 'test');
        $r = $this->svc()->day($d);
        self::assertSame(0, $r['completeness']['complete']);
        self::assertNull($r['slots'][48]['feed']);
        self::assertSame(0.2, $r['slots'][48]['grid'], 'gemessene Arten bleiben sichtbar');
        self::assertContains(['kind' => 'feed', 'utility' => 'pv_einspeisung', 'meter_id' => null, 'name' => null], $r['missing']);
    }

    /** Speicher lädt im Winter mehr, als die Sonne übrig hat: der Rest kam aus dem Netz. */
    public function testBatteryChargedFromTheGrid(): void
    {
        $ids = $this->pvHousehold();
        $d = '2025-01-14';
        $this->feed($ids, [
            'gen' => $this->dayValues($d, 0.0, ['03:00' => 0.0]),
            'feed' => $this->dayValues($d, 0.0),
            'grid' => $this->dayValues($d, 0.0, ['03:00' => 1.2]),
            'charged' => $this->dayValues($d, 0.0, ['03:00' => 1.0]),
            'discharged' => $this->dayValues($d, 0.0, ['18:00' => 0.9]),
        ]);
        $r = $this->svc()->day($d);
        $s = $this->slot($r, '03:00');
        self::assertEqualsWithDelta(1.0, $s['flow']['grid_battery'], 1e-9);
        self::assertEqualsWithDelta(0.2, $s['flow']['grid_house'], 1e-9);
        // Abends entlädt der Speicher: in der Viertelstunde selbst kein Bezug …
        self::assertEqualsWithDelta(0.0, $this->slot($r, '18:00')['flow']['grid_in_house'], 1e-9);
        // … über den Tag bleibt der nachts geladene Netzstrom Netzstrom: Autarkie 0
        self::assertEqualsWithDelta(0.0, $r['totals']['autarky'], 1e-9);
        self::assertEqualsWithDelta(1.1, $r['totals']['house'], 1e-9);
        self::assertEqualsWithDelta(1.1, $r['totals']['flow']['grid_in_house'], 1e-9);
    }

    /** Kein Börsenpreis: price null, die Flüsse rechnen trotzdem. */
    public function testMissingPriceIsNull(): void
    {
        $g = $this->meter('strom', 'Haus');
        $d = '2025-06-14';
        $this->iv->mergeEnergy('strom', $g, $this->dayValues($d, 0.5), 'test');
        $this->iv->mergePrices([$this->at("$d 12:00") => 7.5], 'test');
        $r = $this->svc()->day($d);
        self::assertSame(1, $r['completeness']['price']);
        self::assertSame(7.5, $this->slot($r, '12:00')['price']);
        self::assertNull($this->slot($r, '12:15')['price']);
        self::assertNotNull($this->slot($r, '12:15')['flow']);
    }

    /** Typischer Tag: Mittel je Uhrzeit über die Tage mit Daten, n nennt die Zahl. */
    public function testTypicalDayAveragesPerLocalTime(): void
    {
        $g = $this->meter('strom', 'Haus');
        foreach (['2025-06-02' => 1.0, '2025-06-03' => 2.0, '2025-06-04' => 6.0] as $d => $noon) {
            $this->iv->mergeEnergy('strom', $g, $this->dayValues($d, 0.1, ['12:00' => $noon]), 'test');
        }
        $this->iv->mergePrices([$this->at('2025-06-02 12:00') => 4.0, $this->at('2025-06-20 12:00') => 8.0], 'test');
        $r = $this->svc()->typicalDay('2025-06');
        self::assertSame('typical', $r['mode']);
        self::assertSame(3, $r['days']);
        self::assertCount(96, $r['slots']);
        $noon = $this->slot($r, '12:00');
        self::assertEqualsWithDelta(3.0, $noon['grid'], 1e-9);
        self::assertSame(3, $noon['n']);
        self::assertEqualsWithDelta(6.0, $noon['price'], 1e-9, 'Preis über alle Viertelstunden mit Preis');
        self::assertEqualsWithDelta(0.1, $this->slot($r, '18:00')['grid'], 1e-9);
        // Summe = typischer Tag: 95 × 0,1 + 3,0
        self::assertEqualsWithDelta(12.5, $r['totals']['grid'], 1e-6);
    }

    /** Typischer Tag im Oktober: der Umstellungstag trägt 2:00 zweimal bei, im März gar nicht. */
    public function testTypicalDayAcrossClockChanges(): void
    {
        $g = $this->meter('strom', 'Haus');
        $this->iv->mergeEnergy('strom', $g, $this->dayValues('2025-10-25', 0.1, ['02:00' => 1.0]), 'test');
        $v = $this->dayValues('2025-10-26', 0.1);
        $v[(new \DateTimeImmutable('2025-10-26 00:00:00 UTC'))->getTimestamp()] = 2.0;
        $v[(new \DateTimeImmutable('2025-10-26 01:00:00 UTC'))->getTimestamp()] = 4.0;
        $this->iv->mergeEnergy('strom', $g, $v, 'test');
        $oct = $this->slot($this->svc()->typicalDay('2025-10'), '02:00');
        self::assertSame(3, $oct['n']);
        self::assertEqualsWithDelta((1.0 + 2.0 + 4.0) / 3, $oct['grid'], 1e-4);

        $this->iv->mergeEnergy('strom', $g, $this->dayValues('2025-03-29', 0.1, ['02:00' => 3.0]), 'test');
        $this->iv->mergeEnergy('strom', $g, $this->dayValues('2025-03-30', 0.1), 'test');
        $mar = $this->svc()->typicalDay('2025-03');
        self::assertSame(1, $this->slot($mar, '02:00')['n']);
        self::assertEqualsWithDelta(3.0, $this->slot($mar, '02:00')['grid'], 1e-9);
        self::assertSame(2, $this->slot($mar, '03:00')['n']);
        self::assertSame(2, $mar['days']);
    }

    /** Ohne vollständige Viertelstunde: Lücke je Uhrzeit, n = 0. */
    public function testTypicalDayGapsWithoutData(): void
    {
        $g = $this->meter('strom', 'Haus');
        $this->iv->mergeEnergy('strom', $g, [$this->at('2025-06-02 12:00') => 1.0], 'test');
        $r = $this->svc()->typicalDay('2025-06');
        self::assertSame(1, $r['completeness']['complete']);
        $s = $this->slot($r, '13:00');
        self::assertSame(0, $s['n']);
        self::assertNull($s['grid']);
        self::assertNull($s['flow']);
    }

    /** Tage: nur mit Netzbezug, vollständig nur ohne Lücke, Vorbelegung letzter vollständiger Tag. */
    public function testDaysListAndDefaults(): void
    {
        $ids = $this->pvHousehold();
        foreach (['2025-05-31', '2025-06-10', '2025-06-11', '2025-06-12'] as $d) {
            $this->feed($ids, [
                'gen' => $this->dayValues($d, 0.0, $d === '2025-06-12' ? ['12:00' => null] : []),
                'feed' => $this->dayValues($d, 0.0), 'grid' => $this->dayValues($d, 0.3),
                'charged' => $this->dayValues($d, 0.0), 'discharged' => $this->dayValues($d, 0.0),
            ]);
        }
        // Erzeugung an einem Tag ohne Netzbezug: kein Tagesfilm
        $this->iv->mergeEnergy('pv_erzeugung', $ids['gen'], $this->dayValues('2025-07-01', 0.4), 'test');
        $r = $this->svc()->days('2025-10-01');
        self::assertTrue($r['available']);
        self::assertSame(['2025-05-31', '2025-06-10', '2025-06-11', '2025-06-12'], $r['days']);
        self::assertSame(['2025-05-31', '2025-06-10', '2025-06-11'], $r['complete_days']);
        self::assertSame(['2025-05', '2025-06'], $r['months']);
        self::assertSame('2025-06-11', $r['default_day']);
        self::assertSame('2025-06', $r['default_month']);
        // heute und später nicht (der Tag läuft noch)
        self::assertSame(['2025-05-31', '2025-06-10'], $this->svc()->days('2025-06-11')['days']);
    }

    /** Ohne Viertelstunden des Netzbezugs kein Tagesfilm — auch wenn die PV welche hat. */
    public function testNoFilmWithoutGridQuarterHours(): void
    {
        $this->meter('strom', 'Haus');
        $gen = $this->meter('pv_erzeugung', 'Wechselrichter');
        $this->iv->mergeEnergy('pv_erzeugung', $gen, $this->dayValues('2025-06-14', 0.4), 'test');
        $r = $this->svc()->days('2025-10-01');
        self::assertFalse($r['available']);
        self::assertSame([], $r['days']);
        self::assertNull($r['default_day']);
        self::assertNull($r['default_month']);
    }

    /** Abfrage: nur Kalendertage bzw. Monate, Jahr 2000–2100 (sonst 400 im Controller). */
    public function testQueryValidation(): void
    {
        foreach (['2025-06-14', '2024-02-29', '2000-01-01', '2100-12-31'] as $ok) self::assertTrue(EnergyFlowDayService::validDate($ok), $ok);
        foreach (['2025-02-29', '2025-13-01', '1999-12-31', '2101-01-01', '2025-6-14', '14.06.2025', '', '<b>'] as $bad) {
            self::assertFalse(EnergyFlowDayService::validDate($bad), $bad);
        }
        foreach (['2025-06', '2000-01', '2100-12'] as $ok) self::assertTrue(EnergyFlowDayService::validMonth($ok), $ok);
        foreach (['2025-13', '2025-00', '2025-6', '1999-12', '2101-01', '2025-06-01', ''] as $bad) {
            self::assertFalse(EnergyFlowDayService::validMonth($bad), $bad);
        }
    }

    /** Eine Rechnung: der Fluss einer Viertelstunde ist PvSummaryService::flows(). */
    public function testSlotFlowIsTheSharedFormula(): void
    {
        $ids = $this->pvHousehold();
        $d = '2025-06-14';
        $this->feed($ids, [
            'gen' => $this->dayValues($d, 0.9), 'feed' => $this->dayValues($d, 0.2), 'grid' => $this->dayValues($d, 0.05),
            'charged' => $this->dayValues($d, 0.3), 'discharged' => $this->dayValues($d, 0.1),
        ]);
        $s = $this->svc()->day($d)['slots'][30];
        self::assertSame(PvSummaryService::flows(0.9, 0.2, 0.05, 0.3, 0.1, 4), $s['flow']);
    }
}
