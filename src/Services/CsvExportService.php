<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;

/**
 * Tabular CSV export (F-07, v1.1.0).
 *
 * Complements the JSON backup with spreadsheet-friendly exports for
 * Excel / LibreOffice / Google Sheets. Three datasets:
 *
 *   monthly()      — per-utility monthly aggregates (Verbrauch, Kosten,
 *                    Abschlag, Saldo) across all meters
 *   readings()     — raw meter readings of a utility, one row per reading
 *   temperatures() — the daily temperature series
 *
 * Output conventions: ';' separator (German Excel default), CRLF line
 * endings, a UTF-8 BOM so Excel detects the encoding, German decimal
 * comma for numbers, ISO dates. Values are quoted when they contain the
 * separator, a quote or a line break.
 */
final class CsvExportService
{
    public function __construct(
        private ConsumptionService $consumption,
        private ReadingService $readings,
        private MeterService $meters,
        private TemperatureService $temperatures,
    ) {}

    /** Per-utility monthly aggregates across all meters. */
    public function monthly(string $utility): string
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException('Unbekannte Verbrauchsart: ' . $utility);
        }
        $u = Utilities::get($utility);
        $unit = $u['consumption_unit'];
        $valueField = $unit === 'kWh' ? 'kwh' : 'm3';

        $data = $this->consumption->forUtility($utility);
        $rows = [[
            'Monat', 'Tage', 'Verbrauch (' . $unit . ')', 'Kosten (EUR)',
            'Abschlag (EUR)', 'Monatssaldo (EUR)', 'Saldo kumuliert (EUR)',
            'oe Temp (C)', 'HGT', 'CO2 (kg)',
        ]];
        foreach ($data['monthly_total'] ?? [] as $m) {
            $rows[] = [
                $m['ym'] ?? '',
                $m['days'] ?? '',
                $this->num($m[$valueField] ?? null),
                $this->num($m['cost'] ?? null),
                $this->num($m['advance_eur'] ?? null),
                $this->num($m['monthly_balance'] ?? null),
                $this->num($m['cumulative_balance'] ?? null),
                $this->num($m['avg_temp'] ?? null),
                $this->num($m['hdd'] ?? null),
                $this->num($m['co2_kg'] ?? null),
            ];
        }
        return $this->build($rows);
    }

    /** Raw readings of a utility, one row per reading, across all meters. */
    public function readings(string $utility): string
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException('Unbekannte Verbrauchsart: ' . $utility);
        }
        $meterNames = [];
        foreach ($this->meters->list($utility) as $meter) {
            $meterNames[$meter['id']] = $meter['name'] ?? $meter['id'];
        }
        $rows = [[
            'Zaehler-ID', 'Zaehler', 'Geraet-ID', 'Datum', 'Zaehlerstand',
            'Preis (ct)', 'Notiz', 'Geschaetzt', 'Zukunft',
        ]];
        foreach ($this->readings->list($utility) as $r) {
            $rows[] = [
                $r['meter_id'] ?? '',
                $meterNames[$r['meter_id'] ?? ''] ?? '',
                $r['device_id'] ?? '',
                $r['date'] ?? '',
                $this->num($r['counter'] ?? null),
                $this->num($r['price_cents'] ?? null),
                (string)($r['note'] ?? ''),
                !empty($r['is_estimated']) ? 'ja' : 'nein',
                !empty($r['is_future']) ? 'ja' : 'nein',
            ];
        }
        return $this->build($rows);
    }

    /** The daily temperature series. */
    public function temperatures(): string
    {
        $rows = [['Datum', 'oe Temp (C)', 'Min (C)', 'Max (C)']];
        $all = $this->temperatures->all();
        ksort($all);
        foreach ($all as $date => $vals) {
            if (!is_array($vals)) continue;
            $rows[] = [
                (string)$date,
                $this->num($vals['avg'] ?? null),
                $this->num($vals['min'] ?? null),
                $this->num($vals['max'] ?? null),
            ];
        }
        return $this->build($rows);
    }

    /** A safe download filename for a dataset. */
    public function filename(string $dataset, ?string $utility = null): string
    {
        $date = date('Y-m-d');
        return $utility !== null
            ? "energietracker-{$utility}-{$dataset}-{$date}.csv"
            : "energietracker-{$dataset}-{$date}.csv";
    }

    /**
     * Assemble rows into a CSV string: UTF-8 BOM, ';' separator, CRLF lines.
     *
     * @param array<int,array<int,string|int|float|null>> $rows
     */
    private function build(array $rows): string
    {
        $out = "\xEF\xBB\xBF"; // UTF-8 BOM so Excel picks up the encoding
        foreach ($rows as $row) {
            $cells = array_map([$this, 'cell'], $row);
            $out .= implode(';', $cells) . "\r\n";
        }
        return $out;
    }

    private function cell(string|int|float|null $value): string
    {
        $s = $value === null ? '' : (string)$value;
        if (preg_match('/[;"\r\n]/', $s)) {
            $s = '"' . str_replace('"', '""', $s) . '"';
        }
        return $s;
    }

    /** Format a number with a German decimal comma, or '' for null. */
    private function num(mixed $v): string
    {
        if ($v === null || $v === '' || !is_numeric($v)) return '';
        $s = rtrim(rtrim(number_format((float)$v, 4, ',', ''), '0'), ',');
        return $s === '' ? '0' : $s;
    }
}
