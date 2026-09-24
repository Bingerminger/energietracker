<?php
declare(strict_types=1);

namespace Energietracker\Support;

/**
 * v2.6.0 — Textkodierung hochgeladener CSV-Dateien.
 *
 * Excel speichert „CSV (Trennzeichen-getrennt)" unter deutschem Windows als
 * Windows-1252. Ein Umlaut in einer Notiz brach den Import bisher mitten drin
 * mit HTTP 500 ab (JSON-Kodierung scheitert an ungültigem UTF-8), nachdem die
 * ersten Zeilen schon geschrieben waren.
 */
final class Encoding
{
    /**
     * UTF-8-BOM entfernen und Nicht-UTF-8 aus Windows-1252 umwandeln.
     *
     * @return array{0: string, 1: ?string} [Text, umgewandelt aus (oder null)]
     */
    public static function normalizeCsv(string $csv): array
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) $csv = substr($csv, 3);
        if (self::isUtf8($csv)) return [$csv, null];
        if (function_exists('mb_convert_encoding')) {
            return [(string)mb_convert_encoding($csv, 'UTF-8', 'Windows-1252'), 'Windows-1252'];
        }
        $converted = @iconv('Windows-1252', 'UTF-8//TRANSLIT', $csv);
        return [$converted !== false ? $converted : $csv, 'Windows-1252'];
    }

    public static function isUtf8(string $s): bool
    {
        return function_exists('mb_check_encoding')
            ? mb_check_encoding($s, 'UTF-8')
            : preg_match('//u', $s) === 1;
    }
}
