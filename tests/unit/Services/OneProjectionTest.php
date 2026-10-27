<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\AgendaService;
use Energietracker\Services\BenchmarkService;
use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\Co2CostService;
use Energietracker\Services\Co2SplitService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\ReminderService;
use Energietracker\Services\ReportAdviceService;
use Energietracker\Services\ScenarioService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Services\TenancyService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Paket auswertung, Befunde V1-Rest, L1, L12, L14 des Skeptiker-Laufs) —
 * eine Saldo-Projektion für Saldo-Karte, Kosten & Verträge, Übersicht,
 * Jahresbericht, „Wohin geht mein Geld?“ und Werkstatt.
 *
 * Befund (unordentlicher Testhaushalt, Gas ohne Abrechnungsstichtag und ohne
 * Rechnung, Vertrag seit 11/2022): Die Karte zählte ab Vertragsbeginn (≈ 1.163 €
 * Guthaben zum 01.01.2027), die Werkstatt das Kalenderjahr (356,55 € zum
 * 31.12.2026); der Wert der Werkstatt lag außerhalb der Spanne der Karte.
 *
 * Jetzt rechnet jede Ansicht über die laufende Abrechnungsperiode der
 * Abrechnungsjahre (BillingPeriodService) und nennt als Abrechnungstag ihren
 * letzten Tag. Erwartungen von Hand (Kommentare).
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ScenarioService::class)]
#[CoversClass(BillingPeriodService::class)]
#[CoversClass(ReportAdviceService::class)]
#[CoversClass(AgendaService::class)]
final class OneProjectionTest extends ServiceTestCase
{
    use ReportFixture;

    private const TODAY = '2026-10-26';

    private function loadMessy(): void
    {
        $h = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/messy-household/household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings']);
        $this->store->write('temperatures.json', $h['temperatures']);
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        foreach ($h['utilities'] as $u => $pots) foreach ($pots as $pot => $rows) $this->store->write("$u/$pot.json", $rows);
    }

    private function scenario(): ScenarioService
    {
        $f = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        return new ScenarioService($f, $this->consumption, $this->contracts, $this->meters, $this->settings,
            new TariffSwitchService($f, $this->contracts, $this->meters, $this->i18n),
            new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills));
    }

    private function agenda(): AgendaService
    {
        $benchmark = new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings, $benchmark, $deliveries, $this->i18n);
        $tenancies = new TenancyService($this->store, $this->meters, $this->i18n);
        $co2 = new Co2CostService($this->store, $this->settings, $this->meters, $this->consumption, $this->contracts, $this->i18n, $tenancies);
        return new AgendaService($this->settings, $this->meters, $this->readings, $this->contracts, $this->consumption,
            new ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n, $tenancies, new Co2SplitService($co2, $tenancies, $this->settings, $this->i18n));
    }

    // ── V1: eine Projektion ─────────────────────────────────────────────

    public function testTheMessyHouseholdCardAndWorkshopShareOnePeriodAndOneDate(): void
    {
        $this->loadMessy();
        $meter = $this->meters->list('gas')[0];
        $card = $this->consumption->contractStatus('gas', $meter, self::TODAY)['contracts'][0];
        // laufende Periode des Stichtags 01.01. (keine Rechnung erfasst), nicht seit 11/2022
        self::assertSame('2022-11-01', $card['start']);
        self::assertSame('2026-01-01', $card['balance_from']);
        self::assertSame(['from' => '2026-01-01', 'until' => '2026-12-31', 'open_from' => '2026-01-01', 'source' => 'anchor', 'bill_id' => null], $card['billing_period']);
        self::assertSame('2026-12-31', $card['settle_on'], 'der letzte Tag, nicht der 01.01.2027 (L1)');
        // Abschläge nach Plan: 150 € für Januar bis Oktober 2026 bis heute, November und Dezember danach
        self::assertSame(1500.0, $card['advance_paid']);
        self::assertSame(300.0, $card['advance_remaining']);
        self::assertSame(2, $card['months_left']);
        // die Werkstatt rechnet aus derselben Projektion: derselbe Saldo, derselbe Tag
        $r = $this->scenario()->compute(['utility' => 'gas', 'meter_id' => $meter['id']], self::TODAY);
        self::assertSame('contract_status', $r['billing']['source']);
        self::assertSame($card['projected_end_balance'], $r['billing']['base_end_balance']);
        self::assertSame($card['current_balance'], $r['billing']['balance_so_far']);
        self::assertSame('2026-12-31', $r['billing']['settle_on']);
        // die Spanne der unsicheren Schätzung (Gütesperre) umschließt beide Werte
        [$lo, $hi] = $card['projection_uncertain']['projected_range'];
        self::assertTrue($lo <= $r['billing']['base_end_balance'] && $r['billing']['base_end_balance'] <= $hi);
        // v3.9.0 (Skeptiker H3) — und die Werkstatt zeigt genau diese Spanne (bis dahin nur den Punktwert)
        self::assertSame([round($lo, 2), round($hi, 2)], $r['billing']['base_end_range']);
        self::assertSame($r['billing']['base_end_range'], $r['billing']['scen_end_range'], 'Ausgangsfall: das Szenario verschiebt nichts');
        $cold = $this->scenario()->compute(['utility' => 'gas', 'meter_id' => $meter['id'], 'winter_pct' => 10], self::TODAY);
        $shift = $cold['billing']['scen_end_balance'] - $cold['billing']['base_end_balance'];
        self::assertGreaterThan(0.0, $shift, 'ein kälterer Winter kostet mehr');
        self::assertEqualsWithDelta([round($lo, 2) + $shift, round($hi, 2) + $shift], $cold['billing']['scen_end_range'], 0.02, 'die Spanne wandert mit');
        // Abrechnungsjahr: dieselbe Periode, gemessen bis zur letzten Ablesung (05.01.2026)
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $bp = (new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills))->forTarget('gas', $meter, false, self::TODAY);
        $running = array_values(array_filter($bp['periods'], fn($p) => $p['running']))[0];
        self::assertSame(['2026-01-01', '2026-12-31'], [$running['from'], $running['until']]);
        $measured = (float)$card['energy_cost_to_date'] - (float)$card['estimated_cost_to_date'];
        self::assertEqualsWithDelta($running['energy_cost'], $measured, 0.05, 'gemessene Arbeitskosten der Karte = die der Periode');
        // Jahresbericht („Wohin geht mein Geld?“): derselbe Saldo und derselbe Abrechnungstag
        $m = $this->reportServices()['model']->build(2025, ['today' => self::TODAY]);
        $gas = array_values(array_filter($m['money']['balances'] ?? [], fn($b) => $b['utility'] === 'gas'))[0] ?? null;
        self::assertNotNull($gas);
        self::assertSame('2026-12-31', $gas['settle_on']);
        self::assertSame('2026-12-31', $gas['explainer']['settle_on']);
        self::assertEqualsWithDelta($card['projected_end_balance'], $gas['explainer']['projected_end_balance'], 0.001);
        self::assertStringContainsString($this->i18n->date('2026-12-31'), $gas['text']);
    }

    public function testTheStatusTakesTheDayFromOutside(): void
    {
        $this->loadMessy();
        $meter = $this->meters->list('gas')[0];
        // Stand Juni 2025: die laufende Periode ist 2025, der Saldo beginnt am 01.01.2025
        $c = $this->consumption->contractStatus('gas', $meter, '2025-06-15')['contracts'][0];
        self::assertSame('2025-01-01', $c['balance_from']);
        self::assertSame('2025-12-31', $c['settle_on']);
        self::assertSame('2025-06-15', $c['balance_as_of']);
        // Abschläge Januar bis Juni 2025: 6 × 150 €
        self::assertSame(900.0, $c['advance_paid']);
    }

    // ── L1: der letzte Tag ──────────────────────────────────────────────

    public function testASupersededContractSettlesOnItsLastDayNotTheDayBefore(): void
    {
        $meterId = $this->setMeterDevices('strom', [['id' => 'd1', 'serial' => null, 'installed_on' => '2026-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($i = 0; $i <= 9; $i++) $rows[] = ['date' => date('Y-m-d', strtotime("2026-01-01 +$i months")), 'counter' => 100.0 * $i, 'device_id' => 'd1'];
        $this->setReadings('strom', $meterId, $rows);
        $old = $this->contracts->create('strom', ['meter_id' => $meterId, 'provider' => 'Alt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 30.0]]]);
        $this->contracts->create('strom', ['meter_id' => $meterId, 'provider' => 'Neu', 'start' => '2026-07-01',
            'working_prices' => [['from' => '2026-07-01', 'ct_per_kwh' => 28.0]], 'advance_payments' => [['from' => '2026-07-01', 'amount_eur' => 28.0]]]);
        $byId = array_column($this->consumption->contractStatus('strom', $this->meters->get('strom', $meterId), self::TODAY)['contracts'], null, 'contract_id');
        $a = $byId[$old['id']];
        // abgelöst am 30.06.: Das ist der letzte Tag (bis v3.8 nannte periodLastDay den 29.06.)
        self::assertSame('2026-06-30', $a['superseded_on']);
        self::assertSame('2026-06-30', $a['settle_on']);
        self::assertSame('2026-06-30', ConsumptionService::periodLastDay($a));
    }

    public function testAnOpenContractSettlesOnTheLastDayOfThePeriod(): void
    {
        $this->loadMessy();
        $c = $this->consumption->contractStatus('strom', $this->meters->list('strom')[0], self::TODAY)['contracts'][0];
        // effective_end bleibt der erste Tag der nächsten Periode (Bestandssensoren), settle_on ist der letzte Tag
        self::assertSame('2027-01-01', $c['effective_end']);
        self::assertSame('2026-12-31', $c['settle_on']);
        self::assertSame('2026-12-31', ConsumptionService::periodLastDay($c));
    }

    public function testTheReportSaysTheLastDayAContractEndsNotTheSwitchDay(): void
    {
        $advice = new ReportAdviceService($this->i18n);
        $out = $advice->evaluate(['year' => 2026, 'today' => '2026-10-26', 'contracts' => [[
            'key' => 'k', 'utility' => 'strom', 'label' => 'Strom', 'provider' => 'Alt', 'switchable' => true,
            'is_current' => true, 'cancel_by' => '2026-11-15', 'switch_date' => '2026-12-01', 'end' => null,
        ]], 'agenda' => [], 'totals' => []]);
        $cancel = array_values(array_filter($out['tasks'], fn($t) => $t['rule'] === 'cancel'))[0];
        // switch_date 01.12. ist der erste Tag beim neuen Versorger: Der Vertrag endet zum 30.11.
        self::assertSame($this->i18n->t('report.advice.cancel.reason', ['provider' => 'Alt', 'date' => $this->i18n->date('2026-11-15'),
            'end' => $this->i18n->date('2026-11-30')]), $cancel['reason']);
    }

    // ── L12: kurz vor der Abrechnung kein Nachholbetrag ─────────────────

    /** 100 kWh je Monat zu 30 ct + 10 € Grundpreis = 40 € Kosten, Abschlag 30 €: 10 € Fehlbetrag je Monat. */
    private function flatStrom(string $anchor): array
    {
        $this->settings->set(['billing_cycle_anchor_strom' => $anchor]);
        $meterId = $this->setMeterDevices('strom', [['id' => 'd1', 'serial' => null, 'installed_on' => '2025-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        for ($i = 0; $i <= 21; $i++) $rows[] = ['date' => date('Y-m-d', strtotime("2025-01-01 +$i months")), 'counter' => 100.0 * $i, 'device_id' => 'd1'];
        $this->setReadings('strom', $meterId, $rows);   // bis 01.10.2026
        $this->contracts->create('strom', ['meter_id' => $meterId, 'provider' => 'Werk', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 10.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 30.0]]]);
        return $this->consumption->contractStatus('strom', $this->meters->get('strom', $meterId), self::TODAY)['contracts'][0];
    }

    public function testTwoAdvancesBeforeTheBillGetNoCatchUpButTheOngoingAmount(): void
    {
        // Stichtag 01.01.: Abrechnung am 31.12.2026, danach noch die Abschläge November und Dezember
        $c = $this->flatStrom('01-01');
        self::assertSame(2, $c['months_left']);
        self::assertNull($c['suggested_advance'], 'bis v3.8: 30 € + Fehlbetrag ÷ 2, ein Vielfaches');
        self::assertSame('short', $c['suggestion_withheld']);
        // Fehlbetrag 2026: 12 × 10 € = 120 € (gleichmäßiger Verbrauch, Schätzung = Messung)
        self::assertEqualsWithDelta(120.0, $c['projected_end_balance'], 0.5);
        // laufend angemessen: 100 kWh × 30 ct + 10 € = 40 € im Monat
        self::assertSame(40.0, $c['advance_ongoing']);
    }

    public function testFiveAdvancesBeforeTheBillKeepTheCatchUpAmount(): void
    {
        // Stichtag 04.01.: Periode 01.04.2026–31.03.2027, Abschläge November bis März danach
        $c = $this->flatStrom('04-01');
        self::assertSame(5, $c['months_left']);
        self::assertNull($c['suggestion_withheld']);
        // Fehlbetrag der Periode: 12 × 10 € = 120 € → 30 € + 120 € ÷ 5 = 54 €
        self::assertEqualsWithDelta(120.0, $c['projected_end_balance'], 0.5);
        self::assertSame(54.0, $c['suggested_advance']);
        self::assertSame(40.0, $c['advance_ongoing']);
    }

    // ── L14: Folgevertrag bei einem anderen Versorger ───────────────────

    public function testASwitchAlreadyEnteredAsksForTheConfirmationNotForACancellation(): void
    {
        $meterId = $this->setMeterDevices('strom', [['id' => 'd1', 'serial' => null, 'installed_on' => '2026-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->setReadings('strom', $meterId, [['date' => '2026-01-01', 'counter' => 0.0, 'device_id' => 'd1'], ['date' => '2026-10-01', 'counter' => 900.0, 'device_id' => 'd1']]);
        // endet am 31.12., Kündigung einen Monat vorher (bis 30.11.); der neue Versorger beginnt nach einer Lücke am 15.01.
        $old = $this->contracts->create('strom', ['meter_id' => $meterId, 'provider' => 'Alt', 'start' => '2026-01-01', 'end' => '2026-12-31',
            'notice_period_months' => 1, 'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 30.0]]]);
        $this->contracts->create('strom', ['meter_id' => $meterId, 'provider' => 'Neu', 'start' => '2027-01-15',
            'working_prices' => [['from' => '2027-01-15', 'ct_per_kwh' => 28.0]]]);
        $c = array_column($this->consumption->contractStatus('strom', $this->meters->get('strom', $meterId), self::TODAY)['contracts'], null, 'contract_id')[$old['id']];
        self::assertTrue($c['successor_switch']);
        self::assertSame('2026-11-30', $c['cancel_by']);
        $events = array_values(array_filter($this->agenda()->events(90, self::TODAY), fn($e) => $e['kind'] === 'cancel_by'));
        self::assertCount(1, $events);
        self::assertSame('calendar.event.cancelConfirm', $events[0]['title_key'], 'Kündigung bestätigt? statt Kündigen bis');
        self::assertNotSame('urgent', $events[0]['severity']);
        self::assertTrue($events[0]['ref']['confirm']);
        // Jahresbericht: Hinweis statt Aufgabe „Kündigung oder Wechsel“, die Ampel „Fristen“ eilt nicht
        $advice = (new ReportAdviceService($this->i18n))->evaluate(['year' => 2026, 'today' => self::TODAY, 'totals' => [], 'agenda' => $events, 'contracts' => [[
            'key' => 'k', 'utility' => 'strom', 'label' => 'Strom', 'provider' => 'Alt', 'switchable' => true, 'is_current' => true,
            'cancel_by' => $c['cancel_by'], 'switch_date' => $c['switch_date'], 'end' => $c['end'], 'successor' => $c['successor'], 'successor_switch' => true,
        ]]]);
        self::assertSame([], array_values(array_filter($advice['tasks'], fn($t) => $t['rule'] === 'cancel')));
        self::assertNotSame([], array_values(array_filter($advice['infos'], fn($t) => $t['rule'] === 'cancelConfirm')));
        self::assertSame('ok', array_column($advice['lights'], null, 'key')['deadlines']['level']);
    }
}
