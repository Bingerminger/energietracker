<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Services\Mcp\McpHouseholdTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 (Paket K3) — Vertragsende ohne Folgevertrag (Auszug): Karte, „Saldo heute“, Abrechnungsjahr, „Rechnung prüfen“
 * und KI nennen für den letzten Vertrag dieselbe Spanne und denselben Betrag.
 *
 *  - „Saldo heute“ (Tabelle „Frühere Verträge“, KI `balance_today`) schätzte nach einer Schlussablesung am letzten
 *    Vertragstag noch einen Tag Verbrauch dazu, obwohl die Ablesung am letzten Tag eines Zeitraums dessen Endstand ist
 *    (`$cap` im Abrechnungsjahr, „Saldo am …“ der Karte).
 *  - Das Abrechnungsjahr lief bis zur Ablesung nach dem Ende (Übergabe einige Tage später): mehr Tage und mehr Menge als
 *    Karte und Schlussrechnung, bei gleichem Geld. Jetzt endet es mit dem letzten Vertrag; die Tage danach rechnet kein
 *    Vertrag.
 *
 * Erfunden, von Hand: Strom 9 kWh je Tag, Stände an jedem Monatsersten ab 01.02.2026; Vertrag 01.02. bis 20.08.2026
 * ohne Folgevertrag (`auto_renews: false`), 31 ct/kWh, Grundpreis 8,50 €/Monat, Abschlag 71 €/Monat; heute 05.10.2026.
 * Tageskonvention: Ablesung am Tag D zählt bis D − 1; am letzten Tag des Vertrags ist sie sein Endstand.
 *   Grundpreis  8,50 € × (6 + 20/31) = 56,48 €        Abschläge  71 € × (6 + 20/31) = 471,81 €
 *   Schlussablesung 20.08. (letzter Tag):  200 Tage × 9 kWh = 1.800 kWh × 0,31 € = 558,00 €, Saldo 142,67 €
 *   Übergabe 21.08. (Tag danach):          201 Tage × 9 kWh = 1.809 kWh × 0,31 € = 560,79 €, Saldo 145,46 €
 *   Übergabe 26.08. (sechs Tage danach):   bis 20.08. wie am Tag danach, 1.809 kWh, Saldo 145,46 €
 *     (bis dahin Abrechnungsjahr bis 25.08., 206 Tage, 1.854 kWh)
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillingPeriodService::class)]
final class ContractEndWithoutSuccessorTest extends McpHouseholdTestCase
{
    private const STAND = '2026-10-05';

    private function flat(string $last): array
    {
        $this->meters('strom', ['m_whg' => 'Wohnung']);
        $rows = [];
        foreach ([...array_map(fn($m) => sprintf('2026-%02d-01', $m), range(2, 8)), $last] as $d) {
            $rows[] = [$d, 9.0 * (int)(new \DateTimeImmutable('2026-02-01'))->diff(new \DateTimeImmutable($d))->days];
        }
        $this->counters('strom', ['m_whg' => $rows]);
        $this->contracts->create('strom', ['meter_id' => 'm_whg', 'provider' => 'Stromwerk Musterstadt', 'tariff_name' => 'Wohnung',
            'start' => '2026-02-01', 'end' => '2026-08-20', 'auto_renews' => false,
            'working_prices' => [['from' => '2026-02-01', 'ct_per_kwh' => 31.0]], 'base_prices' => [['from' => '2026-02-01', 'eur_per_month' => 8.5]],
            'advance_payments' => [['from' => '2026-02-01', 'amount_eur' => 71.0]]]);
        return $this->meters->get('strom', 'm_whg');
    }

    /** @return array{card:array, bm:array, year:array, check:array, ki:array, kiRow:array} */
    private function views(string $last): array
    {
        $m = $this->flat($last);
        $card = $this->consumption->contractStatus('strom', $m, self::STAND)['contracts'][0];
        $year = $this->svc(BillingPeriodService::class)->forTarget('strom', $m, false, self::STAND)['periods'][0];
        $check = $this->consumption->atDate(self::STAND, fn() => $this->consumption->billBreakdown('strom', $m, '2026-02-01', '2026-08-21'))['totals'];
        $ki = $this->tool('get_contracts', ['utility' => 'strom', 'include_past' => true], self::STAND)['targets'][0]['contracts'][0]['status'];
        $kiRow = $this->tool('get_consumption', ['utility' => 'strom', 'meter' => 'm_whg', 'granularity' => 'billing_period'], self::STAND)['rows'][0];
        return ['card' => $card, 'bm' => $card['balance_measured'], 'year' => $year, 'check' => $check, 'ki' => $ki, 'kiRow' => $kiRow];
    }

    /** @return array<string, array{string, float, float, ?string}> letzte Ablesung, Menge, Saldo, `reading_on` der KI */
    public static function finalReadings(): array
    {
        return [
            'Schlussablesung am letzten Vertragstag' => ['2026-08-20', 1800.0, 142.67, '2026-08-20'],
            'Übergabe am Tag danach'                 => ['2026-08-21', 1809.0, 145.46, '2026-08-21'],
            'Übergabe sechs Tage danach'             => ['2026-08-26', 1809.0, 145.46, null],
        ];
    }

    #[DataProvider('finalReadings')]
    public function testTheLastContractEndsEveryViewOnItsLastDay(string $last, float $kwh, float $balance, ?string $readingOn): void
    {
        $v = $this->views($last);
        // Abrechnungsjahr: bis zum Vertragsende, nicht bis zur Ablesung danach
        self::assertSame(['2026-08-20', 201], [$v['year']['as_of'], $v['year']['days']], 'Ende des Abrechnungsjahrs');
        self::assertEqualsWithDelta($kwh, $v['year']['kwh'], 0.05, 'Menge des Abrechnungsjahrs');
        self::assertEqualsWithDelta($kwh, $v['check']['kwh'], 0.05, '„Rechnung prüfen“ der Schlussrechnung 01.02. bis 20.08.');
        self::assertSame([56.48, 471.81], [$v['year']['fixed_cost'], $v['year']['advances']]);
        // Karte „Saldo am …“, „Saldo heute“ (Frühere Verträge), Abrechnungsjahr, KI: derselbe Saldo
        self::assertEqualsWithDelta($balance, $v['bm']['balance'], 0.005, 'Karte');
        self::assertEqualsWithDelta($balance, $v['card']['current_balance'], 0.005, '„Saldo heute“ eines beendeten Vertrags');
        self::assertEqualsWithDelta($v['bm']['cost'], $v['card']['cost_to_date'], 0.005, 'Kosten in „Frühere Verträge“');
        self::assertEqualsWithDelta($balance, $v['year']['balance'], 0.005, 'Abrechnungsjahr');
        self::assertEqualsWithDelta($balance, (float)$v['ki']['balance_today']['value'], 0.005, 'KI balance_today');
        self::assertEqualsWithDelta($balance, (float)$v['kiRow']['balance'], 0.005, 'KI Abrechnungsjahr');
        // KI: ein Ablesetag nur, wo eine Ablesung den Endstand bildet; sonst ist das Ende verteilt
        self::assertSame($readingOn, $v['kiRow']['reading_on'] ?? null, 'reading_on');
        self::assertSame($readingOn === null, !empty($v['kiRow']['end_distributed']));
    }

    /**
     * Nächste Termine (ganze Rate am 15.): Ein Vertrag, der am 20.08. endet und sich nicht verlängert, hat am 16.08. keinen
     * mehr; derselbe Vertrag ohne `auto_renews: false` verlängert sich und nennt den 15.09. Eine Regel: das Ende der
     * Abdeckung (ContractService::coverage `to`), nicht das eingetragene Ende.
     */
    public function testNextDatesEndWithTheCoverageNotWithTheEnteredEnd(): void
    {
        $this->settings->set(['advance_counting' => 'due_day', 'advance_due_day' => 15]);
        $m = $this->flat('2026-08-01');
        $next = fn() => $this->consumption->contractStatus('strom', $m, '2026-08-16')['contracts'][0]['next_advance_dates'] ?? [];
        self::assertSame([], $next(), 'endet ohne Verlängerung: keine Termine nach dem 20.08.');
        $c = $this->contracts->list('strom')[0];
        $this->contracts->update('strom', (string)$c['id'], ['auto_renews' => null]);
        self::assertSame('2026-09-15', $next()[0] ?? null, 'verlängert sich: der Termin nach dem Ende bleibt');
    }

    /** Endet die letzte Ablesung zwei Tage vor dem Vertrag, bleibt „Saldo heute“ eine Schätzung über die zwei Tage. */
    public function testTwoDaysBeforeTheEndStayEstimated(): void
    {
        $v = $this->views('2026-08-19');
        self::assertSame('2026-08-18', $v['year']['as_of'], 'Ablesung am 19.08. zählt bis 18.08.');
        // „Saldo am 19.08.“: 199 Tage × 9 kWh × 0,31 € = 555,21 € + 8,50 € × (6 + 18/31) − 71 € × (6 + 18/31)
        self::assertEqualsWithDelta(555.21 + 55.94 - 467.23, $v['bm']['balance'], 0.01);
        self::assertEqualsWithDelta($v['bm']['balance'], $v['year']['balance'], 0.005, 'Abrechnungsjahr = Karte');
        // „Saldo heute“ zählt die zwei Tage dazu: Verbrauch (geschätzt), Grundpreis und Abschlag für 19. und 20.08.
        $twoDays = 2 * (8.5 - 71.0) / 31;
        self::assertGreaterThan($v['bm']['balance'] + $twoDays, $v['card']['current_balance'], 'geschätzter Verbrauch der zwei Tage');
        self::assertLessThan($v['bm']['balance'] + $twoDays + 2 * 2 * 9 * 0.31, $v['card']['current_balance']);
    }
}
