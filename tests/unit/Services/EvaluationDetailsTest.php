<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\ForecastService;
use Energietracker\Services\ReportAdviceService;
use Energietracker\Services\ReportModelService;
use Energietracker\Tests\Support\ReportFixture;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.9.0 (Paket auswertung, Befunde L4, L7, L15 und L17 des Skeptiker-Laufs) —
 * Zahlen, die stimmen oder sagen, warum sie unsicher sind. Am unordentlichen
 * Testhaushalt (tests/fixtures/messy-household) und an einem kleinen Stromhaushalt.
 */
#[CoversClass(ForecastService::class)]
#[CoversClass(ConsumptionService::class)]
#[CoversClass(ReportModelService::class)]
#[CoversClass(ReportAdviceService::class)]
final class EvaluationDetailsTest extends ServiceTestCase
{
    use ReportFixture;

    private const TODAY = '2026-10-26';

    private function messy(): void
    {
        $h = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/messy-household/household.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->store->write('settings.json', $h['settings']);
        $this->store->write('temperatures.json', $h['temperatures']);
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        foreach ($h['utilities'] as $u => $pots) foreach ($pots as $pot => $rows) $this->store->write("$u/$pot.json", $rows);
    }

    // ── L4: Anteil der Heizkurve ────────────────────────────────────────

    public function testTheForecastSaysHowMuchReallyComesFromTheHeatingCurve(): void
    {
        $this->messy();
        $fc = (new ForecastService($this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n))
            ->forMeter('gas', $this->meters->list('gas')[0]);
        // Gewicht nach Modellgüte 0,8 (R² 0,95), aber die Gütesperre schlägt an: Jeder Monat
        // rechnet mit dem Saisonprofil, aus der Heizkurve kommt nichts (bis v3.8 „Anteil Heizkurve 80 %“)
        self::assertSame(0.8, $fc['blend_weight']);
        self::assertSame('poor', $fc['fit_quality']['model_quality']);
        self::assertSame(['seasonal_only'], array_values(array_unique(array_column($fc['forecast'], 'method'))));
        self::assertSame(0.0, $fc['blend_effective']);
    }

    // ── L7: Abschlag der gemessenen Tage ────────────────────────────────

    public function testTheFirstAndLastMonthCountTheAdvanceOfTheirMeasuredDays(): void
    {
        $this->messy();
        $by = array_column($this->consumption->forMeter('gas', $this->meters->list('gas')[0]), null, 'ym');
        // letzte Ablesung am 05.01.2026: vier Tage Januar, Abschlag 150 €, Grundpreis 13,50 € im Monat
        self::assertSame(4, $by['2026-01']['days']);
        self::assertSame(150.0, (float)$by['2026-01']['advance_eur'], 'fällig ist der ganze Abschlag');
        self::assertSame(19.35, $by['2026-01']['advance_measured_eur']);   // 150 × 4/31 = 19,354
        self::assertEqualsWithDelta($by['2026-01']['cost'] - 13.5 * 27 / 31, $by['2026-01']['cost_measured'], 0.006);   // Grundpreis nur für 4 Tage
        // Beginn am 03.11.2022: 28 von 30 Tagen
        self::assertSame(140.0, (float)$by['2022-11']['advance_measured_eur']);   // 150 × 28/30
        self::assertEqualsWithDelta($by['2022-11']['cost'] - 13.5 * 2 / 30, $by['2022-11']['cost_measured'], 0.006);
        // ein voller Monat dazwischen: gleich
        self::assertSame((float)$by['2025-03']['advance_eur'], (float)$by['2025-03']['advance_measured_eur']);
        self::assertSame((float)$by['2025-03']['cost'], (float)$by['2025-03']['cost_measured']);
    }

    // ── L15: künftiger Vertrag ──────────────────────────────────────────

    public function testAFutureContractShowsThePricesAndAdvanceOfItsStart(): void
    {
        $this->messy();
        $meter = $this->meters->list('strom')[0];
        $this->contracts->create('strom', ['meter_id' => $meter['id'], 'provider' => 'Neuversorger', 'start' => '2027-01-01',
            'working_prices' => [['from' => '2027-01-01', 'ct_per_kwh' => 30.0]], 'base_prices' => [['from' => '2027-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2027-01-01', 'amount_eur' => 70.0]]]);
        $rows = $this->consumption->contractStatus('strom', $meter, self::TODAY)['contracts'];
        $future = array_values(array_filter($rows, fn($c) => !empty($c['is_future'])))[0];
        // bis v3.8 „–“: Am 26.10.2026 gilt noch kein Preis dieses Vertrags
        self::assertSame([30.0, 12.0, 70.0], [(float)$future['current_working_price_ct'], (float)$future['current_base_price_eur'],
            (float)$future['current_advance_amount']]);
    }

    // ── L17: weniger verbraucht, als Einsparung nicht belegbar ──────────

    /** Strom am Monatsersten abgelesen: 2024 je 300 kWh, 2025 je 246 kWh (−18 %). */
    private function lessPower(): void
    {
        foreach (['gas', 'strom', 'wasser'] as $u) foreach (['meters', 'readings', 'contracts'] as $pot) $this->store->write("$u/$pot.json", []);
        $this->store->write('strom/meters.json', [['id' => 'm_s', 'name' => 'Strom Test', 'icon' => '', 'created_at' => '2024-01-01', 'active' => true,
            'notes' => '', 'parent_meter_id' => null, 'meter_group_id' => null, 'external_id' => null, 'baseline_events' => [],
            'devices' => [['id' => 'd_s', 'serial' => 'S', 'installed_on' => '2024-01-01', 'initial_counter' => 0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]]]]);
        $rows = []; $c = 0.0;
        for ($i = 0; $i <= 24; $i++) {
            $d = date('Y-m-d', strtotime("2024-01-01 +$i months"));
            $rows[] = ['id' => "r$i", 'meter_id' => 'm_s', 'device_id' => 'd_s', 'date' => $d, 'counter' => $c, 'price_cents' => null, 'note' => '',
                'is_estimated' => false, 'is_future' => false];
            $c += $i < 12 ? 300.0 : 246.0;
        }
        $this->store->write('strom/readings.json', $rows);
    }

    public function testLessConsumptionWithoutProofSaysWhyNotThatNothingWasSaved(): void
    {
        $this->lessPower();
        $r = $this->reportServices()['model']->build(2025, ['today' => self::TODAY]);
        $tile = array_column($r['headline']['tiles'], null, 'key')['consumption'];
        self::assertSame(-18.0, $tile['pct']);   // 12 × 246 = 2.952 gegen 12 × 300 = 3.600
        $light = array_column($r['headline']['lights'], null, 'key')['consumption'];
        self::assertSame($this->i18n->t('report.light.consumption.less'), $light['word'], 'bis v3.8 „Nicht mehr verbraucht −18 %“');
        self::assertSame([], $r['successes']);
        // bis v3.8 „Für 2025 lässt sich keine Einsparung gegenüber 2024 belegen.“; „18 %“ ohne Vorzeichen vor „weniger“
        $plain = fn(string $s): string => (string)preg_replace('/\h/u', ' ', $s);
        self::assertSame($plain($this->i18n->t('report.section.successesNoneUnadjusted', ['year' => 2025, 'prev' => 2024, 'pct' => '18 %'])),
            $plain($r['texts']['successesNone']));
        self::assertStringStartsWith('2025 wurde 18 % weniger verbraucht als 2024.', $plain($r['texts']['successesNone']));
    }
}
