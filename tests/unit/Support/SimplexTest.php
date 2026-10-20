<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

use Energietracker\Support\Simplex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v3.6.0 (F1031) — Simplex-Löser der Prognose-Werkstatt an Lehrbuchfällen.
 */
#[CoversClass(Simplex::class)]
final class SimplexTest extends TestCase
{
    /** Lehrbuch (Hillier/Lieberman, „Wyndor“): max 3x + 5y, x ≤ 4, 2y ≤ 12, 3x + 2y ≤ 18. */
    public function testTextbookOptimumAndShadowPrices(): void
    {
        $r = Simplex::maximize([3, 5], [[1, 0], [0, 2], [3, 2]], [4, 12, 18]);
        self::assertSame('optimal', $r['status']);
        self::assertEqualsWithDelta([2.0, 6.0], $r['x'], 1e-9);
        self::assertEqualsWithDelta(36.0, $r['value'], 1e-9);
        self::assertEqualsWithDelta([0.0, 1.5, 1.0], $r['duals'], 1e-9, 'Schattenpreise: erste Bedingung nicht bindend');
    }

    /** Beales Beispiel kreist mit der Dantzig-Regel; mit Bland endet es im Optimum 5/4. */
    public function testBealeDoesNotCycle(): void
    {
        $r = Simplex::maximize(
            [0.75, -20, 0.5, -6],
            [[0.25, -8, -1, 9], [0.5, -12, -0.5, 3], [0, 0, 1, 0]],
            [0, 0, 1]
        );
        self::assertSame('optimal', $r['status']);
        self::assertEqualsWithDelta(1.25, $r['value'], 1e-9);
        self::assertEqualsWithDelta([1.0, 0.0, 1.0, 0.0], $r['x'], 1e-9);
    }

    public function testUnboundedAndZeroAndBadInput(): void
    {
        self::assertSame('unbounded', Simplex::maximize([1, 1], [[-1, 1]], [1])['status']);
        $z = Simplex::maximize([-1, -2], [[1, 1]], [5]);
        self::assertSame([0.0, 0.0], $z['x'], 'nur negative Beiträge: Ursprung');
        self::assertSame(0.0, $z['value']);
        $this->expectException(\InvalidArgumentException::class);
        Simplex::maximize([1], [[1]], [-1]);
    }

    /** Rucksack-Relaxierung wie in der Werkstatt: Budget + Obergrenzen 1. */
    public function testFractionalKnapsackRelaxation(): void
    {
        $r = Simplex::maximize([10, 40, 30, 50], [[5, 4, 6, 3], [1, 0, 0, 0], [0, 1, 0, 0], [0, 0, 1, 0], [0, 0, 0, 1]], [10, 1, 1, 1, 1]);
        self::assertEqualsWithDelta(105.0, $r['value'], 1e-9);
        self::assertEqualsWithDelta([0.0, 1.0, 0.5, 1.0], $r['x'], 1e-9);
        self::assertEqualsWithDelta(5.0, $r['duals'][0], 1e-9);
    }
}
