<?php
declare(strict_types=1);

namespace Energietracker\Tests\Http;

use Energietracker\Tests\Support\HttpServerTestCase;

/**
 * v3.4.0 (N1021) — `phase` steht additiv an `GET …/contracts`,
 * `GET …/contracts/{id}` und `GET …/meters/{id}/contract-status`, aus
 * derselben Quelle. Die beiden Vertragslisten der Oberfläche lesen den Status
 * nur noch daraus.
 */
final class ContractPhaseEndpointTest extends HttpServerTestCase
{
    public function testAllThreeAnswersCarryTheSamePhase(): void
    {
        $this->startServer();
        $meters = $this->json('GET', '/api/utility/strom/meters');
        $meterId = $meters[0]['id'] ?? $this->json('POST', '/api/utility/strom/meters', ['name' => 'Haushalt'])['id'];
        $y = (int)date('Y');
        $make = fn(array $c) => $this->json('POST', '/api/utility/strom/contracts', $c + [
            'meter_id' => $meterId, 'provider' => 'Versorger',
            'working_prices' => [['from' => $c['start'], 'ct_per_kwh' => 30]],
        ])['id'];
        $past    = $make(['start' => ($y - 3) . '-01-01', 'end' => ($y - 3) . '-12-31']);
        $renewed = $make(['start' => ($y - 2) . '-01-01', 'end' => ($y - 1) . '-12-31']);
        $future  = $make(['start' => ($y + 1) . '-01-01']);
        $shadow  = $make(['start' => ($y + 1) . '-01-01', 'is_shadow' => true]);

        $list = array_column($this->json('GET', '/api/utility/strom/contracts'), null, 'id');
        self::assertSame(['past', 'renewed', 'future', 'shadow'],
            [$list[$past]['phase'], $list[$renewed]['phase'], $list[$future]['phase'], $list[$shadow]['phase']]);
        self::assertSame(30.0, (float)$list[$renewed]['values_on']['working_price_ct']);
        self::assertSame(($y - 1) . '-12-31', $list[$renewed]['values_on']['date']);
        self::assertSame('renewed', $this->json('GET', "/api/utility/strom/contracts/$renewed")['phase'] ?? null);
        self::assertSame('renewed', array_column($this->json('GET', "/api/utility/strom/contracts?meter_id=$meterId"), 'phase', 'id')[$renewed] ?? null);

        $status = array_column($this->json('GET', "/api/utility/strom/meters/$meterId/contract-status")['contracts'] ?? [], null, 'contract_id');
        self::assertSame(['past', 'renewed', 'future'],
            [$status[$past]['phase'] ?? null, $status[$renewed]['phase'] ?? null, $status[$future]['phase'] ?? null]);
        self::assertArrayNotHasKey($shadow, $status, 'Angebote stehen nicht im Saldo');
    }
}
