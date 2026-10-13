<?php
declare(strict_types=1);

namespace Energietracker\Services;

use Energietracker\Config\Utilities;
use Energietracker\Services\Pdf\Chart;

/**
 * v3.4.0 (F1025) — Das Datenmodell des Jahresberichts.
 *
 * Eine Quelle für PDF (PdfReportService) und Druckansicht (report-print.js,
 * über GET /api/reports/yearly → `model`). Das Modell trägt Zahlen für die
 * Grafiken UND die fertigen Sätze in der Sprache der Anfrage — beide Ausgaben
 * zeigen so dieselben Texte.
 *
 * Zielgruppe: Menschen ohne Technikhintergrund. Vorne das Wichtigste (große
 * Zahlen, vier Ampeln, die drei wichtigsten Aufgaben), dann was sich gelohnt
 * hat, was zu tun ist, Fristen, Geld, je Verbrauchsart eine Seite und
 * Themenseiten, die nur mit Daten erscheinen. Die heutigen Monatstabellen
 * stehen als Anhang „Zahlen im Detail“ (`detail`).
 *
 * Zeitbezug: das Kalenderjahr (Standard Vorjahr). Was sich nur „Stand heute“
 * sagen lässt (Saldo, Fristen, Aufgaben), trägt `as_of_today` und erscheint
 * im Bericht in einem Kasten mit dem Datum.
 *
 * Jede Sektion ist einzeln abgesichert: Wirft ein Dienst, fehlt diese Sektion
 * (Name in `errors`), der Bericht entsteht trotzdem — wie im SummaryService.
 *
 * Summen nur über Zähler, die in Summen zählen (MeterService::countsInTotals):
 * Subzähler und Speicher- bzw. Wärmemengenzähler stehen als „davon“ daneben.
 */
final class ReportModelService
{
    public const VERSION = 1;
    /** Verlauf in den Kacheln: so viele Jahre bis einschließlich Berichtsjahr. */
    public const HISTORY_YEARS = 3;
    /** Fristen und Termine: so weit voraus. */
    public const AGENDA_DAYS = 365;

    /** @var array<string,array> */
    private array $rowCache = [];
    /** @var list<string> */
    private array $errors = [];
    /** @var array<string,true> */
    private array $terms = [];
    /** @var array<string,true> */
    private array $sources = [];
    private string $today = '';
    private int $year = 0;

    public function __construct(
        private MeterService $meters,
        private ConsumptionService $consumption,
        private ContractService $contracts,
        private SettingsService $settings,
        private I18nService $i18n,
        private BenchmarkService $benchmark,
        private RecommendationService $recommendations,
        private ReportAdviceService $advice,
        private ?AgendaService $agenda = null,
        private ?PvSummaryService $pv = null,
        private ?HeatPumpService $heatPumps = null,
        private ?FlexibilityService $flexibility = null,
        private ?EvccService $evcc = null,
        private ?EvChargingReportService $evReport = null,
        private ?TenancyService $tenancies = null,
        private ?TenancyBudgetService $tenancyBudget = null,
        private ?Co2SplitService $co2Split = null,
        private ?BillService $bills = null,
        private ?TariffSwitchService $tariffSwitch = null,
        private ?DeliveryService $deliveries = null,
        private ?ForecastService $forecasts = null,
    ) {}

    /**
     * @param array{utilities?:?list<string>,detail?:bool,today?:string} $opts
     * @return array<string,mixed>
     */
    public function build(int $year, array $opts = []): array
    {
        $this->rowCache = []; $this->errors = []; $this->terms = []; $this->sources = [];
        $this->today = (string)($opts['today'] ?? date('Y-m-d'));
        $this->year = $year;
        $utilities = $this->reportUtilities($opts['utilities'] ?? null);
        $consumptionU = array_values(array_filter($utilities, fn($u) => Utilities::accountingKind($u) === 'consumption'));

        // Jahreswerte je Art für das Berichtsjahr und die Jahre davor
        $years = range($year - self::HISTORY_YEARS + 1, $year);
        $yd = [];
        foreach ($utilities as $u) foreach (array_merge([$year - 1], $years) as $y) $yd[$u][$y] ??= $this->safe("year.$u", fn() => $this->yearData($u, $y), $this->emptyYear());

        $pvYear = in_array('pv_erzeugung', $utilities, true) || in_array('pv_einspeisung', $utilities, true)
            ? $this->safe('pv', fn() => $this->pvYears(), []) : [];
        $totals = $this->totals($consumptionU, $yd, $years);
        $contracts = $this->safe('contracts', fn() => $this->contractFacts($utilities), []);
        $agenda = $this->safe('agenda', fn() => $this->agendaEvents($utilities), []);
        $tenancy = $this->safe('tenancy', fn() => $this->tenancyFacts(), null);
        $hp = $this->safe('heatPump', fn() => $this->heatPumpFacts($utilities), []);
        $flex = $this->safe('flexibility', fn() => $this->flexFacts($utilities), null);
        $ev = $this->safe('ev', fn() => $this->evFacts($utilities), null);
        $eff = $this->safe('efficiency', fn() => $this->benchmark->efficiency($year), []);

        $facts = [
            'year' => $year, 'today' => $this->today,
            'country' => (string)$this->settings->get('country', 'DE'),
            'contracts' => $contracts,
            'heating' => $this->heatingFacts($consumptionU, $yd),
            'anomalies' => $this->safe('anomalies', fn() => $this->anomalyFacts($consumptionU), []),
            'efficiency' => $this->efficiencyFacts($eff, $consumptionU, $yd),
            'heat_pumps' => $hp,
            'pv' => $this->pvFacts($pvYear[$year] ?? null, $contracts),
            'flex' => $flex['meters'] ?? [],
            'module3_eur' => $flex['module3_eur'] ?? 0.0,
            'tenancy' => $tenancy['budget'] ?? null,
            'co2_claim' => $tenancy['co2'] ?? null,
            'agenda' => $agenda,
            'bills' => $this->safe('bills', fn() => $this->billFacts($consumptionU), []),
            'baselines' => $this->safe('baselines', fn() => $this->baselineFacts($consumptionU, $yd), []),
            'ev' => $ev !== null ? $ev['facts'] : null,
            'totals' => $totals,
            'dismissed' => $this->recommendations->dismissedIds($this->today),
        ];
        $advice = $this->safe('advice', fn() => $this->advice->evaluate($facts), ['tasks' => [], 'successes' => [], 'infos' => [], 'lights' => []]);

        $pages = [];
        foreach ($consumptionU as $u) {
            $p = $this->safe("page.$u", fn() => $this->utilityPage($u, $yd, $eff, $pvYear[$year] ?? null), null);
            if ($p !== null) $pages[] = $p;
        }

        $model = [
            'version'       => self::VERSION,
            'year'          => $year,
            'today'         => $this->today,
            'today_text'    => $this->i18n->date($this->today),
            'lang'          => $this->i18n->locale(),
            'created_on'    => date('Y-m-d'),
            'location_name' => (string)$this->settings->get('location_name', ''),
            'options'       => ['utilities' => $opts['utilities'] ?? null, 'detail' => !empty($opts['detail'])],
            'texts'         => $this->texts($year),
            'utilities'     => array_map(fn($u) => $this->utilityInfo($u), $utilities),
            'headline'      => [
                'tiles'     => $this->tiles($totals, $pvYear, $years),
                'lights'    => $advice['lights'],
                'top_tasks' => array_slice($advice['tasks'], 0, 3),
            ],
            'successes'     => $advice['successes'],
            'tasks'         => $advice['tasks'],
            'infos'         => $advice['infos'],
            'agenda'        => $this->safe('agendaSection', fn() => $this->agendaSection($agenda, $contracts), ['events' => [], 'timelines' => []]),
            'money'         => $this->safe('money', fn() => $this->moneySection($consumptionU, $utilities, $yd, $totals, $contracts), null),
            'pages'         => $pages,
            'topics'        => [
                'pv'          => $this->safe('topic.pv', fn() => $this->pvTopic($pvYear, $contracts), null),
                'heat_pump'   => $this->safe('topic.heatPump', fn() => $this->heatPumpTopic($hp), null),
                'ev'          => $ev !== null ? $ev['topic'] : null,
                'flexibility' => $flex !== null ? $flex['topic'] : null,
                'tenancy'     => $tenancy !== null ? $tenancy['topic'] : null,
            ],
        ];
        if (!empty($opts['detail'])) {
            $model['appendix'] = $this->safe('appendix', fn() => $this->appendix($consumptionU, $utilities), []);
        }
        $model['glossary'] = $this->glossary();
        $model['sources'] = $this->sourcesList();
        $model['errors'] = $this->errors;
        return $model;
    }

    // ── Grundlagen ───────────────────────────────────────────────────────

    /** Aktive Arten, auf Wunsch eingeschränkt (`?utilities=`); Reihenfolge der SSOT. */
    private function reportUtilities(?array $filter): array
    {
        $active = $this->settings->get('active_utilities', ['gas', 'strom', 'wasser']);
        if (!is_array($active) || $active === []) $active = Utilities::keys();
        $out = array_values(array_filter(Utilities::keys(), fn($u) => in_array($u, $active, true)));
        if ($filter !== null) $out = array_values(array_filter($out, fn($u) => in_array($u, $filter, true)));
        return $out;
    }

    /** @template T @param callable():T $fn @param T $fallback @return T */
    private function safe(string $name, callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->errors[] = $name;
            return $fallback;
        }
    }

    private function rows(string $u, array $m): array
    {
        $key = $u . '|' . ($m['id'] ?? '');
        return $this->rowCache[$key] ??= $this->consumption->forMeter($u, $m);
    }

    private static function valueField(string $u): string
    {
        return (Utilities::get($u)['consumption_unit'] ?? '') === 'kWh' ? 'kwh' : 'm3';
    }

    private static function unit(string $u): string
    {
        return (Utilities::get($u)['consumption_unit'] ?? '') === 'kWh' ? 'kWh' : 'm³';
    }

    private function decimals(string $u): int
    {
        return self::unit($u) === 'kWh' ? 0 : 1;
    }

    /** @return list<array> Zähler, die in Summen zählen */
    private function rootMeters(string $u): array
    {
        return array_values(array_filter($this->meters->list($u), fn($m) => MeterService::countsInTotals($m)));
    }

    private function emptyYear(): array
    {
        return ['value' => 0.0, 'cost' => 0.0, 'kwh_cost' => 0.0, 'co2' => 0.0, 'adj' => null, 'hdd' => 0.0,
                'months' => array_fill(1, 12, null), 'cost_months' => array_fill(1, 12, null), 'estimated' => array_fill(1, 12, false),
                'bonus' => 0.0, 'bonus_months' => [], 'coverage' => 0, 'has_contract' => false, 'adj_months' => 0, 'full_months' => 0];
    }

    /** Jahreswerte einer Art über die Zähler, die in Summen zählen. */
    private function yearData(string $u, int $y): array
    {
        $out = $this->emptyYear();
        $vf = self::valueField($u);
        $adj = 0.0; $adjN = 0;
        foreach ($this->rootMeters($u) as $m) {
            foreach ($this->rows($u, $m) as $r) {
                if ((int)($r['year'] ?? 0) !== $y) continue;
                $mo = (int)$r['month'];
                $v = (float)($r[$vf] ?? 0);
                $out['value'] += $v;
                $out['cost'] += (float)($r['cost'] ?? 0);
                $out['kwh_cost'] += (float)($r['kwh_cost'] ?? 0);
                $out['co2'] += (float)($r['co2_kg'] ?? 0);
                $out['hdd'] += (float)($r['hdd'] ?? 0);
                $out['months'][$mo] = ($out['months'][$mo] ?? 0.0) + $v;
                $out['cost_months'][$mo] = ($out['cost_months'][$mo] ?? 0.0) + (float)($r['cost'] ?? 0);
                if ((int)($r['estimated_days'] ?? 0) > 0) $out['estimated'][$mo] = true;
                if ((float)($r['bonus_eur'] ?? 0) > 0) {
                    $out['bonus'] += (float)$r['bonus_eur'];
                    $out['bonus_months'][$mo] = ($out['bonus_months'][$mo] ?? 0.0) + (float)$r['bonus_eur'];
                }
                if (!empty($r['contract_id'])) $out['has_contract'] = true;
                if (($r['heat_adjusted'] ?? null) !== null) { $adj += (float)$r['heat_adjusted']; $adjN++; }
                if ((int)($r['days'] ?? 0) >= (int)date('t', mktime(0, 0, 0, $mo, 1, $y))) $out['full_months']++;
            }
        }
        $out['coverage'] = count(array_filter($out['months'], fn($v) => $v !== null));
        $out['adj_months'] = $adjN;
        // witterungsbereinigt nur, wenn jeder Monat mit Verbrauch einen Wert hat
        $out['adj'] = $adjN > 0 && $adjN >= $out['coverage'] && $out['coverage'] >= 12 ? $adj : null;
        return $out;
    }

    /** Ø Arbeitspreis eines Jahres in ct je Einheit (nur der verbrauchsabhängige Teil) — null ohne Preis. */
    private static function priceCt(array $y): ?float
    {
        return $y['value'] > 0 && $y['kwh_cost'] > 0 ? $y['kwh_cost'] / $y['value'] * 100 : null;
    }

    private function utilityInfo(string $u): array
    {
        $hex = (string)(Utilities::get($u)['color'] ?? '#64748b');
        return [
            'utility' => $u, 'label' => $this->i18n->utilityLabel($u), 'unit' => self::unit($u),
            'accounting_kind' => Utilities::accountingKind($u), 'color' => $hex,
            'print_color' => self::hex(Chart::printColor($hex)),
        ];
    }

    private static function hex(array $c): string
    {
        return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
    }

    private function term(string ...$ids): void
    {
        foreach ($ids as $id) $this->terms[$id] = true;
    }

    // ── Summen und Kacheln ───────────────────────────────────────────────

    /**
     * Kosten, Verbrauch (Heizarten witterungsbereinigt, wo möglich) und CO₂ der
     * Verbrauchsarten. Preis- und Mengeneffekt je Art:
     *   Mengeneffekt = (Menge jetzt − Menge Vorjahr) × Ø Preis Vorjahr
     *   Preiseffekt  = Kostenänderung − Mengeneffekt
     */
    private function totals(array $consumptionU, array $yd, array $years): array
    {
        $y = $this->year; $p = $y - 1;
        $t = ['cost_now' => null, 'cost_prev' => null, 'cost_pct' => null, 'price_effect' => 0.0, 'volume_effect' => 0.0,
              'consumption_now' => null, 'consumption_prev' => null, 'consumption_pct' => null, 'consumption_adjusted' => false,
              'co2_now' => null, 'co2_prev' => null, 'co2_pct' => null,
              'history' => ['years' => $years, 'cost' => [], 'consumption' => [], 'co2' => []]];
        $cn = $cp = 0.0; $hasCost = false;
        $en = $ep = 0.0; $hasEnergy = false; $adjUsed = false;
        $co2n = $co2p = 0.0;
        foreach ($consumptionU as $u) {
            $now = $yd[$u][$y]; $prev = $yd[$u][$p];
            $co2n += $now['co2']; $co2p += $prev['co2'];
            if ($now['has_contract'] && $now['cost'] != 0.0) {
                $cn += $now['cost']; $hasCost = true;
                if ($prev['has_contract'] && $prev['value'] > 0 && $prev['coverage'] >= 12 && $now['coverage'] >= 12) {
                    $cp += $prev['cost'];
                    $pp = $prev['cost'] / $prev['value'];
                    $vol = ($now['value'] - $prev['value']) * $pp;
                    $t['volume_effect'] += $vol;
                    $t['price_effect'] += ($now['cost'] - $prev['cost']) - $vol;
                }
            }
            if (self::unit($u) === 'kWh' && $now['value'] > 0 && $prev['value'] > 0 && $now['coverage'] >= 12 && $prev['coverage'] >= 12) {
                $useAdj = Utilities::isHgtRelevant($u) && $now['adj'] !== null && $prev['adj'] !== null;
                $en += $useAdj ? $now['adj'] : $now['value'];
                $ep += $useAdj ? $prev['adj'] : $prev['value'];
                $hasEnergy = true; $adjUsed = $adjUsed || $useAdj;
            }
        }
        if ($hasCost) {
            $t['cost_now'] = round($cn, 2);
            $t['cost_prev'] = $cp > 0 ? round($cp, 2) : null;
            $t['cost_pct'] = $cp > 0 ? round(($cn / $cp - 1) * 100, 1) : null;
            if ($cp <= 0) { $t['price_effect'] = 0.0; $t['volume_effect'] = 0.0; }
        }
        if ($hasEnergy && $ep > 0) {
            $t['consumption_now'] = round($en, 1); $t['consumption_prev'] = round($ep, 1);
            $t['consumption_pct'] = round(($en / $ep - 1) * 100, 1);
            $t['consumption_adjusted'] = $adjUsed;
            // witterungsbereinigt heißt: aus Temperaturen gerechnet — Quelle nennen (CC BY 4.0)
            if ($adjUsed) { $this->term('heatAdjusted'); $this->sources['weather'] = true; }
        }
        $t['co2_now'] = round($co2n, 1); $t['co2_prev'] = $co2p > 0 ? round($co2p, 1) : null;
        $t['co2_pct'] = $co2p > 0 ? round(($co2n / $co2p - 1) * 100, 1) : null;
        foreach ($years as $hy) {
            $c = 0.0; $e = 0.0; $k = 0.0;
            foreach ($consumptionU as $u) {
                $d = $yd[$u][$hy];
                if ($d['has_contract']) $c += $d['cost'];
                if (self::unit($u) === 'kWh') $e += Utilities::isHgtRelevant($u) && $d['adj'] !== null ? $d['adj'] : $d['value'];
                $k += $d['co2'];
            }
            $t['history']['cost'][] = $c > 0 ? round($c, 2) : null;
            $t['history']['consumption'][] = $e > 0 ? round($e, 1) : null;
            $t['history']['co2'][] = $k > 0 ? round($k, 1) : null;
        }
        $t['price_effect'] = round($t['price_effect'], 2);
        $t['volume_effect'] = round($t['volume_effect'], 2);
        return $t;
    }

    /** Große Zahlen auf Seite 1 mit Verlauf über drei Jahre. */
    private function tiles(array $t, array $pvYear, array $years): array
    {
        $prev = $this->year - 1;
        $tiles = [];
        $vs = fn(?float $pct) => $pct === null ? $this->i18n->t('report.tile.noPrevious', ['prev' => $prev])
            : $this->i18n->t('report.tile.vsPrevious', ['pct' => $this->advice->pct($pct), 'prev' => $prev]);
        if ($t['cost_now'] !== null) {
            $tiles[] = ['key' => 'cost', 'label' => $this->i18n->t('report.tile.cost'), 'value' => $t['cost_now'],
                'value_text' => $this->i18n->money(round($t['cost_now']), 0), 'pct' => $t['cost_pct'], 'sub' => $vs($t['cost_pct']),
                'spark' => $t['history']['cost'], 'better' => 'lower'];
        }
        if ($t['consumption_now'] !== null || array_filter($t['history']['consumption'])) {
            $now = $t['consumption_now'] ?? end($t['history']['consumption']);
            $tiles[] = ['key' => 'consumption',
                'label' => $this->i18n->t($t['consumption_adjusted'] ? 'report.tile.consumptionAdjusted' : 'report.tile.consumption'),
                'value' => $now, 'value_text' => $now !== null ? $this->i18n->number((float)$now, 0) . ' kWh' : '–',
                'pct' => $t['consumption_pct'], 'sub' => $vs($t['consumption_pct']), 'spark' => $t['history']['consumption'], 'better' => 'lower'];
        }
        if (($t['co2_now'] ?? 0) > 0) {
            $tiles[] = ['key' => 'co2', 'label' => $this->i18n->t('report.tile.co2'), 'value' => $t['co2_now'],
                'value_text' => $this->co2Text((float)$t['co2_now']), 'pct' => $t['co2_pct'], 'sub' => $vs($t['co2_pct']),
                'spark' => $t['history']['co2'], 'better' => 'lower'];
        }
        $pv = $pvYear[$this->year] ?? null;
        if ($pv !== null && ($pv['pv_benefit_eur'] ?? null) !== null) {
            $spark = array_map(fn($y) => isset($pvYear[$y]['pv_benefit_eur']) ? (float)$pvYear[$y]['pv_benefit_eur'] : null, $years);
            $pp = $pvYear[$prev]['pv_benefit_eur'] ?? null;
            $pct = $pp !== null && (float)$pp > 0 ? round(((float)$pv['pv_benefit_eur'] / (float)$pp - 1) * 100, 1) : null;
            $tiles[] = ['key' => 'pv', 'label' => $this->i18n->t('report.tile.pv'), 'value' => (float)$pv['pv_benefit_eur'],
                'value_text' => $this->i18n->money(round((float)$pv['pv_benefit_eur']), 0), 'pct' => $pct, 'sub' => $vs($pct),
                'spark' => $spark, 'better' => 'higher'];
        }
        return $tiles;
    }

    private function co2Text(float $kg): string
    {
        return $kg >= 1000 ? $this->i18n->number($kg / 1000, 1) . ' t' : $this->i18n->number($kg, 0) . ' kg';
    }

    // ── Fakten für die Empfehlungen ──────────────────────────────────────

    /** Laufende und künftige Verträge (Stand heute), auch Gruppenverträge. */
    private function contractFacts(array $utilities): array
    {
        $out = [];
        foreach ($utilities as $u) {
            if (!Utilities::hasContracts($u)) continue;
            $targets = $this->contracts->targets($u);
            foreach ($targets as $target) {
                if (empty($target['is_group']) && !MeterService::inService($target)) continue;
                // mehrere Zähler einer Art: der Name gehört in die Überschrift („Strom · Wärmepumpe“)
                $label = $this->i18n->utilityLabel($u) . (count($targets) > 1 ? ' · ' . (string)($target['name'] ?? '') : '');
                $status = $this->consumption->contractStatus($u, $target);
                foreach ($status['contracts'] ?? [] as $c) {
                    if (empty($c['is_current']) && empty($c['is_future'])) continue;
                    $cid = (string)($c['contract_id'] ?? '');
                    $mid = (string)($target['id'] ?? '');
                    $row = [
                        'key' => "$u|$mid|$cid", 'utility' => $u, 'label' => $label, 'meter_id' => $mid,
                        'meter_name' => (string)($target['name'] ?? $mid), 'provider' => (string)(($c['provider'] ?? '') ?: ($c['tariff_name'] ?? '')),
                        'unit' => self::unit($u),
                        'switchable' => Utilities::isSwitchable($u),
                        'has_advance' => Utilities::hasAdvancePaymentContracts($u),
                        'is_current' => !empty($c['is_current']), 'is_future' => !empty($c['is_future']),
                        'start' => $c['start'] ?? null, 'end' => $c['end'] ?? null, 'effective_end' => $c['effective_end'] ?? null,
                        'is_open_ended' => !empty($c['is_open_ended']), 'renewed' => !empty($c['renewed']),
                        'cancel_by' => $c['cancel_by'] ?? null, 'days_to_cancel' => $c['days_to_cancel'] ?? null,
                        'cancel_missed' => !empty($c['cancel_missed']), 'switch_date' => $c['switch_date'] ?? null,
                        'price_increase' => $c['price_increase'] ?? null,
                        'projected_end_balance' => $c['projected_end_balance'] ?? null,
                        'current_advance' => $c['current_advance_amount'] ?? null,
                        'suggested_advance' => $c['suggested_advance'] ?? null,
                        'settle_date' => $c['effective_end'] ?? null,
                        'balance_path' => $c['balance_path'] ?? null,
                        'energy_cost_to_date' => $c['energy_cost_to_date'] ?? null,
                        'base_to_date' => $c['base_to_date'] ?? null,
                        'bonus_to_date' => $c['bonus_to_date'] ?? null,
                        'working_price_ct' => $c['current_working_price_ct'] ?? null,
                        'rec_r6' => RecommendationService::ruleId('r6', [$u, $mid, $cid]),
                        'rec_price' => is_array($c['price_increase'] ?? null)
                            ? RecommendationService::ruleId('r_price_increase', [$u, $mid, $cid, (string)$c['price_increase']['from']]) : '',
                        'expected_consumption' => null, 'offer' => null,
                    ];
                    // Wechselentscheidung: erwarteter Verbrauch und gespeichertes Angebot
                    if ($this->tariffSwitch !== null && empty($target['is_group']) && Utilities::hasAdvancePaymentContracts($u)
                        && !empty($c['is_current']) && ($row['cancel_by'] !== null || $row['price_increase'] !== null)) {
                        try {
                            $a = $this->tariffSwitch->analyze($u, $mid);
                            $row['expected_consumption'] = $a['expected_consumption'] ?? null;
                            $best = null;
                            foreach ($a['candidates'] ?? [] as $cand) {
                                if (!empty($cand['is_reference']) || ($cand['vs_reference_year2_eur'] ?? null) === null) continue;
                                if ($best === null || $cand['vs_reference_year2_eur'] < $best['vs_reference_year2_eur']) $best = $cand;
                            }
                            if ($best !== null) $row['offer'] = ['label' => (string)($best['label'] ?? ''), 'vs_reference_year2_eur' => (float)$best['vs_reference_year2_eur']];
                        } catch (\Throwable) {
                            // ohne Prognose kein Verbrauch fürs Vergleichsportal
                        }
                    }
                    $out[] = $row;
                }
            }
        }
        return $out;
    }

    private function agendaEvents(array $utilities): array
    {
        if ($this->agenda === null) return [];
        $out = [];
        foreach ($this->agenda->events(self::AGENDA_DAYS, $this->today) as $e) {
            // Teilbericht: Ereignisse anderer Arten weglassen
            $u = $e['ref']['utility'] ?? null;
            if ($u !== null && !in_array($u, $utilities, true)) continue;
            $e['days'] = ReportAdviceService::days($this->today, (string)$e['date']);
            $out[] = $e;
        }
        return $out;
    }

    /** Heizarten: witterungsbereinigter Verbrauch beider Jahre und Ø Arbeitspreis. */
    private function heatingFacts(array $consumptionU, array $yd): array
    {
        $out = [];
        foreach ($consumptionU as $u) {
            if (!Utilities::isHgtRelevant($u) || Utilities::isDelivery($u)) continue;
            $now = $yd[$u][$this->year]; $prev = $yd[$u][$this->year - 1];
            if ($now['adj'] === null || $prev['adj'] === null) continue;
            $out[] = ['utility' => $u, 'label' => $this->i18n->utilityLabel($u), 'adj_now' => $now['adj'], 'adj_prev' => $prev['adj'],
                      'price_ct' => self::priceCt($now)];
        }
        return $out;
    }

    /** Auffällige Monate des Berichtsjahres aus R1/R4 — ausgeblendete fehlen schon. */
    private function anomalyFacts(array $consumptionU): array
    {
        $out = [];
        foreach ($this->recommendations->all() as $r) {
            $rule = substr((string)$r['id'], 0, 3);
            if (!in_array($rule, ['r1_', 'r4_'], true)) continue;
            $ev = $r['evidence'] ?? [];
            $u = (string)($ev['utility'] ?? '');
            $ym = (string)($ev['ym'] ?? '');
            if (!in_array($u, $consumptionU, true) || !str_starts_with($ym, $this->year . '-')) continue;
            $meter = $this->meters->get($u, (string)($ev['meter_id'] ?? ''));
            if ($meter === null) continue;
            $row = null;
            foreach ($this->rows($u, $meter) as $x) if ((string)$x['ym'] === $ym) $row = $x;
            if ($row === null) continue;
            $vf = self::valueField($u);
            $expected = $rule === 'r1_' ? ($row['expected_heat'] ?? null) : $this->typicalMonth($u, $meter, $ym);
            if ($expected === null) continue;
            $price = (float)($row[$vf] ?? 0) > 0 && (float)($row['kwh_cost'] ?? 0) > 0 ? (float)$row['kwh_cost'] / (float)$row[$vf] * 100 : null;
            $out[] = ['id' => (string)$r['id'], 'utility' => $u, 'label' => $this->i18n->utilityLabel($u) . ($this->multiMeter($u) ? ' · ' . (string)$meter['name'] : ''),
                      'ym' => $ym, 'actual' => (float)($row[$vf] ?? 0), 'expected' => (float)$expected, 'unit' => self::unit($u), 'price_ct' => $price];
        }
        usort($out, fn($a, $b) => ($b['actual'] - $b['expected']) * ($b['price_ct'] ?? 0) <=> ($a['actual'] - $a['expected']) * ($a['price_ct'] ?? 0));
        return $out;
    }

    private function multiMeter(string $u): bool
    {
        return count($this->meters->list($u)) > 1;
    }

    /** Erwartung eines Monats ohne Wetterbezug: Mittel desselben Kalendermonats der anderen Jahre (wie die Anomalie-Erkennung). */
    private function typicalMonth(string $u, array $meter, string $ym): ?float
    {
        $vf = self::valueField($u);
        $vals = [];
        foreach ($this->rows($u, $meter) as $r) {
            if ((string)$r['ym'] === $ym || substr((string)$r['ym'], 5) !== substr($ym, 5)) continue;
            if ((int)($r['days'] ?? 0) < 20) continue;
            $vals[] = (float)($r[$vf] ?? 0);
        }
        if ($vals === []) return null;
        sort($vals);
        $n = count($vals);
        return $n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
    }

    private function efficiencyFacts(array $eff, array $consumptionU, array $yd): array
    {
        if (($eff['scale'] ?? null) === null) return [];
        $out = [];
        foreach ($eff['per_source'] ?? [] as $s) {
            $u = (string)($s['utility'] ?? '');
            if (!in_array($u, $consumptionU, true) || empty($s['class'])) continue;
            $out[] = ['utility' => $u, 'label' => $this->i18n->utilityLabel($u), 'class' => (string)$s['class'],
                      'kwh_per_m2' => (float)$s['kwh_per_m2'], 'kwh' => (float)($s['kwh'] ?? 0),
                      'price_ct' => self::priceCt($yd[$u][$this->year]),
                      'rec_id' => RecommendationService::ruleId('r7', ['efficiency', $u, (string)($eff['year'] ?? $this->year)])];
        }
        return $out;
    }

    private function heatPumpFacts(array $utilities): array
    {
        if ($this->heatPumps === null || !in_array('waerme', $this->allActive(), true)) return [];
        $r = $this->heatPumps->forYear($this->year);
        $ref = (float)(($r['reference'] ?? [])['air_water'] ?? HeatPumpService::FIELD_TEST['air_water']);
        $out = [];
        foreach ($r['pumps'] ?? [] as $p) {
            if (($p['jaz'] ?? null) === null || (int)($p['months_covered'] ?? 0) < 12) continue;
            // Preis des Wärmepumpenstroms aus dessen eigenen Monatszeilen
            $cost = 0.0; $kwh = 0.0;
            foreach ($p['elec_meter_ids'] ?? [] as $id) {
                $m = $this->meters->get('strom', (string)$id);
                if ($m === null) continue;
                foreach ($this->rows('strom', $m) as $row) {
                    if ((int)$row['year'] !== $this->year) continue;
                    $cost += (float)($row['kwh_cost'] ?? 0); $kwh += (float)($row['kwh'] ?? 0);
                }
            }
            $out[] = ['id' => (string)$p['heat_meter_id'], 'name' => (string)$p['name'], 'jaz' => (float)$p['jaz'],
                      'jaz_season' => $p['jaz_heating_season'] ?? null, 'reference' => $ref,
                      'reference_ground' => (float)(($r['reference'] ?? [])['ground_water'] ?? HeatPumpService::FIELD_TEST['ground_water']),
                      'elec_kwh' => (float)$p['elec_kwh'], 'heat_kwh' => (float)$p['heat_kwh'],
                      'price_ct' => $kwh > 0 && $cost > 0 ? $cost / $kwh * 100 : null, 'months' => $p['months'] ?? []];
        }
        return $out;
    }

    private function allActive(): array
    {
        $a = $this->settings->get('active_utilities', []);
        return is_array($a) ? $a : [];
    }

    /** PV-Jahreszeilen (PvSummaryService) nach Jahr. */
    private function pvYears(): array
    {
        if ($this->pv === null) return [];
        $c = $this->pv->compute();
        $out = [];
        foreach ($c['yearly'] ?? [] as $y) $out[(int)$y['year']] = $y;
        $out['_payback'] = $c['payback'] ?? null;
        $out['_has_generation'] = !empty($c['has_generation_meter']);
        return $out;
    }

    /** Bezugspreis (Haushalt) und Einspeisevergütung aus den eigenen Verträgen des Berichtsjahres. */
    private function gridAndFeedCt(): array
    {
        $grid = null; $feed = null;
        foreach (['strom' => 'household', 'pv_einspeisung' => null] as $u => $role) {
            if (!Utilities::exists($u)) continue;
            $cost = 0.0; $kwh = 0.0;
            foreach ($this->rootMeters($u) as $m) {
                if ($role !== null && Utilities::roleOf($u, $m) !== $role) continue;
                foreach ($this->rows($u, $m) as $r) {
                    if ((int)$r['year'] !== $this->year) continue;
                    $cost += (float)($r['kwh_cost'] ?? 0); $kwh += (float)($r['kwh'] ?? 0);
                }
            }
            $ct = $kwh > 0 && $cost > 0 ? $cost / $kwh * 100 : null;
            if ($u === 'strom') $grid = $ct; else $feed = $ct;
        }
        return [$grid, $feed];
    }

    private function pvFacts(?array $pv, array $contracts): ?array
    {
        if ($pv === null || (float)($pv['erzeugung_kwh'] ?? 0) <= 0) return null;
        [$grid, $feed] = $this->gridAndFeedCt();
        return [
            'benefit_eur' => $pv['pv_benefit_eur'] ?? null, 'savings_eur' => $pv['savings_eur'] ?? null,
            'feed_in_revenue_eur' => $pv['feed_in_revenue_eur'] ?? null,
            'self_rate' => $pv['eigenverbrauchsquote'] ?? null, 'autarky' => $pv['autarkiequote'] ?? null,
            'battery' => $pv['battery'] ?? null, 'grid_ct' => $grid, 'feed_ct' => $feed,
        ];
    }

    private function flexFacts(array $utilities): ?array
    {
        if ($this->flexibility === null || !in_array('strom', $utilities, true)) return null;
        $r = $this->flexibility->analyze($this->year);
        if (empty($r['prices']['available']) || ($r['meters'] ?? []) === []) return null;
        $meters = []; $rows = [];
        foreach ($r['meters'] as $m) {
            $t = $m['total'] ?? [];
            if (($t['priced_kwh'] ?? 0) <= 0) continue;
            $name = (string)($m['name'] ?? $m['meter_id']);
            $meters[] = ['meter_id' => (string)$m['meter_id'], 'name' => $name, 'role' => (string)($m['role'] ?? ''),
                         'controllable' => !empty($m['controllable']), 'profile_factor' => $t['profile_factor'] ?? null,
                         'own_ct' => $t['own_ct'] ?? null, 'market_ct' => $t['market_ct'] ?? null, 'kwh' => (float)$t['priced_kwh']];
            $rows[] = ['name' => $name, 'cheap_share' => (float)($t['cheap_share'] ?? 0), 'profile_factor' => $t['profile_factor'] ?? null,
                       'advantage_eur' => (float)($t['advantage_eur'] ?? 0), 'controllable' => !empty($m['controllable']),
                       'text' => $this->i18n->t('report.topic.flex.row', [
                           'share' => $this->advice->share((float)($t['cheap_share'] ?? 0) * 100),
                           'pf' => $this->i18n->number((float)($t['profile_factor'] ?? 0), 2),
                       ])];
        }
        if ($meters === []) return null;
        $m3 = 0.0;
        foreach ($r['module3'] ?? [] as $x) $m3 += (float)($x['total']['saving_eur'] ?? 0);
        $bat = (float)($r['battery']['total']['value_eur'] ?? 0);
        $this->term('profileFactor');
        if ($m3 > 0) $this->term('module14a');
        $this->sources['market'] = true;
        return [
            'meters' => $meters,
            'module3_eur' => round($m3, 2),
            'topic' => [
                'rows' => $rows,
                'module3_eur' => round($m3, 2), 'battery_value_eur' => round($bat, 2),
                'sentence' => $this->i18n->t('report.topic.flex.sentence'),
                'module3_text' => $m3 > 0 ? $this->i18n->t('report.topic.flex.module3', ['amount' => $this->advice->approx($m3)]) : null,
                'battery_text' => abs($bat) >= 1 ? $this->i18n->t('report.topic.flex.battery', ['amount' => $this->advice->approx($bat)]) : null,
            ],
        ];
    }

    private function evFacts(array $utilities): ?array
    {
        if (!in_array('strom', $utilities, true)) return null;
        $evMeters = array_values(array_filter($this->meters->list('strom'), fn($m) => Utilities::roleOf('strom', $m) === 'ev_charger'));
        if ($evMeters === []) return null;
        [$grid, $feed] = $this->gridAndFeedCt();
        $topicMeters = []; $kwhAll = 0.0; $solarAll = 0.0;
        $names = $this->i18n->t('format.monthsShort');
        $short = array_map('trim', explode(',', $names));
        foreach ($evMeters as $m) {
            $rows = array_values(array_filter($this->rows('strom', $m), fn($r) => (int)$r['year'] === $this->year));
            if ($rows === []) continue;
            $kwh = array_sum(array_map(fn($r) => (float)($r['kwh'] ?? 0), $rows));
            $evcc = [];
            if ($this->evcc !== null) {
                try { $evcc = $this->evcc->monthly((string)$m['id'], $this->year); } catch (\Throwable) { $evcc = []; }
            }
            $solar = array_sum(array_map(fn($x) => (float)($x['solar_kwh'] ?? 0), $evcc));
            $months = [];
            for ($mo = 1; $mo <= 12; $mo++) {
                $ym = sprintf('%d-%02d', $this->year, $mo);
                $v = null;
                foreach ($rows as $r) if ((string)$r['ym'] === $ym) $v = (float)($r['kwh'] ?? 0);
                $months[] = ['ym' => $ym, 'label' => $short[$mo - 1] ?? (string)$mo, 'value' => $v, 'prev' => null,
                             'part' => isset($evcc[$ym]) ? (float)($evcc[$ym]['solar_kwh'] ?? 0) : null];
            }
            $cost = null;
            if ($this->evReport !== null) {
                try { $cost = (float)($this->evReport->report((string)$m['id'], $this->year)['total']['amount_eur'] ?? 0); } catch (\Throwable) { $cost = null; }
            }
            $parent = !empty($m['parent_meter_id']) ? $this->meters->get('strom', (string)$m['parent_meter_id']) : null;
            $topicMeters[] = [
                'name' => (string)$m['name'], 'kwh' => round($kwh, 1), 'solar_kwh' => round($solar, 1),
                'solar_pct' => $kwh > 0 && $solar > 0 ? round($solar / $kwh * 100, 1) : null,
                'cost_eur' => $cost, 'months' => $months, 'has_solar' => $solar > 0,
                'sentence' => $this->i18n->t($solar > 0 ? 'report.topic.ev.sentenceSolar' : 'report.topic.ev.sentence', [
                    'name' => (string)$m['name'], 'kwh' => $this->i18n->number($kwh, 0),
                    'pct' => $kwh > 0 ? $this->advice->share($solar / $kwh * 100) : '–', 'solar' => $this->i18n->number($solar, 0),
                ]),
                'cost_text' => $cost !== null && $cost > 0 ? $this->i18n->t('report.topic.ev.cost', ['amount' => $this->advice->approx($cost)]) : null,
                'sub_text' => $parent !== null ? $this->i18n->t('report.topic.ev.subMeter', ['parent' => (string)$parent['name']]) : null,
            ];
            $kwhAll += $kwh; $solarAll += $solar;
        }
        if ($topicMeters === []) return null;
        if ($solarAll > 0) $this->term('subMeter');
        return [
            'facts' => ['kwh' => $kwhAll, 'solar_kwh' => $solarAll, 'grid_ct' => $grid, 'feed_ct' => $feed],
            'topic' => ['meters' => $topicMeters],
        ];
    }

    /** Mietverhältnis: Vorauszahlung (Stand heute), Abrechnung und CO₂-Aufteilung des Berichtsjahres. */
    private function tenancyFacts(): ?array
    {
        if ($this->tenancies === null || $this->tenancyBudget === null) return null;
        $list = $this->tenancies->list();
        if ($list === []) return null;
        $t = null;
        foreach ($list as $x) {
            if ((string)($x['start'] ?? '') <= $this->today && (empty($x['end']) || (string)$x['end'] >= $this->today)) { $t = $x; break; }
        }
        $t ??= $list[0];
        $budget = $this->tenancyBudget->budget((string)$t['id'], $this->today);
        $yearBudget = null;
        try { $yearBudget = $this->tenancyBudget->budget((string)$t['id'], $this->year . '-12-31'); } catch (\Throwable) { $yearBudget = null; }
        $statement = null;
        foreach ($this->tenancies->statementsOf((string)$t['id']) as $s) {
            if (substr((string)($s['period_to'] ?? ''), 0, 4) === (string)$this->year) $statement = $s;
        }
        $co2 = null;
        if ($this->co2Split !== null) {
            try {
                $c = $this->co2Split->forYear($this->year);
                if (!empty($c['supported']) && ($c['landlord_amount_eur'] ?? null) !== null) {
                    $co2 = ['landlord_amount_eur' => (float)$c['landlord_amount_eur'], 'case' => (string)($c['case'] ?? ''),
                            'stage' => $c['stage'] ?? null, 'deadline' => $c['deadline'] ?? null, 'co2_cost_eur' => $c['co2_cost_eur'] ?? null];
                    $this->term('co2Split');
                }
            } catch (\Throwable) {
                $co2 = null;
            }
        }
        $label = (string)($t['label'] ?? '');
        $this->term('prepayment');
        $sentences = [];
        $sentences[] = $this->i18n->t('report.topic.tenancy.budget', [
            'from' => $this->i18n->date((string)$budget['period_from']), 'to' => $this->i18n->date((string)$budget['period_to']),
            'expected' => $this->advice->approx((float)$budget['expected_eur']), 'prepaid' => $this->advice->approx((float)$budget['prepaid_eur']),
        ]);
        if ($statement !== null && ($statement['result_eur'] ?? null) !== null) {
            $sentences[] = $this->i18n->t((float)$statement['result_eur'] > 0 ? 'report.topic.tenancy.statementDue' : 'report.topic.tenancy.statementCredit', [
                'year' => $this->year, 'amount' => $this->advice->approx(abs((float)$statement['result_eur'])),
            ]);
        }
        if ($co2 !== null) {
            $sentences[] = $this->i18n->t($co2['case'] === 'central' ? 'report.topic.tenancy.co2Central' : 'report.topic.tenancy.co2Claim', [
                'year' => $this->year, 'amount' => $this->advice->approx((float)$co2['landlord_amount_eur']),
                'date' => $co2['deadline'] !== null ? $this->i18n->date((string)$co2['deadline']) : '–',
            ]);
        }
        $months = [];
        $names = array_map('trim', explode(',', $this->i18n->t('format.monthsShort')));
        $cumExp = 0.0; $cumPaid = 0.0;
        foreach ($budget['months'] ?? [] as $i => $m) {
            $cumExp += (float)($m['expected_eur'] ?? 0); $cumPaid += (float)($m['prepaid_eur'] ?? 0);
            $mo = (int)substr((string)($m['ym'] ?? ''), 5, 2);
            $months[] = ['label' => $names[$mo - 1] ?? (string)$mo, 'value' => round($cumExp - $cumPaid, 2), 'projected' => empty($m['measured'])];
        }
        return [
            'budget' => ['id' => (string)$t['id'], 'label' => $label, 'projected_result_eur' => $budget['projected_result_eur'] ?? null,
                         'suggested_prepayment_eur' => $budget['suggested_prepayment_eur'] ?? null, 'period_to' => $budget['period_to'] ?? null],
            'co2' => $co2,
            'year_costs' => $yearBudget['components'] ?? null,
            'topic' => [
                'label' => $label, 'sentences' => $sentences,
                'as_of_today' => true, 'path' => $months,
                'projected_result_eur' => $budget['projected_result_eur'] ?? null,
                'statement_result_eur' => $statement['result_eur'] ?? null,
                'co2' => $co2,
            ],
        ];
    }

    private function billFacts(array $consumptionU): array
    {
        if ($this->bills === null) return [];
        $out = [];
        foreach ($consumptionU as $u) {
            if (!Utilities::supportsBillCheck($u)) continue;
            foreach ($this->bills->list($u) as $b) {
                if (substr((string)($b['period_to'] ?? ''), 0, 4) !== (string)$this->year) continue;
                $c = $this->bills->compare($u, (string)$b['id']);
                $out[] = ['id' => (string)$b['id'], 'utility' => $u, 'label' => $this->i18n->utilityLabel($u),
                          'verdict' => (string)($c['verdict'] ?? 'ok'), 'delta_eur' => (float)($c['delta']['eur'] ?? 0),
                          'from' => (string)($c['period_from'] ?? $b['period_from'] ?? ''), 'to' => (string)($c['period_to'] ?? $b['period_to'] ?? '')];
            }
        }
        return $out;
    }

    /** Zäsur (F1011): Steigung je Gradtag vorher/nachher, nur wenn belegt. */
    private function baselineFacts(array $consumptionU, array $yd): array
    {
        $out = [];
        $normal = 0.0;
        foreach ($this->consumption->hddNormals() as $n) $normal += (float)($n['mean'] ?? 0);
        foreach ($consumptionU as $u) {
            if (!Utilities::isHgtRelevant($u) || Utilities::isDelivery($u)) continue;
            foreach ($this->rootMeters($u) as $m) {
                $ev = MeterService::activeBaselineEvent($m, $this->today);
                if ($ev === null) continue;
                $b = $this->consumption->baselineComparison($u, $m, $this->rows($u, $m));
                if ($b === null || empty($b['significant'])) continue;
                $this->term('baseline');
                $out[] = ['utility' => $u, 'label' => $this->i18n->utilityLabel($u), 'significant' => true,
                          'delta_pct' => (float)$b['delta_pct'], 'slope_before' => (float)$b['before']['slope'],
                          'slope_after' => (float)$b['after']['slope'], 'hdd_normal_year' => $normal,
                          'price_ct' => self::priceCt($yd[$u][$this->year]),
                          'date' => (string)($ev['date'] ?? ''), 'event' => (string)($ev['label'] ?? '')];
            }
        }
        return $out;
    }

    // ── Sektionen ────────────────────────────────────────────────────────

    private function agendaSection(array $events, array $contracts): array
    {
        $rows = [];
        // fällige Ablesungen in einer Zeile statt einer je Zähler
        $readings = array_values(array_filter($events, fn($e) => ($e['kind'] ?? '') === 'reading_due' && !empty($e['due_now'])));
        if (count($readings) > 1) {
            $events = array_values(array_filter($events, fn($e) => !(($e['kind'] ?? '') === 'reading_due' && !empty($e['due_now']))));
            $names = array_values(array_unique(array_map(fn($e) => (string)($e['params']['meter'] ?? $e['title']), $readings)));
            $events[] = ['date' => $this->today, 'kind' => 'reading_due', 'severity' => 'due', 'days' => 0,
                         'title' => $this->i18n->t('report.advice.readings.title') . ': ' . implode(', ', $names)];
        }
        foreach ($events as $e) {
            $rows[] = ['date' => (string)$e['date'], 'date_text' => $this->i18n->date((string)$e['date']), 'title' => (string)$e['title'],
                       'kind' => (string)$e['kind'], 'severity' => (string)($e['severity'] ?? ''), 'days' => (int)$e['days'],
                       'state' => $e['days'] < 0 ? $this->i18n->t('report.agenda.overdue')
                           : ($e['days'] === 0 ? $this->i18n->t('report.agenda.today') : $this->i18n->tp('report.agenda.inDays', $e['days'], ['days' => $e['days']]))];
        }
        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
        $timelines = [];
        foreach ($contracts as $c) {
            if (empty($c['is_current']) || empty($c['start'])) continue;
            $end = $c['is_open_ended'] ? ($c['cancel_by'] !== null ? $c['switch_date'] : null) : ($c['end'] ?? $c['effective_end']);
            $points = [['date' => (string)$c['start'], 'label' => $this->i18n->t('report.timeline.start'), 'text' => $this->i18n->date((string)$c['start'])]];
            if ($c['cancel_by'] !== null && $c['switchable']) {
                $points[] = ['date' => (string)$c['cancel_by'], 'label' => $this->i18n->t('report.timeline.cancel'), 'text' => $this->i18n->date((string)$c['cancel_by'])];
                $this->term('noticeDeadline');
            }
            if (is_array($c['price_increase'])) {
                $points[] = ['date' => (string)$c['price_increase']['from'], 'label' => $this->i18n->t('report.timeline.price'),
                             'text' => $this->i18n->date((string)$c['price_increase']['from'])];
            }
            if ($end !== null) $points[] = ['date' => (string)$end, 'label' => $this->i18n->t('report.timeline.end'), 'text' => $this->i18n->date((string)$end)];
            $sentence = $c['cancel_by'] !== null && $c['switchable']
                ? $this->i18n->t($c['is_open_ended'] ? 'report.timeline.textOpen' : 'report.timeline.text', [
                    'start' => $this->i18n->date((string)$c['start']), 'cancel' => $this->i18n->date((string)$c['cancel_by']),
                    'end' => $end !== null ? $this->i18n->date((string)$end) : '–'])
                : ($end !== null
                    ? $this->i18n->t('report.timeline.textNoNotice', ['start' => $this->i18n->date((string)$c['start']), 'end' => $this->i18n->date((string)$end)])
                    : $this->i18n->t('report.timeline.textRunning', ['start' => $this->i18n->date((string)$c['start'])]));
            $timelines[] = [
                'utility' => $c['utility'], 'label' => $c['label'], 'provider' => $c['provider'], 'meter_name' => $c['meter_name'],
                'points' => $points, 'sentence' => $sentence,
                // für explainer.js timelineHtml() in der Druckansicht
                'explainer' => ['start' => $c['start'], 'end' => $c['end'], 'effective_end' => $c['effective_end'],
                    'is_open_ended' => $c['is_open_ended'], 'cancel_by' => $c['switchable'] ? $c['cancel_by'] : null,
                    'switch_date' => $c['switch_date'], 'price_increase' => $c['price_increase']],
            ];
        }
        return ['events' => $rows, 'timelines' => $timelines, 'as_of_today' => true];
    }

    private function moneySection(array $consumptionU, array $utilities, array $yd, array $totals, array $contracts): ?array
    {
        if (($totals['cost_now'] ?? 0) <= 0) return null;
        $y = $this->year; $p = $y - 1;
        $by = [];
        foreach ($consumptionU as $u) {
            $now = $yd[$u][$y];
            if (!$now['has_contract'] || $now['cost'] == 0.0) continue;
            $prev = $yd[$u][$p];
            $by[] = ['utility' => $u, 'label' => $this->i18n->utilityLabel($u), 'cost' => round($now['cost'], 2),
                     'prev' => $prev['has_contract'] && $prev['coverage'] >= 12 ? round($prev['cost'], 2) : null,
                     'color' => (string)Utilities::get($u)['color'], 'text' => $this->i18n->money(round($now['cost']), 0)];
        }
        usort($by, fn($a, $b) => $b['cost'] <=> $a['cost']);
        $revenue = [];
        foreach ($utilities as $u) {
            if (Utilities::accountingKind($u) !== 'feed_in') continue;
            $now = $yd[$u][$y];
            if ($now['cost'] <= 0) continue;
            $revenue[] = ['utility' => $u, 'label' => $this->i18n->utilityLabel($u), 'value' => round($now['cost'], 2),
                          'text' => $this->i18n->t('report.money.revenue', ['label' => $this->i18n->utilityLabel($u), 'amount' => $this->advice->approx($now['cost'])])];
        }
        // Kosten je Monat (Summe der Arten mit Vertrag) gegen Vorjahr
        $names = array_map('trim', explode(',', $this->i18n->t('format.monthsShort')));
        $monthly = []; $bonusMonths = [];
        for ($mo = 1; $mo <= 12; $mo++) {
            $v = null; $pv = null;
            foreach ($consumptionU as $u) {
                if ($yd[$u][$y]['has_contract'] && $yd[$u][$y]['cost_months'][$mo] !== null) $v = ($v ?? 0.0) + $yd[$u][$y]['cost_months'][$mo];
                if ($yd[$u][$p]['has_contract'] && $yd[$u][$p]['cost_months'][$mo] !== null) $pv = ($pv ?? 0.0) + $yd[$u][$p]['cost_months'][$mo];
                if (isset($yd[$u][$y]['bonus_months'][$mo])) $bonusMonths[$mo] = ($bonusMonths[$mo] ?? 0.0) + $yd[$u][$y]['bonus_months'][$mo];
            }
            $monthly[] = ['ym' => sprintf('%d-%02d', $y, $mo), 'label' => $names[$mo - 1] ?? (string)$mo,
                          'value' => $v !== null ? round(max(0.0, $v), 2) : null, 'prev' => $pv !== null ? round(max(0.0, $pv), 2) : null];
        }
        $bonusNotes = [];
        foreach ($bonusMonths as $mo => $b) {
            $bonusNotes[] = $this->i18n->t('report.money.bonus', ['month' => $this->i18n->month(sprintf('%d-%02d', $y, $mo)), 'amount' => $this->advice->approx($b)]);
        }
        // Saldo je laufendem Vertrag (Stand heute)
        $balances = [];
        foreach ($contracts as $c) {
            if (empty($c['has_advance']) || empty($c['is_current']) || ($c['projected_end_balance'] ?? null) === null) continue;
            $bal = (float)$c['projected_end_balance'];
            $adv = (float)($c['current_advance'] ?? 0);
            $sugg = $c['suggested_advance'];
            // Mangel 11 — ein Vorschlag ≤ 0 wird nicht als Betrag gezeigt
            $show = $sugg !== null && (float)$sugg > 0 && abs((float)$sugg - $adv) >= 1;
            $date = $this->i18n->date((string)($c['settle_date'] ?? $this->today));
            $text = abs($bal) < 1 ? $this->i18n->t('report.money.balanceEven', ['date' => $date])
                : $this->i18n->t($bal > 0 ? 'report.money.balanceDue' : 'report.money.balanceCredit', ['date' => $date, 'amount' => $this->advice->approx(abs($bal))]);
            $advText = $show ? $this->i18n->t('report.money.advanceSuggested', ['old' => $this->i18n->money(round($adv), 0), 'new' => $this->i18n->money(round((float)$sugg), 0)])
                : ($bal < 0 && ($sugg === null || (float)$sugg <= 0) ? $this->i18n->t('report.money.advanceLower') : null);
            $path = [];
            foreach ((array)($c['balance_path'] ?? []) as $pt) {
                $path[] = ['label' => $this->i18n->month((string)$pt['ym']), 'value' => (float)$pt['balance'], 'projected' => !empty($pt['estimated'])];
            }
            $balances[] = [
                'utility' => $c['utility'], 'label' => $c['label'], 'provider' => $c['provider'], 'meter_name' => $c['meter_name'],
                'projected_end_balance' => round($bal, 2), 'current_advance' => $adv, 'suggested_advance' => $show ? (float)$sugg : null,
                'text' => $text, 'advance_text' => $advText, 'path' => $path, 'color' => (string)Utilities::get($c['utility'])['color'],
                'explainer' => ['balance_path' => $c['balance_path'], 'energy_cost_to_date' => $c['energy_cost_to_date'],
                    'base_to_date' => $c['base_to_date'], 'bonus_to_date' => $c['bonus_to_date'], 'projected_end_balance' => $c['projected_end_balance']],
            ];
            $this->term('balance', 'advance');
        }
        $forecast = $this->forecastNextYear($consumptionU);
        $t = $totals;
        $sentence = $t['cost_pct'] === null
            ? $this->i18n->t('report.money.sentenceNoPrevious', ['amount' => $this->advice->approx((float)$t['cost_now']), 'year' => $y])
            : $this->i18n->t('report.money.sentence', [
                'amount' => $this->advice->approx((float)$t['cost_now']), 'year' => $y, 'pct' => $this->advice->pct((float)$t['cost_pct']), 'prev' => $p,
                'price' => $this->signed((float)$t['price_effect']), 'volume' => $this->signed((float)$t['volume_effect']),
            ]);
        return [
            'total' => ['cost' => $t['cost_now'], 'prev' => $t['cost_prev'], 'pct' => $t['cost_pct'],
                        'price_effect' => $t['price_effect'], 'volume_effect' => $t['volume_effect'], 'sentence' => $sentence],
            'by_utility' => $by, 'revenue' => $revenue,
            'monthly' => $monthly, 'bonus_notes' => $bonusNotes,
            'balances' => $balances, 'balances_as_of_today' => true,
            'forecast' => $forecast,
        ];
    }

    private function signed(float $v): string
    {
        $r = round($v);
        return ($r > 0 ? '+' : ($r < 0 ? '-' : '±')) . $this->i18n->money(abs($r), 0);
    }

    /** Erwartete Kosten der nächsten zwölf Monate (Prognose je Zähler, wie im SummaryService). */
    private function forecastNextYear(array $consumptionU): ?array
    {
        if ($this->forecasts === null) return null;
        $sum = 0.0; $any = false;
        foreach ($consumptionU as $u) {
            if (Utilities::isDelivery($u)) continue;
            foreach ($this->rootMeters($u) as $m) {
                try { $f = $this->forecasts->forMeter($u, $m); } catch (\Throwable) { continue; }
                if (empty($f['valid']) || empty($f['forecast'])) continue;
                $cost = array_sum(array_map(fn($r) => (float)($r['cost_estimated'] ?? 0), array_slice($f['forecast'], 0, 12)));
                if ($cost > 0) { $sum += $cost; $any = true; }
            }
        }
        if (!$any) return null;
        return ['cost' => round($sum, 2), 'text' => $this->i18n->t('report.money.forecast', ['amount' => $this->advice->approx($sum)]), 'as_of_today' => true];
    }

    /** Je Verbrauchsart eine Seite. */
    private function utilityPage(string $u, array $yd, array $eff, ?array $pvNow): ?array
    {
        $y = $this->year; $p = $y - 1;
        $now = $yd[$u][$y]; $prev = $yd[$u][$p];
        if ($now['value'] <= 0) return null;
        $unit = self::unit($u); $dec = $this->decimals($u);
        $label = $this->i18n->utilityLabel($u);
        $names = array_map('trim', explode(',', $this->i18n->t('format.monthsShort')));
        $months = [];
        for ($mo = 1; $mo <= 12; $mo++) {
            $months[] = ['ym' => sprintf('%d-%02d', $y, $mo), 'label' => $names[$mo - 1] ?? (string)$mo,
                         'value' => $now['months'][$mo] !== null ? round($now['months'][$mo], 2) : null,
                         'prev' => $prev['months'][$mo] !== null ? round($prev['months'][$mo], 2) : null,
                         'estimated' => $now['estimated'][$mo]];
        }
        $pct = $prev['value'] > 0 && $prev['coverage'] >= 12 && $now['coverage'] >= 12 ? round(($now['value'] / $prev['value'] - 1) * 100, 1) : null;
        $valueText = $this->i18n->number($now['value'], $dec) . ' ' . $unit;
        $sentence = $pct === null
            ? $this->i18n->t('report.page.sentenceNoPrevious', ['label' => $label, 'value' => $valueText, 'year' => $y])
            : $this->i18n->t('report.page.sentence', ['label' => $label, 'value' => $valueText, 'year' => $y,
                'pct' => $this->advice->pct($pct), 'prev' => $p, 'prevValue' => $this->i18n->number($prev['value'], $dec) . ' ' . $unit]);
        $notes = [];
        if ($now['coverage'] < 12) $notes[] = $this->i18n->tp('report.page.coverage', $now['coverage'], ['count' => $now['coverage']]);
        if (in_array(true, $now['estimated'], true)) { $notes[] = $this->i18n->t('report.page.estimatedNote'); $this->term('estimated'); }

        $page = [
            'utility' => $u, 'label' => $label, 'unit' => $unit, 'color' => (string)Utilities::get($u)['color'],
            'value' => round($now['value'], 2), 'prev_value' => $prev['value'] > 0 ? round($prev['value'], 2) : null, 'pct' => $pct,
            'value_text' => $valueText, 'months' => $months, 'sentence' => $sentence,
            'cost' => $this->costNote($u, $now),
            'weather' => null, 'efficiency' => null, 'roles' => null, 'water' => null, 'tank' => null,
            'sub_meters' => $this->subMeters($u), 'notes' => $notes, 'weather_rows' => [],
        ];
        if ($page['sub_meters'] !== []) $this->term('subMeter');
        // Heizung: kälter oder mehr verbraucht?
        if (Utilities::isHgtRelevant($u) && $now['adj'] !== null && $prev['adj'] !== null && $prev['value'] > 0) {
            [$hddNow, $hddPrev] = $this->hddPair($u);
            $cons = ($now['value'] / $prev['value'] - 1) * 100;
            $hdd = $hddPrev > 0 ? ($hddNow / $hddPrev - 1) * 100 : 0.0;
            $adj = ($now['adj'] / $prev['adj'] - 1) * 100;
            $verdict = $adj < -2 ? 'less' : ($adj > 2 ? 'more' : 'same');
            $page['weather'] = [
                'cons_pct' => round($cons, 1), 'hdd_pct' => round($hdd, 1), 'adj_pct' => round($adj, 1), 'verdict' => $verdict,
                'adj_now' => round($now['adj'], 1), 'adj_prev' => round($prev['adj'], 1),
                'text' => $this->i18n->t("report.page.weather.$verdict", ['cons' => $this->advice->pct($cons), 'hdd' => $this->advice->pct($hdd), 'adj' => $this->advice->pct($adj)]),
            ];
            $page['weather_rows'] = $this->weatherRows($u);
            $this->term('heatAdjusted', 'hdd');
            $this->sources['weather'] = true;
        }
        // Effizienzskala — ohne Skala des Landes (v2.7.0) nur die Zahl und der Grund
        foreach ($eff['per_source'] ?? [] as $s) {
            if (($s['utility'] ?? null) !== $u || ($s['kwh_per_m2'] ?? null) === null) continue;
            $params = ['class' => (string)($s['class'] ?? ''), 'value' => $this->i18n->number((float)$s['kwh_per_m2'], 0),
                       'area' => $this->i18n->number((float)($eff['wohnflaeche_m2'] ?? 0), 0)];
            $scaled = !empty($eff['scale']) && !empty($s['class']);
            $page['efficiency'] = ['class' => $scaled ? (string)$s['class'] : null, 'kwh_per_m2' => (float)$s['kwh_per_m2'],
                'thresholds' => $scaled ? ($eff['thresholds'] ?? []) : [], 'value_text' => $this->i18n->number((float)$s['kwh_per_m2'], 0) . ' kWh/m²·a',
                'text' => $scaled ? $this->i18n->t('report.page.efficiency', $params)
                    : trim($this->i18n->t('report.page.heatDemand', $params) . ' ' . (string)($eff['scale_note'] ?? ''))];
            if ($scaled) $this->term('efficiency');
        }
        if ($u === 'strom') $page['roles'] = $this->stromRoles($pvNow);
        if ($u === 'wasser') {
            $persons = (int)$this->settings->get('wasser_personen_anzahl', 0);
            if ($persons > 0) {
                $days = (int)date('z', mktime(0, 0, 0, 12, 31, $y)) + 1;
                $l = $now['value'] * 1000 / $persons / $days;
                $page['water'] = ['liters_per_person_day' => round($l, 0), 'persons' => $persons,
                    'text' => $this->i18n->tp('report.page.water', $persons, ['liters' => $this->i18n->number($l, 0), 'count' => $persons])];
            }
        }
        if (Utilities::isDelivery($u)) $page['tank'] = $this->tank($u);
        return $page;
    }

    /** Heizgradtage beider Jahre (aus den Monatszeilen des ersten Zählers mit Temperaturen). */
    private function hddPair(string $u): array
    {
        foreach ($this->rootMeters($u) as $m) {
            $now = 0.0; $prev = 0.0;
            foreach ($this->rows($u, $m) as $r) {
                if ((int)$r['year'] === $this->year) $now += (float)($r['hdd'] ?? 0);
                if ((int)$r['year'] === $this->year - 1) $prev += (float)($r['hdd'] ?? 0);
            }
            if ($now > 0 && $prev > 0) return [$now, $prev];
        }
        return [0.0, 0.0];
    }

    /** Monatszeilen (bis Dezember des Berichtsjahres) für explainer.js weatherHtml(). */
    private function weatherRows(string $u): array
    {
        $out = [];
        foreach ($this->rootMeters($u) as $m) {
            foreach ($this->rows($u, $m) as $r) {
                $yr = (int)$r['year'];
                if ($yr < $this->year - 1 || $yr > $this->year) continue;
                $ym = (string)$r['ym'];
                $out[$ym] ??= ['ym' => $ym, 'kwh' => 0.0, 'hdd' => (float)($r['hdd'] ?? 0), 'heat_adjusted' => 0.0, 'days' => (int)($r['days'] ?? 0)];
                $out[$ym]['kwh'] += (float)($r['kwh'] ?? 0);
                $out[$ym]['heat_adjusted'] = ($r['heat_adjusted'] ?? null) === null || $out[$ym]['heat_adjusted'] === null
                    ? null : $out[$ym]['heat_adjusted'] + (float)$r['heat_adjusted'];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * Wie die Kosten einer Art zustande kommen — statt „0,00 €“ (Mangel 4):
     * Vertrag, Nebenkosten des Mietverhältnisses oder keine Preise.
     */
    private function costNote(string $u, array $now): array
    {
        if ($now['has_contract']) {
            $this->term('workingPrice', 'basePrice');
            return ['basis' => 'contract', 'value' => round($now['cost'], 2),
                    'text' => $this->i18n->t('report.page.cost', ['amount' => $this->advice->approx($now['cost']),
                        'price' => self::priceCt($now) !== null ? $this->i18n->number((float)self::priceCt($now), $u === 'wasser' ? 0 : 1) : '–',
                        'unit' => self::unit($u)])];
        }
        if ($this->tenancies !== null) {
            foreach ($this->tenancies->list() as $t) {
                $ids = array_merge(...array_values(array_map(fn($x) => (array)$x, (array)($t['meter_ids'] ?? []))));
                $mine = array_filter($this->rootMeters($u), fn($m) => in_array($m['id'], $ids, true));
                if ($mine === []) continue;
                $est = null;
                if ($this->tenancyBudget !== null) {
                    try {
                        $c = $this->tenancyBudget->budget((string)$t['id'], $this->year . '-12-31')['components'] ?? [];
                        $est = $u === 'waerme' ? ($c['heat_eur'] ?? null)
                            : ($u === 'wasser' ? (float)($c['warm_water_eur'] ?? 0) + (float)($c['cold_water_eur'] ?? 0) : null);
                    } catch (\Throwable) {
                        $est = null;
                    }
                }
                return ['basis' => 'tenancy', 'value' => $est !== null ? round((float)$est, 2) : null,
                        'text' => $est !== null && (float)$est > 0
                            ? $this->i18n->t('report.page.costTenancyEstimate', ['amount' => $this->advice->approx((float)$est), 'label' => (string)($t['label'] ?? '')])
                            : $this->i18n->t('report.page.costTenancy', ['label' => (string)($t['label'] ?? '')])];
            }
        }
        return ['basis' => 'none', 'value' => null, 'text' => $this->i18n->t('report.page.costNone')];
    }

    /** Subzähler und Zähler außerhalb der Summen dieser Art — als „davon“, mit Erklärung statt 0 €. */
    private function subMeters(string $u): array
    {
        $out = [];
        $vf = self::valueField($u); $dec = $this->decimals($u);
        foreach ($this->meters->list($u) as $m) {
            if (MeterService::countsInTotals($m)) continue;
            $v = 0.0; $n = 0;
            foreach ($this->rows($u, $m) as $r) if ((int)$r['year'] === $this->year) { $v += (float)($r[$vf] ?? 0); $n++; }
            if ($n === 0) continue;
            $role = Utilities::roleOf($u, $m);
            $parent = !empty($m['parent_meter_id']) ? $this->meters->get($u, (string)$m['parent_meter_id']) : null;
            $key = $parent !== null ? 'report.page.subMeter'
                : ($role === 'heat_pump_output' ? 'report.page.heatPumpOutput' : 'report.page.outsideTotals');
            $out[] = ['name' => (string)$m['name'], 'role' => $role, 'value' => round($v, 2),
                      'text' => $this->i18n->t($key, ['name' => (string)$m['name'], 'value' => $this->i18n->number($v, $dec) . ' ' . self::unit($u),
                          'parent' => (string)($parent['name'] ?? '')])];
        }
        return $out;
    }

    /**
     * Strom nach Rollen. Mit Sonnenstrom hinter dem Hauszähler ist der
     * Haushaltsverbrauch Bezug + selbst genutzter Sonnenstrom; die Wallbox
     * (Subzähler) ist davon ein Teil — sonst könnte sie im Sommer mehr messen
     * als der Bezug.
     */
    private function stromRoles(?array $pvNow): ?array
    {
        $y = $this->year;
        $sum = fn(array $m): float => array_sum(array_map(fn($r) => (int)$r['year'] === $y ? (float)($r['kwh'] ?? 0) : 0.0, $this->rows('strom', $m)));
        $household = 0.0; $hp = 0.0; $ev = 0.0; $evNames = [];
        foreach ($this->meters->list('strom') as $m) {
            $role = Utilities::roleOf('strom', $m);
            if (MeterService::countsInTotals($m)) {
                if ($role === 'heat_pump') $hp += $sum($m); else $household += $sum($m);
            } elseif ($role === 'ev_charger') {
                $ev += $sum($m);
            }
        }
        if ($hp <= 0 && $ev <= 0) return null;
        $self = $pvNow !== null ? (float)($pvNow['eigenverbrauch_kwh'] ?? 0) : 0.0;
        $house = $household + $self;
        $rest = max(0.0, $house - $ev);
        $total = $rest + $ev + $hp;
        $rows = [];
        foreach ([['household', $rest], ['heat_pump', $hp], ['ev_charger', $ev]] as [$role, $v]) {
            if ($v <= 0) continue;
            $rows[] = ['role' => $role, 'label' => $this->i18n->t("report.page.role.$role"), 'kwh' => round($v, 1),
                       'share' => $total > 0 ? round($v / $total * 100, 1) : null,
                       'text' => $this->i18n->number($v, 0) . ' kWh · ' . $this->advice->share($total > 0 ? $v / $total * 100 : 0)];
        }
        return ['rows' => $rows, 'with_pv' => $self > 0,
                'text' => $this->i18n->t($self > 0 ? 'report.page.rolesWithPv' : 'report.page.roles', ['total' => $this->i18n->number($total, 0)])];
    }

    /** Tank: Bestand heute und wie lange er bei üblichem Verbrauch etwa reicht. */
    private function tank(string $u): ?array
    {
        if ($this->deliveries === null) return null;
        foreach ($this->rootMeters($u) as $m) {
            $h = $this->deliveries->stockHistory($u, (string)$m['id'], $this->consumption);
            $days = $h['days'] ?? [];
            if ($days === []) continue;
            // Tageszeilen: [date, stock, consumption, …] (DeliveryService::stockHistory)
            $stock = null;
            foreach ($days as $x) if ((string)($x['date'] ?? '') <= $this->today) $stock = (float)($x['stock'] ?? 0);
            if ($stock === null) continue;
            $cap = (float)($h['capacity'] ?? $m['capacity'] ?? 0);
            // Verbrauch je Kalendermonat aus dem Berichtsjahr, ab heute fortgeschrieben
            $draw = array_fill(1, 12, 0.0);
            foreach ($days as $x) {
                $d = (string)($x['date'] ?? '');
                if (substr($d, 0, 4) === (string)$this->year) $draw[(int)substr($d, 5, 2)] += (float)($x['consumption'] ?? 0);
            }
            $until = null;
            $left = $stock;
            $t = strtotime(substr($this->today, 0, 7) . '-01');
            for ($i = 0; $i < 36 && array_sum($draw) > 0; $i++) {
                $mo = (int)date('n', $t);
                $left -= $draw[$mo];
                if ($left <= 0) { $until = date('Y-m', $t); break; }
                $t = strtotime('+1 month', $t);
            }
            $unit = (string)($h['capacity_unit'] ?? $m['capacity_unit'] ?? (Utilities::get($u)['volume_unit'] ?? ''));
            $this->term('tankBook');
            return ['stock' => round($stock, 0), 'capacity' => $cap > 0 ? $cap : null, 'unit' => $unit,
                    'percent' => $cap > 0 ? round($stock / $cap * 100, 0) : null, 'until' => $until,
                    'text' => $this->i18n->t($until !== null ? 'report.page.tank' : 'report.page.tankNoRange', [
                        'stock' => $this->i18n->number($stock, 0), 'unit' => $unit,
                        'until' => $until !== null ? $this->i18n->month($until) : '–']),
                    'as_of_today' => true];
        }
        return null;
    }

    // ── Themenseiten ─────────────────────────────────────────────────────

    private function pvTopic(array $pvYear, array $contracts): ?array
    {
        $pv = $pvYear[$this->year] ?? null;
        if ($pv === null || (float)($pv['erzeugung_kwh'] ?? 0) <= 0) return null;
        $prev = $pvYear[$this->year - 1] ?? null;
        $bat = $pv['battery'] ?? null;
        $discharged = (float)($bat['discharged_kwh'] ?? 0);
        $self = (float)($pv['eigenverbrauch_kwh'] ?? 0);
        $flow = ['gen' => (float)$pv['erzeugung_kwh'], 'direct' => max(0.0, $self - $discharged), 'feed' => (float)($pv['einspeisung_kwh'] ?? 0),
                 'grid' => (float)($pv['bezug_kwh'] ?? 0), 'charged' => (float)($bat['charged_kwh'] ?? 0), 'discharged' => $discharged];
        $this->term('selfConsumption', 'autarky', 'feedIn');
        $tiles = [
            ['label' => $this->i18n->t('report.topic.pv.generated'), 'text' => $this->i18n->number($flow['gen'], 0) . ' kWh',
             'sub' => $prev !== null && (float)($prev['erzeugung_kwh'] ?? 0) > 0
                ? $this->i18n->t('report.tile.vsPrevious', ['pct' => $this->advice->pct(($flow['gen'] / (float)$prev['erzeugung_kwh'] - 1) * 100), 'prev' => $this->year - 1]) : ''],
            ['label' => $this->i18n->t('report.topic.pv.autarky'), 'text' => $pv['autarkiequote'] !== null ? $this->advice->share((float)$pv['autarkiequote'] * 100) : '–', 'sub' => ''],
            ['label' => $this->i18n->t('report.topic.pv.selfRate'), 'text' => $pv['eigenverbrauchsquote'] !== null ? $this->advice->share((float)$pv['eigenverbrauchsquote'] * 100) : '–', 'sub' => ''],
            ['label' => $this->i18n->t('report.topic.pv.benefit'), 'text' => ($pv['pv_benefit_eur'] ?? null) !== null ? $this->i18n->money(round((float)$pv['pv_benefit_eur']), 0) : '–', 'sub' => ''],
        ];
        $payback = $pvYear['_payback'] ?? null;
        $pb = null;
        if (is_array($payback) && (float)($payback['investment_eur'] ?? 0) > 0) {
            $pb = ['investment_eur' => (float)$payback['investment_eur'], 'benefit_to_date_eur' => (float)($payback['benefit_to_date_eur'] ?? 0),
                   'break_even_ym' => $payback['break_even_ym'] ?? null,
                   'text' => $this->i18n->t(($payback['break_even_ym'] ?? null) !== null ? 'report.topic.pv.payback' : 'report.topic.pv.paybackOpen', [
                       'paid' => $this->advice->approx((float)($payback['benefit_to_date_eur'] ?? 0)),
                       'investment' => $this->i18n->money(round((float)$payback['investment_eur']), 0),
                       'date' => ($payback['break_even_ym'] ?? null) !== null ? $this->i18n->month((string)$payback['break_even_ym']) : '–',
                   ]), 'as_of_today' => true];
        }
        $battery = null;
        if (is_array($bat) && (float)($bat['charged_kwh'] ?? 0) > 0) {
            $battery = ['text' => $this->i18n->t('report.topic.pv.battery', [
                'charged' => $this->i18n->number((float)$bat['charged_kwh'], 0), 'discharged' => $this->i18n->number($discharged, 0),
                'cycles' => $this->i18n->number((float)($bat['full_cycles'] ?? 0), 0),
                'eff' => $bat['efficiency_pct'] !== null ? $this->advice->share((float)$bat['efficiency_pct']) : '–',
            ])];
        }
        // Erzeugung je Monat gegen Vorjahr
        $gen = $this->monthlyOf('pv_erzeugung', true);
        // vermiedenes CO₂ genau einmal: bei der Erzeugung, ohne Erzeugungszähler bei der Einspeisung
        $co2 = Utilities::exists('pv_erzeugung') ? $this->yearData('pv_erzeugung', $this->year)['co2'] : 0.0;
        if ($co2 <= 0 && Utilities::exists('pv_einspeisung')) $co2 = $this->yearData('pv_einspeisung', $this->year)['co2'];
        return [
            'flow' => $flow, 'tiles' => $tiles, 'payback' => $pb, 'battery' => $battery, 'months' => $gen,
            'co2_text' => $co2 > 0 ? $this->i18n->t('report.topic.pv.co2Avoided', ['kg' => $this->co2Text($co2)]) : null,
            'sentence' => $this->i18n->t('report.topic.pv.sentence', [
                'gen' => $this->i18n->number($flow['gen'], 0), 'self' => $this->i18n->number($self, 0), 'feed' => $this->i18n->number($flow['feed'], 0),
                'grid' => $this->i18n->number($flow['grid'], 0), 'autarky' => $pv['autarkiequote'] !== null ? $this->advice->share((float)$pv['autarkiequote'] * 100) : '–',
            ]),
            'year_row' => $pv,   // für explainer.js energyHtml()
            'labels' => ['pv' => $this->i18n->t('report.topic.pv.nodePv'), 'grid' => $this->i18n->t('report.topic.pv.nodeGrid'),
                         'house' => $this->i18n->t('report.topic.pv.nodeHouse'), 'battery' => $this->i18n->t('report.topic.pv.nodeBattery')],
        ];
    }

    /** Monatswerte einer Art (Zähler in Summen, bei PV nur die Erzeugung) gegen Vorjahr. */
    private function monthlyOf(string $u, bool $generationOnly = false): array
    {
        $names = array_map('trim', explode(',', $this->i18n->t('format.monthsShort')));
        $now = array_fill(1, 12, null); $prev = array_fill(1, 12, null);
        foreach ($this->rootMeters($u) as $m) {
            if ($generationOnly && Utilities::roleOf($u, $m) !== 'generation' && Utilities::roleOf($u, $m) !== null) continue;
            foreach ($this->rows($u, $m) as $r) {
                $mo = (int)$r['month'];
                if ((int)$r['year'] === $this->year) $now[$mo] = ($now[$mo] ?? 0.0) + (float)($r['kwh'] ?? 0);
                if ((int)$r['year'] === $this->year - 1) $prev[$mo] = ($prev[$mo] ?? 0.0) + (float)($r['kwh'] ?? 0);
            }
        }
        $out = [];
        for ($mo = 1; $mo <= 12; $mo++) $out[] = ['label' => $names[$mo - 1] ?? (string)$mo, 'value' => $now[$mo], 'prev' => $prev[$mo], 'estimated' => false];
        return $out;
    }

    private function heatPumpTopic(array $hp): ?array
    {
        if ($hp === []) return null;
        $this->term('jaz');
        $this->sources['heatPump'] = true;
        $names = array_map('trim', explode(',', $this->i18n->t('format.monthsShort')));
        $pumps = [];
        foreach ($hp as $p) {
            $months = [];
            foreach ($p['months'] as $m) $months[] = ['label' => $names[(int)$m['month'] - 1] ?? '', 'value' => (float)$m['cop'], 'prev' => null, 'estimated' => false];
            $pumps[] = ['name' => $p['name'], 'jaz' => $p['jaz'], 'jaz_season' => $p['jaz_season'], 'reference' => $p['reference'],
                'months' => $months,
                'sentence' => $this->i18n->t('report.topic.heatPump.sentence', [
                    'heat' => $this->i18n->number((float)$p['heat_kwh'], 0), 'elec' => $this->i18n->number((float)$p['elec_kwh'], 0),
                    'jaz' => $this->i18n->number((float)$p['jaz'], 1),
                ]),
                'reference_text' => $this->i18n->t('report.topic.heatPump.reference', [
                    'air' => $this->i18n->number((float)$p['reference'], 1), 'ground' => $this->i18n->number((float)$p['reference_ground'], 1)])];
        }
        return ['pumps' => $pumps];
    }

    // ── Anhang ───────────────────────────────────────────────────────────

    /**
     * „Zahlen im Detail“: eine Tabelle je Verbrauchsart (Summe der Zähler in
     * Summen). Temperatur, Gradtage und Witterungsbereinigung nur bei
     * Heizarten (Mangel 3); Kostenspalte nur, wo Verträge rechnen (Mangel 4).
     */
    private function appendix(array $consumptionU, array $utilities): array
    {
        $out = [];
        foreach ($utilities as $u) {
            $kind = Utilities::accountingKind($u);
            $vf = self::valueField($u); $dec = $this->decimals($u);
            $heating = Utilities::isHgtRelevant($u) && $kind === 'consumption';
            $rows = [];
            $hasCost = false;
            $sum = ['value' => 0.0, 'cost' => 0.0];
            $byYm = [];
            foreach ($this->rootMeters($u) as $m) {
                foreach ($this->rows($u, $m) as $r) {
                    if ((int)$r['year'] !== $this->year) continue;
                    $ym = (string)$r['ym'];
                    $byYm[$ym] ??= ['value' => 0.0, 'cost' => 0.0, 'temp' => $r['avg_temp'] ?? null, 'hdd' => $r['hdd'] ?? null, 'adj' => 0.0, 'delta' => $r['weather_delta_pct'] ?? null];
                    $byYm[$ym]['value'] += (float)($r[$vf] ?? 0);
                    $byYm[$ym]['cost'] += (float)($r['cost'] ?? 0);
                    $byYm[$ym]['adj'] = ($r['heat_adjusted'] ?? null) === null || $byYm[$ym]['adj'] === null ? null : $byYm[$ym]['adj'] + (float)$r['heat_adjusted'];
                    if (!empty($r['contract_id'])) $hasCost = true;
                }
            }
            if ($byYm === []) continue;
            ksort($byYm);
            $nf = fn(?float $v, int $d) => $v === null ? '–' : $this->i18n->number($v, $d);
            foreach ($byYm as $ym => $x) {
                $row = [$this->i18n->month($ym), $nf($x['value'], $dec)];
                if ($hasCost && $kind !== 'generation') $row[] = $nf($x['cost'], 2);
                if ($heating) {
                    $row[] = $x['temp'] !== null ? $nf((float)$x['temp'], 1) : '–';
                    $row[] = $x['hdd'] !== null ? $nf((float)$x['hdd'], 0) : '–';
                    $row[] = $x['adj'] !== null && $x['adj'] > 0 ? $nf((float)$x['adj'], 0) : '–';
                    $row[] = $x['delta'] !== null ? $this->advice->pct((float)$x['delta']) : '–';
                }
                $rows[] = $row;
                $sum['value'] += $x['value']; $sum['cost'] += $x['cost'];
            }
            $head = [$this->i18n->t('report.tableMonth'), self::unit($u)];
            if ($hasCost && $kind !== 'generation') $head[] = $kind === 'feed_in' ? $this->i18n->t('report.tableRevenue') : $this->i18n->t('report.tableCost');
            if ($heating) array_push($head, $this->i18n->t('report.tableTemp'), $this->i18n->t('report.tableHdd'),
                $this->i18n->t('report.tableWeatherAdj'), $this->i18n->t('report.tableDelta'));
            $total = [$this->i18n->t('report.appendix.total'), $nf($sum['value'], $dec)];
            if ($hasCost && $kind !== 'generation') $total[] = $nf($sum['cost'], 2);
            $notes = [];
            if ($heating) $notes[] = $this->i18n->t('report.weatherNote');
            foreach ($this->subMeters($u) as $s) $notes[] = $s['text'];
            if ($heating && array_filter(array_column($byYm, 'temp'), fn($v) => $v !== null) !== []) {
                $notes[] = $this->i18n->t('report.weatherSource');
                $this->sources['weather'] = true;
            }
            $out[] = ['utility' => $u, 'label' => $this->i18n->utilityLabel($u), 'head' => $head, 'rows' => $rows, 'total' => $total, 'notes' => $notes,
                      'numeric_from' => 1];
        }
        return $out;
    }

    // ── Wörterbuch, Quellen, feste Texte ─────────────────────────────────

    /** Nur Begriffe, die im Bericht vorkommen, je ein Satz. */
    private function glossary(): array
    {
        $out = [];
        foreach (array_keys($this->terms) as $id) {
            $term = $this->i18n->t("report.glossary.$id.term");
            if ($term === "report.glossary.$id.term") continue;
            $out[] = ['id' => $id, 'term' => $term, 'text' => $this->i18n->t("report.glossary.$id.text")];
        }
        usort($out, fn($a, $b) => strcoll($a['term'], $b['term']));
        return $out;
    }

    private function sourcesList(): array
    {
        $out = [];
        if (isset($this->sources['weather'])) $out[] = $this->i18n->t('report.weatherSource');
        if (isset($this->sources['heatPump'])) $out[] = $this->i18n->t('report.sources.heatPump');
        if (isset($this->sources['market'])) $out[] = $this->i18n->t('report.sources.market');
        $out[] = $this->i18n->t('report.sources.own');
        return $out;
    }

    /** Überschriften und feste Sätze, die beide Ausgaben brauchen. */
    private function texts(int $year): array
    {
        $k = ['important', 'successes', 'successesNone', 'goodToKnow', 'tasks', 'tasksIntro', 'tasksNone', 'tasksNoneButAgenda',
              'agenda', 'agendaIntro', 'agendaNone', 'money', 'topicPv', 'topicHeatPump', 'topicEv', 'topicFlex', 'topicTenancy',
              'glossary', 'sources', 'appendix', 'topTasks', 'lights', 'asOfToday', 'contracts', 'balances', 'costsByUtility',
              'costsByMonth', 'estimated', 'reference', 'cop', 'solarShare', 'cheapShare', 'errors'];
        $out = [];
        foreach ($k as $key) $out[$key] = $this->i18n->t("report.section.$key", ['year' => $year, 'prev' => $year - 1, 'date' => $this->i18n->date($this->today)]);
        $out['title'] = $this->i18n->t('report.title', ['year' => $year]);
        return $out;
    }
}
