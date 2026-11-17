<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\ToolRegistry;
use Energietracker\Support\TextLimits;

/**
 * v3.13.0 (Paket F) — Längengrenzen auf dem Schreibweg der KI: Das Schema von `propose_reading` nennt die
 * Grenze der Notiz aus der Liste (TextLimits::MCP_NOTE, enger als die Notiz einer Ablesung), zu lang ist ein
 * Fehler, gekürzt wird nicht; eine längere Notiz kommt auch über eine Übernahme nicht in die Ablesung.
 */
final class McpTextLimitsTest extends McpInboxTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->gasHousehold();
        $this->setReadings('gas', 'm_gas_default', [['date' => '2026-01-01', 'counter' => 13100.0, 'device_id' => 'd_gas_1']]);
    }

    public function testTheNoteOfAProposedReadingHasTheLimitOfTheList(): void
    {
        $tool = (new ToolRegistry($this->mcpServices()))->get('propose_reading');
        self::assertSame(TextLimits::MCP_NOTE, $tool->inputSchema['properties']['note']['maxLength']);
        $args = ['utility' => 'gas', 'meter' => 'm_gas_default', 'date' => '2026-02-01', 'counter' => 13200.0];
        $err = $this->callToolError('propose_reading', $args + ['note' => str_repeat('n', TextLimits::MCP_NOTE + 1)], $this->writer());
        self::assertSame('errors.mcp.invalidArguments', $err['code']);
        self::assertSame([], $this->pending->listOpen(), 'kein Vorschlag, nichts gekürzt');
        $p = $this->callTool('propose_reading', $args + ['note' => str_repeat('n', TextLimits::MCP_NOTE)], $this->writer());
        $this->callTool('apply_proposal', ['proposal_id' => $p['proposal_id'], 'summary' => $p['summary']], $this->writer());
        $new = array_values(array_filter($this->readings->list('gas', 'm_gas_default'), fn($r) => $r['date'] === '2026-02-01'));
        self::assertSame(TextLimits::MCP_NOTE, mb_strlen((string)$new[0]['note']));
    }
}
