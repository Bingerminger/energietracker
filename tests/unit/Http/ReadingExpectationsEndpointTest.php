<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\App;
use Energietracker\Http\Request;
use Energietracker\Services\ReadingCheckService;
use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.13.0 (Paket K1, #65) — `POST /api/utility/{u}/meters/{id}/reading-expectations` mit `{pairs: [{from, to}, …]}`:
 * die Erwartung nach dem Wetter für viele Abstände in einer Anfrage, für die Vorschau eines CSV-Imports (eine Anfrage
 * für die ganze Datei statt einer je Zeile). Dieselben Werte wie `GET …/reading-expectation` je Abstand. Haushalt wie
 * ReadingExpectationEndpointTest; alle Tage fest und in der Vergangenheit.
 */
final class ReadingExpectationsEndpointTest extends HttpServerTestCase
{
    private const TEMP = [4 => 9.0, 5 => 14.0, 6 => 17.0, 7 => 19.0, 8 => 18.0, 9 => 14.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];

    public function testThePreviewGetsAllIntervalsInOneRequest(): void
    {
        $this->startServer(['language' => 'de', 'active_utilities' => ['gas'], 'hdd_base_temp' => 15.0,
            'gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.5]]]);
        $mid = (string)$this->json('POST', '/api/utility/gas/meters', ['name' => 'Gas', 'installed_on' => '2025-04-01'])['id'];
        $dev = (string)(array_column($this->json('GET', '/api/utility/gas/meters'), null, 'id')[$mid]['devices'][0]['id'] ?? '');
        $temps = []; $rows = []; $c = 1000.0;
        for ($t = strtotime('2025-04-05 12:00'); date('Y-m-d', $t) <= '2025-12-31'; $t += 86400) {
            $d = date('Y-m-d', $t);
            $a = self::TEMP[(int)date('n', $t)];
            $temps[$d] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
            if ($d <= '2025-11-01' && (int)((($t - strtotime('2025-04-05 12:00')) / 86400)) % 7 === 0) {
                $rows[] = ['id' => 'r_' . $d, 'meter_id' => $mid, 'device_id' => $dev, 'date' => $d, 'counter' => round($c, 3),
                           'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
            $c += 0.4;
        }
        file_put_contents($this->dataDir . '/temperatures.json', json_encode($temps));
        file_put_contents($this->dataDir . '/gas/readings.json', json_encode($rows));

        $url = "/api/utility/gas/meters/$mid/reading-expectations";
        $pairs = [['from' => '2025-11-01', 'to' => '2025-11-08'], ['from' => '2025-08-02', 'to' => '2025-08-09'], ['from' => '2025-11-08', 'to' => '2025-11-15']];
        $r = $this->json('POST', $url, ['pairs' => $pairs]);
        self::assertSame(['expectations'], array_keys($r));
        self::assertCount(3, $r['expectations']);
        foreach ($pairs as $i => $p) {
            $single = $this->json('GET', "/api/utility/gas/meters/$mid/reading-expectation?from={$p['from']}&to={$p['to']}");
            self::assertSame($single, $r['expectations'][$i], "{$p['from']} → {$p['to']}: wie die Einzelabfrage");
        }
        self::assertSame(['degree_days', 'colder_than_history'], [$r['expectations'][0]['basis'], $r['expectations'][0]['reason']]);
        self::assertSame('heat_model', $r['expectations'][1]['basis']);
        self::assertSame(['expectations' => []], $this->json('POST', $url, ['pairs' => []]), 'leer ist erlaubt');

        // ungültig: kein Objekt, keine Liste, ein Abstand ohne Datum oder rückwärts, zu viele Abstände
        $over = array_fill(0, ReadingCheckService::MAX_PAIRS + 1, ['from' => '2025-11-01', 'to' => '2025-11-08']);
        $post = fn(string $path, mixed $body) => $this->request('POST', $path, ['Content-Type: application/json'], (string)json_encode($body));
        foreach ([[], ['pairs' => 'x'], ['pairs' => [['from' => '01.11.2025', 'to' => '2025-11-08']]],
                  ['pairs' => [['from' => '2025-11-08', 'to' => '2025-11-01']]], ['pairs' => [['from' => '2025-11-01']]], ['pairs' => ['x']],
                  ['pairs' => $over]] as $bad) {
            $res = $post($url, $bad);
            self::assertSame(400, $res['status'], substr((string)json_encode($bad), 0, 120));
        }
        $tooMany = json_decode($post($url, ['pairs' => $over])['body'], true);
        self::assertSame('errors.readingCheck.tooMany', $tooMany['code'] ?? null);
        // die Arbeit hängt an den Tagen (Gradtage je Tag): zusammen höchstens MAX_PAIR_DAYS, zwei Jahrhunderte sind zu viel
        $long = $post($url, ['pairs' => [['from' => '1900-01-01', 'to' => '2000-01-01'], ['from' => '2000-01-01', 'to' => '2099-12-31']]]);
        self::assertSame(400, $long['status']);
        self::assertSame('errors.readingCheck.tooManyDays', json_decode($long['body'], true)['code'] ?? null);
        $ok = $post($url, ['pairs' => [['from' => '2000-01-01', 'to' => '2099-12-31']]]);
        self::assertSame(200, $ok['status'], 'ein Jahrhundert geht noch: ' . substr($ok['body'], 0, 200));
        self::assertSame(404, $post('/api/utility/gas/meters/m_nope/reading-expectations', ['pairs' => $pairs])['status']);
    }

    /**
     * v3.13.0 — Stände, die die Datei mit einem anderen Wert überschreibt (`exclude`), bestimmen die Erwartung nicht mit:
     * dieselbe Antwort, als stünden sie nicht im Zähler. Wie der bearbeitete Stand in der Einzelabfrage (`exclude=`) und
     * der Stand am selben Tag beim Vorschlag einer KI. Ein zweiter Zähler mit denselben Ständen ohne die ersetzten dient
     * als Maßstab. Kurze Vorgeschichte (Oktober 1 m³ am Tag, November 3 m³, wöchentlich), also der Weg über die
     * Gradtage: Der alte Stand vom 15.11. lag 3 m³ zu hoch (erfunden, eine Schätzung) und war einer von nur zwei
     * Abständen mit so viel Heizwetter. Von Hand: mit ihm Median aus 21 und 24 m³ je Woche = 22,5 m³ erwartet, ohne ihn
     * bleibt ein Vergleichsabstand, die Höhe ist offen (`colder_than_history`). Dazu ein zweiter alter Stand im Oktober.
     */
    public function testReplacedReadingsDoNotShapeTheExpectation(): void
    {
        $this->startServer(['language' => 'de', 'active_utilities' => ['gas'], 'hdd_base_temp' => 15.0,
            'gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.5]]]);
        $meter = function (string $name): array {
            $id = (string)$this->json('POST', '/api/utility/gas/meters', ['name' => $name, 'installed_on' => '2025-04-01'])['id'];
            return [$id, (string)(array_column($this->json('GET', '/api/utility/gas/meters'), null, 'id')[$id]['devices'][0]['id'] ?? '')];
        };
        [$mid, $dev] = $meter('Gas');
        [$ref, $refDev] = $meter('Gas ohne die ersetzten Stände');
        $shift = ['2025-11-15' => 3.0, '2025-10-18' => 2.0];   // so viel lag der alte Stand zu hoch
        $temps = []; $rows = [];
        $c = 1000.0;
        for ($t = strtotime('2025-09-01 12:00'); date('Y-m-d', $t) <= '2025-12-31'; $t += 86400) {
            $d = date('Y-m-d', $t);
            $a = self::TEMP[(int)date('n', $t)];
            $temps[$d] = ['avg' => $a, 'min' => $a - 3, 'max' => $a + 3];
            if ($d >= '2025-10-04' && $d <= '2025-11-15' && (int)((($t - strtotime('2025-10-04 12:00')) / 86400)) % 7 === 0) {
                $rows[] = ['date' => $d, 'counter' => round($c + ($shift[$d] ?? 0.0), 3)];
            }
            if ($d >= '2025-10-04') $c += $d >= '2025-11-01' ? 3.0 : 1.0;
        }
        $outliers = $shift;
        file_put_contents($this->dataDir . '/temperatures.json', json_encode($temps));
        $stored = [];
        foreach ($rows as $r) {
            $stored[] = ['id' => 'r_' . $r['date'], 'meter_id' => $mid, 'device_id' => $dev, 'date' => $r['date'], 'counter' => $r['counter'],
                         'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            if (!isset($outliers[$r['date']])) {
                $stored[] = ['id' => 'q_' . $r['date'], 'meter_id' => $ref, 'device_id' => $refDev, 'date' => $r['date'], 'counter' => $r['counter'],
                             'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
            }
        }
        file_put_contents($this->dataDir . '/gas/readings.json', json_encode($stored));

        $pairs = [['from' => '2025-11-08', 'to' => '2025-11-15'], ['from' => '2025-11-01', 'to' => '2025-11-08'], ['from' => '2025-10-11', 'to' => '2025-10-18']];
        $with = $this->json('POST', "/api/utility/gas/meters/$mid/reading-expectations", ['pairs' => $pairs, 'exclude' => ['r_2025-11-15', 'r_2025-10-18']]);
        $clean = $this->json('POST', "/api/utility/gas/meters/$ref/reading-expectations", ['pairs' => $pairs]);
        $without = $this->json('POST', "/api/utility/gas/meters/$mid/reading-expectations", ['pairs' => $pairs]);
        self::assertSame($clean, $with, 'ohne die ersetzten Stände gerechnet');
        // von Hand (Docblock): mit dem alten Stand 22,5 m³ erwartet, ohne ihn ist die Höhe offen
        self::assertSame(['degree_days', 22.5], [$without['expectations'][0]['basis'], $without['expectations'][0]['expected']], json_encode($without['expectations'][0]));
        self::assertSame(['degree_days', 'colder_than_history', null], [$with['expectations'][0]['basis'], $with['expectations'][0]['reason'], $with['expectations'][0]['high']], json_encode($with['expectations'][0]));
        // ein ersetzter Stand allein: dieselbe Antwort wie die Einzelabfrage mit exclude
        $one = $this->json('POST', "/api/utility/gas/meters/$mid/reading-expectations", ['pairs' => [$pairs[0]], 'exclude' => ['r_2025-11-15']]);
        $single = $this->json('GET', "/api/utility/gas/meters/$mid/reading-expectation?from=2025-11-08&to=2025-11-15&exclude=r_2025-11-15");
        self::assertSame($single, $one['expectations'][0]);
        // unbekannte Kennungen stören nicht; ungültig ist nur, was keine Liste von Kennungen ist
        self::assertSame($without, $this->json('POST', "/api/utility/gas/meters/$mid/reading-expectations", ['pairs' => $pairs, 'exclude' => ['r_nope']]));
        $post = fn(mixed $body) => $this->request('POST', "/api/utility/gas/meters/$mid/reading-expectations", ['Content-Type: application/json'], (string)json_encode($body));
        foreach ([['pairs' => $pairs, 'exclude' => 'r_2025-07-05'], ['pairs' => $pairs, 'exclude' => [5]], ['pairs' => $pairs, 'exclude' => ['']],
                  ['pairs' => $pairs, 'exclude' => array_fill(0, ReadingCheckService::MAX_PAIRS + 1, 'r_x')]] as $bad) {
            self::assertSame(400, $post($bad)['status'], substr((string)json_encode($bad), 0, 80));
        }
    }

    /** Die Abfrage schreibt nichts: auch mit einem Schlüssel erlaubt, der nur lesen darf, und ohne Schreibsperre. */
    public function testTheBatchIsAReadOnlyPost(): void
    {
        $r = new \ReflectionClass(Request::class);
        $req = $r->newInstanceWithoutConstructor();
        foreach (['method' => 'POST', 'path' => '/api/utility/gas/meters/m_x/reading-expectations'] as $k => $v) $r->getProperty($k)->setValue($req, $v);
        self::assertTrue(App::readOnlyPost($req));
        self::assertTrue(App::locksLate($req));
    }
}
