<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

/**
 * v3.13.0 — `reading_on` eines Abrechnungsjahrs nennt nur einen Tag, an dem eine Ablesung liegt. Eine Ablesung am
 * Tag D zählt bis D − 1 (`as_of = D − 1`); liegt sie auf dem letzten Tag der Periode, zählt dieser Tag mit
 * (`as_of = D`). Liegt am Ende keine Ablesung, ist der Stand dort zwischen zwei Ablesungen verteilt: kein
 * `reading_on`, dafür `end_distributed: true`. Bis dahin hieß immer `as_of + 1` „Tag der Ablesung“.
 */
final class McpReadingOnTest extends McpHouseholdTestCase
{
    private function contract(string $meter, string $start): void
    {
        $this->contracts->create('strom', ['meter_id' => $meter, 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Basis', 'start' => $start,
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 28.0]], 'base_prices' => [['from' => $start, 'eur_per_month' => 9.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 45.0]]]);
    }

    /** @return array<string,array<string,mixed>> Periode → Zeile */
    private function periods(array $args): array
    {
        $out = [];
        foreach ($this->tool('get_consumption', ['utility' => 'strom', 'granularity' => 'billing_period'] + $args)['rows'] as $r) $out[$r['period']] = $r;
        return $out;
    }

    public function testReadingOnOnlyWhereAReadingLies(): void
    {
        // Ablesungen nur am 14.11.2024, 14.11.2025 und 28.09.2026; Abrechnungsjahr = Kalenderjahr
        $this->meters('strom', ['m_werkstatt' => 'Werkstatt']);
        $this->counters('strom', ['m_werkstatt' => [['2024-11-14', 0], ['2025-11-14', 1450], ['2026-09-28', 2600]]]);
        $this->contract('m_werkstatt', '2024-11-14');
        $p = $this->periods(['meter' => 'm_werkstatt']);
        // 2024: bis 31.12. gerechnet, am 01.01.2025 keine Ablesung (Stand verteilt zwischen 14.11.2024 und 14.11.2025)
        self::assertSame('2024-12-31', $p['2024-11-14/2024-12-31']['as_of']);
        self::assertArrayNotHasKey('reading_on', $p['2024-11-14/2024-12-31']);
        self::assertTrue($p['2024-11-14/2024-12-31']['end_distributed']);
        // 2025: am 01.01.2026 keine Ablesung
        self::assertArrayNotHasKey('reading_on', $p['2025-01-01/2025-12-31']);
        self::assertTrue($p['2025-01-01/2025-12-31']['end_distributed']);
        // 2026: bis zur letzten Ablesung am 28.09. (as_of 27.09.), dort liegt sie
        self::assertSame(['2026-09-27', '2026-09-28'], [$p['2026-01-01/2026-12-31']['as_of'], $p['2026-01-01/2026-12-31']['reading_on']]);
        self::assertArrayNotHasKey('end_distributed', $p['2026-01-01/2026-12-31']);
    }

    public function testReadingAtTheTurnOfTheYearAndOnTheLastDay(): void
    {
        // Jahreswechsel von Hand: Ablesung am 01.01.2026 → 2025 zählt bis 31.12. (as_of), Ablesung am 01.01.
        $this->meters('strom', ['m_haus' => 'Haus']);
        $this->counters('strom', ['m_haus' => [['2025-01-01', 0], ['2026-01-01', 2400], ['2026-08-17', 4100]]]);
        $this->contract('m_haus', '2025-01-01');
        $p = $this->periods(['meter' => 'm_haus']);
        self::assertSame(['2025-12-31', '2026-01-01'], [$p['2025-01-01/2025-12-31']['as_of'], $p['2025-01-01/2025-12-31']['reading_on']]);

        // Ablesung am letzten Tag der Periode (31.12.2025) und keine danach: Der Tag zählt mit, reading_on = as_of
        $this->counters('strom', ['m_haus' => [['2025-01-01', 0], ['2025-12-31', 2400]]]);
        $p = $this->periods(['meter' => 'm_haus']);
        $r = $p['2025-01-01/2025-12-31'];
        self::assertSame($r['as_of'], $r['reading_on'], 'am letzten Tag gezählt: dieser Tag');
        self::assertSame('2025-12-31', $r['reading_on']);
    }

    public function testSumOfTwoMetersNamesTheDayOnlyIfEveryMeterWasRead(): void
    {
        // A am 01.01.2026 abgelesen, B nicht (B am 12.12.2025): die Summe hat am 01.01.2026 keinen gemessenen Rand
        $this->meters('strom', ['m_a' => 'A', 'm_b' => 'B']);
        $this->counters('strom', ['m_a' => [['2025-01-01', 0], ['2026-01-01', 2150], ['2026-08-01', 3300]],
            'm_b' => [['2025-01-01', 0], ['2025-12-12', 905], ['2026-08-01', 1500]]]);
        $this->contract('m_a', '2025-01-01');
        $this->contract('m_b', '2025-01-01');
        $sum = $this->periods([]);
        self::assertArrayNotHasKey('reading_on', $sum['2025-01-01/2025-12-31']);
        self::assertTrue($sum['2025-01-01/2025-12-31']['end_distributed']);
        self::assertSame('2026-01-01', $this->periods(['meter' => 'm_a'])['2025-01-01/2025-12-31']['reading_on'], 'A allein');
        // beide am 01.01.2026 abgelesen: die Summe nennt den Tag
        $this->counters('strom', ['m_a' => [['2025-01-01', 0], ['2026-01-01', 2150], ['2026-08-01', 3300]],
            'm_b' => [['2025-01-01', 0], ['2026-01-01', 950], ['2026-08-01', 1500]]]);
        self::assertSame('2026-01-01', $this->periods([])['2025-01-01/2025-12-31']['reading_on']);
        // ein stillgelegter Zähler mit Ständen nur bis 2024 hält die Summe nicht auf
        $this->meters('strom', ['m_a' => 'A', 'm_b' => 'B', 'm_alt' => 'Alt']);
        $this->counters('strom', ['m_a' => [['2025-01-01', 0], ['2026-01-01', 2150], ['2026-08-01', 3300]],
            'm_b' => [['2025-01-01', 0], ['2026-01-01', 950], ['2026-08-01', 1500]], 'm_alt' => [['2023-03-01', 0], ['2024-03-01', 500]]]);
        self::assertSame('2026-01-01', $this->periods([])['2025-01-01/2025-12-31']['reading_on'], 'Zähler ohne Stände um diesen Tag zählt nicht');
    }
}
