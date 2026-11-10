<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\Num;
use Energietracker\Services\Mcp\Schema;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolRegistry;

/**
 * v3.12.0 (F1037, Basis) — die JSON-Schema-Teilmenge (Mcp\Schema) je
 * Schlüsselwort, und jedes Werkzeug der Registry: gültige Schemas, Namen,
 * Texte im Katalog (Konzept §8.1 McpSchemaTest).
 */
final class McpSchemaTest extends McpTestCase
{
    /** @return list<string> Regeln der Fehler */
    private static function rules(mixed $value, array $schema): array
    {
        return array_column(Schema::validate($value, $schema), 'rule');
    }

    public function testTypes(): void
    {
        self::assertSame([], self::rules('x', ['type' => 'string']));
        self::assertSame(['type'], self::rules(5, ['type' => 'string']));
        self::assertSame([], self::rules(5, ['type' => 'integer']));
        self::assertSame([], self::rules(5.0, ['type' => 'integer']), '5.0 ist in JSON eine ganze Zahl');
        self::assertSame(['type'], self::rules(5.5, ['type' => 'integer']));
        self::assertSame([], self::rules(5.5, ['type' => 'number']));
        self::assertSame(['type'], self::rules('5', ['type' => 'number']));
        self::assertSame(['type'], self::rules(1, ['type' => 'boolean']));
        self::assertSame([], self::rules(null, ['type' => ['string', 'null']]));
        self::assertSame([], self::rules(['a' => 1], ['type' => 'object']));
        self::assertSame(['type'], self::rules([1, 2], ['type' => 'object']));
        self::assertSame([], self::rules([], ['type' => 'object']), '{} wird nach json_decode zu []');
        self::assertSame([], self::rules([], ['type' => 'array']));
        self::assertSame(['type'], self::rules(['a' => 1], ['type' => 'array']));
    }

    public function testStringNumberAndListRules(): void
    {
        self::assertSame(['enum'], self::rules('wärme', ['enum' => ['gas', 'strom']]));
        self::assertSame([], self::rules(1.0, ['enum' => [1, 2]]), '1 und 1.0 sind gleich');
        self::assertSame(['const'], self::rules('b', ['const' => 'a']));
        self::assertSame(['minLength'], self::rules('ab', ['minLength' => 3]));
        self::assertSame(['maxLength'], self::rules('äöüß', ['maxLength' => 3]), 'Zeichen, nicht Bytes');
        self::assertSame([], self::rules('äöü', ['maxLength' => 3]));
        self::assertSame(['pattern'], self::rules('2026-1-01', ['pattern' => '^\d{4}-\d{2}-\d{2}$']));
        self::assertSame([], self::rules('a/b', ['pattern' => '^a/b$']), 'Schrägstrich im Muster');
        self::assertSame(['format'], self::rules('2026-02-30', ['format' => 'date']), 'echtes Kalenderdatum');
        self::assertSame([], self::rules('2028-02-29', ['format' => 'date']));
        self::assertSame(['minimum'], self::rules(0, ['minimum' => 1]));
        self::assertSame(['maximum'], self::rules(61, ['maximum' => 60]));
        self::assertSame(['exclusiveMinimum'], self::rules(0, ['exclusiveMinimum' => 0]));
        self::assertSame(['exclusiveMaximum'], self::rules(1, ['exclusiveMaximum' => 1]));
        self::assertSame(['minItems'], self::rules([], ['type' => 'array', 'minItems' => 1]));
        self::assertSame(['maxItems'], self::rules([1, 2, 3], ['type' => 'array', 'maxItems' => 2]));
        self::assertSame(['type'], self::rules([1, 'x'], ['type' => 'array', 'items' => ['type' => 'integer']]));
        self::assertSame('/1', Schema::validate([1, 'x'], ['type' => 'array', 'items' => ['type' => 'integer']])[0]['path']);
    }

    public function testObjectsAndOneOf(): void
    {
        $s = ['type' => 'object', 'properties' => ['meter' => ['type' => 'string'], 'n' => ['type' => 'integer']],
              'required' => ['meter'], 'additionalProperties' => false];
        self::assertSame([], Schema::validate(['meter' => 'm1'], $s));
        self::assertSame([['path' => '/meter', 'rule' => 'required'], ['path' => '/x', 'rule' => 'additionalProperties']],
            Schema::validate(['x' => 1], $s));
        self::assertSame([['path' => '/n', 'rule' => 'type', 'expected' => 'integer']], Schema::validate(['meter' => 'm', 'n' => 'zwei'], $s));
        self::assertSame([['path' => '/extra', 'rule' => 'type', 'expected' => 'integer']],
            Schema::validate(['n' => 1, 'extra' => 'x'], ['type' => 'object', 'additionalProperties' => ['type' => 'integer']]));
        $one = ['oneOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'number']]];
        self::assertSame([], self::rules('a', $one));
        self::assertSame(['oneOf'], self::rules(true, $one), 'keine passt');
        self::assertSame(['oneOf'], self::rules(3, $one), 'zwei passen');
        $many = array_fill_keys(range('a', 'p'), 'x');
        self::assertCount(Schema::MAX_ERRORS, Schema::validate($many, ['type' => 'object', 'additionalProperties' => false]), 'höchstens zehn Fehler');
    }

    public function testCheckFindsBrokenSchemas(): void
    {
        self::assertSame([], Schema::check(['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'format' => 'date']],
            'required' => ['a'], 'additionalProperties' => false]));
        self::assertSame(["#: unknown keyword '\$ref'"], Schema::check(['$ref' => '#/x']));
        self::assertSame(["#: unknown type 'text'"], Schema::check(['type' => 'text']));
        self::assertSame(["#: required 'b' has no property"], Schema::check(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['b']]));
        self::assertSame(['#: invalid pattern'], Schema::check(['pattern' => '(']));
        self::assertSame(["#: unsupported format 'email'"], Schema::check(['format' => 'email']));
        self::assertSame(['#: enum must be a non-empty list'], Schema::check(['enum' => []]));
        self::assertSame(['#/properties/a/items: unknown keyword \'uniqueItems\''],
            Schema::check(['properties' => ['a' => ['type' => 'array', 'items' => ['uniqueItems' => true]]]]));
        self::assertSame([], Schema::check(Num::schema()), 'Zahl-Objekt');
    }

    public function testExportWritesObjectsForProperties(): void
    {
        $json = json_encode(Schema::export(['type' => 'object', 'properties' => [], 'additionalProperties' => false]));
        self::assertSame('{"type":"object","properties":{},"additionalProperties":false}', $json);
        $json = json_encode(Schema::export(['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]));
        self::assertSame('{"type":"array","items":{"type":"object","properties":{"a":{"type":"string"}}}}', $json);
    }

    /** Jedes Werkzeug der Registry: Name, Schemas, Texte (alle Pakete fallen hier mit hinein). */
    public function testEveryRegisteredToolIsWellFormed(): void
    {
        $tools = (new ToolRegistry($this->mcpServices()))->all();
        self::assertNotEmpty($tools);
        $de = json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/public/locales/de.json'), true);
        foreach ($tools as $t) {
            self::assertMatchesRegularExpression(Tool::NAME, $t->name);
            self::assertSame([], Schema::check($t->inputSchema), "{$t->name}: inputSchema");
            self::assertSame('object', $t->inputSchema['type'] ?? null, "{$t->name}: inputSchema ist ein Objekt");
            self::assertFalse($t->inputSchema['additionalProperties'] ?? true, "{$t->name}: keine unbekannten Argumente");
            if ($t->outputSchema !== null) self::assertSame([], Schema::check($t->outputSchema), "{$t->name}: outputSchema");
            foreach (['title', 'description'] as $f) {
                self::assertNotSame('', (string)($de['mcp']['tools'][$t->name][$f] ?? ''), "{$t->name}: mcp.tools.{$t->name}.$f fehlt");
            }
            self::assertLessThanOrEqual(400, mb_strlen((string)$de['mcp']['tools'][$t->name]['description']), "{$t->name}: Beschreibung kurz");
            foreach (array_keys((array)($de['mcp']['tools'][$t->name]['args'] ?? [])) as $arg) {
                self::assertArrayHasKey($arg, $t->inputSchema['properties'] ?? [], "{$t->name}: Beschreibung für unbekanntes Argument $arg");
            }
            foreach (['readOnlyHint', 'openWorldHint'] as $hint) self::assertIsBool($t->annotations[$hint], "{$t->name}: $hint");
        }
    }
}
