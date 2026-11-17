<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Controllers\ForecastController;
use Energietracker\Services\ForecastService;
use Energietracker\Tests\Services\Mcp\McpHouseholdTestCase;

/**
 * v3.13.0 — Ein Vertrag ohne Arbeitspreis ergibt keinen Kostenbetrag: nicht 0 € und nicht nur den Grundpreis. Die
 * Monatszeile trägt `price_missing: true` (REST, additiv; der Betrag bleibt für die Rechnung, er ist nur Grundpreis),
 * ebenso die Summe der Art, eine Gruppe, die Prognose (`…/forecast`, `/api/summary`) und der laufende Vertrag; die KI
 * nennt dort keinen Betrag. Gilt auch, wenn der Arbeitspreis erst ab einem späteren Tag eingetragen ist: die Monate
 * davor. Ein Preis je Ablesung trägt den Monat weiter; Einspeisung braucht keinen Arbeitspreis. Alle Werte erfunden.
 */
final class PriceMissingTest extends McpHouseholdTestCase
{
    /**
     * Gas, 90 m³ = 900 kWh je Monat von Januar bis August 2026, Vertrag ab 01.01.2026 mit Abschlag 75 €; auf Wunsch ein
     * Preis je Ablesung (an den Ablesungen ab `$priceFrom`).
     */
    private function gas(array $contract, ?float $readingPrice = null, string $priceFrom = ''): void
    {
        $this->settings->set(['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.0]], 'active_utilities' => ['gas']]);
        $this->meters('gas', ['m_gas' => 'Heizraum']);
        $this->counters('gas', ['m_gas' => self::monthly('2026-01', '2026-09', 90)]);
        if ($readingPrice !== null) {
            $rows = (array)$this->store->read('gas/readings.json', []);
            foreach ($rows as $i => $r) if ((string)$r['date'] >= $priceFrom) $rows[$i]['price_cents'] = $readingPrice;
            $this->store->write('gas/readings.json', $rows);
        }
        $this->contracts->create('gas', array_merge(['meter_id' => 'm_gas', 'provider' => 'Gaswerk Musterstadt', 'tariff_name' => 'Basis', 'start' => '2026-01-01',
            'working_prices' => [], 'base_prices' => [], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 75.0]]], $contract));
    }

    /** @return array<string,array<string,mixed>> ym → REST-Monatszeile */
    private function months(string $u, string $meter): array
    {
        $out = [];
        foreach ($this->consumption->forMeter($u, $this->meters->get($u, $meter), null, self::DAY) as $r) $out[$r['ym']] = $r;
        return $out;
    }

    public function testWithoutAnyPriceThereIsNoAmount(): void
    {
        $this->gas([]);
        $m = $this->months('gas', 'm_gas');
        // REST: Kennzeichen an jedem Monat mit Verbrauch, der Betrag bleibt 0 (keine Verbrauchskosten)
        self::assertTrue($m['2026-03']['price_missing'] ?? false);
        self::assertSame(0.0, (float)$m['2026-03']['cost']);
        self::assertTrue($this->consumption->atDate(self::DAY, fn() => $this->consumption->forUtility('gas'))['monthly_total'][2]['price_missing'] ?? false, 'Summe der Art');
        $summary = $this->svc(\Energietracker\Services\SummaryService::class)->build(null, null, self::DAY)['meters'][0];
        self::assertTrue($summary['contract']['price_missing'] ?? false, '/api/summary: Vertrag');
        self::assertTrue($summary['forecast_12m']['price_missing'] ?? false, '/api/summary: Prognose');
        // REST-Prognose (…/meters/{id}/forecast): jeder Monat mit Kennzeichen
        $meter = $this->meters->get('gas', 'm_gas');
        $f = ForecastController::withPriceMissing($this->svc(ForecastService::class)->forMeter('gas', $meter, []), 'gas', $meter, $this->consumption);
        self::assertNotEmpty($f['forecast'] ?? []);
        self::assertSame([true],array_values(array_unique(array_map(fn($r) => $r['price_missing'] ?? false, $f['forecast']))));
        // KI: kein Betrag, das Kennzeichen; die Menge bleibt (900 kWh)
        $rows = $this->tool('get_consumption', ['utility' => 'gas', 'meter' => 'm_gas'])['rows'];
        $mar = self::row($rows, '2026-03');
        self::assertSame([900.0, true], [$mar['value'], $mar['price_missing']]);
        self::assertArrayNotHasKey('cost', $mar);
        self::assertArrayNotHasKey('no_contract', $mar, 'es gibt einen Vertrag, nur ohne Preis');
        $year = $this->tool('get_consumption', ['utility' => 'gas', 'granularity' => 'year']);
        self::assertSame([true, false], [self::row($year['rows'], '2026')['price_missing'], isset(self::row($year['rows'], '2026')['cost'])]);
        self::assertSame([true, false], [$year['total']['price_missing'], isset($year['total']['cost'])]);
        $ov = $this->tool('get_overview')['meters'][0];
        self::assertSame([true, false], [$ov['forecast_12m']['price_missing'], isset($ov['forecast_12m']['cost'])]);
        self::assertSame('no_price', $ov['contract']['status']['balance_today']['meaning']);
        $fc = $this->tool('get_forecast', ['utility' => 'gas', 'meter' => 'm_gas']);
        self::assertTrue($fc['valid']);
        self::assertTrue($fc['months'][0]['price_missing']);
        self::assertSame([true, false], [$fc['total']['price_missing'], isset($fc['total']['cost'])]);
        $report = $this->tool('get_yearly_report', ['year' => 2026]);
        self::assertSame([true, false], [$report['utilities'][0]['price_missing'], isset($report['utilities'][0]['cost'])]);
    }

    public function testOnlyABasePriceIsNoCostEither(): void
    {
        // 12 €/Monat Grundpreis: REST-Kosten 12 € je Monat (nur der Grundpreis), die KI nennt keinen Betrag
        $this->gas(['base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 12.0]]]);
        $m = $this->months('gas', 'm_gas');
        self::assertSame([12.0, true], [(float)$m['2026-05']['cost'], $m['2026-05']['price_missing'] ?? false]);
        $may = self::row($this->tool('get_consumption', ['utility' => 'gas', 'meter' => 'm_gas'])['rows'], '2026-05');
        self::assertSame([true, false], [$may['price_missing'], isset($may['cost'])]);
    }

    public function testAPriceOnTheReadingsCarriesTheMonth(): void
    {
        // kein Arbeitspreis im Vertrag, aber 9,5 ct je Einheit an den Ablesungen: 900 kWh × 0,095 € = 85,50 € je Monat
        $this->gas([], 9.5);
        $m = $this->months('gas', 'm_gas');
        self::assertArrayNotHasKey('price_missing', $m['2026-04']);
        self::assertEqualsWithDelta(85.5, (float)$m['2026-04']['cost'], 0.01);
        $apr = self::row($this->tool('get_consumption', ['utility' => 'gas', 'meter' => 'm_gas'])['rows'], '2026-04');
        self::assertSame([85.5, false], [$apr['cost'] ?? null, isset($apr['price_missing'])]);
        // Vertrag, Abrechnungsjahr, Jahr, Übersicht: dieselbe Regel wie am Monat (kein „Preis fehlt“, ein Saldo)
        $meter = $this->meters->get('gas', 'm_gas');
        self::assertFalse($this->consumption->contractStatus('gas', $meter, self::DAY)['contracts'][0]['price_missing']);
        $periods = $this->svc(\Energietracker\Services\BillingPeriodService::class)->forTarget('gas', $meter, false, self::DAY)['periods'];
        self::assertFalse(end($periods)['price_missing']);
        $summary = $this->svc(\Energietracker\Services\SummaryService::class)->build(null, null, self::DAY)['meters'][0];
        self::assertSame([false, false], [isset($summary['contract']['price_missing']), isset($summary['forecast_12m']['price_missing'])]);
        $bp = self::row($this->tool('get_consumption', ['utility' => 'gas', 'meter' => 'm_gas', 'granularity' => 'billing_period'])['rows'], '2026-01-01/2026-12-31');
        self::assertSame([false, true], [isset($bp['price_missing']), isset($bp['cost'])]);
        self::assertNotSame('no_price', $bp['balance_meaning'] ?? null);
        $year = $this->tool('get_consumption', ['utility' => 'gas', 'granularity' => 'year']);
        self::assertSame([false, true, false], [isset(self::row($year['rows'], '2026')['price_missing']), isset($year['total']['cost']), isset($year['total']['price_missing'])]);
        self::assertNotSame('no_price', $this->tool('get_overview')['meters'][0]['contract']['status']['balance_today']['meaning'] ?? null);
        // Prognose: die App rechnet mit dem letzten Ablesepreis weiter (Verbrauchskosten, ohne Grundpreis), kein Kennzeichen
        $f = $this->svc(ForecastService::class)->forMeter('gas', $meter, []);
        self::assertGreaterThan(0.0, (float)$f['forecast'][0]['cost_estimated']);
        self::assertSame([], array_values(array_filter(ForecastController::withPriceMissing($f, 'gas', $meter, $this->consumption)['forecast'],
            fn($r) => array_key_exists('price_missing', $r))));
    }

    public function testAPriceOnLaterReadingsLeavesTheMonthsBefore(): void
    {
        // Preis je Ablesung erst ab der Ablesung vom 01.05.2026: Januar bis März ohne Betrag; der April (01.04. bis 01.05.)
        // nimmt schon den Preis der Ablesung vom 01.05. (ein Abschnitt ohne Preis an seiner ersten Ablesung nimmt den seiner
        // letzten, Doku „Preis je Ablesung“), ab Mai 85,50 €; der Vertrag und das Abrechnungsjahr nennen „Preis fehlt“, die
        // Prognose rechnet mit dem Ablesepreis weiter
        $this->gas([], 9.5, '2026-05-01');
        $m = $this->months('gas', 'm_gas');
        self::assertSame([true, false], [$m['2026-03']['price_missing'] ?? false, isset($m['2026-06']['price_missing'])]);
        self::assertSame([false, 85.5], [isset($m['2026-04']['price_missing']), round((float)$m['2026-04']['cost'], 2)], 'April: Preis der Ablesung vom 01.05.');
        self::assertEqualsWithDelta(85.5, (float)$m['2026-06']['cost'], 0.01);
        $meter = $this->meters->get('gas', 'm_gas');
        self::assertTrue($this->consumption->contractStatus('gas', $meter, self::DAY)['contracts'][0]['price_missing']);
        $periods = $this->svc(\Energietracker\Services\BillingPeriodService::class)->forTarget('gas', $meter, false, self::DAY)['periods'];
        self::assertTrue(end($periods)['price_missing']);
        $f = ForecastController::withPriceMissing($this->svc(ForecastService::class)->forMeter('gas', $meter, []), 'gas', $meter, $this->consumption);
        self::assertSame([], array_values(array_filter($f['forecast'], fn($r) => array_key_exists('price_missing', $r))));
    }

    public function testAWorkingPriceFromALaterDayLeavesTheMonthsBeforeWithoutAmount(): void
    {
        // Arbeitspreis 11 ct erst ab 01.05.2026: Januar bis April ohne Betrag, ab Mai 900 kWh × 0,11 € = 99 €
        $this->gas(['working_prices' => [['from' => '2026-05-01', 'ct_per_kwh' => 11.0]]]);
        $m = $this->months('gas', 'm_gas');
        self::assertSame([true, true, true, true], [$m['2026-01']['price_missing'] ?? false, $m['2026-02']['price_missing'] ?? false,
            $m['2026-03']['price_missing'] ?? false, $m['2026-04']['price_missing'] ?? false]);
        self::assertArrayNotHasKey('price_missing', $m['2026-05']);
        self::assertEqualsWithDelta(99.0, (float)$m['2026-05']['cost'], 0.01);
        $rows = $this->tool('get_consumption', ['utility' => 'gas', 'meter' => 'm_gas'])['rows'];
        self::assertSame([true, 99.0], [self::row($rows, '2026-04')['price_missing'], self::row($rows, '2026-05')['cost']]);
        // ein Jahr und der Saldo mit Monaten ohne Preis: kein Betrag bzw. `no_price`
        self::assertTrue(self::row($this->tool('get_consumption', ['utility' => 'gas', 'granularity' => 'year'])['rows'], '2026')['price_missing']);
        $status = $this->consumption->contractStatus('gas', $this->meters->get('gas', 'm_gas'), self::DAY)['contracts'][0];
        self::assertTrue($status['price_missing'], 'der Saldo beginnt am 01.01., der Preis erst am 01.05.');
        // das Abrechnungsjahr 2026 ebenso: kein Kostenbetrag, Saldo `no_price` (REST und KI)
        $period = $this->svc(\Energietracker\Services\BillingPeriodService::class)->forTarget('gas', $this->meters->get('gas', 'm_gas'), false, self::DAY)['periods'];
        self::assertTrue(end($period)['price_missing'], 'REST: Periode 2026');
        $bp = self::row($this->tool('get_consumption', ['utility' => 'gas', 'meter' => 'm_gas', 'granularity' => 'billing_period'])['rows'], '2026-01-01/2026-12-31');
        self::assertSame([true, false, 'no_price'], [$bp['price_missing'], isset($bp['cost']), $bp['balance_meaning']]);
        // die Prognose ab September hat den Preis: kein Kennzeichen
        $meter = $this->meters->get('gas', 'm_gas');
        self::assertSame([], $this->consumption->priceMissingIn('gas', $meter, [['ym' => '2026-10', 'contract_id' => $status['contract_id']]]));
        // ein Preis ab dem Ersten: ohne Kennzeichen
        $this->contracts->update('gas', $status['contract_id'], ['working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 11.0]]]);
        $after = $this->months('gas', 'm_gas');
        self::assertSame([], array_values(array_filter($after, fn($r) => array_key_exists('price_missing', $r))), 'mit Preis fehlt das Feld');
    }

    public function testAGroupContractWithPricesPerRegisterOnly(): void
    {
        // Doppeltarif als Gruppe, Gruppenvertrag nur mit Preisen je Zählwerk (HT 29 ct, NT 19 ct), kein allgemeiner
        // Arbeitspreis: HT 140 kWh × 0,29 € + NT 60 kWh × 0,19 € = 40,60 € + 11,40 € = 52 € je Monat, nichts fehlt
        $this->meters('strom', ['m_ht' => 'Hochtarif', 'm_nt' => 'Niedertarif'],
            ['m_ht' => ['meter_group_id' => 'g_duo', 'obis' => '1.8.1'], 'm_nt' => ['meter_group_id' => 'g_duo', 'obis' => '1.8.2']]);
        $this->store->write('strom/meter_groups.json', [['id' => 'g_duo', 'name' => 'Doppeltarif', 'created_at' => '2025-12-01']]);
        $this->counters('strom', ['m_ht' => self::monthly('2026-01', '2026-09', 140), 'm_nt' => self::monthly('2026-01', '2026-09', 60)]);
        $contract = ['id' => 'c_duo', 'meter_id' => null, 'meter_group_id' => 'g_duo', 'provider' => 'Stadtwerke Musterstadt', 'tariff_name' => 'Zwei',
            'start' => '2026-01-01', 'end' => null, 'auto_renews' => true, 'notes' => '', 'working_prices' => [],
            'working_prices_by_meter' => ['m_ht' => [['from' => '2026-01-01', 'ct_per_kwh' => 29.0]], 'm_nt' => [['from' => '2026-01-01', 'ct_per_kwh' => 19.0]]],
            'base_prices' => [], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 50.0]], 'bonuses' => [], 'special_payments' => [], 'is_shadow' => false];
        $this->store->write('strom/contracts.json', [$contract]);
        $group = $this->meters->groupTarget('strom', 'g_duo');
        $months = [];
        foreach ($this->consumption->forMeter('strom', $group, null, self::DAY) as $r) $months[$r['ym']] = $r;
        self::assertSame([false, 52.0], [isset($months['2026-04']['price_missing']), round((float)$months['2026-04']['cost'], 2)]);
        self::assertFalse($this->consumption->contractStatus('strom', $group, self::DAY)['contracts'][0]['price_missing']);
        $periods = $this->svc(\Energietracker\Services\BillingPeriodService::class)->forTarget('strom', $group, false, self::DAY)['periods'];
        self::assertFalse(end($periods)['price_missing']);
        $f = ForecastController::withPriceMissing($this->svc(ForecastService::class)->forMeter('strom', $group, []), 'strom', $group, $this->consumption);
        self::assertGreaterThan(0.0, (float)$f['forecast'][0]['cost_estimated']);
        self::assertSame([], array_values(array_filter($f['forecast'], fn($r) => array_key_exists('price_missing', $r))));
        $fc = $this->tool('get_forecast', ['utility' => 'strom', 'meter' => 'g_duo']);
        self::assertSame([true, false], [isset($fc['months'][0]['cost']), isset($fc['months'][0]['price_missing'])]);
        // ohne Preis für NT fehlt er: Monat, Vertrag und Prognose sagen es
        $contract['working_prices_by_meter'] = ['m_ht' => [['from' => '2026-01-01', 'ct_per_kwh' => 29.0]]];
        $this->store->write('strom/contracts.json', [$contract]);
        $months = [];
        foreach ($this->consumption->forMeter('strom', $group, null, self::DAY) as $r) $months[$r['ym']] = $r;
        self::assertTrue($months['2026-04']['price_missing'] ?? false);
        self::assertTrue($this->consumption->contractStatus('strom', $group, self::DAY)['contracts'][0]['price_missing']);
        $f = ForecastController::withPriceMissing($this->svc(ForecastService::class)->forMeter('strom', $group, []), 'strom', $group, $this->consumption);
        self::assertTrue($f['forecast'][0]['price_missing'] ?? false);
    }

    public function testElectricityAndWaterToo(): void
    {
        $this->meters('strom', ['m_strom' => 'Haus']);
        $this->counters('strom', ['m_strom' => self::monthly('2026-01', '2026-09', 230)]);
        $this->contracts->create('strom', ['meter_id' => 'm_strom', 'provider' => 'Stadtwerke Musterstadt', 'start' => '2026-01-01',
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 8.5]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 65.0]]]);
        self::assertTrue($this->months('strom', 'm_strom')['2026-06']['price_missing'] ?? false);
        $this->meters('wasser', ['m_wasser' => 'Haus']);
        $this->counters('wasser', ['m_wasser' => self::monthly('2026-01', '2026-09', 8)]);
        $this->contracts->create('wasser', ['meter_id' => 'm_wasser', 'provider' => 'Wasserwerk Musterstadt', 'start' => '2026-01-01',
            'trinkwasser' => ['working_prices' => [], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 6.0]]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 30.0]]]);
        self::assertTrue($this->months('wasser', 'm_wasser')['2026-06']['price_missing'] ?? false);
        $w = self::row($this->tool('get_consumption', ['utility' => 'wasser', 'meter' => 'm_wasser'])['rows'], '2026-06');
        self::assertSame([true, false], [$w['price_missing'], isset($w['cost'])]);
    }

    public function testFeedInNeedsNoWorkingPrice(): void
    {
        $this->meters('pv_einspeisung', ['m_feed' => 'Dach']);
        $this->counters('pv_einspeisung', ['m_feed' => self::monthly('2026-01', '2026-09', 300)]);
        $this->contracts->create('pv_einspeisung', ['meter_id' => 'm_feed', 'provider' => 'Netz Musterstadt', 'start' => '2026-01-01']);
        self::assertSame([], array_values(array_filter($this->months('pv_einspeisung', 'm_feed'), fn($r) => !empty($r['price_missing']))));
    }
}
