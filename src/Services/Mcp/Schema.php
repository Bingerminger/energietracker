<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

use Energietracker\Support\Dates;

/**
 * v3.12.0 (F1037) — Teilmenge von JSON Schema (2020-12) für die Eingaben und
 * Ausgaben der Werkzeuge, ohne Fremdpaket (Konzept §2.11).
 *
 * Schlüsselwörter: type (auch als Liste), properties, required, enum, const,
 * pattern, minimum, maximum, exclusiveMinimum, exclusiveMaximum, minLength,
 * maxLength, items, minItems, maxItems, additionalProperties (false oder Schema),
 * oneOf, format (nur `date` = JJJJ-MM-TT als echtes Datum); dazu ohne Wirkung
 * description, title, default, examples. Kein $ref (also nie ein Abruf aus dem
 * Netz). Ein Werkzeug mit anderem Schlüsselwort fällt in McpSchemaTest auf
 * (check()), statt still nicht geprüft zu werden.
 *
 * JSON kennt `{}` und `[]`, PHP nach json_decode nur `[]`: Ein leeres Feld gilt
 * deshalb als Objekt und als Liste.
 */
final class Schema
{
    public const KEYWORDS = ['type', 'properties', 'required', 'enum', 'const', 'pattern', 'minimum', 'maximum',
        'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'items', 'minItems', 'maxItems',
        'additionalProperties', 'oneOf', 'format', 'description', 'title', 'default', 'examples'];
    public const TYPES = ['string', 'number', 'integer', 'boolean', 'object', 'array', 'null'];
    public const FORMATS = ['date'];
    /** höchstens so viele Fehler je Prüfung (die KI braucht die ersten, nicht alle) */
    public const MAX_ERRORS = 10;

    /**
     * Prüft einen Wert. Leer = gültig.
     *
     * @param array<string,mixed> $schema
     * @return list<array{path:string, rule:string, expected?:mixed}>
     */
    public static function validate(mixed $value, array $schema, string $path = ''): array
    {
        $errors = [];
        self::walk($value, $schema, $path, $errors);
        return array_slice($errors, 0, self::MAX_ERRORS);
    }

    /** @param list<array<string,mixed>> $errors */
    private static function walk(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (count($errors) >= self::MAX_ERRORS) return;
        $at = $path === '' ? '/' : $path;
        if (isset($schema['type'])) {
            $types = (array)$schema['type'];
            $ok = false;
            foreach ($types as $t) if (self::isType($value, (string)$t)) { $ok = true; break; }
            if (!$ok) { $errors[] = ['path' => $at, 'rule' => 'type', 'expected' => implode('|', $types)]; return; }
        }
        if (array_key_exists('const', $schema) && !self::same($value, $schema['const'])) {
            $errors[] = ['path' => $at, 'rule' => 'const', 'expected' => $schema['const']];
        }
        if (isset($schema['enum'])) {
            $hit = false;
            foreach ((array)$schema['enum'] as $e) if (self::same($value, $e)) { $hit = true; break; }
            if (!$hit) $errors[] = ['path' => $at, 'rule' => 'enum', 'expected' => array_values((array)$schema['enum'])];
        }
        if (is_string($value)) {
            $len = mb_strlen($value);
            if (isset($schema['minLength']) && $len < (int)$schema['minLength']) $errors[] = ['path' => $at, 'rule' => 'minLength', 'expected' => (int)$schema['minLength']];
            if (isset($schema['maxLength']) && $len > (int)$schema['maxLength']) $errors[] = ['path' => $at, 'rule' => 'maxLength', 'expected' => (int)$schema['maxLength']];
            if (isset($schema['pattern']) && preg_match(self::regex((string)$schema['pattern']), $value) !== 1) {
                $errors[] = ['path' => $at, 'rule' => 'pattern', 'expected' => (string)$schema['pattern']];
            }
            if (($schema['format'] ?? null) === 'date' && !Dates::isIsoDate($value)) {
                $errors[] = ['path' => $at, 'rule' => 'format', 'expected' => 'date'];
            }
        }
        if ((is_int($value) || is_float($value))) {
            $n = (float)$value;
            if (isset($schema['minimum']) && $n < (float)$schema['minimum']) $errors[] = ['path' => $at, 'rule' => 'minimum', 'expected' => $schema['minimum']];
            if (isset($schema['maximum']) && $n > (float)$schema['maximum']) $errors[] = ['path' => $at, 'rule' => 'maximum', 'expected' => $schema['maximum']];
            if (isset($schema['exclusiveMinimum']) && $n <= (float)$schema['exclusiveMinimum']) $errors[] = ['path' => $at, 'rule' => 'exclusiveMinimum', 'expected' => $schema['exclusiveMinimum']];
            if (isset($schema['exclusiveMaximum']) && $n >= (float)$schema['exclusiveMaximum']) $errors[] = ['path' => $at, 'rule' => 'exclusiveMaximum', 'expected' => $schema['exclusiveMaximum']];
        }
        if (is_array($value) && array_is_list($value) && (isset($schema['items']) || isset($schema['minItems']) || isset($schema['maxItems']))) {
            $n = count($value);
            if (isset($schema['minItems']) && $n < (int)$schema['minItems']) $errors[] = ['path' => $at, 'rule' => 'minItems', 'expected' => (int)$schema['minItems']];
            if (isset($schema['maxItems']) && $n > (int)$schema['maxItems']) { $errors[] = ['path' => $at, 'rule' => 'maxItems', 'expected' => (int)$schema['maxItems']]; return; }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) self::walk($item, $schema['items'], "$path/$i", $errors);
            }
        }
        if (is_array($value) && (isset($schema['properties']) || isset($schema['required']) || array_key_exists('additionalProperties', $schema))
            && ($value === [] || !array_is_list($value))) {
            $props = (array)($schema['properties'] ?? []);
            foreach ((array)($schema['required'] ?? []) as $req) {
                if (!array_key_exists((string)$req, $value)) $errors[] = ['path' => "$path/$req", 'rule' => 'required'];
            }
            foreach ($value as $k => $v) {
                $k = (string)$k;
                if (isset($props[$k])) { self::walk($v, $props[$k], "$path/$k", $errors); continue; }
                $extra = $schema['additionalProperties'] ?? true;
                if ($extra === false) $errors[] = ['path' => "$path/$k", 'rule' => 'additionalProperties'];
                elseif (is_array($extra)) self::walk($v, $extra, "$path/$k", $errors);
            }
        }
        if (isset($schema['oneOf'])) {
            $matches = 0;
            foreach ((array)$schema['oneOf'] as $alt) if (self::validate($value, (array)$alt, $path) === []) $matches++;
            if ($matches !== 1) $errors[] = ['path' => $at, 'rule' => 'oneOf', 'expected' => $matches === 0 ? 'one' : 'only_one'];
        }
    }

    private static function isType(mixed $v, string $type): bool
    {
        return match ($type) {
            'string'  => is_string($v),
            'number'  => is_int($v) || (is_float($v) && is_finite($v)),
            'integer' => is_int($v) || (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 2 ** 53),
            'boolean' => is_bool($v),
            'null'    => $v === null,
            'object'  => is_array($v) && ($v === [] || !array_is_list($v)),
            'array'   => is_array($v) && array_is_list($v),
            default   => false,
        };
    }

    /** Gleichheit wie in JSON: 1 und 1.0 sind gleich, sonst streng. */
    private static function same(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) return (float)$a === (float)$b;
        return $a === $b;
    }

    /** ECMA-Muster als PCRE (die Werkzeuge nutzen nur den gemeinsamen Kern). */
    private static function regex(string $pattern): string
    {
        return '/' . str_replace('/', '\/', $pattern) . '/u';
    }

    /**
     * Prüft ein Schema selbst: nur bekannte Schlüsselwörter, Typen und Formen.
     * Leer = gültig.
     *
     * @param array<string,mixed> $schema
     * @return list<string>
     */
    public static function check(array $schema, string $path = '#'): array
    {
        $errs = [];
        foreach ($schema as $k => $v) {
            $k = (string)$k;
            if (!in_array($k, self::KEYWORDS, true)) { $errs[] = "$path: unknown keyword '$k'"; continue; }
            switch ($k) {
                case 'type':
                    foreach ((array)$v as $t) if (!in_array($t, self::TYPES, true)) $errs[] = "$path: unknown type '" . (is_scalar($t) ? $t : '?') . "'";
                    break;
                case 'properties':
                    if (!is_array($v) || ($v !== [] && array_is_list($v))) { $errs[] = "$path: properties must be an object"; break; }
                    foreach ($v as $name => $sub) {
                        if (!is_array($sub)) { $errs[] = "$path/properties/$name: schema must be an object"; continue; }
                        array_push($errs, ...self::check($sub, "$path/properties/$name"));
                    }
                    break;
                case 'required':
                    if (!is_array($v) || !array_is_list($v)) { $errs[] = "$path: required must be a list"; break; }
                    foreach ($v as $r) if (!is_string($r) || !isset($schema['properties'][$r])) $errs[] = "$path: required '" . (is_scalar($r) ? $r : '?') . "' has no property";
                    break;
                case 'enum':
                    if (!is_array($v) || !array_is_list($v) || $v === []) $errs[] = "$path: enum must be a non-empty list";
                    break;
                case 'pattern':
                    if (!is_string($v) || @preg_match(self::regex($v), '') === false) $errs[] = "$path: invalid pattern";
                    break;
                case 'minimum': case 'maximum': case 'exclusiveMinimum': case 'exclusiveMaximum':
                    if (!is_int($v) && !is_float($v)) $errs[] = "$path: $k must be a number";
                    break;
                case 'minLength': case 'maxLength': case 'minItems': case 'maxItems':
                    if (!is_int($v) || $v < 0) $errs[] = "$path: $k must be a non-negative integer";
                    break;
                case 'items':
                    if (!is_array($v)) $errs[] = "$path: items must be a schema";
                    else array_push($errs, ...self::check($v, "$path/items"));
                    break;
                case 'additionalProperties':
                    if (is_array($v)) array_push($errs, ...self::check($v, "$path/additionalProperties"));
                    elseif (!is_bool($v)) $errs[] = "$path: additionalProperties must be false or a schema";
                    break;
                case 'oneOf':
                    if (!is_array($v) || !array_is_list($v) || $v === []) { $errs[] = "$path: oneOf must be a non-empty list"; break; }
                    foreach ($v as $i => $alt) {
                        if (!is_array($alt)) $errs[] = "$path/oneOf/$i: schema must be an object";
                        else array_push($errs, ...self::check($alt, "$path/oneOf/$i"));
                    }
                    break;
                case 'format':
                    if (!in_array($v, self::FORMATS, true)) $errs[] = "$path: unsupported format '" . (is_scalar($v) ? $v : '?') . "'";
                    break;
                case 'description': case 'title':
                    if (!is_string($v)) $errs[] = "$path: $k must be a string";
                    break;
            }
        }
        return $errs;
    }

    /**
     * Schema für die Ausgabe als JSON: `properties` (und leere Schemas) als
     * Objekt, nicht als `[]`.
     *
     * @param array<string,mixed> $schema
     */
    public static function export(array $schema): object
    {
        $out = [];
        foreach ($schema as $k => $v) {
            $out[$k] = match (true) {
                $k === 'properties' && is_array($v)                         => (object)array_map(fn($s) => self::export((array)$s), $v),
                ($k === 'items' || $k === 'additionalProperties') && is_array($v) => self::export($v),
                $k === 'oneOf' && is_array($v)                              => array_map(fn($s) => self::export((array)$s), $v),
                default                                                     => $v,
            };
        }
        return (object)$out;
    }
}
