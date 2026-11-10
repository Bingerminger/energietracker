<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\Provenance;
use Energietracker\Services\Mcp\ToolRegistry;
use Energietracker\Services\Mcp\Tools;

/**
 * v3.12.0 (F1037, Paket lesen) — „jede Zahl hat Herkunft“ (Konzept §8.1):
 * die Abbildungstabelle aus §3.1 als Datentest mit von Hand gesetzten Fällen,
 * dann jedes lesende Werkzeug auf dem erfundenen, dem unordentlichen und den
 * Beispielhaushalten; dazu die Größe einer Antwort (Ziel unter 8 000 Token).
 */
final class McpProvenanceTest extends McpReadTestCase
{
    /** Gruppen des Pakets (die Dokument-Werkzeuge gehören zu „schreiben“) */
    private const GROUPS = [Tools\MeterTools::class, Tools\ReadTools::class, Tools\ContractTools::class,
        Tools\ConsumptionTools::class, Tools\ScenarioTool::class, Tools\BillTools::class];
    /** rund 4 Zeichen je Token: 8 000 Token */
    private const MAX_BYTES = 32000;

    /** @return iterable<string,array{array<string,mixed>,?string,string}> */
    public static function monthRows(): iterable
    {
        yield 'Abstände bis 45 Tage' => [['ym' => '2026-01', 'interpolated' => false, 'measured_share' => 1.0], null, 'measured'];
        yield 'über 45 Tage verteilt' => [['ym' => '2026-03', 'interpolated' => true, 'reading_gap_days' => 92], null, 'distributed'];
        yield 'an geschätzter Ablesung' => [['ym' => '2026-06', 'interpolated' => true, 'reading_gap_estimated' => true], null, 'estimated'];
        yield 'gemessen trotz geschätzter Stütze' => [['ym' => '2026-06', 'interpolated' => false, 'reading_gap_estimated' => true], null, 'measured'];
        yield 'Tankbuch nach Gradtagen' => [['ym' => '2026-02', 'interpolated' => null, 'estimated_days' => 0], null, 'distributed'];
        yield 'Tankbuch nach letztem Bestand' => [['ym' => '2026-02', 'interpolated' => null, 'estimated_days' => 3], null, 'estimated'];
        yield 'nach der letzten Ablesung' => [['ym' => '2026-08', 'interpolated' => false], '2026-07-01', 'estimated'];
        yield 'Monat der letzten Ablesung' => [['ym' => '2026-07', 'interpolated' => false], '2026-07-01', 'measured'];
    }

    /** @dataProvider monthRows */
    #[\PHPUnit\Framework\Attributes\DataProvider('monthRows')]
    public function testMonthRowMapping(array $row, ?string $until, string $basis): void
    {
        self::assertSame($basis, Provenance::month($row, $until));
    }

    public function testTheOtherMappingsOfTheConceptTable(): void
    {
        // Ränder der Rechnungsprüfung
        self::assertSame(['measured', 'measured', 'estimated', 'distributed', 'estimated'],
            array_map([Provenance::class, 'boundary'], ['reading', 'reading_adjacent', 'reading_estimated', 'interpolated', null]));
        // Ablesung
        self::assertSame(['measured', 'estimated'], [Provenance::reading(['is_estimated' => false]), Provenance::reading(['is_estimated' => true])]);
        // Preise: eingetragen, nach der Preisgarantie oder fortgeschrieben angenommen
        self::assertSame(['entered', 'entered', 'assumed', 'assumed'], [
            Provenance::price([], '2026-07-15'),
            Provenance::price(['price_guarantee_until' => '2026-07-15'], '2026-07-15'),
            Provenance::price(['price_guarantee_until' => '2026-07-14'], '2026-07-15'),
            Provenance::price(['renewed' => true], '2026-07-15'),
        ]);
        self::assertSame(['entered', 'assumed'], [Provenance::monthPrice([]), Provenance::monthPrice(['contract_assumed' => true])]);
        self::assertSame('estimated', Provenance::monthCost(['interpolated' => true, 'reading_gap_estimated' => true]));
        self::assertSame('entered', Provenance::monthCost(['interpolated' => false]), 'gemessene Menge × eingetragener Preis');

        // Anteile am Betrag: 1 000 gemessen, 3 000 verteilt; 1 kWh geschätzt fällt unter 0,5 % weg
        $rows = [['interpolated' => false, 'kwh' => 1000.0], ['interpolated' => true, 'kwh' => 3000.0],
                 ['interpolated' => true, 'reading_gap_estimated' => true, 'kwh' => 1.0]];
        self::assertSame(['measured' => 0.25, 'distributed' => 0.75], array_map(fn($v) => round($v, 4), Provenance::partsOf($rows, 'kwh')));
        // ohne Menge nach Tagen
        self::assertSame(['measured' => 0.5, 'distributed' => 0.5],
            Provenance::partsOf([['interpolated' => false, 'days' => 10], ['interpolated' => true, 'days' => 10]], 'kwh'));
        // über ganze Ablesungsabstände: verteilt wird gemessen (Kosten: eingetragen), geschätzt bleibt
        self::assertSame(['measured' => 0.9, 'estimated' => 0.1], Provenance::aligned(['measured' => 0.5, 'distributed' => 0.4, 'estimated' => 0.1]));
        self::assertSame(['entered' => 1.0], Provenance::aligned(['distributed' => 1.0], 'entered'));

        // Saldo bis heute (contractStatus): 300 € Arbeit gemessen bis zur Ablesung + 50 € geschätzt danach,
        // 60 € Grundpreis, Abschläge 500 € (Kosten 410 €, Saldo −90 €) → Gewichte 300 : 50 : 560
        $parts = Provenance::balanceParts(['energy_cost_to_date' => 350.0, 'estimated_cost_to_date' => 50.0, 'base_to_date' => 60.0,
            'bonus_to_date' => 0.0, 'cost_to_date' => 410.0, 'current_balance' => -90.0], ['measured' => 1.0]);
        self::assertSame(['measured' => 0.3297, 'entered' => 0.6154, 'estimated' => 0.0549], array_map(fn($v) => round($v, 4), $parts));
        self::assertSame(Provenance::SIGN_PAYOUT, Provenance::signFor(true));
    }

    public function testEveryFigureHasItsBasisInTheInventedHousehold(): void
    {
        $this->household();
        $this->estimatedBoundaryBill();
        $this->assertAllTools(self::TODAY, 'erfunden');
    }

    public function testEveryFigureHasItsBasisInTheMessyHousehold(): void
    {
        $this->messy();
        $this->assertAllTools(self::MESSY_TODAY, 'unordentlich');
    }

    public function testEveryFigureHasItsBasisInTheDemoHousehold(): void
    {
        $this->persona('demo');
        $this->assertAllTools(self::DEMO_TODAY, 'demo');
    }

    /** Der größte Beispielhaushalt (Wärmepumpe, PV, Speicher, Auto): Herkunft und Größe je Antwort. */
    public function testEveryFigureHasItsBasisAndAnswersStaySmallInTheLargestPersona(): void
    {
        $this->persona('eigenheim-modern');
        $this->assertAllTools(self::DEMO_TODAY, 'eigenheim-modern');
    }

    public function testEveryToolOfThePackageIsCovered(): void
    {
        $names = array_map(fn($t) => $t->name, (new ToolRegistry($this->mcpServices(), self::GROUPS))->all());
        self::assertSame(['list_meters', 'get_overview', 'get_deadlines', 'list_readings', 'get_insights', 'get_yearly_report', 'get_contracts',
            'get_consumption', 'get_forecast', 'run_scenario', 'list_bills', 'check_bill'], $names);
        $called = array_keys($this->callsFor());
        $listed = array_values(array_diff($names, ['list_meters']));
        sort($called);
        sort($listed);
        self::assertSame($listed, $called, 'jedes Werkzeug läuft im Herkunftstest');
    }

    private function assertAllTools(string $today, string $label): void
    {
        $ctx = $this->context('mcp', $today);
        $calls = $this->callsFor() + ['list_meters' => [['include_inactive' => true]]];
        $ran = 0;
        foreach ($calls as $tool => $list) {
            foreach ($list as $args) {
                $out = $this->callTool($tool, $args, $ctx);
                $where = "$label $tool " . json_encode($args);
                self::assertSame([], self::numbersWithoutProvenance($out, self::EXEMPT), "Zahlen ohne Herkunft: $where");
                self::assertLessThan(self::MAX_BYTES, strlen((string)json_encode($out, JSON_UNESCAPED_UNICODE)), "zu groß: $where");
                $ran++;
            }
        }
        self::assertGreaterThan(10, $ran, "$label: zu wenige Aufrufe");
    }
}
