<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ReportModelService;
use Energietracker\Services\TenancyBudgetService;
use Energietracker\Services\TenancyService;
use Energietracker\Tests\Services\Mcp\McpHouseholdTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Der Jahresbericht ohne Preis: Fehlt einem Vertrag der Arbeitspreis (ganz oder in Monaten des Jahres), verspricht
 * der Bericht kein Guthaben und nennt für diese Art keine Energiekosten. Er sagt „Preis fehlt“ wie Saldo-Karte,
 * Abrechnungsjahr und KI. Ebenso ein Mietverhältnis ohne Preise: keine ganze Vorauszahlung als Guthaben.
 * Bis dahin stand im Abschnitt „Abrechnung“ ein Guthaben aus Grundpreis minus Abschlägen und als Energiekosten nur der
 * Grundpreis.
 *
 * Erfunden: Strom ab 01.05.2026, Grundpreis 9 €/Monat, Abschlag 55 €/Monat, 170 kWh je Monat, heute 02.11.2026.
 * Von Hand: Ohne Arbeitspreis kennt die App die Arbeitskosten von rund 6 × 170 kWh nicht; ab rund 27 ct je kWh wäre aus dem
 * scheinbaren Guthaben (8 × 46 € zum Jahresende) schon eine Nachzahlung geworden. Eine Zahl lässt sich nicht nennen.
 */
#[CoversClass(ReportModelService::class)]
#[CoversClass(TenancyBudgetService::class)]
final class ReportPriceMissingTest extends McpHouseholdTestCase
{
    private const STAND = '2026-11-02';

    private function strom(?string $priceFrom): void
    {
        $this->meters('strom', ['m_strom' => 'Wohnung']);
        $rows = [];
        foreach (['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-10-25'] as $i => $d) {
            $rows[] = [$d, 3000.0 + ($d === '2026-10-25' ? 5 * 170 + 170 * 24 / 31 : $i * 170)];
        }
        $this->counters('strom', ['m_strom' => $rows]);
        $this->contracts->create('strom', ['meter_id' => 'm_strom', 'provider' => 'Stromwerk Musterstadt', 'start' => '2026-05-01',
            'working_prices' => $priceFrom === null ? [] : [['from' => $priceFrom, 'ct_per_kwh' => 32.0]],
            'base_prices' => [['from' => '2026-05-01', 'eur_per_month' => 9.0]], 'advance_payments' => [['from' => '2026-05-01', 'amount_eur' => 55.0]]]);
    }

    private function report(): array
    {
        return $this->svc(ReportModelService::class)->build(2026, ['today' => self::STAND]);
    }

    /** @return array<string, array{?string}> */
    public static function prices(): array
    {
        return ['ohne Arbeitspreis' => [null], 'Arbeitspreis erst ab 15.07.' => ['2026-07-15']];
    }

    #[DataProvider('prices')]
    public function testTheReportPromisesNoCreditAndNamesNoEnergyCost(?string $priceFrom): void
    {
        $this->strom($priceFrom);
        $status = $this->consumption->contractStatus('strom', $this->meters->get('strom', 'm_strom'), self::STAND)['contracts'][0];
        self::assertTrue($status['price_missing'], 'Vorbedingung: der Vertragsstatus sagt „Preis fehlt“');
        $m = $this->report();
        $money = $m['money'] ?? null;
        self::assertIsArray($money, 'der Abschnitt bleibt, er sagt, warum kein Betrag dasteht');
        $bal = $money['balances'][0] ?? null;
        self::assertNotNull($bal);
        self::assertNull($bal['projected_end_balance'], 'kein erwarteter Saldo');
        self::assertStringContainsString('Preis fehlt', $bal['text']);
        self::assertStringNotContainsString('Guthaben', $bal['text']);
        self::assertNull($bal['advance_text'], 'kein Rat zum Abschlag');
        self::assertSame([], $bal['path'], 'kein Saldo-Verlauf');
        self::assertStringContainsString('Preis fehlt', $money['total']['sentence']);
        self::assertStringNotContainsString('Energiekosten 2026', $money['total']['sentence'], 'keine Energiekosten, die nur der Grundpreis sind');
        self::assertSame([], $money['by_utility']);
        self::assertNotContains('cost', array_column($m['headline']['tiles'], 'key'), 'keine Kachel „Kosten“');
        $page = array_values(array_filter($m['pages'] ?? [], fn($p) => ($p['utility'] ?? '') === 'strom'))[0] ?? null;
        self::assertNotNull($page);
        self::assertSame('price_missing', $page['cost']['basis']);
        self::assertStringContainsString('Preis fehlt', $page['cost']['text']);
        // Auf einen Blick und Empfehlungen: kein Guthaben und kein „Abschlag senken“
        $light = array_values(array_filter($m['headline']['lights'] ?? [], fn($l) => $l['key'] === 'billing'))[0] ?? null;
        self::assertNotNull($light);
        self::assertStringNotContainsString('Guthaben', (string)$light['detail']);
        self::assertSame('Abschläge: Preis fehlt', $light['word'], 'nicht „Keine Abschläge“');
        foreach (['tasks', 'infos', 'successes'] as $k) {
            foreach ((array)($m[$k] ?? []) as $a) self::assertStringNotContainsString('Abschlag', (string)json_encode($a, JSON_UNESCAPED_UNICODE), "keine Empfehlung zum Abschlag ($k)");
        }
    }

    /** Daneben eine Art mit Preis (Gas, 10 ct, 15 €/Monat): Ihre Kosten bleiben, Strom steht als „Preis fehlt“ im Satz. */
    public function testAnotherUtilityKeepsItsCost(): void
    {
        $this->strom(null);
        $this->meters('gas', ['m_gas' => 'Therme']);
        $this->counters('gas', ['m_gas' => self::monthly('2026-01', '2026-10', 120.0, 500.0)]);
        $this->contracts->create('gas', ['meter_id' => 'm_gas', 'provider' => 'Gaswerk Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-01-01', 'ct_per_kwh' => 10.0]], 'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 15.0]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 120.0]]]);
        $money = $this->report()['money'];
        self::assertSame(['gas'], array_column($money['by_utility'], 'utility'), 'nur die Art mit Preis');
        self::assertStringContainsString('Preis fehlt', $money['total']['sentence']);
        self::assertStringContainsString('Strom', $money['total']['sentence']);
    }

    // ── Mietverhältnis ──────────────────────────────────────────────────

    private function tenancy(array $extra = []): array
    {
        $t = (new TenancyService($this->store, $this->meters, $this->i18n))->create($extra + ['label' => 'Wohnung Nord', 'start' => '2025-04-01',
            'billing_anchor' => '01-01', 'prepayments' => [['from' => '2025-04-01', 'heating_eur_month' => 0, 'operating_eur_month' => 130.0]]]);
        return (new TenancyBudgetService(new TenancyService($this->store, $this->meters, $this->i18n), $this->meters, $this->consumption, $this->i18n))
            ->budget((string)$t['id'], self::STAND);
    }

    /** Nur Vorauszahlung, keine Preise, keine Zähler, keine pauschalen Kosten: Die Kosten sind unbekannt, kein Guthaben von 12 × 130 €. */
    public function testATenancyWithoutPricesPromisesNoCredit(): void
    {
        $b = $this->tenancy();
        self::assertTrue($b['price_missing'] ?? false, 'Budget: Preis fehlt');
        $text = (string)json_encode($this->report(), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Nebenkosten-Guthaben', $text, 'keine Empfehlung „Guthaben erwartet“');
        self::assertStringNotContainsString('ein Guthaben von', $text, 'nicht die ganze Vorauszahlung als Guthaben');
        self::assertStringContainsString('Die Kosten kennt die App nicht', $text);
    }

    /** Ein Kaltwasserzähler ohne Preis: ebenso. */
    public function testATenancyWithAMeterWithoutPrice(): void
    {
        $this->meters('wasser', ['m_kalt' => 'Kaltwasser']);
        $this->counters('wasser', ['m_kalt' => self::monthly('2025-04', '2026-10', 4.0, 100.0)]);
        $b = $this->tenancy(['meter_ids' => ['cold_water' => ['m_kalt']]]);
        self::assertTrue($b['price_missing'] ?? false);
    }

    /** Gegenprobe: nur pauschale Kosten (1.200 € im Jahr) sind bekannte Kosten; das Budget rechnet wie bisher. */
    public function testFixedCostsAloneAreKnown(): void
    {
        $b = $this->tenancy(['fixed_costs' => [['from' => '2025-04-01', 'label' => 'Betriebskosten', 'eur_per_year' => 1200.0]]]);
        self::assertArrayNotHasKey('price_missing', $b);
        // 2026: 12 × 100 € erwartet, 12 × 130 € gezahlt → 360 € Guthaben
        self::assertEqualsWithDelta(-360.0, $b['projected_result_eur'], 0.01);
    }
}
