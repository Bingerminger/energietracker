<?php
declare(strict_types=1);

namespace Energietracker\Services\Mcp\Tools;

use Energietracker\Config\Utilities;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BillService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\Mcp\McpContext;
use Energietracker\Services\Mcp\Num;
use Energietracker\Services\Mcp\Provenance;
use Energietracker\Services\Mcp\Sanitize;
use Energietracker\Services\Mcp\Tool;
use Energietracker\Services\Mcp\ToolGroup;
use Energietracker\Services\MeterService;
use Energietracker\Services\PdfReportService;
use Energietracker\Services\ReadingService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\RightsService;
use Energietracker\Services\SummaryService;
use Energietracker\Support\LocalizedException;

/**
 * v3.12.0 (F1037, Paket lesen) — Überblick, Fristen, Ablesungen, Hinweise und
 * Jahresbericht für die KI (Konzept §3.2). Jede Zahl trägt ihre Herkunft
 * (Zahl-Objekt oder `basis` je Zeile), dieselben Werte wie die Oberfläche:
 *
 *   get_overview       SummaryService::build (GET /api/summary), Saldo-Teile aus contractStatus
 *   get_deadlines      AgendaService::events (GET /api/agenda, „Zu tun“)
 *   list_readings      ReadingService::list (GET …/readings)
 *   get_insights       RecommendationService::all (GET /api/recommendations)
 *   get_yearly_report  PdfReportService::yearlyData (GET /api/reports/yearly)
 */
final class ReadTools extends ToolGroup
{
    use ReadSupport;

    /** höchstens so viele Fristen, Ablesungen, Hinweise je Antwort (danach `truncated: true`) */
    public const MAX_EVENTS = 100;
    public const MAX_READINGS = 100;
    public const DEFAULT_READINGS = 50;
    public const MAX_INSIGHTS = 30;
    public const DEFAULT_DEADLINE_DAYS = 60;
    /** Fristen mit eingetragenem Datum; die übrigen rechnet die App aus (Kündigungsstichtag, Ablesung fällig …) */
    public const ENTERED_DATE_KINDS = ['reminder', 'term_end', 'price_guarantee_end', 'price_increase'];
    /** Kennzahlen an Hinweisen, die jemand eingetragen hat (oder Zähler aus dem Kalender), keine Schätzungen */
    private const ENTERED_FIGURES = ['amount_eur', 'months', 'days'];
    /** Felder aus `ref` einer Frist, die die KI braucht (nur Kennungen, kein Text) */
    private const REF_KEYS = ['utility', 'meter_id', 'contract_id', 'bill_id', 'reminder_id', 'tenancy_id', 'check', 'conflict', 'category'];

    public function tools(): array
    {
        $ro = ['readOnlyHint' => true, 'idempotentHint' => true];
        return [
            new Tool('get_overview', Tool::READ, self::obj([
                'utility' => self::utilityArg(),
                'meter'   => self::meterArg(),
            ]), fn(array $a, McpContext $c): array => $this->overview($a, $c), self::overviewSchema(), $ro),
            new Tool('get_deadlines', Tool::READ, self::obj([
                'days'         => ['type' => 'integer', 'minimum' => 1, 'maximum' => 366, 'default' => self::DEFAULT_DEADLINE_DAYS],
                'include_done' => ['type' => 'boolean', 'default' => false],
                'utility'      => self::utilityArg(),
            ]), fn(array $a, McpContext $c): array => $this->deadlines($a, $c), self::deadlinesSchema(), $ro),
            new Tool('list_readings', Tool::READ, self::obj([
                'utility'         => self::utilityArg(Utilities::cumulativeKeys()),
                'meter'           => self::meterArg(),
                'from'            => self::date(),
                'to'              => self::date(),
                'limit'           => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_READINGS, 'default' => self::DEFAULT_READINGS],
                'include_planned' => ['type' => 'boolean', 'default' => false],
            ], ['utility', 'meter']), fn(array $a, McpContext $c): array => $this->readings($a, $c), self::readingsSchema(), $ro),
            new Tool('get_insights', Tool::READ, self::obj([
                'category' => ['type' => 'string', 'pattern' => '^[a-z_]{1,30}$'],
            ]), fn(array $a, McpContext $c): array => $this->insights($a, $c), self::insightsSchema(), $ro),
            new Tool('get_yearly_report', Tool::READ, self::obj([
                'year'           => ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2100],
                'utilities'      => ['type' => 'array', 'items' => self::utilityArg(), 'minItems' => 1, 'maxItems' => count(Utilities::keys())],
                'billing_period' => ['type' => 'boolean', 'default' => false],
            ]), fn(array $a, McpContext $c): array => $this->yearlyReport($a, $c), self::yearlySchema(), $ro),
        ];
    }

    // ── get_overview ─────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function overview(array $args, McpContext $ctx): array
    {
        $this->textLocale = $ctx->locale;
        $utility = $args['utility'] ?? null;
        $ref = $args['meter'] ?? null;
        $summary = $this->services->get(SummaryService::class)->build($utility, $ref, $ctx->today);
        if ($ref !== null && $summary['meters'] === []) {
            throw new LocalizedException('errors.mcp.meterNotFound', ['meter' => Sanitize::name($ref), 'utility' => $utility ?? '–'], 'mcp overview');
        }
        $meters = [];
        // v3.12.0 (Skeptiker N7) — der Vertrag einer Gruppe steht einmal da; zusammenzählen wäre sonst doppelt
        $groupSeen = [];
        foreach ($summary['meters'] as $row) {
            $m = $this->overviewMeter($row, $ctx->today);
            $gid = (string)($m['group_contract']['group_id'] ?? '');
            if ($gid !== '') {
                if (isset($groupSeen[$gid])) $m['group_contract'] = ['group_id' => $gid, 'same_as' => $groupSeen[$gid]];
                else $groupSeen[$gid] = (string)$m['id'];
            }
            $meters[] = $m;
        }
        $next = $summary['agenda']['next'] ?? null;
        return [
            'as_of'     => $ctx->today,
            'currency'  => $this->currency(),
            'meters'    => $meters,
            'deadlines' => self::compact([
                'due'     => ['count' => (int)$summary['agenda']['due']],
                'overdue' => ['count' => (int)$summary['agenda']['overdue']],
                'next'    => is_array($next) ? ['kind' => (string)$next['kind'], 'date' => (string)$next['date'],
                                                'untrusted' => Sanitize::untrusted(['title' => $this->masked((string)$next['title'])])] : null,
            ]),
            'suspect_readings' => ['count' => (int)$summary['warnings']],
        ];
    }

    /**
     * Ein Zähler der Übersicht: die Zahlen von SummaryService, die Herkunft aus den
     * Monatszeilen (forMeter) und der Vertragszeile (contractStatus) dahinter.
     *
     * @param array<string,mixed> $row Zähler aus SummaryService::build
     * @return array<string,mixed>
     */
    private function overviewMeter(array $row, string $today): array
    {
        $u = (string)$row['utility'];
        $id = (string)$row['meter_id'];
        $meter = $this->services->get(MeterService::class)->get($u, $id) ?? ['id' => $id];
        $cu = (string)$row['consumption_unit'];
        $monthly = $this->memberRows($u, [$meter]);
        $year = (int)substr($today, 0, 4);
        $ym = substr($today, 0, 7);
        $monthRows = array_values(array_filter($monthly, fn($r) => ($r['ym'] ?? '') === $ym));
        $lastDate = is_string($row['last_reading_date'] ?? null) ? $row['last_reading_date'] : null;
        // Jahr und Monat bis zur letzten Ablesung: über ganze Ablesungsabstände gemessen, sonst je Monat
        $end = fn(string $nextStart): string => $lastDate !== null && $lastDate < $nextStart ? $lastDate : $nextStart;
        $ytdParts = $this->windowParts($u, [$meter], $monthly, "$year-01-01", $end(($year + 1) . '-01-01'));
        $mtdParts = $this->windowParts($u, [$meter], $monthly, "$ym-01", $end(self::afterMonth($ym)));

        $last = null;
        if ($row['last_counter'] !== null) {
            $real = $this->lastRealReading($u, $id);
            $last = ['date' => (string)$row['last_reading_date'], 'counter' => Num::of((float)$row['last_counter'], (string)$row['unit'],
                Provenance::reading($real ?? []), ['as_of' => (string)$row['last_reading_date']])];
        }
        $out = [
            'utility'            => $u,
            'utility_label'      => (string)$row['utility_label'],
            'accounting_kind'    => Utilities::accountingKind($u),
            'id'                 => $id,
            'untrusted'          => Sanitize::untrusted(['name' => $this->masked((string)$row['name'])]),
            'role'               => $row['role'],
            'sub_meter'          => (bool)$row['is_sub_meter'],
            'group_id'           => $meter['meter_group_id'] ?? null,
            'capture'            => (string)$row['capture'],
            'last_reading'       => $last,
            'last_period_until'  => $row['capture'] === 'period' ? $lastDate : null,
            'days_since_reading' => $row['days_since_reading'],
            'reading_due'        => (bool)$row['reading_due'],
            // ohne Zeile für den Monat ist er noch nicht abgelesen: kein Wert (die Kennzahl für Home Assistant zeigt 0)
            'month_to_date'      => $monthRows !== [] && $row['month_to_date'] !== null
                ? Provenance::num((float)$row['month_to_date']['value'], $cu, $mtdParts ?: ['measured' => 1.0]) : null,
            'year_to_date'       => $row['year_to_date'] !== null ? Provenance::num((float)$row['year_to_date']['value'], $cu,
                $ytdParts ?: ['measured' => 1.0], $lastDate !== null && (int)substr($lastDate, 0, 4) === $year ? ['as_of' => $lastDate] : []) : null,
            'forecast_12m'       => $this->overviewForecast($row['forecast_12m'], $cu, $u, $row['contract'] !== null || $row['group_contract'] !== null),
            'contract'           => $row['contract'] !== null ? $this->overviewContract($u, $meter, $today) : null,
            'group_contract'     => $row['group_contract'] !== null ? $this->overviewGroupContract($u, $row['group_contract'], $today) : null,
            'tank'               => $row['tank'] !== null ? $this->overviewTank($row['tank']) : null,
        ];
        return self::compact($out);
    }

    /** @param array{value:?float,cost:?float,low:?float,high:?float}|null $f */
    private function overviewForecast(?array $f, string $unit, string $utility = '', bool $hasContract = true): ?array
    {
        if ($f === null || !is_numeric($f['value'] ?? null)) return null;
        $range = is_numeric($f['low'] ?? null) && is_numeric($f['high'] ?? null) ? ['range' => [(float)$f['low'], (float)$f['high']]] : [];
        $money = $utility !== '' ? self::moneyField($utility) : 'cost';
        // v3.12.0 (Skeptiker M4) — ohne Vertrag keine 0 € als Kosten
        $amount = $money !== null && ($hasContract || abs((float)($f['cost'] ?? 0.0)) >= 0.005) ? ($f['cost'] ?? null) : null;
        return self::compact([
            'quantity' => Num::of((float)$f['value'], $unit, 'estimated', $range),
            (string)($money ?? 'cost') => self::maybe($amount, fn($v) => Num::money($v, $this->currency(), 'estimated')),
            'no_contract' => $money !== null && $amount === null ? true : null,
        ]);
    }

    /**
     * Laufender Vertrag eines Ziels: dieselbe Zeile wie SummaryService::contract
     * (contractStatus am selben Tag, `is_current`); der Saldo zur letzten Ablesung
     * wie dort aus der Summe der Abrechnung, wenn es sie gibt.
     *
     * @return array<string,mixed>|null
     */
    private function overviewContract(string $u, array $target, string $today): ?array
    {
        try {
            $status = $this->services->get(ConsumptionService::class)->contractStatus($u, $target, $today);
        } catch (\Throwable) {
            return null;
        }
        foreach ($status['contracts'] ?? [] as $c) {
            if (empty($c['is_current'])) continue;
            $c['balance_measured'] = $c['settle_measured'] ?? $c['balance_measured'] ?? null;
            $dated = ($c['remind_basis'] ?? null) === 'cancel_by' && (int)($c['successor']['gap_days'] ?? 1) !== 0;
            $nums = $this->contractStatusNums($u, $c, $target);
            $hadProjection = array_key_exists('energy_cost_to_date', $c);
            // v3.12.0 (Skeptiker H4) — `balance_from` bleibt: Der Saldo kann Jahre zurückreichen
            unset($nums['months_left'], $nums['verdict']);
            // wie /api/summary: der Tag des Saldos zur letzten Ablesung (bei einer Abrechnung über mehrere Verträge deren Summe)
            $until = $c['balance_measured']['until'] ?? null;
            if (is_string($until)) $nums['measured_until'] = $until;
            elseif ($hadProjection) unset($nums['measured_until']);
            return self::compact([
                'contract_id'         => (string)($c['contract_id'] ?? ''),
                'untrusted'           => Sanitize::untrusted(['provider' => $this->masked((string)($c['provider'] ?? '')), 'tariff' => $this->masked((string)($c['tariff_name'] ?? ''))]),
                'prices'              => $this->contractPrices($u, $c, $today),
                'status'              => $nums,
                'settle_on'           => is_string($c['settle_on'] ?? null) ? $c['settle_on'] : null,
                'cancel_by'           => $dated && is_string($c['cancel_by'] ?? null) ? $c['cancel_by'] : null,
                'price_increase_from' => is_string($c['price_increase']['from'] ?? null) ? $c['price_increase']['from'] : null,
                'price_increase'      => $this->priceIncreaseRow($u, $c),
            ]);
        }
        return null;
    }

    /** @param array{group_id:string,name:string,contract:array<string,mixed>} $gc */
    private function overviewGroupContract(string $u, array $gc, string $today): ?array
    {
        $meters = $this->services->get(MeterService::class);
        $target = $meters->groupTarget($u, (string)$gc['group_id']);
        if ($target === null) return null;
        $contract = $this->overviewContract($u, $target, $today);
        if ($contract === null) return null;
        return ['group_id' => (string)$gc['group_id'], 'untrusted' => Sanitize::untrusted(['name' => $this->masked((string)$gc['name'])]), 'contract' => $contract];
    }

    /** @param array<string,mixed> $t Tank aus SummaryService */
    private function overviewTank(array $t): array
    {
        $unit = (string)($t['unit'] ?? '') !== '' ? (string)$t['unit'] : 'L';
        // nach dem letzten bekannten Bestand geschätzt (Tankbuch), sonst der letzte Bestand
        $basis = ($t['estimated_from'] ?? null) !== null ? 'estimated' : 'measured';
        return self::compact([
            'stock'          => Num::of((float)$t['stock'], $unit, $basis),
            'capacity'       => self::maybe($t['capacity'] ?? null, fn($v) => Num::of($v, $unit, 'entered')),
            'percent'        => self::maybe($t['percent'] ?? null, fn($v) => Num::of($v, '%', Provenance::weakest($basis, 'entered'), ['derived' => true])),
            'estimated_from' => is_string($t['estimated_from'] ?? null) ? $t['estimated_from'] : null,
        ]);
    }

    // ── get_deadlines ────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function deadlines(array $args, McpContext $ctx): array
    {
        $days = (int)($args['days'] ?? self::DEFAULT_DEADLINE_DAYS);
        $withDone = ($args['include_done'] ?? false) === true;
        $utility = $args['utility'] ?? null;
        $events = [];
        $truncated = false;
        foreach ($this->services->get(AgendaService::class)->events($days, $ctx->today) as $e) {
            $done = $e['done'] ?? null;
            if ($done !== null && !$withDone) continue;
            if ($utility !== null && (($e['ref']['utility'] ?? null) !== $utility)) continue;
            if (count($events) >= self::MAX_EVENTS) { $truncated = true; break; }
            $ref = [];
            foreach (self::REF_KEYS as $k) {
                if (is_string($e['ref'][$k] ?? null) && $e['ref'][$k] !== '') $ref[$k] = $e['ref'][$k];
            }
            $events[] = self::compact([
                'uid'         => (string)$e['uid'],
                'kind'        => (string)$e['kind'],
                'date'        => (string)$e['date'],
                'date_source' => in_array($e['kind'], self::ENTERED_DATE_KINDS, true) ? 'entered' : 'derived',
                'severity'    => (string)$e['severity'],
                'due_now'     => (bool)$e['due_now'],
                'done'        => $done !== null ? true : null,
                'ref'         => $ref,
                'untrusted'   => Sanitize::untrusted(['title' => $this->masked((string)$e['title']), 'assignee' => is_string($e['assignee'] ?? null) ? $e['assignee'] : null]),
            ]);
        }
        // v3.12.0 (Abnahme Open WebUI) — die Zahl steht dabei, damit ein Modell nicht still Einträge weglassen muss
        return ['as_of' => $ctx->today, 'days' => $days, 'count' => count($events), 'events' => $events] + ($truncated ? ['truncated' => true] : []);
    }

    // ── list_readings ────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function readings(array $args, McpContext $ctx): array
    {
        $u = (string)$args['utility'];
        $target = $this->target($u, (string)$args['meter']);
        $from = $args['from'] ?? null;
        $to = $args['to'] ?? null;
        if ($from !== null && $to !== null && $from > $to) {
            throw new LocalizedException('errors.mcp.rangeInvalid', ['from' => $from, 'to' => $to], 'mcp readings');
        }
        $limit = (int)($args['limit'] ?? self::DEFAULT_READINGS);
        $planned = ($args['include_planned'] ?? false) === true;
        $ids = !empty($target['is_group']) ? array_map('strval', (array)$target['members']) : [(string)$target['id']];
        $unit = (string)Utilities::get($u)['unit'];
        $all = [];
        foreach ($this->services->get(ReadingService::class)->list($u) as $r) {
            if (!in_array((string)($r['meter_id'] ?? ''), $ids, true)) continue;
            $d = (string)($r['date'] ?? '');
            if (($from !== null && $d < $from) || ($to !== null && $d > $to)) continue;
            if (!$planned && !empty($r['is_future'])) continue;
            if (!is_numeric($r['counter'] ?? null)) continue;
            $all[] = $r;
        }
        $truncated = count($all) > $limit;
        $skipped = [];
        foreach ($target['is_group'] ?? false ? (array)$this->services->get(MeterService::class)->groupMembers($u, (string)$target['id']) : [$target] as $m) {
            $skipped += $this->services->get(ConsumptionService::class)->skippedReadingIds($u, $m);
        }
        $rows = array_map(fn(array $r): array => self::compact([
            'id'              => (string)($r['id'] ?? ''),
            'meter_id'        => (string)($r['meter_id'] ?? ''),
            'date'            => (string)$r['date'],
            'counter'         => round((float)$r['counter'], 3),
            'basis'           => Provenance::reading($r),
            'source'          => is_string($r['source'] ?? null) ? $r['source'] : null,
            'is_billing_only' => !empty($r['is_billing_only']) ? true : null,
            'is_suspect'      => !empty($r['is_suspect']) ? true : null,
            'is_future'       => !empty($r['is_future']) ? true : null,
            // v3.12.0 (Skeptiker M7) — die Verbrauchsrechnung lässt diesen Stand aus (Ausreißer, höher als der nächste)
            'skipped'         => isset($skipped[(string)($r['id'] ?? '')]) ? true : null,
            'untrusted'       => Sanitize::untrusted(['note' => $this->readingNote($r)]),
        ]), array_slice($all, -$limit));
        return ['utility' => $u, 'meter' => (string)$target['id'], 'unit' => $unit, 'count' => count($all), 'readings' => $rows]
            + ($truncated ? ['truncated' => true] : []);
    }

    /**
     * v3.12.0 (Nachprüfung A2) — Notiz einer Ablesung: Die aus dem Posteingang nennt den Dateinamen („Aus dem Dokument
     * „Rechnung_…pdf“ übernommen“), der eine Nummer tragen kann, die hier niemand mehr kennt. Ohne den Schalter fällt sie
     * weg, `source: inbox` sagt dasselbe; jede andere Notiz wie ein Zitat. Gespeichert bleibt sie, die Oberfläche zeigt sie.
     *
     * @param array<string,mixed> $r
     */
    private function readingNote(array $r): ?string
    {
        if (!is_string($r['note'] ?? null)) return null;
        $mask = $this->householdMask();
        return $mask->active() && ($r['source'] ?? null) === 'inbox' ? null : $mask->quote($r['note']);
    }

    // ── get_insights ─────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function insights(array $args, McpContext $ctx): array
    {
        $category = $args['category'] ?? null;
        $out = [];
        $truncated = false;
        // am Tag der Anfrage wie jedes Werkzeug (die Rechte-Hinweise hängen am Fenster um diesen Tag)
        foreach ($this->services->get(RecommendationService::class)->atDate($ctx->today)->all() as $r) {
            if ($category !== null && ($r['category'] ?? null) !== $category) continue;
            if (count($out) >= self::MAX_INSIGHTS) { $truncated = true; break; }
            // Zahlen aus den Parametern, nicht aus dem Satz; eine Empfehlung ist eine Schätzung (Konzept §3.1)
            // v3.12.0 (Skeptiker N12) — eingetragene Beträge und Zähler heißen nicht „geschätzt“, dazu die Einheit
            $figures = [];
            foreach ((array)($r['evidence'] ?? []) as $k => $v) {
                if (is_string($k) && (is_int($v) || is_float($v)) && is_finite((float)$v)) {
                    $figures[] = self::compact(['name' => $k, 'value' => round((float)$v, 3), 'unit' => $this->figureUnit($k),
                        'basis' => in_array($k, self::ENTERED_FIGURES, true) ? 'entered' : 'estimated']);
                }
            }
            $id = (string)$r['id'];
            $out[] = self::compact([
                'id'        => $id,
                'rule'      => str_starts_with($id, 'rights-') ? 'rights' : (string)preg_replace('/_[0-9a-f]{12}$/', '', $id),
                'severity'  => (string)$r['severity'],
                'category'  => (string)$r['category'],
                'basis'     => 'estimated',
                'figures'   => $figures,
                // v3.12.0 (Skeptiker M10) — Text aus dem Katalog: eigene Länge
                // v3.12.0 — Titel und Text nennen Anbieter und Zähler: dieselbe Maske wie dort (Kennnummern, Angaben für Briefe)
                'untrusted' => Sanitize::finding($this->masked((string)$r['title']), $this->masked((string)($r['detail'] ?? ''))),
            ]);
        }
        return ['insights' => $out] + ($truncated ? ['truncated' => true] : []) + $this->rightsChecked();
    }

    /** Einheit einer Kennzahl aus ihrem Namen (`amount_eur`, `months` …); ohne Angabe keine. */
    private function figureUnit(string $name): ?string
    {
        return match (true) {
            str_ends_with($name, '_eur') || $name === 'eur' => $this->currency(),
            str_ends_with($name, '_kwh') || $name === 'kwh' => 'kWh',
            str_ends_with($name, '_m3') || $name === 'm3'   => 'm³',
            str_ends_with($name, '_pct') || $name === 'pct' => '%',
            $name === 'months' || str_ends_with($name, '_months') => 'months',
            $name === 'days' || str_ends_with($name, '_days')     => 'days',
            default => null,
        };
    }

    /**
     * v3.11.0 (Skeptiker M7) — keine Rechte-Hinweise heißt nicht „alles in Ordnung“: Ohne erfasste
     * Rechnung laufen die Rechnungsprüfungen gar nicht, außerhalb Deutschlands keine. Dieselbe Zahl wie
     * `bills_recorded` in GET /api/rights.
     *
     * @return array{rights_check?: array{enabled:bool, bills_recorded:int}}
     */
    private function rightsChecked(): array
    {
        if (!$this->services->has(RightsService::class)) return [];
        $bills = $this->services->get(BillService::class);
        $n = 0;
        foreach (Utilities::keys() as $u) {
            if (Utilities::supportsBillCheck($u)) $n += count($bills->list($u));
        }
        return ['rights_check' => ['enabled' => $this->services->get(RightsService::class)->enabled(), 'bills_recorded' => $n]];
    }

    // ── get_yearly_report ────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function yearlyReport(array $args, McpContext $ctx): array
    {
        // ohne Angabe wie GET /api/reports/yearly: das Vorjahr
        $year = (int)($args['year'] ?? ((int)substr($ctx->today, 0, 4) - 1));
        $filter = isset($args['utilities']) ? array_values(array_unique($args['utilities'])) : null;
        $data = $this->services->get(PdfReportService::class)->yearlyData($year, ['utilities' => $filter, 'today' => $ctx->today]);
        $consumption = $this->services->get(ConsumptionService::class);
        $meters = $this->services->get(MeterService::class);
        $cur = $this->currency();
        [$start, $end] = ["$year-01-01", ($year + 1) . '-01-01'];
        $cost = [Provenance::class, 'monthCost'];
        $co2 = static fn(array $r): string => Provenance::weakest(Provenance::month($r), 'assumed');

        $utilities = [];
        foreach ($data['utilities'] as $x) {
            $u = (string)$x['utility'];
            $members = $this->membersOf($u, null);
            $rows = $this->memberRows($u, $members);
            // v3.12.0 (Skeptiker H4, M4) — Einspeisung als `revenue`, Erzeugung ohne Geld; ohne Vertrag keine 0 €
            $money = self::moneyField($u);
            $priced = $this->pricedInYear($rows, $year);
            $utilities[] = self::compact([
                'utility'     => $u,
                'label'       => (string)$x['label'],
                'accounting_kind' => Utilities::accountingKind($u),
                'consumption' => Provenance::num((float)$x['consumption'], (string)$x['unit'], $this->windowParts($u, $members, $rows, $start, $end) ?: ['measured' => 1.0]),
                (string)($money ?? 'cost') => $money !== null && $priced ? Provenance::money((float)$x['cost'], $cur, $this->windowParts($u, $members, $rows, $start, $end, 'cost', $cost, 'entered') ?: ['entered' => 1.0]) : null,
                'no_contract' => $money !== null && !$priced ? true : null,
                // CO₂ nur beim Verbrauch: Eine Summe über die Arten zählte sonst die Einspeisung als Ausstoß
                'co2_kg'      => $money === 'cost' && (float)$x['co2_kg'] > 0 ? Provenance::num((float)$x['co2_kg'], 'kg',
                    $this->windowParts($u, $members, $rows, $start, $end, 'co2_kg', $co2, 'assumed') ?: ['assumed' => 1.0], ['decimals' => 1]) : null,
            ]);
        }

        $outMeters = [];
        foreach ($data['meters'] as $x) {
            $u = (string)$x['utility'];
            $m = $meters->get($u, (string)$x['meter_id']);
            $rows = $m !== null ? $this->memberRows($u, [$m]) : [];
            $byYm = array_column($rows, null, 'ym');
            $months = [];
            $money = self::moneyField($u);
            $priced = $this->pricedInYear($rows, $year);
            foreach ($x['months'] as $mo) {
                $months[] = ['ym' => (string)$mo['ym'], 'value' => (float)$mo['value']]
                    + ($money !== null && $priced ? [$money => (float)$mo['cost']] : [])
                    + ['basis' => isset($byYm[$mo['ym']]) ? Provenance::month($byYm[$mo['ym']]) : 'distributed'];
            }
            $outMeters[] = self::compact([
                'utility'   => $u,
                'id'        => (string)$x['meter_id'],
                'untrusted' => Sanitize::untrusted(['name' => $this->masked((string)$x['meter_name'])]),
                'unit'      => (string)$x['unit'],
                'counts_in_totals' => $m !== null ? MeterService::countsInTotals($m) : null,
                'months'    => $months,
                'sum'       => Provenance::num((float)$x['kpis']['sum'], (string)$x['unit'],
                    ($m !== null ? $this->windowParts($u, [$m], $rows, $start, $end) : []) ?: ['measured' => 1.0]),
                (string)($money ?? 'cost') => $money !== null && $priced ? Provenance::money((float)$x['kpis']['cost'], $cur,
                    ($m !== null ? $this->windowParts($u, [$m], $rows, $start, $end, 'cost', $cost, 'entered') : []) ?: ['entered' => 1.0]) : null,
                'no_contract' => $money !== null && !$priced ? true : null,
                'included_in_parent' => $money !== null && !$priced && $m !== null && !empty($m['parent_meter_id']) ? true : null,
            ]);
        }

        $out = ['year' => $year, 'currency' => $cur, 'utilities' => $utilities, 'meters' => $outMeters];
        if (($args['billing_period'] ?? false) === true) {
            $billing = $this->services->get(BillingPeriodService::class);
            $out['billing_periods'] = [];
            foreach ($data['utilities'] as $x) {
                $u = (string)$x['utility'];
                if (!Utilities::supportsBillCheck($u)) continue;
                $members = $this->membersOf($u, null);
                $periods = array_values(array_filter($billing->forUtility($u, false, $ctx->today)['periods'],
                    fn($p) => (int)substr((string)$p['until'], 0, 4) === $year));
                $out['billing_periods'][] = ['utility' => $u, 'unit' => self::consumptionUnit($u),
                    'periods' => $this->billingRows($u, $periods, $members, $this->memberRows($u, $members))];
            }
        }
        return $out;
    }

    /**
     * v3.12.0 (Skeptiker M4) — Gilt in dem Jahr für einen der Monate ein Vertrag oder ein Preis?
     *
     * @param list<array<string,mixed>> $rows Monatszeilen der Zähler
     */
    private function pricedInYear(array $rows, int $year): bool
    {
        foreach ($rows as $r) {
            if ((int)substr((string)($r['ym'] ?? ''), 0, 4) !== $year) continue;
            if (($r['contract_id'] ?? null) !== null || abs((float)($r['cost'] ?? 0.0)) >= 0.005) return true;
        }
        return false;
    }

    // ── Ausgabe-Schemas ──────────────────────────────────────────────────

    private static function overviewSchema(): array
    {
        $num = Num::schema();
        $contract = self::obj([
            'contract_id' => self::str(), 'untrusted' => self::untrustedSchema('provider', 'tariff'),
            'prices' => self::pricesSchema(), 'status' => self::statusSchema(), 'settle_on' => self::date(),
            'cancel_by' => self::date(), 'price_increase_from' => self::date(), 'price_increase' => self::priceIncreaseSchema(),
        ], ['contract_id']);
        $count = self::obj(['count' => self::int()], ['count']);
        return self::obj([
            'as_of' => self::date(), 'currency' => self::str(),
            'meters' => self::arr(self::obj([
                'utility' => self::utilityArg(), 'utility_label' => self::str(), 'accounting_kind' => self::str(ConsumptionTools::ACCOUNTING_KINDS), 'id' => self::str(),
                'untrusted' => self::untrustedSchema('name'), 'role' => self::str(), 'sub_meter' => self::bool(),
                'group_id' => self::str(), 'capture' => self::str(['counter', 'period']),
                'last_reading' => self::obj(['date' => self::date(), 'counter' => $num], ['date', 'counter']),
                'last_period_until' => self::date(), 'days_since_reading' => ['type' => 'integer'], 'reading_due' => self::bool(),
                'month_to_date' => $num, 'year_to_date' => $num,
                'forecast_12m' => self::obj(['quantity' => $num, 'cost' => $num, 'revenue' => $num, 'no_contract' => self::bool()], ['quantity']),
                'contract' => $contract,
                'group_contract' => self::obj(['group_id' => self::str(), 'untrusted' => self::untrustedSchema('name'), 'contract' => $contract, 'same_as' => self::str()],
                    ['group_id']),
                'tank' => self::obj(['stock' => $num, 'capacity' => $num, 'percent' => $num, 'estimated_from' => self::date()], ['stock']),
            ], ['utility', 'id', 'capture', 'reading_due'])),
            'deadlines' => self::obj(['due' => $count, 'overdue' => $count,
                'next' => self::obj(['kind' => self::str(AgendaService::KINDS), 'date' => self::date(), 'untrusted' => self::untrustedSchema('title')],
                    ['kind', 'date'])], ['due', 'overdue']),
            'suspect_readings' => $count,
        ], ['as_of', 'currency', 'meters', 'deadlines']);
    }

    private static function deadlinesSchema(): array
    {
        return self::obj([
            'as_of' => self::date(), 'days' => self::int(1), 'count' => self::int(0),
            'events' => self::arr(self::obj([
                'uid' => self::str(), 'kind' => self::str(AgendaService::KINDS), 'date' => self::date(),
                'date_source' => self::str(['entered', 'derived']), 'severity' => self::str(['overdue', 'urgent', 'due', 'upcoming']),
                'due_now' => self::bool(), 'done' => self::bool(),
                'ref' => self::obj(array_fill_keys(self::REF_KEYS, self::str())),
                'untrusted' => self::untrustedSchema('title', 'assignee'),
            ], ['uid', 'kind', 'date', 'date_source', 'severity', 'due_now']), self::MAX_EVENTS),
            'truncated' => self::bool(),
        ], ['as_of', 'days', 'events']);
    }

    private static function readingsSchema(): array
    {
        return self::obj([
            'utility' => self::utilityArg(), 'meter' => self::str(), 'unit' => self::str(), 'count' => self::int(),
            'readings' => self::arr(self::obj([
                'id' => self::str(), 'meter_id' => self::str(), 'date' => self::date(), 'counter' => self::number(),
                'basis' => self::str(['measured', 'estimated']), 'source' => self::str(), 'is_billing_only' => self::bool(),
                'is_suspect' => self::bool(), 'is_future' => self::bool(), 'skipped' => self::bool(), 'untrusted' => self::untrustedSchema('note'),
            ], ['date', 'counter', 'basis']), self::MAX_READINGS),
            'truncated' => self::bool(),
        ], ['utility', 'meter', 'unit', 'count', 'readings']);
    }

    private static function insightsSchema(): array
    {
        return self::obj([
            'insights' => self::arr(self::obj([
                'id' => self::str(), 'rule' => self::str(), 'severity' => self::str(), 'category' => self::str(),
                'basis' => self::str(['estimated']),
                'figures' => self::arr(self::obj(['name' => self::str(), 'value' => self::number(), 'unit' => self::str(), 'basis' => self::basisSchema()],
                    ['name', 'value', 'basis'])),
                'untrusted' => self::untrustedSchema('title', 'note'),
            ], ['id', 'rule', 'severity', 'category', 'basis']), self::MAX_INSIGHTS),
            'truncated' => self::bool(),
            'rights_check' => self::obj(['enabled' => self::bool(), 'bills_recorded' => self::int()], ['enabled', 'bills_recorded']),
        ], ['insights']);
    }

    private static function yearlySchema(): array
    {
        $num = Num::schema();
        return self::obj([
            'year' => self::int(2000), 'currency' => self::str(),
            'utilities' => self::arr(self::obj(['utility' => self::utilityArg(), 'label' => self::str(), 'accounting_kind' => self::str(ConsumptionTools::ACCOUNTING_KINDS),
                'consumption' => $num, 'cost' => $num, 'revenue' => $num, 'no_contract' => self::bool(), 'co2_kg' => $num], ['utility', 'consumption'])),
            'meters' => self::arr(self::obj([
                'utility' => self::utilityArg(), 'id' => self::str(), 'untrusted' => self::untrustedSchema('name'), 'unit' => self::str(),
                'counts_in_totals' => self::bool(),
                'months' => self::arr(self::obj(['ym' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}$'], 'value' => self::number(),
                    'cost' => self::number(), 'revenue' => self::number(), 'basis' => self::basisSchema()], ['ym', 'value', 'basis']), 12),
                'sum' => $num, 'cost' => $num, 'revenue' => $num, 'no_contract' => self::bool(), 'included_in_parent' => self::bool(),
            ], ['utility', 'id', 'months'])),
            'billing_periods' => self::arr(self::obj(['utility' => self::utilityArg(), 'unit' => self::str(),
                'periods' => self::arr(self::billingRowSchema())], ['utility', 'periods'])),
        ], ['year', 'currency', 'utilities', 'meters']);
    }
}
