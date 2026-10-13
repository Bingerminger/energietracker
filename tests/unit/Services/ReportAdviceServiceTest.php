<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ReportAdviceService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.4.0 (F1025) — Regeln des Jahresberichts, tabellengetrieben.
 *
 * Je Regel: knapp unter und knapp über der Schwelle, die €-Formel, kein € ohne
 * Preis, kein Rechtshinweis außerhalb Deutschlands, kein Wechsel bei Wasser.
 * Die Schwellen kommen aus den Konstanten des Dienstes — eine zweite Liste
 * hier würde mit der ersten auseinanderlaufen.
 */
final class ReportAdviceServiceTest extends ServiceTestCase
{
    private const TODAY = '2026-10-13';

    private function advice(): ReportAdviceService
    {
        $this->i18n->setLocale('de');
        return new ReportAdviceService($this->i18n);
    }

    /** Fakten mit leeren Töpfen; $over überschreibt. */
    private static function facts(array $over = []): array
    {
        return $over + [
            'year' => 2025, 'today' => self::TODAY, 'country' => 'DE', 'contracts' => [], 'heating' => [], 'anomalies' => [],
            'efficiency' => [], 'heat_pumps' => [], 'pv' => null, 'flex' => [], 'module3_eur' => 0.0, 'tenancy' => null,
            'co2_claim' => null, 'agenda' => [], 'bills' => [], 'baselines' => [], 'ev' => null, 'totals' => [], 'dismissed' => [],
        ];
    }

    private static function contract(array $over = []): array
    {
        return $over + [
            'key' => 'strom|m1|c1', 'utility' => 'strom', 'label' => 'Strom', 'meter_id' => 'm1', 'provider' => 'Anbieter', 'unit' => 'kWh',
            'switchable' => true, 'has_advance' => true, 'is_current' => true, 'is_future' => false, 'start' => '2025-01-01',
            'end' => '2026-12-31', 'effective_end' => '2026-12-31', 'is_open_ended' => false, 'renewed' => false,
            'cancel_by' => null, 'days_to_cancel' => null, 'cancel_missed' => false, 'switch_date' => '2027-01-01',
            'price_increase' => null, 'projected_end_balance' => 0.0, 'current_advance' => 80.0, 'suggested_advance' => 80.0,
            'settle_date' => '2026-12-31', 'rec_r6' => 'r6_x', 'rec_price' => 'rp_x', 'expected_consumption' => null, 'offer' => null,
        ];
    }

    private function tasks(array $facts, ?string $rule = null): array
    {
        $r = $this->advice()->evaluate($facts);
        return array_values(array_filter($r['tasks'], fn($t) => $rule === null || $t['rule'] === $rule));
    }

    private function successes(array $facts, string $rule): array
    {
        return array_values(array_filter($this->advice()->evaluate($facts)['successes'], fn($t) => $t['rule'] === $rule));
    }

    private static function days(int $n): string
    {
        return (new \DateTimeImmutable(self::TODAY))->modify("+$n days")->format('Y-m-d');
    }

    // ── Priorität und Ampeln ─────────────────────────────────────────────

    /** @return array<string,array{0:?int,1:float,2:int}> Tage bis zur Frist, €, Priorität */
    public static function priorities(): array
    {
        return [
            'Frist in 14 Tagen'  => [ReportAdviceService::PRIORITY_1['days'], 0.0, 1],
            'Frist in 15 Tagen'  => [ReportAdviceService::PRIORITY_1['days'] + 1, 0.0, 2],
            'über 200 €'         => [null, ReportAdviceService::PRIORITY_1['eur'] + 1, 1],
            'genau 200 €'        => [null, ReportAdviceService::PRIORITY_1['eur'], 2],
            'Frist in 60 Tagen'  => [ReportAdviceService::PRIORITY_2['days'], 0.0, 2],
            'Frist in 61 Tagen'  => [ReportAdviceService::PRIORITY_2['days'] + 1, 0.0, 3],
            'über 50 €'          => [null, ReportAdviceService::PRIORITY_2['eur'] + 1, 2],
            'ohne Frist und €'   => [null, 0.0, 3],
            'überfällig'         => [-5, 0.0, 1],
        ];
    }

    #[DataProvider('priorities')]
    public function testPriority(?int $days, float $eur, int $expected): void
    {
        $task = ['deadline' => $days === null ? null : self::days($days), 'euro' => $eur > 0 ? ['value' => $eur] : null];
        self::assertSame($expected, $this->advice()->priority($task, self::TODAY));
    }

    public function testLightThresholds(): void
    {
        $c = ReportAdviceService::LIGHT_CONSUMPTION;
        self::assertSame(['ok', 'ok', 'warn', 'warn', 'alert', 'none'], array_map(fn($v) => ReportAdviceService::consumptionLevel($v),
            [-3.0, $c['ok'], $c['ok'] + 0.1, $c['warn'], $c['warn'] + 0.1, null]));
        $k = ReportAdviceService::LIGHT_COST;
        self::assertSame(['ok', 'warn', 'warn', 'alert', 'none'], array_map(fn($v) => ReportAdviceService::costLevel($v),
            [$k['ok'], $k['ok'] + 0.1, $k['warn'], $k['warn'] + 0.1, null]));
        $b = ReportAdviceService::LIGHT_BILLING;
        self::assertSame(['ok', 'ok', 'warn', 'warn', 'alert', 'none'], array_map(fn($v) => ReportAdviceService::billingLevel($v),
            [-4.0, $b['ok'] - 0.01, $b['ok'], $b['warn'], $b['warn'] + 0.01, null]));
        $d = ReportAdviceService::LIGHT_DEADLINE_DAYS;
        self::assertSame(['ok', 'ok', 'warn', 'warn', 'alert', 'alert'], array_map(fn($v) => ReportAdviceService::deadlineLevel($v),
            [null, $d['warn'] + 1, $d['warn'], $d['alert'] + 1, $d['alert'], -1]));
    }

    public function testLightsAlwaysCarryWordAndDetail(): void
    {
        $lights = $this->advice()->evaluate(self::facts(['contracts' => [self::contract(['projected_end_balance' => 250.0])],
            'agenda' => [['kind' => 'cancel_by', 'date' => self::days(10), 'title' => 'Frist']],
            'totals' => ['cost_pct' => 12.0, 'price_effect' => 100.0, 'volume_effect' => 20.0, 'consumption_pct' => -1.0, 'consumption_adjusted' => true]]))['lights'];
        self::assertSame(['cost', 'consumption', 'billing', 'deadlines'], array_column($lights, 'key'));
        self::assertSame(['alert', 'ok', 'alert', 'alert'], array_column($lights, 'level'));
        foreach ($lights as $l) {
            self::assertNotSame('', $l['word'], 'Ampel immer mit Wort');
            self::assertStringNotContainsString('report.', $l['word'] . $l['detail']);
        }
        self::assertStringContainsString('+100', $lights[0]['detail'], 'Kosten-Ampel nennt Preis- und Mengeneffekt');
        self::assertStringContainsString('+20', $lights[0]['detail']);
        self::assertTrue($lights[2]['as_of_today'] && $lights[3]['as_of_today'], 'Abrechnung und Fristen: Stand heute');
    }

    // ── Abschlag ─────────────────────────────────────────────────────────

    public function testAdvanceBelowTheThresholdIsASuccess(): void
    {
        $limit = max(ReportAdviceService::ADVANCE_MIN_EUR, 40.0);
        $f = self::facts(['contracts' => [self::contract(['current_advance' => 40.0, 'projected_end_balance' => $limit - 1])]]);
        self::assertSame([], $this->tasks($f, 'advance'));
        self::assertCount(1, $this->successes($f, 'advanceOk'));
    }

    public function testAdvanceDueWithSuggestion(): void
    {
        $t = $this->tasks(self::facts(['contracts' => [self::contract(['projected_end_balance' => 130.0, 'suggested_advance' => 98.0])]]), 'advance');
        self::assertCount(1, $t);
        self::assertSame(['value' => 130.0, 'per' => 'once', 'basis' => 'projected', 'effect' => 'avoids'],
            array_intersect_key($t[0]['euro'], array_flip(['value', 'per', 'basis', 'effect'])));
        self::assertStringContainsString('98 €', $t[0]['steps'][0], 'Vorschlag mit Betrag');
        self::assertStringContainsString('80 €', $t[0]['steps'][0]);
    }

    /** Mangel 11 — ein Vorschlag ≤ 0 wird nicht als „Abschlag 0 €“ gezeigt. */
    public function testAdvanceCreditWithoutUsableSuggestionNamesNoAmount(): void
    {
        foreach ([0.0, null, -20.0] as $sugg) {
            $t = $this->tasks(self::facts(['contracts' => [self::contract(['projected_end_balance' => -900.0, 'suggested_advance' => $sugg])]]), 'advance');
            self::assertCount(1, $t);
            self::assertSame('refund', $t[0]['euro']['effect']);
            self::assertSame($this->i18n->t('report.advice.advance.stepLowerNoAmount'), $t[0]['steps'][0], 'Vorschlag ' . var_export($sugg, true));
            self::assertStringNotContainsString(' 0 €', implode(' ', $t[0]['steps']));
        }
        $t = $this->tasks(self::facts(['contracts' => [self::contract(['projected_end_balance' => -300.0, 'suggested_advance' => 55.0])]]), 'advance');
        self::assertStringContainsString('55 €', $t[0]['steps'][0]);
    }

    // ── Kündigen oder wechseln ───────────────────────────────────────────

    public function testCancelWindowAndOffer(): void
    {
        $in = fn(int $d, array $o = []) => self::facts(['contracts' => [self::contract($o + ['cancel_by' => self::days($d), 'projected_end_balance' => 0.0])]]);
        self::assertSame([], $this->tasks($in(ReportAdviceService::CANCEL_WINDOW_DAYS + 1), 'cancel'));
        $t = $this->tasks($in(ReportAdviceService::CANCEL_WINDOW_DAYS), 'cancel');
        self::assertCount(1, $t);
        self::assertSame(self::days(ReportAdviceService::CANCEL_WINDOW_DAYS), $t[0]['deadline']);
        self::assertNull($t[0]['euro'], 'ohne Angebot kein Betrag');

        $t = $this->tasks($in(30, ['expected_consumption' => 2715.4]), 'cancel');
        self::assertStringContainsString('2.715 kWh', implode(' ', $t[0]['steps']), 'Jahresverbrauch fürs Vergleichsportal');

        $t = $this->tasks($in(30, ['offer' => ['label' => 'Angebot A', 'vs_reference_year2_eur' => -150.0]]), 'cancel');
        self::assertSame(150.0, $t[0]['euro']['value']);
        self::assertSame(['year', 'projected', 'saves'], [$t[0]['euro']['per'], $t[0]['euro']['basis'], $t[0]['euro']['effect']]);

        $t = $this->tasks($in(30, ['offer' => ['label' => 'teurer', 'vs_reference_year2_eur' => 40.0]]), 'cancel');
        self::assertNull($t[0]['euro'], 'ein teureres Angebot ist kein Gewinn');
    }

    /** Mangel 6 — Wasser lässt sich nicht wechseln. */
    public function testWaterIsNeverToldToSwitch(): void
    {
        $f = self::facts(['contracts' => [self::contract(['utility' => 'wasser', 'label' => 'Wasser', 'switchable' => false,
            'has_advance' => false, 'cancel_by' => self::days(20), 'cancel_missed' => true])]]);
        $r = $this->advice()->evaluate($f);
        self::assertSame([], array_filter(array_merge($r['tasks'], $r['infos']), fn($t) => in_array($t['rule'], ['cancel', 'cancelMissed'], true)));
    }

    // ── Preiserhöhung ────────────────────────────────────────────────────

    public function testPriceIncreaseEuroAndLegalHintOnlyInGermany(): void
    {
        $c = self::contract(['price_increase' => ['from' => self::days(40), 'working_price_ct' => [30.0, 35.0], 'base_price_eur' => [10.0, 12.0]],
            'expected_consumption' => 2000.0]);
        $t = $this->tasks(self::facts(['contracts' => [$c]]), 'priceIncrease');
        self::assertEqualsWithDelta((35 - 30) * 2000 / 100 + (12 - 10) * 12, $t[0]['euro']['value'], 0.001);
        self::assertSame('costs', $t[0]['euro']['effect']);
        self::assertStringContainsString('§ 41', implode(' ', $t[0]['steps']));

        $t = $this->tasks(self::facts(['contracts' => [$c], 'country' => 'AT']), 'priceIncrease');
        self::assertStringNotContainsString('§', implode(' ', $t[0]['steps']), 'kein Rechtshinweis außerhalb Deutschlands');

        $t = $this->tasks(self::facts(['contracts' => [['expected_consumption' => null] + $c]]), 'priceIncrease');
        self::assertNull($t[0]['euro'], 'ohne Prognose kein Betrag');
    }

    // ── Heizung, Anomalien, Effizienz ────────────────────────────────────

    /** @return array<string,array{0:float,1:?string}> Veränderung %, erwartete Regel */
    public static function heating(): array
    {
        return [
            'gleich'          => [0.0, null],
            'knapp unter +5'  => [ReportAdviceService::HEATING_RISE_PCT - 0.1, null],
            'genau +5'        => [ReportAdviceService::HEATING_RISE_PCT, 'heatingRise'],
            'genau -2'        => [ReportAdviceService::HEATING_FALL_PCT, 'heatingFall'],
            'knapp über -2'   => [ReportAdviceService::HEATING_FALL_PCT + 0.1, null],
        ];
    }

    #[DataProvider('heating')]
    public function testHeatingRule(float $pct, ?string $rule): void
    {
        $prev = 10000.0;
        $f = self::facts(['heating' => [['utility' => 'gas', 'label' => 'Gas', 'adj_now' => $prev * (1 + $pct / 100), 'adj_prev' => $prev, 'price_ct' => 10.0]]]);
        $r = $this->advice()->evaluate($f);
        $hit = array_values(array_filter(array_merge($r['tasks'], $r['successes']), fn($t) => str_starts_with($t['rule'], 'heating')));
        if ($rule === null) { self::assertSame([], $hit); return; }
        self::assertSame($rule, $hit[0]['rule']);
        self::assertEqualsWithDelta(abs($prev * $pct / 100) * 0.10, $hit[0]['euro']['value'], 0.01, '(Δ witterungsbereinigt) × Ø Arbeitspreis');
        self::assertSame('measured', $hit[0]['euro']['basis']);
    }

    public function testNoEuroWithoutOwnPrice(): void
    {
        $f = self::facts([
            'heating' => [['utility' => 'gas', 'label' => 'Gas', 'adj_now' => 11000.0, 'adj_prev' => 10000.0, 'price_ct' => null]],
            'anomalies' => [['id' => 'r1_a', 'utility' => 'gas', 'label' => 'Gas', 'ym' => '2025-02', 'actual' => 900.0, 'expected' => 700.0, 'unit' => 'kWh', 'price_ct' => null]],
            'efficiency' => [['utility' => 'gas', 'label' => 'Gas', 'class' => 'F', 'kwh_per_m2' => 180.0, 'kwh' => 20000.0, 'price_ct' => null, 'rec_id' => 'r7_x']],
        ]);
        foreach ($this->tasks($f) as $t) self::assertNull($t['euro'], $t['rule'] . ': ohne eigenen Preis kein Betrag');
    }

    public function testAnomaliesAtMostThreeWithoutSigmaAndDismissedLeftOut(): void
    {
        $a = fn(string $id, string $ym) => ['id' => $id, 'utility' => 'strom', 'label' => 'Strom', 'ym' => $ym, 'actual' => 300.0, 'expected' => 200.0, 'unit' => 'kWh', 'price_ct' => 30.0];
        $f = self::facts(['anomalies' => [$a('r4_1', '2025-01'), $a('r4_2', '2025-02'), $a('r4_3', '2025-03'), $a('r4_4', '2025-04')]]);
        $t = $this->tasks($f, 'anomaly');
        self::assertCount(ReportAdviceService::MAX_ANOMALIES, $t);
        self::assertEqualsWithDelta(30.0, $t[0]['euro']['value'], 0.001, '(Ist − Erwartung) × Preis');
        foreach ($t as $x) self::assertDoesNotMatchRegularExpression('/sigma|σ/i', $x['title'] . $x['reason']);

        $t = $this->tasks(['dismissed' => ['r4_1']] + $f, 'anomaly');
        self::assertSame(['r4_2', 'r4_3', 'r4_4'], array_column($t, 'id'), 'ausgeblendete bleiben draußen');
    }

    public function testEfficiencyOnlyFromClassE(): void
    {
        $e = fn(string $class) => self::facts(['efficiency' => [['utility' => 'gas', 'label' => 'Gas', 'class' => $class, 'kwh_per_m2' => 150.0, 'kwh' => 20000.0, 'price_ct' => 10.0, 'rec_id' => 'r7_x']]]);
        self::assertSame([], $this->tasks($e('D'), 'efficiency'));
        foreach (ReportAdviceService::WEAK_CLASSES as $class) {
            $t = $this->tasks($e($class), 'efficiency');
            self::assertSame('example', $t[0]['euro']['basis'], 'nur als Rechenbeispiel');
            self::assertEqualsWithDelta(20000 * ReportAdviceService::EFFICIENCY_EXAMPLE_SHARE * 0.10, $t[0]['euro']['value'], 0.01);
        }
        self::assertSame([], $this->tasks(['dismissed' => ['r7_x']] + $e('G'), 'efficiency'));
    }

    // ── Wärmepumpe, Sonnenstrom, Flexibilität ────────────────────────────

    public function testHeatPumpAgainstTheFieldTest(): void
    {
        $hp = fn(float $jaz) => self::facts(['heat_pumps' => [['id' => 'h', 'name' => 'WP', 'jaz' => $jaz, 'reference' => 3.4, 'elec_kwh' => 4000.0, 'price_ct' => 25.0]]]);
        $r = $this->advice()->evaluate($hp(3.4 - ReportAdviceService::JAZ_MARGIN - 0.01));
        self::assertSame('heatPump', $r['tasks'][0]['rule']);
        self::assertEqualsWithDelta(4000 * (1 - 3.09 / 3.4) * 0.25, $r['tasks'][0]['euro']['value'], 0.05);
        self::assertSame('example', $r['tasks'][0]['euro']['basis']);
        $r = $this->advice()->evaluate($hp(3.2));
        self::assertSame([], $r['tasks']);
        self::assertSame(['heatPump'], array_column(array_filter($r['infos'], fn($i) => $i['rule'] === 'heatPump'), 'rule'));
        self::assertCount(1, $this->successes($hp(3.4), 'heatPump'));
    }

    public function testPvSelfUseAndBattery(): void
    {
        $pv = fn(float $rate, float $eff) => self::facts(['pv' => ['benefit_eur' => 500.0, 'savings_eur' => 400.0, 'feed_in_revenue_eur' => 100.0,
            'self_rate' => $rate, 'autarky' => 0.3, 'grid_ct' => 30.0, 'feed_ct' => 8.0,
            'battery' => ['efficiency_pct' => $eff, 'losses_kwh' => 200.0, 'discharged_kwh' => 1000.0]]]);
        $t = $this->tasks($pv(ReportAdviceService::SELF_RATE_LOW - 0.01, 90.0));
        self::assertSame(['pvSelfUse'], array_column($t, 'rule'));
        self::assertSame(['value' => 22.0, 'per' => 'per100'], array_intersect_key($t[0]['euro'], ['value' => 1, 'per' => 1]), '(Bezug − Einspeisung) je 100 kWh');
        self::assertSame([], $this->tasks($pv(ReportAdviceService::SELF_RATE_LOW, 90.0)));
        $t = $this->tasks($pv(0.5, ReportAdviceService::BATTERY_EFF_MIN - 1));
        self::assertSame('batteryLoss', $t[0]['rule']);
        self::assertEqualsWithDelta(200 * 0.30, $t[0]['euro']['value'], 0.001);
        self::assertSame([], $this->tasks($pv(0.5, ReportAdviceService::BATTERY_EFF_MIN)));
        $s = $this->successes($pv(0.5, 90.0), 'battery');
        self::assertEqualsWithDelta(1000 * 0.22, $s[0]['euro']['value'], 0.001, 'entladen × (Bezug − Einspeisung)');
    }

    public function testExpensiveHoursOnlyForControllableDevices(): void
    {
        $fx = fn(float $pf, bool $ctl) => self::facts(['flex' => [['meter_id' => 'w', 'name' => 'Wallbox', 'role' => 'ev_charger', 'controllable' => $ctl,
            'profile_factor' => $pf, 'own_ct' => 10.0, 'market_ct' => 9.0, 'kwh' => 2000.0]]]);
        $t = $this->tasks($fx(1.11, true), 'flex');
        self::assertEqualsWithDelta(20.0, $t[0]['euro']['value'], 0.001, '(eigener − Börsenpreis) × kWh');
        self::assertSame([], $this->tasks($fx(1.11, false), 'flex'));
        self::assertSame([], $this->tasks($fx(1.0, true), 'flex'));
    }

    // ── Miete, CO₂, Agenda ───────────────────────────────────────────────

    public function testTenancyPrepayment(): void
    {
        $tn = fn(float $res) => self::facts(['tenancy' => ['id' => 't', 'label' => 'Wohnung', 'projected_result_eur' => $res,
            'suggested_prepayment_eur' => 190.0, 'period_to' => '2026-12-31']]);
        $t = $this->tasks($tn(ReportAdviceService::TENANCY_MIN_EUR), 'tenancy');
        self::assertSame('avoids', $t[0]['euro']['effect']);
        self::assertStringContainsString('190 €', $t[0]['steps'][0]);
        self::assertCount(1, $this->successes($tn(ReportAdviceService::TENANCY_MIN_EUR - 1), 'tenancyOk'));
        $r = $this->advice()->evaluate($tn(-ReportAdviceService::TENANCY_MIN_EUR));
        self::assertContains('tenancy', array_column($r['infos'], 'rule'), 'Guthaben: ein Hinweis, keine Aufgabe');
        self::assertSame([], $r['tasks']);
    }

    public function testCo2ClaimWithDeadlineOrSharedInTheStatement(): void
    {
        $t = $this->tasks(self::facts(['co2_claim' => ['landlord_amount_eur' => 27.0, 'case' => 'self', 'stage' => 4, 'deadline' => '2026-12-31']]), 'co2Claim');
        self::assertSame('2026-12-31', $t[0]['deadline']);
        self::assertSame(27.0, $t[0]['euro']['value']);
        self::assertCount(1, $this->successes(self::facts(['co2_claim' => ['landlord_amount_eur' => 27.0, 'case' => 'central', 'stage' => 4, 'deadline' => null]]), 'co2Split'));
    }

    public function testAgendaTasksReadingsGroupedAndOverdueMarked(): void
    {
        $f = self::facts(['agenda' => [
            ['kind' => 'reminder', 'uid' => 'r1', 'date' => self::days(-5), 'title' => 'Wartung'],
            ['kind' => 'reminder', 'uid' => 'r2', 'date' => self::days(ReportAdviceService::LIGHT_DEADLINE_DAYS['warn'] + 1), 'title' => 'Später'],
            ['kind' => 'reading_due', 'uid' => 'a', 'date' => self::TODAY, 'title' => 'A', 'due_now' => true, 'params' => ['meter' => 'Zähler A'], 'ref' => ['last_reading' => '2026-05-31']],
            ['kind' => 'reading_due', 'uid' => 'b', 'date' => self::TODAY, 'title' => 'B', 'due_now' => true, 'params' => ['meter' => 'Zähler B'], 'ref' => ['last_reading' => '2026-05-20']],
            ['kind' => 'cancel_by', 'uid' => 'c', 'date' => self::days(3), 'title' => 'Kündigung'],
        ]]);
        $t = $this->tasks($f);
        self::assertSame(['agenda', 'readings'], array_values(array_unique(array_column($t, 'rule'))), 'Verträge über ihre eigene Regel, Spätes nicht');
        $wartung = array_values(array_filter($t, fn($x) => $x['title'] === 'Wartung'))[0];
        self::assertStringStartsWith('überfällig seit', (string)$wartung['deadline_text']);
        self::assertSame(1, $wartung['priority']);
        $read = array_values(array_filter($t, fn($x) => $x['rule'] === 'readings'));
        self::assertCount(1, $read, 'eine Aufgabe für alle fälligen Ablesungen');
        self::assertStringContainsString('Zähler A, Zähler B', $read[0]['reason']);
    }

    public function testNeutralComparisonWhenNothingPaidOff(): void
    {
        $r = $this->advice()->evaluate(self::facts(['totals' => ['cost_now' => 1100.0, 'cost_prev' => 1000.0, 'cost_pct' => 10.0, 'consumption_pct' => 2.0]]));
        self::assertSame([], $r['successes']);
        self::assertSame(['neutral'], array_column($r['infos'], 'rule'));
        $r = $this->advice()->evaluate(self::facts(['totals' => ['cost_now' => 900.0, 'cost_prev' => 1000.0, 'cost_pct' => -10.0]]));
        self::assertSame(['costDown'], array_column($r['successes'], 'rule'));
        self::assertEqualsWithDelta(100.0, $r['successes'][0]['euro']['value'], 0.001);
    }

    public function testPercentHasNoNegativeZero(): void
    {
        $a = $this->advice();
        self::assertSame('0 %', str_replace("\u{00A0}", ' ', $a->pct(-0.4)), 'Mangel 2: kein „-0“');
        self::assertSame('-1 %', str_replace("\u{00A0}", ' ', $a->pct(-0.6)));
        self::assertSame('+5 %', str_replace("\u{00A0}", ' ', $a->pct(4.5)));
        $this->i18n->setLocale('en');
        self::assertSame('+5%', (new ReportAdviceService($this->i18n))->pct(4.5), 'englisch ohne Leerzeichen');
    }

    public function testEveryTextIsImpersonalInGerman(): void
    {
        // Anrede unpersönlich und ohne Befehlsform: weder „du“ noch „Sie“
        $f = self::facts([
            'contracts' => [self::contract(['projected_end_balance' => 300.0, 'suggested_advance' => 105.0, 'cancel_by' => self::days(20),
                'price_increase' => ['from' => self::days(40), 'working_price_ct' => [30.0, 35.0], 'base_price_eur' => null], 'expected_consumption' => 3000.0])],
            'heating' => [['utility' => 'gas', 'label' => 'Gas', 'adj_now' => 12000.0, 'adj_prev' => 10000.0, 'price_ct' => 10.0]],
            'heat_pumps' => [['id' => 'h', 'name' => 'WP', 'jaz' => 2.5, 'reference' => 3.4, 'elec_kwh' => 4000.0, 'price_ct' => 25.0]],
            'tenancy' => ['id' => 't', 'label' => 'Wohnung', 'projected_result_eur' => 200.0, 'suggested_prepayment_eur' => 190.0, 'period_to' => '2026-12-31'],
        ]);
        $r = $this->advice()->evaluate($f);
        $all = [];
        foreach (array_merge($r['tasks'], $r['successes'], $r['infos']) as $x) $all = array_merge($all, [$x['title'], $x['reason']], $x['steps']);
        self::assertNotEmpty($all);
        foreach ($all as $text) {
            self::assertDoesNotMatchRegularExpression('/\b(du|dein\w*|dich|dir|Sie|Ihr\w*|Ihnen)\b/u', $text, $text);
        }
    }
}
