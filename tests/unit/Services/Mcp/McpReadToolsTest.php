<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

/**
 * v3.12.0 (F1037, Paket lesen) — die lesenden Werkzeuge am erfundenen Haushalt
 * (McpReadTestCase::household) mit von Hand gerechneten Erwartungen (Lektion 46):
 * Gas 10 kWh/m³, 10 ct/kWh, 12 €/Monat, Abschlag 100 €, Preisgarantie bis 30.06.,
 * heute 15.07.2026.
 */
final class McpReadToolsTest extends McpReadTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->household();
    }

    private function call(string $tool, array $args = [], string $today = self::TODAY): array
    {
        return $this->callTool($tool, $args, $this->context('mcp', $today));
    }

    private function error(string $tool, array $args = []): array
    {
        return $this->callToolError($tool, $args, $this->context('mcp', self::TODAY));
    }

    public function testOverviewCarriesTheHandCalculatedFiguresWithTheirBasis(): void
    {
        $out = $this->call('get_overview', ['utility' => 'gas']);
        self::assertSame(['2026-07-15', 'EUR'], [$out['as_of'], $out['currency']]);
        $gas = $out['meters'][0];
        self::assertSame(['m_gas_default', ['name' => 'Hauptzähler']], [$gas['id'], $gas['untrusted']]);
        // letzter Stand: 1 350 m³ am 01.07., als geschätzt erfasst
        self::assertSame(['value' => 1350.0, 'unit' => 'm³', 'basis' => 'estimated', 'as_of' => '2026-07-01'], $gas['last_reading']['counter']);
        self::assertSame(14, $gas['days_since_reading']);
        // Jahr bis zur letzten Ablesung: (1 350 − 1 000) m³ × 10 = 3 500 kWh (verteilte Monate auf 0,1 gerundet),
        // davon 3 300 kWh zwischen echten Ablesungen (gemessen), 200 kWh an der geschätzten (3 300/3 500 = 0,94)
        $ytd = $gas['year_to_date'];
        self::assertEqualsWithDelta(3500.0, $ytd['value'], 0.2);
        self::assertSame(['kWh', 'estimated', '2026-07-01', ['measured' => 0.94, 'estimated' => 0.06], true],
            [$ytd['unit'], $ytd['basis'], $ytd['as_of'], $ytd['parts'], $ytd['derived']]);
        self::assertArrayNotHasKey('month_to_date', $gas, 'Juli noch nicht abgelesen: kein Wert statt 0');

        $c = $gas['contract'];
        self::assertSame(['provider' => 'Gaswerk Musterstadt', 'tariff' => 'Grund'], $c['untrusted']);
        // Preisgarantie bis 30.06., heute 15.07.: Preise angenommen
        self::assertSame(['value' => 10.0, 'unit' => 'ct/kWh', 'basis' => 'assumed'], $c['prices']['working_price']);
        self::assertSame(['value' => 12.0, 'unit' => 'EUR/month', 'basis' => 'assumed'], $c['prices']['base_price']);
        self::assertSame(100.0, $c['prices']['advance']['value']);
        // v3.12.0 (Skeptiker M6) — Saldo seit der Rechnung (ab 01.06.): Die letzte echte Ablesung liegt am 01.06., der Stand
        // vom 01.07. ist geschätzt. Gemessen ist also nichts: kein Wert statt falscher Herkunft (bis dahin −68 € „bis 01.07.“)
        self::assertArrayNotHasKey('balance_measured', $c['status']);
        self::assertArrayNotHasKey('measured_until', $c['status']);
        self::assertSame(['estimated', true, 'positive_is_back_payment'], [$c['status']['balance_today']['basis'], $c['status']['balance_today']['derived'], $c['status']['balance_today']['sign']]);
        self::assertSame('estimated', $c['status']['expected_balance']['basis']);
        // zu wenig gemessen: die Spanne der Saldo-Karte (projection_uncertain), keine einzelne Zahl für den Abschlag
        $cs = $this->consumption->contractStatus('gas', $this->meters->get('gas', 'm_gas_default'), self::TODAY)['contracts'][0];
        self::assertSame([array_map('floatval', $cs['projection_uncertain']['projected_range']), ['few_measured']],
            [$c['status']['expected_balance']['range'], $c['status']['expected_balance']['uncertain']]);
        self::assertSame(array_map('floatval', $cs['projection_uncertain']['suggested_range']), $c['status']['suggested_advance_range']['range']);
        self::assertArrayNotHasKey('suggested_advance', $c['status']);
        self::assertSame(['count' => 0], $out['deadlines']['overdue']);
        $this->assertEveryNumberHasProvenance($out, self::EXEMPT);
    }

    public function testOverviewOfAnUnknownMeterIsAToolError(): void
    {
        $err = $this->error('get_overview', ['meter' => 'm_gibt_es_nicht']);
        self::assertSame('errors.mcp.meterNotFound', $err['code']);
        self::assertStringContainsString('list_meters', $err['message']);
        self::assertSame('errors.mcp.invalidArguments', $this->error('get_overview', ['zaehler' => 'x'])['code'], 'additionalProperties: false');
    }

    public function testDeadlinesShowWhetherADateIsEnteredOrCalculated(): void
    {
        $gas = $this->call('get_deadlines', ['utility' => 'gas']);
        // letzte Ablesung 01.07. + 45 Tage (alert_days_since_reading) = 15.08.
        self::assertSame([['reading-gas-m_gas_default', 'reading_due', '2026-08-15', 'derived', 'upcoming', false]],
            array_map(fn($e) => [$e['uid'], $e['kind'], $e['date'], $e['date_source'], $e['severity'], $e['due_now']], $gas['events']));
        self::assertSame(['utility' => 'gas', 'meter_id' => 'm_gas_default'], $gas['events'][0]['ref']);
        self::assertSame([], $this->call('get_deadlines', ['utility' => 'gas', 'days' => 30])['events'], 'außerhalb von 30 Tagen');
        $all = $this->call('get_deadlines');
        // Abnahme Open WebUI: Die Gesamtzahl steht dabei, damit ein Modell nicht still Einträge weglässt
        self::assertSame([1, count($all['events'])], [$gas['count'], $all['count']]);
        self::assertSame(['reading-strom-m_strom_default', 'reading-wasser-m_wasser_default'],
            array_column(array_filter($all['events'], fn($e) => $e['due_now']), 'uid'));
        $this->assertEveryNumberHasProvenance($all);
        self::assertSame('errors.mcp.invalidArguments', $this->error('get_deadlines', ['days' => 400])['code']);
    }

    public function testReadingsKeepTheirBasisAndOnlyACleanedNote(): void
    {
        $out = $this->call('list_readings', ['utility' => 'gas', 'meter' => 'm_gas_default']);
        self::assertSame(5, $out['count']);
        self::assertSame([['2026-01-01', 1000.0, 'measured'], ['2026-02-01', 1100.0, 'measured'], ['2026-03-01', 1180.0, 'measured'],
            ['2026-06-01', 1330.0, 'measured'], ['2026-07-01', 1350.0, 'estimated']],
            array_map(fn($r) => [$r['date'], $r['counter'], $r['basis']], $out['readings']));
        self::assertSame(['note' => 'Keller'], $out['readings'][2]['untrusted'], 'Zeile mit Rollenmarke entfernt');
        self::assertSame('csv', $out['readings'][3]['source']);

        $last = $this->call('list_readings', ['utility' => 'gas', 'meter' => 'm_gas_default', 'limit' => 2]);
        self::assertSame([5, ['2026-06-01', '2026-07-01'], true], [$last['count'], array_column($last['readings'], 'date'), $last['truncated']]);
        $range = $this->call('list_readings', ['utility' => 'gas', 'meter' => 'm_gas_default', 'from' => '2026-02-01', 'to' => '2026-06-01']);
        self::assertSame(['2026-02-01', '2026-03-01', '2026-06-01'], array_column($range['readings'], 'date'));
        self::assertSame('errors.mcp.rangeInvalid', $this->error('list_readings', ['utility' => 'gas', 'meter' => 'm_gas_default',
            'from' => '2026-06-01', 'to' => '2026-02-01'])['code']);
        self::assertSame('errors.mcp.invalidArguments', $this->error('list_readings', ['utility' => 'heizoel', 'meter' => 'x'])['code'],
            'nur Arten mit Zählerständen');

        // Alias (external_id) wie /api/summary?meter=
        $meters = $this->store->read('gas/meters.json', []);
        $meters[0]['external_id'] = 'sensor.gas';
        $this->store->write('gas/meters.json', $meters);
        self::assertSame('m_gas_default', $this->call('list_readings', ['utility' => 'gas', 'meter' => 'sensor.gas'])['meter']);
        $this->assertEveryNumberHasProvenance($out);
    }

    public function testConsumptionRowsAreMeasuredDistributedOrEstimatedLikeTheTable(): void
    {
        $out = $this->call('get_consumption', ['utility' => 'gas', 'meter' => 'm_gas_default']);
        $rows = array_column($out['rows'], null, 'period');
        // Januar 1 000 kWh × 0,10 € + 12 € = 112 €, Februar 800 kWh → 92 €, Juni 200 kWh → 32 €
        self::assertSame([1000.0, 112.0, 'measured'], [$rows['2026-01']['value'], $rows['2026-01']['cost'], $rows['2026-01']['basis']]);
        self::assertSame([800.0, 92.0, 'measured'], [$rows['2026-02']['value'], $rows['2026-02']['cost'], $rows['2026-02']['basis']]);
        self::assertSame(['distributed', 'distributed', 'distributed'], [$rows['2026-03']['basis'], $rows['2026-04']['basis'], $rows['2026-05']['basis']]);
        self::assertSame(92, $rows['2026-04']['reading_gap_days'], 'Abstand 01.03. bis 01.06.');
        self::assertEqualsWithDelta(1500.0, $rows['2026-03']['value'] + $rows['2026-04']['value'] + $rows['2026-05']['value'], 0.2);
        self::assertSame([200.0, 32.0, 'estimated'], [$rows['2026-06']['value'], $rows['2026-06']['cost'], $rows['2026-06']['basis']]);
        self::assertSame(['estimated', ['measured' => 0.94, 'estimated' => 0.06]], [$out['total']['quantity']['basis'], $out['total']['quantity']['parts']]);

        // die Summe der Art kennt die geschätzte Stütze nicht: aus den Zeilen der Zähler dahinter
        $sum = array_column($this->call('get_consumption', ['utility' => 'gas'])['rows'], 'basis', 'period');
        self::assertSame(['measured', 'distributed', 'estimated'], [$sum['2026-01'], $sum['2026-03'], $sum['2026-06']]);

        $year = $this->call('get_consumption', ['utility' => 'gas', 'meter' => 'm_gas_default', 'granularity' => 'year']);
        self::assertSame([['2026', 'estimated', 0.943]], array_map(fn($r) => [$r['period'], $r['basis'], $r['measured_share']], $year['rows']));

        // Abrechnungsjahre: die Rechnung Januar bis Mai liegt an echten Ablesungen (01.01., 01.06.): gemessen
        $bp = $this->call('get_consumption', ['utility' => 'gas', 'meter' => 'm_gas_default', 'granularity' => 'billing_period']);
        self::assertSame([['2026-01-01/2026-05-31', 3300.0, 390.0, -110.0, 'measured', 'bill'], ['2026-06-01/2026-12-31', 200.0, 32.0, -68.0, 'estimated', 'anchor']],
            array_map(fn($r) => [$r['period'], $r['value'], $r['cost'], $r['balance'], $r['basis'], $r['source']], $bp['rows']));
        $part = $this->call('get_consumption', ['utility' => 'gas', 'meter' => 'm_gas_default', 'from' => '2026-02', 'to' => '2026-03']);
        self::assertSame(['2026-02', '2026-03'], array_column($part['rows'], 'period'));
        self::assertSame('distributed', $part['total']['quantity']['basis'], 'März schneidet den Abstand bis 01.06.');
        self::assertSame('errors.billCheck.unsupportedUtility', $this->error('get_consumption', ['utility' => 'heizoel', 'granularity' => 'billing_period'])['code']);
        foreach ([$out, $year, $bp, $part] as $o) $this->assertEveryNumberHasProvenance($o);
    }

    public function testContractsShowPricesAndIdentifiersOnlyWithTheSwitch(): void
    {
        $out = $this->call('get_contracts', ['utility' => 'gas']);
        $c = $out['targets'][0]['contracts'][0];
        self::assertSame(['current', '2026-01-01', '2026-06-30', 'assumed'], [$c['phase'], $c['start'], $c['price_guarantee_until'], $c['prices']['working_price']['basis']]);
        self::assertArrayNotHasKey('balance_measured', $c['status'], 'seit der Rechnung nur der geschätzte Stand (Skeptiker M6)');
        self::assertArrayNotHasKey('contract_number', $c['untrusted']);
        self::assertStringNotContainsString('V-4711', (string)json_encode($out));
        // vor dem Ende der Preisgarantie: eingetragen
        self::assertSame('entered', $this->call('get_contracts', ['utility' => 'gas'], '2026-06-30')['targets'][0]['contracts'][0]['prices']['working_price']['basis']);

        $this->settings->set(['mcp_share_identifiers' => true]);
        $shared = $this->call('get_contracts', ['utility' => 'gas', 'meter' => 'm_gas_default'])['targets'][0]['contracts'][0];
        self::assertSame(['V-4711', 'K-0815'], [$shared['untrusted']['contract_number'], $shared['untrusted']['customer_number']]);
        self::assertSame('errors.mcp.invalidArguments', $this->error('get_contracts', ['utility' => 'pv_erzeugung'])['code'], 'Art ohne Verträge');
        $this->assertEveryNumberHasProvenance($out, self::EXEMPT);
    }

    public function testContractsLeaveOutOffersAndEndedOnesUnlessAsked(): void
    {
        $current = $this->call('get_contracts', ['utility' => 'gas'])['targets'][0]['contracts'][0]['contract_id'];
        $past = $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Altwerk', 'start' => '2025-01-01', 'end' => '2025-12-31',
            'auto_renews' => false, 'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 9.0]]]);
        $this->contracts->create('gas', ['meter_id' => 'm_gas_default', 'provider' => 'Angebot', 'start' => '2026-08-01', 'is_shadow' => true,
            'working_prices' => [['from' => '2026-08-01', 'ct_per_kwh' => 8.0]]]);
        self::assertSame([$current], array_column($this->call('get_contracts', ['utility' => 'gas'])['targets'][0]['contracts'], 'contract_id'),
            'kein Angebot zum Vergleich, kein abgelaufener');
        $all = $this->call('get_contracts', ['utility' => 'gas', 'include_past' => true])['targets'][0]['contracts'];
        self::assertSame([[$past['id'], 'past'], [$current, 'current']], array_map(fn($c) => [$c['contract_id'], $c['phase']], $all));
    }

    public function testForecastIsEstimatedAndCostsFollowThePrice(): void
    {
        $out = $this->call('get_forecast', ['utility' => 'gas', 'meter' => 'm_gas_default', 'months' => 3]);
        self::assertTrue($out['valid']);
        self::assertSame(['2026-07', '2026-08', '2026-09'], array_column($out['months'], 'ym'));
        self::assertSame(['estimated'], array_values(array_unique(array_column($out['months'], 'basis'))));
        // Kosten je Monat = Menge × 0,10 € + 12 € Grundpreis
        foreach ($out['months'] as $m) self::assertEqualsWithDelta($m['value'] * 0.10 + 12.0, $m['cost'], 0.011);
        self::assertEqualsWithDelta(array_sum(array_column($out['months'], 'value')), $out['total']['quantity']['value'], 0.001);
        self::assertContains('interpolated', $out['warnings'], 'verteilte Monate gesagt, nicht verschwiegen');
        $this->assertEveryNumberHasProvenance($out);
        self::assertSame('errors.mcp.invalidArguments', $this->error('get_forecast', ['utility' => 'gas'])['code'], 'Zähler ist Pflicht');
    }

    public function testScenarioSavingTenPercentIsTenPercentOfTheBaseline(): void
    {
        $out = $this->call('run_scenario', ['utility' => 'gas', 'meter' => 'm_gas_default', 'saving_pct' => 10]);
        $t = $out['totals'];
        self::assertEqualsWithDelta($t['base']['quantity']['value'] * 0.9, $t['scenario']['quantity']['value'], 0.2);
        self::assertSame(-10.0, $t['delta']['quantity_pct']['value']);
        // nur die Arbeitskosten sinken: Δ € = Δ kWh × 0,10 €
        self::assertEqualsWithDelta($t['delta']['quantity']['value'] * 0.10, $t['delta']['cost']['value'], 0.02);
        self::assertSame(12, count($out['months']));
        self::assertSame('errors.mcp.invalidArguments', $this->error('run_scenario', ['utility' => 'gas', 'meter' => 'm_gas_default', 'saving_pct' => 90])['code']);
        self::assertSame('errors.mcp.meterNotFound', $this->error('run_scenario', ['utility' => 'gas', 'meter' => 'x'])['code']);
        $this->assertEveryNumberHasProvenance($out, self::EXEMPT);
    }

    /** v3.12.0 (F1040) — dasselbe Profil wie die Oberfläche; die Zahlen der Beispiele sind Annahmen. */
    public function testScenarioNamesTheProfileOfItsGroup(): void
    {
        $p = $this->call('run_scenario', ['utility' => 'gas', 'meter' => 'm_gas_default'])['profile'];
        self::assertSame('heat_fuel', $p['group']);
        self::assertContains('other', array_column($p['measures'], 'kind'));
        self::assertTrue($p['levers']['saving']);
        self::assertNotSame([], $p['presets']);
        foreach ($p['presets'] as $preset) {
            self::assertNotSame([], $preset['arguments'], $preset['id']);
            foreach ($preset['arguments'] as $a) self::assertSame('assumed', $a['basis'], $a['argument']);
        }
    }

    public function testBillCheckHoldsTheInvoiceAgainstTheOwnCalculation(): void
    {
        $list = $this->call('list_bills', ['utility' => 'gas']);
        self::assertSame(['b_test01', 'entered', 3300.0, false], [$list['bills'][0]['id'], $list['bills'][0]['invoice']['amount']['basis'],
            $list['bills'][0]['invoice']['quantity']['value'], $list['bills'][0]['booked']]);

        $out = $this->call('check_bill', ['utility' => 'gas', 'bill_id' => 'b_test01']);
        // eigene Rechnung: 3 300 kWh × 0,10 € = 330 €, 5 × 12 € = 60 €, zusammen 390 €; Rechnung 396 € → Δ 6 € (1,54 %)
        self::assertSame([3300.0, 'measured'], [$out['ours']['quantity']['value'], $out['ours']['quantity']['basis']]);
        self::assertSame([330.0, 60.0, 390.0], [$out['ours']['energy_cost']['value'], $out['ours']['fixed_cost']['value'], $out['ours']['total']['value']]);
        self::assertSame([0.0, 6.0, 1.54], [$out['delta']['quantity']['value'], $out['delta']['amount']['value'], $out['delta']['amount_pct']['value']]);
        self::assertSame(['check', 'reading', 'reading'], [$out['verdict'], $out['boundaries']['start'], $out['boundaries']['end']]);

        $this->estimatedBoundaryBill();
        $june = $this->call('check_bill', ['utility' => 'gas', 'bill_id' => 'b_test02']);
        self::assertSame([200.0, 'estimated', 'reading_estimated'], [$june['ours']['quantity']['value'], $june['ours']['quantity']['basis'], $june['boundaries']['end']]);
        $reasons = array_column($june['reasons'], 'text', 'code');
        self::assertArrayHasKey('estimated_reading_at_boundary', $reasons);
        self::assertStringContainsString('geschätzt', $reasons['estimated_reading_at_boundary']);
        self::assertSame('errors.bill.notFound', $this->error('check_bill', ['utility' => 'gas', 'bill_id' => 'b_nicht'])['code']);
        foreach ([$list, $out, $june] as $o) $this->assertEveryNumberHasProvenance($o);
    }

    public function testBillCheckCarriesTheRightsFindingsOfThisBill(): void
    {
        // Jahresrechnung 2025, zugegangen am 30.04.2026: weit nach sechs Wochen (§ 40c Abs. 2 EnWG, Befund A1)
        $bills = (array)$this->store->read('gas/bills.json', []);
        $bills[] = ['id' => 'b_late', 'meter_id' => 'm_gas_default', 'contract_id' => null, 'kind' => 'annual',
            'period_from' => '2025-01-01', 'period_to' => '2025-12-31', 'issued_on' => '2026-04-30',
            'invoice' => ['amount_eur' => 500.0], 'items' => [], 'attachment_ids' => [], 'note' => '', 'created_at' => '2026-05-01T10:00:00+02:00'];
        $this->store->write('gas/bills.json', $bills);
        $out = $this->call('check_bill', ['utility' => 'gas', 'bill_id' => 'b_late']);
        self::assertSame(['bill_late'], array_column($out['rights'], 'check'));
        $f = $out['rights'][0];
        self::assertSame(['2026-04-30', 'complaint'], [$f['date'], $f['letter']]);
        self::assertStringContainsString('keine Rechtsberatung', $f['footer']);
        self::assertContains('§ 40c Abs. 2 EnWG', array_column($f['norms'], 'norm'));
        self::assertNotSame('', $f['untrusted']['title'] ?? '', 'Titel nur unter untrusted');
        self::assertSame([], $this->call('check_bill', ['utility' => 'gas', 'bill_id' => 'b_test01'])['rights'] ?? [], 'nur Befunde dieser Rechnung');
        $this->assertEveryNumberHasProvenance($out);
    }

    public function testYearlyReportSumsWithTheBasisOfTheWholeYear(): void
    {
        $out = $this->call('get_yearly_report', ['year' => 2026, 'utilities' => ['gas'], 'billing_period' => true]);
        $gas = $out['utilities'][0];
        self::assertSame('gas', $gas['utility']);
        self::assertEqualsWithDelta(3500.0, $gas['consumption']['value'], 0.2);
        self::assertSame(['measured' => 0.94, 'estimated' => 0.06], $gas['consumption']['parts'], 'Jahr ab der ersten Ablesung bis zur letzten');
        self::assertSame(['measured', 'measured', 'distributed', 'distributed', 'distributed', 'estimated'],
            array_column($out['meters'][0]['months'], 'basis'));
        self::assertSame('2026-01-01/2026-05-31', $out['billing_periods'][0]['periods'][0]['period']);
        self::assertSame(2025, $this->call('get_yearly_report')['year'], 'ohne Angabe das Vorjahr');
        $this->assertEveryNumberHasProvenance($out);
    }

    public function testInsightsCarryFiguresAndKeepTextUntrusted(): void
    {
        self::assertSame(['insights' => [], 'rights_check' => ['enabled' => true, 'bills_recorded' => 1]], $this->call('get_insights', ['category' => 'vertrag']));
        self::assertSame('errors.mcp.invalidArguments', $this->error('get_insights', ['category' => 'Vertrag!'])['code']);
    }
}
