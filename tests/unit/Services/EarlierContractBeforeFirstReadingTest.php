<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Services\Mcp\McpHouseholdTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — „Frühere Verträge“: Ein Vertrag, der vor der ersten Ablesung beginnt, rechnet seinen Saldo ab der ersten
 * Ablesung, wie seine Abrechnungsjahre. Vor ihr ist der Verbrauch unbekannt; Grundpreis und Abschläge dieser Monate
 * gegen einen Verbrauch von null ergaben ein Guthaben, das es nicht gibt. Geld und Menge zählen über dieselbe Spanne.
 * Bis dahin begann der Saldo eines früheren Vertrags (außerhalb der laufenden Abrechnungsperiode, ohne gebuchte
 * Rechnung) immer am Vertragsbeginn.
 *
 * Erfunden, von Hand: Strom 6 kWh je Tag, Vertrag bis 31.12.2024 (29 ct, 11 €/Monat, Abschlag 70 €/Monat), Folgevertrag ab
 * 01.01.2025, Stichtag 01.01., heute 02.11.2026. Tageskonvention: Eine Ablesung am Tag D zählt bis D − 1.
 */
#[CoversClass(ConsumptionService::class)]
final class EarlierContractBeforeFirstReadingTest extends McpHouseholdTestCase
{
    private const STAND = '2026-11-02';

    /** @param list<string> $dates Ablesetage; Stand = Tage seit dem ersten × 6 kWh */
    private function twoContracts(string $start, array $dates): array
    {
        $this->meters('strom', ['m_alt' => 'Wohnung']);
        $first = $dates[0];
        $this->counters('strom', ['m_alt' => array_map(fn(string $d) => [$d, 6.0 * (int)(new \DateTimeImmutable($first))->diff(new \DateTimeImmutable($d))->days], $dates)]);
        $this->contracts->create('strom', ['meter_id' => 'm_alt', 'provider' => 'Stadtwerk Nord', 'start' => $start, 'end' => '2024-12-31',
            'working_prices' => [['from' => $start, 'ct_per_kwh' => 29.0]], 'base_prices' => [['from' => $start, 'eur_per_month' => 11.0]],
            'advance_payments' => [['from' => $start, 'amount_eur' => 70.0]]]);
        $this->contracts->create('strom', ['meter_id' => 'm_alt', 'provider' => 'Stadtwerk Süd', 'start' => '2025-01-01',
            'working_prices' => [['from' => '2025-01-01', 'ct_per_kwh' => 31.0]], 'base_prices' => [['from' => '2025-01-01', 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => '2025-01-01', 'amount_eur' => 80.0]]]);
        return $this->meters->get('strom', 'm_alt');
    }

    /** Monatserste von `$from` bis 01.10.2026, davor `$first`, wenn es kein Monatserster ist. @return list<string> */
    private static function dates(string $first): array
    {
        $out = [$first];
        for ($d = new \DateTimeImmutable(substr($first, 0, 7) . '-01'); $d->format('Y-m-d') <= '2026-10-01'; $d = $d->modify('+1 month')) {
            if ($d->format('Y-m-d') > $first) $out[] = $d->format('Y-m-d');
        }
        return $out;
    }

    /**
     * Vertragsbeginn, erste Ablesung, Saldo von Hand (Saldo ab Vertragsbeginn, wie bis dahin):
     *  - Beginn 01.01.2023, erste Ablesung 01.07.2023: 550 Tage (2024 ist ein Schaltjahr) × 6 kWh = 3.300 kWh × 0,29 € = 957,00 €
     *    + 18 × 11 € − 18 × 70 € = −105,00 € (bis dahin −459,00 €: sechs Monate Grundpreis und Abschlag ohne Verbrauch)
     *  - Beginn 01.01.2023, erste Ablesung 15.07.2023: 536 Tage × 6 = 3.216 kWh × 0,29 € = 932,64 €, Grundpreis
     *    11 € × (17/31 + 17) = 193,03 €, Abschläge 70 € × (17/31 + 17) = 1.228,39 €, Saldo −102,72 €
     *  - am Jahreswechsel: Beginn 01.10.2023, erste Ablesung 01.01.2024: 366 Tage × 6 = 2.196 kWh × 0,29 € = 636,84 € + 132 €
     *    − 840 € = −71,16 € (bis dahin −248,16 €)
     *
     * @return array<string, array{string, string, float, float}>
     */
    public static function cases(): array
    {
        return [
            'erste Ablesung am Monatsersten'   => ['2023-01-01', '2023-07-01', 3300.0, -105.00],
            'erste Ablesung mitten im Monat'   => ['2023-01-01', '2023-07-15', 3216.0, -102.72],
            'erste Ablesung am Jahreswechsel'  => ['2023-10-01', '2024-01-01', 2196.0, -71.16],
        ];
    }

    #[DataProvider('cases')]
    public function testTheEarlierContractStartsAtTheFirstReading(string $start, string $first, float $kwh, float $balance): void
    {
        $m = $this->twoContracts($start, self::dates($first));
        $rows = array_values(array_filter($this->consumption->contractStatus('strom', $m, self::STAND)['contracts'], fn($c) => !empty($c['is_past'])));
        self::assertCount(1, $rows);
        $c = $rows[0];
        self::assertSame($first, $c['balance_from'], 'Saldo ab der ersten Ablesung');
        self::assertEqualsWithDelta($balance, $c['current_balance'], 0.005, 'Saldo von Hand');
        self::assertEqualsWithDelta($balance, $c['balance_measured']['balance'], 0.005, 'Saldo am letzten Stand');
        self::assertEqualsWithDelta($kwh, $c['actual_kwh'], 0.05, 'Menge über dieselbe Spanne');
        // Ansicht gegen Ansicht: Karte = Summe der Abrechnungsjahre des Vertrags
        $periods = array_filter($this->svc(BillingPeriodService::class)->forTarget('strom', $m, false, self::STAND)['periods'],
            fn($p) => $p['until'] <= '2024-12-31');
        self::assertEqualsWithDelta(array_sum(array_column($periods, 'balance')), $c['current_balance'], 0.015, 'Karte = Σ Abrechnungsjahre');
        self::assertEqualsWithDelta(array_sum(array_column($periods, 'advances')), $c['advance_paid'], 0.015, 'Abschläge = Σ Abrechnungsjahre');
        // KI: dieselbe Zahl („Frühere Verträge“)
        $ki = $this->tool('get_contracts', ['utility' => 'strom', 'include_past' => true], self::STAND)['targets'][0]['contracts'];
        $kiPast = array_values(array_filter($ki, fn($x) => ($x['start'] ?? '') === $start))[0];
        self::assertEqualsWithDelta($balance, (float)$kiPast['status']['balance_today']['value'], 0.005, 'KI');
    }

    /**
     * Eine Analyse-Zäsur nach der ersten Ablesung (01.03.2024) trennt die Auswertung, nicht das Geld: Der Saldo beginnt an der
     * ersten Ablesung, mit allen gemessenen Monaten davor (wie im ersten Fall −105,00 €).
     */
    public function testACutAfterTheFirstReadingDoesNotMoveTheStart(): void
    {
        $m = $this->twoContracts('2023-01-01', self::dates('2023-07-01'));
        $all = $this->store->read('strom/meters.json', []);
        $all[0]['baseline_events'] = [['date' => '2024-03-01', 'label' => 'Neue Geräte']];
        $this->store->write('strom/meters.json', $all);
        $c = array_values(array_filter($this->consumption->contractStatus('strom', $this->meters->get('strom', 'm_alt'), self::STAND)['contracts'],
            fn($c) => !empty($c['is_past'])))[0];
        self::assertSame('2023-07-01', $c['balance_from']);
        self::assertEqualsWithDelta(-105.00, $c['current_balance'], 0.005);
    }

    /**
     * Rand davor: Ein Vertrag, der ganz vor der ersten Ablesung endet (2022, erste Ablesung 01.07.2023), hat keinen gemessenen
     * Tag. Er nennt keinen Saldo (bis dahin 12 × 11 € − 12 × 70 € = −708 € Guthaben) und sagt, dass Daten fehlen. Ebenso ein
     * Zähler ganz ohne Ablesung.
     */
    public function testAContractEntirelyBeforeTheFirstReadingNamesNoBalance(): void
    {
        $m = $this->twoContracts('2023-01-01', self::dates('2023-07-01'));
        $this->contracts->create('strom', ['meter_id' => 'm_alt', 'provider' => 'Stadtwerk West', 'start' => '2022-01-01', 'end' => '2022-12-31',
            'working_prices' => [['from' => '2022-01-01', 'ct_per_kwh' => 27.0]], 'base_prices' => [['from' => '2022-01-01', 'eur_per_month' => 11.0]],
            'advance_payments' => [['from' => '2022-01-01', 'amount_eur' => 70.0]]]);
        $rows = array_column($this->consumption->contractStatus('strom', $m, self::STAND)['contracts'], null, 'start');
        self::assertNull($rows['2022-01-01']['current_balance'], 'kein Guthaben aus Abschlägen ohne Verbrauch');
        self::assertNull($rows['2022-01-01']['projected_end_balance']);
        self::assertSame('too_few_readings', $rows['2022-01-01']['projection_missing'] ?? null);
        self::assertEqualsWithDelta(-105.00, $rows['2023-01-01']['current_balance'], 0.005, 'der Vertrag mit Ablesungen bleibt');

        $this->meters('strom', ['m_leer' => 'Ohne Ablesung']);
        $this->counters('strom', []);
        $this->contracts->create('strom', ['meter_id' => 'm_leer', 'provider' => 'Stadtwerk West', 'start' => '2024-01-01', 'end' => '2024-12-31',
            'auto_renews' => false, 'working_prices' => [['from' => '2024-01-01', 'ct_per_kwh' => 27.0]], 'base_prices' => [['from' => '2024-01-01', 'eur_per_month' => 11.0]],
            'advance_payments' => [['from' => '2024-01-01', 'amount_eur' => 70.0]]]);
        $c = $this->consumption->contractStatus('strom', $this->meters->get('strom', 'm_leer'), self::STAND)['contracts'][0];
        self::assertSame([null, 'too_few_readings'], [$c['current_balance'], $c['projection_missing'] ?? null], 'Zähler ohne Ablesung');
    }

    /**
     * Schlussrechnung: Die gebuchte Rechnung des früheren Vertrags (01.07.2023 bis 31.12.2024, Guthaben 105,00 €) deckt sein
     * Ende. Er ist abgerechnet: 0,00 €, ausgeglichen, `settled_until` 31.12.2024, der Saldo beginnt am Tag danach, kein
     * Saldo am letzten Stand daneben (EarlierContractSettledByBillTest). Eine Rechnung, die vor der ersten Ablesung endet
     * (01.01. bis 30.06.2023, Nachzahlung 20,00 € am 15.08.2023), bleibt draußen: Ohne die Schlussrechnung rechnet der Saldo
     * weiter ab der ersten Ablesung, −105,00 €, ihr Ergebnis zählt nicht.
     */
    public function testAFinalBillCoveringTheEndSettlesAndABillBeforeTheFirstReadingStaysOut(): void
    {
        $m = $this->twoContracts('2023-01-01', self::dates('2023-07-01'));
        $all = $this->store->read('strom/contracts.json', []);
        foreach ($all as &$c) {
            if (($c['start'] ?? '') !== '2023-01-01') continue;
            $c['special_payments'] = [
                ['id' => 'sp_schluss', 'date' => '2025-02-10', 'kind' => 'rueckzahlung_mit', 'amount_eur' => 105.0],
                ['id' => 'sp_davor', 'date' => '2023-08-15', 'kind' => 'nachzahlung_mit', 'amount_eur' => 20.0],
            ];
            $cid = (string)$c['id'];
        }
        unset($c);
        $this->store->write('strom/contracts.json', $all);
        $this->store->write('strom/bills.json', [
            ['id' => 'b_schluss', 'meter_id' => 'm_alt', 'contract_id' => $cid, 'period_from' => '2023-07-01', 'period_to' => '2024-12-31',
                'issued_on' => '2025-01-20', 'special_payment_id' => 'sp_schluss'],
            ['id' => 'b_davor', 'meter_id' => 'm_alt', 'contract_id' => $cid, 'period_from' => '2023-01-01', 'period_to' => '2023-06-30',
                'issued_on' => '2023-08-01', 'special_payment_id' => 'sp_davor'],
        ]);
        $past = fn() => array_values(array_filter($this->consumption->contractStatus('strom', $this->meters->get('strom', 'm_alt'), self::STAND)['contracts'],
            fn($c) => !empty($c['is_past'])))[0];
        $c = $past();
        self::assertSame('2024-12-31', $c['settled_until'] ?? null, 'abgerechnet mit der Schlussrechnung');
        self::assertSame('2025-01-01', $c['balance_from'], 'Saldo ab dem Tag nach der Rechnung');
        self::assertSame([0.0, 0.0, 'balanced'], [(float)$c['current_balance'], (float)$c['projected_end_balance'], $c['verdict']]);
        self::assertArrayNotHasKey('balance_measured', $c, 'kein zweiter Saldo neben dem abgerechneten');
        // ohne die Schlussrechnung: Die Rechnung vor der ersten Ablesung bleibt draußen, mit ihrem Ergebnis
        $this->store->write('strom/bills.json', array_values(array_filter($this->store->read('strom/bills.json', []), fn($b) => $b['id'] === 'b_davor')));
        $all = $this->store->read('strom/contracts.json', []);
        foreach ($all as &$x) if ((string)$x['id'] === $cid) $x['special_payments'] = array_values(array_filter($x['special_payments'], fn($sp) => $sp['id'] === 'sp_davor'));
        unset($x);
        $this->store->write('strom/contracts.json', $all);
        $c = $past();
        self::assertArrayNotHasKey('settled_until', $c);
        self::assertSame('2023-07-01', $c['balance_from'], 'Saldo ab der ersten Ablesung');
        self::assertEqualsWithDelta(-105.00, $c['current_balance'], 0.005, 'die Rechnung vor der ersten Ablesung zählt nicht');
        self::assertEqualsWithDelta(-105.00, $c['balance_measured']['balance'], 0.005, 'Saldo am letzten Stand');
    }
    /** Beginnt der Vertrag nach der ersten Ablesung, bleibt der Vertragsbeginn der Beginn (Gegenprobe). */
    public function testAContractStartingAfterTheFirstReadingKeepsItsStart(): void
    {
        $m = $this->twoContracts('2023-07-01', self::dates('2023-01-01'));
        $c = array_values(array_filter($this->consumption->contractStatus('strom', $m, self::STAND)['contracts'], fn($c) => !empty($c['is_past'])))[0];
        self::assertSame('2023-07-01', $c['balance_from']);
        self::assertEqualsWithDelta(-105.00, $c['current_balance'], 0.005, 'dieselben 18 Monate wie im ersten Fall');
    }
}
