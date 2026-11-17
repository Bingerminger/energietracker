<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\SummaryService;
use Energietracker\Tests\Services\Mcp\McpHouseholdTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — `/api/summary` `year_to_date`: die Summe der ungerundeten Tagesabschnitte, erst am Ende gerundet (wie das
 * Messentgelt nach dem Jahresverbrauch). Bis dahin summierte sie die je Monat auf 0,1 gerundeten Monatszeilen; zwei
 * Stände mit glattem Abstand ergaben einen Wert daneben. Die KI liest dieselbe Zahl (`get_overview`).
 *
 * Erfunden, von Hand (Tageskonvention: eine Ablesung am Tag D zählt bis D − 1):
 *  - Strom 500 am 01.01.2026, 2.300 am 20.06.2026: 1.800 kWh, ganz im Jahr
 *  - Wasser 40 m³ am 01.01.2026, 127,3 m³ am 11.08.2026: 87,3 m³
 *  - am Jahreswechsel: Strom 1.000 am 20.12.2025, 1.530 am 10.02.2026 (52 Tage): 2026 sind es 40 Tage, 530 × 40/52 = 407,692 kWh
 */
#[CoversClass(SummaryService::class)]
final class SummaryYearToDateTest extends McpHouseholdTestCase
{
    /** @return array<string, array{string, list<array{0:string,1:float}>, string, float}> */
    public static function cases(): array
    {
        return [
            'Strom, glatter Abstand'    => ['strom', [['2026-01-01', 500.0], ['2026-06-20', 2300.0]], '2026-08-01', 1800.0],
            'Wasser, glatter Abstand'   => ['wasser', [['2026-01-01', 40.0], ['2026-08-11', 127.3]], '2026-09-01', 87.3],
            'Strom am Jahreswechsel'    => ['strom', [['2025-12-20', 1000.0], ['2026-02-10', 1530.0]], '2026-03-01', 407.692],
        ];
    }

    #[DataProvider('cases')]
    public function testTheYearIsTheSumOfTheDaysNotOfRoundedMonths(string $u, array $readings, string $day, float $expected): void
    {
        $this->meters($u, ["m_$u" => 'Probe']);
        $this->counters($u, ["m_$u" => $readings]);
        $row = $this->svc(SummaryService::class)->build($u, null, $day)['meters'][0];
        self::assertEqualsWithDelta($expected, $row['year_to_date']['value'], 0.0005, 'Jahr bis heute = Stand minus Stand (Anteil im Jahr)');
        $ki = $this->tool('get_overview', [], $day);
        $m = array_values(array_filter($ki['meters'] ?? [], fn($x) => ($x['utility'] ?? '') === $u))[0] ?? null;
        self::assertNotNull($m);
        self::assertEqualsWithDelta($expected, (float)$m['year_to_date']['value'], 0.0005, 'KI dieselbe Zahl');
    }
}
