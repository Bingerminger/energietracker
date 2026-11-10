<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.12.0 — Ein Stichtag rechnet wie die Daten an diesem Tag.
 *
 * Bis v3.11 kannte ConsumptionService::forMeter() kein Datum und nahm immer
 * die Ablesungen bis zum echten heutigen Tag. Wer mit einem Stichtag rechnete
 * (Vertragsstatus, Abrechnungsjahr, Rechte-Prüfung, Werkstatt), bekam
 * Monatszeilen eines anderen Tages: Lag der Stichtag zurück, zählten spätere
 * Ablesungen schon mit, lag er voraus, fehlten die Ablesungen bis zu ihm. Das
 * Ergebnis hing damit am Kalender, nicht an Daten und Stichtag.
 *
 * Erfundener Haushalt: Strom, ein Zählwerk ab 01.01.2025 mit Stand 0, genau
 * 10 kWh am Tag, Ablesung am Monatsersten vom 01.01. bis 01.07.2025. Vertrag ab
 * 01.01.2025: 10 ct/kWh, Grundpreis 12 €/Monat, Abschlag 100 €/Monat.
 * Stichtag 20.04.2025: Die letzte Ablesung davor ist die vom 01.04.2025.
 *
 * Handrechnung gemessen [01.01.2025, 01.04.2025): 31 + 28 + 31 = 90 Tage
 *   Menge        90 × 10 kWh                 = 900 kWh
 *   Arbeitspreis 900 kWh × 0,10 €            =  90,00 €
 *   Grundpreis   3 × 12 €                    =  36,00 €
 *   Abschläge    3 × 100 €                   = 300,00 €
 *   Saldo        90,00 + 36,00 − 300,00      = −174,00 € (Guthaben)
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillingPeriodService::class)]
final class StichtagReadingsTest extends ServiceTestCase
{
    use \Energietracker\Tests\Support\ReportFixture;

    private const STICHTAG = '2025-04-20';
    private string $meterId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->meterId = $this->setMeterDevices('strom', [['id' => 'd_a', 'serial' => null, 'installed_on' => '2025-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $this->contracts->create('strom', ['meter_id' => $this->meterId, 'provider' => 'Strom Musterstadt', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 10.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 100]]]);
    }

    /** Ablesungen am Monatsersten bis einschließlich $until, 10 kWh am Tag. */
    private function readUntil(string $until): void
    {
        $rows = [];
        for ($t = strtotime('2025-01-01'); date('Y-m-d', $t) <= $until; $t = strtotime('+1 month', $t)) {
            $d = date('Y-m-d', $t);
            $rows[] = ['date' => $d, 'counter' => 10.0 * (int)round((strtotime("$d 12:00") - strtotime('2025-01-01 12:00')) / 86400), 'device_id' => 'd_a'];
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    private function meter(): array
    {
        return $this->meters->get('strom', $this->meterId);
    }

    public function testMonthlyRowsAtAStichtagSeeOnlyTheReadingsUpToIt(): void
    {
        $this->readUntil('2025-07-01');
        $rows = $this->consumption->forMeter('strom', $this->meter(), null, self::STICHTAG);
        self::assertSame(['2025-01', '2025-02', '2025-03'], array_column($rows, 'ym'),
            'am 20.04. ist die Ablesung vom 01.05. noch nicht da (bis v3.11: Monatszeilen bis Juni)');
        self::assertEqualsWithDelta(900.0, array_sum(array_column($rows, 'kwh')), 1e-6, '90 Tage × 10 kWh');
        // ohne Stichtag (heute, nach allen Ablesungen): Januar bis Juni, 181 Tage × 10 kWh
        $all = $this->consumption->forMeter('strom', $this->meter());
        self::assertSame('2025-06', end($all)['ym']);
        self::assertEqualsWithDelta(1810.0, array_sum(array_column($all, 'kwh')), 1e-6);
    }

    public function testTheMeasuredBalanceAtAStichtagEndsAtTheLastReadingBeforeIt(): void
    {
        $this->readUntil('2025-07-01');
        $m = $this->consumption->contractStatus('strom', $this->meter(), self::STICHTAG)['contracts'][0]['balance_measured'];
        self::assertNotNull($m);
        self::assertSame(['2025-01-01', '2025-04-01'], [$m['from'], $m['until']], 'bis v3.11: bis zur Ablesung vom 01.07.');
        self::assertEqualsWithDelta(90.00, $m['energy_cost'], 0.005);
        self::assertEqualsWithDelta(36.00, $m['base'], 0.005);
        self::assertEqualsWithDelta(300.00, $m['advance'], 0.005);
        self::assertEqualsWithDelta(-174.00, $m['balance'], 0.005);
    }

    public function testTheContractCardAtAStichtagIsTheCardOfThatDay(): void
    {
        // dieselbe Karte, ob die späteren Ablesungen schon erfasst sind oder nicht
        $this->readUntil(self::STICHTAG);
        $then = $this->consumption->contractStatus('strom', $this->meter(), self::STICHTAG)['contracts'][0];
        $this->readUntil('2025-07-01');
        $later = $this->consumption->contractStatus('strom', $this->meter(), self::STICHTAG)['contracts'][0];
        foreach (['current_balance', 'projected_end_balance', 'balance_measured', 'balance_as_of', 'projected_range'] as $k) {
            self::assertSame($then[$k] ?? null, $later[$k] ?? null, $k);
        }
    }

    public function testTheBillingYearAtAStichtagIsTheBillingYearOfThatDay(): void
    {
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $periods = new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills);
        $this->readUntil(self::STICHTAG);
        $then = $periods->forTarget('strom', $this->meter(), true, self::STICHTAG);
        $this->readUntil('2025-07-01');
        $later = $periods->forTarget('strom', $this->meter(), true, self::STICHTAG);
        self::assertSame($then['periods'], $later['periods']);
        self::assertSame('2025-04-01', $later['measured_until']);
        $p = end($later['periods']);
        self::assertEqualsWithDelta(900.0, (float)$p['kwh'], 1e-6, 'die laufende Periode bis zur letzten Ablesung davor');
    }

    /**
     * „Was eine andere Heizgrenze ändert“ rechnet am Stichtag des Aufrufers: derselbe erwartete Saldo
     * wie die Vertragskarte an diesem Tag (Strom ohne Heizmodell: vorher = nachher).
     */
    public function testTheHeatLimitEffectAtAStichtagSpeaksOfTheCardOfThatDay(): void
    {
        $this->readUntil('2025-07-01');
        $card = $this->consumption->contractStatus('strom', $this->meter(), self::STICHTAG)['contracts'][0];
        $e = $this->consumption->heatLimitEffect('strom', $this->meter(), 15.0, self::STICHTAG);
        self::assertNotNull($e);
        self::assertSame('2025-12-31', $e['until'], 'die Periode des Stichtags (bis v3.11: die von heute)');
        self::assertSame(round((float)$card['projected_end_balance'], 2), $e['before']['balance']);
        self::assertEquals($card['suggested_advance'], $e['before']['advance']);
    }

    /** Die Übersicht (`/api/summary`) am Stichtag: Verbrauch im Jahr bis zur letzten Ablesung davor, 900 kWh. */
    public function testTheSummaryAtAStichtagCountsTheYearUpToIt(): void
    {
        $this->readUntil('2025-07-01');
        $reminders = new \Energietracker\Services\ReminderService($this->store, $this->settings, $this->i18n);
        $deliveries = new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n), $deliveries, $this->i18n);
        $agenda = new \Energietracker\Services\AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, $reminders, $recs, $this->i18n);
        $summary = new \Energietracker\Services\SummaryService($this->settings, $this->meters, $this->readings, $this->consumption,
            new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n),
            $deliveries, new \Energietracker\Services\PvSummaryService($this->consumption), new \Energietracker\Services\StromSaldoService($this->consumption),
            $agenda, new \Energietracker\Services\InstanceService($this->store));
        $row = array_column($summary->build('strom', null, self::STICHTAG)['meters'], null, 'meter_id')[$this->meterId];
        self::assertEqualsWithDelta(900.0, (float)$row['year_to_date']['value'], 1e-6, 'bis v3.11: 1.810 kWh bis Juni');
    }

    /**
     * Empfehlungen am Stichtag sehen keinen Monat danach: Im Juni 2025 verbraucht der Haushalt das
     * Fünffache, am 20.04.2025 weiß davon noch niemand.
     */
    /** Strom 10 kWh am Tag ab 01.01. des Vorjahres, im Juni von `$year` das Fünffache, abgelesen bis 01.07. */
    private function juneSpike(int $year): void
    {
        $from = ($year - 1) . '-01-01';
        $this->setMeterDevices('strom', [['id' => 'd_a', 'serial' => null, 'installed_on' => $from,
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        $c = 0.0;
        for ($t = strtotime($from); date('Y-m-d', $t) <= "$year-07-01"; $t = strtotime('+1 month', $t)) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => $c, 'device_id' => 'd_a'];
            $c += (date('Y-m', $t) === "$year-06" ? 50.0 : 10.0) * (int)date('t', $t);
        }
        $this->setReadings('strom', $this->meterId, $rows);
    }

    /**
     * Der Jahresbericht nennt die auffälligen Monate seines Jahres am eigenen Stichtag: Bericht 2024 am
     * 15.07.2024 mit dem Juni 2024. Bis v3.11 las er die Empfehlungen am echten Tag, und deren Fenster
     * (24 Monate) hatte den Juni 2024 längst verlassen.
     */
    public function testTheAnnualReportNamesTheOutliersOfItsYearAtItsStichtag(): void
    {
        $this->juneSpike(2024);
        $m = $this->reportServices()['model']->build(2024, ['today' => '2024-07-15']);
        $anomalies = array_values(array_filter($m['tasks'], fn($t) => $t['rule'] === 'anomaly' && $t['utility'] === 'strom'));
        self::assertNotEmpty($anomalies, 'der Juni 2024 fällt auf');
        self::assertStringContainsString('2024', $anomalies[0]['title']);
    }

    public function testRecommendationsAtAStichtagKnowNothingAfterIt(): void
    {
        $this->juneSpike(2025);
        $recs = new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n, null, self::STICHTAG),
            new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n), $this->i18n);
        $all = json_encode($recs->atDate(self::STICHTAG)->all(), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('2025-06', (string)$all, 'der Juni liegt nach dem Stichtag');
        // Kontrolle: am 15.07.2025 ist er da und fällt auf
        self::assertStringContainsString('2025-06', (string)json_encode($recs->atDate('2025-07-15')->all(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Die Agenda (Kalender, „Zu tun“) am Stichtag nimmt die Empfehlungen dieses Tages: Ein Heizöltank mit
     * 400 L am 01.01.2025, voll aufgefüllt mit 2.000 L am 01.03.2025, hat am 01.04.2025 noch gut 1.700 L;
     * leer wird er erst über ein Jahr später. Am Stichtag also kein „Nachbestellen“ (bis v3.11: der
     * Bestand von heute).
     */
    public function testTheAgendaAtAStichtagUsesTheRecommendationsOfThatDay(): void
    {
        $deliveries = new \Energietracker\Services\DeliveryService($this->store, $this->meters, $this->i18n);
        $tank = $this->meters->create('heizoel', ['name' => 'Tank', 'capacity' => 3000.0, 'initial_stock' => 400.0, 'installed_on' => '2025-01-01']);
        $deliveries->create('heizoel', ['meter_id' => $tank['id'], 'date' => '2025-03-01', 'quantity' => 2000.0, 'unit_price_cents' => 100.0]);
        $this->settings->set(['active_utilities' => ['strom', 'heizoel']]);
        $recs = new \Energietracker\Services\RecommendationService($this->store, $this->meters, $this->consumption, $this->settings,
            new \Energietracker\Services\BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n, null, '2025-04-01'),
            $deliveries, $this->i18n);
        $agenda = new \Energietracker\Services\AgendaService($this->settings, $this->meters, $this->readings, $this->contracts,
            $this->consumption, new \Energietracker\Services\ReminderService($this->store, $this->settings, $this->i18n), $recs, $this->i18n);
        $kinds = fn(string $day) => array_column($agenda->events(90, $day), 'kind');
        self::assertNotContains('tank_reorder', $kinds('2025-04-01'), 'am 01.04.2025 ist der Tank voll genug');
        // Kontrolle: Ende 2026 ist er leer gerechnet, dann steht „Nachbestellen“ an
        self::assertContains('tank_reorder', $kinds('2026-12-31'));
    }

    /** Der Jahresbericht mit `today` kennt nur die Ablesungen bis zu diesem Tag: am 20.04.2025 Januar bis März. */
    public function testTheAnnualReportAtAStichtagSeesOnlyTheReadingsUpToIt(): void
    {
        $this->readUntil('2025-07-01');
        $m = $this->reportServices()['model']->build(2025, ['today' => self::STICHTAG, 'detail' => true]);
        $table = array_column($m['appendix'] ?? [], null, 'utility')['strom'] ?? null;
        self::assertNotNull($table, 'Anhang Strom');
        self::assertCount(3, $table['rows'], 'drei Monatszeilen (bis v3.11: sechs, bis Juni)');
    }

    /**
     * Die Kalibrierung der Schätzung zählt die zwölf Monate vor dem Stichtag (bis v3.11 vor dem
     * echten Tag: Lag der Stichtag mehr als ein Jahr zurück, fand sie keinen Monat, Faktor 1).
     * 2024 10 kWh am Tag, ab 2025 13 kWh, Stichtag 20.07.2025: die jüngsten gemessenen Monate sind
     * Januar bis Juni 2025. Erwartet je Kalendermonat ist das Mittel beider Jahre, (10 + 13) / 2 =
     * 11,5 kWh am Tag; gemessen 13 → Faktor 13 / 11,5 = 1,1304.
     */
    public function testTheCalibrationCountsTheTwelveMonthsBeforeTheStichtag(): void
    {
        $this->setMeterDevices('strom', [['id' => 'd_a', 'serial' => null, 'installed_on' => '2024-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $rows = [];
        $c = 0.0;
        for ($t = strtotime('2024-01-01'); date('Y-m-d', $t) <= '2025-07-01'; $t = strtotime('+1 month', $t)) {
            $rows[] = ['date' => date('Y-m-d', $t), 'counter' => $c, 'device_id' => 'd_a'];
            $c += (date('Y', $t) === '2025' ? 13.0 : 10.0) * (int)date('t', $t);
        }
        $this->setReadings('strom', $this->meterId, $rows);
        $card = $this->consumption->contractStatus('strom', $this->meter(), '2025-07-20')['contracts'][0];
        self::assertEqualsWithDelta(13.0 / 11.5, (float)$card['projection_factor_base'], 0.0005);
    }

    /**
     * doc-claim: docs/referenz/api.md, Budget — „Zählerstände nach `as_of` zählen nicht mit“.
     * Kaltwasser 0,1 m³ am Tag bis März, ab April 0,3 m³, abgelesen am Monatsersten; Budget am
     * 20.04.2025: dasselbe, ob die Stände vom Mai bis Juli schon erfasst sind oder nicht.
     */
    public function testTheTenancyBudgetAtAStichtagIsTheBudgetOfThatDay(): void
    {
        $water = $this->setMeterDevices('wasser', [['id' => 'd_w', 'serial' => null, 'installed_on' => '2025-01-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]);
        $read = function (string $until) use ($water): void {
            $rows = [];
            $c = 0.0;
            for ($t = strtotime('2025-01-01'); date('Y-m-d', $t) <= $until; $t = strtotime('+1 month', $t)) {
                $rows[] = ['date' => date('Y-m-d', $t), 'counter' => round($c, 3), 'device_id' => 'd_w'];
                $c += ($t < strtotime('2025-04-01') ? 0.1 : 0.3) * (int)date('t', $t);
            }
            $this->setReadings('wasser', $water, $rows);
        };
        $tenancies = new \Energietracker\Services\TenancyService($this->store, $this->meters, $this->i18n);
        $budget = new \Energietracker\Services\TenancyBudgetService($tenancies, $this->meters, $this->consumption, $this->i18n);
        $t = $tenancies->create(['start' => '2025-01-01', 'billing_anchor' => '01-01',
            'prepayments' => [['from' => '2025-01-01', 'heating_eur_month' => 0, 'operating_eur_month' => 20]],
            'prices' => [['from' => '2025-01-01', 'cold_water_eur_per_m3' => 4.0]],
            'meter_ids' => ['cold_water' => [$water]]]);
        $read(self::STICHTAG);
        $then = $budget->budget($t['id'], self::STICHTAG);
        $read('2025-07-01');
        $later = $budget->budget($t['id'], self::STICHTAG);
        self::assertSame($then, $later);
        // gemessen Januar bis März: 90 Tage × 0,1 m³ = 9 m³
        self::assertEqualsWithDelta(9.0, array_sum(array_column(array_slice($later['months'], 0, 3), 'cold_water_m3')), 1e-6);
    }

    /** Die API-Referenz sagt es in beiden Sprachen, gleich nach der Marke (der Test oben rechnet es nach). */
    public function testTheApiReferenceSaysTheBudgetUsesTheStateOnTheStichtag(): void
    {
        $want = ['docs/referenz/api.md' => 'Zählerstände nach `as_of` zählen nicht mit',
                 'docs/en/referenz/api.md' => 'meter readings after `as_of` do not count'];
        foreach ($want as $file => $phrase) {
            $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            $pos = strpos($text, '<!-- doc-claim: budget-as-of');
            self::assertNotFalse($pos, "$file: Marke fehlt");
            self::assertStringContainsString($phrase, str_replace("\n", ' ', substr($text, $pos, 900)), $file);
        }
    }

    public function testAForecastWithAStichtagStartsAfterItsLastMonth(): void
    {
        $this->readUntil('2025-08-01');
        $fc = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $now = $fc->forMeter('strom', $this->meter(), ['forecast_months' => 3]);
        $then = $fc->forMeter('strom', $this->meter(), ['forecast_months' => 3, 'today' => '2025-07-15']);
        // heute: sieben Monate Januar bis Juli, die Prognose beginnt im August
        self::assertSame('2025-08', $now['forecast'][0]['ym'] ?? null);
        // am 15.07.: Januar bis Juni (letzte Ablesung davor am 01.07.), die Prognose beginnt im Juli
        self::assertSame('2025-07', $then['forecast'][0]['ym'] ?? null);
    }
}
