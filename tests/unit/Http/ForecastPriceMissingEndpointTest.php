<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.13.0 — `GET …/meters/{id}/forecast` über HTTP: Prognosemonate eines Vertrags ohne Arbeitspreis tragen additiv
 * `price_missing: true` (`cost_estimated` ist dann nur Grundpreis); mit Arbeitspreis fehlt das Feld. Werte erfunden.
 */
final class ForecastPriceMissingEndpointTest extends HttpServerTestCase
{
    public function testForecastMonthsWithoutWorkingPrice(): void
    {
        $this->startServer();
        $m = $this->json('POST', '/api/utility/strom/meters', ['name' => 'Werkstatt']);
        // 230 kWh je Monat über 14 Monate bis zum Ersten dieses Monats (Tage relativ zu heute, kein fester Kalender)
        $start = date('Y-m-01', strtotime(date('Y-m-01') . ' -14 months'));
        for ($i = 0; $i <= 14; $i++) {
            $this->json('POST', '/api/utility/strom/readings', ['meter_id' => $m['id'],
                'date' => date('Y-m-d', strtotime("$start +$i months")), 'counter' => 1000 + 230 * $i]);
        }
        $c = $this->json('POST', '/api/utility/strom/contracts', ['meter_id' => $m['id'], 'provider' => 'Werk Musterstadt', 'start' => $start,
            'base_prices' => [['from' => $start, 'eur_per_month' => 8.5]]]);
        self::assertIsString($c['id'] ?? null, json_encode($c));

        $path = '/api/utility/strom/meters/' . $m['id'] . '/forecast?forecast_months=6';
        $f = $this->json('GET', $path);
        self::assertTrue($f['valid'] ?? false, json_encode($f));
        self::assertCount(6, $f['forecast']);
        self::assertSame([true], array_values(array_unique(array_map(fn($r) => $r['price_missing'] ?? false, $f['forecast']))));

        // mit Arbeitspreis (31 ct ab dem ersten Tag): das Feld fehlt
        $this->json('PATCH', '/api/utility/strom/contracts/' . $c['id'], ['working_prices' => [['from' => $start, 'ct_per_kwh' => 31]]]);
        $priced = $this->json('GET', $path)['forecast'];
        self::assertSame([], array_values(array_filter($priced, fn($r) => array_key_exists('price_missing', $r))));
    }
}
