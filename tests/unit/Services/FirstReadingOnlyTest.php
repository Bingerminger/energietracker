<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\ConsumptionService;
use Energietracker\Services\SummaryService;
use Energietracker\Tests\Services\Mcp\McpHouseholdTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Ein neuer Vertrag mit nur dem Anfangsstand: Ohne gemessenen Verbrauch nennt die App kein erwartetes Guthaben
 * und schlägt keinen Abschlag vor. Sie sagt, dass noch Daten fehlen (`projection_missing`), in Vertragsstatus,
 * `/api/summary` und KI gleich. Bis dahin rechnete der Vertragsstatus den Rest des Jahres mit einem Verbrauch von null:
 * „erwartet“ = Grundpreis − Abschläge, also ein Guthaben in Höhe fast aller Abschläge, und ein Vorschlag von 0 €.
 *
 * Praxis (StromGVV/GasGVV § 13 Abs. 1): Ohne abgerechneten Zeitraum bemisst der Versorger den Abschlag nach dem
 * durchschnittlichen Verbrauch vergleichbarer Kunden. Diese Zahl hat die App nicht; sie nennt darum keine.
 *
 * Erfunden: Vertrag ab 01.02.2026 (31 ct/kWh, 13 €/Monat, Abschlag 85 €/Monat), Stichtag 01.01.
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(SummaryService::class)]
final class FirstReadingOnlyTest extends McpHouseholdTestCase
{
    private function newContract(string $utility, array $readings, string $start = '2026-02-01'): array
    {
        $this->meters($utility, ["m_$utility" => 'Neu']);
        $this->counters($utility, ["m_$utility" => $readings]);
        $price = $utility === 'gas' ? ['working_prices' => [['from' => $start, 'ct_per_kwh' => 11.0]]] : ['working_prices' => [['from' => $start, 'ct_per_kwh' => 31.0]]];
        $this->contracts->create($utility, ['meter_id' => "m_$utility", 'provider' => 'Neuwerk Musterstadt', 'start' => $start] + $price + [
            'base_prices' => [['from' => $start, 'eur_per_month' => 13.0]], 'advance_payments' => [['from' => $start, 'amount_eur' => 85.0]]]);
        return $this->meters->get($utility, "m_$utility");
    }

    /** @return array<string, array{string, list<array{0:string,1:float}>, string}> Art, Stände, Stichtag */
    public static function withoutConsumption(): array
    {
        return [
            'Strom, nur der Anfangsstand'              => ['strom', [['2026-02-01', 4000.0]], '2026-03-20'],
            'Gas, nur der Anfangsstand'                => ['gas', [['2026-02-01', 900.0]], '2026-05-20'],
            // ein zweiter Stand nach 9 Tagen: zu kurz für eine Tagesrate je Monat, der Rest des Jahres wäre wieder null
            'Strom, zweiter Stand nach neun Tagen'     => ['strom', [['2026-02-01', 4000.0], ['2026-02-10', 4060.0]], '2026-03-20'],
        ];
    }

    #[DataProvider('withoutConsumption')]
    public function testNoExpectedBalanceAndNoSuggestionWithoutMeasuredConsumption(string $u, array $readings, string $day): void
    {
        $m = $this->newContract($u, $readings);
        $c = $this->consumption->contractStatus($u, $m, $day)['contracts'][0];
        self::assertNull($c['projected_end_balance'], 'kein erwartetes Guthaben aus einem Verbrauch von null');
        self::assertNull($c['verdict']);
        self::assertNull($c['suggested_advance'], 'kein Vorschlag von 0 €');
        self::assertNull($c['advance_ongoing']);
        self::assertSame('too_few_readings', $c['projection_missing'] ?? null, 'sagt, dass Daten fehlen');
        // /api/summary (Home Assistant) und KI sagen dasselbe
        $s = $this->svc(SummaryService::class)->build($u, null, $day)['meters'][0]['contract'];
        self::assertSame([null, null, null, 'too_few_readings'], [$s['projected_end_balance'], $s['verdict'], $s['suggested_advance'], $s['projection_missing'] ?? null]);
        $ki = $this->tool('get_contracts', ['utility' => $u], $day)['targets'][0]['contracts'][0]['status'];
        self::assertArrayNotHasKey('expected_balance', $ki, 'KI ohne erwarteten Saldo');
        self::assertArrayNotHasKey('suggested_advance', $ki);
    }

    /**
     * „Saldo heute“ ohne gemessenen Verbrauch: Grundpreis minus Abschläge gegen einen Verbrauch von null für die Tage seit dem
     * Stand (Strom, heute 20.03.2026: Grundpreis 13 € × (1 + 19/31) = 20,97 €, die Raten vom 01.02. und 01.03. 170,00 €,
     * Arbeit 0 €: −149,03 €). Die Karte zeigt ihn bewusst nicht. KI (`get_contracts` und `get_overview`) nennt ihn
     * nicht als Guthaben, sondern „noch zu wenig Ablesungen“, Herkunft angenommen; `/api/summary` setzt `contract.balance`
     * auf null (Home Assistant: unbekannt). Bis dahin: KI „Guthaben von …“ (eingetragen) und ein Sensor mit Guthaben.
     */
    #[DataProvider('withoutConsumption')]
    public function testNoCreditTodayWithoutMeasuredConsumption(string $u, array $readings, string $day): void
    {
        $m = $this->newContract($u, $readings);
        $c = $this->consumption->contractStatus($u, $m, $day)['contracts'][0];
        self::assertLessThan(0.0, (float)$c['current_balance'], 'der Stand des Vertragsstatus bleibt (Grundpreis − Abschläge)');
        $s = $this->svc(SummaryService::class)->build($u, null, $day)['meters'][0]['contract'];
        self::assertNull($s['balance'], '/api/summary: kein Saldo aus einem Verbrauch von null');
        self::assertSame('too_few_readings', $s['projection_missing'] ?? null);
        $contracts = $this->tool('get_contracts', ['utility' => $u], $day)['targets'][0]['contracts'][0]['status']['balance_today'];
        $overview = self::firstKey($this->tool('get_overview', ['utility' => $u], $day), 'balance_today');
        foreach (['get_contracts' => $contracts, 'get_overview' => $overview] as $tool => $b) {
            self::assertIsArray($b, "$tool: balance_today");
            self::assertSame('too_few_readings', $b['meaning'], "$tool: kein Guthaben");
            self::assertSame('assumed', $b['basis'], "$tool: Herkunft angenommen (Verbrauch null seit dem Stand)");
            self::assertStringContainsString('noch zu wenig ablesungen', mb_strtolower($b['text']), "$tool: Satz");
            self::assertStringNotContainsString('Guthaben von', $b['text'], "$tool: kein Betrag als Guthaben");
        }
        if ($u === 'strom' && count($readings) === 1) self::assertEqualsWithDelta(-149.03, (float)$contracts['value'], 0.005, 'Betrag von Hand');
    }

    /** Erster Treffer eines Schlüssels in einer verschachtelten Antwort. */
    private static function firstKey(array $a, string $key): mixed
    {
        foreach ($a as $k => $v) {
            if ($k === $key) return $v;
            if (is_array($v) && ($hit = self::firstKey($v, $key)) !== null) return $hit;
        }
        return null;
    }

    /**
     * Die Nachbarn sagen dasselbe: Jahresbericht (Abschnitt „Abrechnung“, Ampel „Abrechnung“) nennt keinen Betrag und nicht
     * „Keine Abschläge“; die Werkstatt rechnet keinen Saldo zur Abrechnung aus null (billingFromStatus ohne Ergebnis).
     */
    public function testTheReportAndTheWorkshopSayTheSame(): void
    {
        $m = $this->newContract('strom', [['2026-02-01', 4000.0]]);
        $model = $this->svc(\Energietracker\Services\ReportModelService::class)->build(2026, ['today' => '2026-03-20']);
        $bal = $model['money']['balances'][0] ?? null;
        self::assertNotNull($bal, 'der Vertrag steht im Abschnitt „Abrechnung“');
        self::assertNull($bal['projected_end_balance']);
        self::assertStringContainsString('noch kein Betrag', $bal['text']);
        $light = array_values(array_filter($model['headline']['lights'], fn($l) => $l['key'] === 'billing'))[0];
        self::assertSame('Abschläge: noch zu wenig Ablesungen', $light['word'], 'nicht „Keine Abschläge“');
        $rows = $this->consumption->contractStatus('strom', $m, '2026-03-20')['contracts'];
        self::assertNull(\Energietracker\Services\ScenarioService::billingFromStatus($rows, [], [], [], '2026-03-20'), 'Werkstatt: kein Saldo aus null');
    }

    /**
     * Gegenprobe am Rand: mit einem gemessenen Monat (Stände 01.02. und 01.03., 300 kWh) rechnet die Karte wie bisher einen
     * erwarteten Saldo und nennt keinen Hinweis.
     */
    public function testWithAMeasuredMonthTheExpectationStays(): void
    {
        $m = $this->newContract('strom', [['2026-02-01', 4000.0], ['2026-03-01', 4300.0]]);
        $c = $this->consumption->contractStatus('strom', $m, '2026-03-20')['contracts'][0];
        self::assertIsFloat($c['projected_end_balance']);
        self::assertArrayNotHasKey('projection_missing', $c);
    }
}
