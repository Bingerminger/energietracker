<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (F1032, GitHub #40) — Die Doku-Behauptung zur Rest-Abrechnung hängt an
 * einem Test (Lektion „Doku-Behauptungen brauchen Tests“: genau diese Zusage
 * stand seit v3.1.0 ohne Test in 13-meter-topologie.md und war drei Releases
 * lang falsch).
 *
 * Das Beispiel nach `<!-- doc-claim: rest-billing-example -->` (DE und EN)
 * nennt Mengen, Preise und Beträge. Der Test rechnet dasselbe mit der App und
 * verlangt, dass die Zahlen im Text genau die gerechneten sind.
 */
#[CoversClass(ConsumptionService::class)]
final class SubmeterRestDocsTest extends ServiceTestCase
{
    private const DOCS = ['docs/verstehen/13-meter-topologie.md', 'docs/en/verstehen/13-meter-topologie.md'];

    /** @return list<int> die ganzen Zahlen des Beispielblocks in Lesereihenfolge */
    private static function exampleNumbers(string $rel, string $claim = 'rest-billing-example'): array
    {
        $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
        $pos = strpos($text, '<!-- doc-claim: ' . $claim);
        self::assertNotFalse($pos, "$rel: Beispiel fehlt");
        if (!preg_match('/```text\n(.*?)```/s', substr($text, (int)$pos), $m)) self::fail("$rel: Codeblock fehlt");
        preg_match_all('/\d+/', $m[1], $n);
        return array_map('intval', $n[0]);
    }

    public function testTheDocumentedExampleIsWhatTheAppCalculates(): void
    {
        $this->store->write('strom/meters.json', []);
        $this->store->write('strom/contracts.json', []);
        $p = (string)$this->meters->create('strom', ['name' => 'P', 'installed_on' => '2025-01-01'])['id'];
        $s = (string)$this->meters->create('strom', ['name' => 'S', 'installed_on' => '2025-01-01', 'parent_meter_id' => $p])['id'];
        [$pKwh, $pCt, $sKwh, $sCt] = [500, 20, 200, 40];
        $all = [];
        foreach ([$p => $pKwh, $s => $sKwh] as $id => $per) {
            $dev = $this->meters->get('strom', $id)['devices'][0]['id'];
            foreach (['2025-03-01' => 0, '2025-04-01' => 1] as $d => $i) {
                $all[] = ['id' => "r_{$id}_$i", 'meter_id' => $id, 'device_id' => $dev, 'date' => $d, 'counter' => 100.0 + $per * $i,
                          'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
        }
        $this->store->write('strom/readings.json', $all);
        foreach ([[$p, $pCt], [$s, $sCt]] as [$m, $ct]) {
            $this->contracts->create('strom', ['meter_id' => $m, 'provider' => 'X', 'start' => '2025-01-01',
                'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => (float)$ct]]]);
        }
        $parent = $this->consumption->forMeter('strom', $this->meters->get('strom', $p))[0];
        $sub = $this->consumption->forMeter('strom', $this->meters->get('strom', $s))[0];
        $total = $this->consumption->forUtility('strom')['monthly_total'][0];
        $calc = [
            $pKwh, $pCt, $sKwh, $sCt,
            (int)round($parent['billed_kwh']), (int)round($parent['rest_of']['measured']), (int)round($parent['rest_of']['submeters'][0]['kwh']), $pCt, (int)round($parent['cost']),
            (int)round($sub['kwh']), $sCt, (int)round($sub['cost']),
            (int)round($total['kwh']), (int)round($total['cost']),
        ];
        self::assertSame([500, 20, 200, 40, 300, 500, 200, 20, 60, 200, 40, 80, 500, 140], $calc, 'die App rechnet das Beispiel');
        foreach (self::DOCS as $rel) self::assertSame($calc, self::exampleNumbers($rel), "$rel: das Beispiel stimmt nicht mit der Rechnung überein");
    }

    /**
     * v3.9.0 (doku) — Anwendungsfall A (WG mit geteilten Zählern) sagte seit v1.8.0, der
     * Subzähler werde vom Hausanschluss „abgezogen“, und nannte den Hausanschluss „netto,
     * ohne Server“. Gerechnet hat die App das nie: Ohne eigenen Vertrag am Subzähler zeigt
     * der Elternzähler seine Messung, die Summe zählt nur ihn, und sein Vertrag rechnet
     * alles. Das Beispiel nach `<!-- doc-claim: shared-meters-example -->` hält die Zahlen.
     */
    public function testUseCaseANamesWhatTheAppShowsWithoutASubmeterContract(): void
    {
        $this->store->write('strom/meters.json', []);
        $this->store->write('strom/contracts.json', []);
        $p = (string)$this->meters->create('strom', ['name' => 'Hausanschluss WG', 'installed_on' => '2025-01-01'])['id'];
        $s = (string)$this->meters->create('strom', ['name' => 'Arbeitszimmer (Server)', 'installed_on' => '2025-01-01', 'parent_meter_id' => $p])['id'];
        $all = [];
        foreach ([$p => 612, $s => 188] as $id => $per) {
            $dev = $this->meters->get('strom', $id)['devices'][0]['id'];
            foreach (['2025-03-01' => 0, '2025-04-01' => 1] as $d => $i) {
                $all[] = ['id' => "r_{$id}_$i", 'meter_id' => $id, 'device_id' => $dev, 'date' => $d, 'counter' => 100.0 + $per * $i,
                          'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
        }
        $this->store->write('strom/readings.json', $all);
        $this->contracts->create('strom', ['meter_id' => $p, 'provider' => 'X', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]]]);
        $parent = $this->consumption->forMeter('strom', $this->meters->get('strom', $p))[0];
        $sub = $this->consumption->forMeter('strom', $this->meters->get('strom', $s))[0];
        $total = $this->consumption->forUtility('strom')['monthly_total'][0];
        $calc = [(int)round($parent['kwh']), (int)round($sub['kwh']), (int)round($total['kwh'])];
        self::assertSame([612, 188, 612], $calc, 'die App rechnet das Beispiel');
        self::assertArrayNotHasKey('rest_of', $parent, 'ohne Vertrag am Subzähler keine Rest-Abrechnung');
        self::assertEqualsWithDelta(612 * 0.30, (float)$parent['cost'], 0.01, 'der WG-Vertrag rechnet alles, was der Hausanschluss misst');
        foreach (['docs/anleitungen/anwendungsfaelle.md', 'docs/en/anleitungen/anwendungsfaelle.md'] as $rel) {
            self::assertSame($calc, self::exampleNumbers($rel, 'shared-meters-example'), "$rel: das Beispiel stimmt nicht mit der Rechnung überein");
            $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            self::assertStringContainsString('612 − 188 = ' . ($calc[0] - $calc[1]), $text, "$rel: Rest der WG");
        }
    }
}
