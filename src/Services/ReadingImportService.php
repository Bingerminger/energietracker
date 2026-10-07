<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Support\Dates;
use Energietracker\Http\NotFoundException;
use Energietracker\Support\Encoding;

/**
 * Bulk import of meter readings (F-06, v1.1.0).
 *
 * Two layers:
 *
 *   importCsv()  — parses a delimited text body and hands the rows to
 *                  importRows(). This is the layer the CSV upload uses.
 *
 *   importRows() — source-agnostic core. Takes already-parsed rows
 *                  ([{date, counter, note?, is_estimated?}, …]) and writes
 *                  them to a meter, overwriting any existing reading on the
 *                  same date. A future smart-meter sync (push / pull) can
 *                  reuse importRows() directly without touching CSV parsing.
 *
 * Overwrite semantics: if a reading already exists for (meter, date) it is
 * updated in place; otherwise a new reading is created. The result report
 * counts `imported` (new), `overwritten` (updated) and `skipped` (rows that
 * could not be parsed), plus a per-row `errors` list.
 *
 * CSV format (header row optional, auto-detected):
 *   datum;zählerstand;notiz;geschätzt
 *   01.01.2023;12345.6;Jahresanfang;false
 *   2023-03-01;12567,8;;
 *
 * - Separator: ';' preferred, ',' as fallback.
 * - Date:      DD.MM.YYYY or ISO YYYY-MM-DD.
 * - Counter:   German decimal comma accepted.
 * - geschätzt: true/false/1/0/ja/nein/x/'' (empty = false).
 */
final class ReadingImportService
{
    public function __construct(
        private ReadingService $readings,
        private MeterService $meters,
        private I18nService $i18n,
    ) {}

    /**
     * Parse a CSV body and import it into the given meter.
     *
     * v2.12.0 (Review UI-23) — `$dryRun`: nur lesen, nichts schreiben. Die
     * Antwort trägt dann zusätzlich `rows` (die gelesenen Zeilen) und
     * `dry_run: true`; die Oberfläche zeigt daraus eine Vorschau mit
     * Rückgang, Sprung, Zukunft und Überschreiben, bevor importiert wird.
     *
     * @return array{imported:int,overwritten:int,skipped:int,errors:string[]}
     */
    public function importCsv(string $utility, string $meterId, string $csv, bool $dryRun = false): array
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
        }
        if (trim($csv) === '') {
            throw new \InvalidArgumentException($this->i18n->t('errors.import.emptyCsv'));
        }
        // v2.6.0 — BOM und Windows-1252 (Excel unter deutschem Windows)
        [$csv, $convertedFrom] = Encoding::normalizeCsv($csv);

        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $rows = [];
        $errors = [];
        $skipped = 0;
        $otherMeter = 0;
        // Positionen: Datum, Stand, Notiz, geschätzt — oder aus der Kopfzeile.
        $cols = ['date' => 0, 'counter' => 1, 'note' => 2, 'estimated' => 3, 'meter' => null];
        $sep = null;
        $first = true;

        foreach ($lines as $lineNo => $raw) {
            $line = trim($raw);
            if ($line === '') continue;
            $sep ??= str_contains($line, ';') ? ';' : ',';
            // str_getcsv statt explode: Der eigene Export setzt Zellen mit
            // Semikolon in Anführungszeichen (Notizen).
            $parts = str_getcsv($line, $sep, '"', '');

            // Kopfzeile auf der ersten nicht leeren Zeile: Spalten per Name.
            // v2.6.0 — so passt auch der eigene readings.csv-Export
            // (Zaehler-ID;Zaehler;Geraet-ID;Datum;Zaehlerstand;…), der sich
            // bisher nicht wieder einlesen ließ.
            // v3.1.0 (Review I18N-11) — Kopf ist, was nicht mit Datum oder Zahl
            // beginnt; die Spaltennamen kennt der Import aus allen Sprachkatalogen.
            if ($first) {
                $first = false;
                if ($this->isHeader($parts)) {
                    $cols = $this->columnsFromHeader($parts) ?? $cols;
                    continue;
                }
            }

            if (count($parts) < 2) {
                $skipped++;
                $errors[] = $this->i18n->t('errors.import.tooFewColumns', ['line' => $lineNo + 1]);
                continue;
            }
            // Export mehrerer Zähler: nur die Zeilen dieses Zählers übernehmen.
            if ($cols['meter'] !== null && trim((string)($parts[$cols['meter']] ?? '')) !== $meterId) {
                $otherMeter++;
                continue;
            }

            $iso = Dates::parseUserDate(trim((string)($parts[$cols['date']] ?? '')), $dateError);
            if ($iso === null) {
                $skipped++;
                $errors[] = $this->i18n->t($dateError === 'monthFirst' ? 'errors.import.dateMonthFirst' : 'errors.import.dateUnrecognized',
                    ['line' => $lineNo + 1, 'value' => trim((string)($parts[$cols['date']] ?? ''))]);
                continue;
            }

            $counter = $this->parseNum(trim((string)($parts[$cols['counter']] ?? '')));
            if ($counter === null) {
                $skipped++;
                $errors[] = $this->i18n->t('errors.import.counterNotNumeric',
                    ['line' => $lineNo + 1, 'value' => trim((string)($parts[$cols['counter']] ?? ''))]);
                continue;
            }

            $rows[] = [
                'line'         => $lineNo + 1,
                'date'         => $iso,
                'counter'      => $counter,
                'note'         => $cols['note'] !== null && isset($parts[$cols['note']]) ? trim((string)$parts[$cols['note']]) : '',
                'is_estimated' => $cols['estimated'] !== null && isset($parts[$cols['estimated']])
                    ? $this->parseBool(trim((string)$parts[$cols['estimated']])) : false,
            ];
        }

        if ($dryRun) {
            if (!$this->meters->get($utility, $meterId)) {
                throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
            }
            $report = [
                'imported'    => 0,
                'overwritten' => 0,
                'skipped'     => $skipped,
                'errors'      => $errors,
                'dry_run'     => true,
                'rows'        => $rows,
            ];
            if ($convertedFrom !== null) $report['encoding_converted_from'] = $convertedFrom;
            if ($otherMeter > 0) $report['other_meter_rows'] = $otherMeter;
            return $report;
        }

        $report = $this->importRows($utility, $meterId, $rows);
        $report['skipped'] += $skipped;
        $report['errors']   = array_merge($errors, $report['errors']);
        if ($convertedFrom !== null) $report['encoding_converted_from'] = $convertedFrom;
        if ($otherMeter > 0) $report['other_meter_rows'] = $otherMeter;
        return $report;
    }

    /**
     * Spaltenpositionen aus einer Kopfzeile. Null, wenn Datum oder Stand
     * fehlen — dann gelten die festen Positionen.
     *
     * @param list<string|null> $header
     * @return array{date:int,counter:int,note:?int,estimated:?int,meter:?int}|null
     */
    private function columnsFromHeader(array $header): ?array
    {
        // v3.1.0 — dazu die Namen aus csvLocal.readings.* aller Sprachen
        $find = function (array $names, string $localKey) use ($header): ?int {
            $names = array_merge($names, array_map([self::class, 'fold'], array_values($this->i18n->valuesInAllLanguages($localKey))));
            foreach ($header as $i => $h) {
                if (in_array(self::fold((string)$h), $names, true)) return $i;
            }
            return null;
        };
        $date    = $find(['datum', 'date'], 'csvLocal.readings.date');
        $counter = $find(['zaehlerstand', 'zahlerstand', 'stand', 'counter', 'value', 'wert'], 'csvLocal.readings.reading');
        if ($date === null || $counter === null) return null;
        return [
            'date'      => $date,
            'counter'   => $counter,
            'note'      => $find(['notiz', 'note', 'bemerkung'], 'csvLocal.readings.note'),
            'estimated' => $find(['geschaetzt', 'geschatzt', 'estimated'], 'csvLocal.readings.estimated'),
            'meter'     => $find(['zaehler-id', 'meter_id', 'meter-id'], 'csvLocal.readings.meterId'),
        ];
    }

    /**
     * Source-agnostic import core. Writes already-parsed rows to a meter,
     * overwriting any existing reading on the same date.
     *
     * Each row: ['date' => 'YYYY-MM-DD', 'counter' => float,
     *            'note' => ?string, 'is_estimated' => ?bool]
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{imported:int,overwritten:int,skipped:int,errors:string[]}
     */
    public function importRows(string $utility, string $meterId, array $rows): array
    {
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) {
            throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
        }

        $valid = [];
        $skipped = 0;
        $errors = [];
        foreach ($rows as $i => $row) {
            if ((string)($row['date'] ?? '') === '' || !array_key_exists('counter', $row)) {
                $skipped++;
                $errors[] = $this->i18n->t('errors.import.rowIncomplete', ['line' => (int)($row['line'] ?? $i + 1)]);
                continue;
            }
            $valid[] = $row + ['line' => $i + 1];
        }

        // v2.6.0 — ein Lese- und ein Schreibvorgang statt je Zeile (linear).
        $report = $this->readings->upsertMany($utility, $meterId, $valid);
        $report['skipped'] += $skipped;
        $report['errors']   = array_merge($errors, $report['errors']);
        return $report;
    }

    /**
     * v3.1.0 — Spaltenname zum Vergleich: klein, ohne Akzente und Umlaute, ohne
     * Einheit in Klammern („Prix (ct)" → „prix", „Zählerstand" → „zaehlerstand").
     */
    public static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s, " \t\"'\u{FEFF}"));
        $s = (string)preg_replace('/\s*\(.*\)\s*$/u', '', $s);
        return strtr($s, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u',
        ]);
    }

    /** @param list<string|null> $parts */
    private function isHeader(array $parts): bool
    {
        $first = trim((string)($parts[0] ?? ''), " \t\"'");
        return preg_match('/\p{L}/u', implode('', array_map('strval', $parts))) === 1
            && Dates::parseUserDate($first) === null && $this->parseNum($first) === null;
    }

    public static function parseNum(string $s): ?float
    {
        // Accept "12345.6", "12345,6", "12.345,6", "12,345.6", quoted or spaced.
        $raw = trim($s, " \t\"'");
        // v2.12.0 — Leerzeichen und Apostroph als Tausendertrenner
        // („1 395,2" in Frankreich, „1'395.2" in der Schweiz)
        $raw = str_replace([' ', "\u{00A0}", "\u{202F}", "'", '’'], '', $raw);
        if ($raw === '') return null;
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            // The rightmost separator is the decimal separator.
            if (strrpos($raw, ',') > strrpos($raw, '.')) {
                $raw = str_replace('.', '', $raw);   // '.' = thousands
                $raw = str_replace(',', '.', $raw);  // ',' = decimal
            } else {
                $raw = str_replace(',', '', $raw);   // ',' = thousands
            }
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }
        return is_numeric($raw) ? (float)$raw : null;
    }

    /** v3.1.0 — „ja" in jeder Sprache des Katalogs (oui, sì, sí, sim …), „geschätzt" ebenso. */
    private function parseBool(string $s): bool
    {
        $yes = array_map([self::class, 'fold'], array_merge(
            ['1', 'true', 'ja', 'yes', 'x', 'wahr', 'geschätzt'],
            array_values($this->i18n->valuesInAllLanguages('common.yes')),
            array_values($this->i18n->valuesInAllLanguages('csvLocal.readings.estimated')),
        ));
        return in_array(self::fold($s), $yes, true);
    }
}
