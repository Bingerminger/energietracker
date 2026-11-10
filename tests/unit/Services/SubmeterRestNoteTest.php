<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\SubmeterRest;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Rest-Abrechnung am Elternzähler, zwei Fehler im Hinweis „Abgerechnet wird hier nur der Rest“:
 *
 * (a) Misst der Subzähler in einem Abschnitt mehr als der Elternzähler, rechnet der Vertrag dort 0 (kein Rest unter
 *     null). Die Summe im Hinweis (`rest_of.rest`) zählte den negativen Rest aber mit; der Hinweis nannte eine kleinere
 *     Menge als die abgerechnete. Neu: `rest_of.billed` = Summe der Reste je Abschnitt, nie unter null.
 * (b) Endet der Prüfzeitraum einen Tag nach dem gemeinsamen Ablesetag, schrieb die App für den Subzähler einen Tag
 *     fort, für den Elternzähler nicht: falsches „Rest unter null“. Neu: abgezogen wird nur bis zum letzten Stand
 *     des Elternzählers.
 *
 * Erfundener Haushalt, Strom, Stände am Monatsersten, Elternvertrag 30 ct, Wallbox mit eigenem Vertrag 20 ct.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(SubmeterRest::class)]
final class SubmeterRestNoteTest extends ServiceTestCase
{
    private string $parent = '';
    private string $sub = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->store->write('strom/meters.json', []);
        $this->store->write('strom/contracts.json', []);
        $this->parent = (string)$this->meters->create('strom', ['name' => 'Hausanschluss', 'installed_on' => '2026-01-01'])['id'];
        $this->sub = (string)$this->meters->create('strom', ['name' => 'Wallbox', 'installed_on' => '2026-01-01', 'parent_meter_id' => $this->parent])['id'];
        foreach ([[$this->parent, 30.0], [$this->sub, 20.0]] as [$id, $ct]) {
            $this->contracts->create('strom', ['meter_id' => $id, 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
                'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => $ct]]]);
        }
    }

    /** @param array<string,float> $parent Stand je Tag  @param array<string,float> $sub */
    private function readings(array $parent, array $sub): void
    {
        $all = [];
        foreach ([$this->parent => $parent, $this->sub => $sub] as $id => $list) {
            $dev = $this->meters->get('strom', $id)['devices'][0]['id'];
            foreach ($list as $date => $counter) {
                $all[] = ['id' => "r_{$id}_$date", 'meter_id' => $id, 'device_id' => $dev, 'date' => $date, 'counter' => $counter,
                          'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
        }
        $this->store->write('strom/readings.json', $all);
    }

    /** @return array<string,mixed> */
    private function check(string $from, string $to): array
    {
        return $this->consumption->billBreakdown('strom', $this->meters->get('strom', $this->parent), $from, $to)['totals'];
    }

    /**
     * Von Hand: Juni 360 − 120 = 240, Juli 150 − 400 = −250 → 0 (Rest unter null 250), August 372 − 124 = 248.
     *   gemessen 882, Wallbox 644, Rest mit Vorzeichen 238; abgerechnet 240 + 0 + 248 = 488 = 238 + 250
     */
    public function testTheNoteCarriesTheBilledQuantity(): void
    {
        $this->readings(['2026-06-01' => 1000, '2026-07-01' => 1360, '2026-08-01' => 1510, '2026-09-01' => 1882],
                        ['2026-06-01' => 0, '2026-07-01' => 120, '2026-08-01' => 520, '2026-09-01' => 644]);
        $t = $this->check('2026-06-01', '2026-09-01');
        self::assertSame(488.0, $t['kwh'], 'von Hand: 240 + 0 + 248');
        self::assertSame(882.0, $t['rest_of']['measured']);
        self::assertSame(238.0, $t['rest_of']['rest'], 'gemessen − Wallbox, wie bisher');
        self::assertSame(250.0, $t['rest_of']['short_kwh']);
        self::assertSame(488.0, $t['rest_of']['billed'], 'die Menge, die der Vertrag rechnet');
        self::assertSame($t['kwh'], $t['rest_of']['billed'], 'Hinweis und Summe: eine Zahl');
        // Doku-Beispiel (Zählertopologie → Rest unter null) mit genau diesen Zahlen der App
        $f = fn(float $v): string => number_format($v, 0, ',', '.');
        $root = dirname(__DIR__, 3);
        $de = sprintf('%s kWh = %s kWh gemessen − %s kWh Wallbox + %s kWh', $f($t['rest_of']['billed']), $f($t['rest_of']['measured']),
            $f($t['rest_of']['submeters'][0]['kwh']), $f($t['rest_of']['short_kwh']));
        self::assertStringContainsString($de, (string)file_get_contents("$root/docs/verstehen/13-meter-topologie.md"));
        $en = sprintf('%s kWh =%s%s kWh measured − %s kWh wallbox + %s kWh', $f($t['rest_of']['billed']), "\n  ", $f($t['rest_of']['measured']),
            $f($t['rest_of']['submeters'][0]['kwh']), $f($t['rest_of']['short_kwh']));
        self::assertStringContainsString($en, (string)file_get_contents("$root/docs/en/verstehen/13-meter-topologie.md"));
    }

    public function testMergingKeepsEachSectionAtLeastZero(): void
    {
        $a = SubmeterRest::restOf(150.0, ['s' => 400.0], ['s' => 'Wallbox'], 0.0, 0, 250.0);
        $b = SubmeterRest::restOf(372.0, ['s' => 124.0], ['s' => 'Wallbox']);
        self::assertSame(0.0, $a['billed']);
        self::assertSame(248.0, $b['billed']);
        $m = SubmeterRest::mergeRestOf($a, $b);
        self::assertSame(248.0, $m['billed'], 'von Hand: 0 + 248, nicht 150 − 400 + 248 = −2');
        self::assertSame(-2.0, $m['rest']);
        $reg = SubmeterRest::mergeRestOf(SubmeterRest::asRegister($a, ['id' => 'ht', 'name' => 'HT']), SubmeterRest::asRegister($b, ['id' => 'ht', 'name' => 'HT']));
        self::assertSame(248.0, $reg['registers'][0]['billed'], 'je Zählwerk ebenso');
    }

    /**
     * Von Hand: Oktober 31 Tage, Elternzähler 12 kWh am Tag = 372, Wallbox 4 kWh am Tag = 124, Rest 248. Beide am
     * 01.11. abgelesen; der Tag danach hat keinen Stand, für keinen der beiden.
     */
    public function testNoShortfallWhenBothEndOnTheSameReadingDay(): void
    {
        $this->readings(['2026-09-01' => 1000, '2026-10-01' => 1360, '2026-11-01' => 1732],
                        ['2026-09-01' => 0, '2026-10-01' => 120, '2026-11-01' => 244]);
        $a = $this->check('2026-10-01', '2026-11-01');
        $b = $this->check('2026-10-01', '2026-11-02');
        self::assertSame(248.0, $a['kwh']);
        self::assertSame(248.0, $b['kwh']);
        self::assertSame(124.0, $b['rest_of']['submeters'][0]['kwh'], 'Wallbox nur bis zum gemeinsamen Ablesetag');
        self::assertArrayNotHasKey('short_kwh', $b['rest_of'], 'kein Rest unter null');
        self::assertArrayNotHasKey('estimated_kwh', $b['rest_of']);
        self::assertSame($a['rest_of'], $b['rest_of'], 'ein Tag ohne Stand ändert den Hinweis nicht');
    }
}
