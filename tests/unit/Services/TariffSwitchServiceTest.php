<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Tests\Support\ServiceTestCase;
use Energietracker\Services\ForecastService;
use Energietracker\Services\TariffSwitchService;
use Energietracker\Services\ContractService;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v2.3.0 — Wechselentscheidung.
 *
 * Geprüft wird das, worauf sich ein Nutzer verlässt, wenn er wegen dieser
 * Zahlen den Anbieter wechselt: der Kündigungstermin, die saisonale
 * Verteilung des prognostizierten Verbrauchs, die Trennung von Jahr 1 und
 * Jahr 2, und der Break-even.
 *
 * Strom statt Gas, weil dort Zählerstand und Verbrauch dieselbe Einheit
 * haben — bei Gas läge zwischen beiden noch der Brennwertfaktor, was die
 * Sollwerte im Test verschleiern würde.
 */
#[CoversClass(TariffSwitchService::class)]
#[CoversClass(ContractService::class)]
final class TariffSwitchServiceTest extends ServiceTestCase
{
    private TariffSwitchService $switch;

    /** Saisonprofil in kWh/Monat — deutlicher Winterpeak, Summe 6.000. */
    private const PROFILE = [
        1 => 800, 2 => 720, 3 => 600, 4 => 450, 5 => 330, 6 => 250,
        7 => 220, 8 => 220, 9 => 300, 10 => 450, 11 => 620, 12 => 760,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $forecast = new ForecastService(
            $this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n
        );
        $this->switch = new TariffSwitchService(
            $forecast, $this->contracts, $this->meters, $this->i18n
        );
    }

    /** Drei Jahre Ablesungen nach dem Saisonprofil, endend im Juli 2026. */
    private function seedMeter(): string
    {
        $meterId = $this->setMeterDevices('strom', [[
            'id' => 'd_strom_1', 'serial' => null,
            'installed_on' => '2023-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null,
        ]]);

        $rows    = [];
        $counter = 1000.0;
        $cur     = new \DateTimeImmutable('2024-01-01');
        $rows[]  = ['date' => $cur->format('Y-m-d'), 'counter' => $counter, 'device_id' => 'd_strom_1'];
        while ($cur < new \DateTimeImmutable('2026-07-01')) {
            $cur = $cur->modify('+1 month');
            $counter += self::PROFILE[(int)$cur->format('n')];
            $rows[] = ['date' => $cur->format('Y-m-d'), 'counter' => $counter, 'device_id' => 'd_strom_1'];
        }
        $this->setReadings('strom', $meterId, $rows);
        return $meterId;
    }

    /** @param array<string,mixed> $extra */
    private function addCurrentContract(string $meterId, array $extra = []): void
    {
        $this->contracts->create('strom', array_merge([
            'meter_id'             => $meterId,
            'provider'             => 'Stadtwerke',
            'tariff_name'          => 'Basis 2026',
            'start'                => '2026-01-01',
            'end'                  => '2026-12-31',
            'notice_period_months' => 1,
            'working_prices'       => [['from' => '2026-01-01', 'ct_per_kwh' => 30.0]],
            'base_prices'          => [['from' => '2026-01-01', 'eur_per_month' => 12.0]],
        ], $extra));
    }

    /** @param array<string,mixed> $extra */
    private function addOffer(string $meterId, string $label, float $wp, float $bp, array $extra = []): void
    {
        $this->contracts->create('strom', array_merge([
            'meter_id'       => $meterId,
            'provider'       => 'Anbieter',
            'tariff_name'    => $label,
            'start'          => '2026-01-01',
            'end'            => null,
            'is_shadow'      => true,
            'shadow_label'   => $label,
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => $wp]],
            'base_prices'    => [['from' => '2026-01-01', 'eur_per_month' => $bp]],
        ], $extra));
    }

    /** @param array<int,array<string,mixed>> $candidates */
    private function byLabel(array $candidates, string $label): ?array
    {
        foreach ($candidates as $c) {
            if ($c['label'] === $label) return $c;
        }
        return null;
    }

    // ── Termine ──────────────────────────────────────────────────────

    public function testSwitchDateFollowsContractEndAndNoticePeriod(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);

        self::assertSame('2027-01-01', $r['switch_date'], 'Wechsel am Tag nach Vertragsende');
        self::assertSame('contract', $r['switch_date_source']);
        self::assertSame('2026-11-30', $r['current']['cancel_by'], 'einen Monat vor Vertragsende');
        self::assertSame(119, $r['current']['days_to_cancel']);
    }

    /**
     * Der Monatsüberlauf, an dem PHP scheitert.
     *
     * `strtotime('2026-03-31 -1 month')` liefert **2026-03-03**: Der 31. Februar
     * existiert nicht, PHP lässt den Überlauf stehen. Ein Nutzer, der sich auf
     * diesen Stichtag verlässt, kündigt vier Wochen zu spät und hängt ein
     * weiteres Vertragsjahr fest.
     */
    public function testCancelDateDoesNotOverflowIntoTheFollowingMonth(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId, ['start' => '2025-04-01', 'end' => '2026-03-31']);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-01-15']);

        self::assertSame('2026-02-28', $r['current']['cancel_by']);
        self::assertSame('2026-04-01', $r['switch_date']);
    }

    public function testSwitchDateCanBeOverridden(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);

        $r = $this->switch->analyze('strom', $meterId, [
            'today' => '2026-08-03', 'switch_date' => '2026-09-01',
        ]);

        self::assertSame('2026-09-01', $r['switch_date']);
        self::assertSame('override', $r['switch_date_source']);
        self::assertSame('2026-09', $r['window']['from']);
        self::assertSame('2027-08', $r['window']['to']);
    }

    public function testMissingNoticePeriodYieldsNoCancelDateInsteadOfAGuess(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId, ['notice_period_months' => null]);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);

        self::assertNull($r['current']['cancel_by'], 'ohne gepflegte Frist keinen Termin behaupten');
        self::assertSame('2027-01-01', $r['switch_date'], 'das Vertragsende steht trotzdem fest');
    }

    // ── Vertragskette (v2.3.1) ───────────────────────────────────────

    /**
     * Wer den Folgevertrag schon abgeschlossen hat, kann nicht zu dessen
     * Beginn wechseln — der Wechsel ist dann bereits vollzogen.
     *
     * Auf Produktivdaten meldete das Modul genau das: Der laufende Vertrag
     * endete am 30.08., ein Nachfolger lief bereits ab dem 31.08., und als
     * „Wechseltermin" erschien der 31.08. Schlimmer noch: Die Vergleichsbasis
     * rechnete das ganze Folgejahr mit den Preisen des auslaufenden Vertrags,
     * obwohl längst der neue galt.
     */
    public function testAlreadySignedFollowUpContractPushesTheSwitchDate(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId, ['start' => '2025-09-01', 'end' => '2026-08-30']);
        $this->contracts->create('strom', [
            'meter_id'       => $meterId,
            'provider'       => 'Nachfolger',
            'tariff_name'    => 'Anschluss',
            'start'          => '2026-08-31',
            'end'            => '2027-08-30',
            'working_prices' => [['from' => '2026-08-31', 'ct_per_kwh' => 24.0]],
            'base_prices'    => [['from' => '2026-08-31', 'eur_per_month' => 15.0]],
        ]);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);

        self::assertSame('2027-08-31', $r['switch_date'],
            'der Wechsel ist erst nach dem Folgevertrag möglich');
        self::assertSame('2027-08', $r['window']['from']);
        self::assertSame('2026-08-30', $r['current']['end'], 'laufend bleibt der laufende');
        self::assertSame('2027-08-30', $r['current']['bound_until']);
        self::assertSame(2, $r['current']['chain_length']);
        self::assertNotNull($r['current']['follow_up']);
        self::assertSame('Nachfolger Anschluss', $r['current']['follow_up']['label']);
    }

    /**
     * Und die Vergleichsbasis muss mit den Preisen rechnen, die im Fenster
     * gelten — also denen des Folgevertrags, nicht denen des auslaufenden.
     */
    public function testReferenceUsesTheFollowUpPricesNotTheExpiringOnes(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId, ['start' => '2025-09-01', 'end' => '2026-08-30']); // 30 ct / 12 €
        $this->contracts->create('strom', [
            'meter_id'       => $meterId,
            'provider'       => 'Nachfolger',
            'tariff_name'    => 'Anschluss',
            'start'          => '2026-08-31',
            'end'            => '2027-08-30',
            'working_prices' => [['from' => '2026-08-31', 'ct_per_kwh' => 24.0]],
            'base_prices'    => [['from' => '2026-08-31', 'eur_per_month' => 15.0]],
        ]);

        $r   = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);
        $ref = $r['candidates'][0];
        $v   = $r['expected_consumption'];

        self::assertTrue($ref['is_reference']);
        self::assertEqualsWithDelta($v * 0.24 + 15.0 * 12, $ref['year2_eur'], 0.05,
            'die Referenz muss den Folgetarif ansetzen');
        self::assertNotEqualsWithDelta($v * 0.30 + 12.0 * 12, $ref['year2_eur'], 1.0,
            'nicht den auslaufenden Tarif fortschreiben');
    }

    /**
     * Eine Lücke zwischen zwei Verträgen beendet die Bindung. Was danach
     * gepflegt ist, ist kein Anschluss, sondern ein neuer Abschnitt — sonst
     * würde ein Vertrag aus ferner Zukunft den Wechseltermin verschieben.
     */
    public function testAGapEndsTheBindingChain(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId, ['start' => '2025-09-01', 'end' => '2026-08-30']);
        $this->contracts->create('strom', [
            'meter_id'       => $meterId,
            'provider'       => 'Später',
            'tariff_name'    => 'Nach einer Lücke',
            'start'          => '2026-12-01',   // zwei Monate Abstand
            'end'            => '2027-11-30',
            'working_prices' => [['from' => '2026-12-01', 'ct_per_kwh' => 24.0]],
        ]);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);

        self::assertSame('2026-08-31', $r['switch_date'], 'die Lücke macht frei');
        self::assertSame(1, $r['current']['chain_length']);
        self::assertNull($r['current']['follow_up']);
    }

    /**
     * Deckt das Fenster mehrere Verträge ab — etwa wenn der Nutzer den Termin
     * vorzieht —, muss jeder Monat mit dem dann gültigen Tarif rechnen.
     */
    public function testWindowSpanningTwoContractsBillsEachMonthCorrectly(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId, ['start' => '2025-09-01', 'end' => '2026-12-31']); // 30 ct
        $this->contracts->create('strom', [
            'meter_id'       => $meterId,
            'provider'       => 'Nachfolger',
            'tariff_name'    => 'Anschluss',
            'start'          => '2027-01-01',
            'end'            => '2027-12-31',
            'working_prices' => [['from' => '2027-01-01', 'ct_per_kwh' => 20.0]],
            'base_prices'    => [['from' => '2027-01-01', 'eur_per_month' => 12.0]],
        ]);

        // Fenster bewusst über die Vertragsgrenze legen.
        $r = $this->switch->analyze('strom', $meterId,
            ['today' => '2026-08-03', 'switch_date' => '2026-11-01']);
        $ref = $r['candidates'][0];

        self::assertSame(2, $ref['chain_length'], 'zwei Verträge im Fenster');
        $byYm = array_column($ref['monthly'], 'working_price_ct', 'ym');
        self::assertEqualsWithDelta(30.0, $byYm['2026-11'], 0.01, 'November noch alter Tarif');
        self::assertEqualsWithDelta(30.0, $byYm['2026-12'], 0.01);
        self::assertEqualsWithDelta(20.0, $byYm['2027-01'], 0.01, 'ab Januar der neue');
        self::assertEqualsWithDelta(20.0, $byYm['2027-10'], 0.01);
        // Das Label muss verraten, dass mehr als ein Vertrag dahintersteckt.
        self::assertStringContainsString('2', $ref['label']);
    }

    // ── Verbrauch und Fenster ────────────────────────────────────────

    public function testWindowSpansTwelveMonthsFromSwitchDate(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);

        self::assertSame(12, $r['window']['months']);
        self::assertSame('2027-01', $r['window']['from']);
        self::assertSame('2027-12', $r['window']['to']);
    }

    /**
     * Ein Wechsel mitten im Jahr darf den Winter nicht halbieren.
     *
     * Eine Zwölftelrechnung würde bei einem Juli-Wechsel dieselbe Menge
     * ansetzen wie bei einem Januar-Wechsel — richtig, aber nur zufällig.
     * Entscheidend ist, dass jedes Fenster jeden Kalendermonat genau einmal
     * enthält und die Summe damit unabhängig vom Startmonat ist.
     */
    public function testConsumptionIsSeasonallyDistributedNotAveraged(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);

        $january = $this->switch->analyze('strom', $meterId,
            ['today' => '2026-08-03', 'switch_date' => '2027-01-01']);
        $july = $this->switch->analyze('strom', $meterId,
            ['today' => '2026-08-03', 'switch_date' => '2027-07-01']);

        self::assertEqualsWithDelta(
            $january['expected_consumption'], $july['expected_consumption'], 1.0,
            'dieselbe Jahresmenge, egal wann das Fenster beginnt'
        );

        // Aber die Monate liegen anders — und genau darauf kommt es an.
        $janFirst = $january['candidates'][0]['monthly'][0]['consumption'];
        $julFirst = $july['candidates'][0]['monthly'][0]['consumption'];
        self::assertGreaterThan(
            $julFirst * 2, $janFirst,
            'Januar verbraucht ein Vielfaches des Juli — flach verteilt wäre beides gleich'
        );
    }

    // ── Kosten, Bonus, Rangfolge ─────────────────────────────────────

    public function testSignupBonusCountsOnlyInTheFirstYear(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);
        $this->addOffer($meterId, 'Mit Bonus', 26.0, 15.0, ['signup_bonus_eur' => 100]);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);
        $offer = $this->byLabel($r['candidates'], 'Mit Bonus');

        self::assertNotNull($offer);
        self::assertSame(100.0, $offer['signup_bonus_eur']);
        self::assertEqualsWithDelta(
            $offer['year2_eur'] - 100.0, $offer['year1_eur'], 0.01,
            'der Bonus senkt genau das erste Jahr'
        );
    }

    /**
     * Ein Lockangebot darf die Rangliste nicht gewinnen.
     *
     * „Billig" wird nach dem dauerhaften Preis sortiert. Der Tarif mit dem
     * hohen Bonus ist im ersten Jahr der günstigste und muss trotzdem hinter
     * dem dauerhaft billigeren stehen.
     */
    public function testRankingUsesTheLastingPriceNotTheFirstYear(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);
        $this->addOffer($meterId, 'Lockangebot',  29.0, 12.0, ['signup_bonus_eur' => 250]);
        $this->addOffer($meterId, 'Dauerhaft günstig', 25.0, 12.0);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);
        $offers = array_values(array_filter($r['candidates'], fn($c) => !$c['is_reference']));

        self::assertSame('Dauerhaft günstig', $offers[0]['label'], 'dauerhaft billigster zuerst');
        self::assertSame('Lockangebot', $offers[1]['label']);
        self::assertLessThan(
            $offers[0]['year1_eur'], $offers[1]['year1_eur'],
            'im ersten Jahr ist das Lockangebot tatsächlich billiger — deshalb wird danach nicht sortiert'
        );
    }

    public function testReferenceIsTheRunningContractAndComesFirst(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);
        $this->addOffer($meterId, 'Angebot', 20.0, 10.0);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);

        self::assertTrue($r['candidates'][0]['is_reference']);
        self::assertSame('Stadtwerke Basis 2026', $r['candidates'][0]['label']);
        self::assertNull($r['candidates'][0]['vs_reference_year2_eur'], 'die Referenz hat keine Differenz zu sich selbst');
    }

    public function testCostsMatchHandCalculation(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);
        $this->addOffer($meterId, 'Angebot', 26.0, 15.0);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);
        $v = $r['expected_consumption'];

        $ref   = $this->byLabel($r['candidates'], 'Stadtwerke Basis 2026');
        $offer = $this->byLabel($r['candidates'], 'Angebot');

        self::assertEqualsWithDelta($v * 0.30 + 12.0 * 12, $ref['year2_eur'], 0.05);
        self::assertEqualsWithDelta($v * 0.26 + 15.0 * 12, $offer['year2_eur'], 0.05);
        self::assertEqualsWithDelta(
            $offer['year2_eur'] - $ref['year2_eur'], $offer['vs_reference_year2_eur'], 0.02
        );
    }

    // ── Break-even und Empfindlichkeit ───────────────────────────────

    /**
     * Der Schnittpunkt zweier linearer Kostenfunktionen:
     *   30 ct/kWh + 12 €/Monat  gegen  26 ct/kWh + 15 €/Monat
     *   x · 0,30 + 144 = x · 0,26 + 180  →  x = 36 / 0,04 = 900 kWh
     */
    public function testBreakEvenMatchesTheAnalyticalCrossover(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);
        $this->addOffer($meterId, 'Angebot', 26.0, 15.0);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);
        $offer = $this->byLabel($r['candidates'], 'Angebot');

        self::assertEqualsWithDelta(900.0, $offer['break_even_consumption'], 5.0);
    }

    public function testBreakEvenIsNullWhenTariffsNeverCross(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);
        // Beides günstiger — die Kurven laufen parallel bzw. schneiden sich
        // nur bei negativer Menge.
        $this->addOffer($meterId, 'Immer besser', 25.0, 10.0);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);
        $offer = $this->byLabel($r['candidates'], 'Immer besser');

        self::assertNull($offer['break_even_consumption']);
    }

    public function testSensitivityBracketsTheExpectedCost(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);
        $this->addOffer($meterId, 'Angebot', 26.0, 15.0);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);
        $offer = $this->byLabel($r['candidates'], 'Angebot');

        self::assertLessThan($offer['year2_eur'], $offer['sensitivity']['low']);
        self::assertGreaterThan($offer['year2_eur'], $offer['sensitivity']['high']);
        // Nur der Arbeitspreisanteil skaliert, der Grundpreis bleibt — die
        // Spanne ist daher schmaler als ±10 % der Gesamtkosten.
        self::assertGreaterThan(
            $offer['year2_eur'] * 0.90, $offer['sensitivity']['low'],
            'der Grundpreis federt die Verbrauchsschwankung ab'
        );
    }

    // ── Preisgarantie ────────────────────────────────────────────────

    public function testMonthsBeyondThePriceGuaranteeAreMarked(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);
        $this->addOffer($meterId, 'Kurze Garantie', 26.0, 15.0,
            ['price_guarantee_until' => '2027-06-30']);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);
        $offer = $this->byLabel($r['candidates'], 'Kurze Garantie');

        self::assertTrue($offer['guarantee_ends_in_window']);

        $marked = array_column($offer['monthly'], 'beyond_guarantee', 'ym');
        self::assertFalse($marked['2027-01'], 'innerhalb der Garantie');
        self::assertFalse($marked['2027-06'], 'letzter Garantiemonat');
        self::assertTrue($marked['2027-07'], 'danach ist der Preis eine Annahme');
        self::assertTrue($marked['2027-12']);
    }

    public function testNoGuaranteeMeansNoMarking(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);
        $this->addOffer($meterId, 'Ohne Garantie', 26.0, 15.0);

        $r = $this->switch->analyze('strom', $meterId, ['today' => '2026-08-03']);
        $offer = $this->byLabel($r['candidates'], 'Ohne Garantie');

        self::assertFalse($offer['guarantee_ends_in_window']);
        self::assertSame([], array_filter(array_column($offer['monthly'], 'beyond_guarantee')));
    }

    // ── Abgrenzung ───────────────────────────────────────────────────

    /**
     * Regression zum v2.2.0-Fix: Die Wechselansicht rechnet mit
     * Schattenverträgen, die Prognose darf sie weiterhin nicht sehen.
     * Beide Pfade nutzen denselben ForecastService — der Ausschluss dort
     * muss also bestehen bleiben, obwohl der Vergleich ihn umgeht.
     */
    public function testShadowContractsStayOutOfTheForecastItself(): void
    {
        $meterId = $this->seedMeter();
        $this->addCurrentContract($meterId);
        $this->addOffer($meterId, 'Sehr teuer', 99.0, 99.0);

        $forecast = new ForecastService(
            $this->consumption, $this->regression, $this->settings, $this->contracts, $this->i18n
        );
        $meter = $this->meters->get('strom', $meterId);
        $fc = $forecast->forMeter('strom', $meter);

        foreach ($fc['forecast'] as $m) {
            if ($m['working_price_ct'] !== null) {
                self::assertNotEqualsWithDelta(
                    99.0, $m['working_price_ct'], 0.01,
                    'der Schattentarif darf die Prognose nicht steuern'
                );
            }
        }
    }

    public function testWaterIsReportedAsUnsupportedRatherThanMiscalculated(): void
    {
        $meterId = $this->meters->defaultId('wasser');
        $r = $this->switch->analyze('wasser', $meterId, ['today' => '2026-08-03']);

        self::assertFalse($r['supported']);
        self::assertNotNull($r['note'], 'das Drei-Komponenten-Modell braucht eine eigene Rechnung');
        self::assertSame([], $r['candidates']);
    }
}
