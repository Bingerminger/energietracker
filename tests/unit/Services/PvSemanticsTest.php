<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BenchmarkService;
use Energietracker\Services\DeliveryService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\PdfReportService;
use Energietracker\Services\PvSummaryService;
use Energietracker\Services\RecommendationService;
use Energietracker\Services\TariffComparisonService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.10.0 — PV nach ihrer Bedeutung (Review CALC-17, CALC-18).
 *
 * Bis v2.9 stand die Erzeugung im Jahresbericht als CO₂-Emission, die
 * Einspeisevergütung als „Kosten", ein Angebot mit höherer Vergütung galt im
 * Tarifwechsel als „teurer" — und ein Erzeugungszähler, der im Juli dazukam,
 * ergab für das Jahr „Eigenverbrauch 0, Autarkie 0". Der Wert des
 * Eigenverbrauchs (vermiedener Bezug) fehlte ganz.
 */
#[CoversClass(PvSummaryService::class)]
#[CoversClass(TariffSwitchService::class)]
#[CoversClass(PdfReportService::class)]
final class PvSemanticsTest extends ServiceTestCase
{
    private const DEVICE = ['serial' => null, 'installed_on' => '2023-12-01', 'initial_counter' => 0.0,
                            'removed_on' => null, 'final_counter' => null, 'reason' => null];

    /** Monatliche Stände ab `$fromMonth` (1–12) im Jahr 2024, `$step` kWh je Monat. */
    private function seed(string $utility, float $step, int $fromMonth = 1): string
    {
        $id = $this->setMeterDevices($utility, [['id' => 'd_' . $utility] + self::DEVICE]);
        $rows = [];
        for ($i = $fromMonth - 1; $i <= 12; $i++) {
            $rows[] = [
                'date' => (new \DateTimeImmutable('2024-01-01'))->modify("+$i months")->format('Y-m-d'),
                'counter' => 1000.0 + ($i - $fromMonth + 1) * $step,
                'device_id' => 'd_' . $utility,
            ];
        }
        $this->setReadings($utility, $id, $rows);
        return $id;
    }

    private function contract(string $utility, string $meterId, float $ct, bool $shadow = false, string $label = 'Tarif'): void
    {
        $this->contracts->create($utility, [
            'meter_id' => $meterId, 'provider' => $label, 'tariff_name' => $label,
            'start' => '2023-12-01', 'end' => $shadow ? null : '2043-12-31',
            'is_shadow' => $shadow, 'shadow_label' => $shadow ? $label : null,
            'working_prices' => [['from' => '2023-12-01', 'ct_per_kwh' => $ct]],
        ]);
    }

    public function testQuotasCountOnlyMonthsWithAllThreeMeters(): void
    {
        $this->seed('strom', 200.0);
        $this->seed('pv_einspeisung', 150.0);
        $this->seed('pv_erzeugung', 300.0, 7);   // Erzeugungszähler erst ab Juli

        $y = null;
        foreach ((new PvSummaryService($this->consumption))->compute()['yearly'] as $row) {
            if ($row['year'] === 2024) $y = $row;
        }
        self::assertNotNull($y);
        self::assertSame(12, $y['months_with_data']);
        self::assertSame(6, $y['months_covered']);
        self::assertEqualsWithDelta(6 * 150.0, $y['eigenverbrauch_kwh'], 1.0, 'Juli–Dezember: 300 erzeugt − 150 eingespeist');
        self::assertEqualsWithDelta(0.5, $y['eigenverbrauchsquote'], 0.01, 'bis v2.9: 0 (Einspeisung Januar–Juni gegen Erzeugung ab Juli)');
        self::assertEqualsWithDelta(150 / 350, $y['autarkiequote'], 0.01);
    }

    public function testSelfConsumptionIsWorthTheWorkingPriceItReplaces(): void
    {
        $strom = $this->seed('strom', 200.0);
        $feed  = $this->seed('pv_einspeisung', 150.0);
        $this->seed('pv_erzeugung', 300.0);
        $this->contract('strom', $strom, 30.0);
        $this->contract('pv_einspeisung', $feed, 8.0);

        $y = null;
        foreach ((new PvSummaryService($this->consumption))->compute()['yearly'] as $row) {
            if ($row['year'] === 2024) $y = $row;
        }
        self::assertEqualsWithDelta(12 * 150 * 0.30, $y['savings_eur'], 0.5, 'Eigenverbrauch × Arbeitspreis des Bezugs');
        self::assertEqualsWithDelta(12 * 150 * 0.08, $y['feed_in_revenue_eur'], 0.5);
        self::assertEqualsWithDelta($y['savings_eur'] + $y['feed_in_revenue_eur'], $y['pv_benefit_eur'], 0.01);
    }

    public function testAHigherFeedInTariffRanksFirst(): void
    {
        $feed = $this->seed('pv_einspeisung', 150.0);
        $this->contract('pv_einspeisung', $feed, 8.0, false, 'Bestand');
        $this->contract('pv_einspeisung', $feed, 7.0, true, 'Niedrig');
        $this->contract('pv_einspeisung', $feed, 10.0, true, 'Hoch');

        $forecasts = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $res = (new TariffSwitchService($forecasts, $this->contracts, $this->meters, $this->i18n))
            ->analyze('pv_einspeisung', $feed, ['today' => '2024-12-15']);
        self::assertTrue($res['higher_is_better']);
        $offers = array_values(array_filter($res['candidates'], fn($c) => !$c['is_reference']));
        self::assertSame(['Hoch', 'Niedrig'], array_map(fn($c) => $c['label'], $offers),
            'mehr Vergütung zuerst (bis v2.9 hinten, als „teurer")');
    }

    public function testHigherIsBetterAlsoWhenNothingCanBeCompared(): void
    {
        // Die Antwortform hängt nicht davon ab, ob gerechnet werden konnte
        $feed = $this->seed('pv_einspeisung', 150.0);
        $retro = (new TariffComparisonService($this->consumption, $this->contracts, $this->meters, $this->i18n))
            ->compare('pv_einspeisung', $feed, 2019);         // Jahr ohne Daten
        self::assertSame([], $retro['rows']);
        self::assertTrue($retro['higher_is_better']);

        $this->setReadings('pv_einspeisung', $feed, []);     // ohne Daten keine Prognose
        $forecasts = new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n);
        $switch = (new TariffSwitchService($forecasts, $this->contracts, $this->meters, $this->i18n))
            ->analyze('pv_einspeisung', $feed, ['today' => '2024-12-15']);
        self::assertFalse($switch['supported']);
        self::assertTrue($switch['higher_is_better']);
    }

    public function testTheReportShowsRevenueAndAvoidedCo2(): void
    {
        $strom = $this->seed('strom', 200.0);
        $feed  = $this->seed('pv_einspeisung', 150.0);
        $this->seed('pv_erzeugung', 300.0);
        $this->contract('strom', $strom, 30.0);
        $this->contract('pv_einspeisung', $feed, 8.0);
        $this->settings->set(['active_utilities' => ['strom', 'pv_einspeisung', 'pv_erzeugung']]);

        $benchmark = new BenchmarkService($this->consumption, $this->meters, $this->settings, $this->i18n);
        $deliveries = new DeliveryService($this->store, $this->meters, $this->i18n);
        $recs = new RecommendationService($this->store, $this->meters, $this->consumption, $this->settings, $benchmark, $deliveries, $this->i18n);
        $pdf = (new PdfReportService($this->meters, $this->consumption, $this->settings, $benchmark, $recs, $this->i18n))->build(2024);
        preg_match_all('/\((.*?)\) Tj/s', $pdf, $m);
        $texts = array_map(fn($t) => (string)iconv('CP1252', 'UTF-8', stripslashes($t)), $m[1]);

        // Übersichtszelle „Erlös 144,00 €" — nicht die Kachel „Erlös gesamt" oder der Spaltenkopf
        self::assertNotEmpty(array_filter($texts, fn($t) => preg_match('/^Erlös \d/u', $t) === 1), 'Einspeisung als Erlös, nicht als Kosten');
        $avoided = array_values(array_filter($texts, fn($t) => str_ends_with($t, 'kg vermieden')));
        self::assertCount(1, $avoided, 'vermiedenes CO₂ genau einmal (bei der Erzeugung)');
        self::assertContains('in der Erzeugung enthalten', $texts);
    }
}
