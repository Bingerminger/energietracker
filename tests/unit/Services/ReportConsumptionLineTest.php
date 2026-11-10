<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ReportModelService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.12.0 — Jahresbericht, Kachel „Verbrauch“: Zahl, Prozentangabe und Linie zeigen dieselbe Größe. Bis v3.11 nahm
 * die Kachel die bereinigte Menge einer Heizart nur, wenn beide Jahre bereinigt waren, die Linie dagegen je Jahr für
 * sich (bereinigt, wo es ging, sonst roh). Nach einer Analyse-Zäsur endete die Linie dann nicht bei der großen Zahl,
 * und ihr Anstieg passte nicht zur Prozentangabe.
 *
 * Ansicht gegen Ansicht: letzter Punkt der Linie = Zahl der Kachel, vorletzter = Vorjahr der Prozentangabe, und
 * (letzter / vorletzter − 1) = Prozentangabe.
 */
#[CoversClass(ReportModelService::class)]
final class ReportConsumptionLineTest extends ServiceTestCase
{
    use ReportFixture;

    /** @return list<array{0:string,1:int}> */
    public static function households(): array
    {
        return [['demo', 2025], ['demo', 2024], ['eigenheim-klassisch', 2025], ['eigenheim-modern', 2025], ['mieterin', 2025], ['etw-fernwaerme', 2025]];
    }

    #[DataProvider('households')]
    public function testTheLineEndsAtTheTileAndRisesLikeThePercentage(string $persona, int $year): void
    {
        $this->importPersona($persona);
        $m = $this->reportServices()['model']->build($year);
        $tile = array_values(array_filter($m['headline']['tiles'] ?? [], fn($x) => $x['key'] === 'consumption'))[0] ?? null;
        if ($tile === null) self::markTestSkipped("$persona $year: keine Verbrauchskachel");
        $spark = $tile['spark'];
        self::assertEqualsWithDelta((float)$tile['value'], (float)end($spark), 0.05, "$persona $year: die Linie endet bei der Zahl der Kachel");
        if ($tile['pct'] !== null) {
            $prev = $spark[count($spark) - 2];
            self::assertNotNull($prev, "$persona $year: mit Prozentangabe hat das Vorjahr einen Punkt");
            self::assertEqualsWithDelta((float)$tile['pct'], round(((float)end($spark) / (float)$prev - 1) * 100, 1), 0.05,
                "$persona $year: der Anstieg der Linie ist die Prozentangabe");
        }
    }

    /**
     * Beispielhaushalt „Mieterin“, Bericht 2025: Die Kachel rechnet die Heizung bereinigt (2024 und 2025 ganz erfasst).
     * 2023 ist nur zum Teil erfasst und hat keine bereinigte Menge: Der Punkt bleibt leer, statt roh in der Linie zu
     * stehen (bis v3.11 stand dort die rohe Teilmenge neben zwei bereinigten Jahren).
     */
    public function testAYearWithoutTheAdjustedFigureLeavesAGap(): void
    {
        $this->importPersona('mieterin');
        $m = $this->reportServices()['model']->build(2025);
        $tile = array_values(array_filter($m['headline']['tiles'], fn($x) => $x['key'] === 'consumption'))[0];
        self::assertSame($this->i18n->t('report.tile.consumptionAdjusted'), $tile['label'], 'die Kachel rechnet bereinigt');
        self::assertNull($tile['spark'][0], '2023 ohne bereinigte Menge: kein Punkt');
        self::assertNotNull($tile['spark'][1]);
    }
}
