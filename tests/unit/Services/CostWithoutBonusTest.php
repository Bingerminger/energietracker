<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ContractService;
use Energietracker\Services\CsvExportService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ReportModelService;
use Energietracker\Services\TariffComparisonService;
use Energietracker\Services\TemperatureService;
use Energietracker\Services\WeatherService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.7.0 (GitHub #33, #35) — Kosten ohne Bonus, der Bonus als eigene Zeile.
 *
 * Der gemeldete Fall: ein Neukundenbonus mit der ersten Rechnung ließ die
 * Kachel „Kosten“ um den Bonus kleiner erscheinen, obwohl der Vertrag so viel
 * kostete. Jetzt zählen Kachel, Jahresbericht und die neuen CSV-Spalten ohne
 * Bonus; der Saldo rechnet ihn ein (er steht auf der Rechnung). Dazu der neue
 * Bonustyp „Sonstige Gutschrift“ (`sonstige`) mit Notiz — er zählt wie jeder
 * Bonus im Monat der Gutschrift.
 *
 * Daten (erfunden): Strom 2025, 200 kWh je Monat, 30 ct, 10 €/Monat, Abschlag
 * 80 €; Neukundenbonus 120 € am 15.03.2025, sonstige Gutschrift 25 € am
 * 10.06.2025 („Kulanz“).
 */
#[CoversClass(ContractService::class)]
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(ReportModelService::class)]
#[CoversClass(CsvExportService::class)]
#[CoversClass(TariffComparisonService::class)]
final class CostWithoutBonusTest extends ServiceTestCase
{
    use ReportFixture;

    private string $meterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [[
            'id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);
        $rows = [];
        for ($m = 0; $m <= 12; $m++) $rows[] = ['date' => date('Y-m-d', (int)strtotime("2025-01-01 +$m months")), 'counter' => 200.0 * $m, 'device_id' => 'd1'];
        $this->setReadings('strom', $this->meterId, $rows);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Stadtwerke Beispielstadt', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 80.0]],
            'bonuses' => [
                ['credit_date' => '2025-03-15', 'amount_eur' => 120.0, 'type' => 'neukunde', 'label' => ''],
                ['credit_date' => '2025-06-10', 'amount_eur' => 25.0, 'type' => 'sonstige', 'label' => 'Kulanz'],
            ]]);
    }

    private function bills(): BillService
    {
        return new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
    }

    private function periods(): BillingPeriodService
    {
        return new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $this->bills());
    }

    public function testTheOtherCreditIsStoredWithItsNoteAndCountsInItsMonth(): void
    {
        $c = $this->contracts->list('strom', $this->meterId)[0];
        self::assertSame(['sonstige', 'Kulanz'], [$c['bonuses'][1]['type'], $c['bonuses'][1]['label']]);
        $rows = array_column($this->consumption->forMeter('strom', $this->meters->get('strom', $this->meterId)), null, 'ym');
        self::assertSame(25.0, (float)$rows['2025-06']['bonus_eur'], 'im Monat der Gutschrift');
        self::assertSame([['credit_date' => '2025-06-10', 'amount_eur' => 25.0, 'type' => 'sonstige', 'label' => 'Kulanz']], $rows['2025-06']['bonus_items']);
        self::assertSame(120.0, (float)$rows['2025-03']['bonus_items'][0]['amount_eur']);
        self::assertArrayNotHasKey('bonus_items', $rows['2025-04'], 'nur Monate mit Bonus tragen das Feld');
        // Monatskosten ziehen den Bonus ab wie bisher (die Rechnung tut es auch)
        self::assertEqualsWithDelta(200 * 0.30 + 10 - 25, (float)$rows['2025-06']['cost'], 0.01);
        // die ganze Art: Summe und Einzelne je Monat (Übersicht)
        $total = array_column($this->consumption->forUtility('strom')['monthly_total'], null, 'ym');
        self::assertSame(120.0, (float)$total['2025-03']['bonus_eur']);
        self::assertSame('neukunde', $total['2025-03']['bonus_items'][0]['type']);
    }

    public function testTheBillingPeriodCarriesItsBonusesAndTheBalanceIncludesThem(): void
    {
        $this->settings->set(['billing_cycle_anchor_strom' => '01-01']);
        $p = $this->periods()->forTarget('strom', $this->meters->get('strom', $this->meterId), false, '2026-02-15')['periods'][0];
        self::assertSame(['2025-01-01', '2025-12-31'], [$p['from'], $p['until']]);
        self::assertEqualsWithDelta(145.0, $p['bonus'], 0.001);
        self::assertSame(['2025-03-15', '2025-06-10'], array_column($p['bonus_items'], 'credit_date'));
        $gross = 12 * 200 * 0.30 + 12 * 10;
        self::assertEqualsWithDelta($gross - 145, $p['cost'], 0.05, 'cost bleibt nach Abzug des Bonus (API, Rechnung prüfen)');
        self::assertEqualsWithDelta($gross - 145 - 12 * 80, $p['balance'], 0.05, 'der Saldo rechnet den Bonus ein');
    }

    public function testTheAnnualReportCountsCostsWithoutTheBonusAndNamesEachBonus(): void
    {
        $m = $this->reportServices()['model']->build(2025, ['today' => '2026-02-15', 'billing' => true, 'detail' => true]);
        $gross = 12 * 200 * 0.30 + 12 * 10;
        $tile = array_column($m['headline']['tiles'], null, 'key')['cost'];
        self::assertEqualsWithDelta($gross, (float)$tile['value'], 0.05, 'Kachel ohne Bonus');
        self::assertEqualsWithDelta($gross, (float)$m['money']['by_utility'][0]['cost'], 0.05, 'Geld je Art ohne Bonus');
        self::assertEqualsWithDelta($gross, array_sum(array_map(fn($x) => (float)$x['value'], $m['money']['monthly'])), 0.1, 'je Monat ohne Bonus');
        $note = $m['money']['bonus_notes'][0] ?? self::fail('kein Satz zu den Boni');
        foreach (['15.03.2025', '10.06.2025', 'Neukunde', 'Sonstige Gutschrift', 'Kulanz', 'Saldo'] as $part) {
            self::assertStringContainsString($part, $note, "Satz nennt $part");
        }
        $page = array_column($m['pages'], null, 'utility')['strom'];
        self::assertEqualsWithDelta($gross, (float)$page['cost']['value'], 0.05, 'Seite ohne Bonus');
        self::assertNotEmpty(array_filter($page['notes'], fn($n) => str_contains($n, 'Kulanz')), 'Seite nennt die Gutschrift');
        // Anhang „Abrechnungsjahre“: Kosten ohne Bonus, Bonus als eigene Spalte, Saldo wie die Periode
        $billing = $m['billing'][0];
        self::assertSame($this->i18n->t('report.billing.bonus'), $billing['head'][4]);
        self::assertSame($this->i18n->number($gross, 2), $billing['rows'][0][3]);
        self::assertSame($this->i18n->number(145.0, 2), $billing['rows'][0][4]);
        // Anhang „Zahlen im Detail“: Monatskosten ohne Bonus, die Summe wie die Kachel
        $detail = array_column($m['appendix'], null, 'utility')['strom'];
        self::assertSame($this->i18n->number($gross, 2), $detail['total'][2]);
    }

    public function testTheTariffComparisonNamesTheBonusesInsideTheAmount(): void
    {
        $cmp = new TariffComparisonService($this->consumption, $this->contracts, $this->meters, $this->i18n);
        $row = $cmp->compare('strom', $this->meterId, 2025)['rows'][0];
        self::assertEqualsWithDelta(145.0, $row['bonus_eur'], 0.001);
        self::assertSame(['neukunde', 'sonstige'], array_column($row['bonus_items'], 'type'));
        self::assertSame('Kulanz', $row['bonus_items'][1]['label']);
        // der Betrag selbst bleibt wie bisher nach Abzug (die Rechnung)
        self::assertEqualsWithDelta(12 * 200 * 0.30 + 12 * 10 - 145, $row['total_eur'], 0.05);
        // ein Jahr ohne Bonus trägt eine leere Liste
        $this->contracts->update('strom', $this->contracts->list('strom', $this->meterId)[0]['id'], ['bonuses' => []]);
        $none = $cmp->compare('strom', $this->meterId, 2025)['rows'][0];
        self::assertSame([0.0, []], [$none['bonus_eur'], $none['bonus_items']]);
    }

    public function testTheCsvAddsBonusAndCostWithoutBonusAtTheEnd(): void
    {
        $csv = new CsvExportService($this->consumption, $this->readings, $this->meters,
            new TemperatureService($this->store, $this->settings, new WeatherService()),
            new DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $csv->attachBillingPeriods($this->periods());
        $lines = array_values(array_filter(explode("\r\n", ltrim($csv->billingPeriods('strom', '1'), "\u{FEFF}"))));
        $head = explode(';', $lines[0]);
        self::assertSame(['Bonus (EUR)', 'Kosten ohne Bonus (EUR)'], array_slice($head, -2), 'additiv am Ende');
        self::assertSame('Kosten (EUR)', $head[5], 'die bisherigen Spalten bleiben, wo sie sind');
        $row = explode(';', $lines[1]);
        self::assertSame('145', $row[10]);
        self::assertSame(number_format(12 * 200 * 0.30 + 12 * 10, 0, ',', ''), $row[11]);
        // Monatsübersicht „local“: der Bonus als letzte Spalte; Format 1 ohne (eingefroren)
        $local = explode("\r\n", ltrim($csv->monthly('strom', 'local', 'de'), "\u{FEFF}"));
        self::assertStringEndsWith('Bonus (EUR)', $local[0]);
        self::assertStringEndsWith(';120', $local[3], 'März');
        self::assertStringNotContainsString('Bonus', explode("\r\n", $csv->monthly('strom', '1'))[0]);
    }
}
