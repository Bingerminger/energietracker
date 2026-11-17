<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

/**
 * v3.13.0 (Paket vertrag, #40) — HT/NT je Vertrag in der KI: `get_overview` nennt an den Zählwerken einer Gruppe mit
 * Gruppenvertrag zusätzlich `group_forecast_12m` (mit Grundpreis und Abschlag), einmal mit Zahlen, danach mit
 * `same_as` (zusammenzählen wäre doppelt). `forecast_12m` der Zählwerke bleibt, wie es war. `get_forecast` eines
 * Zählwerks nennt die Gruppe, deren Prognose die Kosten trägt.
 */
final class McpGroupForecastTest extends McpReadTestCase
{
    public function testOverviewNamesTheGroupForecastOnceAndKeepsTheRegisters(): void
    {
        $this->persona('demo');
        $ctx = $this->context('mcp', '2026-06-15');
        $meters = array_column($this->callTool('get_overview', ['utility' => 'strom'], $ctx)['meters'], null, 'id');
        $ht = $meters['m_strom_wp_ht']; $nt = $meters['m_strom_wp_nt'];
        $g = $ht['group_forecast_12m'];
        self::assertSame('g_strom_wp', $g['group_id']);
        self::assertSame('estimated', $g['quantity']['basis'] ?? null, 'Herkunft je Zahl');
        // Gruppe = HT + NT, Kosten der Gruppe mit 118,80 € Grundpreis über der Summe der Zählwerke
        self::assertEqualsWithDelta($ht['forecast_12m']['quantity']['value'] + $nt['forecast_12m']['quantity']['value'], $g['quantity']['value'], 0.1);
        self::assertEqualsWithDelta(118.80, $g['cost']['value'] - $ht['forecast_12m']['cost']['value'] - $nt['forecast_12m']['cost']['value'], 0.011);
        self::assertSame(['group_id' => 'g_strom_wp', 'same_as' => 'm_strom_wp_ht'], $nt['group_forecast_12m']);
        self::assertArrayNotHasKey('group_forecast_12m', $meters['m_strom_main'], 'kein Mitglied');

        $f = $this->callTool('get_forecast', ['utility' => 'strom', 'meter' => 'm_strom_wp_nt'], $ctx);
        self::assertSame('g_strom_wp', $f['priced_by_group'] ?? null);
        self::assertArrayNotHasKey('priced_by_group', $this->callTool('get_forecast', ['utility' => 'strom', 'meter' => 'g_strom_wp'], $ctx));
    }
}
