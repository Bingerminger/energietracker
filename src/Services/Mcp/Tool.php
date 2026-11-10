<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

use Energietracker\Services\I18nService;

/**
 * v3.12.0 (F1037) — Ein Werkzeug: Name, Bereich, Schemas, Hinweise und der Aufruf.
 *
 * Titel und Beschreibung kommen aus dem Katalog (`mcp.tools.<name>.title`,
 * `mcp.tools.<name>.description`, Sprache des Servers), ebenso je Eingabe eine
 * Beschreibung `mcp.tools.<name>.args.<feld>`, wenn es sie gibt. Beschreibungen
 * kurz, mit den Suchwörtern vorn (Clients lesen oft nur den Anfang).
 *
 * Der Aufruf bekommt die schon gegen `inputSchema` geprüften Argumente und
 * liefert die strukturierte Ausgabe (passend zu `outputSchema`). Fachliche
 * Fehler wirft er als LocalizedException (wird `isError` mit Code), alles andere
 * ist ein Serverfehler.
 */
final class Tool
{
    public const READ = 'mcp';
    public const WRITE = 'mcp_write';
    public const NAME = '/^[A-Za-z0-9_.-]{1,128}$/';

    /** @var array<string,mixed> */
    public readonly array $annotations;

    /**
     * @param array<string,mixed> $inputSchema  Objekt-Schema (Schema::KEYWORDS), `additionalProperties: false`
     * @param \Closure(array<string,mixed>, McpContext): mixed $handler
     * @param array<string,mixed>|null $outputSchema
     * @param array<string,mixed> $annotations readOnlyHint, destructiveHint, idempotentHint, openWorldHint
     * @param array<string,mixed> $meta        `_meta` der Definition (etwa: der Client soll vor jedem Aufruf fragen)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $scope,
        public readonly array $inputSchema,
        private \Closure $handler,
        public readonly ?array $outputSchema = null,
        array $annotations = [],
        public readonly array $meta = [],
    ) {
        if (!preg_match(self::NAME, $name)) throw new \LogicException("mcp: invalid tool name '$name'");
        if (!in_array($scope, [self::READ, self::WRITE], true)) throw new \LogicException("mcp: tool '$name' has unknown scope '$scope'");
        // Standard: Lesewerkzeuge ändern nichts; kein Werkzeug greift nach außen
        $this->annotations = array_merge(['readOnlyHint' => $scope === self::READ, 'openWorldHint' => false], $annotations);
    }

    /**
     * Definition für `tools/list`.
     *
     * @return array<string,mixed>
     */
    public function definition(I18nService $i18n, string $locale): array
    {
        $schema = $this->inputSchema;
        foreach (array_keys((array)($schema['properties'] ?? [])) as $prop) {
            if (isset($schema['properties'][$prop]['description'])) continue;
            $key = "mcp.tools.{$this->name}.args.$prop";
            $text = $i18n->t($key, [], $locale);
            if ($text !== $key) $schema['properties'][$prop]['description'] = $text;
        }
        $def = [
            'name'        => $this->name,
            'title'       => $i18n->t("mcp.tools.{$this->name}.title", [], $locale),
            'description' => $i18n->t("mcp.tools.{$this->name}.description", [], $locale),
            'inputSchema' => Schema::export($schema),
        ];
        if ($this->outputSchema !== null) $def['outputSchema'] = Schema::export($this->outputSchema);
        $def['annotations'] = $this->annotations;
        if ($this->meta !== []) $def['_meta'] = $this->meta;
        return $def;
    }

    /** @param array<string,mixed> $args geprüft gegen inputSchema */
    public function call(array $args, McpContext $ctx): mixed
    {
        return ($this->handler)($args, $ctx);
    }
}
