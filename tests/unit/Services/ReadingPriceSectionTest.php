<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Services\Mcp\McpHouseholdTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket K3) — Ein Preis je Ablesung trägt einen Abschnitt ohne Arbeitspreis im Vertrag: in den Monatszeilen
 * (Saldo-Karte, Jahresbericht) wie in den Abschnitten von „Rechnung prüfen“ und Abrechnungsjahr (und damit der KI), bei
 * Strom, Gas und Wasser (dort für das Trinkwasser). Eine Regel: Ein Ableseabschnitt nimmt den Preis seiner ersten
 * Ablesung, sonst den seiner letzten (ConsumptionService::intervalReadingPrice). Bis dahin rechneten die Abschnitte nur
 * mit dem Vertragspreis: Seit das Kennzeichen „Preis fehlt“ dem Preis je Ablesung folgt, nannte das Abrechnungsjahr
 * dann ein Guthaben, das es nicht gibt (nur Grundpreis gegen die Abschläge), die Karte daneben eine Nachzahlung.
 *
 * Erfunden, von Hand (Stände an jedem Monatsersten von Januar bis September 2026, 243 Tage, heute 08.10.2026):
 *   Strom  6 kWh je Tag = 1.458 kWh × 27,90 ct (an jeder Ablesung) = 406,78 €; Grundpreis 8 × 7 € = 56 €;
 *          Abschläge 8 × 49 € = 392 €; Saldo +70,78 € (Nachzahlung)
 *   Gas    25 kWh je Tag (2,5 m³ × 10 kWh/m³), 9,30 ct an jeder Ablesung, Vertragspreis 8,10 ct erst ab 01.05.:
 *          Januar bis April 120 Tage = 3.000 kWh × 9,30 ct = 279,00 €; Mai bis August 123 Tage = 3.075 kWh × 8,10 ct
 *          = 249,08 €; zusammen 528,08 €; Abschläge 8 × 70 € = 560 €; Saldo −31,92 € (Guthaben)
 *   Wasser 0,35 m³ je Tag = 85,05 m³; Trinkwasser 2,15 € an jeder Ablesung = 182,86 €, Schmutzwasser 2,89 € laut
 *          Vertrag = 245,79 €; Abschläge 8 × 60 € = 480 €; Saldo −51,35 €
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillingPeriodService::class)]
final class ReadingPriceSectionTest extends McpHouseholdTestCase
{
    private const STAND = '2026-10-08';
    /** bis 2 Cent: Rundung je Monat bzw. Abschnitt (Bekannte Grenze „Arbeitspreis auf den Cent“), mit Rand für Gleitkomma */
    private const CENTS = 0.021;

    /** Zähler mit Ständen an jedem Ersten von Januar bis September und dem Preis `$ct` an jeder Ablesung. */
    private function meter(string $utility, string $id, float $perMonthDay, float $ct): array
    {
        $this->meters($utility, [$id => 'Test']);
        $rows = [];
        foreach (range(1, 9) as $m) {
            $d = sprintf('2026-%02d-01', $m);
            $rows[] = [$d, round($perMonthDay * (int)(new \DateTimeImmutable('2026-01-01'))->diff(new \DateTimeImmutable($d))->days, 3)];
        }
        $this->counters($utility, [$id => $rows]);
        $all = (array)$this->store->read("$utility/readings.json", []);
        foreach ($all as $i => $r) $all[$i]['price_cents'] = $ct;
        $this->store->write("$utility/readings.json", $all);
        return $this->meters->get($utility, $id);
    }

    /** @return array{0:array,1:array,2:array,3:array} Karte (`balance_measured`), Abrechnungsjahr, „Rechnung prüfen“, KI-Zeile */
    private function views(string $utility, array $m): array
    {
        $card = $this->consumption->contractStatus($utility, $m, self::STAND)['contracts'][0]['balance_measured'];
        $year = $this->svc(BillingPeriodService::class)->forTarget($utility, $m, false, self::STAND)['periods'][0];
        $check = $this->consumption->atDate(self::STAND, fn() => $this->consumption->billBreakdown($utility, $m, '2026-01-01', '2026-09-01'))['totals'];
        $ki = $this->tool('get_consumption', ['utility' => $utility, 'meter' => (string)$m['id'], 'granularity' => 'billing_period'], self::STAND)['rows'][0];
        return [$card, $year, $check, $ki];
    }

    public function testElectricityWithAPriceOnEveryReadingOnly(): void
    {
        $m = $this->meter('strom', 'm_s', 6.0, 27.9);
        $this->contracts->create('strom', ['meter_id' => 'm_s', 'provider' => 'Stromwerk Musterstadt', 'start' => '2026-01-01',
            'base_prices' => [['from' => '2026-01-01', 'eur_per_month' => 7.0]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 49.0]]]);
        [$card, $year, $check, $ki] = $this->views('strom', $m);
        // je Monat bzw. Abschnitt auf den Cent gerundet (acht Monate): bis 2 Cent neben der Handrechnung (Bekannte Grenze)
        self::assertEqualsWithDelta(406.78, $card['energy_cost'], self::CENTS, 'Karte');
        self::assertSame($card['energy_cost'], $year['energy_cost'], 'Abrechnungsjahr = Karte (bis dahin 0)');
        self::assertSame($card['energy_cost'], $check['energy_cost'], '„Rechnung prüfen“ = Karte (bis dahin 0)');
        self::assertFalse($check['price_missing'], '„Rechnung prüfen“: der Preis ist da');
        self::assertFalse($year['price_missing']);
        self::assertEqualsWithDelta(70.78, $card['balance'], self::CENTS);
        self::assertSame([$card['balance'], $card['balance']], [$year['balance'], (float)$ki['balance']], 'Karte = Abrechnungsjahr = KI');
        self::assertSame('back_payment', $ki['balance_meaning'] ?? null, 'Nachzahlung, nicht Guthaben (bis dahin −336,00 €)');
        // Jahresbericht, Anhang „Abrechnungsjahre“: dieselben Beträge wie das Abrechnungsjahr, ohne „Preis fehlt“
        $model = $this->svc(\Energietracker\Services\ReportModelService::class)->build(2026, ['today' => self::STAND, 'billing' => true]);
        $row = array_values(array_filter((array)$model['billing'], fn($s) => $s['utility'] === 'strom'))[0]['rows'][0];
        self::assertSame([$this->i18n->number($year['cost'], 2), $this->i18n->number($year['balance'], 2)], [$row[3], $row[6]], 'Anhang = Abrechnungsjahr');
        self::assertStringNotContainsString('‡', $row[0]);
    }

    public function testGasWithTheContractPriceFromMay(): void
    {
        $this->settings->set(['gas_conversion_factors' => [['from' => null, 'kwh_per_m3' => 10.0]]]);
        $m = $this->meter('gas', 'm_g', 2.5, 9.3);
        $this->contracts->create('gas', ['meter_id' => 'm_g', 'provider' => 'Gaswerk Musterstadt', 'start' => '2026-01-01',
            'working_prices' => [['from' => '2026-05-01', 'ct_per_kwh' => 8.1]], 'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 70.0]]]);
        [$card, $year, $check, $ki] = $this->views('gas', $m);
        self::assertEqualsWithDelta(528.08, $card['energy_cost'], self::CENTS, 'Karte');
        self::assertSame($card['energy_cost'], $year['energy_cost'], 'Abrechnungsjahr = Karte');
        self::assertSame($card['energy_cost'], $check['energy_cost'], '„Rechnung prüfen“ = Karte');
        self::assertEqualsWithDelta(-31.92, $year['balance'], self::CENTS);
        self::assertSame($card['balance'], $year['balance'], 'Karte = Abrechnungsjahr');
        self::assertSame($year['balance'], (float)$ki['balance'], 'KI = Abrechnungsjahr');
        // je Abschnitt: Januar mit dem Preis der Ablesung, Mai mit dem Vertragspreis
        $rows = $this->consumption->atDate(self::STAND, fn() => $this->consumption->billBreakdown('gas', $m, '2026-01-01', '2026-09-01'))['rows'];
        self::assertSame([9.3, 8.1], [$rows[0]['ct_per_kwh'], $rows[4]['ct_per_kwh']]);
    }

    public function testWaterWithADrinkingWaterPriceOnTheReadings(): void
    {
        $m = $this->meter('wasser', 'm_w', 0.35, 215.0);
        $this->contracts->create('wasser', ['meter_id' => 'm_w', 'provider' => 'Wasserwerk Musterstadt', 'start' => '2026-01-01',
            'trinkwasser' => ['working_prices' => []],
            'schmutzwasser' => ['basis' => 'trinkwasser', 'working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 289.0]]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 60.0]]]);
        [$card, $year, $check, $ki] = $this->views('wasser', $m);
        // Monatszeilen runden Trinkwasser und Schmutzwasser je Monat (zwei Sparten, acht Monate), die Abschnitte die Summe:
        // bis 8 Cent (Bekannte Grenze „Arbeitspreis auf den Cent“); bis dahin fehlte das Trinkwasser ganz (245,82 €)
        self::assertEqualsWithDelta(182.86 + 245.79, $card['energy_cost'], 0.08, 'Karte: Trinkwasser aus dem Preis je Ablesung');
        self::assertEqualsWithDelta(182.86 + 245.79, $year['energy_cost'], self::CENTS, 'Abrechnungsjahr');
        self::assertSame($year['energy_cost'], $check['energy_cost'], '„Rechnung prüfen“ = Abrechnungsjahr');
        self::assertEqualsWithDelta(-51.35, $year['balance'], self::CENTS);
        self::assertEqualsWithDelta($card['balance'], $year['balance'], 0.08, 'Karte = Abrechnungsjahr bis auf die Rundung je Monat');
        self::assertSame($year['balance'], (float)$ki['balance'], 'KI = Abrechnungsjahr');
        $months = $this->consumption->forMeter('wasser', $m, null, self::STAND);
        self::assertArrayNotHasKey('price_missing', $months[3], 'April: der Preis je Ablesung trägt den Monat');
    }

    /**
     * Die Konvention am Rand: Ein Abschnitt, dessen erste Ablesung keinen Preis hat, nimmt den seiner letzten. Preis nur an
     * den Ablesungen ab 01.05.: Der April (01.04. bis 01.05.) hat den Preis der Ablesung vom 01.05., der März keinen.
     */
    public function testASectionWithoutAPriceAtItsStartTakesTheOneAtItsEnd(): void
    {
        $m = $this->meter('strom', 'm_s', 6.0, 27.9);
        $all = (array)$this->store->read('strom/readings.json', []);
        foreach ($all as $i => $r) if ((string)$r['date'] < '2026-05-01') $all[$i]['price_cents'] = null;
        $this->store->write('strom/readings.json', $all);
        $this->contracts->create('strom', ['meter_id' => 'm_s', 'provider' => 'Stromwerk Musterstadt', 'start' => '2026-01-01',
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 49.0]]]);
        $months = array_column($this->consumption->forMeter('strom', $m, null, self::STAND), null, 'ym');
        self::assertTrue($months['2026-03']['price_missing'] ?? false, 'März ohne Preis');
        self::assertEqualsWithDelta(30 * 6 * 0.279, (float)$months['2026-04']['cost'], 0.01, 'April: Preis der Ablesung vom 01.05.');
        $rows = $this->consumption->atDate(self::STAND, fn() => $this->consumption->billBreakdown('strom', $m, '2026-03-01', '2026-06-01'))['rows'];
        self::assertSame([null, 27.9, 27.9], array_column($rows, 'ct_per_kwh'), '„Rechnung prüfen“: dieselbe Regel je Abschnitt');
        self::assertEqualsWithDelta(30 * 6 * 0.279, (float)$rows[1]['energy_cost'], 0.005);
    }

    /** Die Doku nennt die Regel so, wie die App rechnet (der Fall oben: erster Preis am 01.05.). */
    public function testTheDocsDescribeTheRule(): void
    {
        $want = ['docs/verstehen/00-overview.md' => ['**Preis an der Ablesung**', 'den Preis seiner **ersten Ablesung**', 'den seiner **letzten**',
                    'Ablesung vom 01.05.', '(ab der Ablesung vom 01.04.)', 'im Abrechnungsjahr, in „Rechnung prüfen“'],
                 'docs/en/verstehen/00-overview.md' => ['**price at the reading**', 'the price of its **first reading**', 'the one of its **last**',
                    'reading of 1 May', '(from the reading of 1 April)', 'in the billing year, in “Check bill”']];
        foreach ($want as $file => $phrases) {
            $text = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            $pos = strpos($text, '<!-- doc-claim: reading-price-section');
            self::assertNotFalse($pos, "$file: Marke fehlt");
            $block = str_replace("\n", ' ', substr($text, $pos, 1300));
            foreach ($phrases as $p) self::assertStringContainsString($p, $block, "$file: $p");
        }
    }
}
