<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Http\NotFoundException;
use Energietracker\Storage\JsonStore;
use Energietracker\Support\Dates;
use Energietracker\Support\Encoding;
use Energietracker\Support\LocalizedException;

/**
 * v3.1.0 (Paket H3, B2) — Verbrauch je Zeitraum: die dritte Erfassungsart neben
 * Zählerständen und Lieferungen.
 *
 * Für Werte, die schon als Verbrauch vorliegen: die monatliche Verbrauchsinfo
 * des Messdienstes (HeizkostenV § 6a), Intervallwerte aus einem Portal, ein
 * Wohnungs-Wärmezähler, von dem nur Monatswerte bekannt sind. Ein Zähler mit
 * `capture: 'period'` hat statt Ablesungen Zeiträume; die Verbrauchsrechnung
 * verteilt jeden Zeitraum tagesgenau auf die Monate (ConsumptionService), und
 * alles danach — Monatschart, CSV, PDF, Prognose, Wetterbereinigung — läuft
 * unverändert.
 *
 * Topf `<utility>/periods.json`, ein Eintrag:
 *   {id: 'p_<hex>', meter_id, from, to (inklusive), value,
 *    value_unit: 'consumption'|'meter', is_estimated, source: manual|csv|import,
 *    reference?: {prev_month?, prev_year_month?, average_user?}, note,
 *    attachment_id?, client_ref?}
 *
 * `value_unit` (Lektion „zwei Einheiten"): 'consumption' ist die
 * Verbrauchseinheit der Art (kWh, bei Wasser m³), 'meter' die Zählereinheit —
 * nur bei Gas verschieden (m³, umgerechnet mit den datierten Faktoren).
 */
final class PeriodService
{
    public const VALUE_UNITS = ['consumption', 'meter'];
    public const SOURCES = ['manual', 'csv', 'import'];
    public const REFERENCE_FIELDS = ['prev_month', 'prev_year_month', 'average_user'];

    public function __construct(
        private JsonStore $store,
        private MeterService $meters,
        private I18nService $i18n,
    ) {}

    /** @return list<array<string,mixed>> nach Beginn sortiert */
    public function list(string $utility, ?string $meterId = null): array
    {
        $this->assertUtility($utility);
        $all = $this->store->read("$utility/periods.json", []);
        if (!is_array($all)) $all = [];
        $all = array_values(array_filter($all, 'is_array'));
        if ($meterId !== null) $all = array_values(array_filter($all, fn($p) => ($p['meter_id'] ?? null) === $meterId));
        usort($all, fn($a, $b) => strcmp((string)($a['from'] ?? ''), (string)($b['from'] ?? '')));
        return $all;
    }

    /** @return array<string,mixed> */
    public function create(string $utility, array $input): array
    {
        $this->assertUtility($utility);
        $meterId = (string)($input['meter_id'] ?? $this->meters->defaultId($utility));
        $meter = $this->periodMeter($utility, $meterId);

        $clientRef = null;
        if (isset($input['client_ref']) && $input['client_ref'] !== '') {
            $clientRef = (string)$input['client_ref'];
            if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $clientRef)) {
                throw new \InvalidArgumentException($this->i18n->t('errors.reading.clientRefInvalid'));
            }
            foreach ($this->list($utility, $meterId) as $existing) {
                if (($existing['client_ref'] ?? null) === $clientRef) return $existing + ['duplicate' => true];
            }
        }

        $p = ['id' => 'p_' . bin2hex(random_bytes(6)), 'meter_id' => $meter['id']] + $this->normalize($utility, $input, null);
        if ($clientRef !== null) $p['client_ref'] = $clientRef;
        $all = $this->store->read("$utility/periods.json", []);
        if (!is_array($all)) $all = [];
        $this->assertNoOverlap($all, $p);
        if (!empty($input['attachment_id'])) {
            AttachmentService::link($this->store, (string)$input['attachment_id'], null,
                ['type' => 'period', 'utility' => $utility, 'id' => $p['id']]);
            $p['attachment_id'] = (string)$input['attachment_id'];
        }
        $all[] = $p;
        $this->store->write("$utility/periods.json", array_values($all));
        return $p;
    }

    /** @return array<string,mixed> */
    public function update(string $utility, string $id, array $input): array
    {
        $this->assertUtility($utility);
        $all = $this->store->read("$utility/periods.json", []);
        if (!is_array($all)) $all = [];
        foreach ($all as $i => $p) {
            if (!is_array($p) || ($p['id'] ?? null) !== $id) continue;
            $next = array_merge($p, $this->normalize($utility, $input, $p));
            $this->assertNoOverlap($all, $next);
            if (array_key_exists('attachment_id', $input) && ($input['attachment_id'] ?? null) !== ($p['attachment_id'] ?? null)) {
                if (!empty($p['attachment_id'])) AttachmentService::unlinkRef($this->store, 'period', $id);
                if (!empty($input['attachment_id'])) {
                    AttachmentService::link($this->store, (string)$input['attachment_id'], null,
                        ['type' => 'period', 'utility' => $utility, 'id' => $id]);
                    $next['attachment_id'] = (string)$input['attachment_id'];
                } else {
                    unset($next['attachment_id']);
                }
            }
            $all[$i] = $next;
            $this->store->write("$utility/periods.json", array_values($all));
            return $next;
        }
        throw new NotFoundException($this->i18n->t('errors.period.notFound'));
    }

    public function delete(string $utility, string $id): void
    {
        $this->assertUtility($utility);
        $all = $this->store->read("$utility/periods.json", []);
        if (!is_array($all)) $all = [];
        $kept = array_values(array_filter($all, fn($p) => is_array($p) && ($p['id'] ?? null) !== $id));
        if (count($kept) === count($all)) throw new NotFoundException($this->i18n->t('errors.period.notFound'));
        $this->store->write("$utility/periods.json", $kept);
        AttachmentService::unlinkRef($this->store, 'period', $id);
    }

    /** Hat der Zähler Zeiträume? (Sperre beim Wechsel der Erfassungsart) */
    public function hasAny(string $utility, string $meterId): bool
    {
        return $this->list($utility, $meterId) !== [];
    }

    /**
     * CSV: `von;bis;wert[;notiz]` (Datum wie im Ablesungs-Import) oder
     * `monat;wert[;notiz]` mit `MM.JJJJ`, `MM/JJJJ` oder `JJJJ-MM` (ganzer
     * Monat). Kopfzeile optional. Überlappende Zeilen werden übersprungen und
     * gemeldet, nicht überschrieben.
     *
     * @return array{imported:int, skipped:int, errors:list<string>, dry_run?:bool, rows?:list<array<string,mixed>>}
     */
    public function importCsv(string $utility, string $meterId, string $csv, bool $dryRun = false): array
    {
        $this->assertUtility($utility);
        $meter = $this->periodMeter($utility, $meterId);
        if (trim($csv) === '') throw new \InvalidArgumentException($this->i18n->t('errors.import.emptyCsv'));
        [$csv] = Encoding::normalizeCsv($csv);
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $rows = []; $errors = []; $skipped = 0; $sep = null; $first = true;
        foreach ($lines as $lineNo => $raw) {
            $line = trim($raw);
            if ($line === '') continue;
            $sep ??= str_contains($line, ';') ? ';' : (str_contains($line, "\t") ? "\t" : ',');
            $parts = array_map(fn($c) => trim((string)$c), str_getcsv($line, $sep, '"', ''));
            $n = $lineNo + 1;
            [$from, $to, $valueCell, $note] = [null, null, null, ''];
            $month = self::parseMonth($parts[0] ?? '');
            if ($month !== null) {
                [$from, $to] = $month;
                $valueCell = $parts[1] ?? '';
                $note = $parts[2] ?? '';
            } else {
                $from = Dates::parseUserDate($parts[0] ?? '');
                $to = Dates::parseUserDate($parts[1] ?? '');
                $valueCell = $parts[2] ?? '';
                $note = $parts[3] ?? '';
            }
            if ($first) {
                $first = false;
                if ($from === null && preg_match('/\p{L}/u', implode('', $parts))) {   // Kopfzeile
                    // eigener Export: Spalte Zähler-ID → nur die Zeilen dieses Zählers
                    $names = array_merge(['zaehler-id', 'meter_id', 'meter-id'],
                        array_map([ReadingImportService::class, 'fold'], array_values($this->i18n->valuesInAllLanguages('csvLocal.periods.meterId'))));
                    foreach ($parts as $ci => $h) {
                        if (in_array(ReadingImportService::fold((string)$h), $names, true)) $meterCol = $ci;
                    }
                    continue;
                }
            }
            if (isset($meterCol) && ($parts[$meterCol] ?? '') !== '' && $parts[$meterCol] !== $meterId) continue;
            if ($from === null || $to === null) {
                $skipped++;
                $errors[] = $this->i18n->t('errors.period.csvDate', ['line' => $n, 'value' => (string)($parts[0] ?? '')]);
                continue;
            }
            $value = ReadingImportService::parseNum((string)$valueCell);
            if ($value === null || $value < 0) {
                $skipped++;
                $errors[] = $this->i18n->t('errors.period.csvValue', ['line' => $n, 'value' => (string)$valueCell]);
                continue;
            }
            $rows[] = ['line' => $n, 'from' => $from, 'to' => $to, 'value' => $value, 'note' => $note];
        }

        $all = $this->store->read("$utility/periods.json", []);
        if (!is_array($all)) $all = [];
        $imported = 0;
        $new = [];
        foreach ($rows as $r) {
            try {
                $p = ['id' => 'p_' . bin2hex(random_bytes(6)), 'meter_id' => $meter['id']]
                    + $this->normalize($utility, ['from' => $r['from'], 'to' => $r['to'], 'value' => $r['value'], 'note' => $r['note'], 'source' => 'csv'], null);
                $this->assertNoOverlap(array_merge($all, $new), $p);
                $new[] = $p;
                $imported++;
            } catch (\InvalidArgumentException $e) {
                $skipped++;
                $errors[] = $this->i18n->t('errors.period.csvLine', ['line' => $r['line'], 'message' => $this->messageOf($e)]);
            }
        }
        if ($dryRun) {
            return ['imported' => 0, 'skipped' => $skipped, 'errors' => $errors, 'dry_run' => true, 'rows' => $rows, 'would_import' => $imported];
        }
        if ($new !== []) $this->store->write("$utility/periods.json", array_values(array_merge($all, $new)));
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** `MM.JJJJ`, `MM/JJJJ`, `JJJJ-MM` → [erster, letzter Tag] oder null. */
    public static function parseMonth(string $s): ?array
    {
        $s = trim($s, " \t\"'");
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $s, $m)) [$y, $mo] = [(int)$m[1], (int)$m[2]];
        elseif (preg_match('/^(\d{1,2})[.\/](\d{4})$/', $s, $m)) [$y, $mo] = [(int)$m[2], (int)$m[1]];
        else return null;
        if ($mo < 1 || $mo > 12) return null;
        $from = sprintf('%04d-%02d-01', $y, $mo);
        return [$from, date('Y-m-t', (int)strtotime($from))];
    }

    // ── intern ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> nur die übergebenen bzw. für einen neuen Eintrag alle Felder */
    private function normalize(string $utility, array $input, ?array $old): array
    {
        $out = [];
        $isNew = $old === null;
        // „month: JJJJ-MM" als Abkürzung für einen ganzen Monat
        if (isset($input['month']) && !isset($input['from']) && !isset($input['to'])) {
            $m = self::parseMonth((string)$input['month']);
            if ($m === null) throw new LocalizedException('errors.period.dateInvalid', ['date' => (string)$input['month']], 'period month');
            [$input['from'], $input['to']] = $m;
        }
        foreach (['from', 'to'] as $f) {
            if ($isNew || array_key_exists($f, $input)) {
                $v = (string)($input[$f] ?? '');
                if (!Dates::isIsoDate($v)) throw new LocalizedException('errors.period.dateInvalid', ['date' => $v], "period $f");
                $out[$f] = $v;
            }
        }
        $from = $out['from'] ?? (string)$old['from'];
        $to = $out['to'] ?? (string)$old['to'];
        if ($from > $to) throw new LocalizedException('errors.period.order', ['from' => $from, 'to' => $to], 'period from > to');
        if ($isNew || array_key_exists('value', $input)) {
            $raw = $input['value'] ?? null;
            $v = is_string($raw) ? ReadingImportService::parseNum($raw) : (is_int($raw) || is_float($raw) ? (float)$raw : null);
            if ($v === null || !is_finite($v) || $v < 0) {
                throw new LocalizedException('errors.period.valueInvalid', ['value' => is_scalar($raw) ? (string)$raw : ''], 'period value');
            }
            $out['value'] = round($v, 3);
        }
        if ($isNew || array_key_exists('value_unit', $input)) {
            $unit = (string)($input['value_unit'] ?? 'consumption');
            if (!in_array($unit, self::VALUE_UNITS, true)) $unit = 'consumption';
            // Nur bei Gas unterscheiden sich die Einheiten; sonst gibt es nur eine
            $u = Utilities::get($utility);
            if ($u['unit'] === $u['consumption_unit']) $unit = 'consumption';
            $out['value_unit'] = $unit;
        }
        if ($isNew || array_key_exists('is_estimated', $input)) $out['is_estimated'] = !empty($input['is_estimated']);
        if ($isNew || array_key_exists('source', $input)) {
            $out['source'] = in_array($input['source'] ?? null, self::SOURCES, true) ? (string)$input['source'] : 'manual';
        }
        if ($isNew || array_key_exists('note', $input)) $out['note'] = mb_substr((string)($input['note'] ?? ''), 0, 500);
        if (array_key_exists('reference', $input)) {
            $ref = [];
            foreach (self::REFERENCE_FIELDS as $f) {
                $rv = $input['reference'][$f] ?? null;
                if ($rv === null || $rv === '') continue;
                $num = is_string($rv) ? ReadingImportService::parseNum($rv) : (is_numeric($rv) ? (float)$rv : null);
                if ($num === null || $num < 0) throw new LocalizedException('errors.period.valueInvalid', ['value' => (string)$rv], 'period reference');
                $ref[$f] = round($num, 3);
            }
            $out['reference'] = $ref === [] ? null : $ref;
        }
        return $out;
    }

    /** Ein Zeitraum darf keinen anderen desselben Zählers berühren. */
    private function assertNoOverlap(array $all, array $p): void
    {
        foreach ($all as $o) {
            if (!is_array($o) || ($o['meter_id'] ?? null) !== $p['meter_id'] || ($o['id'] ?? null) === $p['id']) continue;
            if ((string)$o['from'] <= $p['to'] && $p['from'] <= (string)$o['to']) {
                throw new LocalizedException('errors.period.overlap', ['from' => (string)$o['from'], 'to' => (string)$o['to']], 'period overlap');
            }
        }
    }

    /** @return array<string,mixed> */
    private function periodMeter(string $utility, string $meterId): array
    {
        $meter = $this->meters->get($utility, $meterId);
        if (!$meter) throw new NotFoundException($this->i18n->t('errors.common.meterNotFound', ['id' => $meterId]));
        if (($meter['capture'] ?? 'counter') !== 'period') {
            throw new LocalizedException('errors.period.meterNotPeriod', [], 'meter does not capture periods');
        }
        return $meter;
    }

    private function assertUtility(string $utility): void
    {
        if (!Utilities::exists($utility) || !Utilities::isCumulative($utility)) {
            throw new LocalizedException('errors.common.unknownUtility', ['utility' => $utility], "period utility $utility");
        }
    }

    private function messageOf(\Throwable $e): string
    {
        return $e instanceof LocalizedException ? $this->i18n->t($e->key, $e->params) : $e->getMessage();
    }
}
