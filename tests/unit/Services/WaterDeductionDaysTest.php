<?php
declare(strict_types=1);

namespace Energietracker\Tests\Services;

use Energietracker\Services\BillingPeriodService;
use Energietracker\Services\BillService;
use Energietracker\Services\ConsumptionService;
use Energietracker\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * v3.13.0 (Paket K2a) — Wasser mit Abzugszähler (Garten) oder eigenem Schmutzwasserzähler: Die Monatszeile zieht nur die
 * Menge ab, die der andere Zähler an den Tagen gemessen hat, die auch der Hauptzähler gemessen hat; dieselbe Regel wie
 * „Rechnung prüfen“ (je Abschnitt des Hauptzählers, ConsumptionService::waterVolumeBetween). Bis dahin nahm die Zeile die
 * Monatsmenge des Gartens gegen die Tage des Hauptzählers: War der Garten später im Monat abgelesen, zog sie seine
 * Menge auch für Tage ab, die der Hauptzähler noch nicht gemessen hatte (Saldo-Karte und Jahresbericht anders als
 * Abrechnungsjahr und „Rechnung prüfen“).
 *
 * Erfunden, von Hand: Haus 0,5 m³ je Tag, Garten 0,15 m³ je Tag, Stände an jedem Monatsersten bis 01.09.2026, Haus
 * zuletzt am 17.09., Garten am 17.09. und 30.09.; Trinkwasser 220 ct/m³, Schmutzwasser 260 ct/m³ (Trinkwasser abzüglich
 * Garten), kein Grundpreis, Abschlag 80 €/Monat ab 01.01.; Stichtag 01.01., heute 06.10.2026.
 * Gemessen 01.01. bis 16.09. = 259 Tage:
 *   Trinkwasser    259 × 0,5 = 129,5 m³ × 2,20 €                 = 284,90 €
 *   Schmutzwasser  (129,5 − 259 × 0,15 = 38,85) = 90,65 m³ × 2,60 = 235,69 €
 *   Abschläge      8 × 80 € + 80 € × 16/30                       = 682,67 €
 *   Saldo          284,90 + 235,69 − 682,67                      = −162,08 € (Guthaben)
 * September: Haus 16 Tage = 8 m³, Garten in denselben Tagen 2,4 m³ (bis v3.12: 29 Tage = 4,35 m³, Saldo −167,15 €).
 */
#[CoversClass(ConsumptionService::class)]
#[CoversClass(BillingPeriodService::class)]
final class WaterDeductionDaysTest extends ServiceTestCase
{
    private const TODAY = '2026-10-06';

    /** @param list<string> $houseTail @param list<string> $gardenTail */
    private function setup2(array $houseTail, array $gardenTail, string $basis = 'trinkwasser_minus_abzug', string $gardenLast = '2026-09-01'): void
    {
        $dev = fn(string $id) => [['id' => $id, 'serial' => null, 'installed_on' => '2025-12-01',
            'initial_counter' => 0.0, 'removed_on' => null, 'final_counter' => null, 'reason' => null]];
        $this->store->write('wasser/meters.json', [
            ['id' => 'm_haus', 'name' => 'Haus', 'icon' => '', 'created_at' => '2025-12-01', 'active' => true,
             'notes' => '', 'devices' => $dev('d_haus'), 'parent_meter_id' => null],
            ['id' => 'm_beet', 'name' => 'Beet', 'icon' => '', 'created_at' => '2025-12-01', 'active' => true,
             'notes' => '', 'devices' => $dev('d_beet'), 'parent_meter_id' => $basis === 'trinkwasser_minus_abzug' ? 'm_haus' : null,
             'role' => $basis === 'trinkwasser_minus_abzug' ? 'garden' : null],
        ]);
        $rows = [];
        $at = fn(string $d) => (int)(new \DateTimeImmutable('2026-01-01'))->diff(new \DateTimeImmutable($d))->days;
        $monthly = fn(string $last) => array_values(array_filter(array_map(fn($m) => sprintf('2026-%02d-01', $m), range(1, 9)), fn($d) => $d <= $last));
        foreach ([...$monthly('2026-09-01'), ...$houseTail] as $i => $d) {
            $rows[] = ['id' => "r_h$i", 'meter_id' => 'm_haus', 'device_id' => 'd_haus', 'date' => $d, 'counter' => round(0.5 * $at($d), 3),
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        foreach ([...$monthly($gardenLast), ...$gardenTail] as $i => $d) {
            $rows[] = ['id' => "r_g$i", 'meter_id' => 'm_beet', 'device_id' => 'd_beet', 'date' => $d, 'counter' => round(0.15 * $at($d), 3),
                'price_cents' => null, 'note' => '', 'is_estimated' => false, 'is_future' => false];
        }
        $this->store->write('wasser/readings.json', $rows);
        $sw = $basis === 'trinkwasser_minus_abzug'
            ? ['basis' => $basis, 'abzug_meter_ids' => ['m_beet']]
            : ['basis' => $basis, 'separater_zaehler_meter_id' => 'm_beet'];
        $this->contracts->create('wasser', [
            'meter_id' => 'm_haus', 'provider' => 'Wasserwerk Musterstadt', 'start' => '2026-01-01',
            'trinkwasser' => ['working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 220.0]]],
            'schmutzwasser' => $sw + ['working_prices' => [['from' => '2026-01-01', 'ct_per_m3' => 260.0]]],
            'advance_payments' => [['from' => '2026-01-01', 'amount_eur' => 80.0]],
        ]);
    }

    /** @return array{0:float,1:float,2:float,3:float,4:float} Karte, Abrechnungsjahr, „Rechnung prüfen“, Jahresbericht (Kosten), Karte (Kosten) */
    private function views(string $until): array
    {
        $meter = $this->meters->get('wasser', 'm_haus');
        $card = $this->consumption->contractStatus('wasser', $meter, self::TODAY)['contracts'][0]['balance_measured'];
        $bills = new BillService($this->store, $this->meters, $this->contracts, $this->consumption, $this->i18n);
        $p = array_values(array_filter((new BillingPeriodService($this->settings, $this->meters, $this->readings, $this->consumption, $bills))
            ->forTarget('wasser', $meter, false, self::TODAY)['periods'], fn($x) => !empty($x['running'])))[0];
        $check = $this->consumption->atDate(self::TODAY, fn() => $this->consumption->billBreakdown('wasser', $meter, '2026-01-01', $until));
        $report = 0.0;
        foreach ($this->consumption->atDate(self::TODAY, fn() => $this->consumption->forMeter('wasser', $meter)) as $m) $report += (float)($m['cost'] ?? 0);
        return [$card['balance'], (float)$p['balance'], (float)$check['totals']['total'], round($report, 2), $card['cost']];
    }

    private function september(): array
    {
        $m = $this->meters->get('wasser', 'm_haus');
        return $this->consumption->atDate(self::TODAY, fn() => array_column($this->consumption->forMeter('wasser', $m), null, 'ym'))['2026-09'];
    }

    public function testTheGardenReadLaterDeductsOnlyTheDaysTheHouseMeasured(): void
    {
        $this->setup2(['2026-09-17'], ['2026-09-17', '2026-09-30']);
        self::assertEqualsWithDelta(2.4, $this->september()['schmutzwasser']['abzug_m3'], 0.0005, '16 Tage Garten (bis v3.12 29 Tage, 4,35 m³)');
        [$card, $year, $check, $report, $cost] = $this->views('2026-09-17');
        self::assertSame(-162.08, $card, 'Karte');
        self::assertSame($card, $year, 'Abrechnungsjahr = Karte');
        self::assertSame(520.59, $check, '„Rechnung prüfen“: 284,90 + 235,69');
        self::assertSame($check, $cost, 'Kosten der Karte = „Rechnung prüfen“');
        self::assertSame($check, $report, 'Jahresbericht (Monatszeilen) = „Rechnung prüfen“');
    }

    /** Garten am selben Tag abgelesen: wie bisher. */
    public function testTheSameDayStaysAsItWas(): void
    {
        $this->setup2(['2026-09-17'], ['2026-09-17']);
        self::assertEqualsWithDelta(2.4, $this->september()['schmutzwasser']['abzug_m3'], 0.0005);
        self::assertSame(-162.08, $this->views('2026-09-17')[0]);
    }

    /**
     * Garten früher abgelesen (zuletzt 01.09.): Für die Tage ohne Gartenstand zieht keine Ansicht etwas ab (wie „Rechnung
     * prüfen“ seit v3.6.0); alle Ansichten nennen denselben Betrag. Garten abgezogen nur Januar bis August, 243 × 0,15 =
     * 36,45 m³; Schmutzwasser 129,5 − 36,45 = 93,05 m³ × 2,60 = 241,93 € → zusammen 526,83 €.
     */
    public function testTheGardenReadEarlierDeductsNothingForTheDaysWithoutIt(): void
    {
        $this->setup2(['2026-09-17'], [], 'trinkwasser_minus_abzug', '2026-09-01');
        self::assertEqualsWithDelta(0.0, $this->september()['schmutzwasser']['abzug_m3'], 0.0005);
        [$card, $year, $check, $report, $cost] = $this->views('2026-09-17');
        self::assertSame(526.83, $check);
        self::assertSame($check, $cost);
        self::assertSame($check, $report);
        self::assertSame($card, $year);
    }

    /** Garten erst im Folgemonat wieder abgelesen (05.10.): Der September zieht nur die Gartenmenge bis 16.09. ab. */
    public function testTheGardenReadInTheNextMonth(): void
    {
        $this->setup2(['2026-09-17'], ['2026-10-05']);
        self::assertEqualsWithDelta(2.4, $this->september()['schmutzwasser']['abzug_m3'], 0.0005);
        [$card, $year, $check, $report, $cost] = $this->views('2026-09-17');
        self::assertSame(-162.08, $card);
        self::assertSame([$card, $check], [$year, $cost]);
        self::assertSame($check, $report);
    }

    /**
     * Eigener Schmutzwasserzähler (`separater_zaehler`) später abgelesen als der Hauptzähler: dieselbe Regel, seine Menge
     * an den Tagen des Hauptzählers. Schmutzwasser 259 × 0,15 = 38,85 m³ × 2,60 = 101,01 € → 385,91 €.
     */
    public function testASeparateWastewaterMeterReadLater(): void
    {
        $this->setup2(['2026-09-17'], ['2026-09-30'], 'separater_zaehler', '2026-09-01');
        self::assertEqualsWithDelta(2.4, $this->september()['schmutzwasser']['m3'], 0.0005, '16 Tage des eigenen Zählers (bis v3.12 29 Tage)');
        [$card, $year, $check, $report, $cost] = $this->views('2026-09-17');
        self::assertSame(385.91, $check);
        self::assertSame([$check, $check], [$cost, $report]);
        self::assertSame($card, $year);
    }
}
