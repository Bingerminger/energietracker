<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

/**
 * v3.12.0 (F1037) — Alle Werkzeuge in fester Reihenfolge (gleiche Liste bei
 * jedem Abruf; Clients und Modelle zwischenspeichern sie).
 *
 * Die Gruppen stehen hier einmal; was eine Gruppe anbietet, steht in ihrer
 * Datei. Ein Name darf nur einmal vorkommen (\LogicException beim Laden).
 */
final class ToolRegistry
{
    /** Gruppen in fester Reihenfolge, je Datei ein Paket (Konzept §7) */
    public const GROUPS = [
        Tools\MeterTools::class,        // Basis: list_meters
        Tools\ReadTools::class,         // Paket lesen: get_overview, get_deadlines, list_readings, get_insights, get_yearly_report
        Tools\ContractTools::class,     // Paket lesen: get_contracts
        Tools\ConsumptionTools::class,  // Paket lesen: get_consumption, get_forecast
        Tools\ScenarioTool::class,      // Paket lesen: run_scenario
        Tools\BillTools::class,         // Paket lesen: list_bills, check_bill
        Tools\DocumentTools::class,     // Paket schreiben: list_documents, get_document, get_document_schema, read_document
        Tools\WriteTools::class,        // Paket schreiben: propose_reading, propose_document, apply_proposal, cancel_proposal
    ];

    /** @var array<string,Tool> */
    private array $tools = [];

    /** @param list<class-string<ToolGroup>> $groups */
    public function __construct(Services $services, array $groups = self::GROUPS)
    {
        foreach ($groups as $class) {
            $group = new $class($services);
            if (!$group instanceof ToolGroup) throw new \LogicException("mcp: $class is not a ToolGroup");
            foreach ($group->tools() as $tool) {
                if (isset($this->tools[$tool->name])) throw new \LogicException("mcp: tool '{$tool->name}' registered twice");
                $this->tools[$tool->name] = $tool;
            }
        }
    }

    /** @return list<Tool> */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * Werkzeuge, die ein Schlüssel nutzen darf (die Liste darf laut
     * Spezifikation vom Bereich abhängen): `mcp` sieht nur die lesenden.
     *
     * @return list<Tool>
     */
    public function forContext(McpContext $ctx): array
    {
        return array_values(array_filter($this->tools, fn(Tool $t) => $ctx->allows($t->scope)));
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }
}
