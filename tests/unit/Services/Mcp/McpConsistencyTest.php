<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Config\Utilities;
use Energietracker\Services\AgendaService;
use Energietracker\Services\BillService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\Mcp\Provenance;
use Energietracker\Services\PdfReportService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\RightsService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\SummaryService;

/**
 * v3.12.0 (F1037, Paket lesen) — „gleiche Zahl wie die Oberfläche“ (Konzept
 * §8.1, Lektion 92): Jedes lesende Werkzeug nennt dieselben Werte wie der Dienst,
 * den der Endpunkt der Oberfläche ruft, am selben Tag und an denselben Daten:
 * erfundener, unordentlicher und Beispielhaushalt. Die Gegenprobe über HTTP
 * steht in tests/unit/Http/McpReadEndpointTest.php.
 */
final class McpConsistencyTest extends McpReadTestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function households(): iterable
    {
        yield 'erfunden' => ['household', self::TODAY];
        yield 'unordentlich' => ['messy', self::MESSY_TODAY];
        yield 'demo' => ['demo', self::DEMO_TODAY];
    }

    private string $today = self::TODAY;

    private function load(string $which, string $today): void
    {
        $this->today = $today;
        match ($which) {
            'household' => $this->household(),
            'messy'     => $this->messy(),
            default     => $this->persona($which),
        };
    }

    private function call(string $tool, array $args = []): array
    {
        return $this->callTool($tool, $args, $this->context('mcp', $this->today));
    }

    private static function value(?array $num): ?float
    {
        return $num === null ? null : (float)$num['value'];
    }

    /** GET /api/summary */
    #[\PHPUnit\Framework\Attributes\DataProvider('households')]
    public function testOverviewEqualsTheSummary(string $which, string $today): void
    {
        $this->load($which, $today);
        $summary = $this->svc(SummaryService::class)->build(null, null, $today);
        $out = $this->call('get_overview');
        self::assertSame(array_column($summary['meters'], 'meter_id'), array_column($out['meters'], 'id'));
        foreach ($summary['meters'] as $i => $s) {
            $m = $out['meters'][$i];
            $where = "$which {$s['key']}";
            self::assertSame($s['last_counter'], self::value($m['last_reading']['counter'] ?? null), "$where Stand");
            self::assertSame($s['days_since_reading'], $m['days_since_reading'] ?? null, "$where Tage");
            self::assertSame($s['year_to_date']['value'] ?? null, self::value($m['year_to_date'] ?? null), "$where Jahr");
            if (isset($m['month_to_date'])) self::assertSame($s['month_to_date']['value'], self::value($m['month_to_date']), "$where Monat");
            else self::assertContains($s['month_to_date']['value'] ?? null, [null, 0.0], "$where Monat ohne Zeile");
            $f = $s['forecast_12m'];
            self::assertSame($f['value'] ?? null, self::value($m['forecast_12m']['quantity'] ?? null), "$where Prognose");
            // v3.12.0 (Skeptiker H4, M4) — Vergütung als `revenue`, ohne Vertrag oder bei Erzeugung kein Betrag (dann war er 0)
            $money = self::value($m['forecast_12m']['cost'] ?? $m['forecast_12m']['revenue'] ?? null);
            if ($money !== null || abs((float)($f['cost'] ?? 0.0)) >= 0.005) self::assertSame($f['cost'] ?? null, $money, "$where Prognose €");
            self::assertSame(isset($f['low'], $f['high']) ? [(float)$f['low'], (float)$f['high']] : null, $m['forecast_12m']['quantity']['range'] ?? null);
            $c = $s['contract'];
            $mc = $m['contract'] ?? null;
            self::assertSame($c['contract_id'] ?? null, $mc['contract_id'] ?? null, "$where Vertrag");
            if ($c === null) continue;
            self::assertSame([$c['balance'], $c['projected_end_balance'], $c['measured_balance'], $c['suggested_advance'], $c['advance_ongoing']], [
                self::value($mc['status']['balance_today'] ?? null), self::value($mc['status']['expected_balance'] ?? null),
                self::value($mc['status']['balance_measured'] ?? null), self::value($mc['status']['suggested_advance'] ?? null),
                self::value($mc['status']['advance_ongoing'] ?? null)], "$where Saldo");
            // v3.12.0 (Skeptiker H4) — Einspeisung: ohne Hochrechnung nennt das Werkzeug den Tag der letzten Ablesung, /api/summary keinen
            if (!Utilities::isFeedIn((string)$m['utility'])) self::assertSame($c['measured_until'], $mc['status']['measured_until'] ?? null);
            self::assertSame([$c['working_price_ct'], $c['base_price_month'], $c['advance_month']], [self::value($mc['prices']['working_price'] ?? null),
                self::value($mc['prices']['base_price'] ?? null), self::value($mc['prices']['advance'] ?? null)], "$where Preise");
            self::assertSame($c['cancel_by'], $mc['cancel_by'] ?? null);
            if (is_array($c['projection_band'] ?? null)) {
                self::assertSame([(float)$c['projection_band']['low'], (float)$c['projection_band']['high']], $mc['status']['expected_balance']['range'], "$where Band");
            }
        }
        self::assertSame([$summary['agenda']['due'], $summary['agenda']['overdue'], $summary['warnings']],
            [$out['deadlines']['due']['count'], $out['deadlines']['overdue']['count'], $out['suspect_readings']['count']]);
        self::assertSame($summary['agenda']['next']['date'] ?? null, $out['deadlines']['next']['date'] ?? null);
    }

    /** GET …/meters/{id}/contract-status (mit Phase) */
    #[\PHPUnit\Framework\Attributes\DataProvider('households')]
    public function testContractsEqualTheContractStatus(string $which, string $today): void
    {
        $this->load($which, $today);
        $n = 0;
        foreach ((array)$this->settings->get('active_utilities', []) as $u) {
            if (!Utilities::hasContracts($u)) continue;
            $out = $this->call('get_contracts', ['utility' => $u, 'include_past' => true]);
            foreach ($out['targets'] as $t) {
                $target = $this->meters->target($u, $t['target']['id']);
                $status = $this->contracts->withPhases($u, $this->consumption->contractStatus($u, $target, $today), $today);
                $rows = array_values(array_filter($status['contracts'], fn($c) => ($c['phase'] ?? '') !== 'shadow'));
                self::assertSame(array_column($rows, 'contract_id'), array_column($t['contracts'], 'contract_id'), "$which $u");
                foreach ($rows as $i => $c) {
                    $mc = $t['contracts'][$i];
                    self::assertSame([$c['phase'], $c['current_balance'], $c['projected_end_balance'] ?? null, $c['balance_measured']['balance'] ?? null,
                        $c['suggested_advance'] ?? null, $c['settle_balance'] ?? null], [$mc['phase'], self::value($mc['status']['balance_today'] ?? null),
                        self::value($mc['status']['expected_balance'] ?? null), self::value($mc['status']['balance_measured'] ?? null),
                        self::value($mc['status']['suggested_advance'] ?? null), self::value($mc['status']['settle_balance'] ?? null)], "$which $u {$c['contract_id']}");
                    if (isset($mc['prices'])) self::assertSame($c['current_working_price_ct'], self::value($mc['prices']['working_price'] ?? null));
                    self::assertSame([$c['cancel_by'], $c['settle_on']], [$mc['cancel_by'] ?? null, $mc['settle_on'] ?? null]);
                    // v3.12.0 (integration) — Felder aus v3.11.0: Frist, Verlängerung, Lücke, Bonus nach dem Tag, Preisstufe
                    self::assertSame([$c['notice_basis'], $c['switch_date'], $c['successor']['gap_days'] ?? null, $c['successor']['gap_covered'] ?? null,
                        $c['price_increase']['from'] ?? null, round((float)($c['bonus_to_date'] ?? 0.0), 2)],
                        [$mc['notice_basis'] ?? null, $mc['switch_date'] ?? null, $mc['successor']['gap_days'] ?? null, $mc['successor']['gap_covered'] ?? null,
                         $mc['price_increase_from'] ?? null, (float)self::value($mc['status']['bonus_to_date'] ?? null)], "$which $u {$c['contract_id']} 3.11");
                    if (is_array($c['renewal_term'] ?? null)) {
                        self::assertSame([$c['renewal_term']['until'], $c['renewal_term']['cancel_by'], 'assumed'],
                            [$mc['renewal_term']['until'], $mc['renewal_term']['cancel_by'], $mc['renewal_term']['basis']], "$which $u Verlängerung");
                    }
                    $n++;
                }
            }
        }
        self::assertGreaterThan(0, $n, "$which: keine Verträge geprüft");
    }

    /** GET …/meters/{id}/consumption, …/consumption, …/billing-periods */
    #[\PHPUnit\Framework\Attributes\DataProvider('households')]
    public function testConsumptionEqualsTheMonthlyRowsAndBillingYears(string $which, string $today): void
    {
        $this->load($which, $today);
        foreach ((array)$this->settings->get('active_utilities', []) as $u) {
            $vf = (Utilities::get($u)['consumption_unit'] === 'kWh') ? 'kwh' : 'm3';
            $total = $this->consumption->forUtility($u)['monthly_total'];
            $out = $this->call('get_consumption', ['utility' => $u]);
            $rows = array_slice($total, -60);
            self::assertSame(array_map(fn($r) => [(string)$r['ym'], round((float)$r[$vf], 3), round((float)$r['cost'], 2)], $rows),
                array_map(fn($r) => [$r['period'], $r['value'], $r['cost'] ?? $r['revenue'] ?? 0.0], $out['rows']), "$which $u Summe der Art");
            foreach ($this->meters->list($u) as $m) {
                $monthly = $this->consumption->forMeter($u, $m);
                if ($monthly === []) continue;
                $mo = $this->call('get_consumption', ['utility' => $u, 'meter' => (string)$m['id']]);
                $last = array_slice($monthly, -60);
                self::assertSame(array_map(fn($r) => [(string)$r['ym'], round((float)$r[$vf], 3), round((float)$r['cost'], 2), (int)$r['days']], $last),
                    array_map(fn($r) => [$r['period'], $r['value'], $r['cost'] ?? $r['revenue'] ?? 0.0, $r['days']], $mo['rows']), "$which $u {$m['id']}");
                // „≈“ der Oberfläche (interpolated === true) ⇔ nicht gemessen
                foreach ($last as $i => $r) {
                    if (($r['interpolated'] ?? null) === null) continue;
                    self::assertSame($r['interpolated'] === true, $mo['rows'][$i]['basis'] !== 'measured', "$which $u {$r['ym']} ≈");
                }
                if (!Utilities::supportsBillCheck($u) || ($m['capture'] ?? null) === 'period') continue;
                $bp = $this->call('get_consumption', ['utility' => $u, 'meter' => (string)$m['id'], 'granularity' => 'billing_period']);
                $periods = array_values(array_filter($this->svc(BillingPeriodService::class)->forTarget($u, $m, false, $today)['periods'],
                    fn($p) => is_string($p['as_of'] ?? null)));
                self::assertSame(array_map(fn($p) => [$p['from'], $p['until'], round((float)$p[$vf], 3), round((float)$p['cost'], 2), round((float)$p['balance'], 2)], $periods),
                    // v3.12.0 (Nachprüfung B2) — ohne Vertrag steht dort kein Betrag; die App rechnet 0
                    array_map(fn($r) => [$r['from'], $r['until'], $r['value'], $r['cost'] ?? 0.0, $r['balance'] ?? 0.0], $bp['rows']), "$which $u {$m['id']} Abrechnungsjahre");
                self::assertSame(array_map(fn($p) => [$p['advance_vs_cost']['factor'] ?? null, $p['advance_vs_cost']['contract_id'] ?? null], $periods),
                    array_map(fn($r) => [$r['advance_vs_cost']['factor'] ?? null, $r['advance_vs_cost']['contract_id'] ?? null], $bp['rows']), "$which $u {$m['id']} Abschläge gegen Kosten");
            }
        }
    }

    /** GET …/meters/{id}/forecast und POST /api/forecast/scenario */
    #[\PHPUnit\Framework\Attributes\DataProvider('households')]
    public function testForecastAndScenarioEqualTheirServices(string $which, string $today): void
    {
        $this->load($which, $today);
        $checked = 0;
        foreach ((array)$this->settings->get('active_utilities', []) as $u) {
            $vf = (Utilities::get($u)['consumption_unit'] === 'kWh') ? 'kwh' : 'm3';
            foreach ($this->meters->list($u) as $m) {
                if ($this->readings->list($u, (string)$m['id']) === [] && !Utilities::isDelivery($u)) continue;
                $f = $this->svc(ForecastService::class)->forMeter($u, $m, ['forecast_months' => 18]);
                $out = $this->call('get_forecast', ['utility' => $u, 'meter' => (string)$m['id'], 'months' => 18]);
                self::assertSame(!empty($f['valid']), $out['valid']);
                if (empty($f['valid'])) continue;
                self::assertSame(array_map(fn($r) => [$r['ym'], round((float)$r[$vf], 3)], $f['forecast']),
                    array_map(fn($r) => [$r['ym'], $r['value']], $out['months']), "$which $u {$m['id']} Prognose");
                self::assertSame($f['annual']['value'] ?? null, self::value($out['annual'] ?? null));
                $checked++;
                if (!ScenarioService::supports($u)) continue;
                $in = ['utility' => $u, 'meter_id' => (string)$m['id'], 'saving_pct' => 10.0];
                $s = $this->svc(ScenarioService::class)->compute($in, $today);
                $o = $this->call('run_scenario', ['utility' => $u, 'meter' => (string)$m['id'], 'saving_pct' => 10]);
                if (empty($s['valid'])) { self::assertFalse($o['valid']); continue; }
                self::assertSame([$s['totals']['base']['qty'], $s['totals']['scenario']['cost'], $s['totals']['delta']['cost']],
                    [self::value($o['totals']['base']['quantity']), self::value($o['totals']['scenario']['cost']), self::value($o['totals']['delta']['cost'])],
                    "$which $u {$m['id']} Szenario");
                if (isset($s['advance']['base_recommended'])) {
                    self::assertSame((float)$s['advance']['base_recommended'], self::value($o['advance']['base_recommended'] ?? null), "$which $u Abschlag");
                } elseif (is_array($s['advance']['uncertain']['base_range'] ?? null)) {
                    self::assertSame(array_map('floatval', $s['advance']['uncertain']['base_range']), $o['advance']['base_recommended']['range'], "$which $u Spanne");
                }
            }
        }
        self::assertGreaterThan(0, $checked, "$which: keine Prognose geprüft");
    }

    /** GET …/bills und …/bills/{id}/check */
    #[\PHPUnit\Framework\Attributes\DataProvider('households')]
    public function testBillsEqualTheBillCheck(string $which, string $today): void
    {
        $this->load($which, $today);
        if ($which === 'household') $this->estimatedBoundaryBill();
        $bills = $this->svc(BillService::class);
        $n = 0;
        foreach ((array)$this->settings->get('active_utilities', []) as $u) {
            if (!Utilities::supportsBillCheck($u)) continue;
            $list = $bills->list($u);
            self::assertSame(array_column($list, 'id'), array_column($this->call('list_bills', ['utility' => $u])['bills'], 'id'));
            foreach ($list as $b) {
                $c = $bills->compare($u, (string)$b['id']);
                $o = $this->call('check_bill', ['utility' => $u, 'bill_id' => (string)$b['id']]);
                $qk = $u === 'wasser' ? 'm3' : 'kwh';
                self::assertSame([$c['verdict'], $c['reasons'], $c['ours'][$qk], $c['ours']['total'], $c['delta']['eur'], $c['boundaries']['start'], $c['boundaries']['end']],
                    [$o['verdict'] ?? null, array_column($o['reasons'], 'code'), self::value($o['ours']['quantity'] ?? null), self::value($o['ours']['total'] ?? null),
                     self::value($o['delta']['amount'] ?? null), $o['boundaries']['start'] ?? null, $o['boundaries']['end'] ?? null], "$which $u {$b['id']}");
                self::assertSame(Provenance::weakest(Provenance::boundary($c['boundaries']['start']), Provenance::boundary($c['boundaries']['end'])),
                    $o['ours']['quantity']['basis'] ?? 'measured', 'Menge so gut wie ihre Ränder');
                // v3.12.0 (integration) — dieselben Rechte-Hinweise wie „Rechnung prüfen“, Frist schon am Werktag
                $found = array_values(array_filter($this->svc(RightsService::class)->findings($today), fn($f) => ($f['bill_id'] ?? null) === $b['id']));
                self::assertSame(array_map(fn($f) => [$f['id'], $f['deadline'], $f['amount_eur']], $found),
                    array_map(fn($r) => [$r['id'], $r['deadline'] ?? null, self::value($r['amount'] ?? null)], $o['rights'] ?? []), "$which $u {$b['id']} Rechte");
                $n++;
            }
        }
        if ($which !== 'messy') self::assertGreaterThan(0, $n, "$which: keine Rechnung geprüft");
    }

    /** GET /api/agenda (Zu tun), /api/recommendations, /api/reports/yearly */
    #[\PHPUnit\Framework\Attributes\DataProvider('households')]
    public function testDeadlinesInsightsAndYearlyReportEqualTheirServices(string $which, string $today): void
    {
        $this->load($which, $today);
        $events = $this->svc(AgendaService::class)->events(120, $today);
        $out = $this->call('get_deadlines', ['days' => 120, 'include_done' => true]);
        self::assertSame(array_map(fn($e) => [$e['uid'], $e['date'], $e['severity'], $e['due_now']], array_slice($events, 0, 100)),
            array_map(fn($e) => [$e['uid'], $e['date'], $e['severity'], $e['due_now']], $out['events']), "$which Fristen");

        $recs = $this->svc(RecommendationService::class)->atDate($today)->all();
        self::assertSame(array_slice(array_column($recs, 'id'), 0, 30), array_column($this->call('get_insights')['insights'], 'id'), "$which Hinweise");

        $year = (int)substr($today, 0, 4) - 1;
        $data = $this->svc(PdfReportService::class)->yearlyData($year, ['today' => $today]);
        $rep = $this->call('get_yearly_report', ['year' => $year]);
        self::assertSame(array_map(fn($x) => [$x['utility'], $x['consumption'], $x['cost']], $data['utilities']),
            array_map(fn($x) => [$x['utility'], self::value($x['consumption']), self::value($x['cost'] ?? $x['revenue'] ?? null) ?? 0.0], $rep['utilities']), "$which Jahresbericht");
        self::assertSame(array_map(fn($x) => [$x['meter_id'], $x['kpis']['sum']], $data['meters']),
            array_map(fn($x) => [$x['id'], self::value($x['sum'])], $rep['meters']));
    }
}
