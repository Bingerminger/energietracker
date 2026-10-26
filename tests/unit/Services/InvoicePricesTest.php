<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\Inbox\InvoicePrices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v3.9.0 (F1034) — Bestandteile eines Arbeitspreises (Gas, Strom, Fernwärme):
 * Weist die Rechnung am selben Tag genau einen Arbeitspreis und daneben
 * Energiesteuer, CO₂-Preis oder einen Aufschlag je kWh aus, ist der Preis im
 * Vertrag ihre Summe. Ohne diese Angabe bleibt jede Zeile für sich wie bis v3.8
 * (nie eine Summe aus Zeilen, die vielleicht zwei Zeitabschnitte sind).
 */
#[CoversClass(InvoicePrices::class)]
final class InvoicePricesTest extends TestCase
{
    /** @param list<array<string,mixed>> $items */
    private static function fields(array $items): array
    {
        return ['working_prices' => ['status' => 'ok', 'items' => array_map(fn($v) => ['value' => $v, 'quote' => null, 'status' => 'ok', 'reasons' => []], $items)]];
    }

    public function testEnergyTaxAndCo2BecomeOneWorkingPrice(): void
    {
        // 8,50 + 0,55 + 0,95 = 10,00 ct/kWh ab 01.01.; ab 01.07. nur der Arbeitspreis (eine Zeile)
        $g = InvoicePrices::workingGroups(self::fields([
            ['from' => '2025-01-01', 'ct' => 8.5, 'part' => 'energy', 'label' => 'Arbeitspreis'],
            ['from' => '2025-01-01', 'ct' => 0.55, 'part' => 'energy_tax', 'label' => 'Energiesteuer'],
            ['from' => '2025-01-01', 'ct' => 0.95, 'part' => 'co2', 'label' => 'CO2-Preis'],
            ['from' => '2025-07-01', 'ct' => 9.0, 'part' => 'energy', 'label' => 'Arbeitspreis'],
        ]));
        self::assertSame([['2025-01-01', 10.0, true, [0, 1, 2]], ['2025-07-01', 9.0, false, [3]]],
            array_map(fn($x) => [$x['from'], $x['value'], $x['merged'], $x['items']], $g));
        self::assertSame(['Arbeitspreis', 'Energiesteuer', 'CO2-Preis'], array_column($g[0]['parts'], 'label'), 'Aufschlüsselung im Vorschlag');
    }

    public function testWithoutPartsOrWithTwoEnergyRowsNothingIsAddedUp(): void
    {
        $none = InvoicePrices::workingGroups(self::fields([
            ['from' => '2025-01-01', 'ct' => 8.5], ['from' => '2025-01-01', 'ct' => 0.55, 'part' => 'energy_tax'],
        ]));
        self::assertSame([8.5, 0.55], array_column($none, 'value'), 'ein Bestandteil ohne Angabe: keine Summe');
        $two = InvoicePrices::workingGroups(self::fields([
            ['from' => '2025-01-01', 'ct' => 8.5, 'part' => 'energy'], ['from' => '2025-01-01', 'ct' => 9.5, 'part' => 'energy'],
        ]));
        self::assertSame([false, false], array_column($two, 'merged'), 'zwei Arbeitspreise am selben Tag sind kein Preis');
        $dup = InvoicePrices::workingGroups(self::fields([
            ['from' => '2025-01-01', 'ct' => 8.5, 'part' => 'energy'], ['from' => '2025-01-01', 'ct' => 0.55, 'part' => 'energy_tax'],
            ['from' => '2025-01-01', 'ct' => 0.55, 'part' => 'energy_tax'],
        ]));
        self::assertCount(3, $dup, 'derselbe Bestandteil zweimal: nicht zusammengezählt');
    }
}
