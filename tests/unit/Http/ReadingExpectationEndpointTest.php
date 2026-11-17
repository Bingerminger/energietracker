<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.13.0 (#65) — `GET /api/utility/{u}/meters/{id}/reading-expectation?from=&to=[&exclude=]`: die
 * Erwartung nach dem Wetter für die Rückfrage beim Eintragen, über HTTP. Erfundener Gaszähler, von
 * Hand wöchentlich abgelesen, Sommer bis Ende Oktober ohne Heizung (0,4 m³ am Tag bei 10,5 kWh je m³),
 * Temperaturen je Kalendermonat fest. Alle Tage fest und in der Vergangenheit.
 */
final class ReadingExpectationEndpointTest extends HttpServerTestCase
{
    private const TEMP = [4 => 9.0, 5 => 14.0, 6 => 17.0, 7 => 19.0, 8 => 18.0, 9 => 14.0, 10 => 9.0, 11 => 4.0, 12 => 1.0];

    public function testTheEntryGetsTheExpectationOfTheInterval(): void
    {
        $this->startServer(['language' => 'de', 'active_utilities' => ['gas'], 'hdd_base_temp' => 15.0,
            'gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.5]]]);
        $mid = (string)$this->json('POST', '/api/utility/gas/meters', ['name' => 'Gas', 'installed_on' => '2025-04-01'])['id'];
        $dev = (string)(array_column($this->json('GET', '/api/utility/gas/meters'), null, 'id')[$mid]['devices'][0]['id'] ?? '');
        self::assertNotSame('', $dev);
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

        $q = fn(string $from, string $to, string $extra = '') => "/api/utility/gas/meters/$mid/reading-expectation?from=$from&to=$to$extra";
        // erste Heizwoche: kälter als alles bisher, die Höhe ist offen (nur „Komma vergessen“)
        $e = $this->json('GET', $q('2025-11-01', '2025-11-08'));
        self::assertSame(['basis', 'reason', 'from', 'to', 'days', 'expected', 'spread', 'high', 'unit', 'hdd_per_day'], array_keys($e));
        self::assertSame(['degree_days', 'colder_than_history', 7, null, 'm³'], [$e['basis'], $e['reason'], $e['days'], $e['high'], $e['unit']]);
        self::assertEqualsWithDelta(11.0, $e['hdd_per_day'], 1e-9);
        // eine Sommerwoche: das Heizmodell aus Sommer und Herbst kennt so warmes Wetter, 7 × 0,4 m³
        $s = $this->json('GET', $q('2025-08-02', '2025-08-09', '&exclude=r_2025-08-09'));
        self::assertSame('heat_model', $s['basis']);
        self::assertEqualsWithDelta(2.8, $s['expected'], 0.05);
        self::assertGreaterThanOrEqual(3 * $s['expected'] - 0.01, $s['high']);

        foreach ([$q('2025-11-08', '2025-11-01'), $q('2025-11-01', '2025-11-01'), $q('01.11.2025', '2025-11-08'), "/api/utility/gas/meters/$mid/reading-expectation"] as $bad) {
            self::assertSame(400, $this->request('GET', $bad)['status'], $bad);
        }
        self::assertSame(404, $this->request('GET', '/api/utility/gas/meters/m_nope/reading-expectation?from=2025-11-01&to=2025-11-08')['status']);
    }
}
