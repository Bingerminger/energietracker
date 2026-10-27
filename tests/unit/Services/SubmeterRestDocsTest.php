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
    private static function exampleNumbers(string $rel): array
    {
        $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
        $pos = strpos($text, '<!-- doc-claim: rest-billing-example');
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
}
