<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Config\Utilities;
use Energietracker\Services\Mcp\Schema;
use Energietracker\Services\Mcp\ToolRegistry;
use Energietracker\Services\ScenarioService;

/**
 * v3.13.0 — Jede Ausgabe jedes lesenden Werkzeugs passt zu ihrem `outputSchema`, so wie sie über die Leitung
 * geht: `{}` ist ein Objekt, `[]` eine Liste. Ein Zähler ohne Abschlag (Heizöl, Pellets, Einspeisung) lieferte
 * `run_scenario.advance` als leere Liste, obwohl das Schema ein Objekt verlangt; ein prüfendes Programm verwirft
 * eine solche Antwort. Geprüft an allen Beispielhaushalten, dem unordentlichen und dem erfundenen Haushalt, mit
 * den Aufrufen des Herkunftstests und dazu jedem Szenario ohne weitere Eingabe.
 */
final class McpOutputSchemaTest extends McpReadTestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function households(): iterable
    {
        yield 'erfunden' => ['household', self::TODAY];
        yield 'unordentlich' => ['messy', self::MESSY_TODAY];
        yield 'vertraege' => ['edge', self::EDGE_TODAY];   // v3.13.0 — Verträge, die nicht glatt laufen
        yield 'schaufenster' => ['demo', self::DEMO_TODAY];
        foreach (['eigenheim-klassisch', 'eigenheim-modern', 'etw-fernwaerme', 'mieterin'] as $p) yield $p => [$p, self::DEMO_TODAY];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('households')]
    public function testEveryAnswerMatchesItsOutputSchemaOnTheWire(string $which, string $today): void
    {
        match ($which) {
            'household' => $this->household(),
            'messy'     => $this->messy(),
            'edge'      => $this->edge(),
            default     => $this->persona($which),
        };
        $ctx = $this->context('mcp', $today);
        $calls = $this->callsFor() + ['list_meters' => [['include_inactive' => true]]];
        // jedes Szenario auch ohne weitere Eingabe (so kam die leere Liste)
        foreach ((array)$this->settings->get('active_utilities', []) as $u) {
            if (!ScenarioService::supports($u)) continue;
            foreach ($this->meters->list($u) as $m) {
                if ($this->readings->list($u, (string)$m['id']) === [] && !Utilities::isDelivery($u)) continue;
                $calls['run_scenario'][] = ['utility' => $u, 'meter' => (string)$m['id']];
            }
        }
        $registry = new ToolRegistry($this->mcpServices());
        $n = 0;
        foreach ($calls as $tool => $list) {
            $schema = $registry->get($tool)?->outputSchema;
            self::assertNotNull($schema, "$tool ohne outputSchema");
            foreach ($list as $args) {
                // callTool prüft nachsichtig und auf der Leitung; hier dazu der Text selbst noch einmal
                $r = $this->rpc('tools/call', ['name' => $tool, 'arguments' => (object)$args], $ctx);
                $text = (string)($r['body']['result']['content'][0]['text'] ?? '');
                self::assertFalse($r['body']['result']['isError'] ?? true, "$which $tool " . json_encode($args) . ': ' . $text);
                self::assertSame([], Schema::validateJson($text, $schema), "$which $tool " . json_encode($args));
                $n++;
            }
        }
        self::assertGreaterThan(10, $n, "$which: zu wenige Aufrufe");
    }

    /** Der gemeldete Fall von Hand: Heizöl ohne Abschlag, Szenario ohne Eingabe. */
    public function testScenarioWithoutAdvanceSendsAnObjectNotAList(): void
    {
        $this->persona('demo');
        $oil = null;
        foreach ($this->meters->list('heizoel') as $m) { $oil = (string)$m['id']; break; }
        self::assertNotNull($oil, 'Beispielhaushalt ohne Heizöl');
        $r = $this->rpc('tools/call', ['name' => 'run_scenario', 'arguments' => (object)['utility' => 'heizoel', 'meter' => $oil]], $this->context('mcp', self::DEMO_TODAY));
        $wire = json_decode((string)$r['body']['result']['content'][0]['text'], false);
        self::assertTrue($wire->valid ?? false, 'Szenario gerechnet');
        // ohne Abschlag: ein leeres Objekt oder kein Feld, nie eine Liste
        self::assertFalse(isset($wire->advance) && is_array($wire->advance), 'advance ist keine Liste');
        if (isset($wire->advance)) self::assertInstanceOf(\stdClass::class, $wire->advance);
    }
}
