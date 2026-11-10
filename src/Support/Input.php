<?php
declare(strict_types=1);

namespace Energietracker\Support;

/**
 * v3.12.0 — Eingaben an einer Stelle prüfen: Typ, Datumsbereich, Dezimalkomma.
 *
 * Bis v3.11 prüfte jeder Schreibpfad selbst, und die meisten casteten nur:
 *   - Eine Liste oder ein Objekt statt Text (`"note": {"a": 1}`) endete in
 *     `(string)$input['note']` als PHP-Warnung „Array to string conversion“,
 *     die der Fehler-Handler in eine Ausnahme wandelt: HTTP 500 statt 400, auf
 *     gut einem Dutzend Schreibrouten. Ein PATCH speicherte die Notiz sogar als
 *     Objekt, danach scheiterte der CSV-Export.
 *   - `(float)"12,5"` wurde still 12, `(float)"abc"` 0 (Preis je Ablesung,
 *     Wohnfläche), ohne Fehlermeldung.
 *   - Ein Datum im Jahr 0001 war ein gültiger Kalendertag (s. Dates::MIN_INPUT_YEAR).
 *
 * Die Helfer werfen eine LocalizedException: 400 mit stabilem Code, übersetzt in
 * der Sprache der Anfrage (ErrorHandler).
 */
final class Input
{
    /**
     * Textfeld aus einem Eingabe-Objekt. Fehlt es oder ist es null, gilt
     * `$default`. Eine Zahl wird Text (Seriennummer 12345). Eine Liste, ein Objekt
     * oder ein Wahrheitswert → 400 `errors.common.textExpected`.
     *
     * @param array<array-key,mixed> $in
     */
    public static function text(array $in, string $key, ?string $default = ''): ?string
    {
        if (!array_key_exists($key, $in) || $in[$key] === null) return $default;
        $v = $in[$key];
        if (is_string($v)) return $v;
        if (is_int($v) || (is_float($v) && is_finite($v))) return (string)$v;
        throw self::textExpected($key);
    }

    /**
     * Mehrere Textfelder auf einmal prüfen (nur den Typ, nichts umwandeln): für
     * Schreibpfade, die die Felder an mehreren Stellen lesen.
     *
     * @param array<array-key,mixed> $in
     * @param list<string> $keys
     */
    public static function assertTexts(array $in, array $keys): void
    {
        foreach ($keys as $k) self::text($in, $k, null);
    }

    public static function textExpected(string $field): LocalizedException
    {
        return new LocalizedException('errors.common.textExpected', ['field' => $field], "field $field: text expected");
    }

    /** Ein Feld, das ein Objekt oder eine Liste sein muss, kam als Text oder Zahl. */
    public static function objectExpected(string $field): LocalizedException
    {
        return new LocalizedException('errors.common.objectExpected', ['field' => $field], "field $field: object expected");
    }

    /**
     * Jahr eines (formal gültigen) Datums im Eingabebereich, sonst 400
     * `errors.common.dateOutOfRange`. Die Form prüft der Aufrufer vorher mit
     * seinem eigenen Schlüssel (errors.reading.dateInvalid …).
     */
    public static function assertDateInRange(string $date, string $field = 'date'): void
    {
        if (Dates::isIsoDate($date) && !Dates::inInputRange($date)) {
            throw new LocalizedException('errors.common.dateOutOfRange', [
                'field' => $field, 'date' => $date,
                'min' => (string)Dates::MIN_INPUT_YEAR, 'max' => (string)Dates::MAX_INPUT_YEAR,
            ], "field $field: date $date out of range");
        }
    }

    /**
     * Dezimalzahl aus einer Eingabe: Zahl, „12.5“ oder „12,5“. Das Komma gilt
     * nur als Dezimaltrenner, wenn es das einzige Trennzeichen ist; „1.234,5“
     * und „1,234.5“ sind mehrdeutig und damit unlesbar (null), ebenso Text,
     * Liste, Wahrheitswert, NaN und ±INF. Leerraum außen herum zählt nicht.
     */
    public static function decimal(mixed $v): ?float
    {
        if (is_int($v)) return (float)$v;
        if (is_float($v)) return is_finite($v) ? $v : null;
        if (!is_string($v)) return null;
        $s = trim($v);
        if (preg_match('/^[+-]?\d+,\d+$/D', $s)) $s = str_replace(',', '.', $s);
        if (!preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/D', $s)) return null;
        $f = (float)$s;
        return is_finite($f) ? $f : null;
    }

    /**
     * Wahrheitswert aus einer Eingabe: true/false, 1/0, „true“/„false“, „1“/„0“,
     * „ja“/„nein“, „yes“/„no“, „on“/„off“ und leer (= false). Sonst null — der
     * Aufrufer meldet den Fehler. Bisher machte `(bool)"nein"` daraus true.
     */
    public static function bool(mixed $v): ?bool
    {
        if (is_bool($v)) return $v;
        if ($v === null || $v === '' || $v === 0 || $v === 1) return (bool)$v;
        if (!is_string($v)) return null;
        $s = strtolower(trim($v));
        if (in_array($s, ['1', 'true', 'ja', 'yes', 'on'], true)) return true;
        if (in_array($s, ['0', 'false', 'nein', 'no', 'off', ''], true)) return false;
        return null;
    }
}
