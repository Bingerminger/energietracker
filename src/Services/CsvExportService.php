<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Support\LocalizedException;

/**
 * Tabular CSV export (F-07, v1.1.0).
 *
 * Complements the JSON backup with spreadsheet-friendly exports for
 * Excel / LibreOffice / Google Sheets. Four datasets:
 *
 *   monthly()      — per-utility monthly aggregates (Verbrauch, Kosten,
 *                    Abschlag, Saldo) across all meters
 *   readings()     — raw meter readings of a utility, one row per reading
 *   deliveries()   — fuel deliveries of a delivery-based utility
 *   temperatures() — the daily temperature series
 *
 * v3.1.0 (Review I18N-10) — zwei Formate:
 *
 *   Format 1 (Standard, `?format=1`) ist eingefroren (Stabilitätsklasse A):
 *   ';' als Trenner, CRLF, UTF-8-BOM, Dezimalkomma ohne Tausendertrennung,
 *   ISO-Datum, „ja"/„nein", feste Kopfzeilen; die Monatsübersicht trägt die
 *   `csv.*` der Standardsprache. tests/unit/Services/CsvFormatV1Test.php hält
 *   das Byte für Byte fest; beschrieben in docs/referenz/api.md („CSV-Formate").
 *
 *   Format „local" (`?format=local`, optional `&lang=xx`) ist die Tabelle in der
 *   Sprache der Installation: Kopfzeilen aus `csvLocal.*`, Dezimaltrenner von
 *   Sprache und Land, Feldtrenner ';' bei Dezimalkomma sonst ',', Datum im
 *   Muster der Sprache, Ja/Nein der Sprache, Dateiname aus dem Katalog. So
 *   öffnet ein französisches Excel die Datei ohne Import-Assistenten.
 *
 * Values are quoted when they contain the separator, a quote or a line break.
 */
final class CsvExportService
{
    public const FORMATS = ['1', 'local'];

    /** Dateinamen von Format 1 (eingefroren). */
    private const V1_FILES = [
        'monthly' => 'monatsuebersicht', 'readings' => 'ablesungen',
        'deliveries' => 'lieferungen', 'temperatures' => 'temperaturen',
        'periods' => 'zeitraeume',   // v3.1.0
    ];

    /** @var array{local:bool,sep:string,dec:string,yes:string,no:string} */
    private array $d = ['local' => false, 'sep' => ';', 'dec' => ',', 'yes' => 'ja', 'no' => 'nein'];

    public function __construct(
        private ConsumptionService $consumption,
        private ReadingService $readings,
        private MeterService $meters,
        private TemperatureService $temperatures,
        private DeliveryService $deliveries,
        private I18nService $i18n,
    ) {}

    /** Per-utility monthly aggregates across all meters. */
    public function monthly(string $utility, string $format = '1', ?string $lang = null): string
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
        }
        return $this->in($format, $lang, function () use ($utility): string {
            $u = Utilities::get($utility);
            $unit = $u['consumption_unit'];
            $valueField = $unit === 'kWh' ? 'kwh' : 'm3';

            $data = $this->consumption->forUtility($utility);
            // v2.2.1 — Kopfzeilen in der Standardsprache (Format 1: eingefrorene csv.*)
            $p = $this->d['local'] ? 'csvLocal.monthly.' : 'csv.';
            $rows = [[
                $this->i18n->t($p . 'month'),
                $this->i18n->t($p . 'days'),
                $this->i18n->t($p . 'consumption', ['unit' => $unit]),
                $this->i18n->t($p . 'cost'),
                $this->i18n->t($p . 'advance'),
                $this->i18n->t($p . 'monthBalance'),
                $this->i18n->t($p . 'balanceCumulative'),
                $this->i18n->t($p . 'avgTemp'),
                $this->i18n->t($p . 'hdd'),
                $this->i18n->t($p . 'co2'),
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
        });
    }

    /** Raw readings of a utility, one row per reading, across all meters. */
    public function readings(string $utility, string $format = '1', ?string $lang = null): string
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
        }
        return $this->in($format, $lang, function () use ($utility): string {
            $meterNames = [];
            foreach ($this->meters->list($utility) as $meter) {
                $meterNames[$meter['id']] = $meter['name'] ?? $meter['id'];
            }
            $rows = [$this->d['local']
                ? $this->headers('csvLocal.readings.', ['meterId', 'meter', 'deviceId', 'date', 'reading', 'price', 'note', 'estimated', 'future'])
                : ['Zaehler-ID', 'Zaehler', 'Geraet-ID', 'Datum', 'Zaehlerstand', 'Preis (ct)', 'Notiz', 'Geschaetzt', 'Zukunft']];
            foreach ($this->readings->list($utility) as $r) {
                $rows[] = [
                    $r['meter_id'] ?? '',
                    $this->text($meterNames[$r['meter_id'] ?? ''] ?? ''),
                    $r['device_id'] ?? '',
                    $this->date($r['date'] ?? ''),
                    $this->num($r['counter'] ?? null),
                    $this->num($r['price_cents'] ?? null),
                    $this->text((string)($r['note'] ?? '')),
                    $this->bool(!empty($r['is_estimated'])),
                    $this->bool(!empty($r['is_future'])),
                ];
            }
            return $this->build($rows);
        });
    }

    /**
     * Brennstofflieferungen einer lieferbasierten Verbrauchsart
     * (Heizöl/Pellets), eine Zeile je Lieferung, über alle Tanks/Lager.
     * v1.4.2 — ergänzt den fehlenden Export für die neuen Energiearten.
     */
    public function deliveries(string $utility, string $format = '1', ?string $lang = null): string
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
        }
        if (!Utilities::isDelivery($utility)) {
            throw new \InvalidArgumentException(
                $this->i18n->t('errors.csv.notDeliveryBased', ['utility' => $utility])
            );
        }
        return $this->in($format, $lang, function () use ($utility): string {
            $u = Utilities::get($utility);
            $unit = $u['volume_unit'] ?? ($utility === 'pellets' ? 'kg' : 'L');
            $meterNames = [];
            foreach ($this->meters->list($utility) as $meter) {
                $meterNames[$meter['id']] = $meter['name'] ?? $meter['id'];
            }
            $rows = [$this->d['local']
                ? $this->headers('csvLocal.deliveries.', ['storageId', 'storage', 'date', 'quantity', 'unitPrice', 'total', 'supplier', 'note', 'planned'], ['unit' => $unit])
                : ['Tank/Lager-ID', 'Tank/Lager', 'Datum', 'Menge (' . $unit . ')',
                   'Preis (ct/' . $unit . ')', 'Gesamt (EUR)', 'Lieferant', 'Notiz', 'Geplant']];
            foreach ($this->deliveries->list($utility) as $d) {
                $qty = isset($d['quantity']) ? (float)$d['quantity'] : null;
                $upc = isset($d['unit_price_cents']) && $d['unit_price_cents'] !== null
                    ? (float)$d['unit_price_cents'] : null;
                $tot = isset($d['total_eur']) && $d['total_eur'] !== null
                    ? (float)$d['total_eur']
                    : ($qty !== null && $upc !== null ? $qty * $upc / 100.0 : null);
                $rows[] = [
                    $d['meter_id'] ?? '',
                    $this->text($meterNames[$d['meter_id'] ?? ''] ?? ''),
                    $this->date($d['date'] ?? ''),
                    $this->num($qty),
                    $this->num($upc),
                    $this->num($tot),
                    $this->text((string)($d['supplier'] ?? '')),
                    $this->text((string)($d['note'] ?? '')),
                    $this->bool(!empty($d['is_planned'])),
                ];
            }
            return $this->build($rows);
        });
    }

    /**
     * v3.1.0 (Paket H3, B2) — Verbrauch je Zeitraum, eine Zeile je Zeitraum.
     * Die ersten vier Spalten (von, bis, Wert, Notiz) liest der Zeitraum-Import
     * wieder ein; eine Kopfzeile mit Zähler-ID beschränkt ihn auf den Zähler.
     *
     * @param list<array<string,mixed>> $periods
     */
    public function periods(string $utility, array $periods, string $format = '1', ?string $lang = null): string
    {
        if (!Utilities::exists($utility)) {
            throw new \InvalidArgumentException($this->i18n->t('errors.common.unknownUtility', ['utility' => $utility]));
        }
        return $this->in($format, $lang, function () use ($utility, $periods): string {
            $u = Utilities::get($utility);
            $meterNames = [];
            foreach ($this->meters->list($utility) as $meter) $meterNames[$meter['id']] = $meter['name'] ?? $meter['id'];
            $rows = [$this->d['local']
                ? $this->headers('csvLocal.periods.', ['from', 'to', 'value', 'note', 'meterId', 'meter', 'unit', 'estimated', 'source'])
                : ['Von', 'Bis', 'Wert', 'Notiz', 'Zaehler-ID', 'Zaehler', 'Einheit', 'Geschaetzt', 'Quelle']];
            foreach ($periods as $p) {
                $rows[] = [
                    $this->date((string)($p['from'] ?? '')),
                    $this->date((string)($p['to'] ?? '')),
                    $this->num($p['value'] ?? null),
                    $this->text((string)($p['note'] ?? '')),
                    $p['meter_id'] ?? '',
                    $this->text((string)($meterNames[$p['meter_id'] ?? ''] ?? '')),
                    ($p['value_unit'] ?? 'consumption') === 'meter' ? $u['unit'] : $u['consumption_unit'],
                    $this->bool(!empty($p['is_estimated'])),
                    (string)($p['source'] ?? 'manual'),
                ];
            }
            return $this->build($rows);
        });
    }

    /**
     * v3.1.0 (H6, MKT-14) — Ladestrom-Nachweis je Monat. Format 1, Kopf ab
     * v3.1.0 eingefroren: Monat;Zaehler-ID;kWh;Preis ct/kWh;Grundpreis-Anteil;Betrag;Methode
     *
     * @param array<string,mixed> $report EvChargingReportService::report()
     */
    public function evCharging(array $report, string $format = '1', ?string $lang = null): string
    {
        return $this->in($format, $lang, function () use ($report): string {
            $rows = [$this->d['local']
                ? $this->headers('csvLocal.evCharging.', ['month', 'meterId', 'kwh', 'price', 'baseShare', 'amount', 'method'])
                : ['Monat', 'Zaehler-ID', 'kWh', 'Preis ct/kWh', 'Grundpreis-Anteil', 'Betrag', 'Methode']];
            foreach ($report['rows'] as $r) {
                $rows[] = [(string)$r['ym'], (string)$report['meter_id'], $this->num($r['kwh']), $this->num($r['price_ct']),
                           $this->num($r['base_share_eur']), $this->num($r['amount_eur']), (string)$report['method']];
            }
            return $this->build($rows);
        });
    }

    /** The daily temperature series. */
    public function temperatures(string $format = '1', ?string $lang = null): string
    {
        return $this->in($format, $lang, function (): string {
            $rows = [$this->d['local']
                ? $this->headers('csvLocal.temperatures.', ['date', 'avg', 'min', 'max'])
                : ['Datum', 'oe Temp (C)', 'Min (C)', 'Max (C)']];
            $all = $this->temperatures->all();
            ksort($all);
            foreach ($all as $date => $vals) {
                if (!is_array($vals)) continue;
                $rows[] = [
                    $this->date((string)$date),
                    $this->num($vals['avg'] ?? null),
                    $this->num($vals['min'] ?? null),
                    $this->num($vals['max'] ?? null),
                ];
            }
            return $this->build($rows);
        });
    }

    /**
     * A safe download filename for a dataset (monthly, readings, deliveries,
     * temperatures). Format 1 keeps its German slugs; „local" takes them from
     * `csvLocal.file.*` in the language of the file.
     */
    public function filename(string $dataset, ?string $utility = null, string $format = '1', ?string $lang = null): string
    {
        $slug = $format === 'local'
            ? $this->in('local', $lang, fn(): string => $this->i18n->t("csvLocal.file.$dataset"))
            : (self::V1_FILES[$dataset] ?? $dataset);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)) ?: $dataset;
        $date = date('Y-m-d');
        return $utility !== null
            ? "energietracker-{$utility}-{$slug}-{$date}.csv"
            : "energietracker-{$slug}-{$date}.csv";
    }

    /** Format prüfen; unbekannt → 400 mit Fehlercode errors.export.formatInvalid. */
    public static function assertFormat(string $format): void
    {
        if (!in_array($format, self::FORMATS, true)) {
            throw new LocalizedException('errors.export.formatInvalid', ['format' => $format], "Unknown CSV format: $format");
        }
    }

    /**
     * Führt einen Export im gewünschten Format aus. „local" schaltet die Sprache
     * für die Dauer des Exports auf `$lang` bzw. die Standardsprache der
     * Installation um (nicht die des Geräts) und danach zurück.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function in(string $format, ?string $lang, callable $fn): mixed
    {
        self::assertFormat($format);
        if ($format === '1') {
            $this->d = ['local' => false, 'sep' => ';', 'dec' => ',', 'yes' => 'ja', 'no' => 'nein'];
            return $fn();
        }
        $prev = $this->i18n->locale();
        $this->i18n->setLocale($this->i18n->normalize($lang) ?? $this->i18n->installationLocale());
        try {
            $dec = $this->i18n->decimalSeparator();
            $this->d = [
                'local' => true, 'sep' => $dec === ',' ? ';' : ',', 'dec' => $dec,
                'yes' => $this->i18n->t('common.yes'), 'no' => $this->i18n->t('common.no'),
            ];
            return $fn();
        } finally {
            $this->i18n->setLocale($prev);
            $this->d = ['local' => false, 'sep' => ';', 'dec' => ',', 'yes' => 'ja', 'no' => 'nein'];
        }
    }

    /**
     * @param list<string> $names
     * @param array<string,scalar> $params
     * @return list<string>
     */
    private function headers(string $prefix, array $names, array $params = []): array
    {
        return array_map(fn(string $n): string => $this->i18n->t($prefix . $n, $params), $names);
    }

    /**
     * Assemble rows into a CSV string: UTF-8 BOM, separator of the format, CRLF lines.
     *
     * @param array<int,array<int,string|int|float|null>> $rows
     */
    private function build(array $rows): string
    {
        $out = "\xEF\xBB\xBF"; // UTF-8 BOM so Excel picks up the encoding
        foreach ($rows as $row) {
            $cells = array_map([$this, 'cell'], $row);
            $out .= implode($this->d['sep'], $cells) . "\r\n";
        }
        return $out;
    }

    /**
     * v2.6.0 — Freitext (Namen, Notizen, Lieferant) gegen Formel-Injection:
     * Excel und LibreOffice werten auch gequotete Zellen mit führendem
     * = + - @ als Formel aus; eine präparierte Notiz wurde so zum Link.
     * Das vorangestellte Apostroph zeigt die Tabelle nicht an. Zahlen laufen
     * über num() und bleiben unberührt (auch negative).
     */
    private function text(string $s): string
    {
        return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
    }

    private function cell(string|int|float|null $value): string
    {
        $s = $value === null ? '' : (string)$value;
        if (str_contains($s, $this->d['sep']) || preg_match('/["\r\n]/', $s)) {
            $s = '"' . str_replace('"', '""', $s) . '"';
        }
        return $s;
    }

    /** ISO-Datum; im Format „local" im Muster der Sprache (15.01.2026, 15/01/2026, 15-01-2026). */
    private function date(string $iso): string
    {
        return $this->d['local'] && $iso !== '' ? $this->i18n->date($iso) : $iso;
    }

    private function bool(bool $v): string
    {
        return $v ? $this->d['yes'] : $this->d['no'];
    }

    /** Zahl ohne Tausendertrennung, bis 4 Nachkommastellen (Format 1: Dezimalkomma), '' für null. */
    private function num(mixed $v): string
    {
        if ($v === null || $v === '' || !is_numeric($v)) return '';
        $dec = $this->d['dec'];
        $s = rtrim(rtrim(number_format((float)$v, 4, $dec, ''), '0'), $dec);
        return $s === '' ? '0' : $s;
    }
}
