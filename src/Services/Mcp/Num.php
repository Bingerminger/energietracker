<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp;

use Energietracker\Support\Dates;

/**
 * v3.12.0 (F1037) — Zahl-Objekt: eine Kennzahl mit Einheit und Herkunft (Konzept §3.1).
 *
 *   {"value": 412.5, "unit": "EUR", "basis": "estimated", "range": [380.0, 445.0],
 *    "as_of": "2026-11-03", "parts": {"measured": 0.71, "estimated": 0.29},
 *    "uncertain": ["few_measured"], "derived": true, "sign": "positive_is_back_payment"}
 *
 * Pflicht sind `value`, `unit`, `basis`. `range` nur mit Band, `parts` bei Summen
 * aus mehreren Herkünften (Anteil am Betrag, 0 bis 1), `uncertain` die Gründe einer
 * Unsicherheit (Schlüssel, kein Text), `derived` für Rechenergebnisse; dann ist
 * `basis` die schwächste Herkunft der Bestandteile (`derived()` setzt sie selbst).
 * Gerundet wie die Oberfläche: Beträge auf 2, Mengen auf 3 Stellen.
 *
 * Ein Formfehler ist ein Programmierfehler (\LogicException), keine Antwort an die KI.
 */
final class Num
{
    public const MONEY_DECIMALS = 2;
    public const QUANTITY_DECIMALS = 3;
    /** Schlüssel der Optionen in der Reihenfolge der Ausgabe */
    private const OPTIONS = ['range', 'as_of', 'parts', 'uncertain', 'derived', 'sign', 'decimals'];

    /**
     * @param array{range?:array{0:float|int,1:float|int}, as_of?:string, parts?:array<string,float|int>,
     *              uncertain?:list<string>, derived?:bool, sign?:string, decimals?:int} $opts
     * @return array<string,mixed>
     */
    public static function of(float|int $value, string $unit, string $basis, array $opts = []): array
    {
        foreach (array_keys($opts) as $k) {
            if (!in_array($k, self::OPTIONS, true)) throw new \LogicException("mcp: unknown Num option '$k'");
        }
        if (!is_finite((float)$value)) throw new \LogicException('mcp: Num value must be finite');
        if ($unit === '') throw new \LogicException('mcp: Num needs a unit');
        if (!Provenance::isBasis($basis)) throw new \LogicException("mcp: unknown basis '$basis'");
        $dec = (int)($opts['decimals'] ?? self::QUANTITY_DECIMALS);
        $out = ['value' => round((float)$value, $dec), 'unit' => $unit, 'basis' => $basis];

        if (isset($opts['range'])) {
            $r = $opts['range'];
            if (!is_array($r) || count($r) !== 2 || !is_numeric($r[0] ?? null) || !is_numeric($r[1] ?? null) || (float)$r[0] > (float)$r[1]) {
                throw new \LogicException('mcp: Num range must be [low, high] with low <= high');
            }
            $out['range'] = [round((float)$r[0], $dec), round((float)$r[1], $dec)];
        }
        if (isset($opts['as_of'])) {
            if (!Dates::isIsoDate($opts['as_of'])) throw new \LogicException('mcp: Num as_of must be YYYY-MM-DD');
            $out['as_of'] = (string)$opts['as_of'];
        }
        if (!empty($opts['parts'])) {
            $parts = [];
            $sum = 0.0;
            // Reihenfolge wie BASES, damit gleiche Daten gleiche Ausgaben ergeben
            foreach (Provenance::BASES as $b) {
                if (!array_key_exists($b, $opts['parts'])) continue;
                $share = $opts['parts'][$b];
                if (!is_numeric($share) || (float)$share < 0 || (float)$share > 1) {
                    throw new \LogicException("mcp: Num part '$b' must be a share between 0 and 1");
                }
                $parts[$b] = round((float)$share, 2);
                $sum += (float)$share;
            }
            if (count($parts) !== count($opts['parts'])) throw new \LogicException('mcp: Num parts must be keyed by basis');
            if (abs($sum - 1.0) > 0.011) throw new \LogicException('mcp: Num parts must add up to 1');
            $present = array_keys(array_filter($parts, fn($s) => $s > 0));
            if ($present !== [] && Provenance::weakest(...$present) !== $basis) {
                throw new \LogicException('mcp: Num basis must be the weakest of its parts');
            }
            $out['parts'] = $parts;
        }
        if (!empty($opts['uncertain'])) {
            if (!array_is_list($opts['uncertain'])) throw new \LogicException('mcp: Num uncertain must be a list');
            $out['uncertain'] = array_values(array_map('strval', $opts['uncertain']));
        }
        if (!empty($opts['derived'])) $out['derived'] = true;
        if (isset($opts['sign'])) {
            if (!in_array($opts['sign'], Provenance::SIGNS, true)) throw new \LogicException('mcp: unknown Num sign');
            $out['sign'] = $opts['sign'];
        }
        return $out;
    }

    /**
     * Betrag in der Währung der Installation (ISO-Code wie `EUR`), auf Cent.
     *
     * @param array<string,mixed> $opts wie of()
     * @return array<string,mixed>
     */
    public static function money(float|int $value, string $currency, string $basis, array $opts = []): array
    {
        return self::of($value, $currency, $basis, ['decimals' => self::MONEY_DECIMALS] + $opts);
    }

    /**
     * Rechenergebnis aus Bestandteilen verschiedener Herkunft: `basis` ist die
     * schwächste der Teile mit Anteil > 0, `derived: true`.
     *
     * @param array<string,float|int> $parts Herkunft → Anteil am Wert (Summe 1)
     * @param array<string,mixed> $opts wie of()
     * @return array<string,mixed>
     */
    public static function derived(float|int $value, string $unit, array $parts, array $opts = []): array
    {
        $present = array_keys(array_filter($parts, fn($s) => is_numeric($s) && (float)$s > 0));
        if ($present === []) throw new \LogicException('mcp: Num::derived needs at least one part');
        return self::of($value, $unit, Provenance::weakest(...$present), ['parts' => $parts, 'derived' => true] + $opts);
    }

    /**
     * JSON-Schema eines Zahl-Objekts für `outputSchema`.
     *
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
        $share = ['type' => 'number', 'minimum' => 0, 'maximum' => 1];
        return [
            'type'       => 'object',
            'properties' => [
                'value'     => ['type' => 'number'],
                'unit'      => ['type' => 'string', 'minLength' => 1],
                'basis'     => ['type' => 'string', 'enum' => Provenance::BASES],
                'range'     => ['type' => 'array', 'items' => ['type' => 'number'], 'minItems' => 2, 'maxItems' => 2],
                'as_of'     => ['type' => 'string', 'format' => 'date'],
                'parts'     => ['type' => 'object', 'properties' => array_fill_keys(Provenance::BASES, $share),
                                'additionalProperties' => false],
                'uncertain' => ['type' => 'array', 'items' => ['type' => 'string']],
                'derived'   => ['type' => 'boolean'],
                'sign'      => ['type' => 'string', 'enum' => Provenance::SIGNS],
                // v3.12.0 — Saldo in Klartext (Provenance::meaning, ReadSupport::withMeaning): Modelle lesen ein Vorzeichen falsch
                'meaning'   => ['type' => 'string', 'enum' => Provenance::MEANINGS],
                'text'      => ['type' => 'string'],
            ],
            'required'             => ['value', 'unit', 'basis'],
            'additionalProperties' => false,
        ];
    }

    /** Ist `$v` ein Zahl-Objekt (Form wie of())? Für Prüfungen der Ausgaben. */
    public static function isNum(mixed $v): bool
    {
        return is_array($v) && array_key_exists('value', $v) && (is_int($v['value']) || is_float($v['value']))
            && is_string($v['unit'] ?? null) && Provenance::isBasis($v['basis'] ?? null);
    }
}
