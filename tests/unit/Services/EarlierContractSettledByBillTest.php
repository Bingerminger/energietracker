<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Services\Mcp\McpHouseholdTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.13.0 — Ein früherer Vertrag rechnet seinen Saldo wie ein laufender ab dem Tag nach seiner letzten gebuchten
 * Rechnung; gebuchte Ergebnisse zählen dann nicht mit. Deckt eine gebuchte Rechnung das Vertragsende, steht er bei
 * 0,00 € (ausgeglichen, `settled_until` = letzter Tag der Rechnung). Deckt sie nur einen Teil, bleibt der Rest danach
 * offen. Eine Rechnung ganz vor der ersten Ablesung bleibt draußen. Die Abweichung zwischen Rechnung und App steht unter
 * „Rechnung prüfen“, nicht im Saldo. Bis dahin begann der Saldo eines früheren Vertrags an der ersten Ablesung und nahm
 * das Ergebnis jeder Rechnung, die hineinragte, ganz dazu: Nach einer Schlussrechnung, die auch die Tage vor der ersten
 * Ablesung abrechnet, blieb so ein Rest stehen.
 *
 * Grundlage: § 40b Abs. 1 EnWG (Schlussrechnung bei Vertragsende), § 40c Abs. 2 und 3 EnWG (binnen sechs Wochen, Guthaben
 * binnen zwei Wochen ausgezahlt), § 40a Abs. 2 EnWG (ohne Stand schätzt der Lieferant, die Rechnung deckt also auch Tage
 * ohne Ablesung der App).
 *
 * Erfunden, von Hand: Strom 6 kWh je Tag, Ablesung am Monatsersten ab 01.07.2023 (erste Ablesung), Vertrag A 01.01.2023 bis
 * 31.12.2024 (29 ct, 11 €/Monat, Abschlag 70 €/Monat), Folgevertrag ab 01.01.2025, Stichtag 01.01., heute 02.11.2026.
 * Tageskonvention: Eine Ablesung am Tag D zählt bis D − 1.
 *  - ohne Rechnung, ab der ersten Ablesung: 550 Tage × 6 = 3.300 kWh × 0,29 € = 957,00 € + 18 × 11 € − 18 × 70 € = −105,00 €
 *  - nur 2024: 366 Tage × 6 = 2.196 kWh × 0,29 € = 636,84 € + 132,00 € − 840,00 € = −71,16 €
 */
#[CoversClass(ConsumptionService::class)]
final class EarlierContractSettledByBillTest extends McpHouseholdTestCase
{
    private const STAND = '2026-11-02';

    /** Zähler m_alt, Stände am Monatsersten ab `$first` bis 01.10.2026 (6 kWh je Tag); Vertrag A und Folgevertrag. */
    private function oldHome(string $first = '2023-07-01', string $endA = '2024-12-31', string $succProvider = 'Stadtwerk Süd', bool $dueDay = false): array
    {
        // ganze Rate am Fälligkeitstag (Einstellung „Abschlag zählen“), sonst tagesanteilig
        if ($dueDay) $this->settings->set(['advance_counting' => 'due_day', 'advance_due_day' => 1]);
        $this->meters('strom', ['m_alt' => 'Wohnung']);
        $dates = [];
        for ($d = new \DateTimeImmutable($first); $d->format('Y-m-d') <= '2026-10-01'; $d = $d->modify('first day of next month')) $dates[] = $d->format('Y-m-d');
        $this->counters('strom', ['m_alt' => array_map(fn(string $d) => [$d, 6.0 * (int)(new \DateTimeImmutable($first))->diff(new \DateTimeImmutable($d))->days], $dates)]);
        $this->contracts->create('strom', ['meter_id' => 'm_alt', 'provider' => 'Stadtwerk Nord', 'start' => '2023-01-01', 'end' => $endA,
            'working_prices' => [['from' => '2023-01-01', 'ct_per_kwh' => 29.0]], 'base_prices' => [['from' => '2023-01-01', 'eur_per_month' => 11.0]],
            'advance_payments' => [['from' => '2023-01-01', 'amount_eur' => 70.0]]]);
        $succStart = date('Y-m-d', (int)strtotime($endA . ' +1 day'));
        $this->contracts->create('strom', ['meter_id' => 'm_alt', 'provider' => $succProvider, 'start' => $succStart,
            'working_prices' => [['from' => $succStart, 'ct_per_kwh' => 31.0]], 'base_prices' => [['from' => $succStart, 'eur_per_month' => 12.0]],
            'advance_payments' => [['from' => $succStart, 'amount_eur' => 80.0]]]);
        return $this->meters->get('strom', 'm_alt');
    }

    /** ID des Vertrags mit diesem Beginn. */
    private function contractId(string $utility, string $start, ?string $target = null): string
    {
        foreach ($this->store->read("$utility/contracts.json", []) as $c) {
            if (($c['start'] ?? '') === $start && ($target === null || ($c['meter_id'] ?? $c['meter_group_id'] ?? null) === $target
                || ($c['meter_group_id'] ?? null) === $target)) return (string)$c['id'];
        }
        self::fail("kein Vertrag ab $start");
    }

    /** Rechnung erfassen und ihr Ergebnis buchen (wie „Rechnung prüfen“ › Buchen). Ergebnis > 0 = Nachzahlung. */
    private function bookedBill(string $meterId, string $from, string $to, float $result, ?string $contractId = null): array
    {
        $bills = $this->svc(BillService::class);
        $b = $bills->create('strom', ['meter_id' => $meterId, 'kind' => 'final', 'period_from' => $from, 'period_to' => $to,
            'issued_on' => date('Y-m-d', (int)strtotime($to . ' +20 days')), 'invoice' => ['result_eur' => $result]]
            + ($contractId !== null ? ['contract_id' => $contractId] : []));
        return $bills->book('strom', (string)$b['id']);
    }

    /** @return array<string,mixed> Zeile des Vertrags mit diesem Beginn im Vertragsstatus */
    private function statusRow(array $target, string $start): array
    {
        foreach ($this->consumption->contractStatus('strom', $target, self::STAND)['contracts'] as $c) {
            if (($c['start'] ?? '') === $start) return $c;
        }
        self::fail("Vertrag ab $start fehlt im Vertragsstatus");
    }

    /** KI „Frühere Verträge“: der Status des Vertrags mit diesem Beginn. */
    private function kiStatus(string $start, ?string $meter = null): array
    {
        $out = $this->tool('get_contracts', ['utility' => 'strom', 'include_past' => true] + ($meter !== null ? ['meter' => $meter] : []), self::STAND);
        foreach ($out['targets'] as $t) foreach ($t['contracts'] as $x) {
            if (($x['start'] ?? '') === $start) return $x['status'];
        }
        self::fail("KI kennt den Vertrag ab $start nicht");
    }

    /** Karte gegen KI: derselbe Betrag, abgerechnet ausgeglichen mit dem Satz und dem Tag der Rechnung. */
    private function assertSettled(array $row, array $ki, string $until, string $label): void
    {
        self::assertSame(0.0, (float)$row['current_balance'], "$label: Saldo 0,00 €");
        self::assertSame(0.0, (float)$row['projected_end_balance'], "$label: am Ende 0,00 €");
        self::assertSame('balanced', $row['verdict'], "$label: ausgeglichen");
        self::assertSame($until, $row['settled_until'] ?? null, "$label: Kennzeichen mit dem Tag der Rechnung");
        self::assertArrayNotHasKey('projection_missing', $row, "$label: kein „zu wenig Ablesungen“");
        self::assertSame(0.0, (float)($row['special_payment_net'] ?? 0.0), "$label: gebuchtes Ergebnis zählt nicht mit");
        self::assertEqualsWithDelta(0.0, (float)$ki['balance_today']['value'], 0.005, "$label: KI = Karte");
        self::assertSame($until, $ki['settled_until'] ?? null, "$label: KI nennt den Tag der Rechnung");
        $date = date('d.m.Y', (int)strtotime($until));
        self::assertStringContainsString($date, (string)$ki['balance_today']['text'], "$label: KI sagt, womit abgerechnet ist");
        self::assertStringContainsString('abgerechnet', mb_strtolower((string)$ki['balance_today']['text']), "$label: KI-Satz");
        self::assertArrayNotHasKey('balance_measured', $ki, "$label: kein zweiter Saldo neben dem abgerechneten");
    }

    /**
     * Rechnungen, die das Ende von A (31.12.2024) decken. Bis dahin (Saldo ab der ersten Ablesung, Ergebnis ganz dazu):
     *  - über die erste Ablesung hinaus, 01.01.2023 bis 31.12.2024, Guthaben 60,00 €: −105,00 + 60,00 = −45,00 €
     *  - genau die erwartete Schlussrechnung 01.01. bis 31.12.2024 mit dem Ergebnis der App (Guthaben 71,16 €): −33,84 €
     *  - Kontrolle ab der ersten Ablesung, 01.07.2023 bis 31.12.2024, Guthaben 105,00 €: 0,00 € (hielt schon)
     *  - einen Tag über das Ende, 01.01.2024 bis 01.01.2025, Nachzahlung 12,00 €: −105,00 − 12,00 = −117,00 €
     *
     * @return array<string, array{string, string, float}>
     */
    public static function covering(): array
    {
        return [
            'Rechnung ragt über die erste Ablesung' => ['2023-01-01', '2024-12-31', -60.0],
            'genau die erwartete Schlussrechnung'   => ['2024-01-01', '2024-12-31', -71.16],
            'Kontrolle ab der ersten Ablesung'      => ['2023-07-01', '2024-12-31', -105.0],
            'einen Tag über das Ende'               => ['2024-01-01', '2025-01-01', 12.0],
        ];
    }

    #[DataProvider('covering')]
    public function testABookedBillCoveringTheEndSettlesTheContract(string $from, string $to, float $result): void
    {
        $m = $this->oldHome();
        $this->bookedBill('m_alt', $from, $to, $result);
        $this->assertSettled($this->statusRow($m, '2023-01-01'), $this->kiStatus('2023-01-01'), $to, "$from bis $to");
    }

    /**
     * Ende im laufenden Abrechnungsjahr, der Alltag: A endet am 30.06.2026, die Schlussrechnung 01.01. bis 30.06.2026
     * (Guthaben 50,00 €) ist gebucht, heute 02.11.2026. Mit Folgevertrag eines anderen Versorgers und ohne Folgevertrag
     * (keine Verlängerung): abgerechnet, 0,00 €.
     *
     * @return array<string, array{bool}>
     */
    public static function endsThisYear(): array
    {
        return ['mit Folgevertrag eines anderen Versorgers' => [true], 'ohne Folgevertrag' => [false]];
    }

    #[DataProvider('endsThisYear')]
    public function testAFinalBillInTheRunningBillingYearSettlesTheContract(bool $successor): void
    {
        $m = $this->oldHome('2023-07-01', '2026-06-30');
        $all = $this->store->read('strom/contracts.json', []);
        if (!$successor) $all = array_values(array_filter($all, fn($c) => ($c['start'] ?? '') === '2023-01-01'));
        foreach ($all as &$c) if (($c['start'] ?? '') === '2023-01-01') $c['auto_renews'] = false;
        unset($c);
        $this->store->write('strom/contracts.json', $all);
        $this->bookedBill('m_alt', '2026-01-01', '2026-06-30', -50.0, $this->contractId('strom', '2023-01-01'));
        $this->assertSettled($this->statusRow($m, '2023-01-01'), $this->kiStatus('2023-01-01'), '2026-06-30', 'Ende im laufenden Jahr');
    }

    /** Ganze Rate am Fälligkeitstag (1.): abgerechnet bleibt abgerechnet (bis dahin −105,00 + 60,00 = −45,00 €). */
    public function testAWholeRateOnTheDueDayDoesNotChangeASettledContract(): void
    {
        $m = $this->oldHome('2023-07-01', '2024-12-31', 'Stadtwerk Süd', true);
        $this->bookedBill('m_alt', '2023-01-01', '2024-12-31', -60.0);
        $this->assertSettled($this->statusRow($m, '2023-01-01'), $this->kiStatus('2023-01-01'), '2024-12-31', 'ganze Rate');
    }

    /**
     * Abgerechnet heißt nichts offen: Eine zusätzliche Abschlagszahlung von 30,00 € am 10.02.2025, nach dem Ende und nach der
     * Schlussrechnung (01.01. bis 31.12.2024, Guthaben 71,16 €), ändert den Saldo nicht (sonst −30,00 €).
     */
    public function testAPaymentAfterTheFinalBillDoesNotReopenTheContract(): void
    {
        $m = $this->oldHome();
        $this->bookedBill('m_alt', '2024-01-01', '2024-12-31', -71.16);
        $all = $this->store->read('strom/contracts.json', []);
        foreach ($all as &$c) {
            if (($c['start'] ?? '') === '2023-01-01') $c['special_payments'][] = ['id' => 'sp_extra', 'date' => '2025-02-10', 'kind' => 'abschlagszahlung', 'amount_eur' => 30.0];
        }
        unset($c);
        $this->store->write('strom/contracts.json', $all);
        $this->assertSettled($this->statusRow($m, '2023-01-01'), $this->kiStatus('2023-01-01'), '2024-12-31', 'Zahlung danach');
    }

    /**
     * Rechnungen, die nur einen Teil decken: Der Rest danach bleibt offen, ab dem Tag nach der Rechnung, ohne ihr Ergebnis.
     *  - Jahresrechnung 01.07. bis 31.12.2023, Guthaben 25,00 €: Rest 2024 = −71,16 € (bis dahin −105,00 + 25,00 = −80,00 €)
     *  - Rechnung ragt über die erste Ablesung, 01.01. bis 31.12.2023, Nachzahlung 15,00 €: Rest 2024 = −71,16 €
     *    (bis dahin −105,00 − 15,00 = −120,00 €)
     *  - ganz vor der ersten Ablesung, 01.01. bis 30.04.2023, Nachzahlung 20,00 €: bleibt draußen, −105,00 € ab der ersten
     *    Ablesung (hielt schon)
     *
     * @return array<string, array{string, string, float, string, float}>
     */
    public static function partial(): array
    {
        return [
            'Jahresrechnung ab der ersten Ablesung'          => ['2023-07-01', '2023-12-31', -25.0, '2024-01-01', -71.16],
            'Rechnung ragt über die erste Ablesung, Teil'    => ['2023-01-01', '2023-12-31', 15.0, '2024-01-01', -71.16],
            'Rechnung ganz vor der ersten Ablesung'          => ['2023-01-01', '2023-04-30', 20.0, '2023-07-01', -105.00],
        ];
    }

    #[DataProvider('partial')]
    public function testABillCoveringOnlyPartLeavesTheRestOpen(string $from, string $to, float $result, string $balanceFrom, float $balance): void
    {
        $m = $this->oldHome();
        $this->bookedBill('m_alt', $from, $to, $result);
        $c = $this->statusRow($m, '2023-01-01');
        self::assertSame($balanceFrom, $c['balance_from'], 'Saldo ab dem Tag nach der Rechnung, nie vor der ersten Ablesung');
        self::assertEqualsWithDelta($balance, $c['current_balance'], 0.005, 'Rest von Hand, ohne das gebuchte Ergebnis');
        self::assertEqualsWithDelta($balance, $c['balance_measured']['balance'], 0.005, 'Saldo am letzten Stand (Ablesung am Vertragsende)');
        self::assertArrayNotHasKey('settled_until', $c, 'nicht abgerechnet');
        self::assertSame(0.0, (float)$c['special_payment_net'], 'das Ergebnis der Rechnung gehört zu ihrem Zeitraum');
        // Ansicht gegen Ansicht: KI und Abrechnungsjahre ab dem Saldobeginn
        $ki = $this->kiStatus('2023-01-01');
        self::assertEqualsWithDelta($balance, (float)$ki['balance_today']['value'], 0.005, 'KI = Karte');
        self::assertEqualsWithDelta($balance, (float)$ki['balance_measured']['value'], 0.005, 'KI am letzten Stand = Karte');
        self::assertSame($ki['balance_today']['as_of'], $ki['balance_measured']['as_of'], 'derselbe Tag, derselbe Betrag');
        $periods = array_filter($this->svc(BillingPeriodService::class)->forTarget('strom', $m, false, self::STAND)['periods'],
            fn($p) => $p['from'] >= $balanceFrom && $p['until'] <= '2024-12-31');
        self::assertEqualsWithDelta($balance, array_sum(array_column($periods, 'balance')), 0.015, 'Karte = Σ Abrechnungsjahre danach');
    }

    /**
     * Ganze Rate am Fälligkeitstag (1.), Rechnung 01.07.2023 bis 15.01.2024: Saldo ab 16.01.2024.
     * 351 Tage × 6 = 2.106 kWh × 0,29 € = 610,74 €, Grundpreis 11 € × (16/31 + 11) = 126,68 €, Raten 01.02. bis 01.12.2024
     * = 11 × 70 € = 770,00 €: 737,42 − 770,00 = −32,58 € (die Rate vom 01.01.2024 gehört zur Rechnung).
     */
    public function testAWholeRateOnTheDueDayCountsFromTheDayAfterTheBill(): void
    {
        $m = $this->oldHome('2023-07-01', '2024-12-31', 'Stadtwerk Süd', true);
        $this->bookedBill('m_alt', '2023-07-01', '2024-01-15', -30.0);
        $c = $this->statusRow($m, '2023-01-01');
        self::assertSame('2024-01-16', $c['balance_from']);
        self::assertEqualsWithDelta(-32.58, $c['current_balance'], 0.005);
        self::assertEqualsWithDelta(770.0, $c['advance_paid'], 0.005, 'elf ganze Raten');
        self::assertEqualsWithDelta(-32.58, (float)$this->kiStatus('2023-01-01')['balance_today']['value'], 0.005, 'KI = Karte');
    }

    /**
     * Nur die eigenen Rechnungen: Die Jahresrechnung des Folgevertrags bei einem anderen Versorger (Nachzahlung 40,00 €)
     * rechnet A nicht ab, obwohl sie für denselben Zähler später endet, auch nicht, wenn ihr Zeitraum in die letzten Tage von
     * A hineinragt (am Folgevertrag gebucht). A bleibt bei −105,00 €.
     *
     * @return array<string, array{string, string}>
     */
    public static function successorBills(): array
    {
        return [
            'Jahresrechnung des Folgevertrags'           => ['2025-01-01', '2025-12-31'],
            'Rechnung des Folgevertrags ragt in A hinein' => ['2024-12-01', '2025-11-30'],
        ];
    }

    #[DataProvider('successorBills')]
    public function testTheSuccessorsBillDoesNotSettleTheEarlierContract(string $from, string $to): void
    {
        $m = $this->oldHome();
        $this->bookedBill('m_alt', $from, $to, 40.0, $this->contractId('strom', '2025-01-01'));
        $c = $this->statusRow($m, '2023-01-01');
        self::assertArrayNotHasKey('settled_until', $c);
        self::assertSame('2023-07-01', $c['balance_from']);
        self::assertEqualsWithDelta(-105.00, $c['current_balance'], 0.005);
        self::assertEqualsWithDelta(-105.00, (float)$this->kiStatus('2023-01-01')['balance_today']['value'], 0.005, 'KI = Karte');
    }

    /**
     * Jahresrechnung über einen Vertragswechsel beim selben Versorger: A bis 30.06.2024, Folgevertrag desselben Versorgers ab
     * 01.07.2024. Die Jahresrechnung 2024 (Guthaben 40,00 €) bucht die App auf den Folgevertrag (er gilt am letzten Tag und
     * rechnet 184 von 366 Tagen ab). Sie deckt das Ende von A: A ist abgerechnet. Bis dahin: ab der ersten Ablesung bis
     * 30.06.2024, 366 Tage × 6 = 2.196 kWh × 0,29 € = 636,84 € + 132,00 € − 840,00 € = −71,16 €.
     */
    public function testAYearBillAcrossASwitchAtTheSameSupplierSettlesTheEarlierContract(): void
    {
        $m = $this->oldHome('2023-07-01', '2024-06-30', 'Stadtwerk Nord');
        $b = $this->bookedBill('m_alt', '2024-01-01', '2024-12-31', -40.0);
        self::assertSame($this->contractId('strom', '2024-07-01'), $b['contract_id'], 'gebucht am Folgevertrag');
        $this->assertSettled($this->statusRow($m, '2023-01-01'), $this->kiStatus('2023-01-01'), '2024-12-31', 'Wechsel beim selben Versorger');
    }

    /**
     * Ein Vertrag ganz vor der ersten Ablesung mit gebuchter Schlussrechnung (2022, Nachzahlung 12,00 €): abgerechnet, 0,00 €,
     * kein „zu wenig Ablesungen“ (bis dahin kein Saldo und `too_few_readings`).
     */
    public function testASettledContractEntirelyBeforeTheFirstReadingNamesZero(): void
    {
        $m = $this->oldHome();
        $this->contracts->create('strom', ['meter_id' => 'm_alt', 'provider' => 'Stadtwerk West', 'start' => '2022-01-01', 'end' => '2022-12-31',
            'working_prices' => [['from' => '2022-01-01', 'ct_per_kwh' => 27.0]], 'base_prices' => [['from' => '2022-01-01', 'eur_per_month' => 11.0]],
            'advance_payments' => [['from' => '2022-01-01', 'amount_eur' => 70.0]]]);
        $this->bookedBill('m_alt', '2022-01-01', '2022-12-31', 12.0, $this->contractId('strom', '2022-01-01'));
        $this->assertSettled($this->statusRow($m, '2022-01-01'), $this->kiStatus('2022-01-01'), '2022-12-31', 'ganz vor der ersten Ablesung');
        self::assertEqualsWithDelta(-105.00, $this->statusRow($m, '2023-01-01')['current_balance'], 0.005, 'der Vertrag danach bleibt');
    }

    /**
     * Gruppe: HT und NT (je 3 kWh am Tag) mit Gruppenvertrag 01.01.2023 bis 31.12.2024 (29 ct, 11 €, 70 €), Folgevertrag
     * eines anderen Versorgers ab 2025, erste Ablesung 01.07.2023. Die Schlussrechnung 2024 ist am HT-Zählwerk erfasst und
     * auf den Gruppenvertrag gebucht (Guthaben 30,00 €): Der Gruppenvertrag ist abgerechnet. Bis dahin wie ein Zähler mit
     * 6 kWh am Tag: −105,00 + 30,00 = −75,00 €.
     */
    public function testAGroupContractIsSettledByTheBillOfAMember(): void
    {
        $dev = fn(string $id) => [['id' => "d_$id", 'serial' => null, 'installed_on' => '2020-01-01', 'initial_counter' => 0.0,
            'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $meter = fn(string $id, string $name, string $obis) => ['id' => $id, 'name' => $name, 'icon' => '', 'created_at' => '2020-01-01',
            'active' => true, 'notes' => '', 'obis' => $obis, 'meter_group_id' => 'g_dt', 'devices' => $dev($id)];
        $this->store->write('strom/meters.json', [$meter('m_ht', 'HT', '1.8.1'), $meter('m_nt', 'NT', '1.8.2')]);
        $this->store->write('strom/meter_groups.json', [['id' => 'g_dt', 'name' => 'Doppeltarif', 'created_at' => '2020-01-01']]);
        $rows = [];
        foreach (['m_ht', 'm_nt'] as $id) {
            for ($d = new \DateTimeImmutable('2023-07-01'); $d->format('Y-m-d') <= '2026-10-01'; $d = $d->modify('first day of next month')) {
                $rows[] = [$d->format('Y-m-d'), 3.0 * (int)(new \DateTimeImmutable('2023-07-01'))->diff($d)->days];
            }
            $by[$id] = $rows;
            $rows = [];
        }
        $this->counters('strom', $by);
        foreach ([['Musterwerk', '2023-01-01', '2024-12-31', 29.0, 11.0, 70.0], ['Anderswerk', '2025-01-01', null, 31.0, 12.0, 80.0]] as [$p, $s, $e, $wp, $bp, $ap]) {
            $this->contracts->create('strom', ['meter_id' => null, 'meter_group_id' => 'g_dt', 'provider' => $p, 'start' => $s, 'end' => $e,
                'working_prices' => [['from' => $s, 'ct_per_kwh' => $wp]], 'base_prices' => [['from' => $s, 'eur_per_month' => $bp]],
                'advance_payments' => [['from' => $s, 'amount_eur' => $ap]]]);
        }
        $b = $this->bookedBill('m_ht', '2024-01-01', '2024-12-31', -30.0);
        self::assertSame($this->contractId('strom', '2023-01-01', 'g_dt'), $b['contract_id'], 'gebucht am Gruppenvertrag');
        $this->assertSettled($this->statusRow($this->meters->groupTarget('strom', 'g_dt'), '2023-01-01'), $this->kiStatus('2023-01-01', 'g_dt'),
            '2024-12-31', 'Gruppe');
    }

    /**
     * KI „Saldo heute“ gegen „Saldo am letzten Stand“ eines früheren Vertrags ohne Rechnung: Eine zusätzliche Abschlagszahlung
     * von 30,00 € am 10.02.2025, nach dem Ende und nach der letzten Ablesung im Vertrag (01.01.2025), zählt heute
     * (−105,00 − 30,00 = −135,00 €), am letzten Stand nicht (−105,00 €). Die beiden nennen dann verschiedene Tage: heute den
     * Tag der Zahlung, am letzten Stand die Ablesung. Bis dahin beide 01.01.2025.
     */
    public function testBalanceTodayOfAnEarlierContractNamesTheDayOfALaterPayment(): void
    {
        $this->oldHome();
        $all = $this->store->read('strom/contracts.json', []);
        foreach ($all as &$c) {
            if (($c['start'] ?? '') === '2023-01-01') $c['special_payments'] = [['id' => 'sp_extra', 'date' => '2025-02-10', 'kind' => 'abschlagszahlung', 'amount_eur' => 30.0]];
        }
        unset($c);
        $this->store->write('strom/contracts.json', $all);
        $ki = $this->kiStatus('2023-01-01');
        self::assertEqualsWithDelta(-135.00, (float)$ki['balance_today']['value'], 0.005, 'heute mit der Zahlung');
        self::assertEqualsWithDelta(-105.00, (float)$ki['balance_measured']['value'], 0.005, 'am letzten Stand ohne sie');
        self::assertSame('2025-02-10', $ki['balance_today']['as_of'], 'Tag der Zahlung');
        self::assertSame('2025-01-01', $ki['balance_measured']['as_of'], 'Tag der Ablesung');
    }

    /**
     * Die Zusage der API-Referenz (DE und EN, `doc-claim: settled-until`) ist die Regel dieser Tests: ab dem Tag nach der
     * letzten gebuchten Rechnung, abgerechnet 0,00 € mit `settled_until`, nie `null`, die Rechnung eines anderen Versorgers
     * rechnet nicht ab.
     */
    public function testTheApiReferenceStatesTheRule(): void
    {
        $flat = fn(string $rel) => (string)preg_replace('/\s+/u', ' ', (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel));
        $claim = function (string $doc): string {
            $a = strpos($doc, '<!-- doc-claim: settled-until -->');
            self::assertNotFalse($a, 'Markierung fehlt');
            return substr($doc, $a, (int)strpos($doc, '<!-- /doc-claim -->', $a) - $a);
        };
        $de = $flat('docs/referenz/api.md'); $en = $flat('docs/en/referenz/api.md');
        foreach (['ab dem Tag nach seiner letzten gebuchten Rechnung', '(`settled_until`, 0,00 €)', 'die Rechnung eines anderen Versorgers rechnet ihn nie ab',
                  'Eine Rechnung, die vor der ersten Ablesung endet, bleibt draußen'] as $s) self::assertStringContainsString($s, $claim($de), "DE: $s");
        foreach (['from the day after its last booked bill', '(`settled_until`, 0.00)', 'a bill of another supplier never settles it',
                  'A bill that ends before the first reading stays out'] as $s) self::assertStringContainsString($s, $claim($en), "EN: $s");
        self::assertStringContainsString('Nie `null` und nie `projection_missing`', $de);
        self::assertStringContainsString('Never `null` and never `projection_missing`', $en);
    }
}
