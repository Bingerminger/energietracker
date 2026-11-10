<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\ToolRegistry;
use Energietracker\Services\Mcp\Tools;

/**
 * v3.12.0 (F1037, Paket lesen) — Die Tabelle „Lesende Werkzeuge“ in
 * docs/referenz/api.md (DE und EN) nennt genau die Werkzeuge des Pakets, mit
 * ihren Eingaben und den Grenzen aus den Konstanten (Lektion 31, 99).
 */
final class McpReadDocsTest extends McpTestCase
{
    private const GROUPS = [Tools\MeterTools::class, Tools\ReadTools::class, Tools\ContractTools::class,
        Tools\ConsumptionTools::class, Tools\ScenarioTool::class, Tools\BillTools::class];

    /** @return array<string,string> Werkzeug → Zeile der Tabelle */
    private static function table(string $file, string $heading): array
    {
        $md = (string)file_get_contents(dirname(__DIR__, 4) . "/docs/$file");
        $start = strpos($md, "#### $heading");
        self::assertNotFalse($start, "$file: Abschnitt „{$heading}“ fehlt");
        $section = substr($md, $start, (int)strpos($md, "\n### ", $start) - $start);
        $rows = [];
        foreach (explode("\n", $section) as $line) {
            if (preg_match('/^\| `([a-z_]+)` \|/', $line, $m)) $rows[$m[1]] = $line;
        }
        return $rows;
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function docs(): iterable
    {
        yield 'de' => ['referenz/api.md', 'Lesende Werkzeuge', 'höchstens'];
        yield 'en' => ['en/referenz/api.md', 'Read tools', 'at most'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('docs')]
    public function testTheTableNamesEveryReadToolWithItsInputsAndLimits(string $file, string $heading, string $atMost): void
    {
        $rows = self::table($file, $heading);
        $tools = (new ToolRegistry($this->mcpServices(), self::GROUPS))->all();
        self::assertSame(array_map(fn($t) => $t->name, $tools), array_keys($rows), "$file: Werkzeuge der Tabelle = Registry");
        foreach ($tools as $t) {
            // run_scenario nennt die Stellschrauben der Werkstatt nur zum Teil („…“)
            $args = $t->name === 'run_scenario' ? ['utility', 'meter', 'saving_pct', 'measures', 'tariff', 'budget_eur']
                : array_keys($t->inputSchema['properties'] ?? []);
            foreach ($args as $arg) {
                self::assertArrayHasKey($arg, $t->inputSchema['properties'], "{$t->name}: $arg");
                self::assertStringContainsString("`$arg`", $rows[$t->name], "$file {$t->name}: Eingabe $arg");
            }
        }
        $limits = [
            'get_deadlines'   => ["$atMost " . Tools\ReadTools::MAX_EVENTS, '366', (string)Tools\ReadTools::DEFAULT_DEADLINE_DAYS],
            'list_readings'   => [(string)Tools\ReadTools::MAX_READINGS, (string)Tools\ReadTools::DEFAULT_READINGS],
            'get_insights'    => ["$atMost " . Tools\ReadTools::MAX_INSIGHTS],
            'get_consumption' => ["$atMost " . Tools\ConsumptionTools::MAX_ROWS],
            'get_forecast'    => ['60', (string)Tools\ConsumptionTools::DEFAULT_FORECAST_MONTHS],
            'list_bills'      => ["$atMost " . Tools\BillTools::MAX_BILLS],
        ];
        foreach ($limits as $tool => $needles) {
            foreach ($needles as $n) self::assertStringContainsString($n, $rows[$tool], "$file $tool: Grenze $n");
        }
        $schema = fn(string $tool, string $arg) => $this->registry()->get($tool)?->inputSchema['properties'][$arg] ?? [];
        self::assertSame([1, 366, Tools\ReadTools::DEFAULT_DEADLINE_DAYS], [$schema('get_deadlines', 'days')['minimum'], $schema('get_deadlines', 'days')['maximum'],
            $schema('get_deadlines', 'days')['default']]);
        self::assertSame([1, 60, Tools\ConsumptionTools::DEFAULT_FORECAST_MONTHS], [$schema('get_forecast', 'months')['minimum'],
            $schema('get_forecast', 'months')['maximum'], $schema('get_forecast', 'months')['default']]);
    }

    private function registry(): ToolRegistry
    {
        return new ToolRegistry($this->mcpServices(), self::GROUPS);
    }
}
