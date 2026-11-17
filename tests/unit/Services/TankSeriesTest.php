<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\DeliveryConsumptionService;
use Energietracker\Services\DeliveryService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 — Das Tankbuch je Anfrage einmal, und jede Ansicht nimmt nur die Tage, die sie braucht.
 *
 * Bis v3.12 hielt jede Anfrage das Tankbuch als eine Zeile je Tag (bei 75 Jahren rund 18 MB je Tank und Stichtag),
 * las die Temperaturen für jeden Tank neu, und Übersicht, Empfehlungen und Jahresbericht bauten für den heutigen
 * Bestand die Tageszeilen aller Jahre. Mit Heizöl und Pellets über 75 Jahre reichten 128 MB für Übersicht, Agenda
 * und Jahresbericht nicht mehr (Skeptiker-Lauf zu v3.12.0). Die Werte bleiben dieselben: alt gegen neu byte-gleich an
 * allen Beispielhaushalten, am unordentlichen Testhaushalt und an einem Haushalt mit langem Tank.
 *
 * Erfundener Tank: Heizöl, 3.000 L, ab 01.01.2020 mit 1.500 L; am 1. Juli jedes Jahres „bis voll“, je Quartal ein
 * Peilstand. Stichtag 30.06.2025.
 */
#[CoversClass(DeliveryConsumptionService::class)]
#[CoversClass(DeliveryService::class)]
final class TankSeriesTest extends ServiceTestCase
{
    private const TODAY = '2025-06-30';

    private DeliveryService $deliveries;
    private string $tankId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $temps = [];
        for ($t = strtotime('2020-01-01 12:00'); $t <= strtotime(self::TODAY . ' 12:00'); $t += 86400) {
            $avg = round(9.0 - 10.0 * cos(2 * M_PI * ((int)date('z', $t) - 15) / 365.25), 1);
            $temps[date('Y-m-d', $t)] = ['avg' => $avg, 'min' => $avg - 3, 'max' => $avg + 3, 'source' => 'csv'];
        }
        $this->store->write('temperatures.json', $temps);
        $levels = [];
        foreach (range(2020, 2025) as $y) foreach (['01-15', '04-15', '10-15'] as $md) {
            if ("$y-$md" < self::TODAY && "$y-$md" > '2020-01-01') $levels[] = ['date' => "$y-$md", 'level' => $md === '01-15' ? 1800.0 : ($md === '04-15' ? 1100.0 : 2600.0)];
        }
        $tank = $this->meters->create('heizoel', ['name' => 'Tank', 'capacity' => 3000.0, 'initial_stock' => 1500.0, 'installed_on' => '2020-01-01']);
        $this->tankId = (string)$tank['id'];
        $m = $this->meter();
        $m['tank_levels'] = $levels;
        $all = $this->store->read('heizoel/meters.json', []);
        foreach ($all as &$row) if (($row['id'] ?? '') === $this->tankId) $row = $m;
        unset($row);
        $this->store->write('heizoel/meters.json', $all);
        foreach (range(2020, 2024) as $y) {
            $this->deliveries->create('heizoel', ['meter_id' => $this->tankId, 'date' => "$y-07-01", 'quantity' => 1700.0,
                'unit_price_cents' => 80.0 + 5 * ($y - 2020), 'fill_to_full' => true]);
        }
        $this->settings->set(['active_utilities' => ['heizoel']]);
    }

    private function meter(): array
    {
        return $this->meters->get('heizoel', $this->tankId);
    }

    /** @return array<string,mixed> der Zwischenspeicher des Tankbuchs (privat; hier zählt, wie oft gerechnet wird) */
    private function memo(): array
    {
        return (new \ReflectionProperty($this->deliveryConsumption, 'memo'))->getValue($this->deliveryConsumption);
    }

    /** Monatszeilen, Verlauf und Bestand am selben Stichtag: ein Tankbuch, einmal gerechnet. */
    public function testOneRequestComputesTheTankBookOncePerStichtag(): void
    {
        $this->consumption->forMeter('heizoel', $this->meter(), null, self::TODAY);
        $this->deliveries->stockHistory('heizoel', $this->tankId, $this->consumption, self::TODAY);
        $this->deliveries->stockLatest('heizoel', $this->tankId, $this->consumption, self::TODAY);
        $this->consumption->tankModel('heizoel', $this->meter(), self::TODAY);
        self::assertCount(1, $this->memo(), 'ein Tank, ein Stichtag: ein Tankbuch');
        // gemerkt in Spalten, nicht als Zeile je Tag
        $s = array_values($this->memo())[0];
        self::assertArrayNotHasKey('days', $s);
        self::assertCount(2008, $s['dates'], '01.01.2020 bis 30.06.2025');
        self::assertTrue(array_is_list($s['draw']) && array_is_list($s['stock']) && array_is_list($s['avg']));
        // ein anderer Stichtag ist ein eigenes Tankbuch
        $this->deliveries->stockLatest('heizoel', $this->tankId, $this->consumption, '2024-12-31');
        self::assertCount(2, $this->memo());
    }

    /** Der Bestand für Übersicht und Empfehlungen ist der letzte Tag des Verlaufs, ohne die Tage davor. */
    public function testTheLatestStockIsTheLastDayOfTheHistoryAlone(): void
    {
        $full = $this->deliveries->stockHistory('heizoel', $this->tankId, $this->consumption, self::TODAY);
        $latest = $this->deliveries->stockLatest('heizoel', $this->tankId, $this->consumption, self::TODAY);
        self::assertCount(1, $latest['days']);
        self::assertSame(end($full['days']), $latest['days'][0]);
        self::assertSame(self::TODAY, $latest['days'][0]['date']);
        unset($full['days'], $latest['days']);
        self::assertSame($full, $latest, 'Kapazität, Stützstellen, Schätzbeginn und Hinweise wie im Verlauf');
    }

    /** Ein Zeitraum (einschließlich) liefert genau die Tage des Verlaufs darin; das Tankbuch rechnet trotzdem ab dem Start. */
    public function testAWindowIsTheSliceOfTheFullHistory(): void
    {
        $full = $this->deliveries->stockHistory('heizoel', $this->tankId, $this->consumption, self::TODAY);
        $win = $this->deliveries->stockHistory('heizoel', $this->tankId, $this->consumption, self::TODAY, '2024-01-01', '2024-12-31');
        $want = array_values(array_filter($full['days'], fn($d) => $d['date'] >= '2024-01-01' && $d['date'] <= '2024-12-31'));
        self::assertCount(366, $win['days'], '2024 ist ein Schaltjahr');
        self::assertSame($want, $win['days']);
        // der Bestand am 01.01.2024 hängt an allen Tagen davor (Lieferungen, Peilstände, Verbrauch)
        self::assertSame($want[0]['stock'], $win['days'][0]['stock']);
    }

    /** Die Zeilen des Tankbuchs sind seine Spalten: dieselben Werte, dieselbe Reihenfolge. */
    public function testTheRowsAreTheColumns(): void
    {
        $model = $this->consumption->tankModel('heizoel', $this->meter(), self::TODAY);
        $s = $this->consumption->tankSeries('heizoel', $this->meter(), self::TODAY);
        self::assertSame($s['dates'], array_keys($model['days']));
        $i = array_search('2023-07-01', $s['dates'], true);
        self::assertSame(DeliveryConsumptionService::dayRow($s, $i), $model['days']['2023-07-01']);
        self::assertSame(1700.0, $model['days']['2023-07-01']['delivery']);
        self::assertEqualsWithDelta(3000.0 - $model['days']['2023-07-01']['draw'], $model['days']['2023-07-01']['stock'], 0.01, 'bis voll, dann der Tagesverbrauch');
        self::assertFalse($model['days']['2025-04-14']['estimated']);
        self::assertTrue($model['days']['2025-04-15']['estimated'], 'ab dem letzten Peilstand geschätzt');
    }

    /**
     * Ein Tag kostet im Tankbuch rund 130 Byte. Bis v3.12 lag jeder Tag mit seinem Datum als Text bei über 250 Byte
     * (DateTimeImmutable::format() hält unter PHP 8.5 einen Vorrat), dazu eine Zeile je Tag: 75 Jahre waren 18 MB.
     */
    public function testATankBookDayStaysSmall(): void
    {
        $temps = $this->deliveryConsumption->temperatures();
        $meter = $this->meter();
        // erst ein anderer Stichtag: Klimanormal und Dienste sind dann geladen, gemessen wird nur das Tankbuch
        $this->deliveryConsumption->tankSeries('heizoel', $meter, '2025-06-29', $temps);
        gc_collect_cycles();
        $before = memory_get_usage();
        $this->deliveryConsumption->tankSeries('heizoel', $meter, self::TODAY, $temps);
        gc_collect_cycles();
        $perDay = (memory_get_usage() - $before) / 2008;
        self::assertLessThan(200, $perDay, sprintf('%.0f Byte je Tag (gemessen 128; mit format() direkt rund 350)', $perDay));
    }
}
