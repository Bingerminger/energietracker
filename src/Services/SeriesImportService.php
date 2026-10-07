<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;
use Energietracker\Support\Dates;
use Energietracker\Support\Encoding;
use Energietracker\Support\LocalizedException;

/**
 * v3.1.0 (Paket H8, MKT-19) — Zeitreihen aus Portalen (Netzbetreiber,
 * Messstellenbetreiber, Wechselrichter, Wärmepumpe) mit Spaltenzuordnung
 * einlesen und zu Tageswerten verdichten. Keine eigenen Protokolle — eine
 * Datei, die man im Portal herunterlädt.
 *
 * mapping (Spalten 0-basiert):
 *   delimiter       ';' | ',' | "\t" (leer = erkennen)
 *   skip_rows       Zeilen vor den Daten (Kopf), Standard 0
 *   date_col        Spalte mit Datum oder Datum und Uhrzeit
 *   time_col        Spalte mit der Uhrzeit, wenn getrennt (sonst null)
 *   tz              Zeitzone der Stempel ohne Angabe (Standard die der Installation)
 *   value_col       Spalte mit dem Wert
 *   value_kind      counter (Zählerstand: letzter Wert je Tag) |
 *                   consumption (Verbrauch je Intervall: Summe je Tag)
 *   unit_factor     Faktor auf die Werte, z. B. 0,001 für Wh → kWh (Standard 1)
 *   interval_stamp  start | end — ein Intervall, das um 00:00 endet, gehört zum Vortag
 *   start_counter   Stand zu Beginn des ersten Tages (nur consumption auf Zählerständen)
 *
 * Ergebnis:
 *   counter                       → Ablesungen je Tag (Überschreiben je Datum)
 *   consumption, Zeitraum-Zähler  → Tageszeiträume (wie der Zeitraum-Import)
 *   consumption, Zählerstände     → Stände, aufsummiert ab einem Anker: einer
 *     Ablesung am ersten Tag oder `start_counter`. Der Stand am Ende eines
 *     Tages steht am Folgetag (00:00). Ohne Anker 400 errors.import.anchorMissing.
 */
final class SeriesImportService
{
    public function __construct(
        private ReadingImportService $import,
        private ReadingService $readings,
        private MeterService $meters,
        private PeriodService $periods,
        private SettingsService $settings,
        private I18nService $i18n,
    ) {}

    /** @return array<string,mixed> */
    public function import(string $utility, string $meterId, string $csv, array $mapping, bool $dryRun = false): array
    {
        if (!Utilities::exists($utility) || !Utilities::isCumulative($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
        }
        $meter = $this->meters->get($utility, $meterId)
            ?? throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
        if (trim($csv) === '') throw new \InvalidArgumentException($this->i18n->t('errors.import.emptyCsv'));
        $m = $this->mapping($mapping);
        [$csv] = Encoding::normalizeCsv($csv);
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $tz = new \DateTimeZone($m['tz']);

        $days = []; $read = 0; $skipped = 0; $errors = [];
        foreach ($lines as $no => $raw) {
            if ($no < $m['skip_rows'] || trim($raw) === '') continue;
            $sep = $m['delimiter'] ?? (str_contains($raw, ';') ? ';' : (str_contains($raw, "\t") ? "\t" : ','));
            $cells = str_getcsv($raw, $sep, '"', '');
            $date = $this->dayOf((string)($cells[$m['date_col']] ?? ''), $m['time_col'] !== null ? (string)($cells[$m['time_col']] ?? '') : '', $tz, $m['interval_stamp']);
            $value = ReadingImportService::parseNum(trim((string)($cells[$m['value_col']] ?? '')));
            if ($date === null || $value === null) {
                $skipped++;
                if (count($errors) < 20) $errors[] = $this->i18n->t('errors.import.seriesRow', ['line' => $no + 1, 'value' => mb_substr(trim($raw), 0, 60)]);
                continue;
            }
            $value *= $m['unit_factor'];
            $read++;
            if ($m['value_kind'] === 'counter') $days[$date] = $value;           // letzter Wert des Tages
            else $days[$date] = ($days[$date] ?? 0.0) + $value;                 // Summe der Intervalle
        }
        if ($days === []) throw new LocalizedException('errors.import.mappingInvalid', [], 'no rows');
        ksort($days);
        $result = ['rows_read' => $read, 'skipped' => $skipped, 'errors' => $errors, 'days' => count($days),
                   'from' => array_key_first($days), 'to' => array_key_last($days),
                   'total' => $m['value_kind'] === 'consumption' ? round(array_sum($days), 3) : null,
                   'value_kind' => $m['value_kind'],
                   'preview' => array_map(fn($d, $v) => ['date' => $d, 'value' => round($v, 3)], array_slice(array_keys($days), 0, 10), array_slice(array_values($days), 0, 10))];

        // Zeitraum-Zähler: Tageszeiträume über den Zeitraum-Import
        if (($meter['capture'] ?? 'counter') === 'period') {
            if ($m['value_kind'] !== 'consumption') throw new LocalizedException('errors.import.mappingInvalid', [], 'counter on period meter');
            $text = implode("\n", array_map(fn($d, $v) => "$d;$d;" . round($v, 4), array_keys($days), array_values($days)));
            $r = $this->periods->importCsv($utility, $meterId, $text, $dryRun);
            return $result + ['target' => 'periods', 'result' => $r];
        }

        // Zählerstände
        if ($m['value_kind'] === 'counter') {
            $rows = array_map(fn($d, $v) => ['date' => $d, 'counter' => round($v, 4), 'note' => '', 'is_estimated' => false],
                array_keys($days), array_values($days));
        } else {
            $first = (string)array_key_first($days);
            $anchor = $m['start_counter'];
            if ($anchor === null) {
                foreach ($this->readings->list($utility, $meterId) as $r) {
                    if (($r['date'] ?? '') === $first && empty($r['is_future'])) $anchor = (float)$r['counter'];
                }
            }
            if ($anchor === null) throw new LocalizedException('errors.import.anchorMissing', ['date' => $first], 'anchor missing');
            $rows = []; $cum = $anchor;
            foreach ($days as $d => $v) {
                $cum += $v;
                $rows[] = ['date' => date('Y-m-d', (int)strtotime("$d +1 day")), 'counter' => round($cum, 4), 'note' => '', 'is_estimated' => false];
            }
            if ($m['start_counter'] !== null) array_unshift($rows, ['date' => $first, 'counter' => round($anchor, 4), 'note' => '', 'is_estimated' => false]);
        }
        if ($dryRun) return $result + ['target' => 'readings', 'readings' => count($rows), 'dry_run' => true];
        return $result + ['target' => 'readings', 'readings' => count($rows), 'result' => $this->import->importRows($utility, $meterId, $rows)];
    }

    /** @return array<string,mixed> geprüfte Zuordnung */
    private function mapping(array $in): array
    {
        $int = function (string $k, ?int $def) use ($in): ?int {
            $v = $in[$k] ?? $def;
            if ($v === null || $v === '') return $def;
            if (!is_numeric($v) || (int)$v < 0 || (int)$v > 200) throw new LocalizedException('errors.import.mappingInvalid', ['field' => $k], "mapping $k");
            return (int)$v;
        };
        $date = $int('date_col', 0);
        $value = $int('value_col', null);
        if ($value === null) throw new LocalizedException('errors.import.mappingInvalid', ['field' => 'value_col'], 'mapping value_col');
        $kind = (string)($in['value_kind'] ?? 'consumption');
        $stamp = (string)($in['interval_stamp'] ?? 'start');
        $factor = $in['unit_factor'] ?? 1;
        $factor = is_string($factor) ? ReadingImportService::parseNum($factor) : (is_numeric($factor) ? (float)$factor : null);
        $tz = (string)($in['tz'] ?? '') ?: (string)$this->settings->get('timezone', 'Europe/Berlin');
        $delim = $in['delimiter'] ?? null;
        $start = $in['start_counter'] ?? null;
        $start = $start === null || $start === '' ? null : (is_string($start) ? ReadingImportService::parseNum($start) : (is_numeric($start) ? (float)$start : null));
        if (!in_array($kind, ['counter', 'consumption'], true) || !in_array($stamp, ['start', 'end'], true)
            || $factor === null || $factor <= 0 || !in_array($tz, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
            || ($delim !== null && $delim !== '' && !in_array($delim, [';', ',', "\t"], true))
            || (($in['start_counter'] ?? null) !== null && ($in['start_counter'] ?? '') !== '' && $start === null)) {
            throw new LocalizedException('errors.import.mappingInvalid', [], 'mapping');
        }
        return ['skip_rows' => $int('skip_rows', 0), 'date_col' => $date, 'time_col' => $int('time_col', null), 'value_col' => $value,
                'value_kind' => $kind, 'interval_stamp' => $stamp, 'unit_factor' => $factor, 'tz' => $tz,
                'delimiter' => $delim === '' ? null : $delim, 'start_counter' => $start];
    }

    /**
     * Tag eines Stempels in der Zeitzone der Installation. Akzeptiert
     * `TT.MM.JJJJ[ HH:MM[:SS]]`, `JJJJ-MM-TT[ HH:MM]` und ISO 8601 mit Offset
     * (`2025-03-30T01:00:00+01:00`, `…Z`). Ein Intervall mit Endstempel 00:00
     * gehört zum Vortag.
     */
    private function dayOf(string $dateCell, string $timeCell, \DateTimeZone $tz, string $stamp): ?string
    {
        $s = trim($dateCell . ($timeCell !== '' ? ' ' . trim($timeCell) : ''));
        if ($s === '') return null;
        $dt = null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{1,2}:\d{2}(?::\d{2})?)(?:\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $s, $x)) {
            try { $dt = (new \DateTimeImmutable("$x[1]T$x[2]$x[3]"))->setTimezone($tz); } catch (\Exception) { return null; }
        } else {
            $parts = preg_split('/[ T]+/', $s, 2) ?: [];
            $iso = Dates::parseUserDate((string)($parts[0] ?? ''), $err);
            if ($iso === null) return null;
            $time = (string)($parts[1] ?? '00:00');
            if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) return null;
            try { $dt = new \DateTimeImmutable("$iso $time", $tz); } catch (\Exception) { return null; }
        }
        if ($stamp === 'end') $dt = $dt->modify('-1 second');
        return $dt->format('Y-m-d');
    }
}
