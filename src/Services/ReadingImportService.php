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
     * @return array{imported:int,overwritten:int,skipped:int,errors:string[]}
     */
    public function importCsv(string $utility, string $meterId, string $csv): array
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
            if ($first) {
                $first = false;
                if (preg_match('/datum|date|z[äa]hler|counter/i', $line)) {
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

            $iso = $this->parseDate(trim((string)($parts[$cols['date']] ?? '')));
            if ($iso === null) {
                $skipped++;
                $errors[] = $this->i18n->t('errors.import.dateUnrecognized',
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
        $find = function (array $names) use ($header): ?int {
            foreach ($header as $i => $h) {
                $h = strtolower(trim((string)$h, " \t\"'"));
                $h = strtr($h, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue']);
                if (in_array($h, $names, true)) return $i;
            }
            return null;
        };
        $date    = $find(['datum', 'date']);
        $counter = $find(['zaehlerstand', 'zahlerstand', 'stand', 'counter', 'value', 'wert']);
        if ($date === null || $counter === null) return null;
        return [
            'date'      => $date,
            'counter'   => $counter,
            'note'      => $find(['notiz', 'note', 'bemerkung']),
            'estimated' => $find(['geschaetzt', 'geschatzt', 'estimated']),
            'meter'     => $find(['zaehler-id', 'meter_id', 'meter-id']),
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

    private function parseDate(string $s): ?string
    {
        $s = trim($s, " \t\"'");
        // v2.5.3 — kalendergültig: der 31.02. ist kein Datum.
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
            $iso = "$m[3]-$m[2]-$m[1]";
            return Dates::isIsoDate($iso) ? $iso : null;
        }
        return Dates::isIsoDate($s) ? $s : null;
    }

    private function parseNum(string $s): ?float
    {
        // Accept "12345.6", "12345,6", "12.345,6", "12,345.6", quoted or spaced.
        $raw = trim($s, " \t\"'");
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

    private function parseBool(string $s): bool
    {
        $s = strtolower(trim($s, " \t\"'"));
        return in_array($s, ['1', 'true', 'ja', 'yes', 'x', 'wahr', 'geschätzt', 'geschaetzt'], true);
    }
}
