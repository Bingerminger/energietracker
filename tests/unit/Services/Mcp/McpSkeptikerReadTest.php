<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services\Mcp;

use Energietracker\Services\Mcp\Provenance;
use Energietracker\Services\ReadingCheckService;
use Energietracker\Services\Mcp\Tools\ContractTools;

/**
 * v3.12.0 (Skeptiker H4, H5, M3 bis M6, M10, N2, N3, N7, N12, N13, Praxistest) — Herkunft und Wortlaut der lesenden Werkzeuge an
 * erfundenen Haushalten mit von Hand gerechneten Erwartungen: Einspeisung als Erlös, Saldo in Klartext, kein Betrag ohne Vertrag, kein
 * Saldo ohne Preis, gemessen nur aus echten Ablesungen, angebrochene Monate.
 */
final class McpSkeptikerReadTest extends McpReadTestCase
{
    private function device(string $u, string $id = 'd_1'): array
    {
        return [['id' => $id, 'serial' => null, 'installed_on' => '2020-01-01', 'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]];
    }

    /** Stände mit `$perDay` je Tag am Monatsersten von … bis (einschließlich). */
    private function monthly(string $u, string $meterId, string $from, string $to, float $perDay, string $device = 'd_1'): void
    {
        $rows = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', (int)strtotime("$d +1 month"))) {
            $rows[] = ['date' => $d, 'device_id' => $device, 'counter' => $perDay * (int)round((strtotime("$d 12:00") - strtotime("$from 12:00")) / 86400)];
        }
        $this->put($u, $meterId, $rows);
    }

    /** Stände eines Zählers setzen, die der anderen bleiben (setReadings ersetzt die ganze Datei). */
    private function put(string $u, string $meterId, array $rows): void
    {
        $keep = array_values(array_filter((array)$this->store->read("$u/readings.json", []), fn($r) => ($r['meter_id'] ?? '') !== $meterId));
        $this->setReadings($u, $meterId, $rows);
        $this->store->write("$u/readings.json", [...$keep, ...(array)$this->store->read("$u/readings.json", [])]);
    }

    // ── Saldo in Klartext ──

    /** @return array<string,mixed> Status des einzigen Vertrags */
    private function stromStatus(float $advance, string $today = '2026-10-15'): array
    {
        $this->settings->set(['active_utilities' => ['strom'], 'billing_cycle_anchor_strom' => '01-01']);
        $m = $this->setMeterDevices('strom', $this->device('strom'));
        // 10 kWh am Tag, 01.01. bis 01.10.2026 = 273 Tage = 2.730 kWh × 0,30 € = 819,00 €
        $this->monthly('strom', $m, '2026-01-01', '2026-10-01', 10.0);
        $this->contracts->create('strom', ['meter_id' => $m, 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => $advance]]]);
        return $this->callTool('get_contracts', ['utility' => 'strom'], $this->context('mcp', $today))['targets'][0]['contracts'][0]['status'];
    }

    public function testACreditIsSpelledOutEvenIfTheSignIsMisread(): void
    {
        // 9 × 150 € = 1.350,00 € Abschläge gegen 819,00 €: −531,00 €, also Guthaben
        $s = $this->stromStatus(150.0);
        self::assertSame(-531.0, $s['balance_measured']['value']);
        self::assertSame(['positive_is_back_payment', 'credit', 'Guthaben von 531,00 €'],
            [$s['balance_measured']['sign'], $s['balance_measured']['meaning'], $s['balance_measured']['text']]);
        self::assertSame('credit', $s['balance_today']['meaning']);
        self::assertStringStartsWith('Geschätzt: Guthaben von ', $s['balance_today']['text'], 'der Saldo bis heute ist geschätzt und heißt auch so');
        self::assertArrayHasKey('meaning', $s['expected_balance']);
    }

    public function testABackPaymentAndZeroAreSpelledOutToo(): void
    {
        // 9 × 80 € = 720,00 € gegen 819,00 €: +99,00 €, also Nachzahlung
        $s = $this->stromStatus(80.0);
        self::assertSame([99.0, 'back_payment', 'Nachzahlung von 99,00 €'], [$s['balance_measured']['value'], $s['balance_measured']['meaning'], $s['balance_measured']['text']]);
        // 819,00 € / 9 = 91,00 € Abschlag: genau 0
        $this->tearDownContracts();
        $z = $this->stromStatus(91.0);
        self::assertSame([0.0, 'balanced', 'Ausgeglichen'], [$z['balance_measured']['value'], $z['balance_measured']['meaning'], $z['balance_measured']['text']]);
    }

    private function tearDownContracts(): void
    {
        $this->store->write('strom/contracts.json', []);
    }

    public function testTheSentenceAlsoStandsInTheTextOfTheAnswer(): void
    {
        $this->stromStatus(150.0);
        $r = $this->rpc('tools/call', ['name' => 'get_contracts', 'arguments' => ['utility' => 'strom']], $this->context('mcp', '2026-10-15'));
        $content = $r['body']['result']['content'];
        self::assertCount(2, $content, 'zweiter Textblock für Programme, die nur content weitergeben');
        self::assertStringContainsString('balance_measured: Guthaben von 531,00 €', $content[1]['text']);
        // Englisch in der Sprache des Servers
        $this->i18n->setLocale('en');
        $en = $this->callTool('get_contracts', ['utility' => 'strom'], $this->context('mcp', '2026-10-15', 'en'))['targets'][0]['contracts'][0]['status'];
        self::assertStringStartsWith('Credit of ', $en['balance_measured']['text']);
    }

    public function testTheToolDescriptionsNameTheFields(): void
    {
        $tools = array_column($this->rpc('tools/list')['body']['result']['tools'], null, 'name');
        foreach (['get_overview', 'get_contracts'] as $t) {
            self::assertStringContainsString('meaning', $tools[$t]['description'], $t);
            self::assertStringContainsString('text', $tools[$t]['description'], $t);
        }
        foreach (Provenance::MEANINGS as $meaning) self::assertNotSame("mcp.balance.$meaning", $this->i18n->t("mcp.balance.$meaning", ['amount' => '1'], 'de'));
    }

    // ── H4 Einspeisung ──

    /** Einspeisung: 8,2 ct/kWh, 5 kWh am Tag, seit 01.01.2025; letzte Ablesung 01.10.2026 */
    private function feedIn(): string
    {
        $this->settings->set(['active_utilities' => ['pv_einspeisung', 'pv_erzeugung']]);
        $m = $this->setMeterDevices('pv_einspeisung', $this->device('pv_einspeisung'));
        $this->monthly('pv_einspeisung', $m, '2025-01-01', '2026-10-01', 5.0);
        $this->contracts->create('pv_einspeisung', ['meter_id' => $m, 'provider' => 'Netzbetreiber Musterstadt', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 8.2]]]);
        return $m;
    }

    public function testFeedInIsRevenueNotCost(): void
    {
        $m = $this->feedIn();
        $ctx = $this->context('mcp', '2026-11-04');
        $out = $this->callTool('get_consumption', ['utility' => 'pv_einspeisung', 'meter' => $m, 'from' => '2026-03', 'to' => '2026-03'], $ctx);
        // März: 31 × 5 kWh = 155 kWh × 0,082 € = 12,71 €
        self::assertSame('feed_in', $out['accounting_kind']);
        self::assertSame(['2026-03', 155.0, 12.71], [$out['rows'][0]['period'], $out['rows'][0]['value'], $out['rows'][0]['revenue']]);
        self::assertArrayNotHasKey('cost', $out['rows'][0]);
        self::assertSame(12.71, $out['total']['revenue']['value']);
        self::assertArrayNotHasKey('cost', $out['total']);

        $over = $this->callTool('get_overview', ['utility' => 'pv_einspeisung'], $ctx)['meters'][0];
        self::assertSame('feed_in', $over['accounting_kind']);
        self::assertArrayNotHasKey('cost', $over['forecast_12m'] ?? []);
        // Saldo: bis zur letzten Ablesung 01.10.2026, nicht bis heute; Beginn steht dabei. v3.12.0 — die Einspeisung
        // rechnet je Abrechnungsjahr (Stichtag 01.01.): Beginn 01.01.2026, nicht mehr der Vertragsbeginn 01.01.2025
        $status = $over['contract']['status'];
        self::assertSame(['2026-10-01', '2026-01-01', 'payout', 'positive_is_payout'],
            [$status['balance_today']['as_of'], $status['balance_from'], $status['balance_today']['meaning'], $status['balance_today']['sign']]);
        self::assertStringStartsWith('Auszahlung von ', $status['balance_today']['text']);
        // v3.12.0 (Nachprüfung C4) — der Satz nennt den Tag, seit dem die Summe läuft
        self::assertStringEndsWith('seit 01.01.2026', $status['balance_today']['text']);

        $rep = $this->callTool('get_yearly_report', ['year' => 2025], $ctx);
        $row = array_values(array_filter($rep['utilities'], fn($x) => $x['utility'] === 'pv_einspeisung'))[0];
        // 2025: 364 Tage bis zum 31.12. plus Anteil: 365 × 5 kWh = 1.825 kWh × 0,082 € = 149,65 €
        self::assertSame(['feed_in', 149.65], [$row['accounting_kind'], $row['revenue']['value']]);
        self::assertArrayNotHasKey('cost', $row);
        self::assertArrayNotHasKey('co2_kg', $row, 'CO₂ nur beim Verbrauch');
        self::assertArrayHasKey('revenue', $rep['meters'][0]['months'][0]);
    }

    public function testGenerationCarriesNoMoneyAtAll(): void
    {
        $this->settings->set(['active_utilities' => ['pv_erzeugung']]);
        $m = $this->setMeterDevices('pv_erzeugung', $this->device('pv_erzeugung'));
        $this->monthly('pv_erzeugung', $m, '2026-01-01', '2026-10-01', 8.0);
        $out = $this->callTool('get_consumption', ['utility' => 'pv_erzeugung', 'meter' => $m], $this->context('mcp', '2026-11-04'));
        self::assertSame('generation', $out['accounting_kind']);
        foreach ($out['rows'] as $r) {
            self::assertArrayNotHasKey('cost', $r);
            self::assertArrayNotHasKey('revenue', $r);
            self::assertArrayNotHasKey('no_contract', $r, 'Erzeugung hat nie einen Vertrag: kein Hinweis darauf');
        }
    }

    // ── M4 kein Vertrag ──

    public function testNoContractMeansNoAmountAndASubMeterSaysWhere(): void
    {
        $this->settings->set(['active_utilities' => ['strom']]);
        $this->store->write('strom/contracts.json', []);   // ServiceTestCase legt einen Vertrag an
        $main = $this->setMeterDevices('strom', $this->device('strom'));
        $this->monthly('strom', $main, '2025-01-01', '2026-10-01', 10.0);
        $sub = $this->meters->create('strom', ['name' => 'Wallbox', 'parent_meter_id' => $main, 'installed_on' => '2020-01-01']);
        $dev = (string)$this->meters->get('strom', (string)$sub['id'])['devices'][0]['id'];
        $this->monthly('strom', (string)$sub['id'], '2025-01-01', '2026-10-01', 4.0, $dev);
        $ctx = $this->context('mcp', '2026-11-04');
        $m = $this->callTool('get_consumption', ['utility' => 'strom', 'meter' => (string)$sub['id']], $ctx)['rows'][0];
        self::assertArrayNotHasKey('cost', $m, 'nicht 0 €: es gibt keinen Preis');
        self::assertSame([true, true], [$m['no_contract'], $m['included_in_parent']]);
        $year = $this->callTool('get_consumption', ['utility' => 'strom', 'meter' => $main, 'granularity' => 'year'], $ctx)['rows'][0];
        self::assertSame(true, $year['no_contract']);
        self::assertArrayNotHasKey('cost', $year);
        self::assertArrayNotHasKey('included_in_parent', $year, 'der Hauptzähler steckt nirgends drin');
        $over = $this->callTool('get_overview', ['utility' => 'strom'], $ctx)['meters'][0];
        self::assertArrayNotHasKey('cost', $over['forecast_12m'] ?? []);
        $rep = $this->callTool('get_yearly_report', ['year' => 2025], $ctx);
        self::assertSame(true, $rep['utilities'][0]['no_contract']);
        self::assertArrayNotHasKey('cost', $rep['utilities'][0]);
    }

    /**
     * v3.12.0 (Nachprüfung B2) — dieselbe Regel in Prognose und Abrechnungsjahr: `get_forecast` sagte `cost: 0`, das Abrechnungsjahr
     * „Ausgeglichen“. Ein Vertrag, der erst in der zweiten Periode beginnt: Die erste bleibt ohne Betrag, die zweite rechnet.
     */
    public function testForecastAndBillingYearWithoutAContractGiveNoAmountAndNoSentence(): void
    {
        $this->settings->set(['active_utilities' => ['strom'], 'billing_cycle_anchor_strom' => '01-01']);
        $this->store->write('strom/contracts.json', []);
        $main = $this->setMeterDevices('strom', $this->device('strom'));
        $this->monthly('strom', $main, '2024-01-01', '2026-10-01', 10.0);
        $ctx = $this->context('mcp', '2026-11-04');

        $fc = $this->callTool('get_forecast', ['utility' => 'strom', 'meter' => $main, 'months' => 3], $ctx);
        self::assertTrue($fc['valid']);
        foreach ($fc['months'] as $r) {
            self::assertArrayNotHasKey('cost', $r, 'nicht 0 €');
            self::assertTrue($r['no_contract']);
        }
        self::assertArrayNotHasKey('cost', $fc['total']);
        self::assertTrue($fc['total']['no_contract']);

        $rows = $this->callTool('get_consumption', ['utility' => 'strom', 'meter' => $main, 'granularity' => 'billing_period'], $ctx)['rows'];
        foreach ($rows as $r) {
            foreach (['cost', 'advances', 'balance', 'balance_meaning', 'balance_text', 'balance_sign'] as $k) self::assertArrayNotHasKey($k, $r, "{$r['period']}: $k");
            self::assertTrue($r['no_contract']);
            self::assertGreaterThan(0.0, $r['value'], 'die Menge bleibt');
        }
        $rep = $this->callTool('get_yearly_report', ['year' => 2025], $ctx);
        foreach ($rep['billing_periods'][0]['periods'] ?? [] as $r) self::assertArrayNotHasKey('balance_text', $r);

        // ab 01.01.2026 ein Vertrag: 2025 ohne Betrag, 2026 mit Saldo; die Prognose mit Kosten
        $this->contracts->create('strom', ['meter_id' => $main, 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 100.0]]]);
        $byFrom = array_column($this->callTool('get_consumption', ['utility' => 'strom', 'meter' => $main, 'granularity' => 'billing_period'], $ctx)['rows'], null, 'from');
        self::assertTrue($byFrom['2025-01-01']['no_contract']);
        self::assertArrayNotHasKey('balance_text', $byFrom['2025-01-01']);
        self::assertArrayNotHasKey('no_contract', $byFrom['2026-01-01']);
        self::assertArrayHasKey('balance_text', $byFrom['2026-01-01']);
        $fc2 = $this->callTool('get_forecast', ['utility' => 'strom', 'meter' => $main, 'months' => 2], $ctx);
        // 10 kWh am Tag × 0,30 € = 3,00 € je Tag, der erste Monat der Prognose hat mindestens 28 Tage
        self::assertGreaterThan(80.0, $fc2['months'][0]['cost']);
        self::assertArrayHasKey('cost', $fc2['total']);
    }

    // ── M5 kein Preis ──

    public function testWithoutAPriceThereIsNoVerdict(): void
    {
        $this->settings->set(['active_utilities' => ['strom'], 'billing_cycle_anchor_strom' => '01-01']);
        $m = $this->setMeterDevices('strom', $this->device('strom'));
        $this->monthly('strom', $m, '2026-01-01', '2026-10-01', 10.0);
        $this->contracts->create('strom', ['meter_id' => $m, 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 55.0]]]);
        $c = $this->callTool('get_contracts', ['utility' => 'strom'], $this->context('mcp', '2026-10-15'))['targets'][0]['contracts'][0];
        self::assertTrue($c['price_missing']);
        self::assertArrayNotHasKey('verdict', $c['status'], 'bis dahin „refund“ aus den Abschlägen allein');
        foreach (['balance_today', 'balance_measured', 'expected_balance'] as $k) {
            self::assertSame('no_price', $c['status'][$k]['meaning'], $k);
            self::assertContains('price_missing', $c['status'][$k]['uncertain'], $k);
        }
        // 9 × 55 € = 495,00 €
        self::assertSame('Kein Saldo: Der Arbeitspreis fehlt, 495,00 € sind nur die Abschläge', $c['status']['balance_measured']['text']);
        self::assertSame('entered', $c['status']['balance_measured']['basis'], 'v3.12.0 (Nachprüfung C5): nur Abschläge, nichts gemessen');
    }

    // ── M7 Stand über dem nächsten ──

    public function testAReadingTheCalculationPassesOverIsNamedWhereYouLook(): void
    {
        $this->settings->set(['active_utilities' => ['gas']]);
        $m = $this->setMeterDevices('gas', $this->device('gas'));
        $this->setReadings('gas', $m, [
            ['date' => '2024-06-01', 'device_id' => 'd_1', 'counter' => 1500.0],
            ['date' => '2024-08-15', 'device_id' => 'd_1', 'counter' => 1565.0],   // über dem nächsten
            ['date' => '2024-10-01', 'device_id' => 'd_1', 'counter' => 1560.5],
        ]);
        $checks = new ReadingCheckService($this->store, $this->meters, $this->readings, $this->consumption, $this->settings);
        $skipped = $checks->forMeter('gas', $this->meters->get('gas', $m), '2024-12-01')['skipped'];
        self::assertSame([['outlier', 'spike', '2024-08-15', 1565.0]], array_map(fn($w) => [$w['type'], $w['kind'], $w['date'], $w['counter']], $skipped));
        $rows = $this->callTool('list_readings', ['utility' => 'gas', 'meter' => $m], $this->context('mcp', '2024-12-01'))['readings'];
        $flagged = array_values(array_filter($rows, fn($r) => !empty($r['skipped'])));
        self::assertSame(['2024-08-15'], array_column($flagged, 'date'));
    }

    // ── M6 gemessen ──

    public function testMeasuredReachesOnlyToTheLastRealReading(): void
    {
        // ohne die Rechnung: Der Saldo beginnt am 01.01.; echt bis 01.06., geschätzter Stand am 01.07.
        $this->household();
        $this->store->write('gas/bills.json', []);
        $c = $this->callTool('get_contracts', ['utility' => 'gas'], $this->context('mcp', self::TODAY))['targets'][0]['contracts'][0];
        // 330 m³ × 10 = 3.300 kWh × 0,10 € = 330,00 €; 5 × 12 € = 60,00 €; 5 × 100 € = 500,00 €: −110,00 €
        self::assertSame(['2026-06-01', '2026-06-01'], [$c['status']['measured_until'], $c['status']['balance_measured']['as_of']]);
        self::assertEqualsWithDelta(-110.0, $c['status']['balance_measured']['value'], 0.02, 'verteilte Monate auf 0,1 kWh gerundet');
        self::assertSame('measured', $c['status']['balance_measured']['basis'], 'nur Gemessenes');
        self::assertSame('credit', $c['status']['balance_measured']['meaning']);
        // Saldo-Karte: derselbe Dienst; der Juni an der geschätzten Ablesung (200 kWh × 0,10 € = 20,00 €) zählt zum geschätzten Teil
        $row = $this->consumption->contractStatus('gas', $this->meters->get('gas', 'm_gas_default'), self::TODAY)['contracts'][0];
        self::assertSame(['2026-06-01', '2026-07-01'], [$row['measured_until'], $row['reading_until']]);
        // ohne Tage nach der Ablesung bleibt genau der geschätzte Teil an ihr: 200 kWh × 0,10 € = 20,00 €
        $atReading = $this->consumption->contractStatus('gas', $this->meters->get('gas', 'm_gas_default'), '2026-07-01')['contracts'][0];
        self::assertEqualsWithDelta(20.0, (float)$atReading['estimated_cost_to_date'], 0.02);
        self::assertEqualsWithDelta(-110.0, (float)$row['balance_measured']['balance'], 0.02);
    }

    public function testAContractStartingBetweenTwoReadingsIsNotAllMeasured(): void
    {
        // Beginn 10.02. zwischen den Ablesungen 01.01. und 15.03.: Der Teil bis 15.03. ist verteilt, also heißt die Summe nicht „gemessen“
        $this->settings->set(['active_utilities' => ['strom'], 'billing_cycle_anchor_strom' => '01-01']);
        $this->store->write('strom/contracts.json', []);
        $m = $this->setMeterDevices('strom', $this->device('strom'));
        $this->setReadings('strom', $m, [['date' => '2026-01-01', 'device_id' => 'd_1', 'counter' => 0.0],
            ['date' => '2026-03-15', 'device_id' => 'd_1', 'counter' => 730.0], ['date' => '2026-06-15', 'device_id' => 'd_1', 'counter' => 1650.0]]);
        $this->contracts->create('strom', ['meter_id' => $m, 'provider' => 'Strom Musterstadt', 'start' => '2026-02-10',
            'working_prices' => [['from' => '2026-02-10', 'ct_per_kwh' => 30.0]], 'advance_payments' => [['from' => '2026-02-10', 'amount_eur' => 80.0]]]);
        $b = $this->callTool('get_contracts', ['utility' => 'strom'], $this->context('mcp', '2026-07-01'))['targets'][0]['contracts'][0]['status']['balance_measured'];
        self::assertSame(['distributed', '2026-06-15'], [$b['basis'], $b['as_of']]);
    }

    public function testNothingButAnEstimateGivesNoMeasuredBalance(): void
    {
        $this->settings->set(['active_utilities' => ['strom']]);
        $m = $this->setMeterDevices('strom', $this->device('strom'));
        $this->setReadings('strom', $m, [['date' => '2026-01-01', 'counter' => 0.0, 'device_id' => 'd_1', 'is_estimated' => true],
            ['date' => '2026-02-01', 'counter' => 300.0, 'device_id' => 'd_1', 'is_estimated' => true]]);
        $this->contracts->create('strom', ['meter_id' => $m, 'start' => '2026-01-01', 'provider' => 'Strom Musterstadt',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 50.0]]]);
        $c = $this->callTool('get_contracts', ['utility' => 'strom'], $this->context('mcp', '2026-03-01'))['targets'][0]['contracts'][0];
        self::assertArrayNotHasKey('measured_until', $c['status']);
        self::assertArrayNotHasKey('balance_measured', $c['status']);
    }

    public function testAGroupMemberReadLaterAddsNothingToTheMeasuredBalance(): void
    {
        // Gruppe mit zwei Zählern, gemeinsam bis 01.10., das zweite Mitglied mit einem geschätzten Stand am 03.10. (+10 kWh × 0,35 €)
        $this->settings->set(['active_utilities' => ['strom'], 'billing_cycle_anchor_strom' => '01-01']);
        $a = $this->setMeterDevices('strom', $this->device('strom'));
        $b = (string)$this->meters->create('strom', ['name' => 'NT', 'installed_on' => '2020-01-01'])['id'];
        $g = $this->meters->createGroup('strom', ['name' => 'HT/NT']);
        foreach ([$a, $b] as $mid) $this->meters->update('strom', $mid, ['meter_group_id' => $g['id']]);
        $devB = (string)$this->meters->get('strom', $b)['devices'][0]['id'];
        $this->monthly('strom', $a, '2026-01-01', '2026-10-01', 10.0);
        $this->monthly('strom', $b, '2026-01-01', '2026-10-01', 5.0, $devB);
        $this->contracts->create('strom', ['meter_group_id' => $g['id'], 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 35.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 100.0]]]);
        $status = fn() => $this->callTool('get_contracts', ['utility' => 'strom', 'meter' => (string)$g['id']], $this->context('mcp', '2026-10-15'))['targets'][0]['contracts'][0]['status']['balance_measured'];
        $before = $status();
        $rows = $this->readings->list('strom', $b);
        $rows[] = ['date' => '2026-10-03', 'device_id' => $devB, 'counter' => (float)end($rows)['counter'] + 10.0, 'is_estimated' => true];
        $this->put('strom', $b, $rows);
        $after = $status();
        self::assertSame(['2026-10-01', $before['value']], [$after['as_of'], $after['value']], 'bis dahin +3,50 € im Saldo „bis 01.10.“');
    }

    // ── M3, N3 Monatszeilen ──

    public function testAPartialMonthSaysSoAndABonusExplainsNegativeCosts(): void
    {
        $this->settings->set(['active_utilities' => ['strom']]);
        $m = $this->setMeterDevices('strom', $this->device('strom'));
        $this->setReadings('strom', $m, [['date' => '2026-08-01', 'counter' => 0.0, 'device_id' => 'd_1'], ['date' => '2026-09-01', 'counter' => 310.0, 'device_id' => 'd_1'],
            ['date' => '2026-10-01', 'counter' => 610.0, 'device_id' => 'd_1'], ['date' => '2026-10-26', 'counter' => 860.0, 'device_id' => 'd_1']]);
        $this->contracts->create('strom', ['meter_id' => $m, 'provider' => 'Strom Musterstadt', 'start' => '2026-08-01',
            'working_prices' => [['from' => '2026-08-01', 'ct_per_kwh' => 10.0]],
            'bonuses' => [['credit_date' => '2026-09-15', 'amount_eur' => 100.0, 'type' => 'neukunde']]]);
        $rows = array_column($this->callTool('get_consumption', ['utility' => 'strom', 'meter' => $m], $this->context('mcp', '2026-11-04'))['rows'], null, 'period');
        // September: 300 kWh × 0,10 € − 100 € = −70,00 €, und `bonus` erklärt es
        self::assertSame([-70.0, 100.0], [$rows['2026-09']['cost'], $rows['2026-09']['bonus']]);
        self::assertArrayNotHasKey('bonus', $rows['2026-08']);
        // Oktober: 25 von 31 Tagen
        self::assertSame([25, false, '2026-10-25'], [$rows['2026-10']['days'], $rows['2026-10']['complete'], $rows['2026-10']['covered_until']]);
        self::assertArrayNotHasKey('complete', $rows['2026-09']);
    }

    public function testThePromptSaysToCompareOnlyFullMonths(): void
    {
        $text = $this->rpc('prompts/get', ['name' => 'monthly_report'])['body']['result']['messages'][0]['content']['text'];
        self::assertStringContainsString('complete false', $text);
        self::assertStringContainsString('covered_until', $text);
        // Skeptiker N1: was die Gliederung verlangt, liefert der Ablauf
        $year = $this->rpc('prompts/get', ['name' => 'yearly_report'])['body']['result']['messages'][0]['content']['text'];
        self::assertStringContainsString('get_insights', $year);
        self::assertStringContainsString('get_forecast', $year);
        $dead = $this->rpc('prompts/get', ['name' => 'deadlines_check'])['body']['result']['messages'][0]['content']['text'];
        self::assertStringContainsString('renewal_term', $dead);
        self::assertStringNotContainsString('etwa dass sich ein Vertrag verlängert', $dead);
    }

    // ── N2, N13 Verträge ──

    public function testPastContractsShowPricesAndAPriceStepItsPrice(): void
    {
        $this->settings->set(['active_utilities' => ['strom'], 'billing_cycle_anchor_strom' => '07-01']);
        $m = $this->setMeterDevices('strom', $this->device('strom'));
        $this->monthly('strom', $m, '2025-01-01', '2026-10-01', 10.0);
        $this->contracts->create('strom', ['meter_id' => $m, 'provider' => 'Alt', 'start' => '2025-01-01', 'end' => '2025-12-31', 'auto_renews' => false,
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 25.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 9.0]]]);
        $this->contracts->create('strom', ['meter_id' => $m, 'provider' => 'Neu', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0], ['from' => '2026-12-01', 'ct_per_kwh' => 33.0]],
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 90.0]]]);
        $rows = $this->callTool('get_contracts', ['utility' => 'strom', 'include_past' => true], $this->context('mcp', '2026-11-04'))['targets'][0]['contracts'];
        $by = array_column($rows, null, 'phase');
        self::assertSame(25.0, $by['past']['prices']['working_price']['value'], 'Skeptiker N2: auch vergangene Verträge');
        self::assertSame(['2026-12-01', 33.0], [$by['current']['price_increase']['from'], $by['current']['price_increase']['working_price']['value']]);
        self::assertSame('2026-12-01', $by['current']['price_increase_from']);
        self::assertSame('07-01', $by['current']['billing_period']['anchor'] ?? null, 'Skeptiker N13: Stichtag wie die App');
    }

    // ── N7 Gruppe ──

    public function testAGroupContractStandsOnceAndNamesThePricePerMeter(): void
    {
        $this->settings->set(['active_utilities' => ['strom']]);
        $a = $this->setMeterDevices('strom', $this->device('strom'));
        $b = (string)$this->meters->create('strom', ['name' => 'NT', 'installed_on' => '2020-01-01'])['id'];
        $g = $this->meters->createGroup('strom', ['name' => 'HT/NT']);
        foreach ([$a, $b] as $mid) $this->meters->update('strom', $mid, ['meter_group_id' => $g['id']]);
        $devB = (string)$this->meters->get('strom', $b)['devices'][0]['id'];
        $this->monthly('strom', $a, '2026-01-01', '2026-10-01', 10.0);
        $this->monthly('strom', $b, '2026-01-01', '2026-10-01', 5.0, $devB);
        $this->contracts->create('strom', ['meter_group_id' => $g['id'], 'provider' => 'Strom Musterstadt', 'start' => '2026-01-01',
            'working_prices_by_meter' => [$a => [['from' => '2026-01-01', 'ct_per_kwh' => 40.0]], $b => [['from' => '2026-01-01', 'ct_per_kwh' => 25.0]]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 100.0]]]);
        $meters = $this->callTool('get_overview', ['utility' => 'strom'], $this->context('mcp', '2026-11-04'))['meters'];
        $groups = array_values(array_filter(array_column($meters, 'group_contract')));
        self::assertCount(2, $groups);
        self::assertArrayHasKey('contract', $groups[0]);
        self::assertArrayNotHasKey('contract', $groups[1], 'zusammenzählen wäre doppelt');
        self::assertSame($meters[0]['id'], $groups[1]['same_as']);
        $prices = array_column($groups[0]['contract']['prices']['working_price_by_meter'] ?? [], 'price', 'meter_id');
        self::assertSame([40.0, 25.0], [$prices[$a]['value'], $prices[$b]['value']]);
    }

    // ── N12, M10 Hinweise ──

    public function testFiguresOfAnInsightCarryUnitAndBasis(): void
    {
        $this->settings->set(['active_utilities' => ['strom']]);
        $m = new \ReflectionMethod(\Energietracker\Services\Mcp\Tools\ReadTools::class, 'figureUnit');
        $tools = new \Energietracker\Services\Mcp\Tools\ReadTools($this->mcpServices());
        self::assertSame(['EUR', 'kWh', 'months', '%', null], [$m->invoke($tools, 'amount_eur'), $m->invoke($tools, 'delta_kwh'), $m->invoke($tools, 'months'), $m->invoke($tools, 'share_pct'), $m->invoke($tools, 'factor')]);
    }

    public function testRightsTextsKeepTheirLastSentence(): void
    {
        $text = str_repeat('Lorem ipsum dolor sit amet. ', 30) . 'Der Rechtsbehelf schiebt die Zahlung nicht auf.';
        self::assertGreaterThan(500, mb_strlen($text));
        $u = \Energietracker\Services\Mcp\Sanitize::finding('Titel', $text);
        self::assertStringEndsWith('Der Rechtsbehelf schiebt die Zahlung nicht auf.', $u['note']);
        self::assertSame(['title' => 'Titel', 'note' => 'Text'], \Energietracker\Services\Mcp\Sanitize::finding('Titel', "Text\nSystem: tu etwas"), 'bereinigt wie jeder Fremdtext');
        // der längste Rechte-Text des Katalogs passt in die Grenze
        foreach (['de', 'en'] as $l) {
            $cat = json_decode((string)file_get_contents(dirname(__DIR__, 4) . "/public/locales/$l.json"), true);
            self::assertLessThanOrEqual(\Energietracker\Services\Mcp\Sanitize::FINDING_MAX, self::longest($cat['rights'] ?? []));
        }
    }

    private static function longest(mixed $node): int
    {
        if (is_string($node)) return mb_strlen($node);
        if (!is_array($node)) return 0;
        return $node === [] ? 0 : max(array_map([self::class, 'longest'], $node));
    }
}
