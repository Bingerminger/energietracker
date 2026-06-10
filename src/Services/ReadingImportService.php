<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

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

        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $rows = [];
        $errors = [];
        $skipped = 0;

        foreach ($lines as $lineNo => $raw) {
            $line = trim($raw);
            if ($line === '') continue;

            // Header detection on the first non-empty line.
            if ($lineNo === 0 && preg_match('/datum|z[äa]hler|counter/i', $line)) {
                continue;
            }

            $parts = str_contains($line, ';') ? explode(';', $line) : explode(',', $line);
            if (count($parts) < 2) {
                $skipped++;
                $errors[] = 'Zeile ' . ($lineNo + 1) . ': zu wenige Spalten';
                continue;
            }

            $iso = $this->parseDate(trim((string)($parts[0] ?? '')));
            if ($iso === null) {
                $skipped++;
                $errors[] = 'Zeile ' . ($lineNo + 1) . ': Datum nicht erkannt (' . trim((string)$parts[0]) . ')';
                continue;
            }

            $counter = $this->parseNum(trim((string)($parts[1] ?? '')));
            if ($counter === null) {
                $skipped++;
                $errors[] = 'Zeile ' . ($lineNo + 1) . ': Zählerstand nicht numerisch (' . trim((string)$parts[1]) . ')';
                continue;
            }

            $rows[] = [
                'date'         => $iso,
                'counter'      => $counter,
                'note'         => isset($parts[2]) ? trim((string)$parts[2]) : '',
                'is_estimated' => isset($parts[3]) ? $this->parseBool(trim((string)$parts[3])) : false,
            ];
        }

        $report = $this->importRows($utility, $meterId, $rows);
        $report['skipped'] += $skipped;
        $report['errors']   = array_merge($errors, $report['errors']);
        return $report;
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
            throw new \InvalidArgumentException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
        }

        // Existing readings of this meter, indexed by date → id, so we can
        // decide create-vs-overwrite without re-reading per row.
        $existing = [];
        foreach ($this->readings->list($utility, $meterId) as $r) {
            if (isset($r['date'], $r['id'])) {
                $existing[$r['date']] = $r['id'];
            }
        }

        $imported = 0;
        $overwritten = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $date = (string)($row['date'] ?? '');
            if ($date === '' || !array_key_exists('counter', $row)) {
                $skipped++;
                $errors[] = 'Zeile ' . ($i + 1) . ': Datum oder Zählerstand fehlt';
                continue;
            }
            $payload = [
                'meter_id'     => $meterId,
                'date'         => $date,
                'counter'      => (float)$row['counter'],
                'note'         => (string)($row['note'] ?? ''),
                'is_estimated' => !empty($row['is_estimated']),
            ];
            try {
                if (isset($existing[$date])) {
                    $this->readings->update($utility, $existing[$date], $payload);
                    $overwritten++;
                } else {
                    $created = $this->readings->create($utility, $payload);
                    $existing[$date] = $created['id'] ?? null;
                    $imported++;
                }
            } catch (\InvalidArgumentException $e) {
                $skipped++;
                $errors[] = 'Zeile ' . ($i + 1) . ' (' . $date . '): ' . $e->getMessage();
            }
        }

        return [
            'imported'    => $imported,
            'overwritten' => $overwritten,
            'skipped'     => $skipped,
            'errors'      => $errors,
        ];
    }

    private function parseDate(string $s): ?string
    {
        $s = trim($s, " \t\"'");
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
            return "$m[3]-$m[2]-$m[1]";
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s)) {
            return $s;
        }
        return null;
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
