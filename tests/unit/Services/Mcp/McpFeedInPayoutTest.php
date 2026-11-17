<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\FeedInPayoutService;

/**
 * v3.13.0 (Paket vertrag) — Auszahlungen der Einspeisung in der KI (lesend): Mit erfassten Auszahlungen nennt der
 * Stand der Einspeisung in `get_overview` erhalten (`entered`) und offen; ohne Auszahlung bleibt die Antwort, wie
 * sie war. Der Saldo bis heute bleibt der Anspruch.
 */
final class McpFeedInPayoutTest extends McpReadTestCase
{
    public function testOverviewNamesReceivedAndOpenOnlyWithPayouts(): void
    {
        $this->persona('demo');
        $this->store->write('feed_in_payouts.json', []);   // ohne die zwei Auszahlungen des Schaufensters
        $ctx = $this->context('mcp', self::DEMO_TODAY);
        $before = $this->callTool('get_overview', ['utility' => 'pv_einspeisung'], $ctx)['meters'][0]['contract']['status'];
        self::assertArrayNotHasKey('payouts_received', $before, 'ohne Auszahlung wie bisher');

        $svc = $this->svc(FeedInPayoutService::class);
        $svc->create(['meter_id' => 'm_pv_einspeisung_main', 'date' => '2026-05-15', 'amount' => 100]);
        $after = $this->callTool('get_overview', ['utility' => 'pv_einspeisung'], $ctx)['meters'][0]['contract']['status'];
        self::assertSame($before['balance_today'], $after['balance_today'], 'Anspruch unverändert');
        self::assertSame([100.0, 'entered'], [$after['payouts_received']['value'], $after['payouts_received']['basis']]);
        self::assertEqualsWithDelta($before['balance_today']['value'] - 100.0, $after['payouts_open']['value'], 0.005);
        self::assertEqualsWithDelta($before['expected_balance']['value'] - 100.0, $after['payouts_open_at_settle']['value'], 0.005);
    }
}
