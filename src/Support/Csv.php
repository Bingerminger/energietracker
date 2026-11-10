<?php
declare(strict_types=1);

namespace Energietracker\Support;

/**
 * v3.12.0 — CSV-Rundreise: eigene Exporte verlustfrei wieder einlesen.
 *
 * Bis v3.11 teilten die Importe die Datei an jedem Zeilenumbruch, bevor sie die
 * Felder lasen. Der eigene Export setzt eine mehrzeilige Notiz aber korrekt in
 * Anführungszeichen; beim Wiedereinlesen zerfiel die Zeile: Die Notiz endete nach
 * der ersten Zeile, „Geschätzt“ stand im abgetrennten Rest und kippte auf nein.
 * Und das Apostroph, das der Export gegen Formeln voranstellt, blieb beim Import
 * als Teil der Notiz stehen („'=1+1“).
 *
 * Formelschutz und Rücknahme folgen einer Regel ({@see formulaLike()}): Was der
 * Import zurücknimmt, schützt der Export auch — sonst wäre eine Notiz, die mit
 * „'=“ beginnt, nach der Rundreise eine andere.
 */
final class Csv
{
    /**
     * Datensätze einer CSV-Datei: getrennt an Zeilenumbrüchen außerhalb von
     * Anführungszeichen, mit der Zeilennummer, in der der Datensatz beginnt.
     * Ein Anführungszeichen öffnet ein Feld nur am Feldanfang (Zeilenanfang oder
     * nach `;`, `,`, Tabulator); `""` darin ist ein Anführungszeichen. Bleibt
     * eines offen (von Hand geschriebene Datei), gilt wie bisher jede Zeile für sich.
     *
     * @return list<array{line:int, text:string}>
     */
    public static function records(string $csv): array
    {
        if (!str_contains($csv, '"')) return self::physicalLines($csv);
        $out = [];
        $buf = '';
        $line = 1;
        $start = 1;
        $inQuotes = false;
        $fieldStart = true;
        $n = strlen($csv);
        for ($i = 0; $i < $n; $i++) {
            $ch = $csv[$i];
            if ($inQuotes) {
                if ($ch === '"') {
                    if ($i + 1 < $n && $csv[$i + 1] === '"') { $buf .= '""'; $i++; continue; }
                    $inQuotes = false;
                } elseif ($ch === "\n" || ($ch === "\r" && ($i + 1 >= $n || $csv[$i + 1] !== "\n"))) {
                    $line++;
                }
                $buf .= $ch;
                continue;
            }
            if ($ch === "\r" || $ch === "\n") {
                if ($ch === "\r" && $i + 1 < $n && $csv[$i + 1] === "\n") $i++;
                $out[] = ['line' => $start, 'text' => $buf];
                $buf = '';
                $start = ++$line;
                $fieldStart = true;
                continue;
            }
            if ($ch === '"' && $fieldStart) { $inQuotes = true; $fieldStart = false; $buf .= $ch; continue; }
            if ($ch === ';' || $ch === ',' || $ch === "\t") $fieldStart = true;
            elseif ($ch !== ' ') $fieldStart = false;
            $buf .= $ch;
        }
        if ($inQuotes) return self::physicalLines($csv);
        if ($buf !== '') $out[] = ['line' => $start, 'text' => $buf];
        return $out;
    }

    /** @return list<array{line:int, text:string}> jede Zeile für sich (Verhalten bis v3.11) */
    private static function physicalLines(string $csv): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $i => $l) $out[] = ['line' => $i + 1, 'text' => $l];
        if ($out !== [] && $out[count($out) - 1]['text'] === '') array_pop($out);
        return $out;
    }

    /**
     * Sähe eine Tabelle den Text als Formel? `=`, `+`, `-`, `@`, Tabulator oder
     * Wagenrücklauf am Anfang, auch nach Leerraum oder Apostrophen.
     */
    public static function formulaLike(string $s): bool
    {
        return preg_match("/^'*\\s*[=+\\-@\\t\\r]/", $s) === 1;
    }

    /**
     * Gegenstück zum Formelschutz des Exports: ein vorangestelltes Apostroph vor
     * einem formelartigen Rest fällt weg („'=1+1“ → „=1+1“, „' =1“ → „ =1“).
     */
    public static function unguard(string $cell): string
    {
        return str_starts_with($cell, "'") && self::formulaLike(substr($cell, 1)) ? substr($cell, 1) : $cell;
    }
}
